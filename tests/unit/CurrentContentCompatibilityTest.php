<?php
/**
 * Forward-compatibility checks for current Notion content block responses.
 */

declare(strict_types=1);

use NTWP\Services\API;
use NTWP\Services\Content_Converter;
use PHPUnit\Framework\TestCase;

final class CurrentContentCompatibilityTest extends TestCase {
	/** @var array<int, array<string, mixed>> */
	private array $blocks;

	private API $api;

	protected function setUp(): void {
		$fixture_path = dirname( __DIR__ ) . '/fixtures/notion/current-content-blocks.json';
		$this->blocks = json_decode( (string) file_get_contents( $fixture_path ), true, 512, JSON_THROW_ON_ERROR );
		$this->api    = ( new ReflectionClass( API::class ) )->newInstanceWithoutConstructor();

		$GLOBALS['__wp_options'] = array();
		Content_Converter::clear_preprocessed_data();
	}

	private function convert( string $format, bool $performance_mode = false ): string {
		$GLOBALS['__wp_options']['notion_to_wordpress_options'] = array(
			'enable_performance_mode' => $performance_mode ? 1 : 0,
			'output_format'           => $format,
		);

		return Content_Converter::convert_blocks_to_html( $this->blocks, $this->api );
	}

	public function test_fixture_covers_current_compatibility_types(): void {
		$this->assertSame(
			array( 'heading_4', 'link_preview', 'breadcrumb', 'table_of_contents', 'template', 'tab', 'meeting_notes', 'transcription', 'unsupported' ),
			array_column( $this->blocks, 'type' )
		);
	}

	/** @dataProvider outputFormatProvider */
	public function test_current_blocks_preserve_content_and_degrade_safely( string $format ): void {
		$html = $this->convert( $format );

		$this->assertStringContainsString( '<h4 id="notion-block-compat-heading-4"', $html );
		$this->assertStringContainsString( 'Fourth-level heading', $html );
		$this->assertStringContainsString( 'class="notion-link-preview notion-block notion-link_preview"', $html );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( 'class="notion-breadcrumb notion-block notion-breadcrumb"', $html );
		$this->assertStringContainsString( 'class="notion-table-of-contents notion-block notion-table_of_contents"', $html );
		$this->assertStringContainsString( 'Template label', $html );
		$this->assertStringContainsString( 'Template child body', $html );
		$this->assertStringContainsString( 'Tab title', $html );
		$this->assertStringContainsString( 'Tab child body', $html );
		$this->assertStringContainsString( 'Team sync', $html );
		$this->assertStringContainsString( 'Meeting summary body', $html );
		$this->assertStringContainsString( 'Legacy transcript', $html );
		$this->assertStringContainsString( 'data-notion-block-type="button&quot; onclick=&quot;alert(1)"', $html );
		$this->assertStringNotContainsString( '<!-- 未支持的块类型:', $html );
		$this->assertStringNotContainsString( 'onclick="alert(1)"', $html );
	}

	public function test_gutenberg_heading_four_is_a_native_parseable_block(): void {
		$html = $this->convert( 'gutenberg' );

		$this->assertStringContainsString( '<!-- wp:heading {"level":4} -->', $html );
		$this->assertStringContainsString( '<!-- /wp:heading -->', $html );
	}

	/** @dataProvider outputFormatProvider */
	public function test_optimized_path_is_semantically_identical( string $format ): void {
		$this->assertSame( $this->convert( $format, false ), $this->convert( $format, true ) );
	}

	/** @return array<string, array{0:string}> */
	public function outputFormatProvider(): array {
		return array(
			'classic'   => array( 'classic' ),
			'gutenberg' => array( 'gutenberg' ),
		);
	}
}
