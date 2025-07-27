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
 * 核心依赖加载
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/utils/class-notion-to-wordpress-helper.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/utils/class-notion-database-helper.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/core/class-notion-memory-manager.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/core/class-notion-adaptive-batch.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/core/class-notion-algorithm-optimizer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/services/class-notion-parallel-image-processor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/services/class-notion-incremental-detector.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/utils/class-notion-async-task-scheduler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/utils/class-notion-data-preloader.php';
// 修复：加载关键的流式处理器类
require_once plugin_dir_path( __FILE__ ) . 'includes/utils/class-notion-stream-processor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/utils/class-notion-concurrent-network-manager.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/utils/class-notion-network-retry.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/utils/class-notion-smart-api-merger.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/framework/class-notion-to-wordpress.php';

/**
 * 插件激活时运行的代码
 * 此操作的文档位于 includes/class-notion-to-wordpress.php
 */
function activate_notion_to_wordpress() {
	// 检查Action Scheduler依赖
	check_action_scheduler_dependency();

	Notion_To_WordPress::activate();
}

/**
 * 检查Action Scheduler依赖
 *
 * @since 2.0.0-beta.1
 */
function check_action_scheduler_dependency() {
	// 检查Action Scheduler是否可用
	if (!function_exists('as_schedule_single_action')) {
		// 显示管理员通知
		add_action('admin_notices', function() {
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p><strong>Notion to WordPress:</strong> 建议安装 WooCommerce 或 Action Scheduler 插件以获得更好的异步处理性能。插件将使用WordPress Cron作为回退方案。</p>';
			echo '</div>';
		});

		// 记录到日志
		if (class_exists('Notion_Logger')) {
			Notion_Logger::warning_log(
				'Action Scheduler不可用，将使用WordPress Cron作为回退方案。建议安装WooCommerce或Action Scheduler插件。',
				'Plugin Activation'
			);
		}
	} else {
		// Action Scheduler可用，记录成功信息
		if (class_exists('Notion_Logger') && !defined('NOTION_PERFORMANCE_MODE')) {
			Notion_Logger::info_log('Action Scheduler已检测到，异步处理性能将得到优化', 'Plugin Activation');
		}
	}
}

/**
 * 插件停用时运行的代码
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
 * 开始执行插件
 *
 * 由于插件中的所有内容都通过钩子注册，
 * 因此从文件的这一点开始启动插件不会影响页面生命周期。
 *
 * @since    1.0.0
 */
function run_notion_to_wordpress() {
	$plugin = new Notion_To_WordPress();
	$plugin->run();

	// 初始化异步任务调度器
	if (class_exists('Notion_Async_Task_Scheduler')) {
		Notion_Async_Task_Scheduler::init();
	}
}

run_notion_to_wordpress(); 