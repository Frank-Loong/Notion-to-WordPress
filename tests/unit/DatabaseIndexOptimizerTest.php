<?php
declare(strict_types=1);

use NTWP\Utils\Database_Index_Optimizer;

final class DatabaseIndexOptimizerWpdbStub {
    /** @var array<int, string> */
    public array $queries = [];

    /** @return array<int, object> */
    public function get_results(string $query): array {
        $this->queries[] = $query;

        return [
            (object) [
                'type' => 'ref',
                'key' => 'notion_page_id_optimized',
                'rows' => 1,
            ],
        ];
    }
}

final class DatabaseIndexOptimizerTest extends \PHPUnit\Framework\TestCase {
    private $previous_wpdb;

    protected function setUp(): void {
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new DatabaseIndexOptimizerWpdbStub();
    }

    protected function tearDown(): void {
        if ($this->previous_wpdb === null) {
            unset($GLOBALS['wpdb']);
            return;
        }

        $GLOBALS['wpdb'] = $this->previous_wpdb;
    }

    public function test_analyzes_a_single_select_query(): void {
        $analysis = Database_Index_Optimizer::analyze_query_performance(' SELECT * FROM wp_posts ');
        /** @var DatabaseIndexOptimizerWpdbStub $wpdb */
        $wpdb = $GLOBALS['wpdb'];

        $this->assertSame('SELECT * FROM wp_posts', $analysis['query']);
        $this->assertSame(['EXPLAIN SELECT * FROM wp_posts'], $wpdb->queries);
        $this->assertSame(60, $analysis['performance_score']);
    }

    /** @dataProvider unsafeQueries */
    public function test_rejects_non_read_only_or_multi_statement_queries(string $query): void {
        try {
            Database_Index_Optimizer::analyze_query_performance($query);
            $this->fail('不安全查询不应被交给数据库分析。');
        } catch (\InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        /** @var DatabaseIndexOptimizerWpdbStub $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $this->assertSame([], $wpdb->queries);
    }

    /** @return array<string, array{string}> */
    public static function unsafeQueries(): array {
        return [
            'write query' => ['DELETE FROM wp_posts'],
            'multiple statements' => ['SELECT * FROM wp_posts; DELETE FROM wp_posts'],
            'locking read' => ['SELECT * FROM wp_posts FOR UPDATE'],
            'file output' => ["SELECT * FROM wp_posts INTO OUTFILE '/tmp/posts'"],
        ];
    }
}
