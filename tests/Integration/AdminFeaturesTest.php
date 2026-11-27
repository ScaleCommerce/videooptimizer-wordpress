<?php
/**
 * REST routes of the 2.0.0 admin features against a real WordPress.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Integration;

final class AdminFeaturesTest extends IntegrationTestCase {

	public function test_new_routes_require_capabilities(): void {
		$this->login_as( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', '/tags' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/subtitle-languages' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/videos/' . self::UUID . '/subtitles' )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', '/tags' )->get_status() );

		$this->login_as( 'editor' );
		$this->configure_token();
		$this->assertSame( 200, $this->rest( 'GET', '/tags' )->get_status() );
		$this->assertSame( 'de', $this->rest( 'GET', '/subtitle-languages' )->get_data()[0]['code'] );
		$this->assertSame(
			403,
			$this->rest( 'POST', '/subtitle-languages', array( 'code' => 'fr' ) )->get_status(),
			'editors cannot manage organization languages'
		);
		$this->assertSame( 403, $this->rest( 'DELETE', '/subtitle-languages/de' )->get_status() );
	}

	public function test_administrators_manage_subtitle_languages(): void {
		$this->login_as( 'administrator' );
		$this->configure_token();

		$created = $this->rest( 'POST', '/subtitle-languages', array( 'code' => 'en-US', 'label' => 'English' ) );
		$this->assertSame( 200, $created->get_status() );
		$this->assertSame( array( 'code' => 'en-US', 'label' => 'English' ), json_decode( $this->write_bodies()[0], true ) );

		$this->assertSame( 400, $this->rest( 'POST', '/subtitle-languages', array( 'code' => 'not valid!' ) )->get_status() );
	}

	public function test_subtitle_upload_sends_multipart_and_drops_the_cached_embed(): void {
		$this->login_as( 'editor' );
		$this->configure_token();
		$embeds = \ScaleCommerce\VideoOptimizer\Plugin::instance()->embeds;
		$this->assertNotNull( $embeds->get( self::UUID ), 'warms the embed cache' );
		$this->assertNotFalse( get_transient( 'videooptimizer_embed_' . self::UUID ) );

		$path = tempnam( sys_get_temp_dir(), 'vosub' );
		file_put_contents( $path, "WEBVTT\n\n00:00.000 --> 00:01.000\nHallo\n" );
		$request = new \WP_REST_Request( 'POST', '/videooptimizer/v1/videos/' . self::UUID . '/subtitles' );
		$request->set_body_params( array( 'language' => 'de', 'is_default' => 'true' ) );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'de.vtt',
					'tmp_name' => $path,
					'size'     => (int) filesize( $path ),
					'error'    => UPLOAD_ERR_OK,
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		unlink( $path );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['skipped_cues'] );
		$body = $this->write_bodies()[0];
		$this->assertStringContainsString( 'name="language"' . "\r\n\r\n" . 'de', $body );
		$this->assertStringContainsString( 'name="is_default"' . "\r\n\r\n" . 'true', $body );
		$this->assertStringContainsString( 'filename="de.vtt"', $body );
		$this->assertStringContainsString( 'Hallo', $body );
		$this->assertFalse( get_transient( 'videooptimizer_embed_' . self::UUID ), 'tracks live in the embed data' );
	}
}
