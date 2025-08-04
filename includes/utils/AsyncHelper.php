<?php
declare(strict_types=1);

namespace NTWP\Utils;

/**
 * 异步处理助手类
 *
 * 提供简化的API接口，兼容现有代码
 * 作为新旧异步系统之间的桥梁
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

class AsyncHelper {
    
    /**
     * 异步导入页面
     * 
     * @param array $pages 页面数据数组
     * @param array $options 选项配置
     * @return string 任务ID
     */
    public static function import_pages_async(array $pages, array $options = []): string {
        if (class_exists('ModernAsyncEngine')) {
            // 使用现代异步引擎
            return Notion_ModernAsyncEngine::execute('import_pages', $pages, [
                'batch_size' => $options['batch_size'] ?? 20,
                'timeout' => $options['timeout'] ?? 300,
                'priority' => Notion_ModernAsyncEngine::PRIORITY_NORMAL
            ]);
        } else {
            // 回退到同步处理
            return self::process_synchronously('import_pages', $pages, $options);
        }
    }
    
    /**
     * 异步更新页面
     * 
     * @param array $pages 页面数据数组
     * @param array $options 选项配置
     * @return string 任务ID
     */
    public static function update_pages_async(array $pages, array $options = []): string {
        if (class_exists('ModernAsyncEngine')) {
            return Notion_ModernAsyncEngine::execute('update_pages', $pages, [
                'batch_size' => $options['batch_size'] ?? 15,
                'timeout' => $options['timeout'] ?? 300,
                'priority' => Notion_ModernAsyncEngine::PRIORITY_HIGH
            ]);
        } else {
            return self::process_synchronously('update_pages', $pages, $options);
        }
    }
    
    /**
     * 异步处理图片
     * 
     * @param array $images 图片数据数组
     * @param array $options 选项配置
     * @return string 任务ID
     */
    public static function process_images_async(array $images, array $options = []): string {
        if (class_exists('ModernAsyncEngine')) {
            return Notion_ModernAsyncEngine::execute('process_images', $images, [
                'batch_size' => $options['batch_size'] ?? 10,
                'timeout' => $options['timeout'] ?? 600,
                'priority' => Notion_ModernAsyncEngine::PRIORITY_LOW
            ]);
        } else {
            return self::process_synchronously('process_images', $images, $options);
        }
    }
    
    /**
     * 异步删除文章
     * 
     * @param array $posts 文章数据数组
     * @param array $options 选项配置
     * @return string 任务ID
     */
    public static function delete_posts_async(array $posts, array $options = []): string {
        if (class_exists('ModernAsyncEngine')) {
            return Notion_ModernAsyncEngine::execute('delete_posts', $posts, [
                'batch_size' => $options['batch_size'] ?? 25,
                'timeout' => $options['timeout'] ?? 180,
                'priority' => Notion_ModernAsyncEngine::PRIORITY_NORMAL
            ]);
        } else {
            return self::process_synchronously('delete_posts', $posts, $options);
        }
    }
    
    /**
     * 增量同步
     * 
     * @param array $data 同步数据
     * @param array $options 选项配置
     * @return string 任务ID
     */
    public static function sync_incremental_async(array $data, array $options = []): string {
        if (class_exists('ModernAsyncEngine')) {
            return Notion_ModernAsyncEngine::execute('sync_incremental', $data, [
                'batch_size' => $options['batch_size'] ?? 30,
                'timeout' => $options['timeout'] ?? 300,
                'priority' => Notion_ModernAsyncEngine::PRIORITY_HIGH
            ]);
        } else {
            return self::process_synchronously('sync_incremental', $data, $options);
        }
    }
    
    /**
     * 获取任务进度
     * 
     * @param string $taskId 任务ID
     * @return array 进度信息
     */
    public static function get_task_progress(string $taskId): array {
        if (class_exists('ModernAsyncEngine')) {
            return Notion_ModernAsyncEngine::getProgress($taskId);
        } else {
            return [
                'status' => 'unavailable',
                'error' => '现代异步引擎不可用',
                'progress' => ['percentage' => 0]
            ];
        }
    }
    
    /**
     * 取消任务
     * 
     * @param string $taskId 任务ID
     * @return bool 是否成功取消
     */
    public static function cancel_task(string $taskId): bool {
        if (class_exists('ModernAsyncEngine')) {
            return Notion_ModernAsyncEngine::cancel($taskId);
        } else {
            return false;
        }
    }
    
    /**
     * 获取系统状态
     * 
     * @return array 系统状态
     */
    public static function get_system_status(): array {
        if (class_exists('ModernAsyncEngine')) {
            return Notion_ModernAsyncEngine::getStatus();
        } else {
            return [
                'queue_size' => 0,
                'active_tasks' => [],
                'system_load' => 0.0,
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'engine_available' => false
            ];
        }
    }
    
    /**
     * 检查是否使用现代异步引擎
     * 
     * @return bool 是否使用现代引擎
     */
    public static function is_modern_engine_available(): bool {
        return class_exists('ModernAsyncEngine');
    }
    
    /**
     * 获取推荐的批次大小
     * 
     * @param string $operation 操作类型
     * @param int $dataCount 数据数量
     * @return int 推荐的批次大小
     */
    public static function get_recommended_batch_size(string $operation, int $dataCount): int {
        // 基于操作类型和系统资源的智能批次大小计算
        $memoryLimit = self::getMemoryLimitInBytes();
        $availableMemory = $memoryLimit - memory_get_usage(true);
        
        $baseSizes = [
            'import_pages' => 20,
            'update_pages' => 15,
            'process_images' => 10,
            'delete_posts' => 25,
            'sync_incremental' => 30,
            'cleanup_data' => 50
        ];
        
        $baseSize = $baseSizes[$operation] ?? 20;
        
        // 根据可用内存调整
        if ($availableMemory < 64 * 1024 * 1024) { // 小于64MB
            $baseSize = max(5, intval($baseSize * 0.5));
        } elseif ($availableMemory > 256 * 1024 * 1024) { // 大于256MB
            $baseSize = intval($baseSize * 1.5);
        }
        
        // 确保不超过数据总量
        return min($baseSize, $dataCount);
    }
    

    
    /**
     * 同步处理（回退方案）
     */
    private static function process_synchronously(string $operation, array $data, array $options): string {
        $taskId = sprintf('sync_%s_%s', $operation, uniqid());

        \NTWP\Core\Foundation\Logger::warning_log(
            sprintf('现代异步引擎不可用，使用同步处理: %s, 数据量: %d', $operation, count($data)),
            'Async Helper'
        );

        // 这里可以实现基础的同步处理逻辑作为最后的回退方案
        // 实际项目中应该调用相应的同步处理方法

        return $taskId;
    }
    
    /**
     * 获取内存限制（字节）
     */
    private static function getMemoryLimitInBytes(): int {
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
}
