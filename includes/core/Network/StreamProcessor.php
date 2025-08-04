<?php
declare(strict_types=1);

namespace NTWP\Core\Network;

/**
 * 流处理器 - 负责大数据集的流式处理
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
            \NTWP\Core\Foundation\Logger::debug_log(
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

    /**
     * 流式处理大数据集 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @param array $data 要处理的数据数组
     * @param callable $processor 处理函数
     * @param int $chunk_size 分块大小
     * @return array|string 处理结果
     */
    public static function process_data_stream(array $data, callable $processor, int $chunk_size = self::DEFAULT_CHUNK_SIZE) {
        if (empty($data)) {
            return [];
        }

        $start_time = microtime(true);

        // 记录流式处理开始信息
        Logger::debug_log(
            sprintf('流式处理开始: %d项数据，分块大小: %d', count($data), $chunk_size),
            'StreamProcessor'
        );

        $results = [];
        $chunks = array_chunk($data, $chunk_size);

        foreach ($chunks as $chunk_index => $chunk) {
            // 内存监控
            if ($chunk_index % 25 === 0) {
                $current_memory = memory_get_usage(true);
                $memory_limit = self::get_memory_limit_mb() * 1024 * 1024;

                if ($current_memory > ($memory_limit * 0.8)) {
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }

            // 处理当前分块
            try {
                $chunk_result = call_user_func($processor, $chunk);
                if (is_array($chunk_result)) {
                    $results = array_merge($results, $chunk_result);
                } else {
                    $results[] = $chunk_result;
                }
            } catch (Exception $e) {
                Logger::error_log(
                    sprintf('流式处理分块失败: %s', $e->getMessage()),
                    'StreamProcessor'
                );
                continue;
            }
        }

        $execution_time = microtime(true) - $start_time;
        Logger::debug_log(
            sprintf('流式处理完成，耗时: %.2fs，处理了 %d 个分块', $execution_time, count($chunks)),
            'StreamProcessor'
        );

        return $results;
    }

    /**
     * 处理大数据集 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @param array $blocks Notion块数组
     * @param object $notion_api API实例
     * @param string $state_id 状态ID
     * @return string HTML内容
     */
    public static function process_large_dataset(array $blocks, $notion_api, string $state_id = null): string {
        if (empty($blocks)) {
            return '';
        }

        // 检查内存限制
        if (!self::is_memory_available()) {
            Logger::warning_log('内存不足，无法处理大数据集', 'StreamProcessor');
            return '';
        }

        // 创建块处理器
        $block_processor = function($block_chunk) use ($notion_api, $state_id) {
            $html_parts = [];

            foreach ($block_chunk as $block) {
                try {
                    $html = self::convert_single_block_lightweight($block, $notion_api);
                    if (!empty($html)) {
                        $html_parts[] = $html;
                    }
                } catch (Exception $e) {
                    Logger::error_log(
                        sprintf('块转换失败: %s', $e->getMessage()),
                        'StreamProcessor'
                    );
                }
            }

            return implode("\n", $html_parts);
        };

        // 使用流式处理
        $results = self::process_data_stream($blocks, $block_processor, 30);

        return is_array($results) ? implode("\n", $results) : $results;
    }

    /**
     * 检查内存状态 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @return array 内存状态信息
     */
    private static function check_memory_status(): array {
        $current_usage = memory_get_usage(true);
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $usage_percentage = ($current_usage / $memory_limit) * 100;

        return [
            'current_usage' => $current_usage,
            'memory_limit' => $memory_limit,
            'usage_percentage' => $usage_percentage,
            'is_critical' => $usage_percentage > 90,
            'needs_chunk_adjustment' => $usage_percentage > 80
        ];
    }

    /**
     * 获取内存使用量(MB) (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @return float 内存使用量
     */
    public static function get_memory_usage_mb(): float {
        return memory_get_usage(true) / 1024 / 1024;
    }

    /**
     * 获取内存限制(MB) (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @return int 内存限制
     */
    public static function get_memory_limit_mb(): int {
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        return intval($limit / 1024 / 1024);
    }

    /**
     * 检查内存是否可用 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @return bool 内存是否充足
     */
    public static function is_memory_available(): bool {
        $memory_status = self::check_memory_status();
        return $memory_status['usage_percentage'] < 85;
    }

    /**
     * 清理内存 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     */
    public static function cleanup_memory(): void {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }

    /**
     * 获取流处理统计信息 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public static function get_stream_stats(): array {
        return [
            'memory_usage_mb' => self::get_memory_usage_mb(),
            'memory_limit_mb' => self::get_memory_limit_mb(),
            'memory_available' => self::is_memory_available(),
            'timestamp' => time()
        ];
    }

    /**
     * 轻量级块转换 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @param array $block Notion块
     * @param object $notion_api API实例
     * @return string HTML内容
     */
    private static function convert_single_block_lightweight(array $block, $notion_api): string {
        $block_type = $block['type'] ?? 'unknown';

        try {
            switch ($block_type) {
                case 'paragraph':
                    return self::convert_paragraph_lightweight($block);
                case 'heading_1':
                case 'heading_2':
                case 'heading_3':
                    $level = intval(substr($block_type, -1));
                    return self::convert_heading_lightweight($block, $level);
                case 'bulleted_list_item':
                case 'numbered_list_item':
                    return self::convert_list_item_lightweight($block, substr($block_type, 0, -10));
                case 'to_do':
                    return self::convert_todo_lightweight($block);
                case 'quote':
                    return self::convert_quote_lightweight($block);
                case 'code':
                    return self::convert_code_lightweight($block);
                default:
                    return '<p><!-- 不支持的块类型: ' . esc_html($block_type) . ' --></p>';
            }
        } catch (Exception $e) {
            Logger::error_log(
                sprintf('块转换失败 [%s]: %s', $block_type, $e->getMessage()),
                'StreamProcessor'
            );
            return '';
        }
    }

    /**
     * 轻量级段落转换 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @param array $block 段落块
     * @return string HTML内容
     */
    private static function convert_paragraph_lightweight(array $block): string {
        $text = self::extract_rich_text_simple($block['paragraph']['rich_text'] ?? []);
        return empty($text) ? '<p>&nbsp;</p>' : '<p>' . $text . '</p>';
    }

    /**
     * 轻量级标题转换 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @param array $block 标题块
     * @param int $level 标题级别
     * @return string HTML内容
     */
    private static function convert_heading_lightweight(array $block, int $level): string {
        $heading_key = 'heading_' . $level;
        $text = self::extract_rich_text_simple($block[$heading_key]['rich_text'] ?? []);
        return "<h{$level}>{$text}</h{$level}>";
    }

    /**
     * 轻量级列表项转换 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @param array $block 列表项块
     * @param string $type 列表类型
     * @return string HTML内容
     */
    private static function convert_list_item_lightweight(array $block, string $type): string {
        $key = $type . '_list_item';
        $text = self::extract_rich_text_simple($block[$key]['rich_text'] ?? []);
        return '<li>' . $text . '</li>';
    }

    /**
     * 轻量级待办事项转换 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @param array $block 待办事项块
     * @return string HTML内容
     */
    private static function convert_todo_lightweight(array $block): string {
        $text = self::extract_rich_text_simple($block['to_do']['rich_text'] ?? []);
        $checked = ($block['to_do']['checked'] ?? false) ? ' checked' : '';

        return '<li class="notion-to-do">' .
               '<input type="checkbox"' . $checked . ' disabled>' .
               '<span>' . $text . '</span></li>';
    }

    /**
     * 轻量级引用转换 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @param array $block 引用块
     * @return string HTML内容
     */
    private static function convert_quote_lightweight(array $block): string {
        $text = self::extract_rich_text_simple($block['quote']['rich_text'] ?? []);
        return '<blockquote>' . $text . '</blockquote>';
    }

    /**
     * 轻量级代码块转换 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @param array $block 代码块
     * @return string HTML内容
     */
    private static function convert_code_lightweight(array $block): string {
        $code = $block['code']['rich_text'][0]['plain_text'] ?? '';
        $language = $block['code']['language'] ?? 'text';

        return '<pre class="notion-code"><code class="language-' . esc_attr($language) . '">' .
               esc_html($code) . '</code></pre>';
    }

    /**
     * 简化的富文本提取 (兼容Stream_Processor)
     *
     * @since 2.0.0-beta.1
     * @param array $rich_text 富文本数组
     * @return string 纯文本
     */
    private static function extract_rich_text_simple(array $rich_text): string {
        if (empty($rich_text)) {
            return '';
        }

        $text_parts = [];
        foreach ($rich_text as $text_item) {
            $plain_text = $text_item['plain_text'] ?? '';

            // 简化的格式处理
            if (!empty($text_item['annotations'])) {
                $annotations = $text_item['annotations'];
                if ($annotations['bold'] ?? false) {
                    $plain_text = '<strong>' . $plain_text . '</strong>';
                }
                if ($annotations['italic'] ?? false) {
                    $plain_text = '<em>' . $plain_text . '</em>';
                }
                if ($annotations['code'] ?? false) {
                    $plain_text = '<code>' . $plain_text . '</code>';
                }
            }

            $text_parts[] = $plain_text;
        }

        return implode('', $text_parts);
    }
}