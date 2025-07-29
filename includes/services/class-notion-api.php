<?php
declare(strict_types=1);

/**
 * Notion API 交互类
 * 
 * 封装了与 Notion API 通信的所有方法，包括获取数据库、页面、块等内容。
 * 处理 API 认证、请求发送、响应解析和错误处理。提供了完整的 Notion API
 * 功能封装，支持数据库查询、页面操作、内容同步等核心功能。
 * 
 * @since      1.0.9
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

// 加载并发网络管理器和重试机制（Utils层）
require_once plugin_dir_path(__FILE__) . '../utils/class-notion-concurrent-network-manager.php';
require_once plugin_dir_path(__FILE__) . '../utils/class-notion-network-retry.php';
require_once plugin_dir_path(__FILE__) . '../utils/class-notion-smart-api-merger.php';

class Notion_API {

    /**
     * Notion API 密钥。
     *
     * @since    1.0.8
     * @access   private
     * @var      string
     */
    private string $api_key;

    /**
     * Notion API 的基础 URL。
     *
     * @since    1.0.8
     * @access   private
     * @var      string
     */
    private string $api_base = 'https://api.notion.com/v1/';

    /**
     * 智能API调用合并器实例
     *
     * @since    2.0.0-beta.1
     * @access   private
     * @var      Notion_Smart_API_Merger|null
     */
    private ?Notion_Smart_API_Merger $api_merger = null;

    /**
     * 是否启用智能API合并
     *
     * @since    2.0.0-beta.1
     * @access   private
     * @var      bool
     */
    private bool $enable_api_merging = true;

    // 注意：API缓存已移除以支持增量同步的实时性
    // 增量同步依赖准确的last_edited_time进行时间戳比较
    // API缓存会返回过时的时间戳，破坏增量同步的核心逻辑

    /**
     * 构造函数，初始化 API 客户端。
     *
     * @since    1.0.8
     * @param    string    $api_key    Notion API 密钥。
     */
    public function __construct(string $api_key) {
        $this->api_key = $api_key;

        // 🚀 性能优化：单例模式初始化智能API合并器（避免重复初始化和内存泄漏）
        $options = get_option('notion_to_wordpress_options', []);
        $this->enable_api_merging = $options['enable_api_merging'] ?? true;

        // 定义性能模式常量以减少日志记录
        if (!defined('NOTION_PERFORMANCE_MODE')) {
            define('NOTION_PERFORMANCE_MODE', true);
        }

        if ($this->enable_api_merging && class_exists('Notion_Smart_API_Merger')) {
            // 单例检查：避免重复创建API合并器实例
            static $global_api_merger = null;

            if ($global_api_merger === null) {
                $global_api_merger = new Notion_Smart_API_Merger();
            }

            $this->api_merger = $global_api_merger;
            $this->api_merger->set_notion_api($this);
        }
    }

    /**
     * 检查 API 密钥是否已设置。
     * 
     * @since    1.0.8
     * @return   bool     如果 API 密钥已设置，则返回 true，否则返回 false。
     */
    public function is_api_key_set(): bool {
        return !empty($this->api_key);
    }

    /**
     * 向 Notion API 发送请求（智能合并版本）
     *
     * @since    2.0.0-beta.1
     * @param    string    $endpoint    API 端点，不包含基础 URL。
     * @param    string    $method      HTTP 请求方法 (e.g., 'GET', 'POST')。
     * @param    array<string, mixed>     $data        要发送的请求数据。
     * @param    bool      $force_immediate 是否强制立即执行，跳过合并
     * @return   array<string, mixed>                  解码后的 JSON 响应。
     * @throws   Exception             如果 API 请求失败或返回错误。
     */
    public function send_request_with_merging(string $endpoint, string $method = 'GET', array $data = [], bool $force_immediate = false): array {
        // 如果启用了智能合并且不是强制立即执行且是GET请求
        if ($this->enable_api_merging && $this->api_merger && !$force_immediate && $method === 'GET') {
            // 🔧 修复：使用同步等待机制处理合并请求
            $request_id = uniqid('sync_req_', true);
            $result = null;
            $exception = null;
            $completed = false;

            // 创建同步回调
            $callback = function($response, $error) use (&$result, &$exception, &$completed) {
                if ($error) {
                    $exception = $error;
                } else {
                    $result = $response;
                }
                $completed = true;
            };

            // 将请求加入队列
            $immediate_result = $this->api_merger->queue_request($endpoint, $method, $data, $callback);

            // 如果立即返回了结果（批处理被触发）
            if ($immediate_result !== null) {
                if ($completed) {
                    if ($exception) {
                        throw $exception;
                    }
                    return $result;
                }
            }

            // 如果没有立即完成，等待一个短暂的时间让批处理完成
            $max_wait_time = 100; // 100ms最大等待时间
            $wait_interval = 5;   // 5ms检查间隔
            $waited = 0;

            while (!$completed && $waited < $max_wait_time) {
                usleep($wait_interval * 1000); // 转换为微秒
                $waited += $wait_interval;

                // 检查是否需要强制刷新
                if ($waited >= 50 && $this->api_merger->get_queue_size() > 0) {
                    $this->api_merger->force_flush();
                }
            }

            // 如果在等待时间内完成了
            if ($completed) {
                if ($exception) {
                    throw $exception;
                }
                if ($result !== null) {
                    return $result;
                }
            }

            // 如果仍未完成，强制刷新并回退
            if (!$completed) {
                $this->api_merger->force_flush();

                if (class_exists('Notion_Logger')) {
                    Notion_Logger::debug_log(
                        "智能合并超时，回退到直接API调用: {$endpoint}",
                        'API Merger Fallback'
                    );
                }
            }
        }

        // 回退到直接发送请求
        return $this->send_request($endpoint, $method, $data);
    }

    /**
     * 向 Notion API 发送请求。
     * 这是一个通用的方法，用于处理所有类型的 API 请求。
     * @since    1.0.8
     * @param    string    $endpoint    API 端点，不包含基础 URL。
     * @param    string    $method      HTTP 请求方法 (e.g., 'GET', 'POST')。
     * @param    array<string, mixed>     $data        要发送的请求数据。
     * @return   array<string, mixed>                  解码后的 JSON 响应。
     * @throws   Exception             如果 API 请求失败或返回错误。
     */
    public function send_request(string $endpoint, string $method = 'GET', array $data = []): array {
        // 🚀 性能优化：检查会话缓存
        if ($method === 'GET' && class_exists('Notion_Session_Cache')) {
            $cached_response = Notion_Session_Cache::get_cached_api_response($endpoint, $data);
            if ($cached_response !== null) {
                return $cached_response;
            }
        }

        $url = $this->api_base . $endpoint;
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization'  => 'Bearer ' . $this->api_key,
                'Content-Type'   => 'application/json',
                'Notion-Version' => '2022-06-28'
            ],
            'timeout' => 45,  // 增加超时时间以处理SSL连接问题
            'sslverify' => false,  // 在Windows环境下禁用SSL验证以避免SSL_ERROR_SYSCALL错误
            'httpversion' => '1.1',  // 强制使用HTTP/1.1避免HTTP/2问题
            'user-agent' => 'Notion-to-WordPress/2.0.0-beta.1 (WordPress)',
            'redirection' => 5,
            'blocking' => true,
            // 添加额外的cURL选项来改善Windows SSL兼容性
            'curl' => [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TCP_NODELAY => true,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true
            ]
        ];

        if (!empty($data) && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }

        // 使用重试机制处理SSL连接问题
        $max_retries = 3;
        $retry_delay = 1; // 秒
        $last_error = null;

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_request($url, $args);

            if (!is_wp_error($response)) {
                break; // 成功，退出重试循环
            }

            $error_message = $response->get_error_message();
            $last_error = $response;

            // 检查是否为SSL相关错误
            if (strpos($error_message, 'SSL_ERROR_SYSCALL') !== false ||
                strpos($error_message, 'SSL_connect') !== false ||
                strpos($error_message, 'cURL error 35') !== false) {

                Notion_Logger::debug_log(
                    "SSL连接错误 (尝试 {$attempt}/{$max_retries}): {$error_message}",
                    'SSL Retry'
                );

                if ($attempt < $max_retries) {
                    sleep($retry_delay);
                    $retry_delay *= 2; // 指数退避
                    continue;
                }
            } else {
                // 非SSL错误，直接退出
                break;
            }
        }

        $response = $last_error ?: $response;

        if (is_wp_error($response)) {
            throw new Exception(__('API请求失败: ', 'notion-to-wordpress') . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            $error_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $error_body['message'] ?? wp_remote_retrieve_body($response);
            throw new Exception(__('API错误 (', 'notion-to-wordpress') . $response_code . '): ' . $error_message);
        }

        $body = wp_remote_retrieve_body($response);
        $decoded_response = json_decode($body, true) ?: [];

        // 🚀 性能优化：智能缓存GET请求的响应
        if ($method === 'GET' && class_exists('Notion_Session_Cache')) {
            // 根据端点类型设置不同的缓存时间
            $cache_ttl = 300; // 默认5分钟
            if (strpos($endpoint, '/children') !== false) {
                $cache_ttl = 600; // 子内容缓存10分钟
            } elseif (strpos($endpoint, '/databases/') !== false) {
                $cache_ttl = 900; // 数据库查询缓存15分钟
            }

            Notion_Session_Cache::cache_api_response($endpoint, $data, $decoded_response, $cache_ttl);
        }

        return $decoded_response;
    }

    /**
     * 获取指定数据库中的所有页面（处理分页）。
     *
     * @since    1.0.8
     * @param    string    $database_id    Notion 数据库的 ID。
     * @param    array<string, mixed>     $filter         应用于查询的筛选条件。
     * @param    bool      $with_details   是否获取页面详细信息（包括cover、icon等）。
     * @return   array<string, mixed>                     页面对象数组。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_database_pages(string $database_id, array $filter = [], bool $with_details = false): array {

        Notion_Logger::debug_log(
            '获取数据库页面（实时）: ' . $database_id . ', 详细信息: ' . ($with_details ? '是' : '否'),
            'Database Pages'
        );

        $all_results = [];
        $has_more = true;
        $start_cursor = null;

        while ($has_more) {
            $endpoint = 'databases/' . $database_id . '/query';
            // 智能分页大小优化：根据数据量动态调整
            $options = get_option('notion_to_wordpress_options', []);
            $base_page_size = $options['api_page_size'] ?? 100;
            
            // 动态优化：首次请求使用较大分页，后续根据结果调整
            $page_size = empty($all_results) ? min(100, $base_page_size) : min(100, $base_page_size * 1.5);

            $data = [
                'page_size' => $page_size
            ];

            if ($this->is_valid_filter($filter)) {
                $data['filter'] = $filter;
            }

            if ($start_cursor) {
                $data['start_cursor'] = $start_cursor;
            }

            $response = $this->send_request($endpoint, 'POST', $data);

            if (isset($response['results'])) {
                $all_results = array_merge($all_results, $response['results']);
                Notion_Logger::debug_log(
                    '获取数据库页面批次: ' . count($response['results']) . ', 总计: ' . count($all_results),
                    'Database Pages'
                );
            }

            $has_more = $response['has_more'] ?? false;
            $start_cursor = $response['next_cursor'] ?? null;
        }

        // 如果需要详细信息，批量获取页面详情
        if ($with_details && !empty($all_results)) {
            $all_results = $this->enrich_pages_with_details($all_results);
        }

        Notion_Logger::debug_log(
            '数据库页面获取完成，总数: ' . count($all_results) . ', 详细信息: ' . ($with_details ? '是' : '否'),
            'Database Pages'
        );



        return $all_results;
    }

    /**
     * 递归获取一个块的所有子块内容，优化版本。
     *
     * @since    1.0.8
     * @param    string    $block_id    块或页面的 ID。
     * @param    int       $depth       当前递归深度，用于限制递归层数。
     * @param    int       $max_depth   最大递归深度，默认为5层。
     * @return   array<string, mixed>   子块对象数组。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_page_content(string $block_id, int $depth = 0, int $max_depth = 5): array {
        // 🚀 性能优化：使用批量并发获取替代递归
        if ($depth === 0) {
            return $this->get_page_content_batch_optimized($block_id, $max_depth);
        }

        // 检查递归深度限制
        if ($depth >= $max_depth) {
            return [];
        }

        $blocks = $this->get_block_children($block_id);

        foreach ($blocks as $i => $block) {
            if ($block['has_children']) {
                // 跳过已知会导致404错误的块类型
                if (isset($block['type']) && in_array($block['type'], [
                    'child_database',
                    'child_page',
                    'link_preview',
                    'unsupported'
                ])) {
                    continue;
                }

                try {
                    $blocks[$i]['children'] = $this->get_page_content($block['id'], $depth + 1, $max_depth);
                } catch (Exception $e) {
                    // 快速跳过404错误，不记录详细日志
                    if (strpos($e->getMessage(), '404') !== false) {
                        continue;
                    }
                    // 对于其他类型的错误，重新抛出
                    throw $e;
                }
            }
        }

        return $blocks;
    }

    /**
     * 🚀 批量并发获取页面内容（高性能版本）
     *
     * 使用广度优先遍历 + 批量API调用，替代递归方式
     *
     * @param string $page_id 页面ID
     * @param int $max_depth 最大深度
     * @return array 完整的内容块数组
     */
    private function get_page_content_batch_optimized(string $page_id, int $max_depth = 5): array {
        $all_blocks = [];
        $blocks_to_process = [$page_id => 0]; // block_id => depth
        $processed_blocks = [];
        $start_time = microtime(true);
        $timeout_limit = 18; // 18秒超时保护

        while (!empty($blocks_to_process) && count($processed_blocks) < 1000) { // 安全限制
            // 🚀 超时保护：避免深层递归导致超时
            if ((microtime(true) - $start_time) > $timeout_limit) {
                Notion_Logger::warning_log(
                    "批量内容获取超时保护触发，已处理 " . count($processed_blocks) . " 个块",
                    'Timeout Protection'
                );
                break;
            }
            $current_batch = [];
            $current_depths = [];

            // 收集当前批次要处理的块
            foreach ($blocks_to_process as $block_id => $depth) {
                if ($depth < $max_depth && !isset($processed_blocks[$block_id])) {
                    $current_batch[] = $block_id;
                    $current_depths[$block_id] = $depth;
                    $processed_blocks[$block_id] = true;
                }
            }

            if (empty($current_batch)) {
                break;
            }

            // 🚀 批量获取所有块的子内容
            try {
                $batch_results = $this->batch_get_block_children_optimized($current_batch);

                foreach ($batch_results as $block_id => $children) {
                    $depth = $current_depths[$block_id];

                    if ($block_id === $page_id) {
                        // 根页面的内容
                        $all_blocks = $children;
                    } else {
                        // 找到父块并添加子内容
                        $this->attach_children_to_parent($all_blocks, $block_id, $children);
                    }

                    // 收集下一层需要处理的块
                    foreach ($children as $child) {
                        if ($child['has_children'] && !isset($processed_blocks[$child['id']])) {
                            // 跳过已知问题块类型
                            if (!isset($child['type']) || !in_array($child['type'], [
                                'child_database', 'child_page', 'link_preview', 'unsupported'
                            ])) {
                                $blocks_to_process[$child['id']] = $depth + 1;
                            }
                        }
                    }
                }

            } catch (Exception $e) {
                Notion_Logger::warning_log(
                    '批量获取块内容失败: ' . $e->getMessage(),
                    'Batch Content'
                );
                break;
            }

            // 清理已处理的块
            foreach ($current_batch as $block_id) {
                unset($blocks_to_process[$block_id]);
            }
        }

        return $all_blocks;
    }

    /**
     * 将子内容附加到父块
     *
     * @param array &$blocks 块数组（引用传递）
     * @param string $parent_id 父块ID
     * @param array $children 子块数组
     */
    private function attach_children_to_parent(array &$blocks, string $parent_id, array $children): void {
        foreach ($blocks as &$block) {
            if ($block['id'] === $parent_id) {
                $block['children'] = $children;
                return;
            }

            if (isset($block['children']) && is_array($block['children'])) {
                $this->attach_children_to_parent($block['children'], $parent_id, $children);
            }
        }
    }

    /**
     * 🚀 批量获取多个块的子内容（优化版本）
     *
     * @param array $block_ids 块ID数组
     * @return array 块ID => 子内容数组的映射
     */
    private function batch_get_block_children_optimized(array $block_ids): array {
        if (empty($block_ids)) {
            return [];
        }

        // 🚀 智能分批：根据测试结果，每批5个以避免超时
        $batch_size = 5;
        $all_results = [];
        $batches = array_chunk($block_ids, $batch_size);

        foreach ($batches as $batch_index => $batch) {
            try {
                Notion_Logger::debug_log(
                    "处理批次 " . ($batch_index + 1) . "/" . count($batches) . "，包含 " . count($batch) . " 个块",
                    'Batch Optimization'
                );

                $batch_results = $this->batch_get_block_children($batch);
                $all_results = array_merge($all_results, $batch_results);

                // 批次间短暂延迟，避免API限制
                if ($batch_index < count($batches) - 1) {
                    usleep(200000); // 0.2秒延迟
                }

            } catch (Exception $e) {
                Notion_Logger::warning_log(
                    "批次 " . ($batch_index + 1) . " 处理失败，回退到单个处理: " . $e->getMessage(),
                    'Batch Fallback'
                );

                // 回退到单个处理
                foreach ($batch as $block_id) {
                    try {
                        $all_results[$block_id] = $this->get_block_children($block_id);
                    } catch (Exception $e) {
                        $all_results[$block_id] = [];
                    }
                }
            }
        }

        return $all_results;
    }

    /**
     * 并发获取块子内容
     *
     * @param array $block_ids 块ID数组
     * @return array 结果数组
     */
    private function concurrent_get_block_children(array $block_ids): array {
        $requests = [];
        $results = [];

        // 准备并发请求
        foreach ($block_ids as $block_id) {
            $requests[] = [
                'url' => $this->api_base . "blocks/{$block_id}/children",
                'method' => 'GET',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                    'Notion-Version' => '2022-06-28'
                ],
                'block_id' => $block_id
            ];
        }

        // 发送并发请求
        try {
            $responses = $this->batch_send_requests($requests);

            foreach ($responses as $i => $response) {
                $block_id = $requests[$i]['block_id'];

                if (isset($response['results'])) {
                    $results[$block_id] = $response['results'];
                } else {
                    $results[$block_id] = [];
                }
            }

        } catch (Exception $e) {
            // 并发失败时回退到串行处理
            Notion_Logger::warning_log(
                '并发获取失败，回退到串行: ' . $e->getMessage(),
                'Concurrent Fallback'
            );

            foreach ($block_ids as $block_id) {
                try {
                    $results[$block_id] = $this->get_block_children($block_id);
                } catch (Exception $e) {
                    $results[$block_id] = [];
                }
            }
        }

        return $results;
    }

    /**
     * 获取一个块的直接子块（处理分页）。
     *
     * @since 1.0.8
     * @param string $block_id 块的 ID。
     * @return array<string, mixed> 子块对象数组。
     * @throws Exception 如果 API 请求失败。
     */
    public function get_block_children(string $block_id): array {
        $all_results = [];
        $has_more = true;
        $start_cursor = null;

        while ($has_more) {
            // 使用最大页面大小以减少API调用次数
            $endpoint = 'blocks/' . $block_id . '/children?page_size=100';
            if ($start_cursor) {
                $endpoint .= '&start_cursor=' . $start_cursor;
            }

            try {
                // 🚀 性能优化：使用智能合并发送请求
                $response = $this->send_request_with_merging($endpoint, 'GET');

                if (isset($response['results'])) {
                    $all_results = array_merge($all_results, $response['results']);
                }

                $has_more = $response['has_more'] ?? false;
                $start_cursor = $response['next_cursor'] ?? null;

            } catch (Exception $e) {
                // 快速跳过404错误，不记录详细日志
                if (strpos($e->getMessage(), '404') !== false) {
                    break;
                }

                // 对于其他错误，记录并重新抛出
                Notion_Logger::error_log(
                    'get_block_children异常: ' . $e->getMessage(),
                    'API Error'
                );
                throw $e;
            }
        }

        return $all_results;
    }

    /**
     * 获取数据库的元数据。
     *
     * @since 1.0.8
     * @param string $database_id Notion 数据库的 ID。
     * @return array<string, mixed> 数据库对象。
     * @throws Exception 如果 API 请求失败。
     */
    public function get_database(string $database_id): array
    {
        $endpoint = 'databases/' . $database_id;
        return $this->send_request($endpoint);
    }

    /**
     * 获取页面的元数据 - 总是返回最新数据（移除缓存以支持增量同步）
     *
     * @since    1.0.8
     * @param    string    $page_id    Notion 页面的 ID。
     * @return   array<string, mixed>                 页面对象。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_page_metadata(string $page_id): array {
        $endpoint = 'pages/' . $page_id;
        $result = $this->send_request($endpoint);

        Notion_Logger::debug_log(
            '获取页面元数据（实时）: ' . $page_id,
            'Page Metadata'
        );

        return $result;
    }

    /**
     * 检查与 Notion API 的连接是否正常。
     *
     * @since    1.0.8
     * @return   bool       如果连接成功，则返回 true，否则返回 false。
     */
    public function check_connection(): bool {
        try {
            $endpoint = 'users/me';
            $this->send_request($endpoint);
            return true;
        } catch (Exception $e) {
            // 可以在这里记录具体的错误信息 $e->getMessage()
            return false;
        }
    }

    /**
     * 测试 API 连接并返回详细的错误信息。
     *
     * @since    1.0.8
     * @param    string    $database_id    要测试的数据库 ID（可选）。
     * @return   true|WP_Error           如果成功，则返回 true；如果失败，则返回 WP_Error 对象。
     */
    public function test_connection(string $database_id = '') {
        try {
            // 1. 检查API密钥本身是否有效
            $this->send_request('users/me');

            // 2. 如果提供了数据库ID，检查数据库是否可访问
            if (!empty($database_id)) {
                $this->get_database($database_id);
            }

            return true;
        } catch (Exception $e) {
            return new WP_Error('connection_failed', __('连接测试失败: ', 'notion-to-wordpress') . $e->getMessage());
        }
    }

    /**
     * 获取单个页面对象 - 总是返回最新数据（移除缓存以支持增量同步）
     *
     * @param string $page_id 页面ID
     * @return array<string, mixed>
     * @throws Exception
     */
    public function get_page(string $page_id): array {
        $endpoint = 'pages/' . $page_id;
        $result = $this->send_request($endpoint);

        Notion_Logger::debug_log(
            '获取页面数据（实时）: ' . $page_id,
            'Page Data'
        );

        return $result;
    }

    /**
     * 安全获取数据库信息，支持优雅降级 - 总是返回最新数据
     *
     * @since 1.0.9
     * @param string $database_id 数据库ID
     * @return array<string, mixed> 数据库信息数组，失败时返回空数组
     */
    public function get_database_info(string $database_id): array {
            try {
                $database_info = $this->get_database($database_id);

                Notion_Logger::debug_log(
                    '数据库信息获取成功（实时）: ' . $database_id,
                    'Database Info'
                );

                return $database_info;
            } catch (Exception $e) {
                Notion_Logger::debug_log(
                    '数据库信息获取失败: ' . $e->getMessage(),
                    'Database Info'
                );
                return [];
            }
    }

    /**
     * 获取页面详细信息，包括cover、icon等完整属性 - 总是返回最新数据
     *
     * @since 1.1.1
     * @param string $page_id 页面ID
     * @return array<string, mixed> 页面详细信息数组，失败时返回空数组
     */
    public function get_page_details(string $page_id): array {
            try {
                $page_data = $this->get_page($page_id);

            Notion_Logger::debug_log(
                '获取页面详情（实时）: ' . $page_id,
                'Page Details'
            );

                Notion_Logger::debug_log(
                    '页面详情获取成功: ' . $page_id . ', 包含cover: ' . (isset($page_data['cover']) ? '是' : '否') . ', 包含icon: ' . (isset($page_data['icon']) ? '是' : '否'),
                    'Page Details'
                );

                return $page_data;
            } catch (Exception $e) {
                Notion_Logger::error_log(
                    '页面详情获取失败: ' . $page_id . ', 错误: ' . $e->getMessage(),
                    'Page Details'
                );
                return [];
            }
    }

    /**
     * 批量为页面添加详细信息 - 优化版本
     *
     * @since 1.1.1
     * @param array<string, mixed> $pages 页面数组
     * @return array<string, mixed> 包含详细信息的页面数组
     */
    private function enrich_pages_with_details(array $pages): array {
        // 对于大量页面，跳过详细信息获取以提高性能
        if (count($pages) > 20) {
            Notion_Logger::debug_log(
                '页面数量过多(' . count($pages) . ')，跳过详细信息获取以提高性能',
                'Performance Optimization'
            );
            return $pages;
        }

        $enriched_pages = [];
        $failed_count = 0;
        $max_failures = 5; // 最多允许5次失败

        foreach ($pages as $page) {
            $page_id = $page['id'] ?? '';
            if (empty($page_id)) {
                $enriched_pages[] = $page;
                continue;
            }

            // 如果失败次数过多，跳过剩余页面的详细信息获取
            if ($failed_count >= $max_failures) {
                $enriched_pages[] = $page;
                continue;
            }

            try {
                // 获取页面详细信息
                $page_details = $this->get_page_details($page_id);

                if (!empty($page_details)) {
                    // 合并基本信息和详细信息
                    $enriched_page = array_merge($page, [
                        'cover' => $page_details['cover'] ?? null,
                        'icon' => $page_details['icon'] ?? null,
                        'url' => $page_details['url'] ?? $page['url'] ?? null,
                    ]);
                    $enriched_pages[] = $enriched_page;
                } else {
                    $enriched_pages[] = $page;
                    $failed_count++;
                }
            } catch (Exception $e) {
                // 快速跳过失败的页面，不记录详细日志
                $enriched_pages[] = $page;
                $failed_count++;
            }
        }

        return $enriched_pages;
    }

    // ========================================
    // 批量并发请求方法
    // ========================================

    /**
     * 批量发送API请求（并发处理）
     *
     * @since    1.9.0-beta.1
     * @param    array     $endpoints    API端点数组
     * @param    string    $method       HTTP方法
     * @param    array     $data_array   请求数据数组（可选）
     * @param    int       $max_retries  最大重试次数
     * @param    int       $base_delay   基础延迟时间（毫秒）
     * @return   array                   响应结果数组
     * @throws   Exception               如果批量请求失败
     */
    public function batch_send_requests(array $endpoints, string $method = 'GET', array $data_array = [], int $max_retries = 2, int $base_delay = 1000): array {
        if (empty($endpoints)) {
            return [];
        }

        $start_time = microtime(true);

        // 记录批量API请求开始
        Notion_Logger::debug_log(
            sprintf('开始批量API请求: %d个端点，方法: %s', count($endpoints), $method),
            'Batch API'
        );

        try {
            // 从配置中获取并发数，使用自适应调整
            $options = get_option('notion_to_wordpress_options', []);

            if (class_exists('Notion_Memory_Manager')) {
                $concurrent_requests = Notion_Memory_Manager::get_concurrent_limit();

                if (class_exists('Notion_Logger')) {
                    Notion_Logger::debug_log(
                        "自适应并发数: {$concurrent_requests} (API请求)",
                        'API Concurrent'
                    );
                }
            } else {
                $concurrent_requests = $options['concurrent_requests'] ?? 5;
            }

            // 创建并发网络管理器
            $manager = new Notion_Concurrent_Network_Manager($concurrent_requests);

            // 添加所有请求到队列
            foreach ($endpoints as $index => $endpoint) {
                $url = $this->api_base . $endpoint;
                $data = $data_array[$index] ?? [];

                // 根据并发数动态调整超时时间
                $timeout = 30; // 默认30秒
                if ($concurrent_requests > 8) {
                    $timeout = 45; // 高并发时增加超时时间
                } elseif ($concurrent_requests <= 3) {
                    $timeout = 20; // 低并发时减少超时时间
                }

                $args = [
                    'method'  => $method,
                    'headers' => [
                        'Authorization'  => 'Bearer ' . $this->api_key,
                        'Content-Type'   => 'application/json',
                        'Notion-Version' => '2022-06-28'
                    ],
                    'timeout' => $timeout
                ];

                if (!empty($data) && $method !== 'GET') {
                    $args['body'] = json_encode($data);
                }

                $manager->add_request($url, $args);
            }

            // 执行并发请求（带重试）
            $responses = $manager->execute_with_retry($max_retries, $base_delay);

            // 处理响应结果
            $results = [];
            $success_count = 0;
            $error_count = 0;

            foreach ($responses as $index => $response) {
                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message();
                    $results[$index] = new Exception("批量请求失败 (#{$index}): " . $error_message);
                    $error_count++;

                    Notion_Logger::error_log(
                        "批量请求失败 (#{$index}): {$error_message}",
                        'Batch API'
                    );
                } else {
                    $response_code = $response['response']['code'];

                    if ($response_code < 200 || $response_code >= 300) {
                        $error_body = json_decode($response['body'], true);
                        $error_message = $error_body['message'] ?? $response['body'];
                        $results[$index] = new Exception("API错误 (#{$index}, {$response_code}): " . $error_message);
                        $error_count++;

                        Notion_Logger::error_log(
                            "批量请求API错误 (#{$index}): {$response_code} - {$error_message}",
                            'Batch API'
                        );
                    } else {
                        $body = json_decode($response['body'], true) ?: [];
                        $results[$index] = $body;
                        $success_count++;
                    }
                }
            }

            $execution_time = microtime(true) - $start_time;

            Notion_Logger::debug_log(
                sprintf(
                    '批量API请求完成: 成功 %d, 失败 %d, 耗时 %.2f秒',
                    $success_count,
                    $error_count,
                    $execution_time
                ),
                'Batch API'
            );

            return $results;

        } catch (Exception $e) {
            Notion_Logger::error_log(
                '批量API请求异常: ' . $e->getMessage(),
                'Batch API'
            );
            throw $e;
        }
    }

    /**
     * 批量获取页面详情
     *
     * @since    1.9.0-beta.1
     * @param    array    $page_ids    页面ID数组
     * @return   array                 页面详情数组，键为页面ID
     */
    public function batch_get_pages(array $page_ids): array {
        if (empty($page_ids)) {
            return [];
        }

        // 禁用缓存，直接进行API请求以确保数据实时性
        Notion_Logger::debug_log(
            sprintf('批量获取页面（无缓存）: 总计 %d', count($page_ids)),
            'Batch Pages'
        );

        // 构建批量请求端点
        $endpoints = [];
        foreach ($page_ids as $page_id) {
            $endpoints[] = 'pages/' . $page_id;
        }

        try {
            // 执行批量请求
            $responses = $this->batch_send_requests($endpoints);

            // 处理响应（无缓存）
            $fetched_pages = [];
            foreach ($responses as $index => $response) {
                $page_id = $page_ids[$index];

                if ($response instanceof Exception) {
                    Notion_Logger::error_log(
                        "获取页面失败 ({$page_id}): " . $response->getMessage(),
                        'Batch Pages'
                    );
                    continue;
                }

                $fetched_pages[$page_id] = $response;
            }

            return $fetched_pages;

        } catch (Exception $e) {
            Notion_Logger::error_log(
                '批量获取页面异常: ' . $e->getMessage(),
                'Batch Pages'
            );

            return [];
        }
    }

    /**
     * 批量获取块内容
     *
     * @since    1.9.0-beta.1
     * @param    array    $block_ids    块ID数组
     * @return   array                  块内容数组，键为块ID
     */
    public function batch_get_block_children(array $block_ids): array {
        if (empty($block_ids)) {
            return [];
        }

        Notion_Logger::debug_log(
            sprintf('批量获取块内容: %d个块', count($block_ids)),
            'Batch Blocks'
        );

        // 构建批量请求端点
        $endpoints = [];
        foreach ($block_ids as $block_id) {
            $endpoints[] = 'blocks/' . $block_id . '/children';
        }

        try {
            // 执行批量请求
            $responses = $this->batch_send_requests($endpoints);

            // 处理响应
            $block_contents = [];
            foreach ($responses as $index => $response) {
                $block_id = $block_ids[$index];

                if ($response instanceof Exception) {
                    Notion_Logger::error_log(
                        "获取块内容失败 ({$block_id}): " . $response->getMessage(),
                        'Batch Blocks'
                    );
                    $block_contents[$block_id] = [];
                    continue;
                }

                $block_contents[$block_id] = $response['results'] ?? [];
            }

            return $block_contents;

        } catch (Exception $e) {
            Notion_Logger::error_log(
                '批量获取块内容异常: ' . $e->getMessage(),
                'Batch Blocks'
            );

            // 返回空数组
            $empty_results = [];
            foreach ($block_ids as $block_id) {
                $empty_results[$block_id] = [];
            }
            return $empty_results;
        }
    }

    /**
     * 批量查询数据库
     *
     * @since    1.9.0-beta.1   
     * @param    array    $database_ids    数据库ID数组
     * @param    array    $filters         筛选条件数组（可选）
     * @return   array                     数据库查询结果数组，键为数据库ID
     */
    public function batch_query_databases(array $database_ids, array $filters = []): array {
        if (empty($database_ids)) {
            return [];
        }

        Notion_Logger::debug_log(
            sprintf('批量查询数据库: %d个数据库', count($database_ids)),
            'Batch Databases'
        );

        // 构建批量请求端点和数据
        $endpoints = [];
        $data_array = [];

        foreach ($database_ids as $index => $database_id) {
            $endpoints[] = 'databases/' . $database_id . '/query';
            $data_array[] = $filters[$index] ?? [];
        }

        try {
            // 执行批量POST请求
            $responses = $this->batch_send_requests($endpoints, 'POST', $data_array);

            // 处理响应
            $database_results = [];
            foreach ($responses as $index => $response) {
                $database_id = $database_ids[$index];

                if ($response instanceof Exception) {
                    Notion_Logger::error_log(
                        "查询数据库失败 ({$database_id}): " . $response->getMessage(),
                        'Batch Databases'
                    );
                    $database_results[$database_id] = [];
                    continue;
                }

                $database_results[$database_id] = $response['results'] ?? [];
            }

            return $database_results;

        } catch (Exception $e) {
            Notion_Logger::error_log(
                '批量查询数据库异常: ' . $e->getMessage(),
                'Batch Databases'
            );

            // 返回空数组
            $empty_results = [];
            foreach ($database_ids as $database_id) {
                $empty_results[$database_id] = [];
            }
            return $empty_results;
        }
    }

    /**
     * 批量获取数据库信息
     *
     * @since    1.9.0-beta.1
     * @param    array    $database_ids    数据库ID数组
     * @return   array                     数据库信息数组，键为数据库ID
     */
    public function batch_get_databases(array $database_ids): array {
        if (empty($database_ids)) {
            return [];
        }

        // 禁用缓存，直接进行API请求以确保数据实时性
        Notion_Logger::debug_log(
            sprintf('批量获取数据库信息（无缓存）: 总计 %d', count($database_ids)),
            'Batch Database Info'
        );

        // 构建批量请求端点
        $endpoints = [];
        foreach ($database_ids as $database_id) {
            $endpoints[] = 'databases/' . $database_id;
        }

        try {
            // 执行批量请求
            $responses = $this->batch_send_requests($endpoints);

            // 处理响应（无缓存）
            $fetched_databases = [];
            foreach ($responses as $index => $response) {
                $database_id = $database_ids[$index];

                if ($response instanceof Exception) {
                    Notion_Logger::error_log(
                        "获取数据库信息失败 ({$database_id}): " . $response->getMessage(),
                        'Batch Database Info'
                    );
                    continue;
                }

                $fetched_databases[$database_id] = $response;
            }

            return $fetched_databases;

        } catch (Exception $e) {
            Notion_Logger::error_log(
                '批量获取数据库信息异常: ' . $e->getMessage(),
                'Batch Database Info'
            );

            // 返回空数组
            return [];
        }
    }

    /**
     * 智能增量获取数据库页面（API层前置过滤）
     *
     * 在API层面过滤变更内容，避免拉取全量数据后本地过滤的带宽浪费
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param string $last_sync_time 最后同步时间（ISO 8601格式）
     * @param array $additional_filters 额外的过滤条件
     * @param bool $with_details 是否获取详细信息
     * @return array 过滤后的页面数组
     */
    public function smart_incremental_fetch(string $database_id, string $last_sync_time = '', array $additional_filters = [], bool $with_details = false): array {
        // 开始性能监控
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);

        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::start_timer('smart_incremental_fetch');
        }

        // 构建时间戳过滤器
        $time_filter = [];
        if (!empty($last_sync_time)) {
            // 确保时间格式正确
            $formatted_time = $this->format_timestamp_for_api($last_sync_time);

            // 只有在格式化后的时间有效时才创建过滤器
            if (!empty($formatted_time)) {
                // 根据官方文档：https://developers.notion.com/reference/post-database-query-filter#timestamp
                $time_filter = [
                    'timestamp' => 'last_edited_time',
                    'last_edited_time' => [
                        'after' => $formatted_time
                    ]
                ];
            }
        }

        // 构建复合过滤器
        $filters = [];

        // 添加时间过滤器
        if (!empty($time_filter)) {
            $filters[] = $time_filter;
        }

        // 添加额外的过滤条件
        foreach ($additional_filters as $filter) {
            $filters[] = $filter;
        }

        // 构建最终的过滤器结构
        $final_filter = [];
        if (count($filters) === 1) {
            $final_filter = $filters[0];
        } elseif (count($filters) > 1) {
            $final_filter = ['and' => $filters];
        }

        // 验证最终过滤器的有效性
        if (!$this->is_valid_filter($final_filter)) {
            // 如果过滤器无效，记录警告并使用无过滤器的查询
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    sprintf(
                        'API层增量过滤器无效，使用全量查询: 数据库=%s, 时间戳=%s',
                        $database_id,
                        $last_sync_time ?: '无'
                    ),
                    'Smart Incremental Fetch'
                );
            }
            $final_filter = []; // 清空过滤器，使用全量查询
        }

        // 记录过滤器信息
        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf(
                    'API层增量过滤: 数据库=%s, 时间戳=%s, 额外过滤器=%d个',
                    $database_id,
                    $last_sync_time ?: '无',
                    count($additional_filters)
                ),
                'Smart Incremental Fetch'
            );
        }

        try {
            // 使用过滤器获取数据（只有在有有效过滤器时才传递）
            $filtered_pages = $this->is_valid_filter($final_filter) 
                ? $this->get_database_pages($database_id, $final_filter, $with_details)
                : $this->get_database_pages($database_id, [], $with_details);

            // 记录过滤效果统计
            $processing_time = microtime(true) - $start_time;
            $memory_used = memory_get_usage(true) - $start_memory;

            if (class_exists('Notion_Performance_Monitor')) {
                Notion_Performance_Monitor::end_timer('smart_incremental_fetch');
                Notion_Performance_Monitor::record_custom_metric('incremental_fetch_time', $processing_time);
                Notion_Performance_Monitor::record_custom_metric('incremental_fetch_count', count($filtered_pages));
                Notion_Performance_Monitor::record_custom_metric('incremental_fetch_memory', $memory_used);
            }

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf(
                        'API层过滤完成: 获取%d个页面, 耗时%.3fs, 内存%s',
                        count($filtered_pages),
                        $processing_time,
                        $this->format_bytes($memory_used)
                    ),
                    'Smart Incremental Fetch'
                );
            }

            return $filtered_pages;

        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('API层增量过滤失败: %s', $e->getMessage()),
                    'Smart Incremental Fetch'
                );
            }

            // 失败时回退到无过滤的查询
            return $this->get_database_pages($database_id, [], $with_details);
        }
    }

    /**
     * 批量增量获取多个数据库的页面
     *
     * 为多个数据库同时执行增量同步，提升效率
     *
     * @since 2.0.0-beta.1
     * @param array $database_configs 数据库配置数组 [database_id => [last_sync_time, filters]]
     * @param bool $with_details 是否获取详细信息
     * @return array [database_id => pages] 映射
     */
    public function batch_smart_incremental_fetch(array $database_configs, bool $with_details = false): array {
        $results = [];
        $start_time = microtime(true);

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf('开始批量增量获取 %d 个数据库', count($database_configs)),
                'Batch Incremental Fetch'
            );
        }

        foreach ($database_configs as $database_id => $config) {
            $last_sync_time = $config['last_sync_time'] ?? '';
            $additional_filters = $config['filters'] ?? [];

            try {
                $results[$database_id] = $this->smart_incremental_fetch(
                    $database_id,
                    $last_sync_time,
                    $additional_filters,
                    $with_details
                );

            } catch (Exception $e) {
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::warning_log(
                        sprintf('数据库 %s 增量获取失败: %s', $database_id, $e->getMessage()),
                        'Batch Incremental Fetch'
                    );
                }
                $results[$database_id] = [];
            }
        }

        $total_time = microtime(true) - $start_time;
        $total_pages = array_sum(array_map('count', $results));

        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::record_custom_metric('batch_incremental_time', $total_time);
            Notion_Performance_Monitor::record_custom_metric('batch_incremental_databases', count($database_configs));
            Notion_Performance_Monitor::record_custom_metric('batch_incremental_total_pages', $total_pages);
        }

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf(
                    '批量增量获取完成: %d个数据库, 总计%d个页面, 耗时%.3fs',
                    count($database_configs),
                    $total_pages,
                    $total_time
                ),
                'Batch Incremental Fetch'
            );
        }

        return $results;
    }

    /**
     * 格式化时间戳为API兼容格式
     *
     * @since 2.0.0-beta.1
     * @param string $timestamp 时间戳
     * @return string 格式化后的时间戳
     */
    public function format_timestamp_for_api(string $timestamp): string {
        // 如果为空或无效，返回null以避免API错误
        if (empty($timestamp) || trim($timestamp) === '') {
            return '';
        }

        // 如果已经是ISO 8601格式，直接返回
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z?$/', $timestamp)) {
            // 确保时间戳以Z结尾（UTC格式）
            return rtrim($timestamp, 'Z') . 'Z';
        }

        // 尝试解析并转换为ISO 8601格式
        try {
            $date = new DateTime($timestamp);
            // 转换为UTC时间并格式化为ISO 8601
            $date->setTimezone(new DateTimeZone('UTC'));
            return $date->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            // 如果解析失败，记录错误并返回空字符串
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    "时间戳格式化失败: {$timestamp} - " . $e->getMessage(),
                    'API Time Format'
                );
            }
            return '';
        }
    }

    /**
     * 验证过滤器是否有效
     * 
     * 检查过滤器是否包含有效的过滤条件，避免传递空过滤器导致API错误
     *
     * @since 2.0.0-beta.1
     * @param array $filter 过滤器数组
     * @return bool 是否为有效过滤器
     */
    public function is_valid_filter(array $filter): bool {
        // 记录过滤器验证开始
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf(
                    "开始验证过滤器: %s",
                    json_encode($filter, JSON_UNESCAPED_UNICODE)
                ),
                'Filter Validation'
            );
        }

        // 如果过滤器为空，返回false
        if (empty($filter)) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    "无过滤器：将获取所有页面（首次同步或全量获取）",
                    'Filter Validation'
                );
            }
            return false;
        }

        // 检查是否包含有效的过滤条件
        $valid_filter_keys = [
            'and', 'or', 'title', 'rich_text', 'number', 'checkbox', 'select',
            'multi_select', 'status', 'date', 'people', 'files', 'url', 'email',
            'phone_number', 'relation', 'created_by', 'created_time',
            'last_edited_by', 'last_edited_time', 'formula', 'unique_id', 'rollup',
            'timestamp', 'property'  // 添加时间戳过滤器和属性过滤器支持
        ];

        $found_valid_keys = [];
        $invalid_keys = [];

        // 检查过滤器是否包含至少一个有效的键
        foreach ($filter as $key => $value) {
            if (in_array($key, $valid_filter_keys)) {
                if (!empty($value)) {
                    $found_valid_keys[] = $key;
                }
            } else {
                $invalid_keys[] = $key;
            }
        }

        // 记录详细的验证结果
        if (class_exists('Notion_Logger')) {
            if (!empty($found_valid_keys)) {
                Notion_Logger::debug_log(
                    sprintf(
                        "过滤器验证成功: 找到有效键 [%s]",
                        implode(', ', $found_valid_keys)
                    ),
                    'Filter Validation'
                );
            }

            if (!empty($invalid_keys)) {
                Notion_Logger::warning_log(
                    sprintf(
                        "过滤器包含无效键: [%s]",
                        implode(', ', $invalid_keys)
                    ),
                    'Filter Validation'
                );
            }

            if (empty($found_valid_keys)) {
                Notion_Logger::debug_log(
                    "过滤器验证失败: 未找到有效的过滤键",
                    'Filter Validation'
                );
            }
        }

        return !empty($found_valid_keys);
    }

    /**
     * 格式化字节数为可读格式
     *
     * @since 2.0.0-beta.1
     * @param int $bytes 字节数
     * @return string 格式化后的字符串
     */
    private function format_bytes(int $bytes): string {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . 'MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . 'KB';
        } else {
            return $bytes . 'B';
        }
    }

    /**
     * 获取增量同步统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public function get_incremental_sync_stats(): array {
        $stats = [
            'api_filter_enabled' => true,
            'supported_filters' => [
                'timestamp' => ['last_edited_time', 'created_time'],
                'property' => ['text', 'number', 'select', 'multi_select', 'date', 'checkbox'],
                'compound' => ['and', 'or']
            ],
            'performance_optimizations' => [
                'server_side_filtering' => true,
                'batch_processing' => true,
                'memory_optimization' => true
            ]
        ];

        if (class_exists('Notion_Performance_Monitor')) {
            $metrics = Notion_Performance_Monitor::get_metrics();
            $stats['recent_performance'] = [
                'last_fetch_time' => $metrics['incremental_fetch_time'] ?? 0,
                'last_fetch_count' => $metrics['incremental_fetch_count'] ?? 0,
                'last_memory_usage' => $metrics['incremental_fetch_memory'] ?? 0
            ];
        }

        return $stats;
    }

    /**
     * 并发获取数据库页面（高性能版本）
     *
     * 使用并发网络管理器实现真正的并发数据库页面获取
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $filter 过滤条件
     * @param bool $with_details 是否获取详细信息
     * @return array 页面数组
     */
    public function get_database_pages_concurrent(string $database_id, array $filter = [], bool $with_details = false): array {
        // 开始性能监控
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);

        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::start_timer('concurrent_database_fetch');
        }

        try {
            // 初始化并发网络管理器
            $concurrent_manager = new Notion_Concurrent_Network_Manager();
            $concurrent_manager->init_connection_pool();

            // 预估数据库大小
            $estimated_size = $concurrent_manager->estimate_database_size($database_id, $filter);

            // 计算最优并发数
            $optimal_concurrency = $concurrent_manager->calculate_optimal_concurrency($estimated_size);

            // 重新初始化管理器使用最优并发数
            $concurrent_manager = new Notion_Concurrent_Network_Manager($optimal_concurrency);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf(
                        '开始并发获取数据库页面: ID=%s, 预估大小=%d, 并发数=%d',
                        $database_id,
                        $estimated_size,
                        $optimal_concurrency
                    ),
                    'Concurrent Database Fetch'
                );
            }

            // 首先获取第一页来确定总页数
            $first_page_data = $this->get_single_page_data($database_id, $filter, null, 100);
            $all_results = $first_page_data['results'] ?? [];
            $has_more = $first_page_data['has_more'] ?? false;
            $next_cursor = $first_page_data['next_cursor'] ?? null;

            // 如果有更多页面，准备并发请求
            if ($has_more && $next_cursor) {
                $concurrent_requests = [];
                $cursors = [$next_cursor];

                // 预先获取几个cursor来准备并发请求
                $max_concurrent_pages = min($optimal_concurrency, 10); // 限制最大并发页数

                for ($i = 1; $i < $max_concurrent_pages && $has_more; $i++) {
                    $temp_data = $this->get_single_page_data($database_id, $filter, $next_cursor, 100);
                    $all_results = array_merge($all_results, $temp_data['results'] ?? []);
                    $has_more = $temp_data['has_more'] ?? false;
                    $next_cursor = $temp_data['next_cursor'] ?? null;

                    if ($has_more && $next_cursor) {
                        $cursors[] = $next_cursor;
                    }
                }

                // 如果还有更多页面，使用并发获取
                if ($has_more && count($cursors) > 1) {
                    foreach ($cursors as $cursor) {
                        if ($cursor) {
                            $request_data = [
                                'page_size' => 100,
                                'start_cursor' => $cursor
                            ];

                            if (!empty($filter)) {
                                $request_data['filter'] = $filter;
                            }

                            $concurrent_manager->add_request(
                                $this->api_base_url . 'databases/' . $database_id . '/query',
                                [
                                    'method' => 'POST',
                                    'headers' => $this->get_headers(),
                                    'body' => json_encode($request_data),
                                    'timeout' => 30
                                ]
                            );
                        }
                    }

                    // 执行并发请求
                    $concurrent_responses = $concurrent_manager->execute_with_retry();

                    // 处理并发响应
                    foreach ($concurrent_responses as $response) {
                        if ($response['success'] && !empty($response['body'])) {
                            $response_data = json_decode($response['body'], true);
                            if (isset($response_data['results'])) {
                                $all_results = array_merge($all_results, $response_data['results']);
                            }
                        }
                    }
                }
            }

            // 如果需要详细信息，批量获取页面详情
            if ($with_details && !empty($all_results)) {
                $all_results = $this->enrich_pages_with_details_concurrent($all_results, $concurrent_manager);
            }

            // 清理连接池
            $concurrent_manager->cleanup_connection_pool();

            // 记录性能统计
            $processing_time = microtime(true) - $start_time;
            $memory_used = memory_get_usage(true) - $start_memory;

            if (class_exists('Notion_Performance_Monitor')) {
                Notion_Performance_Monitor::end_timer('concurrent_database_fetch');
                Notion_Performance_Monitor::record_custom_metric('concurrent_fetch_time', $processing_time);
                Notion_Performance_Monitor::record_custom_metric('concurrent_fetch_count', count($all_results));
                Notion_Performance_Monitor::record_custom_metric('concurrent_fetch_memory', $memory_used);
            }

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf(
                        '并发获取完成: 获取%d个页面, 耗时%.3fs, 内存%s',
                        count($all_results),
                        $processing_time,
                        $this->format_bytes($memory_used)
                    ),
                    'Concurrent Database Fetch'
                );
            }

            return $all_results;

        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('并发数据库获取失败: %s', $e->getMessage()),
                    'Concurrent Database Fetch'
                );
            }

            // 失败时回退到标准方法
            return $this->get_database_pages($database_id, $filter, $with_details);
        }
    }

    /**
     * 获取单页数据
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $filter 过滤条件
     * @param string|null $start_cursor 起始游标
     * @param int $page_size 页面大小
     * @return array 页面数据
     */
    private function get_single_page_data(string $database_id, array $filter, ?string $start_cursor, int $page_size = 100): array {
        $endpoint = 'databases/' . $database_id . '/query';
        $data = ['page_size' => $page_size];

        if (!empty($filter)) {
            $data['filter'] = $filter;
        }

        if ($start_cursor) {
            $data['start_cursor'] = $start_cursor;
        }

        return $this->send_request($endpoint, 'POST', $data);
    }

    /**
     * 并发获取页面详细信息
     *
     * @since 2.0.0-beta.1
     * @param array $pages 页面数组
     * @param Notion_Concurrent_Network_Manager $concurrent_manager 并发管理器
     * @return array 包含详细信息的页面数组
     */
    private function enrich_pages_with_details_concurrent(array $pages, Notion_Concurrent_Network_Manager $concurrent_manager): array {
        if (empty($pages)) {
            return $pages;
        }

        // 为每个页面添加获取详细信息的请求
        $page_ids = [];
        foreach ($pages as $page) {
            $page_id = $page['id'];
            $page_ids[] = $page_id;

            $concurrent_manager->add_request(
                $this->api_base_url . 'pages/' . $page_id,
                [
                    'method' => 'GET',
                    'headers' => $this->get_headers(),
                    'timeout' => 20
                ]
            );
        }

        // 执行并发请求获取详细信息
        $detail_responses = $concurrent_manager->execute_with_retry();

        // 将详细信息合并到页面数据中
        foreach ($detail_responses as $index => $response) {
            if ($response['success'] && !empty($response['body'])) {
                $detail_data = json_decode($response['body'], true);
                if (isset($detail_data['id']) && isset($pages[$index])) {
                    // 合并详细信息到原始页面数据
                    $pages[$index] = array_merge($pages[$index], $detail_data);
                }
            }
        }

        return $pages;
    }
}