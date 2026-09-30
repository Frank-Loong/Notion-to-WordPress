<?php
declare(strict_types=1);

use NTWP\Core\Task_Executor;
use NTWP\Framework\Autoloader;

final class TaskExecutorSourceIsolationTest extends \PHPUnit\Framework\TestCase {
    protected function setUp(): void {
        Autoloader::register( dirname( __DIR__, 2 ) );
    }

    public function test_batch_import_rejects_a_page_from_a_different_database(): void {
        $executor = new Task_Executor();

        $result = $executor->execute(
            'batch_import',
            [ [ 'id' => 'page-a', 'parent' => [ 'database_id' => 'database-a' ] ] ],
            [ 'database_id' => 'database-b' ]
        );

        $this->assertSame( 'completed', $result['status'] );
        $this->assertSame( 0, $result['success_count'] );
        $this->assertSame( 1, $result['error_count'] );
        $this->assertStringContainsString( '数据库来源与页面来源不一致', $result['errors'][0] );
    }

    public function test_incremental_task_without_a_source_database_fails_closed(): void {
        $executor = new Task_Executor();

        $result = $executor->execute( 'incremental_sync', [], [] );

        $this->assertSame( 'completed', $result['status'] );
        $this->assertSame( 0, $result['success_count'] );
        $this->assertSame( 0, $result['error_count'] );
        $this->assertStringContainsString( '缺少来源数据库 ID', $result['errors'][0] );
    }
}
