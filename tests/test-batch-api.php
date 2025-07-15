<?php
/**
 * 批量API调用测试脚本
 *
 * 测试Notion API类的批量并发请求功能。
 *
 * @link       https://github.com/frankloong/Notion-to-WordPress
 * @since      1.1.2
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

/**
 * 测试批量API调用功能
 */
function test_batch_api() {
    echo "<h1>批量API调用功能测试</h1>";
    
    // 获取Notion API密钥和数据库ID
    $options = get_option('notion_to_wordpress_options', []);
    $api_key = $options['notion_api_key'] ?? '';
    $database_id = $options['notion_database_id'] ?? '';

    if (empty($api_key)) {
        echo "<p>❌ 错误：未设置Notion API密钥</p>";
        echo "<p>请在插件设置中配置Notion API密钥后再运行测试。</p>";
        return;
    }
    
    try {
        $notion_api = new Notion_API($api_key);
        
        // 测试1：批量发送基础请求
        echo "<h2>测试1：批量发送基础请求</h2>";
        
        $test_endpoints = [
            'users/me',
            'users/me',
            'users/me'
        ];
        
        $start_time = microtime(true);
        $responses = $notion_api->batch_send_requests($test_endpoints);
        $batch_time = microtime(true) - $start_time;
        
        echo "<p>批量请求耗时: " . round($batch_time, 4) . " 秒</p>";
        echo "<p>响应数量: " . count($responses) . "</p>";
        
        $success_count = 0;
        $error_count = 0;
        
        foreach ($responses as $index => $response) {
            if ($response instanceof Exception) {
                echo "请求 #{$index}: ❌ " . $response->getMessage() . "<br>";
                $error_count++;
            } else {
                echo "请求 #{$index}: ✅ 成功<br>";
                $success_count++;
            }
        }
        
        echo "<p>成功: {$success_count}, 失败: {$error_count}</p>";
        
        // 对比串行请求
        echo "<h3>对比串行请求:</h3>";
        $serial_start_time = microtime(true);
        
        for ($i = 0; $i < 3; $i++) {
            try {
                // 使用单个端点的批量请求来模拟串行请求
                $single_response = $notion_api->batch_send_requests(['users/me']);
                if (!empty($single_response) && !($single_response[0] instanceof Exception)) {
                    echo "串行请求 #{$i}: ✅ 成功<br>";
                } else {
                    echo "串行请求 #{$i}: ❌ 请求失败<br>";
                }
            } catch (Exception $e) {
                echo "串行请求 #{$i}: ❌ " . $e->getMessage() . "<br>";
            }
        }
        
        $serial_time = microtime(true) - $serial_start_time;
        echo "<p>串行请求耗时: " . round($serial_time, 4) . " 秒</p>";
        
        if ($batch_time > 0 && $serial_time > 0) {
            $speedup = $serial_time / $batch_time;
            echo "<p>加速比: " . round($speedup, 2) . "x</p>";
        }
        
        echo "<br>";
        
        // 测试2：批量获取页面（需要有效的页面ID）
        echo "<h2>测试2：批量获取页面测试</h2>";
        
        // 获取一些页面ID进行测试
        if (!empty($database_id)) {
            try {
                echo "<p>从数据库获取页面ID进行测试...</p>";
                $pages = $notion_api->get_database_pages($database_id, [], false);
                
                if (!empty($pages) && count($pages) >= 2) {
                    $page_ids = array_slice(array_column($pages, 'id'), 0, 3); // 取前3个页面
                    
                    echo "<p>测试页面ID: " . implode(', ', $page_ids) . "</p>";
                    
                    // 批量获取页面
                    $batch_pages_start = microtime(true);
                    $batch_pages = $notion_api->batch_get_pages($page_ids);
                    $batch_pages_time = microtime(true) - $batch_pages_start;
                    
                    echo "<p>批量获取页面耗时: " . round($batch_pages_time, 4) . " 秒</p>";
                    echo "<p>获取到页面数量: " . count($batch_pages) . "</p>";
                    
                    foreach ($batch_pages as $page_id => $page_data) {
                        if (isset($page_data['properties'])) {
                            echo "页面 {$page_id}: ✅ 成功获取<br>";
                        } else {
                            echo "页面 {$page_id}: ❌ 数据不完整<br>";
                        }
                    }
                    
                    // 对比串行获取
                    echo "<h3>对比串行获取页面:</h3>";
                    $serial_pages_start = microtime(true);
                    
                    $serial_pages = [];
                    foreach ($page_ids as $page_id) {
                        try {
                            $page = $notion_api->get_page($page_id);
                            $serial_pages[$page_id] = $page;
                            echo "串行获取页面 {$page_id}: ✅ 成功<br>";
                        } catch (Exception $e) {
                            echo "串行获取页面 {$page_id}: ❌ " . $e->getMessage() . "<br>";
                        }
                    }
                    
                    $serial_pages_time = microtime(true) - $serial_pages_start;
                    echo "<p>串行获取页面耗时: " . round($serial_pages_time, 4) . " 秒</p>";
                    
                    if ($batch_pages_time > 0 && $serial_pages_time > 0) {
                        $pages_speedup = $serial_pages_time / $batch_pages_time;
                        echo "<p>页面获取加速比: " . round($pages_speedup, 2) . "x</p>";
                    }
                    
                } else {
                    echo "<p>⚠️ 数据库中页面数量不足，跳过页面批量获取测试</p>";
                }
                
            } catch (Exception $e) {
                echo "<p>❌ 获取数据库页面失败: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p>⚠️ 未设置数据库ID，跳过页面批量获取测试</p>";
        }
        
        echo "<br>";
        
        // 测试3：缓存机制测试
        echo "<h2>测试3：缓存机制测试</h2>";
        
        if (!empty($page_ids)) {
            echo "<p>第一次批量获取页面（应该从API获取）:</p>";
            $first_batch_start = microtime(true);
            $first_batch = $notion_api->batch_get_pages($page_ids);
            $first_batch_time = microtime(true) - $first_batch_start;
            echo "<p>第一次耗时: " . round($first_batch_time, 4) . " 秒</p>";
            
            echo "<p>第二次批量获取页面（应该从缓存获取）:</p>";
            $second_batch_start = microtime(true);
            $second_batch = $notion_api->batch_get_pages($page_ids);
            $second_batch_time = microtime(true) - $second_batch_start;
            echo "<p>第二次耗时: " . round($second_batch_time, 4) . " 秒</p>";
            
            if ($first_batch_time > 0 && $second_batch_time > 0) {
                $cache_speedup = $first_batch_time / $second_batch_time;
                echo "<p>缓存加速比: " . round($cache_speedup, 2) . "x</p>";
            }
            
            // 显示缓存统计
            $cache_stats = Notion_API::get_cache_stats();
            echo "<h3>缓存统计:</h3>";
            echo "<ul>";
            echo "<li>页面缓存数量: " . $cache_stats['page_cache_count'] . "</li>";
            echo "<li>数据库页面缓存数量: " . $cache_stats['database_pages_cache_count'] . "</li>";
            echo "<li>数据库信息缓存数量: " . $cache_stats['database_info_cache_count'] . "</li>";
            echo "<li>总缓存项目: " . $cache_stats['total_cache_items'] . "</li>";
            echo "</ul>";
        }
        
    } catch (Exception $e) {
        echo "<p>❌ 测试异常: " . $e->getMessage() . "</p>";
    }
    
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
}

// 执行测试
test_batch_api();
