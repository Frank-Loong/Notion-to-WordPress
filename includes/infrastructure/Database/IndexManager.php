<?php
declare(strict_types=1);

namespace NTWP\Infrastructure\Database;

use NTWP\Core\Foundation\Logger;

/**
 * 数据库索引管理器
 *
 * 统一数据库索引管理，合并原有的4个重复索引管理器：
 * - Database_Index_Manager.php
 * - Database_Index_Optimizer.php
 * - Database_Index_Manager_Adapter.php
 * - Database_Index_Optimizer_Adapter.php
 *
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}
class IndexManager {
    
    /**
     * 确保必要的数据库索引存在
     *
     * @return array 操作结果
     */
    public static function ensure_indexes(): array {
        global $wpdb;
        
        $results = [];
        $required_indexes = [
            [
                'table' => $wpdb->postmeta,
                'name' => 'idx_notion_page_id',
                'columns' => "meta_key, meta_value(191)",
                'condition' => "meta_key = '_notion_page_id'"
            ],
            [
                'table' => $wpdb->postmeta,
                'name' => 'idx_sync_time',
                'columns' => "meta_key, meta_value",
                'condition' => "meta_key = '_notion_sync_time'"
            ],
            [
                'table' => $wpdb->posts,
                'name' => 'idx_post_status_modified',
                'columns' => "post_status, post_modified",
                'condition' => null
            ]
        ];

        foreach ($required_indexes as $index) {
            $result = self::create_index_if_not_exists(
                $index['table'],
                $index['name'],
                $index['columns'],
                $index['condition']
            );
            
            $results[] = $result;
            
            Logger::debug_log(
                sprintf('索引检查: %s - %s', $index['name'], $result['status']),
                'IndexManager'
            );
        }

        return $results;
    }

    /**
     * 创建索引（如果不存在）
     *
     * @param string $table 表名
     * @param string $index_name 索引名
     * @param string $columns 索引列
     * @param string|null $condition 条件索引
     * @return array 操作结果
     */
    private static function create_index_if_not_exists(
        string $table, 
        string $index_name, 
        string $columns, 
        ?string $condition = null
    ): array {
        global $wpdb;

        // 检查索引是否已存在
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE table_schema = %s 
            AND table_name = %s 
            AND index_name = %s",
            DB_NAME,
            str_replace($wpdb->prefix, '', $table),
            $index_name
        ));

        if ($existing > 0) {
            return [
                'index' => $index_name,
                'status' => 'exists',
                'message' => '索引已存在'
            ];
        }

        // 创建索引
        try {
            $sql = "CREATE INDEX {$index_name} ON {$table} ({$columns})";
            
            if ($condition) {
                $sql .= " WHERE {$condition}";
            }

            $result = $wpdb->query($sql);

            if ($result !== false) {
                return [
                    'index' => $index_name,
                    'status' => 'created',
                    'message' => '索引创建成功'
                ];
            } else {
                return [
                    'index' => $index_name,
                    'status' => 'error',
                    'message' => '索引创建失败: ' . $wpdb->last_error
                ];
            }
        } catch (\Exception $e) {
            return [
                'index' => $index_name,
                'status' => 'error',
                'message' => '索引创建异常: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 分析表性能并提供优化建议
     *
     * @return array 性能分析结果
     */
    public static function analyze_performance(): array {
        global $wpdb;

        $analysis = [];

        // 分析postmeta表
        $postmeta_stats = $wpdb->get_row(
            "SELECT COUNT(*) as total_rows,
                    COUNT(CASE WHEN meta_key = '_notion_page_id' THEN 1 END) as notion_rows,
                    COUNT(CASE WHEN meta_key = '_notion_sync_time' THEN 1 END) as sync_rows
            FROM {$wpdb->postmeta}"
        );

        $analysis['postmeta'] = [
            'total_rows' => $postmeta_stats->total_rows ?? 0,
            'notion_rows' => $postmeta_stats->notion_rows ?? 0,
            'sync_rows' => $postmeta_stats->sync_rows ?? 0,
            'ratio' => $postmeta_stats->total_rows > 0 
                ? round(($postmeta_stats->notion_rows / $postmeta_stats->total_rows) * 100, 2)
                : 0
        ];

        // 检查索引使用情况
        $index_usage = self::check_index_usage();
        $analysis['index_usage'] = $index_usage;

        // 性能建议
        $recommendations = [];
        
        if ($analysis['postmeta']['ratio'] > 10) {
            $recommendations[] = 'Notion数据占用较高，建议优化查询';
        }
        
        if (count($index_usage['missing']) > 0) {
            $recommendations[] = '存在缺失的推荐索引';
        }

        $analysis['recommendations'] = $recommendations;

        Logger::debug_log(
            sprintf('性能分析完成: %d条建议', count($recommendations)),
            'IndexManager'
        );

        return $analysis;
    }

    /**
     * 检查索引使用情况
     *
     * @return array 索引状态
     */
    private static function check_index_usage(): array {
        global $wpdb;

        $required_indexes = ['idx_notion_page_id', 'idx_sync_time', 'idx_post_status_modified'];
        $existing_indexes = [];
        $missing_indexes = [];

        foreach ($required_indexes as $index_name) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE table_schema = %s AND index_name = %s",
                DB_NAME,
                $index_name
            ));

            if ($exists > 0) {
                $existing_indexes[] = $index_name;
            } else {
                $missing_indexes[] = $index_name;
            }
        }

        return [
            'existing' => $existing_indexes,
            'missing' => $missing_indexes,
            'total_required' => count($required_indexes)
        ];
    }

    /**
     * 清理无用索引
     *
     * @return array 清理结果
     */
    public static function cleanup_unused_indexes(): array {
        global $wpdb;

        // 获取所有自定义索引
        $custom_indexes = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT index_name, table_name 
            FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE table_schema = %s 
            AND index_name LIKE 'idx_%'
            AND index_name NOT IN ('idx_notion_page_id', 'idx_sync_time', 'idx_post_status_modified')",
            DB_NAME
        ));

        $cleaned = [];
        
        foreach ($custom_indexes as $index) {
            // 这里可以添加更复杂的逻辑来判断是否应该删除索引
            // 暂时只记录，不自动删除
            $cleaned[] = [
                'index' => $index->index_name,
                'table' => $index->table_name,
                'action' => 'identified_for_review'
            ];
        }

        Logger::debug_log(
            sprintf('索引清理检查: 发现 %d 个可能无用的索引', count($cleaned)),
            'IndexManager'
        );

        return $cleaned;
    }

    /**
     * 检查索引是否存在 (兼容Database_Index_Manager)
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param string $index_name 索引名
     * @return bool 索引是否存在
     */
    public static function index_exists(string $table_name, string $index_name): bool {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            DB_NAME,
            $table_name,
            $index_name
        ));

        return (bool) $result;
    }

    /**
     * 创建索引 (兼容Database_Index_Manager)
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param string $index_name 索引名
     * @param string $columns 列定义
     * @param string $type 索引类型 (INDEX, UNIQUE, FULLTEXT)
     * @return bool 是否成功
     */
    public static function create_index(string $table_name, string $index_name, string $columns, string $type = 'INDEX'): bool {
        global $wpdb;

        // 检查索引是否已存在
        if (self::index_exists($table_name, $index_name)) {
            Logger::debug_log(
                sprintf('索引 %s.%s 已存在，跳过创建', $table_name, $index_name),
                'IndexManager'
            );
            return true;
        }

        // 构建创建索引的SQL
        $sql = sprintf(
            "CREATE %s %s ON %s (%s)",
            $type,
            $index_name,
            $table_name,
            $columns
        );

        $result = $wpdb->query($sql);

        if ($result === false) {
            Logger::error_log(
                sprintf('创建索引失败: %s - %s', $sql, $wpdb->last_error),
                'IndexManager'
            );
            return false;
        }

        Logger::debug_log(
            sprintf('成功创建索引: %s.%s', $table_name, $index_name),
            'IndexManager'
        );

        return true;
    }

    /**
     * 删除索引 (兼容Database_Index_Manager)
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param string $index_name 索引名
     * @return bool 是否成功
     */
    public static function drop_index(string $table_name, string $index_name): bool {
        global $wpdb;

        // 检查索引是否存在
        if (!self::index_exists($table_name, $index_name)) {
            Logger::debug_log(
                sprintf('索引 %s.%s 不存在，跳过删除', $table_name, $index_name),
                'IndexManager'
            );
            return true;
        }

        $sql = sprintf("DROP INDEX %s ON %s", $index_name, $table_name);
        $result = $wpdb->query($sql);

        if ($result === false) {
            Logger::error_log(
                sprintf('删除索引失败: %s - %s', $sql, $wpdb->last_error),
                'IndexManager'
            );
            return false;
        }

        Logger::debug_log(
            sprintf('成功删除索引: %s.%s', $table_name, $index_name),
            'IndexManager'
        );

        return true;
    }

    /**
     * 创建推荐的索引 (兼容Database_Index_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 创建结果
     */
    public static function create_recommended_indexes(): array {
        return self::ensure_indexes();
    }

    /**
     * 删除推荐的索引 (兼容Database_Index_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 删除结果
     */
    public static function drop_recommended_indexes(): array {
        global $wpdb;

        $results = [];
        $indexes_to_drop = [
            ['table' => $wpdb->postmeta, 'name' => 'idx_notion_page_id'],
            ['table' => $wpdb->postmeta, 'name' => 'idx_sync_time'],
            ['table' => $wpdb->posts, 'name' => 'idx_post_status_modified']
        ];

        foreach ($indexes_to_drop as $index) {
            $success = self::drop_index($index['table'], $index['name']);
            $results[] = [
                'table' => $index['table'],
                'index' => $index['name'],
                'action' => $success ? 'dropped' : 'failed'
            ];
        }

        return $results;
    }

    /**
     * 获取索引状态 (兼容Database_Index_Manager)
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @return array 索引状态信息
     */
    public static function get_index_status(string $table_name = ''): array {
        global $wpdb;

        $where_clause = '';
        $params = [DB_NAME];

        if (!empty($table_name)) {
            $where_clause = ' AND TABLE_NAME = %s';
            $params[] = $table_name;
        }

        $indexes = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, INDEX_TYPE
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s{$where_clause}
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
            ...$params
        ));

        $status = [];
        foreach ($indexes as $index) {
            $key = $index->TABLE_NAME . '.' . $index->INDEX_NAME;
            if (!isset($status[$key])) {
                $status[$key] = [
                    'table' => $index->TABLE_NAME,
                    'name' => $index->INDEX_NAME,
                    'type' => $index->INDEX_TYPE,
                    'unique' => !$index->NON_UNIQUE,
                    'columns' => []
                ];
            }
            $status[$key]['columns'][] = $index->COLUMN_NAME;
        }

        return array_values($status);
    }

    /**
     * 优化所有Notion相关索引 (兼容Database_Index_Manager)
     *
     * @since 2.0.0-beta.1
     * @return array 优化结果
     */
    public static function optimize_all_notion_indexes(): array {
        global $wpdb;

        $results = [];
        $tables_to_optimize = [$wpdb->posts, $wpdb->postmeta];

        foreach ($tables_to_optimize as $table) {
            $sql = "OPTIMIZE TABLE {$table}";
            $result = $wpdb->query($sql);

            $results[] = [
                'table' => $table,
                'action' => 'optimize',
                'success' => $result !== false,
                'message' => $result !== false ? 'Optimized successfully' : $wpdb->last_error
            ];
        }

        // 更新表统计信息
        foreach ($tables_to_optimize as $table) {
            $sql = "ANALYZE TABLE {$table}";
            $wpdb->query($sql);
        }

        Logger::debug_log(
            sprintf('优化了 %d 个表的索引', count($tables_to_optimize)),
            'IndexManager'
        );

        return $results;
    }

    /**
     * 计算性能估算 (兼容Database_Index_Manager)
     *
     * @since 2.0.0-beta.1
     * @param array $query_patterns 查询模式
     * @return array 性能估算结果
     */
    public static function calculate_performance_estimate(array $query_patterns = []): array {
        global $wpdb;

        // 获取表统计信息
        $post_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
        $meta_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}");

        // 基础性能估算
        $estimates = [
            'table_sizes' => [
                'posts' => (int) $post_count,
                'postmeta' => (int) $meta_count
            ],
            'index_efficiency' => [],
            'query_performance' => []
        ];

        // 检查关键索引的效率
        $key_indexes = [
            'idx_notion_page_id' => $wpdb->postmeta,
            'idx_sync_time' => $wpdb->postmeta,
            'idx_post_status_modified' => $wpdb->posts
        ];

        foreach ($key_indexes as $index_name => $table) {
            $exists = self::index_exists($table, $index_name);
            $estimates['index_efficiency'][$index_name] = [
                'exists' => $exists,
                'estimated_improvement' => $exists ? '80-90%' : 'N/A',
                'table' => $table
            ];
        }

        // 查询性能估算
        if (!empty($query_patterns)) {
            foreach ($query_patterns as $pattern) {
                $estimates['query_performance'][] = [
                    'pattern' => $pattern,
                    'estimated_time' => 'Requires EXPLAIN analysis'
                ];
            }
        }

        return $estimates;
    }
}
