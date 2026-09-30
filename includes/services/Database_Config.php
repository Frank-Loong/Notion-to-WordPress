<?php
/**
 * 多数据库配置辅助类
 *
 * 统一管理“同步哪些 Notion 数据库”的配置读取与向后兼容迁移。
 * 主数据库（notion_database_id，由 React 前端管理）始终包含在内；
 * 附加数据库来自 notion_databases 选项（每行一个 ID，可选 "名称|ID" 格式）。
 *
 * @since 2.0.0
 */

namespace NTWP\Services;

class Database_Config {

	/**
	 * Meta key used to associate an imported post with its source database.
	 */
	public const META_DATABASE_ID = '_notion_database_id';

	private const SYNC_STATE_KEY       = 'database_sync_state';
	private const LEGACY_MIGRATION_KEY = 'database_source_migration_complete';

	/**
	 * 返回需要同步的数据库列表。
	 *
	 * 每个元素为 ['id' => string, 'name' => string, 'field_mapping' => array]。
	 * 主数据库（notion_database_id）始终置于首位；notion_databases 中的附加库
	 * 去重后追加。无任何配置时返回空数组。
	 *
	 * @since 2.0.0
	 * @param array $options notion_to_wordpress_options
	 * @return array
	 */
	public static function get_configured_databases( array $options ): array {
		$databases = array();
		$seen      = array();

		// 1) 主数据库（React 前端管理，保持向后兼容）
		$primary_id = $options['notion_database_id'] ?? '';
		$primary_id = is_string( $primary_id ) ? trim( $primary_id ) : '';
		if ( $primary_id !== '' ) {
			$databases[]         = array(
				'id'            => $primary_id,
				'name'          => '',
				'field_mapping' => $options['field_mapping'] ?? array(),
			);
			$seen[ $primary_id ] = true;
		}

		// 2) 附加多数据库
		$extra = $options['notion_databases'] ?? array();
		if ( is_array( $extra ) ) {
			foreach ( $extra as $db ) {
				if ( is_array( $db ) ) {
					$id = isset( $db['id'] ) ? (string) $db['id'] : '';
				} else {
					$id = (string) $db;
				}
				$id = trim( $id );
				if ( $id === '' || isset( $seen[ $id ] ) ) {
					continue;
				}
				$databases[] = array(
					'id'            => $id,
					'name'          => is_array( $db ) ? ( $db['name'] ?? '' ) : '',
					'field_mapping' => is_array( $db ) ? ( $db['field_mapping'] ?? array() ) : array(),
				);
				$seen[ $id ] = true;
			}
		}

		return $databases;
	}

	/**
	 * 将表单提交的“每行一个数据库”文本解析为 notion_databases 结构。
	 *
	 * 支持两种格式（每行）：
	 *   - 纯 ID：32 位十六进制（可含连字符）
	 *   - 名称|ID：以首个 | 分隔，左侧为名称，右侧为 ID
	 *
	 * @since 2.0.0
	 * @param string $raw 文本框原始内容
	 * @return array
	 */
	public static function parse_database_list( string $raw ): array {
		$databases = array();
		$lines     = preg_split( '/\r\n|\r|\n/', $raw );
		if ( ! is_array( $lines ) ) {
			return $databases;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			if ( strpos( $line, '|' ) !== false ) {
				list( $name, $id ) = array_map( 'trim', explode( '|', $line, 2 ) );
			} else {
				$name = '';
				$id   = $line;
			}
			$id = trim( $id );
			if ( $id === '' ) {
				continue;
			}
			$databases[] = array(
				'id'   => $id,
				'name' => $name,
			);
		}

		return $databases;
	}

	/**
	 * Return the cursor for one database. The cursor is intentionally scoped to
	 * the database so a failed database cannot hold back, or advance, another.
	 */
	public static function get_last_sync_time( array $options, string $database_id ): string {
		$state = $options[ self::SYNC_STATE_KEY ] ?? array();
		if ( ! is_array( $state ) || ! isset( $state[ $database_id ] ) || ! is_array( $state[ $database_id ] ) ) {
			return '';
		}

		$cursor = $state[ $database_id ]['last_sync_time'] ?? '';
		return is_string( $cursor ) ? $cursor : '';
	}

	/**
	 * Initialize per-database cursors from the legacy single cursor exactly once.
	 * Only the historical primary database inherits the old cursor; added
	 * databases begin with a full import.
	 */
	public static function migrate_sync_state( array $options ): array {
		$state      = $options[ self::SYNC_STATE_KEY ] ?? array();
		$state      = is_array( $state ) ? $state : array();
		$primary_id = isset( $options['notion_database_id'] ) && is_string( $options['notion_database_id'] )
			? trim( $options['notion_database_id'] )
			: '';

		if ( $primary_id !== '' && ! isset( $state[ $primary_id ] ) ) {
			$legacy_cursor        = $options['last_sync_time'] ?? '';
			$state[ $primary_id ] = array(
				'last_sync_time' => is_string( $legacy_cursor ) ? $legacy_cursor : '',
			);
		}

		foreach ( self::get_configured_databases( $options ) as $database ) {
			if ( ! isset( $state[ $database['id'] ] ) ) {
				$state[ $database['id'] ] = array( 'last_sync_time' => '' );
			}
		}

		$options[ self::SYNC_STATE_KEY ] = $state;
		return $options;
	}

	/**
	 * Persist a completed cursor for one source database.
	 */
	public static function mark_database_sync_completed( string $database_id, string $cursor ): void {
		$options = get_option( 'notion_to_wordpress_options', array() );
		$options = is_array( $options ) ? self::migrate_sync_state( $options ) : array();
		$options[ self::SYNC_STATE_KEY ][ $database_id ] = array( 'last_sync_time' => $cursor );
		update_option( 'notion_to_wordpress_options', $options );
	}

	/**
	 * Attach source metadata to posts created before multi-database support.
	 * A legacy installation had one configured source, so it is safe to assign
	 * unscoped imported posts to that primary database. The insert is idempotent.
	 */
	public static function migrate_legacy_post_sources( array $options ): void {
		if ( ! empty( $options[ self::LEGACY_MIGRATION_KEY ] ) ) {
			return;
		}

		$primary_id = isset( $options['notion_database_id'] ) && is_string( $options['notion_database_id'] )
			? trim( $options['notion_database_id'] )
			: '';
		if ( $primary_id === '' ) {
			return;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */
		$query = $wpdb->prepare(
			"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
             SELECT page_meta.post_id, %s, %s
             FROM {$wpdb->postmeta} page_meta
             INNER JOIN {$wpdb->posts} posts ON posts.ID = page_meta.post_id
             LEFT JOIN {$wpdb->postmeta} source_meta
                ON source_meta.post_id = page_meta.post_id AND source_meta.meta_key = %s
             WHERE page_meta.meta_key = %s
               AND source_meta.meta_id IS NULL",
			self::META_DATABASE_ID,
			$primary_id,
			self::META_DATABASE_ID,
			'_notion_page_id'
		);
		$wpdb->query( $query );

		$options[ self::LEGACY_MIGRATION_KEY ] = true;
		update_option( 'notion_to_wordpress_options', $options );
	}
}
