<?php
declare(strict_types=1);

namespace NTWP\Core;

use Exception;

/**
 * 异步任务调度器
 *
 * 负责管理和调度异步任务，支持智能调度、优先级管理和任务监控
 * 提供与现代异步引擎的集成接口
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

class Async_Task_Scheduler {

	/**
	 * 任务类型常量
	 */
	public const TASK_INCREMENTAL_SYNC = 'incremental_sync';
	public const TASK_BATCH_IMPORT     = 'batch_import';
	public const TASK_IMAGE_PROCESSING = 'image_processing';
	public const TASK_CLEANUP          = 'cleanup';

	/**
	 * 调度器实例（单例模式）
	 */
	private static ?self $instance = null;

	/**
	 * 是否已初始化
	 */
	private bool $initialized = false;

	/**
	 * Action Scheduler 是否可用
	 */
	private bool $action_scheduler_available = false;

	/**
	 * 构造函数（私有，单例模式）
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * 获取单例实例
	 */
	public static function getInstance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 初始化调度器
	 */
	private function init(): void {
		if ( $this->initialized ) {
			return;
		}

		// 检查 Action Scheduler 是否可用
		$this->action_scheduler_available = function_exists( 'as_schedule_single_action' );
		foreach ( array( self::TASK_INCREMENTAL_SYNC, self::TASK_BATCH_IMPORT, self::TASK_IMAGE_PROCESSING, self::TASK_CLEANUP ) as $task_type ) {
			add_action( 'notion_async_task_' . $task_type, array( $this, 'handleActionSchedulerTask' ), 10, 2 );
		}

		// 🚀 性能优化：减少日志频率，避免重复记录相同状态
		if ( class_exists( 'NTWP\Core\Logger' ) && ! defined( 'NOTION_PERFORMANCE_MODE' ) ) {
			static $logged_status = null;
			static $log_count     = 0;

			$current_status = $this->action_scheduler_available ? 'available' : 'unavailable';

			// 只在状态变化或每100次初始化时记录一次
			if ( $logged_status !== $current_status || ( ++$log_count % 100 === 0 ) ) {
				if ( ! $this->action_scheduler_available ) {
					Logger::debug_log(
						sprintf( 'Action Scheduler不可用，启用WordPress Cron回退机制 (初始化次数: %d)', $log_count ),
						'Async Task Scheduler'
					);
				} else {
					Logger::debug_log(
						sprintf( 'Action Scheduler已启用 (初始化次数: %d)', $log_count ),
						'Async Task Scheduler'
					);
				}
				$logged_status = $current_status;
			}
		}

		$this->initialized = true;
	}

	/**
	 * 智能调度任务
	 *
	 * @param string $task_type 任务类型
	 * @param array  $task_data 任务数据
	 * @param array  $options 调度选项
	 * @return string|false 任务ID或false
	 */
	public function smart_schedule( string $task_type, array $task_data, array $options = array() ) {
		$this->init();

		// 优先使用现代异步引擎
		if ( class_exists( 'NTWP\Core\Modern_Async_Engine' ) ) {
			return $this->scheduleWithModernEngine( $task_type, $task_data, $options );
		}

		// 回退到传统调度方式
		return $this->scheduleWithTraditionalMethod( $task_type, $task_data, $options );
	}

	/**
	 * 使用现代异步引擎调度任务
	 */
	private function scheduleWithModernEngine( string $task_type, array $task_data, array $options ): string {
		$config = array_merge(
			array(
				'batch_size' => 20,
				'timeout'    => 300,
				'priority'   => Modern_Async_Engine::PRIORITY_NORMAL,
			),
			$options
		);

		// 根据任务类型调整配置
		switch ( $task_type ) {
			case self::TASK_INCREMENTAL_SYNC:
				$config['batch_size'] = $options['batch_size'] ?? 30;
				$config['priority']   = Modern_Async_Engine::PRIORITY_HIGH;
				break;

			case self::TASK_BATCH_IMPORT:
				$config['batch_size'] = $options['batch_size'] ?? 20;
				$config['priority']   = Modern_Async_Engine::PRIORITY_NORMAL;
				break;

			case self::TASK_IMAGE_PROCESSING:
				$config['batch_size'] = $options['batch_size'] ?? 10;
				$config['priority']   = Modern_Async_Engine::PRIORITY_LOW;
				break;

			case self::TASK_CLEANUP:
				$config['batch_size'] = $options['batch_size'] ?? 50;
				$config['priority']   = Modern_Async_Engine::PRIORITY_LOW;
				break;
		}

		return Modern_Async_Engine::execute( $task_type, $task_data, $config );
	}

	/**
	 * 使用传统方法调度任务
	 */
	private function scheduleWithTraditionalMethod( string $task_type, array $task_data, array $options ) {
		if ( $this->action_scheduler_available ) {
			return $this->scheduleWithActionScheduler( $task_type, $task_data, $options );
		} else {
			return $this->scheduleWithWordPressCron( $task_type, $task_data, $options );
		}
	}

	/**
	 * 使用 Action Scheduler 调度任务
	 */
	private function scheduleWithActionScheduler( string $task_type, array $task_data, array $options ) {

		$hook                           = 'notion_async_task_' . $task_type;
		$task_data['_notion_task_type'] = $task_type;
		$args                           = array( $task_data, $options );
		$timestamp                      = time() + ( $options['delay'] ?? 0 );

		$task_id = call_user_func( 'as_schedule_single_action', $timestamp, $hook, $args, 'notion-async' );

		if ( $task_id ) {
			Logger::info_log(
				sprintf( '任务已通过Action Scheduler调度: %s, ID: %s', $task_type, $task_id ),
				'Async Task Scheduler'
			);
		}

		return $task_id;
	}

	/**
	 * 执行 Action Scheduler 触发的任务。
	 */
	public function handleActionSchedulerTask( array $task_data, array $options ): void {

		$task_type = $task_data['_notion_task_type'] ?? '';
		unset( $task_data['_notion_task_type'] );
		$this->executeScheduledTask( $task_type, $task_data, $options );
	}

	/**
	 * 使用 WordPress Cron 调度任务
	 */
	private function scheduleWithWordPressCron( string $task_type, array $task_data, array $options ) {

		$hook      = 'notion_cron_task_' . $task_type;
		$timestamp = time() + ( $options['delay'] ?? 0 );
		$task_id   = uniqid( 'cron_' . $task_type . '_' );

		// 注册钩子处理器
		if ( ! has_action( $hook ) ) {
			add_action( $hook, array( $this, 'handleCronTask' ), 10, 3 );
		}

		// 调度事件
		$task_data['_notion_task_type'] = $task_type;
		$scheduled                      = wp_schedule_single_event( $timestamp, $hook, array( $task_id, $task_data, $options ) );

		if ( $scheduled !== false ) {
			Logger::info_log(
				sprintf( '任务已通过WordPress Cron调度: %s, ID: %s', $task_type, $task_id ),
				'Async Task Scheduler'
			);
			return $task_id;
		}

		return false;
	}

	/**
	 * 处理 Cron 任务
	 */
	public function handleCronTask( string $task_id, array $task_data, array $options ): void {

		Logger::info_log( sprintf( 'Cron任务开始执行: %s', $task_id ), 'Async Task Scheduler' );
		$task_type = $task_data['_notion_task_type'] ?? '';
		unset( $task_data['_notion_task_type'] );
		$this->executeScheduledTask( $task_type, $task_data, $options );
	}

	/**
	 * 通过统一执行器运行传统调度的任务。
	 */
	private function executeScheduledTask( string $task_type, array $task_data, array $options ): void {

		if ( ! in_array(
			$task_type,
			array(
				self::TASK_INCREMENTAL_SYNC,
				self::TASK_BATCH_IMPORT,
				self::TASK_IMAGE_PROCESSING,
				self::TASK_CLEANUP,
			),
			true
		) ) {
			Logger::error_log( '异步任务缺少或包含未知的任务类型', 'Async Task Scheduler' );
			return;
		}

		$result = ( new Task_Executor() )->execute( $task_type, $task_data, $options );
		if ( ( $result['status'] ?? '' ) === 'failed' || (int) ( $result['error_count'] ?? 0 ) > 0 ) {
			Logger::error_log( sprintf( '异步任务执行失败: %s', implode( '; ', $result['errors'] ?? array( $result['error'] ?? '未知错误' ) ) ), 'Async Task Scheduler' );
		}
	}

	/**
	 * 取消任务
	 */
	public function cancel_task( string $task_id ): bool {
		if ( $this->action_scheduler_available && function_exists( 'as_unschedule_action' ) ) {
			return as_unschedule_action( '', array(), 'notion-async' ) > 0;
		}

		// WordPress Cron 任务取消逻辑
		return wp_clear_scheduled_hook( 'notion_cron_task_' . $task_id ) > 0;
	}

	/**
	 * 获取任务状态
	 */
	public function get_task_status( string $task_id ): array {
		// 如果使用现代异步引擎，委托给它
		if ( class_exists( 'NTWP\Core\Modern_Async_Engine' ) ) {
			return Modern_Async_Engine::getProgress( $task_id );
		}

		return array(
			'status'  => 'unknown',
			'message' => '无法获取任务状态',
		);
	}

	/**
	 * 清理过期任务
	 */
	public function cleanup_expired_tasks(): int {
		$cleaned = 0;

		if ( class_exists( 'NTWP\Core\Modern_Async_Engine' ) ) {
			Modern_Async_Engine::cleanup();
			++$cleaned;
		}

		return $cleaned;
	}

	/**
	 * 防止克隆
	 */
	private function __clone() {}

	/**
	 * 防止反序列化
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
