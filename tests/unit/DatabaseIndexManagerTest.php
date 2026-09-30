<?php
declare(strict_types=1);

use NTWP\Utils\Database_Index_Manager;

final class DatabaseIndexManagerWpdbStub {
    public string $prefix = 'wp_';
    /** @var array<int, string> */
    public array $queries = [];

    public function prepare(string $query, ...$arguments): string {
        return $query;
    }

    public function get_var(string $query) {
        $this->queries[] = $query;
        return null;
    }

    public function query(string $query): int {
        $this->queries[] = $query;
        return 1;
    }
}

final class DatabaseIndexManagerTest extends \PHPUnit\Framework\TestCase {
    private $previous_wpdb;

    protected function setUp(): void {
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new DatabaseIndexManagerWpdbStub();
    }

    protected function tearDown(): void {
        if ($this->previous_wpdb === null) {
            unset($GLOBALS['wpdb']);
            return;
        }

        $GLOBALS['wpdb'] = $this->previous_wpdb;
    }

    public function test_rejects_unmanaged_table_and_unsafe_identifiers_before_querying(): void {
        $this->assertFalse(Database_Index_Manager::create_index('users; DROP TABLE wp_posts', 'safe_index', ['meta_key']));
        $this->assertFalse(Database_Index_Manager::create_index('postmeta', 'bad-index', ['meta_key']));
        $this->assertFalse(Database_Index_Manager::create_index('postmeta', 'safe_index', ['meta_key); DROP TABLE wp_posts']));

        /** @var DatabaseIndexManagerWpdbStub $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $this->assertSame([], $wpdb->queries);
    }

    public function test_quotes_valid_managed_identifiers(): void {
        $this->assertTrue(Database_Index_Manager::create_index('postmeta', 'safe_index', ['meta_key', 'meta_value(191)']));
        /** @var DatabaseIndexManagerWpdbStub $wpdb */
        $wpdb = $GLOBALS['wpdb'];

        $this->assertSame(
            [
                'SHOW INDEX FROM `wp_postmeta` WHERE Key_name = %s',
                'CREATE INDEX `safe_index` ON `wp_postmeta` (`meta_key`, `meta_value`(191))',
            ],
            $wpdb->queries
        );
    }
}
