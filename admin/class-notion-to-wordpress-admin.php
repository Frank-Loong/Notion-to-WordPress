<?php
declare(strict_types=1);

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
                'page_refreshing' => __('页面即将刷新以应用语言设置...', 'notion-to-wordpress'),
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
        add_menu_page(
            __('Notion to WordPress', 'notion-to-wordpress'),
            __('Notion to WordPress', 'notion-to-wordpress'),
            'manage_options',
            $this->plugin_name,
            array($this, 'display_plugin_setup_page'),
            'dashicons-database-import', // 统一使用database-import图标
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
            wp_send_json_error( [ 'message' => '权限不足' ] );
            return;
        }

        $api_key     = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
        $database_id = isset( $_POST['database_id'] ) ? sanitize_text_field( $_POST['database_id'] ) : '';
        
        if (empty($api_key) || empty($database_id)) {
            wp_send_json_error(['message' => '请输入API密钥和数据库ID']);
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
            
            wp_send_json_success(['message' => '连接成功！数据库可访问。']);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => '连接失败: ' . $e->getMessage()]);
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
            wp_send_json_error(['message' => '缺少nonce参数']);
            return;
        }

        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            error_log('Notion to WordPress: 手动导入nonce验证失败');
            wp_send_json_error(['message' => 'nonce验证失败']);
            return;
        }

        error_log('Notion to WordPress: 手动导入nonce验证成功');

        if ( ! current_user_can( 'manage_options' ) ) {
            error_log('Notion to WordPress: 权限检查失败');
            wp_send_json_error( [ 'message' => '权限不足' ] );
            return;
        }

        try {
            error_log('Notion to WordPress: 开始导入流程');
            // 获取选项
            $options = get_option( 'notion_to_wordpress_options', [] );
            
            // 检查必要的设置
            if ( empty( $options['notion_api_key'] ) || empty( $options['notion_database_id'] ) ) {
                wp_send_json_error( [ 'message' => '请先配置API密钥和数据库ID' ] );
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

            // 执行导入
            error_log('Notion to WordPress: 开始执行import_pages()');
            $result = $notion_pages->import_pages();
            error_log('Notion to WordPress: import_pages()执行完成，结果: ' . print_r($result, true));

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
            error_log('Notion to WordPress: 捕获异常: ' . $e->getMessage());
            error_log('Notion to WordPress: 异常堆栈: ' . $e->getTraceAsString());
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
            wp_send_json_error(['message' => '缺少nonce参数']);
            return;
        }

        if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
            error_log('Notion to WordPress: 刷新nonce验证失败');
            wp_send_json_error(['message' => 'nonce验证失败']);
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
            wp_send_json_error(['message' => 'Nonce验证失败']);
            return;
        }

        if (!current_user_can('manage_options')) {
            error_log('Notion to WordPress: 用户权限不足');
            wp_send_json_error(['message' => '权限不足']);
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
                $last_update = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_update));
            } else {
                $last_update = __('从未', 'notion-to-wordpress');
            }

            // 获取下次计划运行时间
            $next_run = wp_next_scheduled('notion_cron_import');
            if ($next_run) {
                $next_run = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run);
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
            wp_send_json_error(['message' => '获取统计信息失败: ' . $e->getMessage()]);
        } catch (Error $e) {
            error_log('Notion to WordPress: 获取统计信息错误: ' . $e->getMessage());
            wp_send_json_error(['message' => '获取统计信息错误: ' . $e->getMessage()]);
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
            wp_send_json_error(['message' => '权限不足']);
            return;
        }

        try {
            $success = Notion_To_WordPress_Helper::clear_logs();
            
            if ($success) {
                wp_send_json_success(['message' => '所有日志文件已清除']);
            } else {
                wp_send_json_error(['message' => '清除日志时出现错误']);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => '清除日志失败: ' . $e->getMessage()]);
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
        if (strpos($content, '无效') === 0 || strpos($content, '不存在') !== false) {
            wp_send_json_error(['message' => $content]);
        }

        wp_send_json_success($content);
    }

    /**
     * 测试调试方法
     *
     * @since    1.0.10
     */
    public function handle_test_debug() {
        error_log('Notion to WordPress: handle_test_debug 被调用');

        try {
            // 先检查基本的POST数据
            error_log('Notion to WordPress: POST数据: ' . print_r($_POST, true));

            // 检查nonce
            if (!isset($_POST['nonce'])) {
                error_log('Notion to WordPress: 缺少nonce参数');
                wp_send_json_error(['message' => '缺少nonce参数']);
                return;
            }

            error_log('Notion to WordPress: 收到的nonce: ' . $_POST['nonce']);

            // 验证nonce
            if (!wp_verify_nonce($_POST['nonce'], 'notion_to_wordpress_nonce')) {
                error_log('Notion to WordPress: nonce验证失败');
                wp_send_json_error(['message' => 'nonce验证失败']);
                return;
            }

            error_log('Notion to WordPress: nonce验证成功');

            if (!current_user_can('manage_options')) {
                error_log('Notion to WordPress: 权限检查失败');
                wp_send_json_error(['message' => '权限不足']);
                return;
            }

            error_log('Notion to WordPress: 权限检查成功');

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

            error_log('Notion to WordPress: 测试数据: ' . print_r($test_data, true));
            wp_send_json_success(['message' => '调试测试成功', 'data' => $test_data]);

        } catch (Exception $e) {
            error_log('Notion to WordPress: 测试异常: ' . $e->getMessage());
            error_log('Notion to WordPress: 异常堆栈: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => '测试失败: ' . $e->getMessage()]);
        } catch (Error $e) {
            error_log('Notion to WordPress: 测试错误: ' . $e->getMessage());
            error_log('Notion to WordPress: 错误堆栈: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => '测试错误: ' . $e->getMessage()]);
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