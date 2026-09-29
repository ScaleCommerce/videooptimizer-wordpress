<?php
/**
 * Offer to delete the VideoOptimizer video after a media library video was deleted.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Media;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress asks "delete permanently?" itself and offers no hook to extend that dialog, so the
 * question is asked right afterwards: the deleted attachment's video is remembered per user and
 * the admin UI offers "also delete in VideoOptimizer / keep". Depending on the setting
 * `delete_behavior` the video is instead always kept or deleted right away — but never while it
 * is still used elsewhere (then the user is always asked, with the list of usages).
 */
class DeleteOffers {

	public const META    = '_videooptimizer_delete_offers';
	public const MAX_AGE = WEEK_IN_SECONDS;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private Client $client;

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
	 * @param Client          $client      API client.
	 * @param EmbedRepository $embeds      Embed cache.
	 * @param Attachments     $attachments Attachment mapping.
	 */
	public function __construct( Settings $settings, Client $client, EmbedRepository $embeds, Attachments $attachments ) {
		$this->settings    = $settings;
		$this->client      = $client;
		$this->embeds      = $embeds;
		$this->attachments = $attachments;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		// Runs before WordPress removes the attachment's meta.
		add_action( 'delete_attachment', array( $this, 'on_delete' ), 10, 1 );
	}

	/**
	 * Handles a deleted attachment according to `delete_behavior`.
	 *
	 * @param int $id Attachment id.
	 */
	public function on_delete( int $id ): void {
		$state = $this->attachments->state( $id );
		if ( '' === $state['uuid'] ) {
			return;
		}
		$uuid     = $state['uuid'];
		$behavior = $this->settings->string( 'delete_behavior' );

		// Another media library item still delivers the same video: never offer to delete it.
		if ( array() !== array_diff( $this->attachments->find_by_uuid( $uuid ), array( $id ) ) ) {
			return;
		}
		if ( 'keep' === $behavior ) {
			return;
		}
		if ( 'delete' === $behavior && array() === Usage::find( $uuid, $id ) ) {
			try {
				$this->client->delete_video( $uuid );
				$this->embeds->forget( $uuid );

				return;
			} catch ( ApiException $e ) {
				unset( $e ); // Fall back to asking.
			}
		}

		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return; // Deleted by cron/CLI – nobody to ask.
		}
		$offers          = $this->offers( $user );
		$offers[ $uuid ] = array(
			'uuid'  => $uuid,
			'title' => get_the_title( $id ),
			'time'  => time(),
		);
		update_user_meta( $user, self::META, $offers );
	}

	/**
	 * Open offers of a user (older ones expire), each with its current usages.
	 *
	 * @param int  $user       User id.
	 * @param bool $with_usage Include usage lists.
	 * @return array<string, array<string, mixed>>
	 */
	public function offers( int $user, bool $with_usage = false ): array {
		$offers = get_user_meta( $user, self::META, true );
		$offers = is_array( $offers ) ? $offers : array();
		$fresh  = array_filter(
			$offers,
			static fn ( $offer ): bool => is_array( $offer ) && (int) ( $offer['time'] ?? 0 ) > time() - self::MAX_AGE
		);
		if ( count( $fresh ) !== count( $offers ) ) {
			update_user_meta( $user, self::META, $fresh );
		}
		if ( $with_usage ) {
			foreach ( $fresh as $uuid => $offer ) {
				$fresh[ $uuid ]['usage'] = Usage::find( (string) $uuid );
			}
		}

		return $fresh;
	}

	/**
	 * Resolves an offer: delete the video in VideoOptimizer or keep it.
	 *
	 * @param int    $user   User id.
	 * @param string $uuid   Video uuid.
	 * @param bool   $delete Delete in VideoOptimizer.
	 * @throws ApiException When the deletion fails (the offer stays open).
	 */
	public function resolve( int $user, string $uuid, bool $delete ): void {
		if ( $delete ) {
			try {
				$this->client->delete_video( $uuid );
			} catch ( ApiException $e ) {
				if ( 404 !== $e->status() ) {
					throw $e;
				}
			}
			$this->embeds->forget( $uuid );
		}
		$offers = $this->offers( $user );
		unset( $offers[ strtolower( $uuid ) ] );
		update_user_meta( $user, self::META, $offers );
	}
}
