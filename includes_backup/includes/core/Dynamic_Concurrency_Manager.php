<?php

namespace NTWP\Core;

defined('ABSPATH') or die('Direct access not allowed');

use NTWP\Core\Logger;
use Exception;

/**
 * 动态并发管理器
 * 
 * 根据系统资源动态调整并发处理数量，优化性能
 * 
 * @package NTWP
 * @since 1.9.0
 */
class Dynamic_Concurrency_Manager {
    
    /**
     * 性能指标缓存
     *
     * @var array
     */
    private array $performance_metrics = [];
    
    /**
     * 当前并发数
     *
     * @var int
     */
    private int $current_concurrency = 5;
    
    /**
     * 网络延迟测试缓存时间（秒）
     *
     * @var int
     */
    private const LATENCY_CACHE_TIME = 300; // 5分钟
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->performance_metrics = get_transient('ntwp_performance_metrics') ?: [];
    }
    
    /**
     * 计算最优并发数
     *
     * @return int 最优并发数
     */
    public function calculate_optimal_concurrency(): int {
        $system_load = $this->get_system_load();
        $memory_available = $this->get_available_memory_mb();
        $network_latency = $this->measure_network_latency();
        
        // 动态计算最优并发数
        $base_concurrency = 5;
        
        // 内存充足时增加并发
        if ($memory_available > 512) {
            $base_concurrency = min(20, $base_concurrency * 2);
        } elseif ($memory_available > 256) {
            $base_concurrency = min(15, intval($base_concurrency * 1.5));
        }
        
        // 根据系统负载调整
        if ($system_load < 1.0) {
            $base_concurrency = min(25, intval($base_concurrency * 1.2));
        } elseif ($system_load > 2.0) {
            $base_concurrency = max(3, intval($base_concurrency * 0.7));
        }
        
        // 网络延迟高时减少并发避免超时
        if ($network_latency > 2000) { // 2秒以上
            $base_concurrency = max(3, intval($base_concurrency * 0.8));
        }
        
        Logger::info_log("动态并发调整: {$this->current_concurrency} -> {$base_concurrency} (内存:{$memory_available}MB, 负载:{$system_load}, 延迟:{$network_latency}ms)", 'Concurrency Manager');
        
        $this->current_concurrency = $base_concurrency;
        return $base_concurrency;
    }
    
    /**
     * 获取系统负载
     *
     * @return float 系统负载值
     */
    private function get_system_load(): float {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load ? $load[0] : 1.0;
        }
        return 1.0;
    }
    
    /**
     * 获取可用内存（MB）
     *
     * @return int 可用内存MB数
     */
    private function get_available_memory_mb(): int {
        $memory_limit = ini_get('memory_limit');
        $current_usage = memory_get_usage(true);
        
        $limit_bytes = $this->parse_memory_string($memory_limit);
        $available_mb = ($limit_bytes - $current_usage) / 1024 / 1024;
        
        return max(0, intval($available_mb));
    }
    
    /**
     * 解析内存字符串为字节数
     *
     * @param string $memory_string 内存字符串 (如 "128M", "1G")
     * @return int 字节数
     */
    private function parse_memory_string(string $memory_string): int {
        $memory_string = trim($memory_string);
        $unit = strtoupper(substr($memory_string, -1));
        $value = intval($memory_string);
        
        switch ($unit) {
            case 'G':
                return $value * 1024 * 1024 * 1024;
            case 'M':
                return $value * 1024 * 1024;
            case 'K':
                return $value * 1024;
            default:
                return $value;
        }
    }
    
    /**
     * 测量网络延迟
     *
     * @return int 延迟毫秒数
     */
    private function measure_network_latency(): int {
        // 检查缓存
        $cached_latency = get_transient('ntwp_network_latency');
        if ($cached_latency !== false) {
            return intval($cached_latency);
        }
        
        $start_time = microtime(true);
        $latency = 1000; // 默认1秒延迟
        
        try {
            // 测试到 Notion API 的延迟
            $test_url = 'https://api.notion.com/v1/users/me';
            $context = stream_context_create([
                'http' => [
                    'method' => 'HEAD',
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);
            
            $result = @file_get_contents($test_url, false, $context);
            $end_time = microtime(true);
            
            $latency = intval(($end_time - $start_time) * 1000);
            
        } catch (Exception $e) {
            Logger::warning_log("网络延迟测试失败: " . $e->getMessage(), 'Concurrency Manager');
        }
        
        // 缓存结果
        set_transient('ntwp_network_latency', $latency, self::LATENCY_CACHE_TIME);
        
        return $latency;
    }
    
    /**
     * 获取当前并发数
     *
     * @return int 当前并发数
     */
    public function get_current_concurrency(): int {
        return $this->current_concurrency;
    }
    
    /**
     * 设置并发数
     *
     * @param int $concurrency 并发数
     */
    public function set_concurrency(int $concurrency): void {
        $this->current_concurrency = max(1, min(30, $concurrency));
    }
    
    /**
     * 记录性能指标
     *
     * @param string $metric_name 指标名称
     * @param mixed $value 指标值
     */
    public function record_metric(string $metric_name, $value): void {
        $this->performance_metrics[$metric_name] = [
            'value' => $value,
            'timestamp' => time()
        ];
        
        // 保存到缓存
        set_transient('ntwp_performance_metrics', $this->performance_metrics, 3600);
    }
    
    /**
     * 获取性能指标
     *
     * @param string $metric_name 指标名称
     * @return mixed 指标值或null
     */
    public function get_metric(string $metric_name) {
        return $this->performance_metrics[$metric_name]['value'] ?? null;
    }
    
    /**
     * 获取所有性能指标
     *
     * @return array 所有性能指标
     */
    public function get_all_metrics(): array {
        return $this->performance_metrics;
    }
    
    /**
     * 清理过期指标
     */
    public function cleanup_expired_metrics(): void {
        $current_time = time();
        $expiry_time = 3600; // 1小时
        
        foreach ($this->performance_metrics as $metric_name => $data) {
            if (($current_time - $data['timestamp']) > $expiry_time) {
                unset($this->performance_metrics[$metric_name]);
            }
        }
        
        set_transient('ntwp_performance_metrics', $this->performance_metrics, 3600);
    }
}