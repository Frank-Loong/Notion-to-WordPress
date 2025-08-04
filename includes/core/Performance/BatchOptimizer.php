<?php
declare(strict_types=1);

namespace NTWP\Core\Performance;

/**
 * 批处理优化器 - 负责批处理大小优化
 *
 * 从Memory_Manager拆分出的专职类
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
class BatchOptimizer {
    
    const MIN_BATCH_SIZE = 10;
    const MAX_BATCH_SIZE = 200;
    const DEFAULT_BATCH_SIZE = 50;
    const MIN_CONCURRENT = 2;
    const MAX_CONCURRENT = 15;
    const MEMORY_HIGH_THRESHOLD = 0.8;
    const MEMORY_LOW_THRESHOLD = 0.3;
    const CPU_HIGH_THRESHOLD = 2.0;
    const RESPONSE_TIME_THRESHOLD = 3.0;
    
    /**
     * 获取最优批量大小
     */
    public static function get_optimal_batch_size(string $operation_type): int {
        $base_size = self::DEFAULT_BATCH_SIZE;

        $memory_factor = self::calculate_memory_factor();
        $load_factor = self::calculate_load_factor();
        $type_factor = self::get_operation_type_factor($operation_type);

        $optimal_size = intval($base_size * $memory_factor * $load_factor * $type_factor);

        return max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $optimal_size));
    }
    
    /**
     * 获取最优并发数量
     */
    public static function get_concurrent_limit(): int {
        $options = get_option('notion_to_wordpress_options', []);
        $base_concurrent = $options['concurrent_requests'] ?? 5;

        // 基于当前系统负载调整
        $load_avg = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        if ($load_avg && is_array($load_avg) && isset($load_avg[0])) {
            if ($load_avg[0] > self::CPU_HIGH_THRESHOLD) {
                $base_concurrent = max(self::MIN_CONCURRENT, intval($base_concurrent * 0.6));
            } elseif ($load_avg[0] < 0.5) {
                $base_concurrent = min(self::MAX_CONCURRENT, intval($base_concurrent * 1.3));
            }
        }

        // 基于内存使用情况调整
        $memory_usage = MemoryMonitor::get_memory_usage();
        $usage_ratio = $memory_usage['current'] / $memory_usage['limit'];

        if ($usage_ratio > self::MEMORY_HIGH_THRESHOLD) {
            $base_concurrent = max(self::MIN_CONCURRENT, intval($base_concurrent * 0.7));
        }

        return max(self::MIN_CONCURRENT, min(self::MAX_CONCURRENT, $base_concurrent));
    }
    
    /**
     * 计算最优分块大小
     */
    public static function calculate_optimal_chunk_size(int $total_items, int $suggested_size = 100): int {
        $memory_usage = MemoryMonitor::get_memory_usage();
        
        // 基于内存使用情况调整
        if ($memory_usage['usage_percentage'] > 70) {
            $memory_factor = 0.5;
        } elseif ($memory_usage['usage_percentage'] < 30) {
            $memory_factor = 1.5;
        } else {
            $memory_factor = 1.0;
        }
        
        // 基于总项目数调整
        if ($total_items > 10000) {
            $size_factor = 0.8;
        } elseif ($total_items < 100) {
            $size_factor = 2.0;
        } else {
            $size_factor = 1.0;
        }
        
        $optimal_size = intval($suggested_size * $memory_factor * $size_factor);
        
        return max(10, min(500, $optimal_size));
    }
    
    /**
     * 计算内存因子
     */
    private static function calculate_memory_factor(): float {
        $memory_usage = MemoryMonitor::get_memory_usage();
        $usage_ratio = $memory_usage['current'] / $memory_usage['limit'];

        if ($usage_ratio > self::MEMORY_HIGH_THRESHOLD) {
            return 0.5;
        } elseif ($usage_ratio > 0.6) {
            return 0.8;
        } elseif ($usage_ratio < self::MEMORY_LOW_THRESHOLD) {
            return 1.5;
        }

        return 1.0;
    }

    /**
     * 获取操作类型调整因子
     */
    private static function get_operation_type_factor(string $operation_type): float {
        $type_factors = [
            'api_requests' => 1.0,
            'database_operations' => 1.2,
            'image_processing' => 0.8,
            'content_conversion' => 1.1,
            'file_operations' => 0.9,
            'default' => 1.0
        ];

        return $type_factors[$operation_type] ?? $type_factors['default'];
    }

    /**
     * 计算系统负载因子
     */
    private static function calculate_load_factor(): float {
        if (function_exists('sys_getloadavg')) {
            $load_avg = sys_getloadavg();
            if ($load_avg && is_array($load_avg) && isset($load_avg[0])) {
                if ($load_avg[0] > self::CPU_HIGH_THRESHOLD) {
                    return 0.6;
                } elseif ($load_avg[0] < 0.5) {
                    return 1.3;
                }
            }
        }

        return 1.0;
    }
    
    /**
     * 获取自适应批量处理统计信息
     */
    public static function get_adaptive_stats(): array {
        $memory_usage = MemoryMonitor::get_memory_usage();
        $load_avg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

        return [
            'memory_usage_percent' => $memory_usage['usage_percentage'],
            'memory_current_mb' => round($memory_usage['current'] / 1024 / 1024, 2),
            'memory_limit_mb' => round($memory_usage['limit'] / 1024 / 1024, 2),
            'system_load_1min' => $load_avg[0] ?? 0,
            'system_load_5min' => $load_avg[1] ?? 0,
            'system_load_15min' => $load_avg[2] ?? 0,
            'recommended_batch_size' => [
                'api_requests' => self::get_optimal_batch_size('api_requests'),
                'database_operations' => self::get_optimal_batch_size('database_operations'),
                'image_processing' => self::get_optimal_batch_size('image_processing'),
                'content_conversion' => self::get_optimal_batch_size('content_conversion')
            ],
            'concurrent_limit' => self::get_concurrent_limit()
        ];
    }
}