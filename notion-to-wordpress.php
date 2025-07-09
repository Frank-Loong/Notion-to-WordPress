<?php
declare(strict_types=1);

/**
 * 插件引导文件
 *
 * WordPress 读取此文件以在插件管理后台生成插件信息。
 * 此文件还包括插件使用的所有依赖项，注册激活和停用函数，并定义一个启动插件的函数。
 *
 * 本项目参考了以下优秀的开源项目：
 * - NotionNext (https://github.com/tangly1024/NotionNext)
 * - Elog (https://github.com/LetTTGACO/elog)
 * - notion-content (https://github.com/pchang78/notion-content)
 *
 * @link              https://github.com/Frank-Loong/Notion-to-WordPress
 * @since             1.0.9
 * @package           Notion_To_WordPress
 *
 * @wordpress-plugin
 * Plugin Name:       Notion to WordPress
 * Plugin URI:        https://github.com/Frank-Loong/Notion-to-WordPress
 * Description:       从 Notion 数据库同步内容到 WordPress 文章，支持自动同步、手动同步和 Webhook 触发。
 * Version:           1.2.0
 * Author:            Frank-Loong
 * Author URI:        https://github.com/Frank-Loong
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       notion-to-wordpress
 * Domain Path:       /languages
 */

// 如果此文件被直接调用，则中止。
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * 主插件文件路径
 *
 * @since 1.1.0
 */
define( 'NOTION_TO_WORDPRESS_FILE', __FILE__ );

/**
 * 插件的当前版本号。
 */
define( 'NOTION_TO_WORDPRESS_VERSION', '1.2.0' );

/**
 * 核心依赖加载
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-notion-to-wordpress-helper.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-notion-to-wordpress.php';

/**
 * 插件激活时运行的代码。
 * 此操作的文档位于 includes/class-notion-to-wordpress.php
 */
function activate_notion_to_wordpress() {
	Notion_To_WordPress::activate();
}

/**
 * 插件停用时运行的代码。
 * 此操作的文档位于 includes/class-notion-to-wordpress.php
 */
function deactivate_notion_to_wordpress() {
	// Clear any scheduled cron jobs
	wp_clear_scheduled_hook('notion_cron_import');
	wp_clear_scheduled_hook('notion_to_wordpress_cron_update');
	wp_clear_scheduled_hook('notion_to_wordpress_log_cleanup');
}

register_activation_hook( NOTION_TO_WORDPRESS_FILE, 'activate_notion_to_wordpress' );
register_deactivation_hook( NOTION_TO_WORDPRESS_FILE, 'deactivate_notion_to_wordpress' );

/**
 * 开始执行插件。
 *
 * 由于插件中的所有内容都通过钩子注册，
 * 因此从文件的这一点开始启动插件不会影响页面生命周期。
 *
 * @since    1.0.0
 */
function run_notion_to_wordpress() {
	$plugin = new Notion_To_WordPress();
	$plugin->run();
}

run_notion_to_wordpress(); 