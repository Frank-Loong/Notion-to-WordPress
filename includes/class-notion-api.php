<?php
declare(strict_types=1);

/**
 * Notion API 交互类
 *
 * 封装了与 Notion API 通信的所有方法，包括获取数据库、页面和块等。
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */

class Notion_API {

    /**
     * 全局单例实例
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Requests Session，用于复用连接
     *
     * @var \WpOrg\Requests\Session|null
     */
    private static $requests_session = null;

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
     * 进程内块缓存（仅当前请求）
     *
     * @var array<string,array>
     */
    private static array $local_block_cache = [];

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
        $start_time = microtime( true );
        Notion_To_WordPress_Helper::debug_log( '调用 Notion API: ' . $endpoint, 'Notion API', Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG );

        $url = $this->api_base . $endpoint;
        
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization'  => 'Bearer ' . $this->api_key,
                'Content-Type'   => 'application/json',
                'Notion-Version' => '2022-06-28'
            ],
            'timeout' => 60, // 增加超时时间
            'httpversion' => '1.1',
            'sslverify' => true,
        ];
        
        if (!empty($data) && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }
        
        // 记录请求详情（仅调试模式）
        if (Notion_To_WordPress_Helper::$debug_level >= Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG) {
            Notion_To_WordPress_Helper::debug_log(
                '请求详情: ' . wp_json_encode([
                    'url' => $url,
                    'method' => $method,
                    'timeout' => $args['timeout'],
                    'data_size' => !empty($data) ? strlen($args['body']) : 0,
                ]),
                'Notion API Request',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG
            );
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $msg = 'API请求失败: ' . $response->get_error_message();
            Notion_To_WordPress_Helper::error_log( $msg, 'Notion API' );
            
            // 记录详细错误信息
            $error_data = $response->get_error_data();
            if (!empty($error_data)) {
                Notion_To_WordPress_Helper::error_log(
                    '错误详情: ' . wp_json_encode($error_data),
                    'Notion API'
                );
            }
            
            throw new Exception( $msg );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            $error_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $error_body['message'] ?? wp_remote_retrieve_body($response);
            
            // 记录详细错误响应
            Notion_To_WordPress_Helper::error_log(
                '错误响应: ' . wp_remote_retrieve_body($response),
                'Notion API'
            );
            
            throw new Exception('API错误 (' . $response_code . '): ' . $error_message);
        }
        
        $duration = round( ( microtime( true ) - $start_time ) * 1000 );
        Notion_To_WordPress_Helper::debug_log( 'Notion API 返回 ' . $response_code . ' | 耗时: ' . $duration . 'ms', 'Notion API', Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG );
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // 检查JSON解码错误
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_msg = 'JSON解码失败: ' . json_last_error_msg();
            if (strlen($body) > 500) {
                $error_msg .= ', 响应内容(前500字符): ' . substr($body, 0, 500);
            } else {
                $error_msg .= ', 响应内容: ' . $body;
            }
            throw new Exception($error_msg);
        }

        return is_array($data) ? $data : [];
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
        // 使用更安全的缓存键生成，避免哈希冲突
        $cache_key = 'ntw_db_pages_' . hash('sha256', $database_id . wp_json_encode($filter));
        $cached     = Notion_To_WordPress_Helper::cache_get( $cache_key );

        if ( is_array( $cached ) && ! empty( $cached ) ) {
            Notion_To_WordPress_Helper::debug_log( '命中数据库页面缓存: ' . $database_id, 'Cache', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
            return $cached;
        }

        $all_results = [];
        $has_more = true;
        $start_cursor = null;
        $page_count = 0;
        $max_pages = 50; // 限制最大页数，防止无限循环

        while ($has_more && $page_count < $max_pages) {
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

            if (isset($response['results']) && is_array($response['results'])) {
                // 使用array_push避免array_merge的内存复制开销
                foreach ($response['results'] as $result) {
                    $all_results[] = $result;
                }

                // 定期检查内存使用
                if ($page_count % 10 === 0) {
                    $memory_usage = memory_get_usage(true) / 1024 / 1024;
                    if ($memory_usage > 128) { // 如果内存使用超过128MB，记录警告
                        Notion_To_WordPress_Helper::debug_log(
                            sprintf('内存使用较高: %.2fMB，已获取%d页数据', $memory_usage, $page_count + 1),
                            'Notion API Memory',
                            Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
                        );
                    }
                }
            }

            $has_more = $response['has_more'] ?? false;
            $start_cursor = $response['next_cursor'] ?? null;
            $page_count++;
        }

        if ($page_count >= $max_pages) {
            Notion_To_WordPress_Helper::debug_log(
                "达到最大页数限制({$max_pages})，可能存在更多数据未获取",
                'Notion API',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
            );
        }

        // 将结果缓存，时间可配置
        $cache_duration = $this->get_cache_duration('database_pages', 300); // 默认5分钟
        Notion_To_WordPress_Helper::cache_set( $cache_key, $all_results, $cache_duration );

        return $all_results;
    }

    /**
     * 递归获取一个块的所有子块内容。
     *
     * @since    1.0.8
     * @param    string    $block_id    块或页面的 ID。
     * @param    int       $depth       当前递归深度，防止无限递归
     * @param    int       $max_depth   最大递归深度
     * @return   array<string, mixed>                 子块对象数组。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_page_content(string $block_id, int $depth = 0, int $max_depth = 10): array {
        // 检查递归深度
        if ($depth >= $max_depth) {
            Notion_To_WordPress_Helper::debug_log(
                "达到最大递归深度({$max_depth})，停止获取子块: {$block_id}",
                'Notion API Recursion',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
            );
            return [];
        }

        $cache_key = 'ntw_pg_content_' . hash('sha256', $block_id . '_d' . $depth);
        $cached    = Notion_To_WordPress_Helper::cache_get( $cache_key );

        if ( is_array( $cached ) ) {
            Notion_To_WordPress_Helper::debug_log( '命中页面内容缓存: ' . $block_id, 'Cache', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
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
                        $first_level_children[ $j ]['children'] = $this->get_page_content( $child_block['id'], $depth + 1, $max_depth );
                    }
                }

                $blocks[ $i ]['children'] = $first_level_children;
            }
        }

        // 缓存
        $cache_duration = $this->get_cache_duration('page_content', 600); // 默认10分钟
        Notion_To_WordPress_Helper::cache_set( $cache_key, $blocks, $cache_duration );

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
        // 先检查请求级缓存
        if ( isset( self::$local_block_cache[ $block_id ] ) ) {
            Notion_To_WordPress_Helper::debug_log( '命中本地块缓存: ' . $block_id, 'Cache', Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG );
            return self::$local_block_cache[ $block_id ];
        }

        $cache_key = 'ntw_blk_children_' . md5($block_id);
        $cached    = Notion_To_WordPress_Helper::cache_get( $cache_key );

        if ( is_array( $cached ) ) {
            // 同时写入本地缓存，后续命中
            self::$local_block_cache[ $block_id ] = $cached;
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

        // 写缓存
        Notion_To_WordPress_Helper::cache_set( $cache_key, $all_results, 300 );
        self::$local_block_cache[ $block_id ] = $all_results;

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
        $cache_key = 'ntw_page_meta_' . md5( $page_id );
        $cached    = Notion_To_WordPress_Helper::cache_get( $cache_key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $endpoint = 'pages/' . $page_id;
        $data     = $this->send_request( $endpoint );

        // 缓存 5 分钟
        Notion_To_WordPress_Helper::cache_set( $cache_key, $data, 300 );

        return $data;
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
        $cache_key = 'ntw_page_obj_' . md5( $page_id );
        $cached    = Notion_To_WordPress_Helper::cache_get( $cache_key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $endpoint = 'pages/' . $page_id;
        $data     = $this->send_request( $endpoint );

        Notion_To_WordPress_Helper::cache_set( $cache_key, $data, 300 );

        return $data;
    }

    private function get_blocks_children_batch( array $block_ids ): array {
        // 批量并发获取多个块的直接子块，用于加速页面内容递归处理
        $results  = [];
        $uncached = [];

        // 先检查缓存，避免重复请求
        foreach ( $block_ids as $bid ) {
            $cache_key = 'ntw_blk_children_' . md5( $bid );
            $cached    = Notion_To_WordPress_Helper::cache_get( $cache_key );
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

        // 优先使用共享 Session（可复用连接池）
        $session = $this->get_requests_session();

        if ( $session ) {
            // 按照 Notion API 速率限制（每秒最多 3 次）对请求进行分组
            $chunks = array_chunk( $reqs, 3, true );
            foreach ( $chunks as $chunk ) {
                try {
                    $responses = $session->request_multiple( $chunk );
                } catch ( Exception $e ) {
                    // 若批量请求失败则回退为逐个会话请求
                    $responses = [];
                    foreach ( $chunk as $id => $r ) {
                        try {
                            $responses[ $id ] = $session->request( $r['url'], $r['headers'] ?? [], $r['data'] ?? [], $r['type'] ?? 'GET' );
                        } catch ( Exception $e2 ) {
                            // 留空，稍后使用顺序函数兜底
                        }
                    }
                }

                foreach ( $chunk as $bid => $_ ) {
                    $res = $responses[ $bid ] ?? null;
                    if ( $res && isset( $res->status_code ) && $res->status_code >= 200 && $res->status_code < 300 ) {
                        $data = json_decode( $res->body, true );

                        // 检查JSON解码错误
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            Notion_To_WordPress_Helper::debug_log(
                                'JSON解码失败: ' . json_last_error_msg(),
                                'Notion API Batch',
                                Notion_To_WordPress_Helper::DEBUG_LEVEL_ERROR
                            );
                            $results[ $bid ] = $this->get_block_children( $bid );
                            continue;
                        }

                        $children = is_array($data) ? ($data['results'] ?? []) : [];
                        $results[ $bid ] = $children;
                        // 缓存
                        Notion_To_WordPress_Helper::cache_set( 'ntw_blk_children_' . md5( $bid ), $children, 300 );
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

    /**
     * 获取（或初始化）共享 Requests Session
     *
     * @return \WpOrg\Requests\Session|null
     */
    private function get_requests_session() {
        if ( null !== self::$requests_session ) {
            return self::$requests_session;
        }

        // 仅在环境已加载 Requests 时启用
        if ( class_exists( '\\WpOrg\\Requests\\Session' ) ) {
            $default_headers = [
                'Authorization'  => 'Bearer ' . $this->api_key,
                'Notion-Version' => '2022-06-28',
            ];
            // 初始化 Session（基址已包含，不必每次拼接）
            self::$requests_session = new \WpOrg\Requests\Session( $this->api_base, $default_headers, [], [ 'timeout' => 30 ] );
        }

        return self::$requests_session;
    }

    /**
     * 获取缓存持续时间
     *
     * @since 1.1.0
     * @param string $cache_type 缓存类型
     * @param int $default_duration 默认持续时间（秒）
     * @return int 缓存持续时间
     */
    private function get_cache_duration(string $cache_type, int $default_duration): int {
        $options = get_option('notion_to_wordpress_options', []);
        $cache_settings = $options['cache_durations'] ?? [];

        return (int) ($cache_settings[$cache_type] ?? $default_duration);
    }

    /**
     * 清理所有Notion相关缓存
     *
     * @since 1.1.0
     */
    public static function clear_all_cache(): void {
        global $wpdb;

        // 清理WordPress transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ntw_%' OR option_name LIKE '_transient_timeout_ntw_%'");

        // 清理Helper类的缓存
        if (method_exists('Notion_To_WordPress_Helper', 'clear_all_cache')) {
            Notion_To_WordPress_Helper::clear_all_cache();
        }

        Notion_To_WordPress_Helper::debug_log('已清理所有Notion缓存', 'Cache', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO);
    }

    public static function instance( ?string $api_key = null ): self {
        // 检查是否需要创建新实例或更新现有实例
        $need_new_instance = false;

        if ( null === self::$instance ) {
            $need_new_instance = true;
        } elseif ( $api_key && $api_key !== self::$instance->api_key ) {
            // 只有在实例存在且API密钥不同时才重新创建
            $need_new_instance = true;
        }

        if ( $need_new_instance ) {
            // 若未显式提供，则从选项读取
            if ( ! $api_key ) {
                $opts    = get_option( 'notion_to_wordpress_options', [] );
                $api_key = $opts['notion_api_key'] ?? '';
            }
            self::$instance = new self( $api_key );
        }

        return self::$instance;
    }
}