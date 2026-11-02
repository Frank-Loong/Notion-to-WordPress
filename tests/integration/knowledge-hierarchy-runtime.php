<?php
declare(strict_types=1);

use NTWP\Handlers\Import_Coordinator;
use NTWP\Handlers\Integrator;
use NTWP\Services\API;

$root = getenv('NTWP_TEST_WP_ROOT');
if (PHP_SAPI !== 'cli' || !$root || !is_file($root . '/wp-load.php')) exit(2);
require $root . '/wp-load.php';
if (!defined('NTWP_INTEGRATION_TEST') || NTWP_INTEGRATION_TEST !== true) exit(2);

final class HierarchyRuntimeAPI extends API {
    public string $version = 'one';
    public array $archived = [];
    public function __construct() {}
    public function get_page(string $page_id): array {
        $month = ['one' => '01', 'two' => '02', 'child-only' => '03'][$this->version];
        return ['id' => $page_id, 'object' => 'page', 'archived' => in_array($page_id, $this->archived, true), 'last_edited_time' => '2026-' . $month . '-01T00:00:00.000Z',
            'properties' => [
                'Name' => ['type' => 'title', 'title' => [['plain_text' => 'Hierarchy fixture ' . substr($page_id, 0, 2)]]],
                'Status' => ['type' => 'select', 'select' => ['name' => 'publish']],
                'Type' => ['type' => 'select', 'select' => ['name' => 'page']],
            ]];
    }
    public function get_page_content(string $block_id, int $depth = 0, int $max_depth = 5): array {
        $blocks = [['id' => 'ab' . substr($block_id, 2), 'type' => 'paragraph', 'has_children' => false,
            'paragraph' => ['rich_text' => [['type' => 'text', 'plain_text' => 'hierarchy-body-' . $this->version, 'annotations' => []]]]]];
        $child = $block_id === str_repeat('12', 16) ? str_repeat('34', 16) : ($block_id === str_repeat('34', 16) ? str_repeat('56', 16) : '');
        if ($child !== '') $blocks[] = ['id' => $child, 'type' => 'child_page', 'has_children' => false, 'child_page' => ['title' => 'Child fixture']];
        return $blocks;
    }
}

$database_id = str_repeat('78', 16);
$options = get_option('notion_to_wordpress_options', []);
$created = [];
$checks = [];
function hierarchy_check(string $name, bool $pass): void {
    global $checks;
    $checks[$name] = $pass;
    if (!$pass) throw new RuntimeException($name);
}
try {
    $prior = get_posts(['post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_notion_database_id', 'meta_value' => $database_id]);
    if ($prior) throw new RuntimeException('Fixture source already exists; refusing to overwrite it');
    update_option('notion_to_wordpress_options', array_merge($options, ['enable_performance_mode' => 0]));
    $api = new HierarchyRuntimeAPI();
    $mapping = ['title' => 'Name', 'status' => 'Status', 'post_type' => 'Type'];
    $coordinator = new Import_Coordinator($api, $database_id, $mapping);
    hierarchy_check('first_import', $coordinator->import_notion_page($api->get_page(str_repeat('12', 16)), true) === true);
    foreach (['12', '34', '56'] as $part) $created[] = Integrator::get_post_by_notion_id(str_repeat($part, 16), $database_id);
    hierarchy_check('three_distinct_pages', count(array_unique(array_filter($created))) === 3);
    hierarchy_check('child_parent', (int) get_post($created[1])->post_parent === $created[0]);
    hierarchy_check('grandchild_parent', (int) get_post($created[2])->post_parent === $created[1]);
    hierarchy_check('child_type_page', get_post_type($created[1]) === 'page');
    hierarchy_check('parent_links_local_child', strpos(get_post($created[0])->post_content, (string) get_permalink($created[1])) !== false);
    hierarchy_check('child_links_back_to_parent', strpos(get_post($created[1])->post_content, 'notion-parent-page') !== false);
    $api->version = 'two';
    $second = new Import_Coordinator($api, $database_id, $mapping);
    hierarchy_check('repeat_import', $second->import_notion_page($api->get_page(str_repeat('12', 16)), true) === true);
    hierarchy_check('descendant_updated', strpos(get_post($created[2])->post_content, 'hierarchy-body-two') !== false);
    hierarchy_check('identity_preserved', Integrator::get_post_by_notion_id(str_repeat('34', 16), $database_id) === $created[1]);
    $api->version = 'child-only';
    $poll = new Import_Coordinator($api, $database_id, $mapping);
    $method = new ReflectionMethod($poll, 'sync_known_child_pages');
    $method->setAccessible(true);
    $stats = ['failed' => 0, 'updated' => 0];
    $method->invokeArgs($poll, [&$stats, false]);
    hierarchy_check('child_only_update_without_root_import', strpos(get_post($created[2])->post_content, 'hierarchy-body-child-only') !== false);
    $api->archived = [str_repeat('56', 16)];
    $poll = new Import_Coordinator($api, $database_id, $mapping);
    $method->invokeArgs($poll, [&$stats, false]);
    hierarchy_check('archived_child_moves_to_trash', get_post_status($created[2]) === 'trash');
    $api->archived = [];
    $poll = new Import_Coordinator($api, $database_id, $mapping);
    $method->invokeArgs($poll, [&$stats, false]);
    hierarchy_check('restored_child_reuses_original_id', get_post_status($created[2]) === 'publish' && Integrator::get_post_by_notion_id(str_repeat('56', 16), $database_id) === $created[2]);
    wp_update_post(['ID' => $created[0], 'post_status' => 'draft']);
    $poll = new Import_Coordinator($api, $database_id, $mapping);
    $method->invokeArgs($poll, [&$stats, false]);
    hierarchy_check('parent_draft_hides_unchanged_descendants', get_post_status($created[1]) === 'draft' && get_post_status($created[2]) === 'draft');
    wp_update_post(['ID' => $created[0], 'post_status' => 'publish']);
    $poll = new Import_Coordinator($api, $database_id, $mapping);
    $method->invokeArgs($poll, [&$stats, false]);
    hierarchy_check('parent_publish_restores_unchanged_descendants', get_post_status($created[1]) === 'publish' && get_post_status($created[2]) === 'publish');
    wp_update_post(['ID' => $created[0], 'post_type' => 'post']);
    $third = new Import_Coordinator($api, $database_id, $mapping);
    hierarchy_check('post_root_import', $third->import_notion_page($api->get_page(str_repeat('12', 16)), true) === true);
    hierarchy_check('existing_post_type_preserved', get_post_type($created[0]) === 'post');
    hierarchy_check('child_of_post_uses_navigation', (int) get_post($created[1])->post_parent === 0 && (int) get_post_meta($created[1], '_notion_parent_post_id', true) === $created[0]);
} catch (Throwable $error) {
    $checks['error'] = $error->getMessage();
    $failed = true;
} finally {
    $fixtures = get_posts(['post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_notion_database_id', 'meta_value' => $database_id]);
    // Only IDs belonging to this dedicated fixture source are removed.
    if (empty($prior)) foreach ($fixtures as $post_id) wp_delete_post($post_id, true);
    update_option('notion_to_wordpress_options', $options);
}
echo json_encode(['status' => empty($failed) ? 'PASS' : 'FAIL', 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(empty($failed) ? 0 : 1);
