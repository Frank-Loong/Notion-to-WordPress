<?php
declare(strict_types=1);

use NTWP\Services\Content_Converter;
use NTWP\Services\Database_Renderer;

final class KnowledgeBasePresentationTest extends PHPUnit\Framework\TestCase {
    protected function setUp(): void {
        $GLOBALS['__wp_options'] = [];
    }

    private function records(): array {
        $records = [];
        for ($i = 1; $i <= 9; ++$i) {
            $records[] = [
                'id' => 'record-' . $i,
                'url' => 'https://notion.so/record-' . $i,
                'properties' => ['Name' => ['type' => 'title', 'title' => [['plain_text' => 'Entry ' . $i]]]],
            ];
        }
        return $records;
    }

    public function test_configured_list_overrides_legacy_gallery_title_and_keeps_all_entries(): void {
        $GLOBALS['__wp_options']['notion_to_wordpress_options'] = ['embedded_database_view' => 'list'];
        $html = Database_Renderer::render_database_preview_records_with_data(
            'database-1', ['title' => [['plain_text' => 'Gallery notes']], 'properties' => []], $this->records()
        );
        $this->assertStringContainsString('notion-database-list', $html);
        $this->assertSame(1, substr_count($html, '<h4'));
        $this->assertSame(9, substr_count($html, '<li>'));
        $this->assertStringContainsString('https://notion.so/record-9', $html);
    }

    public function test_explicit_view_overrides_global_setting_and_titles_are_escaped(): void {
        $GLOBALS['__wp_options']['notion_to_wordpress_options'] = ['embedded_database_view' => 'list'];
        $html = Database_Renderer::render_database([
            'view_type' => 'table',
            'database_info' => ['title' => [['plain_text' => '<script>bad</script>']], 'properties' => []],
            'records' => $this->records(),
        ]);
        $this->assertStringContainsString('notion-database-table', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame(9, substr_count($html, 'notion-table-title-cell'));
    }

    public function test_embed_metadata_prefers_open_graph_and_rejects_non_http_image(): void {
        $method = new ReflectionMethod(Content_Converter::class, 'parse_embed_metadata');
        $method->setAccessible(true);
        $metadata = $method->invoke(null, '<title>Fallback</title><meta property="og:title" content="知识库 &amp; Notes"><meta property="og:image" content="javascript:alert(1)">', 'https://example.org');
        $this->assertSame('知识库 & Notes', $metadata['title']);
        $this->assertSame('', $metadata['image']);
        $metadata = $method->invoke(null, '<meta property="og:image" content="https://example.org/cover.png">', 'https://example.org');
        $this->assertSame('https://example.org/cover.png', $metadata['image']);
    }

    public function test_embed_metadata_failure_preserves_source_link(): void {
        $method = new ReflectionMethod(Content_Converter::class, 'render_embed_card');
        $method->setAccessible(true);
        $html = $method->invoke(null, 'https://example.org/article');
        $this->assertStringContainsString('notion-embed-card', $html);
        $this->assertStringContainsString('href="https://example.org/article"', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_per_database_view_normalizes_ids_and_overrides_default(): void {
        $id = '123456781234123412341234567890ab';
        $GLOBALS['__wp_options']['notion_to_wordpress_options'] = [
            'embedded_database_view' => 'gallery',
            'embedded_database_views' => [$id => 'list'],
        ];
        $html = Database_Renderer::render_database_preview_records_with_data(
            '12345678-1234-1234-1234-1234567890AB', ['properties' => []], $this->records()
        );
        $this->assertStringContainsString('notion-database-list', $html);
        $this->assertStringNotContainsString('notion-database-gallery', $html);
    }

    public function test_layout_parser_accepts_chinese_labels_and_rejects_bad_configuration(): void {
        $id = '123456781234123412341234567890ab';
        $this->assertSame([$id => 'board'], NTWP\Services\Database_Config::parse_embedded_views("12345678-1234-1234-1234-1234567890AB|看板\n"));
        $this->assertSame([], NTWP\Services\Database_Config::parse_embedded_views(''));
        $this->expectException(InvalidArgumentException::class);
        NTWP\Services\Database_Config::parse_embedded_views($id . '|unsupported');
    }
}
