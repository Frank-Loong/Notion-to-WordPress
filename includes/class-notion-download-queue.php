<?php
/**
 * 延迟下载队列处理类
 *
 * 按队列批量下载附件，并发少量请求，完成后更新文章内容和特色图。
 *
 * @since 1.1.0
 * @package Notion_To_WordPress
 */

declare(strict_types=1);

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Notion_Download_Queue {
    const OPTION_QUEUE   = 'ntw_download_queue';
    const OPTION_HISTORY = 'ntw_download_history';
    const MAX_HISTORY    = 100;
    const CONCURRENCY    = 5; // 每批并发数

    /**
     * 推送任务到队列
     *
     * @param array $task [type,url,post_id,is_featured,caption]
     */
    public static function push( array $task ): void {
        $queue   = get_option( self::OPTION_QUEUE, [] );
        $queue[] = $task;
        update_option( self::OPTION_QUEUE, $queue, false );

        // 调度一次性后台事件，尽快处理队列
        if ( ! wp_next_scheduled( 'ntw_async_media' ) ) {
            wp_schedule_single_event( time() + 1, 'ntw_async_media' );
        }
    }

    /**
     * 获取并弹出前 N 条任务
     */
    private static function pop_batch( int $size ): array {
        $queue = get_option( self::OPTION_QUEUE, [] );
        if ( empty( $queue ) ) {
            return [];
        }
        $batch  = array_splice( $queue, 0, $size );
        update_option( self::OPTION_QUEUE, $queue, false );
        return $batch;
    }

    /**
     * 当前队列长度
     */
    public static function size(): int {
        return count( get_option( self::OPTION_QUEUE, [] ) );
    }

    /**
     * 处理一批任务（cron 调用）
     */
    public static function process_queue(): void {
        $batch = self::pop_batch( self::CONCURRENCY );
        if ( empty( $batch ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ( $batch as $task ) {
            $result = self::handle_single( $task );
            self::log_history( $task, $result );
        }

        // 若队列仍有剩余任务，继续调度下一次事件
        if ( self::size() > 0 && ! wp_next_scheduled( 'ntw_async_media' ) ) {
            wp_schedule_single_event( time() + 60, 'ntw_async_media' ); // 下一分钟继续
        }
    }

    /**
     * 处理单个任务
     */
    private static function handle_single( array $t ): bool {
        $url   = $t['url'] ?? '';
        if ( empty( $url ) ) {
            return false;
        }

        // 下载到临时文件
        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            Notion_To_WordPress_Helper::error_log( '队列下载失败: ' . $tmp->get_error_message(), 'Notion Queue' );
            return false;
        }

        $file_name = basename( parse_url( $url, PHP_URL_PATH ) );
        if ( empty( $file_name ) ) {
            $file_name = 'notion-file-' . time();
        }

        $file = [
            'name'     => $file_name,
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file, (int) $t['post_id'], $t['caption'] ?? '' );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            Notion_To_WordPress_Helper::error_log( 'media_handle_sideload 错误: ' . $attachment_id->get_error_message(), 'Notion Queue' );
            return false;
        }

        // 标记来源，便于卸载清理
        update_post_meta( $attachment_id, '_notion_original_url', esc_url( $url ) );

        $post_id = (int) $t['post_id'];
        if ( $post_id > 0 ) {
            if ( ! empty( $t['is_featured'] ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
            }
            // 替换正文 URL
            $attachment_url = wp_get_attachment_url( $attachment_id );
            $post           = get_post( $post_id );
            if ( $post && strpos( $post->post_content, $url ) !== false ) {
                $updated = str_replace( $url, $attachment_url, $post->post_content );
                wp_update_post( [ 'ID' => $post_id, 'post_content' => $updated ] );
            }
        }

        return true;
    }

    /**
     * 记录历史
     */
    private static function log_history( array $task, bool $success ): void {
        $history   = get_option( self::OPTION_HISTORY, [] );
        $history[] = [
            'time'   => current_time( 'mysql' ),
            'url'    => $task['url'] ?? '',
            'post'   => $task['post_id'] ?? 0,
            'status' => $success ? 'success' : 'fail',
        ];
        // 保留最后 MAX_HISTORY 条
        if ( count( $history ) > self::MAX_HISTORY ) {
            $history = array_slice( $history, - self::MAX_HISTORY );
        }
        update_option( self::OPTION_HISTORY, $history, false );
    }

    /**
     * 获取历史记录
     */
    public static function history(): array {
        return get_option( self::OPTION_HISTORY, [] );
    }
} 