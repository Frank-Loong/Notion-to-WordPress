<?php
declare(strict_types=1);

namespace NTWP\Core;

use NTWP\Core\MemoryMonitor;
use NTWP\Core\StreamProcessor;
use NTWP\Core\BatchOptimizer;
use NTWP\Core\GarbageCollector;

/**
 * 内存管理器适配器 - 保持向后兼容
 * 
 * 将原有的Memory_Manager功能分发到新的专职类
 * 
 * @deprecated 直接使用专职类: MemoryMonitor, StreamProcessor, BatchOptimizer, GarbageCollector
 */
class Memory_Manager {
    
    // 保持原有常量
    const MEMORY_WARNING_THRESHOLD = 0.8;
    const MEMORY_CRITICAL_THRESHOLD = 0.9;
    const DEFAULT_CHUNK_SIZE = 100;
    const GC_FREQUENCY = 10;
    const LIGHTWEIGHT_THRESHOLD = 100;
    
    /**
     * @deprecated 使用 StreamProcessor::stream_process()
     */
    public static function stream_process(array $data, callable $processor, int $chunk_size = self::DEFAULT_CHUNK_SIZE): array {
        return StreamProcessor::stream_process($data, $processor, $chunk_size);
    }
    
    /**
     * @deprecated 使用 MemoryMonitor::get_memory_usage()
     */
    public static function get_memory_usage(): array {
        return MemoryMonitor::get_memory_usage();
    }
    
    /**
     * @deprecated 使用 MemoryMonitor::is_memory_warning()
     */
    public static function is_memory_warning(): bool {
        return MemoryMonitor::is_memory_warning();
    }
    
    /**
     * @deprecated 使用 MemoryMonitor::is_memory_critical()
     */
    public static function is_memory_critical(): bool {
        return MemoryMonitor::is_memory_critical();
    }
    
    /**
     * @deprecated 使用 GarbageCollector::force_garbage_collection()
     */
    public static function force_garbage_collection(): void {
        GarbageCollector::force_garbage_collection();
    }
    
    /**
     * @deprecated 使用 BatchOptimizer::get_optimal_batch_size()
     */
    public static function get_optimal_batch_size(string $operation_type): int {
        return BatchOptimizer::get_optimal_batch_size($operation_type);
    }
    
    /**
     * @deprecated 使用 BatchOptimizer::get_concurrent_limit()
     */
    public static function get_concurrent_limit(): int {
        return BatchOptimizer::get_concurrent_limit();
    }
    
    /**
     * @deprecated 使用 StreamProcessor::optimize_large_array_processing()
     */
    public static function optimize_large_array_processing(array &$large_array, callable $processor): void {
        StreamProcessor::optimize_large_array_processing($large_array, $processor);
    }
    
    /**
     * @deprecated 使用 MemoryMonitor::monitor_memory_usage()
     */
    public static function monitor_memory_usage(string $operation_name = 'Unknown'): void {
        MemoryMonitor::monitor_memory_usage($operation_name);
    }
    
    /**
     * @deprecated 使用 MemoryMonitor::get_optimization_suggestions()
     */
    public static function get_optimization_suggestions(): array {
        return MemoryMonitor::get_optimization_suggestions();
    }
    
    /**
     * @deprecated 使用 StreamProcessor::stream_process_generator()
     */
    public static function stream_process_generator(array $data, callable $processor, int $chunk_size = 30): \Generator {
        return StreamProcessor::stream_process_generator($data, $processor, $chunk_size);
    }
    
    /**
     * @deprecated 使用 BatchOptimizer::get_adaptive_stats()
     */
    public static function get_adaptive_stats(): array {
        return BatchOptimizer::get_adaptive_stats();
    }
    
    // 其他保持兼容的方法...
    public static function is_lightweight_mode(int $data_count = 0, bool $force_check = false): bool {
        $options = get_option('notion_to_wordpress_options', []);
        $enable_lightweight = $options['enable_lightweight_mode'] ?? true;

        if (!$enable_lightweight) {
            return false;
        }

        if ($data_count > 0 && $data_count < self::LIGHTWEIGHT_THRESHOLD) {
            return true;
        }

        if ($force_check) {
            $usage = MemoryMonitor::get_memory_usage();
            $system_load = function_exists('sys_getloadavg') ? sys_getloadavg()[0] ?? 1.0 : 1.0;

            if ($usage['usage_percentage'] < 50 && $system_load < 2.0) {
                return true;
            }
        }

        return false;
    }
    
    public static function smart_process(array $data, callable $processor, int $chunk_size = null): array {
        $data_count = count($data);

        if (self::is_lightweight_mode($data_count, true)) {
            if (class_exists('NTWP\\Utils\\Stream_Processor')) {
                $effective_chunk_size = $chunk_size ?? 50;
                $stream_processor = new \NTWP\Utils\Stream_Processor();
                return $stream_processor->process_data_stream($data, $processor, $effective_chunk_size);
            }
        }

        $effective_chunk_size = $chunk_size ?? self::DEFAULT_CHUNK_SIZE;
        return StreamProcessor::stream_process($data, $processor, $effective_chunk_size);
    }
}