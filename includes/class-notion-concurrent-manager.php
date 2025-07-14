<?php

/**
 * Notion并发请求管理器
 *
 * 实现安全的并发API调用机制，支持智能速率限制和错误处理
 *
 * @link       https://github.com/frankloong/notion-to-wordpress
 * @since      1.8.1
 *
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/includes
 */

/**
 * Notion并发请求管理器类
 *
 * 提供并发API调用功能，包括：
 * - 请求池管理
 * - 智能速率限制
 * - 错误处理和重试机制
 * - 性能监控
 *
 * @since      1.8.1
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/includes
 * @author     Frank Loong <frankloong@gmail.com>
 */
class Notion_Concurrent_Manager {

    /**
     * 最大并发请求数
     *
     * @since    1.8.1
     * @access   private
     * @var      int
     */
    private int $max_concurrent = 8;

    /**
     * 请求池
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private array $request_pool = [];

    /**
     * API基础URL
     *
     * @since    1.8.1
     * @access   private
     * @var      string
     */
    private string $api_base = 'https://api.notion.com/v1/';

    /**
     * API密钥
     *
     * @since    1.8.1
     * @access   private
     * @var      string
     */
    private string $api_key;

    /**
     * 速率限制配置
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private array $rate_limit_config = [
        'requests_per_second' => 8,
        'burst_limit' => 20,
        'backoff_multiplier' => 1.5,
        'max_backoff' => 30
    ];

    /**
     * 请求统计
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private array $stats = [
        'total_requests' => 0,
        'successful_requests' => 0,
        'failed_requests' => 0,
        'retried_requests' => 0,
        'rate_limited_requests' => 0
    ];

    /**
     * 构造函数
     *
     * @since    1.8.1
     * @param    string    $api_key           API密钥
     * @param    int       $max_concurrent    最大并发数
     */
    public function __construct(string $api_key, int $max_concurrent = 15) {
        $this->api_key = $api_key;
        $this->max_concurrent = max(1, min(20, $max_concurrent)); // 限制在1-20之间

        // 从选项中获取配置
        $options = get_option('notion_to_wordpress_options', []);
        if (isset($options['concurrent_requests'])) {
            $this->max_concurrent = max(1, min(20, (int)$options['concurrent_requests']));
        }

        Notion_To_WordPress_Helper::debug_log(
            '并发管理器初始化，最大并发数: ' . $this->max_concurrent,
            'Concurrent Manager'
        );
    }

    /**
     * 添加请求到池中
     *
     * @since    1.8.1
     * @param    string    $request_id    请求ID（用于识别）
     * @param    string    $endpoint      API端点
     * @param    string    $method        HTTP方法
     * @param    array     $data          请求数据
     * @param    array     $context       上下文信息
     */
    public function add_request(string $request_id, string $endpoint, string $method = 'GET', array $data = [], array $context = []): void {
        $this->request_pool[$request_id] = [
            'endpoint' => $endpoint,
            'method' => $method,
            'data' => $data,
            'context' => $context,
            'retry_count' => 0,
            'added_time' => microtime(true)
        ];

        Notion_To_WordPress_Helper::debug_log(
            '添加请求到池: ' . $request_id . ' -> ' . $endpoint,
            'Concurrent Manager'
        );
    }

    /**
     * 执行批量并发请求
     *
     * @since    1.8.1
     * @return   array    请求结果数组
     */
    public function execute_batch(): array {
        if (empty($this->request_pool)) {
            return [];
        }

        $start_time = Notion_To_WordPress_Helper::start_performance_timer('concurrent_batch');
        $total_requests = count($this->request_pool);

        Notion_To_WordPress_Helper::debug_log(
            '开始执行并发批处理，总请求数: ' . $total_requests . ', 最大并发数: ' . $this->max_concurrent,
            'Concurrent Manager'
        );

        // 将请求分批处理
        $batches = array_chunk($this->request_pool, $this->max_concurrent, true);
        $all_results = [];

        foreach ($batches as $batch_index => $batch) {
            Notion_To_WordPress_Helper::debug_log(
                '处理批次 ' . ($batch_index + 1) . '/' . count($batches) . ', 请求数: ' . count($batch),
                'Concurrent Manager'
            );

            $batch_results = $this->execute_concurrent_batch($batch);
            $all_results = array_merge($all_results, $batch_results);

            // 批次间延迟，避免API速率限制
            if ($batch_index < count($batches) - 1) {
                $delay = $this->calculate_batch_delay();
                Notion_To_WordPress_Helper::debug_log(
                    '批次间延迟: ' . $delay . 'ms',
                    'Concurrent Manager'
                );
                usleep($delay * 1000);
            }
        }

        // 记录性能数据
        Notion_To_WordPress_Helper::end_performance_timer('concurrent_batch', $start_time, [
            'total_requests' => $total_requests,
            'successful_requests' => $this->stats['successful_requests'],
            'failed_requests' => $this->stats['failed_requests'],
            'batches_count' => count($batches),
            'max_concurrent' => $this->max_concurrent
        ]);

        // 清空请求池
        $this->request_pool = [];

        return $all_results;
    }

    /**
     * 执行单个并发批次
     *
     * @since    1.8.1
     * @param    array    $batch    批次请求数组
     * @return   array             批次结果数组
     */
    private function execute_concurrent_batch(array $batch): array {
        $requests = [];
        $request_mapping = [];

        // 准备并发请求
        foreach ($batch as $request_id => $request_data) {
            $url = $this->api_base . $request_data['endpoint'];
            $args = $this->prepare_request_args($request_data['method'], $request_data['data']);

            $requests[] = [
                'url' => $url,
                'args' => $args
            ];

            $request_mapping[] = $request_id;
        }

        // 执行并发请求
        $responses = $this->execute_concurrent_requests($requests);
        $results = [];

        // 处理响应
        foreach ($responses as $index => $response) {
            $request_id = $request_mapping[$index];
            $request_data = $batch[$request_id];

            $this->stats['total_requests']++;

            if (is_wp_error($response)) {
                $this->stats['failed_requests']++;
                $results[$request_id] = $this->handle_request_error($response, $request_data);
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                if ($response_code >= 200 && $response_code < 300) {
                    $this->stats['successful_requests']++;
                    $body = wp_remote_retrieve_body($response);
                    $results[$request_id] = json_decode($body, true) ?: [];
                } else {
                    $this->stats['failed_requests']++;
                    $results[$request_id] = $this->handle_http_error($response, $request_data);
                }
            }
        }

        return $results;
    }

    /**
     * 执行并发HTTP请求
     *
     * @since    1.8.1
     * @param    array    $requests    请求数组
     * @return   array                响应数组
     */
    private function execute_concurrent_requests(array $requests): array {
        // 使用WordPress的并发请求功能
        $responses = [];
        $multi_handle = curl_multi_init();
        $curl_handles = [];

        // 初始化所有cURL句柄
        foreach ($requests as $index => $request) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $request['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->api_key,
                    'Content-Type: application/json',
                    'Notion-Version: 2022-06-28'
                ]
            ]);

            if ($request['args']['method'] === 'POST' && !empty($request['args']['body'])) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request['args']['body']);
            }

            curl_multi_add_handle($multi_handle, $ch);
            $curl_handles[$index] = $ch;
        }

        // 执行并发请求
        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle);
        } while ($running > 0);

        // 收集响应
        foreach ($curl_handles as $index => $ch) {
            $response_body = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($error) {
                $responses[$index] = new WP_Error('curl_error', $error);
            } else {
                // 模拟WordPress HTTP响应格式
                $responses[$index] = [
                    'response' => ['code' => $http_code],
                    'body' => $response_body
                ];
            }

            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi_handle);

        return $responses;
    }

    /**
     * 准备请求参数
     *
     * @since    1.8.1
     * @param    string    $method    HTTP方法
     * @param    array     $data      请求数据
     * @return   array               WordPress HTTP请求参数
     */
    private function prepare_request_args(string $method, array $data = []): array {
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'Notion-Version' => '2022-06-28'
            ]
        ];

        if (!empty($data) && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }

        return $args;
    }

    /**
     * 处理请求错误
     *
     * @since    1.8.1
     * @param    WP_Error    $error         错误对象
     * @param    array       $request_data  请求数据
     * @return   array                      错误结果
     */
    private function handle_request_error(WP_Error $error, array $request_data): array {
        $error_code = $error->get_error_code();
        $error_message = $error->get_error_message();

        Notion_To_WordPress_Helper::error_log(
            '并发请求错误: ' . $error_code . ' - ' . $error_message,
            'Concurrent Manager'
        );

        // 根据错误类型决定是否重试
        if ($this->should_retry_error($error_code) && $request_data['retry_count'] < 3) {
            $this->stats['retried_requests']++;
            return $this->retry_request($request_data);
        }

        return [
            'error' => true,
            'error_code' => $error_code,
            'error_message' => $error_message
        ];
    }

    /**
     * 处理HTTP错误
     *
     * @since    1.8.1
     * @param    array    $response      HTTP响应
     * @param    array    $request_data  请求数据
     * @return   array                   错误结果
     */
    private function handle_http_error(array $response, array $request_data): array {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // 尝试解析错误信息
        $error_data = json_decode($response_body, true);
        $error_message = $error_data['message'] ?? $response_body;

        Notion_To_WordPress_Helper::error_log(
            '并发请求HTTP错误: ' . $response_code . ' - ' . $error_message,
            'Concurrent Manager'
        );

        // 处理速率限制
        if ($response_code === 429) {
            $this->stats['rate_limited_requests']++;
            return $this->handle_rate_limit($request_data, $response);
        }

        // 根据状态码决定是否重试
        if ($this->should_retry_http_code($response_code) && $request_data['retry_count'] < 3) {
            $this->stats['retried_requests']++;
            return $this->retry_request($request_data);
        }

        return [
            'error' => true,
            'error_code' => $response_code,
            'error_message' => $error_message
        ];
    }

    /**
     * 判断错误是否应该重试
     *
     * @since    1.8.1
     * @param    string    $error_code    错误代码
     * @return   bool                     是否应该重试
     */
    private function should_retry_error(string $error_code): bool {
        $retryable_errors = [
            'http_request_failed',
            'curl_error',
            'timeout',
            'network_timeout'
        ];

        return in_array($error_code, $retryable_errors);
    }

    /**
     * 判断HTTP状态码是否应该重试
     *
     * @since    1.8.1
     * @param    int    $http_code    HTTP状态码
     * @return   bool                 是否应该重试
     */
    private function should_retry_http_code(int $http_code): bool {
        $retryable_codes = [500, 502, 503, 504];
        return in_array($http_code, $retryable_codes);
    }

    /**
     * 重试请求
     *
     * @since    1.8.1
     * @param    array    $request_data    请求数据
     * @return   array                     重试结果
     */
    private function retry_request(array $request_data): array {
        $request_data['retry_count']++;
        $delay = $this->calculate_retry_delay($request_data['retry_count']);

        Notion_To_WordPress_Helper::debug_log(
            '重试请求 (第' . $request_data['retry_count'] . '次), 延迟: ' . $delay . 'ms',
            'Concurrent Manager'
        );

        usleep($delay * 1000);

        // 单独执行重试请求
        $url = $this->api_base . $request_data['endpoint'];
        $args = $this->prepare_request_args($request_data['method'], $request_data['data']);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $this->handle_request_error($response, $request_data);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code >= 200 && $response_code < 300) {
            $this->stats['successful_requests']++;
            $body = wp_remote_retrieve_body($response);
            return json_decode($body, true) ?: [];
        } else {
            return $this->handle_http_error($response, $request_data);
        }
    }

    /**
     * 处理速率限制
     *
     * @since    1.8.1
     * @param    array    $request_data    请求数据
     * @param    array    $response        HTTP响应
     * @return   array                     处理结果
     */
    private function handle_rate_limit(array $request_data, array $response): array {
        // 从响应头获取重试时间
        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
        $delay = $retry_after ? (int)$retry_after * 1000 : $this->rate_limit_config['max_backoff'] * 1000;

        Notion_To_WordPress_Helper::debug_log(
            '遇到速率限制，延迟: ' . $delay . 'ms',
            'Concurrent Manager'
        );

        if ($request_data['retry_count'] < 2) { // 速率限制最多重试2次
            sleep($delay / 1000); // 等待指定时间
            return $this->retry_request($request_data);
        }

        return [
            'error' => true,
            'error_code' => 429,
            'error_message' => '速率限制，请稍后重试'
        ];
    }

    /**
     * 计算重试延迟时间
     *
     * @since    1.8.1
     * @param    int    $retry_count    重试次数
     * @return   int                    延迟时间（毫秒）
     */
    private function calculate_retry_delay(int $retry_count): int {
        $base_delay = 1000; // 1秒基础延迟
        $delay = $base_delay * pow($this->rate_limit_config['backoff_multiplier'], $retry_count - 1);
        return min($delay, $this->rate_limit_config['max_backoff'] * 1000);
    }

    /**
     * 计算批次间延迟时间
     *
     * @since    1.8.1
     * @return   int    延迟时间（毫秒）
     */
    private function calculate_batch_delay(): int {
        $requests_per_second = $this->rate_limit_config['requests_per_second'];
        $delay_per_request = 1000 / $requests_per_second; // 毫秒
        return (int)($delay_per_request * $this->max_concurrent);
    }

    /**
     * 获取统计信息
     *
     * @since    1.8.1
     * @return   array    统计数据
     */
    public function get_stats(): array {
        $total = $this->stats['total_requests'];
        $success_rate = $total > 0 ? round(($this->stats['successful_requests'] / $total) * 100, 2) : 0;

        return array_merge($this->stats, [
            'success_rate' => $success_rate . '%',
            'max_concurrent' => $this->max_concurrent
        ]);
    }

    /**
     * 重置统计信息
     *
     * @since    1.8.1
     */
    public function reset_stats(): void {
        $this->stats = [
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'retried_requests' => 0,
            'rate_limited_requests' => 0
        ];
    }

    /**
     * 设置最大并发数
     *
     * @since    1.8.1
     * @param    int    $max_concurrent    最大并发数
     */
    public function set_max_concurrent(int $max_concurrent): void {
        $this->max_concurrent = max(1, min(10, $max_concurrent));
        Notion_To_WordPress_Helper::debug_log(
            '设置最大并发数: ' . $this->max_concurrent,
            'Concurrent Manager'
        );
    }

    /**
     * 获取请求池大小
     *
     * @since    1.8.1
     * @return   int    请求池大小
     */
    public function get_pool_size(): int {
        return count($this->request_pool);
    }

    /**
     * 清空请求池
     *
     * @since    1.8.1
     */
    public function clear_pool(): void {
        $this->request_pool = [];
        Notion_To_WordPress_Helper::debug_log(
            '清空请求池',
            'Concurrent Manager'
        );
    }
}
