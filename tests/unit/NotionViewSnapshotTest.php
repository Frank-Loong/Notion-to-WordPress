<?php
declare(strict_types=1);

use NTWP\Services\API;

final class ViewSnapshotApiFixture extends API {
    public array $calls = [];
    public array $responses = [];
    public function __construct() {}
    public function send_request(string $endpoint, string $method = 'GET', array $data = []): array {
        $this->calls[] = [$endpoint, $method, $data];
        return array_shift($this->responses);
    }
}

final class NotionViewSnapshotTest extends PHPUnit\Framework\TestCase {
    public function test_first_view_preserves_filters_sort_and_paginates(): void {
        $GLOBALS['__wp_options'] = [];
        $api = new ViewSnapshotApiFixture();
        $filter = ['property' => 'Status', 'status' => ['equals' => 'Done']];
        $sorts = [['property' => 'Name', 'direction' => 'ascending']];
        $api->responses = [
            ['results' => [['id' => 'view-first'], ['id' => 'view-second']]],
            ['parent' => ['database_id' => 'db'], 'data_source_id' => 'source', 'filter' => $filter, 'sorts' => $sorts],
            ['results' => [['id' => 'one']], 'has_more' => true, 'next_cursor' => 'next'],
            ['results' => [['id' => 'two']], 'has_more' => false],
        ];
        $snapshot = $api->get_database_view_snapshot('db');
        $this->assertSame(['one', 'two'], array_column($snapshot['records'], 'id'));
        $this->assertSame('views/view-first', $api->calls[1][0]);
        $this->assertSame($filter, $api->calls[2][2]['filter']);
        $this->assertSame($sorts, $api->calls[2][2]['sorts']);
        $this->assertSame('next', $api->calls[3][2]['start_cursor']);
    }

    public function test_explicit_view_cannot_read_another_database(): void {
        $GLOBALS['__wp_options']['notion_to_wordpress_options'] = ['embedded_database_view_ids' => ['db' => 'selected']];
        $api = new ViewSnapshotApiFixture();
        $api->responses = [['parent' => ['database_id' => 'other'], 'data_source_id' => 'source']];
        $this->expectException(RuntimeException::class);
        $api->get_database_view_snapshot('db');
    }

    public function test_repeated_cursor_fails_instead_of_returning_partial_rows(): void {
        $GLOBALS['__wp_options'] = [];
        $api = new ViewSnapshotApiFixture();
        $api->responses = [
            ['results' => [['id' => 'view']]],
            ['parent' => ['database_id' => 'db'], 'data_source_id' => 'source'],
            ['results' => [], 'has_more' => true, 'next_cursor' => 'same'],
            ['results' => [], 'has_more' => true, 'next_cursor' => 'same'],
        ];
        $this->expectException(RuntimeException::class);
        $api->get_database_view_snapshot('db');
    }
}
