<?php
/**
 * Media library integration.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Media;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Plugin;
use ScaleCommerce\VideoOptimizer\Render\Assets;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "Send to VideoOptimizer" for media library videos: attachment details field (grid + modal),
 * list-view column, row and bulk action, optional auto-send of new uploads.
 */
class MediaLibrary {

	public const SCRIPT = 'videooptimizer-media';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Attachment mapping.
	 *
	 * @var Attachments
	 */
	private Attachments $attachments;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings    Settings.
	 * @param Attachments $attachments Attachment mapping.
	 */
	public function __construct( Settings $settings, Attachments $attachments ) {
		$this->settings    = $settings;
		$this->attachments = $attachments;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'attachment_js' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_field' ), 10, 2 );
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'add_attachment', array( $this, 'maybe_auto_send' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_on_media_screens' ) );
	}

	/**
	 * Adds the VideoOptimizer state to the attachment JSON used by the media modal.
	 *
	 * @param array<string, mixed> $response   Attachment data.
	 * @param \WP_Post             $attachment Attachment.
	 * @return array<string, mixed>
	 */
	public function attachment_js( array $response, \WP_Post $attachment ): array {
		if ( str_starts_with( (string) $attachment->post_mime_type, 'video/' ) ) {
			$response['videooptimizer'] = $this->attachments->state( $attachment->ID );
		}

		return $response;
	}

	/**
	 * Adds a "VideoOptimizer" row to the attachment details (media modal + edit screen). The UI is
	 * rendered by the media script into the placeholder.
	 *
	 * @param array<string, array<string, mixed>> $fields     Fields.
	 * @param \WP_Post                            $attachment Attachment.
	 * @return array<string, array<string, mixed>>
	 */
	public function attachment_field( array $fields, \WP_Post $attachment ): array {
		if ( ! str_starts_with( (string) $attachment->post_mime_type, 'video/' ) || ! current_user_can( 'upload_files' ) ) {
			return $fields;
		}

		$fields['videooptimizer'] = array(
			'label' => 'VideoOptimizer',
			'input' => 'html',
			'html'  => sprintf(
				'<div class="videooptimizer-attachment" data-id="%1$d" data-url="%2$s" data-title="%4$s" data-state="%3$s"><span class="spinner is-active" style="float:none;margin:0"></span></div>',
				$attachment->ID,
				esc_url( (string) wp_get_attachment_url( $attachment->ID ) ),
				esc_attr( (string) wp_json_encode( $this->attachments->state( $attachment->ID ) ) ),
				esc_attr( get_the_title( $attachment ) )
			),
		);

		return $fields;
	}

	/**
	 * List view column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$columns['videooptimizer'] = 'VideoOptimizer';

		return $columns;
	}

	/**
	 * List view column content.
	 *
	 * @param string $column Column name.
	 * @param int    $id     Attachment id.
	 */
	public function render_column( string $column, int $id ): void {
		if ( 'videooptimizer' !== $column || ! $this->attachments->is_video( $id ) ) {
			return;
		}
		$state  = $this->attachments->state( $id );
		$labels = self::status_labels();
		printf(
			'<span class="videooptimizer-badge videooptimizer-badge--%1$s" data-videooptimizer-status="%2$d">%3$s</span>',
			esc_attr( $state['pending'] ? 'pending' : $state['status'] ),
			(int) $id,
			esc_html( $state['pending'] ? $labels['pending'] : ( $labels[ $state['status'] ] ?? $state['status'] ) )
		);
		if ( 'failed' === $state['status'] && '' !== $state['error'] ) {
			echo '<br><small>' . esc_html( $state['error'] ) . '</small>';
		}
	}

	/**
	 * Row action in the list view.
	 *
	 * @param array<string, string> $actions Actions.
	 * @param \WP_Post              $post    Attachment.
	 * @return array<string, string>
	 */
	public function row_action( array $actions, \WP_Post $post ): array {
		if ( ! str_starts_with( (string) $post->post_mime_type, 'video/' ) || ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}
		$state = $this->attachments->state( $post->ID );
		$label = 'none' === $state['status'] ? __( 'Send to VideoOptimizer', 'videooptimizer' ) : __( 'Send to VideoOptimizer again', 'videooptimizer' );

		$actions['videooptimizer'] = '<a href="' . esc_url( self::send_url( array( $post->ID ) ) ) . '">' . esc_html( $label ) . '</a>';

		return $actions;
	}

	/**
	 * Bulk action.
	 *
	 * @param array<string, string> $actions Actions.
	 * @return array<string, string>
	 */
	public function bulk_action( array $actions ): array {
		if ( current_user_can( 'upload_files' ) ) {
			$actions['videooptimizer_send'] = __( 'Send to VideoOptimizer', 'videooptimizer' );
		}

		return $actions;
	}

	/**
	 * Redirects the bulk action to the transfer screen (uploads run in the browser with progress).
	 *
	 * @param string          $redirect Redirect URL.
	 * @param string          $action   Action.
	 * @param array<int, int> $ids      Attachment ids.
	 */
	public function handle_bulk_action( string $redirect, string $action, array $ids ): string {
		if ( 'videooptimizer_send' !== $action ) {
			return $redirect;
		}
		$videos = array_values( array_filter( array_map( 'intval', $ids ), array( $this->attachments, 'is_video' ) ) );

		return array() === $videos ? $redirect : self::send_url( $videos );
	}

	/**
	 * Auto-send on upload: by URL right away on public sites, otherwise queued for the browser.
	 *
	 * @param int $id Attachment id.
	 */
	public function maybe_auto_send( int $id ): void {
		if ( ! $this->settings->flag( 'auto_send' ) || ! $this->attachments->is_video( $id ) || null === $this->settings->token() ) {
			return;
		}

		$sender = new Sender( $this->settings, Plugin::instance()->client, $this->attachments );
		if ( $sender->can_send_by_url( $id ) ) {
			try {
				$sender->send_by_url( $id );

				return;
			} catch ( ApiException $e ) {
				// Fall through to the browser queue.
				unset( $e );
			}
		}
		update_post_meta( $id, Attachments::META_PENDING, time() );
	}

	/**
	 * Media modal / grid: load the media script wherever the media library is used.
	 */
	public function enqueue(): void {
		self::enqueue_script( $this->settings, $this->attachments );
	}

	/**
	 * Enqueues the media script (also used for the delete offers on other admin screens).
	 *
	 * @param Settings    $settings    Settings.
	 * @param Attachments $attachments Attachment mapping.
	 */
	public static function enqueue_script( Settings $settings, Attachments $attachments ): void {
		if ( ! current_user_can( 'upload_files' ) || wp_script_is( self::SCRIPT, 'enqueued' ) ) {
			return;
		}
		$asset = Assets::asset_file( 'media/index' );
		wp_enqueue_script( self::SCRIPT, VIDEOOPTIMIZER_URL . 'build/media/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( self::SCRIPT, VIDEOOPTIMIZER_URL . 'build/media/index.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( self::SCRIPT, 'rtl', 'replace' );
		wp_set_script_translations( self::SCRIPT, 'videooptimizer', VIDEOOPTIMIZER_DIR . 'languages' );
		wp_add_inline_script(
			self::SCRIPT,
			'window.videooptimizerMedia=' . wp_json_encode(
				array(
					'autoSend'    => $settings->flag( 'media_integration' ) && $settings->flag( 'auto_send' ),
					'integration' => $settings->flag( 'media_integration' ),
					'configured'  => null !== $settings->token(),
					'pending'     => $settings->flag( 'auto_send' ) ? $attachments->pending_ids() : array(),
					'adminUrl'    => admin_url( 'admin.php?page=videooptimizer' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Also load it on the list view (which does not call wp_enqueue_media) and attachment edit.
	 *
	 * @param string $hook Screen hook.
	 */
	public function enqueue_on_media_screens( string $hook ): void {
		if ( in_array( $hook, array( 'upload.php', 'post.php', 'media-new.php' ), true ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Transfer screen URL.
	 *
	 * @param array<int, int> $ids Attachment ids.
	 */
	public static function send_url( array $ids ): string {
		return admin_url( 'admin.php?page=videooptimizer#/send/' . implode( ',', array_map( 'intval', $ids ) ) );
	}

	/**
	 * Human status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			'none'       => __( 'Not sent', 'videooptimizer' ),
			'pending'    => __( 'Waiting for transfer', 'videooptimizer' ),
			'processing' => __( 'Processing', 'videooptimizer' ),
			'ready'      => __( 'Optimized', 'videooptimizer' ),
			'failed'     => __( 'Failed', 'videooptimizer' ),
		);
	}
}
