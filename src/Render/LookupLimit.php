<?php
/**
 * Site-wide limit for lookups driven by query strings.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Render;

defined( 'ABSPATH' ) || exit;

/**
 * A visitor can make the site ask the API about any uuid or cursor by editing the URL. New
 * (uncached) lookups of that kind share one budget of MAX_PER_MINUTE a minute for the whole site;
 * beyond it callers answer "temporarily unavailable" (never a cacheable 404) without calling the API.
 */
final class LookupLimit {

	public const MAX_PER_MINUTE = 30;
	public const KEY            = 'videooptimizer_lookup_rate';

	/**
	 * Takes one lookup from the budget.
	 *
	 * @return bool False when the budget of this minute is used up.
	 */
	public static function reserve(): bool {
		$count = (int) get_transient( self::KEY );
		if ( $count >= self::MAX_PER_MINUTE ) {
			return false;
		}
		if ( 0 === $count ) {
			set_transient( self::KEY, 1, MINUTE_IN_SECONDS );

			return true;
		}
		// Keeps the window of the first lookup: only the counter changes.
		$timeout = (int) get_option( '_transient_timeout_' . self::KEY, 0 );
		set_transient( self::KEY, $count + 1, $timeout > time() ? $timeout - time() : MINUTE_IN_SECONDS );

		return true;
	}
}
