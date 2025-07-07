<?php
declare(strict_types=1);

/**
 * Webhook 处理类
 *
 * 处理来自 Notion 的 Webhook 请求，并触发相应的同步操作。
 *
 * @since      1.0.10
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */
// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

class Notion_To_WordPress_Webhook {

    /**
     * Notion Pages 实例
     *
     * @since    1.0.10
     * @access   private
     * @var      Notion_Pages    $notion_pages    Notion Pages 实例
     */
    private Notion_Pages $notion_pages;

    /**
     * 构造函数
     *
     * @since    1.0.10
     * @param    Notion_Pages    $notion_pages    Notion Pages 实例
     */
    public function __construct(Notion_Pages $notion_pages) {
        $this->notion_pages = $notion_pages;
    }

    /**
     * 注册 REST API 路由
     *
     * @since    1.0.10
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
     * @since    1.0.10
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

            Notion_To_WordPress_Helper::info_log('Webhook 接收到 verification_token: ' . $verification_token, 'Notion Webhook');

            // 将 token 回显，方便直接复制
            return new WP_REST_Response(['verification_token' => $verification_token], 200);
        }

        // 兼容旧版 challenge 验证
        if (isset($body['challenge'])) {
            return new WP_REST_Response([ 'challenge' => $body['challenge'] ], 200);
        }
        
        // 记录请求
        Notion_To_WordPress_Helper::debug_log('收到 Webhook 请求: ' . json_encode($body), 'Notion Webhook', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO);

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
            Notion_To_WordPress_Helper::info_log('触发同步，事件类型: ' . $event_type, 'Notion Webhook');

            try {
                // 根据事件类型进行不同的处理
                $result = $this->handle_specific_event($event_type, $body);
                return new WP_REST_Response(['status' => 'success', 'message' => $result], 200);
            } catch (Exception $e) {
                Notion_To_WordPress_Helper::error_log('Webhook 同步失败: ' . $e->getMessage());
                return new WP_REST_Response(['status' => 'error', 'message' => __('同步过程中出错: ', 'notion-to-wordpress') . $e->getMessage()], 500);
            }
        }

        // 其他事件暂时忽略
        Notion_To_WordPress_Helper::info_log('忽略事件类型: ' . $event_type, 'Notion Webhook');
        return new WP_REST_Response(['status' => 'ignored', 'event_type' => $event_type], 200);
    }

    /**
     * 从 Webhook 请求中提取页面 ID
     *
     * @since    1.0.10
     * @param    array     $body    Webhook 请求体
     * @return   string             页面 ID 或空字符串
     */
    private function extract_page_id(array $body): string {
        // 新版结构: event.data 或 entity
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
     * 处理特定的webhook事件
     *
     * @since    1.0.10
     * @param    string    $event_type    事件类型
     * @param    array     $body          webhook请求体
     * @return   string                   处理结果消息
     */
    private function handle_specific_event(string $event_type, array $body): string {
        $page_id = $this->extract_page_id($body);

        switch ($event_type) {
            case 'page.deleted':
                return $this->handle_page_deleted($page_id);

            case 'page.created':
            case 'page.content_updated':
            case 'page.property_updated':
            case 'page.restored':
                return $this->handle_page_updated($page_id);

            case 'page.locked':
            case 'page.unlocked':
                return $this->handle_page_status_changed($page_id);

            case 'database.updated':
            case 'database.schema_updated':
                return $this->handle_database_updated();

            default:
                // 对于其他事件，执行全量同步
                $this->notion_pages->import_pages();
                return __('已触发全量同步', 'notion-to-wordpress');
        }
    }

    /**
     * 处理页面删除事件
     *
     * @since    1.0.10
     * @param    string    $page_id    页面ID
     * @return   string                处理结果消息
     */
    private function handle_page_deleted(string $page_id): string {
        if (empty($page_id)) {
            return __('页面ID为空，无法处理删除事件', 'notion-to-wordpress');
        }

        // 查找对应的WordPress文章
        $post_id = $this->get_wordpress_post_by_notion_id($page_id);

        if (!$post_id) {
            Notion_To_WordPress_Helper::info_log('未找到对应的WordPress文章，页面ID: ' . $page_id, 'Notion Webhook');
            return __('未找到对应的WordPress文章', 'notion-to-wordpress');
        }

        // 删除WordPress文章
        $result = wp_delete_post($post_id, true); // true表示彻底删除，不放入回收站

        if ($result) {
            Notion_To_WordPress_Helper::info_log('已删除WordPress文章，文章ID: ' . $post_id . '，对应Notion页面ID: ' . $page_id, 'Notion Webhook');
            return sprintf(__('已删除对应的WordPress文章 (ID: %d)', 'notion-to-wordpress'), $post_id);
        } else {
            Notion_To_WordPress_Helper::error_log('删除WordPress文章失败，文章ID: ' . $post_id);
            return __('删除WordPress文章失败', 'notion-to-wordpress');
        }
    }

    /**
     * 处理页面更新事件
     *
     * @since    1.0.10
     * @param    string    $page_id    页面ID
     * @return   string                处理结果消息
     */
    private function handle_page_updated(string $page_id): string {
        if (empty($page_id)) {
            // 如果没有具体页面ID，执行全量同步
            $this->notion_pages->import_pages();
            return __('已触发全量同步', 'notion-to-wordpress');
        }

        try {
            // 获取页面数据并导入
            $page = $this->notion_pages->notion_api->get_page($page_id);
            $result = $this->notion_pages->import_notion_page($page);

            if ($result) {
                return sprintf(__('已同步页面: %s', 'notion-to-wordpress'), $page_id);
            } else {
                return __('页面同步失败', 'notion-to-wordpress');
            }
        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log('单页面同步失败: ' . $e->getMessage());
            // 回退到全量同步
            $this->notion_pages->import_pages();
            return __('单页面同步失败，已执行全量同步', 'notion-to-wordpress');
        }
    }

    /**
     * 处理页面状态变化事件
     *
     * @since    1.0.10
     * @param    string    $page_id    页面ID
     * @return   string                处理结果消息
     */
    private function handle_page_status_changed(string $page_id): string {
        // 页面锁定/解锁状态变化，重新同步该页面
        return $this->handle_page_updated($page_id);
    }

    /**
     * 处理数据库更新事件
     *
     * @since    1.0.10
     * @return   string    处理结果消息
     */
    private function handle_database_updated(): string {
        // 数据库结构或内容更新，执行全量同步
        $this->notion_pages->import_pages();
        return __('数据库已更新，已触发全量同步', 'notion-to-wordpress');
    }

    /**
     * 根据Notion页面ID获取WordPress文章ID
     *
     * @since    1.0.10
     * @param    string    $notion_id    Notion页面ID
     * @return   int                     WordPress文章ID，未找到返回0
     */
    private function get_wordpress_post_by_notion_id(string $notion_id): int {
        $args = array(
            'post_type'      => 'any',
            'post_status'    => 'any',
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
}