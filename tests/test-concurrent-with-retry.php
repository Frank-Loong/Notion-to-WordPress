<?php
/**
 * 并发网络管理器与重试机制集成测试
 *
 * 测试并发网络管理器与重试机制的集成功能。
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

/**
 * 测试并发网络管理器与重试机制集成
 */
function test_concurrent_with_retry() {
    echo "<h1>并发网络管理器与重试机制集成测试</h1>";
    
    // 重置重试统计信息
    Notion_Network_Retry::reset_retry_stats();
    
    // 创建测试URLs（包含一些可能失败的URL）
    $test_urls = [
        'https://api.wordpress.org/stats/wordpress/1.0/',
        'https://httpstat.us/200?sleep=1000', // 延迟1秒
        'https://httpstat.us/500', // 服务器错误（临时性）
        'https://api.wordpress.org/core/version-check/1.7/',
        'https://httpstat.us/404', // 404错误（永久性）
    ];
    
    echo "<h2>测试URLs:</h2>";
    echo "<ul>";
    foreach ($test_urls as $index => $url) {
        echo "<li>#{$index}: {$url}</li>";
    }
    echo "</ul>";
    
    // 测试1：不使用重试的并发请求
    echo "<h2>测试1：不使用重试的并发请求</h2>";
    $no_retry_start_time = microtime(true);
    
    try {
        $manager = new Notion_Concurrent_Network_Manager(3);
        
        foreach ($test_urls as $url) {
            $manager->add_request($url, ['timeout' => 5]);
        }
        
        $responses = $manager->execute_internal(); // 直接调用内部方法，不使用重试

        $no_retry_time = microtime(true) - $no_retry_start_time;

        echo "<p>执行时间: " . round($no_retry_time, 4) . " 秒</p>";
        echo "<p>响应数量: " . count($responses) . "</p>";

        $success_count = 0;
        $error_count = 0;

        foreach ($responses as $index => $response) {
            if (is_wp_error($response)) {
                echo "请求 #{$index}: ❌ " . $response->get_error_message() . "<br>";
                $error_count++;
            } else {
                echo "请求 #{$index}: ✅ HTTP " . $response['response']['code'] . "<br>";
                $success_count++;
            }
        }
        
        echo "<p>成功: {$success_count}, 失败: {$error_count}</p>";
        
        $stats = $manager->get_stats();
        echo "<p>管理器统计: 总请求 {$stats['total_requests']}, 成功 {$stats['successful_requests']}, 失败 {$stats['failed_requests']}</p>";
        
    } catch (Exception $e) {
        echo "<p>❌ 测试失败: " . $e->getMessage() . "</p>";
    }
    
    echo "<br>";
    
    // 测试2：使用重试的并发请求
    echo "<h2>测试2：使用重试的并发请求</h2>";
    $with_retry_start_time = microtime(true);
    
    try {
        $manager_retry = new Notion_Concurrent_Network_Manager(3);
        
        foreach ($test_urls as $url) {
            $manager_retry->add_request($url, ['timeout' => 5]);
        }
        
        $responses_retry = $manager_retry->execute_with_retry(2, 500); // 最多重试2次，延迟500ms

        $with_retry_time = microtime(true) - $with_retry_start_time;

        echo "<p>执行时间: " . round($with_retry_time, 4) . " 秒</p>";
        echo "<p>响应数量: " . count($responses_retry) . "</p>";

        $success_count_retry = 0;
        $error_count_retry = 0;

        foreach ($responses_retry as $index => $response) {
            if (is_wp_error($response)) {
                echo "请求 #{$index}: ❌ " . $response->get_error_message() . "<br>";
                $error_count_retry++;
            } else {
                echo "请求 #{$index}: ✅ HTTP " . $response['response']['code'] . "<br>";
                $success_count_retry++;
            }
        }
        
        echo "<p>成功: {$success_count_retry}, 失败: {$error_count_retry}</p>";
        
        $stats_retry = $manager_retry->get_stats();
        echo "<p>管理器统计: 总请求 {$stats_retry['total_requests']}, 成功 {$stats_retry['successful_requests']}, 失败 {$stats_retry['failed_requests']}</p>";
        
    } catch (Exception $e) {
        echo "<p>❌ 测试失败: " . $e->getMessage() . "</p>";
    }
    
    echo "<br>";
    
    // 测试3：单个请求重试测试
    echo "<h2>测试3：单个请求重试测试</h2>";
    
    try {
        echo "<h3>测试临时性错误重试:</h3>";
        $temp_manager = new Notion_Concurrent_Network_Manager(1);
        $temp_manager->add_request('https://httpstat.us/503', ['timeout' => 3]); // 503 Service Unavailable
        
        $temp_responses = $temp_manager->execute_with_retry(2, 300);
        
        foreach ($temp_responses as $response) {
            if (is_wp_error($response)) {
                echo "临时性错误测试: ❌ " . $response->get_error_message() . "<br>";
            } else {
                echo "临时性错误测试: ✅ HTTP " . $response['response']['code'] . "<br>";
            }
        }
        
        echo "<h3>测试永久性错误（不重试）:</h3>";
        $perm_manager = new Notion_Concurrent_Network_Manager(1);
        $perm_manager->add_request('https://httpstat.us/401', ['timeout' => 3]); // 401 Unauthorized
        
        $perm_responses = $perm_manager->execute_with_retry(2, 300);
        
        foreach ($perm_responses as $response) {
            if (is_wp_error($response)) {
                echo "永久性错误测试: ❌ " . $response->get_error_message() . "<br>";
            } else {
                echo "永久性错误测试: ✅ HTTP " . $response['response']['code'] . "<br>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p>❌ 单个请求测试失败: " . $e->getMessage() . "</p>";
    }
    
    // 显示重试统计信息
    echo "<h2>重试统计信息</h2>";
    $retry_stats = Notion_Network_Retry::get_retry_stats();
    echo "<ul>";
    echo "<li>总尝试次数: " . $retry_stats['total_attempts'] . "</li>";
    echo "<li>成功重试: " . $retry_stats['successful_retries'] . "</li>";
    echo "<li>失败重试: " . $retry_stats['failed_retries'] . "</li>";
    echo "<li>永久性错误: " . $retry_stats['permanent_errors'] . "</li>";
    echo "<li>总延迟时间: " . $retry_stats['total_delay_time'] . " 毫秒</li>";
    echo "</ul>";
    
    // 性能比较
    echo "<h2>性能比较</h2>";
    if (isset($no_retry_time) && isset($with_retry_time)) {
        echo "<p>不使用重试: " . round($no_retry_time, 4) . " 秒</p>";
        echo "<p>使用重试: " . round($with_retry_time, 4) . " 秒</p>";
        echo "<p>重试机制增加的时间: " . round($with_retry_time - $no_retry_time, 4) . " 秒</p>";
    }
}

// 执行测试
test_concurrent_with_retry();
