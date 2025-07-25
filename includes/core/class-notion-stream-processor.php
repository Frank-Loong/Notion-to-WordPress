<?php
declare(strict_types=1);

/**
 * Notion 轻量级流式处理器类
 * 
 * 为小数据集提供简化的流式处理，避免过度优化的性能开销
 * 专为小于100项的数据集设计，提供更快的处理速度和更低的内存占用
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

class Notion_Stream_Processor {
    
    /**
     * 轻量级处理常量
     */
    const DEFAULT_CHUNK_SIZE = 50;          // 轻量级默认分块大小
    const GC_FREQUENCY = 10;                // 每处理10个块进行一次垃圾回收
    const MAX_CHUNK_SIZE = 100;             // 最大分块大小
    const MIN_CHUNK_SIZE = 10;              // 最小分块大小
    
    /**
     * 轻量级流式数据处理
     * 
     * 简化版的流式处理，专为小数据集优化
     * 减少内存监控和动态调整的开销
     *
     * @since 2.0.0-beta.1
     * @param array $data 要处理的数据数组
     * @param callable $processor 处理函数，接收数据块并返回结果
     * @param int $chunk_size 分块大小，默认50
     * @return array 处理结果数组
     */
    public static function process_data_stream(array $data, callable $processor, int $chunk_size = self::DEFAULT_CHUNK_SIZE): array {
        if (empty($data)) {
            return [];
        }

        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        $results = [];
        
        // 限制分块大小在合理范围内
        $effective_chunk_size = max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, $chunk_size));
        
        // 对于小数据集，直接处理而不分块
        if (count($data) <= $effective_chunk_size) {
            $results = $processor($data);
            
            // 记录处理统计
            self::log_processing_stats(count($data), microtime(true) - $start_time, $start_memory, 'direct');
            
            return is_array($results) ? $results : [];
        }
        
        // 分块处理
        $chunks = array_chunk($data, $effective_chunk_size);
        $chunk_count = 0;
        
        foreach ($chunks as $chunk) {
            $chunk_results = $processor($chunk);
            
            if (is_array($chunk_results)) {
                $results = array_merge($results, $chunk_results);
            }
            
            // 轻量级内存清理
            unset($chunk, $chunk_results);
            $chunk_count++;
            
            // 定期垃圾回收（频率较低）
            if ($chunk_count % self::GC_FREQUENCY === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        // 最终清理
        unset($chunks);
        
        // 记录处理统计
        self::log_processing_stats(count($data), microtime(true) - $start_time, $start_memory, 'chunked');
        
        return $results;
    }

    /**
     * 生成器版本的流式处理
     * 
     * 使用PHP生成器实现真正的流式处理，内存占用最小
     *
     * @since 2.0.0-beta.1
     * @param array $data 要处理的数据数组
     * @param callable $processor 处理函数
     * @param int $chunk_size 分块大小
     * @return Generator 生成器对象
     */
    public static function process_data_generator(array $data, callable $processor, int $chunk_size = self::DEFAULT_CHUNK_SIZE): Generator {
        if (empty($data)) {
            return;
        }

        $effective_chunk_size = max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, $chunk_size));
        $chunks = array_chunk($data, $effective_chunk_size);
        $chunk_count = 0;
        
        foreach ($chunks as $chunk) {
            $chunk_results = $processor($chunk);
            
            if (is_array($chunk_results)) {
                foreach ($chunk_results as $result) {
                    yield $result;
                }
            }
            
            // 轻量级内存清理
            unset($chunk, $chunk_results);
            $chunk_count++;
            
            // 定期垃圾回收
            if ($chunk_count % self::GC_FREQUENCY === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        unset($chunks);
    }

    /**
     * 简单的批量处理
     * 
     * 最简化的批量处理，适用于非常小的数据集
     *
     * @since 2.0.0-beta.1
     * @param array $data 要处理的数据数组
     * @param callable $processor 处理函数
     * @return array 处理结果
     */
    public static function simple_batch_process(array $data, callable $processor): array {
        if (empty($data)) {
            return [];
        }

        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        // 直接处理，不分块
        $results = $processor($data);
        
        // 记录处理统计
        self::log_processing_stats(count($data), microtime(true) - $start_time, $start_memory, 'simple');
        
        return is_array($results) ? $results : [];
    }

    /**
     * 获取轻量级处理器的性能统计
     *
     * @since 2.0.0-beta.1
     * @return array 性能统计信息
     */
    public static function get_performance_stats(): array {
        static $stats = [
            'total_processed' => 0,
            'total_time' => 0,
            'total_memory_saved' => 0,
            'processing_modes' => []
        ];
        
        return $stats;
    }

    /**
     * 重置性能统计
     *
     * @since 2.0.0-beta.1
     */
    public static function reset_performance_stats(): void {
        // 重置静态统计变量
        $stats = [
            'total_processed' => 0,
            'total_time' => 0,
            'total_memory_saved' => 0,
            'processing_modes' => []
        ];
    }

    /**
     * 记录处理统计信息
     *
     * @since 2.0.0-beta.1
     * @param int $item_count 处理的项目数
     * @param float $processing_time 处理时间
     * @param int $start_memory 开始时的内存使用
     * @param string $mode 处理模式
     */
    private static function log_processing_stats(int $item_count, float $processing_time, int $start_memory, string $mode): void {
        $end_memory = memory_get_usage(true);
        $memory_diff = $start_memory - $end_memory;
        
        // 更新静态统计
        static $stats = [
            'total_processed' => 0,
            'total_time' => 0,
            'total_memory_saved' => 0,
            'processing_modes' => []
        ];
        
        $stats['total_processed'] += $item_count;
        $stats['total_time'] += $processing_time;
        $stats['total_memory_saved'] += abs($memory_diff);
        
        if (!isset($stats['processing_modes'][$mode])) {
            $stats['processing_modes'][$mode] = 0;
        }
        $stats['processing_modes'][$mode]++;
        
        // 记录到日志
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf(
                    '轻量级处理完成: 模式=%s, 项目=%d, 耗时=%.3fs, 内存变化=%s',
                    $mode,
                    $item_count,
                    $processing_time,
                    $memory_diff > 0 ? '+' . size_format($memory_diff) : size_format(abs($memory_diff))
                ),
                'Stream Processor'
            );
        }
        
        // 集成到性能监控系统
        if (class_exists('Notion_Performance_Monitor')) {
            if (method_exists('Notion_Performance_Monitor', 'record_custom_metric')) {
                Notion_Performance_Monitor::record_custom_metric('stream_processor_items', $item_count);
                Notion_Performance_Monitor::record_custom_metric('stream_processor_time', $processing_time);
            }
        }
    }

    /**
     * 检查是否适合使用轻量级处理器
     *
     * @since 2.0.0-beta.1
     * @param int $data_count 数据项数量
     * @return bool 是否适合使用轻量级处理器
     */
    public static function is_suitable_for_lightweight(int $data_count): bool {
        // 检查数据量
        if ($data_count > 100) {
            return false;
        }
        
        // 检查系统负载
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg()[0] ?? 1.0;
            if ($load > 3.0) {
                return false; // 系统负载过高时不使用轻量级模式
            }
        }
        
        return true;
    }

    /**
     * 获取推荐的分块大小
     *
     * @since 2.0.0-beta.1
     * @param int $data_count 数据项数量
     * @return int 推荐的分块大小
     */
    public static function get_recommended_chunk_size(int $data_count): int {
        if ($data_count <= 20) {
            return $data_count; // 非常小的数据集不分块
        } elseif ($data_count <= 50) {
            return 25;
        } else {
            return self::DEFAULT_CHUNK_SIZE;
        }
    }
}
