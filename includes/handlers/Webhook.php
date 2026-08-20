<?php
declare(strict_types=1);

namespace NTWP\Handlers;

/**
 * Webhook 处理类
 * 
 * 负责注册 REST API 路由以接收来自 Notion 的 Webhook 请求，并根据请求内容触发相应的同步操作。
 * 
 * @since      1.1.0
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

class Webhook {

    /**
     * Notion导入协调器实例
     *
     * @since    1.1.0
     * @access   private
     * @var      \NTWP\Handlers\Import_Coordinator    $notion_pages    Notion导入协调器实例
     */
    private \NTWP\Handlers\Import_Coordinator $notion_pages;

    /**
     * 构造函数
     *
     * @since    1.1.0
     * @param    \NTWP\Handlers\Import_Coordinator $notion_pages    Notion导入协调器实例
     */
    public function __construct(\NTWP\Handlers\Import_Coordinator $notion_pages) {
        $this->notion_pages = $notion_pages;
    }

    /**
     * 注册 REST API 路由
     *
     * @since    1.1.0
     */
    public function register_routes() {
        // 获取选项
        $options = get_option('notion_to_wordpress_options', []);
        $webhook_enabled = $options['webhook_enabled'] ?? 0;
        $webhook_token = $options['webhook_token'] ?? '';

        // 如果未启用 Webhook 或令牌为空，则不注册路由
        if (empty($webhook_enabled) || empty($webhook_token)) {
            return;
        }

        register_rest_route('notion-to-wordpress/v1', '/webhook/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ]
            ]
        ]);
    }

    /**
     * 处理 Webhook 请求
     *
     * @since    1.1.0
     * @param    WP_REST_Request    $request    REST 请求对象
     * @return   WP_REST_Response               REST 响应对象
     */
    public function handle_webhook($request) {
        $token_param = $request['token'] ?? '';
        $options = get_option('notion_to_wordpress_options', []);
        $expected_token = $options['webhook_token'] ?? '';

        if (empty($expected_token) || $token_param !== $expected_token) {
            return new WP_REST_Response(['status' => 'error', 'message' => __('Token mismatch', 'notion-to-wordpress')], 403);
        }

        // 获取请求体
        $body = $request->get_json_params();

        // Webhook 安全加固（Wiki §Webhook 安全）：签名验证 / 时间戳检查 / 重放攻击防护 / IP 白名单。
        // 仅对“非握手、非 challenge”的正式事件请求执行；握手请求（含 verification_token）由下方逻辑处理。
        $options = get_option('notion_to_wordpress_options', []);
        if (!empty($options['webhook_signature_verification'])
            && !isset($body['verification_token'])
            && !isset($body['challenge'])) {
            $sig_result = $this->verify_notion_request($request, $body);
            if (is_wp_error($sig_result)) {
                \NTWP\Core\Logger::warning_log('Webhook 安全校验失败: ' . $sig_result->get_error_message(), 'Notion Webhook');
                return new WP_REST_Response(['status' => 'error', 'message' => $sig_result->get_error_message()], 403);
            }
        }

        // Step 2: Subscription verification —— Notion 仅发送 { "verification_token": "..." }
        // 只要检测到 verification_token 字段，即视为验证请求，直接返回 200 空响应
        if (isset($body['verification_token']) && !isset($body['event'])) {
            $verification_token = $body['verification_token'];

            // 将其存到 options 供后台查看
            update_option('notion_to_wordpress_last_verification_token', $verification_token, false);

            // 同时写入主设置数组，便于后台显示
            $opt = get_option('notion_to_wordpress_options', []);
            $opt['webhook_verify_token'] = $verification_token;
            update_option('notion_to_wordpress_options', $opt, false);

            \NTWP\Core\Logger::info_log('Webhook 接收到 verification_token: ' . $verification_token, 'Notion Webhook');

            // 将 token 回显，方便直接复制
            return new WP_REST_Response(['verification_token' => $verification_token], 200);
        }

        // 兼容旧版 challenge 验证
        if (isset($body['challenge'])) {
            return new WP_REST_Response([ 'challenge' => $body['challenge'] ], 200);
        }
        
        // 记录请求
        \NTWP\Core\Logger::debug_log('收到 Webhook 请求: ' . json_encode($body), 'Notion Webhook');

        // 验证请求体
        if (empty($body)) {
            return new WP_REST_Response(['status' => 'error', 'message' => __('无效的请求体', 'notion-to-wordpress')], 400);
        }

        // 新版 Notion Webhook 事件位于 event.type
        $event_type = $body['type'] ?? ($body['event']['type'] ?? '');
        if (empty($event_type)) {
            return new WP_REST_Response(['status' => 'error', 'message' => __('缺少事件类型', 'notion-to-wordpress')], 400);
        }

        // 处理不同类型的事件
        if (preg_match('/^(page|block|database)\./', $event_type)) {
            // 记录触发的事件类型
            \NTWP\Core\Logger::info_log('触发同步，事件类型: ' . $event_type, 'Notion Webhook');

            try {
                // 先快速响应webhook，避免超时
                $response = new WP_REST_Response(['status' => 'success', 'message' => __('Webhook已接收，正在处理', 'notion-to-wordpress')], 200);

                // 立即发送响应
                if (!headers_sent()) {
                    status_header(200);
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'success', 'message' => __('Webhook已接收，正在处理', 'notion-to-wordpress')]);

                    // 刷新输出缓冲区，确保响应立即发送
                    if (ob_get_level()) {
                        ob_end_flush();
                    }
                    flush();
                }

                // 在后台处理同步
                $result = $this->handle_specific_event($event_type, $body);
                \NTWP\Core\Logger::info_log('Webhook处理完成: ' . $result, 'Notion Webhook');

                return $response;
            } catch (Exception $e) {
                \NTWP\Core\Logger::error_log('Webhook 同步失败: ' . $e->getMessage());
                return new WP_REST_Response(['status' => 'error', 'message' => __('同步过程中出错: ', 'notion-to-wordpress') . $e->getMessage()], 500);
            }
        }

        // 其他事件暂时忽略
        \NTWP\Core\Logger::info_log('忽略事件类型: ' . $event_type, 'Notion Webhook');
        return new WP_REST_Response(['status' => 'ignored', 'event_type' => $event_type], 200);
    }

    /**
     * 从 Webhook 请求中提取页面 ID
     *
     * @since    1.1.0
     * @param    array     $body    Webhook 请求体
     * @return   string             页面 ID 或空字符串
     */
    private function extract_page_id(array $body): string {
        // 当前Notion webhook结构: entity.id
        if (isset($body['entity']['id']) && isset($body['entity']['type']) && $body['entity']['type'] === 'page') {
            return $body['entity']['id'];
        }

        // 新版结构: event.data 或 event.entity
        if (isset($body['event']['data']['id'])) {
            return $body['event']['data']['id'];
        }
        if (isset($body['event']['entity']['id'])) {
            return $body['event']['entity']['id'];
        }

        // 兼容旧结构
        if (!empty($body['page']['id'])) {
            return $body['page']['id'];
        }
        if (!empty($body['block']['id'])) {
            return $body['block']['id'];
        }

        return '';
    }

    /**
     * 判断 Webhook 事件是否为重复投递（幂等性去重）。
     *
     * Notion 在 Webhook 网络抖动时会重试同一事件。若不做去重，page.created /
     * page.content_updated 等事件会被重复处理，导致 WordPress 中重复创建文章或
     * 重复触发同步。本方法通过 Transient 缓存事件指纹来去重：
     *
     * 1. 优先使用事件自身 ID（$body['id']，Notion 新版结构为每个事件分配唯一 ID）；
     * 2. 退回使用「事件类型 + 对象 ID + last_edited_time」组合指纹（兼容无事件 ID 的旧结构）。
     *
     * 去重窗口默认 10 分钟，与 Notion 的重试窗口（通常数分钟内）匹配。
     *
     * @since    2.0.0
     * @param    string    $event_type    事件类型（如 page.updated）
     * @param    array     $body          Webhook 请求体
     * @return   bool                     是否为重复事件（true = 应跳过处理）
     */
    private function is_duplicate_event(string $event_type, array $body): bool {
        // 提取事件唯一 ID（优先）
        $event_id = $body['id'] ?? ($body['event']['id'] ?? '');
        if (!empty($event_id)) {
            $key = 'ntwp_webhook_event_' . hash('sha256', (string) $event_id);
            if (get_transient($key) !== false) {
                return true;
            }
            set_transient($key, time(), 10 * MINUTE_IN_SECONDS);
            return false;
        }

        // 退回：无事件 ID 时，用「事件类型 + 对象 ID + 最后编辑时间」构造指纹。
        $object_id = $body['data']['id']
            ?? ($body['event']['data']['id']
            ?? ($body['entity']['id']
            ?? ($body['page']['id']
            ?? ($body['block']['id']
            ?? ''))));
        $last_edited = $body['data']['last_edited_time']
            ?? ($body['event']['data']['last_edited_time']
            ?? ($body['last_edited_time']
            ?? ''));

        // 若对象 ID 与最后编辑时间都缺失，无法可靠去重，放行处理（宁可不拦截也不误拦）。
        if (empty($object_id) && empty($last_edited)) {
            return false;
        }

        $fingerprint = $event_type . '|' . (string) $object_id . '|' . (string) $last_edited;
        $key = 'ntwp_webhook_event_' . hash('sha256', $fingerprint);
        if (get_transient($key) !== false) {
            return true;
        }
        set_transient($key, time(), 10 * MINUTE_IN_SECONDS);
        return false;
    }

    /**
     * 处理特定的webhook事件
     *
     * @since    1.1.0
     * @param    string    $event_type    事件类型
     * @param    array     $body          webhook请求体
     * @return   string                   处理结果消息
     */
    private function handle_specific_event(string $event_type, array $body): string {
        // 幂等去重：Notion 在网络抖动时会重试同一事件，若不加以去重会导致重复创建/更新文章。
        // 采用「事件 ID 优先，退回 object_id + last_edited_time 指纹」策略，Transient 缓存 10 分钟。
        if ($this->is_duplicate_event($event_type, $body)) {
            \NTWP\Core\Logger::info_log('检测到重复 Webhook 事件，已跳过: ' . $event_type, 'Notion Webhook');
            return __('重复事件，已跳过处理', 'notion-to-wordpress');
        }

        $page_id = $this->extract_page_id($body);

        // P1-07 API 缓存清除：Webhook 事件触发同步时，清除 Notion API 响应缓存，
        // 确保本次同步获取最新数据（而非过期的缓存）。
        if (method_exists($this->notion_pages, 'notion_api') || property_exists($this->notion_pages, 'notion_api')) {
            // Import_Coordinator 有 notion_api 属性
        }
        // 通过选项中的 database_id 清除缓存（API::clear_api_cache 接受空参数时自动读取选项）
        $options = get_option('notion_to_wordpress_options', []);
        $db_id = $options['notion_database_id'] ?? '';
        if (!empty($db_id)) {
            $cache_key = 'ntwp_api_dbpages_' . md5($db_id);
            delete_transient($cache_key);
        }

        // 添加调试日志
        \NTWP\Core\Logger::info_log('处理事件: ' . $event_type . ', 页面ID: ' . $page_id, 'Notion Webhook');

        switch ($event_type) {
            case 'page.deleted':
                return $this->handle_page_deleted($page_id);

            case 'page.created':
            case 'page.property_updated':
            case 'page.restored':
            case 'page.undeleted':
                return $this->handle_page_updated($page_id);

            case 'page.content_updated':
                return $this->handle_page_content_updated($page_id);

            case 'page.locked':
            case 'page.unlocked':
                return $this->handle_page_status_changed($page_id);

            case 'database.updated':
            case 'database.schema_updated':
                return $this->handle_database_updated();

            default:
                // 对于其他事件，根据配置执行同步（不检查删除，避免误删）
                $options = get_option('notion_to_wordpress_options', []);
                $webhook_incremental = $options['webhook_incremental_sync'] ?? 1;

                $this->notion_pages->import_pages(false, (bool)$webhook_incremental);
                $sync_type = $webhook_incremental ? '增量同步' : '全量同步';
                return sprintf(__('已触发%s', 'notion-to-wordpress'), $sync_type);
        }
    }

    /**
     * 处理页面删除事件
     *
     * @since    1.1.0
     * @param    string    $page_id    页面ID
     * @return   string                处理结果消息
     */
    private function handle_page_deleted(string $page_id): string {
        \NTWP\Core\Logger::info_log('开始处理页面删除事件，页面ID: ' . $page_id, 'Notion Webhook');

        if (empty($page_id)) {
            \NTWP\Core\Logger::error_log('页面ID为空，无法处理删除事件');
            return __('页面ID为空，无法处理删除事件', 'notion-to-wordpress');
        }

        // 查找对应的WordPress文章
        $post_id = $this->get_wordpress_post_by_notion_id($page_id);
        \NTWP\Core\Logger::info_log('查找WordPress文章结果，页面ID: ' . $page_id . ', 文章ID: ' . $post_id, 'Notion Webhook');

        if (!$post_id) {
            \NTWP\Core\Logger::info_log('未找到对应的WordPress文章，页面ID: ' . $page_id, 'Notion Webhook');
            return __('未找到对应的WordPress文章', 'notion-to-wordpress');
        }

        // 根据 Wiki「智能删除检测」的可配置删除行为处理：彻底删除 / 移至回收站 / 标记为草稿。
        $options       = get_option('notion_to_wordpress_options', []);
        $delete_action = $options['webhook_delete_action'] ?? 'delete';

        if ($delete_action === 'draft') {
            // 标记为草稿，而不是直接删除（保留内容，便于恢复）。
            $updated = wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
            if ($updated && !is_wp_error($updated)) {
                \NTWP\Core\Logger::info_log('已将WordPress文章标记为草稿，文章ID: ' . $post_id . '，对应Notion页面ID: ' . $page_id, 'Notion Webhook');
                return sprintf(__('已将对应WordPress文章标记为草稿 (ID: %d)', 'notion-to-wordpress'), $post_id);
            }
            \NTWP\Core\Logger::error_log('将WordPress文章标记为草稿失败，文章ID: ' . $post_id);
            return __('将WordPress文章标记为草稿失败', 'notion-to-wordpress');
        }

        // delete（彻底删除）或 trash（移至回收站）均复用 Integrator::delete_post，保持与插件其它删除逻辑一致。
        $force   = ($delete_action === 'delete');
        $result  = Integrator::delete_post($post_id, $force);
        $label   = $force ? __('彻底删除', 'notion-to-wordpress') : __('移至回收站', 'notion-to-wordpress');

        if ($result) {
            \NTWP\Core\Logger::info_log('已' . $label . 'WordPress文章，文章ID: ' . $post_id . '，对应Notion页面ID: ' . $page_id, 'Notion Webhook');
            return sprintf(__('已%s对应的WordPress文章 (ID: %d)', 'notion-to-wordpress'), $label, $post_id);
        } else {
            \NTWP\Core\Logger::error_log('删除WordPress文章失败，文章ID: ' . $post_id);
            return __('删除WordPress文章失败', 'notion-to-wordpress');
        }
    }

    /**
     * 处理页面更新事件
     *
     * @since    1.1.0
     * @param    string    $page_id    页面ID
     * @return   string                处理结果消息
     */
    private function handle_page_updated(string $page_id): string {
        // 获取webhook配置
        $options = get_option('notion_to_wordpress_options', []);
        $webhook_incremental = $options['webhook_incremental_sync'] ?? 1;

        if (empty($page_id)) {
            // 如果没有具体页面ID，根据配置执行同步（不检查删除）
            $this->notion_pages->import_pages(false, (bool)$webhook_incremental);
            $sync_type = $webhook_incremental ? '增量同步' : '全量同步';
            return sprintf(__('已触发%s', 'notion-to-wordpress'), $sync_type);
        }

        try {
            // 获取页面数据并导入（强制同步，不进行增量检测）
            $page = $this->notion_pages->get_page_data($page_id);

            // 记录强制同步日志
            \NTWP\Core\Logger::info_log('Webhook强制同步页面: ' . $page_id, 'Notion Webhook');

            $result = $this->notion_pages->import_notion_page($page);

            if ($result === true) {
                return sprintf(__('已同步页面: %s', 'notion-to-wordpress'), $page_id);
            } elseif ($result === 'skipped') {
                return sprintf(__('页面无需同步（增量检测跳过）: %s', 'notion-to-wordpress'), $page_id);
            } else {
                return __('页面同步失败', 'notion-to-wordpress');
            }
        } catch (Exception $e) {
            \NTWP\Core\Logger::error_log('单页面同步失败: ' . $e->getMessage());
            // 回退到配置的同步方式（不检查删除）
            $this->notion_pages->import_pages(false, (bool)$webhook_incremental);
            $sync_type = $webhook_incremental ? '增量同步' : '全量同步';
            return sprintf(__('单页面同步失败，已执行%s', 'notion-to-wordpress'), $sync_type);
        }
    }

    /**
     * 处理页面状态变化事件
     *
     * @since    1.1.0
     * @param    string    $page_id    页面ID
     * @return   string                处理结果消息
     */
    private function handle_page_status_changed(string $page_id): string {
        // 页面锁定/解锁状态变化，重新同步该页面
        return $this->handle_page_updated($page_id);
    }

    /**
     * 处理页面内容更新事件（强制同步，不依赖时间戳）
     *
     * @since    1.1.0
     * @param    string    $page_id    页面ID
     * @return   string                处理结果消息
     */
    private function handle_page_content_updated(string $page_id): string {
        \NTWP\Core\Logger::info_log('处理页面内容更新事件（强制同步）: ' . $page_id, 'Notion Webhook');

        if (empty($page_id)) {
            // 如果没有具体页面ID，执行增量同步（不检查删除）
            $options = get_option('notion_to_wordpress_options', []);
            $webhook_incremental = $options['webhook_incremental_sync'] ?? 1;

            $this->notion_pages->import_pages(false, (bool)$webhook_incremental);
            $sync_type = $webhook_incremental ? '增量同步' : '全量同步';
            return sprintf(__('已触发%s', 'notion-to-wordpress'), $sync_type);
        }

        try {
            // 强制同步页面内容，不进行增量检测
            \NTWP\Core\Logger::info_log('开始获取页面数据: ' . $page_id, 'Notion Webhook');

            // 检查导入协调器对象是否存在
            if (!$this->notion_pages) {
                \NTWP\Core\Logger::error_log('Notion导入协调器对象不存在');
                return __('Notion导入协调器对象不存在', 'notion-to-wordpress');
            }

            \NTWP\Core\Logger::info_log('调用get_page_data方法: ' . $page_id, 'Notion Webhook');
            $page = $this->notion_pages->get_page_data($page_id);
            \NTWP\Core\Logger::info_log('get_page_data方法调用完成', 'Notion Webhook');

            if (empty($page)) {
                \NTWP\Core\Logger::error_log('获取页面数据失败，页面为空: ' . $page_id);
                return __('获取页面数据失败', 'notion-to-wordpress');
            }

            \NTWP\Core\Logger::info_log('强制同步页面内容: ' . $page_id, 'Notion Webhook');

            $result = $this->notion_pages->import_notion_page($page);

            \NTWP\Core\Logger::info_log('页面内容同步结果: ' . ($result === true ? 'success' : ($result === 'skipped' ? 'skipped' : 'failed')), 'Notion Webhook');

            if ($result === true) {
                return sprintf(__('已强制同步页面内容: %s', 'notion-to-wordpress'), $page_id);
            } elseif ($result === 'skipped') {
                return sprintf(__('页面无需同步（增量检测跳过）: %s', 'notion-to-wordpress'), $page_id);
            } else {
                \NTWP\Core\Logger::error_log('页面内容同步失败，import_notion_page返回false');
                return __('页面内容同步失败', 'notion-to-wordpress');
            }
        } catch (Exception $e) {
            \NTWP\Core\Logger::error_log('页面内容同步异常: ' . $e->getMessage());
            \NTWP\Core\Logger::error_log('异常堆栈: ' . $e->getTraceAsString());

            // 回退到增量同步（不检查删除）
            $options = get_option('notion_to_wordpress_options', []);
            $webhook_incremental = $options['webhook_incremental_sync'] ?? 1;

            $this->notion_pages->import_pages(false, (bool)$webhook_incremental);
            $sync_type = $webhook_incremental ? '增量同步' : '全量同步';
            return sprintf(__('页面内容同步失败，已执行%s', 'notion-to-wordpress'), $sync_type);
        } catch (Error $e) {
            \NTWP\Core\Logger::error_log('页面内容同步致命错误: ' . $e->getMessage());
            \NTWP\Core\Logger::error_log('错误堆栈: ' . $e->getTraceAsString());
            return __('页面内容同步发生致命错误', 'notion-to-wordpress');
        }
    }

    /**
     * 处理数据库更新事件
     *
     * @since    1.1.0
     * @return   string    处理结果消息
     */
    private function handle_database_updated(): string {
        // 获取webhook配置
        $options = get_option('notion_to_wordpress_options', []);
        $webhook_incremental = $options['webhook_incremental_sync'] ?? 1;
        $webhook_check_deletions = $options['webhook_check_deletions'] ?? 1;

        // 数据库结构或内容更新，根据配置执行同步
        $this->notion_pages->import_pages((bool)$webhook_check_deletions, (bool)$webhook_incremental);

        $sync_type = $webhook_incremental ? '增量同步' : '全量同步';
        $deletion_check = $webhook_check_deletions ? '（含删除检测）' : '';

        return sprintf(__('数据库已更新，已触发%s%s', 'notion-to-wordpress'), $sync_type, $deletion_check);
    }

    /**
     * 根据Notion页面ID获取WordPress文章ID
     *
     * @since    1.1.0
     * @param    string    $notion_id    Notion页面ID
     * @return   int                     WordPress文章ID，未找到返回0
     */
    private function get_wordpress_post_by_notion_id(string $notion_id): int {
        $args = array(
            'post_type'      => 'any',
            'post_status'    => array('publish', 'private', 'draft', 'pending', 'future'), // 排除回收站
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'     => '_notion_page_id',
                    'value'   => $notion_id,
                    'compare' => '='
                )
            ),
            'fields' => 'ids'
        );

        $posts = get_posts($args);

        return !empty($posts) ? $posts[0] : 0;
    }

    /**
     * 校验 Notion Webhook 请求的签名、时间戳、重放攻击与来源 IP（Wiki §Webhook 安全）。
     *
     * 采用 Notion 官方方案：以订阅握手时获取的 verification_token 为密钥，
     * 对「原始请求体」计算 HMAC-SHA256，并与 X-Notion-Signature 头（格式 sha256=...）比对。
     *
     * @since    2.0.0
     * @param    WP_REST_Request $request REST 请求对象
     * @param    array           $body    已解析的请求体
     * @return   true|WP_Error            校验通过返回 true，失败返回 WP_Error
     */
    private function verify_notion_request($request, array $body) {
        $options      = get_option('notion_to_wordpress_options', []);
        $verify_token = $options['webhook_verify_token'] ?? '';
        $signature    = $request->get_header('x-notion-signature');

        // 尚未完成 Notion 订阅握手（无 verification_token）时不强制校验，避免误伤正常请求。
        if (empty($verify_token)) {
            \NTWP\Core\Logger::warning_log('Webhook 签名验证已启用，但未找到 verification_token，跳过签名校验', 'Notion Webhook');
            return true;
        }

        if (empty($signature)) {
            return new WP_Error('missing_signature', __('缺少 X-Notion-Signature 请求头', 'notion-to-wordpress'));
        }

        // 重放攻击防护：拒绝短时间内重复出现的相同签名。
        $sig_key = 'ntwp_webhook_replay_' . hash('sha256', $signature);
        if (get_transient($sig_key) !== false) {
            return new WP_Error('replay_detected', __('检测到重放攻击：签名已被使用过', 'notion-to-wordpress'));
        }

        // 签名验证：HMAC-SHA256(原始请求体, verification_token) 与 X-Notion-Signature 比对（恒定时间比较）。
        $raw_body = (string) $request->get_body();
        $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $verify_token);
        if (!hash_equals($expected, (string) $signature)) {
            return new WP_Error('invalid_signature', __('Webhook 签名校验失败', 'notion-to-wordpress'));
        }

        // 时间戳检查：若负载包含 timestamp（Unix 秒），拒绝过期（>15 分钟）或异常超前（>5 分钟）的事件。
        $event_ts = $body['timestamp'] ?? ($body['event']['timestamp'] ?? 0);
        if (!empty($event_ts) && is_numeric($event_ts)) {
            $age = time() - (int) $event_ts;
            if ($age > 900 || $age < -300) {
                return new WP_Error('stale_event', __('Webhook 事件时间戳超出允许范围', 'notion-to-wordpress'));
            }
        }

        // IP 白名单（可选）。
        $ip_whitelist = trim($options['webhook_ip_whitelist'] ?? '');
        if ($ip_whitelist !== '') {
            $client_ip = $this->get_client_ip();
            $allowed   = false;
            foreach (array_map('trim', explode(',', $ip_whitelist)) as $range) {
                if ($range === '' || $this->ip_in_range($client_ip, $range)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return new WP_Error('ip_not_allowed', __('请求来源 IP 不在白名单内', 'notion-to-wordpress'));
            }
        }

        // 校验通过：记录签名以防止重放（10 分钟 TTL）。
        set_transient($sig_key, time(), 10 * MINUTE_IN_SECONDS);

        return true;
    }

    /**
     * 获取客户端真实 IP（考虑反向代理场景）。
     *
     * @since  2.0.0
     * @return string
     */
    private function get_client_ip(): string {
        $ip = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = (string) $_SERVER['REMOTE_ADDR'];
        }
        return (string) filter_var($ip, FILTER_VALIDATE_IP) ?: '';
    }

    /**
     * 判断 IP 是否落在给定范围（支持精确匹配与 IPv4 CIDR）。
     *
     * @since  2.0.0
     * @param  string $ip    客户端 IP
     * @param  string $range 精确 IP 或 CIDR（如 198.51.100.0/24）
     * @return bool
     */
    private function ip_in_range(string $ip, string $range): bool {
        if ($ip === '') {
            return false;
        }
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        list($subnet, $bits) = array_pad(explode('/', $range, 2), 2, '0');
        $bits = (int) $bits;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip_long     = ip2long($ip);
            $subnet_long = ip2long($subnet);
            if ($ip_long === false || $subnet_long === false || $bits < 0 || $bits > 32) {
                return false;
            }
            $mask = (~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF;
            return ($ip_long & $mask) === ($subnet_long & $mask);
        }
        return $ip === $range;
    }
}