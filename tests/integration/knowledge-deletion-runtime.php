<?php
declare(strict_types=1);

use NTWP\Handlers\Import_Coordinator;
use NTWP\Services\API;

$root = getenv('NTWP_TEST_WP_ROOT');
if (PHP_SAPI !== 'cli' || !$root || !is_file($root . '/wp-load.php')) exit(2);
require $root . '/wp-load.php';
if (!defined('NTWP_INTEGRATION_TEST') || NTWP_INTEGRATION_TEST !== true) exit(2);

final class EmptyDatabaseRuntimeAPI extends API {
    public function __construct() {}
    public function get_database_pages(string $database_id, array $filter = [], bool $with_details = false): array { return []; }
    public function get_page(string $page_id): array {
        if ($page_id === str_repeat('fe', 16)) throw new RuntimeException('simulated permission failure');
        return ['id' => $page_id, 'archived' => $page_id !== str_repeat('fd', 16)];
    }
}

$database_id = str_repeat('91', 16);
$options = get_option('notion_to_wordpress_options', []);
$checks = [];
$ids = [];
$prior = get_posts(['post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_notion_database_id', 'meta_value' => $database_id]);
if ($prior) { fwrite(STDERR, "Fixture source exists; refusing to overwrite\n"); exit(2); }
try {
    // Use a small boundary for the keyset test below; public sync still uses
    // its normal 1000-row batches. This avoids thousands of fixture hooks.
    for ($i = 1; $i <= 12; ++$i) {
        $id = wp_insert_post(['post_title' => 'Deletion fixture', 'post_status' => 'publish', 'post_type' => $i % 2 ? 'page' : 'post',
            'meta_input' => ['_notion_page_id' => str_pad(dechex($i), 32, '0', STR_PAD_LEFT), '_notion_database_id' => $database_id]], true);
        if (is_wp_error($id)) throw new RuntimeException('fixture insert failed');
        $ids[] = $id;
    }
    $retained = [];
    foreach (['fc', 'fd', 'fe'] as $part) {
        $id = wp_insert_post(['post_title' => 'Retained fixture', 'post_status' => 'publish', 'post_type' => 'page',
            'meta_input' => ['_notion_page_id' => str_repeat($part, 16), '_notion_database_id' => $database_id]], true);
        if (is_wp_error($id)) throw new RuntimeException('retained insert failed');
        $ids[] = $id;
        $retained[$part] = $id;
    }
    update_post_meta($retained['fc'], '_notion_protect_from_deletion', 'yes');
    $coordinator = new Import_Coordinator(new EmptyDatabaseRuntimeAPI(), $database_id, []);
    $result = $coordinator->import_pages(true, false, true);
    $checks['empty_source_is_valid_sync'] = !is_wp_error($result);
    $checks['empty_source_recycled'] = !is_wp_error($result) && $result['deleted'] === 12;
    $checks['protected_page_preserved'] = get_post_status($retained['fc']) === 'publish';
    $checks['active_page_preserved'] = get_post_status($retained['fd']) === 'publish';
    $checks['permission_failure_preserved'] = get_post_status($retained['fe']) === 'publish';
    $checks['trash_is_recoverable'] = get_post($ids[0]) !== null && get_post_status($ids[0]) === 'trash';
    foreach (array_slice($ids, 0, 12) as $post_id) wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
    $method = new ReflectionMethod($coordinator, 'cleanup_deleted_pages');
    $method->setAccessible(true);
    $checks['keyset_crosses_four_batches'] = $method->invoke($coordinator, [], 3) === 12;
} catch (Throwable $error) {
    $checks['error'] = $error->getMessage();
} finally {
    foreach ($ids as $id) wp_delete_post($id, true);
    update_option('notion_to_wordpress_options', $options);
}
$pass = !isset($checks['error']) && !in_array(false, $checks, true) && count($checks) === 7;
echo json_encode(['status' => $pass ? 'PASS' : 'FAIL', 'checks' => $checks], JSON_PRETTY_PRINT) . PHP_EOL;
exit($pass ? 0 : 1);
