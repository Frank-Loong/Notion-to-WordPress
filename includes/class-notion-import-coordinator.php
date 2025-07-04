<?php
declare(strict_types=1);

/**
 * Notion 页面批量导入协调器 + 策略抽象
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 */

interface Notion_Page_Importer_Strategy {
    /**
     * 处理单个页面导入，返回统计数据
     *
     * @param Notion_Pages $service
     * @param array        $page
     * @param bool         $force_update
     *
     * @return array{imported:int,updated:int,skipped:int,failed:int,errors:array<string>}
     */
    public function import_page( Notion_Pages $service, array $page, bool $force_update = false ): array;
}

/**
 * 默认页面导入策略：直接调用 Notion_Pages::import_notion_page
 */
class Notion_Default_Page_Importer_Strategy implements Notion_Page_Importer_Strategy {

    public function import_page( Notion_Pages $service, array $page, bool $force_update = false ): array {
        $page_id = $page['id'] ?? '';

        try {
            // 检查页面是否已存在
            $existing_post_id = $service->get_post_by_notion_id($page_id);

            // 如果不强制更新且页面已存在，跳过
            if (!$force_update && $existing_post_id) {
                return [
                    'imported' => 0,
                    'updated'  => 0,
                    'skipped'  => 1,
                    'failed'   => 0,
                    'errors'   => [],
                ];
            }

            // 导入页面
            $success = $service->import_notion_page( $page );

            if ($success) {
                return [
                    'imported' => $existing_post_id ? 0 : 1,
                    'updated'  => $existing_post_id ? 1 : 0,
                    'skipped'  => 0,
                    'failed'   => 0,
                    'errors'   => [],
                ];
            } else {
                return [
                    'imported' => 0,
                    'updated'  => 0,
                    'skipped'  => 0,
                    'failed'   => 1,
                    'errors'   => [ 'Failed to import page ' . $page_id ],
                ];
            }
        } catch (Exception $e) {
            return [
                'imported' => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'failed'   => 1,
                'errors'   => [ 'Exception importing page ' . $page_id . ': ' . $e->getMessage() ],
            ];
        }
    }
}

/**
 * 批量导入协调器：负责锁管理、分页遍历、统计汇总与异常处理
 */
class Notion_Import_Coordinator {

    private Notion_Pages $page_service;
    private Notion_API   $api;
    private string       $database_id;
    private int          $lock_timeout;
    private Notion_Page_Importer_Strategy $strategy;

    public function __construct(
        Notion_Pages $page_service,
        Notion_API   $api,
        string       $database_id,
        int          $lock_timeout = 300,
        ?Notion_Page_Importer_Strategy $strategy = null
    ) {
        $this->page_service = $page_service;
        $this->api          = $api;
        $this->database_id  = $database_id;
        $this->lock_timeout = $lock_timeout;
        $this->strategy     = $strategy ?? new Notion_Default_Page_Importer_Strategy();
    }

    /**
     * 运行批量导入
     *
     * @param bool   $force_update     是否强制覆盖更新
     * @param string $filter_page_id   仅导入指定页面
     *
     * @return array{total:int,imported:int,updated:int}
     * @throws \Exception             获取锁或 API 失败时抛出
     */
    public function run( bool $force_update = false, string $filter_page_id = '' ): array {
        $lock = null;

        try {
            // 获取需处理页面
            $pages = [];
            if ( $filter_page_id ) {
                $single = $this->api->get_page( $filter_page_id );
                if ( empty( $single ) ) {
                    throw new \Exception( '无法获取指定页面 ' . $filter_page_id );
                }
                $pages[] = $single;
            } else {
                // 构造增量同步过滤器（仅拉取自上次同步后有改动的页面）
                $filter = array();
                if ( ! $force_update ) {
                    $options = get_option( 'notion_to_wordpress_options', array() );
                    if ( ! empty( $options['last_sync_time'] ) ) {
                        $iso_time = date( 'c', strtotime( $options['last_sync_time'] ) );
                        $filter   = array(
                            'timestamp'       => 'last_edited_time',
                            'last_edited_time' => array(
                                'after' => $iso_time,
                            ),
                        );
                    }
                }

                $pages = $this->api->get_database_pages( $this->database_id, $filter );
            }

            $stats = [
                'total'    => count( $pages ),
                'imported' => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'failed'   => 0,
                'errors'   => [],
            ];

            // 使用内存管理器进行批量处理
            if (class_exists('Notion_To_WordPress_Memory_Manager')) {
                $batch_callback = function($page) use ($force_update, &$stats) {
                    $result = $this->strategy->import_page( $this->page_service, $page, $force_update );
                    $stats['imported'] += $result['imported'];
                    $stats['updated']  += $result['updated'];
                    $stats['skipped']  += $result['skipped'];
                    $stats['failed']   += $result['failed'];

                    if (!empty($result['errors'])) {
                        $stats['errors'] = array_merge($stats['errors'], $result['errors']);
                    }

                    return $result;
                };

                Notion_To_WordPress_Memory_Manager::process_in_batches($pages, $batch_callback, 20);
            } else {
                // 回退到原始处理方式
                foreach ( $pages as $page ) {
                    $result = $this->strategy->import_page( $this->page_service, $page, $force_update );
                    $stats['imported'] += $result['imported'];
                    $stats['updated']  += $result['updated'];
                    $stats['skipped']  += $result['skipped'];
                    $stats['failed']   += $result['failed'];

                    if (!empty($result['errors'])) {
                        $stats['errors'] = array_merge($stats['errors'], $result['errors']);
                    }
                }
            }

            // 触发下载队列处理
            if ( class_exists( 'Notion_Download_Queue' ) ) {
                Notion_Download_Queue::process_queue();
            }

            return $stats;
        } finally {
            // 无锁释放
        }
    }
} 