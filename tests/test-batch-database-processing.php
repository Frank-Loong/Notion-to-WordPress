<?php
/**
 * 批量子数据库处理测试脚本
 *
 * 测试子数据库处理的批量并发优化功能。
 *
 * @link       https://github.com/frankloong/Notion-to-WordPress
 * @since      1.9.0-beta.1
 *
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/tests
 */

// 确保WordPress环境已加载
if (!defined('ABSPATH')) {
    // 修正路径到WordPress根目录
    require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/wp-load.php';
}

// 加载必要的类
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-to-wordpress-helper.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-network-retry.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-concurrent-network-manager.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-api.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-pages.php';

/**
 * 测试批量子数据库处理功能
 */
function test_batch_database_processing() {
    echo "<h1>批量子数据库处理功能测试</h1>";
    
    // 获取Notion API密钥和数据库ID
    $options = get_option('notion_to_wordpress_options', []);
    $api_key = $options['notion_api_key'] ?? '';
    $database_id = $options['notion_database_id'] ?? '';
    
    if (empty($api_key)) {
        echo "<p>❌ 错误：未设置Notion API密钥</p>";
        echo "<p>请在插件设置中配置Notion API密钥后再运行测试。</p>";
        return;
    }
    
    if (empty($database_id)) {
        echo "<p>❌ 错误：未设置数据库ID</p>";
        echo "<p>请在插件设置中配置数据库ID后再运行测试。</p>";
        return;
    }

    try {
        $notion_api = new Notion_API($api_key);
        $notion_pages = new Notion_Pages($notion_api, $database_id);
        
        // 重置重试统计
        Notion_Network_Retry::reset_retry_stats();
        
        echo "<h2>测试1：获取包含子数据库的页面</h2>";
        
        // 获取数据库中的页面
        $pages = $notion_api->get_database_pages($database_id, [], false);
        
        if (empty($pages)) {
            echo "<p>❌ 数据库中没有页面</p>";
            return;
        }
        
        echo "<p>数据库中共有 " . count($pages) . " 个页面</p>";
        
        // 查找包含子数据库的页面
        $test_page = null;
        $child_database_count = 0;
        
        foreach ($pages as $page) {
            $page_id = $page['id'];
            echo "<p>检查页面: " . ($page['properties']['Name']['title'][0]['plain_text'] ?? $page_id) . "</p>";
            
            try {
                // 获取页面内容
                $blocks = $notion_api->get_page_content($page_id);
                
                // 统计子数据库块
                $database_blocks = [];
                foreach ($blocks as $block) {
                    if (isset($block['type']) && $block['type'] === 'child_database') {
                        $database_blocks[] = $block;
                    }
                }
                
                if (!empty($database_blocks)) {
                    $test_page = $page;
                    $child_database_count = count($database_blocks);
                    echo "<p>✅ 找到包含 {$child_database_count} 个子数据库的页面</p>";
                    break;
                }
                
            } catch (Exception $e) {
                echo "<p>⚠️ 获取页面内容失败: " . $e->getMessage() . "</p>";
                continue;
            }
        }
        
        if (!$test_page) {
            echo "<p>⚠️ 未找到包含子数据库的页面，创建模拟测试数据</p>";
            
            // 创建模拟的子数据库块进行测试
            $mock_blocks = [
                [
                    'type' => 'child_database',
                    'id' => $database_id, // 使用配置的数据库ID作为测试
                    'child_database' => [
                        'title' => '测试数据库'
                    ]
                ]
            ];
            
            echo "<h2>测试2：模拟子数据库批量处理</h2>";
            
            // 测试批量处理
            $batch_start_time = microtime(true);
            $html_content = $notion_pages->test_convert_blocks_to_html($mock_blocks, $notion_api);
            $batch_time = microtime(true) - $batch_start_time;
            
            echo "<p>批量处理耗时: " . round($batch_time, 4) . " 秒</p>";
            echo "<p>生成的HTML长度: " . strlen($html_content) . " 字符</p>";
            
            if (!empty($html_content)) {
                echo "<h3>生成的HTML内容预览:</h3>";
                echo "<div style='border: 1px solid #ccc; padding: 10px; max-height: 300px; overflow-y: auto;'>";
                echo htmlspecialchars(substr($html_content, 0, 1000)) . (strlen($html_content) > 1000 ? '...' : '');
                echo "</div>";
                echo "<p>✅ 批量处理成功</p>";
            } else {
                echo "<p>❌ 批量处理失败，未生成HTML内容</p>";
            }
            
        } else {
            echo "<h2>测试2：实际页面子数据库批量处理</h2>";
            
            $page_id = $test_page['id'];
            $page_title = $test_page['properties']['Name']['title'][0]['plain_text'] ?? $page_id;
            
            echo "<p>测试页面: {$page_title}</p>";
            echo "<p>子数据库数量: {$child_database_count}</p>";
            
            // 获取页面内容
            $blocks = $notion_api->get_page_content($page_id);
            
            // 测试批量处理
            echo "<h3>批量处理测试:</h3>";
            $batch_start_time = microtime(true);
            $batch_html = $notion_pages->test_convert_blocks_to_html($blocks, $notion_api);
            $batch_time = microtime(true) - $batch_start_time;
            
            echo "<p>批量处理耗时: " . round($batch_time, 4) . " 秒</p>";
            echo "<p>生成的HTML长度: " . strlen($batch_html) . " 字符</p>";
            
            // 对比串行处理（使用原始方法）
            echo "<h3>串行处理对比测试:</h3>";
            
            // 临时禁用批量处理，模拟串行处理
            $serial_start_time = microtime(true);
            
            $serial_html = '';
            foreach ($blocks as $block) {
                if (isset($block['type']) && $block['type'] === 'child_database') {
                    // 模拟原始的串行处理
                    try {
                        $database_id_block = $block['id'];
                        $database_info = $notion_api->get_database_info($database_id_block);
                        $records = $notion_api->get_database_pages($database_id_block, [], true);
                        
                        $serial_html .= '<div class="notion-child-database">串行处理的数据库块</div>';
                    } catch (Exception $e) {
                        $serial_html .= '<div class="notion-database-error">串行处理失败</div>';
                    }
                }
            }
            
            $serial_time = microtime(true) - $serial_start_time;
            
            echo "<p>串行处理耗时: " . round($serial_time, 4) . " 秒</p>";
            echo "<p>生成的HTML长度: " . strlen($serial_html) . " 字符</p>";
            
            // 性能比较
            if ($batch_time > 0 && $serial_time > 0) {
                $speedup = $serial_time / $batch_time;
                echo "<h3>性能比较:</h3>";
                echo "<p>加速比: " . round($speedup, 2) . "x</p>";
                echo "<p>性能提升: " . round(($speedup - 1) * 100, 2) . "%</p>";
                
                if ($speedup > 1.5) {
                    echo "<p>✅ 批量处理显著提升性能</p>";
                } elseif ($speedup > 1.1) {
                    echo "<p>✅ 批量处理有一定性能提升</p>";
                } else {
                    echo "<p>⚠️ 批量处理性能提升不明显</p>";
                }
            }
            
            // 内容一致性检查
            echo "<h3>内容一致性检查:</h3>";
            if (strlen($batch_html) > 0 && strlen($serial_html) > 0) {
                // 简单的内容长度比较
                $length_diff = abs(strlen($batch_html) - strlen($serial_html));
                $length_ratio = $length_diff / max(strlen($batch_html), strlen($serial_html));
                
                if ($length_ratio < 0.1) {
                    echo "<p>✅ 批量处理和串行处理生成的内容长度相近</p>";
                } else {
                    echo "<p>⚠️ 批量处理和串行处理生成的内容长度差异较大</p>";
                }
            }
        }
        
        // 显示缓存统计
        echo "<h2>缓存统计信息</h2>";
        $cache_stats = Notion_API::get_cache_stats();
        echo "<ul>";
        echo "<li>页面缓存数量: " . $cache_stats['page_cache_count'] . "</li>";
        echo "<li>数据库页面缓存数量: " . $cache_stats['database_pages_cache_count'] . "</li>";
        echo "<li>数据库信息缓存数量: " . $cache_stats['database_info_cache_count'] . "</li>";
        echo "<li>总缓存项目: " . $cache_stats['total_cache_items'] . "</li>";
        echo "</ul>";
        
        // 显示重试统计
        echo "<h2>重试统计信息</h2>";
        $retry_stats = Notion_Network_Retry::get_retry_stats();
        echo "<ul>";
        echo "<li>总尝试次数: " . $retry_stats['total_attempts'] . "</li>";
        echo "<li>成功重试: " . $retry_stats['successful_retries'] . "</li>";
        echo "<li>失败重试: " . $retry_stats['failed_retries'] . "</li>";
        echo "<li>永久性错误: " . $retry_stats['permanent_errors'] . "</li>";
        echo "<li>总延迟时间: " . $retry_stats['total_delay_time'] . " 毫秒</li>";
        echo "</ul>";
        
    } catch (Exception $e) {
        echo "<p>❌ 测试异常: " . $e->getMessage() . "</p>";
    }
}

// 执行测试
test_batch_database_processing();
