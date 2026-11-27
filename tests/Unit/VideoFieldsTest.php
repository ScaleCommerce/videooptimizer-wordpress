<?php
/**
 * Tag, metadata and subtitle language validation.
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

	public function test_removals_do_not_count_towards_the_ten_key_limit(): void {
		$metadata = array_combine( array_map( static fn ( $i ) => 'k' . $i, range( 1, 10 ) ), array_fill( 0, 10, 'v' ) );
		$metadata['gone1'] = null;
		$metadata['gone2'] = '';

		$this->assertCount( 12, VideoFields::metadata( $metadata ) );
	}

	public function test_non_scalar_values_are_rejected(): void {
		foreach ( array( fn () => VideoFields::tags( array( array( 'x' ) ) ), fn () => VideoFields::metadata( array( 'k' => array( 'x' ) ) ) ) as $call ) {
			try {
				$call();
				$this->fail( 'Expected an exception.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_metadata_strips_control_characters(): void {
		$this->assertSame( array( 'k' => 'ab' ), VideoFields::metadata( array( 'k' => "a\x00b\n" ) ) );
	}

	public function test_language_codes_are_validated_as_bcp47(): void {
		foreach ( array( 'de', 'en-US', 'de-x-simple', 'zh-Hans-CN' ) as $code ) {
			$this->assertSame( $code, VideoFields::language( $code ) );
		}
		foreach ( array( '', 'd', 'de_DE', 'de/../x', str_repeat( 'a', 40 ), 'de en' ) as $code ) {
			try {
				VideoFields::language( $code );
				$this->fail( 'Expected an exception for ' . $code );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_subtitle_kind_defaults_and_validates(): void {
		$this->assertSame( 'captions', VideoFields::subtitle_kind( 'captions' ) );
		$this->assertNull( VideoFields::subtitle_kind( '' ) );
		$this->expectException( \InvalidArgumentException::class );
		VideoFields::subtitle_kind( 'lyrics' );
	}
}
