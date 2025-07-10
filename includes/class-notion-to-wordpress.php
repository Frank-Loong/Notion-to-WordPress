<?php
declare(strict_types=1);

/**
 * 核心插件类
 *
 * 用于定义国际化、后台特定的钩子和面向公众的站点钩子。
 *
 * 同样，它也用于通过加载所有依赖项、设置区域设置和注册钩子来初始化插件。
 *
 * @since      1.0.9
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 */
// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

class Notion_To_WordPress {

	/**
	 * 负责维护和注册所有驱动插件功能的钩子的加载器。
	 *
	 * @since    1.0.5
	 * @access   protected
	 * @var      Notion_To_WordPress_Loader    $loader    维护和注册插件的所有钩子。
	 */
	protected Notion_To_WordPress_Loader $loader;

	/**
	 * 此插件的唯一标识符。
	 *
	 * @since    1.0.5
	 * @access   protected
	 * @var      string    $plugin_name    用于唯一标识此插件的字符串。
	 */
	protected string $plugin_name;

	/**
	 * 插件的当前版本。
	 *
	 * @since    1.0.5
	 * @access   protected
	 * @var      string    $version    插件的当前版本。
	 */
	protected string $version;

	/**
	 * Notion API处理器实例。
	 *
	 * @since    1.0.6
	 * @access   protected
	 * @var      Notion_API    $notion_api    Notion API处理器实例。
	 */
	protected Notion_API $notion_api;

	/**
	 * Notion页面处理器实例。
	 *
	 * @since    1.0.6
	 * @access   protected
	 * @var      Notion_Pages    $notion_pages    Notion页面处理器实例。
	 */
	protected Notion_Pages $notion_pages;

	/**
	 * 后台区域处理器实例。
	 *
	 * @since    1.0.6
	 * @access   protected
	 * @var      Notion_To_WordPress_Admin    $admin    后台区域处理器实例。
	 */
	protected Notion_To_WordPress_Admin $admin;

	/**
	 * Webhook 处理器实例。
	 *
	 * @since    1.1.0
	 * @access   protected
	 * @var      Notion_To_WordPress_Webhook    $webhook    Webhook 处理器实例。
	 */
	protected Notion_To_WordPress_Webhook $webhook;

	/**
	 * 定义插件的核心功能。
	 *
	 * 设置插件名称和版本，加载依赖项，定义区域设置，
	 * 设置后台和公共钩子。
	 *
	 * @since    1.0.5
	 */
	public function __construct() {
		if ( defined( 'NOTION_TO_WORDPRESS_VERSION' ) ) {
			$this->version = NOTION_TO_WORDPRESS_VERSION;
		} else {
			$this->version = '1.2.5-beta.4';
		}
		$this->plugin_name = 'notion-to-wordpress';

		$this->load_dependencies();
		$this->instantiate_objects();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * 加载此插件所需的依赖项。
	 *
	 * 包括构成插件的以下文件：
	 *
	 * - Notion_To_WordPress_Loader. 协调插件的钩子。
	 * - Notion_To_WordPress_i18n. 定义国际化功能。
	 * - Notion_To_WordPress_Admin. 定义后台区域的所有钩子。
	 * - Notion_API. 定义面向公众的站点的所有钩子。
	 * - Notion_Pages. 处理页面处理。
	 * - Notion_To_WordPress_Webhook. 处理 Webhook 请求。
	 *
	 * @since    1.0.5
	 * @access   private
	 */
	private function load_dependencies() {
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/class-notion-to-wordpress-loader.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/class-notion-to-wordpress-i18n.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'admin/class-notion-to-wordpress-admin.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/class-notion-api.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/class-notion-pages.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/class-notion-to-wordpress-webhook.php' );

		$this->loader = new Notion_To_WordPress_Loader();
	}

	/**
	 * 实例化核心对象。
	 *
	 * 创建API处理器、页面处理器和后台处理器的实例。
	 * 将所需的依赖项传递给它们中的每一个。
	 *
	 * @since 1.0.6
	 * @access private
	 */
	private function instantiate_objects() {
		// 获取选项
		$options       = get_option( 'notion_to_wordpress_options', array() );
		$api_key       = $options['notion_api_key'] ?? '';
		$database_id   = $options['notion_database_id'] ?? '';
		$field_mapping = $options['field_mapping'] ?? array();

		// 实例化API处理器
		$this->notion_api = new Notion_API( $api_key );

		// 实例化页面处理器
		$this->notion_pages = new Notion_Pages( $this->notion_api, $database_id, $field_mapping );

		// 实例化后台处理器
		$this->admin = new Notion_To_WordPress_Admin(
			$this->get_plugin_name(),
			$this->get_version(),
			$this->notion_api,
			$this->notion_pages
		);

		// 实例化Webhook处理器
		$this->webhook = new Notion_To_WordPress_Webhook(
			$this->notion_pages
		);
	}

	/**
	 * 为此插件定义区域设置以进行国际化。
	 *
	 * 使用Notion_To_WordPress_i18n类来设置域并向WordPress注册钩子。
	 *
	 * @since    1.0.5
	 * @access   private
	 */
	private function set_locale() {
		$plugin_i18n = new Notion_To_WordPress_i18n();

		// 注册多个钩子来确保语言切换生效
		$this->loader->add_filter( 'plugin_locale', $plugin_i18n, 'maybe_override_locale', 10, 2 );
		$this->loader->add_filter( 'gettext', $plugin_i18n, 'override_gettext', 10, 3 );

		// 在 init 钩子上加载文本域
		$this->loader->add_action( 'init', $plugin_i18n, 'load_plugin_textdomain' );

		// 在 admin_init 钩子上强制重新加载翻译（仅在后台）
		$this->loader->add_action( 'admin_init', $plugin_i18n, 'force_reload_translations' );
	}

	/**
	 * 注册与插件后台区域功能相关的所有钩子。
	 *
	 * @since    1.0.5
	 * @access   private
	 */
	private function define_admin_hooks() {
		$this->loader->add_action( 'admin_enqueue_scripts', $this->admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $this->admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $this->admin, 'add_plugin_admin_menu' );
		$this->loader->add_action( 'admin_post_notion_to_wordpress_options', $this->admin, 'handle_settings_form' );
		$this->loader->add_action( 'wp_ajax_save_notion_settings', $this->admin, 'handle_save_settings_ajax' );

		// AJAX钩子
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_manual_sync', $this->admin, 'handle_manual_import' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_test_connection', $this->admin, 'handle_test_connection' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_refresh_all', $this->admin, 'handle_refresh_all' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_get_stats', $this->admin, 'handle_get_stats' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_clear_logs', $this->admin, 'handle_clear_logs' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_view_log', $this->admin, 'handle_view_log' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_test_debug', $this->admin, 'handle_test_debug' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_refresh_verification_token', $this->admin, 'handle_refresh_verification_token' );

		// 定时任务钩子
		$options = get_option( 'notion_to_wordpress_options', array() );
		if ( ! empty( $options['sync_schedule'] ) && 'manual' !== $options['sync_schedule'] ) {
			$this->loader->add_action( 'notion_cron_import', $this, 'cron_import_pages' );
		}

		// 注册自定义计划频率过滤器
		$this->loader->add_filter( 'cron_schedules', $this, 'add_custom_cron_schedules' );

		// 新增：添加新的cron事件
		$this->loader->add_action('notion_to_wordpress_cron_update', $this->notion_pages, 'update_post_from_notion_cron', 10, 1);
		$this->loader->add_action('notion_to_wordpress_log_cleanup', 'Notion_To_WordPress_Helper', 'run_log_cleanup');
	}

	/**
	 * 注册与插件面向公众功能相关的所有钩子。
	 *
	 * @since    1.0.5
	 * @access   private
	 */
	private function define_public_hooks() {
		$this->loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_frontend_scripts' );

		// 添加内容过滤器来保护公式不被wpautop处理
		$this->loader->add_filter( 'the_content', $this, 'protect_formulas_from_wpautop', 8 );
		$this->loader->add_filter( 'the_content', $this, 'restore_formulas_after_wpautop', 12 );

		// 同样保护摘要中的公式
		$this->loader->add_filter( 'the_excerpt', $this, 'protect_formulas_from_wpautop', 8 );
		$this->loader->add_filter( 'the_excerpt', $this, 'restore_formulas_after_wpautop', 12 );
	}

	/**
	 * 运行加载器以执行所有组件的钩子。
	 *
	 * @since    1.0.5
	 */
	public function run() {
		$this->loader->run();
		$this->define_webhook_hooks();
	}

	/**
	 * 注册与 Webhook 功能相关的所有钩子。
	 *
	 * @since    1.1.0
	 * @access   private
	 */
	private function define_webhook_hooks() {
		add_action('rest_api_init', [$this->webhook, 'register_routes']);
	}

	/**
	 * 用于在WordPress上下文中唯一标识它并定义国际化功能的插件名称。
	 *
	 * @since     1.0.5
	 * @return    string    插件的名称。
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * 对协调插件钩子的类的引用。
	 *
	 * @since     1.0.5
	 * @return    Notion_To_WordPress_Loader    协调插件的钩子。
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * 检索插件的版本号。
	 *
	 * @since     1.0.5
	 * @return    string    插件的版本号。
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * 核心导入逻辑，可由cron调用。
	 *
	 * @since 1.0.5
	 */
	public function cron_import_pages() {
		// 获取选项
		$options     = get_option( 'notion_to_wordpress_options', array() );
		$database_id = $options['notion_database_id'] ?? '';

		if (empty($database_id)) {
			Notion_To_WordPress_Helper::error_log( 'Cron import failed: Database ID is not set.' );
			return;
		}

		// 定时同步默认启用增量同步和删除检测
		$incremental = $options['cron_incremental_sync'] ?? true;
		$check_deletions = $options['cron_check_deletions'] ?? true;

		$this->_core_import_process( $database_id, $options, $incremental, $check_deletions );

		// 更新上次同步时间
		$options['last_sync_time'] = current_time( 'mysql' );
		update_option( 'notion_to_wordpress_options', $options );
	}

	/**
	 * 核心导入逻辑，可由cron或手动调用。
	 *
	 * @since 1.1.0
	 * @param string $database_id 要导入的数据库ID
	 * @param array  $options 插件设置选项
	 * @param bool   $incremental 是否启用增量同步
	 * @param bool   $check_deletions 是否检查删除
	 */
	private function _core_import_process( string $database_id, array $options, bool $incremental = true, bool $check_deletions = true ): void {
		try {
			// 使用统一的import_pages方法，支持增量同步和删除检测
			$result = $this->notion_pages->import_pages($check_deletions, $incremental);

			if (is_wp_error($result)) {
				Notion_To_WordPress_Helper::error_log('Cron import failed: ' . $result->get_error_message());
			} else {
				Notion_To_WordPress_Helper::info_log(
					sprintf('定时同步完成 - 总计: %d, 导入: %d, 更新: %d, 删除: %d, 失败: %d',
						$result['total'] ?? 0,
						$result['imported'] ?? 0,
						$result['updated'] ?? 0,
						$result['deleted'] ?? 0,
						$result['failed'] ?? 0
					),
					'Cron Sync'
				);
			}
		} catch ( Exception $e ) {
			Notion_To_WordPress_Helper::error_log( 'Notion import error: ' . $e->getMessage() );
		}
	}

	/**
	 * 在插件激活期间触发。
	 *
	 * 此类定义了在插件激活期间运行所需的所有代码。
	 *
	 * @since    1.0.5
	 * @access   public
	 * @static
	 */
	public static function activate() {
		// 设置默认选项
		$default_options = array(
			'notion_api_key'      => '',
			'notion_database_id'  => '',
			'sync_schedule'       => 'manual',
			'delete_on_uninstall' => 0,
			'field_mapping'       => array(
				'title'          => 'Title,标题',
				'status'         => 'Status,状态',
				'post_type'      => 'Type,类型',
				'date'           => 'Date,日期',
				'excerpt'        => 'Summary,摘要,Excerpt',
				'featured_image' => 'Featured Image,特色图片',
				'categories'     => 'Categories,分类,Category',
				'tags'           => 'Tags,标签,Tag',

			),
			'custom_field_mappings' => array(),
			'debug_level'         => Notion_To_WordPress_Helper::DEBUG_LEVEL_ERROR,
		);

		// 获取现有选项，并与默认值合并
		$existing_options = get_option( 'notion_to_wordpress_options', array() );
		$options          = array_merge( $default_options, $existing_options );

		update_option( 'notion_to_wordpress_options', $options );

		// 如果未设置为手动，则安排cron作业
		if ( ! empty( $options['sync_schedule'] ) && 'manual' !== $options['sync_schedule'] ) {
			if ( ! wp_next_scheduled( 'notion_cron_import' ) ) {
				wp_schedule_event( time(), $options['sync_schedule'], 'notion_cron_import' );
			}
		}

		// 刷新重写规则
		flush_rewrite_rules();
	}

	/**
	 * 在插件停用期间触发。
	 *
	 * 此类定义了在插件停用期间运行所需的所有代码。
	 *
	 * @since    1.0.5
	 * @access   public
	 * @static
	 */
	public static function deactivate() {
		// 取消计划的cron作业
		$timestamp = wp_next_scheduled( 'notion_cron_import' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'notion_cron_import' );
		}

		// 同时清除此钩子的任何其他计划
		wp_clear_scheduled_hook( 'notion_cron_import' );

		// 刷新重写规则
		flush_rewrite_rules();
	}

	/**
	 * 在前端加载所需的样式与脚本。
	 *
	 * @since 1.1.0
	 */
	public function enqueue_frontend_scripts() {
		// 样式
		wp_enqueue_style(
			$this->plugin_name . '-latex',
			Notion_To_WordPress_Helper::plugin_url('assets/css/latex-styles.css'),
			array(),
			$this->version
		);
		wp_enqueue_style(
			$this->plugin_name . '-custom',
			Notion_To_WordPress_Helper::plugin_url('assets/css/custom-styles.css'),
			array(),
			$this->version
		);

		// ---------------- 公式相关（KaTeX） ----------------
		// 允许通过过滤器自定义CDN前缀
		$cdn_prefix = apply_filters( 'ntw_cdn_prefix', 'https://cdn.jsdelivr.net' );

		// KaTeX 样式
		wp_enqueue_style(
			'katex',
			$cdn_prefix . '/npm/katex@0.16.22/dist/katex.min.css',
			array(),
			'0.16.22'
		);

		// KaTeX 主库
		wp_register_script(
			'katex',
			$cdn_prefix . '/npm/katex@0.16.22/dist/katex.min.js',
			array(),
			'0.16.22',
			true
		);

		// mhchem 扩展（化学公式）依赖 KaTeX
		wp_register_script(
			'katex-mhchem',
			$cdn_prefix . '/npm/katex@0.16.22/dist/contrib/mhchem.min.js',
			array( 'katex' ),
			'0.16.22',
			true
		);

		// 新增：KaTeX auto-render 扩展，依赖 KaTeX
		wp_register_script(
			'katex-auto-render',
			$cdn_prefix . '/npm/katex@0.16.22/dist/contrib/auto-render.min.js',
			array( 'katex' ),
			'0.16.22',
			true
		);

		// 按顺序入队 KaTeX 相关脚本
		wp_enqueue_script( 'katex' );
		wp_enqueue_script( 'katex-mhchem' );
		wp_enqueue_script( 'katex-auto-render' );

		// ---------------- Mermaid ----------------
		wp_enqueue_script(
			'mermaid',
			$cdn_prefix . '/npm/mermaid@11/dist/mermaid.min.js',
			array(),
			'11.7.0',
			true
		);

		// 处理公式与Mermaid渲染的脚本
		wp_enqueue_script(
			$this->plugin_name . '-katex-mermaid',
			Notion_To_WordPress_Helper::plugin_url('assets/js/katex-mermaid.js'),
			array('jquery', 'mermaid', 'katex', 'katex-mhchem', 'katex-auto-render'),
			$this->version,
			true
		);

		// 懒加载和性能优化脚本
		wp_enqueue_script(
			$this->plugin_name . '-lazy-loading',
			Notion_To_WordPress_Helper::plugin_url('assets/js/lazy-loading.js'),
			array(),
			$this->version,
			true
		);

		// 数据库交互功能脚本
		wp_enqueue_script(
			$this->plugin_name . '-database-interactions',
			Notion_To_WordPress_Helper::plugin_url('assets/js/database-interactions.js'),
			array('jquery'),
			$this->version,
			true
		);

		// 注册锚点导航脚本，支持区块锚点跳转
		wp_enqueue_script(
			$this->plugin_name . '-anchor-navigation',
			Notion_To_WordPress_Helper::plugin_url('assets/js/anchor-navigation.js'),
			array('jquery'),
			$this->version,
			true
		);
	}

	/**
	 * 向 WordPress 注册自定义 Cron 计划。
	 *
	 * @since 1.0.11
	 * @param array $schedules 现有计划数组
	 * @return array 修改后的计划数组
	 */
	public function add_custom_cron_schedules( array $schedules ): array {
		// 每周一次（7 天）
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( '每周一次', 'notion-to-wordpress' ),
			);
		}

		// 每两周一次（14 天）
		if ( ! isset( $schedules['biweekly'] ) ) {
			$schedules['biweekly'] = array(
				'interval' => 14 * DAY_IN_SECONDS,
				'display'  => __( '每两周一次', 'notion-to-wordpress' ),
			);
		}

		// 每月一次（30 天，近似）
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( '每月一次', 'notion-to-wordpress' ),
			);
		}

		return $schedules;
	}

	private function _convert_block_equation( array $block ): string {
		$expression = $block['equation']['expression'] ?? '';
		// 保持原始 LaTeX，避免对反斜杠做二次转义
		return '<div class="notion-equation notion-equation-block">$$' . $expression . '$$</div>';
	}

	/**
	 * 保护公式内容不被wpautop处理
	 *
	 * 在wpautop之前运行，将公式内容替换为占位符
	 *
	 * @since 1.1.0
	 * @param string $content 文章内容
	 * @return string 处理后的内容
	 */
	public function protect_formulas_from_wpautop( $content ) {
		// 只在包含公式的内容上运行，提高性能
		if ( strpos( $content, 'notion-equation' ) === false ) {
			return $content;
		}

		// 静态变量存储公式内容，确保在同一请求中可以恢复
		static $formula_placeholders = array();

		// 匹配所有公式标签（行内和块级），使用更精确的正则表达式
		$patterns = array(
			// 匹配行内公式 span 标签
			'/<span[^>]*class="[^"]*notion-equation-inline[^"]*"[^>]*>.*?<\/span>/s',
			// 匹配块级公式 div 标签
			'/<div[^>]*class="[^"]*notion-equation-block[^"]*"[^>]*>.*?<\/div>/s',
			// 兼容旧版本的通用匹配
			'/<span[^>]*class="[^"]*notion-equation[^"]*"[^>]*>.*?<\/span>/s',
			'/<div[^>]*class="[^"]*notion-equation[^"]*"[^>]*>.*?<\/div>/s'
		);

		foreach ( $patterns as $pattern ) {
			$content = preg_replace_callback( $pattern, function( $matches ) use ( &$formula_placeholders ) {
				$placeholder = '<!--NOTION_FORMULA_PLACEHOLDER_' . count( $formula_placeholders ) . '-->';
				$formula_placeholders[ $placeholder ] = $matches[0];
				return $placeholder;
			}, $content );
		}

		// 将占位符数组存储到全局变量中，以便后续恢复
		$GLOBALS['notion_formula_placeholders'] = $formula_placeholders;

		return $content;
	}

	/**
	 * 恢复被保护的公式内容
	 *
	 * 在wpautop之后运行，将占位符替换回原始公式内容
	 *
	 * @since 1.1.0
	 * @param string $content 经过wpautop处理的内容
	 * @return string 恢复公式后的内容
	 */
	public function restore_formulas_after_wpautop( $content ) {
		// 从全局变量中获取占位符数组
		$formula_placeholders = isset( $GLOBALS['notion_formula_placeholders'] ) ? $GLOBALS['notion_formula_placeholders'] : array();

		if ( empty( $formula_placeholders ) ) {
			return $content;
		}

		// 将占位符替换回原始公式内容
		foreach ( $formula_placeholders as $placeholder => $formula_html ) {
			$content = str_replace( $placeholder, $formula_html, $content );
		}

		// 清理全局变量
		unset( $GLOBALS['notion_formula_placeholders'] );

		return $content;
	}
}