<?php
declare(strict_types=1);

/**
 * Notion 统一并发管理器类
 * 
 * 统一并发管理策略，移除复杂的交叉逻辑
 * 使用配置文件控制并发参数，简化系统负载检查
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

class Notion_Unified_Concurrency_Manager {
    
    /**
     * 默认并发配置
     */
    const DEFAULT_CONFIG = [
        'max_concurrent_requests' => 5,
        'max_concurrent_downloads' => 3,
        'max_concurrent_uploads' => 2,
        'system_load_threshold' => 2.0,
        'memory_threshold' => 0.8,
        'enable_adaptive_concurrency' => true,
        'min_concurrency' => 1,
        'max_concurrency' => 10
    ];
    
    /**
     * 当前并发配置
     * @var array
     */
    private static $config = null;
    
    /**
     * 并发统计
     * @var array
     */
    private static $stats = [
        'current_requests' => 0,
        'current_downloads' => 0,
        'current_uploads' => 0,
        'total_requests' => 0,
        'adaptive_adjustments' => 0
    ];
    
    /**
     * 获取并发配置
     *
     * @since 2.0.0-beta.1
     * @return array 并发配置
     */
    public static function get_config(): array {
        if (self::$config === null) {
            $options = get_option('notion_to_wordpress_options', []);
            
            self::$config = array_merge(self::DEFAULT_CONFIG, [
                'max_concurrent_requests' => $options['concurrent_requests'] ?? self::DEFAULT_CONFIG['max_concurrent_requests'],
                'max_concurrent_downloads' => $options['concurrent_downloads'] ?? self::DEFAULT_CONFIG['max_concurrent_downloads'],
                'max_concurrent_uploads' => $options['concurrent_uploads'] ?? self::DEFAULT_CONFIG['max_concurrent_uploads'],
                'enable_adaptive_concurrency' => $options['enable_adaptive_concurrency'] ?? self::DEFAULT_CONFIG['enable_adaptive_concurrency']
            ]);
        }
        
        return self::$config;
    }
    
    /**
     * 检查是否可以启动新的并发任务
     *
     * @since 2.0.0-beta.1
     * @param string $type 任务类型 (request|download|upload)
     * @return bool 是否可以启动
     */
    public static function can_start_task(string $type): bool {
        $config = self::get_config();
        
        // 检查系统资源
        if (!self::is_system_healthy()) {
            return false;
        }
        
        // 检查具体类型的并发限制
        switch ($type) {
            case 'request':
                return self::$stats['current_requests'] < $config['max_concurrent_requests'];
            case 'download':
                return self::$stats['current_downloads'] < $config['max_concurrent_downloads'];
            case 'upload':
                return self::$stats['current_uploads'] < $config['max_concurrent_uploads'];
            default:
                return false;
        }
    }
    
    /**
     * 开始任务
     *
     * @since 2.0.0-beta.1
     * @param string $type 任务类型
     * @return bool 是否成功开始
     */
    public static function start_task(string $type): bool {
        if (!self::can_start_task($type)) {
            return false;
        }
        
        switch ($type) {
            case 'request':
                self::$stats['current_requests']++;
                break;
            case 'download':
                self::$stats['current_downloads']++;
                break;
            case 'upload':
                self::$stats['current_uploads']++;
                break;
        }
        
        self::$stats['total_requests']++;
        
        return true;
    }
    
    /**
     * 结束任务
     *
     * @since 2.0.0-beta.1
     * @param string $type 任务类型
     */
    public static function end_task(string $type): void {
        switch ($type) {
            case 'request':
                self::$stats['current_requests'] = max(0, self::$stats['current_requests'] - 1);
                break;
            case 'download':
                self::$stats['current_downloads'] = max(0, self::$stats['current_downloads'] - 1);
                break;
            case 'upload':
                self::$stats['current_uploads'] = max(0, self::$stats['current_uploads'] - 1);
                break;
        }
    }
    
    /**
     * 检查系统健康状态
     *
     * @since 2.0.0-beta.1
     * @return bool 系统是否健康
     */
    public static function is_system_healthy(): bool {
        $config = self::get_config();
        
        // 检查系统负载（简化版）
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load[0] > $config['system_load_threshold']) {
                return false;
            }
        }
        
        // 检查内存使用
        $memory_usage = memory_get_usage(true);
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_percentage = $memory_usage / $memory_limit;
        
        if ($memory_percentage > $config['memory_threshold']) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取最优并发数
     *
     * @since 2.0.0-beta.1
     * @param string $type 任务类型
     * @param int $data_size 数据大小（可选）
     * @return int 最优并发数
     */
    public static function get_optimal_concurrency(string $type, int $data_size = 0): int {
        $config = self::get_config();
        
        // 基础并发数
        switch($type) {
            case 'request':
                $base_concurrency = $config['max_concurrent_requests'];
                break;
            case 'download':
                $base_concurrency = $config['max_concurrent_downloads'];
                break;
            case 'upload':
                $base_concurrency = $config['max_concurrent_uploads'];
                break;
            default:
                $base_concurrency = 1;
                break;
        }
        
        // 如果未启用自适应并发，直接返回基础值
        if (!$config['enable_adaptive_concurrency']) {
            return $base_concurrency;
        }
        
        // 自适应调整
        $optimal = $base_concurrency;
        
        // 根据系统负载调整
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load[0] > 1.5) {
                $optimal = max($config['min_concurrency'], intval($optimal * 0.7));
            } elseif ($load[0] < 0.5) {
                $optimal = min($config['max_concurrency'], intval($optimal * 1.3));
            }
        }
        
        // 根据内存使用调整
        $memory_usage = memory_get_usage(true);
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_percentage = $memory_usage / $memory_limit;
        
        if ($memory_percentage > 0.7) {
            $optimal = max($config['min_concurrency'], intval($optimal * 0.8));
        }
        
        // 根据数据大小调整
        if ($data_size > 0) {
            if ($data_size > 1000) { // 大数据集
                $optimal = min($optimal, 3);
            } elseif ($data_size < 100) { // 小数据集
                $optimal = min($config['max_concurrency'], $optimal + 2);
            }
        }
        
        // 确保在合理范围内
        $optimal = max($config['min_concurrency'], min($config['max_concurrency'], $optimal));
        
        // 记录自适应调整
        if ($optimal !== $base_concurrency) {
            self::$stats['adaptive_adjustments']++;
        }
        
        return $optimal;
    }
    
    /**
     * 获取并发统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public static function get_stats(): array {
        $config = self::get_config();
        
        return array_merge(self::$stats, [
            'config' => $config,
            'system_healthy' => self::is_system_healthy(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'system_load' => function_exists('sys_getloadavg') ? sys_getloadavg()[0] : 'N/A'
        ]);
    }
    
    /**
     * 重置统计信息
     *
     * @since 2.0.0-beta.1
     */
    public static function reset_stats(): void {
        self::$stats = [
            'current_requests' => 0,
            'current_downloads' => 0,
            'current_uploads' => 0,
            'total_requests' => 0,
            'adaptive_adjustments' => 0
        ];
    }
    
    /**
     * 等待可用槽位
     *
     * @since 2.0.0-beta.1
     * @param string $type 任务类型
     * @param int $max_wait_ms 最大等待时间（毫秒）
     * @return bool 是否获得槽位
     */
    public static function wait_for_slot(string $type, int $max_wait_ms = 5000): bool {
        $start_time = microtime(true);
        $wait_interval = 50; // 50ms检查间隔
        
        while ((microtime(true) - $start_time) * 1000 < $max_wait_ms) {
            if (self::can_start_task($type)) {
                return self::start_task($type);
            }
            
            usleep($wait_interval * 1000); // 转换为微秒
        }
        
        return false;
    }
    
    /**
     * 批量任务管理
     *
     * @since 2.0.0-beta.1
     * @param string $type 任务类型
     * @param int $task_count 任务数量
     * @return array 批量管理信息
     */
    public static function manage_batch_tasks(string $type, int $task_count): array {
        $optimal_concurrency = self::get_optimal_concurrency($type, $task_count);
        $batch_size = min($optimal_concurrency, $task_count);
        $batches = ceil($task_count / $batch_size);
        
        return [
            'optimal_concurrency' => $optimal_concurrency,
            'batch_size' => $batch_size,
            'total_batches' => $batches,
            'estimated_time' => $batches * 2, // 估算时间（秒）
            'recommendation' => $batches > 10 ? 'consider_splitting' : 'proceed'
        ];
    }
}
