<?php
/**
 * Tag and metadata validation.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use ScaleCommerce\VideoOptimizer\Api\VideoFields;

final class VideoFieldsTest extends TestCase {

	public function test_tags_are_trimmed_and_deduplicated_case_insensitively(): void {
		$this->assertSame(
			array( 'Promo', '2026' ),
			VideoFields::tags( array( ' Promo ', 'promo', '', '2026' ) )
		);
	}

	public function test_tags_reject_commas_long_names_and_more_than_twenty(): void {
		foreach ( array( array( 'a,b' ), array( str_repeat( 'x', 51 ) ), array_map( 'strval', range( 1, 21 ) ) ) as $tags ) {
			try {
				VideoFields::tags( $tags );
				$this->fail( 'Expected an exception.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_tags_accept_exactly_twenty_names_of_fifty_characters(): void {
		$tags = array_map( static fn ( $i ) => str_pad( (string) $i, 50, 'x' ), range( 1, 20 ) );
		$this->assertCount( 20, VideoFields::tags( $tags ) );
	}

	public function test_metadata_sets_trims_and_marks_removals(): void {
		$this->assertSame(
			array(
				'cms_id' => 'abc',
				'old'    => null,
				'gone'   => null,
			),
			VideoFields::metadata(
				array(
					'cms_id' => '  abc ',
					'old'    => null,
					'gone'   => '',
				)
			)
		);
	}

	public function test_metadata_rejects_invalid_keys_values_and_more_than_ten_keys(): void {
		$invalid = array(
			array( 'Upper' => 'x' ),
			array( '-lead' => 'x' ),
			array( str_repeat( 'k', 41 ) => 'x' ),
			array( 'k' => str_repeat( 'v', 65 ) ),
			array_combine( array_map( static fn ( $i ) => 'k' . $i, range( 1, 11 ) ), array_fill( 0, 11, 'v' ) ),
		);
		foreach ( $invalid as $metadata ) {
			try {
				VideoFields::metadata( $metadata );
				$this->fail( 'Expected an exception.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_metadata_strips_control_characters(): void {
		$this->assertSame( array( 'k' => 'ab' ), VideoFields::metadata( array( 'k' => "a\x00b\n" ) ) );
	}
}
