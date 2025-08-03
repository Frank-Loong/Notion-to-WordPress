<?php
declare(strict_types=1);

namespace NTWP\Utils;

use NTWP\Infrastructure\DatabaseManager;
use NTWP\Core\Logger;

/**
 * Database_Index_Optimizer向后兼容适配器
 * 
 * 将旧的Database_Index_Optimizer调用适配到新的DatabaseManager
 * 
 * @deprecated 使用 NTWP\Infrastructure\DatabaseManager 替代
 */
class Database_Index_Optimizer {
    
    /**
     * 分析查询性能
     * 
     * @deprecated 使用 DatabaseManager::analyze_query_performance() 替代
     */
    public static function analyze_query_performance(string $query): array {
        Logger::debug_log('使用Database_Index_Optimizer适配器 - analyze_query_performance', 'Database Adapter');
        return DatabaseManager::analyze_query_performance($query);
    }
    
    /**
     * 创建所有索引
     * 
     * @deprecated 使用 DatabaseManager::create_all_recommended_indexes() 替代
     */
    public static function create_all_indexes(): array {
        Logger::debug_log('使用Database_Index_Optimizer适配器 - create_all_indexes', 'Database Adapter');
        
        $result = DatabaseManager::create_all_recommended_indexes();
        
        // 转换结果格式以保持兼容性
        return [
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'details' => $result['details']
        ];
    }
    
    /**
     * 创建单个索引
     * 
     * @deprecated 使用 DatabaseManager::create_index() 替代
     */
    public static function create_index(string $index_name, array $config): array {
        Logger::debug_log('使用Database_Index_Optimizer适配器 - create_index', 'Database Adapter');
        
        $result = [
            'success' => false,
            'created' => false,
            'error' => '',
            'index_name' => $index_name
        ];
        
        try {
            $full_index_name = 'idx_ntwp_' . $index_name;
            
            if (DatabaseManager::index_exists($config['table'], $full_index_name)) {
                $result['success'] = true;
                $result['created'] = false;
                $result['error'] = 'Index already exists';
            } else {
                $success = DatabaseManager::create_index(
                    $config['table'],
                    $full_index_name,
                    $config['columns'],
                    $config['description'] ?? ''
                );
                
                $result['success'] = $success;
                $result['created'] = $success;
                
                if (!$success) {
                    $result['error'] = 'Failed to create index';
                }
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            Logger::error_log("创建索引失败: {$index_name} - " . $e->getMessage(), 'Database Index Optimizer Adapter');
        }
        
        return $result;
    }
    
    /**
     * 获取查询优化建议
     * 
     * @deprecated 使用 DatabaseManager::get_optimization_suggestions() 替代
     */
    public static function get_query_optimization_recommendations(): array {
        Logger::debug_log('使用Database_Index_Optimizer适配器 - get_query_optimization_recommendations', 'Database Adapter');
        
        $suggestions = DatabaseManager::get_optimization_suggestions();
        $recommendations = [];
        
        // 转换格式以保持兼容性
        foreach ($suggestions as $suggestion) {
            $recommendations[$suggestion['type']] = [
                'query_type' => $suggestion['type'],
                'current_performance' => 50, // 默认评分
                'analysis' => [
                    'description' => $suggestion['description'],
                    'priority' => $suggestion['priority']
                ],
                'suggested_indexes' => [$suggestion['action']]
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * 执行索引维护
     * 
     * @deprecated 使用 DatabaseManager 的相关方法
     */
    public static function perform_index_maintenance(): array {
        Logger::debug_log('使用Database_Index_Optimizer适配器 - perform_index_maintenance', 'Database Adapter');
        
        global $wpdb;
        
        $maintenance_results = [
            'analyzed' => 0,
            'optimized' => 0,
            'warnings' => []
        ];
        
        // 分析主要表
        $tables = [$wpdb->postmeta, $wpdb->posts];
        
        foreach ($tables as $table) {
            try {
                // 分析表
                $analyze_sql = "ANALYZE TABLE `{$table}`";
                $wpdb->query($analyze_sql);
                $maintenance_results['analyzed']++;
                
                // 优化表（仅对大表执行）
                $table_stats = DatabaseManager::get_table_statistics();
                $table_size = $table === $wpdb->postmeta ? $table_stats['postmeta_size'] : $table_stats['posts_size'];
                
                if ($table_size > 50 * 1024 * 1024) { // 50MB以上
                    $optimize_sql = "OPTIMIZE TABLE `{$table}`";
                    $wpdb->query($optimize_sql);
                    $maintenance_results['optimized']++;
                }
            } catch (\Exception $e) {
                $maintenance_results['warnings'][] = "表 {$table} 维护失败: " . $e->getMessage();
            }
        }
        
        Logger::info_log(
            sprintf('索引维护完成: 分析 %d 个表，优化 %d 个表',
                $maintenance_results['analyzed'], $maintenance_results['optimized']),
            'Database Index Optimizer Adapter'
        );
        
        return $maintenance_results;
    }
    
    /**
     * 获取性能基准测试
     * 
     * @deprecated 使用 DatabaseManager::get_query_statistics() 替代
     */
    public static function get_performance_benchmark(): array {
        Logger::debug_log('使用Database_Index_Optimizer适配器 - get_performance_benchmark', 'Database Adapter');
        
        $stats = DatabaseManager::get_query_statistics();
        $table_stats = DatabaseManager::get_table_statistics();
        
        return [
            'query_performance' => [
                'average_time' => $stats['average_time'],
                'total_queries' => $stats['total_queries'],
                'cache_hit_rate' => $stats['cache_hit_rate']
            ],
            'table_stats' => [
                'postmeta_size' => $table_stats['postmeta_size'],
                'postmeta_rows' => $table_stats['postmeta_rows'],
                'posts_size' => $table_stats['posts_size'],
                'posts_rows' => $table_stats['posts_rows']
            ],
            'optimization_score' => self::calculate_optimization_score($stats, $table_stats)
        ];
    }
    
    /**
     * 计算优化评分
     */
    private static function calculate_optimization_score(array $query_stats, array $table_stats): int {
        $score = 100;
        
        // 基于查询性能扣分
        if ($query_stats['average_time'] > 0.5) {
            $score -= 30;
        } elseif ($query_stats['average_time'] > 0.1) {
            $score -= 15;
        }
        
        // 基于缓存命中率扣分
        if ($query_stats['cache_hit_rate'] < 50) {
            $score -= 20;
        } elseif ($query_stats['cache_hit_rate'] < 80) {
            $score -= 10;
        }
        
        // 基于表大小扣分
        if ($table_stats['postmeta_size'] > 200 * 1024 * 1024) { // 200MB
            $score -= 15;
        } elseif ($table_stats['postmeta_size'] > 100 * 1024 * 1024) { // 100MB
            $score -= 10;
        }
        
        return max(0, $score);
    }
    
    /**
     * 生成优化报告
     * 
     * @deprecated 使用 DatabaseManager 的相关方法
     */
    public static function generate_optimization_report(): string {
        Logger::debug_log('使用Database_Index_Optimizer适配器 - generate_optimization_report', 'Database Adapter');
        
        $report = "=== 数据库性能优化报告 ===\n";
        
        $benchmark = self::get_performance_benchmark();
        $suggestions = DatabaseManager::get_optimization_suggestions();
        
        $report .= sprintf("性能概览:\n");
        $report .= sprintf("- 优化评分: %d/100\n", $benchmark['optimization_score']);
        $report .= sprintf("- 平均查询时间: %.4f 秒\n", $benchmark['query_performance']['average_time']);
        $report .= sprintf("- 缓存命中率: %.1f%%\n", $benchmark['query_performance']['cache_hit_rate']);
        
        $report .= sprintf("\n表统计:\n");
        $report .= sprintf("- postmeta表: %.2fMB (%d 行)\n", 
            $benchmark['table_stats']['postmeta_size'] / 1024 / 1024,
            $benchmark['table_stats']['postmeta_rows']
        );
        $report .= sprintf("- posts表: %.2fMB (%d 行)\n",
            $benchmark['table_stats']['posts_size'] / 1024 / 1024,
            $benchmark['table_stats']['posts_rows']
        );
        
        $report .= sprintf("\n优化建议:\n");
        if (empty($suggestions)) {
            $report .= "- ✅ 所有推荐的优化已完成\n";
        } else {
            foreach ($suggestions as $suggestion) {
                $report .= sprintf("- [%s] %s\n", 
                    strtoupper($suggestion['priority']), 
                    $suggestion['description']
                );
            }
        }
        
        return $report;
    }
    
    /**
     * 检查索引是否存在
     * 
     * @deprecated 使用 DatabaseManager::index_exists() 替代
     */
    public static function index_exists(string $table_name, string $index_name): bool {
        Logger::debug_log('使用Database_Index_Optimizer适配器 - index_exists', 'Database Adapter');
        return DatabaseManager::index_exists($table_name, $index_name);
    }
    
    /**
     * 获取慢查询分析
     * 
     * @deprecated 使用 DatabaseManager::analyze_query_performance() 替代
     */
    public static function analyze_slow_queries(): array {
        Logger::debug_log('使用Database_Index_Optimizer适配器 - analyze_slow_queries', 'Database Adapter');
        
        // 简化实现，分析常见的Notion查询
        $common_queries = [
            'notion_id_lookup' => "SELECT post_id FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = '_notion_page_id' AND meta_value = 'sample_id'",
            'batch_meta_query' => "SELECT * FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id IN (1,2,3,4,5)"
        ];
        
        $analysis_results = [];
        
        foreach ($common_queries as $query_name => $query) {
            try {
                $analysis = DatabaseManager::analyze_query_performance($query);
                $analysis_results[$query_name] = [
                    'query' => $query,
                    'execution_time' => $analysis['execution_time'],
                    'performance_score' => $analysis['performance_score'],
                    'recommendations' => $analysis['recommendations']
                ];
            } catch (\Exception $e) {
                $analysis_results[$query_name] = [
                    'query' => $query,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $analysis_results;
    }
    
    /**
     * 执行性能测试
     * 
     * @deprecated 使用 DatabaseManager 的性能监控功能
     */
    public static function run_performance_test(): array {
        Logger::debug_log('使用Database_Index_Optimizer适配器 - run_performance_test', 'Database Adapter');
        
        $test_results = [
            'test_start_time' => microtime(true),
            'tests' => [],
            'summary' => []
        ];
        
        // 测试批量查询性能
        $test_notion_ids = ['test1', 'test2', 'test3', 'test4', 'test5'];
        $start_time = microtime(true);
        DatabaseManager::batch_get_posts_by_notion_ids($test_notion_ids);
        $batch_query_time = microtime(true) - $start_time;
        
        $test_results['tests']['batch_notion_lookup'] = [
            'description' => '批量Notion ID查询',
            'execution_time' => $batch_query_time,
            'performance_rating' => $batch_query_time < 0.1 ? 'excellent' : ($batch_query_time < 0.5 ? 'good' : 'needs_improvement')
        ];
        
        // 测试元数据查询性能
        $test_post_ids = [1, 2, 3, 4, 5];
        $start_time = microtime(true);
        DatabaseManager::batch_get_post_meta($test_post_ids);
        $meta_query_time = microtime(true) - $start_time;
        
        $test_results['tests']['batch_meta_query'] = [
            'description' => '批量元数据查询',
            'execution_time' => $meta_query_time,
            'performance_rating' => $meta_query_time < 0.05 ? 'excellent' : ($meta_query_time < 0.2 ? 'good' : 'needs_improvement')
        ];
        
        $test_results['test_end_time'] = microtime(true);
        $test_results['total_test_time'] = $test_results['test_end_time'] - $test_results['test_start_time'];
        
        $test_results['summary'] = [
            'total_tests' => count($test_results['tests']),
            'average_performance' => array_sum(array_column($test_results['tests'], 'execution_time')) / count($test_results['tests']),
            'overall_rating' => $test_results['summary']['average_performance'] ?? 0 < 0.1 ? 'excellent' : 'good'
        ];
        
        return $test_results;
    }
}
