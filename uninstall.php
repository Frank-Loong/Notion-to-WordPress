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
wp_clear_scheduled_hook('ntw_process_media_queue');

// 查找所有使用计划任务的页面并清理（分批处理，降低内存占用）
$paged = 1;
$batch = 500;
do {
    $query = new WP_Query(array(
        'post_type'      => array('post', 'page'),
        'meta_key'       => 'notion_to_wordpress_cron_interval',
        'meta_compare'   => 'EXISTS',
        'posts_per_page' => $batch,
        'fields'         => 'ids',
        'paged'          => $paged,
        'no_found_rows'  => true,
    ));

    if ($query->have_posts()) {
        foreach ($query->posts as $post_id) {
            $page_id = get_post_meta($post_id, '_notion_page_id', true);
            if ($page_id) {
                wp_clear_scheduled_hook('notion_to_wordpress_cron_update', array($page_id));
            }
        }
    }
    wp_reset_postdata();
    $paged++;
} while ($query->have_posts());

// 根据设置决定是否删除内容
if ($delete_content) {
    // 批量删除导入内容，500 条一批
    $paged = 1;
    do {
        $posts_query = new WP_Query(array(
            'post_type'      => array('post', 'page'),
            'meta_key'       => '_notion_page_id',
            'meta_compare'   => 'EXISTS',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'paged'          => $paged,
            'no_found_rows'  => true,
        ));

        if ($posts_query->have_posts()) {
            foreach ($posts_query->posts as $post_id) {
                wp_delete_post($post_id, true);
            }
        }
        wp_reset_postdata();
        $paged++;
    } while ($posts_query->have_posts());

    // 删除notion_images自定义文章类型中的所有内容（分批处理）
    $batch_size = 100;
    $offset = 0;

    do {
        $images_query = new WP_Query(array(
            'post_type' => 'notion_images',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        if ($images_query->have_posts()) {
            foreach ($images_query->posts as $image_id) {
                wp_delete_post($image_id, true);
            }
        }

        wp_reset_postdata();
        $offset += $batch_size;
    } while ($images_query->have_posts());

    // 删除由插件下载到媒体库的附件（通过 _notion_original_url 标识），分批500
    $paged = 1;
    do {
        $attachments = new WP_Query(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 500,
            'meta_query'     => array(
                array(
                    'key'     => '_notion_original_url',
                    'compare' => 'EXISTS',
                ),
            ),
            'fields' => 'ids',
            'paged'  => $paged,
            'no_found_rows' => true,
        ));

        if ( $attachments->have_posts() ) {
            foreach ( $attachments->posts as $att_id ) {
                wp_delete_attachment( $att_id, true );
            }
        }
        wp_reset_postdata();
        $paged++;
    } while ( $attachments->have_posts() );
} else {
    // 保留文章但清理 post_meta，分批500
    $paged = 1;
    do {
        $posts_query = new WP_Query( array(
            'post_type'      => array( 'post', 'page' ),
            'meta_key'       => '_notion_page_id',
            'meta_compare'   => 'EXISTS',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'paged'          => $paged,
            'no_found_rows'  => true,
        ) );

        if ( $posts_query->have_posts() ) {
            foreach ( $posts_query->posts as $post_id ) {
                delete_post_meta( $post_id, '_notion_page_id' );
            }
        }
        wp_reset_postdata();
        $paged++;
    } while ( $posts_query->have_posts() );
}

// 最后删除插件设置
delete_option( 'notion_to_wordpress_options' );

// ---------------- 额外清理 ----------------
// 1. 删除日志目录（/uploads/notion-to-wordpress-logs）
$upload_dir = wp_upload_dir();
$log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'notion-to-wordpress-logs';

if ( is_dir( $log_dir ) ) {
    // 递归删除目录
    $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $log_dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $fileinfo ) {
        $path = $fileinfo->getRealPath();
        if ( $fileinfo->isDir() ) {
            // 尝试删除子目录
            if ( is_writable( $path ) ) {
                if ( ! rmdir( $path ) ) {
                    error_log( '[Notion-to-WP Uninstall] 无法删除目录: ' . $path . ' (权限或非空目录)' );
                }
            } else {
                error_log( '[Notion-to-WP Uninstall] 目录无写权限: ' . $path );
            }
        } else {
            // 尝试删除文件
            if ( is_writable( $path ) ) {
                if ( ! unlink( $path ) ) {
                    error_log( '[Notion-to-WP Uninstall] 无法删除文件: ' . $path . ' (可能被占用)' );
                }
            } else {
                error_log( '[Notion-to-WP Uninstall] 文件无写权限: ' . $path );
            }
        }
    }
}

// 2. 清理与插件相关的 transient 缓存（ntw_ 前缀）
global $wpdb;
$transients = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_ntw_%',
        '_transient_timeout_ntw_%'
    )
);

foreach ( $transients as $transient_option ) {
    // WordPress 内部会同时有 timeout 和 data 两条记录，这里直接 delete_option 即可
    delete_option( $transient_option );
}

// 刷新重写规则
flush_rewrite_rules();

// ---------- 清理对象缓存 ----------
if ( function_exists( 'wp_cache_flush' ) ) {
    // Flushing entire object cache to确保删除 ntw 前缀缓存；在卸载阶段影响可忽略
    wp_cache_flush();
}

// 移除最后同步时间等单独记录的选项
delete_option( 'notion_to_wordpress_last_sync' );
delete_option( 'ntw_download_queue' );
delete_option( 'ntw_download_history' ); 