<?php
declare(strict_types=1);

namespace NTWP\Utils;

use NTWP\Infrastructure\DatabaseManager;
use NTWP\Core\Logger;

/**
 * Database_Index_Manager向后兼容适配器
 * 
 * 将旧的Database_Index_Manager调用适配到新的DatabaseManager
 * 
 * @deprecated 使用 NTWP\Infrastructure\DatabaseManager 替代
 */
class Database_Index_Manager {
    
    /**
     * 推荐的索引配置 - 保持兼容性
     */
    const RECOMMENDED_INDEXES = [
        'notion_meta_page_id' => [
            'table' => 'postmeta',
            'columns' => ['meta_key', 'meta_value(191)', 'post_id'],
            'description' => '优化 _notion_page_id 查询，提升50%性能'
        ],
        'notion_meta_sync_time' => [
            'table' => 'postmeta', 
            'columns' => ['meta_key', 'post_id', 'meta_value(20)'],
            'description' => '优化同步时间查询，提升40%性能'
        ],
        'notion_posts_status_type' => [
            'table' => 'posts',
            'columns' => ['post_type', 'post_status', 'ID'],
            'description' => '优化文章状态查询，提升30%性能'
        ]
    ];
    
    /**
     * 检查索引是否存在
     * 
     * @deprecated 使用 DatabaseManager::index_exists() 替代
     */
    public static function index_exists(string $table_name, string $index_name): bool {
        Logger::debug_log('使用Database_Index_Manager适配器 - index_exists', 'Database Adapter');
        return DatabaseManager::index_exists($table_name, $index_name);
    }
    
    /**
     * 创建索引
     * 
     * @deprecated 使用 DatabaseManager::create_index() 替代
     */
    public static function create_index(string $table_name, string $index_name, array $columns, string $description = ''): bool {
        Logger::debug_log('使用Database_Index_Manager适配器 - create_index', 'Database Adapter');
        return DatabaseManager::create_index($table_name, $index_name, $columns, $description);
    }
    
    /**
     * 删除索引
     * 
     * @deprecated 使用 DatabaseManager::drop_index() 替代
     */
    public static function drop_index(string $table_name, string $index_name): bool {
        Logger::debug_log('使用Database_Index_Manager适配器 - drop_index', 'Database Adapter');
        return DatabaseManager::drop_index($table_name, $index_name);
    }
    
    /**
     * 创建所有推荐索引
     * 
     * @deprecated 使用 DatabaseManager::create_all_recommended_indexes() 替代
     */
    public static function create_all_indexes(): array {
        Logger::debug_log('使用Database_Index_Manager适配器 - create_all_indexes', 'Database Adapter');
        
        $result = DatabaseManager::create_all_recommended_indexes();
        
        // 转换结果格式以保持兼容性
        return [
            'total' => $result['created'] + $result['skipped'] + $result['failed'],
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'details' => $result['details']
        ];
    }
    
    /**
     * 删除所有索引
     * 
     * @deprecated 使用 DatabaseManager::drop_index() 逐个删除
     */
    public static function drop_all_indexes(): array {
        Logger::debug_log('使用Database_Index_Manager适配器 - drop_all_indexes', 'Database Adapter');
        
        $stats = [
            'total' => 0,
            'dropped' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        foreach (self::RECOMMENDED_INDEXES as $index_name => $config) {
            $full_index_name = 'idx_ntwp_' . $index_name;
            $stats['total']++;
            
            $success = DatabaseManager::drop_index($config['table'], $full_index_name);
            
            if ($success) {
                if (DatabaseManager::index_exists($config['table'], $full_index_name)) {
                    $stats['skipped']++;
                    $stats['details'][$index_name] = 'skipped (not exists)';
                } else {
                    $stats['dropped']++;
                    $stats['details'][$index_name] = 'dropped';
                }
            } else {
                $stats['failed']++;
                $stats['details'][$index_name] = 'failed';
            }
        }
        
        Logger::info_log(
            sprintf('索引删除完成: 总计%d个，删除%d个，跳过%d个，失败%d个',
                $stats['total'], $stats['dropped'], $stats['skipped'], $stats['failed']),
            'Database Index Manager Adapter'
        );
        
        return $stats;
    }
    
    /**
     * 获取索引状态报告
     * 
     * @deprecated 使用 DatabaseManager 的相关方法
     */
    public static function get_index_status(): array {
        Logger::debug_log('使用Database_Index_Manager适配器 - get_index_status', 'Database Adapter');
        
        $status = [];
        
        foreach (self::RECOMMENDED_INDEXES as $index_name => $config) {
            $full_index_name = 'idx_ntwp_' . $index_name;
            $exists = DatabaseManager::index_exists($config['table'], $full_index_name);
            
            $status[$index_name] = [
                'exists' => $exists,
                'table' => $config['table'],
                'columns' => $config['columns'],
                'description' => $config['description']
            ];
        }
        
        return $status;
    }
    
    /**
     * 验证索引完整性
     * 
     * @deprecated 使用 DatabaseManager 的索引检查方法
     */
    public static function verify_index_integrity(): array {
        Logger::debug_log('使用Database_Index_Manager适配器 - verify_index_integrity', 'Database Adapter');
        
        $verification = [
            'total_indexes' => 0,
            'valid_indexes' => 0,
            'invalid_indexes' => 0,
            'missing_indexes' => 0,
            'details' => []
        ];
        
        foreach (self::RECOMMENDED_INDEXES as $index_name => $config) {
            $full_index_name = 'idx_ntwp_' . $index_name;
            $verification['total_indexes']++;
            
            if (DatabaseManager::index_exists($config['table'], $full_index_name)) {
                $verification['valid_indexes']++;
                $verification['details'][$index_name] = 'valid';
            } else {
                $verification['missing_indexes']++;
                $verification['details'][$index_name] = 'missing';
            }
        }
        
        return $verification;
    }
    
    /**
     * 获取索引使用统计
     * 
     * @deprecated 使用 DatabaseManager::get_table_statistics() 替代
     */
    public static function get_index_usage_stats(): array {
        Logger::debug_log('使用Database_Index_Manager适配器 - get_index_usage_stats', 'Database Adapter');
        
        // 简化实现，返回基本统计
        $table_stats = DatabaseManager::get_table_statistics();
        
        return [
            'postmeta_table_size' => $table_stats['postmeta_size'],
            'postmeta_rows' => $table_stats['postmeta_rows'],
            'posts_table_size' => $table_stats['posts_size'],
            'posts_rows' => $table_stats['posts_rows'],
            'estimated_index_benefit' => '20-30% 查询性能提升'
        ];
    }
    
    /**
     * 生成索引报告
     * 
     * @deprecated 使用 DatabaseManager 的相关方法
     */
    public static function generate_index_report(): string {
        Logger::debug_log('使用Database_Index_Manager适配器 - generate_index_report', 'Database Adapter');
        
        $report = "=== 数据库索引状态报告 ===\n";
        
        $status = self::get_index_status();
        $verification = self::verify_index_integrity();
        
        $report .= sprintf("索引概览:\n");
        $report .= sprintf("- 总索引数: %d\n", $verification['total_indexes']);
        $report .= sprintf("- 有效索引: %d\n", $verification['valid_indexes']);
        $report .= sprintf("- 缺失索引: %d\n", $verification['missing_indexes']);
        
        $report .= sprintf("\n索引详情:\n");
        foreach ($status as $index_name => $info) {
            $status_text = $info['exists'] ? '✅ 已创建' : '❌ 缺失';
            $report .= sprintf("- %s: %s - %s\n", $index_name, $status_text, $info['description']);
        }
        
        if ($verification['missing_indexes'] > 0) {
            $report .= sprintf("\n建议:\n");
            $report .= sprintf("- 运行 create_all_indexes() 创建缺失的索引\n");
            $report .= sprintf("- 预计性能提升: 20-30%%\n");
        }
        
        return $report;
    }
    
    /**
     * 安全创建索引（带错误处理）
     * 
     * @deprecated 使用 DatabaseManager::create_index() 替代
     */
    public static function safe_create_index(string $table_name, string $index_name, array $columns, string $description = ''): array {
        Logger::debug_log('使用Database_Index_Manager适配器 - safe_create_index', 'Database Adapter');
        
        $result = [
            'success' => false,
            'created' => false,
            'error' => '',
            'index_name' => $index_name,
            'table_name' => $table_name
        ];
        
        try {
            if (DatabaseManager::index_exists($table_name, $index_name)) {
                $result['success'] = true;
                $result['created'] = false;
                $result['error'] = 'Index already exists';
            } else {
                $success = DatabaseManager::create_index($table_name, $index_name, $columns, $description);
                $result['success'] = $success;
                $result['created'] = $success;
                
                if (!$success) {
                    $result['error'] = 'Failed to create index';
                }
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            Logger::error_log("安全创建索引失败: {$index_name} - " . $e->getMessage(), 'Database Index Manager Adapter');
        }
        
        return $result;
    }
    
    /**
     * 批量创建索引
     * 
     * @deprecated 使用 DatabaseManager::create_all_recommended_indexes() 替代
     */
    public static function batch_create_indexes(array $indexes): array {
        Logger::debug_log('使用Database_Index_Manager适配器 - batch_create_indexes', 'Database Adapter');
        
        $results = [
            'total' => count($indexes),
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        foreach ($indexes as $index_name => $config) {
            $result = self::safe_create_index(
                $config['table'],
                $index_name,
                $config['columns'],
                $config['description'] ?? ''
            );
            
            $results['details'][$index_name] = $result;
            
            if ($result['success']) {
                if ($result['created']) {
                    $results['created']++;
                } else {
                    $results['skipped']++;
                }
            } else {
                $results['failed']++;
            }
        }
        
        return $results;
    }
}
