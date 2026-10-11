<?php
declare(strict_types=1);

namespace NTWP\Services;

use Exception;
use InvalidArgumentException;

/**
 * 内容同步服务
 *
 * 分离数据访问层和业务逻辑层，提供统一的服务接口
 * 实现业务逻辑的封装和复用
 *
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 如果直接访问此文件，则退出
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 内容同步服务
 */
class Content_Sync_Service extends \NTWP\Core\Abstract_Service {

	/**
	 * 初始化服务
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;
	}

	/**
	 * 同步单个页面
	 *
	 * @param array $page_data 页面数据
	 * @param array $options 同步选项
	 * @return array 同步结果
	 */
	public function sync_page( array $page_data, array $options = array() ): array {
		$this->init();

		$page_id = $page_data['id'] ?? '';
		if ( empty( $page_id ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid page ID',
			);
		}

		try {
			$database_id = $this->resolve_database_id( $page_data, $options );
			// 检查缓存
			$cache_key     = "page_sync_{$database_id}_{$page_id}";
			$cached_result = \NTWP\Utils\Smart_Cache::get_tiered( 'page_content', $cache_key );
			if ( $cached_result !== false && ! ( $options['force_refresh'] ?? false ) ) {
				return $cached_result;
			}

			// 执行同步逻辑
			$result = $this->perform_page_sync( $page_data, $options );

			// 缓存结果
			if ( $result['success'] ) {
				\NTWP\Utils\Smart_Cache::set_tiered( 'page_content', $cache_key, $result, array(), 300 );
			}

			return $result;

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
				'page_id' => $page_id,
			);
		}
	}

	/**
	 * 批量同步页面
	 *
	 * @param array $pages_data 页面数据数组
	 * @param array $options 同步选项
	 * @return array 批量同步结果
	 */
	public function sync_pages_batch( array $pages_data, array $options = array() ): array {
		$this->init();

		$results = array(
			'total'   => count( $pages_data ),
			'success' => 0,
			'failed'  => 0,
			'details' => array(),
		);

		foreach ( $pages_data as $page_data ) {
			$result = $this->sync_page( $page_data, $options );

			if ( $result['success'] ) {
				++$results['success'];
			} else {
				++$results['failed'];
			}

			$results['details'][] = $result;
		}

		return $results;
	}

	/**
	 * 执行页面同步
	 *
	 * @param array $page_data 页面数据
	 * @param array $options 同步选项
	 * @return array 同步结果
	 */
	private function perform_page_sync( array $page_data, array $options ): array {

		$coordinator = $this->create_source_scoped_coordinator( $page_data, $options );
		$sync_result = $coordinator->import_notion_page( $page_data );
		if ( $sync_result === false ) {
			return array(
				'success'   => false,
				'error'     => '页面导入失败',
				'notion_id' => $page_data['id'],
			);
		}

		return array(
			'success'   => true,
			'action'    => $sync_result === 'skipped' ? 'skipped' : 'synced',
			'notion_id' => $page_data['id'],
		);
	}

	/**
	 * 解析并验证任务页面的来源数据库。
	 */
	private function resolve_database_id( array $page_data, array $options ): string {

		$configured_database_id = $options['database_id'] ?? $options['notion_database_id'] ?? '';
		$payload_database_id    = $page_data['database_id'] ?? $page_data['_notion_database_id'] ?? ( $page_data['parent']['database_id'] ?? '' );
		if ( ! is_string( $configured_database_id ) || ! is_string( $payload_database_id ) ) {
			throw new InvalidArgumentException( '同步页面的数据库来源无效' );
		}
		if ( $configured_database_id !== '' && $payload_database_id !== '' && $configured_database_id !== $payload_database_id ) {
			throw new InvalidArgumentException( '同步页面的数据库来源与任务配置不一致' );
		}

		$database_id = $configured_database_id !== '' ? $configured_database_id : $payload_database_id;
		if ( $database_id === '' ) {
			throw new InvalidArgumentException( '同步页面缺少来源数据库 ID' );
		}

		return $database_id;
	}

	/**
	 * 创建来源隔离的页面导入协调器。
	 */
	private function create_source_scoped_coordinator( array $page_data, array $options ): \NTWP\Handlers\Import_Coordinator {

		$plugin_options = get_option( 'notion_to_wordpress_options', array() );
		$api_key        = $plugin_options['notion_api_key'] ?? '';
		if ( ! is_string( $api_key ) || $api_key === '' ) {
			throw new InvalidArgumentException( '未配置 Notion API 密钥' );
		}

		$field_mapping = $options['field_mapping'] ?? ( $plugin_options['field_mapping'] ?? array() );
		if ( ! is_array( $field_mapping ) ) {
			$field_mapping = array();
		}

		return new \NTWP\Handlers\Import_Coordinator( new API( $api_key ), $this->resolve_database_id( $page_data, $options ), $field_mapping );
	}
}
