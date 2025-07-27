<?php
/**
 * 智能API调用合并器
 * 
 * 基于DataLoader模式实现智能API调用合并，优化Notion API的批处理效率
 * 
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/includes/utils
 * @since      2.0.0-beta.1
 * @author     Frank Loong <frankloong@gmail.com>
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 智能API调用合并器类
 * 
 * 实现基于DataLoader模式的智能API调用合并，通过批处理窗口和动态批处理大小
 * 优化API调用效率，减少网络请求次数
 * 
 * @since 2.0.0-beta.1
 */
class Notion_Smart_API_Merger {
    
    /**
     * 待处理请求队列
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @var array $pending_requests 待处理请求数组
     */
    private $pending_requests = [];
    
    /**
     * 批处理窗口时间（毫秒）
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @var int $batch_timeout 批处理窗口时间
     */
    private $batch_timeout = 50;
    
    /**
     * 最小批处理大小
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @var int $min_batch_size 最小批处理大小
     */
    private $min_batch_size = 5;
    
    /**
     * 最大批处理大小
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @var int $max_batch_size 最大批处理大小
     */
    private $max_batch_size = 15;
    
    /**
     * 上次刷新时间
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @var float $last_flush_time 上次刷新时间戳
     */
    private $last_flush_time = 0;
    
    /**
     * Notion API实例
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @var Notion_API $notion_api Notion API实例
     */
    private $notion_api;
    
    /**
     * 性能统计
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @var array $stats 性能统计数据
     */
    private $stats = [
        'total_requests' => 0,
        'merged_requests' => 0,
        'batch_count' => 0,
        'merge_ratio' => 0
    ];
    
    /**
     * 构造函数
     * 
     * @since 2.0.0-beta.1
     * @param Notion_API $notion_api Notion API实例
     */
    public function __construct(Notion_API $notion_api) {
        $this->notion_api = $notion_api;
        $this->last_flush_time = microtime(true);
        
        // 从配置中获取批处理参数
        $options = get_option('notion_to_wordpress_options', []);
        $this->batch_timeout = $options['api_merge_timeout'] ?? 50;
        $this->min_batch_size = $options['api_merge_min_batch'] ?? 5;
        $this->max_batch_size = $options['api_merge_max_batch'] ?? 15;
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('智能API合并器初始化: 窗口=%dms, 批处理大小=%d-%d', 
                    $this->batch_timeout, $this->min_batch_size, $this->max_batch_size),
                'API Merger'
            );
        }
    }
    
    /**
     * 添加请求到合并队列
     * 
     * @since 2.0.0-beta.1
     * @param string $endpoint API端点
     * @param string $method HTTP方法
     * @param array $data 请求数据
     * @param callable $callback 回调函数
     * @return mixed 如果立即执行返回结果，否则返回null
     */
    public function queue_request(string $endpoint, string $method = 'GET', array $data = [], callable $callback = null) {
        $this->stats['total_requests']++;
        
        // 创建请求对象
        $request = [
            'endpoint' => $endpoint,
            'method' => $method,
            'data' => $data,
            'callback' => $callback,
            'timestamp' => microtime(true),
            'id' => uniqid('req_', true)
        ];
        
        // 添加到队列
        $this->pending_requests[] = $request;
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('请求加入合并队列: %s %s (队列大小: %d)', 
                    $method, $endpoint, count($this->pending_requests)),
                'API Merger'
            );
        }
        
        // 检查是否需要刷新批处理
        if ($this->should_flush()) {
            return $this->flush_batch();
        }
        
        return null;
    }
    
    /**
     * 检查是否应该刷新批处理
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @return bool 是否应该刷新
     */
    private function should_flush(): bool {
        $queue_size = count($this->pending_requests);
        $time_elapsed = (microtime(true) - $this->last_flush_time) * 1000; // 转换为毫秒
        
        // 队列达到最大大小
        if ($queue_size >= $this->max_batch_size) {
            return true;
        }
        
        // 超过批处理窗口时间且有请求
        if ($time_elapsed >= $this->batch_timeout && $queue_size > 0) {
            return true;
        }
        
        // 队列达到最小大小且时间超过一半窗口
        if ($queue_size >= $this->min_batch_size && $time_elapsed >= ($this->batch_timeout / 2)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 刷新批处理，执行合并的请求
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @return array 批处理结果
     */
    private function flush_batch(): array {
        if (empty($this->pending_requests)) {
            return [];
        }
        
        $batch_start_time = microtime(true);
        $original_count = count($this->pending_requests);
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('开始刷新批处理: %d个请求', $original_count),
                'API Merger'
            );
        }
        
        // 合并相似请求
        $merged_groups = $this->merge_similar_requests($this->pending_requests);
        $this->stats['merged_requests'] += $original_count - count($merged_groups);
        $this->stats['batch_count']++;
        
        // 执行批处理
        $results = $this->execute_merged_requests($merged_groups);
        
        // 清空队列并重置时间
        $this->pending_requests = [];
        $this->last_flush_time = microtime(true);
        
        // 更新统计
        $this->update_merge_ratio();
        
        $batch_duration = (microtime(true) - $batch_start_time) * 1000;
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('批处理完成: 原始%d个请求 → 合并为%d组，耗时%.2fms', 
                    $original_count, count($merged_groups), $batch_duration),
                'API Merger'
            );
        }
        
        return $results;
    }
    
    /**
     * 合并相似请求
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @param array $requests 请求数组
     * @return array 合并后的请求组
     */
    private function merge_similar_requests(array $requests): array {
        $groups = [];
        
        foreach ($requests as $request) {
            $group_key = $this->generate_group_key($request);
            
            if (!isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'method' => $request['method'],
                    'base_endpoint' => $this->extract_base_endpoint($request['endpoint']),
                    'requests' => []
                ];
            }
            
            $groups[$group_key]['requests'][] = $request;
        }
        
        return array_values($groups);
    }
    
    /**
     * 生成请求分组键
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @param array $request 请求对象
     * @return string 分组键
     */
    private function generate_group_key(array $request): string {
        $base_endpoint = $this->extract_base_endpoint($request['endpoint']);
        return md5($request['method'] . '|' . $base_endpoint);
    }
    
    /**
     * 提取基础端点（用于分组）
     * 
     * @since 2.0.0-beta.1
     * @access private
     * @param string $endpoint 完整端点
     * @return string 基础端点
     */
    private function extract_base_endpoint(string $endpoint): string {
        // 移除具体的ID，保留端点模式
        $patterns = [
            '/\/blocks\/[a-f0-9-]+\/children/' => '/blocks/{id}/children',
            '/\/pages\/[a-f0-9-]+/' => '/pages/{id}',
            '/\/databases\/[a-f0-9-]+\/query/' => '/databases/{id}/query',
            '/\/databases\/[a-f0-9-]+/' => '/databases/{id}'
        ];
        
        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $endpoint)) {
                return $replacement;
            }
        }
        
        return $endpoint;
    }

    /**
     * 执行合并后的请求组
     *
     * @since 2.0.0-beta.1
     * @access private
     * @param array $groups 合并后的请求组
     * @return array 执行结果
     */
    private function execute_merged_requests(array $groups): array {
        $all_results = [];

        foreach ($groups as $group) {
            $group_results = $this->execute_request_group($group);
            $all_results = array_merge($all_results, $group_results);
        }

        return $all_results;
    }

    /**
     * 执行单个请求组
     *
     * @since 2.0.0-beta.1
     * @access private
     * @param array $group 请求组
     * @return array 执行结果
     */
    private function execute_request_group(array $group): array {
        $requests = $group['requests'];
        $method = $group['method'];
        $results = [];

        if (count($requests) === 1) {
            // 单个请求直接执行
            $request = $requests[0];
            try {
                $result = $this->notion_api->send_request($request['endpoint'], $method, $request['data']);
                $results[$request['id']] = $result;

                // 执行回调
                if ($request['callback']) {
                    call_user_func($request['callback'], $result, null);
                }
            } catch (Exception $e) {
                $results[$request['id']] = new WP_Error('api_error', $e->getMessage());

                // 执行错误回调
                if ($request['callback']) {
                    call_user_func($request['callback'], null, $e);
                }
            }
        } else {
            // 多个请求使用批处理
            $endpoints = array_column($requests, 'endpoint');
            $data_array = array_column($requests, 'data');

            try {
                $batch_results = $this->notion_api->batch_send_requests($endpoints, $method, $data_array);

                // 分发结果到各个请求
                foreach ($requests as $index => $request) {
                    $result = $batch_results[$index] ?? new WP_Error('batch_error', '批处理结果缺失');
                    $results[$request['id']] = $result;

                    // 执行回调
                    if ($request['callback']) {
                        if (is_wp_error($result)) {
                            call_user_func($request['callback'], null, new Exception($result->get_error_message()));
                        } else {
                            call_user_func($request['callback'], $result, null);
                        }
                    }
                }
            } catch (Exception $e) {
                // 批处理失败，为所有请求返回错误
                foreach ($requests as $request) {
                    $results[$request['id']] = new WP_Error('batch_error', $e->getMessage());

                    if ($request['callback']) {
                        call_user_func($request['callback'], null, $e);
                    }
                }
            }
        }

        return $results;
    }

    /**
     * 更新合并比率统计
     *
     * @since 2.0.0-beta.1
     * @access private
     */
    private function update_merge_ratio(): void {
        if ($this->stats['total_requests'] > 0) {
            $this->stats['merge_ratio'] = ($this->stats['merged_requests'] / $this->stats['total_requests']) * 100;
        }
    }

    /**
     * 获取性能统计
     *
     * @since 2.0.0-beta.1
     * @return array 性能统计数据
     */
    public function get_stats(): array {
        return $this->stats;
    }

    /**
     * 重置性能统计
     *
     * @since 2.0.0-beta.1
     */
    public function reset_stats(): void {
        $this->stats = [
            'total_requests' => 0,
            'merged_requests' => 0,
            'batch_count' => 0,
            'merge_ratio' => 0
        ];
    }

    /**
     * 强制刷新当前队列
     *
     * @since 2.0.0-beta.1
     * @return array 刷新结果
     */
    public function force_flush(): array {
        return $this->flush_batch();
    }

    /**
     * 获取当前队列大小
     *
     * @since 2.0.0-beta.1
     * @return int 队列大小
     */
    public function get_queue_size(): int {
        return count($this->pending_requests);
    }

    /**
     * 检查是否有待处理的请求
     *
     * @since 2.0.0-beta.1
     * @return bool 是否有待处理请求
     */
    public function has_pending_requests(): bool {
        return !empty($this->pending_requests);
    }

    /**
     * 析构函数 - 确保所有请求都被处理
     *
     * @since 2.0.0-beta.1
     */
    public function __destruct() {
        if ($this->has_pending_requests()) {
            $this->force_flush();
        }
    }
}
