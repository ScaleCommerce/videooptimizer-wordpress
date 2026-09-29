<?php
/**
 * Attribute schema.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use ScaleCommerce\VideoOptimizer\Render\Attributes;

final class AttributesTest extends TestCase {

	private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

	public function test_defaults(): void {
		$a = Attributes::normalize( array() );

		$this->assertSame( '', $a['uuid'] );
		$this->assertSame( '', $a['player'] );
		$this->assertSame( 'left', $a['side'] );
		$this->assertSame( 'large', $a['height'] );
		$this->assertSame( 3, $a['columns'] );
		$this->assertFalse( $a['priority'] );
		$this->assertSame( array(), $a['items'] );
	}

	public function test_shortcode_keys_and_values_are_mapped(): void {
		$a = Attributes::normalize(
			array(
				'video'          => strtoupper( self::UUID ),
				'player'         => 'embed',
				'autoplay'       => 'true',
				'muted'          => 'no',
				'cta_label'      => 'Shop',
				'cta-url'        => '/shop',
				'headlinecolor'  => '#fff',
				'priority'       => 'yes',
				'unknown'        => 'dropped',
			)
		);

		$this->assertSame( self::UUID, $a['uuid'] );
		$this->assertSame( 'hosted', $a['player'] );
		$this->assertSame( '1', $a['autoplay'] );
		$this->assertSame( '0', $a['muted'] );
		$this->assertSame( 'Shop', $a['ctaLabel'] );
		$this->assertSame( '/shop', $a['ctaUrl'] );
		$this->assertSame( '#fff', $a['headlineColor'] );
		$this->assertTrue( $a['priority'] );
		$this->assertArrayNotHasKey( 'unknown', $a );
	}

	public function test_invalid_enums_fall_back(): void {
		$a = Attributes::normalize(
			array(
				'player'       => 'flash',
				'presentation' => 'popup',
				'side'         => 'top',
				'columns'      => 99,
			)
		);

		$this->assertSame( '', $a['player'] );
		$this->assertSame( '', $a['presentation'] );
		$this->assertSame( 'left', $a['side'] );
		$this->assertSame( 4, $a['columns'] );
	}

	/**
	 * @dataProvider urls
	 */
	public function test_safe_url( string $input, string $expected ): void {
		$this->assertSame( $expected, Attributes::safe_url( $input ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function urls(): array {
		return array(
			'https'             => array( 'https://example.com/x', 'https://example.com/x' ),
			'relative'          => array( '/shop', '/shop' ),
			'mailto'            => array( 'mailto:a@b.c', 'mailto:a@b.c' ),
			'tel'               => array( 'tel:+49123', 'tel:+49123' ),
			'javascript'        => array( 'javascript:alert(1)', '' ),
			'javascript-ctrl'   => array( "java\tscript:alert(1)", '' ),
			'data'              => array( 'data:text/html,x', '' ),
			'protocol-relative' => array( '//evil.example', '' ),
			'backslash'         => array( '\\\\evil', '' ),
		);
	}

	public function test_safe_color(): void {
		$this->assertSame( '#abc', Attributes::safe_color( '#abc' ) );
		$this->assertSame( '#aabbccdd', Attributes::safe_color( '#aabbccdd' ) );
		$this->assertSame( '', Attributes::safe_color( 'red;background:url(x)' ) );
		$this->assertSame( '', Attributes::safe_color( '#abcde' ) );
	}

	public function test_items_from_shortcode_string(): void {
		$a = Attributes::normalize( array( 'items' => self::UUID . '|First, not-a-uuid|x, ' . self::UUID ) );

		$this->assertSame(
			array(
				array(
					'uuid'  => self::UUID,
					'label' => 'First',
				),
				array(
					'uuid'  => self::UUID,
					'label' => '',
				),
			),
			$a['items']
		);
	}

	public function test_html_text_is_sanitized(): void {
		$a = Attributes::normalize( array( 'text' => '<p>Hi</p><script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '<script>', $a['text'] );
	}
}
