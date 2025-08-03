<?php
declare(strict_types=1);

namespace NTWP\Core;

/**
 * 流处理器 - 负责大数据集的流式处理
 * 
 * 从Memory_Manager拆分出的专职类
 */
class StreamProcessor {
    
    const DEFAULT_CHUNK_SIZE = 100;
    const GC_FREQUENCY = 10;
    
    /**
     * 流式处理大数据集
     * 
     * @param array $data 要处理的数据数组
     * @param callable $processor 处理函数
     * @param int $chunk_size 分块大小
     * @return array 处理结果数组
     */
    public static function stream_process(array $data, callable $processor, int $chunk_size = self::DEFAULT_CHUNK_SIZE): array {
        if (empty($data)) {
            return [];
        }

        $start_memory = memory_get_usage(true);
        $results = [];
        
        $optimal_chunk_size = min($chunk_size, 50);
        $chunks = array_chunk($data, $optimal_chunk_size);
        
        foreach ($chunks as $chunk_index => $chunk) {
            // 内存检查
            if ($chunk_index % 10 === 0 && MemoryMonitor::is_memory_critical()) {
                GarbageCollector::force_garbage_collection();
            }
            
            // 处理当前块
            $chunk_results = $processor($chunk);

            if (is_array($chunk_results)) {
                foreach ($chunk_results as $result) {
                    $results[] = $result;
                }
            }
            
            // 及时释放内存
            unset($chunk, $chunk_results);
            
            // 定期垃圾回收
            if ($chunk_index % self::GC_FREQUENCY === 0) {
                GarbageCollector::collect_cycles();
            }
        }
        
        // 最终清理
        unset($chunks);
        GarbageCollector::force_garbage_collection();
        
        $end_memory = memory_get_usage(true);
        $memory_saved = $start_memory - $end_memory;
        
        if (class_exists('NTWP\\Core\\Logger') && !defined('NOTION_PERFORMANCE_MODE')) {
            \NTWP\Core\Logger::debug_log(
                sprintf('流式处理完成: 处理%d项，节省内存%s',
                    count($data),
                    size_format(abs($memory_saved))
                ),
                'Stream Processor'
            );
        }
        
        return $results;
    }
    
    /**
     * 流式处理生成器
     */
    public static function stream_process_generator(array $data, callable $processor, int $chunk_size = 30): \Generator {
        if (empty($data)) {
            return;
        }

        $chunks = array_chunk($data, $chunk_size);

        foreach ($chunks as $chunk_index => $chunk) {
            $result = $processor($chunk);
            yield $result;

            unset($chunk, $result);

            if ($chunk_index % 5 === 0) {
                GarbageCollector::collect_cycles();
            }
        }

        unset($chunks);
        GarbageCollector::force_garbage_collection();
    }
    
    /**
     * 优化大数组处理
     */
    public static function optimize_large_array_processing(array &$large_array, callable $processor): void {
        if (empty($large_array)) {
            return;
        }

        $total_count = count($large_array);
        $chunk_size = BatchOptimizer::calculate_optimal_chunk_size($total_count);
        
        for ($i = 0; $i < $total_count; $i += $chunk_size) {
            if (MemoryMonitor::is_memory_warning()) {
                GarbageCollector::force_garbage_collection();
            }
            
            $chunk = array_slice($large_array, $i, $chunk_size, true);
            $processor($chunk);
            
            // 清理已处理的部分
            for ($j = $i; $j < min($i + $chunk_size, $total_count); $j++) {
                unset($large_array[$j]);
            }
            
            unset($chunk);
        }
        
        GarbageCollector::force_garbage_collection();
    }
}