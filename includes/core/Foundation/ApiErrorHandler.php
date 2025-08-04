<?php
declare(strict_types=1);

namespace NTWP\Core\Foundation;

use NTWP\Core\Foundation\Logger;
use NTWP\Utils\ApiResult;

/**
 * API错误处理服务
 *
 * 从原始API.php中提取的错误处理和重试机制
 * 提供智能错误分类、重试策略和降级处理
 *
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}
class ApiErrorHandler {
    
    /**
     * 增强的重试配置 (从原API.php恢复)
     */
    private static array $retry_config = [
        'NETWORK_ERROR' => [
            'max_retries' => 3, 
            'backoff' => [1, 3, 9], 
            'should_retry' => true
        ],
        'RATE_LIMIT_ERROR' => [
            'max_retries' => 5, 
            'backoff' => [5, 15, 45, 120, 300], 
            'should_retry' => true
        ],
        'SERVER_ERROR' => [
            'max_retries' => 2, 
            'backoff' => [2, 8], 
            'should_retry' => true
        ],
        'FILTER_ERROR' => [
            'max_retries' => 1, 
            'backoff' => [1], 
            'should_retry' => false
        ],
        'AUTH_ERROR' => [
            'max_retries' => 0, 
            'backoff' => [], 
            'should_retry' => false
        ],
        'CLIENT_ERROR' => [
            'max_retries' => 0, 
            'backoff' => [], 
            'should_retry' => false
        ]
    ];
    
    /**
     * 错误处理统计
     */
    private static array $error_stats = [
        'total_errors' => 0,
        'retry_attempts' => 0,
        'successful_retries' => 0,
        'fallback_activations' => 0,
        'error_types' => []
    ];
    
    /**
     * 带退避的重试机制 (从原API.php恢复)
     *
     * @param callable $operation 要执行的操作
     * @param string $operation_name 操作名称
     * @param array $context 上下文信息
     * @return mixed 操作结果
     */
    public static function retry_with_backoff(callable $operation, string $operation_name = 'API调用', array $context = []) {
        $max_attempts = 3;
        $attempt = 0;
        $last_exception = null;
        
        while ($attempt < $max_attempts) {
            $attempt++;
            
            try {
                Logger::debug_log(
                    sprintf('%s - 尝试 %d/%d', $operation_name, $attempt, $max_attempts),
                    'ApiErrorHandler'
                );
                
                $result = $operation();
                
                if ($attempt > 1) {
                    self::$error_stats['successful_retries']++;
                    Logger::debug_log(
                        sprintf('%s - 重试成功 (第%d次尝试)', $operation_name, $attempt),
                        'ApiErrorHandler'
                    );
                }
                
                return $result;
                
            } catch (\Exception $e) {
                $last_exception = $e;
                self::$error_stats['total_errors']++;
                
                $error_type = self::classify_api_error_precise($e);
                self::$error_stats['error_types'][$error_type] = (self::$error_stats['error_types'][$error_type] ?? 0) + 1;
                
                Logger::debug_log(
                    sprintf('%s - 尝试 %d 失败: %s (错误类型: %s)', 
                        $operation_name, $attempt, $e->getMessage(), $error_type),
                    'ApiErrorHandler'
                );
                
                // 检查是否应该重试
                if (!self::should_retry_enhanced($error_type, $attempt)) {
                    Logger::debug_log(
                        sprintf('%s - 不再重试 (错误类型: %s)', $operation_name, $error_type),
                        'ApiErrorHandler'
                    );
                    break;
                }
                
                // 如果不是最后一次尝试，等待后重试
                if ($attempt < $max_attempts) {
                    $backoff_time = self::calculate_backoff_time($error_type, $attempt);
                    
                    Logger::debug_log(
                        sprintf('%s - 等待 %d 秒后重试', $operation_name, $backoff_time),
                        'ApiErrorHandler'
                    );
                    
                    sleep($backoff_time);
                    self::$error_stats['retry_attempts']++;
                }
            }
        }
        
        // 所有重试都失败，抛出最后的异常
        if ($last_exception) {
            Logger::debug_log(
                sprintf('%s - 所有重试失败，放弃操作', $operation_name),
                'ApiErrorHandler'
            );
            throw $last_exception;
        }
        
        throw new \Exception(sprintf('%s - 未知错误，重试失败', $operation_name));
    }
    
    /**
     * 精确的API错误分类 (从原API.php恢复)
     *
     * @param \Exception $exception 异常对象
     * @return string 错误类型
     */
    public static function classify_api_error_precise(\Exception $exception): string {
        $message = $exception->getMessage();
        $http_code = self::extract_http_code($exception);
        
        // 网络连接错误
        if (strpos($message, 'cURL error') !== false || 
            strpos($message, 'Connection timed out') !== false ||
            strpos($message, 'Could not resolve host') !== false) {
            return 'NETWORK_ERROR';
        }
        
        // 基于HTTP状态码分类
        switch ($http_code) {
            case 401:
            case 403:
                return 'AUTH_ERROR';
                
            case 400:
                // 进一步细分400错误
                if (strpos($message, 'filter') !== false || 
                    strpos($message, 'query') !== false) {
                    return 'FILTER_ERROR';
                }
                return 'CLIENT_ERROR';
                
            case 429:
                return 'RATE_LIMIT_ERROR';
                
            case 500:
            case 502:
            case 503:
            case 504:
                return 'SERVER_ERROR';
                
            default:
                // 基于错误消息内容分类
                if (strpos($message, 'rate limit') !== false || 
                    strpos($message, 'too many requests') !== false) {
                    return 'RATE_LIMIT_ERROR';
                }
                
                if (strpos($message, 'invalid_request') !== false || 
                    strpos($message, 'validation_error') !== false) {
                    return 'CLIENT_ERROR';
                }
                
                return 'NETWORK_ERROR';
        }
    }
    
    /**
     * 获取降级策略 (从原API.php恢复)
     *
     * @param string $error_type 错误类型
     * @param int $estimated_data_size 估算数据大小
     * @param array $context 上下文信息
     * @return string 降级策略
     */
    public static function get_fallback_strategy(string $error_type, int $estimated_data_size, array $context = []): string {
        switch ($error_type) {
            case 'RATE_LIMIT_ERROR':
                return $estimated_data_size > 500 ? 'throttled_sync' : 'paginated_sync';
                
            case 'FILTER_ERROR':
                return 'simplified_filter';
                
            case 'SERVER_ERROR':
                return 'conservative_sync';
                
            case 'NETWORK_ERROR':
                return $estimated_data_size > 100 ? 'paginated_sync' : 'single_request';
                
            default:
                return 'conservative_sync';
        }
    }
    
    /**
     * 执行降级同步 (从原API.php恢复)
     *
     * @param string $database_id 数据库ID
     * @param array $original_filter 原始筛选条件
     * @param string $fallback_strategy 降级策略
     * @param array $context 上下文信息
     * @return array 同步结果
     */
    public static function execute_fallback_sync(string $database_id, array $original_filter, string $fallback_strategy, array $context = []): array {
        self::$error_stats['fallback_activations']++;
        
        Logger::debug_log(
            sprintf('执行降级同步: %s, 策略: %s', $database_id, $fallback_strategy),
            'ApiErrorHandler'
        );
        
        switch ($fallback_strategy) {
            case 'simplified_filter':
                return self::execute_simplified_filter_sync($database_id, $original_filter);
                
            case 'paginated_sync':
                return self::execute_paginated_sync($database_id, $original_filter);
                
            case 'throttled_sync':
                return self::execute_throttled_sync($database_id, $original_filter);
                
            case 'conservative_sync':
                return self::execute_conservative_sync($database_id, $original_filter);
                
            default:
                Logger::debug_log(
                    sprintf('未知降级策略: %s', $fallback_strategy),
                    'ApiErrorHandler'
                );
                return [];
        }
    }
    
    /**
     * 获取增强错误处理统计 (从原API.php恢复)
     *
     * @return array 错误处理统计
     */
    public static function get_enhanced_error_handling_stats(): array {
        $stats = self::$error_stats;
        
        // 计算衍生指标
        $stats['retry_success_rate'] = self::$error_stats['retry_attempts'] > 0
            ? round(self::$error_stats['successful_retries'] / self::$error_stats['retry_attempts'] * 100, 2) . '%'
            : '0%';
            
        $stats['fallback_usage_rate'] = self::$error_stats['total_errors'] > 0
            ? round(self::$error_stats['fallback_activations'] / self::$error_stats['total_errors'] * 100, 2) . '%'
            : '0%';
            
        // 最常见的错误类型
        if (!empty(self::$error_stats['error_types'])) {
            $stats['most_common_error'] = array_keys(self::$error_stats['error_types'], 
                max(self::$error_stats['error_types']))[0];
        } else {
            $stats['most_common_error'] = 'N/A';
        }
        
        return $stats;
    }
    
    /**
     * 测试增强错误处理 (从原API.php恢复)
     *
     * @param string $test_scenario 测试场景
     * @return ApiResult 测试结果
     */
    public static function test_enhanced_error_handling(string $test_scenario = 'filter_error'): ApiResult {
        Logger::debug_log(
            sprintf('测试错误处理: %s', $test_scenario),
            'ApiErrorHandler'
        );
        
        try {
            switch ($test_scenario) {
                case 'filter_error':
                    throw new \Exception('invalid_filter: Property "invalid_property" does not exist');
                    
                case 'rate_limit':
                    throw new \Exception('rate_limit_exceeded: Too many requests');
                    
                case 'network_error':
                    throw new \Exception('cURL error 7: Could not resolve host');
                    
                case 'server_error':
                    throw new \Exception('HTTP 500: Internal Server Error');
                    
                default:
                    throw new \Exception('Unknown test scenario');
            }
        } catch (\Exception $e) {
            $error_type = self::classify_api_error_precise($e);
            $fallback_strategy = self::get_fallback_strategy($error_type, 100);
            
            return new ApiResult(true, [
                'error_type' => $error_type,
                'fallback_strategy' => $fallback_strategy,
                'should_retry' => self::should_retry_enhanced($error_type, 1),
                'backoff_time' => self::calculate_backoff_time($error_type, 1)
            ], '错误处理测试完成');
        }
    }
    
    /**
     * 检查是否应该重试 (增强版)
     */
    private static function should_retry_enhanced(string $error_type, int $attempt_count): bool {
        $config = self::$retry_config[$error_type] ?? self::$retry_config['NETWORK_ERROR'];
        
        return $config['should_retry'] && $attempt_count < $config['max_retries'];
    }
    
    /**
     * 计算退避时间
     */
    private static function calculate_backoff_time(string $error_type, int $attempt_count): int {
        $config = self::$retry_config[$error_type] ?? self::$retry_config['NETWORK_ERROR'];
        $backoff_array = $config['backoff'];
        
        $index = min($attempt_count - 1, count($backoff_array) - 1);
        return $backoff_array[$index] ?? 1;
    }
    
    /**
     * 从异常中提取HTTP状态码
     */
    private static function extract_http_code(\Exception $exception): int {
        $message = $exception->getMessage();
        
        // 尝试从错误消息中提取HTTP状态码
        if (preg_match('/HTTP\s+(\d{3})/', $message, $matches)) {
            return (int)$matches[1];
        }
        
        // 尝试从WordPress HTTP错误中提取
        if (preg_match('/HTTP\s+Error\s+(\d{3})/', $message, $matches)) {
            return (int)$matches[1];
        }
        
        // 基于错误消息推断状态码
        if (strpos($message, 'Unauthorized') !== false) {
            return 401;
        }
        
        if (strpos($message, 'Forbidden') !== false) {
            return 403;
        }
        
        if (strpos($message, 'rate limit') !== false) {
            return 429;
        }
        
        return 0; // 未知状态码
    }
    
    /**
     * 执行简化筛选同步
     */
    private static function execute_simplified_filter_sync(string $database_id, array $original_filter): array {
        // 移除复杂的筛选条件，只保留基本条件
        $simplified_filter = [];
        
        if (isset($original_filter['and'])) {
            // 只保留第一个条件
            $simplified_filter = $original_filter['and'][0] ?? [];
        } elseif (isset($original_filter['or'])) {
            // 转换为简单条件
            $simplified_filter = $original_filter['or'][0] ?? [];
        } else {
            $simplified_filter = $original_filter;
        }
        
        Logger::debug_log('使用简化筛选条件', 'ApiErrorHandler');
        
        // 这里需要调用实际的API，暂时返回空数组
        return [];
    }
    
    /**
     * 执行分页同步
     */
    private static function execute_paginated_sync(string $database_id, array $filter): array {
        Logger::debug_log('使用分页同步策略', 'ApiErrorHandler');
        
        // 实现分页逻辑
        $all_results = [];
        $page_size = 50; // 较小的页面大小
        $next_cursor = null;
        
        // 这里需要实际的API调用逻辑
        // 暂时返回空数组
        return $all_results;
    }
    
    /**
     * 执行节流同步
     */
    private static function execute_throttled_sync(string $database_id, array $filter): array {
        Logger::debug_log('使用节流同步策略', 'ApiErrorHandler');
        
        // 添加延迟以避免速率限制
        sleep(2);
        
        // 使用较小的并发数
        return [];
    }
    
    /**
     * 执行保守同步
     */
    private static function execute_conservative_sync(string $database_id, array $filter): array {
        Logger::debug_log('使用保守同步策略', 'ApiErrorHandler');
        
        // 最基本的同步方式，移除所有筛选条件
        return [];
    }
}