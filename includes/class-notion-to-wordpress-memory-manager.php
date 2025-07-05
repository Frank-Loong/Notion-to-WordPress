<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 内存管理器
 *
 * 负责监控和优化插件的内存使用
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */

class Notion_To_WordPress_Memory_Manager {

    /**
     * 内存使用阈值（字节）
     *
     * @since 1.1.0
     * @var int
     */
    private static int $memory_threshold = 100 * 1024 * 1024; // 100MB

    /**
     * 批处理大小
     *
     * @since 1.1.0
     * @var int
     */
    private static int $batch_size = 50;

    /**
     * 内存监控数据
     *
     * @since 1.1.0
     * @var array<string, mixed>
     */
    private static array $memory_stats = [
        'peak_usage' => 0,
        'current_usage' => 0,
        'gc_runs' => 0,
        'cache_clears' => 0
    ];

    /**
     * 检查内存使用情况
     *
     * @since 1.1.0
     * @return array<string, mixed> 内存使用信息
     */
    public static function check_memory_usage(): array {
        $current_usage = memory_get_usage(true);
        $peak_usage = memory_get_peak_usage(true);
        $memory_limit = self::get_memory_limit();

        self::$memory_stats['current_usage'] = $current_usage;
        self::$memory_stats['peak_usage'] = max(self::$memory_stats['peak_usage'], $peak_usage);

        return [
            'current_usage' => $current_usage,
            'peak_usage' => $peak_usage,
            'memory_limit' => $memory_limit,
            'usage_percentage' => $memory_limit > 0 ? ($current_usage / $memory_limit) * 100 : 0,
            'available_memory' => $memory_limit > 0 ? $memory_limit - $current_usage : 0,
            'is_critical' => $current_usage > self::$memory_threshold
        ];
    }

    /**
     * 执行内存清理
     *
     * @since 1.1.0
     * @param bool $force_gc 是否强制执行垃圾回收
     * @return bool 是否执行了清理
     */
    public static function cleanup_memory(bool $force_gc = false): bool {
        $memory_info = self::check_memory_usage();
        $cleanup_performed = false;

        // 如果内存使用超过阈值或强制清理
        if ($memory_info['is_critical'] || $force_gc) {
            // 清理WordPress对象缓存
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
                $cleanup_performed = true;
            }

            // 清理插件特定的缓存
            self::clear_plugin_caches();
            $cleanup_performed = true;

            // 执行垃圾回收
            if (function_exists('gc_collect_cycles')) {
                $collected = gc_collect_cycles();
                self::$memory_stats['gc_runs']++;
                
                Notion_To_WordPress_Error_Handler::log_debug(
                    "垃圾回收完成，回收了 {$collected} 个对象",
                    Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
                    ['collected_objects' => $collected]
                );
                $cleanup_performed = true;
            }

            if ($cleanup_performed) {
                self::$memory_stats['cache_clears']++;
                
                $new_usage = memory_get_usage(true);
                $freed_memory = $memory_info['current_usage'] - $new_usage;
                
                Notion_To_WordPress_Error_Handler::log_info(
                    "内存清理完成，释放了 " . size_format($freed_memory),
                    Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
                    [
                        'before_cleanup' => $memory_info['current_usage'],
                        'after_cleanup' => $new_usage,
                        'freed_memory' => $freed_memory
                    ]
                );
            }
        }

        return $cleanup_performed;
    }

    /**
     * 批量处理数据
     *
     * @since 1.1.0
     * @param array    $data     要处理的数据
     * @param callable $callback 处理回调函数
     * @param int      $batch_size 批处理大小
     * @return array 处理结果
     */
    public static function process_in_batches(array $data, callable $callback, int $batch_size = 0): array {
        if ($batch_size <= 0) {
            $batch_size = self::$batch_size;
        }

        $results = [];
        $total_items = count($data);
        $processed = 0;

        Notion_To_WordPress_Error_Handler::log_info(
            "开始批量处理 {$total_items} 个项目，批大小: {$batch_size}",
            Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR
        );

        for ($i = 0; $i < $total_items; $i += $batch_size) {
            $batch = array_slice($data, $i, $batch_size);
            
            // 处理当前批次
            foreach ($batch as $item) {
                try {
                    $result = call_user_func($callback, $item);
                    $results[] = $result;
                    $processed++;
                } catch (Exception $e) {
                    Notion_To_WordPress_Error_Handler::exception_to_wp_error(
                        $e,
                        Notion_To_WordPress_Error_Handler::CODE_IMPORT_ERROR,
                        ['item' => $item, 'batch_index' => $i]
                    );
                }
            }

            // 批次处理完成后检查内存
            $memory_info = self::check_memory_usage();
            
            if ($memory_info['is_critical']) {
                self::cleanup_memory(true);
            }

            // 记录进度
            $progress = ($processed / $total_items) * 100;
            Notion_To_WordPress_Error_Handler::log_debug(
                sprintf("批量处理进度: %d/%d (%.1f%%)", $processed, $total_items, $progress),
                Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
                [
                    'processed' => $processed,
                    'total' => $total_items,
                    'memory_usage' => $memory_info['current_usage']
                ]
            );

            // 短暂休息，让系统有时间处理其他任务
            if ($i + $batch_size < $total_items) {
                $sleep_ms = apply_filters('ntw_batch_sleep_ms', 10);
                usleep($sleep_ms * 1000); // 转换为微秒
            }
        }

        Notion_To_WordPress_Error_Handler::log_info(
            "批量处理完成，共处理 {$processed} 个项目",
            Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
            ['final_memory_usage' => memory_get_usage(true)]
        );

        return $results;
    }

    /**
     * 获取内存统计信息
     *
     * @since 1.1.0
     * @return array<string, mixed> 内存统计信息
     */
    public static function get_memory_stats(): array {
        $current_info = self::check_memory_usage();
        
        return array_merge(self::$memory_stats, [
            'current_info' => $current_info,
            'memory_limit_formatted' => size_format($current_info['memory_limit']),
            'current_usage_formatted' => size_format($current_info['current_usage']),
            'peak_usage_formatted' => size_format(self::$memory_stats['peak_usage'])
        ]);
    }

    /**
     * 设置内存阈值
     *
     * @since 1.1.0
     * @param int $threshold 内存阈值（字节）
     */
    public static function set_memory_threshold(int $threshold): void {
        self::$memory_threshold = max(50 * 1024 * 1024, $threshold); // 最小50MB
    }

    /**
     * 设置批处理大小
     *
     * @since 1.1.0
     * @param int $size 批处理大小
     */
    public static function set_batch_size(int $size): void {
        self::$batch_size = max(1, min(1000, $size)); // 限制在1-1000之间
    }

    /**
     * 监控函数执行的内存使用
     *
     * @since 1.1.0
     * @param callable $callback 要监控的函数
     * @param array    $args     函数参数
     * @return array 包含结果和内存使用信息
     */
    public static function monitor_execution(callable $callback, array $args = []): array {
        $start_memory = memory_get_usage(true);
        $start_time = microtime(true);

        try {
            $result = call_user_func_array($callback, $args);
            $success = true;
            $error = null;
        } catch (Exception $e) {
            $result = null;
            $success = false;
            $error = $e->getMessage();
        }

        $end_memory = memory_get_usage(true);
        $end_time = microtime(true);

        $memory_used = $end_memory - $start_memory;
        $execution_time = $end_time - $start_time;

        return [
            'result' => $result,
            'success' => $success,
            'error' => $error,
            'memory_used' => $memory_used,
            'memory_used_formatted' => size_format(abs($memory_used)),
            'execution_time' => $execution_time,
            'start_memory' => $start_memory,
            'end_memory' => $end_memory
        ];
    }

    /**
     * 获取PHP内存限制
     *
     * @since 1.1.0
     * @return int 内存限制（字节）
     */
    private static function get_memory_limit(): int {
        $memory_limit = ini_get('memory_limit');
        
        if ($memory_limit === '-1') {
            return 0; // 无限制
        }

        // 转换为字节
        $value = (int) $memory_limit;
        $unit = strtolower(substr($memory_limit, -1));

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * 清理插件特定的缓存
     *
     * @since 1.1.0
     */
    private static function clear_plugin_caches(): void {
        // 清理Notion页面缓存
        if (class_exists('Notion_Pages')) {
            Notion_Pages::clear_static_cache();
        }

        // 清理Helper缓存
        if (class_exists('Notion_To_WordPress_Helper')) {
            Notion_To_WordPress_Helper::cache_delete('ntw_imported_posts_count');
            Notion_To_WordPress_Helper::cache_delete('ntw_published_posts_count');
        }

        // 清理transient缓存
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ntw_%' OR option_name LIKE '_transient_timeout_ntw_%'"
        );
    }

    /**
     * 重置内存统计
     *
     * @since 1.1.0
     */
    public static function reset_stats(): void {
        self::$memory_stats = [
            'peak_usage' => 0,
            'current_usage' => 0,
            'gc_runs' => 0,
            'cache_clears' => 0
        ];
    }

    /**
     * 获取性能建议
     *
     * @since 1.1.0
     * @return array 性能建议数组
     */
    public static function get_performance_recommendations(): array {
        $memory_info = self::check_memory_usage();
        $recommendations = [];

        // 内存使用建议
        if ($memory_info['usage_percentage'] > 80) {
            $recommendations[] = [
                'type' => 'memory',
                'level' => 'critical',
                'message' => '内存使用率超过80%，建议增加PHP内存限制或优化批处理大小'
            ];
        } elseif ($memory_info['usage_percentage'] > 60) {
            $recommendations[] = [
                'type' => 'memory',
                'level' => 'warning',
                'message' => '内存使用率较高，建议监控内存使用情况'
            ];
        }

        // 垃圾回收建议
        if (self::$memory_stats['gc_runs'] > 10) {
            $recommendations[] = [
                'type' => 'gc',
                'level' => 'info',
                'message' => '垃圾回收频繁，可能存在内存泄漏或批处理大小过大'
            ];
        }

        // 缓存清理建议
        if (self::$memory_stats['cache_clears'] > 5) {
            $recommendations[] = [
                'type' => 'cache',
                'level' => 'info',
                'message' => '缓存清理频繁，建议检查缓存策略或增加内存限制'
            ];
        }

        return $recommendations;
    }
}
