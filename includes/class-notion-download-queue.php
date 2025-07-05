<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 延迟下载队列处理类
 *
 * 按队列批量下载附件，并发少量请求，完成后更新文章内容和特色图。
 *
 * @since 1.1.0
 * @package Notion_To_WordPress
 */

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
        // 使用缓存暂停提高并发安全性
        wp_suspend_cache_invalidation( true );
        $queue   = get_option( self::OPTION_QUEUE, [] );
        $queue[] = $task;
        update_option( self::OPTION_QUEUE, $queue, false );
        wp_suspend_cache_invalidation( false );

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
        // 使用缓存暂停提高并发安全性
        wp_suspend_cache_invalidation( true );
        $queue = get_option( self::OPTION_QUEUE, [] );
        if ( empty( $queue ) ) {
            wp_suspend_cache_invalidation( false );
            return [];
        }
        $batch  = array_splice( $queue, 0, $size );
        update_option( self::OPTION_QUEUE, $queue, false );
        wp_suspend_cache_invalidation( false );
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
     *
     * @since 1.1.0 使用统一的媒体处理器
     */
    private static function handle_single( array $t ): bool {
        $url = $t['url'] ?? '';
        if ( empty( $url ) ) {
            return false;
        }

        // 额外的私网检查（媒体处理器已包含基本URL验证）
        $parsed = wp_parse_url( $url );
        if ( $parsed && isset( $parsed['host'] ) && self::is_private_host( $parsed['host'] ) ) {
            Notion_To_WordPress_Helper::error_log( '下载跳过：目标位于私网/本地网段 ' . $url, 'Download Queue' );
            return false;
        }

        Notion_To_WordPress_Helper::debug_log( '处理下载: ' . $url . ' (retry ' . ( $t['retry'] ?? 0 ) . ')', 'Download Queue', Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG );

        // 使用统一的媒体处理器
        $result = Notion_Media_Handler::download_and_process(
            $url,
            (int) ($t['post_id'] ?? 0),
            (bool) ($t['is_featured'] ?? false),
            $t['caption'] ?? '',
            $t['alt_text'] ?? ''
        );

        if ( $result['success'] ) {
            // 存储原始URL用于去重
            if ( isset( $result['attachment_id'] ) ) {
                update_post_meta( $result['attachment_id'], '_notion_media_url', $url );
            }
            return true;
        }

        // 处理失败，考虑重试
        $retry = (int) ( $t['retry'] ?? 0 );
        if ( $retry < self::MAX_RETRY ) {
            $t['retry'] = $retry + 1;
            // 将任务重新入队
            $queue = get_option( self::OPTION_QUEUE, [] );
            $queue[] = $t;
            update_option( self::OPTION_QUEUE, $queue, false );

            // 退避：延后 60 秒再次调度处理
            if ( ! wp_next_scheduled( 'ntw_async_media' ) ) {
                wp_schedule_single_event( time() + 60, 'ntw_async_media' );
            }
        }

        Notion_To_WordPress_Helper::error_log( '队列下载失败: ' . ($result['error'] ?? '未知错误'), 'Download Queue' );
        return false;
    }



    /**
     * 判断主机是否解析到私网 / 保留 IP
     */
    private static function is_private_host( string $host ): bool {
        $ip = gethostbyname( $host );
        // 若解析失败直接视为非法
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return true;
        }

        return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
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