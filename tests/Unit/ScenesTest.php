<?php
/**
 * Scene helpers.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use ScaleCommerce\VideoOptimizer\Render\Scenes;

final class ScenesTest extends TestCase {

	public function test_indices_are_spread_evenly(): void {
		$this->assertSame( array( 0, 5, 9 ), Scenes::indices( 3 ) );
		$this->assertSame( array( 0, 2, 5, 7, 9 ), Scenes::indices( 5 ) );
		$this->assertSame( range( 0, 9 ), Scenes::indices( 10 ) );
	}

	public function test_indices_are_clamped_to_three_to_ten_scenes(): void {
		$this->assertCount( 3, Scenes::indices( 1 ) );
		$this->assertCount( 10, Scenes::indices( 99 ) );
	}

	public function test_start_is_where_the_encoder_sampled_the_frame(): void {
		// 110 s: frames at 10, 20, … 100 s.
		$this->assertSame( 10, Scenes::start( 110, 0 ) );
		$this->assertSame( 100, Scenes::start( 110, 9 ) );
		$this->assertSame( 9, Scenes::start( 100, 0 ) ); // floor( 100 / 11 ).
		$this->assertSame( 90, Scenes::start( 100, 9 ) ); // floor( 100 / 11 * 10 ).
	}

	public function test_clock(): void {
		$this->assertSame( '00:09', Scenes::clock( 9 ) );
		$this->assertSame( '01:05', Scenes::clock( 65 ) );
		$this->assertSame( '1:01:05', Scenes::clock( 3665 ) );
		$this->assertSame( '00:00', Scenes::clock( -3 ) );
	}

	public function test_thumbnail_url_does_not_duplicate_the_api_path(): void {
		$this->assertSame(
			'https://api.videooptimizer.eu/api/v1/videos/abc/thumbnails/3',
			Scenes::thumbnail_url( 'https://api.videooptimizer.eu/api/v1/', 'abc', 3 )
		);
	}

	public function test_titles_split_on_lines_and_pipes(): void {
		$this->assertSame( array( 'Intro', '', 'End' ), Scenes::titles( "Intro\n\r\nEnd" ) );
		$this->assertSame( array( 'A', 'B' ), Scenes::titles( 'A|B' ) );
	}
}
