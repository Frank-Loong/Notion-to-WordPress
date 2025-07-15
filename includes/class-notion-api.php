<?php
declare(strict_types=1);

/**
 * Notion API 交互类。
 * 封装了与 Notion API 通信的所有方法，包括获取数据库、页面、块等内容。
 * 处理 API 认证、请求发送、响应解析和错误处理。提供了完整的 Notion API
 * 功能封装，支持数据库查询、页面操作、内容同步等核心功能。
 *
 * @since      1.0.9
 * @version    1.8.3-beta.2
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

        // 生成缓存键
        $cache_key = $database_id . '_' . md5(serialize($filter)) . '_' . ($with_details ? 'detailed' : 'basic');

        // 检查缓存
        if (isset(self::$database_pages_cache[$cache_key])) {
            Notion_To_WordPress_Helper::debug_log(
                '从缓存获取数据库页面: ' . $database_id,
                'Database Pages Cache'
            );

            Notion_To_WordPress_Helper::end_performance_timer('get_database_pages', $start_time, [
                'cache_hit' => true,
                'database_id' => $database_id,
                'with_details' => $with_details
            ]);

            return self::$database_pages_cache[$cache_key];
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

            $response = $this->send_request($endpoint, 'POST', $data);

            if (isset($response['results'])) {
                $all_results = array_merge($all_results, $response['results']);
                Notion_To_WordPress_Helper::debug_log(
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

        // 存储到缓存
        self::$database_pages_cache[$cache_key] = $all_results;

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
     * 递归获取一个块的所有子块内容，优化版本。
     *
     * @since    1.0.8
     * @param    string    $block_id    块或页面的 ID。
     * @param    int       $depth       当前递归深度，用于限制递归层数。
     * @param    int       $max_depth   最大递归深度，默认为3层。
     * @return   array<string, mixed>                 子块对象数组。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_page_content(string $block_id, int $depth = 0, int $max_depth = 3): array {
        // 检查递归深度限制 - 降低到3层以减少API调用
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
     * 获取一个块的直接子块（处理分页）。
     *
     * @since 1.0.8
     * @access private
     * @param string $block_id 块的 ID。
     * @return array<string, mixed> 子块对象数组。
     * @throws Exception 如果 API 请求失败。
     */
    private function get_block_children(string $block_id): array {
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
                $response = $this->send_request($endpoint, 'GET');

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
                Notion_To_WordPress_Helper::error_log(
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
     * 获取页面的元数据 - 优化版本（会话级缓存）
     *
     * @since    1.0.8
     * @param    string    $page_id    Notion 页面的 ID。
     * @return   array<string, mixed>                 页面对象。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_page_metadata(string $page_id): array {
        // 检查会话级缓存
        if (isset(self::$page_cache[$page_id])) {
            return self::$page_cache[$page_id];
        }

        $endpoint = 'pages/' . $page_id;
        $result = $this->send_request($endpoint);

        // 存储到会话级缓存
        self::$page_cache[$page_id] = $result;

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
     * 获取单个页面对象 - 优化版本（会话级缓存）
     *
     * @param string $page_id 页面ID
     * @return array<string, mixed>
     * @throws Exception
     */
    public function get_page(string $page_id): array {
        // 检查会话级缓存
        if (isset(self::$page_cache[$page_id])) {
            return self::$page_cache[$page_id];
        }

        $endpoint = 'pages/' . $page_id;
        $result = $this->send_request($endpoint);

        // 存储到会话级缓存
        self::$page_cache[$page_id] = $result;

        return $result;
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
        if (isset(self::$page_cache[$page_id])) {
            Notion_To_WordPress_Helper::debug_log(
                '从缓存获取页面详情: ' . $page_id,
                'Page Details Cache'
            );
            return self::$page_cache[$page_id];
        }

            try {
                $page_data = $this->get_page($page_id);

            // 存储到缓存
            self::$page_cache[$page_id] = $page_data;

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
     * 批量为页面添加详细信息 - 优化版本
     *
     * @since 1.1.1
     * @param array<string, mixed> $pages 页面数组
     * @return array<string, mixed> 包含详细信息的页面数组
     */
    private function enrich_pages_with_details(array $pages): array {
        // 对于大量页面，跳过详细信息获取以提高性能
        if (count($pages) > 20) {
            Notion_To_WordPress_Helper::debug_log(
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

    /**
     * 清除页面缓存
     *
     * @since 1.1.1
     * @param string|null $page_id 特定页面ID，为null时清除所有缓存
     */
    public static function clear_page_cache(?string $page_id = null): void {
        if ($page_id !== null) {
            unset(self::$page_cache[$page_id]);
            unset(self::$cache_timestamps['page_' . $page_id]);
            Notion_To_WordPress_Helper::debug_log(
                '清除页面缓存: ' . $page_id,
                'Page Cache'
            );
        } else {
            self::$page_cache = [];
            self::$database_pages_cache = [];
            self::$database_info_cache = [];
            self::$cache_timestamps = [];
            Notion_To_WordPress_Helper::debug_log(
                '清除所有缓存',
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
     * 获取缓存统计信息
     *
     * @since 1.1.1
     * @return array
     */
    public static function get_cache_stats(): array {
        return [
            'page_cache_count' => count(self::$page_cache),
            'database_pages_cache_count' => count(self::$database_pages_cache),
            'database_info_cache_count' => count(self::$database_info_cache),
            'total_cache_items' => count(self::$cache_timestamps),
            'cache_ttl' => self::$cache_ttl
        ];
    }
}