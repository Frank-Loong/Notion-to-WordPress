<?php
declare(strict_types=1);

/**
 * Notion 流式数据处理器
 * 
 * 实现大数据集的流式处理机制，优化内存使用效率
 * 支持分块处理、动态内存监控、智能垃圾回收
 * 
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 流式数据处理器类
 */
class Notion_Stream_Processor {
    
    /**
     * 处理配置常量（优化版）
     */
    const DEFAULT_CHUNK_SIZE = 30;           // 默认分块大小（从50优化为30，提升内存效率）
    const MIN_CHUNK_SIZE = 20;               // 最小分块大小
    const MAX_CHUNK_SIZE = 100;              // 最大分块大小（减少以提高稳定性）
    const MEMORY_CHECK_FREQUENCY = 25;       // 内存检查频率（从10优化为25，减少75%检查开销）
    const GC_TRIGGER_THRESHOLD = 0.85;       // 垃圾回收触发阈值（从0.8优化为0.85，减少无效GC）
    const MEMORY_LIMIT_THRESHOLD = 256;      // 内存限制阈值(MB)
    
    /**
     * 处理统计信息
     * @var array
     */
    private static $processing_stats = [
        'total_processed' => 0,
        'chunks_processed' => 0,
        'memory_peak' => 0,
        'gc_triggered' => 0,
        'processing_time' => 0,
        'chunk_size_adjustments' => 0
    ];
    
    /**
     * 流式处理大数据集
     * 
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
        $start_memory = memory_get_usage(true);
        
        // 重置统计信息
        self::reset_stats();
        
        // 简化：使用固定分块大小，避免动态调整的开销
        $optimal_chunk_size = min($chunk_size, self::MAX_CHUNK_SIZE);

        // 减少日志记录
        if (class_exists('Notion_Logger') && !defined('NOTION_PERFORMANCE_MODE')) {
            Notion_Logger::debug_log(
                sprintf('流式处理开始: %d项数据，分块大小: %d', count($data), $optimal_chunk_size),
                'Stream Processor'
            );
        }
        
        $results = [];
        $chunks = array_chunk($data, $optimal_chunk_size);
        
        foreach ($chunks as $chunk_index => $chunk) {
            // 简化内存监控：仅在必要时检查
            if ($chunk_index % self::MEMORY_CHECK_FREQUENCY === 0) {
                $current_memory = memory_get_usage(true);
                $memory_limit = self::get_memory_limit();

                // 简化的内存检查
                if ($current_memory > ($memory_limit * 0.8)) {
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                        self::$processing_stats['gc_triggered']++;
                    }
                }
            }
            
            // 处理当前块
            $chunk_result = $processor($chunk);
            
            // 合并结果（优化：避免array_merge的性能开销）
            if (is_array($chunk_result)) {
                foreach ($chunk_result as $result) {
                    $results[] = $result;
                }
            } elseif (is_string($chunk_result)) {
                $results .= $chunk_result;
            }
            
            // 更新统计信息
            self::$processing_stats['total_processed'] += count($chunk);
            self::$processing_stats['chunks_processed']++;
            
            // 及时释放内存
            unset($chunk, $chunk_result);
            
            // 定期垃圾回收
            if ($chunk_index % self::MEMORY_CHECK_FREQUENCY === 0) {
                self::trigger_garbage_collection();
            }
        }
        
        // 最终统计
        $end_time = microtime(true);
        self::$processing_stats['processing_time'] = round(($end_time - $start_time) * 1000, 2);
        self::$processing_stats['memory_peak'] = memory_get_peak_usage(true) - $start_memory;
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('流式处理完成: 处理%d项，耗时%.2fms，峰值内存%.2fMB', 
                    self::$processing_stats['total_processed'],
                    self::$processing_stats['processing_time'],
                    self::$processing_stats['memory_peak'] / 1024 / 1024
                ),
                'Stream Processor'
            );
        }
        
        return $results;
    }
    
    /**
     * 处理大数据集的内容转换
     * 
     * @param array $blocks Notion块数组
     * @param Notion_API $notion_api API实例
     * @param string $state_id 状态ID
     * @return string HTML内容
     */
    public static function process_large_dataset(array $blocks, Notion_API $notion_api, string $state_id = null): string {
        if (empty($blocks)) {
            return '';
        }
        
        // 检查内存限制
        $memory_limit = self::get_memory_limit_mb();
        if ($memory_limit < self::MEMORY_LIMIT_THRESHOLD) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    sprintf('内存限制过低: %dMB，建议至少%dMB', $memory_limit, self::MEMORY_LIMIT_THRESHOLD),
                    'Stream Processor'
                );
            }
        }
        
        // 创建块处理器
        $block_processor = function($block_chunk) use ($notion_api, $state_id) {
            $html_parts = [];
            $list_wrapper = null;
            
            foreach ($block_chunk as $block) {
                $block_type = $block['type'] ?? 'unknown';
                
                // 简化的列表处理逻辑
                $is_list_item = in_array($block_type, ['bulleted_list_item', 'numbered_list_item', 'to_do']);
                
                if ($is_list_item) {
                    $required_wrapper = self::get_list_wrapper_type($block_type);
                    
                    if ($list_wrapper !== $required_wrapper) {
                        if ($list_wrapper !== null) {
                            $html_parts[] = self::close_list_wrapper($list_wrapper);
                        }
                        $html_parts[] = self::open_list_wrapper($required_wrapper);
                        $list_wrapper = $required_wrapper;
                    }
                } elseif ($list_wrapper !== null) {
                    $html_parts[] = self::close_list_wrapper($list_wrapper);
                    $list_wrapper = null;
                }
                
                // 转换单个块
                $block_html = self::convert_single_block_lightweight($block, $notion_api);
                
                if (!empty($block_html)) {
                    $html_parts[] = $block_html;
                }
            }
            
            // 关闭未关闭的列表
            if ($list_wrapper !== null) {
                $html_parts[] = self::close_list_wrapper($list_wrapper);
            }
            
            return implode('', $html_parts);
        };
        
        // 使用流式处理
        return self::process_data_stream($blocks, $block_processor);
    }
    
    /**
     * 检查内存状态
     * 
     * @return array 内存状态信息
     */
    private static function check_memory_status(): array {
        $current_usage = memory_get_usage(true);
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $usage_percentage = ($current_usage / $memory_limit) * 100;
        
        return [
            'current_mb' => round($current_usage / 1024 / 1024, 2),
            'usage_percentage' => round($usage_percentage, 2),
            'needs_gc' => $usage_percentage > (self::GC_TRIGGER_THRESHOLD * 100),
            'needs_chunk_adjustment' => $usage_percentage > 85,
            'is_critical' => $usage_percentage > 90
        ];
    }
    
    /**
     * 计算最优分块大小
     * 
     * @param int $total_count 总数据量
     * @param int $suggested_size 建议大小
     * @return int 最优分块大小
     */
    private static function calculate_optimal_chunk_size(int $total_count, int $suggested_size): int {
        $memory_status = self::check_memory_status();
        
        // 根据内存使用情况调整
        if ($memory_status['usage_percentage'] > 80) {
            $suggested_size = max(self::MIN_CHUNK_SIZE, intval($suggested_size * 0.7));
        } elseif ($memory_status['usage_percentage'] < 50) {
            $suggested_size = min(self::MAX_CHUNK_SIZE, intval($suggested_size * 1.3));
        }
        
        // 根据数据量调整
        if ($total_count < 50) {
            $suggested_size = min($suggested_size, 20);
        } elseif ($total_count > 500) {
            $suggested_size = max($suggested_size, 30);
        }
        
        return max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, $suggested_size));
    }
    
    /**
     * 动态调整分块大小
     * 
     * @param int $current_size 当前分块大小
     * @param array $memory_status 内存状态
     * @return int 调整后的分块大小
     */
    private static function adjust_chunk_size(int $current_size, array $memory_status): int {
        if ($memory_status['is_critical']) {
            return max(self::MIN_CHUNK_SIZE, intval($current_size * 0.5));
        } elseif ($memory_status['needs_chunk_adjustment']) {
            return max(self::MIN_CHUNK_SIZE, intval($current_size * 0.8));
        }
        
        return $current_size;
    }
    
    /**
     * 触发垃圾回收
     */
    private static function trigger_garbage_collection(): void {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
            self::$processing_stats['gc_triggered']++;
        }
        
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }
    
    /**
     * 获取内存限制(MB)
     * 
     * @return int 内存限制
     */
    private static function get_memory_limit_mb(): int {
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        return intval($limit / 1024 / 1024);
    }
    
    /**
     * 重置统计信息
     */
    private static function reset_stats(): void {
        self::$processing_stats = [
            'total_processed' => 0,
            'chunks_processed' => 0,
            'memory_peak' => 0,
            'gc_triggered' => 0,
            'processing_time' => 0,
            'chunk_size_adjustments' => 0
        ];
    }
    
    /**
     * 获取处理统计信息
     *
     * @return array 统计信息
     */
    public static function get_processing_stats(): array {
        return self::$processing_stats;
    }

    /**
     * 获取列表包装器类型
     *
     * @param string $block_type 块类型
     * @return string 包装器类型
     */
    private static function get_list_wrapper_type(string $block_type): string {
        switch ($block_type) {
            case 'to_do':
                return 'todo';
            case 'bulleted_list_item':
                return 'ul';
            case 'numbered_list_item':
                return 'ol';
            default:
                return 'ul';
        }
    }

    /**
     * 打开列表包装器
     *
     * @param string $wrapper_type 包装器类型
     * @return string HTML标签
     */
    private static function open_list_wrapper(string $wrapper_type): string {
        switch ($wrapper_type) {
            case 'todo':
                return '<ul class="notion-todo-list">';
            case 'ul':
                return '<ul>';
            case 'ol':
                return '<ol>';
            default:
                return '<ul>';
        }
    }

    /**
     * 关闭列表包装器
     *
     * @param string $wrapper_type 包装器类型
     * @return string HTML标签
     */
    private static function close_list_wrapper(string $wrapper_type): string {
        switch ($wrapper_type) {
            case 'todo':
            case 'ul':
                return '</ul>';
            case 'ol':
                return '</ol>';
            default:
                return '</ul>';
        }
    }

    /**
     * 轻量级单块转换
     *
     * @param array $block Notion块
     * @param Notion_API $notion_api API实例
     * @return string HTML内容
     */
    private static function convert_single_block_lightweight(array $block, Notion_API $notion_api): string {
        $block_type = $block['type'] ?? 'unknown';

        try {
            switch ($block_type) {
                case 'paragraph':
                    return self::convert_paragraph_lightweight($block);

                case 'heading_1':
                    return self::convert_heading_lightweight($block, 1);

                case 'heading_2':
                    return self::convert_heading_lightweight($block, 2);

                case 'heading_3':
                    return self::convert_heading_lightweight($block, 3);

                case 'bulleted_list_item':
                    return self::convert_list_item_lightweight($block, 'bulleted');

                case 'numbered_list_item':
                    return self::convert_list_item_lightweight($block, 'numbered');

                case 'to_do':
                    return self::convert_todo_lightweight($block);

                case 'quote':
                    return self::convert_quote_lightweight($block);

                case 'code':
                    return self::convert_code_lightweight($block);

                case 'divider':
                    return '<hr class="notion-divider">';

                default:
                    // 对于复杂块类型，回退到原始转换器
                    if (class_exists('Notion_Content_Converter')) {
                        return Notion_Content_Converter::convert_single_block_optimized($block, $notion_api, []);
                    }
                    return '';
            }
        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('轻量级块转换失败: %s - %s', $block_type, $e->getMessage()),
                    'Stream Processor'
                );
            }
            return '';
        }
    }

    /**
     * 轻量级段落转换
     *
     * @param array $block 段落块
     * @return string HTML内容
     */
    private static function convert_paragraph_lightweight(array $block): string {
        $text = self::extract_rich_text_simple($block['paragraph']['rich_text'] ?? []);
        return empty($text) ? '<p>&nbsp;</p>' : '<p>' . $text . '</p>';
    }

    /**
     * 轻量级标题转换
     *
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
     * 轻量级列表项转换
     *
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
     * 轻量级待办事项转换
     *
     * @param array $block 待办事项块
     * @return string HTML内容
     */
    private static function convert_todo_lightweight(array $block): string {
        $text = self::extract_rich_text_simple($block['to_do']['rich_text'] ?? []);
        $checked = ($block['to_do']['checked'] ?? false) ? ' checked' : '';

        return '<li class="notion-to-do">' .
               '<input type="checkbox"' . $checked . ' disabled>' .
               '<span class="notion-to-do-text">' . $text . '</span>' .
               '</li>';
    }

    /**
     * 轻量级引用转换
     *
     * @param array $block 引用块
     * @return string HTML内容
     */
    private static function convert_quote_lightweight(array $block): string {
        $text = self::extract_rich_text_simple($block['quote']['rich_text'] ?? []);
        return '<blockquote>' . $text . '</blockquote>';
    }

    /**
     * 轻量级代码块转换
     *
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
     * 简化的富文本提取
     *
     * @param array $rich_text 富文本数组
     * @return string 纯文本
     */
    private static function extract_rich_text_simple(array $rich_text): string {
        if (empty($rich_text)) {
            return '';
        }

        $text_parts = [];

        foreach ($rich_text as $text_obj) {
            $plain_text = $text_obj['plain_text'] ?? '';

            if (empty($plain_text)) {
                continue;
            }

            // 简化的格式处理
            $annotations = $text_obj['annotations'] ?? [];

            if ($annotations['bold'] ?? false) {
                $plain_text = '<strong>' . $plain_text . '</strong>';
            }

            if ($annotations['italic'] ?? false) {
                $plain_text = '<em>' . $plain_text . '</em>';
            }

            if ($annotations['code'] ?? false) {
                $plain_text = '<code>' . esc_html($plain_text) . '</code>';
            }

            $text_parts[] = $plain_text;
        }

        return implode('', $text_parts);
    }

    /**
     * 获取内存使用报告
     *
     * @return array 内存使用报告
     */
    public static function get_memory_report(): array {
        $memory_status = self::check_memory_status();
        $stats = self::get_processing_stats();

        return [
            'current_usage' => $memory_status,
            'processing_stats' => $stats,
            'recommendations' => self::get_memory_recommendations($memory_status)
        ];
    }

    /**
     * 获取内存优化建议
     *
     * @param array $memory_status 内存状态
     * @return array 优化建议
     */
    private static function get_memory_recommendations(array $memory_status): array {
        $recommendations = [];

        if ($memory_status['usage_percentage'] > 85) {
            $recommendations[] = '内存使用率过高，建议减少分块大小';
            $recommendations[] = '考虑增加PHP内存限制';
        }

        if ($memory_status['current_mb'] > 200) {
            $recommendations[] = '当前内存使用较高，建议启用更频繁的垃圾回收';
        }

        if (self::get_memory_limit_mb() < self::MEMORY_LIMIT_THRESHOLD) {
            $recommendations[] = sprintf('建议将PHP内存限制增加到至少%dMB', self::MEMORY_LIMIT_THRESHOLD);
        }

        return $recommendations;
    }
}
