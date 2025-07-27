<?php
declare(strict_types=1);
/**
 * Notion异步任务调度器
 * 
 * 基于WordPress Action Scheduler实现真正的后台异步任务处理
 * 提升用户体验和系统资源利用率
 *
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 异步任务调度器类
 * 
 * 利用WordPress Action Scheduler实现高效的后台任务处理
 *
 * @since 2.0.0-beta.1
 */
class Notion_Async_Task_Scheduler {

    /**
     * 任务组名称
     */
    const TASK_GROUP = 'notion_async_tasks';
    
    /**
     * 任务优先级
     */
    const PRIORITY_HIGH = 1;
    const PRIORITY_NORMAL = 5;
    const PRIORITY_LOW = 10;
    
    /**
     * 任务类型
     */
    const TASK_CONTENT_PROCESSING = 'content_processing';
    const TASK_IMAGE_PROCESSING = 'image_processing';
    const TASK_BATCH_IMPORT = 'batch_import';
    const TASK_INCREMENTAL_SYNC = 'incremental_sync';
    const TASK_CLEANUP = 'cleanup';
    
    /**
     * 任务状态
     */
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    
    /**
     * 最大重试次数
     */
    const MAX_RETRIES = 3;
    
    /**
     * 任务超时时间（秒）
     */
    const TASK_TIMEOUT = 300; // 5分钟
    
    /**
     * 初始化调度器
     */
    public static function init(): void {
        // 检查Action Scheduler是否可用（优化版）
        if (!self::is_action_scheduler_available()) {
            // 尝试启用基础的WordPress Cron作为替代
            self::init_fallback_scheduler();

            if (class_exists('Notion_Logger') && !defined('NOTION_PERFORMANCE_MODE')) {
                Notion_Logger::warning_log(
                    'Action Scheduler不可用，启用WordPress Cron回退机制',
                    'Async Task Scheduler'
                );
            }
            return;
        }
        
        // 注册任务处理钩子
        add_action('notion_process_content_task', [self::class, 'handle_content_processing'], 10, 1);
        add_action('notion_process_image_task', [self::class, 'handle_image_processing'], 10, 1);
        add_action('notion_batch_import_task', [self::class, 'handle_batch_import'], 10, 1);
        add_action('notion_incremental_sync_task', [self::class, 'handle_incremental_sync'], 10, 1);
        add_action('notion_cleanup_task', [self::class, 'handle_cleanup'], 10, 1);
        
        // 注册任务状态监控
        add_action('action_scheduler_completed_action', [self::class, 'on_task_completed'], 10, 1);
        add_action('action_scheduler_failed_action', [self::class, 'on_task_failed'], 10, 1);
        add_action('action_scheduler_canceled_action', [self::class, 'on_task_cancelled'], 10, 1);
        
        // 定期清理完成的任务
        if (!as_next_scheduled_action('notion_cleanup_completed_tasks')) {
            as_schedule_recurring_action(
                time() + HOUR_IN_SECONDS,
                HOUR_IN_SECONDS * 6, // 每6小时清理一次
                'notion_cleanup_completed_tasks',
                [],
                self::TASK_GROUP
            );
        }
        
        add_action('notion_cleanup_completed_tasks', [self::class, 'cleanup_completed_tasks']);
    }
    
    /**
     * 检查Action Scheduler是否可用（优化版）
     */
    public static function is_action_scheduler_available(): bool {
        // 缓存检查结果，避免重复检查
        static $is_available = null;

        if ($is_available === null) {
            $is_available = function_exists('as_schedule_single_action') &&
                           function_exists('as_get_scheduled_actions') &&
                           function_exists('as_unschedule_all_actions') &&
                           class_exists('ActionScheduler');

            // 尝试安装Action Scheduler（如果WooCommerce可用）
            if (!$is_available && function_exists('WC')) {
                $is_available = true; // WooCommerce包含Action Scheduler
            }
        }

        return $is_available;
    }

    /**
     * 初始化回退调度器（使用WordPress Cron）
     */
    private static function init_fallback_scheduler(): void {
        // 注册WordPress Cron事件
        add_action('notion_fallback_content_processing', [__CLASS__, 'handle_fallback_content_processing']);
        add_action('notion_fallback_image_processing', [__CLASS__, 'handle_fallback_image_processing']);
        add_action('notion_fallback_batch_import', [__CLASS__, 'handle_fallback_batch_import']);

        // 确保WordPress Cron正常工作
        if (!wp_next_scheduled('notion_fallback_heartbeat')) {
            wp_schedule_event(time(), 'hourly', 'notion_fallback_heartbeat');
        }
    }

    /**
     * 调度内容处理任务
     * 
     * @param array $blocks Notion块数据
     * @param array $options 处理选项
     * @return string|false 任务ID或false
     */
    public static function schedule_content_processing(array $blocks, array $options = []) {
        if (!self::is_action_scheduler_available()) {
            // 回退到原有异步处理器
            return Notion_Async_Processor::start_async_operation(
                Notion_Async_Processor::OPERATION_IMPORT,
                $blocks,
                $options
            );
        }
        
        $task_data = [
            'blocks' => $blocks,
            'options' => array_merge([
                'priority' => self::PRIORITY_NORMAL,
                'timeout' => self::TASK_TIMEOUT,
                'retries' => 0,
                'max_retries' => self::MAX_RETRIES
            ], $options),
            'created_at' => current_time('mysql'),
            'task_type' => self::TASK_CONTENT_PROCESSING
        ];
        
        // 根据数据量决定优先级和延迟
        $block_count = count($blocks);
        $delay = 0;
        
        if ($block_count > 100) {
            $task_data['options']['priority'] = self::PRIORITY_LOW;
            $delay = 30; // 大任务延迟30秒执行
        } elseif ($block_count > 50) {
            $task_data['options']['priority'] = self::PRIORITY_NORMAL;
            $delay = 10; // 中等任务延迟10秒执行
        } else {
            $task_data['options']['priority'] = self::PRIORITY_HIGH;
            $delay = 2; // 小任务延迟2秒执行
        }
        
        $task_id = as_schedule_single_action(
            time() + $delay,
            'notion_process_content_task',
            [$task_data],
            self::TASK_GROUP
        );
        
        if ($task_id) {
            // 记录任务信息
            self::save_task_info($task_id, $task_data);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('内容处理任务已调度: ID=%d, 块数量=%d, 优先级=%d, 延迟=%ds',
                        $task_id, $block_count, $task_data['options']['priority'], $delay
                    ),
                    'Async Task Scheduler'
                );
            }
        }
        
        return $task_id;
    }
    
    /**
     * 调度图片处理任务
     * 
     * @param array $images 图片数据
     * @param array $options 处理选项
     * @return string|false 任务ID或false
     */
    public static function schedule_image_processing(array $images, array $options = []) {
        if (!self::is_action_scheduler_available()) {
            return Notion_Async_Processor::start_async_operation(
                Notion_Async_Processor::OPERATION_IMAGE_PROCESS,
                $images,
                $options
            );
        }
        
        $task_data = [
            'images' => $images,
            'options' => array_merge([
                'priority' => self::PRIORITY_NORMAL,
                'timeout' => self::TASK_TIMEOUT,
                'retries' => 0,
                'max_retries' => self::MAX_RETRIES
            ], $options),
            'created_at' => current_time('mysql'),
            'task_type' => self::TASK_IMAGE_PROCESSING
        ];
        
        $task_id = as_schedule_single_action(
            time() + 5, // 图片处理延迟5秒
            'notion_process_image_task',
            [$task_data],
            self::TASK_GROUP
        );
        
        if ($task_id) {
            self::save_task_info($task_id, $task_data);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('图片处理任务已调度: ID=%d, 图片数量=%d', $task_id, count($images)),
                    'Async Task Scheduler'
                );
            }
        }
        
        return $task_id;
    }
    
    /**
     * 调度批量导入任务
     * 
     * @param array $pages 页面数据
     * @param array $options 导入选项
     * @return string|false 任务ID或false
     */
    public static function schedule_batch_import(array $pages, array $options = []) {
        if (!self::is_action_scheduler_available()) {
            return Notion_Async_Processor::start_async_operation(
                Notion_Async_Processor::OPERATION_IMPORT,
                $pages,
                $options
            );
        }
        
        $task_data = [
            'pages' => $pages,
            'options' => array_merge([
                'priority' => self::PRIORITY_LOW, // 批量导入优先级较低
                'timeout' => self::TASK_TIMEOUT * 2, // 批量导入超时时间更长
                'retries' => 0,
                'max_retries' => self::MAX_RETRIES
            ], $options),
            'created_at' => current_time('mysql'),
            'task_type' => self::TASK_BATCH_IMPORT
        ];
        
        $task_id = as_schedule_single_action(
            time() + 60, // 批量导入延迟1分钟执行
            'notion_batch_import_task',
            [$task_data],
            self::TASK_GROUP
        );
        
        if ($task_id) {
            self::save_task_info($task_id, $task_data);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('批量导入任务已调度: ID=%d, 页面数量=%d', $task_id, count($pages)),
                    'Async Task Scheduler'
                );
            }
        }
        
        return $task_id;
    }
    
    /**
     * 调度增量同步任务
     * 
     * @param array $sync_data 同步数据
     * @param array $options 同步选项
     * @return string|false 任务ID或false
     */
    public static function schedule_incremental_sync(array $sync_data, array $options = []) {
        if (!self::is_action_scheduler_available()) {
            return Notion_Async_Processor::start_async_operation(
                Notion_Async_Processor::OPERATION_UPDATE,
                $sync_data,
                $options
            );
        }
        
        $task_data = [
            'sync_data' => $sync_data,
            'options' => array_merge([
                'priority' => self::PRIORITY_HIGH, // 增量同步优先级高
                'timeout' => self::TASK_TIMEOUT,
                'retries' => 0,
                'max_retries' => self::MAX_RETRIES
            ], $options),
            'created_at' => current_time('mysql'),
            'task_type' => self::TASK_INCREMENTAL_SYNC
        ];
        
        $task_id = as_schedule_single_action(
            time() + 1, // 增量同步立即执行
            'notion_incremental_sync_task',
            [$task_data],
            self::TASK_GROUP
        );
        
        if ($task_id) {
            self::save_task_info($task_id, $task_data);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('增量同步任务已调度: ID=%d', $task_id),
                    'Async Task Scheduler'
                );
            }
        }
        
        return $task_id;
    }

    /**
     * 调度清理任务
     *
     * @param array $cleanup_data 清理数据
     * @param array $options 清理选项
     * @return string|false 任务ID或false
     */
    public static function schedule_cleanup(array $cleanup_data, array $options = []) {
        if (!self::is_action_scheduler_available()) {
            return Notion_Async_Processor::start_async_operation(
                Notion_Async_Processor::OPERATION_DELETE,
                $cleanup_data,
                $options
            );
        }

        $task_data = [
            'cleanup_data' => $cleanup_data,
            'cleanup_type' => $cleanup_data['cleanup_type'] ?? 'general',
            'options' => array_merge([
                'priority' => self::PRIORITY_LOW, // 清理任务优先级最低
                'timeout' => self::TASK_TIMEOUT,
                'retries' => 0,
                'max_retries' => self::MAX_RETRIES
            ], $options),
            'created_at' => current_time('mysql'),
            'task_type' => self::TASK_CLEANUP
        ];

        $task_id = as_schedule_single_action(
            time() + 120, // 清理任务延迟2分钟执行
            'notion_cleanup_task',
            [$task_data],
            self::TASK_GROUP
        );

        if ($task_id) {
            self::save_task_info($task_id, $task_data);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('清理任务已调度: ID=%d, 类型=%s', $task_id, $task_data['cleanup_type']),
                    'Async Task Scheduler'
                );
            }
        }

        return $task_id;
    }

    /**
     * 保存任务信息
     * 
     * @param int $task_id 任务ID
     * @param array $task_data 任务数据
     */
    private static function save_task_info(int $task_id, array $task_data): void {
        $task_info = [
            'id' => $task_id,
            'type' => $task_data['task_type'],
            'status' => self::STATUS_PENDING,
            'created_at' => $task_data['created_at'],
            'updated_at' => current_time('mysql'),
            'options' => $task_data['options'],
            'progress' => 0,
            'error_message' => null
        ];
        
        // 保存到数据库或缓存
        $tasks = get_option('notion_async_tasks', []);
        $tasks[$task_id] = $task_info;
        update_option('notion_async_tasks', $tasks);
    }
    
    /**
     * 获取任务信息
     * 
     * @param int $task_id 任务ID
     * @return array|null 任务信息
     */
    public static function get_task_info(int $task_id): ?array {
        $tasks = get_option('notion_async_tasks', []);
        return $tasks[$task_id] ?? null;
    }
    
    /**
     * 更新任务状态
     * 
     * @param int $task_id 任务ID
     * @param string $status 新状态
     * @param array $data 额外数据
     */
    public static function update_task_status(int $task_id, string $status, array $data = []): void {
        $tasks = get_option('notion_async_tasks', []);
        
        if (isset($tasks[$task_id])) {
            $tasks[$task_id]['status'] = $status;
            $tasks[$task_id]['updated_at'] = current_time('mysql');
            
            foreach ($data as $key => $value) {
                $tasks[$task_id][$key] = $value;
            }
            
            update_option('notion_async_tasks', $tasks);
        }
    }
    
    /**
     * 获取任务状态
     * 
     * @param int $task_id 任务ID
     * @return string|null 任务状态
     */
    public static function get_task_status(int $task_id): ?string {
        $task_info = self::get_task_info($task_id);
        return $task_info['status'] ?? null;
    }
    
    /**
     * 取消任务
     * 
     * @param int $task_id 任务ID
     * @return bool 是否成功取消
     */
    public static function cancel_task(int $task_id): bool {
        if (!self::is_action_scheduler_available()) {
            return false;
        }
        
        $cancelled = as_unschedule_action('notion_process_content_task', [$task_id], self::TASK_GROUP) ||
                    as_unschedule_action('notion_process_image_task', [$task_id], self::TASK_GROUP) ||
                    as_unschedule_action('notion_batch_import_task', [$task_id], self::TASK_GROUP) ||
                    as_unschedule_action('notion_incremental_sync_task', [$task_id], self::TASK_GROUP);
        
        if ($cancelled) {
            self::update_task_status($task_id, self::STATUS_CANCELLED);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('任务已取消: ID=%d', $task_id),
                    'Async Task Scheduler'
                );
            }
        }
        
        return $cancelled;
    }

    /**
     * 处理内容处理任务
     *
     * @param array $task_data 任务数据
     */
    public static function handle_content_processing(array $task_data): void {
        $task_id = self::extract_task_id_from_data($task_data);

        try {
            self::update_task_status($task_id, self::STATUS_RUNNING, ['started_at' => current_time('mysql')]);

            $blocks = $task_data['blocks'] ?? [];
            $options = $task_data['options'] ?? [];

            if (empty($blocks)) {
                throw new Exception('没有要处理的块数据');
            }

            // 使用流式处理器处理内容
            if (class_exists('Notion_Stream_Processor')) {
                $mock_api = new stdClass(); // 模拟API对象
                $html_result = Notion_Stream_Processor::process_large_dataset($blocks, $mock_api);

                $result = [
                    'html_content' => $html_result,
                    'blocks_processed' => count($blocks),
                    'processing_stats' => Notion_Stream_Processor::get_processing_stats()
                ];
            } else {
                // 回退到基础处理
                $result = self::process_blocks_basic($blocks, $options);
            }

            self::update_task_status($task_id, self::STATUS_COMPLETED, [
                'completed_at' => current_time('mysql'),
                'result' => $result,
                'progress' => 100
            ]);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('内容处理任务完成: ID=%d, 处理块数=%d', $task_id, count($blocks)),
                    'Async Task Scheduler'
                );
            }

        } catch (Exception $e) {
            self::handle_task_error($task_id, $e, $task_data);
        }
    }

    /**
     * 处理图片处理任务
     *
     * @param array $task_data 任务数据
     */
    public static function handle_image_processing(array $task_data): void {
        $task_id = self::extract_task_id_from_data($task_data);

        try {
            self::update_task_status($task_id, self::STATUS_RUNNING, ['started_at' => current_time('mysql')]);

            $images = $task_data['images'] ?? [];
            $options = $task_data['options'] ?? [];

            if (empty($images)) {
                throw new Exception('没有要处理的图片数据');
            }

            $processed_count = 0;
            $failed_count = 0;
            $results = [];

            foreach ($images as $index => $image) {
                try {
                    // 使用并行图片处理器
                    if (class_exists('Notion_Parallel_Image_Processor')) {
                        $result = Notion_Parallel_Image_Processor::process_single_image($image, $options);
                        $results[] = $result;
                        $processed_count++;
                    } else {
                        // 基础图片处理
                        $result = self::process_image_basic($image, $options);
                        $results[] = $result;
                        $processed_count++;
                    }

                    // 更新进度
                    $progress = round(($index + 1) / count($images) * 100);
                    self::update_task_status($task_id, self::STATUS_RUNNING, ['progress' => $progress]);

                } catch (Exception $e) {
                    $failed_count++;
                    $results[] = ['error' => $e->getMessage(), 'image' => $image];

                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::error_log(
                            sprintf('图片处理失败: %s', $e->getMessage()),
                            'Async Task Scheduler'
                        );
                    }
                }
            }

            self::update_task_status($task_id, self::STATUS_COMPLETED, [
                'completed_at' => current_time('mysql'),
                'result' => [
                    'processed_count' => $processed_count,
                    'failed_count' => $failed_count,
                    'results' => $results
                ],
                'progress' => 100
            ]);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('图片处理任务完成: ID=%d, 成功=%d, 失败=%d', $task_id, $processed_count, $failed_count),
                    'Async Task Scheduler'
                );
            }

        } catch (Exception $e) {
            self::handle_task_error($task_id, $e, $task_data);
        }
    }

    /**
     * 处理批量导入任务
     *
     * @param array $task_data 任务数据
     */
    public static function handle_batch_import(array $task_data): void {
        $task_id = self::extract_task_id_from_data($task_data);

        try {
            self::update_task_status($task_id, self::STATUS_RUNNING, ['started_at' => current_time('mysql')]);

            $pages = $task_data['pages'] ?? [];
            $options = $task_data['options'] ?? [];

            if (empty($pages)) {
                throw new Exception('没有要导入的页面数据');
            }

            $imported_count = 0;
            $failed_count = 0;
            $results = [];

            foreach ($pages as $index => $page) {
                try {
                    // 使用现有的导入逻辑
                    if (class_exists('Notion_To_WordPress_Importer')) {
                        $result = Notion_To_WordPress_Importer::import_single_page($page, $options);
                        $results[] = $result;
                        $imported_count++;
                    } else {
                        // 基础导入处理
                        $result = self::import_page_basic($page, $options);
                        $results[] = $result;
                        $imported_count++;
                    }

                    // 更新进度
                    $progress = round(($index + 1) / count($pages) * 100);
                    self::update_task_status($task_id, self::STATUS_RUNNING, ['progress' => $progress]);

                    // 避免内存溢出，每处理10个页面清理一次
                    if (($index + 1) % 10 === 0) {
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    }

                } catch (Exception $e) {
                    $failed_count++;
                    $results[] = ['error' => $e->getMessage(), 'page' => $page];

                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::error_log(
                            sprintf('页面导入失败: %s', $e->getMessage()),
                            'Async Task Scheduler'
                        );
                    }
                }
            }

            self::update_task_status($task_id, self::STATUS_COMPLETED, [
                'completed_at' => current_time('mysql'),
                'result' => [
                    'imported_count' => $imported_count,
                    'failed_count' => $failed_count,
                    'results' => $results
                ],
                'progress' => 100
            ]);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('批量导入任务完成: ID=%d, 成功=%d, 失败=%d', $task_id, $imported_count, $failed_count),
                    'Async Task Scheduler'
                );
            }

        } catch (Exception $e) {
            self::handle_task_error($task_id, $e, $task_data);
        }
    }

    /**
     * 处理增量同步任务
     *
     * @param array $task_data 任务数据
     */
    public static function handle_incremental_sync(array $task_data): void {
        $task_id = self::extract_task_id_from_data($task_data);

        try {
            self::update_task_status($task_id, self::STATUS_RUNNING, ['started_at' => current_time('mysql')]);

            $sync_data = $task_data['sync_data'] ?? [];
            $options = $task_data['options'] ?? [];

            if (empty($sync_data)) {
                throw new Exception('没有要同步的数据');
            }

            // 使用增量检测器
            if (class_exists('Notion_Incremental_Detector')) {
                $result = Notion_Incremental_Detector::process_incremental_sync($sync_data, $options);
            } else {
                // 基础同步处理
                $result = self::process_sync_basic($sync_data, $options);
            }

            self::update_task_status($task_id, self::STATUS_COMPLETED, [
                'completed_at' => current_time('mysql'),
                'result' => $result,
                'progress' => 100
            ]);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('增量同步任务完成: ID=%d', $task_id),
                    'Async Task Scheduler'
                );
            }

        } catch (Exception $e) {
            self::handle_task_error($task_id, $e, $task_data);
        }
    }

    /**
     * 处理清理任务
     *
     * @param array $task_data 任务数据
     */
    public static function handle_cleanup(array $task_data): void {
        $task_id = self::extract_task_id_from_data($task_data);

        try {
            self::update_task_status($task_id, self::STATUS_RUNNING, ['started_at' => current_time('mysql')]);

            $cleanup_type = $task_data['cleanup_type'] ?? 'general';
            $options = $task_data['options'] ?? [];

            $result = [];

            switch ($cleanup_type) {
                case 'logs':
                    $result = self::cleanup_logs($options);
                    break;
                case 'cache':
                    $result = self::cleanup_cache($options);
                    break;
                case 'temp_files':
                    $result = self::cleanup_temp_files($options);
                    break;
                default:
                    $result = self::cleanup_general($options);
                    break;
            }

            self::update_task_status($task_id, self::STATUS_COMPLETED, [
                'completed_at' => current_time('mysql'),
                'result' => $result,
                'progress' => 100
            ]);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('清理任务完成: ID=%d, 类型=%s', $task_id, $cleanup_type),
                    'Async Task Scheduler'
                );
            }

        } catch (Exception $e) {
            self::handle_task_error($task_id, $e, $task_data);
        }
    }

    /**
     * 从任务数据中提取任务ID
     *
     * @param array $task_data 任务数据
     * @return int 任务ID
     */
    private static function extract_task_id_from_data(array $task_data): int {
        // Action Scheduler会自动传递action_id
        global $wp_current_filter;
        $action_id = 0;

        if (function_exists('as_get_scheduled_actions')) {
            $actions = as_get_scheduled_actions([
                'hook' => current_filter(),
                'status' => 'in-progress',
                'per_page' => 1
            ]);

            if (!empty($actions)) {
                $action_id = $actions[0]->get_id();
            }
        }

        return $action_id ?: time(); // 回退到时间戳
    }

    /**
     * 处理任务错误
     *
     * @param int $task_id 任务ID
     * @param Exception $e 异常对象
     * @param array $task_data 任务数据
     */
    private static function handle_task_error(int $task_id, Exception $e, array $task_data): void {
        $options = $task_data['options'] ?? [];
        $retries = $options['retries'] ?? 0;
        $max_retries = $options['max_retries'] ?? self::MAX_RETRIES;

        if ($retries < $max_retries) {
            // 重试任务
            $options['retries'] = $retries + 1;
            $task_data['options'] = $options;

            // 计算重试延迟（指数退避）
            $delay = min(300, pow(2, $retries) * 30); // 最大5分钟延迟

            // 重新调度任务
            $new_task_id = as_schedule_single_action(
                time() + $delay,
                current_filter(),
                [$task_data],
                self::TASK_GROUP
            );

            self::update_task_status($task_id, self::STATUS_FAILED, [
                'error_message' => $e->getMessage(),
                'retry_count' => $retries + 1,
                'retry_task_id' => $new_task_id,
                'retry_delay' => $delay
            ]);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    sprintf('任务失败，将在%d秒后重试: ID=%d, 错误=%s, 重试次数=%d/%d',
                        $delay, $task_id, $e->getMessage(), $retries + 1, $max_retries
                    ),
                    'Async Task Scheduler'
                );
            }
        } else {
            // 达到最大重试次数，标记为失败
            self::update_task_status($task_id, self::STATUS_FAILED, [
                'error_message' => $e->getMessage(),
                'failed_at' => current_time('mysql'),
                'final_failure' => true
            ]);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('任务最终失败: ID=%d, 错误=%s, 重试次数=%d/%d',
                        $task_id, $e->getMessage(), $retries, $max_retries
                    ),
                    'Async Task Scheduler'
                );
            }
        }
    }

    /**
     * 基础块处理
     *
     * @param array $blocks 块数据
     * @param array $options 选项
     * @return array 处理结果
     */
    private static function process_blocks_basic(array $blocks, array $options): array {
        $html_parts = [];

        foreach ($blocks as $block) {
            $block_type = $block['type'] ?? 'unknown';

            switch ($block_type) {
                case 'paragraph':
                    $text = $block['paragraph']['rich_text'][0]['plain_text'] ?? '';
                    $html_parts[] = '<p>' . esc_html($text) . '</p>';
                    break;
                case 'heading_1':
                    $text = $block['heading_1']['rich_text'][0]['plain_text'] ?? '';
                    $html_parts[] = '<h1>' . esc_html($text) . '</h1>';
                    break;
                case 'heading_2':
                    $text = $block['heading_2']['rich_text'][0]['plain_text'] ?? '';
                    $html_parts[] = '<h2>' . esc_html($text) . '</h2>';
                    break;
                default:
                    $html_parts[] = '<!-- Unsupported block type: ' . esc_html($block_type) . ' -->';
                    break;
            }
        }

        return [
            'html_content' => implode("\n", $html_parts),
            'blocks_processed' => count($blocks)
        ];
    }

    /**
     * 基础图片处理
     *
     * @param array $image 图片数据
     * @param array $options 选项
     * @return array 处理结果
     */
    private static function process_image_basic(array $image, array $options): array {
        // 基础图片处理逻辑
        $url = $image['url'] ?? '';
        $alt = $image['alt'] ?? '';

        if (empty($url)) {
            throw new Exception('图片URL为空');
        }

        // 简单的图片验证
        $response = wp_remote_head($url);
        if (is_wp_error($response)) {
            throw new Exception('无法访问图片: ' . $response->get_error_message());
        }

        return [
            'url' => $url,
            'alt' => $alt,
            'status' => 'processed'
        ];
    }

    /**
     * 基础页面导入
     *
     * @param array $page 页面数据
     * @param array $options 选项
     * @return array 导入结果
     */
    private static function import_page_basic(array $page, array $options): array {
        $title = $page['title'] ?? 'Untitled';
        $content = $page['content'] ?? '';

        // 创建WordPress文章
        $post_data = [
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'draft',
            'post_type' => 'post'
        ];

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            throw new Exception('创建文章失败: ' . $post_id->get_error_message());
        }

        return [
            'post_id' => $post_id,
            'title' => $title,
            'status' => 'imported'
        ];
    }

    /**
     * 基础同步处理
     *
     * @param array $sync_data 同步数据
     * @param array $options 选项
     * @return array 同步结果
     */
    private static function process_sync_basic(array $sync_data, array $options): array {
        $updated_count = 0;
        $created_count = 0;
        $deleted_count = 0;

        foreach ($sync_data as $item) {
            $action = $item['action'] ?? 'update';

            switch ($action) {
                case 'create':
                    $created_count++;
                    break;
                case 'update':
                    $updated_count++;
                    break;
                case 'delete':
                    $deleted_count++;
                    break;
            }
        }

        return [
            'created' => $created_count,
            'updated' => $updated_count,
            'deleted' => $deleted_count,
            'total' => count($sync_data)
        ];
    }

    /**
     * 清理日志
     *
     * @param array $options 选项
     * @return array 清理结果
     */
    private static function cleanup_logs(array $options): array {
        $days = $options['days'] ?? 30;
        $deleted_count = 0;

        // 清理旧日志文件
        if (class_exists('Notion_Logger')) {
            $deleted_count = Notion_Logger::cleanup_old_logs($days);
        }

        return [
            'type' => 'logs',
            'deleted_count' => $deleted_count,
            'days' => $days
        ];
    }

    /**
     * 清理缓存
     *
     * @param array $options 选项
     * @return array 清理结果
     */
    private static function cleanup_cache(array $options): array {
        $cleared_count = 0;

        // 清理WordPress缓存
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $cleared_count++;
        }

        // 清理对象缓存
        if (function_exists('wp_cache_delete_group')) {
            wp_cache_delete_group('notion_cache');
            $cleared_count++;
        }

        return [
            'type' => 'cache',
            'cleared_count' => $cleared_count
        ];
    }

    /**
     * 清理临时文件
     *
     * @param array $options 选项
     * @return array 清理结果
     */
    private static function cleanup_temp_files(array $options): array {
        $deleted_count = 0;
        $temp_dir = wp_upload_dir()['basedir'] . '/notion-temp/';

        if (is_dir($temp_dir)) {
            $files = glob($temp_dir . '*');
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < (time() - 3600)) { // 1小时前的文件
                    if (unlink($file)) {
                        $deleted_count++;
                    }
                }
            }
        }

        return [
            'type' => 'temp_files',
            'deleted_count' => $deleted_count,
            'temp_dir' => $temp_dir
        ];
    }

    /**
     * 通用清理
     *
     * @param array $options 选项
     * @return array 清理结果
     */
    private static function cleanup_general(array $options): array {
        $results = [];

        // 清理日志
        $results['logs'] = self::cleanup_logs($options);

        // 清理缓存
        $results['cache'] = self::cleanup_cache($options);

        // 清理临时文件
        $results['temp_files'] = self::cleanup_temp_files($options);

        return [
            'type' => 'general',
            'results' => $results
        ];
    }

    /**
     * 任务完成回调
     *
     * @param int $action_id Action ID
     */
    public static function on_task_completed(int $action_id): void {
        self::update_task_status($action_id, self::STATUS_COMPLETED, [
            'completed_at' => current_time('mysql')
        ]);

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf('任务完成回调: ID=%d', $action_id),
                'Async Task Scheduler'
            );
        }
    }

    /**
     * 任务失败回调
     *
     * @param int $action_id Action ID
     */
    public static function on_task_failed(int $action_id): void {
        self::update_task_status($action_id, self::STATUS_FAILED, [
            'failed_at' => current_time('mysql')
        ]);

        if (class_exists('Notion_Logger')) {
            Notion_Logger::error_log(
                sprintf('任务失败回调: ID=%d', $action_id),
                'Async Task Scheduler'
            );
        }
    }

    /**
     * 任务取消回调
     *
     * @param int $action_id Action ID
     */
    public static function on_task_cancelled(int $action_id): void {
        self::update_task_status($action_id, self::STATUS_CANCELLED, [
            'cancelled_at' => current_time('mysql')
        ]);

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf('任务取消回调: ID=%d', $action_id),
                'Async Task Scheduler'
            );
        }
    }

    /**
     * 清理已完成的任务
     */
    public static function cleanup_completed_tasks(): void {
        $tasks = get_option('notion_async_tasks', []);
        $cleaned_count = 0;
        $cutoff_time = strtotime('-7 days'); // 清理7天前的任务

        foreach ($tasks as $task_id => $task_info) {
            $task_time = strtotime($task_info['updated_at'] ?? '');

            if ($task_time < $cutoff_time &&
                in_array($task_info['status'], [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED])) {
                unset($tasks[$task_id]);
                $cleaned_count++;
            }
        }

        if ($cleaned_count > 0) {
            update_option('notion_async_tasks', $tasks);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('清理了%d个已完成的任务', $cleaned_count),
                    'Async Task Scheduler'
                );
            }
        }
    }

    /**
     * 获取所有任务状态
     *
     * @param array $filters 过滤条件
     * @return array 任务列表
     */
    public static function get_all_tasks(array $filters = []): array {
        $tasks = get_option('notion_async_tasks', []);

        if (!empty($filters)) {
            $filtered_tasks = [];

            foreach ($tasks as $task_id => $task_info) {
                $include = true;

                // 状态过滤
                if (isset($filters['status']) && $task_info['status'] !== $filters['status']) {
                    $include = false;
                }

                // 类型过滤
                if (isset($filters['type']) && $task_info['type'] !== $filters['type']) {
                    $include = false;
                }

                // 时间范围过滤
                if (isset($filters['since'])) {
                    $task_time = strtotime($task_info['created_at'] ?? '');
                    if ($task_time < strtotime($filters['since'])) {
                        $include = false;
                    }
                }

                if ($include) {
                    $filtered_tasks[$task_id] = $task_info;
                }
            }

            return $filtered_tasks;
        }

        return $tasks;
    }

    /**
     * 获取任务统计信息
     *
     * @return array 统计信息
     */
    public static function get_task_statistics(): array {
        $tasks = get_option('notion_async_tasks', []);

        $stats = [
            'total' => count($tasks),
            'pending' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'by_type' => []
        ];

        foreach ($tasks as $task_info) {
            $status = $task_info['status'] ?? 'unknown';
            $type = $task_info['type'] ?? 'unknown';

            // 状态统计
            if (isset($stats[$status])) {
                $stats[$status]++;
            }

            // 类型统计
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = 0;
            }
            $stats['by_type'][$type]++;
        }

        return $stats;
    }

    /**
     * 获取系统状态
     *
     * @return array 系统状态
     */
    public static function get_system_status(): array {
        $status = [
            'action_scheduler_available' => self::is_action_scheduler_available(),
            'pending_actions' => 0,
            'running_actions' => 0,
            'failed_actions' => 0,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => wp_convert_hr_to_bytes(ini_get('memory_limit'))
        ];

        if (self::is_action_scheduler_available()) {
            // 获取Action Scheduler统计
            $pending = as_get_scheduled_actions([
                'group' => self::TASK_GROUP,
                'status' => 'pending',
                'per_page' => -1
            ]);
            $status['pending_actions'] = count($pending);

            $running = as_get_scheduled_actions([
                'group' => self::TASK_GROUP,
                'status' => 'in-progress',
                'per_page' => -1
            ]);
            $status['running_actions'] = count($running);

            $failed = as_get_scheduled_actions([
                'group' => self::TASK_GROUP,
                'status' => 'failed',
                'per_page' => -1
            ]);
            $status['failed_actions'] = count($failed);
        }

        return $status;
    }

    /**
     * 强制停止所有任务
     *
     * @return int 停止的任务数量
     */
    public static function stop_all_tasks(): int {
        if (!self::is_action_scheduler_available()) {
            return 0;
        }

        $stopped_count = 0;

        // 取消所有待执行的任务
        $pending_actions = as_get_scheduled_actions([
            'group' => self::TASK_GROUP,
            'status' => 'pending',
            'per_page' => -1
        ]);

        foreach ($pending_actions as $action) {
            if (as_unschedule_action($action->get_hook(), $action->get_args(), $action->get_group())) {
                $stopped_count++;
                self::update_task_status($action->get_id(), self::STATUS_CANCELLED);
            }
        }

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf('强制停止了%d个任务', $stopped_count),
                'Async Task Scheduler'
            );
        }

        return $stopped_count;
    }

    /**
     * 重启失败的任务
     *
     * @param int $max_retries 最大重试次数
     * @return int 重启的任务数量
     */
    public static function restart_failed_tasks(int $max_retries = 1): int {
        $tasks = get_option('notion_async_tasks', []);
        $restarted_count = 0;

        foreach ($tasks as $task_id => $task_info) {
            if ($task_info['status'] === self::STATUS_FAILED &&
                ($task_info['retry_count'] ?? 0) < $max_retries) {

                // 重新调度任务
                $task_type = $task_info['type'];
                $hook = 'notion_process_content_task'; // 默认钩子

                switch ($task_type) {
                    case self::TASK_IMAGE_PROCESSING:
                        $hook = 'notion_process_image_task';
                        break;
                    case self::TASK_BATCH_IMPORT:
                        $hook = 'notion_batch_import_task';
                        break;
                    case self::TASK_INCREMENTAL_SYNC:
                        $hook = 'notion_incremental_sync_task';
                        break;
                    case self::TASK_CLEANUP:
                        $hook = 'notion_cleanup_task';
                        break;
                }

                $new_task_id = as_schedule_single_action(
                    time() + 60, // 1分钟后重试
                    $hook,
                    [$task_info],
                    self::TASK_GROUP
                );

                if ($new_task_id) {
                    self::update_task_status($task_id, self::STATUS_PENDING, [
                        'restarted_at' => current_time('mysql'),
                        'restart_task_id' => $new_task_id
                    ]);
                    $restarted_count++;
                }
            }
        }

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf('重启了%d个失败的任务', $restarted_count),
                'Async Task Scheduler'
            );
        }

        return $restarted_count;
    }
}
