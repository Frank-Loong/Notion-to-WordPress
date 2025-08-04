<?php
declare(strict_types=1);

namespace NTWP\Infrastructure\Concurrency;

use NTWP\Core\Foundation\Logger;

/**
 * 统一并发管理器
 *
 * 功能整合:
 * ✅ 网络请求并发 (from Concurrent_Network_Manager)
 * ✅ 配置管理 (from Unified_Concurrency_Manager)
 * ✅ 动态调优 (from Dynamic_Concurrency_Manager)
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
class ConcurrencyManager {

    /**
     * 默认并发配置 (兼容Unified_Concurrency_Manager)
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
     * 当前并发配置 (兼容Unified_Concurrency_Manager)
     * @var array
     */
    private static $static_config = null;

    private array $config = [
        'max_concurrent_requests' => 5,
        'request_timeout' => 30,
        'enable_adaptive_adjustment' => true,
        'memory_threshold' => 0.8,
        'cpu_threshold' => 2.0,
        'min_concurrency' => 1,
        'max_concurrency' => 10,
    ];
    
    private $multi_handle;
    private array $performance_metrics = [];
    
    /**
     * 数据量预估缓存
     * @var array
     */
    private array $size_estimation_cache = [];
    
    /**
     * 并发统计信息
     * @var array
     */
    private array $concurrency_stats = [
        'total_batches_processed' => 0,
        'adaptive_adjustments' => 0,
        'optimal_concurrency_history' => [],
        'performance_improvements' => 0,
    ];
    
    public function __construct() {
        $this->multi_handle = curl_multi_init();
        $this->load_config();
    }

    /**
     * 获取并发配置 (兼容Unified_Concurrency_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 并发配置
     */
    public static function get_config(): array {
        if (self::$static_config === null) {
            $options = get_option('notion_to_wordpress_options', []);

            self::$static_config = array_merge(self::DEFAULT_CONFIG, [
                'max_concurrent_requests' => $options['concurrent_requests'] ?? self::DEFAULT_CONFIG['max_concurrent_requests'],
                'max_concurrent_downloads' => $options['concurrent_downloads'] ?? self::DEFAULT_CONFIG['max_concurrent_downloads'],
                'max_concurrent_uploads' => $options['concurrent_uploads'] ?? self::DEFAULT_CONFIG['max_concurrent_uploads'],
                'enable_adaptive_concurrency' => $options['enable_adaptive_concurrency'] ?? self::DEFAULT_CONFIG['enable_adaptive_concurrency']
            ]);
        }

        return self::$static_config;
    }
    
    /**
     * 执行并发请求 - 核心功能
     * 
     * @param array $requests 请求配置数组
     * @return array 响应结果
     */
    public function execute_concurrent_requests(array $requests): array {
        if (empty($requests)) {
            return [];
        }
        
        $start_time = microtime(true);
        $optimal_concurrency = $this->get_optimal_concurrency();
        
        Logger::debug_log(
            sprintf('开始并发处理 %d 个请求，并发数: %d', 
                count($requests), $optimal_concurrency),
            'ConcurrencyManager'
        );
        
        $results = [];
        $batches = array_chunk($requests, $optimal_concurrency);
        
        foreach ($batches as $batch) {
            $batch_results = $this->process_batch($batch);
            $results = array_merge($results, $batch_results);
            
            // 性能自适应调整
            if ($this->config['enable_adaptive_adjustment']) {
                $this->adjust_concurrency_based_on_performance();
            }
        }
        
        $total_time = microtime(true) - $start_time;
        $this->record_performance_metrics($total_time, count($requests));
        
        return $results;
    }
    
    /**
     * 计算最优并发数 - 动态调优核心
     * 
     * @return int 最优并发数
     */
    public function get_optimal_concurrency(): int {
        $base_concurrency = $this->config['max_concurrent_requests'];
        
        if (!$this->config['enable_adaptive_adjustment']) {
            return $base_concurrency;
        }
        
        // 系统资源检查
        $system_load = sys_getloadavg()[0] ?? 1.0;
        $memory_usage = memory_get_usage(true) / $this->get_memory_limit();
        
        $adjustment_factor = 1.0;
        
        // CPU负载调整
        if ($system_load > $this->config['cpu_threshold']) {
            $adjustment_factor *= 0.7; // 减少30%
        }
        
        // 内存使用调整
        if ($memory_usage > $this->config['memory_threshold']) {
            $adjustment_factor *= 0.8; // 减少20%
        }
        
        // 历史性能调整
        if (!empty($this->performance_metrics)) {
            $avg_response_time = array_sum($this->performance_metrics) / count($this->performance_metrics);
            if ($avg_response_time > 3.0) {
                $adjustment_factor *= 0.9; // 响应慢则减少并发
            }
        }
        
        $optimal = max(1, intval($base_concurrency * $adjustment_factor));
        
        return min($this->config['max_concurrent_requests'], $optimal);
    }
    
    /**
     * 处理单个批次 - cURL multi-handle核心逻辑
     */
    private function process_batch(array $batch): array {
        $curl_handles = [];
        $results = [];
        
        // 初始化cURL句柄
        foreach ($batch as $index => $request) {
            $ch = curl_init();
            curl_setopt_array($ch, $this->prepare_curl_options($request));
            curl_multi_add_handle($this->multi_handle, $ch);
            $curl_handles[$index] = $ch;
        }
        
        // 执行并发请求
        $running = null;
        do {
            $status = curl_multi_exec($this->multi_handle, $running);
            if ($running > 0) {
                curl_multi_select($this->multi_handle, 0.1);
            }
        } while ($running > 0 && $status === CURLM_OK);
        
        // 收集结果
        foreach ($curl_handles as $index => $ch) {
            $response = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);
            
            $results[$index] = [
                'response' => $response,
                'http_code' => $info['http_code'],
                'response_time' => $info['total_time'],
                'error' => $error,
                'success' => empty($error) && $info['http_code'] < 400
            ];
            
            curl_multi_remove_handle($this->multi_handle, $ch);
            curl_close($ch);
        }
        
        return $results;
    }
    
    /**
     * 准备cURL选项
     */
    private function prepare_curl_options(array $request): array {
        $default_options = [
            CURLOPT_URL => $request['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['request_timeout'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Notion-to-WordPress/2.0',
            CURLOPT_HTTPHEADER => $request['headers'] ?? [],
        ];
        
        if (!empty($request['curl_options'])) {
            $default_options = array_replace($default_options, $request['curl_options']);
        }
        
        return $default_options;
    }
    
    /**
     * 连接池管理 (from Concurrent_Network_Manager)
     * @var array
     */
    private array $connection_pool = [];
    private array $connection_pool_stats = [
        'total_connections_created' => 0,
        'connections_reused' => 0,
        'connections_closed' => 0,
        'pool_hits' => 0,
        'pool_misses' => 0
    ];
    
    /**
     * 任务管理统计 (from Unified_Concurrency_Manager)
     * @var array
     */
    private static array $task_counters = [
        'requests' => 0,
        'downloads' => 0,
        'uploads' => 0
    ];

    /**
     * 统一并发统计 (兼容Unified_Concurrency_Manager)
     * @var array
     */
    private static array $unified_stats = [
        'current_requests' => 0,
        'current_downloads' => 0,
        'current_uploads' => 0,
        'total_requests' => 0,
        'adaptive_adjustments' => 0
    ];

    /**
     * 请求队列管理 (from Concurrent_Network_Manager)
     * @var array
     */
    private array $request_queue = [];
    private array $response_results = [];
    private int $next_request_id = 0;
    private array $curl_handles = [];
    private int $default_timeout = 30;
    
    /**
     * 配置管理
     */
    public function configure_limits(array $config): void {
        $validated_config = $this->validate_config($config);
        $this->config = array_merge($this->config, $validated_config);
        
        Logger::debug_log('并发配置已更新: ' . json_encode($validated_config), 'ConcurrencyManager');
    }
    
    /**
     * 性能监控 - 增强版
     */
    public function monitor_performance(): array {
        return $this->get_detailed_performance_stats();
    }
    
    // === 辅助方法 ===
    
    private function load_config(): void {
        $options = get_option('notion_to_wordpress_options', []);
        
        $this->config = array_merge($this->config, [
            'max_concurrent_requests' => $options['concurrent_requests'] ?? 5,
            'enable_adaptive_adjustment' => $options['enable_adaptive_concurrency'] ?? true,
        ]);
    }
    
    private function adjust_concurrency_based_on_performance(): void {
        if (count($this->performance_metrics) < 3) return; // 需要足够样本

        $recent_avg = array_sum(array_slice($this->performance_metrics, -3)) / 3;
        $original_concurrency = $this->config['max_concurrent_requests'];

        if ($recent_avg > 3.0) {
            // 性能下降，减少并发
            $this->config['max_concurrent_requests'] = max(1,
                intval($this->config['max_concurrent_requests'] * 0.8)
            );
        } elseif ($recent_avg < 1.0) {
            // 性能良好，适度增加
            $this->config['max_concurrent_requests'] = min(10,
                intval($this->config['max_concurrent_requests'] * 1.2)
            );
        }

        // 记录自适应调整 (兼容Unified_Concurrency_Manager)
        if ($original_concurrency !== $this->config['max_concurrent_requests']) {
            self::$unified_stats['adaptive_adjustments']++;
            $this->concurrency_stats['adaptive_adjustments']++;

            Logger::debug_log(
                sprintf('自适应调整并发数: %d → %d (平均响应时间: %.2fs)',
                    $original_concurrency, $this->config['max_concurrent_requests'], $recent_avg),
                'ConcurrencyManager'
            );
        }
    }
    
    private function record_performance_metrics(float $total_time, int $request_count): void {
        $avg_time = $total_time / $request_count;
        $this->performance_metrics[] = $avg_time;
        
        // 只保留最近50次记录
        if (count($this->performance_metrics) > 50) {
            array_shift($this->performance_metrics);
        }
    }
    
    private function validate_config(array $config): array {
        $validated = [];
        
        if (isset($config['max_concurrent_requests'])) {
            $validated['max_concurrent_requests'] = max(1, min(20, intval($config['max_concurrent_requests'])));
        }
        
        if (isset($config['request_timeout'])) {
            $validated['request_timeout'] = max(5, min(300, intval($config['request_timeout'])));
        }
        
        if (isset($config['memory_threshold'])) {
            $validated['memory_threshold'] = max(0.5, min(0.95, floatval($config['memory_threshold'])));
        }
        
        return $validated;
    }
    
    private function get_memory_limit(): int {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') return PHP_INT_MAX;
        
        $unit = strtoupper(substr($limit, -1));
        $value = intval($limit);
        
        return match($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024, 
            'K' => $value * 1024,
            default => $value
        };
    }
    
    
    /**
     * 适配性并发管理方法 - 根据数据量动态调整
     *
     * @param array $requests 请求配置数组
     * @param int $estimated_size 预估数据量
     * @return array 响应结果
     */
    public function execute_adaptive_concurrent_requests(array $requests, int $estimated_size = 0): array {
        if (empty($requests)) {
            return [];
        }
        
        $start_time = microtime(true);
        
        // 智能并发数计算
        $optimal_concurrency = $this->calculate_optimal_concurrency_by_data_size(
            $estimated_size > 0 ? $estimated_size : count($requests)
        );
        
        Logger::debug_log(
            sprintf('适配性并发处理: %d 个请求，预估大小: %d，最优并发数: %d', 
                count($requests), $estimated_size, $optimal_concurrency),
            'ConcurrencyManager'
        );
        
        $results = [];
        $batches = array_chunk($requests, $optimal_concurrency);
        $this->concurrency_stats['total_batches_processed'] += count($batches);
        
        foreach ($batches as $batch_index => $batch) {
            $batch_results = $this->process_adaptive_batch($batch, $batch_index);
            $results = array_merge($results, $batch_results);
            
            // 实时性能监控和自适应调整
            if ($this->config['enable_adaptive_adjustment']) {
                $new_optimal = $this->adjust_concurrency_based_on_real_time_performance($optimal_concurrency, $batch_results);
                if ($new_optimal !== $optimal_concurrency) {
                    $optimal_concurrency = $new_optimal;
                    $this->concurrency_stats['adaptive_adjustments']++;
                    Logger::debug_log(
                        sprintf('实时调整并发数: %d -> %d', $optimal_concurrency, $new_optimal),
                        'ConcurrencyManager'
                    );
                }
            }
        }
        
        $total_time = microtime(true) - $start_time;
        $this->record_adaptive_performance_metrics($total_time, count($requests), $optimal_concurrency);
        
        return $results;
    }

    /**
     * 根据数据量计算最优并发数 - 智能算法
     *
     * @param int $estimated_size 预估的数据量
     * @param int $page_size 每页大小
     * @return int 最优并发数
     */
    public function calculate_optimal_concurrency_by_data_size(int $estimated_size, int $page_size = 100): int {
        // 计算预估的页面数
        $estimated_pages = ceil($estimated_size / $page_size);
        
        // 基础并发数计算
        $base_concurrency = $this->config['max_concurrent_requests'];
        
        // 根据数据量动态调整并发数
        if ($estimated_pages <= 2) {
            $optimal_concurrency = 1; // 小数据集使用单线程
        } elseif ($estimated_pages <= 10) {
            $optimal_concurrency = min(3, $estimated_pages); // 中等数据集
        } else {
            $optimal_concurrency = min($this->config['max_concurrent_requests'], ceil($estimated_pages / 5)); // 大数据集
        }
        
        // 考虑系统负载调整
        $system_load = $this->get_system_load_factor();
        $memory_factor = $this->get_memory_usage_factor();
        
        $adjustment_factor = min($system_load, $memory_factor);
        $optimal_concurrency = max(
            $this->config['min_concurrency'],
            intval($optimal_concurrency * $adjustment_factor)
        );
        
        // 历史性能调整
        if (!empty($this->performance_metrics)) {
            $avg_response_time = array_sum($this->performance_metrics) / count($this->performance_metrics);
            if ($avg_response_time > 3.0) {
                $optimal_concurrency = max($this->config['min_concurrency'], intval($optimal_concurrency * 0.8));
            } elseif ($avg_response_time < 1.0) {
                $optimal_concurrency = min($this->config['max_concurrency'], intval($optimal_concurrency * 1.2));
            }
        }
        
        // 记录历史数据
        $this->concurrency_stats['optimal_concurrency_history'][] = [
            'timestamp' => time(),
            'estimated_size' => $estimated_size,
            'optimal_concurrency' => $optimal_concurrency,
            'system_load' => $system_load,
            'memory_factor' => $memory_factor,
        ];
        
        // 只保留最近50条记录
        if (count($this->concurrency_stats['optimal_concurrency_history']) > 50) {
            array_shift($this->concurrency_stats['optimal_concurrency_history']);
        }
        
        Logger::debug_log(
            sprintf(
                '智能并发计算: 预估大小=%d, 页面数=%d, 系统负载=%.2f, 内存因子=%.2f, 最优并发=%d',
                $estimated_size,
                $estimated_pages,
                $system_load,
                $memory_factor,
                $optimal_concurrency
            ),
            'ConcurrencyManager'
        );
        
        return $optimal_concurrency;
    }

    /**
     * 预估数据库大小
     *
     * @param string $database_id 数据库ID
     * @param array $filter 过滤条件
     * @return int 预估的页面数量
     */
    public function estimate_database_size(string $database_id, array $filter = []): int {
        $cache_key = md5($database_id . serialize($filter));
        
        // 检查缓存
        if (isset($this->size_estimation_cache[$cache_key])) {
            return $this->size_estimation_cache[$cache_key];
        }
        
        // 执行小样本查询来预估大小
        $sample_size = 10;
        $estimation = $sample_size; // 默认预估值
        
        try {
            // 这里可以实现更复杂的预估逻辑
            // 比如查询数据库的元数据或执行小样本查询
            
            // 简化实现：根据过滤条件调整预估
            if (empty($filter)) {
                $estimation = 500; // 无过滤条件时的默认预估
            } else {
                $estimation = 100; // 有过滤条件时的预估
            }
            
            // 缓存预估结果
            $this->size_estimation_cache[$cache_key] = $estimation;
            
        } catch (\Exception $e) {
            Logger::warning_log(
                sprintf('数据库大小预估失败: %s', $e->getMessage()),
                'ConcurrencyManager'
            );
        }
        
        return $estimation;
    }

    /**
     * 批量任务管理
     *
     * @param string $type 任务类型
     * @param int $task_count 任务数量
     * @return array 批量管理信息
     */
    public function manage_batch_tasks(string $type, int $task_count): array {
        $optimal_concurrency = $this->calculate_optimal_concurrency_by_data_size($task_count);
        $batch_size = min($optimal_concurrency, $task_count);
        $batches = ceil($task_count / $batch_size);
        
        return [
            'optimal_concurrency' => $optimal_concurrency,
            'batch_size' => $batch_size,
            'total_batches' => $batches,
            'estimated_time' => $batches * 2, // 估算时间（秒）
            'recommendation' => match(true) {
                $batches > 20 => 'consider_splitting_further',
                $batches > 10 => 'consider_splitting',
                default => 'proceed'
            }
        ];
    }
    
    // === 新增的辅助方法 ===
    
    /**
     * 获取系统负载因子
     */
    private function get_system_load_factor(): float {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg()[0] ?? 1.0;
            if ($load > $this->config['cpu_threshold']) {
                return 0.7; // 高负载降低并发
            } elseif ($load < 0.5) {
                return 1.3; // 低负载增加并发
            }
        }
        return 1.0;
    }
    
    /**
     * 获取内存使用因子
     */
    private function get_memory_usage_factor(): float {
        $memory_usage = memory_get_usage(true) / $this->get_memory_limit();
        
        if ($memory_usage > $this->config['memory_threshold']) {
            return 0.8; // 内存紧张时减少并发
        } elseif ($memory_usage < 0.5) {
            return 1.2; // 内存充裕时适度增加
        }
        
        return 1.0;
    }
    
    /**
     * 处理适配性批次
     */
    private function process_adaptive_batch(array $batch, int $batch_index): array {
        $batch_start_time = microtime(true);
        $results = $this->process_batch($batch);
        $batch_time = microtime(true) - $batch_start_time;
        
        // 记录批次性能
        $successful_count = count(array_filter($results, fn($r) => $r['success'] ?? false));
        $batch_performance = [
            'batch_index' => $batch_index,
            'batch_size' => count($batch),
            'execution_time' => $batch_time,
            'success_rate' => $successful_count / count($batch),
            'avg_response_time' => $batch_time / count($batch),
        ];
        
        Logger::debug_log(
            sprintf('Batch %d: 耗时%.2fs, 成功率%.1f%%, 平均响应%.3fs',
                $batch_index, $batch_time, $batch_performance['success_rate'] * 100, $batch_performance['avg_response_time']),
            'ConcurrencyManager'
        );
        
        return $results;
    }
    
    /**
     * 基于实时性能调整并发数
     */
    private function adjust_concurrency_based_on_real_time_performance(int $current_concurrency, array $batch_results): int {
        $success_count = count(array_filter($batch_results, fn($r) => $r['success'] ?? false));
        $success_rate = $success_count / count($batch_results);
        
        // 根据成功率调整
        if ($success_rate < 0.8) {
            // 成功率低，减少并发
            return max($this->config['min_concurrency'], intval($current_concurrency * 0.8));
        } elseif ($success_rate > 0.95) {
            // 成功率高，可以适度增加并发
            return min($this->config['max_concurrency'], intval($current_concurrency * 1.1));
        }
        
        return $current_concurrency;
    }
    
    /**
     * 记录适配性性能指标
     */
    private function record_adaptive_performance_metrics(float $total_time, int $request_count, int $optimal_concurrency): void {
        $avg_time = $total_time / $request_count;
        $this->performance_metrics[] = $avg_time;
        
        // 只保留最近50次记录
        if (count($this->performance_metrics) > 50) {
            array_shift($this->performance_metrics);
        }
        
        // 记录性能改进
        if (count($this->performance_metrics) >= 10) {
            $recent_avg = array_sum(array_slice($this->performance_metrics, -5)) / 5;
            $historical_avg = array_sum(array_slice($this->performance_metrics, -10, 5)) / 5;
            
            if ($recent_avg < $historical_avg * 0.9) {
                $this->concurrency_stats['performance_improvements']++;
            }
        }
    }
    
    /**
     * 获取详细性能统计
     */
    public function get_detailed_performance_stats(): array {
        return [
            'current_concurrency' => $this->config['max_concurrent_requests'],
            'performance_metrics' => $this->performance_metrics,
            'concurrency_stats' => $this->concurrency_stats,
            'system_resources' => [
                'memory_usage' => memory_get_usage(true),
                'memory_limit' => $this->get_memory_limit(),
                'memory_usage_percentage' => (memory_get_usage(true) / $this->get_memory_limit()) * 100,
                'system_load' => function_exists('sys_getloadavg') ? sys_getloadavg()[0] : 'N/A',
            ],
            'connection_pool_stats' => class_exists('\NTWP\Core\Foundation\HttpClient') ? 
                \NTWP\Core\Foundation\HttpClient::get_connection_pool_stats() : [],
            'cache_stats' => [
                'size_estimation_cache_size' => count($this->size_estimation_cache),
            ],
        ];
    }
    
    // ========================================
    // 从原始并发管理器中恢复的高级功能
    // ========================================
    
    /**
     * 任务管理 - 统一并发管理功能 (from Unified_Concurrency_Manager)
     */
    public function can_start_task(string $type): bool {
        $max_concurrent = $this->get_max_concurrent_for_type($type);
        $current_count = self::$task_counters[$type] ?? 0;
        
        return $current_count < $max_concurrent && $this->is_system_healthy();
    }
    
    public function start_task(string $type): bool {
        if (!$this->can_start_task($type)) {
            return false;
        }

        self::$task_counters[$type] = (self::$task_counters[$type] ?? 0) + 1;

        // 更新统一统计数据 (兼容Unified_Concurrency_Manager)
        switch ($type) {
            case 'requests':
                self::$unified_stats['current_requests']++;
                break;
            case 'downloads':
                self::$unified_stats['current_downloads']++;
                break;
            case 'uploads':
                self::$unified_stats['current_uploads']++;
                break;
        }
        self::$unified_stats['total_requests']++;

        Logger::debug_log(
            sprintf('开始任务: %s (当前: %d)', $type, self::$task_counters[$type]),
            'ConcurrencyManager'
        );

        return true;
    }
    
    public function end_task(string $type): void {
        if (isset(self::$task_counters[$type]) && self::$task_counters[$type] > 0) {
            self::$task_counters[$type]--;

            // 更新统一统计数据 (兼容Unified_Concurrency_Manager)
            switch ($type) {
                case 'requests':
                    self::$unified_stats['current_requests'] = max(0, self::$unified_stats['current_requests'] - 1);
                    break;
                case 'downloads':
                    self::$unified_stats['current_downloads'] = max(0, self::$unified_stats['current_downloads'] - 1);
                    break;
                case 'uploads':
                    self::$unified_stats['current_uploads'] = max(0, self::$unified_stats['current_uploads'] - 1);
                    break;
            }

            Logger::debug_log(
                sprintf('结束任务: %s (当前: %d)', $type, self::$task_counters[$type]),
                'ConcurrencyManager'
            );
        }
    }
    
    public function is_system_healthy(): bool {
        $system_load = sys_getloadavg()[0] ?? 1.0;
        $memory_usage = memory_get_usage(true) / $this->get_memory_limit();
        
        return $system_load < $this->config['cpu_threshold'] && 
               $memory_usage < $this->config['memory_threshold'];
    }
    
    public function get_optimal_concurrency_for_type(string $type, int $data_size = 0): int {
        $base_concurrency = $this->get_max_concurrent_for_type($type);
        
        // 根据数据大小调整
        if ($data_size > 1000) {
            return max(1, intval($base_concurrency * 0.7));
        } elseif ($data_size > 100) {
            return max(1, intval($base_concurrency * 0.9));
        }
        
        return $base_concurrency;
    }
    
    public function get_concurrency_stats(): array {
        return [
            'task_counters' => self::$task_counters,
            'system_healthy' => $this->is_system_healthy(),
            'optimal_concurrency' => $this->get_optimal_concurrency(),
            'performance_stats' => $this->concurrency_stats
        ];
    }
    
    private function get_max_concurrent_for_type(string $type): int {
        $defaults = [
            'requests' => $this->config['max_concurrent_requests'],
            'downloads' => max(1, intval($this->config['max_concurrent_requests'] * 0.6)),
            'uploads' => max(1, intval($this->config['max_concurrent_requests'] * 0.4))
        ];

        return $defaults[$type] ?? $this->config['max_concurrent_requests'];
    }

    // ========================================
    // 补充的统计和监控方法 (from Unified_Concurrency_Manager)
    // ========================================

    /**
     * 获取并发统计信息 (兼容Unified_Concurrency_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public function get_stats(): array {
        // 更新统一统计数据
        self::$unified_stats['current_requests'] = self::$task_counters['requests'] ?? 0;
        self::$unified_stats['current_downloads'] = self::$task_counters['downloads'] ?? 0;
        self::$unified_stats['current_uploads'] = self::$task_counters['uploads'] ?? 0;

        return array_merge(self::$unified_stats, [
            'config' => $this->config,
            'system_healthy' => $this->is_system_healthy(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'system_load' => function_exists('sys_getloadavg') ? sys_getloadavg()[0] : 'N/A',
            'performance_metrics' => $this->performance_metrics,
            'concurrency_stats' => $this->concurrency_stats
        ]);
    }

    /**
     * 重置统计信息 (兼容Unified_Concurrency_Manager)
     *
     * @since 2.0.0-beta.1
     */
    public function reset_stats(): void {
        self::$unified_stats = [
            'current_requests' => 0,
            'current_downloads' => 0,
            'current_uploads' => 0,
            'total_requests' => 0,
            'adaptive_adjustments' => 0
        ];

        // 同时重置任务计数器
        self::$task_counters = [
            'requests' => 0,
            'downloads' => 0,
            'uploads' => 0
        ];

        // 重置性能指标
        $this->performance_metrics = [];
        $this->concurrency_stats = [
            'total_batches_processed' => 0,
            'adaptive_adjustments' => 0,
            'optimal_concurrency_history' => [],
            'performance_improvements' => 0,
        ];

        Logger::debug_log('并发统计信息已重置', 'ConcurrencyManager');
    }

    /**
     * 等待可用槽位 (兼容Unified_Concurrency_Manager)
     *
     * @since 2.0.0-beta.1
     * @param string $type 任务类型
     * @param int $max_wait_ms 最大等待时间（毫秒）
     * @return bool 是否获得槽位
     */
    public function wait_for_slot(string $type, int $max_wait_ms = 5000): bool {
        $start_time = microtime(true);
        $wait_interval = 50; // 50ms检查间隔

        Logger::debug_log(
            sprintf('开始等待槽位: %s (最大等待: %dms)', $type, $max_wait_ms),
            'ConcurrencyManager'
        );

        while ((microtime(true) - $start_time) * 1000 < $max_wait_ms) {
            if ($this->can_start_task($type)) {
                $success = $this->start_task($type);
                if ($success) {
                    Logger::debug_log(
                        sprintf('成功获得槽位: %s (等待时间: %.2fms)',
                            $type, (microtime(true) - $start_time) * 1000),
                        'ConcurrencyManager'
                    );
                }
                return $success;
            }

            usleep($wait_interval * 1000); // 转换为微秒
        }

        Logger::debug_log(
            sprintf('等待槽位超时: %s (等待时间: %dms)', $type, $max_wait_ms),
            'ConcurrencyManager'
        );

        return false;
    }

    // ========================================
    // 补充的请求队列管理方法 (from Concurrent_Network_Manager)
    // ========================================

    /**
     * 添加请求到队列 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param string $url 请求URL
     * @param array $args 请求参数
     * @return int 请求ID
     */
    public function add_request($url, $args = []): int {
        $request_id = $this->next_request_id++;

        // 设置默认参数
        $default_args = [
            'method'     => 'GET',
            'timeout'    => $this->default_timeout,
            'headers'    => [],
            'body'       => '',
            'user-agent' => 'Notion-to-WordPress/' . (defined('NOTION_TO_WORDPRESS_VERSION') ? NOTION_TO_WORDPRESS_VERSION : '2.0.0')
        ];

        // 合并参数 (兼容WordPress的wp_parse_args)
        if (function_exists('wp_parse_args')) {
            $args = wp_parse_args($args, $default_args);
        } else {
            $args = array_merge($default_args, $args);
        }

        // 存储请求配置
        $this->request_queue[$request_id] = [
            'url'  => $url,
            'args' => $args,
            'status' => 'pending'
        ];

        Logger::debug_log(
            sprintf("添加请求到队列: %s %s (ID: %d)", $args['method'], $url, $request_id),
            'ConcurrencyManager'
        );

        return $request_id;
    }

    /**
     * 获取所有响应结果 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 响应结果数组
     */
    public function get_responses(): array {
        return $this->response_results;
    }

    /**
     * 获取指定请求的响应 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param int $request_id 请求ID
     * @return mixed 响应结果或null
     */
    public function get_response($request_id) {
        return isset($this->response_results[$request_id]) ? $this->response_results[$request_id] : null;
    }

    /**
     * 执行所有并发请求 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 响应结果数组
     */
    public function execute(): array {
        return $this->execute_with_retry();
    }

    /**
     * 执行所有并发请求（支持重试） (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param int $max_retries 最大重试次数
     * @param int $base_delay 基础延迟时间（毫秒）
     * @return array 响应结果数组
     */
    public function execute_with_retry($max_retries = 2, $base_delay = 1000): array {
        $attempt = 0;
        $last_exception = null;

        while ($attempt <= $max_retries) {
            try {
                return $this->execute_internal();
            } catch (Exception $e) {
                $last_exception = $e;
                $attempt++;

                if ($attempt <= $max_retries) {
                    $delay = $base_delay * pow(2, $attempt - 1); // 指数退避
                    Logger::debug_log(
                        sprintf('请求执行失败，%dms后重试 (第%d次)', $delay, $attempt),
                        'ConcurrencyManager'
                    );
                    usleep($delay * 1000); // 转换为微秒
                }
            }
        }

        // 所有重试都失败了
        Logger::error_log(
            sprintf('请求执行失败，已重试%d次: %s', $max_retries, $last_exception->getMessage()),
            'ConcurrencyManager'
        );

        return [];
    }

    /**
     * 内部执行方法 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 响应结果数组
     * @throws Exception
     */
    public function execute_internal(): array {
        if (empty($this->request_queue)) {
            Logger::debug_log('没有待执行的请求', 'ConcurrencyManager');
            return [];
        }

        Logger::debug_log(
            sprintf('开始执行 %d 个并发请求', count($this->request_queue)),
            'ConcurrencyManager'
        );

        $start_time = microtime(true);

        try {
            // 初始化multi handle
            $this->init_multi_handle();

            // 创建cURL句柄
            $this->create_curl_handles();

            // 执行请求
            $this->execute_requests();

            // 处理响应
            $this->process_responses();

            $execution_time = microtime(true) - $start_time;
            Logger::debug_log(
                sprintf('并发请求执行完成，耗时: %.2fs', $execution_time),
                'ConcurrencyManager'
            );

            return $this->response_results;

        } catch (Exception $e) {
            Logger::error_log(
                sprintf('并发请求执行失败: %s', $e->getMessage()),
                'ConcurrencyManager'
            );
            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    /**
     * 执行队列中的请求
     *
     * @since 2.0.0-beta.1
     * @return array 执行结果
     */
    public function execute_queued_requests(): array {
        if (empty($this->request_queue)) {
            Logger::debug_log('请求队列为空，无需执行', 'ConcurrencyManager');
            return [];
        }

        Logger::debug_log(
            sprintf('开始执行队列中的 %d 个请求', count($this->request_queue)),
            'ConcurrencyManager'
        );

        // 将队列请求转换为execute_concurrent_requests格式
        $requests = [];
        foreach ($this->request_queue as $request_id => $request_data) {
            if ($request_data['status'] === 'pending') {
                $requests[] = [
                    'url' => $request_data['url'],
                    'args' => $request_data['args'],
                    'request_id' => $request_id
                ];
                $this->request_queue[$request_id]['status'] = 'executing';
            }
        }

        // 执行并发请求
        $results = $this->execute_concurrent_requests($requests);

        // 存储结果到response_results
        foreach ($results as $index => $result) {
            if (isset($requests[$index]['request_id'])) {
                $request_id = $requests[$index]['request_id'];
                $this->response_results[$request_id] = $result;
                $this->request_queue[$request_id]['status'] = 'completed';
            }
        }

        Logger::debug_log(
            sprintf('队列请求执行完成，处理了 %d 个请求', count($results)),
            'ConcurrencyManager'
        );

        return $results;
    }

    /**
     * 获取连接池统计信息 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 连接池统计数据
     */
    public function get_connection_pool_stats(): array {
        $stats = $this->connection_pool_stats;

        // 计算连接复用率
        if ($stats['total_connections_created'] > 0) {
            $stats['reuse_rate'] = round(($stats['connections_reused'] / $stats['total_connections_created']) * 100, 2);
        } else {
            $stats['reuse_rate'] = 0;
        }

        // 添加当前连接池状态
        $stats['current_pool_size'] = count($this->connection_pool);
        $stats['max_pool_size'] = 10; // 默认最大连接池大小

        if ($stats['max_pool_size'] > 0) {
            $stats['pool_utilization'] = round((count($this->connection_pool) / $stats['max_pool_size']) * 100, 2);
        } else {
            $stats['pool_utilization'] = 0;
        }

        // 添加队列统计
        $stats['queue_stats'] = [
            'total_queued' => count($this->request_queue),
            'pending_requests' => count(array_filter($this->request_queue, function($req) {
                return $req['status'] === 'pending';
            })),
            'completed_requests' => count(array_filter($this->request_queue, function($req) {
                return $req['status'] === 'completed';
            })),
            'total_responses' => count($this->response_results)
        ];

        return $stats;
    }

    /**
     * 初始化multi handle (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @access private
     */
    private function init_multi_handle(): void {
        if (!$this->multi_handle) {
            $this->multi_handle = curl_multi_init();

            if ($this->multi_handle === false) {
                throw new Exception('无法初始化cURL multi handle');
            }

            Logger::debug_log('cURL multi handle 初始化成功', 'ConcurrencyManager');
        }
    }

    /**
     * 创建cURL句柄 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @access private
     */
    private function create_curl_handles(): void {
        $this->curl_handles = [];

        foreach ($this->request_queue as $request_id => $request) {
            $curl_handle = curl_init();

            if ($curl_handle === false) {
                Logger::error_log("无法创建cURL句柄，请求ID: {$request_id}", 'ConcurrencyManager');
                continue;
            }

            // 配置cURL句柄
            $this->configure_curl_handle($curl_handle, $request);

            // 添加到multi handle
            $result = curl_multi_add_handle($this->multi_handle, $curl_handle);
            if ($result !== CURLM_OK) {
                Logger::error_log("无法添加cURL句柄到multi handle，请求ID: {$request_id}", 'ConcurrencyManager');
                curl_close($curl_handle);
                continue;
            }

            $this->curl_handles[$request_id] = $curl_handle;
        }

        Logger::debug_log(
            sprintf('创建了 %d 个cURL句柄', count($this->curl_handles)),
            'ConcurrencyManager'
        );
    }

    /**
     * 配置cURL句柄 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @access private
     * @param resource $curl_handle cURL句柄
     * @param array $request 请求配置
     */
    private function configure_curl_handle($curl_handle, array $request): void {
        $url = $request['url'];
        $args = $request['args'];

        // 基本配置
        $curl_options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $args['timeout'] ?? $this->config['request_timeout'],
            CURLOPT_FOLLOWLOCATION => $args['redirection'] ?? false,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => $args['user-agent'] ?? 'Notion-to-WordPress/2.0',
        ];

        // HTTP方法配置
        $method = strtoupper($args['method'] ?? 'GET');
        switch ($method) {
            case 'POST':
                $curl_options[CURLOPT_POST] = true;
                if (isset($args['body'])) {
                    $curl_options[CURLOPT_POSTFIELDS] = $args['body'];
                }
                break;
            case 'PUT':
                $curl_options[CURLOPT_CUSTOMREQUEST] = 'PUT';
                if (isset($args['body'])) {
                    $curl_options[CURLOPT_POSTFIELDS] = $args['body'];
                }
                break;
            case 'DELETE':
                $curl_options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
        }

        // 请求头配置
        if (isset($args['headers']) && is_array($args['headers'])) {
            $headers = [];
            foreach ($args['headers'] as $key => $value) {
                $headers[] = "{$key}: {$value}";
            }
            $curl_options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($curl_handle, $curl_options);
    }

    /**
     * 执行并发请求 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @access private
     */
    private function execute_requests(): void {
        $running = null;
        $start_time = microtime(true);
        $max_execution_time = 90; // 最大执行时间90秒

        // 开始执行
        do {
            $status = curl_multi_exec($this->multi_handle, $running);

            if ($status !== CURLM_OK) {
                throw new Exception("cURL multi exec 错误: {$status}");
            }

            // 检查是否有完成的请求
            while (($info = curl_multi_info_read($this->multi_handle)) !== false) {
                // 处理完成的请求信息
                Logger::debug_log('请求完成', 'ConcurrencyManager');
            }

            // 检查执行时间
            if ((microtime(true) - $start_time) > $max_execution_time) {
                throw new Exception('请求执行超时');
            }

            // 短暂休眠避免CPU占用过高
            if ($running > 0) {
                curl_multi_select($this->multi_handle, 0.1);
            }

        } while ($running > 0);

        Logger::debug_log('所有并发请求执行完成', 'ConcurrencyManager');
    }

    /**
     * 处理响应结果 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @access private
     */
    private function process_responses(): void {
        $this->response_results = [];
        $successful_requests = 0;

        foreach ($this->curl_handles as $request_id => $curl_handle) {
            $response_data = curl_multi_getcontent($curl_handle);
            $http_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
            $error = curl_error($curl_handle);

            if ($error) {
                // 请求错误
                $this->response_results[$request_id] = new \WP_Error(
                    'curl_error',
                    $error,
                    ['http_code' => $http_code]
                );
                Logger::error_log("请求失败 ID {$request_id}: {$error}", 'ConcurrencyManager');
            } else {
                // 请求成功
                $this->response_results[$request_id] = [
                    'body' => $response_data,
                    'response' => [
                        'code' => $http_code,
                        'message' => $this->get_http_status_message($http_code)
                    ],
                    'headers' => $this->parse_response_headers($curl_handle)
                ];

                if ($http_code >= 200 && $http_code < 300) {
                    $successful_requests++;
                }
            }
        }

        Logger::debug_log(
            sprintf('响应处理完成，成功: %d，总计: %d',
                $successful_requests, count($this->curl_handles)),
            'ConcurrencyManager'
        );
    }

    /**
     * 清理资源 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     */
    private function cleanup(): void {
        // 清理cURL句柄
        foreach ($this->curl_handles as $curl_handle) {
            if ($this->multi_handle) {
                curl_multi_remove_handle($this->multi_handle, $curl_handle);
            }
            curl_close($curl_handle);
        }

        // 清理连接池
        foreach ($this->connection_pool as $handle) {
            curl_close($handle);
        }

        // 清理multi handle
        if ($this->multi_handle) {
            curl_multi_close($this->multi_handle);
            $this->multi_handle = null;
        }

        // 重置数组
        $this->curl_handles = [];
        $this->connection_pool = [];
        $this->request_queue = [];
        // 保留响应结果和统计信息以供后续查询

        Logger::debug_log('ConcurrencyManager资源清理完成', 'ConcurrencyManager');
    }

    /**
     * 解析响应头 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @access private
     * @param resource $curl_handle cURL句柄
     * @return array 响应头数组
     */
    private function parse_response_headers($curl_handle): array {
        $headers = [];

        // 获取响应头信息
        $content_type = curl_getinfo($curl_handle, CURLINFO_CONTENT_TYPE);
        if ($content_type) {
            $headers['content-type'] = $content_type;
        }

        $content_length = curl_getinfo($curl_handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        if ($content_length > 0) {
            $headers['content-length'] = $content_length;
        }

        return $headers;
    }

    /**
     * 获取HTTP状态码消息 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @access private
     * @param int $code HTTP状态码
     * @return string 状态消息
     */
    private function get_http_status_message(int $code): string {
        $messages = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable'
        ];

        return $messages[$code] ?? 'Unknown Status';
    }

    /**
     * 统计成功响应数量 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @access private
     * @return int 成功响应数量
     */
    private function count_successful_responses(): int {
        $count = 0;
        foreach ($this->response_results as $response) {
            if (!is_wp_error($response)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 统计失败响应数量 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @access private
     * @return int 失败响应数量
     */
    private function count_failed_responses(): int {
        $count = 0;
        foreach ($this->response_results as $response) {
            if (is_wp_error($response)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 析构函数 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     */
    public function __destruct() {
        $this->cleanup();
    }

    /**
     * 清理请求队列和响应结果
     *
     * @since 2.0.0-beta.1
     */
    public function clear_queue(): void {
        $queue_count = count($this->request_queue);
        $response_count = count($this->response_results);

        $this->request_queue = [];
        $this->response_results = [];
        $this->next_request_id = 0;

        Logger::debug_log(
            sprintf('清理请求队列: %d个请求, %d个响应', $queue_count, $response_count),
            'ConcurrencyManager'
        );
    }

    /**
     * 获取队列状态信息
     *
     * @since 2.0.0-beta.1
     * @return array 队列状态
     */
    public function get_queue_status(): array {
        $pending = 0;
        $executing = 0;
        $completed = 0;

        foreach ($this->request_queue as $request) {
            switch ($request['status']) {
                case 'pending':
                    $pending++;
                    break;
                case 'executing':
                    $executing++;
                    break;
                case 'completed':
                    $completed++;
                    break;
            }
        }

        return [
            'total_requests' => count($this->request_queue),
            'pending' => $pending,
            'executing' => $executing,
            'completed' => $completed,
            'total_responses' => count($this->response_results),
            'next_request_id' => $this->next_request_id
        ];
    }

    /**
     * 检查是否启用性能模式 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return bool 是否启用性能模式
     */
    public function is_performance_mode(): bool {
        return $this->config['enable_adaptive_adjustment'] ?? false;
    }

    /**
     * 设置最大并发请求数 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param int $max_requests 最大并发请求数
     */
    public function set_max_concurrent_requests(int $max_requests): void {
        $this->config['max_concurrent_requests'] = max(1, min($max_requests, 20));
        Logger::debug_log(
            sprintf('设置最大并发请求数: %d', $this->config['max_concurrent_requests']),
            'ConcurrencyManager'
        );
    }

    /**
     * 设置默认超时时间 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param int $timeout 超时时间（秒）
     */
    public function set_default_timeout(int $timeout): void {
        $this->config['request_timeout'] = max(5, min($timeout, 300));
        Logger::debug_log(
            sprintf('设置默认超时时间: %d秒', $this->config['request_timeout']),
            'ConcurrencyManager'
        );
    }

    /**
     * 初始化连接池 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param int $pool_size 连接池大小
     * @return bool 是否成功
     */
    public function init_connection_pool(int $pool_size = 10): bool {
        // 简化的连接池实现
        $this->connection_pool = [
            'size' => $pool_size,
            'active' => 0,
            'available' => $pool_size,
            'connections' => [],
            'stats' => [
                'created' => 0,
                'reused' => 0,
                'closed' => 0
            ]
        ];

        Logger::debug_log(
            sprintf('初始化连接池，大小: %d', $pool_size),
            'ConcurrencyManager'
        );

        return true;
    }

    /**
     * 从连接池获取连接 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return resource|null cURL句柄
     */
    public function get_connection_from_pool() {
        if (!isset($this->connection_pool)) {
            $this->init_connection_pool();
        }

        // 检查是否有可用连接
        if (!empty($this->connection_pool['connections'])) {
            $connection = array_pop($this->connection_pool['connections']);
            $this->connection_pool['active']++;
            $this->connection_pool['available']--;
            $this->connection_pool['stats']['reused']++;

            return $connection;
        }

        // 创建新连接
        if ($this->connection_pool['active'] < $this->connection_pool['size']) {
            $connection = curl_init();
            $this->connection_pool['active']++;
            $this->connection_pool['stats']['created']++;

            return $connection;
        }

        return null;
    }

    /**
     * 创建优化的cURL句柄 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param array $options cURL选项
     * @return resource cURL句柄
     */
    public function create_optimized_curl_handle(array $options = []) {
        $curl = $this->get_connection_from_pool() ?: curl_init();

        // 设置默认选项
        $default_options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $this->config['request_timeout'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Notion-to-WordPress/2.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ];

        // 合并用户选项
        $final_options = array_merge($default_options, $options);
        curl_setopt_array($curl, $final_options);

        return $curl;
    }

    /**
     * 检查连接健康状态（增强版） (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param resource $connection cURL连接
     * @return bool 连接是否健康
     */
    public function is_connection_healthy_enhanced($connection): bool {
        if (!is_resource($connection)) {
            return false;
        }

        // 检查连接信息
        $info = curl_getinfo($connection);

        // 检查是否有错误
        if (curl_errno($connection) !== 0) {
            return false;
        }

        // 检查连接时间是否合理
        if (isset($info['connect_time']) && $info['connect_time'] > 10) {
            return false;
        }

        return true;
    }

    /**
     * 获取连接复用率 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return float 连接复用率
     */
    public function get_connection_reuse_rate(): float {
        if (!isset($this->connection_pool['stats'])) {
            return 0.0;
        }

        $stats = $this->connection_pool['stats'];
        $total = $stats['created'] + $stats['reused'];

        return $total > 0 ? ($stats['reused'] / $total) * 100 : 0.0;
    }

    /**
     * 获取连接池报告 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 连接池状态报告
     */
    public function get_connection_pool_report(): array {
        if (!isset($this->connection_pool)) {
            return ['status' => 'not_initialized'];
        }

        return [
            'pool_size' => $this->connection_pool['size'],
            'active_connections' => $this->connection_pool['active'],
            'available_connections' => $this->connection_pool['available'],
            'utilization_rate' => ($this->connection_pool['active'] / $this->connection_pool['size']) * 100,
            'reuse_rate' => $this->get_connection_reuse_rate(),
            'statistics' => $this->connection_pool['stats']
        ];
    }

    /**
     * 计算效率分数 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return float 效率分数 (0-100)
     */
    public function calculate_efficiency_score(): float {
        $report = $this->get_connection_pool_report();

        if ($report['status'] ?? null === 'not_initialized') {
            return 0.0;
        }

        $score = 0.0;

        // 连接池利用率 (40%)
        $utilization = $report['utilization_rate'];
        $score += min($utilization, 80) * 0.5; // 最优80%利用率

        // 连接复用率 (30%)
        $reuse_rate = $report['reuse_rate'];
        $score += min($reuse_rate, 70) * 0.43; // 最优70%复用率

        // 性能指标 (30%)
        if (!empty($this->performance_metrics)) {
            $avg_response_time = array_sum($this->performance_metrics) / count($this->performance_metrics);
            $time_score = max(0, 100 - ($avg_response_time * 10)); // 响应时间越短分数越高
            $score += $time_score * 0.3;
        }

        return min(100.0, $score);
    }

    /**
     * 检查连接健康状态 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param resource $connection cURL连接
     * @return bool 连接是否健康
     */
    public function is_connection_healthy($connection): bool {
        return $this->is_connection_healthy_enhanced($connection);
    }

    /**
     * 将连接返回到连接池 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param resource $connection cURL连接
     * @return bool 是否成功
     */
    public function return_connection_to_pool($connection): bool {
        if (!isset($this->connection_pool) || !is_resource($connection)) {
            return false;
        }

        // 检查连接健康状态
        if (!$this->is_connection_healthy($connection)) {
            curl_close($connection);
            $this->connection_pool['stats']['closed']++;
            $this->connection_pool['active']--;
            return false;
        }

        // 重置连接状态
        curl_reset($connection);

        // 返回到连接池
        $this->connection_pool['connections'][] = $connection;
        $this->connection_pool['active']--;
        $this->connection_pool['available']++;

        return true;
    }

    /**
     * 清理连接池 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return int 清理的连接数
     */
    public function cleanup_connection_pool(): int {
        if (!isset($this->connection_pool)) {
            return 0;
        }

        $cleaned = 0;

        // 关闭所有连接
        foreach ($this->connection_pool['connections'] as $connection) {
            if (is_resource($connection)) {
                curl_close($connection);
                $cleaned++;
            }
        }

        // 重置连接池
        $this->connection_pool['connections'] = [];
        $this->connection_pool['active'] = 0;
        $this->connection_pool['available'] = $this->connection_pool['size'];
        $this->connection_pool['stats']['closed'] += $cleaned;

        Logger::debug_log(
            sprintf('清理连接池，关闭了 %d 个连接', $cleaned),
            'ConcurrencyManager'
        );

        return $cleaned;
    }

    /**
     * 重置连接池统计 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     */
    public function reset_connection_pool_stats(): void {
        if (isset($this->connection_pool['stats'])) {
            $this->connection_pool['stats'] = [
                'created' => 0,
                'reused' => 0,
                'closed' => 0
            ];
        }
    }

    /**
     * 获取连接池健康状态 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 健康状态报告
     */
    public function get_connection_pool_health(): array {
        if (!isset($this->connection_pool)) {
            return [
                'status' => 'not_initialized',
                'health_score' => 0,
                'issues' => ['连接池未初始化']
            ];
        }

        $issues = [];
        $health_score = 100;

        // 检查利用率
        $utilization = ($this->connection_pool['active'] / $this->connection_pool['size']) * 100;
        if ($utilization > 90) {
            $issues[] = '连接池利用率过高';
            $health_score -= 20;
        }

        // 检查复用率
        $reuse_rate = $this->get_connection_reuse_rate();
        if ($reuse_rate < 30) {
            $issues[] = '连接复用率偏低';
            $health_score -= 15;
        }

        // 检查连接健康状态
        $unhealthy_connections = 0;
        foreach ($this->connection_pool['connections'] as $connection) {
            if (!$this->is_connection_healthy($connection)) {
                $unhealthy_connections++;
            }
        }

        if ($unhealthy_connections > 0) {
            $issues[] = sprintf('发现 %d 个不健康连接', $unhealthy_connections);
            $health_score -= $unhealthy_connections * 5;
        }

        return [
            'status' => empty($issues) ? 'healthy' : 'warning',
            'health_score' => max(0, $health_score),
            'utilization_rate' => $utilization,
            'reuse_rate' => $reuse_rate,
            'unhealthy_connections' => $unhealthy_connections,
            'issues' => $issues
        ];
    }

    /**
     * 刷新连接池 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @return bool 是否成功
     */
    public function refresh_connection_pool(): bool {
        $old_size = $this->connection_pool['size'] ?? 10;

        // 清理现有连接池
        $this->cleanup_connection_pool();

        // 重新初始化
        $success = $this->init_connection_pool($old_size);

        Logger::debug_log('连接池已刷新', 'ConcurrencyManager');

        return $success;
    }

    /**
     * 计算最优并发数 (兼容Concurrent_Network_Manager)
     *
     * @since 2.0.0-beta.1
     * @param array $options 计算选项
     * @return int 最优并发数
     */
    public function calculate_optimal_concurrency(array $options = []): int {
        // 获取系统资源信息
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);
        $memory_available = $memory_limit - $memory_usage;

        // 基础并发数计算
        $base_concurrency = $this->config['max_concurrent_requests'];

        // 内存因子 (可用内存越多，并发数越高)
        $memory_factor = min(2.0, $memory_available / (64 * 1024 * 1024)); // 64MB为基准

        // CPU负载因子
        $cpu_factor = 1.0;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load && $load[0] > 0) {
                $cpu_factor = max(0.5, min(2.0, 2.0 / $load[0])); // 负载越低，因子越高
            }
        }

        // 网络延迟因子 (基于历史性能数据)
        $latency_factor = 1.0;
        if (!empty($this->performance_metrics)) {
            $avg_latency = array_sum($this->performance_metrics) / count($this->performance_metrics);
            $latency_factor = max(0.5, min(2.0, 1.0 / max(0.1, $avg_latency))); // 延迟越低，因子越高
        }

        // 计算最优并发数
        $optimal = intval($base_concurrency * $memory_factor * $cpu_factor * $latency_factor);

        // 应用配置限制
        $optimal = max($this->config['min_concurrency'], $optimal);
        $optimal = min($this->config['max_concurrency'], $optimal);

        Logger::debug_log(
            sprintf(
                '计算最优并发数: %d (基础: %d, 内存因子: %.2f, CPU因子: %.2f, 延迟因子: %.2f)',
                $optimal, $base_concurrency, $memory_factor, $cpu_factor, $latency_factor
            ),
            'ConcurrencyManager'
        );

        return $optimal;
    }
}