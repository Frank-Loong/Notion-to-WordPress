<?php
declare(strict_types=1);

/**
 * 插件引导文件
 * 
 * WordPress 读取此文件以在插件管理后台生成插件信息，
 * 此文件还包括插件使用的所有依赖项，注册激活和停用函数，并定义一个启动插件的函数。
 * 
 * @link              https://github.com/Frank-Loong/Notion-to-WordPress
 * @since             1.0.9
 * @package           Notion_To_WordPress
 * @wordpress-plugin
 * Plugin Name:       Notion to WordPress
 * Plugin URI:        https://github.com/Frank-Loong/Notion-to-WordPress
 * Description:       从 Notion 数据库同步内容到 WordPress 文章，支持增量同步、定时同步和 Webhook 同步。
 * Version:           2.0.0-beta.1
 * Author:            Frank-Loong
 * Author URI:        https://github.com/Frank-Loong
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       notion-to-wordpress
 * Domain Path:       /languages
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 主插件文件路径
 *
 * @since 1.1.0
 */
define( 'NOTION_TO_WORDPRESS_FILE', __FILE__ );

/**
 * 插件的当前版本号
 */
define( 'NOTION_TO_WORDPRESS_VERSION', '2.0.0-beta.1' );

/**
 * 性能模式常量 - 启用后将减少日志记录和统计收集
 */
if ( ! defined( 'NOTION_PERFORMANCE_MODE' ) ) {
    define( 'NOTION_PERFORMANCE_MODE', true ); // 默认启用性能模式
}

/**
 * 插件运行时自动加载器。发布包不会包含 Composer 的 vendor/ 目录，因此类加载必须
 * 由插件自身提供。
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/framework/Autoloader.php';
\NTWP\Framework\Autoloader::register( plugin_dir_path( __FILE__ ) );



/**
 * 插件激活时运行的代码
 * 此操作由 includes/framework/Main.php 实现。
 *
 * 支持 WordPress Multisite：网络激活时为每个子站初始化默认选项与 cron。
 */
function activate_notion_to_wordpress() {
	// 初始化现代异步处理系统
	init_modern_async_system();

	if ( is_multisite() ) {
		// 网络激活：为每个子站初始化默认选项
		$sites = get_sites( array( 'number' => 0 ) );
		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );
			NTWP\Framework\Main::activate();
			restore_current_blog();
		}
	} else {
		// 单站点激活
		NTWP\Framework\Main::activate();
	}
}

/**
 * 初始化现代异步处理系统
 *
 * @since 2.0.0-beta.2
 */
function init_modern_async_system() {
	// 记录现代异步系统启动
	if (class_exists('NTWP\\Core\\Logger')) {
		NTWP\Core\Logger::info_log('现代异步处理系统已启动，无需外部依赖', 'Plugin Activation');
	}
}

/**
 * 插件停用时运行的代码
 * 此操作由 includes/framework/Main.php 实现。
 *
 * 支持 WordPress Multisite：网络停用时清理每个子站的 cron。
 */
function deactivate_notion_to_wordpress() {
	if ( is_multisite() ) {
		// 网络停用：清理每个子站的 cron
		$sites = get_sites( array( 'number' => 0 ) );
		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );
			wp_clear_scheduled_hook( 'notion_cron_import' );
			wp_clear_scheduled_hook( 'notion_to_wordpress_cron_update' );
			wp_clear_scheduled_hook( 'notion_to_wordpress_log_cleanup' );
			restore_current_blog();
		}
	} else {
		// 单站点停用
		wp_clear_scheduled_hook( 'notion_cron_import' );
		wp_clear_scheduled_hook( 'notion_to_wordpress_cron_update' );
		wp_clear_scheduled_hook( 'notion_to_wordpress_log_cleanup' );
	}
}

register_activation_hook( NOTION_TO_WORDPRESS_FILE, 'activate_notion_to_wordpress' );
register_deactivation_hook( NOTION_TO_WORDPRESS_FILE, 'deactivate_notion_to_wordpress' );

/**
 * 开始执行插件
 *
 * 由于插件中的所有内容都通过钩子注册，
 * 因此从文件的这一点开始启动插件不会影响页面生命周期。
 *
 * @since    1.0.0
 */
function run_notion_to_wordpress() {
	$plugin = new NTWP\Framework\Main();
	$plugin->run();
}

run_notion_to_wordpress();
