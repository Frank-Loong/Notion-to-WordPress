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
 * @version    1.8.3-test.2
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
     * Notion页面处理程序实例
     *
     * @since    1.0.5
     * @access   private
     * @var      Notion_Pages
     */
    private Notion_Pages $notion_pages;

    /**
     * 性能配置默认值
     *
     * @since    1.8.1
     * @access   private
     * @var      array
     */
    private array $performance_config_defaults = [
        // 并发管理器配置
        'concurrent_max_requests' => 25,
        'concurrent_adaptive_enabled' => true,
        'concurrent_target_response_time' => 2000,
        'concurrent_adjustment_threshold' => 0.1,

        // 缓存配置
        'cache_memory_ttl' => 1800,
        'cache_transient_ttl' => 3600,
        'cache_preload_enabled' => true,
        'cache_preload_max_size' => 100,

        // 分页处理配置
        'pagination_enabled' => true,
        'pagination_default_size' => 20,
        'pagination_max_size' => 50,
        'pagination_memory_threshold' => 70,

        // 网络配置
        'network_base_timeout' => 8,
        'network_connect_timeout' => 3,
        'network_keepalive_enabled' => true,
        'network_compression_enabled' => true,
        'network_adaptive_timeout' => true,

        // 内存管理配置
        'memory_warning_threshold' => 70,
        'memory_critical_threshold' => 85,
        'memory_emergency_threshold' => 95,
        'memory_monitoring_enabled' => true
    ];

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

        // 添加性能监控子菜单
        add_submenu_page(
            $this->plugin_name,
            __('性能监控', 'notion-to-wordpress'),
            __('性能监控', 'notion-to-wordpress'),
            'manage_options',
            $this->plugin_name . '-performance',
            array($this, 'display_performance_page')
        );

        // 添加性能配置子菜单
        add_submenu_page(
            $this->plugin_name,
            __('性能配置', 'notion-to-wordpress'),
            __('性能配置', 'notion-to-wordpress'),
            'manage_options',
            $this->plugin_name . '-performance-config',
            array($this, 'display_performance_config_page')
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
        $options['debug_level'] = isset( $_POST['debug_level'] ) ? intval( $_POST['debug_level'] ) : Notion_To_WordPress_Helper::DEBUG_LEVEL_ERROR;

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

        // 缓存设置
        $options['enable_transient_cache'] = isset( $_POST['enable_transient_cache'] ) ? 1 : 0;
        $options['transient_cache_ttl'] = isset( $_POST['transient_cache_ttl'] ) ? max( 300, intval( $_POST['transient_cache_ttl'] ) ) : 3600; // 最小5分钟，默认1小时
        $options['memory_cache_ttl'] = isset( $_POST['memory_cache_ttl'] ) ? max( 60, intval( $_POST['memory_cache_ttl'] ) ) : 300; // 最小1分钟，默认5分钟

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

    public function handle_settings_form() {
        $this->validate_settings_request();

        $current_options = get_option( 'notion_to_wordpress_options', [] );
        $options         = $this->parse_settings( $current_options );

        update_option( 'notion_to_wordpress_options', $options );

        // 重新初始化调试级别
        Notion_To_WordPress_Helper::init();

        // 应用缓存配置
        $this->apply_cache_settings( $options );

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

            // 重新初始化调试级别
            Notion_To_WordPress_Helper::init();

            // 应用缓存配置
            $this->apply_cache_settings($options);

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

            error_log('Notion to WordPress: 创建Pages实例，Database ID: ' . $database_id);
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
        error_log('Notion to WordPress: handle_refresh_all 开始执行');

        // 增加执行时间限制
        set_time_limit(300); // 5分钟

        // 增加内存限制
        ini_set('memory_limit', '256M');

        // 详细的nonce检查
        if (!isset($_POST['nonce'])) {
            error_log('Notion to WordPress: 刷新缺少nonce参数');
            wp_send_json_error(['message' => __('缺少nonce参数', 'notion-to-wordpress')]);
            return;
        }

        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            error_log('Notion to WordPress: 刷新nonce验证失败');
            wp_send_json_error(['message' => __('nonce验证失败', 'notion-to-wordpress')]);
            return;
        }

        error_log('Notion to WordPress: 刷新nonce验证成功');

        if (!current_user_can('manage_options')) {
            error_log('Notion to WordPress: 刷新权限检查失败');
            wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')]);
            return;
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
        check_ajax_referer('notion_to_wordpress_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')]);
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
     * 应用缓存设置（增强版）
     *
     * @since    1.8.1
     * @param    array    $options    插件选项
     */
    private function apply_cache_settings(array $options): void {
        // 设置transient缓存TTL
        if (isset($options['transient_cache_ttl'])) {
            Notion_API::set_transient_cache_ttl((int)$options['transient_cache_ttl']);
        }

        // 应用智能缓存配置
        if (isset($options['smart_cache_enabled'])) {
            $this->apply_smart_cache_config($options);
        }

        // 应用缓存预热配置
        if (isset($options['cache_preload_enabled'])) {
            $this->apply_cache_preload_config($options);
        }

        // 如果禁用了transient缓存，清除所有transient缓存
        if (empty($options['enable_transient_cache'])) {
            Notion_API::clear_transient_cache();
            Notion_To_WordPress_Helper::debug_log(
                '已禁用transient缓存，清除所有transient缓存',
                'Cache Config'
            );
        }

        Notion_To_WordPress_Helper::debug_log(
            '增强缓存设置已应用: transient_cache=' . ($options['enable_transient_cache'] ? 'enabled' : 'disabled') .
            ', smart_cache=' . (($options['smart_cache_enabled'] ?? false) ? 'enabled' : 'disabled') .
            ', preload=' . (($options['cache_preload_enabled'] ?? false) ? 'enabled' : 'disabled') .
            ', ttl=' . ($options['transient_cache_ttl'] ?? 7200) . 's',
            'Enhanced Cache Config'
        );
    }

    /**
     * 应用智能缓存配置
     *
     * @since    1.8.1
     * @param    array    $options    插件选项
     */
    private function apply_smart_cache_config(array $options): void {
        $smart_cache_config = [
            'page_content_ttl' => (int)($options['page_content_cache_ttl'] ?? 3600),
            'page_details_ttl' => (int)($options['page_details_cache_ttl'] ?? 1800),
            'database_pages_ttl' => (int)($options['database_pages_cache_ttl'] ?? 1800),
            'database_info_ttl' => (int)($options['database_info_cache_ttl'] ?? 7200),
            'image_urls_ttl' => (int)($options['image_urls_cache_ttl'] ?? 86400),
            'adaptive_ttl_enabled' => !empty($options['adaptive_cache_ttl'])
        ];

        // 这里可以添加设置智能缓存配置的API调用
        // Notion_API::set_smart_cache_config($smart_cache_config);

        Notion_To_WordPress_Helper::debug_log(
            '智能缓存配置已应用: ' . json_encode($smart_cache_config),
            'Smart Cache Config'
        );
    }

    /**
     * 应用缓存预热配置
     *
     * @since    1.8.1
     * @param    array    $options    插件选项
     */
    private function apply_cache_preload_config(array $options): void {
        $preload_config = [
            'enabled' => !empty($options['cache_preload_enabled']),
            'max_preload_items' => (int)($options['cache_preload_max_items'] ?? 100),
            'preload_on_startup' => !empty($options['cache_preload_on_startup']),
            'smart_preload' => !empty($options['cache_smart_preload']),
            'preload_popular_pages' => !empty($options['cache_preload_popular_pages'])
        ];

        // 这里可以添加设置缓存预热配置的API调用
        // Notion_API::set_cache_preload_config($preload_config);

        Notion_To_WordPress_Helper::debug_log(
            '缓存预热配置已应用: ' . json_encode($preload_config),
            'Cache Preload Config'
        );
    }

    /**
     * 获取缓存统计信息的AJAX处理器
     *
     * @since    1.8.1
     */
    public function handle_get_cache_stats() {
        check_ajax_referer('notion_to_wordpress_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')]);
            return;
        }

        try {
            // 获取增强的缓存统计信息
            $enhanced_cache_stats = Notion_API::get_enhanced_cache_stats();

            // 添加数据库查询缓存统计
            $db_cache_stats = Notion_Pages::get_cache_performance_stats();

            // 合并所有缓存统计
            $combined_stats = array_merge($enhanced_cache_stats, [
                'database_cache' => $db_cache_stats,
                'last_updated' => current_time('mysql'),
                'cache_health_score' => $this->calculate_cache_health_score($enhanced_cache_stats, $db_cache_stats)
            ]);

            wp_send_json_success([
                'message' => __('增强缓存统计获取成功', 'notion-to-wordpress'),
                'stats' => $combined_stats
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('获取增强缓存统计失败: ', 'notion-to-wordpress') . $e->getMessage()]);
        }
    }

    /**
     * 计算缓存健康评分
     *
     * @since    1.8.1
     * @param    array    $api_cache_stats    API缓存统计
     * @param    array    $db_cache_stats     数据库缓存统计
     * @return   int                          健康评分（0-100）
     */
    private function calculate_cache_health_score(array $api_cache_stats, array $db_cache_stats): int {
        $score = 0;

        // API缓存命中率评分（40%权重）
        $api_hit_rate = (float)str_replace('%', '', $api_cache_stats['overall_hit_rate'] ?? '0');
        $score += min(40, $api_hit_rate * 0.4);

        // 数据库缓存命中率评分（40%权重）
        $db_hit_rate = (float)str_replace('%', '', $db_cache_stats['cache_hit_rate'] ?? '0');
        $score += min(40, $db_hit_rate * 0.4);

        // 缓存操作效率评分（20%权重）
        $total_operations = $api_cache_stats['total_cache_operations'] ?? 0;
        $preload_operations = $api_cache_stats['cache_preloads'] ?? 0;
        $smart_updates = $api_cache_stats['smart_cache_updates'] ?? 0;

        if ($total_operations > 0) {
            $efficiency_ratio = ($preload_operations + $smart_updates) / $total_operations;
            $score += min(20, $efficiency_ratio * 100 * 0.2);
        }

        return min(100, max(0, (int)$score));
    }

    /**
     * 清除缓存的AJAX处理器
     *
     * @since    1.8.1
     */
    public function handle_clear_cache() {
        check_ajax_referer('notion_to_wordpress_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'notion-to-wordpress')]);
            return;
        }

        try {
            $cache_type = isset($_POST['cache_type']) ? sanitize_text_field($_POST['cache_type']) : 'all';

            switch ($cache_type) {
                case 'memory':
                    Notion_API::clear_page_cache();
                    $message = __('内存缓存已清除', 'notion-to-wordpress');
                    break;
                case 'transient':
                    Notion_API::clear_transient_cache();
                    $message = __('Transient缓存已清除', 'notion-to-wordpress');
                    break;
                case 'all':
                default:
                    Notion_API::clear_page_cache();
                    Notion_API::clear_transient_cache();
                    $message = __('所有缓存已清除', 'notion-to-wordpress');
                    break;
            }

            wp_send_json_success(['message' => $message]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('清除缓存失败: ', 'notion-to-wordpress') . $e->getMessage()]);
        }
    }

    /**
     * 显示性能监控页面
     *
     * @since    1.8.1
     */
    public function display_performance_page() {
        // 处理AJAX请求
        if (isset($_POST['action']) && $_POST['action'] === 'refresh_performance_data') {
            $this->handle_performance_ajax();
            return;
        }

        // 获取性能数据
        $performance_data = $this->get_comprehensive_performance_data();

        // 显示性能监控页面
        include_once plugin_dir_path(__FILE__) . 'partials/performance-monitor-display.php';
    }

    /**
     * 显示性能配置页面
     *
     * @since    1.8.1
     */
    public function display_performance_config_page() {
        // 处理配置保存
        if (isset($_POST['save_performance_config'])) {
            $this->save_performance_config();
        }

        // 获取当前配置
        $current_config = $this->get_performance_config();

        // 显示性能配置页面
        include_once plugin_dir_path(__FILE__) . 'partials/performance-config-display.php';
    }

    /**
     * 获取综合性能数据
     *
     * @since    1.8.1
     * @return   array    性能数据
     */
    private function get_comprehensive_performance_data(): array {
        // 获取各模块的性能统计
        $concurrent_stats = [];
        $api_stats = [];
        $memory_stats = [];
        $pagination_stats = [];

        try {
            // 尝试获取并发管理器统计
            if ($this->notion_api && method_exists($this->notion_api, 'get_concurrent_manager')) {
                $concurrent_manager = $this->notion_api->get_concurrent_manager();
                if ($concurrent_manager) {
                    $concurrent_stats = $concurrent_manager->get_comprehensive_performance_report();
                }
            }

            // 获取API统计
            if (class_exists('Notion_API')) {
                $api_stats = Notion_API::get_comprehensive_api_stats();
            }

            // 获取内存统计
            if (class_exists('Notion_To_WordPress_Helper')) {
                $memory_stats = Notion_To_WordPress_Helper::get_memory_stats();
            }

            // 获取分页统计
            if (class_exists('Notion_Pages')) {
                $pagination_stats = Notion_Pages::get_pagination_stats();
            }

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log(
                '获取性能数据时发生错误: ' . $e->getMessage(),
                'Performance Monitor'
            );
        }

        return [
            'concurrent' => $concurrent_stats,
            'api' => $api_stats,
            'memory' => $memory_stats,
            'pagination' => $pagination_stats,
            'timestamp' => time(),
            'formatted_time' => current_time('Y-m-d H:i:s')
        ];
    }

    /**
     * 获取性能配置
     *
     * @since    1.8.1
     * @return   array    性能配置
     */
    private function get_performance_config(): array {
        $saved_config = get_option('notion_to_wordpress_performance_config', []);
        return array_merge($this->performance_config_defaults, $saved_config);
    }

    /**
     * 保存性能配置
     *
     * @since    1.8.1
     */
    private function save_performance_config(): void {
        // 验证nonce
        if (!isset($_POST['performance_config_nonce']) ||
            !wp_verify_nonce($_POST['performance_config_nonce'], 'save_performance_config')) {
            wp_die(__('安全验证失败', 'notion-to-wordpress'));
        }

        // 验证权限
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'notion-to-wordpress'));
        }

        $config = [];

        // 处理并发管理器配置
        $config['concurrent_max_requests'] = intval($_POST['concurrent_max_requests'] ?? 25);
        $config['concurrent_adaptive_enabled'] = isset($_POST['concurrent_adaptive_enabled']);
        $config['concurrent_target_response_time'] = intval($_POST['concurrent_target_response_time'] ?? 2000);
        $config['concurrent_adjustment_threshold'] = floatval($_POST['concurrent_adjustment_threshold'] ?? 0.1);

        // 处理缓存配置
        $config['cache_memory_ttl'] = intval($_POST['cache_memory_ttl'] ?? 1800);
        $config['cache_transient_ttl'] = intval($_POST['cache_transient_ttl'] ?? 3600);
        $config['cache_preload_enabled'] = isset($_POST['cache_preload_enabled']);
        $config['cache_preload_max_size'] = intval($_POST['cache_preload_max_size'] ?? 100);

        // 处理分页配置
        $config['pagination_enabled'] = isset($_POST['pagination_enabled']);
        $config['pagination_default_size'] = intval($_POST['pagination_default_size'] ?? 20);
        $config['pagination_max_size'] = intval($_POST['pagination_max_size'] ?? 50);
        $config['pagination_memory_threshold'] = intval($_POST['pagination_memory_threshold'] ?? 70);

        // 处理网络配置
        $config['network_base_timeout'] = intval($_POST['network_base_timeout'] ?? 8);
        $config['network_connect_timeout'] = intval($_POST['network_connect_timeout'] ?? 3);
        $config['network_keepalive_enabled'] = isset($_POST['network_keepalive_enabled']);
        $config['network_compression_enabled'] = isset($_POST['network_compression_enabled']);
        $config['network_adaptive_timeout'] = isset($_POST['network_adaptive_timeout']);

        // 处理内存管理配置
        $config['memory_warning_threshold'] = intval($_POST['memory_warning_threshold'] ?? 70);
        $config['memory_critical_threshold'] = intval($_POST['memory_critical_threshold'] ?? 85);
        $config['memory_emergency_threshold'] = intval($_POST['memory_emergency_threshold'] ?? 95);
        $config['memory_monitoring_enabled'] = isset($_POST['memory_monitoring_enabled']);

        // 保存配置
        update_option('notion_to_wordpress_performance_config', $config);

        // 应用配置到各个模块
        $this->apply_performance_config($config);

        // 显示成功消息
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 __('性能配置已保存并应用', 'notion-to-wordpress') . '</p></div>';
        });
    }

    /**
     * 应用性能配置到各个模块
     *
     * @since    1.8.1
     * @param    array    $config    性能配置
     */
    private function apply_performance_config(array $config): void {
        try {
            // 应用并发管理器配置
            if ($this->notion_api && method_exists($this->notion_api, 'get_concurrent_manager')) {
                $concurrent_manager = $this->notion_api->get_concurrent_manager();
                if ($concurrent_manager) {
                    $concurrent_manager->set_max_concurrent($config['concurrent_max_requests']);
                    $concurrent_manager->set_adaptive_config([
                        'enabled' => $config['concurrent_adaptive_enabled'],
                        'target_response_time' => $config['concurrent_target_response_time'],
                        'adjustment_threshold' => $config['concurrent_adjustment_threshold']
                    ]);
                    $concurrent_manager->set_network_config([
                        'base_timeout' => $config['network_base_timeout'],
                        'base_connect_timeout' => $config['network_connect_timeout'],
                        'enable_keepalive' => $config['network_keepalive_enabled'],
                        'enable_compression' => $config['network_compression_enabled'],
                        'adaptive_timeout' => $config['network_adaptive_timeout']
                    ]);
                }
            }

            // 应用API缓存配置
            if (class_exists('Notion_API')) {
                Notion_API::set_cache_config([
                    'memory_ttl' => $config['cache_memory_ttl'],
                    'transient_ttl' => $config['cache_transient_ttl'],
                    'preload_enabled' => $config['cache_preload_enabled'],
                    'preload_max_size' => $config['cache_preload_max_size']
                ]);
            }

            // 应用内存管理配置
            if (class_exists('Notion_To_WordPress_Helper')) {
                Notion_To_WordPress_Helper::set_resource_config([
                    'enable_memory_monitoring' => $config['memory_monitoring_enabled'],
                    'warning_threshold' => $config['memory_warning_threshold'] / 100,
                    'critical_threshold' => $config['memory_critical_threshold'] / 100,
                    'emergency_threshold' => $config['memory_emergency_threshold'] / 100
                ]);
            }

            Notion_To_WordPress_Helper::info_log(
                '性能配置已应用到所有模块',
                'Performance Config'
            );

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log(
                '应用性能配置时发生错误: ' . $e->getMessage(),
                'Performance Config'
            );
        }
    }

    /**
     * 处理性能监控AJAX请求
     *
     * @since    1.8.1
     */
    private function handle_performance_ajax(): void {
        // 验证nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'performance_ajax')) {
            wp_die(json_encode(['error' => '安全验证失败']));
        }

        // 验证权限
        if (!current_user_can('manage_options')) {
            wp_die(json_encode(['error' => '权限不足']));
        }

        $action_type = $_POST['action_type'] ?? '';

        switch ($action_type) {
            case 'get_stats':
                $data = $this->get_comprehensive_performance_data();
                wp_die(json_encode(['success' => true, 'data' => $data]));
                break;

            case 'reset_stats':
                $this->reset_all_performance_stats();
                wp_die(json_encode(['success' => true, 'message' => '统计数据已重置']));
                break;

            case 'run_performance_test':
                $test_results = $this->run_performance_test();
                wp_die(json_encode(['success' => true, 'data' => $test_results]));
                break;

            default:
                wp_die(json_encode(['error' => '未知的操作类型']));
        }
    }

    /**
     * 重置所有性能统计
     *
     * @since    1.8.1
     */
    private function reset_all_performance_stats(): void {
        try {
            // 重置并发管理器统计
            if ($this->notion_api && method_exists($this->notion_api, 'get_concurrent_manager')) {
                $concurrent_manager = $this->notion_api->get_concurrent_manager();
                if ($concurrent_manager) {
                    $concurrent_manager->reset_stats();
                    $concurrent_manager->reset_network_stats();
                }
            }

            // 重置API统计
            if (class_exists('Notion_API')) {
                Notion_API::reset_cache_stats();
                Notion_API::reset_request_merge_stats();
            }

            // 重置内存统计
            if (class_exists('Notion_To_WordPress_Helper')) {
                Notion_To_WordPress_Helper::reset_memory_stats();
            }

            // 重置分页统计
            if (class_exists('Notion_Pages')) {
                Notion_Pages::reset_pagination_stats();
                Notion_Pages::reset_image_download_stats();
            }

            Notion_To_WordPress_Helper::info_log(
                '所有性能统计已重置',
                'Performance Reset'
            );

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log(
                '重置性能统计时发生错误: ' . $e->getMessage(),
                'Performance Reset'
            );
        }
    }

    /**
     * 运行性能测试
     *
     * @since    1.8.1
     * @return   array    测试结果
     */
    private function run_performance_test(): array {
        $test_start_time = Notion_To_WordPress_Helper::start_performance_timer('performance_test');

        $test_results = [
            'test_timestamp' => time(),
            'test_duration' => 0,
            'api_test' => [],
            'memory_test' => [],
            'concurrent_test' => [],
            'overall_score' => 0,
            'recommendations' => []
        ];

        try {
            // API响应时间测试
            $api_test_start = microtime(true);
            if ($this->notion_api) {
                // 测试基本API连接
                $test_response = $this->notion_api->test_connection();
                $api_response_time = (microtime(true) - $api_test_start) * 1000;

                $test_results['api_test'] = [
                    'connection_success' => !is_wp_error($test_response),
                    'response_time' => round($api_response_time, 2),
                    'status' => $api_response_time < 1000 ? 'excellent' :
                               ($api_response_time < 2000 ? 'good' : 'poor')
                ];
            }

            // 内存使用测试
            $memory_before = memory_get_usage(true);
            $memory_peak_before = memory_get_peak_usage(true);

            // 模拟一些内存操作
            $test_data = array_fill(0, 1000, str_repeat('test', 100));
            unset($test_data);

            $memory_after = memory_get_usage(true);
            $memory_peak_after = memory_get_peak_usage(true);

            $test_results['memory_test'] = [
                'memory_before' => Notion_To_WordPress_Helper::format_bytes($memory_before),
                'memory_after' => Notion_To_WordPress_Helper::format_bytes($memory_after),
                'memory_used' => Notion_To_WordPress_Helper::format_bytes($memory_after - $memory_before),
                'peak_increase' => Notion_To_WordPress_Helper::format_bytes($memory_peak_after - $memory_peak_before),
                'memory_efficiency' => $memory_after <= $memory_before * 1.1 ? 'good' : 'needs_improvement'
            ];

            // 并发性能测试
            if ($this->notion_api && method_exists($this->notion_api, 'get_concurrent_manager')) {
                $concurrent_manager = $this->notion_api->get_concurrent_manager();
                if ($concurrent_manager) {
                    $concurrent_stats = $concurrent_manager->get_stats();
                    $network_stats = $concurrent_manager->get_network_stats();

                    $test_results['concurrent_test'] = [
                        'max_concurrent' => $concurrent_stats['max_concurrent'] ?? 0,
                        'current_concurrent' => $concurrent_stats['current_concurrent'] ?? 0,
                        'success_rate' => $concurrent_stats['success_rate'] ?? 0,
                        'avg_response_time' => $concurrent_stats['average_response_time'] ?? 0,
                        'network_quality' => $network_stats['network_quality_score'] ?? 0,
                        'performance_grade' => $network_stats['performance_grade'] ?? 'N/A'
                    ];
                }
            }

            // 计算综合评分
            $api_score = isset($test_results['api_test']['response_time']) ?
                        max(0, 100 - ($test_results['api_test']['response_time'] / 20)) : 50;
            $memory_score = $test_results['memory_test']['memory_efficiency'] === 'good' ? 90 : 60;
            $concurrent_score = $test_results['concurrent_test']['network_quality'] ?? 50;

            $test_results['overall_score'] = round(($api_score + $memory_score + $concurrent_score) / 3, 2);

            // 生成建议
            $test_results['recommendations'] = $this->generate_test_recommendations($test_results);

        } catch (Exception $e) {
            $test_results['error'] = $e->getMessage();
            Notion_To_WordPress_Helper::error_log(
                '性能测试时发生错误: ' . $e->getMessage(),
                'Performance Test'
            );
        }

        $test_results['test_duration'] = Notion_To_WordPress_Helper::end_performance_timer($test_start_time, 'performance_test');

        return $test_results;
    }

    /**
     * 生成测试建议
     *
     * @since    1.8.1
     * @param    array    $test_results    测试结果
     * @return   array                     建议数组
     */
    private function generate_test_recommendations(array $test_results): array {
        $recommendations = [];

        // API性能建议
        if (isset($test_results['api_test']['response_time'])) {
            $response_time = $test_results['api_test']['response_time'];
            if ($response_time > 2000) {
                $recommendations[] = 'API响应时间较慢，建议检查网络连接或启用缓存';
            } elseif ($response_time > 1000) {
                $recommendations[] = 'API响应时间一般，可考虑优化网络配置';
            }
        }

        // 内存使用建议
        if ($test_results['memory_test']['memory_efficiency'] === 'needs_improvement') {
            $recommendations[] = '内存使用效率需要改善，建议启用内存监控和分页处理';
        }

        // 并发性能建议
        if (isset($test_results['concurrent_test']['network_quality'])) {
            $network_quality = $test_results['concurrent_test']['network_quality'];
            if ($network_quality < 70) {
                $recommendations[] = '网络质量较差，建议优化网络配置或增加重试机制';
            }
        }

        // 综合评分建议
        $overall_score = $test_results['overall_score'];
        if ($overall_score < 60) {
            $recommendations[] = '整体性能需要改善，建议查看详细配置并进行优化';
        } elseif ($overall_score < 80) {
            $recommendations[] = '性能表现良好，可进一步微调配置以获得更佳效果';
        } else {
            $recommendations[] = '性能表现优秀，继续保持当前配置';
        }

        return $recommendations;
    }

    /**
     * 公共AJAX处理方法（用于WordPress钩子）
     *
     * @since    1.8.1
     */
    public function handle_performance_ajax_public() {
        $this->handle_performance_ajax();
    }

    /**
     * 注册与管理区域功能相关的所有钩子
     *
     * @since    1.0.5
     * @access   private
     */
    // 注意：钩子注册在主插件类的define_admin_hooks方法中处理
}