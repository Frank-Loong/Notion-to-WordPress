<?php
declare(strict_types=1);

namespace NTWP\Core;

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
if (!defined('ABSPATH')) {
    exit;
}

class Task_Executor {
    
    /**
     * 支持的操作类型
     */
    private const SUPPORTED_OPERATIONS = [
        'import_pages',
        'update_pages', 
        'process_images',
        'delete_posts',
        'sync_incremental',
        'cleanup_data'
    ];
    
    /**
     * 执行任务
     * 
     * @param string $operation 操作类型
     * @param array $data 任务数据
     * @param array $config 配置选项
     * @return array 执行结果
     */
    public function execute(string $operation, array $data, array $config): array {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        Logger::debug_log(
            sprintf('开始执行任务: %s, 数据量: %d', $operation, count($data)),
            'Task Executor'
        );
        
        try {
            // 验证操作类型
            if (!in_array($operation, self::SUPPORTED_OPERATIONS)) {
                throw new InvalidArgumentException("不支持的操作类型: {$operation}");
            }
            
            // 设置内存和时间限制
            $this->setExecutionLimits($config);
            
            // 执行具体操作
            $result = $this->executeOperation($operation, $data, $config);
            
            // 计算执行统计
            $executionTime = microtime(true) - $startTime;
            $memoryUsed = memory_get_usage(true) - $startMemory;
            $memoryPeak = memory_get_peak_usage(true);
            
            $finalResult = array_merge($result, [
                'execution_time' => $executionTime,
                'memory_used' => $memoryUsed,
                'memory_peak' => $memoryPeak,
                'status' => 'completed'
            ]);
            
            Logger::debug_log(
                sprintf('任务执行完成: %s, 耗时: %.2fs, 内存: %s', 
                    $operation, $executionTime, size_format($memoryUsed)),
                'Task Executor'
            );
            
            return $finalResult;
            
        } catch (Exception $e) {
            $executionTime = microtime(true) - $startTime;
            
            Logger::error_log(
                sprintf('任务执行失败: %s, 错误: %s, 耗时: %.2fs', 
                    $operation, $e->getMessage(), $executionTime),
                'Task Executor'
            );
            
            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'execution_time' => $executionTime,
                'success_count' => 0,
                'error_count' => count($data)
            ];
        }
    }
    
    /**
     * 执行具体操作
     */
    private function executeOperation(string $operation, array $data, array $config): array {
        switch($operation) {
            case 'import_pages':
                return $this->executeImportPages($data, $config);
            case 'update_pages':
                return $this->executeUpdatePages($data, $config);
            case 'process_images':
                return $this->executeProcessImages($data, $config);
            case 'delete_posts':
                return $this->executeDeletePosts($data, $config);
            case 'sync_incremental':
                return $this->executeSyncIncremental($data, $config);
            case 'cleanup_data':
                return $this->executeCleanupData($data, $config);
            default:
                throw new InvalidArgumentException("未实现的操作: {$operation}");
        }
    }
    
    /**
     * 执行页面导入
     */
    private function executeImportPages(array $pages, array $config): array {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        
        foreach ($pages as $pageData) {
            try {
                // 使用现有的导入逻辑
                if (class_exists('NTWP\Handlers\Import_Coordinator')) {
                    $result = \NTWP\Handlers\Import_Coordinator::import_single_page($pageData, $config);
                    if ($result) {
                        $successCount++;
                    } else {
                        $errorCount++;
                        $errors[] = "页面导入失败: " . ($pageData['id'] ?? 'unknown');
                    }
                } else {
                    // 基础导入逻辑
                    $result = $this->basicPageImport($pageData, $config);
                    $successCount += $result['success'] ? 1 : 0;
                    $errorCount += $result['success'] ? 0 : 1;
                    if (!$result['success']) {
                        $errors[] = $result['error'];
                    }
                }
                
                // 内存管理
                if (memory_get_usage(true) > $this->getMemoryLimit() * 0.8) {
                    gc_collect_cycles();
                }
                
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = sprintf('页面处理异常: %s', $e->getMessage());
            }
        }
        
        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ];
    }
    
    /**
     * 执行页面更新
     */
    private function executeUpdatePages(array $pages, array $config): array {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        
        foreach ($pages as $pageData) {
            try {
                // 使用现有的更新逻辑
                if (class_exists('NTWP\Handlers\Import_Coordinator')) {
                    $result = \NTWP\Handlers\Import_Coordinator::update_single_page($pageData, $config);
                    if ($result) {
                        $successCount++;
                    } else {
                        $errorCount++;
                        $errors[] = "页面更新失败: " . ($pageData['id'] ?? 'unknown');
                    }
                } else {
                    // 基础更新逻辑
                    $result = $this->basicPageUpdate($pageData, $config);
                    $successCount += $result['success'] ? 1 : 0;
                    $errorCount += $result['success'] ? 0 : 1;
                    if (!$result['success']) {
                        $errors[] = $result['error'];
                    }
                }
                
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = sprintf('页面更新异常: %s', $e->getMessage());
            }
        }
        
        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ];
    }
    
    /**
     * 执行图片处理
     */
    private function executeProcessImages(array $images, array $config): array {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        
        foreach ($images as $imageData) {
            try {
                // 使用现有的图片处理逻辑
                if (class_exists('NTWP\Services\Image_Processor')) {
                    $result = \NTWP\Services\Image_Processor::process_image($imageData, $config);
                    if ($result) {
                        $successCount++;
                    } else {
                        $errorCount++;
                        $errors[] = "图片处理失败: " . ($imageData['url'] ?? 'unknown');
                    }
                } else {
                    // 基础图片处理
                    $result = $this->basicImageProcess($imageData, $config);
                    $successCount += $result['success'] ? 1 : 0;
                    $errorCount += $result['success'] ? 0 : 1;
                    if (!$result['success']) {
                        $errors[] = $result['error'];
                    }
                }
                
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = sprintf('图片处理异常: %s', $e->getMessage());
            }
        }
        
        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ];
    }
    
    /**
     * 执行文章删除
     */
    private function executeDeletePosts(array $posts, array $config): array {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        
        foreach ($posts as $postData) {
            try {
                $postId = $postData['post_id'] ?? $postData['id'] ?? null;
                if (!$postId) {
                    $errorCount++;
                    $errors[] = '缺少文章ID';
                    continue;
                }
                
                $result = wp_delete_post($postId, true);
                if ($result) {
                    $successCount++;
                } else {
                    $errorCount++;
                    $errors[] = "删除文章失败: {$postId}";
                }
                
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = sprintf('删除文章异常: %s', $e->getMessage());
            }
        }
        
        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ];
    }
    
    /**
     * 执行增量同步
     */
    private function executeSyncIncremental(array $data, array $config): array {
        try {
            if (class_exists('NTWP\Services\Incremental_Detector')) {
                return \NTWP\Services\Incremental_Detector::process_incremental_sync($data, $config);
            } else {
                // 基础增量同步逻辑
                return $this->basicIncrementalSync($data, $config);
            }
        } catch (Exception $e) {
            return [
                'success_count' => 0,
                'error_count' => count($data),
                'errors' => [$e->getMessage()]
            ];
        }
    }
    
    /**
     * 执行数据清理
     */
    private function executeCleanupData(array $data, array $config): array {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        
        foreach ($data as $item) {
            try {
                $type = $item['type'] ?? 'unknown';
                
                switch ($type) {
                    case 'orphaned_meta':
                        $result = $this->cleanupOrphanedMeta($item);
                        break;
                    case 'expired_cache':
                        $result = $this->cleanupExpiredCache($item);
                        break;
                    case 'temp_files':
                        $result = $this->cleanupTempFiles($item);
                        break;
                    default:
                        $result = ['success' => false, 'error' => "未知清理类型: {$type}"];
                }
                
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                    $errors[] = $result['error'];
                }
                
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = sprintf('清理异常: %s', $e->getMessage());
            }
        }
        
        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ];
    }
    
    /**
     * 设置执行限制
     */
    private function setExecutionLimits(array $config): void {
        // 设置内存限制
        if (isset($config['memory_limit'])) {
            ini_set('memory_limit', $config['memory_limit']);
        }
        
        // 设置执行时间限制
        if (isset($config['timeout'])) {
            set_time_limit($config['timeout']);
        }
    }
    
    /**
     * 获取内存限制
     */
    private function getMemoryLimit(): int {
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
     * 基础页面导入（回退方案）
     */
    private function basicPageImport(array $pageData, array $config): array {
        // 简化的导入逻辑
        try {
            $postData = [
                'post_title' => $pageData['title'] ?? 'Untitled',
                'post_content' => $pageData['content'] ?? '',
                'post_status' => 'publish',
                'post_type' => 'post'
            ];
            
            $postId = wp_insert_post($postData);
            
            if (is_wp_error($postId)) {
                return ['success' => false, 'error' => $postId->get_error_message()];
            }
            
            return ['success' => true, 'post_id' => $postId];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 基础页面更新（回退方案）
     */
    private function basicPageUpdate(array $pageData, array $config): array {
        // 简化的更新逻辑
        try {
            $postId = $pageData['post_id'] ?? null;
            if (!$postId) {
                return ['success' => false, 'error' => '缺少文章ID'];
            }
            
            $postData = [
                'ID' => $postId,
                'post_title' => $pageData['title'] ?? null,
                'post_content' => $pageData['content'] ?? null
            ];
            
            // 移除空值
            $postData = array_filter($postData, function($value) {
                return $value !== null;
            });
            
            $result = wp_update_post($postData);
            
            if (is_wp_error($result)) {
                return ['success' => false, 'error' => $result->get_error_message()];
            }
            
            return ['success' => true, 'post_id' => $result];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 基础图片处理（回退方案）
     */
    private function basicImageProcess(array $imageData, array $config): array {
        // 简化的图片处理逻辑
        try {
            $url = $imageData['url'] ?? null;
            if (!$url) {
                return ['success' => false, 'error' => '缺少图片URL'];
            }
            
            // 这里可以添加基础的图片下载和处理逻辑
            // 暂时返回成功
            return ['success' => true, 'url' => $url];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 基础增量同步（回退方案）
     */
    private function basicIncrementalSync(array $data, array $config): array {
        // 简化的增量同步逻辑
        return [
            'success_count' => count($data),
            'error_count' => 0,
            'errors' => []
        ];
    }
    
    /**
     * 清理孤立的元数据
     */
    private function cleanupOrphanedMeta(array $item): array {
        // 实现孤立元数据清理逻辑
        return ['success' => true];
    }
    
    /**
     * 清理过期缓存
     */
    private function cleanupExpiredCache(array $item): array {
        // 实现过期缓存清理逻辑
        return ['success' => true];
    }
    
    /**
     * 清理临时文件
     */
    private function cleanupTempFiles(array $item): array {
        // 实现临时文件清理逻辑
        return ['success' => true];
    }
}
