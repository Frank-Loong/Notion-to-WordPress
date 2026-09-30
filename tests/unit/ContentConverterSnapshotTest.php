<?php
/**
 * Golden snapshots for representative Notion content blocks.
 */

declare(strict_types=1);

use NTWP\Services\API;
use NTWP\Services\Content_Converter;
use PHPUnit\Framework\TestCase;

final class ContentConverterSnapshotTest extends TestCase {
	/** @var array<int, array<string, mixed>> */
	private array $blocks;

	/** @var array<string, string> */
	private array $snapshots;

	private API $api;

	protected function setUp(): void {
		$fixture_path  = dirname( __DIR__ ) . '/fixtures/notion/core-content-blocks.json';
		$snapshot_path = dirname( __DIR__ ) . '/snapshots/content-core.sha256.json';

		$this->blocks    = json_decode( (string) file_get_contents( $fixture_path ), true, 512, JSON_THROW_ON_ERROR );
		$this->snapshots = json_decode( (string) file_get_contents( $snapshot_path ), true, 512, JSON_THROW_ON_ERROR );
		$this->api       = ( new ReflectionClass( API::class ) )->newInstanceWithoutConstructor();

		$GLOBALS['__wp_options'] = array();
		Content_Converter::clear_preprocessed_data();
	}

	private function convert( string $format, bool $performance_mode ): string {
		$GLOBALS['__wp_options']['notion_to_wordpress_options'] = array(
			'enable_performance_mode' => $performance_mode ? 1 : 0,
			'output_format'           => $format,
		);

		return Content_Converter::convert_blocks_to_html( $this->blocks, $this->api );
	}

	public function test_fixture_covers_the_core_block_matrix(): void {
		$types = array_column( $this->blocks, 'type' );

		$this->assertCount( 15, $this->blocks );
		$this->assertSame(
			array(
				'paragraph',
				'heading_2',
				'bulleted_list_item',
				'bulleted_list_item',
				'numbered_list_item',
				'to_do',
				'quote',
				'code',
				'equation',
				'divider',
				'bookmark',
				'table',
				'toggle',
				'callout',
				'future_block',
			),
			$types
		);
	}

	/**
	 * @dataProvider outputFormatProvider
	 */
	public function test_output_matches_golden_snapshot( string $format ): void {
		$html = $this->convert( $format, false );

		$this->assertSame( $this->snapshots[ $format ], hash( 'sha256', $html ) );
	}

	/**
	 * @dataProvider outputFormatProvider
	 */
	public function test_performance_mode_is_semantically_identical( string $format ): void {
		$normal    = $this->convert( $format, false );
		$optimized = $this->convert( $format, true );

		$this->assertSame( $normal, $optimized );
	}

	public function test_classic_snapshot_preserves_structure_and_escapes_content(): void {
		$html = $this->convert( 'classic', false );

		$this->assertStringContainsString( 'Hello &lt;Notion&gt; &amp; WordPress', $html );
		$this->assertStringNotContainsString( 'Hello <Notion>', $html );
		$this->assertStringContainsString( '<ul><li id="notion-block-block-bullet-1"', $html );
		$this->assertStringContainsString( 'Bullet two<p id="notion-block-block-nested-paragraph"', $html );
		$this->assertStringContainsString( '<pre id="notion-block-block-code"', $html );
		$this->assertStringContainsString( 'if (a &lt; b &amp;&amp; b &gt; 0)', $html );
		$this->assertStringContainsString( 'data-expression="E = mc^2"', $html );
		$this->assertStringContainsString( '<table id="notion-block-block-table"', $html );
		$this->assertStringContainsString( '<th>Name</th><th>Value</th>', $html );
		$this->assertStringContainsString( '<!-- 未支持的块类型: future_block -->', $html );
		$this->assertStringNotContainsString( 'notion-block-block-unsupported', $html );
	}

	public function test_gutenberg_snapshot_keeps_valid_list_hierarchy(): void {
		$html = $this->convert( 'gutenberg', false );

		$this->assertStringContainsString( '<!-- wp:paragraph -->', $html );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $html );
		$this->assertMatchesRegularExpression( '/<ul><!-- wp:list-item -->\s*<li id="notion-block-block-bullet-1"/', $html );
		$this->assertMatchesRegularExpression( '/<ol><!-- wp:list-item -->\s*<li id="notion-block-block-numbered"/', $html );
		$this->assertStringNotContainsString( '<ul><div', $html );
		$this->assertStringNotContainsString( '<ol><div', $html );
		$this->assertStringContainsString( '<!-- wp:separator -->', $html );
		$this->assertStringContainsString( '<!-- /wp:separator -->', $html );
	}

	public function test_empty_and_unknown_blocks_degrade_without_throwing(): void {
		$this->assertSame( '<!-- 无效的块数据 -->', Content_Converter::convert_block_to_html( array(), $this->api ) );
		$this->assertSame(
			'<!-- 未支持的块类型: future_block -->',
			Content_Converter::convert_block_to_html(
				array(
					'id'           => 'unknown',
					'type'         => 'future_block',
					'has_children' => false,
				),
				$this->api
			)
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function outputFormatProvider(): array {
		return array(
			'classic'   => array( 'classic' ),
			'gutenberg' => array( 'gutenberg' ),
		);
	}
}
