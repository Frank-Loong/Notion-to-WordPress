<?php
declare(strict_types=1);

namespace NTWP\Core;

/**
 * 进度跟踪器
 * 
 * 负责跟踪异步任务的执行进度和状态
 * 提供实时进度查询和状态更新功能
 *
 * @since      2.0.0-beta.2
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Progress_Tracker {
    
    /**
     * 进度文件目录
     */
    private string $progressDir;
    
    /**
     * 构造函数
     */
    public function __construct() {
        $uploadDir = wp_upload_dir();
        $this->progressDir = $uploadDir['basedir'] . '/notion-async-progress';
        
        $this->ensureDirectory();
    }
    
    /**
     * 创建任务
     * 
     * @param string $taskId 任务ID
     * @param array $taskData 任务数据
     * @return bool 是否创建成功
     */
    public function createTask(string $taskId, array $taskData): bool {
        $filename = $this->getProgressFilename($taskId);
        
        $progressData = array_merge($taskData, [
            'created_at' => time(),
            'updated_at' => time(),
            'version' => '1.0'
        ]);
        
        $result = file_put_contents(
            $filename, 
            json_encode($progressData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        
        if ($result !== false) {
            \NTWP\Core\Logger::debug_log(
                sprintf('任务进度跟踪已创建: %s', $taskId),
                'Progress Tracker'
            );
            return true;
        }
        
        return false;
    }
    
    /**
     * 更新任务状态
     * 
     * @param string $taskId 任务ID
     * @param string $status 新状态
     * @return bool 是否更新成功
     */
    public function updateStatus(string $taskId, string $status): bool {
        return $this->updateTask($taskId, ['status' => $status]);
    }
    
    /**
     * 更新任务进度
     * 
     * @param string $taskId 任务ID
     * @param array $progressUpdate 进度更新数据
     * @return bool 是否更新成功
     */
    public function updateProgress(string $taskId, array $progressUpdate): bool {
        $taskData = $this->getTaskData($taskId);
        if ($taskData === null) {
            return false;
        }
        
        // 合并进度数据
        $currentProgress = $taskData['progress'] ?? [];
        $newProgress = array_merge($currentProgress, $progressUpdate);
        
        // 计算百分比
        if (isset($newProgress['total']) && $newProgress['total'] > 0) {
            $processed = $newProgress['processed'] ?? 0;
            $newProgress['percentage'] = min(100, round(($processed / $newProgress['total']) * 100, 2));
        }
        
        return $this->updateTask($taskId, ['progress' => $newProgress]);
    }
    
    /**
     * 获取任务进度
     * 
     * @param string $taskId 任务ID
     * @return array 进度信息
     */
    public function getProgress(string $taskId): array {
        $taskData = $this->getTaskData($taskId);
        
        if ($taskData === null) {
            return [
                'status' => 'not_found',
                'error' => '任务不存在',
                'progress' => [
                    'total' => 0,
                    'processed' => 0,
                    'percentage' => 0
                ]
            ];
        }
        
        return [
            'id' => $taskId,
            'status' => $taskData['status'] ?? 'unknown',
            'operation' => $taskData['operation'] ?? 'unknown',
            'progress' => $taskData['progress'] ?? [],
            'metadata' => $taskData['metadata'] ?? [],
            'created_at' => $taskData['created_at'] ?? 0,
            'updated_at' => $taskData['updated_at'] ?? 0,
            'errors' => $taskData['errors'] ?? []
        ];
    }
    
    /**
     * 获取活跃任务列表
     * 
     * @return array 活跃任务列表
     */
    public function getActiveTasks(): array {
        $files = glob($this->progressDir . '/progress_*.json');
        $activeTasks = [];
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            $taskData = json_decode($content, true);
            if ($taskData === null) {
                continue;
            }
            
            $status = $taskData['status'] ?? 'unknown';
            if (in_array($status, ['pending', 'running'])) {
                $taskId = basename($file, '.json');
                $taskId = str_replace('progress_', '', $taskId);
                
                $activeTasks[] = [
                    'id' => $taskId,
                    'operation' => $taskData['operation'] ?? 'unknown',
                    'status' => $status,
                    'progress' => $taskData['progress'] ?? [],
                    'created_at' => $taskData['created_at'] ?? 0
                ];
            }
        }
        
        // 按创建时间排序
        usort($activeTasks, function($a, $b) {
            return $b['created_at'] - $a['created_at'];
        });
        
        return $activeTasks;
    }
    
    /**
     * 删除任务
     * 
     * @param string $taskId 任务ID
     * @return bool 是否删除成功
     */
    public function deleteTask(string $taskId): bool {
        $filename = $this->getProgressFilename($taskId);
        
        if (file_exists($filename)) {
            $result = @unlink($filename);
            
            if ($result) {
                \NTWP\Core\Logger::debug_log(
                    sprintf('任务进度已删除: %s', $taskId),
                    'Progress Tracker'
                );
            }
            
            return $result;
        }
        
        return true; // 文件不存在也算删除成功
    }
    
    /**
     * 清理过期任务
     * 
     * @param int $maxAge 最大保留时间（秒），默认7天
     * @return int 清理的任务数量
     */
    public function cleanupExpiredTasks(int $maxAge = 604800): int {
        $files = glob($this->progressDir . '/progress_*.json');
        $cleaned = 0;
        $cutoffTime = time() - $maxAge;
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            $taskData = json_decode($content, true);
            if ($taskData === null) {
                // 删除损坏的文件
                if (@unlink($file)) {
                    $cleaned++;
                }
                continue;
            }
            
            $createdAt = $taskData['created_at'] ?? 0;
            $status = $taskData['status'] ?? 'unknown';
            
            // 删除过期的已完成/失败/取消任务
            if ($createdAt < $cutoffTime && in_array($status, ['completed', 'failed', 'cancelled'])) {
                if (@unlink($file)) {
                    $cleaned++;
                }
            }
        }
        
        if ($cleaned > 0) {
            \NTWP\Core\Logger::info_log(
                sprintf('进度跟踪清理完成: 清理了 %d 个过期任务', $cleaned),
                'Progress Tracker'
            );
        }
        
        return $cleaned;
    }
    
    /**
     * 添加错误信息
     * 
     * @param string $taskId 任务ID
     * @param string $error 错误信息
     * @return bool 是否添加成功
     */
    public function addError(string $taskId, string $error): bool {
        $taskData = $this->getTaskData($taskId);
        if ($taskData === null) {
            return false;
        }
        
        $errors = $taskData['errors'] ?? [];
        $errors[] = [
            'message' => $error,
            'timestamp' => time()
        ];
        
        // 限制错误数量，避免文件过大
        if (count($errors) > 50) {
            $errors = array_slice($errors, -50);
        }
        
        return $this->updateTask($taskId, ['errors' => $errors]);
    }
    
    /**
     * 更新任务元数据
     * 
     * @param string $taskId 任务ID
     * @param array $metadata 元数据
     * @return bool 是否更新成功
     */
    public function updateMetadata(string $taskId, array $metadata): bool {
        $taskData = $this->getTaskData($taskId);
        if ($taskData === null) {
            return false;
        }
        
        $currentMetadata = $taskData['metadata'] ?? [];
        $newMetadata = array_merge($currentMetadata, $metadata);
        
        return $this->updateTask($taskId, ['metadata' => $newMetadata]);
    }
    
    /**
     * 获取系统统计信息
     * 
     * @return array 统计信息
     */
    public function getStatistics(): array {
        $files = glob($this->progressDir . '/progress_*.json');
        $stats = [
            'total_tasks' => 0,
            'pending' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0
        ];
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            $taskData = json_decode($content, true);
            if ($taskData === null) {
                continue;
            }
            
            $stats['total_tasks']++;
            $status = $taskData['status'] ?? 'unknown';
            
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }
        
        return $stats;
    }
    
    /**
     * 确保目录存在
     */
    private function ensureDirectory(): void {
        if (!is_dir($this->progressDir)) {
            wp_mkdir_p($this->progressDir);
            
            // 创建.htaccess文件保护进度目录
            file_put_contents($this->progressDir . '/.htaccess', "Deny from all\n");
        }
    }
    
    /**
     * 获取进度文件名
     */
    private function getProgressFilename(string $taskId): string {
        return $this->progressDir . '/progress_' . $taskId . '.json';
    }
    
    /**
     * 获取任务数据
     */
    private function getTaskData(string $taskId): ?array {
        $filename = $this->getProgressFilename($taskId);
        
        if (!file_exists($filename)) {
            return null;
        }
        
        $content = file_get_contents($filename);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        return $data === null ? null : $data;
    }
    
    /**
     * 更新任务数据
     */
    private function updateTask(string $taskId, array $updates): bool {
        $taskData = $this->getTaskData($taskId);
        if ($taskData === null) {
            return false;
        }
        
        // 合并更新数据
        $taskData = array_merge($taskData, $updates);
        $taskData['updated_at'] = time();
        
        // 原子写入
        $filename = $this->getProgressFilename($taskId);
        $tempFile = $filename . '.tmp';
        
        $result = file_put_contents(
            $tempFile, 
            json_encode($taskData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        
        if ($result !== false) {
            return rename($tempFile, $filename);
        }
        
        return false;
    }
}
