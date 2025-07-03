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
        // 目前 Notion_Pages::import_notion_page 仅返回布尔值；
        // 后续可扩展以区分新建 / 更新 / 跳过。
        $success = $service->import_notion_page( $page );

        return [
            'imported' => $success ? 1 : 0,
            'updated'  => 0,
            'skipped'  => $success ? 0 : 1,
            'failed'   => $success ? 0 : 1,
            'errors'   => $success ? [] : [ 'Failed to import page ' . ( $page['id'] ?? '' ) ],
        ];
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
        $lock = new Notion_To_WordPress_Lock( $this->database_id, $this->lock_timeout );
        if ( ! $lock->acquire() ) {
            throw new \Exception( '已有同步任务正在运行，请稍后再试' );
        }

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
                $pages = $this->api->get_database_pages( $this->database_id );
            }

            $stats = [
                'total'    => count( $pages ),
                'imported' => 0,
                'updated'  => 0,
            ];

            foreach ( $pages as $page ) {
                $result = $this->strategy->import_page( $this->page_service, $page, $force_update );
                $stats['imported'] += $result['imported'];
                $stats['updated']  += $result['updated'];
            }

            // 触发下载队列处理
            if ( class_exists( 'Notion_Download_Queue' ) ) {
                Notion_Download_Queue::process_queue();
            }

            return $stats;
        } finally {
            $lock->release();
        }
    }
} 