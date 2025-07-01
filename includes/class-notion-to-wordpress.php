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
	 * @since    1.0.10
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
			$this->version = '1.0.9';
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
	 * - Notion_To_WordPress_Lock. 在同步期间处理文件锁定。
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
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/class-notion-to-wordpress-lock.php' );
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
		$lock_timeout  = $options['lock_timeout'] ?? 300;

		// 实例化API处理器
		$this->notion_api = new Notion_API( $api_key );

		// 实例化页面处理器
		$this->notion_pages = new Notion_Pages( $this->notion_api, $database_id, $field_mapping, $lock_timeout );

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
		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
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

		// AJAX钩子
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_manual_sync', $this->admin, 'handle_manual_import' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_test_connection', $this->admin, 'handle_test_connection' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_refresh_all', $this->admin, 'handle_refresh_all' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_get_stats', $this->admin, 'handle_get_stats' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_clear_logs', $this->admin, 'handle_clear_logs' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_view_log', $this->admin, 'handle_view_log' );

		// 定时任务钩子
		$options = get_option( 'notion_to_wordpress_options', array() );
		if ( ! empty( $options['sync_schedule'] ) && 'manual' !== $options['sync_schedule'] ) {
			$this->loader->add_action( 'notion_cron_import', $this, 'cron_import_pages' );
		}

		// 注册自定义计划频率过滤器
		$this->loader->add_filter( 'cron_schedules', $this, 'add_custom_cron_schedules' );
	}

	/**
	 * 注册与插件面向公众功能相关的所有钩子。
	 *
	 * @since    1.0.5
	 * @access   private
	 */
	private function define_public_hooks() {
		$this->loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_frontend_scripts' );
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
	 * @since    1.0.10
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

		$this->_core_import_process( $database_id, $options );

		// 更新上次同步时间
		$options['last_sync_time'] = current_time( 'mysql' );
		update_option( 'notion_to_wordpress_options', $options );
	}

	/**
	 * 核心导入逻辑，可由cron或手动调用。
	 *
	 * @since 1.0.10
	 * @param string $database_id 要导入的数据库ID
	 * @param array  $options 插件设置选项
	 */
	private function _core_import_process( string $database_id, array $options ): void {
		$lock_timeout = $options['lock_timeout'] ?? 300;

		// 实例化锁
		$lock = new Notion_To_WordPress_Lock( $database_id, $lock_timeout );

		// 尝试获取锁
		if ( ! $lock->acquire() ) {
			Notion_To_WordPress_Helper::error_log( 'Import aborted: Another import process is already running.' );
			return;
		}

		try {
			// 构造增量同步过滤器（仅拉取自上次同步后有改动的页面）
			$filter = array();
			if ( ! empty( $options['last_sync_time'] ) ) {
				$iso_time = date( 'c', strtotime( $options['last_sync_time'] ) );
				$filter   = array(
					'timestamp'       => 'last_edited_time',
					'last_edited_time' => array(
						'after' => $iso_time,
					),
				);
			}

			$pages = $this->notion_api->get_database_pages( $database_id, $filter );

			if ( empty( $pages ) ) {
				$lock->release();
				return;
			}

			foreach ( $pages as $page ) {
				$this->notion_pages->import_notion_page( $page );
			}
		} catch ( Exception $e ) {
			Notion_To_WordPress_Helper::error_log( 'Notion import error: ' . $e->getMessage() );
		} finally {
			$lock->release();
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
				'excerpt'        => 'Excerpt,摘要',
				'featured_image' => 'Featured Image,特色图片',
				'categories'     => 'Categories,分类',
				'tags'           => 'Tags,标签',
				'visibility'     => 'Visibility,可见性',
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

		// MathJax 配置（必须在库之前加载，放在 <head> 以便立即生效）
		wp_register_script(
			'mathjax-config',
			Notion_To_WordPress_Helper::plugin_url('assets/js/mathjax-config.js'),
			array(),
			$this->version,
			false // 不放到 footer，确保先于库
		);

		// MathJax 主库（依赖配置脚本）
		wp_register_script(
			'mathjax',
			'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js',
			array('mathjax-config'),
			'3.2.2',
			true
		);

		wp_enqueue_script('mathjax');

		// Mermaid 图表渲染库
		wp_enqueue_script(
			'mermaid',
			'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js',
			array(),
			'10.4.0',
			true
		);

		// 处理公式与Mermaid渲染的脚本
		wp_enqueue_script(
			$this->plugin_name . '-math-mermaid',
			Notion_To_WordPress_Helper::plugin_url('assets/js/notion-math-mermaid.js'),
			array('jquery', 'mermaid', 'mathjax'),
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
} 