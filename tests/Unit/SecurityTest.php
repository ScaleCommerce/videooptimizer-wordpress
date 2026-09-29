<?php
/**
 * Token cipher + webhook signature.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use ScaleCommerce\VideoOptimizer\Rest\WebhookController;
use ScaleCommerce\VideoOptimizer\Settings\Settings;
use ScaleCommerce\VideoOptimizer\Settings\TokenCipher;

final class SecurityTest extends TestCase {

	public function test_cipher_roundtrip_uses_random_nonce(): void {
		$cipher = new TokenCipher( 'secret' );
		$a      = $cipher->encrypt( 'vp_token_123' );
		$b      = $cipher->encrypt( 'vp_token_123' );

		$this->assertNotSame( $a, $b );
		$this->assertStringNotContainsString( 'vp_token', $a );
		$this->assertSame( 'vp_token_123', $cipher->decrypt( $a ) );
	}

	public function test_cipher_rejects_wrong_key_and_garbage(): void {
		$encrypted = ( new TokenCipher( 'secret' ) )->encrypt( 'vp_token_123' );

		$this->assertNull( ( new TokenCipher( 'other' ) )->decrypt( $encrypted ) );
		$this->assertNull( ( new TokenCipher( 'secret' ) )->decrypt( 'not base64 !!' ) );
		$this->assertNull( ( new TokenCipher( 'secret' ) )->decrypt( base64_encode( 'short' ) ) );
	}

	public function test_settings_store_token_encrypted_and_hide_it(): void {
		$settings = new Settings( new TokenCipher( 'secret' ) );
		$settings->set_token( '  vp_abcdefghijk  ' );

		$stored = $this->options[ Settings::OPTION ]['token'];
		$this->assertStringNotContainsString( 'vp_', $stored );
		$this->assertSame( 'vp_abcdefghijk', $settings->token() );
		$this->assertSame( 'option', $settings->token_source() );

		$view = $settings->public_view();
		$this->assertArrayNotHasKey( 'token', $view );
		$this->assertArrayNotHasKey( 'webhook_secret', $view );
		$this->assertTrue( $view['token_configured'] );
	}

	public function test_settings_update_validates_values(): void {
		$settings = new Settings( new TokenCipher( 'secret' ) );
		$settings->update(
			array(
				'default_player'  => 'flash',
				'default_library' => '../../etc',
				'auto_send'       => '1',
				'woo_tab_title'   => '<b>Film</b>',
				'token'           => 'ignored-here',
			)
		);

		$this->assertSame( 'hosted', $settings->string( 'default_player' ) );
		$this->assertSame( '', $settings->string( 'default_library' ) );
		$this->assertTrue( $settings->flag( 'auto_send' ) );
		$this->assertSame( 'Film', $settings->string( 'woo_tab_title' ) );
		$this->assertNull( $settings->token() );
	}

	public function test_webhook_signature(): void {
		$secret = 'whsec_test';
		$body   = '{"event":"video.ready","data":{"uuid":"x"}}';
		$now    = 1_700_000_000;
		$sig    = 'sha256=' . hash_hmac( 'sha256', $now . '.' . $body, $secret );

		$this->assertTrue( WebhookController::verify( $secret, (string) $now, $body, $sig, $now + 10 ) );
		$this->assertFalse( WebhookController::verify( $secret, (string) $now, $body . ' ', $sig, $now ), 'tampered body' );
		$this->assertFalse( WebhookController::verify( 'wrong', (string) $now, $body, $sig, $now ), 'wrong secret' );
		$this->assertFalse( WebhookController::verify( $secret, (string) $now, $body, $sig, $now + 301 ), 'replay' );
		$this->assertFalse( WebhookController::verify( $secret, 'abc', $body, $sig, $now ), 'bad timestamp' );
		$this->assertFalse( WebhookController::verify( '', (string) $now, $body, $sig, $now ), 'no secret' );
	}
}
