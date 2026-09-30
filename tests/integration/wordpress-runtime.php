<?php
/**
 * WordPress runtime integration checks.
 *
 * These checks intentionally refuse to run unless the target WordPress site
 * defines NTWP_INTEGRATION_TEST as true. This prevents fixture writes from
 * being executed against a production site by mistake.
 *
 * Usage:
 *   php tests/integration/wordpress-runtime.php smoke
 *   php tests/integration/wordpress-runtime.php incremental
 *   php tests/integration/wordpress-runtime.php full
 *   php tests/integration/wordpress-runtime.php lifecycle
 *   php tests/integration/wordpress-runtime.php content-fixture
 *   php tests/integration/wordpress-runtime.php media-fixture
 *   php tests/integration/wordpress-runtime.php media-concurrency
 *   php tests/integration/wordpress-runtime.php attachment-fixture
 *   php tests/integration/wordpress-runtime.php failure-guard
 *
 * Required environment variables:
 *   NTWP_TEST_WP_ROOT
 *   NTWP_NOTION_TOKEN and NTWP_NOTION_DATABASE_ID, or credentials already
 *   saved in the disposable WordPress site's plugin settings
 *   NTWP_TEST_ALLOW_MUTATION=1 (full mode only)
 *   NTWP_TEST_BASE_URL (optional; enables public HTTP checks)
 */

declare(strict_types=1);

use NTWP\Handlers\Import_Coordinator;
use NTWP\Services\API;
use NTWP\Services\Database_Config;

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This integration runner is CLI-only.\n" );
	exit( 2 );
}

$wp_root = getenv( 'NTWP_TEST_WP_ROOT' );
if ( ! is_string( $wp_root ) || '' === trim( $wp_root ) ) {
	fwrite( STDERR, "NTWP_TEST_WP_ROOT is required.\n" );
	exit( 2 );
}

$wp_load = rtrim( $wp_root, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-load.php';
if ( ! is_file( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php was not found below NTWP_TEST_WP_ROOT.\n" );
	exit( 2 );
}

require $wp_load;

if ( ! defined( 'NTWP_INTEGRATION_TEST' ) || true !== NTWP_INTEGRATION_TEST ) {
	fwrite( STDERR, "Refusing to run: target site is not marked with NTWP_INTEGRATION_TEST=true.\n" );
	exit( 2 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$results = array();
$failed  = false;

/**
 * Record one assertion without exposing page titles, content, or credentials.
 */
function ntwp_test_assert( string $name, bool $passed, array $details = array() ): void {
	global $results, $failed;

	$results[] = array(
		'name'    => $name,
		'status'  => $passed ? 'PASS' : 'FAIL',
		'details' => $details,
	);

	if ( ! $passed ) {
		$failed = true;
	}
}

/**
 * Return posts imported from one Notion database.
 *
 * @return array<int, object>
 */
function ntwp_test_source_posts( string $database_id ): array {
	global $wpdb;

	$query = $wpdb->prepare(
		"SELECT p.ID, p.post_type, p.post_status, p.post_content, source.meta_value AS notion_page_id
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} database_meta
			ON database_meta.post_id = p.ID
			AND database_meta.meta_key = %s
			AND database_meta.meta_value = %s
		INNER JOIN {$wpdb->postmeta} source
			ON source.post_id = p.ID
			AND source.meta_key = %s
		WHERE p.post_type NOT IN ('revision', 'auto-draft')
		ORDER BY source.meta_value, p.ID",
		'_notion_database_id',
		$database_id,
		'_notion_page_id'
	);

	$rows = $wpdb->get_results( $query );
	return is_array( $rows ) ? $rows : array();
}

/**
 * Build a stable fingerprint for source identity and body contents.
 */
function ntwp_test_fingerprint( array $posts ): string {
	$parts = array();
	foreach ( $posts as $post ) {
		$parts[] = $post->notion_page_id . ':' . hash( 'sha256', (string) $post->post_content );
	}

	return hash( 'sha256', implode( '|', $parts ) );
}

/**
 * Delete fixture posts belonging to one test-only source database.
 */
function ntwp_test_delete_source_posts( string $database_id ): void {
	foreach ( ntwp_test_source_posts( $database_id ) as $post ) {
		wp_delete_post( (int) $post->ID, true );
	}
}

/** Publish a fixture without re-running WordPress's editor KSES filters. */
function ntwp_test_publish_fixture_post( int $post_id ): void {
	global $wpdb;
	$wpdb->update(
		$wpdb->posts,
		array( 'post_status' => 'publish' ),
		array( 'ID' => $post_id ),
		array( '%s' ),
		array( '%d' )
	);
	clean_post_cache( $post_id );
}

/**
 * Load credentials without persisting them to the repository or output.
 *
 * @return array{0:string,1:string}
 */
function ntwp_test_credentials(): array {
	$token       = getenv( 'NTWP_NOTION_TOKEN' );
	$database_id = getenv( 'NTWP_NOTION_DATABASE_ID' );
	$options     = get_option( 'notion_to_wordpress_options', array() );
	$options     = is_array( $options ) ? $options : array();

	if ( ! is_string( $token ) || '' === trim( $token ) ) {
		$token = $options['notion_api_key'] ?? '';
	}
	if ( ! is_string( $database_id ) || '' === trim( $database_id ) ) {
		$database_id = $options['notion_database_id'] ?? '';
	}

	if ( ! is_string( $token ) || '' === trim( $token ) || ! is_string( $database_id ) || '' === trim( $database_id ) ) {
		throw new RuntimeException( 'Notion credentials are required via environment variables or saved WordPress plugin settings.' );
	}

	return array( $token, $database_id );
}

/**
 * Verify source uniqueness, body integrity, and optional frontend responses.
 */
function ntwp_test_verify_synced_content( API $api, string $database_id ): void {
	$notion_pages = $api->get_database_pages( $database_id );
	$posts        = ntwp_test_source_posts( $database_id );
	$source_count = array();
	$post_by_page = array();

	foreach ( $posts as $post ) {
		$source_id                  = (string) $post->notion_page_id;
		$source_count[ $source_id ] = ( $source_count[ $source_id ] ?? 0 ) + 1;
		$post_by_page[ $source_id ] = $post;
	}

	$duplicate_count = count(
		array_filter(
			$source_count,
			static fn ( int $count ): bool => $count > 1
		)
	);

	ntwp_test_assert( 'source_count_matches_notion', count( $notion_pages ) === count( $posts ), array( 'notion' => count( $notion_pages ), 'wordpress' => count( $posts ) ) );
	ntwp_test_assert( 'source_identity_is_unique', 0 === $duplicate_count, array( 'duplicates' => $duplicate_count ) );

	$missing_posts       = 0;
	$nonempty_mismatches = 0;
	foreach ( $notion_pages as $page ) {
		$page_id = (string) ( $page['id'] ?? '' );
		if ( '' === $page_id || ! isset( $post_by_page[ $page_id ] ) ) {
			++$missing_posts;
			continue;
		}

		$blocks = $api->get_page_content( $page_id );
		if ( ! empty( $blocks ) && '' === trim( wp_strip_all_tags( (string) $post_by_page[ $page_id ]->post_content ) ) ) {
			++$nonempty_mismatches;
		}
	}

	ntwp_test_assert( 'all_notion_pages_have_posts', 0 === $missing_posts, array( 'missing' => $missing_posts ) );
	ntwp_test_assert( 'blocks_are_not_stored_as_empty_content', 0 === $nonempty_mismatches, array( 'mismatches' => $nonempty_mismatches ) );

	$base_url = getenv( 'NTWP_TEST_BASE_URL' );
	if ( is_string( $base_url ) && '' !== trim( $base_url ) ) {
		$http_failures = 0;
		$checked       = 0;
		foreach ( $posts as $post ) {
			if ( 'publish' !== $post->post_status ) {
				continue;
			}

			++$checked;
			$url      = rtrim( $base_url, '/' ) . '/?p=' . (int) $post->ID;
			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				++$http_failures;
			}
		}

		ntwp_test_assert( 'published_frontend_http_200', 0 === $http_failures, array( 'checked' => $checked, 'failures' => $http_failures ) );
	}
}

/**
 * API fixture that fails before the WordPress write stage.
 */
final class NTWP_Failing_Content_API extends API {
	public function __construct() {}

	public function get_database_pages( string $database_id, array $filter = array(), bool $with_details = false ): array {
		return array(
			array(
				'id'               => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
				'object'           => 'page',
				'last_edited_time' => '2026-09-14T00:00:00.000Z',
				'properties'       => array(
					'Name' => array(
						'title' => array( array( 'plain_text' => 'Failure Guard Fixture' ) ),
					),
				),
			),
		);
	}

	public function get_page_content( string $block_id, int $depth = 0, int $max_depth = 5 ): array {
		throw new RuntimeException( 'simulated content transport failure' );
	}
}

/**
 * Deterministic API fixture for first-import and idempotency checks.
 */
final class NTWP_Lifecycle_API extends API {
	public const PAGE_ID = 'cccccccccccccccccccccccccccccccc';

	public function __construct() {}

	public function get_database_pages( string $database_id, array $filter = array(), bool $with_details = false ): array {
		return array(
			array(
				'id'               => self::PAGE_ID,
				'object'           => 'page',
				'last_edited_time' => '2026-09-14T00:00:00.000Z',
				'properties'       => array(
					'Name' => array(
						'title' => array( array( 'plain_text' => 'Lifecycle Fixture' ) ),
					),
				),
			),
		);
	}

	public function get_page_content( string $block_id, int $depth = 0, int $max_depth = 5 ): array {
		return array(
			array(
				'id'           => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
				'type'         => 'paragraph',
				'has_children' => false,
				'paragraph'    => array(
					'rich_text' => array(
						array(
							'type'        => 'text',
							'plain_text'  => 'lifecycle-body-sentinel',
							'href'        => null,
							'annotations' => array(),
						),
					),
				),
			),
		);
	}
}

/**
 * API fixture that returns the complete core block matrix.
 */
final class NTWP_Content_Fixture_API extends API {
	public const PAGE_ID = 'ffffffffffffffffffffffffffffffff';

	/** @var array<int, array<string, mixed>> */
	private array $blocks;

	/**
	 * @param array<int, array<string, mixed>> $blocks Content fixture blocks.
	 */
	public function __construct( array $blocks ) {
		$this->blocks = $blocks;
	}

	public function get_database_pages( string $database_id, array $filter = array(), bool $with_details = false ): array {
		return array(
			array(
				'id'               => self::PAGE_ID,
				'object'           => 'page',
				'last_edited_time' => '2026-09-14T00:00:00.000Z',
				'properties'       => array(
					'Name' => array(
						'title' => array( array( 'plain_text' => 'Content Matrix Fixture' ) ),
					),
				),
			),
		);
	}

	public function get_page_content( string $block_id, int $depth = 0, int $max_depth = 5 ): array {
		return $this->blocks;
	}
}

/**
 * API fixture for local media download and signed-URL rotation checks.
 */
final class NTWP_Media_Fixture_API extends API {
	public const PAGE_ID = '56565656565656565656565656565656';

	private string $image_url;
	private string $version;

	public function __construct( string $image_url, string $version ) {
		$this->image_url = $image_url;
		$this->version   = $version;
	}

	public function get_database_pages( string $database_id, array $filter = array(), bool $with_details = false ): array {
		return array(
			array(
				'id'               => self::PAGE_ID,
				'object'           => 'page',
				'last_edited_time' => '2026-09-14T00:00:0' . $this->version . '.000Z',
				'properties'       => array(
					'Name'    => array(
						'title' => array( array( 'plain_text' => 'Media Fixture' ) ),
					),
					'Version' => array(
						'rich_text' => array( array( 'plain_text' => $this->version ) ),
					),
				),
			),
		);
	}

	public function get_page_content( string $block_id, int $depth = 0, int $max_depth = 5 ): array {
		return array(
			array(
				'id'           => '78787878787878787878787878787878',
				'type'         => 'image',
				'has_children' => false,
				'image'        => array(
					'type'    => 'file',
					'file'    => array( 'url' => $this->image_url ),
					'caption' => array(
						array(
							'type'        => 'text',
							'plain_text'  => 'Local media fixture',
							'href'        => null,
							'annotations' => array(),
						),
					),
				),
			),
		);
	}
}

/**
 * API fixture for bounded concurrent media batches.
 */
final class NTWP_Media_Concurrency_API extends API {
	public const PAGE_ID = '67676767676767676767676767676767';

	/** @var array<int, string> */
	private array $image_urls;
	private string $version;

	/**
	 * @param array<int, string> $image_urls Image URLs for this batch.
	 */
	public function __construct( array $image_urls, string $version ) {
		$this->image_urls = $image_urls;
		$this->version    = $version;
	}

	public function get_database_pages( string $database_id, array $filter = array(), bool $with_details = false ): array {
		return array(
			array(
				'id'               => self::PAGE_ID,
				'object'           => 'page',
				'last_edited_time' => '2026-09-14T00:01:0' . $this->version . '.000Z',
				'properties'       => array(
					'Name'    => array(
						'title' => array( array( 'plain_text' => 'Media Concurrency Fixture' ) ),
					),
					'Version' => array(
						'rich_text' => array( array( 'plain_text' => $this->version ) ),
					),
				),
			),
		);
	}

	public function get_page_content( string $block_id, int $depth = 0, int $max_depth = 5 ): array {
		$blocks = array();
		foreach ( $this->image_urls as $index => $image_url ) {
			$blocks[] = array(
				'id'           => sprintf( '%032x', $index + 1 ),
				'type'         => 'image',
				'has_children' => false,
				'image'        => array(
					'type'    => 'file',
					'file'    => array( 'url' => $image_url ),
					'caption' => array(
						array(
							'type'        => 'text',
							'plain_text'  => 'Batch image ' . ( $index + 1 ),
							'href'        => null,
							'annotations' => array(),
						),
					),
				),
			);
		}

		return $blocks;
	}
}

/**
 * API fixture for durable non-image attachments shared by two posts.
 */
final class NTWP_Attachment_Fixture_API extends API {
	/** @var array<int, array<string, mixed>> */
	private array $blocks;
	private string $version;

	/**
	 * @param array<int, array<string, mixed>> $blocks Attachment blocks.
	 */
	public function __construct( array $blocks, string $version ) {
		$this->blocks  = $blocks;
		$this->version = $version;
	}

	public function get_database_pages( string $database_id, array $filter = array(), bool $with_details = false ): array {
		$pages = array();
		foreach ( array( '89898989898989898989898989898989', '90909090909090909090909090909090' ) as $index => $page_id ) {
			$pages[] = array(
				'id'               => $page_id,
				'object'           => 'page',
				'last_edited_time' => '2026-09-14T00:02:0' . $this->version . '.000Z',
				'properties'       => array(
					'Name'    => array(
						'title' => array( array( 'plain_text' => 'Attachment Fixture ' . ( $index + 1 ) ) ),
					),
					'Version' => array(
						'rich_text' => array( array( 'plain_text' => $this->version ) ),
					),
				),
			);
		}
		return $pages;
	}

	public function get_page_content( string $block_id, int $depth = 0, int $max_depth = 5 ): array {
		return $this->blocks;
	}
}

/**
 * Exercise first import followed by an identical second import.
 */
function ntwp_test_lifecycle(): void {
	$database_id     = 'dddddddddddddddddddddddddddddddd';
	$original_option = get_option( 'notion_to_wordpress_options', array() );

	ntwp_test_delete_source_posts( $database_id );

	try {
		$options = is_array( $original_option ) ? $original_option : array();
		$options['enable_performance_mode']  = 0;
		$options['enable_super_batch_sync'] = false;
		$options['output_format']           = 'classic';
		unset( $options['database_sync_state'][ $database_id ] );
		update_option( 'notion_to_wordpress_options', $options );

		$api         = new NTWP_Lifecycle_API();
		$coordinator = new Import_Coordinator( $api, $database_id );
		$first       = $coordinator->import_pages( false, false, true );
		$first_posts = ntwp_test_source_posts( $database_id );
		$first_hash  = ntwp_test_fingerprint( $first_posts );

		ntwp_test_assert( 'first_import_reports_success', is_array( $first ) && 1 === ( $first['imported'] ?? 0 ) && 0 === ( $first['failed'] ?? -1 ), is_array( $first ) ? array_intersect_key( $first, array_flip( array( 'total', 'imported', 'updated', 'skipped', 'failed' ) ) ) : array() );
		ntwp_test_assert( 'first_import_creates_one_source_post', 1 === count( $first_posts ), array( 'posts' => count( $first_posts ) ) );
		ntwp_test_assert( 'first_import_writes_expected_body', 1 === count( $first_posts ) && false !== strpos( (string) $first_posts[0]->post_content, 'lifecycle-body-sentinel' ) );

		$second       = $coordinator->import_pages( false, false, true );
		$second_posts = ntwp_test_source_posts( $database_id );

		ntwp_test_assert( 'second_import_does_not_duplicate_source', 1 === count( $second_posts ), array( 'posts' => count( $second_posts ) ) );
		ntwp_test_assert( 'second_import_preserves_content', hash_equals( $first_hash, ntwp_test_fingerprint( $second_posts ) ) );
		ntwp_test_assert( 'second_import_is_idempotent', is_array( $second ) && 0 === ( $second['failed'] ?? -1 ) && 1 === ( ( $second['skipped'] ?? 0 ) + ( $second['updated'] ?? 0 ) ), is_array( $second ) ? array_intersect_key( $second, array_flip( array( 'total', 'imported', 'updated', 'skipped', 'failed' ) ) ) : array() );
	} finally {
		ntwp_test_delete_source_posts( $database_id );
		update_option( 'notion_to_wordpress_options', $original_option );
	}
}

/**
 * Pass the core block matrix through the complete WordPress save pipeline.
 */
function ntwp_test_content_fixture(): void {
	$database_id         = '12121212121212121212121212121212';
	$current_database_id = '23232323232323232323232323232323';
	$rich_database_id    = '45454545454545454545454545454545';
	$original_option     = get_option( 'notion_to_wordpress_options', array() );
	$fixture_path        = dirname( __DIR__ ) . '/fixtures/notion/core-content-blocks.json';
	$current_fixture     = dirname( __DIR__ ) . '/fixtures/notion/current-content-blocks.json';
	$rich_fixture        = dirname( __DIR__ ) . '/fixtures/notion/rich-media-blocks.json';
	$blocks              = json_decode( (string) file_get_contents( $fixture_path ), true, 512, JSON_THROW_ON_ERROR );
	$current_blocks      = json_decode( (string) file_get_contents( $current_fixture ), true, 512, JSON_THROW_ON_ERROR );
	$rich_blocks         = json_decode( (string) file_get_contents( $rich_fixture ), true, 512, JSON_THROW_ON_ERROR );
	$keep_fixtures       = '1' === getenv( 'NTWP_TEST_KEEP_FIXTURES' );

	ntwp_test_delete_source_posts( $database_id );
	ntwp_test_delete_source_posts( $current_database_id );
	ntwp_test_delete_source_posts( $rich_database_id );

	try {
		$options = is_array( $original_option ) ? $original_option : array();
		$options['enable_performance_mode']  = 1;
		$options['enable_super_batch_sync'] = false;
		$options['output_format']           = 'gutenberg';
		unset( $options['database_sync_state'][ $database_id ] );
		update_option( 'notion_to_wordpress_options', $options );

		$coordinator = new Import_Coordinator( new NTWP_Content_Fixture_API( $blocks ), $database_id );
		$stats       = $coordinator->import_pages( false, false, true );
		$posts       = ntwp_test_source_posts( $database_id );
		$content     = 1 === count( $posts ) ? (string) $posts[0]->post_content : '';
		$parsed      = '' !== $content ? parse_blocks( $content ) : array();
		$block_names = array_values( array_filter( array_column( $parsed, 'blockName' ) ) );

		ntwp_test_assert( 'content_fixture_import_succeeds', is_array( $stats ) && 1 === ( $stats['imported'] ?? 0 ) && 0 === ( $stats['failed'] ?? -1 ), is_array( $stats ) ? array_intersect_key( $stats, array_flip( array( 'total', 'imported', 'failed' ) ) ) : array() );
		ntwp_test_assert( 'content_fixture_creates_one_post', 1 === count( $posts ), array( 'posts' => count( $posts ) ) );
		ntwp_test_assert( 'wordpress_preserves_gutenberg_comments', false !== strpos( $content, '<!-- wp:paragraph -->' ) && false !== strpos( $content, '<!-- wp:list -->' ) );
		ntwp_test_assert( 'wordpress_preserves_escaped_code', false !== strpos( $content, 'if (a &lt; b &amp;&amp; b &gt; 0)' ) );
		ntwp_test_assert( 'wordpress_preserves_nested_content', false !== strpos( $content, 'Nested paragraph' ) && false !== strpos( $content, 'Hidden detail' ) );
		ntwp_test_assert( 'gutenberg_list_hierarchy_has_no_div_wrapper', false === strpos( $content, '<ul><div' ) && false === strpos( $content, '<ol><div' ) );
		ntwp_test_assert( 'wordpress_parser_recognizes_core_blocks', in_array( 'core/paragraph', $block_names, true ) && in_array( 'core/heading', $block_names, true ) && in_array( 'core/list', $block_names, true ), array( 'recognized' => array_values( array_unique( $block_names ) ) ) );

		unset( $options['database_sync_state'][ $current_database_id ] );
		update_option( 'notion_to_wordpress_options', $options );
		$current_coordinator = new Import_Coordinator( new NTWP_Content_Fixture_API( $current_blocks ), $current_database_id );
		$current_stats       = $current_coordinator->import_pages( false, false, true );
		$current_posts       = ntwp_test_source_posts( $current_database_id );
		$current_content     = 1 === count( $current_posts ) ? (string) $current_posts[0]->post_content : '';
		$current_parsed      = '' !== $current_content ? parse_blocks( $current_content ) : array();
		if ( $keep_fixtures && 1 === count( $current_posts ) ) {
			ntwp_test_publish_fixture_post( (int) $current_posts[0]->ID );
		}
		$has_heading_four    = false;
		foreach ( $current_parsed as $parsed_block ) {
			if ( 'core/heading' === ( $parsed_block['blockName'] ?? null ) && 4 === ( $parsed_block['attrs']['level'] ?? null ) ) {
				$has_heading_four = true;
				break;
			}
		}

		ntwp_test_assert( 'current_content_fixture_import_succeeds', is_array( $current_stats ) && 1 === ( $current_stats['imported'] ?? 0 ) && 0 === ( $current_stats['failed'] ?? -1 ), is_array( $current_stats ) ? array_intersect_key( $current_stats, array_flip( array( 'total', 'imported', 'failed' ) ) ) : array() );
		ntwp_test_assert( 'current_content_fixture_creates_one_post', 1 === count( $current_posts ), array( 'posts' => count( $current_posts ) ) );
		ntwp_test_assert( 'current_content_fixture_frontend_target_available', 1 === count( $current_posts ), array( 'post_id' => 1 === count( $current_posts ) ? (int) $current_posts[0]->ID : null ) );
		ntwp_test_assert( 'wordpress_parser_recognizes_heading_four', $has_heading_four );
		ntwp_test_assert( 'current_container_children_are_preserved', false !== strpos( $current_content, 'Template child body' ) && false !== strpos( $current_content, 'Tab child body' ) && false !== strpos( $current_content, 'Meeting summary body' ) );
		ntwp_test_assert( 'current_blocks_do_not_fall_through_unknown_handler', false === strpos( $current_content, '<!-- 未支持的块类型:' ) );
		ntwp_test_assert( 'current_links_keep_safe_new_tab_relationship', false !== strpos( $current_content, 'target="_blank" rel="noopener noreferrer"' ) );
		ntwp_test_assert( 'current_unsupported_diagnostic_is_escaped', false !== strpos( $current_content, 'button&quot; onclick=&quot;alert(1)' ) && false === strpos( $current_content, 'onclick="alert(1)"' ) );

		unset( $options['database_sync_state'][ $rich_database_id ] );
		update_option( 'notion_to_wordpress_options', $options );
		$rich_coordinator = new Import_Coordinator( new NTWP_Content_Fixture_API( $rich_blocks ), $rich_database_id );
		$rich_stats       = $rich_coordinator->import_pages( false, false, true );
		$rich_posts       = ntwp_test_source_posts( $rich_database_id );
		$rich_content     = 1 === count( $rich_posts ) ? (string) $rich_posts[0]->post_content : '';
		if ( $keep_fixtures && 1 === count( $rich_posts ) ) {
			ntwp_test_publish_fixture_post( (int) $rich_posts[0]->ID );
		}

		ntwp_test_assert( 'rich_media_fixture_import_succeeds', is_array( $rich_stats ) && 1 === ( $rich_stats['imported'] ?? 0 ) && 0 === ( $rich_stats['failed'] ?? -1 ), is_array( $rich_stats ) ? array_intersect_key( $rich_stats, array_flip( array( 'total', 'imported', 'failed' ) ) ) : array() );
		ntwp_test_assert( 'rich_media_fixture_creates_one_post', 1 === count( $rich_posts ), array( 'posts' => count( $rich_posts ) ) );
		ntwp_test_assert( 'rich_media_tags_survive_wordpress_save', false !== strpos( $rich_content, 'notion-equation' ) && false !== strpos( $rich_content, '<pre') && false !== strpos( $rich_content, '<iframe') && false !== strpos( $rich_content, '<video') );
		ntwp_test_assert( 'generic_embed_degrades_to_safe_link', false !== strpos( $rich_content, 'notion-embed-fallback' ) && false === strpos( $rich_content, '<iframe src="https://example.com/embedded-page"' ) );
		ntwp_test_assert( 'remote_pdf_degrades_to_safe_link', false !== strpos( $rich_content, 'notion-pdf-fallback' ) && false === strpos( $rich_content, 'notion-pdf-container' ) );
		ntwp_test_assert( 'rich_media_fixture_frontend_target_available', 1 === count( $rich_posts ), array( 'post_id' => 1 === count( $rich_posts ) ? (int) $rich_posts[0]->ID : null ) );
	} finally {
		if ( ! $keep_fixtures ) {
			ntwp_test_delete_source_posts( $database_id );
			ntwp_test_delete_source_posts( $current_database_id );
			ntwp_test_delete_source_posts( $rich_database_id );
		}
		update_option( 'notion_to_wordpress_options', $original_option );
	}
}

/**
 * Count attachments by the stable Notion image hash.
 *
 * @return array<int, int>
 */
function ntwp_test_media_attachments( string $stable_url ): array {
	global $wpdb;

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id",
			'_notion_image_hash',
			md5( $stable_url )
		)
	);

	return array_map( 'intval', is_array( $ids ) ? $ids : array() );
}

/**
 * Find non-image attachments by their stable source identity.
 *
 * @return array<int, int>
 */
function ntwp_test_attachment_ids( string $kind, string $stable_url ): array {
	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id",
			'_notion_attachment_hash',
			md5( $kind . ':' . $stable_url )
		)
	);
	return array_map( 'intval', is_array( $ids ) ? $ids : array() );
}

/**
 * Verify download, signed-URL deduplication, and failure fallback locally.
 */
function ntwp_test_media_fixture(): void {
	$database_id     = '34343434343434343434343434343434';
	$original_option = get_option( 'notion_to_wordpress_options', array() );
	$base_url        = getenv( 'NTWP_TEST_BASE_URL' );
	$wp_root         = getenv( 'NTWP_TEST_WP_ROOT' );

	if ( ! is_string( $base_url ) || '' === trim( $base_url ) ) {
		throw new RuntimeException( 'media-fixture requires NTWP_TEST_BASE_URL.' );
	}

	$fixture_source = dirname( __DIR__ ) . '/fixtures/media/one-pixel.png.base64';
	$fixture_target = rtrim( (string) $wp_root, '/\\' ) . DIRECTORY_SEPARATOR . 'ntwp-media-fixture.png';
	$stable_url     = rtrim( $base_url, '/' ) . '/ntwp-media-fixture.png';
	$first_url      = $stable_url . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=one';
	$second_url     = $stable_url . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=two';
	$missing_url    = rtrim( $base_url, '/' ) . '/ntwp-missing-media-fixture.png';
	$missing_signed = $missing_url . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=missing';
	$invalid_target = rtrim( (string) $wp_root, '/\\' ) . DIRECTORY_SEPARATOR . 'ntwp-invalid-media-fixture.html';
	$invalid_url    = rtrim( $base_url, '/' ) . '/ntwp-invalid-media-fixture.html';
	$invalid_signed = $invalid_url . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=invalid';
	$large_target   = rtrim( (string) $wp_root, '/\\' ) . DIRECTORY_SEPARATOR . 'ntwp-oversized-media-fixture.png';
	$large_url      = rtrim( $base_url, '/' ) . '/ntwp-oversized-media-fixture.png';
	$large_signed   = $large_url . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=oversized';
	$attachment_ids = array();

	ntwp_test_delete_source_posts( $database_id );
	foreach ( ntwp_test_media_attachments( $stable_url ) as $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}

	try {
		$image_data = base64_decode( trim( (string) file_get_contents( $fixture_source ) ), true );
		if ( false === $image_data || false === file_put_contents( $fixture_target, $image_data ) ) {
			throw new RuntimeException( 'Unable to create the local image fixture.' );
		}
		if ( false === file_put_contents( $invalid_target, '<html>not an image</html>' ) ) {
			throw new RuntimeException( 'Unable to create the invalid media fixture.' );
		}
		if ( false === file_put_contents( $large_target, str_repeat( 'x', \NTWP\Services\Image_Processor::MAX_IMAGE_SIZE + 1 ) ) ) {
			throw new RuntimeException( 'Unable to create the oversized media fixture.' );
		}

		$options = is_array( $original_option ) ? $original_option : array();
		$options['enable_performance_mode']  = 1;
		$options['enable_super_batch_sync'] = false;
		$options['output_format']           = 'classic';
		unset( $options['database_sync_state'][ $database_id ] );
		update_option( 'notion_to_wordpress_options', $options );

		$first_coordinator = new Import_Coordinator( new NTWP_Media_Fixture_API( $first_url, '1' ), $database_id );
		$first_stats       = $first_coordinator->import_pages( false, false, true );
		$first_posts       = ntwp_test_source_posts( $database_id );
		$first_content     = 1 === count( $first_posts ) ? (string) $first_posts[0]->post_content : '';
		$attachment_ids    = ntwp_test_media_attachments( $stable_url );

		ntwp_test_assert( 'media_first_import_succeeds', is_array( $first_stats ) && 1 === ( $first_stats['imported'] ?? 0 ) && 0 === ( $first_stats['failed'] ?? -1 ) );
		ntwp_test_assert( 'media_first_import_creates_one_attachment', 1 === count( $attachment_ids ), array( 'attachments' => count( $attachment_ids ) ) );
		ntwp_test_assert( 'media_first_import_uses_local_attachment', 1 === count( $attachment_ids ) && false !== strpos( $first_content, 'wp-image-' . $attachment_ids[0] ) );
		ntwp_test_assert( 'media_caption_is_preserved', false !== strpos( $first_content, '<figcaption>Local media fixture</figcaption>' ) );

		// Remove the origin file before rotating the signature. A working stable
		// dedup key must reuse the attachment without attempting another request.
		if ( is_file( $fixture_target ) ) {
			unlink( $fixture_target );
		}

		$second_coordinator = new Import_Coordinator( new NTWP_Media_Fixture_API( $second_url, '2' ), $database_id );
		$second_stats       = $second_coordinator->import_pages( false, false, true );
		$second_posts       = ntwp_test_source_posts( $database_id );
		$second_content     = 1 === count( $second_posts ) ? (string) $second_posts[0]->post_content : '';
		$second_ids         = ntwp_test_media_attachments( $stable_url );

		ntwp_test_assert( 'rotated_signature_reuses_attachment', $attachment_ids === $second_ids, array( 'before' => count( $attachment_ids ), 'after' => count( $second_ids ) ) );
		ntwp_test_assert( 'rotated_signature_sync_succeeds_without_origin', is_array( $second_stats ) && 0 === ( $second_stats['failed'] ?? -1 ) );
		ntwp_test_assert( 'reused_attachment_html_is_stable', hash_equals( hash( 'sha256', $first_content ), hash( 'sha256', $second_content ) ) );
		ntwp_test_assert( 'reused_attachment_still_exists', 1 === count( $second_ids ) && null !== get_post( $second_ids[0] ) );

		// A genuinely missing temporary image must degrade to the external URL
		// without failing the page import or deleting an unrelated attachment.
		$missing_coordinator = new Import_Coordinator( new NTWP_Media_Fixture_API( $missing_signed, '3' ), $database_id );
		$missing_stats       = $missing_coordinator->import_pages( false, false, true );
		$missing_posts       = ntwp_test_source_posts( $database_id );
		$missing_content     = 1 === count( $missing_posts ) ? (string) $missing_posts[0]->post_content : '';

		ntwp_test_assert( 'missing_media_does_not_fail_page_import', is_array( $missing_stats ) && 0 === ( $missing_stats['failed'] ?? -1 ) );
		ntwp_test_assert( 'missing_media_falls_back_to_external_url', false !== strpos( $missing_content, 'ntwp-missing-media-fixture.png' ) );
		ntwp_test_assert( 'missing_media_preserves_caption', false !== strpos( $missing_content, '<figcaption>Local media fixture</figcaption>' ) );
		ntwp_test_assert( 'missing_media_leaves_no_placeholder', false === strpos( $missing_content, 'pending_image_' ) );
		ntwp_test_assert( 'missing_media_creates_no_attachment', array() === ntwp_test_media_attachments( $missing_url ) );
		ntwp_test_assert( 'missing_media_preserves_existing_attachment', 1 === count( $attachment_ids ) && null !== get_post( $attachment_ids[0] ) );

		$invalid_coordinator = new Import_Coordinator( new NTWP_Media_Fixture_API( $invalid_signed, '4' ), $database_id );
		$invalid_stats       = $invalid_coordinator->import_pages( false, false, true );
		$invalid_posts       = ntwp_test_source_posts( $database_id );
		$invalid_content     = 1 === count( $invalid_posts ) ? (string) $invalid_posts[0]->post_content : '';

		ntwp_test_assert( 'invalid_media_type_does_not_fail_page_import', is_array( $invalid_stats ) && 0 === ( $invalid_stats['failed'] ?? -1 ) );
		ntwp_test_assert( 'invalid_media_type_falls_back_to_external_url', false !== strpos( $invalid_content, 'ntwp-invalid-media-fixture.html' ) );
		ntwp_test_assert( 'invalid_media_type_creates_no_attachment', array() === ntwp_test_media_attachments( $invalid_url ) );

		$large_coordinator = new Import_Coordinator( new NTWP_Media_Fixture_API( $large_signed, '5' ), $database_id );
		$large_stats       = $large_coordinator->import_pages( false, false, true );
		$large_posts       = ntwp_test_source_posts( $database_id );
		$large_content     = 1 === count( $large_posts ) ? (string) $large_posts[0]->post_content : '';

		ntwp_test_assert( 'oversized_media_does_not_fail_page_import', is_array( $large_stats ) && 0 === ( $large_stats['failed'] ?? -1 ) );
		ntwp_test_assert( 'oversized_media_falls_back_to_external_url', false !== strpos( $large_content, 'ntwp-oversized-media-fixture.png' ) );
		ntwp_test_assert( 'oversized_media_creates_no_attachment', array() === ntwp_test_media_attachments( $large_url ) );
		ntwp_test_assert( 'media_failures_preserve_existing_attachment', 1 === count( $attachment_ids ) && null !== get_post( $attachment_ids[0] ) );
	} finally {
		foreach ( array( $fixture_target, $invalid_target, $large_target ) as $temporary_file ) {
			if ( is_file( $temporary_file ) ) {
				unlink( $temporary_file );
			}
		}
		ntwp_test_delete_source_posts( $database_id );
		foreach ( array( $stable_url, $invalid_url, $large_url ) as $cleanup_url ) {
			foreach ( ntwp_test_media_attachments( $cleanup_url ) as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}
		update_option( 'notion_to_wordpress_options', $original_option );
	}
}

/**
 * Verify bounded concurrent downloads at 5, 20, and 50-image scales.
 */
function ntwp_test_media_concurrency(): void {
	$database_id     = '45454545454545454545454545454545';
	$original_option = get_option( 'notion_to_wordpress_options', array() );
	$base_url        = getenv( 'NTWP_TEST_BASE_URL' );
	$wp_root         = getenv( 'NTWP_TEST_WP_ROOT' );
	$fixture_source  = dirname( __DIR__ ) . '/fixtures/media/one-pixel.png.base64';
	$fixture_targets = array();
	$stable_urls     = array();
	$signed_urls     = array();

	if ( ! is_string( $base_url ) || '' === trim( $base_url ) || ! is_string( $wp_root ) || '' === trim( $wp_root ) ) {
		throw new RuntimeException( 'media-concurrency requires NTWP_TEST_BASE_URL and NTWP_TEST_WP_ROOT.' );
	}

	$image_data = base64_decode( trim( (string) file_get_contents( $fixture_source ) ), true );
	if ( false === $image_data ) {
		throw new RuntimeException( 'Unable to decode the local image fixture.' );
	}

	ntwp_test_delete_source_posts( $database_id );

	try {
		for ( $index = 1; $index <= 50; ++$index ) {
			$filename          = sprintf( 'ntwp-media-batch-%02d.png', $index );
			$fixture_target    = rtrim( $wp_root, '/\\' ) . DIRECTORY_SEPARATOR . $filename;
			$stable_url        = rtrim( $base_url, '/' ) . '/' . $filename;
			$fixture_targets[] = $fixture_target;
			$stable_urls[]     = $stable_url;
			$signed_urls[]     = $stable_url . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=batch-' . $index;

			if ( false === file_put_contents( $fixture_target, $image_data ) ) {
				throw new RuntimeException( 'Unable to create media concurrency fixture: ' . $filename );
			}
		}

		foreach ( $stable_urls as $stable_url ) {
			foreach ( ntwp_test_media_attachments( $stable_url ) as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}

		$options = is_array( $original_option ) ? $original_option : array();
		$options['enable_performance_mode']  = 1;
		$options['enable_super_batch_sync'] = false;
		$options['output_format']           = 'classic';
		unset( $options['database_sync_state'][ $database_id ] );
		update_option( 'notion_to_wordpress_options', $options );

		foreach ( array( 5, 20, 50 ) as $version_index => $image_count ) {
			$coordinator = new Import_Coordinator(
				new NTWP_Media_Concurrency_API( array_slice( $signed_urls, 0, $image_count ), (string) ( $version_index + 1 ) ),
				$database_id
			);
			$stats       = $coordinator->import_pages( false, false, true );
			$posts       = ntwp_test_source_posts( $database_id );
			$content     = 1 === count( $posts ) ? (string) $posts[0]->post_content : '';
			$all_ids     = array();
			foreach ( array_slice( $stable_urls, 0, $image_count ) as $stable_url ) {
				$all_ids = array_merge( $all_ids, ntwp_test_media_attachments( $stable_url ) );
			}
			preg_match_all( '/wp-image-\d+/', $content, $image_matches );
			preg_match_all( '/<figcaption>Batch image \d+<\/figcaption>/', $content, $caption_matches );

			ntwp_test_assert( 'media_batch_' . $image_count . '_sync_succeeds', is_array( $stats ) && 0 === ( $stats['failed'] ?? -1 ) );
			ntwp_test_assert( 'media_batch_' . $image_count . '_attachments_are_unique', $image_count === count( array_unique( $all_ids ) ), array( 'attachments' => count( array_unique( $all_ids ) ) ) );
			ntwp_test_assert( 'media_batch_' . $image_count . '_renders_all_images', $image_count === count( $image_matches[0] ), array( 'rendered' => count( $image_matches[0] ) ) );
			ntwp_test_assert( 'media_batch_' . $image_count . '_preserves_captions', $image_count === count( $caption_matches[0] ), array( 'captions' => count( $caption_matches[0] ) ) );
		}
	} finally {
		ntwp_test_delete_source_posts( $database_id );
		foreach ( $stable_urls as $stable_url ) {
			foreach ( ntwp_test_media_attachments( $stable_url ) as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}
		foreach ( $fixture_targets as $fixture_target ) {
			if ( is_file( $fixture_target ) ) {
				unlink( $fixture_target );
			}
		}
		update_option( 'notion_to_wordpress_options', $original_option );
	}
}

/**
 * Verify durable file/PDF/video/audio URLs and conservative attachment life cycle.
 */
function ntwp_test_attachment_fixture(): void {
	$database_id     = '46464646464646464646464646464646';
	$original_option = get_option( 'notion_to_wordpress_options', array() );
	$base_url        = getenv( 'NTWP_TEST_BASE_URL' );
	$wp_root         = getenv( 'NTWP_TEST_WP_ROOT' );
	$targets         = array();
	$stable_urls     = array();
	$replacement_target = rtrim( (string) $wp_root, '/\\' ) . DIRECTORY_SEPARATOR . 'ntwp-attachment-replacement.pdf';
	$replacement_url    = rtrim( (string) $base_url, '/' ) . '/ntwp-attachment-replacement.pdf';
	$specifications  = array(
		'file'  => array( 'filename' => 'ntwp-attachment-fixture.txt', 'body' => "attachment fixture\n" ),
		'pdf'   => array( 'filename' => 'ntwp-attachment-fixture.pdf', 'body' => "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n" ),
		'video' => array( 'filename' => 'ntwp-attachment-fixture.mp4', 'body' => pack( 'N', 24 ) . 'ftypisom' . pack( 'N', 0 ) . 'isomiso2' ),
		'audio' => array( 'filename' => 'ntwp-attachment-fixture.wav', 'body' => 'RIFF' . pack( 'V', 37 ) . 'WAVEfmt ' . pack( 'V', 16 ) . pack( 'vvVVvv', 1, 1, 8000, 8000, 1, 8 ) . 'data' . pack( 'V', 1 ) . "\x80" ),
	);

	if ( ! is_string( $base_url ) || '' === trim( $base_url ) || ! is_string( $wp_root ) || '' === trim( $wp_root ) ) {
		throw new RuntimeException( 'attachment-fixture requires NTWP_TEST_BASE_URL and NTWP_TEST_WP_ROOT.' );
	}

	$build_blocks = static function ( string $signature ) use ( &$stable_urls, $base_url, $specifications ): array {
		$blocks = array();
		foreach ( $specifications as $kind => $specification ) {
			$stable_url          = rtrim( $base_url, '/' ) . '/' . $specification['filename'];
			$stable_urls[ $kind ] = $stable_url;
			$blocks[]            = array(
				'id'           => md5( 'attachment-block-' . $kind ),
				'type'         => $kind,
				'has_children' => false,
				$kind           => array(
					'type'    => 'file',
					'file'    => array( 'url' => $stable_url . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=' . $signature ),
					'name'    => 'Fixture ' . strtoupper( $kind ) . '.' . pathinfo( $specification['filename'], PATHINFO_EXTENSION ),
					'caption' => array(
						array(
							'type'        => 'text',
							'plain_text'  => ucfirst( $kind ) . ' attachment caption',
							'href'        => null,
							'annotations' => array(),
						),
					),
				),
			);
		}
		return $blocks;
	};

	ntwp_test_delete_source_posts( $database_id );

	try {
		foreach ( $specifications as $kind => $specification ) {
			$target           = rtrim( $wp_root, '/\\' ) . DIRECTORY_SEPARATOR . $specification['filename'];
			$targets[ $kind ] = $target;
			if ( false === file_put_contents( $target, $specification['body'] ) ) {
				throw new RuntimeException( 'Unable to create attachment fixture: ' . $specification['filename'] );
			}
		}

		$first_blocks = $build_blocks( 'first' );
		foreach ( $stable_urls as $kind => $stable_url ) {
			foreach ( ntwp_test_attachment_ids( $kind, $stable_url ) as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}

		$options = is_array( $original_option ) ? $original_option : array();
		$options['enable_performance_mode']  = 0;
		$options['enable_super_batch_sync'] = false;
		$options['output_format']           = 'classic';
		unset( $options['database_sync_state'][ $database_id ] );
		update_option( 'notion_to_wordpress_options', $options );

		$first_stats = ( new Import_Coordinator( new NTWP_Attachment_Fixture_API( $first_blocks, '1' ), $database_id ) )->import_pages( false, false, true );
		$first_posts = ntwp_test_source_posts( $database_id );
		$first_hash  = ntwp_test_fingerprint( $first_posts );
		$all_content = implode( '\n', array_map( static fn( $post ): string => (string) $post->post_content, $first_posts ) );

		ntwp_test_assert( 'attachment_fixture_first_sync_succeeds', is_array( $first_stats ) && 0 === ( $first_stats['failed'] ?? -1 ) );
		ntwp_test_assert( 'attachment_fixture_creates_two_posts', 2 === count( $first_posts ), array( 'posts' => count( $first_posts ) ) );
		foreach ( $stable_urls as $kind => $stable_url ) {
			$ids = ntwp_test_attachment_ids( $kind, $stable_url );
			$details = array( 'attachments' => count( $ids ) );
			if ( 'audio' === $kind && 1 !== count( $ids ) ) {
				$audio_response             = wp_remote_get( $stable_url . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=diagnostic' );
				$details['response_code']   = is_wp_error( $audio_response ) ? 0 : wp_remote_retrieve_response_code( $audio_response );
				$details['response_mime']   = is_wp_error( $audio_response ) ? '' : wp_remote_retrieve_header( $audio_response, 'content-type' );
				$details['wordpress_mime']  = wp_check_filetype( 'fixture.wav' )['type'];
				$details['response_length'] = is_wp_error( $audio_response ) ? 0 : strlen( wp_remote_retrieve_body( $audio_response ) );
			}
			ntwp_test_assert( 'attachment_' . $kind . '_is_deduplicated_across_posts', 1 === count( $ids ), $details );
		}
		ntwp_test_assert( 'attachment_fixture_uses_local_urls', false !== strpos( $all_content, '/wp-content/uploads/' ) && false === strpos( $all_content, 'X-Amz-Signature' ) );
		ntwp_test_assert( 'attachment_fixture_preserves_all_captions', 4 === substr_count( $first_posts[0]->post_content, 'attachment caption' ) );

		foreach ( $targets as $target ) {
			if ( is_file( $target ) ) {
				unlink( $target );
			}
		}

		$second_blocks = $build_blocks( 'rotated' );
		$second_stats  = ( new Import_Coordinator( new NTWP_Attachment_Fixture_API( $second_blocks, '2' ), $database_id ) )->import_pages( false, false, true );
		$second_posts  = ntwp_test_source_posts( $database_id );
		ntwp_test_assert( 'attachment_signature_rotation_succeeds_without_origin', is_array( $second_stats ) && 0 === ( $second_stats['failed'] ?? -1 ) );
		ntwp_test_assert( 'attachment_signature_rotation_preserves_content', hash_equals( $first_hash, ntwp_test_fingerprint( $second_posts ) ) );

		file_put_contents( $replacement_target, "%PDF-1.4\n2 0 obj<</Type/Catalog>>endobj\n%%EOF\n" );
		$replacement_blocks = $build_blocks( 'replacement' );
		foreach ( $replacement_blocks as &$replacement_block ) {
			if ( 'pdf' === $replacement_block['type'] ) {
				$replacement_block['pdf']['file']['url'] = $replacement_url . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=replacement';
			}
		}
		unset( $replacement_block );
		$replacement_stats = ( new Import_Coordinator( new NTWP_Attachment_Fixture_API( $replacement_blocks, '3' ), $database_id ) )->import_pages( false, false, true );
		ntwp_test_assert( 'attachment_replacement_sync_succeeds', is_array( $replacement_stats ) && 0 === ( $replacement_stats['failed'] ?? -1 ) );
		ntwp_test_assert( 'attachment_replacement_creates_new_pdf', 1 === count( ntwp_test_attachment_ids( 'pdf', $replacement_url ) ) );
		ntwp_test_assert( 'attachment_replacement_retains_old_pdf', 1 === count( ntwp_test_attachment_ids( 'pdf', $stable_urls['pdf'] ) ) );

		$empty_stats = ( new Import_Coordinator( new NTWP_Attachment_Fixture_API( array(), '4' ), $database_id ) )->import_pages( false, false, true );
		ntwp_test_assert( 'attachment_source_removal_sync_succeeds', is_array( $empty_stats ) && 0 === ( $empty_stats['failed'] ?? -1 ) );
		foreach ( $stable_urls as $kind => $stable_url ) {
			ntwp_test_assert( 'attachment_' . $kind . '_is_retained_after_source_removal', 1 === count( ntwp_test_attachment_ids( $kind, $stable_url ) ) );
		}
	} finally {
		ntwp_test_delete_source_posts( $database_id );
		foreach ( $stable_urls as $kind => $stable_url ) {
			foreach ( ntwp_test_attachment_ids( $kind, $stable_url ) as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}
		foreach ( ntwp_test_attachment_ids( 'pdf', $replacement_url ) as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		foreach ( $targets as $target ) {
			if ( is_file( $target ) ) {
				unlink( $target );
			}
		}
		if ( is_file( $replacement_target ) ) {
			unlink( $replacement_target );
		}
		update_option( 'notion_to_wordpress_options', $original_option );
	}
}

/**
 * Assert that a content-fetch failure preserves the old body and cursor.
 */
function ntwp_test_failure_guard(): void {
	$database_id     = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	$page_id         = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	$sentinel        = '<p>preserve-existing-body</p>';
	$original_option = get_option( 'notion_to_wordpress_options', array() );
	$post_id         = 0;

	try {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Failure Guard Fixture',
				'post_content' => $sentinel,
				'post_status'  => 'draft',
				'post_type'    => 'post',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Unable to create the failure-guard fixture.' );
		}

		update_post_meta( $post_id, '_notion_page_id', $page_id );
		update_post_meta( $post_id, '_notion_database_id', $database_id );

		$options                          = is_array( $original_option ) ? $original_option : array();
		$options['enable_performance_mode'] = 0;
		$options['database_sync_state'][ $database_id ] = array( 'last_sync_time' => '2026-01-01 00:00:00' );
		update_option( 'notion_to_wordpress_options', $options );

		$coordinator = new Import_Coordinator( new NTWP_Failing_Content_API(), $database_id );
		$stats       = $coordinator->import_pages( false, false, true );
		$current     = get_post( $post_id );
		$current_opt = get_option( 'notion_to_wordpress_options', array() );
		$current_cur = Database_Config::get_last_sync_time( is_array( $current_opt ) ? $current_opt : array(), $database_id );

		ntwp_test_assert( 'transport_failure_is_reported', is_array( $stats ) && 1 === ( $stats['failed'] ?? 0 ), array( 'failed' => is_array( $stats ) ? ( $stats['failed'] ?? null ) : null ) );
		ntwp_test_assert( 'existing_body_is_preserved', $current && $sentinel === $current->post_content );
		ntwp_test_assert( 'failed_run_does_not_advance_cursor', '2026-01-01 00:00:00' === $current_cur );
	} finally {
		if ( is_int( $post_id ) && $post_id > 0 ) {
			wp_delete_post( $post_id, true );
		}
		update_option( 'notion_to_wordpress_options', $original_option );
	}
}

$mode = $argv[1] ?? 'smoke';

try {
	ntwp_test_assert( 'plugin_is_active', is_plugin_active( 'notion-to-wordpress/notion-to-wordpress.php' ) );

	if ( 'failure-guard' === $mode ) {
		ntwp_test_failure_guard();
	} elseif ( 'lifecycle' === $mode ) {
		ntwp_test_lifecycle();
	} elseif ( 'content-fixture' === $mode ) {
		ntwp_test_content_fixture();
	} elseif ( 'media-fixture' === $mode ) {
		ntwp_test_media_fixture();
	} elseif ( 'media-concurrency' === $mode ) {
		ntwp_test_media_concurrency();
	} elseif ( 'attachment-fixture' === $mode ) {
		ntwp_test_attachment_fixture();
	} elseif ( in_array( $mode, array( 'smoke', 'incremental', 'full' ), true ) ) {
		list( $token, $database_id ) = ntwp_test_credentials();
		$api                         = new API( $token );
		$connection                  = $api->test_connection( $database_id );
		ntwp_test_assert( 'notion_connection', true === $connection, array( 'error' => is_wp_error( $connection ) ? $connection->get_error_code() : null ) );

		if ( 'full' === $mode ) {
			if ( '1' !== getenv( 'NTWP_TEST_ALLOW_MUTATION' ) ) {
				throw new RuntimeException( 'Full mode requires NTWP_TEST_ALLOW_MUTATION=1.' );
			}

			$options = get_option( 'notion_to_wordpress_options', array() );
			$options = is_array( $options ) ? $options : array();
			$options['enable_super_batch_sync'] = false;
			update_option( 'notion_to_wordpress_options', $options );
			$coordinator = new Import_Coordinator( $api, $database_id, $options['field_mapping'] ?? array() );
			$stats       = $coordinator->import_pages( true, true, true );
			ntwp_test_assert( 'full_sync_completed', is_array( $stats ) && 0 === ( $stats['failed'] ?? -1 ) && 0 === ( $stats['errors'] ?? -1 ) && empty( $stats['interrupted'] ), is_array( $stats ) ? array_intersect_key( $stats, array_flip( array( 'total', 'imported', 'updated', 'failed', 'errors', 'interrupted' ) ) ) : array() );
		} elseif ( 'incremental' === $mode ) {
			$before      = ntwp_test_source_posts( $database_id );
			$fingerprint = ntwp_test_fingerprint( $before );
			$options     = get_option( 'notion_to_wordpress_options', array() );
			$options     = is_array( $options ) ? $options : array();
			$coordinator = new Import_Coordinator( $api, $database_id, $options['field_mapping'] ?? array() );
			$stats       = $coordinator->import_pages( true, true, false );
			$after       = ntwp_test_source_posts( $database_id );

			ntwp_test_assert( 'incremental_has_no_unexpected_changes', is_array( $stats ) && 0 === ( $stats['total'] ?? -1 ), array( 'total' => is_array( $stats ) ? ( $stats['total'] ?? null ) : null ) );
			ntwp_test_assert( 'incremental_preserves_count', count( $before ) === count( $after ), array( 'before' => count( $before ), 'after' => count( $after ) ) );
			ntwp_test_assert( 'incremental_preserves_content', hash_equals( $fingerprint, ntwp_test_fingerprint( $after ) ) );
		}

		ntwp_test_verify_synced_content( $api, $database_id );
	} else {
		throw new RuntimeException( 'Unknown mode. Use smoke, incremental, full, lifecycle, content-fixture, media-fixture, media-concurrency, attachment-fixture, or failure-guard.' );
	}
} catch ( Throwable $error ) {
	ntwp_test_assert( 'runner_completed_without_exception', false, array( 'exception' => get_class( $error ), 'message' => $error->getMessage() ) );
}

echo wp_json_encode(
	array(
		'mode'    => $mode,
		'status'  => $failed ? 'FAIL' : 'PASS',
		'results' => $results,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

exit( $failed ? 1 : 0 );
