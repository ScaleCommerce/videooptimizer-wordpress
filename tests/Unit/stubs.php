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
		private mixed $data;
		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_data(): mixed {
			return $this->data;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_content = '';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		private array $json;
		public function __construct( array $json = array(), private array $params = array(), private array $files = array() ) {
			$this->json = $json;
		}
		public function get_file_params(): array {
			return $this->files;
		}
		public function get_json_params(): array {
			return $this->json;
		}
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}
		public function offsetExists( mixed $offset ): bool {
			return isset( $this->params[ $offset ] );
		}
		public function offsetGet( mixed $offset ): mixed {
			return $this->params[ $offset ] ?? null;
		}
		public function offsetSet( mixed $offset, mixed $value ): void {
			$this->params[ $offset ] = $value;
		}
		public function offsetUnset( mixed $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}
