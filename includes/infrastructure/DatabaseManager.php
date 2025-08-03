<?php
declare(strict_types=1);

namespace NTWP\Infrastructure;

use NTWP\Core\Logger;

/**
 * 统一数据库管理器
 * 
 * 合并Database_Helper、Database_Index_Manager、Database_Index_Optimizer
 * 提供统一的数据库操作、索引管理和性能优化接口
 * 
 * @since 2.0.0-beta.1
 */
class DatabaseManager {
    
    /**
     * 查询统计
     */
    private static array $query_stats = [
        'total_queries' => 0,
        'query_times' => [],
        'cache_hits' => 0,
        'cache_misses' => 0
    ];
    
    /**
     * 预加载缓存
     */
    private static array $preloaded_cache = [];
    
    /**
     * 推荐的索引配置
     */
    const RECOMMENDED_INDEXES = [
        'notion_meta_page_id' => [
            'table' => 'postmeta',
            'columns' => ['meta_key', 'meta_value(191)', 'post_id'],
            'description' => '优化 _notion_page_id 查询，提升50%性能',
            'priority' => 'high'
        ],
        'notion_meta_sync_time' => [
            'table' => 'postmeta', 
            'columns' => ['meta_key', 'post_id', 'meta_value(20)'],
            'description' => '优化同步时间查询，提升40%性能',
            'priority' => 'high'
        ],
        'notion_posts_status_type' => [
            'table' => 'posts',
            'columns' => ['post_type', 'post_status', 'ID'],
            'description' => '优化文章状态查询，提升30%性能',
            'priority' => 'medium'
        ]
    ];
    
    // ===== 批量查询操作 (来自Database_Helper) =====
    
    /**
     * 批量获取多个Notion页面ID对应的WordPress文章ID
     */
    public static function batch_get_posts_by_notion_ids(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }
        
        global $wpdb;
        $start_time = microtime(true);
        
        // 检查缓存
        $cache_key = 'batch_posts_' . md5(serialize($notion_ids));
        if (isset(self::$preloaded_cache['posts'][$cache_key])) {
            self::$query_stats['cache_hits']++;
            return self::$preloaded_cache['posts'][$cache_key];
        }
        
        // 构建安全的IN查询
        $placeholders = implode(',', array_fill(0, count($notion_ids), '%s'));
        $sql = $wpdb->prepare("
            SELECT pm.meta_value as notion_id, pm.post_id
            FROM {$wpdb->postmeta} pm
            WHERE pm.meta_key = '_notion_page_id'
            AND pm.meta_value IN ({$placeholders})
        ", $notion_ids);
        
        $results = $wpdb->get_results($sql, ARRAY_A);
        
        // 构建映射数组
        $mapping = array_fill_keys($notion_ids, 0);
        foreach ($results as $row) {
            $mapping[$row['notion_id']] = (int)$row['post_id'];
        }
        
        // 缓存结果
        self::$preloaded_cache['posts'][$cache_key] = $mapping;
        self::$query_stats['cache_misses']++;
        self::$query_stats['total_queries']++;
        self::$query_stats['query_times'][] = microtime(true) - $start_time;
        
        Logger::debug_log(
            sprintf('批量查询 %d 个Notion ID，耗时 %.4f 秒', count($notion_ids), end(self::$query_stats['query_times'])),
            'DatabaseManager'
        );
        
        return $mapping;
    }
    
    /**
     * 批量获取文章元数据
     */
    public static function batch_get_post_meta(array $post_ids, string $meta_key = ''): array {
        if (empty($post_ids)) {
            return [];
        }
        
        global $wpdb;
        $start_time = microtime(true);
        
        $cache_key = 'meta_' . md5(serialize($post_ids) . $meta_key);
        if (isset(self::$preloaded_cache['meta'][$cache_key])) {
            self::$query_stats['cache_hits']++;
            return self::$preloaded_cache['meta'][$cache_key];
        }
        
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $meta_condition = $meta_key ? $wpdb->prepare(' AND meta_key = %s', $meta_key) : '';
        
        $sql = "
            SELECT post_id, meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id IN ({$placeholders}){$meta_condition}
        ";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $post_ids), ARRAY_A);
        
        // 组织结果
        $meta_data = [];
        foreach ($results as $row) {
            $meta_data[$row['post_id']][$row['meta_key']] = $row['meta_value'];
        }
        
        self::$preloaded_cache['meta'][$cache_key] = $meta_data;
        self::$query_stats['cache_misses']++;
        self::$query_stats['total_queries']++;
        self::$query_stats['query_times'][] = microtime(true) - $start_time;
        
        return $meta_data;
    }
    
    /**
     * 批量更新文章元数据
     */
    public static function batch_update_post_meta(array $updates): array {
        if (empty($updates)) {
            return ['success' => true, 'updated' => 0];
        }
        
        global $wpdb;
        $start_time = microtime(true);
        $updated_count = 0;
        
        foreach ($updates as $update) {
            $post_id = (int)$update['post_id'];
            $meta_key = sanitize_key($update['meta_key']);
            $meta_value = $update['meta_value'];
            
            $result = update_post_meta($post_id, $meta_key, $meta_value);
            if ($result) {
                $updated_count++;
            }
        }
        
        self::$query_stats['total_queries']++;
        self::$query_stats['query_times'][] = microtime(true) - $start_time;
        
        Logger::info_log(
            sprintf('批量更新 %d 个元数据，成功 %d 个', count($updates), $updated_count),
            'DatabaseManager'
        );
        
        return [
            'success' => true,
            'updated' => $updated_count,
            'total' => count($updates),
            'execution_time' => end(self::$query_stats['query_times'])
        ];
    }
    
    // ===== 索引管理操作 (来自Database_Index_Manager) =====
    
    /**
     * 检查索引是否存在
     */
    public static function index_exists(string $table_name, string $index_name): bool {
        global $wpdb;
        
        $full_table_name = $wpdb->prefix . $table_name;
        $result = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM `{$full_table_name}` WHERE Key_name = %s",
            $index_name
        ));
        
        return !empty($result);
    }
    
    /**
     * 创建索引
     */
    public static function create_index(string $table_name, string $index_name, array $columns, string $description = ''): bool {
        global $wpdb;
        
        if (self::index_exists($table_name, $index_name)) {
            Logger::debug_log("索引 {$index_name} 已存在，跳过创建", 'DatabaseManager');
            return true;
        }
        
        $full_table_name = $wpdb->prefix . $table_name;
        $columns_str = implode(', ', $columns);
        
        $sql = "CREATE INDEX {$index_name} ON {$full_table_name} ({$columns_str})";
        
        try {
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                Logger::info_log("成功创建索引: {$index_name} - {$description}", 'DatabaseManager');
                return true;
            } else {
                Logger::error_log("创建索引失败: {$index_name} - " . $wpdb->last_error, 'DatabaseManager');
                return false;
            }
        } catch (\Exception $e) {
            Logger::error_log("创建索引异常: {$index_name} - " . $e->getMessage(), 'DatabaseManager');
            return false;
        }
    }
    
    /**
     * 创建所有推荐索引
     */
    public static function create_all_recommended_indexes(): array {
        $results = [
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        Logger::info_log('开始创建推荐的数据库索引', 'DatabaseManager');
        
        foreach (self::RECOMMENDED_INDEXES as $index_name => $config) {
            $success = self::create_index(
                $config['table'],
                'idx_ntwp_' . $index_name,
                $config['columns'],
                $config['description']
            );
            
            $results['details'][$index_name] = [
                'success' => $success,
                'description' => $config['description'],
                'priority' => $config['priority']
            ];
            
            if ($success) {
                if (self::index_exists($config['table'], 'idx_ntwp_' . $index_name)) {
                    $results['created']++;
                } else {
                    $results['skipped']++;
                }
            } else {
                $results['failed']++;
            }
        }
        
        Logger::info_log(
            sprintf('索引创建完成: 创建 %d 个，跳过 %d 个，失败 %d 个', 
                $results['created'], $results['skipped'], $results['failed']),
            'DatabaseManager'
        );
        
        return $results;
    }
    
    /**
     * 删除索引
     */
    public static function drop_index(string $table_name, string $index_name): bool {
        global $wpdb;
        
        if (!self::index_exists($table_name, $index_name)) {
            return true;
        }
        
        $full_table_name = $wpdb->prefix . $table_name;
        $sql = "DROP INDEX {$index_name} ON {$full_table_name}";
        
        try {
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                Logger::info_log("成功删除索引: {$index_name}", 'DatabaseManager');
                return true;
            } else {
                Logger::error_log("删除索引失败: {$index_name} - " . $wpdb->last_error, 'DatabaseManager');
                return false;
            }
        } catch (\Exception $e) {
            Logger::error_log("删除索引异常: {$index_name} - " . $e->getMessage(), 'DatabaseManager');
            return false;
        }
    }
    
    // ===== 性能分析操作 (来自Database_Index_Optimizer) =====
    
    /**
     * 分析查询性能
     */
    public static function analyze_query_performance(string $query): array {
        global $wpdb;
        
        $start_time = microtime(true);
        
        // 执行EXPLAIN分析
        $explain_query = "EXPLAIN " . $query;
        $explain_results = $wpdb->get_results($explain_query, ARRAY_A);
        
        // 执行实际查询测量时间
        $wpdb->get_results($query);
        $execution_time = microtime(true) - $start_time;
        
        // 分析结果
        $analysis = [
            'execution_time' => $execution_time,
            'explain_results' => $explain_results,
            'performance_score' => self::calculate_performance_score($explain_results, $execution_time),
            'recommendations' => self::generate_query_recommendations($explain_results)
        ];
        
        Logger::debug_log(
            sprintf('查询性能分析完成，耗时 %.4f 秒，性能评分 %d', $execution_time, $analysis['performance_score']),
            'DatabaseManager'
        );
        
        return $analysis;
    }
    
    /**
     * 计算性能评分
     */
    private static function calculate_performance_score(array $explain_results, float $execution_time): int {
        $score = 100;
        
        // 基于执行时间扣分
        if ($execution_time > 1.0) {
            $score -= 50;
        } elseif ($execution_time > 0.5) {
            $score -= 30;
        } elseif ($execution_time > 0.1) {
            $score -= 15;
        }
        
        // 基于EXPLAIN结果扣分
        foreach ($explain_results as $row) {
            if (isset($row['type']) && $row['type'] === 'ALL') {
                $score -= 20; // 全表扫描
            }
            if (isset($row['rows']) && $row['rows'] > 1000) {
                $score -= 15; // 扫描行数过多
            }
            if (isset($row['key']) && is_null($row['key'])) {
                $score -= 10; // 未使用索引
            }
        }
        
        return max(0, $score);
    }
    
    /**
     * 生成查询优化建议
     */
    private static function generate_query_recommendations(array $explain_results): array {
        $recommendations = [];
        
        foreach ($explain_results as $row) {
            if (isset($row['type']) && $row['type'] === 'ALL') {
                $recommendations[] = '建议为表 ' . $row['table'] . ' 创建适当的索引以避免全表扫描';
            }
            
            if (isset($row['rows']) && $row['rows'] > 1000) {
                $recommendations[] = '查询扫描行数过多 (' . $row['rows'] . ')，建议优化WHERE条件或添加索引';
            }
            
            if (isset($row['key']) && is_null($row['key'])) {
                $recommendations[] = '查询未使用索引，建议检查WHERE条件中的字段是否有合适的索引';
            }
        }
        
        return $recommendations;
    }
    
    /**
     * 获取数据库优化建议
     */
    public static function get_optimization_suggestions(): array {
        global $wpdb;
        
        $suggestions = [];
        
        // 检查推荐索引状态
        foreach (self::RECOMMENDED_INDEXES as $index_name => $config) {
            $full_index_name = 'idx_ntwp_' . $index_name;
            if (!self::index_exists($config['table'], $full_index_name)) {
                $suggestions[] = [
                    'type' => 'missing_index',
                    'priority' => $config['priority'],
                    'description' => $config['description'],
                    'action' => "创建索引: {$full_index_name}"
                ];
            }
        }
        
        // 检查表大小和性能
        $table_stats = self::get_table_statistics();
        if ($table_stats['postmeta_size'] > 100 * 1024 * 1024) { // 100MB
            $suggestions[] = [
                'type' => 'large_table',
                'priority' => 'medium',
                'description' => 'postmeta表较大，建议定期清理无用数据',
                'action' => '执行数据库清理和优化'
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * 获取表统计信息
     */
    public static function get_table_statistics(): array {
        global $wpdb;
        
        $stats = [];
        
        // 获取postmeta表大小
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb,
                table_rows
            FROM information_schema.TABLES 
            WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $wpdb->postmeta
        ), ARRAY_A);
        
        $stats['postmeta_size'] = (is_array($result) ? ($result['size_mb'] ?? 0) : ($result->size_mb ?? 0)) * 1024 * 1024; // 转换为字节
        $stats['postmeta_rows'] = is_array($result) ? ($result['table_rows'] ?? 0) : ($result->table_rows ?? 0);
        
        // 获取posts表大小
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb,
                table_rows
            FROM information_schema.TABLES 
            WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $wpdb->posts
        ), ARRAY_A);
        
        $stats['posts_size'] = (is_array($result) ? ($result['size_mb'] ?? 0) : ($result->size_mb ?? 0)) * 1024 * 1024;
        $stats['posts_rows'] = is_array($result) ? ($result['table_rows'] ?? 0) : ($result->table_rows ?? 0);
        
        return $stats;
    }
    
    /**
     * 获取查询统计信息
     */
    public static function get_query_statistics(): array {
        $avg_time = !empty(self::$query_stats['query_times']) 
            ? array_sum(self::$query_stats['query_times']) / count(self::$query_stats['query_times'])
            : 0;
            
        return [
            'total_queries' => self::$query_stats['total_queries'],
            'average_time' => $avg_time,
            'cache_hit_rate' => self::$query_stats['cache_hits'] + self::$query_stats['cache_misses'] > 0
                ? self::$query_stats['cache_hits'] / (self::$query_stats['cache_hits'] + self::$query_stats['cache_misses']) * 100
                : 0,
            'cache_hits' => self::$query_stats['cache_hits'],
            'cache_misses' => self::$query_stats['cache_misses']
        ];
    }
    
    /**
     * 清理缓存
     */
    public static function clear_cache(string $cache_type = ''): void {
        if ($cache_type) {
            unset(self::$preloaded_cache[$cache_type]);
        } else {
            self::$preloaded_cache = [];
        }
        
        Logger::debug_log(
            $cache_type ? "清理缓存类型: {$cache_type}" : "清理所有缓存",
            'DatabaseManager'
        );
    }
    
    /**
     * 重置统计信息
     */
    public static function reset_statistics(): void {
        self::$query_stats = [
            'total_queries' => 0,
            'query_times' => [],
            'cache_hits' => 0,
            'cache_misses' => 0
        ];
        
        Logger::debug_log('数据库统计信息已重置', 'DatabaseManager');
    }
}
