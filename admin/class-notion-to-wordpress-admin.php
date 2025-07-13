<?php
// 声明严格类型
declare(strict_types=1);

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 插件的管理区域功能
 *
 * @since      1.0.9
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */

class Notion_To_WordPress_Admin {

    /**
     * 插件名称
     *
     * @since    1.0.5
     * @access   private
     * @var      string    $plugin_name    插件名称
     */
    private string $plugin_name;

    /**
     * 插件版本
     *
     * @since    1.0.5
     * @access   private
     * @var      string    $version    插件版本
     */
    private string $version;

    /**
     * Notion API处理程序实例
     *
     * @since    1.0.5
     * @access   private
     * @var      Notion_API
     */
    private Notion_API $notion_api;

    /**
     * Notion页面处理程序实例
     *
     * @since    1.0.5
     * @access   private
     * @var      Notion_Pages
     */
    private Notion_Pages $notion_pages;

    /**
     * 初始化类并设置其属性
     *
     * @since    1.0.5
     * @param string $plugin_name 插件名称
     * @param string $version 插件版本
     * @param Notion_API $notion_api Notion API实例
     * @param Notion_Pages $notion_pages Notion Pages实例
     */
    public function __construct(string $plugin_name, string $version, Notion_API $notion_api, Notion_Pages $notion_pages) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->notion_api = $notion_api;
        $this->notion_pages = $notion_pages;
    }

    /**
     * 注册管理区域的样式
     *
     * @since    1.0.5
     * @param    string    $hook_suffix    当前管理页面的钩子后缀
     */
    public function enqueue_styles($hook_suffix) {
        // 仅在插件设置页面加载样式
        if ($hook_suffix !== 'toplevel_page_notion-to-wordpress') {
            return;
        }

        // 添加安全头部
        $this->add_security_headers();

        wp_enqueue_style(
            $this->plugin_name . '-admin',
            Notion_To_WordPress_Helper::plugin_url('assets/css/admin-modern.css'),
            array(),
            $this->version,
            'all'
        );

        wp_enqueue_style(
            $this->plugin_name . '-tooltip',
            Notion_To_WordPress_Helper::plugin_url('assets/css/tooltip.css'),
            array(),
            $this->version,
            'all'
        );

        wp_enqueue_style(
            $this->plugin_name . '-custom',
            Notion_To_WordPress_Helper::plugin_url('assets/css/custom-styles.css'),
            array(),
            $this->version,
            'all'
        );
    }

    /**
     * 注册管理区域的脚本
     *
     * @since    1.0.5
     * @param    string    $hook_suffix    当前管理页面的钩子后缀
     */
    public function enqueue_scripts($hook_suffix) {
        // 仅在插件设置页面加载脚本
        if ($hook_suffix !== 'toplevel_page_notion-to-wordpress') {
            return;
        }

        // 创建nonce用于内联脚本安全
        $script_nonce = wp_create_nonce('notion_wp_script_nonce');
        
        // 添加CSP nonce到脚本标签
        wp_enqueue_script(
            $this->plugin_name . '-admin',
            Notion_To_WordPress_Helper::plugin_url('assets/js/admin-interactions.js'),
            array('jquery'),
            $this->version,
            true // 在页脚加载
        );

        // 为JS提供统一的PHP数据对象
        wp_localize_script($this->plugin_name . '-admin', 'notionToWp', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('notion_to_wordpress_nonce'),
            'version'  => $this->version,
            'script_nonce' => $script_nonce, // 添加脚本nonce
            'i18n'     => [ // 国际化字符串
                'importing' => __('导入中...', 'notion-to-wordpress'),
                'import' => __('手动导入', 'notion-to-wordpress'),
                'import_error' => __('导入过程中发生错误', 'notion-to-wordpress'),
                'testing' => __('测试中...', 'notion-to-wordpress'),
                'test_connection' => __('测试连接', 'notion-to-wordpress'),
                'test_error' => __('测试连接时发生错误', 'notion-to-wordpress'),
                'fill_fields' => __('请输入API密钥和数据库ID', 'notion-to-wordpress'),
                'copied' => __('已复制到剪贴板', 'notion-to-wordpress'),
                'refreshing_token' => __('刷新中...', 'notion-to-wordpress'),
                'refresh_token' => __('刷新验证令牌', 'notion-to-wordpress'),
                'stats_error' => __('统计信息错误', 'notion-to-wordpress'),
                'confirm_sync' => __('确定要开始同步Notion内容吗？', 'notion-to-wordpress'),
                'confirm_refresh_all' => __('确定要刷新全部内容吗？这将根据Notion的当前状态重新同步所有页面。', 'notion-to-wordpress'),
                'confirm_clear_logs' => __('确定要清除所有日志文件吗？此操作不可恢复。', 'notion-to-wordpress'),
                'required_fields' => __('请填写所有必填字段', 'notion-to-wordpress'),
                'hide_key' => __('隐藏密钥', 'notion-to-wordpress'),
                'show_key' => __('显示密钥', 'notion-to-wordpress'),
                'never' => __('从未', 'notion-to-wordpress'),
                'not_scheduled' => __('未计划', 'notion-to-wordpress'),
                'unknown_error' => __('未知错误', 'notion-to-wordpress'),
                'invalid_page_id' => __('页面ID无效，无法刷新。', 'notion-to-wordpress'),
                'security_missing' => __('安全验证参数缺失，无法继续操作。请刷新页面后重试。', 'notion-to-wordpress'),
                'page_refreshed' => __('页面已刷新完成！', 'notion-to-wordpress'),
                'refresh_failed' => __('刷新失败: ', 'notion-to-wordpress'),
                'network_error' => __('网络错误，无法刷新页面。', 'notion-to-wordpress'),
                'timeout_error' => __('操作超时，请检查该Notion页面内容是否过大。', 'notion-to-wordpress'),
                'select_log_file' => __('请先选择一个日志文件。', 'notion-to-wordpress'),
                'loading_logs' => __('正在加载日志...', 'notion-to-wordpress'),
                'load_logs_failed' => __('无法加载日志: ', 'notion-to-wordpress'),
                'log_request_error' => __('请求日志时发生错误。', 'notion-to-wordpress'),
                'copy_failed_no_target' => __('复制失败: 未指定目标元素', 'notion-to-wordpress'),
                'copy_failed_not_found' => __('复制失败: 未找到目标元素', 'notion-to-wordpress'),
                'copy_failed' => __('复制失败: ', 'notion-to-wordpress'),
                'copy_to_clipboard' => __('复制到剪贴板', 'notion-to-wordpress'),
                'copy_code' => __('复制代码', 'notion-to-wordpress'),
                'copied_success' => __('已复制!', 'notion-to-wordpress'),
                'copy_manual' => __('复制失败，请手动复制。', 'notion-to-wordpress'),
                'loading' => __('加载中...', 'notion-to-wordpress'),
                'loading_stats' => __('加载统计信息...', 'notion-to-wordpress'),
                'stats_error' => __('无法加载统计信息', 'notion-to-wordpress'),
                'refresh_all' => __('刷新全部内容', 'notion-to-wordpress'),
                'refreshing'  => __('刷新中...', 'notion-to-wordpress'),
                'refresh_error' => __('刷新失败', 'notion-to-wordpress'),
                'clearing' => __('清除中...', 'notion-to-wordpress'),
                'clear_logs' => __('清除所有日志', 'notion-to-wordpress'),
                'settings_saved' => __('设置已保存！', 'notion-to-wordpress'),
                'saving' => __('保存中...', 'notion-to-wordpress'),
                // 同步相关的国际化字符串
                'smart_sync' => __('智能同步', 'notion-to-wordpress'),
                'full_sync' => __('完全同步', 'notion-to-wordpress'),
                'confirm_smart_sync' => __('确定要执行智能同步吗？（仅同步有变化的内容）', 'notion-to-wordpress'),
                'confirm_full_sync' => __('确定要执行完全同步吗？（同步所有内容，耗时较长）', 'notion-to-wordpress'),
                'syncing' => __('中...', 'notion-to-wordpress'),
                'sync_completed' => __('完成', 'notion-to-wordpress'),
                'sync_failed' => __('失败，请稍后重试', 'notion-to-wordpress'),
                'page_refreshing' => __('页面即将刷新以应用设置变更...', 'notion-to-wordpress'),
                // JavaScript中使用的国际化字符串
                'copy_text_empty' => __('要复制的文本为空', 'notion-to-wordpress'),
                'no_new_verification_token' => __('暂无新的验证令牌', 'notion-to-wordpress'),
                'details' => __('详细信息', 'notion-to-wordpress'),
                'verification_token_updated' => __('验证令牌已更新', 'notion-to-wordpress'),
                'language_settings' => __('语言设置', 'notion-to-wordpress'),
                'webhook_settings' => __('Webhook设置', 'notion-to-wordpress'),
                'and' => __('和', 'notion-to-wordpress'),
            ]
        ));
        
        // 添加CSP头
        add_filter('script_loader_tag', function($tag, $handle) use ($script_nonce) {
            if ($handle === $this->plugin_name . '-admin') {
                return str_replace('<script ', '<script nonce="' . esc_attr($script_nonce) . '" ', $tag);
            }
            return $tag;
        }, 10, 2);
    }
    
    /**
     * 添加插件管理菜单
     *
     * @since    1.0.5
     */
    public function add_plugin_admin_menu() {
        // 使用自定义SVG图标
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents(Notion_To_WordPress_Helper::plugin_path('assets/icon.svg')));

        add_menu_page(
            __('Notion to WordPress', 'notion-to-wordpress'),
            __('Notion to WordPress', 'notion-to-wordpress'),
            'manage_options',
            $this->plugin_name,
            array($this, 'display_plugin_setup_page'),
            $icon_svg, // 使用自定义SVG图标
            99
        );
    }

    /**
     * 显示插件设置页面
     *
     * @since    1.0.5
     */
    public function display_plugin_setup_page() {
        require_once Notion_To_WordPress_Helper::plugin_path('admin/partials/notion-to-wordpress-admin-display.php');
    }

    /**
     * 对设置表单提交进行权限与 nonce 校验
     */
    private function validate_settings_request(): void {
        if ( ! isset( $_POST['notion_to_wordpress_options_nonce'] ) || ! wp_verify_nonce( $_POST['notion_to_wordpress_options_nonce'], 'notion_to_wordpress_options_update' ) ) {
            wp_die( __( '安全验证失败', 'notion-to-wordpress' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( '权限不足', 'notion-to-wordpress' ) );
        }
    }

    /**
     * 从 POST 数据解析并返回更新后的插件选项
     */
    private function parse_settings(array $options): array {
        // API密钥和数据库ID
        $api_key = isset($_POST['notion_to_wordpress_api_key']) ? sanitize_text_field($_POST['notion_to_wordpress_api_key']) : '';
        
        // 如果API密钥不为空且与保存的不同，则加密后存储
        if (!empty($api_key)) {
            // 检查是否已经是加密格式
            $current_key = isset($options['notion_api_key']) ? $options['notion_api_key'] : '';
            $decrypted_current = Notion_To_WordPress_Helper::decrypt_api_key($current_key);
            
            // 如果输入的密钥与当前解密的密钥不同，则加密新密钥
            if ($api_key !== $decrypted_current) {
                $options['notion_api_key'] = Notion_To_WordPress_Helper::encrypt_api_key($api_key);
            }
        } else {
            $options['notion_api_key'] = '';
        }
        
        $options['notion_database_id'] = isset($_POST['notion_to_wordpress_database_id']) ? sanitize_text_field($_POST['notion_to_wordpress_database_id']) : '';

        // Sync Schedule
        $options['sync_schedule'] = isset( $_POST['sync_schedule'] ) ? sanitize_text_field( $_POST['sync_schedule'] ) : '';

        // Delete on Uninstall
        $options['delete_on_uninstall'] = isset( $_POST['delete_on_uninstall'] ) ? 1 : 0;

        // Webhook 设置
        $options['webhook_enabled'] = isset( $_POST['webhook_enabled'] ) ? 1 : 0;

        // 保留已生成的 webhook_token；若不存在则生成一次
        if ( empty( $options['webhook_token'] ) ) {
            $options['webhook_token'] = Notion_To_WordPress_Helper::generate_token( 32 );
        }

        // Debug Level
        $options['debug_level'] = isset( $_POST['debug_level'] ) ? intval( $_POST['debug_level'] ) : Notion_To_WordPress_Helper::DEBUG_LEVEL_ERROR;

        // 新增设置项 - 使用配置管理系统
        // iframe 白名单域名
        $options['iframe_whitelist'] = isset( $_POST['iframe_whitelist'] ) ?
            sanitize_textarea_field( $_POST['iframe_whitelist'] ) :
            Notion_To_WordPress_Helper::get_config('security.iframe_whitelist');

        // 允许的图片格式
        $options['allowed_image_types'] = isset( $_POST['allowed_image_types'] ) ?
            sanitize_textarea_field( $_POST['allowed_image_types'] ) :
            Notion_To_WordPress_Helper::get_config('files.allowed_image_types');

        // 最大图片大小
        $options['max_image_size'] = isset( $_POST['max_image_size'] ) ?
            min( 20, max( 1, intval( $_POST['max_image_size'] ) ) ) :
            Notion_To_WordPress_Helper::get_config('files.max_image_size_mb');

        // 允许的文件类型（安全设置）
        $options['allowed_file_types'] = isset( $_POST['allowed_file_types'] ) ? sanitize_textarea_field( $_POST['allowed_file_types'] ) : '';

        // 文件上传安全级别
        $options['file_security_level'] = isset( $_POST['file_security_level'] ) ? sanitize_text_field( $_POST['file_security_level'] ) : 'strict';
        if ( ! in_array( $options['file_security_level'], ['strict', 'moderate', 'permissive'] ) ) {
            $options['file_security_level'] = 'strict';
        }

        // 缓存配置设置 - 使用配置管理系统
        $options['cache_max_items'] = isset( $_POST['cache_max_items'] ) ?
            min( 10000, max( 100, intval( $_POST['cache_max_items'] ) ) ) :
            Notion_To_WordPress_Helper::get_config('cache.max_items');
        $options['cache_memory_limit'] = isset( $_POST['cache_memory_limit'] ) ?
            min( 500, max( 10, intval( $_POST['cache_memory_limit'] ) ) ) :
            Notion_To_WordPress_Helper::get_config('cache.memory_limit_mb');
        $options['cache_ttl'] = isset( $_POST['cache_ttl'] ) ?
            min( 3600, max( 60, intval( $_POST['cache_ttl'] ) ) ) :
            Notion_To_WordPress_Helper::get_config('cache.ttl');

        // Plugin Language option (替换旧的 force_english_ui)
        $plugin_language = isset( $_POST['plugin_language'] ) ? sanitize_text_field( $_POST['plugin_language'] ) : 'auto';
        if ( in_array( $plugin_language, ['auto', 'zh_CN', 'en_US'] ) ) {
            $options['plugin_language'] = $plugin_language;
        } else {
            $options['plugin_language'] = 'auto';
        }

        // 向后兼容：根据新的 plugin_language 设置旧的 force_english_ui
        $options['force_english_ui'] = ( $plugin_language === 'en_US' ) ? 1 : 0;

        // Field Mapping
        if ( isset( $_POST['field_mapping'] ) && is_array( $_POST['field_mapping'] ) ) {
            $options['field_mapping'] = array_map( 'sanitize_text_field', $_POST['field_mapping'] );
        }

        // 自定义字段映射
        if ( isset( $_POST['custom_field_mappings'] ) && is_array( $_POST['custom_field_mappings'] ) ) {
            $custom_field_mappings = [];
            
            foreach ( $_POST['custom_field_mappings'] as $mapping ) {
                if ( empty( $mapping['notion_property'] ) || empty( $mapping['wp_field'] ) ) {
                    continue; // 跳过空映射
                }
                
                $custom_field_mappings[] = [
                    'notion_property' => sanitize_text_field( $mapping['notion_property'] ),
                    'wp_field'        => sanitize_text_field( $mapping['wp_field'] ),
                    'field_type'      => sanitize_text_field( $mapping['field_type'] ?? 'text' ),
                ];
            }
            
            $options['custom_field_mappings'] = $custom_field_mappings;
        }

        return $options;
    }

    /**
     * 根据选项更新或清理 cron 计划
     */
    private function update_cron_schedule(array $options): void {
        $schedule = $options['sync_schedule'] ?? 'manual';
        if ('manual' !== $schedule && !wp_next_scheduled('notion_cron_import')) {
            wp_schedule_event(time(), $schedule, 'notion_cron_import');
        } elseif ('manual' === $schedule && wp_next_scheduled('notion_cron_import')) {
            wp_clear_scheduled_hook('notion_cron_import');
        }
    }

    private function update_log_cleanup_schedule(array $options): void {
        $retention_days = isset($options['log_retention_days']) ? (int)$options['log_retention_days'] : 0;
        $hook_name = 'notion_to_wordpress_log_cleanup';

        if ($retention_days > 0) {
            if (!wp_next_scheduled($hook_name)) {
                wp_schedule_event(time(), 'daily', $hook_name);
            }
        } else {
            if (wp_next_scheduled($hook_name)) {
                wp_clear_scheduled_hook($hook_name);
            }
        }
    }

    /**
     * 更新缓存清理调度和配置
     *
     * @since 1.1.1
     * @param array $options 插件选项
     */
    private function update_cache_schedule(array $options): void {
        $hook_name = 'notion_to_wordpress_cache_cleanup';

        // 确保缓存清理任务已调度
        if (!wp_next_scheduled($hook_name)) {
            wp_schedule_event(time(), 'hourly', $hook_name);
        }

        // 更新缓存配置
        $cache_config = [
            'max_items' => $options['cache_max_items'] ?? 1000,
            'memory_limit_mb' => $options['cache_memory_limit'] ?? 50,
            'ttl' => $options['cache_ttl'] ?? 300
        ];

        Notion_API::configure_cache($cache_config);
    }

    /**
     * 统一的AJAX安全验证中间件
     *
     * @since 1.1.1
     * @param string $nonce_action nonce动作名称
     * @param string $capability 所需权限
     * @param bool $log_attempts 是否记录验证尝试
     * @return bool 验证是否通过
     */
    private function validate_ajax_security(string $nonce_action = 'notion_to_wordpress_nonce', string $capability = 'manage_options', bool $log_attempts = true): bool {
        $request_id = uniqid('req_', true);
        $user_ip = $this->get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        if ($log_attempts) {
            Notion_To_WordPress_Helper::debug_log(
                "AJAX安全验证开始 - 请求ID: {$request_id}, IP: {$user_ip}, UA: " . substr($user_agent, 0, 100),
                'Security Validation'
            );
        }

        // 1. 检查请求方法
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($log_attempts) {
                Notion_To_WordPress_Helper::error_log(
                    "非POST请求被拒绝 - 请求ID: {$request_id}, 方法: " . $_SERVER['REQUEST_METHOD'],
                    'Security Validation'
                );
            }
            wp_send_json_error(['message' => __('无效的请求方法', 'notion-to-wordpress')], 405);
            return false;
        }

        // 2. 检查请求来源
        if (!$this->validate_request_origin()) {
            if ($log_attempts) {
                Notion_To_WordPress_Helper::error_log(
                    "请求来源验证失败 - 请求ID: {$request_id}, Referer: " . ($_SERVER['HTTP_REFERER'] ?? 'None'),
                    'Security Validation'
                );
            }
            wp_send_json_error(['message' => __('请求来源验证失败', 'notion-to-wordpress')], 403);
            return false;
        }

        // 3. 检查nonce
        if (!isset($_POST['nonce'])) {
            if ($log_attempts) {
                Notion_To_WordPress_Helper::error_log(
                    "缺少nonce参数 - 请求ID: {$request_id}",
                    'Security Validation'
                );
            }
            wp_send_json_error(['message' => __('缺少安全验证参数', 'notion-to-wordpress')], 400);
            return false;
        }

        if (!wp_verify_nonce($_POST['nonce'], $nonce_action)) {
            if ($log_attempts) {
                Notion_To_WordPress_Helper::error_log(
                    "nonce验证失败 - 请求ID: {$request_id}, 提供的nonce: " . substr($_POST['nonce'], 0, 10) . '...',
                    'Security Validation'
                );
            }
            wp_send_json_error(['message' => __('安全验证失败', 'notion-to-wordpress')], 403);
            return false;
        }

        // 4. 检查用户权限
        if (!current_user_can($capability)) {
            if ($log_attempts) {
                Notion_To_WordPress_Helper::error_log(
                    "权限检查失败 - 请求ID: {$request_id}, 用户ID: " . get_current_user_id() . ", 所需权限: {$capability}",
                    'Security Validation'
                );
            }
            wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')], 403);
            return false;
        }

        // 5. 检查请求频率限制
        if (!$this->check_rate_limit($user_ip)) {
            if ($log_attempts) {
                Notion_To_WordPress_Helper::error_log(
                    "请求频率超限 - 请求ID: {$request_id}, IP: {$user_ip}",
                    'Security Validation'
                );
            }
            wp_send_json_error(['message' => __('请求过于频繁，请稍后重试', 'notion-to-wordpress')], 429);
            return false;
        }

        if ($log_attempts) {
            Notion_To_WordPress_Helper::debug_log(
                "AJAX安全验证通过 - 请求ID: {$request_id}",
                'Security Validation'
            );
        }

        return true;
    }

    /**
     * 验证请求来源
     *
     * @since 1.1.1
     * @return bool
     */
    private function validate_request_origin(): bool {
        // 检查Referer头部
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (empty($referer)) {
            return false; // 严格模式：必须有Referer
        }

        $site_url = get_site_url();
        $admin_url = admin_url();

        // 检查Referer是否来自当前站点
        if (!str_starts_with($referer, $site_url) && !str_starts_with($referer, $admin_url)) {
            return false;
        }

        // 检查Origin头部（如果存在）
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!empty($origin)) {
            $parsed_site = parse_url($site_url);
            $expected_origin = $parsed_site['scheme'] . '://' . $parsed_site['host'];
            if (isset($parsed_site['port'])) {
                $expected_origin .= ':' . $parsed_site['port'];
            }

            if ($origin !== $expected_origin) {
                return false;
            }
        }

        return true;
    }

    /**
     * 获取客户端真实IP地址
     *
     * @since 1.1.1
     * @return string
     */
    private function get_client_ip(): string {
        $ip_headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // 代理服务器
            'HTTP_X_FORWARDED',          // 代理服务器
            'HTTP_X_CLUSTER_CLIENT_IP',  // 集群
            'HTTP_FORWARDED_FOR',        // 代理服务器
            'HTTP_FORWARDED',            // 代理服务器
            'REMOTE_ADDR'                // 标准
        ];

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // 处理多个IP的情况（取第一个）
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // 验证IP格式
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * 检查请求频率限制
     *
     * @since 1.1.1
     * @param string $ip 客户端IP
     * @return bool
     */
    private function check_rate_limit(string $ip): bool {
        $transient_key = 'notion_wp_rate_limit_' . md5($ip);
        $current_time = time();
        $window_size = 60; // 1分钟窗口
        $max_requests = 30; // 每分钟最多30个请求

        $requests = get_transient($transient_key);
        if ($requests === false) {
            $requests = [];
        }

        // 清理过期的请求记录
        $requests = array_filter($requests, function($timestamp) use ($current_time, $window_size) {
            return ($current_time - $timestamp) < $window_size;
        });

        // 检查是否超过限制
        if (count($requests) >= $max_requests) {
            return false;
        }

        // 记录当前请求
        $requests[] = $current_time;
        set_transient($transient_key, $requests, $window_size);

        return true;
    }

    /**
     * 添加安全头部，防止XSS攻击
     *
     * @since 1.1.1
     */
    private function add_security_headers(): void {
        // 只在插件管理页面添加CSP头部
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        // 生成nonce用于内联脚本
        $script_nonce = wp_create_nonce('notion_wp_script_nonce');

        // 内容安全策略
        $csp_directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$script_nonce}'", // 移除unsafe-eval
            "style-src 'self' 'unsafe-inline'", // WordPress admin需要内联样式
            "img-src 'self' data: https:", 
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "block-all-mixed-content",
            "upgrade-insecure-requests"
        ];

        $csp_header = implode('; ', $csp_directives);

        // 设置安全头部
        if (!headers_sent()) {
            header("Content-Security-Policy: {$csp_header}");
            header("X-Content-Type-Options: nosniff");
            header("X-Frame-Options: DENY");
            header("X-XSS-Protection: 1; mode=block");
            header("Referrer-Policy: strict-origin-when-cross-origin");
            header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
        }
    }

    public function handle_settings_form() {
        $this->validate_settings_request();

        $current_options = get_option( 'notion_to_wordpress_options', [] );
        $options         = $this->parse_settings( $current_options );

        update_option( 'notion_to_wordpress_options', $options );

        // 重新初始化调试级别
        Notion_To_WordPress_Helper::init();

        // 更新 cron
        $this->update_cron_schedule( $options );
        $this->update_log_cleanup_schedule($options);
        $this->update_cache_schedule($options);

        // 设置一个短暂的transient来传递成功消息
        set_transient('notion_to_wordpress_settings_saved', true, 5);

        // 重定向回设置页面
        wp_safe_redirect(admin_url('admin.php?page=' . $this->plugin_name));
        exit;
    }

    public function handle_save_settings_ajax() {
        try {
            check_ajax_referer('notion_to_wordpress_options_update', 'notion_to_wordpress_options_nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')], 403);
            }

            $current_options = get_option('notion_to_wordpress_options', []);
            $options = $this->parse_settings($current_options);

            update_option('notion_to_wordpress_options', $options);

            // 重新初始化调试级别
            Notion_To_WordPress_Helper::init();

            // 更新 cron
            $this->update_cron_schedule($options);
            $this->update_log_cleanup_schedule($options);

            wp_send_json_success(['message' => __('设置已成功保存。', 'notion-to-wordpress')]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => __('保存设置时发生错误：', 'notion-to-wordpress') . $e->getMessage()]);
        }
    }

    /**
     * 测试Notion API连接
     *
     * @since    1.0.5
     */
    public function handle_test_connection() {
        // 使用统一的安全验证中间件
        if (!$this->validate_ajax_security()) {
            return; // 验证失败时中间件已经发送了错误响应
        }

        $api_key     = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
        $database_id = isset( $_POST['database_id'] ) ? sanitize_text_field( $_POST['database_id'] ) : '';
        
        if (empty($api_key) || empty($database_id)) {
            wp_send_json_error(['message' => __('请输入API密钥和数据库ID', 'notion-to-wordpress')]);
            return;
        }
        
        // 使用传入的Key和ID进行测试
        $temp_api = new Notion_API($api_key);
        
        try {
            $response = $temp_api->test_connection( $database_id );
            if (is_wp_error($response)) {
                // 尝试错误恢复
                $recovery_result = Notion_To_WordPress_Helper::attempt_error_recovery(
                    $response,
                    function() use ($temp_api, $database_id) {
                        return $temp_api->test_connection($database_id);
                    },
                    2
                );

                if (is_wp_error($recovery_result)) {
                    wp_send_json_error(['message' => $recovery_result->get_error_message()]);
                    return;
                }
            }

            wp_send_json_success(['message' => __('连接成功！数据库可访问。', 'notion-to-wordpress')]);

        } catch (Exception $e) {
            $error = Notion_To_WordPress_Helper::exception_to_wp_error(
                $e,
                Notion_To_WordPress_Helper::ERROR_TYPE_API,
                Notion_To_WordPress_Helper::ERROR_SEVERITY_HIGH,
                ['operation' => 'test_connection']
            );
            wp_send_json_error(['message' => $error->get_error_message()]);
        }
    }

    /**
     * 处理手动导入请求
     *
     * @since    1.0.5
     */
    public function handle_manual_import() {
        // 使用统一的安全验证中间件
        if (!$this->validate_ajax_security()) {
            return; // 验证失败时中间件已经发送了错误响应
        }

        // 添加调试日志
        Notion_To_WordPress_Helper::debug_log('handle_manual_import 开始执行', 'Manual Import');

        // 增加执行时间限制 - 使用配置管理
        $time_limit = Notion_To_WordPress_Helper::get_config('performance.execution_time_limit', 300);
        set_time_limit($time_limit);

        // 增加内存限制 - 使用配置管理
        $memory_limit = Notion_To_WordPress_Helper::get_config('performance.memory_limit_mb', 256);
        ini_set('memory_limit', $memory_limit . 'M');

        try {
            Notion_To_WordPress_Helper::debug_log('开始导入流程', 'Manual Import');
            // 获取选项
            $options = get_option( 'notion_to_wordpress_options', [] );

            // 检查必要的设置
            if ( empty( $options['notion_api_key'] ) || empty( $options['notion_database_id'] ) ) {
                wp_send_json_error( [ 'message' => __('请先配置API密钥和数据库ID', 'notion-to-wordpress') ] );
                return;
            }

            // 初始化API和Pages对象
            $api_key = $options['notion_api_key'];
            $database_id = $options['notion_database_id'];
            $field_mapping = $options['field_mapping'] ?? [];
            $custom_field_mappings = $options['custom_field_mappings'] ?? [];

            // 实例化API和Pages对象
            Notion_To_WordPress_Helper::debug_log('创建API实例，API Key: ' . substr($api_key, 0, 10) . '...', 'Manual Import');
            $notion_api = new Notion_API( $api_key );

            Notion_To_WordPress_Helper::debug_log('创建Pages实例，Database ID: ' . $database_id, 'Manual Import');
            $notion_pages = new Notion_Pages( $notion_api, $database_id, $field_mapping );
            $notion_pages->set_custom_field_mappings($custom_field_mappings);

            // 检查是否启用增量同步
            $incremental = isset($_POST['incremental']) ? (bool) $_POST['incremental'] : true;
            $check_deletions = isset($_POST['check_deletions']) ? (bool) $_POST['check_deletions'] : true;

            Notion_To_WordPress_Helper::info_log('手动同步参数 - 增量: ' . ($incremental ? 'yes' : 'no') . ', 检查删除: ' . ($check_deletions ? 'yes' : 'no'), 'Manual Sync');

            // 执行导入
            Notion_To_WordPress_Helper::info_log('开始执行import_pages()', 'Manual Sync');
            $result = $notion_pages->import_pages($check_deletions, $incremental);
            Notion_To_WordPress_Helper::info_log('import_pages()执行完成，结果: ' . print_r($result, true), 'Manual Sync');

            // 更新最后同步时间
            update_option( 'notion_to_wordpress_last_sync', current_time( 'mysql' ) );

            // 返回结果
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
                return;
            }

            wp_send_json_success( [
                'message' => sprintf(
                    __( '导入完成！处理了 %d 个页面，导入了 %d 个页面，更新了 %d 个页面。', 'notion-to-wordpress' ),
                    $result['total'],
                    $result['imported'],
                    $result['updated']
                )
            ] );
            
        } catch ( Exception $e ) {
            Notion_To_WordPress_Helper::error_log('捕获异常: ' . $e->getMessage(), 'Manual Sync');
            Notion_To_WordPress_Helper::error_log('异常堆栈: ' . $e->getTraceAsString(), 'Manual Sync');
            wp_send_json_error( [ 'message' => __('导入失败: ', 'notion-to-wordpress') . $e->getMessage() ] );
        }
    }

    /**
     * 处理刷新全部内容请求
     *
     * @since    1.0.5
     */
    public function handle_refresh_all() {
        // 添加调试日志
        Notion_To_WordPress_Helper::debug_log('handle_refresh_all 开始执行', 'Refresh All');

        // 增加执行时间限制 - 使用配置管理
        $time_limit = Notion_To_WordPress_Helper::get_config('performance.execution_time_limit', 300);
        set_time_limit($time_limit);

        // 增加内存限制 - 使用配置管理
        $memory_limit = Notion_To_WordPress_Helper::get_config('performance.memory_limit_mb', 256);
        ini_set('memory_limit', $memory_limit . 'M');

        // 使用统一的安全验证中间件
        if (!$this->validate_ajax_security()) {
            return; // 验证失败时中间件已经发送了错误响应
        }

        try {
            // 获取选项
            $options = get_option( 'notion_to_wordpress_options', [] );

            // 检查必要的设置
            if ( empty( $options['notion_api_key'] ) || empty( $options['notion_database_id'] ) ) {
                wp_send_json_error( [ 'message' => __('请先配置API密钥和数据库ID', 'notion-to-wordpress') ] );
                return;
            }

            // 初始化API和Pages对象
            $api_key = $options['notion_api_key'];
            $database_id = $options['notion_database_id'];
            $field_mapping = $options['field_mapping'] ?? [];
            $custom_field_mappings = $options['custom_field_mappings'] ?? [];

            // 实例化API和Pages对象
            $notion_api = new Notion_API( $api_key );
            $notion_pages = new Notion_Pages( $notion_api, $database_id, $field_mapping );
            $notion_pages->set_custom_field_mappings($custom_field_mappings);

            // 执行导入
            $result = $notion_pages->import_pages();

            // 更新最后同步时间
            update_option( 'notion_to_wordpress_last_sync', current_time( 'mysql' ) );

            // 返回结果
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
                return;
            }

            wp_send_json_success( [
                'message' => sprintf(
                    __( '刷新完成！处理了 %d 个页面，导入了 %d 个页面，更新了 %d 个页面。', 'notion-to-wordpress' ),
                    $result['total'],
                    $result['imported'],
                    $result['updated']
                )
            ] );

        } catch (Exception $e) {
            wp_send_json_error(['message' => __('刷新失败: ', 'notion-to-wordpress') . $e->getMessage()]);
        }
    }

    /**
     * 获取统计信息
     *
     * @since    1.0.8
     */
    public function handle_get_stats() {
        // 添加错误日志记录
        Notion_To_WordPress_Helper::debug_log('handle_get_stats 被调用', 'Get Stats');

        // 使用统一的安全验证中间件
        if (!$this->validate_ajax_security()) {
            return; // 验证失败时中间件已经发送了错误响应
        }

        try {
            // 获取导入的文章数量
            $imported_count = $this->get_imported_posts_count();
            Notion_To_WordPress_Helper::debug_log('导入文章数量: ' . $imported_count, 'Get Stats');

            // 获取已发布的文章数量
            $published_count = $this->get_published_posts_count();
            Notion_To_WordPress_Helper::debug_log('已发布文章数量: ' . $published_count, 'Get Stats');

            // 获取缓存统计信息
            $cache_stats = Notion_API::get_cache_stats();

            // 获取最后同步时间
            $last_update = get_option('notion_to_wordpress_last_sync', '');
            if ($last_update) {
                $last_update = Notion_To_WordPress_Helper::format_datetime_by_plugin_language(strtotime($last_update));
            } else {
                $last_update = __('从未', 'notion-to-wordpress');
            }

            // 获取下次计划运行时间
            $next_run = wp_next_scheduled('notion_cron_import');
            if ($next_run) {
                $next_run = Notion_To_WordPress_Helper::format_datetime_by_plugin_language($next_run);
            } else {
                $next_run = __('未计划', 'notion-to-wordpress');
            }

            $result = [
                'imported_count' => $imported_count,
                'published_count' => $published_count,
                'last_update' => $last_update,
                'next_run' => $next_run,
                'cache_stats' => $cache_stats
            ];

            Notion_To_WordPress_Helper::debug_log('统计信息获取成功: ' . json_encode($result), 'Get Stats');
            wp_send_json_success($result);

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log('获取统计信息异常: ' . $e->getMessage(), 'Get Stats');
            wp_send_json_error(['message' => __('获取统计信息失败: ', 'notion-to-wordpress') . $e->getMessage()]);
        } catch (Error $e) {
            Notion_To_WordPress_Helper::error_log('获取统计信息错误: ' . $e->getMessage(), 'Get Stats');
            wp_send_json_error(['message' => __('获取统计信息错误: ', 'notion-to-wordpress') . $e->getMessage()]);
        }
    }
    
    /**
     * 获取已导入的文章数量
     *
     * @since    1.0.8
     * @return   int    导入的文章数量
     */
    private function get_imported_posts_count() {
        global $wpdb;

        try {
            // 若从未同步，则直接返回0，避免误计
            if ( ! get_option( 'notion_to_wordpress_last_sync', '' ) ) {
                return 0;
            }

            // 优化后的查询：使用预处理语句和更高效的JOIN
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT pm.meta_value)
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = %s
                     AND pm.meta_value != ''
                     AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'future')
                     AND p.post_type IN ('post', 'page')",
                    '_notion_page_id'
                )
            );

            // 检查数据库错误
            if ( $wpdb->last_error ) {
                Notion_To_WordPress_Helper::error_log('数据库查询错误: ' . $wpdb->last_error, 'Database Query');
                return 0;
            }

            return intval( $count ?: 0 );

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log('get_imported_posts_count 异常: ' . $e->getMessage(), 'Database Query');
            return 0;
        }
    }
    
    /**
     * 获取已发布的文章数量
     *
     * @since    1.0.8
     * @return   int    已发布的文章数量
     */
    private function get_published_posts_count() {
        global $wpdb;

        try {
            if ( ! get_option( 'notion_to_wordpress_last_sync', '' ) ) {
                return 0;
            }

            $count = $wpdb->get_var(
                "SELECT COUNT(DISTINCT pm.meta_value)
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_notion_page_id'
                 AND pm.meta_value <> ''
                 AND p.post_status = 'publish'"
            );

            // 检查数据库错误
            if ( $wpdb->last_error ) {
                Notion_To_WordPress_Helper::error_log('数据库查询错误: ' . $wpdb->last_error, 'Database Query');
                return 0;
            }

            return intval( $count ?: 0 );

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log('get_published_posts_count 异常: ' . $e->getMessage(), 'Database Query');
            return 0;
        }
    }

    /**
     * 处理清除日志请求
     *
     * @since    1.0.8
     */
    public function handle_clear_logs() {
        // 使用统一的安全验证中间件
        if (!$this->validate_ajax_security()) {
            return; // 验证失败时中间件已经发送了错误响应
        }

        try {
            $success = Notion_To_WordPress_Helper::clear_logs();
            
            if ($success) {
                wp_send_json_success(['message' => __('所有日志文件已清除', 'notion-to-wordpress')]);
            } else {
                wp_send_json_error(['message' => __('清除日志时出现错误', 'notion-to-wordpress')]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('清除日志失败: ', 'notion-to-wordpress') . $e->getMessage()]);
        }
    }

    public function handle_view_log() {
        // 使用统一的安全验证中间件
        if (!$this->validate_ajax_security()) {
            return; // 验证失败时中间件已经发送了错误响应
        }

        $file = isset($_POST['file']) ? sanitize_text_field($_POST['file']) : '';
        if (empty($file)) {
            wp_send_json_error(['message' => __('未指定日志文件', 'notion-to-wordpress')]);
        }

        $content = Notion_To_WordPress_Helper::get_log_content($file);

        // 如果返回错误信息，则视为失败
        if (strpos($content, __('无效', 'notion-to-wordpress')) === 0 || strpos($content, __('不存在', 'notion-to-wordpress')) !== false) {
            wp_send_json_error(['message' => $content]);
        }

        wp_send_json_success($content);
    }

    /**
     * 刷新验证令牌
     *
     * @since    1.1.0
     */
    public function handle_refresh_verification_token() {
        // 使用统一的安全验证中间件
        if (!$this->validate_ajax_security()) {
            return; // 验证失败时中间件已经发送了错误响应
        }

        // 获取最新的验证令牌
        $options = get_option('notion_to_wordpress_options', []);
        $verification_token = $options['webhook_verify_token'] ?? '';

        wp_send_json_success([
            'verification_token' => $verification_token,
            'message' => __('验证令牌已刷新', 'notion-to-wordpress')
        ]);
    }

    /**
     * 测试调试方法
     *
     * @since    1.1.0
     */
    public function handle_test_debug() {
        Notion_To_WordPress_Helper::debug_log('handle_test_debug 被调用', 'Test Debug');

        try {
            // 使用统一的安全验证中间件
            if (!$this->validate_ajax_security()) {
                return; // 验证失败时中间件已经发送了错误响应
            }

            // 先检查基本的POST数据
            Notion_To_WordPress_Helper::debug_log('POST数据: ' . print_r($_POST, true), 'Test Debug');

            Notion_To_WordPress_Helper::info_log('权限检查成功', 'Debug Test');

            $test_data = [
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'plugin_version' => NOTION_TO_WORDPRESS_VERSION,
                'current_time' => current_time('mysql'),
                'options_exist' => get_option('notion_to_wordpress_options') ? 'yes' : 'no',
                'ajax_url' => admin_url('admin-ajax.php')
            ];

            Notion_To_WordPress_Helper::info_log('测试数据: ' . print_r($test_data, true), 'Debug Test');
            wp_send_json_success(['message' => __('调试测试成功', 'notion-to-wordpress'), 'data' => $test_data]);

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log('测试异常: ' . $e->getMessage(), 'Debug Test');
            Notion_To_WordPress_Helper::error_log('异常堆栈: ' . $e->getTraceAsString(), 'Debug Test');
            wp_send_json_error(['message' => __('测试失败: ', 'notion-to-wordpress') . $e->getMessage()]);
        } catch (Error $e) {
            Notion_To_WordPress_Helper::error_log('测试错误: ' . $e->getMessage(), 'Debug Test');
            Notion_To_WordPress_Helper::error_log('错误堆栈: ' . $e->getTraceAsString(), 'Debug Test');
            wp_send_json_error(['message' => __('测试错误: ', 'notion-to-wordpress') . $e->getMessage()]);
        }
    }

    /**
     * 处理配置管理请求
     *
     * @since 1.1.1
     */
    public function handle_config_management() {
        // 使用统一的安全验证中间件
        if (!$this->validate_ajax_security()) {
            return; // 验证失败时中间件已经发送了错误响应
        }

        $action = isset($_POST['config_action']) ? sanitize_text_field($_POST['config_action']) : '';

        try {
            switch ($action) {
                case 'validate':
                    $result = Notion_To_WordPress_Helper::validate_config();
                    wp_send_json_success($result);
                    break;

                case 'reset':
                    $section = isset($_POST['section']) ? sanitize_text_field($_POST['section']) : null;
                    $success = Notion_To_WordPress_Helper::reset_config($section);
                    if ($success) {
                        wp_send_json_success(['message' => __('配置已重置为默认值', 'notion-to-wordpress')]);
                    } else {
                        wp_send_json_error(['message' => __('配置重置失败', 'notion-to-wordpress')]);
                    }
                    break;

                case 'export':
                    $config = Notion_To_WordPress_Helper::get_all_config();
                    // 移除敏感信息
                    unset($config['notion_api_key']);
                    wp_send_json_success(['config' => $config]);
                    break;

                case 'get_schema':
                    $schema = Notion_To_WordPress_Helper::get_default_config_schema();
                    wp_send_json_success(['schema' => $schema]);
                    break;

                default:
                    wp_send_json_error(['message' => __('未知的配置操作', 'notion-to-wordpress')]);
            }
        } catch (Exception $e) {
            $error = Notion_To_WordPress_Helper::exception_to_wp_error(
                $e,
                Notion_To_WordPress_Helper::ERROR_TYPE_SYSTEM,
                Notion_To_WordPress_Helper::ERROR_SEVERITY_MEDIUM,
                ['operation' => 'config_management', 'action' => $action]
            );
            wp_send_json_error(['message' => $error->get_error_message()]);
        }
    }

    /**
     * 处理查询性能分析请求
     *
     * @since 1.1.1
     */
    public function handle_query_performance() {
        // 使用统一的安全验证中间件
        if (!$this->validate_ajax_security()) {
            return; // 验证失败时中间件已经发送了错误响应
        }

        try {
            $stats = Notion_To_WordPress_Helper::get_query_stats();
            wp_send_json_success($stats);
        } catch (Exception $e) {
            $error = Notion_To_WordPress_Helper::exception_to_wp_error(
                $e,
                Notion_To_WordPress_Helper::ERROR_TYPE_SYSTEM,
                Notion_To_WordPress_Helper::ERROR_SEVERITY_MEDIUM,
                ['operation' => 'query_performance']
            );
            wp_send_json_error(['message' => $error->get_error_message()]);
        }
    }

    /**
     * 处理测试验证请求
     *
     * @since 1.1.1
     */
    public function handle_test_validation() {
        // 使用统一的安全验证中间件
        if (!$this->validate_ajax_security()) {
            return; // 验证失败时中间件已经发送了错误响应
        }

        $test_type = isset($_POST['test_type']) ? sanitize_text_field($_POST['test_type']) : 'quick';

        try {
            // 加载测试执行器
            require_once plugin_dir_path(dirname(__FILE__)) . 'tests/admin-test-executor.php';

            switch ($test_type) {
                case 'security':
                    $results = Notion_Admin_Test_Executor::run_quick_security_check();
                    break;
                case 'performance':
                    $results = Notion_Admin_Test_Executor::run_quick_performance_check();
                    break;
                case 'functional':
                    $results = Notion_Admin_Test_Executor::run_quick_functional_check();
                    break;
                case 'quick':
                default:
                    $results = Notion_Admin_Test_Executor::run_complete_quick_check();
                    break;
            }

            wp_send_json_success($results);
        } catch (Exception $e) {
            $error = Notion_To_WordPress_Helper::exception_to_wp_error(
                $e,
                Notion_To_WordPress_Helper::ERROR_TYPE_SYSTEM,
                Notion_To_WordPress_Helper::ERROR_SEVERITY_MEDIUM,
                ['operation' => 'test_validation', 'test_type' => $test_type]
            );
            wp_send_json_error(['message' => $error->get_error_message()]);
        }
    }

    /**
     * 注册与管理区域功能相关的所有钩子
     *
     * @since    1.0.5
     * @access   private
     */
    // 注意：钩子注册在主插件类的define_admin_hooks方法中处理
}