<?php
/**
 * Content_Converter Gutenberg 输出测试
 *
 * 验证 P1-02：output_format = 'gutenberg' 时，块被包裹为原生 wp: 区块标记；
 * 'classic' 时不产生任何 wp: 注释（向后兼容）。
 *
 * 为避免触发优化路径中的 Logger/Performance_Monitor 等依赖，测试统一关闭性能模式。
 *
 * @since 2.0.0
 */

use NTWP\Services\Content_Converter;

class ContentConverterTest extends \PHPUnit\Framework\TestCase {

    /**
     * 构造一个最小可用的 Notion API 模拟对象（不调用原构造器）。
     *
     * @return \NTWP\Services\API
     */
    private function make_api_mock() {
        return $this->getMockBuilder( \NTWP\Services\API::class )
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * 构造一段 Notion rich_text（纯文本）。
     */
    private function rich_text( string $text ): array {
        return [ [ 'type' => 'text', 'plain_text' => $text, 'annotations' => [], 'href' => null ] ];
    }

    /**
     * 设置插件选项并调用转换，返回 HTML。
     */
    private function convert( array $blocks, string $output_format ): string {
        $GLOBALS['__wp_options']['notion_to_wordpress_options'] = [
            'enable_performance_mode' => 0,
            'output_format'           => $output_format,
        ];
        $api = $this->make_api_mock();
        return Content_Converter::convert_blocks_to_html( $blocks, $api, null );
    }

    /**
     * 经典模式：段落不应包含任何 wp: 区块注释。
     */
    public function test_classic_paragraph_has_no_gutenberg_comments(): void {
        $blocks = [
            [ 'id' => 'b1', 'type' => 'paragraph', 'paragraph' => [ 'rich_text' => $this->rich_text( 'Hello' ) ] ],
        ];

        $html = $this->convert( $blocks, 'classic' );

        $this->assertStringContainsString( 'Hello</p>', $html );
        $this->assertStringNotContainsString( '<!-- wp:', $html );
    }

    /**
     * Gutenberg 模式：段落应被 wp:paragraph 包裹。
     */
    public function test_gutenberg_paragraph_is_wrapped(): void {
        $blocks = [
            [ 'id' => 'b1', 'type' => 'paragraph', 'paragraph' => [ 'rich_text' => $this->rich_text( 'Hello' ) ] ],
        ];

        $html = $this->convert( $blocks, 'gutenberg' );

        $this->assertStringContainsString( '<!-- wp:paragraph -->', $html );
        $this->assertStringContainsString( '<!-- /wp:paragraph -->', $html );
		$this->assertStringContainsString( '<p id="notion-block-b1" class="notion-block notion-paragraph">Hello</p>', $html );
    }

    /**
     * Gutenberg 模式：标题应带 level 标注。
     */
    public function test_gutenberg_heading_carries_level(): void {
        $blocks = [
            [ 'id' => 'b2', 'type' => 'heading_2', 'heading_2' => [ 'rich_text' => $this->rich_text( 'Title' ) ] ],
        ];

        $html = $this->convert( $blocks, 'gutenberg' );

        $this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $html );
		$this->assertStringContainsString( '<h2 id="notion-block-b2" class="notion-block notion-heading_2">Title</h2>', $html );
    }

    /**
     * Gutenberg 模式：无序列表应同时包裹 wp:list 容器与 wp:list-item 项。
     */
    public function test_gutenberg_bulleted_list_is_wrapped(): void {
        $blocks = [
            [ 'id' => 'b3', 'type' => 'bulleted_list_item', 'bulleted_list_item' => [ 'rich_text' => $this->rich_text( 'Item' ) ] ],
        ];

        $html = $this->convert( $blocks, 'gutenberg' );

        $this->assertStringContainsString( '<!-- wp:list -->', $html );
        $this->assertStringContainsString( '<!-- /wp:list -->', $html );
        $this->assertStringContainsString( '<!-- wp:list-item -->', $html );
        $this->assertStringContainsString( '<!-- /wp:list-item -->', $html );
        $this->assertStringContainsString( 'Item</li>', $html );
    }

    /**
     * 经典模式列表不含任何 wp: 注释。
     */
    public function test_classic_list_has_no_gutenberg_comments(): void {
        $blocks = [
            [ 'id' => 'b3', 'type' => 'bulleted_list_item', 'bulleted_list_item' => [ 'rich_text' => $this->rich_text( 'Item' ) ] ],
        ];

        $html = $this->convert( $blocks, 'classic' );

        $this->assertStringContainsString( '<ul>', $html );
        $this->assertStringContainsString( 'Item</li>', $html );
        $this->assertStringNotContainsString( '<!-- wp:', $html );
    }

	public function test_external_file_pdf_audio_and_video_blocks_preserve_metadata(): void {
		$blocks = [
			[
				'id' => 'file-1',
				'type' => 'file',
				'file' => [
					'type' => 'external',
					'external' => [ 'url' => 'https://downloads.example/manual.pdf' ],
					'name' => 'Manual.pdf',
					'caption' => $this->rich_text( 'File caption' ),
				],
			],
			[
				'id' => 'pdf-1',
				'type' => 'pdf',
				'pdf' => [
					'type' => 'external',
					'external' => [ 'url' => 'https://downloads.example/document.pdf' ],
					'caption' => $this->rich_text( 'PDF caption' ),
				],
			],
			[
				'id' => 'audio-1',
				'type' => 'audio',
				'audio' => [
					'type' => 'external',
					'external' => [ 'url' => 'https://media.example/audio.mp3' ],
					'caption' => $this->rich_text( 'Audio caption' ),
				],
			],
			[
				'id' => 'video-1',
				'type' => 'video',
				'video' => [
					'type' => 'external',
					'external' => [ 'url' => 'https://media.example/video.mp4' ],
					'caption' => $this->rich_text( 'Video caption' ),
				],
			],
		];

		$html = $this->convert( $blocks, 'classic' );

		$this->assertStringContainsString( 'Manual.pdf', $html );
		$this->assertStringContainsString( 'File caption', $html );
		$this->assertStringContainsString( 'notion-pdf-fallback', $html );
		$this->assertStringNotContainsString( 'application/pdf', $html );
		$this->assertStringContainsString( '<audio controls preload="metadata"', $html );
		$this->assertStringContainsString( 'Audio caption', $html );
		$this->assertStringContainsString( '<video controls>', $html );
		$this->assertStringContainsString( 'Video caption', $html );
	}

	public function test_video_platform_detection_does_not_trust_suffix_confusion(): void {
		$blocks = [
			[
				'id' => 'video-host-check',
				'type' => 'video',
				'video' => [
					'type' => 'external',
					'external' => [ 'url' => 'https://youtube.com.attacker.example/watch?v=dQw4w9WgXcQ' ],
				],
			],
		];

		$html = $this->convert( $blocks, 'classic' );

		$this->assertStringContainsString( '<video controls>', $html );
		$this->assertStringNotContainsString( 'youtube.com/embed/', $html );
	}

	public function test_embed_platform_detection_does_not_trust_suffix_confusion(): void {
		$blocks = [
			[
				'id' => 'embed-host-check',
				'type' => 'embed',
				'embed' => [
					'url' => 'https://youtube.com.attacker.example/watch?v=dQw4w9WgXcQ',
				],
			],
		];

		$html = $this->convert( $blocks, 'classic' );

		$this->assertStringContainsString( 'id="notion-block-embed-host-check"', $html );
		$this->assertStringContainsString( 'class="notion-embed notion-block notion-embed"', $html );
		$this->assertStringContainsString( 'notion-embed-fallback', $html );
		$this->assertStringContainsString( 'href="https://youtube.com.attacker.example/watch?v=dQw4w9WgXcQ"', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( 'youtube.com/embed/', $html );
	}
}
