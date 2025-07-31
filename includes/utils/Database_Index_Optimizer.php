<?php
declare(strict_types=1);

namespace NTWP\Utils;

/**
 * 数据库索引优化管理器
 *
 * 专门针对Notion-to-WordPress插件的数据库查询优化
 * 创建和管理高效的数据库索引，提升查询性能
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

/**
 * 数据库索引优化管理器类
 *
 * 提供数据库索引的创建、检查、优化和维护功能
 *
 * @since 2.0.0-beta.1
 */
class Database_Index_Optimizer {

    /**
     * 索引配置定义
     *
     * @since 2.0.0-beta.1
     * @var array
     */
    private static $index_definitions = [
        'notion_page_id_optimized' => [
            'table' => 'postmeta',
            'type' => 'composite',
            'columns' => ['meta_key', 'meta_value(100)', 'post_id'],
            'where' => "meta_key = '_notion_page_id'",
            'description' => '优化Notion页面ID查询性能',
            'priority' => 'high'
        ],
        'notion_sync_time' => [
            'table' => 'postmeta',
            'type' => 'composite',
            'columns' => ['meta_key', 'meta_value(50)', 'post_id'],
            'where' => "meta_key = '_notion_sync_time'",
            'description' => '优化同步时间查询性能',
            'priority' => 'high'
        ],
        'notion_meta_composite' => [
            'table' => 'postmeta',
            'type' => 'composite',
            'columns' => ['meta_key', 'post_id', 'meta_value(191)'],
            'where' => "meta_key IN ('_notion_page_id', '_notion_sync_time', '_notion_last_edited_time')",
            'description' => '优化批量元数据查询性能',
            'priority' => 'medium'
        ],
        'posts_notion_sync' => [
            'table' => 'posts',
            'type' => 'composite',
            'columns' => ['post_type', 'post_status', 'ID', 'post_modified'],
            'where' => "post_type = 'post'",
            'description' => '优化文章状态和同步查询性能',
            'priority' => 'medium'
        ],
        'notion_last_edited_time' => [
            'table' => 'postmeta',
            'type' => 'composite',
            'columns' => ['meta_key', 'meta_value(50)', 'post_id'],
            'where' => "meta_key = '_notion_last_edited_time'",
            'description' => '优化最后编辑时间查询性能',
            'priority' => 'high'
        ]
    ];

    /**
     * 创建所有优化索引
     *
     * @since 2.0.0-beta.1
     * @return array 创建结果统计
     */
    public static function create_all_indexes(): array {
        global $wpdb;
        
        $results = [
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => []
        ];

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::info_log(
                '开始创建数据库索引优化',
                'Database Index Optimizer'
            );
        }

        foreach (self::$index_definitions as $index_name => $config) {
            $result = self::create_index($index_name, $config);
            
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

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::info_log(
                sprintf(
                    '数据库索引优化完成: 创建%d个, 跳过%d个, 失败%d个',
                    $results['created'],
                    $results['skipped'],
                    $results['failed']
                ),
                'Database Index Optimizer'
            );
        }

        return $results;
    }

    /**
     * 创建单个索引
     *
     * @since 2.0.0-beta.1
     * @param string $index_name 索引名称
     * @param array $config 索引配置
     * @return array 创建结果
     */
    private static function create_index(string $index_name, array $config): array {
        global $wpdb;

        $table_name = $wpdb->prefix . $config['table'];
        $full_index_name = 'idx_ntwp_' . $index_name;

        // 检查索引是否已存在
        if (self::index_exists($table_name, $full_index_name)) {
            return [
                'success' => true,
                'created' => false,
                'message' => '索引已存在',
                'index_name' => $full_index_name
            ];
        }

        // 构建创建索引的SQL语句
        $columns_str = implode(', ', $config['columns']);
        
        $sql = "CREATE INDEX `{$full_index_name}` ON `{$table_name}` ({$columns_str})";
        
        // 如果有WHERE条件，添加到SQL中（注意：MySQL 8.0+ 支持函数索引和条件索引）
        if (!empty($config['where']) && self::supports_partial_indexes()) {
            $sql .= " WHERE {$config['where']}";
        }

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::debug_log(
                "创建索引SQL: {$sql}",
                'Database Index Optimizer'
            );
        }

        // 执行创建索引
        $result = $wpdb->query($sql);

        if ($result === false) {
            $error_message = $wpdb->last_error ?: '未知错误';
            
            if (class_exists('\\NTWP\\Core\\Logger')) {
                \NTWP\Core\Logger::error_log(
                    "创建索引失败: {$full_index_name}, 错误: {$error_message}",
                    'Database Index Optimizer'
                );
            }

            return [
                'success' => false,
                'created' => false,
                'message' => $error_message,
                'index_name' => $full_index_name
            ];
        }

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::info_log(
                "成功创建索引: {$full_index_name} ({$config['description']})",
                'Database Index Optimizer'
            );
        }

        return [
            'success' => true,
            'created' => true,
            'message' => '索引创建成功',
            'index_name' => $full_index_name
        ];
    }

    /**
     * 检查索引是否存在
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param string $index_name 索引名
     * @return bool 索引是否存在
     */
    private static function index_exists(string $table_name, string $index_name): bool {
        global $wpdb;

        $sql = "SHOW INDEX FROM `{$table_name}` WHERE Key_name = %s";
        $result = $wpdb->get_row($wpdb->prepare($sql, $index_name));

        return !empty($result);
    }

    /**
     * 检查数据库是否支持条件索引
     *
     * @since 2.0.0-beta.1
     * @return bool 是否支持条件索引
     */
    private static function supports_partial_indexes(): bool {
        global $wpdb;

        // 获取MySQL版本
        $version = $wpdb->get_var("SELECT VERSION()");
        
        // MySQL 8.0+ 支持函数索引，但条件索引支持有限
        // 为了兼容性，暂时不使用WHERE条件
        return false;
    }

    /**
     * 获取所有Notion相关索引的状态
     *
     * @since 2.0.0-beta.1
     * @return array 索引状态信息
     */
    public static function get_indexes_status(): array {
        global $wpdb;

        $status = [
            'total_defined' => count(self::$index_definitions),
            'existing' => 0,
            'missing' => 0,
            'details' => []
        ];

        foreach (self::$index_definitions as $index_name => $config) {
            $table_name = $wpdb->prefix . $config['table'];
            $full_index_name = 'idx_ntwp_' . $index_name;
            
            $exists = self::index_exists($table_name, $full_index_name);
            
            if ($exists) {
                $status['existing']++;
                $index_info = self::get_index_info($table_name, $full_index_name);
            } else {
                $status['missing']++;
                $index_info = null;
            }

            $status['details'][$index_name] = [
                'exists' => $exists,
                'table' => $config['table'],
                'description' => $config['description'],
                'priority' => $config['priority'],
                'full_name' => $full_index_name,
                'info' => $index_info
            ];
        }

        return $status;
    }

    /**
     * 获取指定索引的详细信息
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param string $index_name 索引名
     * @return array|null 索引信息
     */
    private static function get_index_info(string $table_name, string $index_name): ?array {
        global $wpdb;

        $sql = "SHOW INDEX FROM `{$table_name}` WHERE Key_name = %s";
        $results = $wpdb->get_results($wpdb->prepare($sql, $index_name));

        if (empty($results)) {
            return null;
        }

        $columns = [];
        foreach ($results as $row) {
            $columns[] = [
                'column' => $row->Column_name,
                'seq' => $row->Seq_in_index,
                'collation' => $row->Collation,
                'cardinality' => $row->Cardinality,
                'sub_part' => $row->Sub_part
            ];
        }

        return [
            'type' => $results[0]->Index_type,
            'unique' => $results[0]->Non_unique == 0,
            'columns' => $columns,
            'comment' => $results[0]->Index_comment ?? ''
        ];
    }

    /**
     * 删除指定的索引
     *
     * @since 2.0.0-beta.1
     * @param string $index_name 索引名称
     * @return array 删除结果
     */
    public static function drop_index(string $index_name): array {
        global $wpdb;

        if (!isset(self::$index_definitions[$index_name])) {
            return [
                'success' => false,
                'message' => '未知的索引名称'
            ];
        }

        $config = self::$index_definitions[$index_name];
        $table_name = $wpdb->prefix . $config['table'];
        $full_index_name = 'idx_ntwp_' . $index_name;

        if (!self::index_exists($table_name, $full_index_name)) {
            return [
                'success' => true,
                'dropped' => false,
                'message' => '索引不存在'
            ];
        }

        $sql = "DROP INDEX `{$full_index_name}` ON `{$table_name}`";
        $result = $wpdb->query($sql);

        if ($result === false) {
            $error_message = $wpdb->last_error ?: '未知错误';
            
            if (class_exists('\\NTWP\\Core\\Logger')) {
                \NTWP\Core\Logger::error_log(
                    "删除索引失败: {$full_index_name}, 错误: {$error_message}",
                    'Database Index Optimizer'
                );
            }

            return [
                'success' => false,
                'dropped' => false,
                'message' => $error_message
            ];
        }

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::info_log(
                "成功删除索引: {$full_index_name}",
                'Database Index Optimizer'
            );
        }

        return [
            'success' => true,
            'dropped' => true,
            'message' => '索引删除成功'
        ];
    }

    /**
     * 分析查询性能
     *
     * @since 2.0.0-beta.1
     * @param string $sql 要分析的SQL语句
     * @return array 性能分析结果
     */
    public static function analyze_query_performance(string $sql): array {
        global $wpdb;

        // 使用EXPLAIN分析查询计划
        $explain_sql = "EXPLAIN " . $sql;
        $explain_results = $wpdb->get_results($explain_sql);

        $analysis = [
            'query' => $sql,
            'execution_plan' => $explain_results,
            'recommendations' => [],
            'performance_score' => 0
        ];

        if (!empty($explain_results)) {
            foreach ($explain_results as $row) {
                // 分析查询性能并提供建议
                if ($row->type === 'ALL') {
                    $analysis['recommendations'][] = '全表扫描检测到，建议添加索引';
                    $analysis['performance_score'] -= 20;
                }

                if ($row->key === null) {
                    $analysis['recommendations'][] = '未使用索引，查询性能可能很差';
                    $analysis['performance_score'] -= 15;
                }

                if (isset($row->rows) && $row->rows > 1000) {
                    $analysis['recommendations'][] = '扫描行数过多 (' . $row->rows . ')，建议优化查询或添加索引';
                    $analysis['performance_score'] -= 10;
                }

                if (!empty($row->key)) {
                    $analysis['performance_score'] += 10;
                }
            }
        }

        // 基础性能分数
        $analysis['performance_score'] = max(0, min(100, $analysis['performance_score'] + 50));

        return $analysis;
    }

    /**
     * 优化常见的Notion相关查询
     *
     * @since 2.0.0-beta.1
     * @return array 优化建议
     */
    public static function get_query_optimization_recommendations(): array {
        global $wpdb;

        $recommendations = [];

        // 分析删除检测查询性能
        $deletion_query = "
            SELECT p.ID as post_id, pm.meta_value as notion_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_notion_page_id'
        ";

        $deletion_analysis = self::analyze_query_performance($deletion_query);
        $recommendations['deletion_detection'] = [
            'query_type' => '删除检测查询',
            'current_performance' => $deletion_analysis['performance_score'],
            'analysis' => $deletion_analysis,
            'suggested_indexes' => ['notion_page_id_optimized']
        ];

        // 分析同步时间查询性能
        $sync_time_query = "
            SELECT post_id, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_notion_sync_time'
            ORDER BY meta_value DESC
            LIMIT 100
        ";

        $sync_analysis = self::analyze_query_performance($sync_time_query);
        $recommendations['sync_time_query'] = [
            'query_type' => '同步时间查询',
            'current_performance' => $sync_analysis['performance_score'],
            'analysis' => $sync_analysis,
            'suggested_indexes' => ['notion_sync_time']
        ];

        return $recommendations;
    }

    /**
     * 执行索引维护
     *
     * @since 2.0.0-beta.1
     * @return array 维护结果
     */
    public static function maintain_indexes(): array {
        global $wpdb;

        $maintenance_results = [
            'analyzed' => 0,
            'optimized' => 0,
            'warnings' => []
        ];

        foreach (self::$index_definitions as $index_name => $config) {
            $table_name = $wpdb->prefix . $config['table'];
            $full_index_name = 'idx_ntwp_' . $index_name;

            if (self::index_exists($table_name, $full_index_name)) {
                // 分析表
                $analyze_sql = "ANALYZE TABLE `{$table_name}`";
                $wpdb->query($analyze_sql);
                $maintenance_results['analyzed']++;

                // 优化表（可选）
                if ($config['priority'] === 'high') {
                    $optimize_sql = "OPTIMIZE TABLE `{$table_name}`";
                    $wpdb->query($optimize_sql);
                    $maintenance_results['optimized']++;
                }
            } else {
                $maintenance_results['warnings'][] = "索引 {$full_index_name} 不存在";
            }
        }

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::info_log(
                sprintf(
                    '索引维护完成: 分析%d个表, 优化%d个表, %d个警告',
                    $maintenance_results['analyzed'],
                    $maintenance_results['optimized'],
                    count($maintenance_results['warnings'])
                ),
                'Database Index Optimizer'
            );
        }

        return $maintenance_results;
    }
}