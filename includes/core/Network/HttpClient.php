<?php
declare(strict_types=1);

namespace NTWP\Core\Network;

/**
 * Notion HTTP客户端类
 * 
 * 专门处理插件的HTTP请求功能，包括安全的远程请求、错误处理、重试机制等。
 * 统一网络请求处理逻辑，实现单一职责原则，从Helper类中分离出来。
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

class HttpClient {

    /**
     * 默认请求超时时间（秒）
     */
    const DEFAULT_TIMEOUT = 30;

    /**
     * 默认用户代理
     */
    const DEFAULT_USER_AGENT = 'Notion-to-WordPress';

    /**
     * 连接池最大大小
     */
    const MAX_POOL_SIZE = 10;

    /**
     * 连接池
     * @var array
     */
    private static $connection_pool = [];

    /**
     * 连接池统计信息
     * @var array
     */
    private static $pool_stats = [
        'total_requests' => 0,
        'pool_hits' => 0,
        'pool_misses' => 0,
        'connections_created' => 0,
        'connections_reused' => 0,
        'http2_connections' => 0,
        'keepalive_connections' => 0,
        'average_response_time' => 0,
        'connection_errors' => 0,
        'unhealthy_connections' => 0
    ];

    /**
     * 连接健康检查缓存
     * @var array
     */
    private static $connection_ages = [];

    /**
     * 安全地获取远程内容
     *
     * @since 2.0.0-beta.1
     * @param string $url 要获取的URL
     * @param array $args 请求参数
     * @return array|WP_Error 响应数组或错误对象
     */
    public static function safe_remote_get(string $url, array $args = []) {
        // 默认参数
        $default_args = [
            'timeout' => self::DEFAULT_TIMEOUT,
            'user-agent' => self::get_user_agent(),
            'headers' => [
                'Accept' => 'application/json',
            ],
            'sslverify' => true,
        ];

        $args = wp_parse_args($args, $default_args);

        // 验证URL
        if (!self::is_valid_url($url)) {
            return new WP_Error('invalid_url', __('无效的URL', 'notion-to-wordpress'));
        }

        // 执行请求
        $response = wp_remote_get($url, $args);

        // 处理响应
        return self::process_response($response, $url);
    }

    /**
     * 安全地发送POST请求
     *
     * @since 2.0.0-beta.1
     * @param string $url 要请求的URL
     * @param array $data 要发送的数据
     * @param array $args 请求参数
     * @return array|WP_Error 响应数组或错误对象
     */
    public static function safe_remote_post(string $url, array $data = [], array $args = []) {
        // 默认参数
        $default_args = [
            'timeout' => self::DEFAULT_TIMEOUT,
            'user-agent' => self::get_user_agent(),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($data),
            'sslverify' => true,
        ];

        $args = wp_parse_args($args, $default_args);

        // 验证URL
        if (!self::is_valid_url($url)) {
            return new WP_Error('invalid_url', __('无效的URL', 'notion-to-wordpress'));
        }

        // 执行请求
        $response = wp_remote_post($url, $args);

        // 处理响应
        return self::process_response($response, $url);
    }

    /**
     * 带重试机制的安全远程请求
     *
     * @since 2.0.0-beta.1
     * @param string $url 要请求的URL
     * @param array $args 请求参数
     * @param int $max_retries 最大重试次数
     * @return array|WP_Error 响应数组或错误对象
     */
    public static function safe_remote_get_with_retry(string $url, array $args = [], int $max_retries = 3) {
        // 如果Network_Retry类存在，使用它的重试机制
        if (class_exists('NTWP\Utils\Network_Retry')) {
            return \NTWP\Infrastructure\NetworkRetry::execute_with_retry(function() use ($url, $args) {
                return self::safe_remote_get($url, $args);
            }, $max_retries);
        }

        // 否则使用简单的重试机制
        $last_error = null;
        for ($i = 0; $i <= $max_retries; $i++) {
            $response = self::safe_remote_get($url, $args);
            
            if (!is_wp_error($response)) {
                return $response;
            }
            
            $last_error = $response;
            
            // 如果不是最后一次尝试，等待一段时间
            if ($i < $max_retries) {
                sleep(pow(2, $i)); // 指数退避
            }
        }
        
        return $last_error;
    }

    /**
     * 使用连接池的并发请求方法
     *
     * @since 2.0.0-beta.1
     * @param array $requests 请求配置数组
     * @return array 响应结果数组
     */
    public static function execute_concurrent_requests_with_pool(array $requests): array {
        if (empty($requests)) {
            return [];
        }

        // 初始化连接池
        self::init_connection_pool();

        $start_time = microtime(true);
        $multi_handle = curl_multi_init();
        $curl_handles = [];
        $results = [];

        Logger::debug_log(
            sprintf('开始并发处理 %d 个请求（使用连接池）', count($requests)),
            'HttpClient'
        );

        // 初始化cURL句柄
        foreach ($requests as $index => $request) {
            $ch = self::get_connection_from_pool();
            if ($ch === false) {
                Logger::error_log(
                    "无法获取cURL句柄，请求ID: {$index}",
                    'HttpClient'
                );
                continue;
            }

            // 配置cURL句柄
            self::configure_curl_handle_for_request($ch, $request);
            curl_multi_add_handle($multi_handle, $ch);
            $curl_handles[$index] = $ch;
        }

        // 执行并发请求
        $running = null;
        $max_execution_time = 90; // 最大执行时间90秒
        do {
            $status = curl_multi_exec($multi_handle, $running);

            if ($status != CURLM_OK) {
                throw new \Exception("cURL multi exec错误: " . curl_multi_strerror($status));
            }

            // 检查执行时间，避免超时
            $elapsed_time = microtime(true) - $start_time;
            if ($elapsed_time > $max_execution_time) {
                Logger::error_log(
                    sprintf('并发请求执行超时 (%.2f秒)，强制终止', $elapsed_time),
                    'HttpClient'
                );
                break;
            }

            // 等待活动，减少CPU占用
            if ($running > 0) {
                curl_multi_select($multi_handle, 0.1);
            }

        } while ($running > 0);

        // 收集响应结果
        $total_response_time = 0;
        $successful_requests = 0;

        foreach ($curl_handles as $index => $ch) {
            $response_data = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error_code = curl_errno($ch);
            $error_message = curl_error($ch);
            $info = curl_getinfo($ch);
            $response_time = $info['total_time'] ?? 0;
            $total_response_time += $response_time;

            // 检查是否使用了HTTP/2
            if (isset($info['http_version']) && $info['http_version'] >= 3) {
                Logger::debug_log("请求 {$index} 使用HTTP/2", 'HttpClient');
            }

            if ($error_code !== 0) {
                // cURL错误
                self::$pool_stats['connection_errors']++;
                $results[$index] = new \WP_Error(
                    'curl_error',
                    sprintf('cURL错误 %d: %s', $error_code, $error_message)
                );
                Logger::error_log(
                    "请求失败 (ID: {$index}): cURL错误 {$error_code} - {$error_message}",
                    'HttpClient'
                );
            } elseif ($http_code >= 400) {
                // HTTP错误
                self::$pool_stats['connection_errors']++;
                $results[$index] = new \WP_Error(
                    'http_error',
                    sprintf('HTTP错误 %d', $http_code)
                );
                Logger::error_log(
                    "请求失败 (ID: {$index}): HTTP错误 {$http_code}",
                    'HttpClient'
                );
            } else {
                // 成功响应
                $successful_requests++;
                $results[$index] = [
                    'body'     => $response_data,
                    'response' => [
                        'code'    => $http_code,
                        'message' => self::get_http_status_message($http_code)
                    ],
                    'headers'  => self::parse_response_headers($ch),
                    'stats'    => [
                        'response_time' => $response_time,
                        'http_version' => $info['http_version'] ?? 0,
                        'connect_time' => $info['connect_time'] ?? 0
                    ]
                ];

                // 检查是否为性能模式
                $is_performance_mode = defined('NOTION_PERFORMANCE_MODE') && NOTION_PERFORMANCE_MODE;
                if (!$is_performance_mode) {
                    Logger::debug_log(
                        "请求成功 (ID: {$index}): HTTP {$http_code}, 响应时间: {$response_time}s",
                        'HttpClient'
                    );
                }
            }

            // 将连接返回到连接池
            curl_multi_remove_handle($multi_handle, $ch);
            self::return_connection_to_pool($ch);
        }

        // 更新平均响应时间统计
        if ($successful_requests > 0) {
            $avg_response_time = $total_response_time / $successful_requests;
            self::$pool_stats['average_response_time'] = round($avg_response_time, 4);
        }

        // 清理multi handle
        curl_multi_close($multi_handle);

        $total_time = microtime(true) - $start_time;
        Logger::debug_log(
            sprintf(
                "并发请求执行完成，耗时: %.2f秒，成功: %d，失败: %d",
                $total_time,
                $successful_requests,
                count($requests) - $successful_requests
            ),
            'HttpClient'
        );

        return $results;
    }

    /**
     * 为请求配置cURL句柄
     *
     * @since 2.0.0-beta.1
     * @param resource $curl_handle cURL句柄
     * @param array $request 请求配置
     */
    private static function configure_curl_handle_for_request($curl_handle, array $request): void {
        $url = $request['url'];
        $args = $request['args'] ?? [];

        // 基本设置
        curl_setopt_array($curl_handle, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $args['timeout'] ?? self::DEFAULT_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => $args['user-agent'] ?? self::get_user_agent(),
            CURLOPT_SSL_VERIFYPEER => $args['sslverify'] ?? true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // 设置HTTP方法
        $method = strtoupper($args['method'] ?? 'GET');
        switch ($method) {
            case 'POST':
                curl_setopt($curl_handle, CURLOPT_POST, true);
                if (!empty($args['body'])) {
                    curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $args['body']);
                }
                break;
                
            case 'PUT':
                curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, 'PUT');
                if (!empty($args['body'])) {
                    curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $args['body']);
                }
                break;
                
            case 'DELETE':
                curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
                
            default: // GET
                curl_setopt($curl_handle, CURLOPT_HTTPGET, true);
                break;
        }
        
        // 设置请求头
        if (!empty($args['headers'])) {
            $headers = [];
            foreach ($args['headers'] as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            curl_setopt($curl_handle, CURLOPT_HTTPHEADER, $headers);
        }
    }

    /**
     * 解析响应头
     *
     * @since 2.0.0-beta.1
     * @param resource $curl_handle cURL句柄
     * @return array 响应头数组
     */
    private static function parse_response_headers($curl_handle): array {
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
     * 获取HTTP状态消息
     *
     * @since 2.0.0-beta.1
     * @param int $code HTTP状态码
     * @return string 状态消息
     */
    private static function get_http_status_message(int $code): string {
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

        return $messages[$code] ?? 'Unknown';
    }

    /**
     * 生成一个安全的、唯一的令牌
     *
     * @since 2.0.0-beta.1
     * @param int $length 令牌的长度
     * @return string 生成的唯一令牌
     */
    public static function generate_token(int $length = 32): string {
        return wp_generate_password($length, false, false);
    }

    /**
     * 生成随机字符串
     *
     * @since 2.0.0-beta.1
     * @param int $length 字符串长度
     * @param bool $include_special 是否包含特殊字符
     * @return string 随机字符串
     */
    public static function generate_random_string(int $length = 16, bool $include_special = false): string {
        return wp_generate_password($length, $include_special, false);
    }

    /**
     * 验证URL是否有效
     *
     * @since 2.0.0-beta.1
     * @param string $url 要验证的URL
     * @return bool 是否有效
     */
    private static function is_valid_url(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 处理HTTP响应
     *
     * @since 2.0.0-beta.1
     * @param array|WP_Error $response HTTP响应
     * @param string $url 请求的URL
     * @return array|WP_Error 处理后的响应
     */
    private static function process_response($response, string $url) {
        // 检查错误
        if (is_wp_error($response)) {
            Logger::error_log(__('远程请求失败: ', 'notion-to-wordpress') . $response->get_error_message(), 'HTTP');
            return $response;
        }

        // 检查HTTP状态码
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $error_msg = sprintf(__('HTTP错误 %d: %s', 'notion-to-wordpress'), $status_code, wp_remote_retrieve_response_message($response));
            Logger::error_log($error_msg, 'HTTP');
            return new WP_Error('http_error', $error_msg, ['status_code' => $status_code]);
        }

        return $response;
    }

    /**
     * 获取用户代理字符串
     *
     * @since 2.0.0-beta.1
     * @return string 用户代理字符串
     */
    private static function get_user_agent(): string {
        $version = defined('NOTION_TO_WORDPRESS_VERSION') ? NOTION_TO_WORDPRESS_VERSION : '2.0.0';
        return self::DEFAULT_USER_AGENT . '/' . $version;
    }

    /**
     * 解析JSON响应
     *
     * @since 2.0.0-beta.1
     * @param array|WP_Error $response HTTP响应
     * @return array|WP_Error 解析后的数据或错误
     */
    public static function parse_json_response($response) {
        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new WP_Error('empty_response', __('响应内容为空', 'notion-to-wordpress'));
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_decode_error', __('JSON解析失败: ', 'notion-to-wordpress') . json_last_error_msg());
        }

        return $data;
    }

    /**
     * 检查响应是否成功
     *
     * @since 2.0.0-beta.1
     * @param array|WP_Error $response HTTP响应
     * @return bool 是否成功
     */
    public static function is_response_successful($response): bool {
        if (is_wp_error($response)) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        return $status_code >= 200 && $status_code < 300;
    }

    /**
     * 获取响应状态码
     *
     * @since 2.0.0-beta.1
     * @param array|WP_Error $response HTTP响应
     * @return int|null 状态码或null
     */
    public static function get_response_status_code($response): ?int {
        if (is_wp_error($response)) {
            return null;
        }

        return wp_remote_retrieve_response_code($response);
    }

    /**
     * 获取响应头
     *
     * @since 2.0.0-beta.1
     * @param array|WP_Error $response HTTP响应
     * @param string|null $header 特定头名称，为null时返回所有头
     * @return array|string|null 响应头
     */
    public static function get_response_header($response, ?string $header = null) {
        if (is_wp_error($response)) {
            return null;
        }

        if ($header) {
            return wp_remote_retrieve_header($response, $header);
        }

        return wp_remote_retrieve_headers($response);
    }

    /**
     * 设置请求认证
     *
     * @since 2.0.0-beta.1
     * @param array $args 请求参数
     * @param string $token 认证令牌
     * @param string $type 认证类型 ('Bearer', 'Basic')
     * @return array 更新后的请求参数
     */
    public static function set_auth(array $args, string $token, string $type = 'Bearer'): array {
        if (!isset($args['headers'])) {
            $args['headers'] = [];
        }

        $args['headers']['Authorization'] = $type . ' ' . $token;
        return $args;
    }

    /**
     * 设置请求超时
     *
     * @since 2.0.0-beta.1
     * @param array $args 请求参数
     * @param int $timeout 超时时间（秒）
     * @return array 更新后的请求参数
     */
    public static function set_timeout(array $args, int $timeout): array {
        $args['timeout'] = $timeout;
        return $args;
    }

    // ===== 连接池管理功能 =====

    /**
     * 初始化优化连接池（支持Keep-Alive和HTTP/2）
     *
     * @since 2.0.0-beta.1
     * @return void
     */
    public static function init_connection_pool(): void {
        if (empty(self::$connection_pool)) {
            // 获取最优连接池大小
            $pool_size = min(self::MAX_POOL_SIZE, 5);

            for ($i = 0; $i < $pool_size; $i++) {
                self::$connection_pool[] = self::create_optimized_curl_handle();
            }

            Logger::debug_log(
                sprintf('初始化连接池: %d个连接', $pool_size),
                'HttpClient'
            );
        }
    }

    /**
     * 从连接池获取优化的连接
     *
     * @since 2.0.0-beta.1
     * @return resource|false cURL句柄或false
     */
    public static function get_connection_from_pool() {
        self::$pool_stats['total_requests']++;

        if (!empty(self::$connection_pool)) {
            $handle = array_pop(self::$connection_pool);

            if (self::is_connection_healthy_enhanced($handle)) {
                self::$pool_stats['pool_hits']++;
                self::$pool_stats['connections_reused']++;

                // 减少日志频率：每10次复用记录一次
                static $reuse_count = 0;
                $reuse_count++;
                if ($reuse_count % 10 === 0) {
                    Logger::debug_log(
                        sprintf('连接池复用统计: 已复用%d次连接', $reuse_count),
                        'HttpClient'
                    );
                }

                return $handle;
            } else {
                // 连接不健康，关闭并创建新连接
                curl_close($handle);
                self::$pool_stats['pool_misses']++;
                self::$pool_stats['unhealthy_connections']++;
                return self::create_optimized_curl_handle();
            }
        }

        // 如果连接池为空，创建新的优化连接
        self::$pool_stats['pool_misses']++;
        return self::create_optimized_curl_handle();
    }

    /**
     * 创建优化的cURL句柄（支持Keep-Alive和HTTP/2）
     *
     * @since 2.0.0-beta.1
     * @return resource cURL句柄
     */
    private static function create_optimized_curl_handle() {
        $handle = curl_init();

        // HTTP Keep-Alive和连接复用优化
        curl_setopt_array($handle, [
            // HTTP/2支持（如果服务器支持）
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,

            // Keep-Alive连接复用
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 120,      // 120秒空闲后开始发送keep-alive包
            CURLOPT_TCP_KEEPINTVL => 60,      // keep-alive包间隔60秒

            // 连接复用设置
            CURLOPT_FORBID_REUSE => false,    // 允许连接复用
            CURLOPT_FRESH_CONNECT => false,   // 不强制新连接

            // DNS缓存优化
            CURLOPT_DNS_CACHE_TIMEOUT => 300, // DNS缓存5分钟

            // SSL/TLS优化
            CURLOPT_SSL_SESSIONID_CACHE => true, // 启用SSL会话缓存

            // 压缩支持
            CURLOPT_ENCODING => '',           // 支持所有编码格式

            // 连接超时优化
            CURLOPT_CONNECTTIMEOUT => 10,     // 连接超时10秒
            CURLOPT_TCP_NODELAY => 1,         // 禁用Nagle算法，减少延迟
        ]);

        // 更新统计信息
        self::$pool_stats['connections_created']++;
        self::$pool_stats['http2_connections']++;
        self::$pool_stats['keepalive_connections']++;

        // 检查是否为性能模式
        $is_performance_mode = defined('NOTION_PERFORMANCE_MODE') && NOTION_PERFORMANCE_MODE;
        if (!$is_performance_mode) {
            Logger::debug_log('创建优化cURL句柄（Keep-Alive + HTTP/2）', 'HttpClient');
        }

        return $handle;
    }

    /**
     * 增强的连接健康检查
     *
     * @since 2.0.0-beta.1
     * @param resource $handle cURL句柄
     * @return bool 连接是否健康
     */
    private static function is_connection_healthy_enhanced($handle): bool {
        if (!is_resource($handle)) {
            return false;
        }

        // 基础检查
        $info = curl_getinfo($handle);

        // 检查连接是否仍然有效
        if (isset($info['connect_time']) && $info['connect_time'] > 30) {
            return false; // 连接时间过长，可能已断开
        }

        // 检查是否有错误
        if (curl_errno($handle) !== 0) {
            return false;
        }

        // 检查连接年龄（避免长时间复用导致的问题）
        $handle_id = intval($handle);

        if (!isset(self::$connection_ages[$handle_id])) {
            self::$connection_ages[$handle_id] = time();
        }

        $age = time() - self::$connection_ages[$handle_id];
        if ($age > 300) { // 5分钟后认为连接过旧
            unset(self::$connection_ages[$handle_id]);
            return false;
        }

        return true;
    }

    /**
     * 将连接返回到连接池
     *
     * @since 2.0.0-beta.1
     * @param resource $handle cURL句柄
     * @return void
     */
    public static function return_connection_to_pool($handle): void {
        if (count(self::$connection_pool) < self::MAX_POOL_SIZE) {
            // 重置连接状态
            curl_reset($handle);
            self::$connection_pool[] = $handle;
        } else {
            // 连接池已满，关闭连接
            curl_close($handle);
        }
    }

    /**
     * 清理连接池
     *
     * @since 2.0.0-beta.1
     * @return void
     */
    public static function cleanup_connection_pool(): void {
        foreach (self::$connection_pool as $handle) {
            curl_close($handle);
        }
        self::$connection_pool = [];

        Logger::debug_log('连接池已清理', 'HttpClient');
    }

    /**
     * 获取连接池复用率
     *
     * @since 2.0.0-beta.1
     * @return float 复用率百分比
     */
    public static function get_connection_reuse_rate(): float {
        if (self::$pool_stats['total_requests'] === 0) {
            return 0.0;
        }

        return round((self::$pool_stats['connections_reused'] / self::$pool_stats['total_requests']) * 100, 2);
    }

    /**
     * 获取连接池统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 连接池统计数据
     */
    public static function get_connection_pool_stats(): array {
        $stats = self::$pool_stats;

        // 计算连接复用率
        if ($stats['total_requests'] > 0) {
            $stats['reuse_rate'] = round(($stats['pool_hits'] / $stats['total_requests']) * 100, 2);
        } else {
            $stats['reuse_rate'] = 0;
        }

        // 添加当前连接池状态
        $stats['current_pool_size'] = count(self::$connection_pool);
        $stats['max_pool_size'] = self::MAX_POOL_SIZE;
        $stats['pool_utilization'] = count(self::$connection_pool) > 0 ? 
            round(((self::MAX_POOL_SIZE - count(self::$connection_pool)) / self::MAX_POOL_SIZE) * 100, 2) : 0;

        return $stats;
    }

    /**
     * 获取连接池健康状态
     *
     * @since 2.0.0-beta.1
     * @return array 健康状态信息
     */
    public static function get_connection_pool_health(): array {
        $stats = self::get_connection_pool_stats();

        $health = [
            'status' => 'healthy',
            'issues' => [],
            'recommendations' => []
        ];

        // 检查连接复用率
        if ($stats['reuse_rate'] < 50 && $stats['total_requests'] > 10) {
            $health['status'] = 'warning';
            $health['issues'][] = '连接复用率过低 (' . $stats['reuse_rate'] . '%)';
            $health['recommendations'][] = '考虑增加连接池大小或检查Keep-Alive配置';
        }

        // 检查错误率
        if ($stats['connection_errors'] > 0) {
            $error_rate = round(($stats['connection_errors'] / $stats['total_requests']) * 100, 2);
            if ($error_rate > 5) {
                $health['status'] = 'critical';
                $health['issues'][] = '连接错误率过高 (' . $error_rate . '%)';
                $health['recommendations'][] = '检查网络连接和服务器配置';
            }
        }

        // 检查池利用率
        if ($stats['pool_utilization'] > 90) {
            $health['status'] = 'warning';
            $health['issues'][] = '连接池利用率过高 (' . $stats['pool_utilization'] . '%)';
            $health['recommendations'][] = '考虑增加连接池大小';
        }

        return $health;
    }

    /**
     * 重置连接池统计信息
     *
     * @since 2.0.0-beta.1
     * @return bool 重置是否成功
     */
    public static function reset_connection_pool_stats(): bool {
        self::$pool_stats = [
            'total_requests' => 0,
            'pool_hits' => 0,
            'pool_misses' => 0,
            'connections_created' => 0,
            'connections_reused' => 0,
            'http2_connections' => 0,
            'keepalive_connections' => 0,
            'average_response_time' => 0,
            'connection_errors' => 0,
            'unhealthy_connections' => 0
        ];

        Logger::debug_log('连接池统计信息已重置', 'HttpClient');
        return true;
    }

    /**
     * 强制刷新连接池（关闭所有连接并重新创建）
     *
     * @since 2.0.0-beta.1
     * @return bool 刷新是否成功
     */
    public static function refresh_connection_pool(): bool {
        try {
            // 清理现有连接池
            self::cleanup_connection_pool();

            // 重新初始化
            self::init_connection_pool();

            Logger::debug_log('连接池已强制刷新', 'HttpClient');
            return true;
        } catch (Exception $e) {
            Logger::error_log('连接池刷新失败: ' . $e->getMessage(), 'HttpClient');
            return false;
        }
    }
}
