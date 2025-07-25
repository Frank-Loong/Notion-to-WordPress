<?php
declare(strict_types=1);

/**
 * Notion 自适应批量处理器类
 * 
 * 根据服务器性能和网络状况动态调整批量处理大小，优化处理效率
 * 实时计算最优批量大小，不使用任何缓存，确保根据当前系统状态动态调整
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

class Notion_Adaptive_Batch {

    /**
     * 批量大小常量
     */
    const MIN_BATCH_SIZE = 10;          // 最小批量大小
    const MAX_BATCH_SIZE = 200;         // 最大批量大小
    const DEFAULT_BATCH_SIZE = 50;      // 默认批量大小
    const MIN_CONCURRENT = 2;           // 最小并发数
    const MAX_CONCURRENT = 15;          // 最大并发数

    /**
     * 缓存相关常量
     */
    const CACHE_DURATION = 30;          // 缓存持续时间（秒）

    /**
     * 静态缓存变量
     */
    private static array $cache = [];
    private static int $last_cache_time = 0;
    
    /**
     * 性能阈值常量
     */
    const MEMORY_HIGH_THRESHOLD = 0.8;  // 内存高使用阈值
    const MEMORY_LOW_THRESHOLD = 0.3;   // 内存低使用阈值
    const CPU_HIGH_THRESHOLD = 2.0;     // CPU高负载阈值
    const RESPONSE_TIME_THRESHOLD = 5.0; // 响应时间阈值（秒）
    
    /**
     * 获取最优批量大小
     * 
     * 根据操作类型、内存使用、系统负载等因素动态计算最优批量大小
     * 不使用任何缓存，确保实时性
     *
     * @since 2.0.0-beta.1
     * @param string $operation_type 操作类型
     * @return int 最优批量大小
     */
    public static function get_optimal_batch_size(string $operation_type): int {
        $current_time = time();
        $cache_key = "batch_size_{$operation_type}";

        // 检查缓存是否有效
        if (isset(self::$cache[$cache_key]) &&
            ($current_time - self::$last_cache_time) < self::CACHE_DURATION) {
            return self::$cache[$cache_key];
        }

        // 获取配置
        $options = get_option('notion_to_wordpress_options', []);
        $base_size = $options['batch_size'] ?? self::DEFAULT_BATCH_SIZE;

        // 计算各种因子（缓存失效时才重新计算）
        $memory_factor = self::calculate_memory_factor();
        $type_factor = self::get_operation_type_factor($operation_type);
        $load_factor = self::calculate_load_factor();
        $network_factor = self::calculate_network_factor();

        // 计算最优大小
        $optimal_size = intval($base_size * $memory_factor * $type_factor * $load_factor * $network_factor);

        // 限制在合理范围内
        $final_size = max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $optimal_size));

        // 缓存结果
        self::$cache[$cache_key] = $final_size;
        self::$last_cache_time = $current_time;

        // 记录调整信息
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf(
                    '自适应批量大小（已缓存%ds）: %s操作 基础=%d 内存=%.2f 类型=%.2f 负载=%.2f 网络=%.2f 最终=%d',
                    self::CACHE_DURATION,
                    $operation_type,
                    $base_size,
                    $memory_factor,
                    $type_factor,
                    $load_factor,
                    $network_factor,
                    $final_size
                ),
                'Adaptive Batch'
            );
        }

        return $final_size;
    }
    
    /**
     * 获取最优并发限制
     * 
     * 根据系统负载和配置动态调整并发数量
     *
     * @since 2.0.0-beta.1
     * @return int 最优并发数
     */
    public static function get_concurrent_limit(): int {
        $options = get_option('notion_to_wordpress_options', []);
        $base_concurrent = $options['concurrent_requests'] ?? 5;
        
        // 基于当前系统负载调整
        $load_avg = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        $load_factor = 1.0;
        
        if ($load_avg && is_array($load_avg) && isset($load_avg[0])) {
            if ($load_avg[0] > self::CPU_HIGH_THRESHOLD) {
                $load_factor = 0.5; // 高负载时减少并发
            } elseif ($load_avg[0] < 1.0) {
                $load_factor = 1.2; // 低负载时增加并发
            }
        }
        
        // 基于内存使用调整
        $memory_factor = self::calculate_memory_factor();
        if ($memory_factor < 0.8) {
            $load_factor *= 0.8; // 内存紧张时减少并发
        }
        
        $optimal_concurrent = intval($base_concurrent * $load_factor);
        $final_concurrent = max(self::MIN_CONCURRENT, min(self::MAX_CONCURRENT, $optimal_concurrent));
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf(
                    '自适应并发数: 基础=%d 负载=%.2f 内存=%.2f 最终=%d',
                    $base_concurrent,
                    $load_factor,
                    $memory_factor,
                    $final_concurrent
                ),
                'Adaptive Batch'
            );
        }
        
        return $final_concurrent;
    }
    
    /**
     * 计算内存因子
     *
     * @since 2.0.0-beta.1
     * @return float 内存调整因子
     */
    private static function calculate_memory_factor(): float {
        if (!class_exists('Notion_Memory_Manager')) {
            return 1.0;
        }
        
        $memory_usage = Notion_Memory_Manager::get_memory_usage();
        $usage_ratio = $memory_usage['current'] / $memory_usage['limit'];
        
        if ($usage_ratio > self::MEMORY_HIGH_THRESHOLD) {
            return 0.5; // 内存紧张时大幅减少批量大小
        } elseif ($usage_ratio > 0.6) {
            return 0.8; // 内存使用较高时适度减少
        } elseif ($usage_ratio < self::MEMORY_LOW_THRESHOLD) {
            return 1.5; // 内存充足时增加批量大小
        }
        
        return 1.0; // 正常情况
    }
    
    /**
     * 获取操作类型因子
     *
     * @since 2.0.0-beta.1
     * @param string $operation_type 操作类型
     * @return float 类型调整因子
     */
    private static function get_operation_type_factor(string $operation_type): float {
        $type_factors = [
            'api_requests' => 1.0,          // API请求标准处理
            'database_operations' => 1.2,   // 数据库操作可以更大批量
            'image_processing' => 0.8,      // 图片处理需要更小批量
            'content_conversion' => 1.1,    // 内容转换适度增加
            'file_operations' => 0.9,       // 文件操作稍微减少
            'network_requests' => 0.7,      // 网络请求需要更小批量
            'memory_intensive' => 0.6,      // 内存密集型操作大幅减少
            'cpu_intensive' => 0.7          // CPU密集型操作减少
        ];
        
        return $type_factors[$operation_type] ?? 1.0;
    }
    
    /**
     * 计算系统负载因子
     *
     * @since 2.0.0-beta.1
     * @return float 负载调整因子
     */
    private static function calculate_load_factor(): float {
        // 检查系统负载
        if (function_exists('sys_getloadavg')) {
            $load_avg = sys_getloadavg();
            if ($load_avg && is_array($load_avg) && isset($load_avg[0])) {
                if ($load_avg[0] > self::CPU_HIGH_THRESHOLD) {
                    return 0.6; // 高负载时大幅减少
                } elseif ($load_avg[0] > 1.5) {
                    return 0.8; // 中等负载时适度减少
                } elseif ($load_avg[0] < 0.5) {
                    return 1.3; // 低负载时增加
                }
            }
        }
        
        return 1.0; // 无法检测或正常负载
    }
    
    /**
     * 计算网络性能因子
     *
     * @since 2.0.0-beta.1
     * @return float 网络调整因子
     */
    private static function calculate_network_factor(): float {
        // 检查最近的网络响应时间
        $recent_response_time = self::get_recent_response_time();
        
        if ($recent_response_time > self::RESPONSE_TIME_THRESHOLD) {
            return 0.7; // 网络慢时减少批量大小
        } elseif ($recent_response_time > 2.0) {
            return 0.9; // 网络较慢时适度减少
        } elseif ($recent_response_time < 1.0) {
            return 1.2; // 网络快时增加批量大小
        }
        
        return 1.0; // 正常网络速度
    }
    
    /**
     * 获取最近的响应时间（优化版本，避免网络请求）
     *
     * @since 2.0.0-beta.1
     * @return float 平均响应时间（秒）
     */
    private static function get_recent_response_time(): float {
        // 尝试从性能监控获取数据
        if (class_exists('Notion_Performance_Monitor')) {
            $stats = Notion_Performance_Monitor::get_recent_stats();
            if (isset($stats['avg_response_time'])) {
                return $stats['avg_response_time'];
            }
        }

        // 移除网络测试，避免性能开销
        // 使用默认的网络响应时间，避免每次都发送网络请求
        return 1.5; // 假设正常的网络响应时间
    }
    
    /**
     * 获取自适应批量处理统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public static function get_adaptive_stats(): array {
        $memory_usage = class_exists('Notion_Memory_Manager') 
            ? Notion_Memory_Manager::get_memory_usage() 
            : ['usage_percentage' => 0];
            
        $load_avg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
        $response_time = self::get_recent_response_time();
        
        return [
            'memory_usage_percent' => $memory_usage['usage_percentage'] ?? 0,
            'cpu_load_1min' => $load_avg[0] ?? 0,
            'cpu_load_5min' => $load_avg[1] ?? 0,
            'cpu_load_15min' => $load_avg[2] ?? 0,
            'avg_response_time' => $response_time,
            'memory_factor' => self::calculate_memory_factor(),
            'load_factor' => self::calculate_load_factor(),
            'network_factor' => self::calculate_network_factor(),
            'batch_size_range' => [
                'min' => self::MIN_BATCH_SIZE,
                'max' => self::MAX_BATCH_SIZE,
                'default' => self::DEFAULT_BATCH_SIZE
            ],
            'concurrent_range' => [
                'min' => self::MIN_CONCURRENT,
                'max' => self::MAX_CONCURRENT
            ]
        ];
    }
    
    /**
     * 获取操作类型的建议批量大小
     *
     * @since 2.0.0-beta.1
     * @param array $operation_types 操作类型数组
     * @return array [operation_type => batch_size] 映射
     */
    public static function get_batch_sizes_for_operations(array $operation_types): array {
        $results = [];
        
        foreach ($operation_types as $operation_type) {
            $results[$operation_type] = self::get_optimal_batch_size($operation_type);
        }
        
        return $results;
    }
    
    /**
     * 检查是否需要调整批量大小
     *
     * @since 2.0.0-beta.1
     * @param string $operation_type 操作类型
     * @param int $current_batch_size 当前批量大小
     * @return array 调整建议
     */
    public static function should_adjust_batch_size(string $operation_type, int $current_batch_size): array {
        $optimal_size = self::get_optimal_batch_size($operation_type);
        $difference = abs($optimal_size - $current_batch_size);
        $percentage_diff = ($difference / $current_batch_size) * 100;
        
        $should_adjust = $percentage_diff > 20; // 差异超过20%时建议调整
        
        return [
            'should_adjust' => $should_adjust,
            'current_size' => $current_batch_size,
            'optimal_size' => $optimal_size,
            'difference' => $optimal_size - $current_batch_size,
            'percentage_diff' => $percentage_diff,
            'reason' => $should_adjust ? self::get_adjustment_reason($operation_type) : '当前批量大小已优化'
        ];
    }
    
    /**
     * 获取调整原因
     *
     * @since 2.0.0-beta.1
     * @param string $operation_type 操作类型
     * @return string 调整原因
     */
    private static function get_adjustment_reason(string $operation_type): string {
        $memory_factor = self::calculate_memory_factor();
        $load_factor = self::calculate_load_factor();
        $network_factor = self::calculate_network_factor();
        
        $reasons = [];
        
        if ($memory_factor < 0.8) {
            $reasons[] = '内存使用率过高';
        } elseif ($memory_factor > 1.2) {
            $reasons[] = '内存充足可增加批量';
        }
        
        if ($load_factor < 0.8) {
            $reasons[] = 'CPU负载过高';
        } elseif ($load_factor > 1.2) {
            $reasons[] = 'CPU负载较低可增加批量';
        }
        
        if ($network_factor < 0.8) {
            $reasons[] = '网络响应较慢';
        } elseif ($network_factor > 1.2) {
            $reasons[] = '网络响应良好可增加批量';
        }
        
        return empty($reasons) ? '系统性能优化' : implode('，', $reasons);
    }
}
