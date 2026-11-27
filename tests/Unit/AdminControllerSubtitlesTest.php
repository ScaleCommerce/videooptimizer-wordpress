<?php
/**
 * Subtitle routes of the admin REST controller.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Media\DeleteOffers;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Rest\AdminController;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

final class AdminControllerSubtitlesTest extends TestCase {

	private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

	/**
	 * Recorded client calls [method, args].
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	public array $calls = array();

	/**
	 * Forgotten embed uuids.
	 *
	 * @var array<int, string>
	 */
	public array $forgotten = array();

	/**
	 * Temporary files to remove.
	 *
	 * @var array<int, string>
	 */
	private array $files = array();

	protected function tear_down(): void {
		foreach ( $this->files as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		parent::tear_down();
	}

	private function controller(): AdminController {
		$test  = $this;
		$noctr = static fn ( string $class ) => ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();

		$client = new class( static fn () => 'tok', 'https://api.videooptimizer.eu/api/v1', $test ) extends Client {
			public function __construct( callable $t, string $b, private $test ) {
				parent::__construct( $t, $b );
			}
			public function list_subtitle_languages(): array {
				$this->test->calls[] = array( 'list_subtitle_languages', array() );
				return array(
					array(
						'code'  => 'de',
						'label' => 'Deutsch',
					),
				);
			}
			public function create_subtitle_language( string $code, string $label = '' ): array {
				$this->test->calls[] = array( 'create_subtitle_language', array( $code, $label ) );
				return array( 'code' => $code );
			}
			public function delete_subtitle_language( string $code ): void {
				$this->test->calls[] = array( 'delete_subtitle_language', array( $code ) );
			}
			public function list_subtitles( string $uuid ): array {
				$this->test->calls[] = array( 'list_subtitles', array( $uuid ) );
				return array( array( 'language' => 'de' ) );
			}
			public function upload_subtitle( string $uuid, string $filename, string $contents, string $language, ?string $kind = null, bool $is_default = false ): array {
				$this->test->calls[] = array( 'upload_subtitle', array( $uuid, $filename, $contents, $language, $kind, $is_default ) );
				return array( 'skipped_cues' => 0 );
			}
			public function download_subtitle( string $uuid, string $language ): string {
				$this->test->calls[] = array( 'download_subtitle', array( $uuid, $language ) );
				return "WEBVTT\n";
			}
			public function delete_subtitle( string $uuid, string $language ): void {
				$this->test->calls[] = array( 'delete_subtitle', array( $uuid, $language ) );
			}
		};

		$embeds = new class( $client, $test ) extends EmbedRepository {
			public function __construct( Client $c, private $test ) {
				parent::__construct( $c );
			}
			public function forget( string $uuid ): void {
				$this->test->forgotten[] = $uuid;
			}
		};

		return new AdminController( $noctr( Settings::class ), $client, $embeds, $noctr( Attachments::class ), $noctr( DeleteOffers::class ) );
	}

	/**
	 * Calls a private handler.
	 *
	 * @param string               $method Handler.
	 * @param array<string, mixed> $json   JSON body.
	 * @param array<string, mixed> $params URL/form params.
	 * @param array<string, mixed> $files  $_FILES-style file params.
	 * @return mixed
	 */
	private function call( string $method, array $json = array(), array $params = array(), array $files = array() ) {
		$controller = $this->controller();
		$m          = new \ReflectionMethod( $controller, $method );
		return $m->invoke( $controller, new \WP_REST_Request( $json, $params + array( 'uuid' => self::UUID ), $files ) );
	}

	/**
	 * A $_FILES entry backed by a real temporary file.
	 *
	 * @param string $name     Client file name.
	 * @param string $contents File contents.
	 * @return array<string, array<string, mixed>>
	 */
	private function upload( string $name, string $contents ): array {
		$path = tempnam( sys_get_temp_dir(), 'vosub' );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->files[] = $path;

		return array(
			'file' => array(
				'name'     => $name,
				'tmp_name' => $path,
				'size'     => strlen( $contents ),
				'error'    => UPLOAD_ERR_OK,
			),
		);
	}

	public function test_lists_languages_and_subtitles(): void {
		$this->assertSame( 'de', $this->call( 'list_subtitle_languages' )[0]['code'] );
		$this->assertSame( 'de', $this->call( 'list_subtitles' )[0]['language'] );
	}

	public function test_adds_and_removes_a_language(): void {
		$this->call( 'create_subtitle_language', array( 'code' => ' en-US ', 'label' => ' English ' ) );
		$this->call( 'delete_subtitle_language', array(), array( 'language' => 'en-US' ) );

		$this->assertSame( array( 'en-US', 'English' ), $this->calls[0][1] );
		$this->assertSame( array( 'en-US' ), $this->calls[1][1] );
	}

	public function test_rejects_malformed_language_codes(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->call( 'create_subtitle_language', array( 'code' => 'de/../x' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->call( 'delete_subtitle_language', array(), array( 'language' => '??' ) ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_uploads_the_file_bytes_and_forgets_the_embed(): void {
		$bytes = "WEBVTT\n\n00:00.000 --> 00:01.000\nHallo\n";

		$this->call( 'upload_subtitle', array(), array( 'language' => 'de', 'kind' => 'captions', 'is_default' => 'true' ), $this->upload( 'de.vtt', $bytes ) );

		$this->assertSame( array( self::UUID, 'de.vtt', $bytes, 'de', 'captions', true ), $this->calls[0][1] );
		$this->assertSame( array( self::UUID ), $this->forgotten );
	}

	public function test_upload_defaults_kind_and_default_flag(): void {
		$this->call( 'upload_subtitle', array(), array( 'language' => 'de' ), $this->upload( 'de.srt', '1' ) );

		$this->assertSame( array( null, false ), array_slice( $this->calls[0][1], 4 ) );
	}

	public function test_rejects_wrong_extension_missing_or_oversized_files(): void {
		$bad = array(
			'wrong extension' => $this->upload( 'de.txt', 'x' ),
			'missing'         => array(),
			'too large'       => array(
				'file' => array(
					'name'     => 'de.vtt',
					'tmp_name' => '/nonexistent',
					'size'     => 2 * 1024 * 1024 + 1,
					'error'    => UPLOAD_ERR_OK,
				),
			),
			'php error'       => array(
				'file' => array(
					'name'     => 'de.vtt',
					'tmp_name' => '',
					'size'     => 0,
					'error'    => UPLOAD_ERR_INI_SIZE,
				),
			),
		);
		foreach ( $bad as $label => $files ) {
			$result = $this->call( 'upload_subtitle', array(), array( 'language' => 'de' ), $files );
			$this->assertInstanceOf( \WP_Error::class, $result, $label );
		}
		$this->assertSame( array(), $this->calls );
	}

	public function test_rejects_an_invalid_language_or_kind_on_upload(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->call( 'upload_subtitle', array(), array( 'language' => 'x' ), $this->upload( 'a.vtt', 'x' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->call( 'upload_subtitle', array(), array( 'language' => 'de', 'kind' => 'lyrics' ), $this->upload( 'a.vtt', 'x' ) ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_downloads_and_deletes_a_subtitle(): void {
		$this->assertSame(
			array(
				'language' => 'de',
				'content'  => "WEBVTT\n",
			),
			$this->call( 'download_subtitle', array(), array( 'language' => 'de' ) )
		);

		$this->call( 'delete_subtitle', array(), array( 'language' => 'de' ) );
		$this->assertSame( array( self::UUID, 'de' ), $this->calls[1][1] );
		$this->assertSame( array( self::UUID ), $this->forgotten );
	}
}
