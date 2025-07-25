<?php
declare(strict_types=1);

/**
 * Notion 性能监控器类
 * 
 * 专门处理插件的性能监控功能，包括同步速度统计、资源使用监控、
 * 性能指标收集等。帮助用户了解优化效果和系统性能状况。
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

class Notion_Performance_Monitor {
    
    /**
     * 性能统计数据
     *
     * @access private
     * @var array
     */
    private static array $stats = [];
    
    /**
     * 计时器
     *
     * @access private
     * @var array
     */
    private static array $timers = [];
    
    /**
     * 内存使用记录
     *
     * @access private
     * @var array
     */
    private static array $memory_usage = [];
    
    /**
     * 开始计时
     *
     * @since 2.0.0-beta.1
     * @param string $name 计时器名称
     */
    public static function start_timer(string $name): void {
        self::$timers[$name] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true)
        ];
    }
    
    /**
     * 结束计时
     *
     * @since 2.0.0-beta.1
     * @param string $name 计时器名称
     * @return float 执行时间（秒）
     */
    public static function end_timer(string $name): float {
        if (!isset(self::$timers[$name])) {
            return 0.0;
        }
        
        $end_time = microtime(true);
        $end_memory = memory_get_usage(true);
        
        $duration = $end_time - self::$timers[$name]['start'];
        $memory_used = $end_memory - self::$timers[$name]['memory_start'];
        
        // 记录统计数据
        if (!isset(self::$stats[$name])) {
            self::$stats[$name] = [
                'count' => 0,
                'total_time' => 0,
                'min_time' => PHP_FLOAT_MAX,
                'max_time' => 0,
                'total_memory' => 0,
                'min_memory' => PHP_INT_MAX,
                'max_memory' => 0
            ];
        }
        
        self::$stats[$name]['count']++;
        self::$stats[$name]['total_time'] += $duration;
        self::$stats[$name]['min_time'] = min(self::$stats[$name]['min_time'], $duration);
        self::$stats[$name]['max_time'] = max(self::$stats[$name]['max_time'], $duration);
        self::$stats[$name]['total_memory'] += $memory_used;
        self::$stats[$name]['min_memory'] = min(self::$stats[$name]['min_memory'], $memory_used);
        self::$stats[$name]['max_memory'] = max(self::$stats[$name]['max_memory'], $memory_used);
        
        unset(self::$timers[$name]);
        
        return $duration;
    }
    
    /**
     * 记录API调用统计
     *
     * @since 2.0.0-beta.1
     * @param string $endpoint API端点
     * @param float $duration 执行时间
     * @param bool $success 是否成功
     */
    public static function record_api_call(string $endpoint, float $duration, bool $success): void {
        $key = 'api_' . $endpoint;
        
        if (!isset(self::$stats[$key])) {
            self::$stats[$key] = [
                'count' => 0,
                'success_count' => 0,
                'total_time' => 0,
                'min_time' => PHP_FLOAT_MAX,
                'max_time' => 0
            ];
        }
        
        self::$stats[$key]['count']++;
        if ($success) {
            self::$stats[$key]['success_count']++;
        }
        self::$stats[$key]['total_time'] += $duration;
        self::$stats[$key]['min_time'] = min(self::$stats[$key]['min_time'], $duration);
        self::$stats[$key]['max_time'] = max(self::$stats[$key]['max_time'], $duration);
    }
    
    /**
     * 记录数据库操作统计
     *
     * @since 2.0.0-beta.1
     * @param string $operation 操作类型
     * @param int $affected_rows 影响的行数
     * @param float $duration 执行时间
     */
    public static function record_db_operation(string $operation, int $affected_rows, float $duration): void {
        $key = 'db_' . $operation;
        
        if (!isset(self::$stats[$key])) {
            self::$stats[$key] = [
                'count' => 0,
                'total_rows' => 0,
                'total_time' => 0,
                'min_time' => PHP_FLOAT_MAX,
                'max_time' => 0
            ];
        }
        
        self::$stats[$key]['count']++;
        self::$stats[$key]['total_rows'] += $affected_rows;
        self::$stats[$key]['total_time'] += $duration;
        self::$stats[$key]['min_time'] = min(self::$stats[$key]['min_time'], $duration);
        self::$stats[$key]['max_time'] = max(self::$stats[$key]['max_time'], $duration);
    }
    
    /**
     * 获取性能统计报告
     *
     * @since 2.0.0-beta.1
     * @return array 性能统计数据
     */
    public static function get_performance_report(): array {
        $report = [
            'summary' => [
                'total_operations' => 0,
                'total_time' => 0,
                'peak_memory' => memory_get_peak_usage(true),
                'current_memory' => memory_get_usage(true)
            ],
            'timers' => [],
            'api_calls' => [],
            'db_operations' => []
        ];
        
        foreach (self::$stats as $name => $data) {
            $report['summary']['total_operations'] += $data['count'];
            $report['summary']['total_time'] += $data['total_time'];
            
            $avg_time = $data['count'] > 0 ? $data['total_time'] / $data['count'] : 0;
            
            $formatted_data = [
                'name' => $name,
                'count' => $data['count'],
                'total_time' => round($data['total_time'], 4),
                'avg_time' => round($avg_time, 4),
                'min_time' => $data['min_time'] === PHP_FLOAT_MAX ? 0 : round($data['min_time'], 4),
                'max_time' => round($data['max_time'], 4)
            ];
            
            if (strpos($name, 'api_') === 0) {
                $formatted_data['success_rate'] = $data['count'] > 0 
                    ? round(($data['success_count'] / $data['count']) * 100, 2) 
                    : 0;
                $report['api_calls'][] = $formatted_data;
            } elseif (strpos($name, 'db_') === 0) {
                $formatted_data['total_rows'] = $data['total_rows'];
                $formatted_data['avg_rows'] = $data['count'] > 0 
                    ? round($data['total_rows'] / $data['count'], 2) 
                    : 0;
                $report['db_operations'][] = $formatted_data;
            } else {
                if (isset($data['total_memory'])) {
                    $formatted_data['total_memory'] = $data['total_memory'];
                    $formatted_data['avg_memory'] = $data['count'] > 0 
                        ? round($data['total_memory'] / $data['count']) 
                        : 0;
                    $formatted_data['min_memory'] = $data['min_memory'] === PHP_INT_MAX ? 0 : $data['min_memory'];
                    $formatted_data['max_memory'] = $data['max_memory'];
                }
                $report['timers'][] = $formatted_data;
            }
        }
        
        return $report;
    }
    
    /**
     * 重置性能统计
     *
     * @since 2.0.0-beta.1
     */
    public static function reset_stats(): void {
        self::$stats = [];
        self::$timers = [];
        self::$memory_usage = [];
    }
    
    /**
     * 获取当前配置的性能参数
     *
     * @since 2.0.0-beta.1
     * @return array 性能配置参数
     */
    public static function get_performance_config(): array {
        $options = get_option('notion_to_wordpress_options', []);
        
        return [
            'api_page_size' => $options['api_page_size'] ?? 100,
            'concurrent_requests' => $options['concurrent_requests'] ?? 5,
            'batch_size' => $options['batch_size'] ?? 20,
            'log_buffer_size' => $options['log_buffer_size'] ?? 50,
            'enable_performance_mode' => $options['enable_performance_mode'] ?? 1,
            'log_buffer_status' => class_exists('Notion_Logger') 
                ? Notion_Logger::get_buffer_status() 
                : ['buffer_size' => 0, 'current_count' => 0, 'usage_percentage' => 0]
        ];
    }
    
    /**
     * 格式化字节数为可读格式
     *
     * @since 2.0.0-beta.1
     * @param int $bytes 字节数
     * @return string 格式化后的字符串
     */
    public static function format_bytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}
