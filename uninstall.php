<?php
declare(strict_types=1);

/**
 * 插件卸载时运行的代码
 *
 * @since      1.0.5
 * @package    Notion_To_WordPress
 */

// 如果不是WordPress调用，则退出
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 插件卸载时的清理工作

// 删除选项
$options = array(
    'notion_to_wordpress_api_key',
    'notion_to_wordpress_database_id',
    'notion_to_wordpress_image_size',
    'notion_to_wordpress_column_tag',
    'notion_to_wordpress_webhook_secret',
    'notion_to_wordpress_webhook_path',
    'notion_to_wordpress_auto_import',
    'notion_to_wordpress_import_schedule',
    'notion_to_wordpress_webhook_enabled',
    'notion_to_wordpress_custom_css',
    'notion_to_wordpress_global_cron_interval',
    'notion_to_wordpress_last_refresh',
    'notion_to_wordpress_last_update'
);

// 新版插件将所有设置存储于单一数组中
$plugin_options = get_option( 'notion_to_wordpress_options', [] );
$delete_content  = isset( $plugin_options['delete_on_uninstall'] ) ? (int) $plugin_options['delete_on_uninstall'] : 0;

foreach ($options as $option) {
    delete_option($option);
}

// 清理计划任务
wp_clear_scheduled_hook('notion_cron_import');

// 查找所有使用计划任务的页面并清理
$query = new WP_Query(array(
    'post_type' => array('post', 'page'),
    'meta_key' => 'notion_to_wordpress_cron_interval',
    'meta_compare' => 'EXISTS',
    'posts_per_page' => -1,
    'fields' => 'ids'
));

if ($query->have_posts()) {
    foreach ($query->posts as $post_id) {
        $page_id = get_post_meta($post_id, '_notion_page_id', true);
        if ($page_id) {
            wp_clear_scheduled_hook('notion_to_wordpress_cron_update', array($page_id));
        }
    }
}

// 根据设置决定是否删除内容
if ($delete_content) {
    // 查找并删除所有导入的内容
    $posts_query = new WP_Query(array(
        'post_type' => array('post', 'page'),
        'meta_key' => '_notion_page_id',
        'meta_compare' => 'EXISTS',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ));

    if ($posts_query->have_posts()) {
        foreach ($posts_query->posts as $post_id) {
            wp_delete_post($post_id, true); // 第二个参数为true表示彻底删除，不放入回收站
        }
    }

    // 删除notion_images自定义文章类型中的所有内容
    $images_query = new WP_Query(array(
        'post_type' => 'notion_images',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ));

    if ($images_query->have_posts()) {
        foreach ($images_query->posts as $image_id) {
            wp_delete_post($image_id, true);
        }
    }
}

// 最后删除插件设置
delete_option( 'notion_to_wordpress_options' );

// 刷新重写规则
flush_rewrite_rules(); 