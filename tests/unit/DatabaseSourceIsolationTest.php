<?php
declare(strict_types=1);

use NTWP\Services\Database_Config;
use NTWP\Utils\Database_Helper;

final class DatabaseSourceIsolationWpdbStub {
    public string $postmeta = 'wp_postmeta';
    public string $posts = 'wp_posts';
    /** @var array<int, mixed> */
    public array $prepare_arguments = [];

    public function prepare( string $query, ...$arguments ): string {
        $this->prepare_arguments = $arguments;
        return $query;
    }

    /** @return array<int, object> */
    public function get_results( string $query ): array {
        return [ (object) [ 'notion_id' => 'same-page', 'post_id' => 21 ] ];
    }

    public function get_var( string $query ) {
        return 0;
    }
}

final class DatabaseSourceIsolationTest extends \PHPUnit\Framework\TestCase {
    private $previous_wpdb;

    protected function setUp(): void {
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new DatabaseSourceIsolationWpdbStub();
    }

    protected function tearDown(): void {
        if ($this->previous_wpdb === null) {
            unset($GLOBALS['wpdb']);
            return;
        }
        $GLOBALS['wpdb'] = $this->previous_wpdb;
    }

    public function test_source_aware_lookup_binds_database_meta_before_page_id(): void {
        $posts = Database_Helper::batch_get_posts_by_notion_ids( [ 'same-page' ], 'database-a' );
        /** @var DatabaseSourceIsolationWpdbStub $wpdb */
        $wpdb = $GLOBALS['wpdb'];

        $this->assertSame( 21, $posts['same-page'] );
        $this->assertSame(
            [ Database_Config::META_DATABASE_ID, 'database-a', '_notion_page_id', 'same-page' ],
            $wpdb->prepare_arguments
        );
    }
}
