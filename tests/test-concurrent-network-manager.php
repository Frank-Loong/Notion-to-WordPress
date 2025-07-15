<?php
/**
 * 并发网络管理器测试脚本
 *
 * 用于测试并发网络管理器的功能和性能。
 *
 * @link       https://github.com/frankloong/Notion-to-WordPress
 * @since      1.1.2
 *
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/tests
 */

// 确保WordPress环境已加载
if (!defined('ABSPATH')) {
    define('WP_DEBUG', true);
    // 修正路径到WordPress根目录
    require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/wp-load.php';
}

// 加载必要的类
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-to-wordpress-helper.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-notion-concurrent-network-manager.php';

/**
 * 测试并发网络管理器
 */
function test_concurrent_network_manager() {
    echo "<h1>并发网络管理器测试</h1>";
    
    // 创建测试URLs
    $test_urls = [
        'https://api.wordpress.org/stats/wordpress/1.0/',
        'https://api.wordpress.org/core/version-check/1.7/',
        'https://api.wordpress.org/plugins/info/1.0/',
        'https://api.wordpress.org/themes/info/1.1/',
        'https://api.wordpress.org/translations/core/1.0/'
    ];
    
    echo "<h2>测试URLs:</h2>";
    echo "<ul>";
    foreach ($test_urls as $url) {
        echo "<li>{$url}</li>";
    }
    echo "</ul>";
    
    // 测试串行请求
    echo "<h2>串行请求测试:</h2>";
    $serial_start_time = microtime(true);
    $serial_responses = [];
    
    foreach ($test_urls as $index => $url) {
        echo "请求 #{$index}: {$url}<br>";
        $response = wp_remote_get($url, ['timeout' => 10]);
        $serial_responses[] = $response;
        
        if (is_wp_error($response)) {
            echo "错误: " . $response->get_error_message() . "<br>";
        } else {
            echo "成功: HTTP " . wp_remote_retrieve_response_code($response) . "<br>";
        }
    }
    
    $serial_time = microtime(true) - $serial_start_time;
    echo "<p>串行请求总耗时: " . round($serial_time, 4) . " 秒</p>";
    
    // 测试并发请求
    echo "<h2>并发请求测试:</h2>";
    $concurrent_start_time = microtime(true);
    
    try {
        // 创建并发网络管理器
        $manager = new Notion_Concurrent_Network_Manager(5);
        
        // 添加请求
        foreach ($test_urls as $url) {
            $manager->add_request($url, ['timeout' => 10]);
        }
        
        // 执行请求
        $concurrent_responses = $manager->execute();
        
        // 显示结果
        foreach ($concurrent_responses as $index => $response) {
            echo "请求 #{$index}: " . $test_urls[$index] . "<br>";
            
            if (is_wp_error($response)) {
                echo "错误: " . $response->get_error_message() . "<br>";
            } else {
                echo "成功: HTTP " . $response['response']['code'] . "<br>";
            }
        }
        
        // 显示统计信息
        $stats = $manager->get_stats();
        echo "<h3>统计信息:</h3>";
        echo "<ul>";
        echo "<li>总请求数: " . $stats['total_requests'] . "</li>";
        echo "<li>成功请求: " . $stats['successful_requests'] . "</li>";
        echo "<li>失败请求: " . $stats['failed_requests'] . "</li>";
        echo "<li>最大并发数: " . $stats['max_concurrent'] . "</li>";
        echo "<li>内存使用: " . round($stats['memory_usage'] / 1024 / 1024, 2) . " MB</li>";
        echo "<li>峰值内存: " . round($stats['peak_memory'] / 1024 / 1024, 2) . " MB</li>";
        echo "</ul>";
        
    } catch (Exception $e) {
        echo "<p>错误: " . $e->getMessage() . "</p>";
    }
    
    $concurrent_time = microtime(true) - $concurrent_start_time;
    echo "<p>并发请求总耗时: " . round($concurrent_time, 4) . " 秒</p>";
    
    // 性能比较
    echo "<h2>性能比较:</h2>";
    $speedup = $serial_time / $concurrent_time;
    echo "<p>加速比: " . round($speedup, 2) . "x</p>";
    echo "<p>性能提升: " . round(($speedup - 1) * 100, 2) . "%</p>";
}

// 执行测试
test_concurrent_network_manager();
