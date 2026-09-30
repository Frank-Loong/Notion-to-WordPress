<?php
declare(strict_types=1);

namespace NTWP\Core;

use Exception;
use InvalidArgumentException;

/**
 * 任务执行器
 *
 * 负责执行具体的异步任务，支持多种操作类型
 * 提供统一的执行接口和错误处理机制
 *
 * @since      2.0.0-beta.2
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Task_Executor {

	/**
	 * 支持的操作类型
	 */
	private const SUPPORTED_OPERATIONS = array(
		'import_pages',
		'update_pages',
		'process_images',
		'delete_posts',
		'sync_incremental',
		'cleanup_data',
	);
	/**
	 * 调度器任务类型与执行器操作的兼容映射。
	 *
	 * @var array<string, string>
	 */
	private const OPERATION_ALIASES = array(
		'batch_import'     => 'import_pages',
		'incremental_sync' => 'sync_incremental',
		'image_processing' => 'process_images',
		'cleanup'          => 'cleanup_data',
	);

	/**
	 * 执行任务
	 *
	 * @param string $operation 操作类型
	 * @param array  $data 任务数据
	 * @param array  $config 配置选项
	 * @return array 执行结果
	 */
	public function execute( string $operation, array $data, array $config ): array {

		$startTime   = microtime( true );
		$startMemory = memory_get_usage( true );

		Logger::debug_log(
			sprintf( '开始执行任务: %s, 数据量: %d', $operation, count( $data ) ),
			'Task Executor'
		);

		try {
			$operation = self::OPERATION_ALIASES[ $operation ] ?? $operation;

			// 验证操作类型
			if ( ! in_array( $operation, self::SUPPORTED_OPERATIONS ) ) {
				throw new InvalidArgumentException( "不支持的操作类型: {$operation}" );
			}

			// 设置内存和时间限制
			$this->setExecutionLimits( $config );

			// 执行具体操作
			$result = $this->executeOperation( $operation, $data, $config );

			// 计算执行统计
			$executionTime = microtime( true ) - $startTime;
			$memoryUsed    = memory_get_usage( true ) - $startMemory;
			$memoryPeak    = memory_get_peak_usage( true );

			$finalResult = array_merge(
				$result,
				array(
					'execution_time' => $executionTime,
					'memory_used'    => $memoryUsed,
					'memory_peak'    => $memoryPeak,
					'status'         => 'completed',
				)
			);

			Logger::debug_log(
				sprintf(
					'任务执行完成: %s, 耗时: %.2fs, 内存: %s',
					$operation,
					$executionTime,
					size_format( $memoryUsed )
				),
				'Task Executor'
			);

			return $finalResult;

		} catch ( Exception $e ) {
			$executionTime      = microtime( true ) - $startTime;
			$safe_operation     = esc_html( $operation );
			$safe_error_message = esc_html( $e->getMessage() );

			Logger::error_log(
				sprintf( '任务执行失败: %s, 错误: %s, 耗时: %.2fs', $safe_operation, $safe_error_message, $executionTime ),
				'Task Executor'
			);

			return array(
				'status'         => 'failed',
				'error'          => $safe_error_message,
				'execution_time' => $executionTime,
				'success_count'  => 0,
				'error_count'    => count( $data ),
			);
		}
	}

	/**
	 * 执行具体操作
	 */
	private function executeOperation( string $operation, array $data, array $config ): array {
		switch ( $operation ) {
			case 'import_pages':
				return $this->executeImportPages( $data, $config );
			case 'update_pages':
				return $this->executeUpdatePages( $data, $config );
			case 'process_images':
				return $this->executeProcessImages( $data, $config );
			case 'delete_posts':
				return $this->executeDeletePosts( $data, $config );
			case 'sync_incremental':
				return $this->executeSyncIncremental( $data, $config );
			case 'cleanup_data':
				return $this->executeCleanupData( $data, $config );
			default:
				throw new InvalidArgumentException( '未实现的操作: ' . esc_html( $operation ) );
		}
	}

	/**
	 * 执行页面导入
	 */
	private function executeImportPages( array $pages, array $config ): array {

		$successCount = 0;
		$errorCount   = 0;
		$errors       = array();

		if ( isset( $pages['pages'] ) && is_array( $pages['pages'] ) ) {
			$pages = $pages['pages'];
		}

		foreach ( $pages as $pageData ) {
			try {
				if ( ! is_array( $pageData ) ) {
					throw new InvalidArgumentException( '页面任务数据无效' );
				}

				$coordinator = $this->createSourceScopedCoordinator( $pageData, $config );
				$result      = $coordinator->import_notion_page( $pageData );
				if ( $result ) {
					++$successCount;
				} else {
					++$errorCount;
					$errors[] = '页面导入失败: ' . ( $pageData['id'] ?? 'unknown' );
				}

				// 内存管理
				if ( memory_get_usage( true ) > $this->getMemoryLimit() * 0.8 ) {
					gc_collect_cycles();
				}
			} catch ( Exception $e ) {
				++$errorCount;
				$errors[] = sprintf( '页面处理异常: %s', $e->getMessage() );
			}
		}

		return array(
			'success_count' => $successCount,
			'error_count'   => $errorCount,
			'errors'        => $errors,
		);
	}

	/**
	 * 执行页面更新
	 */
	private function executeUpdatePages( array $pages, array $config ): array {

		return $this->executeImportPages( $pages, $config );
	}

	/**
	 * 执行图片处理
	 */
	private function executeProcessImages( array $images, array $config ): array {
		$successCount = 0;
		$errorCount   = 0;
		$errors       = array();

		foreach ( $images as $imageData ) {
			try {
				$url = $imageData['url'] ?? '';
				if ( ! is_string( $url ) || $url === '' ) {
					throw new InvalidArgumentException( '图片任务缺少 URL' );
				}

				$result = \NTWP\Services\Image_Processor::process_images_parallel( array( $url ), (int) ( $config['max_concurrent'] ?? 1 ) );
				if ( ! empty( $result[ $url ] ) ) {
					++$successCount;
				} else {
					++$errorCount;
					$errors[] = "图片处理失败: {$url}";
				}
			} catch ( Exception $e ) {
				++$errorCount;
				$errors[] = sprintf( '图片处理异常: %s', $e->getMessage() );
			}
		}

		return array(
			'success_count' => $successCount,
			'error_count'   => $errorCount,
			'errors'        => $errors,
		);
	}

	/**
	 * 执行文章删除
	 */
	private function executeDeletePosts( array $posts, array $config ): array {
		$successCount = 0;
		$errorCount   = 0;
		$errors       = array();

		foreach ( $posts as $postData ) {
			try {
				$postId = $postData['post_id'] ?? $postData['id'] ?? null;
				if ( ! $postId ) {
					++$errorCount;
					$errors[] = '缺少文章ID';
					continue;
				}

				$result = wp_delete_post( $postId, true );
				if ( $result ) {
					++$successCount;
				} else {
					++$errorCount;
					$errors[] = "删除文章失败: {$postId}";
				}
			} catch ( Exception $e ) {
				++$errorCount;
				$errors[] = sprintf( '删除文章异常: %s', $e->getMessage() );
			}
		}

		return array(
			'success_count' => $successCount,
			'error_count'   => $errorCount,
			'errors'        => $errors,
		);
	}

	/**
	 * 执行增量同步
	 */
	private function executeSyncIncremental( array $data, array $config ): array {

		try {
			$coordinator = $this->createSourceScopedCoordinator( $data, $config );
			$result      = $coordinator->import_pages( true, true, false );
			if ( is_wp_error( $result ) ) {
				throw new Exception( $result->get_error_message() );
			}

			return array(
				'success_count' => (int) ( $result['imported'] ?? 0 ) + (int) ( $result['updated'] ?? 0 ),
				'error_count'   => (int) ( $result['failed'] ?? 0 ),
				'errors'        => array(),
			);
		} catch ( Exception $e ) {
			return array(
				'success_count' => 0,
				'error_count'   => count( $data ),
				'errors'        => array( $e->getMessage() ),
			);
		}
	}

	/**
	 * 执行数据清理
	 */
	private function executeCleanupData( array $data, array $config ): array {
		$successCount = 0;
		$errorCount   = 0;
		$errors       = array();

		foreach ( $data as $item ) {
			try {
				$type = $item['type'] ?? 'unknown';

				switch ( $type ) {
					case 'orphaned_meta':
						$result = $this->cleanupOrphanedMeta( $item );
						break;
					case 'expired_cache':
						$result = $this->cleanupExpiredCache( $item );
						break;
					case 'temp_files':
						$result = $this->cleanupTempFiles( $item );
						break;
					default:
						$result = array(
							'success' => false,
							'error'   => "未知清理类型: {$type}",
						);
				}

				if ( $result['success'] ) {
					++$successCount;
				} else {
					++$errorCount;
					$errors[] = $result['error'];
				}
			} catch ( Exception $e ) {
				++$errorCount;
				$errors[] = sprintf( '清理异常: %s', $e->getMessage() );
			}
		}

		return array(
			'success_count' => $successCount,
			'error_count'   => $errorCount,
			'errors'        => $errors,
		);
	}

	/**
	 * 设置执行限制
	 */
	private function setExecutionLimits( array $config ): void {
		// 设置内存限制
		if ( isset( $config['memory_limit'] ) ) {
			ini_set( 'memory_limit', $config['memory_limit'] );
		}

		// 设置执行时间限制
		if ( isset( $config['timeout'] ) ) {
			set_time_limit( $config['timeout'] );
		}
	}

	/**
	 * 获取内存限制
	 */
	private function getMemoryLimit(): int {
		$limit = ini_get( 'memory_limit' );
		if ( $limit === '-1' ) {
			return PHP_INT_MAX;
		}

		$value = (int) $limit;
		$unit  = strtolower( substr( $limit, -1 ) );

		switch ( $unit ) {
			case 'g':
				return $value * 1024 * 1024 * 1024;
			case 'm':
				return $value * 1024 * 1024;
			case 'k':
				return $value * 1024;
			default:
				return $value;
		}
	}

	/**
	 * 创建与来源数据库绑定的导入协调器。
	 *
	 * 不接受队列配置中的 API 密钥，避免将密钥持久化到任务文件中；仅从插件设置读取。
	 *
	 * @param array<string, mixed> $data 任务或页面数据
	 * @param array<string, mixed> $config 任务配置
	 */
	private function createSourceScopedCoordinator( array $data, array $config ): \NTWP\Handlers\Import_Coordinator {

		$configured_database_id = $config['database_id'] ?? $config['notion_database_id'] ?? '';
		$payload_database_id    = $data['database_id'] ?? $data['_notion_database_id'] ?? ( $data['parent']['database_id'] ?? '' );
		if ( ! is_string( $configured_database_id ) || ! is_string( $payload_database_id ) ) {
			throw new InvalidArgumentException( '异步任务的数据库来源无效' );
		}
		if ( $configured_database_id !== '' && $payload_database_id !== '' && $configured_database_id !== $payload_database_id ) {
			throw new InvalidArgumentException( '异步任务的数据库来源与页面来源不一致' );
		}

		$database_id = $configured_database_id !== '' ? $configured_database_id : $payload_database_id;
		if ( $database_id === '' ) {
			throw new InvalidArgumentException( '异步同步任务缺少来源数据库 ID' );
		}

		$options = get_option( 'notion_to_wordpress_options', array() );
		$api_key = is_array( $options ) ? ( $options['notion_api_key'] ?? '' ) : '';
		if ( ! is_string( $api_key ) || $api_key === '' ) {
			throw new InvalidArgumentException( '未配置 Notion API 密钥' );
		}

		$field_mapping = $config['field_mapping'] ?? ( is_array( $options ) ? ( $options['field_mapping'] ?? array() ) : array() );
		if ( ! is_array( $field_mapping ) ) {
			$field_mapping = array();
		}

		return new \NTWP\Handlers\Import_Coordinator( new \NTWP\Services\API( $api_key ), $database_id, $field_mapping );
	}

	/**
	 * 清理孤立的元数据
	 */
	private function cleanupOrphanedMeta( array $item ): array {
		// 实现孤立元数据清理逻辑
		return array( 'success' => true );
	}

	/**
	 * 清理过期缓存
	 */
	private function cleanupExpiredCache( array $item ): array {
		// 实现过期缓存清理逻辑
		return array( 'success' => true );
	}

	/**
	 * 清理临时文件
	 */
	private function cleanupTempFiles( array $item ): array {
		// 实现临时文件清理逻辑
		return array( 'success' => true );
	}
}
