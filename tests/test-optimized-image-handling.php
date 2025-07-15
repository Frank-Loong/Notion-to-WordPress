<?php
/**
 * 优化图片处理测试脚本
 *
 * 测试外链优化和真正并发下载的功能。
 *
 * @link       https://github.com/frankloong/Notion-to-WordPress
 * @since      1.9.0-beta.1
 *
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/tests
 */

// 确保WordPress环境已加载
if (!defined('ABSPATH')) {
    require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/wp-load.php';
}

// 加载必要的类
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-to-wordpress-helper.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-network-retry.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-concurrent-network-manager.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-api.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-pages.php';

/**
 * 测试优化的图片处理功能
 */
function test_optimized_image_handling() {
    echo "<h1>优化图片处理功能测试</h1>";
    
    $options = get_option('notion_to_wordpress_options', []);
    $api_key = $options['notion_api_key'] ?? '';
    $database_id = $options['notion_database_id'] ?? '';
    
    if (empty($api_key) || empty($database_id)) {
        echo "<p>❌ 配置错误</p>";
        return;
    }

    try {
        $notion_api = new Notion_API($api_key);
        $notion_pages = new Notion_Pages($notion_api, $database_id);
        
        echo "<h2>测试1：外链图片优化</h2>";
        
        // 测试混合图片块：外链 + Notion临时URL
        $mixed_image_blocks = [
            [
                'type' => 'image',
                'id' => 'external-image-1',
                'image' => [
                    'type' => 'external',
                    'external' => ['url' => 'https://via.placeholder.com/300x200/0066cc/ffffff?text=External+Image'],
                    'caption' => [['plain_text' => '外链图片（应直接使用）']]
                ]
            ],
            [
                'type' => 'image',
                'id' => 'notion-image-1',
                'image' => [
                    'type' => 'file',
                    'file' => ['url' => 'https://prod-files-secure.s3.us-west-2.amazonaws.com/test/notion-image.jpg?X-Amz-Algorithm=test'],
                    'caption' => [['plain_text' => 'Notion临时图片（需要下载）']]
                ]
            ]
        ];
        
        echo "<p>测试块数量: " . count($mixed_image_blocks) . "</p>";
        echo "<p>包含: 1个外链图片 + 1个Notion临时图片</p>";
        
        // 禁用异步模式测试
        $notion_pages->disable_async_image_mode();
        
        $mixed_start = microtime(true);
        $mixed_html = $notion_pages->test_convert_blocks_to_html($mixed_image_blocks, $notion_api);
        $mixed_time = microtime(true) - $mixed_start;
        
        echo "<p>混合图片处理耗时: " . round($mixed_time, 4) . " 秒</p>";
        echo "<p>生成HTML长度: " . strlen($mixed_html) . " 字符</p>";
        
        // 检查外链是否被直接使用
        $external_direct_use = strpos($mixed_html, 'via.placeholder.com') !== false;
        echo "<p>外链直接使用: " . ($external_direct_use ? "✅ 是" : "❌ 否") . "</p>";
        
        echo "<h2>测试2：真正的并发下载</h2>";
        
        // 创建多个Notion临时URL进行并发测试
        $concurrent_image_blocks = [
            [
                'type' => 'image',
                'id' => 'concurrent-1',
                'image' => [
                    'type' => 'file',
                    'file' => ['url' => 'https://prod-files-secure.s3.us-west-2.amazonaws.com/test1/image1.jpg?X-Amz-Algorithm=test1'],
                    'caption' => [['plain_text' => '并发测试图片1']]
                ]
            ],
            [
                'type' => 'image',
                'id' => 'concurrent-2',
                'image' => [
                    'type' => 'file',
                    'file' => ['url' => 'https://secure.notion-static.com/test2/image2.png?table=test2'],
                    'caption' => [['plain_text' => '并发测试图片2']]
                ]
            ],
            [
                'type' => 'image',
                'id' => 'concurrent-3',
                'image' => [
                    'type' => 'file',
                    'file' => ['url' => 'https://prod-files-secure.s3.amazonaws.com/test3/image3.gif?signature=test3'],
                    'caption' => [['plain_text' => '并发测试图片3']]
                ]
            ],
            [
                'type' => 'image',
                'id' => 'concurrent-4',
                'image' => [
                    'type' => 'file',
                    'file' => ['url' => 'https://files.notion.com/test4/image4.webp?id=test4'],
                    'caption' => [['plain_text' => '并发测试图片4']]
                ]
            ]
        ];
        
        echo "<p>并发测试图片数量: " . count($concurrent_image_blocks) . "</p>";

        // 调试：检查每个URL是否被识别为Notion临时URL
        echo "<h3>URL识别检查:</h3>";
        $reflection = new ReflectionClass($notion_pages);
        $is_notion_url_method = $reflection->getMethod('is_notion_temp_url');
        $is_notion_url_method->setAccessible(true);

        foreach ($concurrent_image_blocks as $index => $block) {
            $url = $block['image']['file']['url'];
            $is_notion = $is_notion_url_method->invoke($notion_pages, $url);
            echo "<p>图片 " . ($index + 1) . ": " . ($is_notion ? "✅ Notion临时URL" : "❌ 非Notion URL") . "</p>";
            echo "<p>  URL: " . substr($url, 0, 80) . "...</p>";
        }

        // 启用异步模式进行并发测试
        $notion_pages->enable_async_image_mode();
        
        $concurrent_start = microtime(true);
        
        // 收集阶段
        $collect_start = microtime(true);
        $html_with_placeholders = $notion_pages->test_convert_blocks_to_html($concurrent_image_blocks, $notion_api);
        $collect_time = microtime(true) - $collect_start;
        
        echo "<p>图片收集阶段耗时: " . round($collect_time, 4) . " 秒</p>";
        
        $placeholder_count = substr_count($html_with_placeholders, 'pending_image_');
        echo "<p>生成占位符数量: {$placeholder_count}</p>";
        
        // 并发下载阶段
        $download_start = microtime(true);
        $final_html = $notion_pages->process_async_images($html_with_placeholders);
        $download_time = microtime(true) - $download_start;

        $concurrent_time = microtime(true) - $concurrent_start;

        echo "<p>并发下载阶段耗时: " . round($download_time, 4) . " 秒</p>";
        echo "<p>总并发处理耗时: " . round($concurrent_time, 4) . " 秒</p>";

        // 获取队列统计（使用最后一次处理的统计）
        $queue_stats = $notion_pages->get_image_queue_stats(true);
        echo "<p>下载统计: 成功 {$queue_stats['successful_downloads']} 个，失败 {$queue_stats['failed_downloads']} 个</p>";

        // 添加详细的调试信息
        echo "<p>调试信息: 待处理 {$queue_stats['pending_count']} 个，占位符 {$queue_stats['placeholder_count']} 个</p>";
        
        echo "<h2>测试3：cURL并发能力检查</h2>";
        
        $curl_available = function_exists('curl_multi_init');
        echo "<p>cURL多句柄支持: " . ($curl_available ? "✅ 可用" : "❌ 不可用") . "</p>";
        
        if ($curl_available) {
            echo "<p>✅ 系统支持真正的并发下载</p>";
        } else {
            echo "<p>⚠️ 系统不支持cURL多句柄，将使用顺序下载</p>";
        }
        
        echo "<h2>测试4：特色图片外链优化</h2>";
        
        // 测试特色图片的外链处理
        echo "<p>测试特色图片外链优化功能...</p>";
        
        // 创建一个测试文章
        $test_post_id = wp_insert_post([
            'post_title' => 'Test Post for Featured Image',
            'post_content' => 'Test content',
            'post_status' => 'draft',
            'post_type' => 'post'
        ]);
        
        if ($test_post_id && !is_wp_error($test_post_id)) {
            // 测试外链特色图片
            $external_featured_url = 'https://via.placeholder.com/600x400/cc6600/ffffff?text=Featured+Image';
            
            // 使用反射调用私有方法进行测试
            $reflection = new ReflectionClass($notion_pages);
            $set_featured_method = $reflection->getMethod('set_featured_image');
            $set_featured_method->setAccessible(true);
            
            $featured_start = microtime(true);
            $set_featured_method->invoke($notion_pages, $test_post_id, $external_featured_url);
            $featured_time = microtime(true) - $featured_start;
            
            echo "<p>特色图片设置耗时: " . round($featured_time, 4) . " 秒</p>";
            
            // 检查是否使用了外链
            $featured_url_meta = get_post_meta($test_post_id, '_notion_featured_image_url', true);
            $has_thumbnail = has_post_thumbnail($test_post_id);
            
            echo "<p>外链特色图片URL存储: " . (!empty($featured_url_meta) ? "✅ 是" : "❌ 否") . "</p>";
            echo "<p>WordPress缩略图设置: " . ($has_thumbnail ? "❌ 是（不应该）" : "✅ 否（正确）") . "</p>";
            
            // 清理测试文章
            wp_delete_post($test_post_id, true);
        }
        
        echo "<h2>测试5：性能对比总结</h2>";
        
        $test_results = [
            'external_link_optimization' => $external_direct_use,
            'concurrent_download_support' => $curl_available,
            'placeholder_generation' => $placeholder_count >= count($concurrent_image_blocks),
            'featured_image_optimization' => !empty($featured_url_meta),
            'fast_collection' => $collect_time < 0.1, // 收集应该很快
            'reasonable_download_time' => $download_time < 30 // 下载时间合理
        ];
        
        $passed_tests = array_sum($test_results);
        $total_tests = count($test_results);
        $success_rate = ($passed_tests / $total_tests) * 100;
        
        echo "<h3>测试结果:</h3>";
        echo "<ul>";
        foreach ($test_results as $test => $passed) {
            $status = $passed ? "✅ 通过" : "❌ 失败";
            $test_name = str_replace('_', ' ', ucwords($test));
            echo "<li>{$test_name}: {$status}</li>";
        }
        echo "</ul>";
        
        echo "<h3>总体评分: {$passed_tests}/{$total_tests} (" . round($success_rate, 1) . "%)</h3>";
        
        if ($success_rate >= 85) {
            echo "<p>🎉 图片处理优化测试成功！</p>";
            echo "<p>✅ 外链优化正常工作</p>";
            echo "<p>✅ 并发下载功能完善</p>";
        } elseif ($success_rate >= 70) {
            echo "<p>✅ 图片处理优化基本成功</p>";
            echo "<p>⚠️ 部分功能需要进一步优化</p>";
        } else {
            echo "<p>❌ 图片处理优化需要重大改进</p>";
        }
        
        // 显示性能统计
        echo "<h3>性能统计:</h3>";
        echo "<ul>";
        echo "<li>混合图片处理: " . round($mixed_time, 4) . " 秒</li>";
        echo "<li>图片收集阶段: " . round($collect_time, 4) . " 秒</li>";
        echo "<li>并发下载阶段: " . round($download_time, 4) . " 秒</li>";
        echo "<li>特色图片设置: " . round($featured_time, 4) . " 秒</li>";
        echo "</ul>";
        
        echo "<h3>HTML内容预览（前400字符）:</h3>";
        echo "<div style='border: 1px solid #ccc; padding: 10px; max-height: 150px; overflow-y: auto; font-family: monospace; font-size: 10px;'>";
        echo htmlspecialchars(substr($final_html, 0, 400));
        if (strlen($final_html) > 400) {
            echo "<br><em>... (已截断)</em>";
        }
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<p>❌ 测试异常: " . $e->getMessage() . "</p>";
    }
}

// 执行测试
test_optimized_image_handling();
