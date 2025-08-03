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
    
    // 移除了文件存储相关属性，现在只使用内存存储

    /**
     * 内存存储缓存前缀
     */
    private const CACHE_PREFIX = 'ntwp_progress_';

    /**
     * 缓存过期时间（秒）
     */
    private const CACHE_EXPIRATION = 3600; // 1小时

    /**
     * 构造函数
     */
    public function __construct() {
        // 现在只使用内存存储
    }
    
    /**
     * 创建任务
     *
     * @param string $taskId 任务ID
     * @param array $taskData 任务数据
     * @return bool 是否创建成功
     */
    public function createTask(string $taskId, array $taskData): bool {
        $progressData = array_merge($taskData, [
            'created_at' => time(),
            'updated_at' => time(),
            'version' => '1.0'
        ]);

        // 使用内存存储（transient）
        $cacheKey = self::CACHE_PREFIX . $taskId;
        $result = set_transient($cacheKey, $progressData, self::CACHE_EXPIRATION);

        if (!$result) {
            \NTWP\Core\Logger::error_log(
                sprintf('创建任务进度跟踪失败: %s', $taskId),
                'Progress Tracker'
            );
        }

        return $result;
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
     * 更新当前步骤
     *
     * @param string $taskId 任务ID
     * @param string $currentStep 当前步骤ID
     * @param array $stepData 步骤数据
     * @return bool 是否更新成功
     */
    public function updateCurrentStep(string $taskId, string $currentStep, array $stepData = []): bool {
        $taskData = $this->getTaskData($taskId);
        if ($taskData === null) {
            return false;
        }

        // 更新当前步骤
        $updateData = [
            'currentStep' => $currentStep,
            'stepUpdatedAt' => time()
        ];

        // 如果有步骤数据，也更新
        if (!empty($stepData)) {
            $currentSteps = $taskData['steps'] ?? [];
            $currentSteps[$currentStep] = array_merge(
                $currentSteps[$currentStep] ?? [],
                $stepData,
                ['updatedAt' => time()]
            );
            $updateData['steps'] = $currentSteps;
        }

        return $this->updateTask($taskId, $updateData);
    }



    /**
     * 更新时间信息
     *
     * @param string $taskId 任务ID
     * @param array $timingUpdate 时间更新数据
     * @return bool 是否更新成功
     */
    public function updateTiming(string $taskId, array $timingUpdate): bool {
        $taskData = $this->getTaskData($taskId);
        if ($taskData === null) {
            return false;
        }

        $currentTiming = $taskData['timing'] ?? [];
        $newTiming = array_merge($currentTiming, $timingUpdate);

        // 计算已用时间
        if (isset($newTiming['startTime'])) {
            $newTiming['elapsedTime'] = (time() - $newTiming['startTime']) * 1000; // 转换为毫秒
        }

        return $this->updateTask($taskId, ['timing' => $newTiming]);
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
            'currentStep' => $taskData['currentStep'] ?? 'validate',
            'steps' => $taskData['steps'] ?? [],
            'timing' => $taskData['timing'] ?? [],
            'metadata' => $taskData['metadata'] ?? [],
            'created_at' => $taskData['created_at'] ?? 0,
            'updated_at' => $taskData['updated_at'] ?? 0,
            'errors' => $taskData['errors'] ?? []
        ];
    }
    
    /**
     * 获取活跃任务列表（已废弃 - 内存存储无法枚举）
     *
     * @deprecated 2.0.0 内存存储模式下无法枚举所有任务
     * @return array 活跃任务列表
     */
    public function getActiveTasks(): array {
        // 内存存储模式下无法枚举所有transient，返回空数组
        return [];
    }
    
    /**
     * 删除任务
     *
     * @param string $taskId 任务ID
     * @return bool 是否删除成功
     */
    public function deleteTask(string $taskId): bool {
        $cacheKey = self::CACHE_PREFIX . $taskId;
        return delete_transient($cacheKey);
    }
    
    /**
     * 清理过期任务（已废弃 - 内存存储自动过期）
     *
     * @deprecated 2.0.0 使用内存存储时自动过期，无需手动清理
     * @param int $maxAge 最大保留时间（秒），默认7天
     * @return int 清理的任务数量
     */
    public function cleanupExpiredTasks(int $maxAge = 604800): int {
        // 内存存储模式下，transient会自动过期，无需手动清理
        return 0;
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
     * 获取任务数据
     *
     * @param string $taskId 任务ID
     * @return array|null 任务数据，如果不存在返回null
     */
    public function getTask(string $taskId): ?array {
        return $this->getTaskData($taskId);
    }

    /**
     * 获取失败的任务列表（已废弃 - 内存存储无法枚举）
     *
     * @deprecated 2.0.0 内存存储模式下无法枚举所有任务
     * @return array 失败任务列表
     */
    public function getFailedTasks(): array {
        // 内存存储模式下无法枚举所有transient，返回空数组
        // 在实际应用中，应该维护一个失败任务的索引
        return [];
    }

    /**
     * 增加重试计数
     *
     * @param string $taskId 任务ID
     * @return bool 是否更新成功
     */
    public function incrementRetryCount(string $taskId): bool {
        $taskData = $this->getTaskData($taskId);
        if ($taskData === null) {
            return false;
        }

        $metadata = $taskData['metadata'] ?? [];
        $metadata['retry_count'] = ($metadata['retry_count'] ?? 0) + 1;
        $metadata['last_retry_at'] = time();

        return $this->updateTask($taskId, ['metadata' => $metadata]);
    }

    /**
     * 获取系统统计信息（已废弃 - 内存存储无法枚举）
     *
     * @deprecated 2.0.0 内存存储模式下无法枚举所有任务
     * @return array 统计信息
     */
    public function getStatistics(): array {
        // 内存存储模式下无法枚举所有transient，返回基础统计信息
        return [
            'total_tasks' => 0,
            'pending' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'note' => '内存存储模式下无法统计活跃任务数量'
        ];
    }
    
    // 移除了文件存储相关的辅助方法
    
    /**
     * 获取任务数据
     */
    private function getTaskData(string $taskId): ?array {
        // 从内存缓存获取
        $cacheKey = self::CACHE_PREFIX . $taskId;
        $data = get_transient($cacheKey);
        return $data !== false ? $data : null;
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

        // 使用内存存储
        $cacheKey = self::CACHE_PREFIX . $taskId;
        return set_transient($cacheKey, $taskData, self::CACHE_EXPIRATION);
    }
}
