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
if (!defined('WPINC')) {
    die;
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
        // 在处理Webhook请求前先修复可能的会话冲突
        $this->fix_session_conflicts();
        
        $token_param = $request['token'] ?? '';
        $options = get_option('notion_to_wordpress_options', []);
        $expected_token = $options['webhook_token'] ?? '';

        if (empty($expected_token) || $token_param !== $expected_token) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Token mismatch'], 403);
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
            return new WP_REST_Response(['status' => 'error', 'message' => '无效的请求体'], 400);
        }

        // 新版 Notion Webhook 事件位于 event.type
        $event_type = $body['type'] ?? ($body['event']['type'] ?? '');
        if (empty($event_type)) {
            return new WP_REST_Response(['status' => 'error', 'message' => '缺少事件类型'], 400);
        }

        // 处理不同类型的事件
        if (preg_match('/^(page|block)\./', $event_type)) {
            // 清理与插件相关的 transient 缓存，避免使用过期的块内容
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ntw_%' OR option_name LIKE '_transient_timeout_ntw_%'" );

            try {
                $this->notion_pages->import_pages();
                return new WP_REST_Response(['status' => 'success', 'message' => '已触发同步'], 200);
            } catch (Exception $e) {
                Notion_To_WordPress_Helper::error_log('Webhook 同步失败: ' . $e->getMessage());
                return new WP_REST_Response(['status' => 'error', 'message' => '同步过程中出错: ' . $e->getMessage()], 500);
            }
        }

        // 其他事件暂时忽略
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
     * 修复在Webhook处理期间可能发生的会话冲突
     * 
     * @since 1.1.0
     */
    private function fix_session_conflicts() {
        // 抑制session_start警告
        $current_error_level = error_reporting();
        error_reporting($current_error_level & ~E_WARNING);
        
        // 如果会话未开始且没有发送头部，则安全启动会话
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        // 禁用主题的session_start尝试，尤其是ZIB主题
        add_filter('zib_session_start', '__return_false');
        
        // 恢复正常错误报告级别
        error_reporting($current_error_level);
    }
} 