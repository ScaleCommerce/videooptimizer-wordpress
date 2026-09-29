<?php
/**
 * Fallback status polling.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Webhooks are the fast path for status updates, but they need a public URL and manual setup.
 * This WP-Cron job polls every five minutes while any media library video is still processing
 * and unschedules itself once nothing is left.
 */
class StatusSync {

	public const HOOK     = 'videooptimizer_sync_status';
	public const SCHEDULE = 'videooptimizer_five_minutes';

	/**
	 * Attachment mapping.
	 *
	 * @var Attachments
	 */
	private Attachments $attachments;

	/**
	 * Constructor.
	 *
	 * @param Attachments $attachments Attachment mapping.
	 */
	public function __construct( Attachments $attachments ) {
		$this->attachments = $attachments;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minutes is intended.
		add_action( self::HOOK, array( $this, 'handle' ) );
	}

	/**
	 * Adds the five-minute interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every five minutes (VideoOptimizer)',
		);

		return $schedules;
	}

	/**
	 * Ensures the job is scheduled.
	 */
	public static function schedule(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Removes the job.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron callback.
	 */
	public function handle(): void {
		$this->run();
	}

	/**
	 * Refreshes all processing attachments; unschedules when none remain.
	 *
	 * @return int Number of attachments checked.
	 */
	public function run(): int {
		$ids = $this->attachments->processing_ids( 50 );
		foreach ( $ids as $id ) {
			$this->attachments->refresh( $id );
		}
		if ( array() === $this->attachments->processing_ids( 1 ) ) {
			self::unschedule();
		}

		return count( $ids );
	}
}
