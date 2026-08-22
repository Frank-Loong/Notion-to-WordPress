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

        $this->assertStringContainsString( '<p>Hello</p>', $html );
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
        $this->assertStringContainsString( '<p>Hello</p>', $html );
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
        $this->assertStringContainsString( '<h2>Title</h2>', $html );
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
        $this->assertStringContainsString( '<li>Item</li>', $html );
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
        $this->assertStringContainsString( '<li>Item</li>', $html );
        $this->assertStringNotContainsString( '<!-- wp:', $html );
    }
}
