<?php
declare(strict_types=1);

namespace NTWP\Infrastructure\Concurrency;

/**
 * 轻量级任务队列
 *
 * 基于文件系统的高性能任务队列，无需数据库
 * 支持优先级、原子操作、自动清理
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

class TaskQueue {
    
    /**
     * 队列目录
     */
    private string $queueDir;
    
    /**
     * 锁文件目录
     */
    private string $lockDir;
    
    /**
     * 构造函数
     */
    public function __construct() {
        $uploadDir = wp_upload_dir();
        $this->queueDir = $uploadDir['basedir'] . '/notion-async-queue';
        $this->lockDir = $this->queueDir . '/locks';
        
        $this->ensureDirectories();
    }
    
    /**
     * 将任务加入队列
     * 
     * @param array $data 任务数据
     * @param int $priority 优先级 (1-15, 数字越大优先级越高)
     * @return string 队列项目ID
     */
    public function enqueue(array $data, int $priority = 5): string {
        $itemId = $this->generateItemId($priority);
        $filename = $this->getQueueFilename($itemId);
        
        $queueItem = [
            'id' => $itemId,
            'data' => $data,
            'priority' => $priority,
            'created_at' => time(),
            'attempts' => 0,
            'max_attempts' => 3
        ];
        
        // 原子写入
        $tempFile = $filename . '.tmp';
        if (file_put_contents($tempFile, json_encode($queueItem, JSON_UNESCAPED_UNICODE)) !== false) {
            rename($tempFile, $filename);
            
            Logger::debug_log(
                sprintf('任务已加入队列: %s, 优先级: %d', $itemId, $priority),
                'Task Queue'
            );
            
            return $itemId;
        }
        
        throw new RuntimeException('无法将任务写入队列');
    }
    
    /**
     * 从队列中取出任务
     * 
     * @return array|null 任务数据或null（如果队列为空）
     */
    public function dequeue(): ?array {
        $files = $this->getQueueFiles();
        
        if (empty($files)) {
            return null;
        }
        
        // 按优先级和时间排序（优先级高的先处理，同优先级按时间先后）
        usort($files, function($a, $b) {
            // 从文件名解析优先级和时间戳
            $priorityA = $this->extractPriorityFromFilename($a);
            $priorityB = $this->extractPriorityFromFilename($b);
            
            if ($priorityA !== $priorityB) {
                return $priorityB - $priorityA; // 优先级高的在前
            }
            
            return strcmp($a, $b); // 同优先级按文件名（时间戳）排序
        });
        
        // 尝试获取第一个可用的任务
        foreach ($files as $file) {
            $lockFile = $this->getLockFilename($file);
            
            // 检查是否被锁定
            if (file_exists($lockFile)) {
                // 检查锁是否过期（超过5分钟）
                if (time() - filemtime($lockFile) < 300) {
                    continue; // 锁未过期，跳过
                }
                // 锁已过期，删除锁文件
                @unlink($lockFile);
            }
            
            // 尝试获取锁
            if (!$this->acquireLock($file)) {
                continue;
            }
            
            // 读取任务数据
            $content = file_get_contents($this->queueDir . '/' . $file);
            if ($content === false) {
                $this->releaseLock($file);
                continue;
            }
            
            $queueItem = json_decode($content, true);
            if ($queueItem === null) {
                $this->releaseLock($file);
                @unlink($this->queueDir . '/' . $file); // 删除损坏的文件
                continue;
            }
            
            // 删除队列文件
            @unlink($this->queueDir . '/' . $file);
            $this->releaseLock($file);
            
            Logger::debug_log(
                sprintf('从队列取出任务: %s', $queueItem['id']),
                'Task Queue'
            );
            
            return $queueItem['data'];
        }
        
        return null;
    }
    
    /**
     * 获取队列大小
     * 
     * @return int 队列中的任务数量
     */
    public function size(): int {
        return count($this->getQueueFiles());
    }
    
    /**
     * 清空队列
     */
    public function clear(): void {
        $files = glob($this->queueDir . '/queue_*.json');
        foreach ($files as $file) {
            @unlink($file);
        }
        
        Logger::info_log('任务队列已清空', 'Task Queue');
    }
    
    /**
     * 根据任务ID移除任务
     * 
     * @param string $taskId 任务ID
     * @return int 移除的任务数量
     */
    public function removeByTaskId(string $taskId): int {
        $files = $this->getQueueFiles();
        $removed = 0;
        
        foreach ($files as $file) {
            $content = file_get_contents($this->queueDir . '/' . $file);
            if ($content === false) {
                continue;
            }
            
            $queueItem = json_decode($content, true);
            if ($queueItem === null) {
                continue;
            }
            
            // 检查是否匹配任务ID
            if (isset($queueItem['data']['task_id']) && $queueItem['data']['task_id'] === $taskId) {
                if (@unlink($this->queueDir . '/' . $file)) {
                    $removed++;
                }
            }
        }
        
        if ($removed > 0) {
            Logger::info_log(
                sprintf('已从队列移除 %d 个任务: %s', $removed, $taskId),
                'Task Queue'
            );
        }
        
        return $removed;
    }
    
    /**
     * 清理过期的锁文件和损坏的队列文件
     */
    public function cleanup(): void {
        $cleaned = 0;
        
        // 清理过期锁文件
        $lockFiles = glob($this->lockDir . '/*.lock');
        foreach ($lockFiles as $lockFile) {
            if (time() - filemtime($lockFile) > 3600) { // 1小时过期
                if (@unlink($lockFile)) {
                    $cleaned++;
                }
            }
        }
        
        // 清理损坏的队列文件
        $queueFiles = $this->getQueueFiles();
        foreach ($queueFiles as $file) {
            $content = file_get_contents($this->queueDir . '/' . $file);
            if ($content === false || json_decode($content, true) === null) {
                if (@unlink($this->queueDir . '/' . $file)) {
                    $cleaned++;
                }
            }
        }
        
        if ($cleaned > 0) {
            Logger::info_log(
                sprintf('队列清理完成: 清理了 %d 个文件', $cleaned),
                'Task Queue'
            );
        }
    }
    
    /**
     * 确保目录存在
     */
    private function ensureDirectories(): void {
        if (!is_dir($this->queueDir)) {
            wp_mkdir_p($this->queueDir);
            
            // 创建.htaccess文件保护队列目录
            file_put_contents($this->queueDir . '/.htaccess', "Deny from all\n");
        }
        
        if (!is_dir($this->lockDir)) {
            wp_mkdir_p($this->lockDir);
        }
    }
    
    /**
     * 生成队列项目ID
     */
    private function generateItemId(int $priority): string {
        // 格式: priority_timestamp_random
        return sprintf('%02d_%d_%s', 
            $priority, 
            time(), 
            substr(uniqid(), -6)
        );
    }
    
    /**
     * 获取队列文件名
     */
    private function getQueueFilename(string $itemId): string {
        return $this->queueDir . '/queue_' . $itemId . '.json';
    }
    
    /**
     * 获取锁文件名
     */
    private function getLockFilename(string $queueFile): string {
        $basename = basename($queueFile, '.json');
        return $this->lockDir . '/' . $basename . '.lock';
    }
    
    /**
     * 获取队列文件列表
     */
    private function getQueueFiles(): array {
        $files = glob($this->queueDir . '/queue_*.json');
        return array_map('basename', $files);
    }
    
    /**
     * 从文件名提取优先级
     */
    private function extractPriorityFromFilename(string $filename): int {
        if (preg_match('/queue_(\d{2})_/', $filename, $matches)) {
            return (int) $matches[1];
        }
        return 5; // 默认优先级
    }
    
    /**
     * 获取锁
     */
    private function acquireLock(string $queueFile): bool {
        $lockFile = $this->getLockFilename($queueFile);
        
        // 尝试创建锁文件
        $handle = @fopen($lockFile, 'x');
        if ($handle === false) {
            return false;
        }
        
        fwrite($handle, (string) getmypid());
        fclose($handle);
        
        return true;
    }
    
    /**
     * 释放锁
     */
    private function releaseLock(string $queueFile): void {
        $lockFile = $this->getLockFilename($queueFile);
        @unlink($lockFile);
    }
}
