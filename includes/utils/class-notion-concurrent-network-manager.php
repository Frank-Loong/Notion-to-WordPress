<?php
declare(strict_types=1);

/**
 * 并发网络请求管理器类
 * 
 * 基于PHP cURL multi-handle技术，提供批量HTTP请求处理能力。
 * 支持并发请求、错误处理、超时控制、重试机制和进度监控。
 * 
 * @since      1.9.0-beta.1
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

// 加载重试机制类
require_once plugin_dir_path(__FILE__) . 'class-notion-network-retry.php';

class Notion_Concurrent_Network_Manager {

    /**
     * cURL multi handle
     *
     * @since    1.1.2
     * @access   private
     * @var      resource    $multi_handle    cURL multi handle资源
     */
    private $multi_handle = null;

    /**
     * cURL handles数组
     *
     * @since    1.1.2
     * @access   private
     * @var      array    $curl_handles    cURL句柄数组
     */
    private $curl_handles = [];

    /**
     * 请求配置数组
     *
     * @since    1.1.2
     * @access   private
     * @var      array    $requests    请求配置数组
     */
    private $requests = [];

    /**
     * 响应结果数组
     *
     * @since    1.1.2
     * @access   private
     * @var      array    $responses    响应结果数组
     */
    private $responses = [];

    /**
     * 执行统计信息
     *
     * @since    1.1.2
     * @access   private
     * @var      array    $execution_stats    执行统计信息
     */
    private $execution_stats = [];

    /**
     * 最大并发请求数量
     *
     * @since    1.1.2
     * @access   private
     * @var      int    $max_concurrent_requests    最大并发请求数量
     */
    private $max_concurrent_requests = 5;

    /**
     * 默认超时时间（秒）
     *
     * @since    1.1.2
     * @access   private
     * @var      int    $default_timeout    默认超时时间
     */
    private $default_timeout = 30;

    /**
     * 构造函数
     *
     * @since    1.1.2
     * @param    int    $max_concurrent    最大并发请求数量
     */
    public function __construct($max_concurrent = 5) {
        $this->max_concurrent_requests = max(1, min(10, $max_concurrent));
        
        Notion_Logger::debug_log(
            "初始化并发网络管理器，最大并发数: {$this->max_concurrent_requests}",
            'Concurrent Network'
        );
    }

    /**
     * 添加请求到队列
     *
     * @since    1.1.2
     * @param    string    $url     请求URL
     * @param    array     $args    请求参数
     * @return   int                请求ID
     */
    public function add_request($url, $args = []) {
        $request_id = count($this->requests);
        
        // 设置默认参数
        $default_args = [
            'method'     => 'GET',
            'timeout'    => $this->default_timeout,
            'headers'    => [],
            'body'       => '',
            'user-agent' => 'Notion-to-WordPress/' . NOTION_TO_WORDPRESS_VERSION
        ];
        
        $args = wp_parse_args($args, $default_args);
        
        // 存储请求配置
        $this->requests[$request_id] = [
            'url'  => $url,
            'args' => $args
        ];
        
        Notion_Logger::debug_log(
            "添加请求到队列: {$args['method']} {$url}",
            'Concurrent Network'
        );
        
        return $request_id;
    }

    /**
     * 执行所有并发请求
     *
     * @since    1.1.2
     * @return   array    响应结果数组
     */
    public function execute() {
        return $this->execute_with_retry();
    }

    /**
     * 执行所有并发请求（支持重试）
     *
     * @since    1.1.2
     * @param    int    $max_retries    最大重试次数
     * @param    int    $base_delay     基础延迟时间（毫秒）
     * @return   array                  响应结果数组
     */
    public function execute_with_retry($max_retries = 2, $base_delay = 1000) {
        return Notion_Network_Retry::with_retry(
            [$this, 'execute_internal'],
            $max_retries,
            $base_delay
        );
    }

    /**
     * 内部执行方法（实际的并发请求处理）
     *
     * @since    1.1.2
     * @return   array    响应结果数组
     * @throws   Exception
     */
    public function execute_internal() {
        if (empty($this->requests)) {
            Notion_Logger::debug_log(
                '没有待执行的请求',
                'Concurrent Network'
            );
            return [];
        }

        $start_time = microtime(true);
        
        Notion_Logger::debug_log(
            "开始执行 " . count($this->requests) . " 个并发请求",
            'Concurrent Network'
        );

        try {
            $this->init_multi_handle();
            $this->create_curl_handles();
            $this->execute_requests();
            $this->process_responses();
            
            $execution_time = microtime(true) - $start_time;

            // 保存执行统计信息
            $this->execution_stats = [
                'total_requests'     => count($this->requests),
                'successful_requests' => $this->count_successful_responses(),
                'failed_requests'    => $this->count_failed_responses(),
                'execution_time'     => $execution_time,
                'max_concurrent'     => $this->max_concurrent_requests,
                'memory_usage'       => memory_get_usage(true),
                'peak_memory'        => memory_get_peak_usage(true)
            ];

            Notion_Logger::debug_log(
                sprintf(
                    "并发请求执行完成，耗时: %.2f秒，成功: %d，失败: %d",
                    $execution_time,
                    $this->execution_stats['successful_requests'],
                    $this->execution_stats['failed_requests']
                ),
                'Concurrent Network'
            );
            
        } catch (Exception $e) {
            Notion_Logger::error_log(
                "并发请求执行异常: " . $e->getMessage(),
                'Concurrent Network'
            );

            // 清理资源
            $this->cleanup();
            throw $e;
        }

        // 保存响应结果
        $responses = $this->responses;

        // 清理资源
        $this->cleanup();

        return $responses;
    }

    /**
     * 获取响应结果
     *
     * @since    1.1.2
     * @return   array    响应结果数组
     */
    public function get_responses() {
        return $this->responses;
    }

    /**
     * 获取指定请求的响应
     *
     * @since    1.1.2
     * @param    int    $request_id    请求ID
     * @return   mixed               响应结果或null
     */
    public function get_response($request_id) {
        return isset($this->responses[$request_id]) ? $this->responses[$request_id] : null;
    }

    /**
     * 初始化multi handle
     *
     * @since    1.1.2
     * @access   private
     */
    private function init_multi_handle() {
        $this->multi_handle = curl_multi_init();
        
        if ($this->multi_handle === false) {
            throw new Exception('无法初始化cURL multi handle');
        }
        
        // 设置multi handle选项
        curl_multi_setopt($this->multi_handle, CURLMOPT_MAXCONNECTS, $this->max_concurrent_requests);
    }

    /**
     * 创建cURL句柄
     *
     * @since    1.1.2
     * @access   private
     */
    private function create_curl_handles() {
        foreach ($this->requests as $request_id => $request) {
            $curl_handle = curl_init();
            
            if ($curl_handle === false) {
                Notion_Logger::error_log(
                    "无法创建cURL句柄，请求ID: {$request_id}",
                    'Concurrent Network'
                );
                continue;
            }
            
            $this->configure_curl_handle($curl_handle, $request);
            $this->curl_handles[$request_id] = $curl_handle;
            
            // 添加到multi handle
            curl_multi_add_handle($this->multi_handle, $curl_handle);
        }
    }

    /**
     * 配置cURL句柄
     *
     * @since    1.1.2
     * @access   private
     * @param    resource    $curl_handle    cURL句柄
     * @param    array       $request        请求配置
     */
    private function configure_curl_handle($curl_handle, $request) {
        $url = $request['url'];
        $args = $request['args'];
        
        // 基本配置
        curl_setopt_array($curl_handle, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $args['timeout'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => $args['user-agent'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        // 设置HTTP方法
        switch (strtoupper($args['method'])) {
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
     * 执行并发请求
     *
     * @since    1.1.2
     * @access   private
     */
    private function execute_requests() {
        $running = null;

        // 开始执行
        do {
            $status = curl_multi_exec($this->multi_handle, $running);

            if ($status != CURLM_OK) {
                throw new Exception("cURL multi exec错误: " . curl_multi_strerror($status));
            }

            // 等待活动
            if ($running > 0) {
                curl_multi_select($this->multi_handle, 0.1);
            }

        } while ($running > 0);
    }

    /**
     * 处理响应结果
     *
     * @since    1.1.2
     * @access   private
     */
    private function process_responses() {
        foreach ($this->curl_handles as $request_id => $curl_handle) {
            $response_data = curl_multi_getcontent($curl_handle);
            $http_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
            $error_code = curl_errno($curl_handle);
            $error_message = curl_error($curl_handle);

            if ($error_code !== 0) {
                // cURL错误
                $this->responses[$request_id] = new WP_Error(
                    'curl_error',
                    sprintf('cURL错误 %d: %s', $error_code, $error_message)
                );

                Notion_Logger::error_log(
                    "请求失败 (ID: {$request_id}): cURL错误 {$error_code} - {$error_message}",
                    'Concurrent Network'
                );

            } elseif ($http_code >= 400) {
                // HTTP错误
                $this->responses[$request_id] = new WP_Error(
                    'http_error',
                    sprintf('HTTP错误 %d', $http_code)
                );

                Notion_Logger::error_log(
                    "请求失败 (ID: {$request_id}): HTTP错误 {$http_code}",
                    'Concurrent Network'
                );

            } else {
                // 成功响应
                $this->responses[$request_id] = [
                    'body'     => $response_data,
                    'response' => [
                        'code'    => $http_code,
                        'message' => $this->get_http_status_message($http_code)
                    ],
                    'headers'  => $this->parse_response_headers($curl_handle)
                ];

                Notion_Logger::debug_log(
                    "请求成功 (ID: {$request_id}): HTTP {$http_code}",
                    'Concurrent Network'
                );
            }
        }
    }

    /**
     * 解析响应头
     *
     * @since    1.1.2
     * @access   private
     * @param    resource    $curl_handle    cURL句柄
     * @return   array                       响应头数组
     */
    private function parse_response_headers($curl_handle) {
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
     * @since    1.1.2
     * @access   private
     * @param    int    $code    HTTP状态码
     * @return   string          状态消息
     */
    private function get_http_status_message($code) {
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

        return isset($messages[$code]) ? $messages[$code] : 'Unknown';
    }

    /**
     * 统计成功响应数量
     *
     * @since    1.1.2
     * @access   private
     * @return   int    成功响应数量
     */
    private function count_successful_responses() {
        $count = 0;
        foreach ($this->responses as $response) {
            if (!is_wp_error($response)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 统计失败响应数量
     *
     * @since    1.1.2
     * @access   private
     * @return   int    失败响应数量
     */
    private function count_failed_responses() {
        $count = 0;
        foreach ($this->responses as $response) {
            if (is_wp_error($response)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 清理资源
     *
     * @since    1.1.2
     * @access   private
     */
    private function cleanup() {
        // 清理cURL句柄
        foreach ($this->curl_handles as $curl_handle) {
            if ($this->multi_handle) {
                curl_multi_remove_handle($this->multi_handle, $curl_handle);
            }
            curl_close($curl_handle);
        }

        // 清理multi handle
        if ($this->multi_handle) {
            curl_multi_close($this->multi_handle);
            $this->multi_handle = null;
        }

        // 重置数组，但保留执行统计信息
        $this->curl_handles = [];
        $this->requests = [];
        $this->responses = [];

        Notion_Logger::debug_log(
            '并发网络管理器资源清理完成',
            'Concurrent Network'
        );
    }

    /**
     * 析构函数
     *
     * @since    1.1.2
     */
    public function __destruct() {
        $this->cleanup();
    }

    /**
     * 获取性能统计信息
     *
     * @since    1.1.2
     * @return   array    性能统计数组
     */
    public function get_stats() {
        // 如果有保存的执行统计信息，使用它
        if (!empty($this->execution_stats)) {
            return $this->execution_stats;
        }

        // 否则返回当前状态
        return [
            'total_requests'     => count($this->requests),
            'successful_requests' => $this->count_successful_responses(),
            'failed_requests'    => $this->count_failed_responses(),
            'max_concurrent'     => $this->max_concurrent_requests,
            'memory_usage'       => memory_get_usage(true),
            'peak_memory'        => memory_get_peak_usage(true)
        ];
    }

    /**
     * 设置最大并发请求数量
     *
     * @since    1.1.2
     * @param    int    $max_concurrent    最大并发数量
     */
    public function set_max_concurrent_requests($max_concurrent) {
        $this->max_concurrent_requests = max(1, min(10, $max_concurrent));

        Notion_Logger::debug_log(
            "设置最大并发请求数: {$this->max_concurrent_requests}",
            'Concurrent Network'
        );
    }

    /**
     * 设置默认超时时间
     *
     * @since    1.1.2
     * @param    int    $timeout    超时时间（秒）
     */
    public function set_default_timeout($timeout) {
        $this->default_timeout = max(5, min(120, $timeout));

        Notion_Logger::debug_log(
            "设置默认超时时间: {$this->default_timeout}秒",
            'Concurrent Network'
        );
    }

    /**
     * 连接池管理
     *
     * @since 2.0.0-beta.1
     * @access private
     * @var array $connection_pool 连接池
     */
    private $connection_pool = [];

    /**
     * 连接池最大大小
     *
     * @since 2.0.0-beta.1
     * @access private
     * @var int $max_pool_size 连接池最大大小
     */
    private $max_pool_size = 10;

    /**
     * 数据量预估缓存
     *
     * @since 2.0.0-beta.1
     * @access private
     * @var array $size_estimation_cache 数据量预估缓存
     */
    private $size_estimation_cache = [];

    /**
     * 初始化连接池
     *
     * @since 2.0.0-beta.1
     * @return void
     */
    private function init_connection_pool(): void {
        if (empty($this->connection_pool)) {
            for ($i = 0; $i < $this->max_pool_size; $i++) {
                $this->connection_pool[] = curl_init();
            }

            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    sprintf('初始化连接池: %d个连接', $this->max_pool_size),
                    'Connection Pool'
                );
            }
        }
    }

    /**
     * 从连接池获取连接
     *
     * @since 2.0.0-beta.1
     * @return resource|false cURL句柄或false
     */
    private function get_connection_from_pool() {
        if (!empty($this->connection_pool)) {
            return array_pop($this->connection_pool);
        }

        // 如果连接池为空，创建新连接
        return curl_init();
    }

    /**
     * 将连接返回到连接池
     *
     * @since 2.0.0-beta.1
     * @param resource $handle cURL句柄
     * @return void
     */
    private function return_connection_to_pool($handle): void {
        if (count($this->connection_pool) < $this->max_pool_size) {
            // 重置连接状态
            curl_reset($handle);
            $this->connection_pool[] = $handle;
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
    public function cleanup_connection_pool(): void {
        foreach ($this->connection_pool as $handle) {
            curl_close($handle);
        }
        $this->connection_pool = [];

        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log('连接池已清理', 'Connection Pool');
        }
    }

    /**
     * 预估数据库大小
     *
     * @since 2.0.0-beta.1
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

        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    sprintf('数据库大小预估失败: %s', $e->getMessage()),
                    'Size Estimation'
                );
            }
        }

        return $estimation;
    }

    /**
     * 动态计算最优并发数
     *
     * @since 2.0.0-beta.1
     * @param int $estimated_size 预估的数据量
     * @param int $page_size 每页大小
     * @return int 最优并发数
     */
    public function calculate_optimal_concurrency(int $estimated_size, int $page_size = 100): int {
        // 计算预估的页面数
        $estimated_pages = ceil($estimated_size / $page_size);

        // 根据数据量动态调整并发数
        if ($estimated_pages <= 2) {
            $optimal_concurrency = 1; // 小数据集使用单线程
        } elseif ($estimated_pages <= 10) {
            $optimal_concurrency = min(3, $estimated_pages); // 中等数据集
        } else {
            $optimal_concurrency = min($this->max_concurrent_requests, ceil($estimated_pages / 5)); // 大数据集
        }

        // 考虑系统负载调整
        if (class_exists('Notion_Adaptive_Batch')) {
            $system_stats = Notion_Adaptive_Batch::get_adaptive_stats();
            if ($system_stats['memory_usage_percent'] > 80) {
                $optimal_concurrency = max(1, floor($optimal_concurrency * 0.7)); // 内存紧张时减少并发
            }
        }

        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf(
                    '动态并发计算: 预估大小=%d, 页面数=%d, 最优并发=%d',
                    $estimated_size,
                    $estimated_pages,
                    $optimal_concurrency
                ),
                'Concurrency Calculation'
            );
        }

        return $optimal_concurrency;
    }
}
