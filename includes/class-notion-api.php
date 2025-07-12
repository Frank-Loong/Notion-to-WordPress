<?php
declare(strict_types=1);

/**
 * Notion API 交互类
 *
 * 封装了与 Notion API 通信的所有方法，包括获取数据库、页面和块等。
 *
 * @since      1.0.9
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
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
    private static int $cache_ttl;

    /**
     * 缓存时间戳
     *
     * @since    1.1.1
     * @access   private
     * @var      array<string, int>
     */
    private static array $cache_timestamps = [];

    /**
     * 缓存访问时间（用于LRU算法）
     *
     * @since    1.1.1
     * @access   private
     * @var      array<string, int>
     */
    private static array $cache_access_times = [];

    /**
     * 最大缓存条目数
     *
     * @since    1.1.1
     * @access   private
     * @var      int
     */
    private static int $max_cache_items;

    /**
     * 内存使用限制（字节）
     *
     * @since    1.1.1
     * @access   private
     * @var      int
     */
    private static int $memory_limit;

    /**
     * 缓存统计信息
     *
     * @since    1.1.1
     * @access   private
     * @var      array
     */
    private static array $cache_stats = [
        'hits' => 0,
        'misses' => 0,
        'evictions' => 0,
        'memory_cleanups' => 0
    ];

    /**
     * 静态初始化缓存配置
     *
     * @since 1.1.1
     */
    private static function init_cache_config(): void {
        if (!isset(self::$cache_ttl)) {
            self::$cache_ttl = Notion_To_WordPress_Helper::get_config('cache.ttl', 300);
            self::$max_cache_items = Notion_To_WordPress_Helper::get_config('cache.max_items', 1000);
            self::$memory_limit = Notion_To_WordPress_Helper::get_config('cache.memory_limit_mb', 50) * 1024 * 1024;
        }
    }

    /**
     * 构造函数，初始化 API 客户端。
     *
     * @since    1.0.8
     * @param    string    $api_key    Notion API 密钥。
     */
    public function __construct(string $api_key) {
        // 初始化缓存配置
        self::init_cache_config();

        // 如果可能是加密的API密钥，尝试解密
        if (!empty($api_key) && strlen($api_key) > 50) {
            $this->api_key = Notion_To_WordPress_Helper::decrypt_api_key($api_key);
        } else {
            $this->api_key = $api_key;
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

        // 记录数据库区块相关的API调用（仅在调试模式下）
        if (defined('WP_DEBUG') && WP_DEBUG && strpos($endpoint, 'blocks/') !== false && strpos($endpoint, 'children') !== false) {
            Notion_To_WordPress_Helper::debug_log(
                '数据库区块API调用: ' . $endpoint,
                'Database Block'
            );
        }

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization'  => 'Bearer ' . $this->api_key,
                'Content-Type'   => 'application/json',
                'Notion-Version' => '2022-06-28'
            ],
            'timeout' => Notion_To_WordPress_Helper::get_config('api.timeout', 30)
        ];

        if (!empty($data) && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = __('API请求失败: ', 'notion-to-wordpress') . $response->get_error_message();
            throw new Exception($error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            $error_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $error_body['message'] ?? wp_remote_retrieve_body($response);

            // 根据HTTP状态码确定错误类型
            $error_type = $this->determine_error_type_from_status_code($response_code);
            $full_message = __('API错误 (', 'notion-to-wordpress') . $response_code . '): ' . $error_message;

            throw new Exception($full_message);
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true) ?: [];
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

        Notion_To_WordPress_Helper::debug_log('get_database_pages() 开始，Database ID: ' . $database_id, 'Database Pages');

        // 生成缓存键
        $cache_key = $database_id . '_' . md5(serialize($filter)) . '_' . ($with_details ? 'detailed' : 'basic');

        // 检查缓存
        $cached_data = self::get_from_cache('database_pages', $cache_key);
        if ($cached_data !== null) {
            Notion_To_WordPress_Helper::debug_log(
                '从缓存获取数据库页面: ' . $database_id,
                'Database Pages Cache'
            );

            Notion_To_WordPress_Helper::end_performance_timer('get_database_pages', $start_time, [
                'cache_hit' => true,
                'database_id' => $database_id,
                'with_details' => $with_details
            ]);

            return $cached_data;
        }

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

            Notion_To_WordPress_Helper::debug_log('发送API请求到: ' . $endpoint, 'Database Pages');
            $response = $this->send_request($endpoint, 'POST', $data);
            Notion_To_WordPress_Helper::debug_log('API响应状态: ' . (isset($response['results']) ? 'success' : 'no results'), 'Database Pages');

            if (isset($response['results'])) {
                $all_results = array_merge($all_results, $response['results']);
                Notion_To_WordPress_Helper::debug_log('当前批次页面数: ' . count($response['results']) . ', 总计: ' . count($all_results), 'Database Pages');
            }

            $has_more = $response['has_more'] ?? false;
            $start_cursor = $response['next_cursor'] ?? null;
        }

        // 如果需要详细信息，批量获取页面详情
        if ($with_details && !empty($all_results)) {
            $all_results = $this->enrich_pages_with_details($all_results);
        }

        // 存储到缓存
        self::set_to_cache('database_pages', $cache_key, $all_results);

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
        Notion_To_WordPress_Helper::debug_log('get_page_content() 开始，Block ID: ' . $block_id, 'Page Content');

        $blocks = $this->get_block_children($block_id);
        Notion_To_WordPress_Helper::debug_log('获取到顶级块数量: ' . count($blocks), 'Page Content');

        foreach ($blocks as $i => $block) {
            if ($block['has_children']) {
                // 特殊处理：数据库区块不尝试获取子内容，避免API 404错误
                if (isset($block['type']) && $block['type'] === 'child_database') {
                    Notion_To_WordPress_Helper::debug_log(
                        '在API层跳过数据库区块子内容获取: ' . $block['id'],
                        'Database Block'
                    );
                    // 不设置children，保持has_children为true但不尝试获取子内容
                    continue;
                }

                Notion_To_WordPress_Helper::debug_log('处理子块，Block ID: ' . $block['id'], 'Page Content');
                try {
                    $blocks[$i]['children'] = $this->get_page_content($block['id']);
                } catch (Exception $e) {
                    // 特殊处理：如果是数据库权限相关的404错误，跳过这个子块但不影响整个页面
                    if (strpos($e->getMessage(), '404') !== false &&
                        strpos($e->getMessage(), 'Make sure the relevant pages and databases are shared') !== false) {

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

        Notion_To_WordPress_Helper::debug_log('get_page_content() 完成，返回块数量: ' . count($blocks), 'Page Content');
        return $blocks;
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
        Notion_To_WordPress_Helper::debug_log('get_block_children() 开始，Block ID: ' . $block_id, 'Block Children');

        $all_results = [];
        $has_more = true;
        $start_cursor = null;

        while ($has_more) {
            $endpoint = 'blocks/' . $block_id . '/children?page_size=100';
            if ($start_cursor) {
                $endpoint .= '&start_cursor=' . $start_cursor;
            }

            Notion_To_WordPress_Helper::debug_log('请求子块，endpoint: ' . $endpoint, 'Block Children');

            try {
                $response = $this->send_request($endpoint, 'GET');

                if (isset($response['results'])) {
                    $all_results = array_merge($all_results, $response['results']);
                    Notion_To_WordPress_Helper::debug_log('获取到子块数量: ' . count($response['results']) . ', 总计: ' . count($all_results), 'Block Children');

                    // 记录每个子块的类型，特别关注数据库区块
                    foreach ($response['results'] as $block) {
                        if (isset($block['type']) && $block['type'] === 'child_database') {
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

        Notion_To_WordPress_Helper::debug_log('get_block_children() 完成，返回总块数: ' . count($all_results), 'Block Children');
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
    public function get_database(string $database_id)
    {
        try {
            $endpoint = 'databases/' . $database_id;
            return $this->send_request($endpoint);
        } catch (Exception $e) {
            return Notion_To_WordPress_Helper::exception_to_wp_error(
                $e,
                Notion_To_WordPress_Helper::ERROR_TYPE_API,
                Notion_To_WordPress_Helper::ERROR_SEVERITY_MEDIUM,
                ['operation' => 'get_database', 'database_id' => $database_id]
            );
        }
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
                $result = $this->get_database($database_id);
                if (is_wp_error($result)) {
                    return $result;
                }
            }

            return true;
        } catch (Exception $e) {
            return Notion_To_WordPress_Helper::exception_to_wp_error(
                $e,
                Notion_To_WordPress_Helper::ERROR_TYPE_API,
                Notion_To_WordPress_Helper::ERROR_SEVERITY_HIGH,
                ['operation' => 'test_connection', 'database_id' => $database_id]
            );
        }
    }

    /**
     * 获取单个页面对象
     *
     * @param string $page_id 页面ID
     * @return array|WP_Error 页面数据或错误对象
     */
    public function get_page(string $page_id) {
        try {
            $endpoint = 'pages/' . $page_id;
            return $this->send_request($endpoint);
        } catch (Exception $e) {
            return Notion_To_WordPress_Helper::exception_to_wp_error(
                $e,
                Notion_To_WordPress_Helper::ERROR_TYPE_API,
                Notion_To_WordPress_Helper::ERROR_SEVERITY_MEDIUM,
                ['operation' => 'get_page', 'page_id' => $page_id]
            );
        }
    }

    /**
     * 安全获取数据库信息，支持优雅降级
     *
     * @since 1.0.9
     * @param string $database_id 数据库ID
     * @return array<string, mixed> 数据库信息数组，失败时返回空数组
     */
    public function get_database_info(string $database_id): array {
        // 检查缓存
        if ($this->is_cache_valid('database_info_' . $database_id)) {
            Notion_To_WordPress_Helper::debug_log(
                '从缓存获取数据库信息: ' . $database_id,
                'Database Info Cache'
            );
            return self::$database_info_cache[$database_id];
        }

        try {
            $database_info = $this->get_database($database_id);

            // 存储到缓存
            self::$database_info_cache[$database_id] = $database_info;
            self::$cache_timestamps['database_info_' . $database_id] = time();

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
    }

    /**
     * 获取页面详细信息，包括cover、icon等完整属性
     *
     * @since 1.1.1
     * @param string $page_id 页面ID
     * @return array<string, mixed> 页面详细信息数组，失败时返回空数组
     */
    public function get_page_details(string $page_id): array {
        // 检查缓存
        $cached_data = self::get_from_cache('page', $page_id);
        if ($cached_data !== null) {
            Notion_To_WordPress_Helper::debug_log(
                '从缓存获取页面详情: ' . $page_id,
                'Page Details Cache'
            );
            return $cached_data;
        }

        try {
            $page_data = $this->get_page($page_id);

            // 存储到缓存
            self::set_to_cache('page', $page_id, $page_data);

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
    }

    /**
     * 批量为页面添加详细信息
     *
     * @since 1.1.1
     * @param array<string, mixed> $pages 页面数组
     * @return array<string, mixed> 包含详细信息的页面数组
     */
    private function enrich_pages_with_details(array $pages): array {
        $enriched_pages = [];

        foreach ($pages as $page) {
            $page_id = $page['id'] ?? '';
            if (empty($page_id)) {
                $enriched_pages[] = $page;
                continue;
            }

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
            }
        }

        return $enriched_pages;
    }

    /**
     * 智能缓存获取方法
     *
     * @since 1.1.1
     * @param string $cache_type 缓存类型 (page, database_pages, database_info)
     * @param string $key 缓存键
     * @return mixed|null 缓存数据或null
     */
    private static function get_from_cache(string $cache_type, string $key) {
        $cache_key = $cache_type . '_' . $key;
        $transient_key = 'notion_wp_' . md5($cache_key); // 使用MD5避免过长的transient键

        // 首先尝试从内存缓存获取数据
        if (self::is_cache_valid($cache_key)) {
            // 更新访问时间（LRU）
            self::$cache_access_times[$cache_key] = time();
            self::$cache_stats['hits']++;

            // 根据类型返回缓存数据
            switch ($cache_type) {
                case 'page':
                    return self::$page_cache[$key] ?? null;
                case 'database_pages':
                    return self::$database_pages_cache[$key] ?? null;
                case 'database_info':
                    return self::$database_info_cache[$key] ?? null;
                default:
                    return null;
            }
        }

        // 内存中没有缓存或已过期，尝试从transient获取
        // 获取持久性缓存选项设置
        $options = get_option('notion_to_wordpress_options', []);
        $use_persistent_cache = isset($options['use_persistent_cache']) && $options['use_persistent_cache'] === 'yes';

        if ($use_persistent_cache) {
            $cached_data = get_transient($transient_key);
            if ($cached_data !== false) {
                // 数据存在于transient中，更新内存缓存
                self::set_to_cache($cache_type, $key, $cached_data);
                self::$cache_stats['hits']++;
                return $cached_data;
            }
        }

        // 缓存未命中
        self::$cache_stats['misses']++;
        return null;
    }

    /**
     * 智能缓存存储方法
     *
     * @since 1.1.1
     * @param string $cache_type 缓存类型
     * @param string $key 缓存键
     * @param mixed $data 要缓存的数据
     */
    private static function set_to_cache(string $cache_type, string $key, $data): void {
        $cache_key = $cache_type . '_' . $key;
        $transient_key = 'notion_wp_' . md5($cache_key);
        $current_time = time();

        // 检查内存使用情况
        self::check_memory_usage();

        // 检查缓存大小限制
        self::enforce_cache_limits();

        // 存储数据
        switch ($cache_type) {
            case 'page':
                self::$page_cache[$key] = $data;
                break;
            case 'database_pages':
                self::$database_pages_cache[$key] = $data;
                break;
            case 'database_info':
                self::$database_info_cache[$key] = $data;
                break;
        }

        // 更新时间戳
        self::$cache_timestamps[$cache_key] = $current_time;
        self::$cache_access_times[$cache_key] = $current_time;

        // 获取持久性缓存选项设置
        $options = get_option('notion_to_wordpress_options', []);
        $use_persistent_cache = isset($options['use_persistent_cache']) && $options['use_persistent_cache'] === 'yes';
        
        // 根据设置决定是否使用transient存储
        if ($use_persistent_cache) {
            // 计算持久化缓存的过期时间（默认10分钟）
            $transient_expiration = isset($options['persistent_cache_ttl']) ? 
                intval($options['persistent_cache_ttl']) : 600;
                
            // 将数据序列化并存储到transient
            set_transient($transient_key, $data, $transient_expiration);
        }
    }

    /**
     * 检查内存使用情况并在必要时清理
     *
     * @since 1.1.1
     */
    private static function check_memory_usage(): void {
        $current_memory = memory_get_usage(true);

        // 如果内存使用超过限制，触发清理
        if ($current_memory > self::$memory_limit) {
            self::cleanup_cache_by_memory();
            self::$cache_stats['memory_cleanups']++;

            Notion_To_WordPress_Helper::debug_log(
                '内存使用过高，触发缓存清理。当前内存: ' . round($current_memory / 1024 / 1024, 2) . 'MB',
                'Cache Memory Management'
            );
        }
    }

    /**
     * 强制执行缓存大小限制
     *
     * @since 1.1.1
     */
    private static function enforce_cache_limits(): void {
        $total_items = count(self::$cache_timestamps);

        if ($total_items >= self::$max_cache_items) {
            $items_to_remove = $total_items - self::$max_cache_items + 100; // 额外清理100个
            self::cleanup_cache_by_lru($items_to_remove);

            Notion_To_WordPress_Helper::debug_log(
                '缓存条目数量超限，清理 ' . $items_to_remove . ' 个最少使用的条目',
                'Cache Size Management'
            );
        }
    }

    /**
     * 清除页面缓存
     *
     * @since 1.1.1
     * @param string|null $page_id 特定页面ID，为null时清除所有缓存
     */
    public static function clear_page_cache(?string $page_id = null): void {
        if ($page_id !== null) {
            unset(self::$page_cache[$page_id]);
            $cache_key = 'page_' . $page_id;
            unset(self::$cache_timestamps[$cache_key]);
            unset(self::$cache_access_times[$cache_key]);
            Notion_To_WordPress_Helper::debug_log(
                '清除页面缓存: ' . $page_id,
                'Page Cache'
            );
        } else {
            self::$page_cache = [];
            self::$database_pages_cache = [];
            self::$database_info_cache = [];
            self::$cache_timestamps = [];
            self::$cache_access_times = [];
            self::$cache_stats = ['hits' => 0, 'misses' => 0, 'evictions' => 0, 'memory_cleanups' => 0];
            Notion_To_WordPress_Helper::debug_log(
                '清除所有缓存',
                'Page Cache'
            );
        }
    }

    /**
     * 基于LRU算法清理缓存
     *
     * @since 1.1.1
     * @param int $items_to_remove 要移除的条目数
     */
    private static function cleanup_cache_by_lru(int $items_to_remove): void {
        if (empty(self::$cache_access_times)) {
            return;
        }

        // 按访问时间排序，最少使用的在前
        asort(self::$cache_access_times);
        $keys_to_remove = array_slice(array_keys(self::$cache_access_times), 0, $items_to_remove);

        foreach ($keys_to_remove as $cache_key) {
            self::remove_cache_item($cache_key);
            self::$cache_stats['evictions']++;
        }
    }

    /**
     * 基于内存压力清理缓存
     *
     * @since 1.1.1
     */
    private static function cleanup_cache_by_memory(): void {
        // 清理25%的最少使用缓存
        $total_items = count(self::$cache_timestamps);
        $items_to_remove = max(1, intval($total_items * 0.25));

        self::cleanup_cache_by_lru($items_to_remove);
    }

    /**
     * 移除单个缓存项
     *
     * @since 1.1.1
     * @param string $cache_key 缓存键
     */
    private static function remove_cache_item(string $cache_key): void {
        // 解析缓存键获取类型和实际键
        $parts = explode('_', $cache_key, 2);
        if (count($parts) < 2) {
            return;
        }

        $cache_type = $parts[0];
        $key = $parts[1];

        // 根据类型移除缓存
        switch ($cache_type) {
            case 'page':
                unset(self::$page_cache[$key]);
                break;
            case 'database':
                if (strpos($cache_key, 'database_pages_') === 0) {
                    unset(self::$database_pages_cache[$key]);
                } elseif (strpos($cache_key, 'database_info_') === 0) {
                    unset(self::$database_info_cache[$key]);
                }
                break;
        }

        // 移除时间戳
        unset(self::$cache_timestamps[$cache_key]);
        unset(self::$cache_access_times[$cache_key]);
    }

    /**
     * 检查缓存是否有效
     *
     * @since 1.1.1
     * @param string $cache_key 缓存键
     * @return bool
     */
    private static function is_cache_valid(string $cache_key): bool {
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

        foreach ($expired_keys as $cache_key) {
            self::remove_cache_item($cache_key);
        }

        if (!empty($expired_keys)) {
            Notion_To_WordPress_Helper::debug_log(
                '清理过期缓存: ' . count($expired_keys) . ' 个项目',
                'Cache Cleanup'
            );
        }
    }

    /**
     * 缓存预热
     *
     * @since 1.1.1
     * @param string $database_id 数据库ID
     */
    public static function warmup_cache(string $database_id): void {
        $start_time = microtime(true);

        try {
            // 预热数据库信息缓存
            $api = new self('dummy'); // 临时实例
            $api->get_database($database_id);

            // 预热数据库页面缓存（基础信息）
            $api->get_database_pages($database_id, [], false);

            $duration = microtime(true) - $start_time;
            Notion_To_WordPress_Helper::debug_log(
                '缓存预热完成，耗时: ' . round($duration * 1000, 2) . 'ms',
                'Cache Warmup'
            );
        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log(
                '缓存预热失败: ' . $e->getMessage(),
                'Cache Warmup'
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
        $memory_usage = memory_get_usage(true);
        $hit_rate = self::$cache_stats['hits'] + self::$cache_stats['misses'] > 0
            ? round((self::$cache_stats['hits'] / (self::$cache_stats['hits'] + self::$cache_stats['misses'])) * 100, 2)
            : 0;

        return [
            'page_cache_count' => count(self::$page_cache),
            'database_pages_cache_count' => count(self::$database_pages_cache),
            'database_info_cache_count' => count(self::$database_info_cache),
            'total_cache_items' => count(self::$cache_timestamps),
            'cache_ttl' => self::$cache_ttl,
            'max_cache_items' => self::$max_cache_items,
            'memory_limit' => self::$memory_limit,
            'current_memory_usage' => $memory_usage,
            'memory_usage_mb' => round($memory_usage / 1024 / 1024, 2),
            'memory_limit_mb' => round(self::$memory_limit / 1024 / 1024, 2),
            'cache_hits' => self::$cache_stats['hits'],
            'cache_misses' => self::$cache_stats['misses'],
            'cache_evictions' => self::$cache_stats['evictions'],
            'memory_cleanups' => self::$cache_stats['memory_cleanups'],
            'hit_rate_percent' => $hit_rate,
            'oldest_cache_age' => self::get_oldest_cache_age(),
            'newest_cache_age' => self::get_newest_cache_age()
        ];
    }

    /**
     * 获取最旧缓存的年龄（秒）
     *
     * @since 1.1.1
     * @return int
     */
    private static function get_oldest_cache_age(): int {
        if (empty(self::$cache_timestamps)) {
            return 0;
        }

        $oldest_time = min(self::$cache_timestamps);
        return time() - $oldest_time;
    }

    /**
     * 获取最新缓存的年龄（秒）
     *
     * @since 1.1.1
     * @return int
     */
    private static function get_newest_cache_age(): int {
        if (empty(self::$cache_timestamps)) {
            return 0;
        }

        $newest_time = max(self::$cache_timestamps);
        return time() - $newest_time;
    }

    /**
     * 设置缓存配置
     *
     * @since 1.1.1
     * @param array $config 配置数组
     */
    public static function configure_cache(array $config): void {
        if (isset($config['max_items'])) {
            self::$max_cache_items = max(100, min(10000, intval($config['max_items'])));
        }

        if (isset($config['memory_limit_mb'])) {
            self::$memory_limit = max(10, min(500, intval($config['memory_limit_mb']))) * 1024 * 1024;
        }

        if (isset($config['ttl'])) {
            self::$cache_ttl = max(60, min(3600, intval($config['ttl'])));
        }

        Notion_To_WordPress_Helper::debug_log(
            '缓存配置已更新 - 最大条目: ' . self::$max_cache_items .
            ', 内存限制: ' . round(self::$memory_limit / 1024 / 1024, 2) . 'MB' .
            ', TTL: ' . self::$cache_ttl . 's',
            'Cache Configuration'
        );
    }

    /**
     * 根据HTTP状态码确定错误类型
     *
     * @since 1.1.1
     * @param int $status_code HTTP状态码
     * @return string 错误类型
     */
    private function determine_error_type_from_status_code(int $status_code): string {
        switch ($status_code) {
            case 401:
            case 403:
                return Notion_To_WordPress_Helper::ERROR_TYPE_PERMISSION;
            case 429:
                return Notion_To_WordPress_Helper::ERROR_TYPE_RATE_LIMIT;
            case 400:
            case 422:
                return Notion_To_WordPress_Helper::ERROR_TYPE_VALIDATION;
            case 500:
            case 502:
            case 503:
            case 504:
                return Notion_To_WordPress_Helper::ERROR_TYPE_API;
            default:
                return Notion_To_WordPress_Helper::ERROR_TYPE_NETWORK;
        }
    }
}