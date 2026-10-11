<?php
/**
 * Incremental_Detector 单元测试
 *
 * 测试核心增量检测逻辑：内容哈希计算、属性变化分析、跳过同步判断。
 * 这些方法为纯逻辑，不依赖 Notion API，适合单元测试。
 *
 * @since 2.0.0
 * @package Notion_To_WordPress
 */

declare(strict_types=1);

namespace NTWP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NTWP\Services\Incremental_Detector;

/**
 * 由于 Incremental_Detector 的检测方法依赖 WordPress post meta 桩，
 * 本测试类聚焦验证以下行为：
 * 1. 首次同步的页面不应被跳过（should_skip_sync 返回 false）
 * 2. 内容未变化且已有哈希的页面应被跳过（should_skip_sync 返回 true）
 * 3. update_sync_hashes 后 should_skip_sync 应返回 true
 * 4. 属性变化分析能正确识别新增/修改/删除的属性
 */
final class IncrementalDetectorTest extends TestCase {

    /**
     * 每个测试前重置全局桩状态。
     */
    protected function setUp(): void {
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_posts'] = [];

        // 准备一篇已存在且已同步过的文章
        $GLOBALS['__wp_posts'][42] = [
            'post_title' => 'Test Page',
            'post_content' => 'content',
        ];
    }

    /**
     * 构造一个最小的 Notion 页面数据。
     */
    private function make_page(string $title = 'Test Page', array $properties = [], array $children = [], string $last_edited = '2025-01-01T00:00:00.000Z'): array {
        return [
            'id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'object' => 'page',
            'properties' => $properties ?: [
                'Name' => ['title' => [['plain_text' => $title]]],
            ],
            'children' => $children,
            'last_edited_time' => $last_edited,
        ];
    }

    /**
     * 测试：从未同步过的页面不应被跳过。
     */
    public function test_should_skip_sync_returns_false_for_new_post(): void {
        $page = $this->make_page();

        $result = Incremental_Detector::should_skip_sync($page, 42);

        $this->assertFalse($result, '从未同步过的页面不应被跳过');
    }

    /**
     * 测试：update_sync_hashes 后，内容未变化的页面应被跳过。
     */
    public function test_should_skip_sync_returns_true_after_hashes_updated(): void {
        $page = $this->make_page();

        // 模拟首次同步：更新哈希
        Incremental_Detector::update_sync_hashes($page, 42);

        // 确认哈希已写入
        $content_hash = get_post_meta(42, Incremental_Detector::META_CONTENT_HASH, true);
        $this->assertNotEmpty($content_hash, 'update_sync_hashes 应写入内容哈希');

        // 内容未变化，应被跳过
        $result = Incremental_Detector::should_skip_sync($page, 42);
        $this->assertTrue($result, '内容未变化的已同步页面应被跳过');
    }

    /**
     * 测试：update_sync_hashes 后修改正文，应检测到变化并需要同步。
     */
    public function test_should_skip_sync_returns_false_after_content_change(): void {
        $page = $this->make_page('Test Page', [], [], '2025-01-01T00:00:00.000Z');
        Incremental_Detector::update_sync_hashes($page, 42);

        // 修改内容与最后编辑时间
        $changed_page = $this->make_page(
            'Test Page',
            ['Name' => ['title' => [['plain_text' => 'Test Page']]]],
            [['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'new content']]]]],
            '2025-01-02T00:00:00.000Z'
        );

        $result = Incremental_Detector::should_skip_sync($changed_page, 42);
        $this->assertFalse($result, '内容变化后应需要同步');
    }

    /**
     * 测试：属性变化分析能识别新增与修改的属性。
     */
    public function test_analyze_properties_changes_detects_added_and_modified(): void {
        $old = json_encode([
            'Name' => ['title' => [['plain_text' => 'Old']]],
            'Status' => ['select' => ['name' => 'Draft']],
        ]);
        $new = json_encode([
            'Name' => ['title' => [['plain_text' => 'New']]],
            'Status' => ['select' => ['name' => 'Published']],
            'Tags' => ['multi_select' => [['name' => 'tag1']]],
        ]);

        $reflection = new \ReflectionMethod(Incremental_Detector::class, 'analyze_properties_changes');
        $reflection->setAccessible(true);
        $changes = $reflection->invoke(null, $old, $new);

        $this->assertContains('Name', $changes, '标题属性变化应被识别');
        $this->assertContains('Status', $changes, '状态属性变化应被识别');
        $this->assertContains('Tags', $changes, '新增属性应被识别');
    }

    /**
     * 测试：detect_content_changes 能区分标题/属性/内容变化维度。
     */
    public function test_detect_content_changes_returns_dimensioned_changes(): void {
        $page = $this->make_page();
        Incremental_Detector::update_sync_hashes($page, 42);

        // 仅修改属性（分类/标签），不改正文
        $changed_page = $this->make_page(
            'Test Page',
            ['Name' => ['title' => [['plain_text' => 'Test Page']]], 'Tags' => ['multi_select' => [['name' => 'tag-new']]]],
            [],
            '2025-01-03T00:00:00.000Z'
        );

        $changes = Incremental_Detector::detect_content_changes($changed_page, 42, false);

        $this->assertIsArray($changes, '检测结果应为数组');
        $this->assertArrayHasKey('properties', $changes, '属性变化应被检测到');
    }

    /**
     * 测试：detection stats 在无数据时返回空统计。
     */
    public function test_get_detection_stats_returns_expected_shape(): void {
        $stats = Incremental_Detector::get_detection_stats();

        $this->assertArrayHasKey('total_tracked_posts', $stats);
        $this->assertArrayHasKey('recently_checked_posts', $stats);
        $this->assertArrayHasKey('hash_algorithm', $stats);
        $this->assertEquals('sha256', $stats['hash_algorithm']);
    }

    /**
     * 测试：detect_change_type 对从未同步过的页面返回 none。
     */
    public function test_detect_change_type_returns_none_for_new_post(): void {
        $page = $this->make_page();

        $type = Incremental_Detector::detect_change_type($page, 42);

        $this->assertEquals('none', $type, '从未同步过的页面应返回 none');
    }

    /**
     * 测试：detect_change_type 对无变化的已同步页面返回 none。
     */
    public function test_detect_change_type_returns_none_when_unchanged(): void {
        $page = $this->make_page();
        Incremental_Detector::update_sync_hashes($page, 42);

        $type = Incremental_Detector::detect_change_type($page, 42);

        $this->assertEquals('none', $type, '无变化的已同步页面应返回 none');
    }

    /**
     * 测试：detect_change_type 对仅属性变化返回 properties_only。
     */
    public function test_detect_change_type_returns_properties_only_for_property_change(): void {
        $page = $this->make_page();
        Incremental_Detector::update_sync_hashes($page, 42);

        // 仅修改属性（新增标签），正文不变
        $changed_page = $this->make_page(
            'Test Page',
            ['Name' => ['title' => [['plain_text' => 'Test Page']]], 'Tags' => ['multi_select' => [['name' => 'tag-new']]]],
            [],
            '2025-01-05T00:00:00.000Z'
        );

        $type = Incremental_Detector::detect_change_type($changed_page, 42);

        $this->assertEquals('properties_only', $type, '仅属性变化应返回 properties_only');
    }

    /**
     * 测试：detect_change_type 对内容变化返回 content 或 both。
     */
    public function test_detect_change_type_returns_content_for_content_change(): void {
        $page = $this->make_page();
        Incremental_Detector::update_sync_hashes($page, 42);

        // 修改正文内容
        $changed_page = $this->make_page(
            'Test Page',
            ['Name' => ['title' => [['plain_text' => 'Test Page']]]],
            [['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'brand new content']]]]],
            '2025-01-06T00:00:00.000Z'
        );

        $type = Incremental_Detector::detect_change_type($changed_page, 42);

        $this->assertContains($type, ['content', 'both'], '内容变化应返回 content 或 both');
    }
}

