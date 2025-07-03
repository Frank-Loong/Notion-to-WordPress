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
    const CONCURRENCY    = 2; // 默认每批并发数，可通过 ntw_download_queue_concurrency 过滤器覆盖
    const MAX_RETRY      = 3; // 每任务最大重试次数

    /**
     * 推送任务到队列
     *
     * @param array $task [type,url,post_id,is_featured,caption]
     */
    public static function push( array $task ): void {
        $queue   = get_option( self::OPTION_QUEUE, [] );
        $queue[] = $task;
        update_option( self::OPTION_QUEUE, $queue, false );

        Notion_To_WordPress_Helper::debug_log( '入队任务: ' . ( $task['url'] ?? 'unknown' ) . ' | 队列长度: ' . count( $queue ), 'Download Queue', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );

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
        // 允许开发者通过过滤器调整并发批大小
        $concurrency = (int) apply_filters( 'ntw_download_queue_concurrency', self::CONCURRENCY );
        $concurrency = max( 1, $concurrency ); // 保底 1

        $batch = self::pop_batch( $concurrency );
        if ( empty( $batch ) ) {
            return;
        }

        Notion_To_WordPress_Helper::debug_log( '开始处理下载批次, 大小: ' . count( $batch ), 'Download Queue', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ( $batch as $task ) {
            $result = self::handle_single( $task );
            self::log_history( $task, $result );
        }

        Notion_To_WordPress_Helper::debug_log( '批次处理完成, 队列剩余: ' . self::size(), 'Download Queue', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );

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

        Notion_To_WordPress_Helper::debug_log( '处理下载: ' . $url . ' (retry ' . ( $t['retry'] ?? 0 ) . ')', 'Download Queue', Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG );

        // 下载到临时文件
        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            Notion_To_WordPress_Helper::error_log( '队列下载失败: ' . $tmp->get_error_message(), 'Notion Queue' );
            // 若未超过重试次数，则重新入队，稍后再试
            $retry = (int) ( $t['retry'] ?? 0 );
            if ( $retry < self::MAX_RETRY ) {
                $t['retry'] = $retry + 1;
                // 退避：延后 60 秒再加入队列
                wp_schedule_single_event( time() + 60, 'ntw_async_media', [ $t ] );
            }
            return false;
        }

        // 使用指定文件名或从URL解析
        $file_name = '';
        if (!empty($t['name'])) {
            $file_name = sanitize_file_name($t['name']);
            Notion_To_WordPress_Helper::debug_log( '使用指定文件名: ' . $file_name, 'Download Queue', Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG );
        } else {
            $file_name = basename( parse_url( $url, PHP_URL_PATH ) );
            if ( empty( $file_name ) ) {
                $file_name = 'notion-file-' . time();
            }
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

        Notion_To_WordPress_Helper::debug_log( '下载成功并插入附件 ID: ' . $attachment_id, 'Download Queue', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );

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
                // 使用正则匹配包含可能 querystring 的原始 URL
                $pattern  = '#'. preg_quote( $url, '#' ) . '(?:\?[^"\']*)?#';
                $updated  = preg_replace( $pattern, $attachment_url, $post->post_content );
                if ( null !== $updated ) {
                    wp_update_post( [ 'ID' => $post_id, 'post_content' => $updated ] );
                }
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