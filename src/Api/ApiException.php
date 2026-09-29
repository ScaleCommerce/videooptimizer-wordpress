<?php
/**
 * API error.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Raised for any failed VideoOptimizer request. The code is the upstream HTTP status (0 when the
 * API could not be reached, 428 when no token is configured).
 */
class ApiException extends \RuntimeException {

	public const NOT_CONFIGURED = 428;
	public const UNREACHABLE    = 0;

	/**
	 * Raw upstream message, untranslated.
	 *
	 * @var string
	 */
	private string $upstream_message;

	/**
	 * Constructor.
	 *
	 * @param string          $message  Upstream (English) message.
	 * @param int             $status   HTTP status.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct( string $message, int $status = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $status, $previous );
		$this->upstream_message = $message;
	}

	/**
	 * Upstream HTTP status.
	 */
	public function status(): int {
		return (int) $this->getCode();
	}

	/**
	 * A clear, translated message for editors. Known API situations get actionable wording, anything
	 * else falls back to the upstream text.
	 */
	public function user_message(): string {
		$message = $this->upstream_message;
		$status  = $this->status();

		if ( self::NOT_CONFIGURED === $status ) {
			return __( 'VideoOptimizer is not connected yet. Please enter your API token under VideoOptimizer → Settings.', 'videooptimizer' );
		}
		if ( self::UNREACHABLE === $status ) {
			/* translators: %s: technical error message */
			return sprintf( __( 'VideoOptimizer could not be reached (%s). Please try again in a moment.', 'videooptimizer' ), $message );
		}
		if ( 401 === $status ) {
			return __( 'The API token is invalid, expired or has been revoked. Please create a new organization token in VideoOptimizer.', 'videooptimizer' );
		}
		if ( 403 === $status && false !== stripos( $message, 'limit' ) ) {
			/* translators: %s: upstream message, e.g. "Video limit of your plan reached" */
			return sprintf( __( 'Your VideoOptimizer plan limit has been reached (%s). Please upgrade your plan or delete unused videos.', 'videooptimizer' ), $message );
		}
		if ( 403 === $status ) {
			/* translators: %s: upstream message */
			return sprintf( __( 'The API token is missing a permission for this action (%s). Use an organization token.', 'videooptimizer' ), $message );
		}
		if ( 404 === $status ) {
			return __( 'The video or library no longer exists in VideoOptimizer.', 'videooptimizer' );
		}
		if ( 413 === $status ) {
			return __( 'The file is too large for VideoOptimizer.', 'videooptimizer' );
		}
		if ( 415 === $status ) {
			return __( 'This file type is not supported.', 'videooptimizer' );
		}
		if ( 429 === $status ) {
			return __( 'Too many requests to VideoOptimizer. Please wait a minute and try again.', 'videooptimizer' );
		}
		if ( 400 === $status && false !== stripos( $message, 'managed' ) ) {
			return __( 'This library is delivery-only (not media-managed) — uploads and poster changes are not possible here.', 'videooptimizer' );
		}

		return '' !== $message ? $message : __( 'The VideoOptimizer request failed.', 'videooptimizer' );
	}
}
