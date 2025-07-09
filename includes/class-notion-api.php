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
     * @return   array<string, mixed>                     页面对象数组。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_database_pages(string $database_id, array $filter = []): array {
        error_log('Notion to WordPress: get_database_pages() 开始，Database ID: ' . $database_id);

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
                    $blocks[$i]['children'] = $this->get_page_content($block['id']);
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
        try {
            return $this->get_database($database_id);
        } catch (Exception $e) {
            Notion_To_WordPress_Helper::debug_log(
                '数据库信息获取失败: ' . $e->getMessage(),
                'Database Info'
            );
            return [];
        }
    }
}