<?php
declare(strict_types=1);

/**
 * Notion API 交互类。
 * 封装了与 Notion API 通信的所有方法，包括获取数据库、页面、块等内容。
 * 处理 API 认证、请求发送、响应解析和错误处理。提供了完整的 Notion API
 * 功能封装，支持数据库查询、页面操作、内容同步等核心功能。
 *
 * @since      1.0.9
 * @version    1.8.3-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */
// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

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
     * 页面详细信息缓存
     *
     * @since    1.1.1
     * @access   private
     * @var      array<string, array>
     */
    private static array $page_cache = [];

    /**
     * 数据库页面缓存
     *
     * @since    1.1.1
     * @access   private
     * @var      array<string, array>
     */
    private static array $database_pages_cache = [];

    /**
     * 数据库信息缓存
     *
     * @since    1.1.1
     * @access   private
     * @var      array<string, array>
     */
    private static array $database_info_cache = [];

    /**
     * 缓存过期时间（秒）
     *
     * @since    1.1.1
     * @access   private
     * @var      int
     */
    private static int $cache_ttl = 1800; // 30分钟，提升缓存效率

    /**
     * 缓存时间戳
     *
     * @since    1.1.1
     * @access   private
     * @var      array<string, int>
     */
    private static array $cache_timestamps = [];

    /**
     * 分层缓存TTL配置
     *
     * @since    1.8.1
     * @access   private
     * @var      array<string, int>
     */
    private static array $cache_ttl_config = [
        'page_content' => 3600,      // 页面内容缓存：1小时
        'page_details' => 1800,      // 页面详情缓存：30分钟
        'database_pages' => 1800,    // 数据库页面列表：30分钟
        'database_info' => 7200,     // 数据库信息：2小时
        'image_urls' => 86400,       // 图片URL映射：24小时
        'block_children' => 3600,    // 子块内容：1小时
        'user_info' => 7200,         // 用户信息：2小时
        'default' => 1800            // 默认：30分钟
    ];

    /**
     * WordPress transients缓存过期时间（秒）
     *
     * @since    1.8.1
     * @access   private
     * @var      int
     */
    private static int $transient_cache_ttl = 7200; // 2小时，延长持久化缓存时间

    /**
     * 缓存统计信息
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private static array $cache_stats = [
        'memory_hits' => 0,
        'transient_hits' => 0,
        'cache_misses' => 0,
        'total_requests' => 0,
        'cache_preloads' => 0,
        'smart_cache_updates' => 0,
        'cache_evictions' => 0,
        'total_cache_operations' => 0
    ];

    /**
     * 缓存预热配置
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private static array $cache_preload_config = [
        'enabled' => true,
        'max_preload_items' => 100,
        'preload_on_startup' => true,
        'smart_preload' => true,
        'preload_popular_pages' => true
    ];

    /**
     * API请求合并统计
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private static array $request_merge_stats = [
        'total_requests' => 0,
        'merged_requests' => 0,
        'merge_ratio' => 0,
        'saved_api_calls' => 0,
        'batch_operations' => 0
    ];

    /**
     * 请求合并配置
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private static array $request_merge_config = [
        'enabled' => true,
        'max_batch_size' => 50,
        'merge_similar_requests' => true,
        'priority_requests' => ['page_details', 'page_content'],
        'delay_non_critical' => true
    ];

    /**
     * 并发管理器实例
     *
     * @since    1.8.1
     * @access   private
     * @var      Notion_Concurrent_Manager|null
     */
    private ?Notion_Concurrent_Manager $concurrent_manager = null;

    /**
     * 构造函数，初始化 API 客户端。
     *
     * @since    1.0.8
     * @param    string    $api_key    Notion API 密钥。
     */
    public function __construct(string $api_key) {
        $this->api_key = $api_key;

        // 添加HTTP请求过滤器来优化cURL配置
        add_filter('http_request_args', [$this, 'optimize_http_request_args'], 10, 2);
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
     * 向 Notion API 发送请求。
     *
     * 这是一个通用的私有方法，用于处理所有类型的 API 请求。
     *
     * @since    1.0.8
     * @access   private
     * @param    string    $endpoint    API 端点，不包含基础 URL。
     * @param    string    $method      HTTP 请求方法 (e.g., 'GET', 'POST')。
     * @param    array<string, mixed>     $data        要发送的请求数据。
     * @return   array<string, mixed>                  解码后的 JSON 响应。
     * @throws   Exception             如果 API 请求失败或返回错误。
     */
    private function send_request(string $endpoint, string $method = 'GET', array $data = []): array {
        $url = $this->api_base . $endpoint;

        // 特别记录数据库区块相关的API调用
        if (strpos($endpoint, 'blocks/') !== false && strpos($endpoint, 'children') !== false) {
            $block_id = str_replace(['blocks/', '/children'], '', explode('?', $endpoint)[0]);
            if ($block_id === '22a75443-76be-819a-8e2b-d82f3a78dc47') {
                error_log('Notion to WordPress: 检测到问题区块API调用: ' . $endpoint);
                Notion_To_WordPress_Helper::error_log(
                    '检测到问题区块API调用: ' . $endpoint . ', 调用栈: ' . wp_debug_backtrace_summary(),
                    'Database Block'
                );
            }
        }

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization'  => 'Bearer ' . $this->api_key,
                'Content-Type'   => 'application/json',
                'Notion-Version' => '2022-06-28'
            ],
            'timeout' => 30,
            'connect_timeout' => 10,
            'user-agent' => 'Notion-to-WordPress/' . NOTION_TO_WORDPRESS_VERSION,
            'sslverify' => true,
            'httpversion' => '1.1'
        ];

        if (!empty($data) && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }

        // 添加重试机制处理DNS超时问题 - 减少重试次数以提升速度
        $max_retries = 1;  // 从3次减少到1次
        $retry_delay = 1; // 秒
        $last_error = null;

        for ($retry = 0; $retry < $max_retries; $retry++) {
            if ($retry > 0) {
                Notion_To_WordPress_Helper::debug_log(
                    "API请求重试 (第{$retry}次): {$endpoint}",
                    'API Retry'
                );
                sleep($retry_delay);
                $retry_delay *= 2; // 指数退避
            }

            $response = wp_remote_request($url, $args);

            if (!is_wp_error($response)) {
                // 请求成功，跳出重试循环
                break;
            }

            $last_error = $response;
            $error_message = $response->get_error_message();

            // 记录重试日志
            Notion_To_WordPress_Helper::error_log(
                "API请求失败 (第" . ($retry + 1) . "次尝试): {$error_message}",
                'API Error'
            );

            // 如果不是网络相关错误，不重试
            if (!$this->is_retryable_error($error_message)) {
                break;
            }
        }

        if (is_wp_error($response)) {
            throw new Exception(__('API请求失败: ', 'notion-to-wordpress') . $last_error->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            $error_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $error_body['message'] ?? wp_remote_retrieve_body($response);
            throw new Exception(__('API错误 (', 'notion-to-wordpress') . $response_code . '): ' . $error_message);
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true) ?: [];
    }

    /**
     * 判断错误是否可以重试
     *
     * @since    1.8.3
     * @param    string    $error_message    错误消息
     * @return   bool                        是否可以重试
     */
    private function is_retryable_error(string $error_message): bool {
        $retryable_patterns = [
            'timeout',
            'timed out',
            'resolving timed out',
            'connection timeout',
            'dns',
            'network',
            'temporary failure',
            'curl error 28',
            'curl error 6',
            'curl error 7'
        ];

        $error_lower = strtolower($error_message);
        foreach ($retryable_patterns as $pattern) {
            if (strpos($error_lower, $pattern) !== false) {
                return true;
            }
        }

        return false;
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
        $start_time = Notion_To_WordPress_Helper::start_performance_timer('get_database_pages');

        error_log('Notion to WordPress: get_database_pages() 开始，Database ID: ' . $database_id);

        // 生成缓存键
        $cache_key = $database_id . '_' . md5(serialize($filter)) . '_' . ($with_details ? 'detailed' : 'basic');

        // 使用多层缓存获取数据
        return $this->get_cached_data($cache_key, function() use ($database_id, $filter, $with_details, $start_time) {
            return $this->fetch_database_pages_from_api($database_id, $filter, $with_details, $start_time);
        }, 'database_pages');
    }

    /**
     * 从API获取数据库页面（内部方法）
     *
     * @since    1.8.1
     * @param    string    $database_id    数据库ID
     * @param    array     $filter         过滤条件
     * @param    bool      $with_details   是否包含详细信息
     * @param    float     $start_time     开始时间
     * @return   array                     页面数组
     */
    private function fetch_database_pages_from_api(string $database_id, array $filter, bool $with_details, float $start_time): array {
        $all_results = [];
        $has_more = true;
        $start_cursor = null;

        while ($has_more) {
            $endpoint = 'databases/' . $database_id . '/query';
            $data = [
                'page_size' => 100 // 使用API允许的最大值
            ];

            if (!empty($filter)) {
                $data['filter'] = $filter;
            }

            if ($start_cursor) {
                $data['start_cursor'] = $start_cursor;
            }
            
            // 禁用API请求日志以提升速度
            // error_log('Notion to WordPress: 发送API请求到: ' . $endpoint);
            $response = $this->send_request($endpoint, 'POST', $data);
            // error_log('Notion to WordPress: API响应状态: ' . (isset($response['results']) ? 'success' : 'no results'));

            if (isset($response['results'])) {
                $all_results = array_merge($all_results, $response['results']);
                // 禁用批次日志以提升速度
                // error_log('Notion to WordPress: 当前批次页面数: ' . count($response['results']) . ', 总计: ' . count($all_results));
            }

            $has_more = $response['has_more'] ?? false;
            $start_cursor = $response['next_cursor'] ?? null;
        }

        // 如果需要详细信息，批量获取页面详情
        if ($with_details && !empty($all_results)) {
            $all_results = $this->enrich_pages_with_details($all_results);
        }

        Notion_To_WordPress_Helper::debug_log(
            '数据库页面获取完成，总数: ' . count($all_results) . ', 详细信息: ' . ($with_details ? '是' : '否'),
            'Database Pages'
        );

        Notion_To_WordPress_Helper::end_performance_timer('get_database_pages', $start_time, [
            'cache_hit' => false,
            'database_id' => $database_id,
            'with_details' => $with_details,
            'records_count' => count($all_results),
            'api_calls' => $with_details ? count($all_results) + 1 : 1
        ]);

        return $all_results;
    }

    /**
     * 递归获取一个块的所有子块内容。
     *
     * @since    1.0.8
     * @param    string    $block_id    块或页面的 ID。
     * @return   array<string, mixed>                 子块对象数组。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_page_content(string $block_id): array {
        error_log('Notion to WordPress: get_page_content() 开始，Block ID: ' . $block_id);

        $blocks = $this->get_block_children($block_id);
        error_log('Notion to WordPress: 获取到顶级块数量: ' . count($blocks));

        foreach ($blocks as $i => $block) {
            if ($block['has_children']) {
                // 特殊处理：数据库区块不尝试获取子内容，避免API 404错误
                if (isset($block['type']) && $block['type'] === 'child_database') {
                    error_log('Notion to WordPress: 跳过数据库区块子内容获取: ' . $block['id']);
                    Notion_To_WordPress_Helper::debug_log(
                        '在API层跳过数据库区块子内容获取: ' . $block['id'],
                        'Database Block'
                    );
                    // 不设置children，保持has_children为true但不尝试获取子内容
                    continue;
                }

                error_log('Notion to WordPress: 处理子块，Block ID: ' . $block['id']);
                try {
                    // 使用并发版本获取子块内容，提升性能
                    $blocks[$i]['children'] = $this->get_page_content_concurrent($block['id']);
                } catch (Exception $e) {
                    // 特殊处理：如果是数据库权限相关的404错误，跳过这个子块但不影响整个页面
                    if (strpos($e->getMessage(), '404') !== false &&
                        strpos($e->getMessage(), 'Make sure the relevant pages and databases are shared') !== false) {

                        error_log('Notion to WordPress: 子块获取失败(数据库权限问题)，跳过: ' . $block['id']);
                        Notion_To_WordPress_Helper::debug_log(
                            '子块获取失败(数据库权限问题)，跳过: ' . $block['id'] . ', 错误: ' . $e->getMessage(),
                            'Database Block'
                        );
                        // 不设置children，但继续处理其他子块
                        continue;
                    }
                    // 对于其他类型的错误，重新抛出
                    throw $e;
                }
            }
        }

        error_log('Notion to WordPress: get_page_content() 完成，返回块数量: ' . count($blocks));
        return $blocks;
    }

    /**
     * 并发获取页面内容（极致性能优化版本）
     *
     * @since    1.8.1
     * @param    string    $block_id    块或页面的 ID
     * @return   array<string, mixed>   子块对象数组
     * @throws   Exception             如果 API 请求失败
     */
    public function get_page_content_concurrent(string $block_id): array {
        error_log('Notion to WordPress: get_page_content_concurrent() 开始，Block ID: ' . $block_id);

        $blocks = $this->get_block_children($block_id);
        error_log('Notion to WordPress: 获取到顶级块数量: ' . count($blocks));

        // 预收集所有需要并发获取的子块ID（包括深层嵌套）
        $all_child_block_ids = [];
        $this->collect_all_child_block_ids($blocks, $all_child_block_ids);

        // 如果有子块需要获取，使用超级并发管理器一次性获取所有
        if (!empty($all_child_block_ids)) {
            error_log('Notion to WordPress: 开始超级并发获取所有子块，总数量: ' . count($all_child_block_ids));

            $concurrent_manager = new Notion_Concurrent_Manager($this->api_key, 15); // 提升到15个并发

            // 添加所有子块请求到并发池
            foreach ($all_child_block_ids as $child_id) {
                $concurrent_manager->add_request(
                    $child_id,
                    'blocks/' . $child_id . '/children?page_size=100',
                    'GET'
                );
            }

            // 执行超级并发请求
            $concurrent_results = $concurrent_manager->execute_batch();

            // 递归填充所有子块数据
            $this->fill_children_data($blocks, $concurrent_results);
        }

        error_log('Notion to WordPress: get_page_content_concurrent() 完成，返回块数量: ' . count($blocks));
        return $blocks;
    }

    /**
     * 递归收集所有需要获取的子块ID
     *
     * @since    1.8.1
     * @param    array    $blocks           块数组
     * @param    array    &$child_block_ids 收集的子块ID数组（引用传递）
     */
    private function collect_all_child_block_ids(array $blocks, array &$child_block_ids): void {
        foreach ($blocks as $block) {
            if ($block['has_children']) {
                // 特殊处理：数据库区块不尝试获取子内容
                if (isset($block['type']) && $block['type'] === 'child_database') {
                    continue;
                }
                $child_block_ids[] = $block['id'];
            }
        }
    }

    /**
     * 递归填充子块数据（优化版本）
     *
     * @since    1.8.1
     * @param    array    &$blocks           块数组（引用传递）
     * @param    array    $concurrent_results 并发请求结果
     */
    private function fill_children_data(array &$blocks, array $concurrent_results): void {
        $fill_start_time = microtime(true);
        $processed_blocks = 0;
        $filled_children = 0;

        foreach ($blocks as $i => &$block) {
            $processed_blocks++;

            if ($block['has_children'] && isset($block['type']) && $block['type'] !== 'child_database') {
                $child_id = $block['id'];

                if (isset($concurrent_results[$child_id])) {
                    $child_data = $concurrent_results[$child_id];

                    // 处理API返回的数据结构
                    if (isset($child_data['results']) && is_array($child_data['results'])) {
                        $child_blocks = $child_data['results'];

                        // 优化：只有当子块确实有内容时才递归处理
                        if (!empty($child_blocks)) {
                            // 递归填充子块的子块
                            $this->fill_children_data($child_blocks, $concurrent_results);
                            $blocks[$i]['children'] = $child_blocks;
                            $filled_children++;
                        } else {
                            $blocks[$i]['children'] = [];
                        }
                    } else {
                        // 处理错误情况
                        if (isset($child_data['error'])) {
                            Notion_To_WordPress_Helper::debug_log(
                                sprintf('子块获取失败：%s - %s', $child_id, $child_data['error_message'] ?? 'Unknown error'),
                                'Fill Children Error'
                            );
                        }
                        $blocks[$i]['children'] = [];
                    }
                } else {
                    // 未找到对应的并发结果
                    Notion_To_WordPress_Helper::debug_log(
                        sprintf('并发结果中未找到子块：%s', $child_id),
                        'Fill Children Missing'
                    );
                    $blocks[$i]['children'] = [];
                }
            }
        }

        $fill_time = (microtime(true) - $fill_start_time) * 1000;

        Notion_To_WordPress_Helper::debug_log(
            sprintf('子块填充完成：处理%d个块，填充%d个子块，耗时%.2fms',
                   $processed_blocks, $filled_children, $fill_time),
            'Fill Children Performance'
        );
    }

    /**
     * 获取一个块的直接子块（处理分页）。
     *
     * @since 1.0.8
     * @access private
     * @param string $block_id 块的 ID。
     * @return array<string, mixed> 子块对象数组。
     * @throws Exception 如果 API 请求失败。
     */
    private function get_block_children(string $block_id): array {
        error_log('Notion to WordPress: get_block_children() 开始，Block ID: ' . $block_id);

        $all_results = [];
        $has_more = true;
        $start_cursor = null;

        while ($has_more) {
            $endpoint = 'blocks/' . $block_id . '/children?page_size=100';
            if ($start_cursor) {
                $endpoint .= '&start_cursor=' . $start_cursor;
            }

            error_log('Notion to WordPress: 请求子块，endpoint: ' . $endpoint);

            try {
                $response = $this->send_request($endpoint, 'GET');

                if (isset($response['results'])) {
                    $all_results = array_merge($all_results, $response['results']);
                    error_log('Notion to WordPress: 获取到子块数量: ' . count($response['results']) . ', 总计: ' . count($all_results));

                    // 记录每个子块的类型，特别关注数据库区块
                    foreach ($response['results'] as $block) {
                        if (isset($block['type']) && $block['type'] === 'child_database') {
                            error_log('Notion to WordPress: 发现数据库区块: ' . $block['id'] . ', 父块: ' . $block_id);
                            Notion_To_WordPress_Helper::debug_log(
                                '在get_block_children中发现数据库区块: ' . $block['id'] . ', 父块: ' . $block_id,
                                'Database Block'
                            );
                        }
                    }
                }

                $has_more = $response['has_more'] ?? false;
                $start_cursor = $response['next_cursor'] ?? null;

            } catch (Exception $e) {
                error_log('Notion to WordPress: get_block_children() 异常: ' . $e->getMessage() . ', Block ID: ' . $block_id);
                Notion_To_WordPress_Helper::error_log(
                    'get_block_children异常: ' . $e->getMessage() . ', Block ID: ' . $block_id,
                    'Database Block'
                );

                // 特殊处理：只对数据库区块相关的404错误进行优雅处理
                if (strpos($e->getMessage(), '404') !== false &&
                    strpos($e->getMessage(), 'Make sure the relevant pages and databases are shared') !== false) {

                    Notion_To_WordPress_Helper::debug_log(
                        '检测到数据库权限相关的404错误，跳过并继续处理: ' . $block_id,
                        'Database Block'
                    );
                    break; // 跳出循环，返回已获取的结果
                }

                throw $e; // 对于其他错误，重新抛出异常
            }
        }

        error_log('Notion to WordPress: get_block_children() 完成，返回总块数: ' . count($all_results));
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
     * 获取页面的元数据。
     *
     * @since    1.0.8
     * @param    string    $page_id    Notion 页面的 ID。
     * @return   array<string, mixed>                 页面对象。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_page_metadata(string $page_id): array {
        $endpoint = 'pages/' . $page_id;
        return $this->send_request($endpoint);
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
     * 获取单个页面对象
     *
     * @param string $page_id 页面ID
     * @return array<string, mixed>
     * @throws Exception
     */
    public function get_page(string $page_id): array {
        $endpoint = 'pages/' . $page_id;
        return $this->send_request($endpoint);
    }

    /**
     * 安全获取数据库信息，支持优雅降级
     *
     * @since 1.0.9
     * @param string $database_id 数据库ID
     * @return array<string, mixed> 数据库信息数组，失败时返回空数组
     */
    public function get_database_info(string $database_id): array {
        $cache_key = 'database_info_' . $database_id;

        return $this->get_cached_data($cache_key, function() use ($database_id) {
            try {
                $database_info = $this->get_database($database_id);

                Notion_To_WordPress_Helper::debug_log(
                    '数据库信息获取成功并缓存: ' . $database_id,
                    'Database Info'
                );

                return $database_info;
            } catch (Exception $e) {
                Notion_To_WordPress_Helper::debug_log(
                    '数据库信息获取失败: ' . $e->getMessage(),
                    'Database Info'
                );
                return [];
            }
        }, 'database_info');
    }

    /**
     * 获取页面详细信息，包括cover、icon等完整属性
     *
     * @since 1.1.1
     * @param string $page_id 页面ID
     * @return array<string, mixed> 页面详细信息数组，失败时返回空数组
     */
    public function get_page_details(string $page_id): array {
        $cache_key = 'page_' . $page_id;

        return $this->get_cached_data($cache_key, function() use ($page_id) {
            try {
                $page_data = $this->get_page($page_id);

                Notion_To_WordPress_Helper::debug_log(
                    '页面详情获取成功: ' . $page_id . ', 包含cover: ' . (isset($page_data['cover']) ? '是' : '否') . ', 包含icon: ' . (isset($page_data['icon']) ? '是' : '否'),
                    'Page Details'
                );

                return $page_data;
            } catch (Exception $e) {
                Notion_To_WordPress_Helper::error_log(
                    '页面详情获取失败: ' . $page_id . ', 错误: ' . $e->getMessage(),
                    'Page Details'
                );
                return [];
            }
        }, 'page_details');
    }

    /**
     * 批量为页面添加详细信息
     *
     * @since 1.1.1
     * @param array<string, mixed> $pages 页面数组
     * @return array<string, mixed> 包含详细信息的页面数组
     */
    private function enrich_pages_with_details(array $pages): array {
        if (empty($pages)) {
            return $pages;
        }

        $start_time = Notion_To_WordPress_Helper::start_performance_timer('enrich_pages_with_details');

        // 提取所有页面ID
        $page_ids = [];
        $page_mapping = [];

        foreach ($pages as $index => $page) {
            $page_id = $page['id'] ?? '';
            if (!empty($page_id)) {
                $page_ids[] = $page_id;
                $page_mapping[$page_id] = $index;
            }
        }

        if (empty($page_ids)) {
            return $pages;
        }

        Notion_To_WordPress_Helper::debug_log(
            '开始并发获取页面详情，总数: ' . count($page_ids),
            'Enrich Pages'
        );

        // 使用并发方式获取所有页面详情
        $pages_details = $this->get_pages_details_concurrent($page_ids);

        // 合并详细信息到原始页面数据
        $enriched_pages = $pages;
        foreach ($pages_details as $page_id => $page_details) {
            if (isset($page_mapping[$page_id]) && !empty($page_details)) {
                $index = $page_mapping[$page_id];
                $enriched_pages[$index] = array_merge($enriched_pages[$index], [
                    'cover' => $page_details['cover'] ?? null,
                    'icon' => $page_details['icon'] ?? null,
                    'url' => $page_details['url'] ?? $enriched_pages[$index]['url'] ?? null,
                ]);
            }
        }

        Notion_To_WordPress_Helper::end_performance_timer('enrich_pages_with_details', $start_time, [
            'total_pages' => count($pages),
            'enriched_pages' => count($page_ids),
            'concurrent_enabled' => true
        ]);

        return $enriched_pages;
    }

    /**
     * 多层缓存获取数据的通用方法（支持智能TTL）
     *
     * @since    1.8.1
     * @param    string    $cache_key     缓存键
     * @param    callable  $data_callback 获取数据的回调函数
     * @param    string    $cache_type    缓存类型（用于统计和TTL选择）
     * @return   mixed                    缓存的数据或回调函数的结果
     */
    private function get_cached_data(string $cache_key, callable $data_callback, string $cache_type = 'general') {
        $start_time = Notion_To_WordPress_Helper::start_performance_timer('smart_multi_layer_cache');
        self::$cache_stats['total_requests']++;
        self::$cache_stats['total_cache_operations']++;

        // 获取智能TTL配置
        $smart_ttl = $this->get_smart_cache_ttl($cache_type, $cache_key);

        // 第一层：检查内存缓存
        if ($this->check_memory_cache($cache_key)) {
            self::$cache_stats['memory_hits']++;
            Notion_To_WordPress_Helper::debug_log(
                "多层缓存命中（内存）: {$cache_key}",
                'Multi-Layer Cache'
            );

            Notion_To_WordPress_Helper::end_performance_timer('multi_layer_cache', $start_time, [
                'cache_type' => $cache_type,
                'hit_level' => 'memory',
                'cache_key' => $cache_key
            ]);

            return $this->get_from_memory_cache($cache_key);
        }

        // 第二层：检查WordPress transients缓存
        $transient_key = 'notion_cache_' . md5($cache_key);
        $cached_data = get_transient($transient_key);

        if ($cached_data !== false) {
            self::$cache_stats['transient_hits']++;

            // 将数据回填到内存缓存
            $this->store_to_memory_cache($cache_key, $cached_data);

            Notion_To_WordPress_Helper::debug_log(
                "多层缓存命中（transients）: {$cache_key}",
                'Multi-Layer Cache'
            );

            Notion_To_WordPress_Helper::end_performance_timer('multi_layer_cache', $start_time, [
                'cache_type' => $cache_type,
                'hit_level' => 'transients',
                'cache_key' => $cache_key
            ]);

            return $cached_data;
        }

        // 第三层：缓存未命中，执行回调获取数据
        self::$cache_stats['cache_misses']++;

        Notion_To_WordPress_Helper::debug_log(
            "多层缓存未命中，执行回调: {$cache_key}",
            'Multi-Layer Cache'
        );

        $data = $data_callback();

        // 存储到两层缓存（使用智能TTL）
        $this->store_to_memory_cache($cache_key, $data);
        set_transient($transient_key, $data, $smart_ttl['transient']);

        // 记录智能缓存更新
        self::$cache_stats['smart_cache_updates']++;

        Notion_To_WordPress_Helper::debug_log(
            sprintf("智能缓存存储: %s, 内存TTL: %ds, 持久TTL: %ds",
                   $cache_key, $smart_ttl['memory'], $smart_ttl['transient']),
            'Smart Cache Storage'
        );

        Notion_To_WordPress_Helper::end_performance_timer('smart_multi_layer_cache', $start_time, [
            'cache_type' => $cache_type,
            'hit_level' => 'miss',
            'cache_key' => $cache_key,
            'data_size' => is_array($data) ? count($data) : strlen(serialize($data)),
            'memory_ttl' => $smart_ttl['memory'],
            'transient_ttl' => $smart_ttl['transient']
        ]);

        return $data;
    }

    /**
     * 清除页面缓存
     *
     * @since 1.1.1
     * @param string|null $page_id 特定页面ID，为null时清除所有缓存
     */
    public static function clear_page_cache(?string $page_id = null): void {
        if ($page_id !== null) {
            // 清除内存缓存
            unset(self::$page_cache[$page_id]);
            unset(self::$cache_timestamps['page_' . $page_id]);

            // 清除transient缓存
            self::clear_transient_cache('page_' . $page_id);

            Notion_To_WordPress_Helper::debug_log(
                '清除页面缓存（内存+transient）: ' . $page_id,
                'Page Cache'
            );
        } else {
            // 清除所有内存缓存
            self::$page_cache = [];
            self::$database_pages_cache = [];
            self::$database_info_cache = [];
            self::$cache_timestamps = [];

            // 清除所有transient缓存
            self::clear_transient_cache();

            // 重置缓存统计
            self::reset_cache_stats();

            Notion_To_WordPress_Helper::debug_log(
                '清除所有缓存（内存+transient）',
                'Page Cache'
            );
        }
    }

    /**
     * 检查缓存是否有效
     *
     * @since 1.1.1
     * @param string $cache_key 缓存键
     * @return bool
     */
    private function is_cache_valid(string $cache_key): bool {
        if (!isset(self::$cache_timestamps[$cache_key])) {
            return false;
        }

        $cache_time = self::$cache_timestamps[$cache_key];
        $current_time = time();

        return ($current_time - $cache_time) < self::$cache_ttl;
    }

    /**
     * 清理过期缓存
     *
     * @since 1.1.1
     */
    public static function cleanup_expired_cache(): void {
        $current_time = time();
        $expired_keys = [];

        foreach (self::$cache_timestamps as $cache_key => $timestamp) {
            if (($current_time - $timestamp) >= self::$cache_ttl) {
                $expired_keys[] = $cache_key;
            }
        }

        foreach ($expired_keys as $key) {
            unset(self::$cache_timestamps[$key]);

            // 根据键名清理对应的缓存
            if (strpos($key, 'page_') === 0) {
                $page_id = substr($key, 5);
                unset(self::$page_cache[$page_id]);
            } elseif (strpos($key, 'database_info_') === 0) {
                $db_id = substr($key, 14);
                unset(self::$database_info_cache[$db_id]);
            } elseif (strpos($key, 'database_pages_') === 0) {
                unset(self::$database_pages_cache[$key]);
            }
        }

        if (!empty($expired_keys)) {
            Notion_To_WordPress_Helper::debug_log(
                '清理过期缓存: ' . count($expired_keys) . ' 个项目',
                'Cache Cleanup'
            );
        }
    }

    /**
     * 检查内存缓存是否存在且有效
     *
     * @since    1.8.1
     * @param    string    $cache_key    缓存键
     * @return   bool
     */
    private function check_memory_cache(string $cache_key): bool {
        // 检查不同类型的内存缓存
        if (isset(self::$page_cache[$cache_key])) {
            return true;
        }

        if (isset(self::$database_pages_cache[$cache_key])) {
            return true;
        }

        if (isset(self::$database_info_cache[$cache_key])) {
            return true;
        }

        return false;
    }

    /**
     * 从内存缓存获取数据
     *
     * @since    1.8.1
     * @param    string    $cache_key    缓存键
     * @return   mixed
     */
    private function get_from_memory_cache(string $cache_key) {
        if (isset(self::$page_cache[$cache_key])) {
            return self::$page_cache[$cache_key];
        }

        if (isset(self::$database_pages_cache[$cache_key])) {
            return self::$database_pages_cache[$cache_key];
        }

        if (isset(self::$database_info_cache[$cache_key])) {
            return self::$database_info_cache[$cache_key];
        }

        return null;
    }

    /**
     * 存储数据到内存缓存
     *
     * @since    1.8.1
     * @param    string    $cache_key    缓存键
     * @param    mixed     $data         要缓存的数据
     */
    private function store_to_memory_cache(string $cache_key, $data): void {
        // 根据缓存键的模式决定存储位置
        if (strpos($cache_key, 'page_') === 0) {
            $page_id = substr($cache_key, 5);
            self::$page_cache[$page_id] = $data;
            self::$cache_timestamps['page_' . $page_id] = time();
        } elseif (strpos($cache_key, 'database_info_') === 0) {
            $db_id = substr($cache_key, 14);
            self::$database_info_cache[$db_id] = $data;
            self::$cache_timestamps['database_info_' . $db_id] = time();
        } else {
            // 默认存储到database_pages_cache
            self::$database_pages_cache[$cache_key] = $data;
            self::$cache_timestamps[$cache_key] = time();
        }
    }

    /**
     * 清除WordPress transients缓存
     *
     * @since    1.8.1
     * @param    string|null    $cache_key    特定缓存键，为null时清除所有
     */
    public static function clear_transient_cache(?string $cache_key = null): void {
        if ($cache_key !== null) {
            $transient_key = 'notion_cache_' . md5($cache_key);
            delete_transient($transient_key);
            Notion_To_WordPress_Helper::debug_log(
                '清除transient缓存: ' . $cache_key,
                'Transient Cache'
            );
        } else {
            // 清除所有notion相关的transients
            global $wpdb;
            $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_notion_cache_%' OR option_name LIKE '_transient_timeout_notion_cache_%'"
            );
            Notion_To_WordPress_Helper::debug_log(
                '清除所有transient缓存',
                'Transient Cache'
            );
        }
    }

    /**
     * 获取缓存统计信息
     *
     * @since 1.1.1
     * @return array
     */
    public static function get_cache_stats(): array {
        $total_requests = self::$cache_stats['total_requests'];
        $memory_hit_rate = $total_requests > 0 ? round((self::$cache_stats['memory_hits'] / $total_requests) * 100, 2) : 0;
        $transient_hit_rate = $total_requests > 0 ? round((self::$cache_stats['transient_hits'] / $total_requests) * 100, 2) : 0;
        $overall_hit_rate = $total_requests > 0 ? round(((self::$cache_stats['memory_hits'] + self::$cache_stats['transient_hits']) / $total_requests) * 100, 2) : 0;

        return [
            'page_cache_count' => count(self::$page_cache),
            'database_pages_cache_count' => count(self::$database_pages_cache),
            'database_info_cache_count' => count(self::$database_info_cache),
            'total_cache_items' => count(self::$cache_timestamps),
            'cache_ttl' => self::$cache_ttl,
            'transient_cache_ttl' => self::$transient_cache_ttl,
            'memory_hits' => self::$cache_stats['memory_hits'],
            'transient_hits' => self::$cache_stats['transient_hits'],
            'cache_misses' => self::$cache_stats['cache_misses'],
            'total_requests' => $total_requests,
            'memory_hit_rate' => $memory_hit_rate . '%',
            'transient_hit_rate' => $transient_hit_rate . '%',
            'overall_hit_rate' => $overall_hit_rate . '%'
        ];
    }

    /**
     * 重置缓存统计信息
     *
     * @since    1.8.1
     */
    public static function reset_cache_stats(): void {
        self::$cache_stats = [
            'memory_hits' => 0,
            'transient_hits' => 0,
            'cache_misses' => 0,
            'total_requests' => 0
        ];
        Notion_To_WordPress_Helper::debug_log(
            '缓存统计信息已重置',
            'Cache Stats'
        );
    }

    /**
     * 设置transient缓存TTL
     *
     * @since    1.8.1
     * @param    int    $ttl    缓存时间（秒）
     */
    public static function set_transient_cache_ttl(int $ttl): void {
        self::$transient_cache_ttl = max(300, $ttl); // 最小5分钟
        Notion_To_WordPress_Helper::debug_log(
            '设置transient缓存TTL: ' . self::$transient_cache_ttl . '秒',
            'Cache Config'
        );
    }

    /**
     * 获取并发管理器实例
     *
     * @since    1.8.1
     * @return   Notion_Concurrent_Manager    并发管理器实例
     */
    public function get_concurrent_manager(): Notion_Concurrent_Manager {
        if ($this->concurrent_manager === null) {
            $options = get_option('notion_to_wordpress_options', []);
            $max_concurrent = isset($options['concurrent_requests']) ? (int)$options['concurrent_requests'] : 3;

            $this->concurrent_manager = new Notion_Concurrent_Manager($this->api_key, $max_concurrent);

            Notion_To_WordPress_Helper::debug_log(
                '创建并发管理器实例，最大并发数: ' . $max_concurrent,
                'Concurrent API'
            );
        }

        return $this->concurrent_manager;
    }

    /**
     * 批量获取页面详情（并发版本）
     *
     * @since    1.8.1
     * @param    array    $page_ids    页面ID数组
     * @return   array                 页面详情数组，键为页面ID
     */
    public function get_pages_details_concurrent(array $page_ids): array {
        if (empty($page_ids)) {
            return [];
        }

        $start_time = Notion_To_WordPress_Helper::start_performance_timer('concurrent_pages_details');
        $concurrent_manager = $this->get_concurrent_manager();

        // 检查缓存，只对未缓存的页面发起并发请求
        $cached_results = [];
        $uncached_page_ids = [];

        foreach ($page_ids as $page_id) {
            $cache_key = 'page_' . $page_id;
            if ($this->check_memory_cache($cache_key)) {
                $cached_results[$page_id] = $this->get_from_memory_cache($cache_key);
            } else {
                // 检查transient缓存
                $transient_key = 'notion_cache_' . md5($cache_key);
                $cached_data = get_transient($transient_key);
                if ($cached_data !== false) {
                    $this->store_to_memory_cache($cache_key, $cached_data);
                    $cached_results[$page_id] = $cached_data;
                } else {
                    $uncached_page_ids[] = $page_id;
                }
            }
        }

        Notion_To_WordPress_Helper::debug_log(
            '并发页面详情获取 - 总数: ' . count($page_ids) . ', 缓存命中: ' . count($cached_results) . ', 需要请求: ' . count($uncached_page_ids),
            'Concurrent API'
        );

        // 如果所有页面都已缓存，直接返回
        if (empty($uncached_page_ids)) {
            Notion_To_WordPress_Helper::end_performance_timer('concurrent_pages_details', $start_time, [
                'total_pages' => count($page_ids),
                'cached_pages' => count($cached_results),
                'api_requests' => 0,
                'cache_hit_rate' => '100%'
            ]);

            return $cached_results;
        }

        // 为未缓存的页面添加并发请求
        foreach ($uncached_page_ids as $page_id) {
            $concurrent_manager->add_request(
                $page_id,
                'pages/' . $page_id,
                'GET',
                [],
                ['type' => 'page_details']
            );
        }

        // 执行并发请求
        $concurrent_results = $concurrent_manager->execute_batch();
        $api_results = [];

        // 处理并发请求结果
        foreach ($concurrent_results as $page_id => $result) {
            if (isset($result['error'])) {
                Notion_To_WordPress_Helper::error_log(
                    '并发获取页面详情失败: ' . $page_id . ' - ' . ($result['error_message'] ?? 'Unknown error'),
                    'Concurrent API'
                );
                $api_results[$page_id] = [];
            } else {
                // 存储到缓存
                $cache_key = 'page_' . $page_id;
                $this->store_to_memory_cache($cache_key, $result);
                $transient_key = 'notion_cache_' . md5($cache_key);
                set_transient($transient_key, $result, self::$transient_cache_ttl);

                $api_results[$page_id] = $result;
            }
        }

        // 合并缓存结果和API结果
        $all_results = array_merge($cached_results, $api_results);

        // 记录性能数据
        $cache_hit_rate = count($page_ids) > 0 ? round((count($cached_results) / count($page_ids)) * 100, 2) : 0;

        Notion_To_WordPress_Helper::end_performance_timer('concurrent_pages_details', $start_time, [
            'total_pages' => count($page_ids),
            'cached_pages' => count($cached_results),
            'api_requests' => count($uncached_page_ids),
            'cache_hit_rate' => $cache_hit_rate . '%',
            'concurrent_stats' => $concurrent_manager->get_stats()
        ]);

        return $all_results;
    }

    /**
     * 批量获取页面内容（智能合并版本）
     *
     * @since    1.8.1
     * @param    array    $page_ids    页面ID数组
     * @return   array                 页面内容数组，键为页面ID
     */
    public function get_pages_content_concurrent(array $page_ids): array {
        if (empty($page_ids)) {
            return [];
        }

        $start_time = Notion_To_WordPress_Helper::start_performance_timer('smart_concurrent_pages_content');

        // 使用智能请求合并
        $results = $this->execute_smart_batch_requests($page_ids, 'page_content');

        $execution_time = Notion_To_WordPress_Helper::end_performance_timer($start_time, 'smart_concurrent_pages_content');

        // 更新请求合并统计
        self::$request_merge_stats['total_requests'] += count($page_ids);
        self::$request_merge_stats['batch_operations']++;

        Notion_To_WordPress_Helper::info_log(
            sprintf('智能批量页面内容获取完成：处理%d个页面，执行时间%.2fms',
                   count($page_ids), $execution_time),
            'Smart Batch API'
        );

        return $results;
    }

    /**
     * 执行智能批量请求
     *
     * @since    1.8.1
     * @param    array     $page_ids      页面ID数组
     * @param    string    $request_type  请求类型
     * @return   array                    请求结果数组
     */
    private function execute_smart_batch_requests(array $page_ids, string $request_type): array {
        // 检查缓存，分离已缓存和未缓存的页面
        $cached_results = [];
        $uncached_page_ids = [];

        foreach ($page_ids as $page_id) {
            $cache_key = $request_type . '_' . $page_id;

            if ($this->check_memory_cache($cache_key)) {
                $cached_results[$page_id] = $this->get_from_memory_cache($cache_key);
            } else {
                // 检查transient缓存
                $transient_key = 'notion_cache_' . md5($cache_key);
                $cached_data = get_transient($transient_key);

                if ($cached_data !== false) {
                    $this->store_to_memory_cache($cache_key, $cached_data);
                    $cached_results[$page_id] = $cached_data;
                } else {
                    $uncached_page_ids[] = $page_id;
                }
            }
        }

        // 如果所有页面都已缓存，直接返回
        if (empty($uncached_page_ids)) {
            Notion_To_WordPress_Helper::debug_log(
                sprintf('智能批量请求：所有%d个页面均已缓存', count($page_ids)),
                'Smart Batch Cache'
            );
            return $cached_results;
        }

        // 执行优化的并发请求
        $api_results = $this->execute_optimized_concurrent_requests($uncached_page_ids, $request_type);

        // 合并缓存结果和API结果
        $all_results = array_merge($cached_results, $api_results);

        // 记录合并统计
        $cache_hit_rate = count($page_ids) > 0 ? round((count($cached_results) / count($page_ids)) * 100, 2) : 0;
        $saved_calls = count($cached_results);
        self::$request_merge_stats['saved_api_calls'] += $saved_calls;

        Notion_To_WordPress_Helper::debug_log(
            sprintf('智能批量请求完成：总计%d个页面，缓存命中%d个(%.1f%%)，API请求%d个',
                   count($page_ids), count($cached_results), $cache_hit_rate, count($uncached_page_ids)),
            'Smart Batch Performance'
        );

        return $all_results;
    }

    /**
     * 执行优化的并发请求
     *
     * @since    1.8.1
     * @param    array     $page_ids      页面ID数组
     * @param    string    $request_type  请求类型
     * @return   array                    API请求结果数组
     */
    private function execute_optimized_concurrent_requests(array $page_ids, string $request_type): array {
        $concurrent_manager = $this->get_concurrent_manager();
        $api_results = [];

        // 根据请求类型优化批次大小
        $batch_size = $this->calculate_optimal_batch_size($request_type, count($page_ids));
        $batches = array_chunk($page_ids, $batch_size);

        Notion_To_WordPress_Helper::debug_log(
            sprintf('优化并发请求：%d个页面分为%d个批次，每批%d个',
                   count($page_ids), count($batches), $batch_size),
            'Optimized Concurrent'
        );

        foreach ($batches as $batch_index => $batch) {
            $batch_start_time = microtime(true);

            // 为批次中的每个页面添加请求
            foreach ($batch as $page_id) {
                $this->add_optimized_request($concurrent_manager, $page_id, $request_type);
            }

            // 执行批次请求
            $batch_results = $concurrent_manager->execute_batch();

            // 处理批次结果
            $processed_results = $this->process_batch_results($batch_results, $request_type);
            $api_results = array_merge($api_results, $processed_results);

            $batch_time = (microtime(true) - $batch_start_time) * 1000;

            Notion_To_WordPress_Helper::debug_log(
                sprintf('批次%d/%d完成：处理%d个页面，耗时%.2fms',
                       $batch_index + 1, count($batches), count($batch), $batch_time),
                'Batch Processing'
            );

            // 批次间智能延迟
            if ($batch_index < count($batches) - 1) {
                $delay = $this->calculate_smart_batch_delay($batch_time, count($batch));
                if ($delay > 0) {
                    usleep($delay * 1000); // 转换为微秒
                }
            }
        }

        return $api_results;
    }

    /**
     * 计算最优批次大小
     *
     * @since    1.8.1
     * @param    string    $request_type  请求类型
     * @param    int       $total_count   总请求数
     * @return   int                      最优批次大小
     */
    private function calculate_optimal_batch_size(string $request_type, int $total_count): int {
        $base_batch_size = self::$request_merge_config['max_batch_size'];

        // 根据请求类型调整批次大小
        switch ($request_type) {
            case 'page_content':
                // 页面内容请求较重，使用较小批次
                $batch_size = min(20, $base_batch_size);
                break;
            case 'page_details':
                // 页面详情请求较轻，可以使用较大批次
                $batch_size = min(30, $base_batch_size);
                break;
            case 'block_children':
                // 子块请求中等，使用中等批次
                $batch_size = min(25, $base_batch_size);
                break;
            default:
                $batch_size = min(25, $base_batch_size);
        }

        // 根据总数量进行调整
        if ($total_count <= 10) {
            $batch_size = $total_count; // 小量请求不分批
        } elseif ($total_count <= 50) {
            $batch_size = min($batch_size, ceil($total_count / 2)); // 分为2批
        }

        return max(1, $batch_size);
    }

    /**
     * 添加优化的请求
     *
     * @since    1.8.1
     * @param    object    $concurrent_manager  并发管理器
     * @param    string    $page_id            页面ID
     * @param    string    $request_type       请求类型
     */
    private function add_optimized_request($concurrent_manager, string $page_id, string $request_type): void {
        switch ($request_type) {
            case 'page_content':
                $concurrent_manager->add_request(
                    $page_id . '_content',
                    'blocks/' . $page_id . '/children?page_size=100',
                    'GET',
                    [],
                    ['type' => 'page_content', 'page_id' => $page_id, 'priority' => 'high']
                );
                break;
            case 'page_details':
                $concurrent_manager->add_request(
                    $page_id,
                    'pages/' . $page_id,
                    'GET',
                    [],
                    ['type' => 'page_details', 'page_id' => $page_id, 'priority' => 'high']
                );
                break;
            case 'block_children':
                $concurrent_manager->add_request(
                    $page_id . '_children',
                    'blocks/' . $page_id . '/children?page_size=100',
                    'GET',
                    [],
                    ['type' => 'block_children', 'page_id' => $page_id, 'priority' => 'medium']
                );
                break;
        }
    }

    /**
     * 处理批次结果
     *
     * @since    1.8.1
     * @param    array     $batch_results  批次结果
     * @param    string    $request_type   请求类型
     * @return   array                     处理后的结果
     */
    private function process_batch_results(array $batch_results, string $request_type): array {
        $processed_results = [];

        foreach ($batch_results as $request_id => $result) {
            // 提取页面ID
            $page_id = $this->extract_page_id_from_request($request_id, $request_type);

            if (isset($result['error'])) {
                Notion_To_WordPress_Helper::error_log(
                    sprintf('批量请求失败：%s - %s', $page_id, $result['error_message'] ?? 'Unknown error'),
                    'Batch Request Error'
                );
                $processed_results[$page_id] = [];
            } else {
                // 根据请求类型处理结果
                $processed_data = $this->process_request_result($result, $request_type);
                $processed_results[$page_id] = $processed_data;

                // 存储到缓存
                $cache_key = $request_type . '_' . $page_id;
                $this->store_to_memory_cache($cache_key, $processed_data);

                // 存储到transient缓存
                $transient_key = 'notion_cache_' . md5($cache_key);
                $ttl = $this->get_smart_cache_ttl($request_type, $cache_key);
                set_transient($transient_key, $processed_data, $ttl['transient']);
            }
        }

        return $processed_results;
    }

    /**
     * 从请求ID提取页面ID
     *
     * @since    1.8.1
     * @param    string    $request_id     请求ID
     * @param    string    $request_type   请求类型
     * @return   string                    页面ID
     */
    private function extract_page_id_from_request(string $request_id, string $request_type): string {
        switch ($request_type) {
            case 'page_content':
                return str_replace('_content', '', $request_id);
            case 'page_details':
                return $request_id;
            case 'block_children':
                return str_replace('_children', '', $request_id);
            default:
                return $request_id;
        }
    }

    /**
     * 处理请求结果
     *
     * @since    1.8.1
     * @param    array     $result         原始结果
     * @param    string    $request_type   请求类型
     * @return   array                     处理后的结果
     */
    private function process_request_result(array $result, string $request_type): array {
        switch ($request_type) {
            case 'page_content':
            case 'block_children':
                return $result['results'] ?? [];
            case 'page_details':
                return $result;
            default:
                return $result;
        }
    }

    /**
     * 计算智能批次延迟
     *
     * @since    1.8.1
     * @param    float     $batch_time     批次执行时间（毫秒）
     * @param    int       $batch_size     批次大小
     * @return   int                       延迟时间（毫秒）
     */
    private function calculate_smart_batch_delay(float $batch_time, int $batch_size): int {
        // 基础延迟：根据批次执行时间动态调整
        $base_delay = 100; // 基础100ms延迟

        // 如果批次执行时间过长，增加延迟
        if ($batch_time > 5000) { // 超过5秒
            $base_delay = 500;
        } elseif ($batch_time > 2000) { // 超过2秒
            $base_delay = 300;
        } elseif ($batch_time > 1000) { // 超过1秒
            $base_delay = 200;
        }

        // 根据批次大小调整
        $size_factor = min(2.0, $batch_size / 10); // 批次越大，延迟越长
        $adjusted_delay = (int)($base_delay * $size_factor);

        // 确保延迟在合理范围内
        return max(50, min(1000, $adjusted_delay)); // 50ms到1秒之间
    }

    /**
     * 获取智能缓存TTL配置
     *
     * @since    1.8.1
     * @param    string    $cache_type    缓存类型
     * @param    string    $cache_key     缓存键
     * @return   array                    包含memory和transient TTL的数组
     */
    private function get_smart_cache_ttl(string $cache_type, string $cache_key): array {
        // 基础TTL配置
        $base_memory_ttl = self::$cache_ttl_config[$cache_type] ?? self::$cache_ttl_config['default'];
        $base_transient_ttl = $base_memory_ttl * 2; // 持久化缓存时间是内存缓存的2倍

        // 根据缓存键类型进行智能调整
        $memory_ttl = $base_memory_ttl;
        $transient_ttl = $base_transient_ttl;

        // 页面内容类型的特殊处理
        if (strpos($cache_key, 'page_') === 0) {
            $memory_ttl = self::$cache_ttl_config['page_content'];
            $transient_ttl = $memory_ttl * 2;
        }
        // 数据库页面列表的特殊处理
        elseif (strpos($cache_key, 'database_pages_') === 0) {
            $memory_ttl = self::$cache_ttl_config['database_pages'];
            $transient_ttl = $memory_ttl * 3; // 数据库页面列表变化较少，延长持久化时间
        }
        // 数据库信息的特殊处理
        elseif (strpos($cache_key, 'database_info_') === 0) {
            $memory_ttl = self::$cache_ttl_config['database_info'];
            $transient_ttl = $memory_ttl * 2;
        }
        // 图片URL的特殊处理
        elseif (strpos($cache_key, 'image_') === 0) {
            $memory_ttl = self::$cache_ttl_config['image_urls'];
            $transient_ttl = $memory_ttl; // 图片URL很少变化，内存和持久化时间相同
        }

        // 根据缓存命中率动态调整
        $hit_rate = $this->calculate_cache_hit_rate();
        if ($hit_rate > 0.9) {
            // 命中率很高，延长缓存时间
            $memory_ttl = (int)($memory_ttl * 1.2);
            $transient_ttl = (int)($transient_ttl * 1.3);
        } elseif ($hit_rate < 0.6) {
            // 命中率较低，缩短缓存时间
            $memory_ttl = (int)($memory_ttl * 0.8);
            $transient_ttl = (int)($transient_ttl * 0.9);
        }

        return [
            'memory' => max(300, $memory_ttl),      // 最小5分钟
            'transient' => max(600, $transient_ttl) // 最小10分钟
        ];
    }

    /**
     * 计算当前缓存命中率
     *
     * @since    1.8.1
     * @return   float    缓存命中率（0-1之间）
     */
    private function calculate_cache_hit_rate(): float {
        $total_hits = self::$cache_stats['memory_hits'] + self::$cache_stats['transient_hits'];
        $total_requests = $total_hits + self::$cache_stats['cache_misses'];

        return $total_requests > 0 ? $total_hits / $total_requests : 0;
    }

    /**
     * 缓存预热机制
     *
     * @since    1.8.1
     * @param    array    $preload_items    预加载项目列表
     */
    public function cache_preload(array $preload_items = []): void {
        if (!self::$cache_preload_config['enabled']) {
            return;
        }

        $preload_start_time = Notion_To_WordPress_Helper::start_performance_timer('cache_preload');
        $preloaded_count = 0;

        Notion_To_WordPress_Helper::info_log(
            sprintf('开始缓存预热，预加载%d个项目', count($preload_items)),
            'Cache Preload'
        );

        foreach ($preload_items as $item) {
            if ($preloaded_count >= self::$cache_preload_config['max_preload_items']) {
                break;
            }

            try {
                $cache_key = $item['cache_key'] ?? '';
                $cache_type = $item['cache_type'] ?? 'default';
                $data_callback = $item['data_callback'] ?? null;

                if (empty($cache_key) || !is_callable($data_callback)) {
                    continue;
                }

                // 检查是否已经缓存
                if (!$this->check_memory_cache($cache_key)) {
                    // 预加载数据到缓存
                    $this->get_cached_data($cache_key, $data_callback, $cache_type);
                    $preloaded_count++;
                    self::$cache_stats['cache_preloads']++;
                }
            } catch (Exception $e) {
                Notion_To_WordPress_Helper::error_log(
                    '缓存预热失败: ' . $e->getMessage(),
                    'Cache Preload Error'
                );
            }
        }

        $execution_time = Notion_To_WordPress_Helper::end_performance_timer($preload_start_time, 'cache_preload');

        Notion_To_WordPress_Helper::info_log(
            sprintf('缓存预热完成：预加载%d个项目，执行时间%.2fms', $preloaded_count, $execution_time),
            'Cache Preload'
        );
    }

    /**
     * 获取增强的缓存统计信息
     *
     * @since    1.8.1
     * @return   array    增强的缓存统计数据
     */
    public static function get_enhanced_cache_stats(): array {
        $total_requests = self::$cache_stats['memory_hits'] +
                         self::$cache_stats['transient_hits'] +
                         self::$cache_stats['cache_misses'];

        $memory_hit_rate = $total_requests > 0 ?
            round((self::$cache_stats['memory_hits'] / $total_requests) * 100, 2) : 0;

        $transient_hit_rate = $total_requests > 0 ?
            round((self::$cache_stats['transient_hits'] / $total_requests) * 100, 2) : 0;

        $overall_hit_rate = $total_requests > 0 ?
            round(((self::$cache_stats['memory_hits'] + self::$cache_stats['transient_hits']) / $total_requests) * 100, 2) : 0;

        return array_merge(self::$cache_stats, [
            'page_cache_count' => count(self::$page_cache),
            'database_pages_cache_count' => count(self::$database_pages_cache),
            'database_info_cache_count' => count(self::$database_info_cache),
            'total_cache_items' => count(self::$cache_timestamps),
            'cache_ttl_config' => self::$cache_ttl_config,
            'transient_cache_ttl' => self::$transient_cache_ttl,
            'memory_hit_rate' => $memory_hit_rate . '%',
            'transient_hit_rate' => $transient_hit_rate . '%',
            'overall_hit_rate' => $overall_hit_rate . '%',
            'cache_efficiency_score' => min(100, $overall_hit_rate + ($memory_hit_rate * 0.2))
        ]);
    }

    /**
     * 获取API请求合并统计
     *
     * @since    1.8.1
     * @return   array    请求合并统计数据
     */
    public static function get_request_merge_stats(): array {
        // 计算合并比率
        if (self::$request_merge_stats['total_requests'] > 0) {
            self::$request_merge_stats['merge_ratio'] = round(
                (self::$request_merge_stats['saved_api_calls'] / self::$request_merge_stats['total_requests']) * 100, 2
            );
        }

        return array_merge(self::$request_merge_stats, [
            'merge_ratio_percent' => self::$request_merge_stats['merge_ratio'] . '%',
            'efficiency_score' => min(100, self::$request_merge_stats['merge_ratio'] +
                                     (self::$request_merge_stats['batch_operations'] * 2)),
            'config' => self::$request_merge_config
        ]);
    }

    /**
     * 重置API请求合并统计
     *
     * @since    1.8.1
     */
    public static function reset_request_merge_stats(): void {
        self::$request_merge_stats = [
            'total_requests' => 0,
            'merged_requests' => 0,
            'merge_ratio' => 0,
            'saved_api_calls' => 0,
            'batch_operations' => 0
        ];
    }

    /**
     * 设置请求合并配置
     *
     * @since    1.8.1
     * @param    array    $config    配置数组
     */
    public static function set_request_merge_config(array $config): void {
        self::$request_merge_config = array_merge(self::$request_merge_config, $config);

        Notion_To_WordPress_Helper::debug_log(
            '请求合并配置已更新: ' . json_encode(self::$request_merge_config),
            'Request Merge Config'
        );
    }

    /**
     * 获取综合API性能统计
     *
     * @since    1.8.1
     * @return   array    综合性能统计
     */
    public static function get_comprehensive_api_stats(): array {
        $cache_stats = self::get_enhanced_cache_stats();
        $merge_stats = self::get_request_merge_stats();

        // 计算综合性能评分
        $cache_score = (float)str_replace('%', '', $cache_stats['overall_hit_rate'] ?? '0');
        $merge_score = $merge_stats['merge_ratio'];
        $comprehensive_score = round(($cache_score * 0.6) + ($merge_score * 0.4), 2);

        return [
            'cache_performance' => $cache_stats,
            'request_merge_performance' => $merge_stats,
            'comprehensive_score' => $comprehensive_score,
            'performance_grade' => $comprehensive_score >= 80 ? 'A' :
                                  ($comprehensive_score >= 60 ? 'B' :
                                  ($comprehensive_score >= 40 ? 'C' : 'D')),
            'recommendations' => self::generate_performance_recommendations($cache_score, $merge_score)
        ];
    }

    /**
     * 生成性能优化建议
     *
     * @since    1.8.1
     * @param    float    $cache_score    缓存评分
     * @param    float    $merge_score    合并评分
     * @return   array                    优化建议数组
     */
    private static function generate_performance_recommendations(float $cache_score, float $merge_score): array {
        $recommendations = [];

        if ($cache_score < 70) {
            $recommendations[] = '建议优化缓存策略，提升缓存命中率';
        }

        if ($merge_score < 30) {
            $recommendations[] = '建议启用API请求合并，减少API调用次数';
        }

        if ($cache_score > 90 && $merge_score > 50) {
            $recommendations[] = '性能表现优秀，可考虑进一步优化并发数';
        }

        if (empty($recommendations)) {
            $recommendations[] = '当前性能表现良好，继续保持';
        }

        return $recommendations;
    }

    /**
     * 优化HTTP请求参数，特别针对Notion API请求
     *
     * @since    1.8.3
     * @param    array     $args    HTTP请求参数
     * @param    string    $url     请求URL
     * @return   array              优化后的请求参数
     */
    public function optimize_http_request_args(array $args, string $url): array {
        // 只对Notion API请求进行优化
        if (strpos($url, 'api.notion.com') === false) {
            return $args;
        }

        // 添加DNS和连接优化配置
        if (!isset($args['curl'])) {
            $args['curl'] = [];
        }

        // DNS优化配置
        $args['curl'][CURLOPT_DNS_SERVERS] = '8.8.8.8,1.1.1.1';
        $args['curl'][CURLOPT_DNS_CACHE_TIMEOUT] = 300; // 5分钟DNS缓存

        // 连接优化
        $args['curl'][CURLOPT_TCP_KEEPALIVE] = 1;
        $args['curl'][CURLOPT_TCP_KEEPIDLE] = 60;
        $args['curl'][CURLOPT_TCP_KEEPINTVL] = 30;
        $args['curl'][CURLOPT_FRESH_CONNECT] = false;
        $args['curl'][CURLOPT_FORBID_REUSE] = false;

        // 压缩支持
        $args['curl'][CURLOPT_ENCODING] = '';

        // 超时优化 - 大幅减少超时时间以提升速度
        if (!isset($args['timeout'])) {
            $args['timeout'] = 5;  // 从30秒减少到5秒
        }
        if (!isset($args['connect_timeout'])) {
            $args['connect_timeout'] = 2;  // 从10秒减少到2秒
        }

        // SSL优化
        $args['curl'][CURLOPT_SSL_VERIFYPEER] = true;
        $args['curl'][CURLOPT_SSL_VERIFYHOST] = 2;

        // 禁用优化日志以提升速度
        // Notion_To_WordPress_Helper::debug_log(
        //     'HTTP请求已优化: ' . $url,
        //     'HTTP Optimization'
        // );

        return $args;
    }
}