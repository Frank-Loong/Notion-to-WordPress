<?php
declare(strict_types=1);

namespace NTWP\Core;

/**
 * 内存监控器 - 负责内存使用监控和检测
 * 
 * 从Memory_Manager拆分出的专职类
 */
class MemoryMonitor {
    
    const MEMORY_WARNING_THRESHOLD = 0.8;  // 80%内存使用时警告
    const MEMORY_CRITICAL_THRESHOLD = 0.9; // 90%内存使用时强制清理
    
    /**
     * 获取内存使用情况
     *
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
     * 检查是否达到内存警告阈值
     */
    public static function is_memory_warning(): bool {
        $usage = self::get_memory_usage();
        return $usage['usage_percentage'] >= (self::MEMORY_WARNING_THRESHOLD * 100);
    }
    
    /**
     * 检查是否达到内存临界阈值
     */
    public static function is_memory_critical(): bool {
        $usage = self::get_memory_usage();
        return $usage['usage_percentage'] >= (self::MEMORY_CRITICAL_THRESHOLD * 100);
    }
    
    /**
     * 内存使用监控
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
        }
    }
    
    /**
     * 获取内存优化建议
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
}