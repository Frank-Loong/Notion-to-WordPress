<?php
declare(strict_types=1);

use NTWP\Handlers\Import_Coordinator;
use NTWP\Services\API;

final class DeletionEvidenceApiFixture extends API {
    public array $page = [];
    public bool $fail = false;
    public function __construct() {}
    public function get_page(string $page_id): array {
        if ($this->fail) throw new RuntimeException('Permission or network failure');
        return $this->page;
    }
}

final class DeletionEvidenceTest extends PHPUnit\Framework\TestCase {
    public function test_missing_query_entry_requires_explicit_archive_evidence(): void {
        $GLOBALS['__wp_post_meta'] = [];
        $api = new DeletionEvidenceApiFixture();
        $coordinator = (new ReflectionClass(Import_Coordinator::class))->newInstanceWithoutConstructor();
        $coordinator->notion_api = $api;
        $method = new ReflectionMethod($coordinator, 'should_delete_post');
        $method->setAccessible(true);

        $api->page = ['archived' => false, 'in_trash' => false];
        $this->assertFalse($method->invoke($coordinator, 1, 'page'));
        $api->fail = true;
        $this->assertFalse($method->invoke($coordinator, 1, 'page'));
        $api->fail = false;
        $api->page = ['archived' => true];
        $this->assertTrue($method->invoke($coordinator, 1, 'page'));
        $api->page = ['in_trash' => true];
        $this->assertTrue($method->invoke($coordinator, 1, 'page'));
        $GLOBALS['__wp_post_meta'][1]['_notion_protect_from_deletion'] = 'yes';
        $this->assertFalse($method->invoke($coordinator, 1, 'page'));
    }
}
