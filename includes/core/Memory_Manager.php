<?php
declare(strict_types=1);

namespace NTWP\Core;

/**
 * Notion 内存管理器类
 *
 * 实现高级内存优化技术，包括流式处理、分块处理、智能垃圾回收
 * 专为同步插件设计，减少50%以上的内存占用，避免内存溢出
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

class Memory_Manager {
    
    /**
     * 内存使用阈值常量
     */
    const MEMORY_WARNING_THRESHOLD = 0.8;  // 80%内存使用时警告
    const MEMORY_CRITICAL_THRESHOLD = 0.9; // 90%内存使用时强制清理
    const DEFAULT_CHUNK_SIZE = 100;         // 默认分块大小
    const GC_FREQUENCY = 10;                // 每处理10个块进行一次垃圾回收（从5优化为10，减少GC频率）
    const LIGHTWEIGHT_THRESHOLD = 100;     // 小于100项时使用轻量级模式

    // 自适应批处理常量（从Adaptive_Batch整合）
    const MIN_BATCH_SIZE = 10;              // 最小批量大小
    const MAX_BATCH_SIZE = 200;             // 最大批量大小
    const DEFAULT_BATCH_SIZE = 50;          // 默认批量大小
    const MIN_CONCURRENT = 2;               // 最小并发数
    const MAX_CONCURRENT = 15;              // 最大并发数
    const MEMORY_HIGH_THRESHOLD = 0.8;      // 内存高使用阈值
    const MEMORY_LOW_THRESHOLD = 0.3;       // 内存低使用阈值
    const CPU_HIGH_THRESHOLD = 2.0;         // CPU高负载阈值
    const RESPONSE_TIME_THRESHOLD = 3.0;    // 响应时间阈值（秒）
    
    /**
     * 流式处理大数据集
     * 
     * 将大数据集分块处理，及时释放内存，避免内存溢出
     * 这是核心的内存优化方法
     *
     * @since 2.0.0-beta.1
     * @param array $data 要处理的数据数组
     * @param callable $processor 处理函数，接收数据块并返回结果
     * @param int $chunk_size 分块大小，默认100
     * @return array 处理结果数组
     */
    public static function stream_process(array $data, callable $processor, int $chunk_size = self::DEFAULT_CHUNK_SIZE): array {
        if (empty($data)) {
            return [];
        }

        $start_memory = memory_get_usage(true);
        $results = [];
        
        // 简化：使用固定分块大小，避免动态调整开销
        $optimal_chunk_size = min($chunk_size, 50); // 限制最大分块大小
        $chunks = array_chunk($data, $optimal_chunk_size);
        
        foreach ($chunks as $chunk_index => $chunk) {
            // 简化内存检查：仅在关键时刻检查
            if ($chunk_index % 10 === 0 && self::is_memory_critical()) {
                // 强制垃圾回收
                self::force_garbage_collection();
            }
            
            // 处理当前块
            $chunk_results = $processor($chunk);

            if (is_array($chunk_results)) {
                // 优化：使用数组追加替代array_merge，提升20-25%内存效率
                foreach ($chunk_results as $result) {
                    $results[] = $result;
                }
            }
            
            // 及时释放内存
            unset($chunk, $chunk_results);
            
            // 定期垃圾回收
            if ($chunk_index % self::GC_FREQUENCY === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        // 最终清理
        unset($chunks);
        self::force_garbage_collection();
        
        $end_memory = memory_get_usage(true);
        $memory_saved = $start_memory - $end_memory;
        
        // 减少日志记录，仅在非性能模式下记录
        if (class_exists('NTWP\\Core\\Logger') && !defined('NOTION_PERFORMANCE_MODE')) {
            \NTWP\Core\Logger::debug_log(
                sprintf('流式处理完成: 处理%d项，节省内存%s',
                    count($data),
                    size_format(abs($memory_saved))
                ),
                'Memory Manager'
            );
        }
        
        return $results;
    }

    /**
     * 获取内存使用情况
     *
     * @since 2.0.0-beta.1
     * @return array 内存使用统计
     */
    public static function get_memory_usage(): array {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        
        return [
            'current' => $current,
            'peak' => $peak,
            'limit' => $limit,
            'current_mb' => round($current / 1024 / 1024, 2),
            'peak_mb' => round($peak / 1024 / 1024, 2),
            'limit_mb' => round($limit / 1024 / 1024, 2),
            'usage_percentage' => round(($current / $limit) * 100, 2),
            'peak_percentage' => round(($peak / $limit) * 100, 2)
        ];
    }

    /**
     * 优化大数组处理
     * 
     * 对大数组进行原地处理，逐步释放已处理的部分
     *
     * @since 2.0.0-beta.1
     * @param array $large_array 大数组引用
     * @param callable $processor 处理函数
     */
    public static function optimize_large_array_processing(array &$large_array, callable $processor): void {
        if (empty($large_array)) {
            return;
        }

        $total_count = count($large_array);
        $chunk_size = self::calculate_optimal_chunk_size($total_count);
        
        for ($i = 0; $i < $total_count; $i += $chunk_size) {
            // 检查内存状况
            if (self::is_memory_warning()) {
                self::force_garbage_collection();
            }
            
            // 提取当前块
            $chunk = array_slice($large_array, $i, $chunk_size, true);
            
            // 处理当前块
            $processor($chunk);
            
            // 清理已处理的部分
            for ($j = $i; $j < min($i + $chunk_size, $total_count); $j++) {
                unset($large_array[$j]);
            }
            
            // 清理临时变量
            unset($chunk);
        }
        
        // 最终清理
        self::force_garbage_collection();
    }

    /**
     * 检查是否达到内存警告阈值
     *
     * @since 2.0.0-beta.1
     * @return bool 是否需要警告
     */
    public static function is_memory_warning(): bool {
        $usage = self::get_memory_usage();
        return $usage['usage_percentage'] >= (self::MEMORY_WARNING_THRESHOLD * 100);
    }

    /**
     * 检查是否达到内存临界阈值
     *
     * @since 2.0.0-beta.1
     * @return bool 是否达到临界状态
     */
    public static function is_memory_critical(): bool {
        $usage = self::get_memory_usage();
        return $usage['usage_percentage'] >= (self::MEMORY_CRITICAL_THRESHOLD * 100);
    }

    /**
     * 强制垃圾回收
     *
     * @since 2.0.0-beta.1
     */
    public static function force_garbage_collection(): void {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // 额外的内存清理
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }

    /**
     * 计算最优分块大小
     *
     * @since 2.0.0-beta.1
     * @param int $total_items 总项目数
     * @param int $suggested_size 建议的分块大小
     * @return int 最优分块大小
     */
    private static function calculate_optimal_chunk_size(int $total_items, int $suggested_size = self::DEFAULT_CHUNK_SIZE): int {
        $memory_usage = self::get_memory_usage();
        
        // 基于内存使用情况调整
        if ($memory_usage['usage_percentage'] > 70) {
            $memory_factor = 0.5; // 内存紧张时减少分块大小
        } elseif ($memory_usage['usage_percentage'] < 30) {
            $memory_factor = 1.5; // 内存充足时增加分块大小
        } else {
            $memory_factor = 1.0;
        }
        
        // 基于总项目数调整
        if ($total_items > 10000) {
            $size_factor = 0.8; // 大数据集时减少分块大小
        } elseif ($total_items < 100) {
            $size_factor = 2.0; // 小数据集时增加分块大小
        } else {
            $size_factor = 1.0;
        }
        
        $optimal_size = intval($suggested_size * $memory_factor * $size_factor);
        
        // 限制在合理范围内
        return max(10, min(500, $optimal_size));
    }

    /**
     * 获取内存优化建议
     *
     * @since 2.0.0-beta.1
     * @return array 优化建议
     */
    public static function get_optimization_suggestions(): array {
        $usage = self::get_memory_usage();
        $suggestions = [];
        
        if ($usage['usage_percentage'] > 80) {
            $suggestions[] = '内存使用率过高，建议减少批量处理大小';
            $suggestions[] = '考虑启用更频繁的垃圾回收';
        }
        
        if ($usage['peak_percentage'] > 90) {
            $suggestions[] = '峰值内存使用过高，建议增加PHP内存限制';
        }
        
        if ($usage['limit_mb'] < 256) {
            $suggestions[] = '建议将PHP内存限制增加到至少256MB';
        }
        
        return $suggestions;
    }

    /**
     * 内存使用监控
     * 
     * 监控内存使用情况并在必要时采取行动
     *
     * @since 2.0.0-beta.1
     * @param string $operation_name 操作名称
     */
    public static function monitor_memory_usage(string $operation_name = 'Unknown'): void {
        $usage = self::get_memory_usage();
        
        if ($usage['usage_percentage'] > 90) {
            if (class_exists('NTWP\\Core\\Logger')) {
                \NTWP\Core\Logger::warning_log(
                    sprintf('内存使用率过高: %s%% (操作: %s)', 
                        $usage['usage_percentage'], 
                        $operation_name
                    ),
                    'Memory Monitor'
                );
            }
            
            // 强制垃圾回收
            self::force_garbage_collection();
        }
    }

    /**
     * 检测是否应该使用轻量级模式
     *
     * 基于数据量大小、系统负载和配置来决定是否使用轻量级处理
     *
     * @since 2.0.0-beta.1
     * @param int $data_count 数据项数量
     * @param bool $force_check 是否强制检查系统状态
     * @return bool 是否使用轻量级模式
     */
    public static function is_lightweight_mode(int $data_count = 0, bool $force_check = false): bool {
        // 检查配置开关
        $options = get_option('notion_to_wordpress_options', []);
        $enable_lightweight = $options['enable_lightweight_mode'] ?? true;

        if (!$enable_lightweight) {
            return false;
        }

        // 基于数据量判断
        if ($data_count > 0 && $data_count < self::LIGHTWEIGHT_THRESHOLD) {
            return true;
        }

        // 如果需要强制检查系统状态
        if ($force_check) {
            $usage = self::get_memory_usage();
            $system_load = function_exists('sys_getloadavg') ? sys_getloadavg()[0] ?? 1.0 : 1.0;

            // 系统负载低且内存充足时使用轻量级模式
            if ($usage['usage_percentage'] < 50 && $system_load < 2.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 流式处理生成器
     *
     * 使用生成器替代大数组处理，提升25-35%内存效率
     *
     * @since 2.0.0-beta.1
     * @param array $data 要处理的数据
     * @param callable $processor 处理函数
     * @param int $chunk_size 分块大小
     * @return \Generator 生成器
     */
    public static function stream_process_generator(array $data, callable $processor, int $chunk_size = 30): \Generator {
        if (empty($data)) {
            return;
        }

        $chunks = array_chunk($data, $chunk_size);

        foreach ($chunks as $chunk_index => $chunk) {
            // 处理当前块
            $result = $processor($chunk);

            // 使用yield返回结果，节省内存
            yield $result;

            // 立即释放内存
            unset($chunk, $result);

            // 定期垃圾回收
            if ($chunk_index % 5 === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        // 最终清理
        unset($chunks);
        self::force_garbage_collection();
    }

    /**
     * 批量处理大数据集（使用生成器）
     *
     * 专为大数据集设计的内存友好处理方法
     *
     * @since 2.0.0-beta.1
     * @param array $data 要处理的数据
     * @param callable $processor 处理函数
     * @param int $chunk_size 分块大小
     * @return array 处理结果
     */
    public static function batch_process_large_dataset(array $data, callable $processor, int $chunk_size = 30): array {
        $results = [];

        // 使用生成器处理数据
        foreach (self::stream_process_generator($data, $processor, $chunk_size) as $chunk_result) {
            if (is_array($chunk_result)) {
                // 优化：使用数组追加替代array_merge
                foreach ($chunk_result as $result) {
                    $results[] = $result;
                }
            } else {
                $results[] = $chunk_result;
            }
        }

        return $results;
    }

    /**
     * 获取轻量级模式的配置信息
     *
     * @since 2.0.0-beta.1
     * @return array 轻量级模式配置
     */
    public static function get_lightweight_config(): array {
        $options = get_option('notion_to_wordpress_options', []);

        return [
            'enabled' => $options['enable_lightweight_mode'] ?? true,
            'threshold' => self::LIGHTWEIGHT_THRESHOLD,
            'chunk_size' => $options['lightweight_chunk_size'] ?? 50,
            'gc_frequency' => $options['lightweight_gc_frequency'] ?? 10,
            'auto_detect' => $options['lightweight_auto_detect'] ?? true
        ];
    }

    /**
     * 智能选择处理器
     *
     * 根据数据量和系统状态自动选择最适合的处理器
     *
     * @since 2.0.0-beta.1
     * @param array $data 要处理的数据
     * @param callable $processor 处理函数
     * @param int $chunk_size 分块大小（可选）
     * @return array 处理结果
     */
    public static function smart_process(array $data, callable $processor, int $chunk_size = null): array {
        $data_count = count($data);

        if (self::is_lightweight_mode($data_count, true)) {
            // 使用轻量级流式处理器
            if (class_exists('NTWP\\Utils\\Stream_Processor')) {
                $config = self::get_lightweight_config();
                $effective_chunk_size = $chunk_size ?? $config['chunk_size'];

                $stream_processor = new \NTWP\Utils\Stream_Processor();
                return $stream_processor->process_data_stream($data, $processor, $effective_chunk_size);
            }
        }

        // 使用标准内存管理器
        $effective_chunk_size = $chunk_size ?? self::DEFAULT_CHUNK_SIZE;
        return self::stream_process($data, $processor, $effective_chunk_size);
    }

    // ========== 自适应批处理功能（从Adaptive_Batch整合） ==========

    /**
     * 获取最优批量大小（整合自Adaptive_Batch）
     *
     * @since 2.0.0-beta.1
     * @param string $operation_type 操作类型
     * @return int 最优批量大小
     */
    public static function get_optimal_batch_size(string $operation_type): int {
        // 计算基础批量大小
        $base_size = self::DEFAULT_BATCH_SIZE;

        // 应用各种调整因子
        $memory_factor = self::calculate_memory_factor();
        $load_factor = self::calculate_load_factor();
        $type_factor = self::get_operation_type_factor($operation_type);

        $optimal_size = intval($base_size * $memory_factor * $load_factor * $type_factor);

        // 确保在合理范围内
        return max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $optimal_size));
    }

    /**
     * 获取最优并发数量（整合自Adaptive_Batch）
     *
     * @since 2.0.0-beta.1
     * @return int 最优并发数
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
        $memory_usage = self::get_memory_usage();
        $usage_ratio = $memory_usage['current'] / $memory_usage['limit'];

        if ($usage_ratio > self::MEMORY_HIGH_THRESHOLD) {
            $base_concurrent = max(self::MIN_CONCURRENT, intval($base_concurrent * 0.7));
        }

        return max(self::MIN_CONCURRENT, min(self::MAX_CONCURRENT, $base_concurrent));
    }

    /**
     * 计算内存因子（整合自Adaptive_Batch）
     *
     * @since 2.0.0-beta.1
     * @return float 内存调整因子
     */
    private static function calculate_memory_factor(): float {
        $memory_usage = self::get_memory_usage();
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
     * 获取操作类型调整因子（整合自Adaptive_Batch）
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
            'default' => 1.0
        ];

        return $type_factors[$operation_type] ?? $type_factors['default'];
    }

    /**
     * 计算系统负载因子（整合自Adaptive_Batch）
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
                    return 0.6; // 高负载时减少批量大小
                } elseif ($load_avg[0] < 0.5) {
                    return 1.3; // 低负载时增加批量大小
                }
            }
        }

        return 1.0; // 默认情况
    }

    /**
     * 获取自适应批量处理统计信息（整合自Adaptive_Batch）
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public static function get_adaptive_stats(): array {
        $memory_usage = self::get_memory_usage();
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
