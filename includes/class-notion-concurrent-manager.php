<?php

/**
 * 并发请求管理器类。
 * 实现安全的并发 API 调用机制，支持智能速率限制、错误处理、重试机制和性能监控。
 * @since      1.8.1
 * @version    1.8.3-beta.1
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/includes
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */
class Notion_Concurrent_Manager {

    /**
     * 最大并发请求数
     *
     * @since    1.8.1
     * @access   private
     * @var      int
     */
    private int $max_concurrent = 25;

    /**
     * 当前并发请求数
     *
     * @since    1.8.3
     * @access   private
     * @var      int
     */
    private int $current_concurrent = 0;

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
        'rate_limited_requests' => 0,
        'average_response_time' => 0,
        'total_response_time' => 0,
        'network_quality_score' => 100
    ];

    /**
     * 自适应并发配置
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private array $adaptive_config = [
        'min_concurrent' => 5,
        'max_concurrent' => 30,
        'target_response_time' => 2000, // 目标响应时间2秒
        'adjustment_threshold' => 0.1,  // 调整阈值10%
        'quality_check_interval' => 10  // 每10个请求检查一次网络质量
    ];

    /**
     * 网络质量监控
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private array $network_monitor = [
        'last_check_time' => 0,
        'response_times' => [],
        'error_rates' => [],
        'last_adjustment_time' => 0
    ];

    /**
     * 图片下载队列
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private array $image_download_queue = [];

    /**
     * 图片下载统计
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private array $image_download_stats = [
        'total_images' => 0,
        'downloaded_images' => 0,
        'failed_images' => 0,
        'cached_images' => 0,
        'total_download_time' => 0,
        'average_download_time' => 0
    ];

    /**
     * 网络连接配置
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private array $network_config = [
        'base_timeout' => 5,           // 基础超时时间（秒）- 减少到5秒
        'base_connect_timeout' => 2,   // 基础连接超时时间（秒）- 减少到2秒
        'max_timeout' => 10,           // 最大超时时间（秒）- 减少到10秒
        'min_timeout' => 3,            // 最小超时时间（秒）- 减少到3秒
        'enable_keepalive' => true,    // 启用TCP Keep-Alive
        'enable_compression' => true,  // 启用压缩
        'max_redirects' => 2,          // 最大重定向次数 - 减少到2次
        'retry_attempts' => 1,         // 重试次数 - 减少到1次
        'adaptive_timeout' => false    // 禁用自适应超时以提升速度
    ];

    /**
     * 网络性能统计
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private array $network_stats = [
        'total_connect_time' => 0,
        'total_transfer_time' => 0,
        'avg_connect_time' => 0,
        'avg_transfer_time' => 0,
        'connection_failures' => 0,
        'timeout_failures' => 0,
        'dns_lookup_time' => 0,
        'ssl_handshake_time' => 0,
        'network_quality_score' => 100
    ];

    /**
     * 构造函数
     *
     * @since    1.8.1
     * @param    string    $api_key           API密钥
     * @param    int       $max_concurrent    最大并发数
     */
    public function __construct(string $api_key, int $max_concurrent = 25) {
        $this->api_key = $api_key;
        $this->max_concurrent = max($this->adaptive_config['min_concurrent'],
                                   min($this->adaptive_config['max_concurrent'], $max_concurrent));

        // 从选项中获取配置
        $options = get_option('notion_to_wordpress_options', []);
        if (isset($options['concurrent_requests'])) {
            $this->max_concurrent = max($this->adaptive_config['min_concurrent'],
                                       min($this->adaptive_config['max_concurrent'], (int)$options['concurrent_requests']));
        }

        // 初始化当前并发数
        $this->current_concurrent = 0;

        // 初始化网络监控
        $this->network_monitor['last_check_time'] = time();
        $this->network_monitor['last_adjustment_time'] = time();

        // 禁用初始化日志以提升速度
        // Notion_To_WordPress_Helper::debug_log(
        //     '并发管理器初始化，最大并发数: ' . $this->max_concurrent . ' (范围: ' .
        //     $this->adaptive_config['min_concurrent'] . '-' . $this->adaptive_config['max_concurrent'] . ')',
        //     'Concurrent Manager'
        // );
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

        // 禁用请求添加日志以提升速度
        // Notion_To_WordPress_Helper::debug_log(
        //     '添加请求到池: ' . $request_id . ' -> ' . $endpoint,
        //     'Concurrent Manager'
        // );
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

            // 禁用批次间延迟以提升速度
            // if ($batch_index < count($batches) - 1) {
            //     $delay = $this->calculate_batch_delay();
            //     Notion_To_WordPress_Helper::debug_log(
            //         '批次间延迟: ' . $delay . 'ms',
            //         'Concurrent Manager'
            //     );
            //     usleep($delay * 1000);
            // }
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
        $batch_start_time = microtime(true);
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
        $batch_end_time = microtime(true);
        $batch_response_time = ($batch_end_time - $batch_start_time) * 1000; // 转换为毫秒

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

        // 更新响应时间统计
        $this->update_response_time_stats($batch_response_time, count($batch));

        // 检查是否需要调整并发数
        $this->check_and_adjust_concurrent();

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
        // 更新当前并发数
        $this->current_concurrent = count($requests);

        // 使用WordPress的并发请求功能
        $responses = [];
        $multi_handle = curl_multi_init();
        $curl_handles = [];

        // 初始化所有cURL句柄（优化版本）
        foreach ($requests as $index => $request) {
            $ch = curl_init();

            // 计算自适应超时时间
            $timeout_config = $this->calculate_adaptive_timeout();

            // 优化的cURL配置
            $curl_options = [
                CURLOPT_URL => $request['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout_config['timeout'],
                CURLOPT_CONNECTTIMEOUT => $timeout_config['connect_timeout'],
                CURLOPT_HTTPHEADER => $this->build_optimized_headers(),

                // 连接复用和性能优化
                CURLOPT_TCP_KEEPALIVE => $this->network_config['enable_keepalive'] ? 1 : 0,
                CURLOPT_TCP_KEEPIDLE => 60,
                CURLOPT_TCP_KEEPINTVL => 30,

                // 压缩支持
                CURLOPT_ENCODING => $this->network_config['enable_compression'] ? '' : null,

                // 重定向和安全设置
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => $this->network_config['max_redirects'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,

                // 性能监控
                CURLOPT_VERBOSE => false,
                CURLOPT_NOPROGRESS => true,

                // User-Agent优化
                CURLOPT_USERAGENT => $this->get_optimized_user_agent(),

                // DNS优化配置
                CURLOPT_DNS_SERVERS => '8.8.8.8,1.1.1.1', // 使用Google和Cloudflare DNS
                CURLOPT_DNS_CACHE_TIMEOUT => 300, // 5分钟DNS缓存

                // 连接池优化
                CURLOPT_FRESH_CONNECT => false,
                CURLOPT_FORBID_REUSE => false
            ];

            // 移除空值选项
            $curl_options = array_filter($curl_options, function($value) {
                return $value !== null;
            });

            curl_setopt_array($ch, $curl_options);

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

        // 收集响应（增强版本）
        foreach ($curl_handles as $index => $ch) {
            $response_body = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            // 收集网络性能数据
            $network_info = $this->collect_network_performance_data($ch);
            $this->update_network_stats($network_info, $error);

            if ($error) {
                // 处理网络错误
                $this->handle_network_error($error, $network_info);
                $responses[$index] = new WP_Error('curl_error', $error);
            } else {
                // 模拟WordPress HTTP响应格式
                $responses[$index] = [
                    'response' => ['code' => $http_code],
                    'body' => $response_body,
                    'network_info' => $network_info // 添加网络信息
                ];
            }

            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi_handle);

        // 重置当前并发数
        $this->current_concurrent = 0;

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
     * 计算批次间延迟时间（基于网络性能自适应）
     *
     * @since    1.8.1
     * @return   int    延迟时间（毫秒）
     */
    private function calculate_batch_delay(): int {
        $base_requests_per_second = $this->rate_limit_config['requests_per_second'];

        // 根据网络质量调整请求频率
        $quality_factor = $this->stats['network_quality_score'] / 100;
        $adjusted_requests_per_second = $base_requests_per_second * $quality_factor;

        // 基础延迟计算
        $delay_per_request = 1000 / max(1, $adjusted_requests_per_second); // 毫秒
        $base_delay = (int)($delay_per_request * $this->max_concurrent);

        // 根据错误率调整延迟
        $error_rate = $this->calculate_current_error_rate();
        if ($error_rate > 0.1) { // 错误率超过10%
            $base_delay *= (1 + $error_rate); // 增加延迟
        }

        // 根据平均响应时间调整
        if ($this->stats['average_response_time'] > $this->adaptive_config['target_response_time']) {
            $response_factor = $this->stats['average_response_time'] / $this->adaptive_config['target_response_time'];
            $base_delay *= min(2.0, $response_factor); // 最多增加一倍延迟
        }

        // 确保延迟在合理范围内
        return max(100, min(5000, (int)$base_delay)); // 100ms到5秒之间
    }

    /**
     * 更新响应时间统计
     *
     * @since    1.8.1
     * @param    float    $batch_response_time    批次响应时间（毫秒）
     * @param    int      $request_count          请求数量
     */
    private function update_response_time_stats(float $batch_response_time, int $request_count): void {
        // 计算平均单个请求响应时间
        $avg_request_time = $request_count > 0 ? $batch_response_time / $request_count : $batch_response_time;

        // 更新总响应时间
        $this->stats['total_response_time'] += $avg_request_time;

        // 计算平均响应时间
        if ($this->stats['total_requests'] > 0) {
            $this->stats['average_response_time'] = $this->stats['total_response_time'] / $this->stats['total_requests'];
        }

        // 记录到网络监控数组（保留最近20个响应时间）
        $this->network_monitor['response_times'][] = $avg_request_time;
        if (count($this->network_monitor['response_times']) > 20) {
            array_shift($this->network_monitor['response_times']);
        }

        // 禁用性能监控日志以提升速度
        // Notion_To_WordPress_Helper::debug_log(
        //     sprintf('响应时间统计更新 - 批次: %.2fms, 平均单请求: %.2fms, 总平均: %.2fms',
        //            $batch_response_time, $avg_request_time, $this->stats['average_response_time']),
        //     'Performance Monitor'
        // );
    }

    /**
     * 检查并调整并发数
     *
     * @since    1.8.1
     */
    private function check_and_adjust_concurrent(): void {
        $current_time = time();

        // 禁用频繁的网络质量检查以提升速度
        // 改为每100个请求或每300秒检查一次
        if ($this->stats['total_requests'] % 100 !== 0 &&
            $current_time - $this->network_monitor['last_adjustment_time'] < 300) {
            return;
        }

        $this->network_monitor['last_adjustment_time'] = $current_time;

        // 计算网络质量分数
        $this->calculate_network_quality();

        // 根据网络质量调整并发数
        $this->adjust_concurrent_based_on_performance();
    }

    /**
     * 计算当前错误率
     *
     * @since    1.8.1
     * @return   float    错误率（0-1之间）
     */
    private function calculate_current_error_rate(): float {
        if ($this->stats['total_requests'] === 0) {
            return 0;
        }
        return $this->stats['failed_requests'] / $this->stats['total_requests'];
    }

    /**
     * 计算网络质量分数
     *
     * @since    1.8.1
     */
    private function calculate_network_quality(): void {
        $quality_score = 100;

        // 基于错误率调整质量分数
        $error_rate = $this->calculate_current_error_rate();
        $quality_score -= $error_rate * 50; // 错误率每增加1%，质量分数减少0.5分

        // 基于响应时间调整质量分数
        if ($this->stats['average_response_time'] > 0) {
            $response_factor = $this->stats['average_response_time'] / $this->adaptive_config['target_response_time'];
            if ($response_factor > 1) {
                $quality_score -= min(30, ($response_factor - 1) * 20); // 响应时间超标，最多减30分
            }
        }

        // 基于速率限制调整质量分数
        if ($this->stats['total_requests'] > 0) {
            $rate_limit_ratio = $this->stats['rate_limited_requests'] / $this->stats['total_requests'];
            $quality_score -= $rate_limit_ratio * 40; // 速率限制比例每增加1%，减少0.4分
        }

        // 确保质量分数在0-100之间
        $this->stats['network_quality_score'] = max(0, min(100, $quality_score));

        Notion_To_WordPress_Helper::debug_log(
            sprintf('网络质量评估 - 分数: %.1f, 错误率: %.2f%%, 平均响应时间: %.2fms',
                   $this->stats['network_quality_score'], $error_rate * 100, $this->stats['average_response_time']),
            'Network Quality'
        );
    }

    /**
     * 基于性能调整并发数
     *
     * @since    1.8.1
     */
    private function adjust_concurrent_based_on_performance(): void {
        $old_concurrent = $this->max_concurrent;
        $quality_score = $this->stats['network_quality_score'];

        // 根据网络质量调整并发数
        if ($quality_score >= 80) {
            // 网络质量良好，可以适当增加并发数
            $new_concurrent = min($this->adaptive_config['max_concurrent'],
                                 (int)($this->max_concurrent * 1.1));
        } elseif ($quality_score >= 60) {
            // 网络质量一般，保持当前并发数
            $new_concurrent = $this->max_concurrent;
        } else {
            // 网络质量较差，减少并发数
            $new_concurrent = max($this->adaptive_config['min_concurrent'],
                                 (int)($this->max_concurrent * 0.8));
        }

        // 应用调整
        if ($new_concurrent !== $old_concurrent) {
            $this->max_concurrent = $new_concurrent;

            Notion_To_WordPress_Helper::info_log(
                sprintf('自适应并发调整 - 从 %d 调整到 %d (网络质量: %.1f)',
                       $old_concurrent, $new_concurrent, $quality_score),
                'Adaptive Concurrent'
            );
        }
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
            'success_rate' => $success_rate,  // 返回数字而不是字符串
            'success_rate_formatted' => $success_rate . '%',  // 格式化版本
            'max_concurrent' => $this->max_concurrent,
            'current_concurrent' => count($this->request_pool),  // 使用实际的当前并发数
            'network_quality' => $this->stats['network_quality_score'],
            'avg_response_time_ms' => round($this->stats['average_response_time'], 2)
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
            'rate_limited_requests' => 0,
            'average_response_time' => 0,
            'total_response_time' => 0,
            'network_quality_score' => 100
        ];

        // 重置网络监控数据
        $this->network_monitor = [
            'last_check_time' => time(),
            'response_times' => [],
            'error_rates' => [],
            'last_adjustment_time' => time()
        ];
    }

    /**
     * 设置最大并发数
     *
     * @since    1.8.1
     * @param    int    $max_concurrent    最大并发数
     */
    public function set_max_concurrent(int $max_concurrent): void {
        $this->max_concurrent = max($this->adaptive_config['min_concurrent'],
                                   min($this->adaptive_config['max_concurrent'], $max_concurrent));
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

    /**
     * 添加图片下载请求到队列
     *
     * @since    1.8.1
     * @param    string    $image_url     图片URL
     * @param    string    $caption       图片标题
     * @param    string    $request_id    请求ID（可选）
     * @return   string                   请求ID
     */
    public function add_image_download_request(string $image_url, string $caption = '', string $request_id = ''): string {
        if (empty($request_id)) {
            $request_id = 'image_' . md5($image_url . time());
        }

        $this->image_download_queue[$request_id] = [
            'url' => $image_url,
            'caption' => $caption,
            'status' => 'pending',
            'created_at' => time(),
            'attempts' => 0,
            'max_attempts' => 3,
            'attachment_id' => null,
            'error_message' => null
        ];

        $this->image_download_stats['total_images']++;

        Notion_To_WordPress_Helper::debug_log(
            sprintf('图片下载请求已添加到队列：%s (ID: %s)', $image_url, $request_id),
            'Image Download Queue'
        );

        return $request_id;
    }

    /**
     * 批量添加图片下载请求
     *
     * @since    1.8.1
     * @param    array    $images    图片数组，格式：[['url' => '', 'caption' => ''], ...]
     * @return   array              请求ID数组
     */
    public function add_batch_image_download_requests(array $images): array {
        $request_ids = [];

        foreach ($images as $image) {
            if (isset($image['url']) && !empty($image['url'])) {
                $caption = $image['caption'] ?? '';
                $request_id = $this->add_image_download_request($image['url'], $caption);
                $request_ids[] = $request_id;
            }
        }

        Notion_To_WordPress_Helper::info_log(
            sprintf('批量添加%d个图片下载请求到队列', count($request_ids)),
            'Image Download Queue'
        );

        return $request_ids;
    }

    /**
     * 执行图片下载队列
     *
     * @since    1.8.1
     * @param    int      $max_concurrent    最大并发数（可选）
     * @return   array                      下载结果数组
     */
    public function execute_image_downloads(int $max_concurrent = 0): array {
        if (empty($this->image_download_queue)) {
            return [];
        }

        $start_time = Notion_To_WordPress_Helper::start_performance_timer('execute_image_downloads');

        // 使用指定的并发数或默认并发数
        $concurrent_limit = $max_concurrent > 0 ? $max_concurrent : min($this->max_concurrent, 10);

        // 过滤出待处理的图片
        $pending_images = array_filter($this->image_download_queue, function($item) {
            return $item['status'] === 'pending' && $item['attempts'] < $item['max_attempts'];
        });

        if (empty($pending_images)) {
            Notion_To_WordPress_Helper::debug_log('没有待处理的图片下载请求', 'Image Download Queue');
            return [];
        }

        Notion_To_WordPress_Helper::info_log(
            sprintf('开始执行图片下载队列：%d个图片，并发数：%d', count($pending_images), $concurrent_limit),
            'Image Download Queue'
        );

        $results = [];
        $batches = array_chunk($pending_images, $concurrent_limit, true);

        foreach ($batches as $batch_index => $batch) {
            Notion_To_WordPress_Helper::debug_log(
                sprintf('执行图片下载批次 %d/%d，包含%d个图片',
                       $batch_index + 1, count($batches), count($batch)),
                'Image Download Batch'
            );

            $batch_results = $this->execute_image_download_batch($batch);
            $results = array_merge($results, $batch_results);

            // 禁用批次间延迟以提升速度
            // if ($batch_index < count($batches) - 1) {
            //     $delay = $this->calculate_batch_delay();
            //     usleep($delay * 1000); // 转换为微秒
            // }
        }

        $execution_time = Notion_To_WordPress_Helper::end_performance_timer($start_time, 'execute_image_downloads');

        // 更新统计信息
        $this->update_image_download_stats();

        Notion_To_WordPress_Helper::info_log(
            sprintf('图片下载队列执行完成：处理%d个图片，成功%d个，失败%d个，执行时间%.2fms',
                   count($pending_images), $this->image_download_stats['downloaded_images'],
                   $this->image_download_stats['failed_images'], $execution_time),
            'Image Download Queue'
        );

        return $results;
    }

    /**
     * 执行单个图片下载批次
     *
     * @since    1.8.1
     * @param    array    $batch    批次图片数组
     * @return   array             批次结果数组
     */
    private function execute_image_download_batch(array $batch): array {
        $batch_start_time = microtime(true);
        $results = [];

        // 准备并发下载请求
        $download_requests = [];
        $request_mapping = [];

        foreach ($batch as $request_id => $image_data) {
            // 更新状态为处理中
            $this->image_download_queue[$request_id]['status'] = 'processing';
            $this->image_download_queue[$request_id]['attempts']++;

            // 检查是否已存在
            $existing_attachment = $this->check_existing_image($image_data['url']);
            if ($existing_attachment) {
                $this->image_download_queue[$request_id]['status'] = 'completed';
                $this->image_download_queue[$request_id]['attachment_id'] = $existing_attachment;
                $this->image_download_stats['cached_images']++;

                $results[$request_id] = [
                    'success' => true,
                    'attachment_id' => $existing_attachment,
                    'cached' => true
                ];
                continue;
            }

            // 准备下载请求
            $download_requests[] = [
                'url' => $image_data['url'],
                'args' => [
                    'timeout' => 30,
                    'headers' => [
                        'User-Agent' => 'WordPress/Notion-to-WordPress Plugin'
                    ]
                ]
            ];

            $request_mapping[] = $request_id;
        }

        // 执行并发下载
        if (!empty($download_requests)) {
            $download_responses = $this->execute_concurrent_requests($download_requests);

            // 处理下载结果
            foreach ($download_responses as $index => $response) {
                $request_id = $request_mapping[$index];
                $image_data = $batch[$request_id];

                $download_result = $this->process_image_download_response($request_id, $response, $image_data);
                $results[$request_id] = $download_result;
            }
        }

        $batch_time = (microtime(true) - $batch_start_time) * 1000;

        Notion_To_WordPress_Helper::debug_log(
            sprintf('图片下载批次完成：处理%d个图片，执行时间%.2fms', count($batch), $batch_time),
            'Image Download Batch'
        );

        return $results;
    }

    /**
     * 检查图片是否已存在
     *
     * @since    1.8.1
     * @param    string    $image_url    图片URL
     * @return   int|null               存在的附件ID或null
     */
    private function check_existing_image(string $image_url): ?int {
        // 去掉查询参数用于去重
        $base_url = strtok($image_url, '?');

        // 查询是否已存在
        $posts = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_notion_base_url',
                    'value' => esc_url($base_url),
                    'compare' => '='
                ]
            ],
            'fields' => 'ids'
        ]);

        return !empty($posts) ? $posts[0] : null;
    }

    /**
     * 处理图片下载响应
     *
     * @since    1.8.1
     * @param    string    $request_id     请求ID
     * @param    mixed     $response       下载响应
     * @param    array     $image_data     图片数据
     * @return   array                     处理结果
     */
    private function process_image_download_response(string $request_id, $response, array $image_data): array {
        $download_start_time = microtime(true);

        if (is_wp_error($response)) {
            $this->image_download_queue[$request_id]['status'] = 'failed';
            $this->image_download_queue[$request_id]['error_message'] = $response->get_error_message();
            $this->image_download_stats['failed_images']++;

            return [
                'success' => false,
                'error' => $response->get_error_message(),
                'cached' => false
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            $error_message = sprintf('HTTP错误：%d', $response_code);
            $this->image_download_queue[$request_id]['status'] = 'failed';
            $this->image_download_queue[$request_id]['error_message'] = $error_message;
            $this->image_download_stats['failed_images']++;

            return [
                'success' => false,
                'error' => $error_message,
                'cached' => false
            ];
        }

        // 获取图片数据
        $image_content = wp_remote_retrieve_body($response);
        if (empty($image_content)) {
            $error_message = '图片内容为空';
            $this->image_download_queue[$request_id]['status'] = 'failed';
            $this->image_download_queue[$request_id]['error_message'] = $error_message;
            $this->image_download_stats['failed_images']++;

            return [
                'success' => false,
                'error' => $error_message,
                'cached' => false
            ];
        }

        // 保存图片到媒体库
        $attachment_id = $this->save_image_to_media_library($image_content, $image_data);

        if (is_wp_error($attachment_id)) {
            $this->image_download_queue[$request_id]['status'] = 'failed';
            $this->image_download_queue[$request_id]['error_message'] = $attachment_id->get_error_message();
            $this->image_download_stats['failed_images']++;

            return [
                'success' => false,
                'error' => $attachment_id->get_error_message(),
                'cached' => false
            ];
        }

        // 成功
        $this->image_download_queue[$request_id]['status'] = 'completed';
        $this->image_download_queue[$request_id]['attachment_id'] = $attachment_id;
        $this->image_download_stats['downloaded_images']++;

        $download_time = (microtime(true) - $download_start_time) * 1000;
        $this->image_download_stats['total_download_time'] += $download_time;

        return [
            'success' => true,
            'attachment_id' => $attachment_id,
            'cached' => false,
            'download_time' => $download_time
        ];
    }

    /**
     * 保存图片到媒体库
     *
     * @since    1.8.1
     * @param    string    $image_content    图片内容
     * @param    array     $image_data       图片数据
     * @return   int|WP_Error              附件ID或错误
     */
    private function save_image_to_media_library(string $image_content, array $image_data) {
        // 引入必要的WordPress文件
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // 创建临时文件
        $temp_file = wp_tempnam();
        if (!$temp_file) {
            return new WP_Error('temp_file_failed', '无法创建临时文件');
        }

        // 写入图片内容
        $bytes_written = file_put_contents($temp_file, $image_content);
        if ($bytes_written === false) {
            unlink($temp_file);
            return new WP_Error('write_failed', '无法写入临时文件');
        }

        // 获取文件名
        $file_name = basename(parse_url($image_data['url'], PHP_URL_PATH));
        if (!$file_name) {
            $file_name = 'notion-image-' . time() . '.jpg';
        }

        // 准备文件数组
        $file = [
            'name' => $file_name,
            'tmp_name' => $temp_file,
        ];

        // 保存到媒体库
        $attachment_id = media_handle_sideload($file, 0, $image_data['caption']);

        if (is_wp_error($attachment_id)) {
            unlink($temp_file);
            return $attachment_id;
        }

        // 存储源URL方便后续去重
        $base_url = strtok($image_data['url'], '?');
        update_post_meta($attachment_id, '_notion_original_url', esc_url_raw($image_data['url']));
        update_post_meta($attachment_id, '_notion_base_url', esc_url_raw($base_url));

        return $attachment_id;
    }

    /**
     * 更新图片下载统计
     *
     * @since    1.8.1
     */
    private function update_image_download_stats(): void {
        if ($this->image_download_stats['downloaded_images'] > 0) {
            $this->image_download_stats['average_download_time'] =
                $this->image_download_stats['total_download_time'] / $this->image_download_stats['downloaded_images'];
        }
    }

    /**
     * 获取图片下载队列状态
     *
     * @since    1.8.1
     * @return   array    队列状态信息
     */
    public function get_image_download_queue_status(): array {
        $pending = 0;
        $processing = 0;
        $completed = 0;
        $failed = 0;

        foreach ($this->image_download_queue as $item) {
            switch ($item['status']) {
                case 'pending':
                    $pending++;
                    break;
                case 'processing':
                    $processing++;
                    break;
                case 'completed':
                    $completed++;
                    break;
                case 'failed':
                    $failed++;
                    break;
            }
        }

        return [
            'total_queue_size' => count($this->image_download_queue),
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'failed' => $failed,
            'stats' => $this->image_download_stats
        ];
    }

    /**
     * 清空图片下载队列
     *
     * @since    1.8.1
     */
    public function clear_image_download_queue(): void {
        $this->image_download_queue = [];
        $this->image_download_stats = [
            'total_images' => 0,
            'downloaded_images' => 0,
            'failed_images' => 0,
            'cached_images' => 0,
            'total_download_time' => 0,
            'average_download_time' => 0
        ];

        Notion_To_WordPress_Helper::debug_log(
            '图片下载队列已清空',
            'Image Download Queue'
        );
    }

    /**
     * 获取指定请求的下载结果
     *
     * @since    1.8.1
     * @param    string    $request_id    请求ID
     * @return   array|null              下载结果或null
     */
    public function get_image_download_result(string $request_id): ?array {
        if (!isset($this->image_download_queue[$request_id])) {
            return null;
        }

        $item = $this->image_download_queue[$request_id];

        return [
            'request_id' => $request_id,
            'url' => $item['url'],
            'caption' => $item['caption'],
            'status' => $item['status'],
            'attachment_id' => $item['attachment_id'],
            'error_message' => $item['error_message'],
            'attempts' => $item['attempts'],
            'created_at' => $item['created_at']
        ];
    }

    /**
     * 计算自适应超时时间
     *
     * @since    1.8.1
     * @return   array    超时配置
     */
    private function calculate_adaptive_timeout(): array {
        if (!$this->network_config['adaptive_timeout']) {
            return [
                'timeout' => $this->network_config['base_timeout'],
                'connect_timeout' => $this->network_config['base_connect_timeout']
            ];
        }

        // 基于网络质量调整超时时间
        $quality_score = $this->network_stats['network_quality_score'];
        $base_timeout = $this->network_config['base_timeout'];
        $base_connect_timeout = $this->network_config['base_connect_timeout'];

        // 网络质量越差，超时时间越长
        if ($quality_score < 50) {
            $timeout_multiplier = 2.0;
        } elseif ($quality_score < 70) {
            $timeout_multiplier = 1.5;
        } elseif ($quality_score < 90) {
            $timeout_multiplier = 1.2;
        } else {
            $timeout_multiplier = 1.0;
        }

        $adaptive_timeout = min(
            $this->network_config['max_timeout'],
            max($this->network_config['min_timeout'], (int)($base_timeout * $timeout_multiplier))
        );

        $adaptive_connect_timeout = min(
            $adaptive_timeout / 2,
            max(2, (int)($base_connect_timeout * $timeout_multiplier))
        );

        return [
            'timeout' => $adaptive_timeout,
            'connect_timeout' => $adaptive_connect_timeout
        ];
    }

    /**
     * 构建优化的HTTP头
     *
     * @since    1.8.1
     * @return   array    HTTP头数组
     */
    private function build_optimized_headers(): array {
        $headers = [
            'Authorization: Bearer ' . $this->api_key,
            'Content-Type: application/json',
            'Notion-Version: 2022-06-28'
        ];

        // 添加Keep-Alive支持
        if ($this->network_config['enable_keepalive']) {
            $headers[] = 'Connection: keep-alive';
            $headers[] = 'Keep-Alive: timeout=60, max=100';
        }

        // 添加压缩支持
        if ($this->network_config['enable_compression']) {
            $headers[] = 'Accept-Encoding: gzip, deflate, br';
        }

        // 添加缓存控制
        $headers[] = 'Cache-Control: no-cache';
        $headers[] = 'Pragma: no-cache';

        return $headers;
    }

    /**
     * 获取优化的User-Agent
     *
     * @since    1.8.1
     * @return   string    User-Agent字符串
     */
    private function get_optimized_user_agent(): string {
        $wp_version = get_bloginfo('version');
        $plugin_version = '1.8.1'; // 插件版本

        return sprintf(
            'WordPress/%s Notion-to-WordPress/%s (PHP/%s; %s)',
            $wp_version,
            $plugin_version,
            PHP_VERSION,
            php_uname('s')
        );
    }

    /**
     * 收集网络性能数据
     *
     * @since    1.8.1
     * @param    resource    $ch    cURL句柄
     * @return   array              网络性能数据
     */
    private function collect_network_performance_data($ch): array {
        return [
            'total_time' => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
            'connect_time' => curl_getinfo($ch, CURLINFO_CONNECT_TIME),
            'namelookup_time' => curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME),
            'pretransfer_time' => curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME),
            'starttransfer_time' => curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME),
            'redirect_time' => curl_getinfo($ch, CURLINFO_REDIRECT_TIME),
            'size_download' => curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD),
            'size_upload' => curl_getinfo($ch, CURLINFO_SIZE_UPLOAD),
            'speed_download' => curl_getinfo($ch, CURLINFO_SPEED_DOWNLOAD),
            'speed_upload' => curl_getinfo($ch, CURLINFO_SPEED_UPLOAD),
            'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'ssl_verify_result' => curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT)
        ];
    }

    /**
     * 更新网络统计
     *
     * @since    1.8.1
     * @param    array     $network_info    网络性能数据
     * @param    string    $error          错误信息
     */
    private function update_network_stats(array $network_info, string $error = ''): void {
        // 更新连接时间统计
        if (isset($network_info['connect_time'])) {
            $this->network_stats['total_connect_time'] += $network_info['connect_time'];
        }

        // 更新传输时间统计
        if (isset($network_info['total_time'])) {
            $this->network_stats['total_transfer_time'] += $network_info['total_time'];
        }

        // 更新DNS查询时间
        if (isset($network_info['namelookup_time'])) {
            $this->network_stats['dns_lookup_time'] += $network_info['namelookup_time'];
        }

        // 更新SSL握手时间
        if (isset($network_info['pretransfer_time']) && isset($network_info['connect_time'])) {
            $ssl_time = $network_info['pretransfer_time'] - $network_info['connect_time'];
            $this->network_stats['ssl_handshake_time'] += max(0, $ssl_time);
        }

        // 处理错误统计
        if (!empty($error)) {
            if (strpos($error, 'timeout') !== false || strpos($error, 'Timeout') !== false) {
                $this->network_stats['timeout_failures']++;
            } else {
                $this->network_stats['connection_failures']++;
            }
        }

        // 计算平均值
        $total_requests = $this->stats['total_requests'];
        if ($total_requests > 0) {
            $this->network_stats['avg_connect_time'] = $this->network_stats['total_connect_time'] / $total_requests;
            $this->network_stats['avg_transfer_time'] = $this->network_stats['total_transfer_time'] / $total_requests;
        }

        // 更新网络质量评分
        $this->update_network_quality_score();
    }

    /**
     * 处理网络错误
     *
     * @since    1.8.1
     * @param    string    $error         错误信息
     * @param    array     $network_info  网络信息
     */
    private function handle_network_error(string $error, array $network_info): void {
        Notion_To_WordPress_Helper::error_log(
            sprintf('网络请求错误: %s, 连接时间: %.3fs, 总时间: %.3fs',
                   $error,
                   $network_info['connect_time'] ?? 0,
                   $network_info['total_time'] ?? 0),
            'Network Error'
        );

        // 根据错误类型调整网络配置
        if (strpos($error, 'timeout') !== false) {
            $this->adjust_timeout_on_error();
        } elseif (strpos($error, 'connect') !== false) {
            $this->adjust_connection_on_error();
        }
    }

    /**
     * 更新网络质量评分
     *
     * @since    1.8.1
     */
    private function update_network_quality_score(): void {
        $total_requests = $this->stats['total_requests'];
        if ($total_requests === 0) {
            return;
        }

        // 基础评分
        $base_score = 100;

        // 根据失败率扣分
        $failure_rate = ($this->stats['failed_requests'] / $total_requests) * 100;
        $base_score -= $failure_rate * 2; // 每1%失败率扣2分

        // 根据平均响应时间扣分
        $avg_response_time = $this->network_stats['avg_transfer_time'];
        if ($avg_response_time > 2.0) { // 超过2秒
            $base_score -= ($avg_response_time - 2.0) * 10; // 每超过1秒扣10分
        }

        // 根据连接时间扣分
        $avg_connect_time = $this->network_stats['avg_connect_time'];
        if ($avg_connect_time > 1.0) { // 超过1秒
            $base_score -= ($avg_connect_time - 1.0) * 15; // 每超过1秒扣15分
        }

        // 根据超时失败率扣分
        $timeout_rate = ($this->network_stats['timeout_failures'] / $total_requests) * 100;
        $base_score -= $timeout_rate * 3; // 每1%超时率扣3分

        // 确保评分在0-100范围内
        $this->network_stats['network_quality_score'] = max(0, min(100, $base_score));
    }

    /**
     * 超时错误时调整配置
     *
     * @since    1.8.1
     */
    private function adjust_timeout_on_error(): void {
        if ($this->network_config['adaptive_timeout']) {
            // 增加基础超时时间
            $this->network_config['base_timeout'] = min(
                $this->network_config['max_timeout'],
                $this->network_config['base_timeout'] + 2
            );

            Notion_To_WordPress_Helper::debug_log(
                sprintf('因超时错误调整基础超时时间至%d秒', $this->network_config['base_timeout']),
                'Network Adaptation'
            );
        }
    }

    /**
     * 连接错误时调整配置
     *
     * @since    1.8.1
     */
    private function adjust_connection_on_error(): void {
        if ($this->network_config['adaptive_timeout']) {
            // 增加连接超时时间
            $this->network_config['base_connect_timeout'] = min(
                $this->network_config['base_timeout'] / 2,
                $this->network_config['base_connect_timeout'] + 1
            );

            Notion_To_WordPress_Helper::debug_log(
                sprintf('因连接错误调整连接超时时间至%d秒', $this->network_config['base_connect_timeout']),
                'Network Adaptation'
            );
        }
    }

    /**
     * 获取网络性能统计
     *
     * @since    1.8.1
     * @return   array    网络性能统计数据
     */
    public function get_network_stats(): array {
        return array_merge($this->network_stats, [
            'config' => $this->network_config,
            'performance_grade' => $this->calculate_network_performance_grade(),
            'recommendations' => $this->generate_network_recommendations()
        ]);
    }

    /**
     * 计算网络性能等级
     *
     * @since    1.8.1
     * @return   string    性能等级
     */
    private function calculate_network_performance_grade(): string {
        $score = $this->network_stats['network_quality_score'];

        if ($score >= 90) {
            return 'A';
        } elseif ($score >= 80) {
            return 'B';
        } elseif ($score >= 70) {
            return 'C';
        } elseif ($score >= 60) {
            return 'D';
        } else {
            return 'F';
        }
    }

    /**
     * 生成网络优化建议
     *
     * @since    1.8.1
     * @return   array    优化建议数组
     */
    private function generate_network_recommendations(): array {
        $recommendations = [];
        $score = $this->network_stats['network_quality_score'];
        $avg_connect_time = $this->network_stats['avg_connect_time'];
        $avg_transfer_time = $this->network_stats['avg_transfer_time'];
        $failure_rate = $this->stats['total_requests'] > 0 ?
            ($this->stats['failed_requests'] / $this->stats['total_requests']) * 100 : 0;

        if ($score < 70) {
            $recommendations[] = '网络质量较差，建议检查网络连接';
        }

        if ($avg_connect_time > 2.0) {
            $recommendations[] = '连接时间过长，建议启用连接复用或检查DNS设置';
        }

        if ($avg_transfer_time > 5.0) {
            $recommendations[] = '传输时间过长，建议启用压缩或增加超时时间';
        }

        if ($failure_rate > 10) {
            $recommendations[] = '请求失败率较高，建议增加重试次数或调整超时设置';
        }

        if ($this->network_stats['timeout_failures'] > 0) {
            $recommendations[] = '存在超时失败，建议启用自适应超时策略';
        }

        if (empty($recommendations)) {
            $recommendations[] = '网络性能良好，继续保持当前配置';
        }

        return $recommendations;
    }

    /**
     * 设置自适应并发配置
     *
     * @since    1.8.1
     * @param    array    $config    自适应配置
     */
    public function set_adaptive_config(array $config): void {
        $this->adaptive_config = array_merge($this->adaptive_config, $config);

        Notion_To_WordPress_Helper::debug_log(
            '自适应并发配置已更新: ' . json_encode($this->adaptive_config),
            'Adaptive Config'
        );
    }

    /**
     * 设置网络配置
     *
     * @since    1.8.1
     * @param    array    $config    网络配置
     */
    public function set_network_config(array $config): void {
        $this->network_config = array_merge($this->network_config, $config);

        Notion_To_WordPress_Helper::debug_log(
            '网络配置已更新: ' . json_encode($this->network_config),
            'Network Config'
        );
    }

    /**
     * 重置网络统计
     *
     * @since    1.8.1
     */
    public function reset_network_stats(): void {
        $this->network_stats = [
            'total_connect_time' => 0,
            'total_transfer_time' => 0,
            'avg_connect_time' => 0,
            'avg_transfer_time' => 0,
            'connection_failures' => 0,
            'timeout_failures' => 0,
            'dns_lookup_time' => 0,
            'ssl_handshake_time' => 0,
            'network_quality_score' => 100
        ];

        Notion_To_WordPress_Helper::debug_log('网络统计已重置', 'Network Management');
    }

    /**
     * 获取综合性能报告
     *
     * @since    1.8.1
     * @return   array    综合性能报告
     */
    public function get_comprehensive_performance_report(): array {
        return [
            'concurrent_stats' => $this->get_stats(),
            'network_stats' => $this->get_network_stats(),
            'image_download_stats' => $this->get_image_download_queue_status(),
            'overall_score' => $this->calculate_overall_performance_score(),
            'summary' => $this->generate_performance_summary()
        ];
    }

    /**
     * 计算综合性能评分
     *
     * @since    1.8.1
     * @return   float    综合评分
     */
    private function calculate_overall_performance_score(): float {
        error_log("DEBUG: calculate_overall_performance_score 开始执行");
        error_log("DEBUG: stats 内容: " . json_encode($this->stats));
        error_log("DEBUG: network_stats 内容: " . json_encode($this->network_stats));

        // 动态计算成功率
        $total = $this->stats['total_requests'];
        $success_rate = $total > 0 ? round(($this->stats['successful_requests'] / $total) * 100, 2) : 0;

        error_log("DEBUG: 计算得到 success_rate: $success_rate");

        $concurrent_score = min(100, $success_rate);
        $network_score = $this->network_stats['network_quality_score'] ?? 100;

        // 加权平均：并发性能50%，网络性能50%
        $result = round(($concurrent_score * 0.5) + ($network_score * 0.5), 2);
        error_log("DEBUG: calculate_overall_performance_score 返回: $result");
        return $result;
    }

    /**
     * 生成性能摘要
     *
     * @since    1.8.1
     * @return   array    性能摘要
     */
    private function generate_performance_summary(): array {
        error_log("DEBUG: generate_performance_summary 开始执行");
        $overall_score = $this->calculate_overall_performance_score();

        // 动态计算成功率
        $total = $this->stats['total_requests'];
        $success_rate = $total > 0 ? round(($this->stats['successful_requests'] / $total) * 100, 2) : 0;
        error_log("DEBUG: generate_performance_summary 计算得到 success_rate: $success_rate");

        return [
            'overall_score' => $overall_score,
            'performance_level' => $overall_score >= 80 ? 'Excellent' :
                                  ($overall_score >= 60 ? 'Good' :
                                  ($overall_score >= 40 ? 'Fair' : 'Poor')),
            'key_metrics' => [
                'success_rate' => $success_rate . '%',
                'avg_response_time' => round($this->stats['average_response_time'], 2) . 'ms',
                'network_quality' => $this->network_stats['network_quality_score'] ?? 100,
                'concurrent_efficiency' => count($this->request_pool) . '/' . $this->max_concurrent
            ]
        ];
    }
}
