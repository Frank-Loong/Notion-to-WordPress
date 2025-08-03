<?php
declare(strict_types=1);

namespace NTWP\Utils;

use NTWP\Infrastructure\DatabaseManager;
use NTWP\Core\Logger;

/**
 * Database_Helper向后兼容适配器
 * 
 * 将旧的Database_Helper调用适配到新的DatabaseManager
 * 
 * @deprecated 使用 NTWP\Infrastructure\DatabaseManager 替代
 */
class Database_Helper {
    
    /**
     * 批量获取多个Notion页面ID对应的WordPress文章ID
     * 
     * @deprecated 使用 DatabaseManager::batch_get_posts_by_notion_ids() 替代
     */
    public static function batch_get_posts_by_notion_ids(array $notion_ids): array {
        Logger::debug_log('使用Database_Helper适配器 - batch_get_posts_by_notion_ids', 'Database Adapter');
        return DatabaseManager::batch_get_posts_by_notion_ids($notion_ids);
    }
    
    /**
     * 批量获取文章元数据
     * 
     * @deprecated 使用 DatabaseManager::batch_get_post_meta() 替代
     */
    public static function batch_get_post_meta(array $post_ids, string $meta_key = ''): array {
        Logger::debug_log('使用Database_Helper适配器 - batch_get_post_meta', 'Database Adapter');
        return DatabaseManager::batch_get_post_meta($post_ids, $meta_key);
    }
    
    /**
     * 批量更新文章元数据
     * 
     * @deprecated 使用 DatabaseManager::batch_update_post_meta() 替代
     */
    public static function batch_update_post_meta(array $updates): array {
        Logger::debug_log('使用Database_Helper适配器 - batch_update_post_meta', 'Database Adapter');
        return DatabaseManager::batch_update_post_meta($updates);
    }
    
    /**
     * 获取优化建议
     * 
     * @deprecated 使用 DatabaseManager::get_optimization_suggestions() 替代
     */
    public static function get_optimization_suggestions(): array {
        Logger::debug_log('使用Database_Helper适配器 - get_optimization_suggestions', 'Database Adapter');
        return DatabaseManager::get_optimization_suggestions();
    }
    
    /**
     * 创建性能优化索引
     * 
     * @deprecated 使用 DatabaseManager::create_all_recommended_indexes() 替代
     */
    public static function create_performance_indexes(): array {
        Logger::debug_log('使用Database_Helper适配器 - create_performance_indexes', 'Database Adapter');
        $result = DatabaseManager::create_all_recommended_indexes();
        
        // 转换结果格式以保持兼容性
        return [
            'success' => $result['failed'] === 0,
            'created_indexes' => array_keys(array_filter($result['details'], fn($detail) => $detail['success'])),
            'skipped_indexes' => array_keys(array_filter($result['details'], fn($detail) => !$detail['success'])),
            'errors' => [],
            'performance_improvement' => $result['created'] > 0 ? 25 : 0 // 估算性能提升
        ];
    }
    
    /**
     * 获取查询统计
     * 
     * @deprecated 使用 DatabaseManager::get_query_statistics() 替代
     */
    public static function get_query_statistics(): array {
        Logger::debug_log('使用Database_Helper适配器 - get_query_statistics', 'Database Adapter');
        return DatabaseManager::get_query_statistics();
    }
    
    /**
     * 清理缓存
     * 
     * @deprecated 使用 DatabaseManager::clear_cache() 替代
     */
    public static function clear_cache(string $cache_type = ''): void {
        Logger::debug_log('使用Database_Helper适配器 - clear_cache', 'Database Adapter');
        DatabaseManager::clear_cache($cache_type);
    }
    
    /**
     * 获取缓存数据
     * 
     * @deprecated 使用 DatabaseManager 的内部缓存机制
     */
    public static function get_cached_data(string $cache_type, $key) {
        Logger::debug_log('使用Database_Helper适配器 - get_cached_data (已弃用)', 'Database Adapter');
        // 返回null，让调用者重新查询
        return null;
    }
    
    /**
     * 设置缓存数据
     * 
     * @deprecated 使用 DatabaseManager 的内部缓存机制
     */
    public static function set_cached_data(string $cache_type, $key, $value): void {
        Logger::debug_log('使用Database_Helper适配器 - set_cached_data (已弃用)', 'Database Adapter');
        // 空实现，DatabaseManager内部自动处理缓存
    }
    
    /**
     * 数据预加载
     * 
     * @deprecated 使用 DatabaseManager 的批量查询方法
     */
    public static function preload_data(array $context): bool {
        Logger::debug_log('使用Database_Helper适配器 - preload_data', 'Database Adapter');
        
        try {
            // 预加载WordPress文章元数据
            if (!empty($context['post_ids'])) {
                DatabaseManager::batch_get_post_meta($context['post_ids']);
            }
            
            // 预加载Notion页面关联数据
            if (!empty($context['notion_ids'])) {
                DatabaseManager::batch_get_posts_by_notion_ids($context['notion_ids']);
            }
            
            return true;
        } catch (\Exception $e) {
            Logger::error_log('数据预加载失败: ' . $e->getMessage(), 'Database Adapter');
            return false;
        }
    }
    
    /**
     * 生成优化报告
     * 
     * @deprecated 使用 DatabaseManager 的相关方法
     */
    public static function generate_optimization_report(): string {
        Logger::debug_log('使用Database_Helper适配器 - generate_optimization_report', 'Database Adapter');
        
        $report = "=== 数据库查询优化报告 ===\n";
        
        // 获取统计信息
        $stats = DatabaseManager::get_query_statistics();
        $table_stats = DatabaseManager::get_table_statistics();
        $suggestions = DatabaseManager::get_optimization_suggestions();
        
        $report .= sprintf("查询统计:\n");
        $report .= sprintf("- 总查询数: %d\n", $stats['total_queries']);
        $report .= sprintf("- 平均查询时间: %.4f 秒\n", $stats['average_time']);
        $report .= sprintf("- 缓存命中率: %.1f%%\n", $stats['cache_hit_rate']);
        
        $report .= sprintf("\n表统计:\n");
        $report .= sprintf("- postmeta表大小: %.2fMB\n", $table_stats['postmeta_size'] / 1024 / 1024);
        $report .= sprintf("- postmeta行数: %d\n", $table_stats['postmeta_rows']);
        $report .= sprintf("- posts表大小: %.2fMB\n", $table_stats['posts_size'] / 1024 / 1024);
        $report .= sprintf("- posts行数: %d\n", $table_stats['posts_rows']);
        
        $report .= sprintf("\n优化建议:\n");
        foreach ($suggestions as $suggestion) {
            $report .= sprintf("- [%s] %s\n", strtoupper($suggestion['priority']), $suggestion['description']);
        }
        
        return $report;
    }
    
    /**
     * 获取Notion特定的优化建议
     * 
     * @deprecated 使用 DatabaseManager::get_optimization_suggestions() 替代
     */
    public static function get_notion_specific_optimization_suggestions(): array {
        Logger::debug_log('使用Database_Helper适配器 - get_notion_specific_optimization_suggestions', 'Database Adapter');
        
        $suggestions = DatabaseManager::get_optimization_suggestions();
        $notion_suggestions = [];
        
        foreach ($suggestions as $suggestion) {
            if ($suggestion['type'] === 'missing_index') {
                $notion_suggestions[] = "🔥 高优先级：" . $suggestion['description'];
            }
        }
        
        if (empty($notion_suggestions)) {
            $notion_suggestions[] = "✅ 所有推荐的Notion优化索引已创建";
        }
        
        return $notion_suggestions;
    }
    
    /**
     * 检测慢查询
     * 
     * @deprecated 使用 DatabaseManager::analyze_query_performance() 替代
     */
    public static function detect_slow_notion_queries(): array {
        Logger::debug_log('使用Database_Helper适配器 - detect_slow_notion_queries', 'Database Adapter');
        
        // 简化实现，返回空数组
        // 实际的慢查询检测需要更复杂的实现
        return [];
    }
    
    /**
     * 测量查询性能
     * 
     * @deprecated 使用 DatabaseManager::analyze_query_performance() 替代
     */
    public static function measure_query_performance(): array {
        Logger::debug_log('使用Database_Helper适配器 - measure_query_performance', 'Database Adapter');
        
        $stats = DatabaseManager::get_query_statistics();
        
        return [
            'average_time' => $stats['average_time'],
            'total_queries' => $stats['total_queries'],
            'cache_hit_rate' => $stats['cache_hit_rate']
        ];
    }
    
    /**
     * 计算性能改进
     * 
     * @deprecated 使用 DatabaseManager 的内部性能监控
     */
    public static function calculate_performance_improvement(array $before, array $after): float {
        Logger::debug_log('使用Database_Helper适配器 - calculate_performance_improvement', 'Database Adapter');
        
        if ($before['average_time'] > 0 && $after['average_time'] > 0) {
            return (($before['average_time'] - $after['average_time']) / $before['average_time']) * 100;
        }
        
        return 0.0;
    }
    
    /**
     * 获取索引状态
     * 
     * @deprecated 使用 DatabaseManager::index_exists() 替代
     */
    public static function get_index_status(): array {
        Logger::debug_log('使用Database_Helper适配器 - get_index_status', 'Database Adapter');
        
        $status = [];
        
        foreach (DatabaseManager::RECOMMENDED_INDEXES as $index_name => $config) {
            $full_index_name = 'idx_ntwp_' . $index_name;
            $status[$index_name . '_index'] = DatabaseManager::index_exists($config['table'], $full_index_name);
        }
        
        $table_stats = DatabaseManager::get_table_statistics();
        $status['total_indexes'] = count(array_filter($status));
        $status['table_size'] = $table_stats['postmeta_size'];
        
        return $status;
    }
}
