<?php
declare(strict_types=1);

namespace NTWP\Infrastructure;

use NTWP\Core\Logger;

/**
 * 统一并发管理器
 * 
 * 功能整合:
 * ✅ 网络请求并发 (from Concurrent_Network_Manager)
 * ✅ 配置管理 (from Unified_Concurrency_Manager)
 * ✅ 动态调优 (from Dynamic_Concurrency_Manager)
 */
class ConcurrencyManager {
    
    private array $config = [
        'max_concurrent_requests' => 5,
        'request_timeout' => 30,
        'enable_adaptive_adjustment' => true,
        'memory_threshold' => 0.8,
        'cpu_threshold' => 2.0,
    ];
    
    private $multi_handle;
    private array $performance_metrics = [];
    
    public function __construct() {
        $this->multi_handle = curl_multi_init();
        $this->load_config();
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
     * 配置管理
     */
    public function configure_limits(array $config): void {
        $validated_config = $this->validate_config($config);
        $this->config = array_merge($this->config, $validated_config);
        
        Logger::debug_log('并发配置已更新: ' . json_encode($validated_config), 'ConcurrencyManager');
    }
    
    /**
     * 性能监控
     */
    public function monitor_performance(): array {
        return [
            'current_concurrency' => $this->config['max_concurrent_requests'],
            'performance_metrics' => $this->performance_metrics,
            'system_resources' => [
                'memory_usage' => memory_get_usage(true),
                'system_load' => sys_getloadavg()[0] ?? 1.0,
            ],
        ];
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
    
    public function __destruct() {
        if (is_resource($this->multi_handle)) {
            curl_multi_close($this->multi_handle);
        }
    }
}