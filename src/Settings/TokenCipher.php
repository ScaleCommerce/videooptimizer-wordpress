<?php
/**
 * Encrypts secrets (API token, webhook secret) at rest.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Libsodium secretbox with a key derived from the site's secret salts, so a database dump alone
 * does not reveal the token. If the salts are rotated the value can no longer be decrypted and
 * the admin is asked to enter it again.
 */
final class TokenCipher {

	/**
	 * Raw 32-byte key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string $secret Application secret the key is derived from.
	 */
	public function __construct( string $secret ) {
		$this->key = sodium_crypto_generichash( 'videooptimizer|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Builds a cipher keyed with this site's secret salts.
	 */
	public static function for_site(): self {
		return new self( wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) );
	}

	/**
	 * Encrypts a plain value into base64(nonce + ciphertext).
	 *
	 * @param string $plain Plain text.
	 */
	public function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext storage.
	}

	/**
	 * Decrypts a value produced by encrypt(); null when it is malformed or the key changed.
	 *
	 * @param string $encoded Encoded ciphertext.
	 */
	public function decrypt( string $encoded ): ?string {
		$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext storage.
		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key
		);

		return false === $plain ? null : $plain;
	}
}
