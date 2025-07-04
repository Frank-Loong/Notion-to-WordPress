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
		$this->version = defined( 'NOTION_TO_WORDPRESS_VERSION' ) ? NOTION_TO_WORDPRESS_VERSION : 'dev';
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
	 * - Notion_To_WordPress_Download_Queue. 处理下载队列。
	 * - Notion_To_WordPress_Import_Coordinator. 处理导入协调。
	 * - Notion_To_WordPress_Block_Converter. 处理块转换。
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
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/class-notion-download-queue.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/class-notion-import-coordinator.php' );
		require_once Notion_To_WordPress_Helper::plugin_path( 'includes/class-notion-block-converter.php' );

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
		$lock_timeout  = $options['lock_timeout'] ?? 120;

		// 获取（或初始化）单例 API 处理器
		$this->notion_api = Notion_API::instance( $api_key );

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

		// 根据后台设置调整下载并发过滤器
		add_filter( 'ntw_download_queue_concurrency', function( $default ) use ( $options ) {
			$val = isset( $options['download_concurrency'] ) ? intval( $options['download_concurrency'] ) : $default;
			return max( 1, min( 10, $val ) );
		} );
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
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_refresh_single', $this->admin, 'handle_refresh_single' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_get_stats', $this->admin, 'handle_get_stats' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_clear_logs', $this->admin, 'handle_clear_logs' );
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_view_log', $this->admin, 'handle_view_log' );
		// 同步进度查询
		$this->loader->add_action( 'wp_ajax_notion_to_wordpress_get_sync_progress', $this->admin, 'handle_get_sync_progress' );

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

		// 前端占位符替换过滤器
		$this->loader->add_filter( 'the_content', $this, 'replace_temp_media_placeholders', 20 );
	}

	/**
	 * 运行加载器以执行所有组件的钩子。
	 *
	 * @since    1.0.5
	 */
	public function run() {
		$this->loader->run();
		$this->define_webhook_hooks();
		// 注册下载队列处理钩子
		add_action( 'ntw_process_media_queue', [ 'Notion_Download_Queue', 'process_queue' ] );
		// 定义所有钩子后，注册队列cron处理程序
		add_action( 'ntw_async_media', [ 'Notion_Download_Queue', 'process_queue' ] );
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

		// 清理统计缓存，确保后台显示最新数据
		Notion_To_WordPress_Helper::cache_delete( 'ntw_imported_posts_count' );
		Notion_To_WordPress_Helper::cache_delete( 'ntw_published_posts_count' );

		// 更新上次同步时间
		$options['last_sync_time'] = current_time( 'mysql' );
		update_option( 'notion_to_wordpress_options', $options );
		// 兼容后台统计：单独记录最新同步时间
		update_option( 'notion_to_wordpress_last_sync', $options['last_sync_time'] );
	}

	/**
	 * 核心导入逻辑，可由cron或手动调用。
	 *
	 * @since 1.0.10
	 * @param string $database_id 要导入的数据库ID
	 * @param array  $options 插件设置选项
	 */
	private function _core_import_process( string $database_id, array $options ): void {
		$lock_timeout = $options['lock_timeout'] ?? 120;

		// 锁机制已废弃，直接执行导入
		try {
			// 同步前：暂停对象缓存写入和分类/评论计数，提高性能
			wp_suspend_cache_addition( true );
			wp_defer_term_counting( true );
			if ( function_exists( 'wp_defer_comment_counting' ) ) {
				wp_defer_comment_counting( true );
			}

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
				return;
			}

			foreach ( $pages as $page ) {
				$this->notion_pages->import_notion_page( $page );
			}
		} catch ( Exception $e ) {
			Notion_To_WordPress_Helper::error_log( 'Notion import error: ' . $e->getMessage() );
		} finally {
			// 同步结束：恢复 WP 默认行为
			wp_suspend_cache_addition( false );
			wp_defer_term_counting( false );
			if ( function_exists( 'wp_defer_comment_counting' ) ) {
				wp_defer_comment_counting( false );
			}
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
				'title'          => 'title,标题',
				'status'         => 'status,状态',
				'post_type'      => 'type,类型',
				'date'           => 'date,日期',
				'excerpt'        => 'summary,摘要',
				'featured_image' => 'featured image,特色图片',
				'categories'     => 'category,分类',
				'tags'           => 'tags,标签',
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

		// 队列下载任务：每5分钟
		if ( ! wp_next_scheduled( 'ntw_process_media_queue' ) ) {
			wp_schedule_event( time() + 300, 'ntw_five_minutes', 'ntw_process_media_queue' );
		}

		// 为 postmeta 创建索引，加速 _notion_page_id 查询
		global $wpdb;
		$index_name = 'ntw_idx_notion_page_id';
		$has_index  = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = %s", $index_name ) );

		if ( ! $has_index ) {
			// meta_key + meta_value 前缀索引 (191) 以保持 mysql utf8mb4 兼容
			$wpdb->query( "ALTER TABLE {$wpdb->postmeta} ADD INDEX {$index_name} (meta_key(50), meta_value(191))" );
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
		wp_clear_scheduled_hook( 'ntw_process_media_queue' );

		// 刷新重写规则
		flush_rewrite_rules();
	}

	/**
	 * 在前端加载所需的样式与脚本。
	 *
	 * @since 1.1.0
	 */
	public function enqueue_frontend_scripts() {
		// 仅在单篇文章/页面中加载，避免首页或存档页多余资源
		if ( ! is_singular() ) {
			return;
		}

		// ------- 内容检测：若正文未包含公式 / Mermaid 则跳过加载 -------
		global $post;
		if ( $post instanceof \WP_Post ) {
			$cnt = $post->post_content ?? '';
			// 检测 Notion 导入时插入的占位 class、KaTeX/LaTeX 语法或 mermaid 标记
			if ( ! preg_match( '/notion\-(equation|chem)|\\\\\(|\\$\\$|<pre[^>]*class="[^"]*(mermaid)[^"]*"|class="mermaid"/i', $cnt ) ) {
				return;
			}
		}

		// 样式
		// 直接加载编译后的前端单文件样式（assets/css/frontend.css）
		wp_enqueue_style(
			$this->plugin_name . '-frontend',
			Notion_To_WordPress_Helper::plugin_url( 'assets/css/frontend.css' ),
			array(),
			$this->version
		);

		// ---------------- 公式相关（KaTeX） ----------------
		// KaTeX 样式
		wp_enqueue_style(
			'katex',
			'https://cdn.jsdelivr.net/npm/katex@0.16.22/dist/katex.min.css',
			array(),
			'0.16.22'
		);

		// KaTeX 主库
		wp_register_script(
			'katex',
			'https://cdn.jsdelivr.net/npm/katex@0.16.22/dist/katex.min.js',
			array(),
			'0.16.22',
			true
		);

		// mhchem 扩展（化学公式）依赖 KaTeX
		wp_register_script(
			'katex-mhchem',
			'https://cdn.jsdelivr.net/npm/katex@0.16.22/dist/contrib/mhchem.min.js',
			array( 'katex' ),
			'0.16.22',
			true
		);

		// ---------------- Mermaid ----------------
		wp_enqueue_script(
			'mermaid',
			'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js',
			array(),
			'11.7.0',
			true
		);

		// 处理公式与Mermaid渲染的脚本
		$deps = array( 'jquery', 'mermaid', 'katex-mhchem' );

		wp_enqueue_script(
			$this->plugin_name . '-math-mermaid',
			Notion_To_WordPress_Helper::plugin_url('assets/js/notion-math-mermaid.js'),
			$deps,
			$this->version,
			true
		);

		// -------- 减少渲染阻塞：为前端脚本添加 defer 属性 --------
		$defer_handles = [ 'mermaid', 'katex', 'katex-mhchem', $this->plugin_name . '-math-mermaid' ];
		add_filter( 'script_loader_tag', function( $tag, $handle ) use ( $defer_handles ) {
			if ( in_array( $handle, $defer_handles, true ) ) {
				if ( false === strpos( $tag, ' defer' ) ) {
					$tag = str_replace( '<script ', '<script defer ', $tag );
				}
			}
			return $tag;
		}, 10, 2 );

		// -------- 提前连接 CDN：DNS 预解析 + 预连接 --------
		$cdn_origin = '//cdn.jsdelivr.net';
		add_filter( 'wp_resource_hints', function ( $hints, $relation_type ) use ( $cdn_origin ) {
			if ( in_array( $relation_type, [ 'dns-prefetch', 'preconnect' ], true ) ) {
				if ( ! in_array( $cdn_origin, $hints, true ) ) {
					$hints[] = $cdn_origin;
				}
			}
			return $hints;
		}, 10, 2 );
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

		// 每5分钟一次
		if ( ! isset( $schedules['ntw_five_minutes'] ) ) {
			$schedules['ntw_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( '每5分钟', 'notion-to-wordpress' ),
			);
		}

		return $schedules;
	}

	/**
	 * 替换 notion-temp-image / notion-temp-file 占位符为本地附件 URL
	 */
	public function replace_temp_media_placeholders( string $content ): string {
		if ( false === strpos( $content, 'notion-temp-' ) ) {
			return $content;
		}

		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		if ( ! $loaded ) {
			return $content; // 解析失败则跳过
		}

		$changed = false;

		$xpath = new \DOMXPath( $dom );
		// 处理图片占位符
		foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " notion-temp-image ")]' ) as $figure ) {
			/** @var \DOMElement $figure */
			$url = $figure->getAttribute( 'data-ntw-url' );
			if ( ! $url ) {
				continue;
			}
			
			// 移除查询参数以提高匹配率
			$base_url = preg_replace( '/\?.*$/', '', $url );
			$aid = Notion_To_WordPress_Helper::get_attachment_id_by_url( $base_url );
			
			if ( $aid <= 0 ) {
				// 再尝试完整URL
				$aid = Notion_To_WordPress_Helper::get_attachment_id_by_url( $url );
				if ( $aid <= 0 ) {
					continue;
				}
			}
			
			$local_url = wp_get_attachment_url( $aid );
			if ( ! $local_url ) {
				continue;
			}
			// 更新 <img> src
			foreach ( $figure->getElementsByTagName( 'img' ) as $img ) {
				$img->setAttribute( 'src', $local_url );
			}
			// 移除占位符 class & 属性
			$figure->setAttribute( 'class', trim( str_replace( 'notion-temp-image', '', $figure->getAttribute( 'class' ) ) ) );
			$figure->removeAttribute( 'data-ntw-url' );
			$changed = true;
		}

		// 处理文件占位符
		foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " notion-temp-file ")]' ) as $box ) {
			/** @var \DOMElement $box */
			$url = $box->getAttribute( 'data-ntw-url' );
			if ( ! $url ) {
				continue;
			}
			
			// 移除查询参数以提高匹配率
			$base_url = preg_replace( '/\?.*$/', '', $url );
			$aid = Notion_To_WordPress_Helper::get_attachment_id_by_url( $base_url );
			
			if ( $aid <= 0 ) {
				// 再尝试完整URL
				$aid = Notion_To_WordPress_Helper::get_attachment_id_by_url( $url );
				if ( $aid <= 0 ) {
					continue;
				}
			}
			
			$local_url = wp_get_attachment_url( $aid );
			if ( ! $local_url ) {
				continue;
			}
			// 更新下载按钮 href
			foreach ( $box->getElementsByTagName( 'a' ) as $a ) {
				if ( $a->hasAttribute( 'href' ) ) {
					$a->setAttribute( 'href', $local_url );
					$a->textContent = __( '下载附件', 'notion-to-wordpress' );
				}
			}
			$box->setAttribute( 'class', trim( str_replace( 'notion-temp-file', '', $box->getAttribute( 'class' ) ) ) );
			$box->removeAttribute( 'data-ntw-url' );
			$changed = true;
		}

		if ( ! $changed ) {
			return $content;
		}

		$new_content = $dom->saveHTML();

		// 若在单篇文章上下文，更新数据库以永久保存
		if ( is_singular() && in_the_loop() ) {
			global $post;
			if ( $post instanceof \WP_Post && ! empty( $post->ID ) ) {
				remove_filter( 'the_content', [ $this, 'replace_temp_media_placeholders' ], 20 ); // 避免递归
				wp_update_post([
					'ID'           => $post->ID,
					'post_content' => $new_content,
				]);
				add_filter( 'the_content', [ $this, 'replace_temp_media_placeholders' ], 20 );
			}
		}

		return $new_content;
	}
} 