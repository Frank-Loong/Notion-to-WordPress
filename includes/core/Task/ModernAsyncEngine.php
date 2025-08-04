<?php
declare(strict_types=1);

namespace NTWP\Core\Task;

use NTWP\Core\Foundation\Logger;

/**
 * 现代化异步处理引擎
 *
 * 高性能、代码简洁、不依赖外部插件的异步处理系统
 * 使用现代PHP特性，提供统一的API接口
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

class ModernAsyncEngine {
    
    /**
     * 任务状态常量
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    
    /**
     * 优先级常量
     */
    public const PRIORITY_LOW = 1;
    public const PRIORITY_NORMAL = 5;
    public const PRIORITY_HIGH = 10;
    public const PRIORITY_URGENT = 15;
    
    /**
     * 默认配置
     */
    private const DEFAULT_CONFIG = [
        'batch_size' => 20,
        'timeout' => 300,
        'max_retries' => 3,
        'priority' => self::PRIORITY_NORMAL,
        'memory_limit' => '256M',
        'concurrent_limit' => 3
    ];
    
    /**
     * 任务队列实例
     */
    private static ?\NTWP\Infrastructure\Concurrency\TaskQueue $queue = null;
    
    /**
     * 进度跟踪器实例
     */
    private static ?Progress_Tracker $tracker = null;
    
    /**
     * 执行异步任务
     * 
     * @param string $operation 操作类型
     * @param iterable $data 要处理的数据
     * @param array $options 选项配置
     * @return string 任务ID
     */
    public static function execute(string $operation, iterable $data, array $options = []): string {
        // 合并配置
        $config = array_merge(self::DEFAULT_CONFIG, $options);
        
        // 生成任务ID
        $taskId = self::generateTaskId($operation);
        
        // 初始化组件
        self::initializeComponents();
        
        // 创建任务
        $task = [
            'id' => $taskId,
            'operation' => $operation,
            'status' => self::STATUS_PENDING,
            'created_at' => time(),
            'config' => $config,
            'progress' => [
                'total' => is_countable($data) ? count($data) : 0,
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'percentage' => 0
            ],
            'metadata' => [
                'memory_peak' => 0,
                'execution_time' => 0,
                'retry_count' => 0
            ]
        ];
        
        // 将数据分片并加入队列
        $chunks = self::createDataChunks($data, $config['batch_size']);
        foreach ($chunks as $index => $chunk) {
            self::$queue->enqueue([
                'task_id' => $taskId,
                'chunk_index' => $index,
                'operation' => $operation,
                'data' => $chunk,
                'config' => $config
            ], $config['priority']);
        }
        
        // 保存任务信息
        self::$tracker->createTask($taskId, $task);
        
        // 触发异步处理
        self::triggerAsyncProcessing();
        
        Logger::info_log(
            sprintf('异步任务已创建: %s, 操作: %s, 数据量: %d', 
                $taskId, $operation, $task['progress']['total']),
            'Modern Async Engine'
        );
        
        return $taskId;
    }
    
    /**
     * 获取任务进度
     * 
     * @param string $taskId 任务ID
     * @return array 进度信息
     */
    public static function getProgress(string $taskId): array {
        self::initializeComponents();
        return self::$tracker->getProgress($taskId);
    }
    
    /**
     * 取消任务
     * 
     * @param string $taskId 任务ID
     * @return bool 是否成功取消
     */
    public static function cancel(string $taskId): bool {
        self::initializeComponents();
        
        // 更新任务状态
        $success = self::$tracker->updateStatus($taskId, self::STATUS_CANCELLED);
        
        // 从队列中移除相关任务
        self::$queue->removeByTaskId($taskId);
        
        if ($success) {
            Logger::info_log("任务已取消: {$taskId}", 'Modern Async Engine');
        }
        
        return $success;
    }
    
    /**
     * 获取系统状态
     * 
     * @return array 系统状态信息
     */
    public static function getStatus(): array {
        self::initializeComponents();
        
        return [
            'queue_size' => self::$queue->size(),
            'active_tasks' => self::$tracker->getActiveTasks(),
            'system_load' => self::getSystemLoad(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }
    
    /**
     * 处理异步请求
     * 
     * 这个方法由WordPress的异步HTTP请求调用
     */
    public static function handleAsyncRequest(): void {
        // 验证请求
        if (!self::validateAsyncRequest()) {
            wp_die('Invalid async request', 'Async Processing', ['response' => 403]);
        }
        
        self::initializeComponents();
        
        $startTime = microtime(true);
        $processedCount = 0;
        $maxExecutionTime = 25; // 25秒执行限制
        
        // 处理队列中的任务
        while (($item = self::$queue->dequeue()) !== null) {
            try {
                self::processQueueItem($item);
                $processedCount++;
                
                // 检查执行时间限制
                if ((microtime(true) - $startTime) > $maxExecutionTime) {
                    break;
                }
                
                // 检查内存使用
                if (memory_get_usage(true) > self::getMemoryLimit() * 0.8) {
                    break;
                }
                
            } catch (Exception $e) {
                Logger::error_log(
                    sprintf('队列项目处理失败: %s', $e->getMessage()),
                    'Modern Async Engine'
                );
            }
        }
        
        // 如果还有任务，触发下一轮处理
        if (self::$queue->size() > 0) {
            self::triggerAsyncProcessing();
        }
        
        Logger::debug_log(
            sprintf('异步处理完成: 处理了 %d 个项目，耗时 %.2f 秒', 
                $processedCount, microtime(true) - $startTime),
            'Modern Async Engine'
        );
        
        wp_die(); // 正常结束异步请求
    }
    
    /**
     * 初始化组件
     */
    private static function initializeComponents(): void {
        if (self::$queue === null) {
            self::$queue = new \NTWP\Infrastructure\Concurrency\TaskQueue();
        }
        
        if (self::$tracker === null) {
            self::$tracker = new \NTWP\Core\Foundation\Performance\ProgressTracker();
        }
    }
    
    /**
     * 生成任务ID
     */
    private static function generateTaskId(string $operation): string {
        return sprintf('%s_%s_%s', 
            $operation, 
            date('YmdHis'), 
            substr(uniqid(), -6)
        );
    }
    
    /**
     * 创建数据分片
     */
    private static function createDataChunks(iterable $data, int $chunkSize): \Generator {
        $chunk = [];
        $count = 0;
        
        foreach ($data as $item) {
            $chunk[] = $item;
            $count++;
            
            if ($count >= $chunkSize) {
                yield $chunk;
                $chunk = [];
                $count = 0;
            }
        }
        
        // 处理最后一个不完整的分片
        if (!empty($chunk)) {
            yield $chunk;
        }
    }
    
    /**
     * 触发异步处理
     */
    private static function triggerAsyncProcessing(): void {
        $url = add_query_arg([
            'action' => 'notion_async_process',
            'nonce' => wp_create_nonce('notion_async_process')
        ], admin_url('admin-ajax.php'));
        
        wp_remote_post($url, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false,
            'body' => ['async' => '1']
        ]);
    }
    
    /**
     * 验证异步请求
     */
    private static function validateAsyncRequest(): bool {
        return isset($_POST['async']) && 
               wp_verify_nonce($_GET['nonce'] ?? '', 'notion_async_process');
    }
    
    /**
     * 处理队列项目
     */
    private static function processQueueItem(array $item): void {
        $taskId = $item['task_id'];
        $operation = $item['operation'];
        $data = $item['data'];
        $config = $item['config'];
        
        // 更新任务状态为运行中
        self::$tracker->updateStatus($taskId, self::STATUS_RUNNING);
        
        // 执行具体操作
        $executor = new \NTWP\Core\Foundation\Task\TaskExecutor();
        $result = $executor->execute($operation, $data, $config);
        
        // 更新进度
        self::$tracker->updateProgress($taskId, [
            'processed' => count($data),
            'success' => $result['success_count'] ?? 0,
            'failed' => $result['error_count'] ?? 0
        ]);
    }
    
    /**
     * 获取系统负载
     */
    private static function getSystemLoad(): float {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load[0] ?? 0.0;
        }
        return 0.0;
    }
    
    /**
     * 获取内存限制
     */
    private static function getMemoryLimit(): int {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        
        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));
        
        switch($unit) {
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
     * 初始化异步处理系统
     */
    public static function init(): void {
        // 注册AJAX处理器
        add_action('wp_ajax_notion_async_process', [__CLASS__, 'handleAsyncRequest']);
        add_action('wp_ajax_nopriv_notion_async_process', [__CLASS__, 'handleAsyncRequest']);
        
        // 注册清理钩子
        add_action('notion_async_cleanup', [__CLASS__, 'cleanup']);
        
        // 调度定期清理
        if (!wp_next_scheduled('notion_async_cleanup')) {
            wp_schedule_event(time(), 'daily', 'notion_async_cleanup');
        }
    }
    
    /**
     * 重试失败的任务
     *
     * @param string $taskId 任务ID，如果为空则重试所有失败任务
     * @return bool 是否成功启动重试
     */
    public static function retryFailed(string $taskId = ''): bool {
        self::initializeComponents();

        try {
            if (!empty($taskId)) {
                // 重试特定任务
                $task = self::$tracker->getTask($taskId);
                if (!$task || $task['status'] !== self::STATUS_FAILED) {
                    return false;
                }

                // 重置任务状态
                self::$tracker->updateStatus($taskId, self::STATUS_PENDING);
                self::$tracker->incrementRetryCount($taskId);

                Logger::info_log("重试失败任务: {$taskId}", 'Modern Async Engine');

                // 触发异步处理
                self::triggerAsyncProcessing();

                return true;
            } else {
                // 重试所有失败任务
                $failedTasks = self::$tracker->getFailedTasks();
                $retryCount = 0;

                foreach ($failedTasks as $task) {
                    if (self::retryFailed($task['id'])) {
                        $retryCount++;
                    }
                }

                Logger::info_log("批量重试失败任务: {$retryCount} 个", 'Modern Async Engine');

                return $retryCount > 0;
            }
        } catch (Exception $e) {
            Logger::error_log(
                sprintf('重试失败任务时出错: %s', $e->getMessage()),
                'Modern Async Engine'
            );
            return false;
        }
    }

    /**
     * 清理过期任务
     */
    public static function cleanup(): void {
        self::initializeComponents();

        $cleaned = self::$tracker->cleanupExpiredTasks();
        self::$queue->cleanup();

        Logger::info_log(
            sprintf('异步任务清理完成: 清理了 %d 个过期任务', $cleaned),
            'Modern Async Engine'
        );
    }
}
