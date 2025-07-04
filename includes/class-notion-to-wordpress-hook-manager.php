<?php
/**
 * 钩子管理器
 *
 * 负责注册和管理插件的所有WordPress钩子
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notion_To_WordPress_Hook_Manager {

    /**
     * 加载器实例
     *
     * @since 1.1.0
     * @var Notion_To_WordPress_Loader
     */
    private Notion_To_WordPress_Loader $loader;

    /**
     * 管理界面实例
     *
     * @since 1.1.0
     * @var Notion_To_WordPress_Admin|null
     */
    private ?Notion_To_WordPress_Admin $admin = null;

    /**
     * Webhook处理器实例
     *
     * @since 1.1.0
     * @var Notion_To_WordPress_Webhook|null
     */
    private ?Notion_To_WordPress_Webhook $webhook = null;

    /**
     * 国际化处理器实例
     *
     * @since 1.1.0
     * @var Notion_To_WordPress_i18n|null
     */
    private ?Notion_To_WordPress_i18n $i18n = null;

    /**
     * 构造函数
     *
     * @since 1.1.0
     * @param Notion_To_WordPress_Loader $loader 加载器实例
     */
    public function __construct(Notion_To_WordPress_Loader $loader) {
        $this->loader = $loader;
    }

    /**
     * 设置管理界面实例
     *
     * @since 1.1.0
     * @param Notion_To_WordPress_Admin $admin 管理界面实例
     */
    public function set_admin(Notion_To_WordPress_Admin $admin): void {
        $this->admin = $admin;
    }

    /**
     * 设置Webhook处理器实例
     *
     * @since 1.1.0
     * @param Notion_To_WordPress_Webhook $webhook Webhook处理器实例
     */
    public function set_webhook(Notion_To_WordPress_Webhook $webhook): void {
        $this->webhook = $webhook;
    }

    /**
     * 设置国际化处理器实例
     *
     * @since 1.1.0
     * @param Notion_To_WordPress_i18n $i18n 国际化处理器实例
     */
    public function set_i18n(Notion_To_WordPress_i18n $i18n): void {
        $this->i18n = $i18n;
    }

    /**
     * 定义所有钩子
     *
     * @since 1.1.0
     */
    public function define_all_hooks(): void {
        $this->define_locale_hooks();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_cron_hooks();
        $this->define_webhook_hooks();
        $this->define_cleanup_hooks();
    }

    /**
     * 定义国际化钩子
     *
     * @since 1.1.0
     */
    public function define_locale_hooks(): void {
        if ($this->i18n) {
            $this->loader->add_action('plugins_loaded', $this->i18n, 'load_plugin_textdomain');
        }
    }

    /**
     * 定义管理界面钩子
     *
     * @since 1.1.0
     */
    public function define_admin_hooks(): void {
        if (!$this->admin) {
            return;
        }

        // 管理界面基础钩子
        $this->loader->add_action('admin_enqueue_scripts', $this->admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $this->admin, 'enqueue_scripts');
        $this->loader->add_action('admin_menu', $this->admin, 'add_plugin_admin_menu');

        // AJAX钩子
        $this->define_admin_ajax_hooks();

        // 管理界面通知钩子
        $this->loader->add_action('admin_notices', $this->admin, 'show_admin_notices');
    }

    /**
     * 定义管理界面AJAX钩子
     *
     * @since 1.1.0
     */
    private function define_admin_ajax_hooks(): void {
        if (!$this->admin) {
            return;
        }

        $ajax_actions = [
            'manual_import',
            'sync_single_page',
            'get_stats',
            'clear_cache',
            'test_connection',
            'force_unlock',
            'get_logs'
        ];

        foreach ($ajax_actions as $action) {
            $hook_name = "wp_ajax_notion_to_wordpress_{$action}";
            $method_name = "handle_{$action}";
            
            if (method_exists($this->admin, $method_name)) {
                $this->loader->add_action($hook_name, $this->admin, $method_name);
                $nopriv_hook = "wp_ajax_nopriv_notion_to_wordpress_{$action}";
                $this->loader->add_action($nopriv_hook, $this->admin, $method_name);
            }
        }
    }

    /**
     * 定义公共钩子
     *
     * @since 1.1.0
     */
    public function define_public_hooks(): void {
        // 前端脚本和样式
        $this->loader->add_action('wp_enqueue_scripts', $this, 'enqueue_public_styles');
        $this->loader->add_action('wp_enqueue_scripts', $this, 'enqueue_public_scripts');

        // 内容过滤器
        $this->loader->add_filter('the_content', $this, 'process_notion_content', 10);

        // 短代码
        $this->loader->add_action('init', $this, 'register_shortcodes');
    }

    /**
     * 定义Cron钩子
     *
     * @since 1.1.0
     */
    public function define_cron_hooks(): void {
        // 自动同步钩子
        $this->loader->add_action('notion_to_wordpress_auto_sync', $this, 'handle_auto_sync');

        // 媒体下载队列处理
        $this->loader->add_action('ntw_async_media', 'Notion_Download_Queue', 'process_queue');

        // 清理任务
        $this->loader->add_action('notion_to_wordpress_cleanup', $this, 'handle_cleanup_task');
    }

    /**
     * 定义Webhook钩子
     *
     * @since 1.1.0
     */
    public function define_webhook_hooks(): void {
        if ($this->webhook) {
            $this->loader->add_action('rest_api_init', $this->webhook, 'register_routes');
        }
    }

    /**
     * 定义清理钩子
     *
     * @since 1.1.0
     */
    public function define_cleanup_hooks(): void {
        // 插件停用时的清理
        $this->loader->add_action('deactivate_' . plugin_basename(NOTION_TO_WORDPRESS_FILE), $this, 'handle_plugin_deactivation');

        // 定期清理任务
        $this->loader->add_action('wp_scheduled_delete', $this, 'cleanup_expired_data');
    }

    /**
     * 加载公共样式
     *
     * @since 1.1.0
     */
    public function enqueue_public_styles(): void {
        wp_enqueue_style(
            'notion-to-wordpress-public',
            Notion_To_WordPress_Helper::plugin_url('assets/css/frontend.css'),
            [],
            NOTION_TO_WORDPRESS_VERSION,
            'all'
        );
    }

    /**
     * 加载公共脚本
     *
     * @since 1.1.0
     */
    public function enqueue_public_scripts(): void {
        wp_enqueue_script(
            'notion-to-wordpress-math-mermaid',
            Notion_To_WordPress_Helper::plugin_url('assets/js/notion-math-mermaid.js'),
            ['jquery'],
            NOTION_TO_WORDPRESS_VERSION,
            true
        );
    }

    /**
     * 处理Notion内容
     *
     * @since 1.1.0
     * @param string $content 文章内容
     * @return string 处理后的内容
     */
    public function process_notion_content(string $content): string {
        // 检查是否为Notion导入的内容
        global $post;
        if (!$post || !get_post_meta($post->ID, '_notion_page_id', true)) {
            return $content;
        }

        // 应用Notion特定的内容处理
        $content = $this->process_notion_equations($content);
        $content = $this->process_notion_callouts($content);
        
        return $content;
    }

    /**
     * 注册短代码
     *
     * @since 1.1.0
     */
    public function register_shortcodes(): void {
        add_shortcode('notion_page', [$this, 'notion_page_shortcode']);
        add_shortcode('notion_database', [$this, 'notion_database_shortcode']);
    }

    /**
     * 处理自动同步
     *
     * @since 1.1.0
     */
    public function handle_auto_sync(): void {
        $options = get_option('notion_to_wordpress_options', []);
        
        if (empty($options['auto_sync_enabled'])) {
            return;
        }

        try {
            $notion_to_wordpress = new Notion_To_WordPress();
            $notion_to_wordpress->run_auto_sync();
        } catch (Exception $e) {
            Notion_To_WordPress_Error_Handler::exception_to_wp_error(
                $e,
                Notion_To_WordPress_Error_Handler::CODE_IMPORT_ERROR
            );
        }
    }

    /**
     * 处理清理任务
     *
     * @since 1.1.0
     */
    public function handle_cleanup_task(): void {
        // 清理过期的错误日志
        Notion_To_WordPress_Error_Handler::cleanup_old_logs(30);

        // 清理过期的缓存
        $this->cleanup_expired_cache();

        // 清理过期的锁
        $this->cleanup_expired_locks();
    }

    /**
     * 处理插件停用
     *
     * @since 1.1.0
     */
    public function handle_plugin_deactivation(): void {
        // 清理计划任务
        wp_clear_scheduled_hook('notion_to_wordpress_auto_sync');
        wp_clear_scheduled_hook('ntw_async_media');
        wp_clear_scheduled_hook('notion_to_wordpress_cleanup');

        Notion_To_WordPress_Error_Handler::log_info(
            '插件停用，已清理计划任务',
            Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR
        );
    }

    /**
     * 清理过期数据
     *
     * @since 1.1.0
     */
    public function cleanup_expired_data(): void {
        $this->cleanup_expired_cache();
        $this->cleanup_expired_locks();
    }

    /**
     * 处理Notion公式
     *
     * @since 1.1.0
     * @param string $content 内容
     * @return string 处理后的内容
     */
    private function process_notion_equations(string $content): string {
        // 处理行内公式
        $content = preg_replace_callback(
            '/<span class="notion-equation notion-equation-inline">\$(.+?)\$<\/span>/',
            function($matches) {
                return '<span class="notion-equation-inline">\\(' . $matches[1] . '\\)</span>';
            },
            $content
        );

        // 处理块级公式
        $content = preg_replace_callback(
            '/<div class="notion-equation">\$\$(.+?)\$\$<\/div>/s',
            function($matches) {
                return '<div class="notion-equation-block">\\[' . $matches[1] . '\\]</div>';
            },
            $content
        );

        return $content;
    }

    /**
     * 处理Notion标注
     *
     * @since 1.1.0
     * @param string $content 内容
     * @return string 处理后的内容
     */
    private function process_notion_callouts(string $content): string {
        // 这里可以添加标注处理逻辑
        return $content;
    }

    /**
     * 清理过期缓存
     *
     * @since 1.1.0
     */
    private function cleanup_expired_cache(): void {
        // 实现缓存清理逻辑
        Notion_To_WordPress_Helper::cache_delete('ntw_imported_posts_count');
        Notion_To_WordPress_Helper::cache_delete('ntw_published_posts_count');
    }

    /**
     * 清理过期锁
     *
     * @since 1.1.0
     */
    private function cleanup_expired_locks(): void {
        global $wpdb;
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(autoload AS UNSIGNED) > 0 AND CAST(autoload AS UNSIGNED) < %d",
                'ntw_import_lock_%',
                time()
            )
        );
    }

    /**
     * Notion页面短代码
     *
     * @since 1.1.0
     * @param array $atts 短代码属性
     * @return string 短代码输出
     */
    public function notion_page_shortcode(array $atts): string {
        $atts = shortcode_atts([
            'id' => '',
            'title' => 'true'
        ], $atts);

        if (empty($atts['id'])) {
            return '<!-- Notion页面ID不能为空 -->';
        }

        // 实现页面嵌入逻辑
        return '<!-- Notion页面嵌入功能待实现 -->';
    }

    /**
     * Notion数据库短代码
     *
     * @since 1.1.0
     * @param array $atts 短代码属性
     * @return string 短代码输出
     */
    public function notion_database_shortcode(array $atts): string {
        $atts = shortcode_atts([
            'id' => '',
            'limit' => '10'
        ], $atts);

        if (empty($atts['id'])) {
            return '<!-- Notion数据库ID不能为空 -->';
        }

        // 实现数据库嵌入逻辑
        return '<!-- Notion数据库嵌入功能待实现 -->';
    }
}
