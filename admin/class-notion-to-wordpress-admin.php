<?php
// 声明严格类型
declare(strict_types=1);

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 后台管理类。
 * 负责插件后台设置页面的功能，包括表单处理、选项保存等。
 * @since      1.0.9
 * @version    1.8.3-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
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
     * Notion导入协调器实例
     *
     * @since    1.0.5
     * @access   private
     * @var      Notion_Import_Coordinator
     */
    private Notion_Import_Coordinator $notion_pages;

    /**
     * 初始化类并设置其属性
     *
     * @since    1.0.5
     * @param string $plugin_name 插件名称
     * @param string $version 插件版本
     * @param Notion_API $notion_api Notion API实例
     * @param Notion_Import_Coordinator $notion_pages Notion导入协调器实例
     */
    public function __construct(string $plugin_name, string $version, Notion_API $notion_api, Notion_Import_Coordinator $notion_pages) {
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
        // API Key & Database ID
        $options['notion_api_key']     = isset( $_POST['notion_to_wordpress_api_key'] ) ? sanitize_text_field( $_POST['notion_to_wordpress_api_key'] ) : '';
        $options['notion_database_id'] = isset( $_POST['notion_to_wordpress_database_id'] ) ? sanitize_text_field( $_POST['notion_to_wordpress_database_id'] ) : '';

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
        $options['debug_level'] = isset( $_POST['debug_level'] ) ? intval( $_POST['debug_level'] ) : Notion_Logger::DEBUG_LEVEL_ERROR;

        // 新增设置项
        // iframe 白名单域名
        $options['iframe_whitelist'] = isset( $_POST['iframe_whitelist'] ) ? sanitize_textarea_field( $_POST['iframe_whitelist'] ) : 'www.youtube.com,youtu.be,player.bilibili.com,b23.tv,v.qq.com';
        
        // 允许的图片格式
        $options['allowed_image_types'] = isset( $_POST['allowed_image_types'] ) ? sanitize_textarea_field( $_POST['allowed_image_types'] ) : 'image/jpeg,image/png,image/gif,image/webp';
        
        // 最大图片大小
        $options['max_image_size'] = isset( $_POST['max_image_size'] ) ? min( 20, max( 1, intval( $_POST['max_image_size'] ) ) ) : 5; // 1-20MB 范围

        // Plugin Language option (替换旧的 force_english_ui)
        $plugin_language = isset( $_POST['plugin_language'] ) ? sanitize_text_field( $_POST['plugin_language'] ) : 'auto';
        if ( in_array( $plugin_language, ['auto', 'zh_CN', 'en_US'] ) ) {
            $options['plugin_language'] = $plugin_language;
        } else {
            $options['plugin_language'] = 'auto';
        }

        // 向后兼容：根据新的 plugin_language 设置旧的 force_english_ui
        $options['force_english_ui'] = ( $plugin_language === 'en_US' ) ? 1 : 0;

        // 简化配置处理
        if (isset($_POST['performance_level'])) {
            $options['performance_level'] = sanitize_text_field($_POST['performance_level']);
        }

        if (isset($_POST['field_template'])) {
            $options['field_template'] = sanitize_text_field($_POST['field_template']);
        }

        // 性能优化配置（保持向后兼容）
        $options['api_page_size'] = isset( $_POST['api_page_size'] ) ? min( 200, max( 50, intval( $_POST['api_page_size'] ) ) ) : 100;
        $options['concurrent_requests'] = isset( $_POST['concurrent_requests'] ) ? min( 15, max( 3, intval( $_POST['concurrent_requests'] ) ) ) : 5;
        $options['batch_size'] = isset( $_POST['batch_size'] ) ? min( 100, max( 10, intval( $_POST['batch_size'] ) ) ) : 20;
        $options['log_buffer_size'] = isset( $_POST['log_buffer_size'] ) ? min( 200, max( 10, intval( $_POST['log_buffer_size'] ) ) ) : 50;
        $options['enable_performance_mode'] = isset( $_POST['enable_performance_mode'] ) ? 1 : 0;

        // CDN 配置
        $options['enable_cdn'] = isset( $_POST['enable_cdn'] ) ? 1 : 0;
        $options['cdn_provider'] = isset( $_POST['cdn_provider'] ) ? sanitize_text_field( $_POST['cdn_provider'] ) : 'jsdelivr';
        $options['custom_cdn_url'] = isset( $_POST['custom_cdn_url'] ) ? esc_url_raw( $_POST['custom_cdn_url'] ) : '';
        
        // CDN 相关的性能优化选项
        $options['enable_asset_compression'] = isset( $_POST['enable_asset_compression'] ) ? 1 : 0;
        $options['compression_level'] = isset( $_POST['compression_level'] ) ? sanitize_text_field( $_POST['compression_level'] ) : 'auto';
        $options['enhanced_lazy_loading'] = isset( $_POST['enhanced_lazy_loading'] ) ? 1 : 0;
        $options['preload_threshold'] = isset( $_POST['preload_threshold'] ) ? min( 10, max( 1, intval( $_POST['preload_threshold'] ) ) ) : 2;
        $options['performance_monitoring'] = isset( $_POST['performance_monitoring'] ) ? 1 : 0;
        $options['performance_report_interval'] = isset( $_POST['performance_report_interval'] ) ? min( 60000, max( 5000, intval( $_POST['performance_report_interval'] ) ) ) : 30000;

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

        // 应用简化配置（如果配置简化器可用）
        if (class_exists('Notion_Config_Simplifier')) {
            // 首次迁移现有配置
            if (!isset($options['config_migrated'])) {
                $options = Notion_Config_Simplifier::migrate_legacy_config($options);
            }

            // 应用简化配置到详细配置
            $options = Notion_Config_Simplifier::apply_simplified_config($options);
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

    public function handle_settings_form() {
        $this->validate_settings_request();

        $current_options = get_option( 'notion_to_wordpress_options', [] );
        $options         = $this->parse_settings( $current_options );

        update_option( 'notion_to_wordpress_options', $options );

        // 重新初始化日志系统
        if (class_exists('Notion_Logger')) {
            Notion_Logger::init();
        }

        // 缓存功能已移除，使用增量同步替代

        // 更新 cron
        $this->update_cron_schedule( $options );
        $this->update_log_cleanup_schedule($options);

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

            // 重新初始化日志系统
            Notion_Logger::init();

            // 缓存功能已移除，使用增量同步替代

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
        check_ajax_referer('notion_to_wordpress_nonce', 'nonce');

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('权限不足', 'notion-to-wordpress') ] );
            return;
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
                wp_send_json_error(['message' => $response->get_error_message()]);
                return;
            }
            
            wp_send_json_success(['message' => __('连接成功！数据库可访问。', 'notion-to-wordpress')]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('连接失败: ', 'notion-to-wordpress') . $e->getMessage()]);
        }
    }

    /**
     * 处理手动导入请求
     *
     * @since    1.0.5
     */
    public function handle_manual_import() {
        // 添加调试日志
        error_log('Notion to WordPress: handle_manual_import 开始执行');

        // 增加执行时间限制
        set_time_limit(300); // 5分钟

        // 增加内存限制
        ini_set('memory_limit', '256M');

        // 详细的nonce检查
        if (!isset($_POST['nonce'])) {
            error_log('Notion to WordPress: 手动导入缺少nonce参数');
            wp_send_json_error(['message' => __('缺少nonce参数', 'notion-to-wordpress')]);
            return;
        }

        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            error_log('Notion to WordPress: 手动导入nonce验证失败');
            wp_send_json_error(['message' => __('nonce验证失败', 'notion-to-wordpress')]);
            return;
        }

        error_log('Notion to WordPress: 手动导入nonce验证成功');

        if ( ! current_user_can( 'manage_options' ) ) {
            error_log('Notion to WordPress: 权限检查失败');
            wp_send_json_error( [ 'message' => __('权限不足', 'notion-to-wordpress') ] );
            return;
        }

        try {
            error_log('Notion to WordPress: 开始导入流程');
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
            error_log('Notion to WordPress: 创建API实例，API Key: ' . substr($api_key, 0, 10) . '...');
            $notion_api = new Notion_API( $api_key );

            error_log('Notion to WordPress: 创建导入协调器实例，Database ID: ' . $database_id);
            $notion_pages = new Notion_Import_Coordinator( $notion_api, $database_id, $field_mapping );
            $notion_pages->set_custom_field_mappings($custom_field_mappings);

            // 检查是否启用增量同步
            $incremental = isset($_POST['incremental']) ? (bool) $_POST['incremental'] : true;
            $check_deletions = isset($_POST['check_deletions']) ? (bool) $_POST['check_deletions'] : true;

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('手动同步参数 - 增量: ' . ($incremental ? 'yes' : 'no') . ', 检查删除: ' . ($check_deletions ? 'yes' : 'no'), 'Manual Sync');
            }

            // 执行导入
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('开始执行import_pages()', 'Manual Sync');
            }
            $result = $notion_pages->import_pages($check_deletions, $incremental);
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('import_pages()执行完成，结果: ' . print_r($result, true), 'Manual Sync');
            }

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
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log('捕获异常: ' . $e->getMessage(), 'Manual Sync');
                Notion_Logger::error_log('异常堆栈: ' . $e->getTraceAsString(), 'Manual Sync');
            }
            wp_send_json_error( [ 'message' => __('导入失败: ', 'notion-to-wordpress') . $e->getMessage() ] );
        }
    }



    /**
     * 获取统计信息
     *
     * @since    1.0.8
     */
    public function handle_get_stats() {
        // 添加错误日志记录
        error_log('Notion to WordPress: handle_get_stats 被调用');

        try {
            check_ajax_referer('notion_to_wordpress_nonce', 'nonce');
        } catch (Exception $e) {
            error_log('Notion to WordPress: Nonce 验证失败: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Nonce验证失败', 'notion-to-wordpress')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            error_log('Notion to WordPress: 用户权限不足');
            wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')]);
            return;
        }

        try {
            // 获取导入的文章数量
            $imported_count = $this->get_imported_posts_count();
            error_log('Notion to WordPress: 导入文章数量: ' . $imported_count);

            // 获取已发布的文章数量
            $published_count = $this->get_published_posts_count();
            error_log('Notion to WordPress: 已发布文章数量: ' . $published_count);

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
                'next_run' => $next_run
            ];

            error_log('Notion to WordPress: 统计信息获取成功: ' . json_encode($result));
            wp_send_json_success($result);

        } catch (Exception $e) {
            error_log('Notion to WordPress: 获取统计信息异常: ' . $e->getMessage());
            wp_send_json_error(['message' => __('获取统计信息失败: ', 'notion-to-wordpress') . $e->getMessage()]);
        } catch (Error $e) {
            error_log('Notion to WordPress: 获取统计信息错误: ' . $e->getMessage());
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
                error_log('Notion to WordPress: 数据库查询错误: ' . $wpdb->last_error);
                return 0;
            }

            return intval( $count ?: 0 );

        } catch (Exception $e) {
            error_log('Notion to WordPress: get_imported_posts_count 异常: ' . $e->getMessage());
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
                error_log('Notion to WordPress: 数据库查询错误: ' . $wpdb->last_error);
                return 0;
            }

            return intval( $count ?: 0 );

        } catch (Exception $e) {
            error_log('Notion to WordPress: get_published_posts_count 异常: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 处理清除日志请求
     *
     * @since    1.0.8
     */
    public function handle_clear_logs() {
        check_ajax_referer('notion_to_wordpress_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')]);
            return;
        }

        try {
            $success = Notion_Logger::clear_logs();
            
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
        check_ajax_referer('notion_to_wordpress_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')]);
        }

        $file = isset($_POST['file']) ? sanitize_text_field($_POST['file']) : '';
        if (empty($file)) {
            wp_send_json_error(['message' => __('未指定日志文件', 'notion-to-wordpress')]);
        }

        $content = Notion_Logger::get_log_content($file);

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
        check_ajax_referer('notion_to_wordpress_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')]);
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
        error_log('Notion to WordPress: handle_test_debug 被调用');

        try {
            // 先检查基本的POST数据
            error_log('Notion to WordPress: POST数据: ' . print_r($_POST, true));

            // 检查nonce
            if (!isset($_POST['nonce'])) {
                error_log('Notion to WordPress: 缺少nonce参数');
                wp_send_json_error(['message' => __('缺少nonce参数', 'notion-to-wordpress')]);
                return;
            }

            error_log('Notion to WordPress: 收到的nonce: ' . $_POST['nonce']);

            // 验证nonce
            if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
                error_log('Notion to WordPress: nonce验证失败');
                wp_send_json_error(['message' => __('nonce验证失败', 'notion-to-wordpress')]);
                return;
            }

            error_log('Notion to WordPress: nonce验证成功');

            if (!current_user_can('manage_options')) {
                error_log('Notion to WordPress: 权限检查失败');
                wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')]);
                return;
            }

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('权限检查成功', 'Debug Test');
            }

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

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('测试数据: ' . print_r($test_data, true), 'Debug Test');
            }
            wp_send_json_success(['message' => __('调试测试成功', 'notion-to-wordpress'), 'data' => $test_data]);

        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log('测试异常: ' . $e->getMessage(), 'Debug Test');
                Notion_Logger::error_log('异常堆栈: ' . $e->getTraceAsString(), 'Debug Test');
            }
            wp_send_json_error(['message' => __('测试失败: ', 'notion-to-wordpress') . $e->getMessage()]);
        } catch (Error $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log('测试错误: ' . $e->getMessage(), 'Debug Test');
                Notion_Logger::error_log('错误堆栈: ' . $e->getTraceAsString(), 'Debug Test');
            }
            wp_send_json_error(['message' => __('测试错误: ', 'notion-to-wordpress') . $e->getMessage()]);
        }
    }

    /**
     * 处理刷新性能统计的AJAX请求
     *
     * @since 2.0.0-beta.1
     */
    public function handle_refresh_performance_stats() {
        // 验证nonce - 使用统一的 nonce 名称
        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            wp_die('安全验证失败');
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
            return;
        }

        try {
            // 获取最新的内存使用情况
            $memory_usage = [];
            if (class_exists('Notion_Memory_Manager')) {
                $memory_usage = Notion_Memory_Manager::get_memory_usage();
            } else {
                // 备用方案
                $memory_usage = [
                    'current' => memory_get_usage(true),
                    'peak' => memory_get_peak_usage(true),
                    'limit' => wp_convert_hr_to_bytes(ini_get('memory_limit')),
                    'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                    'limit_mb' => round(wp_convert_hr_to_bytes(ini_get('memory_limit')) / 1024 / 1024, 2),
                    'usage_percentage' => round((memory_get_usage(true) / wp_convert_hr_to_bytes(ini_get('memory_limit'))) * 100, 2)
                ];
            }

            // 获取其他性能数据
            $performance_data = [
                'memory_usage' => $memory_usage,
                'php_version' => PHP_VERSION,
                'wordpress_version' => get_bloginfo('version'),
                'plugin_version' => $this->version,
                'timestamp' => current_time('mysql')
            ];

            wp_send_json_success($performance_data);

        } catch (Exception $e) {
            wp_send_json_error('刷新统计失败: ' . $e->getMessage());
        }
    }

    /**
     * 处理重置性能统计的AJAX请求
     *
     * @since 2.0.0-beta.1
     */
    public function handle_reset_performance_stats() {
        // 验证nonce - 使用统一的 nonce 名称
        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            wp_die('安全验证失败');
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
            return;
        }

        try {
            // 重置性能统计数据
            // 这里可以清理缓存、重置计数器等

            // 强制垃圾回收
            if (class_exists('Notion_Memory_Manager')) {
                Notion_Memory_Manager::force_garbage_collection();
            } else if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // 清理WordPress对象缓存
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }

            // 记录重置操作
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('性能统计已重置', 'Performance Reset');
            }

            wp_send_json_success('性能统计已重置');

        } catch (Exception $e) {
            wp_send_json_error('重置统计失败: ' . $e->getMessage());
        }
    }

    // ==================== 数据库索引管理AJAX处理方法 ====================

    /**
     * 处理创建数据库索引的AJAX请求
     *
     * @since 2.0.0-beta.1
     */
    public function handle_create_database_indexes() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            wp_send_json_error(['message' => '安全验证失败']);
            return;
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足']);
            return;
        }

        try {
            // 调用数据库助手创建索引
            $result = Notion_Database_Helper::create_performance_indexes();

            if ($result['success']) {
                $message = sprintf(
                    '索引创建成功！创建了%d个索引，性能提升%.1f%%',
                    count($result['created_indexes']),
                    $result['performance_improvement']
                );

                wp_send_json_success([
                    'message' => $message,
                    'data' => $result
                ]);
            } else {
                wp_send_json_error([
                    'message' => '索引创建失败: ' . implode(', ', $result['errors']),
                    'data' => $result
                ]);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => '创建索引时发生异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 处理获取索引状态的AJAX请求
     *
     * @since 2.0.0-beta.1
     */
    public function handle_get_index_status() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            wp_send_json_error(['message' => '安全验证失败']);
            return;
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足']);
            return;
        }

        try {
            // 获取索引状态
            $status = Notion_Database_Helper::get_index_status();

            // 获取优化建议
            $suggestions = Notion_Database_Helper::get_optimization_suggestions();

            wp_send_json_success([
                'status' => $status,
                'suggestions' => $suggestions,
                'message' => '索引状态获取成功'
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => '获取索引状态时发生异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 处理删除数据库索引的AJAX请求（用于测试或回退）
     *
     * @since 2.0.0-beta.1
     */
    public function handle_remove_database_indexes() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            wp_send_json_error(['message' => '安全验证失败']);
            return;
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足']);
            return;
        }

        try {
            // 调用数据库助手删除索引
            $result = Notion_Database_Helper::remove_performance_indexes();

            if ($result['success']) {
                $message = sprintf(
                    '索引删除成功！删除了%d个索引',
                    count($result['removed_indexes'])
                );

                wp_send_json_success([
                    'message' => $message,
                    'data' => $result
                ]);
            } else {
                wp_send_json_error([
                    'message' => '索引删除失败: ' . implode(', ', $result['errors']),
                    'data' => $result
                ]);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => '删除索引时发生异常: ' . $e->getMessage()]);
        }
    }

    // ==================== 队列管理AJAX处理方法 ====================

    /**
     * 处理获取队列状态的AJAX请求
     *
     * @since 2.0.0-beta.1
     */
    public function handle_get_queue_status() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            wp_send_json_error(['message' => '安全验证失败']);
            return;
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足']);
            return;
        }

        try {
            if (class_exists('Notion_Queue_Manager')) {
                $queue_status = Notion_Queue_Manager::get_queue_status();

                wp_send_json_success([
                    'status' => $queue_status,
                    'message' => '队列状态获取成功'
                ]);
            } else {
                // 提供默认队列状态而不是错误（字段名匹配JavaScript期望）
                $default_queue_status = [
                    'total_tasks' => 0,
                    'pending' => 0,
                    'processing' => 0,
                    'completed' => 0,
                    'failed' => 0,
                    'retrying' => 0,
                    'queue_size' => 0,
                    'is_processing' => false,
                    'last_processed' => '',
                    'next_scheduled' => ''
                ];

                wp_send_json_success([
                    'status' => $default_queue_status,
                    'message' => '队列管理器不可用，返回默认状态'
                ]);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => '获取队列状态时发生异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 处理取消队列任务的AJAX请求
     *
     * @since 2.0.0-beta.1
     */
    public function handle_cancel_queue_task() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            wp_send_json_error(['message' => '安全验证失败']);
            return;
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足']);
            return;
        }

        $task_id = sanitize_text_field($_POST['task_id'] ?? '');

        if (empty($task_id)) {
            wp_send_json_error(['message' => '任务ID不能为空']);
            return;
        }

        try {
            if (class_exists('Notion_Queue_Manager')) {
                $result = Notion_Queue_Manager::cancel_task($task_id);

                if ($result) {
                    wp_send_json_success(['message' => '任务已成功取消']);
                } else {
                    wp_send_json_error(['message' => '任务取消失败，可能任务不存在或已完成']);
                }
            } else {
                wp_send_json_error(['message' => '队列管理器不可用']);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => '取消任务时发生异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 处理清理队列的AJAX请求
     *
     * @since 2.0.0-beta.1
     */
    public function handle_cleanup_queue() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            wp_send_json_error(['message' => '安全验证失败']);
            return;
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足']);
            return;
        }

        try {
            if (class_exists('Notion_Queue_Manager')) {
                $cleaned_count = Notion_Queue_Manager::cleanup_completed_tasks();

                wp_send_json_success([
                    'message' => "已清理 {$cleaned_count} 个已完成的任务",
                    'cleaned_count' => $cleaned_count
                ]);
            } else {
                wp_send_json_error(['message' => '队列管理器不可用']);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => '清理队列时发生异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 处理获取异步状态的AJAX请求
     *
     * @since 2.0.0-beta.1
     */
    public function handle_get_async_status() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            wp_send_json_error(['message' => '安全验证失败']);
            return;
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足']);
            return;
        }

        try {
            if (class_exists('Notion_Async_Processor')) {
                $async_status = Notion_Async_Processor::get_async_status();

                wp_send_json_success([
                    'status' => $async_status,
                    'message' => '异步状态获取成功'
                ]);
            } else {
                // 提供默认状态而不是错误
                $default_status = [
                    'status' => 'idle',
                    'operation' => '',
                    'started_at' => '',
                    'updated_at' => '',
                    'data_count' => 0,
                    'progress' => 0,
                    'details' => []
                ];

                wp_send_json_success([
                    'status' => $default_status,
                    'message' => '异步处理器不可用，返回默认状态'
                ]);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => '获取异步状态时发生异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 处理控制异步操作的AJAX请求
     *
     * @since 2.0.0-beta.1
     */
    public function handle_control_async_operation() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            wp_send_json_error(['message' => '安全验证失败']);
            return;
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足']);
            return;
        }

        $action = sanitize_text_field($_POST['action_type'] ?? '');

        if (empty($action)) {
            wp_send_json_error(['message' => '操作类型不能为空']);
            return;
        }

        try {
            if (class_exists('Notion_Async_Processor')) {
                $result = false;
                $message = '';

                switch ($action) {
                    case 'pause':
                        $result = Notion_Async_Processor::pause_async_operation();
                        $message = $result ? '异步操作已暂停' : '暂停失败，可能没有运行中的操作';
                        break;

                    case 'resume':
                        $result = Notion_Async_Processor::resume_async_operation();
                        $message = $result ? '异步操作已恢复' : '恢复失败，可能没有暂停的操作';
                        break;

                    case 'stop':
                        $result = Notion_Async_Processor::stop_async_operation();
                        $message = $result ? '异步操作已停止' : '停止失败，可能没有运行中的操作';
                        break;

                    default:
                        wp_send_json_error(['message' => '未知的操作类型']);
                        return;
                }

                if ($result) {
                    wp_send_json_success(['message' => $message]);
                } else {
                    wp_send_json_error(['message' => $message]);
                }
            } else {
                wp_send_json_error(['message' => '异步处理器不可用']);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => '控制异步操作时发生异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 处理智能推荐AJAX请求
     *
     * @since    2.0.0-beta.1
     */
    public function handle_smart_recommendations(): void {
        // 验证nonce - 使用统一的 nonce 名称
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'notion_to_wordpress_nonce')) {
            wp_send_json_error('安全验证失败');
            return;
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
            return;
        }

        try {
            if (class_exists('Notion_Config_Simplifier')) {
                $recommendations = Notion_Config_Simplifier::detect_optimal_config();
                wp_send_json_success($recommendations);
            } else {
                wp_send_json_error('配置简化器不可用');
            }
        } catch (Exception $e) {
            wp_send_json_error('获取推荐配置失败: ' . $e->getMessage());
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