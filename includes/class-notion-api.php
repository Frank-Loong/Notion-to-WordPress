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
            throw new Exception('API请求失败: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            $error_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $error_body['message'] ?? wp_remote_retrieve_body($response);
            throw new Exception('API错误 (' . $response_code . '): ' . $error_message);
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
        // 使用 transient 缓存结果，减少短时间内的重复 API 调用
        $cache_key = 'ntw_db_pages_' . md5($database_id . wp_json_encode($filter));
        $cached     = get_transient($cache_key);

        if ( is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
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
            }

            $has_more = $response['has_more'] ?? false;
            $start_cursor = $response['next_cursor'] ?? null;
        }

        // 将结果缓存 2 分钟，避免频繁请求（可在设置中调整）
        set_transient( $cache_key, $all_results, 120 );

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
        $cache_key = 'ntw_pg_content_' . md5($block_id);
        $cached    = get_transient( $cache_key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $blocks = $this->get_block_children($block_id);

        // 收集所有需要加载子块的块ID
        $child_ids = [];
        foreach ( $blocks as $blk ) {
            if ( ( $blk['has_children'] ?? false ) ) {
                $child_ids[] = $blk['id'];
            }
        }

        // 并发获取子块
        $children_map = [];
        if ( ! empty( $child_ids ) ) {
            $children_map = $this->get_blocks_children_batch( $child_ids );
        }

        // 递归构建完整结构
        foreach ( $blocks as $i => $blk ) {
            if ( ( $blk['has_children'] ?? false ) ) {
                $first_level_children = $children_map[ $blk['id'] ] ?? $this->get_block_children( $blk['id'] );

                // 深度处理其子级（递归，内部仍将尝试并发）
                foreach ( $first_level_children as $j => $child_block ) {
                    if ( ( $child_block['has_children'] ?? false ) ) {
                        $first_level_children[ $j ]['children'] = $this->get_page_content( $child_block['id'] );
                    }
                }

                $blocks[ $i ]['children'] = $first_level_children;
            }
        }

        // 缓存
        set_transient( $cache_key, $blocks, 300 );

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
        $cache_key = 'ntw_blk_children_' . md5($block_id);
        $cached    = get_transient( $cache_key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $all_results = [];
        $has_more = true;
        $start_cursor = null;

        while ($has_more) {
            $endpoint = 'blocks/' . $block_id . '/children?page_size=100';
            if ($start_cursor) {
                $endpoint .= '&start_cursor=' . $start_cursor;
            }

            $response = $this->send_request($endpoint, 'GET');

            if (isset($response['results'])) {
                $all_results = array_merge($all_results, $response['results']);
            }

            $has_more = $response['has_more'] ?? false;
            $start_cursor = $response['next_cursor'] ?? null;
        }

        set_transient( $cache_key, $all_results, 300 );

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
            return new WP_Error('connection_failed', '连接测试失败: ' . $e->getMessage());
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

    private function get_blocks_children_batch( array $block_ids ): array {
        // 批量并发获取多个块的直接子块，用于加速页面内容递归处理
        $results  = [];
        $uncached = [];

        // 先检查缓存，避免重复请求
        foreach ( $block_ids as $bid ) {
            $cache_key = 'ntw_blk_children_' . md5( $bid );
            $cached    = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                $results[ $bid ] = $cached;
            } else {
                $uncached[] = $bid;
            }
        }

        if ( empty( $uncached ) ) {
            return $results; // 全部命中缓存
        }

        // 构建 Requests::request_multiple 需要的请求数组
        $reqs = [];
        foreach ( $uncached as $bid ) {
            $endpoint         = 'blocks/' . $bid . '/children?page_size=100';
            $reqs[ $bid ] = [
                'url'     => $this->api_base . $endpoint,
                'type'    => 'GET',
                'headers' => [
                    'Authorization'  => 'Bearer ' . $this->api_key,
                    'Notion-Version' => '2022-06-28',
                ],
                'cookies' => [],
                'data'    => []
            ];
        }

        // WordPress 捆绑的 Requests 库 6.2+ 使用命名空间。
        $requests_class = null;
        if ( class_exists( '\\WpOrg\\Requests\\Requests' ) ) {
            $requests_class = '\\WpOrg\\Requests\\Requests';
        } elseif ( class_exists( '\\Requests' ) ) {
            $requests_class = '\\Requests';
        }

        if ( $requests_class ) {
            // 按照 Notion API 速率限制（每秒最多 3 次）对请求进行分组
            $chunks = array_chunk( $reqs, 3, true );
            foreach ( $chunks as $chunk ) {
                try {
                    $responses = $requests_class::request_multiple( $chunk, [ 'timeout' => 30 ] );
                } catch ( Exception $e ) {
                    // 若批量请求失败则回退为单请求
                    $responses = [];
                    foreach ( $chunk as $id => $r ) {
                        try {
                            $responses[ $id ] = $requests_class::request( $r['url'], $r['headers'], [], $r['type'], [ 'timeout' => 30 ] );
                        } catch ( Exception $e2 ) {
                            // 留空，稍后使用传统方法兜底
                        }
                    }
                }

                foreach ( $chunk as $bid => $_ ) {
                    $res = $responses[ $bid ] ?? null;
                    if ( $res && isset( $res->status_code ) && $res->status_code >= 200 && $res->status_code < 300 ) {
                        $data          = json_decode( $res->body, true );
                        $children      = $data['results'] ?? [];
                        $results[ $bid ] = $children;
                        // 缓存
                        set_transient( 'ntw_blk_children_' . md5( $bid ), $children, 300 );
                    } else {
                        // 回退：使用顺序方式强制拉取，保证完整性
                        $results[ $bid ] = $this->get_block_children( $bid );
                    }
                }

                // 避免超过速率限制，适当暂停
                usleep( 400000 ); // 0.4 秒
            }
        } else {
            // 若环境缺少 Requests 库，则顺序拉取
            foreach ( $uncached as $bid ) {
                $results[ $bid ] = $this->get_block_children( $bid );
            }
        }

        return $results;
    }
} 