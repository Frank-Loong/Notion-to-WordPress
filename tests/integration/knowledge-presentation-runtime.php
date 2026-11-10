<?php
declare(strict_types=1);

use NTWP\Handlers\Integrator;
use NTWP\Services\API;
use NTWP\Services\Content_Converter;
use NTWP\Services\Database_Renderer;

$root = getenv('NTWP_TEST_WP_ROOT');
if (PHP_SAPI !== 'cli' || !$root || !is_file($root . '/wp-load.php')) exit(2);
require $root . '/wp-load.php';
if (!defined('NTWP_INTEGRATION_TEST') || NTWP_INTEGRATION_TEST !== true) exit(2);
$options = get_option('notion_to_wordpress_options', []);
$source = str_repeat('a9', 16);
$target_notion_id = str_repeat('b9', 16);
$main_notion_id = str_repeat('c9', 16);
$api = (new ReflectionClass(API::class))->newInstanceWithoutConstructor();
try {
    update_option('notion_to_wordpress_options', array_merge($options, ['enable_performance_mode' => 0, 'embedded_database_view' => 'auto', 'output_format' => 'classic', 'iframe_whitelist' => 'ntwp-fixture.example,www.youtube.com,youtu.be']));
    $target = Integrator::create_or_update_post(['title' => 'Knowledge fixture target', 'status' => 'publish', 'post_type' => 'page'], '<p>Local link target.</p>', 1, $target_notion_id, Integrator::get_post_by_notion_id($target_notion_id, $source), $source);
    if (is_wp_error($target)) throw new RuntimeException('Cannot create link target');
    $upload = wp_upload_bits('knowledge-cover.png', null, base64_decode(trim(file_get_contents(dirname(__DIR__) . '/fixtures/media/one-pixel.png.base64'))));
    if (!empty($upload['error'])) throw new RuntimeException('Cannot create cover fixture');
    $mock = static function ($response, $args, $url) use ($upload) {
        if (strpos($url, 'https://ntwp-fixture.example/') !== 0) return $response;
        return ['headers' => ['content-type' => 'text/html', 'x-frame-options' => 'DENY'], 'response' => ['code' => 200, 'message' => 'OK'],
            'body' => '<title>Fixture website</title><meta property="og:title" content="网页卡片标题"><meta property="og:image" content="' . esc_attr($upload['url']) . '">', 'cookies' => []];
    };
    add_filter('pre_http_request', $mock, 10, 3);
    $url = 'https://ntwp-fixture.example/blocked';
    delete_transient('ntwp_frame_' . hash('sha256', $url));
    delete_transient('ntwp_embed_' . hash('sha256', $url));
    $blocks = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/notion/rich-media-blocks.json'), true);
    foreach ([
        '\\begin{aligned}a&=b+c\\\\&=d\\end{aligned}',
        '\\begin{pmatrix}1&2\\\\3&4\\end{pmatrix}',
        '\\ce{2H2 + O2 -> 2H2O}',
        '\\int_0^1 x^2\\,dx=\\frac{1}{3}',
    ] as $index => $expression) $blocks[] = ['id' => 'formula-' . $index, 'type' => 'equation', 'has_children' => false, 'equation' => ['expression' => $expression]];
    $blocks[] = ['id' => 'blocked-webpage', 'type' => 'embed', 'has_children' => false, 'embed' => ['url' => $url]];
    $content = Content_Converter::convert_blocks_to_html($blocks, $api);
    remove_filter('pre_http_request', $mock, 10);
    $records = [];
    for ($i = 1; $i <= 9; ++$i) $records[] = [
        'id' => $target_notion_id, 'url' => 'https://www.notion.so/' . $target_notion_id,
        'cover' => ['external' => ['url' => $upload['url']]],
        'properties' => [
            'Name' => ['type' => 'title', 'title' => [['plain_text' => '知识条目 ' . $i]]],
            'Priority' => ['type' => 'select', 'select' => ['name' => $i % 2 ? 'High' : 'Low']],
            'Summary' => ['type' => 'rich_text', 'rich_text' => [['plain_text' => '可见摘要']]],
            'Hidden' => ['type' => 'rich_text', 'rich_text' => [['plain_text' => 'HIDDEN_SENTINEL']]],
        ],
    ];
    foreach (['table', 'gallery', 'list', 'board'] as $view) {
        $content .= '<h2>' . esc_html($view) . '</h2>' . Database_Renderer::render_database([
            'database_info' => ['title' => [['plain_text' => '知识库 ' . $view]], 'properties' => [
                'Name' => ['id' => 'title', 'type' => 'title'], 'Priority' => ['id' => 'p', 'type' => 'select'], 'Summary' => ['id' => 's', 'type' => 'rich_text'], 'Hidden' => ['id' => 'h', 'type' => 'rich_text'],
            ], 'notion_view' => ['type' => $view, 'configuration' => [
                'properties' => [['property_id' => 'title', 'visible' => true], ['property_id' => 's', 'visible' => true], ['property_id' => 'h', 'visible' => false]],
                'group_by' => ['type' => 'select', 'property_id' => 'p', 'sort' => ['type' => 'ascending'], 'hide_empty_groups' => true],
            ]]], 'records' => $records,
        ]);
    }
    $main = Integrator::create_or_update_post(['title' => 'Knowledge presentation acceptance', 'status' => 'publish', 'post_type' => 'page'], $content, 1, $main_notion_id, Integrator::get_post_by_notion_id($main_notion_id, $source), $source);
    if (is_wp_error($main)) throw new RuntimeException('Cannot save presentation fixture');
    $body = get_post($main)->post_content;
    $checks = ['all_views_saved' => substr_count($body, 'class="notion-database notion-database-') === 4, 'blocked_iframe_omitted' => strpos($body, '<iframe src="' . $url) === false, 'card_saved' => strpos($body, '网页卡片标题') !== false, 'hidden_columns_omitted' => strpos($body, 'HIDDEN_SENTINEL') === false];
    echo json_encode(['status' => !in_array(false, $checks, true) ? 'PASS' : 'FAIL', 'url' => get_permalink($main), 'target_url' => get_permalink($target), 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    $failed = in_array(false, $checks, true);
} catch (Throwable $error) {
    echo json_encode(['status' => 'FAIL', 'error' => $error->getMessage()]) . PHP_EOL;
    $failed = true;
} finally {
    update_option('notion_to_wordpress_options', $options);
}
exit(!empty($failed) ? 1 : 0);
