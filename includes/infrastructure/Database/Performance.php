<?php
declare(strict_types=1);

namespace NTWP\Infrastructure\Database;

use NTWP\Core\Foundation\Logger;

/**
 * 数据库性能监控器
 *
 * 监控数据库查询性能，提供优化建议
 * 提取自Database_Helper的性能监控功能
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
class Performance {
    
    private static array $query_log = [];
    private static float $total_query_time = 0.0;
    
    /**
     * 开始监控查询
     *
     * @param string $query_id 查询标识
     * @return float 开始时间戳
     */
    public static function start_query_timer(string $query_id): float {
        $start_time = microtime(true);
        
        self::$query_log[$query_id] = [
            'start_time' => $start_time,
            'end_time' => null,
            'duration' => null,
            'status' => 'running'
        ];
        
        return $start_time;
    }
    
    /**
     * 结束查询监控
     *
     * @param string $query_id 查询标识
     * @param bool $success 查询是否成功
     * @return float 查询耗时
     */
    public static function end_query_timer(string $query_id, bool $success = true): float {
        $end_time = microtime(true);
        
        if (!isset(self::$query_log[$query_id])) {
            return 0.0;
        }
        
        $duration = $end_time - self::$query_log[$query_id]['start_time'];
        
        self::$query_log[$query_id]['end_time'] = $end_time;
        self::$query_log[$query_id]['duration'] = $duration;
        self::$query_log[$query_id]['status'] = $success ? 'completed' : 'failed';
        
        self::$total_query_time += $duration;
        
        // 记录慢查询
        if ($duration > 1.0) {
            Logger::debug_log(
                sprintf('慢查询检测: %s 耗时 %.2fs', $query_id, $duration),
                'Database Performance'
            );
        }
        
        return $duration;
    }
    
    /**
     * 获取性能统计
     *
     * @return array 性能数据
     */
    public static function get_performance_stats(): array {
        $total_queries = count(self::$query_log);
        $completed_queries = count(array_filter(self::$query_log, function($log) {
            return $log['status'] === 'completed';
        }));
        
        $slow_queries = array_filter(self::$query_log, function($log) {
            return $log['duration'] && $log['duration'] > 1.0;
        });
        
        $avg_query_time = $total_queries > 0 ? self::$total_query_time / $total_queries : 0;
        
        return [
            'total_queries' => $total_queries,
            'completed_queries' => $completed_queries,
            'failed_queries' => $total_queries - $completed_queries,
            'slow_queries' => count($slow_queries),
            'total_time' => self::$total_query_time,
            'avg_query_time' => $avg_query_time,
            'success_rate' => $total_queries > 0 ? ($completed_queries / $total_queries) * 100 : 0
        ];
    }
    
    /**
     * 监控内存使用情况
     *
     * @return array 内存使用数据
     */
    public static function get_memory_stats(): array {
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        $memory_limit = ini_get('memory_limit');
        
        // 转换内存限制为字节
        $memory_limit_bytes = self::convert_to_bytes($memory_limit);
        
        return [
            'current_usage' => $memory_usage,
            'current_usage_mb' => round($memory_usage / 1024 / 1024, 2),
            'peak_usage' => $memory_peak,
            'peak_usage_mb' => round($memory_peak / 1024 / 1024, 2),
            'memory_limit' => $memory_limit,
            'memory_limit_bytes' => $memory_limit_bytes,
            'usage_percentage' => $memory_limit_bytes > 0 
                ? round(($memory_usage / $memory_limit_bytes) * 100, 2) 
                : 0
        ];
    }
    
    /**
     * 获取数据库连接状态
     *
     * @return array 连接状态信息
     */
    public static function get_database_status(): array {
        global $wpdb;
        
        $status = [];
        
        // 检查数据库连接
        $status['connected'] = $wpdb->db_connect() !== false;
        
        // 获取数据库版本
        $status['version'] = $wpdb->get_var("SELECT VERSION()");
        
        // 获取字符集
        $status['charset'] = $wpdb->charset;
        $status['collate'] = $wpdb->collate;
        
        // 检查表状态
        $tables_status = $wpdb->get_results(
            "SHOW TABLE STATUS LIKE '{$wpdb->prefix}%'"
        );
        
        $total_size = 0;
        $table_count = 0;
        
        foreach ($tables_status as $table) {
            $total_size += $table->Data_length + $table->Index_length;
            $table_count++;
        }
        
        $status['table_count'] = $table_count;
        $status['total_size_mb'] = round($total_size / 1024 / 1024, 2);
        
        return $status;
    }
    
    /**
     * 生成性能报告
     *
     * @return array 完整的性能报告
     */
    public static function generate_performance_report(): array {
        $report = [
            'timestamp' => current_time('mysql'),
            'query_performance' => self::get_performance_stats(),
            'memory_usage' => self::get_memory_stats(),
            'database_status' => self::get_database_status(),
            'recommendations' => []
        ];
        
        // 生成建议
        $recommendations = [];
        
        // 查询性能建议
        if ($report['query_performance']['slow_queries'] > 0) {
            $recommendations[] = sprintf(
                '检测到 %d 个慢查询，建议优化索引',
                $report['query_performance']['slow_queries']
            );
        }
        
        if ($report['query_performance']['success_rate'] < 95) {
            $recommendations[] = sprintf(
                '查询成功率 %.1f%% 偏低，建议检查数据库连接',
                $report['query_performance']['success_rate']
            );
        }
        
        // 内存使用建议
        if ($report['memory_usage']['usage_percentage'] > 80) {
            $recommendations[] = sprintf(
                '内存使用率 %.1f%% 过高，建议优化内存管理',
                $report['memory_usage']['usage_percentage']
            );
        }
        
        $report['recommendations'] = $recommendations;
        
        Logger::debug_log(
            sprintf('性能报告生成: %d 条建议', count($recommendations)),
            'Database Performance'
        );
        
        return $report;
    }
    
    /**
     * 清理性能日志
     */
    public static function clear_performance_logs(): void {
        self::$query_log = [];
        self::$total_query_time = 0.0;
        
        Logger::debug_log('性能监控日志已清理', 'Database Performance');
    }
    
    /**
     * 转换内存限制字符串为字节数
     *
     * @param string $size 内存大小字符串 (如 "256M", "1G")
     * @return int 字节数
     */
    private static function convert_to_bytes(string $size): int {
        $size = trim($size);
        $last = strtolower($size[strlen($size) - 1]);
        $size = (int) $size;
        
        switch ($last) {
            case 'g':
                $size *= 1024;
            case 'm':
                $size *= 1024;
            case 'k':
                $size *= 1024;
        }
        
        return $size;
    }
}
