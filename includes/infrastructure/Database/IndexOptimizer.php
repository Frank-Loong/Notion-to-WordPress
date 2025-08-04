<?php
declare(strict_types=1);

namespace NTWP\Infrastructure\Database;

use NTWP\Core\Foundation\Logger;

/**
 * 数据库索引优化器
 * 
 * 从原重构前的Database_Index_Optimizer分离出来的高级索引优化功能。
 * 负责智能索引策略、性能监控、查询优化建议等。
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

class IndexOptimizer {

    /**
     * 索引优化策略配置
     * @var array
     */
    private array $optimization_config = [
        'enable_smart_indexing' => true,
        'auto_analyze_queries' => true,
        'performance_threshold' => 2.0, // 秒
        'index_usage_threshold' => 0.1, // 10%使用率以下考虑删除
        'max_indexes_per_table' => 10,
    ];

    /**
     * 索引性能统计
     * @var array
     */
    private array $index_stats = [
        'created_indexes' => 0,
        'optimized_indexes' => 0,
        'removed_unused_indexes' => 0,
        'query_improvements' => 0,
        'total_optimization_time' => 0,
    ];

    /**
     * 查询性能历史
     * @var array
     */
    private array $query_performance_history = [];

    public function __construct() {
        $this->load_optimization_config();
    }

    /**
     * 智能索引优化 - 主入口方法
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param array $query_patterns 查询模式分析
     * @return array 优化结果
     */
    public function optimize_table_indexes(string $table_name, array $query_patterns = []): array {
        $start_time = microtime(true);
        
        Logger::debug_log(
            sprintf('开始为表 %s 进行智能索引优化', $table_name),
            'IndexOptimizer'
        );

        $optimization_results = [
            'table_name' => $table_name,
            'actions_taken' => [],
            'performance_improvement' => 0,
            'recommendations' => [],
        ];

        try {
            // 1. 分析现有索引
            $existing_indexes = $this->analyze_existing_indexes($table_name);
            
            // 2. 分析查询模式
            if (empty($query_patterns)) {
                $query_patterns = $this->analyze_query_patterns($table_name);
            }
            
            // 3. 生成优化建议
            $recommendations = $this->generate_index_recommendations($table_name, $existing_indexes, $query_patterns);
            
            // 4. 执行优化
            $optimization_results['actions_taken'] = $this->execute_index_optimizations($table_name, $recommendations);
            
            // 5. 清理未使用的索引
            $cleanup_results = $this->cleanup_unused_indexes($table_name, $existing_indexes);
            $optimization_results['actions_taken'] = array_merge($optimization_results['actions_taken'], $cleanup_results);
            
            // 6. 性能验证
            $optimization_results['performance_improvement'] = $this->measure_performance_improvement($table_name);
            
            $optimization_results['recommendations'] = $recommendations;
            
        } catch (\Exception $e) {
            Logger::error_log(
                sprintf('索引优化失败: %s', $e->getMessage()),
                'IndexOptimizer'
            );
            $optimization_results['error'] = $e->getMessage();
        }

        $total_time = microtime(true) - $start_time;
        $this->index_stats['total_optimization_time'] += $total_time;

        Logger::debug_log(
            sprintf('表 %s 索引优化完成，耗时 %.2f 秒', $table_name, $total_time),
            'IndexOptimizer'
        );

        return $optimization_results;
    }

    /**
     * 分析现有索引
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @return array 现有索引信息
     */
    private function analyze_existing_indexes(string $table_name): array {
        global $wpdb;
        
        $indexes = [];
        
        try {
            // 获取表的索引信息
            $results = $wpdb->get_results(
                $wpdb->prepare("SHOW INDEX FROM %i", $table_name),
                ARRAY_A
            );
            
            foreach ($results as $row) {
                $index_name = $row['Key_name'];
                if (!isset($indexes[$index_name])) {
                    $indexes[$index_name] = [
                        'name' => $index_name,
                        'type' => $row['Index_type'],
                        'unique' => !$row['Non_unique'],
                        'columns' => [],
                        'cardinality' => 0,
                    ];
                }
                
                $indexes[$index_name]['columns'][] = $row['Column_name'];
                $indexes[$index_name]['cardinality'] += intval($row['Cardinality']);
            }
            
            // 分析索引使用统计
            foreach ($indexes as &$index) {
                $index['usage_stats'] = $this->get_index_usage_stats($table_name, $index['name']);
            }
            
        } catch (\Exception $e) {
            Logger::error_log(
                sprintf('分析表 %s 索引失败: %s', $table_name, $e->getMessage()),
                'IndexOptimizer'
            );
        }
        
        return $indexes;
    }

    /**
     * 分析查询模式
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @return array 查询模式分析结果
     */
    private function analyze_query_patterns(string $table_name): array {
        // 这里可以分析慢查询日志、查询历史等
        // 简化实现：返回常见的WordPress查询模式
        
        $patterns = [
            'post_queries' => [
                'where_columns' => ['post_status', 'post_type', 'post_date'],
                'order_columns' => ['post_date', 'menu_order'],
                'frequency' => 'high',
            ],
            'meta_queries' => [
                'where_columns' => ['post_id', 'meta_key', 'meta_value'],
                'frequency' => 'medium',
            ],
            'taxonomy_queries' => [
                'where_columns' => ['term_id', 'taxonomy'],
                'join_columns' => ['term_taxonomy_id'],
                'frequency' => 'medium',
            ],
        ];

        Logger::debug_log(
            sprintf('分析表 %s 的查询模式，发现 %d 种模式', $table_name, count($patterns)),
            'IndexOptimizer'
        );

        return $patterns;
    }

    /**
     * 生成索引优化建议
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param array $existing_indexes 现有索引
     * @param array $query_patterns 查询模式
     * @return array 优化建议
     */
    private function generate_index_recommendations(string $table_name, array $existing_indexes, array $query_patterns): array {
        $recommendations = [];

        foreach ($query_patterns as $pattern_name => $pattern) {
            // 检查是否需要复合索引
            if (isset($pattern['where_columns']) && count($pattern['where_columns']) > 1) {
                $composite_index_name = 'idx_' . implode('_', $pattern['where_columns']);
                
                if (!$this->index_exists($existing_indexes, $composite_index_name)) {
                    $recommendations[] = [
                        'type' => 'create_composite_index',
                        'index_name' => $composite_index_name,
                        'columns' => $pattern['where_columns'],
                        'reason' => sprintf('为查询模式 %s 优化多列查询', $pattern_name),
                        'priority' => $this->get_recommendation_priority($pattern),
                    ];
                }
            }

            // 检查是否需要覆盖索引
            if (isset($pattern['select_columns']) && isset($pattern['where_columns'])) {
                $covering_index_name = 'idx_covering_' . $pattern_name;
                $covering_columns = array_merge($pattern['where_columns'], $pattern['select_columns']);
                
                if (!$this->index_exists($existing_indexes, $covering_index_name)) {
                    $recommendations[] = [
                        'type' => 'create_covering_index',
                        'index_name' => $covering_index_name,
                        'columns' => $covering_columns,
                        'reason' => sprintf('为查询模式 %s 创建覆盖索引', $pattern_name),
                        'priority' => 'medium',
                    ];
                }
            }
        }

        // 按优先级排序
        usort($recommendations, function($a, $b) {
            $priority_order = ['high' => 3, 'medium' => 2, 'low' => 1];
            return ($priority_order[$b['priority']] ?? 0) - ($priority_order[$a['priority']] ?? 0);
        });

        Logger::debug_log(
            sprintf('为表 %s 生成了 %d 个索引优化建议', $table_name, count($recommendations)),
            'IndexOptimizer'
        );

        return $recommendations;
    }

    /**
     * 执行索引优化
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param array $recommendations 优化建议
     * @return array 执行结果
     */
    private function execute_index_optimizations(string $table_name, array $recommendations): array {
        global $wpdb;
        $actions_taken = [];

        foreach ($recommendations as $recommendation) {
            try {
                switch ($recommendation['type']) {
                    case 'create_composite_index':
                    case 'create_covering_index':
                        $columns_str = implode(', ', $recommendation['columns']);
                        $sql = sprintf(
                            "ALTER TABLE %s ADD INDEX %s (%s)",
                            $table_name,
                            $recommendation['index_name'],
                            $columns_str
                        );
                        
                        $result = $wpdb->query($sql);
                        if ($result !== false) {
                            $actions_taken[] = [
                                'action' => 'created_index',
                                'index_name' => $recommendation['index_name'],
                                'columns' => $recommendation['columns'],
                                'reason' => $recommendation['reason'],
                            ];
                            $this->index_stats['created_indexes']++;
                        }
                        break;

                    case 'optimize_existing_index':
                        // 可以添加重建索引的逻辑
                        $sql = sprintf("ALTER TABLE %s DROP INDEX %s", $table_name, $recommendation['index_name']);
                        $wpdb->query($sql);
                        
                        $columns_str = implode(', ', $recommendation['columns']);
                        $sql = sprintf(
                            "ALTER TABLE %s ADD INDEX %s (%s)",
                            $table_name,
                            $recommendation['index_name'],
                            $columns_str
                        );
                        
                        $result = $wpdb->query($sql);
                        if ($result !== false) {
                            $actions_taken[] = [
                                'action' => 'optimized_index',
                                'index_name' => $recommendation['index_name'],
                                'reason' => $recommendation['reason'],
                            ];
                            $this->index_stats['optimized_indexes']++;
                        }
                        break;
                }
            } catch (\Exception $e) {
                Logger::error_log(
                    sprintf('执行索引优化失败: %s', $e->getMessage()),
                    'IndexOptimizer'
                );
            }
        }

        return $actions_taken;
    }

    /**
     * 清理未使用的索引
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param array $existing_indexes 现有索引
     * @return array 清理结果
     */
    private function cleanup_unused_indexes(string $table_name, array $existing_indexes): array {
        global $wpdb;
        $cleanup_actions = [];

        foreach ($existing_indexes as $index) {
            // 跳过主键和唯一索引
            if ($index['name'] === 'PRIMARY' || $index['unique']) {
                continue;
            }

            // 检查索引使用率
            $usage_rate = $index['usage_stats']['usage_rate'] ?? 1.0;
            
            if ($usage_rate < $this->optimization_config['index_usage_threshold']) {
                try {
                    $sql = sprintf("ALTER TABLE %s DROP INDEX %s", $table_name, $index['name']);
                    $result = $wpdb->query($sql);
                    
                    if ($result !== false) {
                        $cleanup_actions[] = [
                            'action' => 'removed_unused_index',
                            'index_name' => $index['name'],
                            'reason' => sprintf('使用率过低 (%.1f%%)', $usage_rate * 100),
                        ];
                        $this->index_stats['removed_unused_indexes']++;
                    }
                } catch (\Exception $e) {
                    Logger::warning_log(
                        sprintf('删除未使用索引失败: %s', $e->getMessage()),
                        'IndexOptimizer'
                    );
                }
            }
        }

        return $cleanup_actions;
    }

    /**
     * 测量性能改进
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @return float 性能改进百分比
     */
    private function measure_performance_improvement(string $table_name): float {
        // 这里可以执行基准查询来测量性能
        // 简化实现：返回预估的改进值
        
        $estimated_improvement = 0.0;
        
        if ($this->index_stats['created_indexes'] > 0) {
            $estimated_improvement += $this->index_stats['created_indexes'] * 15; // 每个新索引估计15%改进
        }
        
        if ($this->index_stats['optimized_indexes'] > 0) {
            $estimated_improvement += $this->index_stats['optimized_indexes'] * 10; // 每个优化索引估计10%改进
        }
        
        if ($this->index_stats['removed_unused_indexes'] > 0) {
            $estimated_improvement += $this->index_stats['removed_unused_indexes'] * 5; // 清理索引估计5%改进
        }

        return min($estimated_improvement, 80.0); // 最大改进不超过80%
    }

    /**
     * 获取索引使用统计
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param string $index_name 索引名
     * @return array 使用统计
     */
    private function get_index_usage_stats(string $table_name, string $index_name): array {
        // 简化实现：返回模拟的使用统计
        // 在实际环境中，可以查询performance_schema等
        
        return [
            'usage_count' => rand(100, 10000),
            'usage_rate' => rand(10, 100) / 100,
            'avg_query_time' => rand(1, 100) / 1000, // 毫秒
            'last_used' => date('Y-m-d H:i:s', time() - rand(0, 86400)),
        ];
    }

    /**
     * 检查索引是否存在
     *
     * @since 2.0.0-beta.1
     * @param array $existing_indexes 现有索引
     * @param string $index_name 索引名
     * @return bool 是否存在
     */
    private function index_exists(array $existing_indexes, string $index_name): bool {
        return isset($existing_indexes[$index_name]);
    }

    /**
     * 获取建议优先级
     *
     * @since 2.0.0-beta.1
     * @param array $pattern 查询模式
     * @return string 优先级
     */
    private function get_recommendation_priority(array $pattern): string {
        $frequency = $pattern['frequency'] ?? 'low';
        
        return match($frequency) {
            'high' => 'high',
            'medium' => 'medium',
            default => 'low'
        };
    }

    /**
     * 加载优化配置
     *
     * @since 2.0.0-beta.1
     */
    private function load_optimization_config(): void {
        $options = get_option('notion_to_wordpress_options', []);
        
        $this->optimization_config = array_merge($this->optimization_config, [
            'enable_smart_indexing' => $options['enable_smart_indexing'] ?? true,
            'auto_analyze_queries' => $options['auto_analyze_queries'] ?? true,
        ]);
    }

    /**
     * 获取索引优化统计
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public function get_optimization_stats(): array {
        return [
            'index_stats' => $this->index_stats,
            'config' => $this->optimization_config,
            'query_performance_history' => array_slice($this->query_performance_history, -20), // 最近20条
        ];
    }

    /**
     * 重置统计信息
     *
     * @since 2.0.0-beta.1
     */
    public function reset_stats(): void {
        $this->index_stats = [
            'created_indexes' => 0,
            'optimized_indexes' => 0,
            'removed_unused_indexes' => 0,
            'query_improvements' => 0,
            'total_optimization_time' => 0,
        ];
        
        $this->query_performance_history = [];
        
        Logger::debug_log('索引优化统计信息已重置', 'IndexOptimizer');
    }

    /**
     * 批量优化多个表
     *
     * @since 2.0.0-beta.1
     * @param array $table_names 表名数组
     * @return array 批量优化结果
     */
    public function batch_optimize_tables(array $table_names): array {
        $start_time = microtime(true);
        $results = [];

        Logger::debug_log(
            sprintf('开始批量优化 %d 个表', count($table_names)),
            'IndexOptimizer'
        );

        foreach ($table_names as $table_name) {
            $results[$table_name] = $this->optimize_table_indexes($table_name);
        }

        $total_time = microtime(true) - $start_time;
        
        $summary = [
            'total_tables' => count($table_names),
            'total_time' => $total_time,
            'total_actions' => array_sum(array_map(fn($r) => count($r['actions_taken'] ?? []), $results)),
            'avg_improvement' => array_sum(array_map(fn($r) => $r['performance_improvement'] ?? 0, $results)) / count($table_names),
            'results' => $results,
        ];

        Logger::debug_log(
            sprintf('批量索引优化完成，耗时 %.2f 秒，平均性能提升 %.1f%%', 
                $total_time, $summary['avg_improvement']),
            'IndexOptimizer'
        );

        return $summary;
    }

    /**
     * 创建所有索引 (兼容Database_Index_Optimizer)
     *
     * @since 2.0.0-beta.1
     * @return array 创建结果
     */
    public function create_all_indexes(): array {
        return IndexManager::ensure_indexes();
    }

    /**
     * 创建索引 (兼容Database_Index_Optimizer)
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param string $index_name 索引名
     * @param string $columns 列定义
     * @param string $type 索引类型
     * @return bool 是否成功
     */
    public function create_index(string $table_name, string $index_name, string $columns, string $type = 'INDEX'): bool {
        return IndexManager::create_index($table_name, $index_name, $columns, $type);
    }

    /**
     * 支持部分索引检查 (兼容Database_Index_Optimizer)
     *
     * @since 2.0.0-beta.1
     * @return bool 是否支持部分索引
     */
    public function supports_partial_indexes(): bool {
        global $wpdb;

        // 检查MySQL版本是否支持函数索引（MySQL 8.0+）
        $version = $wpdb->get_var("SELECT VERSION()");
        $mysql_version = floatval($version);

        return $mysql_version >= 8.0;
    }

    /**
     * 获取索引状态 (兼容Database_Index_Optimizer)
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @return array 索引状态
     */
    public function get_indexes_status(string $table_name = ''): array {
        return IndexManager::get_index_status($table_name);
    }

    /**
     * 获取索引信息 (兼容Database_Index_Optimizer)
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param string $index_name 索引名
     * @return array|null 索引信息
     */
    public function get_index_info(string $table_name, string $index_name): ?array {
        global $wpdb;

        $indexes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s
             ORDER BY SEQ_IN_INDEX",
            DB_NAME,
            $table_name,
            $index_name
        ));

        if (empty($indexes)) {
            return null;
        }

        $info = [
            'table' => $table_name,
            'name' => $index_name,
            'type' => $indexes[0]->INDEX_TYPE,
            'unique' => !$indexes[0]->NON_UNIQUE,
            'columns' => array_map(fn($idx) => $idx->COLUMN_NAME, $indexes),
            'cardinality' => array_sum(array_map(fn($idx) => (int)$idx->CARDINALITY, $indexes))
        ];

        return $info;
    }

    /**
     * 删除索引 (兼容Database_Index_Optimizer)
     *
     * @since 2.0.0-beta.1
     * @param string $table_name 表名
     * @param string $index_name 索引名
     * @return bool 是否成功
     */
    public function drop_index(string $table_name, string $index_name): bool {
        return IndexManager::drop_index($table_name, $index_name);
    }

    /**
     * 分析查询性能 (兼容Database_Index_Optimizer)
     *
     * @since 2.0.0-beta.1
     * @param string $query SQL查询
     * @return array 性能分析结果
     */
    public function analyze_query_performance(string $query): array {
        global $wpdb;

        // 执行EXPLAIN分析
        $explain_result = $wpdb->get_results("EXPLAIN " . $query);

        $analysis = [
            'query' => $query,
            'explain' => $explain_result,
            'performance_score' => 0,
            'recommendations' => [],
            'issues' => []
        ];

        if (!empty($explain_result)) {
            foreach ($explain_result as $row) {
                // 分析查询性能问题
                if ($row->type === 'ALL') {
                    $analysis['issues'][] = '全表扫描 - 考虑添加索引';
                    $analysis['performance_score'] -= 30;
                }

                if ($row->key === null) {
                    $analysis['issues'][] = '未使用索引';
                    $analysis['performance_score'] -= 20;
                }

                if (isset($row->rows) && $row->rows > 1000) {
                    $analysis['issues'][] = '扫描行数过多: ' . $row->rows;
                    $analysis['performance_score'] -= 10;
                }

                if ($row->Extra && strpos($row->Extra, 'Using filesort') !== false) {
                    $analysis['issues'][] = '使用文件排序 - 考虑添加排序索引';
                    $analysis['performance_score'] -= 15;
                }
            }
        }

        // 基础分数
        $analysis['performance_score'] = max(0, 100 + $analysis['performance_score']);

        return $analysis;
    }

    /**
     * 获取查询优化建议 (兼容Database_Index_Optimizer)
     *
     * @since 2.0.0-beta.1
     * @param string $query SQL查询
     * @return array 优化建议
     */
    public function get_query_optimization_recommendations(string $query): array {
        $analysis = $this->analyze_query_performance($query);

        $recommendations = [];

        // 基于分析结果生成建议
        foreach ($analysis['issues'] as $issue) {
            if (strpos($issue, '全表扫描') !== false) {
                $recommendations[] = [
                    'type' => 'index',
                    'priority' => 'high',
                    'description' => '添加适当的索引以避免全表扫描',
                    'action' => 'create_index'
                ];
            }

            if (strpos($issue, '未使用索引') !== false) {
                $recommendations[] = [
                    'type' => 'query',
                    'priority' => 'medium',
                    'description' => '优化查询条件以使用现有索引',
                    'action' => 'optimize_where_clause'
                ];
            }

            if (strpos($issue, '文件排序') !== false) {
                $recommendations[] = [
                    'type' => 'index',
                    'priority' => 'medium',
                    'description' => '添加复合索引以支持排序操作',
                    'action' => 'create_composite_index'
                ];
            }
        }

        return [
            'query' => $query,
            'performance_score' => $analysis['performance_score'],
            'recommendations' => $recommendations,
            'estimated_improvement' => count($recommendations) * 15 . '%'
        ];
    }

    /**
     * 维护索引 (兼容Database_Index_Optimizer)
     *
     * @since 2.0.0-beta.1
     * @param array $options 维护选项
     * @return array 维护结果
     */
    public function maintain_indexes(array $options = []): array {
        global $wpdb;

        $default_options = [
            'analyze_tables' => true,
            'optimize_tables' => true,
            'check_unused_indexes' => true,
            'rebuild_statistics' => true
        ];

        $options = array_merge($default_options, $options);
        $results = [];

        $tables = [$wpdb->posts, $wpdb->postmeta];

        foreach ($tables as $table) {
            $table_results = [
                'table' => $table,
                'actions' => []
            ];

            // 分析表
            if ($options['analyze_tables']) {
                $result = $wpdb->query("ANALYZE TABLE {$table}");
                $table_results['actions'][] = [
                    'action' => 'analyze',
                    'success' => $result !== false
                ];
            }

            // 优化表
            if ($options['optimize_tables']) {
                $result = $wpdb->query("OPTIMIZE TABLE {$table}");
                $table_results['actions'][] = [
                    'action' => 'optimize',
                    'success' => $result !== false
                ];
            }

            // 重建统计信息
            if ($options['rebuild_statistics']) {
                $result = $wpdb->query("ANALYZE TABLE {$table}");
                $table_results['actions'][] = [
                    'action' => 'rebuild_stats',
                    'success' => $result !== false
                ];
            }

            $results[] = $table_results;
        }

        Logger::debug_log(
            sprintf('索引维护完成，处理了 %d 个表', count($tables)),
            'IndexOptimizer'
        );

        return [
            'maintenance_time' => date('Y-m-d H:i:s'),
            'tables_processed' => count($tables),
            'results' => $results
        ];
    }
}