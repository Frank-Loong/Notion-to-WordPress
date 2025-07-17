<?php
declare(strict_types=1);

/**
 * 卸载脚本
 * 
 * 当插件从 WordPress 卸载时，此脚本负责清理所有相关的数据库选项、计划任务和（可选的）内容。
 * 
 * @since      1.0.5
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
    'notion_to_wordpress_last_update',
    'notion_to_wordpress_last_sync'
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

    // 删除插件下载到媒体库的所有附件
    notion_to_wordpress_delete_plugin_attachments();
}

// 最后删除插件设置
delete_option( 'notion_to_wordpress_options' );

// 清理日志文件
notion_to_wordpress_clear_logs();

// 刷新重写规则
flush_rewrite_rules();

/**
 * 删除插件下载到媒体库的所有附件
 *
 * @since 1.0.9
 */
function notion_to_wordpress_delete_plugin_attachments() {
    global $wpdb;

    // 查找所有由插件下载的附件
    // 方法1: 通过meta_key查找
    $attachment_ids = $wpdb->get_col(
        "SELECT DISTINCT post_id
         FROM {$wpdb->postmeta}
         WHERE meta_key = '_notion_attachment'
         OR meta_key = '_notion_file_url'
         OR meta_key = '_notion_original_url'"
    );

    // 方法2: 通过文件名模式查找（notion-开头的文件）
    $notion_attachments = $wpdb->get_col(
        "SELECT ID
         FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_title LIKE 'notion-%'"
    );

    // 合并两个结果
    $all_attachment_ids = array_unique(array_merge($attachment_ids, $notion_attachments));

    if (!empty($all_attachment_ids)) {
        foreach ($all_attachment_ids as $attachment_id) {
            // 获取附件文件路径
            $file_path = get_attached_file($attachment_id);

            // 删除附件记录
            wp_delete_attachment($attachment_id, true);

            // 如果文件仍然存在，手动删除
            if ($file_path && file_exists($file_path)) {
                @unlink($file_path);

                // 删除缩略图
                $metadata = wp_get_attachment_metadata($attachment_id);
                if ($metadata && isset($metadata['sizes'])) {
                    $upload_dir = wp_upload_dir();
                    $base_dir = dirname($file_path);

                    foreach ($metadata['sizes'] as $size) {
                        $thumb_path = $base_dir . '/' . $size['file'];
                        if (file_exists($thumb_path)) {
                            @unlink($thumb_path);
                        }
                    }
                }
            }
        }

        // 记录删除的附件数量
        error_log("Notion to WordPress: 已删除 " . count($all_attachment_ids) . " 个插件相关附件");
    }

    // 清理可能的孤立meta数据
    $wpdb->query(
        "DELETE FROM {$wpdb->postmeta}
         WHERE meta_key IN ('_notion_attachment', '_notion_file_url', '_notion_original_url')"
    );
}

/**
 * 清理插件的日志目录
 *
 * @since 2.0.0
 */
function notion_to_wordpress_clear_logs() {
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';

    if (!is_dir($log_dir)) {
        return;
    }

    // 递归删除目录及其内容
    notion_to_wordpress_recursive_delete($log_dir);
}

/**
 * 递归删除目录和文件
 *
 * @since 2.0.0
 * @param string $dir 要删除的目录路径
 */
function notion_to_wordpress_recursive_delete($dir) {
    if (!file_exists($dir)) {
        return;
    }
    
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it,
                 RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
        if ($file->isDir()){
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($dir);
}