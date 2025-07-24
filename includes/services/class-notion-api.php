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
     * 这是一个通用的私有方法，用于处理所有类型的 API 请求。
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

        Notion_Logger::debug_log(
            '获取数据库页面（实时）: ' . $database_id . ', 详细信息: ' . ($with_details ? '是' : '否'),
            'Database Pages'
        );

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
        // 检查递归深度限制 - 降低到5层以减少API调用
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

        Notion_Logger::debug_log(
            sprintf('开始批量API请求: %d个端点，方法: %s', count($endpoints), $method),
            'Batch API'
        );

        try {
            // 创建并发网络管理器
            $manager = new Notion_Concurrent_Network_Manager(5); // 最多5个并发请求

            // 添加所有请求到队列
            foreach ($endpoints as $index => $endpoint) {
                $url = $this->api_base . $endpoint;
                $data = $data_array[$index] ?? [];

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
}