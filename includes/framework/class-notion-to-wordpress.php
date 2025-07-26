<?php
declare(strict_types=1);

/**
 * 插件核心类
 * 
 * 负责初始化插件，加载依赖项，定义国际化，以及注册后台和前台的钩子。
 * 
 * @since      1.0.9
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
	 * Notion导入协调器实例。
	 *
	 * @since    1.0.6
	 * @access   protected
	 * @var      Notion_Import_Coordinator    $notion_pages    Notion导入协调器实例。
	 */
	protected Notion_Import_Coordinator $notion_pages;

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
			$this->version = '2.0.0-beta.1';
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
	 * 按照分层架构加载以下文件：
	 *
	 * Core层 - 基础设施服务：
	 * - Notion_Logger. 日志记录服务。
	 * - Notion_Security. 安全验证服务。
	 * - Notion_Text_Processor. 文本处理服务。
	 * - Notion_HTTP_Client. HTTP客户端服务。
	 *
	 * Framework层 - 框架管理服务：
	 * - Notion_To_WordPress_Loader. 协调插件的钩子。
	 * - Notion_To_WordPress_i18n. 定义国际化功能。
	 *
	 * Services层 - 业务逻辑服务：
	 * - Notion_API. API接口服务。
	 * - Notion_Content_Converter. 内容转换服务。
	 * - Notion_Database_Renderer. 数据库渲染服务。
	 * - Notion_Image_Processor. 图片处理服务。
	 * - Notion_Metadata_Extractor. 元数据提取服务。
	 * - Notion_Sync_Manager. 同步管理服务。
	 *
	 * Handlers层 - 协调器服务：
	 * - Notion_Import_Coordinator. 导入协调器（原Notion_Pages）。
	 * - Notion_To_WordPress_Integrator. 集成协调器。
	 * - Notion_To_WordPress_Webhook. Webhook处理器。
	 *
	 * @since    1.0.5
	 * @access   private
	 */
	private function load_dependencies() {
		// Core层 - 基础设施服务
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/core/class-notion-logger.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/core/class-notion-security.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/core/class-notion-text-processor.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/core/class-notion-http-client.php' );

		// Framework层 - 框架管理服务
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/framework/class-notion-to-wordpress-loader.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/framework/class-notion-to-wordpress-i18n.php' );

		// Admin层 - 后台管理
		require_once Notion_To_WordPress_Helper::plugin_path( 'admin/class-notion-to-wordpress-admin.php' );

		// Services层 - 业务逻辑服务
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/services/class-notion-metadata-extractor.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/services/class-notion-sync-manager.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/services/class-notion-content-converter.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/services/class-notion-image-processor.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/services/class-notion-api.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/services/class-notion-database-renderer.php' );

		// Handlers层 - 协调器服务
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/handlers/class-notion-import-coordinator.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/handlers/class-notion-to-wordpress-integrator.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/handlers/class-notion-to-wordpress-webhook.php' );

		$this->loader = new Notion_To_WordPress_Loader();
	}

	/**
	 * 实例化核心对象。
	 *
	 * 创建API处理器、导入协调器和后台处理器的实例。
	 * 将所需的依赖项传递给它们中的每一个。
	 *
	 * @since 1.0.6
	 * @access private
	 */
	private function instantiate_objects() {
		// 初始化日志记录器
		Notion_Logger::init();

		// 获取选项
		$options       = get_option( 'notion_to_wordpress_options', array() );
		$api_key       = $options['notion_api_key'] ?? '';
		$database_id   = $options['notion_database_id'] ?? '';
		$field_mapping = $options['field_mapping'] ?? array();

		// 实例化API处理器
		$this->notion_api = new Notion_API( $api_key );

		// 实例化导入协调器（原页面处理器）
		$this->notion_pages = new Notion_Import_Coordinator( $this->notion_api, $database_id, $field_mapping );

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

		// 队列系统集成
		if (class_exists('Notion_Queue_Manager')) {
			Notion_Queue_Manager::init();
		}

		// 异步处理器集成
		if (class_exists('Notion_Async_Processor')) {
			Notion_Async_Processor::init();
		}
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

		// 初始化特色图像支持
		$this->loader->add_action( 'init', 'Notion_To_WordPress_Integrator', 'init_featured_image_support' );
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
			Notion_Logger::error_log( 'Cron import failed: Database ID is not set.' );
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
				Notion_Logger::error_log('Cron import failed: ' . $result->get_error_message());
			} else {
				Notion_Logger::info_log(
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
			Notion_Logger::error_log( 'Notion import error: ' . $e->getMessage() );
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

		// 新的数据库渲染器样式
		wp_enqueue_style(
			$this->plugin_name . '-database',
			Notion_To_WordPress_Helper::plugin_url('assets/css/notion-database.css'),
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

		// 本地兜底样式
		wp_enqueue_style(
			'katex-fallback',
			Notion_To_WordPress_Helper::plugin_url('assets/vendor/katex/katex.min.css'),
			array(),
			$this->version
		);

		// KaTeX 主库 - CDN优先
		wp_register_script(
			'katex',
			$cdn_prefix . '/npm/katex@0.16.22/dist/katex.min.js',
			array(),
			'0.16.22',
			true
		);

		// 本地兜底脚本
		wp_register_script(
			'katex-fallback',
			Notion_To_WordPress_Helper::plugin_url('assets/vendor/katex/katex.min.js'),
			array(),
			$this->version,
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

		// mhchem 本地兜底
		wp_register_script(
			'katex-mhchem-fallback',
			Notion_To_WordPress_Helper::plugin_url('assets/vendor/katex/mhchem.min.js'),
			array( 'katex-fallback' ),
			$this->version,
			true
		);

		// KaTeX auto-render 扩展，依赖 KaTeX
		wp_register_script(
			'katex-auto-render',
			$cdn_prefix . '/npm/katex@0.16.22/dist/contrib/auto-render.min.js',
			array( 'katex' ),
			'0.16.22',
			true
		);

		// auto-render 本地兜底
		wp_register_script(
			'katex-auto-render-fallback',
			Notion_To_WordPress_Helper::plugin_url('assets/vendor/katex/auto-render.min.js'),
			array( 'katex-fallback' ),
			$this->version,
			true
		);

		// 按顺序入队 KaTeX 相关脚本
		wp_enqueue_script( 'katex' );
		wp_enqueue_script( 'katex-mhchem' );
		wp_enqueue_script( 'katex-auto-render' );

		// 添加CDN兜底逻辑
		wp_add_inline_script( 'katex', "
			// KaTeX CDN兜底逻辑
			if (typeof katex === 'undefined') {
				console.log('KaTeX CDN failed, loading fallback...');
				var script = document.createElement('script');
				script.src = '" . Notion_To_WordPress_Helper::plugin_url('assets/vendor/katex/katex.min.js') . "';
				script.onload = function() {
					// 加载mhchem兜底
					var mhchemScript = document.createElement('script');
					mhchemScript.src = '" . Notion_To_WordPress_Helper::plugin_url('assets/vendor/katex/mhchem.min.js') . "';
					document.head.appendChild(mhchemScript);

					// 加载auto-render兜底
					var autoRenderScript = document.createElement('script');
					autoRenderScript.src = '" . Notion_To_WordPress_Helper::plugin_url('assets/vendor/katex/auto-render.min.js') . "';
					document.head.appendChild(autoRenderScript);
				};
				document.head.appendChild(script);
			}
		" );

		// ---------------- Mermaid - CDN优先，本地兜底 ----------------
		wp_enqueue_script(
			'mermaid',
			$cdn_prefix . '/npm/mermaid@11/dist/mermaid.min.js',
			array(),
			'11.7.0',
			true
		);

		// Mermaid CDN兜底逻辑
		wp_add_inline_script( 'mermaid', "
			if (typeof mermaid === 'undefined') {
				console.log('Mermaid CDN failed, loading fallback...');
				var script = document.createElement('script');
				script.src = '" . Notion_To_WordPress_Helper::plugin_url('assets/vendor/mermaid/mermaid.min.js') . "';
				document.head.appendChild(script);
			}
		" );

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

		// 前端资源优化脚本
		wp_enqueue_script(
			$this->plugin_name . '-resource-optimizer',
			Notion_To_WordPress_Helper::plugin_url('assets/js/resource-optimizer.js'),
			array($this->plugin_name . '-lazy-loading'),
			$this->version,
			true
		);

		// 传递CDN配置到前端
		$cdn_config = $this->get_cdn_config();
		wp_localize_script(
			$this->plugin_name . '-resource-optimizer',
			'notionCdnConfig',
			$cdn_config
		);

		// 注册锚点导航脚本，支持区块锚点跳转
		wp_enqueue_script(
			$this->plugin_name . '-anchor-navigation',
			Notion_To_WordPress_Helper::plugin_url('assets/js/anchor-navigation.js'),
			array(),
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
		// 注意：避免匹配已经包含占位符的包装div
		$patterns = array(
			// 匹配行内公式 span 标签
			'/<span[^>]*class="[^"]*notion-equation-inline[^"]*"[^>]*>.*?<\/span>/s',
			// 匹配块级公式 div 标签，但排除包含占位符的div
			'/<div[^>]*class="[^"]*notion-equation-block[^"]*"[^>]*>(?!.*NOTION_FORMULA_PLACEHOLDER).*?<\/div>/s',
			// 兼容旧版本的行内公式匹配
			'/<span[^>]*class="[^"]*notion-equation[^"]*"[^>]*>.*?<\/span>/s'
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

	/**
	 * 获取CDN配置
	 *
	 * 为前端资源优化器提供CDN配置信息
	 *
	 * @since 2.0.0-beta.1
	 * @return array CDN配置数组
	 */
	private function get_cdn_config(): array {
		$options = get_option('notion_to_wordpress_options', []);

		// 基础CDN配置
		$cdn_config = [
			'enabled' => false,
			'baseUrl' => '',
			'fallbackEnabled' => true,
			'timeout' => 5000,
			'providers' => [
				'jsdelivr' => 'https://cdn.jsdelivr.net',
				'unpkg' => 'https://unpkg.com',
				'cdnjs' => 'https://cdnjs.cloudflare.com'
			]
		];

		// 检查是否启用CDN
		$enable_cdn = $options['enable_cdn'] ?? false;
		if ($enable_cdn) {
			$cdn_provider = $options['cdn_provider'] ?? 'jsdelivr';
			$custom_cdn_url = $options['custom_cdn_url'] ?? '';

			$cdn_config['enabled'] = true;

			if ($cdn_provider === 'custom' && !empty($custom_cdn_url)) {
				$cdn_config['baseUrl'] = rtrim($custom_cdn_url, '/');
			} elseif (isset($cdn_config['providers'][$cdn_provider])) {
				$cdn_config['baseUrl'] = $cdn_config['providers'][$cdn_provider];
			}
		}

		// 性能优化配置
		$cdn_config['optimization'] = [
			'compression' => [
				'enabled' => $options['enable_asset_compression'] ?? true,
				'level' => $options['compression_level'] ?? 'auto'
			],
			'lazyLoading' => [
				'enhanced' => $options['enhanced_lazy_loading'] ?? true,
				'preloadThreshold' => $options['preload_threshold'] ?? 2
			],
			'performance' => [
				'monitoring' => $options['performance_monitoring'] ?? true,
				'reportInterval' => $options['performance_report_interval'] ?? 30000
			]
		];

		// 应用过滤器，允许主题或其他插件修改配置
		return apply_filters('notion_cdn_config', $cdn_config);
	}

	/**
	 * 获取资源优化统计信息
	 *
	 * @since 2.0.0-beta.1
	 * @return array 统计信息
	 */
	public static function get_resource_optimization_stats(): array {
		$stats = [
			'cdn_enabled' => false,
			'compression_enabled' => false,
			'lazy_loading_enhanced' => false,
			'performance_monitoring' => false,
			'last_optimization_time' => null
		];

		$options = get_option('notion_to_wordpress_options', []);

		$stats['cdn_enabled'] = $options['enable_cdn'] ?? false;
		$stats['compression_enabled'] = $options['enable_asset_compression'] ?? true;
		$stats['lazy_loading_enhanced'] = $options['enhanced_lazy_loading'] ?? true;
		$stats['performance_monitoring'] = $options['performance_monitoring'] ?? true;
		$stats['last_optimization_time'] = $options['last_optimization_time'] ?? null;

		return $stats;
	}
}