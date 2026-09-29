<?php
/**
 * Embed normalization.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use ScaleCommerce\VideoOptimizer\Render\Embed;

final class EmbedTest extends TestCase {

	private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

	/**
	 * Real-world payload shape of GET /embed/{uuid}.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$cdn = 'https://cdn.example/encoded/' . self::UUID;

		return array(
			'title'        => 'Imagefilm_final.mp4',
			'duration'     => 64.6,
			'resolution'   => '1920x1080',
			'poster'       => 'https://cdn.example/thumbnails/' . self::UUID . '/thumbnail.jpg',
			'posterSrcset' => array(
				array(
					'width'  => 480,
					'height' => 270,
					'url'    => 'https://cdn.example/p480.jpg',
				),
				array(
					'width'  => 0,
					'height' => 0,
					'url'    => 'https://cdn.example/zero.jpg',
				),
				'https://cdn.example/p1280.jpg 1280w',
			),
			'theme'        => array( 'accentColor' => '#C72876' ),
			'sources'      => array(
				array(
					'src'   => $cdn . '/hls/master.m3u8',
					'type'  => 'application/vnd.apple.mpegurl',
					'label' => 'auto',
					'codec' => 'hls',
				),
				array(
					'src'   => $cdn . '/h264/1080p.mp4',
					'type'  => 'video/mp4',
					'label' => '1080p',
					'size'  => 19775807,
				),
				array(
					'src'   => $cdn . '/h264/360p.mp4',
					'type'  => 'video/mp4',
					'label' => '360p',
				),
				array(
					// Rendition still encoding: bare CDN root.
					'src'   => 'https://cdn.example/',
					'type'  => 'video/mp4',
					'label' => '720p',
				),
				array( 'src' => '' ),
				'garbage',
			),
		);
	}

	public function test_normalize_extracts_hls_and_sorted_files(): void {
		$embed = Embed::normalize( self::UUID, $this->payload() );

		$this->assertSame( 'https://cdn.example/encoded/' . self::UUID . '/hls/master.m3u8', $embed['hls'] );
		$this->assertSame( array( '360p', '1080p' ), array_column( $embed['files'], 'label' ) );
		$this->assertSame( 19775807, $embed['files'][1]['size'] );
		$this->assertTrue( $embed['playable'] );
	}

	public function test_normalize_metadata(): void {
		$embed = Embed::normalize( self::UUID, $this->payload() );

		$this->assertSame( 'Imagefilm_final', $embed['title'] );
		$this->assertSame( 1920, $embed['width'] );
		$this->assertSame( 1080, $embed['height'] );
		$this->assertSame( 'landscape', $embed['orientation'] );
		$this->assertSame( 65, $embed['duration'] );
		$this->assertSame( 'https://cdn.example/p480.jpg 480w, https://cdn.example/p1280.jpg 1280w', $embed['srcset'] );
		$this->assertSame( '#C72876', $embed['theme']['accentColor'] );
	}

	public function test_processing_video_is_not_playable(): void {
		$embed = Embed::normalize( self::UUID, array( 'sources' => array() ) );

		$this->assertFalse( $embed['playable'] );
		$this->assertNull( $embed['poster'] );
		$this->assertNull( $embed['orientation'] );
		$this->assertSame( array(), $embed['theme'] );
	}

	public function test_smallest_mp4(): void {
		$embed = Embed::normalize( self::UUID, $this->payload() );

		$this->assertStringEndsWith( '/360p.mp4', (string) Embed::smallest_mp4( $embed ) );
		$this->assertNull( Embed::smallest_mp4( array( 'files' => array() ) ) );
	}

	/**
	 * @dataProvider durations
	 */
	public function test_iso_duration( ?int $seconds, ?string $expected ): void {
		$this->assertSame( $expected, Embed::iso_duration( $seconds ) );
	}

	/**
	 * @return array<string, array{0: ?int, 1: ?string}>
	 */
	public static function durations(): array {
		return array(
			'null'     => array( null, null ),
			'zero'     => array( 0, null ),
			'seconds'  => array( 5, 'PT5S' ),
			'minutes'  => array( 90, 'PT1M30S' ),
			'exact'    => array( 120, 'PT2M' ),
			'hours'    => array( 3725, 'PT1H2M5S' ),
		);
	}

	public function test_resolution_and_orientation(): void {
		$this->assertSame( array( 1080, 1920 ), Embed::parse_resolution( ' 1080 x 1920 ' ) );
		$this->assertSame( array( null, null ), Embed::parse_resolution( '0x0' ) );
		$this->assertSame( array( null, null ), Embed::parse_resolution( 1920 ) );
		$this->assertSame( 'portrait', Embed::orientation( 1080, 1920 ) );
		$this->assertSame( 'square', Embed::orientation( 500, 500 ) );
	}

	public function test_is_uuid(): void {
		$this->assertTrue( Embed::is_uuid( self::UUID ) );
		$this->assertTrue( Embed::is_uuid( strtoupper( self::UUID ) ) );
		$this->assertFalse( Embed::is_uuid( 'e7ab89b1' ) );
		$this->assertFalse( Embed::is_uuid( self::UUID . '/../x' ) );
		$this->assertFalse( Embed::is_uuid( null ) );
	}
}
