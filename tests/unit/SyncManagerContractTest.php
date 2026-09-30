<?php
declare(strict_types=1);

use NTWP\Framework\Autoloader;
use NTWP\Services\API;
use NTWP\Services\Sync_Manager;
use NTWP\Utils\API_Result;

final class SyncManagerContractApiStub extends API {
    public function __construct() {}

    public function smart_incremental_fetch_enhanced(string $database_id, string $last_sync_time = '', array $additional_filters = [], bool $with_details = false): API_Result {
        return API_Result::success( [] );
    }
}

final class SyncManagerContractTest extends \PHPUnit\Framework\TestCase {
    protected function setUp(): void {
        Autoloader::register( dirname( __DIR__, 2 ) );
        $GLOBALS['__wp_options'] = [ 'enable_performance_mode' => 0 ];
    }

    public function test_super_batch_sync_has_a_safe_path_when_performance_mode_is_disabled(): void {
        $result = Sync_Manager::super_batch_sync( [], new SyncManagerContractApiStub(), 'database-a' );

        $this->assertSame( 0, $result['total'] );
        $this->assertSame( 0, $result['errors'] );
    }

    public function test_api_filtered_incremental_sync_unwraps_api_result_objects(): void {
        $result = Sync_Manager::api_filtered_incremental_sync( 'database-a', new SyncManagerContractApiStub() );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['filtered_pages'] );
    }
}
