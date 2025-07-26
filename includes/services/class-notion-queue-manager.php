<?php
/**
 * Notion队列管理器
 * 
 * 基于现有WP-Cron系统的后台队列系统，处理大批量操作
 * 增强错误恢复和重试机制，改进cron调度的智能化程度
 *
 * @since      2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/includes/services
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 队列管理器类
 * 
 * 提供基于WP-Cron的队列系统，支持大批量操作的异步处理
 *
 * @since 2.0.0-beta.1
 */
class Notion_Queue_Manager {

    /**
     * 队列选项键名
     */
    const QUEUE_OPTION_KEY = 'notion_queue_data';
    
    /**
     * 队列状态常量
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_RETRYING = 'retrying';
    
    /**
     * 默认配置
     */
    const DEFAULT_BATCH_SIZE = 10;
    const DEFAULT_MAX_RETRIES = 3;
    const DEFAULT_RETRY_DELAY = 300; // 5分钟
    const DEFAULT_TIMEOUT = 300; // 5分钟
    
    /**
     * 队列钩子名称
     */
    const QUEUE_HOOK = 'notion_queue_process';
    const CLEANUP_HOOK = 'notion_queue_cleanup';

    /**
     * 将批量操作加入队列
     *
     * @since 2.0.0-beta.1
     * @param string $operation 操作类型
     * @param array $data 操作数据
     * @param array $options 队列选项
     * @return string 队列任务ID
     */
    public static function enqueue_batch_operation(string $operation, array $data, array $options = []): string {
        $task_id = self::generate_task_id();
        
        // 合并默认选项
        $default_options = [
            'batch_size' => self::DEFAULT_BATCH_SIZE,
            'max_retries' => self::DEFAULT_MAX_RETRIES,
            'retry_delay' => self::DEFAULT_RETRY_DELAY,
            'timeout' => self::DEFAULT_TIMEOUT,
            'priority' => 10
        ];
        $options = array_merge($default_options, $options);
        
        // 分解大批量操作为小批次
        $batches = self::split_into_batches($data, $options['batch_size']);
        
        $task = [
            'id' => $task_id,
            'operation' => $operation,
            'status' => self::STATUS_PENDING,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'options' => $options,
            'batches' => $batches,
            'current_batch' => 0,
            'total_batches' => count($batches),
            'retry_count' => 0,
            'progress' => 0,
            'results' => [],
            'errors' => []
        ];
        
        // 保存到队列
        self::save_task($task);
        
        // 调度处理
        self::schedule_queue_processing();
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf(
                    '队列任务已创建: %s, 操作: %s, 批次数: %d',
                    $task_id,
                    $operation,
                    count($batches)
                ),
                'Queue Manager'
            );
        }
        
        return $task_id;
    }

    /**
     * 处理队列中的任务
     *
     * @since 2.0.0-beta.1
     * @return array 处理结果
     */
    public static function process_queue(): array {
        $start_time = microtime(true);
        $processed_tasks = 0;
        $completed_tasks = 0;
        $failed_tasks = 0;
        
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::start_timer('queue_processing');
        }
        
        // 获取待处理的任务
        $tasks = self::get_pending_tasks();
        
        if (empty($tasks)) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log('队列中没有待处理的任务', 'Queue Manager');
            }
            return [
                'processed' => 0,
                'completed' => 0,
                'failed' => 0,
                'processing_time' => 0
            ];
        }
        
        foreach ($tasks as $task) {
            try {
                $result = self::process_task($task);
                $processed_tasks++;
                
                if ($result['status'] === self::STATUS_COMPLETED) {
                    $completed_tasks++;
                } elseif ($result['status'] === self::STATUS_FAILED) {
                    $failed_tasks++;
                }
                
                // 避免长时间运行
                if (microtime(true) - $start_time > 30) {
                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::info_log('队列处理超时，停止当前批次', 'Queue Manager');
                    }
                    break;
                }
                
            } catch (Exception $e) {
                $failed_tasks++;
                
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::error_log(
                        sprintf('队列任务处理异常: %s, 错误: %s', $task['id'], $e->getMessage()),
                        'Queue Manager'
                    );
                }
            }
        }
        
        $processing_time = microtime(true) - $start_time;
        
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::end_timer('queue_processing');
            Notion_Performance_Monitor::record_custom_metric('queue_processed_tasks', $processed_tasks);
            Notion_Performance_Monitor::record_custom_metric('queue_completed_tasks', $completed_tasks);
            Notion_Performance_Monitor::record_custom_metric('queue_failed_tasks', $failed_tasks);
        }
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf(
                    '队列处理完成: 处理%d个任务, 完成%d个, 失败%d个, 耗时%.3f秒',
                    $processed_tasks,
                    $completed_tasks,
                    $failed_tasks,
                    $processing_time
                ),
                'Queue Manager'
            );
        }
        
        // 如果还有待处理任务，调度下一次处理
        if (self::has_pending_tasks()) {
            self::schedule_queue_processing();
        }
        
        return [
            'processed' => $processed_tasks,
            'completed' => $completed_tasks,
            'failed' => $failed_tasks,
            'processing_time' => $processing_time
        ];
    }

    /**
     * 处理单个任务
     *
     * @since 2.0.0-beta.1
     * @param array $task 任务数据
     * @return array 处理结果
     */
    private static function process_task(array $task): array {
        $task['status'] = self::STATUS_PROCESSING;
        $task['updated_at'] = current_time('mysql');
        self::save_task($task);
        
        try {
            // 获取当前批次数据
            $current_batch_index = $task['current_batch'];
            $batch_data = $task['batches'][$current_batch_index] ?? [];
            
            if (empty($batch_data)) {
                // 所有批次已完成
                $task['status'] = self::STATUS_COMPLETED;
                $task['progress'] = 100;
                $task['updated_at'] = current_time('mysql');
                self::save_task($task);
                
                return ['status' => self::STATUS_COMPLETED, 'task' => $task];
            }
            
            // 执行批次操作
            $batch_result = self::execute_batch_operation($task['operation'], $batch_data, $task['options']);
            
            // 更新任务状态
            $task['results'][] = $batch_result;
            $task['current_batch']++;
            $task['progress'] = round(($task['current_batch'] / $task['total_batches']) * 100, 2);
            
            if ($batch_result['success']) {
                $task['retry_count'] = 0; // 重置重试计数
                
                if ($task['current_batch'] >= $task['total_batches']) {
                    // 所有批次完成
                    $task['status'] = self::STATUS_COMPLETED;
                } else {
                    // 继续下一批次
                    $task['status'] = self::STATUS_PENDING;
                }
            } else {
                // 批次失败，检查是否需要重试
                $task['errors'][] = $batch_result['error'] ?? '未知错误';
                $task['retry_count']++;
                
                if ($task['retry_count'] >= $task['options']['max_retries']) {
                    $task['status'] = self::STATUS_FAILED;
                } else {
                    $task['status'] = self::STATUS_RETRYING;
                    // 调度重试
                    self::schedule_retry($task);
                }
            }
            
            $task['updated_at'] = current_time('mysql');
            self::save_task($task);
            
            return ['status' => $task['status'], 'task' => $task];
            
        } catch (Exception $e) {
            $task['status'] = self::STATUS_FAILED;
            $task['errors'][] = $e->getMessage();
            $task['updated_at'] = current_time('mysql');
            self::save_task($task);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('任务执行异常: %s, 错误: %s', $task['id'], $e->getMessage()),
                    'Queue Manager'
                );
            }
            
            return ['status' => self::STATUS_FAILED, 'task' => $task];
        }
    }

    /**
     * 执行批次操作
     *
     * @since 2.0.0-beta.1
     * @param string $operation 操作类型
     * @param array $batch_data 批次数据
     * @param array $options 选项
     * @return array 执行结果
     */
    private static function execute_batch_operation(string $operation, array $batch_data, array $options): array {
        $start_time = microtime(true);
        
        try {
            switch ($operation) {
                case 'bulk_import':
                    return self::execute_bulk_import($batch_data, $options);
                    
                case 'bulk_update':
                    return self::execute_bulk_update($batch_data, $options);
                    
                case 'bulk_delete':
                    return self::execute_bulk_delete($batch_data, $options);
                    
                case 'bulk_image_process':
                    return self::execute_bulk_image_process($batch_data, $options);
                    
                default:
                    // 允许自定义操作
                    $result = apply_filters('notion_queue_execute_operation', null, $operation, $batch_data, $options);
                    
                    if ($result === null) {
                        throw new Exception("未知的操作类型: {$operation}");
                    }
                    
                    return $result;
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processing_time' => microtime(true) - $start_time
            ];
        }
    }

    /**
     * 执行批量导入操作
     *
     * @since 2.0.0-beta.1
     * @param array $batch_data 批次数据
     * @param array $options 选项
     * @return array 执行结果
     */
    private static function execute_bulk_import(array $batch_data, array $options): array {
        $start_time = microtime(true);
        $success_count = 0;
        $error_count = 0;
        $errors = [];

        foreach ($batch_data as $page_data) {
            try {
                if (class_exists('Notion_Import_Coordinator')) {
                    $result = Notion_Import_Coordinator::import_single_page($page_data);
                    if ($result) {
                        $success_count++;
                    } else {
                        $error_count++;
                        $errors[] = "页面导入失败: {$page_data['id']}";
                    }
                } else {
                    $error_count++;
                    $errors[] = "导入协调器不可用";
                }
            } catch (Exception $e) {
                $error_count++;
                $errors[] = "页面导入异常: {$e->getMessage()}";
            }
        }

        return [
            'success' => $error_count === 0,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors,
            'processing_time' => microtime(true) - $start_time
        ];
    }

    /**
     * 执行批量更新操作
     *
     * @since 2.0.0-beta.1
     * @param array $batch_data 批次数据
     * @param array $options 选项
     * @return array 执行结果
     */
    private static function execute_bulk_update(array $batch_data, array $options): array {
        $start_time = microtime(true);
        $success_count = 0;
        $error_count = 0;
        $errors = [];

        foreach ($batch_data as $update_data) {
            try {
                $post_id = $update_data['post_id'] ?? 0;
                $page_data = $update_data['page_data'] ?? [];

                if ($post_id && !empty($page_data)) {
                    if (class_exists('Notion_Import_Coordinator')) {
                        $result = Notion_Import_Coordinator::update_existing_post($post_id, $page_data);
                        if ($result) {
                            $success_count++;
                        } else {
                            $error_count++;
                            $errors[] = "文章更新失败: {$post_id}";
                        }
                    } else {
                        $error_count++;
                        $errors[] = "导入协调器不可用";
                    }
                } else {
                    $error_count++;
                    $errors[] = "无效的更新数据";
                }
            } catch (Exception $e) {
                $error_count++;
                $errors[] = "文章更新异常: {$e->getMessage()}";
            }
        }

        return [
            'success' => $error_count === 0,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors,
            'processing_time' => microtime(true) - $start_time
        ];
    }

    /**
     * 执行批量删除操作
     *
     * @since 2.0.0-beta.1
     * @param array $batch_data 批次数据
     * @param array $options 选项
     * @return array 执行结果
     */
    private static function execute_bulk_delete(array $batch_data, array $options): array {
        $start_time = microtime(true);
        $success_count = 0;
        $error_count = 0;
        $errors = [];

        foreach ($batch_data as $delete_data) {
            try {
                $post_id = $delete_data['post_id'] ?? 0;
                $force_delete = $delete_data['force_delete'] ?? false;

                if ($post_id) {
                    $result = wp_delete_post($post_id, $force_delete);
                    if ($result) {
                        $success_count++;
                    } else {
                        $error_count++;
                        $errors[] = "文章删除失败: {$post_id}";
                    }
                } else {
                    $error_count++;
                    $errors[] = "无效的删除数据";
                }
            } catch (Exception $e) {
                $error_count++;
                $errors[] = "文章删除异常: {$e->getMessage()}";
            }
        }

        return [
            'success' => $error_count === 0,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors,
            'processing_time' => microtime(true) - $start_time
        ];
    }

    /**
     * 执行批量图片处理操作
     *
     * @since 2.0.0-beta.1
     * @param array $batch_data 批次数据
     * @param array $options 选项
     * @return array 执行结果
     */
    private static function execute_bulk_image_process(array $batch_data, array $options): array {
        $start_time = microtime(true);
        $success_count = 0;
        $error_count = 0;
        $errors = [];

        if (class_exists('Notion_Parallel_Image_Processor')) {
            try {
                $image_urls = array_column($batch_data, 'url');
                $results = Notion_Parallel_Image_Processor::process_images_parallel($image_urls);

                foreach ($results as $url => $attachment_id) {
                    if ($attachment_id) {
                        $success_count++;
                    } else {
                        $error_count++;
                        $errors[] = "图片处理失败: {$url}";
                    }
                }
            } catch (Exception $e) {
                $error_count = count($batch_data);
                $errors[] = "批量图片处理异常: {$e->getMessage()}";
            }
        } else {
            $error_count = count($batch_data);
            $errors[] = "图片处理器不可用";
        }

        return [
            'success' => $error_count === 0,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors,
            'processing_time' => microtime(true) - $start_time
        ];
    }

    // ==================== 队列管理辅助方法 ====================

    /**
     * 生成任务ID
     *
     * @since 2.0.0-beta.1
     * @return string 任务ID
     */
    private static function generate_task_id(): string {
        return 'task_' . uniqid() . '_' . time();
    }

    /**
     * 将数据分解为批次
     *
     * @since 2.0.0-beta.1
     * @param array $data 原始数据
     * @param int $batch_size 批次大小
     * @return array 分批后的数据
     */
    private static function split_into_batches(array $data, int $batch_size): array {
        return array_chunk($data, $batch_size);
    }

    /**
     * 保存任务到队列
     *
     * @since 2.0.0-beta.1
     * @param array $task 任务数据
     * @return bool 保存结果
     */
    private static function save_task(array $task): bool {
        $queue_data = get_option(self::QUEUE_OPTION_KEY, []);
        $queue_data[$task['id']] = $task;
        return update_option(self::QUEUE_OPTION_KEY, $queue_data);
    }

    /**
     * 获取待处理的任务
     *
     * @since 2.0.0-beta.1
     * @param int $limit 限制数量
     * @return array 待处理任务列表
     */
    private static function get_pending_tasks(int $limit = 5): array {
        $queue_data = get_option(self::QUEUE_OPTION_KEY, []);
        $pending_tasks = [];

        foreach ($queue_data as $task) {
            if (in_array($task['status'], [self::STATUS_PENDING, self::STATUS_RETRYING])) {
                $pending_tasks[] = $task;

                if (count($pending_tasks) >= $limit) {
                    break;
                }
            }
        }

        // 按优先级和创建时间排序
        usort($pending_tasks, function($a, $b) {
            $priority_diff = ($a['options']['priority'] ?? 10) - ($b['options']['priority'] ?? 10);
            if ($priority_diff !== 0) {
                return $priority_diff;
            }
            return strtotime($a['created_at']) - strtotime($b['created_at']);
        });

        return $pending_tasks;
    }

    /**
     * 检查是否有待处理任务
     *
     * @since 2.0.0-beta.1
     * @return bool 是否有待处理任务
     */
    private static function has_pending_tasks(): bool {
        $queue_data = get_option(self::QUEUE_OPTION_KEY, []);

        foreach ($queue_data as $task) {
            if (in_array($task['status'], [self::STATUS_PENDING, self::STATUS_RETRYING])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 调度队列处理
     *
     * @since 2.0.0-beta.1
     * @param int $delay 延迟时间（秒）
     * @return bool 调度结果
     */
    private static function schedule_queue_processing(int $delay = 0): bool {
        $timestamp = time() + $delay;

        // 避免重复调度
        if (!wp_next_scheduled(self::QUEUE_HOOK)) {
            return wp_schedule_single_event($timestamp, self::QUEUE_HOOK);
        }

        return true;
    }

    /**
     * 调度重试
     *
     * @since 2.0.0-beta.1
     * @param array $task 任务数据
     * @return bool 调度结果
     */
    private static function schedule_retry(array $task): bool {
        $delay = $task['options']['retry_delay'] * $task['retry_count']; // 指数退避
        return self::schedule_queue_processing($delay);
    }

    /**
     * 获取队列状态
     *
     * @since 2.0.0-beta.1
     * @return array 队列状态信息
     */
    public static function get_queue_status(): array {
        $queue_data = get_option(self::QUEUE_OPTION_KEY, []);

        $status = [
            'total_tasks' => count($queue_data),
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'retrying' => 0
        ];

        foreach ($queue_data as $task) {
            $status[$task['status']]++;
        }

        return $status;
    }

    /**
     * 获取任务详情
     *
     * @since 2.0.0-beta.1
     * @param string $task_id 任务ID
     * @return array|null 任务详情
     */
    public static function get_task(string $task_id): ?array {
        $queue_data = get_option(self::QUEUE_OPTION_KEY, []);
        return $queue_data[$task_id] ?? null;
    }

    /**
     * 取消任务
     *
     * @since 2.0.0-beta.1
     * @param string $task_id 任务ID
     * @return bool 取消结果
     */
    public static function cancel_task(string $task_id): bool {
        $queue_data = get_option(self::QUEUE_OPTION_KEY, []);

        if (isset($queue_data[$task_id])) {
            $task = $queue_data[$task_id];

            // 只能取消待处理或重试中的任务
            if (in_array($task['status'], [self::STATUS_PENDING, self::STATUS_RETRYING])) {
                unset($queue_data[$task_id]);
                update_option(self::QUEUE_OPTION_KEY, $queue_data);

                if (class_exists('Notion_Logger')) {
                    Notion_Logger::info_log("任务已取消: {$task_id}", 'Queue Manager');
                }

                return true;
            }
        }

        return false;
    }

    /**
     * 清理已完成的任务
     *
     * @since 2.0.0-beta.1
     * @param int $days_old 清理多少天前的任务
     * @return int 清理的任务数
     */
    public static function cleanup_completed_tasks(int $days_old = 7): int {
        $queue_data = get_option(self::QUEUE_OPTION_KEY, []);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
        $cleaned_count = 0;

        foreach ($queue_data as $task_id => $task) {
            if (in_array($task['status'], [self::STATUS_COMPLETED, self::STATUS_FAILED]) &&
                $task['updated_at'] < $cutoff_date) {
                unset($queue_data[$task_id]);
                $cleaned_count++;
            }
        }

        if ($cleaned_count > 0) {
            update_option(self::QUEUE_OPTION_KEY, $queue_data);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    "清理了 {$cleaned_count} 个已完成的队列任务",
                    'Queue Manager'
                );
            }
        }

        return $cleaned_count;
    }

    /**
     * 初始化队列系统
     *
     * @since 2.0.0-beta.1
     */
    public static function init(): void {
        // 注册队列处理钩子
        add_action(self::QUEUE_HOOK, [__CLASS__, 'process_queue']);

        // 注册清理钩子
        add_action(self::CLEANUP_HOOK, [__CLASS__, 'cleanup_completed_tasks']);

        // 调度定期清理
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CLEANUP_HOOK);
        }
    }

    /**
     * 停用队列系统
     *
     * @since 2.0.0-beta.1
     */
    public static function deactivate(): void {
        // 清理调度的事件
        wp_clear_scheduled_hook(self::QUEUE_HOOK);
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
    }
}
