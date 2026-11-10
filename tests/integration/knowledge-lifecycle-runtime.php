<?php
declare(strict_types=1);

use NTWP\Framework\Main;
use NTWP\Handlers\Integrator;

$root = getenv('NTWP_TEST_WP_ROOT');
if (PHP_SAPI !== 'cli' || !$root || !is_file($root . '/wp-load.php')) exit(2);
require $root . '/wp-load.php';
if (!defined('NTWP_INTEGRATION_TEST') || NTWP_INTEGRATION_TEST !== true) exit(2);
$options = get_option('notion_to_wordpress_options', []);
$cron = get_option('cron');
$checks = [];
$post_id = 0;
$attachments = [];
$url = 'http://127.0.0.1:8091/ntwp-featured-fixture.png';
$mock = static function ($response, $args, $request_url) use ($url) {
    if ($request_url !== $url) return $response;
    return ['headers' => ['content-type' => 'image/png'], 'response' => ['code' => 200, 'message' => 'OK'],
        'body' => base64_decode(trim(file_get_contents(dirname(__DIR__) . '/fixtures/media/one-pixel.png.base64'))), 'cookies' => []];
};
function lifecycle_check(string $name, bool $pass): void {
    global $checks;
    $checks[$name] = $pass;
    if (!$pass) throw new RuntimeException($name);
}
try {
    wp_clear_scheduled_hook('notion_cron_import');
    delete_option('notion_to_wordpress_options');
    Main::activate();
    lifecycle_check('new_install_defaults_to_ten_minutes', get_option('notion_to_wordpress_options')['sync_schedule'] === 'ntwp_ten_minutes');
    $event = wp_get_scheduled_event('notion_cron_import');
    lifecycle_check('activation_registers_600_second_event', $event && $event->interval === 600);
    wp_clear_scheduled_hook('notion_cron_import');
    update_option('notion_to_wordpress_options', ['sync_schedule' => 'hourly']);
    Main::activate();
    lifecycle_check('activation_preserves_existing_schedule', wp_get_schedule('notion_cron_import') === 'hourly');
    $admin = (new ReflectionClass(Notion_To_WordPress_Admin::class))->newInstanceWithoutConstructor();
    $schedule = new ReflectionMethod($admin, 'update_cron_schedule');
    $schedule->setAccessible(true);
    $schedule->invoke($admin, ['sync_schedule' => 'ntwp_ten_minutes']);
    lifecycle_check('schedule_change_replaces_event', wp_get_schedule('notion_cron_import') === 'ntwp_ten_minutes');
    $schedule->invoke($admin, ['sync_schedule' => 'manual']);
    lifecycle_check('manual_clears_event', wp_next_scheduled('notion_cron_import') === false);

    $post_id = wp_insert_post(['post_title' => 'Featured image lifecycle fixture', 'post_type' => 'page', 'post_status' => 'draft']);
    add_filter('pre_http_request', $mock, 10, 3);
    update_post_meta($post_id, '_notion_featured_image_url', 'https://obsolete.example/cover.png');
    Integrator::apply_featured_image($post_id, ['featured_image' => $url]);
    $thumbnail = get_post_thumbnail_id($post_id);
    if ($thumbnail) $attachments[] = $thumbnail;
    lifecycle_check('external_featured_image_saved_locally_by_default', $thumbnail > 0 && is_file(get_attached_file($thumbnail)));
    lifecycle_check('local_featured_image_clears_stale_external_override', get_post_meta($post_id, '_notion_featured_image_url', true) === '');
    Integrator::apply_featured_image($post_id, ['featured_image' => $url]);
    lifecycle_check('repeated_featured_image_reuses_attachment', get_post_thumbnail_id($post_id) === $thumbnail);
    lifecycle_check('no_unprocessed_featured_image_placeholder', get_post_meta($post_id, '_notion_featured_image_placeholder', true) === '');
} catch (Throwable $error) {
    $checks['error'] = $error->getMessage();
    $failed = true;
} finally {
    remove_filter('pre_http_request', $mock, 10);
    if ($post_id) wp_delete_post($post_id, true);
    foreach ($attachments as $attachment) wp_delete_attachment($attachment, true);
    update_option('notion_to_wordpress_options', $options);
    update_option('cron', $cron);
}
echo json_encode(['status' => empty($failed) ? 'PASS' : 'FAIL', 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(empty($failed) ? 0 : 1);
