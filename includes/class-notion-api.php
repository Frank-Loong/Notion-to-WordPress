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
    private static int $cache_ttl = 300; // 5分钟

    /**
     * 缓存时间戳
     *
     * @since    1.1.1
     * @access   private
     * @var      array<string, int>
     */
    private static array $cache_timestamps = [];

    /**
     * WordPress transients缓存过期时间（秒）
     *
     * @since    1.8.1
     * @access   private
     * @var      int
     */
    private static int $transient_cache_ttl = 3600; // 1小时

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
        'total_requests' => 0
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
            'timeout' => 30
        ];

        if (!empty($data) && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

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
            
            error_log('Notion to WordPress: 发送API请求到: ' . $endpoint);
            $response = $this->send_request($endpoint, 'POST', $data);
            error_log('Notion to WordPress: API响应状态: ' . (isset($response['results']) ? 'success' : 'no results'));

            if (isset($response['results'])) {
                $all_results = array_merge($all_results, $response['results']);
                error_log('Notion to WordPress: 当前批次页面数: ' . count($response['results']) . ', 总计: ' . count($all_results));
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
     * 递归填充子块数据
     *
     * @since    1.8.1
     * @param    array    &$blocks           块数组（引用传递）
     * @param    array    $concurrent_results 并发请求结果
     */
    private function fill_children_data(array &$blocks, array $concurrent_results): void {
        foreach ($blocks as $i => &$block) {
            if ($block['has_children'] && isset($block['type']) && $block['type'] !== 'child_database') {
                $child_id = $block['id'];
                if (isset($concurrent_results[$child_id])) {
                    $child_data = $concurrent_results[$child_id];
                    // 直接使用Notion API返回的数据结构
                    if (isset($child_data['results'])) {
                        $child_blocks = $child_data['results'];
                        // 递归填充子块的子块
                        $this->fill_children_data($child_blocks, $concurrent_results);
                        $blocks[$i]['children'] = $child_blocks;
                    }
                } else {
                    error_log('Notion to WordPress: 并发获取子块失败: ' . $child_id);
                }
            }
        }
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
     * 多层缓存获取数据的通用方法
     *
     * @since    1.8.1
     * @param    string    $cache_key     缓存键
     * @param    callable  $data_callback 获取数据的回调函数
     * @param    string    $cache_type    缓存类型（用于统计）
     * @return   mixed                    缓存的数据或回调函数的结果
     */
    private function get_cached_data(string $cache_key, callable $data_callback, string $cache_type = 'general') {
        $start_time = Notion_To_WordPress_Helper::start_performance_timer('multi_layer_cache');
        self::$cache_stats['total_requests']++;

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

        // 存储到两层缓存
        $this->store_to_memory_cache($cache_key, $data);
        set_transient($transient_key, $data, self::$transient_cache_ttl);

        Notion_To_WordPress_Helper::end_performance_timer('multi_layer_cache', $start_time, [
            'cache_type' => $cache_type,
            'hit_level' => 'miss',
            'cache_key' => $cache_key,
            'data_size' => is_array($data) ? count($data) : strlen(serialize($data))
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
     * 批量获取页面内容（并发版本）
     *
     * @since    1.8.1
     * @param    array    $page_ids    页面ID数组
     * @return   array                 页面内容数组，键为页面ID
     */
    public function get_pages_content_concurrent(array $page_ids): array {
        if (empty($page_ids)) {
            return [];
        }

        $start_time = Notion_To_WordPress_Helper::start_performance_timer('concurrent_pages_content');
        $concurrent_manager = $this->get_concurrent_manager();

        // 为每个页面添加获取内容的并发请求
        foreach ($page_ids as $page_id) {
            $concurrent_manager->add_request(
                $page_id . '_content',
                'blocks/' . $page_id . '/children?page_size=100',
                'GET',
                [],
                ['type' => 'page_content', 'page_id' => $page_id]
            );
        }

        // 执行并发请求
        $concurrent_results = $concurrent_manager->execute_batch();
        $results = [];

        // 处理并发请求结果
        foreach ($concurrent_results as $request_id => $result) {
            $page_id = str_replace('_content', '', $request_id);

            if (isset($result['error'])) {
                Notion_To_WordPress_Helper::error_log(
                    '并发获取页面内容失败: ' . $page_id . ' - ' . ($result['error_message'] ?? 'Unknown error'),
                    'Concurrent API'
                );
                $results[$page_id] = [];
            } else {
                $results[$page_id] = $result['results'] ?? [];
            }
        }

        // 记录性能数据
        Notion_To_WordPress_Helper::end_performance_timer('concurrent_pages_content', $start_time, [
            'total_pages' => count($page_ids),
            'api_requests' => count($page_ids),
            'concurrent_stats' => $concurrent_manager->get_stats()
        ]);

        return $results;
    }
}