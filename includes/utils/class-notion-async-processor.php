<?php
/**
 * Notion异步处理工具类
 * 
 * 提供异步处理的工具方法和状态管理
 * 基于现有的异步处理基础设施扩展
 *
 * @since      2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/includes/utils
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 异步处理工具类
 * 
 * 提供异步处理的通用工具方法
 *
 * @since 2.0.0-beta.1
 */
class Notion_Async_Processor {

    /**
     * 异步状态选项键名
     */
    const ASYNC_STATUS_KEY = 'notion_async_status';
    
    /**
     * 异步操作类型
     */
    const OPERATION_IMPORT = 'import';
    const OPERATION_UPDATE = 'update';
    const OPERATION_DELETE = 'delete';
    const OPERATION_IMAGE_PROCESS = 'image_process';
    
    /**
     * 状态常量
     */
    const STATUS_IDLE = 'idle';
    const STATUS_RUNNING = 'running';
    const STATUS_PAUSED = 'paused';
    const STATUS_ERROR = 'error';

    /**
     * 检查是否可以启动异步操作
     *
     * @since 2.0.0-beta.1
     * @param string $operation 操作类型
     * @return bool 是否可以启动
     */
    public static function can_start_async_operation(string $operation): bool {
        $current_status = self::get_async_status();
        
        // 如果系统空闲，可以启动任何操作
        if ($current_status['status'] === self::STATUS_IDLE) {
            return true;
        }
        
        // 如果有相同类型的操作在运行，不能启动
        if ($current_status['operation'] === $operation && 
            $current_status['status'] === self::STATUS_RUNNING) {
            return false;
        }
        
        // 检查系统负载
        if (self::is_system_overloaded()) {
            return false;
        }
        
        return true;
    }

    /**
     * 启动异步操作
     *
     * @since 2.0.0-beta.1
     * @param string $operation 操作类型
     * @param array $data 操作数据
     * @param array $options 选项
     * @return string|false 任务ID或false
     */
    public static function start_async_operation(string $operation, array $data, array $options = []) {
        if (!self::can_start_async_operation($operation)) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    "无法启动异步操作: {$operation}，系统忙碌或已有相同操作运行",
                    'Async Processor'
                );
            }
            return false;
        }
        
        // 更新异步状态
        self::update_async_status($operation, self::STATUS_RUNNING, [
            'started_at' => current_time('mysql'),
            'data_count' => count($data),
            'options' => $options
        ]);
        
        // 根据数据量决定处理方式
        if (count($data) > ($options['queue_threshold'] ?? 50)) {
            // 大批量操作使用队列
            if (class_exists('Notion_Queue_Manager')) {
                $task_id = Notion_Queue_Manager::enqueue_batch_operation($operation, $data, $options);
                
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::info_log(
                        "大批量异步操作已加入队列: {$operation}, 数据量: " . count($data) . ", 任务ID: {$task_id}",
                        'Async Processor'
                    );
                }
                
                return $task_id;
            }
        }
        
        // 小批量操作直接处理
        return self::process_small_batch($operation, $data, $options);
    }

    /**
     * 处理小批量操作
     *
     * @since 2.0.0-beta.1
     * @param string $operation 操作类型
     * @param array $data 操作数据
     * @param array $options 选项
     * @return string 任务ID
     */
    private static function process_small_batch(string $operation, array $data, array $options): string {
        $task_id = 'small_batch_' . uniqid();
        
        // 使用WordPress的异步请求处理
        wp_schedule_single_event(time() + 1, 'notion_async_small_batch', [
            'task_id' => $task_id,
            'operation' => $operation,
            'data' => $data,
            'options' => $options
        ]);
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                "小批量异步操作已调度: {$operation}, 数据量: " . count($data) . ", 任务ID: {$task_id}",
                'Async Processor'
            );
        }
        
        return $task_id;
    }

    /**
     * 处理小批量异步任务
     *
     * @since 2.0.0-beta.1
     * @param string $task_id 任务ID
     * @param string $operation 操作类型
     * @param array $data 操作数据
     * @param array $options 选项
     */
    public static function handle_small_batch_async(string $task_id, string $operation, array $data, array $options): void {
        $start_time = microtime(true);
        
        try {
            $success_count = 0;
            $error_count = 0;
            
            foreach ($data as $item) {
                try {
                    $result = self::process_single_item($operation, $item, $options);
                    if ($result) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                } catch (Exception $e) {
                    $error_count++;
                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::error_log(
                            "小批量处理项目失败: {$e->getMessage()}",
                            'Async Processor'
                        );
                    }
                }
            }
            
            $processing_time = microtime(true) - $start_time;
            
            // 更新状态为空闲
            self::update_async_status($operation, self::STATUS_IDLE, [
                'completed_at' => current_time('mysql'),
                'success_count' => $success_count,
                'error_count' => $error_count,
                'processing_time' => $processing_time
            ]);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    "小批量异步处理完成: {$task_id}, 成功: {$success_count}, 失败: {$error_count}, 耗时: " . round($processing_time, 3) . "秒",
                    'Async Processor'
                );
            }
            
        } catch (Exception $e) {
            // 更新状态为错误
            self::update_async_status($operation, self::STATUS_ERROR, [
                'error_at' => current_time('mysql'),
                'error_message' => $e->getMessage()
            ]);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    "小批量异步处理异常: {$task_id}, 错误: {$e->getMessage()}",
                    'Async Processor'
                );
            }
        }
    }

    /**
     * 处理单个项目
     *
     * @since 2.0.0-beta.1
     * @param string $operation 操作类型
     * @param mixed $item 项目数据
     * @param array $options 选项
     * @return bool 处理结果
     */
    private static function process_single_item(string $operation, $item, array $options): bool {
        switch ($operation) {
            case self::OPERATION_IMPORT:
                if (class_exists('Notion_Import_Coordinator')) {
                    return Notion_Import_Coordinator::import_single_page($item);
                }
                break;
                
            case self::OPERATION_UPDATE:
                if (class_exists('Notion_Import_Coordinator') && isset($item['post_id'], $item['page_data'])) {
                    return Notion_Import_Coordinator::update_existing_post($item['post_id'], $item['page_data']);
                }
                break;
                
            case self::OPERATION_DELETE:
                if (isset($item['post_id'])) {
                    return wp_delete_post($item['post_id'], $item['force_delete'] ?? false) !== false;
                }
                break;
                
            case self::OPERATION_IMAGE_PROCESS:
                if (class_exists('Notion_Image_Processor') && isset($item['url'])) {
                    return Notion_Image_Processor::process_single_image($item['url']) !== false;
                }
                break;
                
            default:
                // 允许自定义操作
                $result = apply_filters('notion_async_process_item', null, $operation, $item, $options);
                return $result !== null ? (bool)$result : false;
        }
        
        return false;
    }

    /**
     * 获取异步状态
     *
     * @since 2.0.0-beta.1
     * @return array 异步状态
     */
    public static function get_async_status(): array {
        $default_status = [
            'status' => self::STATUS_IDLE,
            'operation' => '',
            'started_at' => '',
            'updated_at' => '',
            'data_count' => 0,
            'progress' => 0,
            'details' => []
        ];
        
        return array_merge($default_status, get_option(self::ASYNC_STATUS_KEY, []));
    }

    /**
     * 更新异步状态
     *
     * @since 2.0.0-beta.1
     * @param string $operation 操作类型
     * @param string $status 状态
     * @param array $details 详细信息
     */
    public static function update_async_status(string $operation, string $status, array $details = []): void {
        $current_status = self::get_async_status();
        
        $new_status = [
            'status' => $status,
            'operation' => $operation,
            'updated_at' => current_time('mysql'),
            'details' => array_merge($current_status['details'], $details)
        ];
        
        // 保留某些字段
        if (!empty($current_status['started_at']) && $status !== self::STATUS_IDLE) {
            $new_status['started_at'] = $current_status['started_at'];
        }
        
        if (isset($details['data_count'])) {
            $new_status['data_count'] = $details['data_count'];
        } elseif (!empty($current_status['data_count'])) {
            $new_status['data_count'] = $current_status['data_count'];
        }
        
        update_option(self::ASYNC_STATUS_KEY, $new_status);
    }

    /**
     * 检查系统是否过载
     *
     * @since 2.0.0-beta.1
     * @return bool 是否过载
     */
    private static function is_system_overloaded(): bool {
        // 检查内存使用率
        if (class_exists('Notion_Memory_Manager')) {
            $memory_stats = Notion_Memory_Manager::get_memory_stats();
            if (isset($memory_stats['usage_percent']) && $memory_stats['usage_percent'] > 90) {
                return true;
            }
        }
        
        // 检查系统负载
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg()[0] ?? 0;
            if ($load > 4.0) {
                return true;
            }
        }
        
        // 检查队列状态
        if (class_exists('Notion_Queue_Manager')) {
            $queue_status = Notion_Queue_Manager::get_queue_status();
            if ($queue_status['processing'] > 3) { // 超过3个任务在处理
                return true;
            }
        }
        
        return false;
    }

    /**
     * 暂停异步操作
     *
     * @since 2.0.0-beta.1
     * @return bool 暂停结果
     */
    public static function pause_async_operation(): bool {
        $current_status = self::get_async_status();
        
        if ($current_status['status'] === self::STATUS_RUNNING) {
            self::update_async_status($current_status['operation'], self::STATUS_PAUSED, [
                'paused_at' => current_time('mysql')
            ]);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('异步操作已暂停', 'Async Processor');
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * 恢复异步操作
     *
     * @since 2.0.0-beta.1
     * @return bool 恢复结果
     */
    public static function resume_async_operation(): bool {
        $current_status = self::get_async_status();
        
        if ($current_status['status'] === self::STATUS_PAUSED) {
            self::update_async_status($current_status['operation'], self::STATUS_RUNNING, [
                'resumed_at' => current_time('mysql')
            ]);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('异步操作已恢复', 'Async Processor');
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * 停止异步操作
     *
     * @since 2.0.0-beta.1
     * @return bool 停止结果
     */
    public static function stop_async_operation(): bool {
        $current_status = self::get_async_status();
        
        if (in_array($current_status['status'], [self::STATUS_RUNNING, self::STATUS_PAUSED])) {
            self::update_async_status('', self::STATUS_IDLE, [
                'stopped_at' => current_time('mysql')
            ]);
            
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('异步操作已停止', 'Async Processor');
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * 初始化异步处理器
     *
     * @since 2.0.0-beta.1
     */
    public static function init(): void {
        // 注册小批量异步处理钩子
        add_action('notion_async_small_batch', [__CLASS__, 'handle_small_batch_async'], 10, 4);
    }
}
