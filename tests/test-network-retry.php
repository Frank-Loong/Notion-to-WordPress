<?php
/**
 * 网络重试机制测试脚本
 *
 * 用于测试网络重试机制的功能和错误分类。
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

/**
 * 测试网络重试机制
 */
function test_network_retry() {
    echo "<h1>网络重试机制测试</h1>";
    
    // 重置统计信息
    Notion_Network_Retry::reset_retry_stats();
    
    // 测试1：成功的请求（无需重试）
    echo "<h2>测试1：成功请求（无需重试）</h2>";
    try {
        $result = Notion_Network_Retry::with_retry(function() {
            echo "执行成功的请求...<br>";
            return "成功结果";
        }, 3, 500);
        
        echo "结果: {$result}<br>";
        echo "✅ 测试通过<br><br>";
        
    } catch (Exception $e) {
        echo "❌ 测试失败: " . $e->getMessage() . "<br><br>";
    }
    
    // 测试2：临时性错误重试
    echo "<h2>测试2：临时性错误重试</h2>";
    $attempt_count = 0;
    try {
        $result = Notion_Network_Retry::with_retry(function() use (&$attempt_count) {
            $attempt_count++;
            echo "尝试 #{$attempt_count}<br>";
            
            if ($attempt_count < 3) {
                throw new Exception("cURL错误 28: 操作超时");
            }
            
            return "重试成功";
        }, 3, 500);
        
        echo "结果: {$result}<br>";
        echo "✅ 测试通过（经过 {$attempt_count} 次尝试）<br><br>";
        
    } catch (Exception $e) {
        echo "❌ 测试失败: " . $e->getMessage() . "<br><br>";
    }
    
    // 测试3：永久性错误（不重试）
    echo "<h2>测试3：永久性错误（不重试）</h2>";
    $permanent_attempt_count = 0;
    try {
        $result = Notion_Network_Retry::with_retry(function() use (&$permanent_attempt_count) {
            $permanent_attempt_count++;
            echo "尝试 #{$permanent_attempt_count}<br>";
            throw new Exception("HTTP错误 401: Unauthorized");
        }, 3, 500);
        
        echo "❌ 测试失败：应该抛出异常<br><br>";
        
    } catch (Exception $e) {
        echo "捕获异常: " . $e->getMessage() . "<br>";
        echo "尝试次数: {$permanent_attempt_count}<br>";
        if ($permanent_attempt_count === 1) {
            echo "✅ 测试通过（正确识别永久性错误，未重试）<br><br>";
        } else {
            echo "❌ 测试失败：不应该重试永久性错误<br><br>";
        }
    }
    
    // 测试4：最大重试次数
    echo "<h2>测试4：达到最大重试次数</h2>";
    $max_retry_count = 0;
    try {
        $result = Notion_Network_Retry::with_retry(function() use (&$max_retry_count) {
            $max_retry_count++;
            echo "尝试 #{$max_retry_count}<br>";
            throw new Exception("cURL错误 56: 接收数据错误");
        }, 2, 200);
        
        echo "❌ 测试失败：应该抛出异常<br><br>";
        
    } catch (Exception $e) {
        echo "捕获异常: " . $e->getMessage() . "<br>";
        echo "尝试次数: {$max_retry_count}<br>";
        if ($max_retry_count === 3) { // 2次重试 + 1次初始尝试
            echo "✅ 测试通过（正确达到最大重试次数）<br><br>";
        } else {
            echo "❌ 测试失败：重试次数不正确<br><br>";
        }
    }
    
    // 测试5：错误类型分类
    echo "<h2>测试5：错误类型分类</h2>";

    $test_errors = [
        ["HTTP错误 429: Too Many Requests", "临时性错误"],
        ["HTTP错误 401: Unauthorized", "永久性错误"],
        ["cURL错误 28: 操作超时", "临时性错误"],
        ["cURL错误 3: URL格式错误", "永久性错误"],
        ["网络连接超时", "临时性错误"],
        ["认证失败", "永久性错误"],
    ];

    foreach ($test_errors as $test_case) {
        $exception = new Exception($test_case[0]);
        $expected_type = $test_case[1];

        $is_permanent = Notion_Network_Retry::is_permanent_error($exception);
        $is_temporary = Notion_Network_Retry::is_temporary_error($exception);

        $actual_type = $is_permanent ? "永久性错误" : ($is_temporary ? "临时性错误" : "未知错误");
        
        echo "错误: " . $exception->getMessage() . "<br>";
        echo "预期类型: {$expected_type}, 实际类型: {$actual_type}<br>";
        
        if ($actual_type === $expected_type) {
            echo "✅ 分类正确<br><br>";
        } else {
            echo "❌ 分类错误<br><br>";
        }
    }
    
    // 测试6：WordPress HTTP请求重试
    echo "<h2>测试6：WordPress HTTP请求重试</h2>";
    try {
        // 测试一个可能失败的URL
        $response = Notion_Network_Retry::wp_remote_get_with_retry(
            'https://httpstat.us/500', // 模拟服务器错误
            ['timeout' => 5],
            1, // 只重试1次
            500
        );
        
        echo "❌ 测试失败：应该抛出异常<br><br>";
        
    } catch (Exception $e) {
        echo "捕获异常: " . $e->getMessage() . "<br>";
        echo "✅ 测试通过（正确处理HTTP错误）<br><br>";
    }
    
    // 显示统计信息
    echo "<h2>重试统计信息</h2>";
    $stats = Notion_Network_Retry::get_retry_stats();
    echo "<ul>";
    echo "<li>总尝试次数: " . $stats['total_attempts'] . "</li>";
    echo "<li>成功重试: " . $stats['successful_retries'] . "</li>";
    echo "<li>失败重试: " . $stats['failed_retries'] . "</li>";
    echo "<li>永久性错误: " . $stats['permanent_errors'] . "</li>";
    echo "<li>总延迟时间: " . $stats['total_delay_time'] . " 毫秒</li>";
    echo "</ul>";
}

// 执行测试
test_network_retry();
