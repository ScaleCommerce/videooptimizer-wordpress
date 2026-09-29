<?php
/**
 * Webhook receiver.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Rest;

use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/videooptimizer/v1/webhook — receives video.ready / video.failed / webhook.test
 * from VideoOptimizer (configured in the VideoOptimizer app under Organization → Webhooks).
 *
 * Signature: X-VideoOptimizer-Signature: sha256=hex(HMAC-SHA256(secret, "{timestamp}.{rawBody}")).
 * Deliveries older than five minutes are rejected (replay protection) and retries of the same
 * delivery id are acknowledged without being processed twice.
 */
class WebhookController {

	public const TOLERANCE = 300;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Embed cache.
	 *
	 * @var EmbedRepository
	 */
	private EmbedRepository $embeds;

	/**
	 * Attachment mapping.
	 *
	 * @var Attachments
	 */
	private Attachments $attachments;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings    Settings.
	 * @param EmbedRepository $embeds      Embed cache.
	 * @param Attachments     $attachments Attachment mapping.
	 */
	public function __construct( Settings $settings, EmbedRepository $embeds, Attachments $attachments ) {
		$this->settings    = $settings;
		$this->embeds      = $embeds;
		$this->attachments = $attachments;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the public route (authenticated by signature, not by user).
	 */
	public function register_routes(): void {
		register_rest_route(
			AdminController::NAMESPACE,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verifies a signature.
	 *
	 * @param string $secret    Signing secret.
	 * @param string $timestamp Unix timestamp header.
	 * @param string $raw_body  Raw request body.
	 * @param string $signature Signature header ("sha256=…").
	 * @param int    $now       Current time.
	 */
	public static function verify( string $secret, string $timestamp, string $raw_body, string $signature, int $now ): bool {
		if ( '' === $secret || ! ctype_digit( $timestamp ) || abs( $now - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		return hash_equals( 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret ), $signature );
	}

	/**
	 * Handles a delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$secret = $this->settings->webhook_secret();
		if ( null === $secret ) {
			return new \WP_Error( 'videooptimizer_webhook_disabled', 'Webhook secret not configured.', array( 'status' => 503 ) );
		}

		$raw       = (string) $request->get_body();
		$timestamp = (string) $request->get_header( 'x-videooptimizer-timestamp' );
		$signature = (string) $request->get_header( 'x-videooptimizer-signature' );
		if ( ! self::verify( $secret, $timestamp, $raw, $signature, time() ) ) {
			return new \WP_Error( 'videooptimizer_webhook_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}

		$delivery = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $request->get_header( 'x-videooptimizer-delivery-id' ) );
		if ( '' !== $delivery ) {
			$key = 'videooptimizer_wh_' . substr( md5( $delivery ), 0, 32 );
			if ( get_transient( $key ) ) {
				return rest_ensure_response( array( 'duplicate' => true ) );
			}
			set_transient( $key, 1, DAY_IN_SECONDS );
		}

		$payload = json_decode( $raw, true );
		$event   = is_array( $payload ) && is_string( $payload['event'] ?? null ) ? $payload['event'] : '';
		$data    = is_array( $payload ) && is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
		$updated = 0;

		if ( in_array( $event, array( 'video.ready', 'video.failed' ), true ) ) {
			$updated = $this->attachments->apply_video( $data );
			if ( is_string( $data['uuid'] ?? null ) ) {
				$this->embeds->forget( $data['uuid'] );
			}

			/**
			 * Fires after a verified video.ready / video.failed webhook was processed.
			 *
			 * @param string               $event Event name.
			 * @param array<string, mixed> $data  Video object.
			 */
			do_action( 'videooptimizer_webhook', $event, $data );
		}

		update_option(
			Settings::WEBHOOK_STATUS,
			array(
				'event' => '' !== $event ? sanitize_key( str_replace( '.', '_', $event ) ) : 'unknown',
				'time'  => time(),
			),
			false
		);

		return rest_ensure_response(
			array(
				'received' => true,
				'updated'  => $updated,
			)
		);
	}
}
