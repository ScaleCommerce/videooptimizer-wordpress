<?php
/**
 * Minimal WordPress class stubs for unit tests.
 *
 * @package VideoOptimizer
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->message = $message;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
