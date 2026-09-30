<?php
/**
 * Database_Config 多数据库配置测试
 *
 * 验证 P1-01：主数据库 + 附加数据库的合并、去重与表单文本解析。
 *
 * @since 2.0.0
 */

use NTWP\Services\Database_Config;

class DatabaseConfigTest extends \PHPUnit\Framework\TestCase {

    /**
     * 仅配置主数据库时，返回仅含主库的列表。
     */
    public function test_returns_primary_database_only(): void {
        $options = [ 'notion_database_id' => 'abc123' ];
        $dbs = Database_Config::get_configured_databases( $options );

        $this->assertCount( 1, $dbs );
        $this->assertSame( 'abc123', $dbs[0]['id'] );
        $this->assertArrayHasKey( 'field_mapping', $dbs[0] );
    }

    /**
     * 主库与附加库合并，且主库优先去重。
     */
    public function test_merges_and_dedupes_additional_databases(): void {
        $options = [
            'notion_database_id' => 'primary',
            'notion_databases'   => [
                [ 'id' => 'extra1', 'name' => 'Blog' ],
                [ 'id' => 'primary', 'name' => 'Dup' ], // 应与主库去重
                'extra2',
            ],
        ];

        $dbs = Database_Config::get_configured_databases( $options );

        $ids = array_column( $dbs, 'id' );
        $this->assertSame( [ 'primary', 'extra1', 'extra2' ], $ids );
    }

    /**
     * 无任何配置时返回空数组。
     */
    public function test_returns_empty_when_nothing_configured(): void {
        $this->assertSame( [], Database_Config::get_configured_databases( [] ) );
        $this->assertSame( [], Database_Config::get_configured_databases( [ 'notion_database_id' => '   ' ] ) );
    }

    /**
     * 解析表单文本：支持纯 ID 与 “名称|ID”，忽略空行。
     */
    public function test_parse_database_list(): void {
        $raw = "Blog|aaaa1111\nbbbb2222\n\n  \nDocs|cccc3333";
        $dbs = Database_Config::parse_database_list( $raw );

        $this->assertSame( 'aaaa1111', $dbs[0]['id'] );
        $this->assertSame( 'Blog', $dbs[0]['name'] );
        $this->assertSame( 'bbbb2222', $dbs[1]['id'] );
        $this->assertSame( '', $dbs[1]['name'] );
        $this->assertSame( 'cccc3333', $dbs[2]['id'] );
        $this->assertSame( 'Docs', $dbs[2]['name'] );
        $this->assertCount( 3, $dbs );
    }

    public function test_migrates_legacy_cursor_only_to_primary_database(): void {
        $options = Database_Config::migrate_sync_state( [
            'notion_database_id' => 'primary',
            'notion_databases'   => [ [ 'id' => 'secondary' ] ],
            'last_sync_time'     => '2026-08-24 10:00:00',
        ] );

        $this->assertSame( '2026-08-24 10:00:00', Database_Config::get_last_sync_time( $options, 'primary' ) );
        $this->assertSame( '', Database_Config::get_last_sync_time( $options, 'secondary' ) );
    }

    public function test_updates_only_the_completed_database_cursor(): void {
        $GLOBALS['__wp_options']['notion_to_wordpress_options'] = [
            'notion_database_id'  => 'primary',
            'database_sync_state' => [
                'primary'   => [ 'last_sync_time' => 'old-primary' ],
                'secondary' => [ 'last_sync_time' => 'old-secondary' ],
            ],
        ];

        Database_Config::mark_database_sync_completed( 'primary', 'new-primary' );
        $state = $GLOBALS['__wp_options']['notion_to_wordpress_options']['database_sync_state'];

        $this->assertSame( 'new-primary', $state['primary']['last_sync_time'] );
        $this->assertSame( 'old-secondary', $state['secondary']['last_sync_time'] );
    }
}
