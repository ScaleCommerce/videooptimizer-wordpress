<?php
/**
 * Plugin settings storage.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * All settings live in one autoloaded option. Secrets (API token, webhook secret) are write-only:
 * they are stored encrypted and never returned to the browser — only whether they are set.
 *
 * The token can also be pinned in wp-config.php via the VIDEOOPTIMIZER_API_TOKEN constant, which
 * then takes precedence and locks the settings field.
 */
final class Settings {

	public const OPTION         = 'videooptimizer_settings';
	public const WEBHOOK_STATUS = 'videooptimizer_webhook_status';

	public const PLAYERS       = array( 'hosted', 'native' );
	public const PRESENTATIONS = array( 'facade', 'lightbox', 'direct' );
	public const TRANSFERS     = array( 'auto', 'browser' );
	public const POSITIONS     = array( 'end', 'second' );
	public const DELETE        = array( 'ask', 'keep', 'delete' );

	/**
	 * Cipher for secrets.
	 *
	 * @var TokenCipher|null
	 */
	private ?TokenCipher $cipher;

	/**
	 * Per-request memo of the decrypted token.
	 *
	 * @var string|null|false false = not resolved yet.
	 */
	private string|null|false $token_memo = false;

	/**
	 * Constructor.
	 *
	 * @param TokenCipher|null $cipher Cipher (defaults to one keyed with the site salts).
	 */
	public function __construct( ?TokenCipher $cipher = null ) {
		$this->cipher = $cipher;
	}

	/**
	 * Default values for every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'token'                => '',
			'webhook_secret'       => '',
			'default_library'      => '',
			'default_player'       => 'hosted',
			'default_presentation' => 'facade',
			'media_integration'    => true,
			'auto_send'            => false,
			'transfer'             => 'auto',
			'delete_behavior'      => 'ask',
			'replace_core_video'   => true,
			'woo_gallery'          => true,
			'woo_gallery_position' => 'end',
			'woo_tab'              => true,
			'woo_tab_title'        => '',
			'woo_hover'            => true,
			'schema'               => true,
		);
	}

	/**
	 * All raw settings merged with defaults (secrets stay encrypted).
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * A single setting value.
	 *
	 * @param string $key Setting key.
	 */
	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Boolean setting helper.
	 *
	 * @param string $key Setting key.
	 */
	public function flag( string $key ): bool {
		return (bool) $this->get( $key );
	}

	/**
	 * String setting helper.
	 *
	 * @param string $key Setting key.
	 */
	public function string( string $key ): string {
		$value = $this->get( $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Settings safe to expose to the admin UI (secrets reduced to booleans).
	 *
	 * @return array<string, mixed>
	 */
	public function public_view(): array {
		$all = $this->all();
		unset( $all['token'], $all['webhook_secret'] );

		$all['token_configured']   = null !== $this->token();
		$all['token_source']       = $this->token_source();
		$all['token_unreadable']   = 'option' === $this->token_source() && null === $this->token();
		$all['webhook_configured'] = null !== $this->webhook_secret();
		$all['webhook_status']     = get_option( self::WEBHOOK_STATUS, null );

		return $all;
	}

	/**
	 * Validates and stores non-secret settings. Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $input Raw input.
	 */
	public function update( array $input ): void {
		$current = $this->all();

		foreach ( array( 'media_integration', 'auto_send', 'replace_core_video', 'woo_gallery', 'woo_tab', 'woo_hover', 'schema' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$current[ $flag ] = (bool) filter_var( $input[ $flag ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		$enums = array(
			'default_player'       => self::PLAYERS,
			'default_presentation' => self::PRESENTATIONS,
			'transfer'             => self::TRANSFERS,
			'woo_gallery_position' => self::POSITIONS,
			'delete_behavior'      => self::DELETE,
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$current[ $key ] = $input[ $key ];
			}
		}

		if ( array_key_exists( 'default_library', $input ) ) {
			$library                    = is_string( $input['default_library'] ) ? $input['default_library'] : '';
			$current['default_library'] = preg_match( '/^[A-Za-z0-9-]{1,64}$/', $library ) ? $library : '';
		}
		if ( array_key_exists( 'woo_tab_title', $input ) ) {
			$current['woo_tab_title'] = sanitize_text_field( is_string( $input['woo_tab_title'] ) ? $input['woo_tab_title'] : '' );
		}

		update_option( self::OPTION, $current );
	}

	/**
	 * Where the token comes from: 'constant', 'option' or 'none'.
	 */
	public function token_source(): string {
		if ( defined( 'VIDEOOPTIMIZER_API_TOKEN' ) && is_string( constant( 'VIDEOOPTIMIZER_API_TOKEN' ) ) && '' !== constant( 'VIDEOOPTIMIZER_API_TOKEN' ) ) {
			return 'constant';
		}

		return '' !== $this->string( 'token' ) ? 'option' : 'none';
	}

	/**
	 * The decrypted API token, or null when none is configured (or it cannot be decrypted).
	 */
	public function token(): ?string {
		if ( false !== $this->token_memo ) {
			return $this->token_memo;
		}

		if ( 'constant' === $this->token_source() ) {
			$this->token_memo = (string) constant( 'VIDEOOPTIMIZER_API_TOKEN' );

			return $this->token_memo;
		}

		$stored           = $this->string( 'token' );
		$this->token_memo = '' === $stored ? null : $this->cipher()->decrypt( $stored );

		return $this->token_memo;
	}

	/**
	 * Forgets the per-request token memo (after the option changed outside this object).
	 */
	public function flush(): void {
		$this->token_memo = false;
	}

	/**
	 * Stores (encrypted) or clears the API token.
	 *
	 * @param string $token Plain token; empty string clears it.
	 */
	public function set_token( string $token ): void {
		$token            = trim( $token );
		$all              = $this->all();
		$all['token']     = '' === $token ? '' : $this->cipher()->encrypt( $token );
		$this->token_memo = false;
		update_option( self::OPTION, $all );
	}

	/**
	 * The decrypted webhook signing secret, or null.
	 */
	public function webhook_secret(): ?string {
		$stored = $this->string( 'webhook_secret' );

		return '' === $stored ? null : $this->cipher()->decrypt( $stored );
	}

	/**
	 * Stores (encrypted) or clears the webhook signing secret.
	 *
	 * @param string $secret Plain secret; empty string clears it.
	 */
	public function set_webhook_secret( string $secret ): void {
		$secret                = trim( $secret );
		$all                   = $this->all();
		$all['webhook_secret'] = '' === $secret ? '' : $this->cipher()->encrypt( $secret );
		update_option( self::OPTION, $all );
	}

	/**
	 * VideoOptimizer REST API base URL (overridable for staging via constant or filter).
	 */
	public static function api_base_url(): string {
		$url = defined( 'VIDEOOPTIMIZER_API_BASE_URL' ) ? (string) constant( 'VIDEOOPTIMIZER_API_BASE_URL' ) : 'https://api.videooptimizer.eu/api/v1';

		/**
		 * Filters the VideoOptimizer API base URL. Must be https.
		 *
		 * @param string $url Base URL without trailing slash.
		 */
		return rtrim( (string) apply_filters( 'videooptimizer_api_base_url', $url ), '/' );
	}

	/**
	 * Base URL of the hosted player (/embed/{uuid}).
	 */
	public static function embed_base_url(): string {
		$url = defined( 'VIDEOOPTIMIZER_EMBED_BASE_URL' ) ? (string) constant( 'VIDEOOPTIMIZER_EMBED_BASE_URL' ) : 'https://videooptimizer.eu';

		/**
		 * Filters the base URL of the hosted VideoOptimizer player. Must be https.
		 *
		 * @param string $url Base URL without trailing slash.
		 */
		return rtrim( (string) apply_filters( 'videooptimizer_embed_base_url', $url ), '/' );
	}

	/**
	 * Lazily built cipher (wp_salt() is only safe to call once WordPress is loaded).
	 */
	private function cipher(): TokenCipher {
		$this->cipher ??= TokenCipher::for_site();

		return $this->cipher;
	}
}
