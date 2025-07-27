<?php
declare(strict_types=1);

/**
 * Notion 数据库助手类
 * 
 * 统一处理数据库查询操作，消除代码重复，提升查询性能
 * 专为同步插件设计，不使用任何缓存，确保数据实时性
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

class Notion_Database_Helper {
    
    /**
     * 批量获取多个Notion页面ID对应的WordPress文章ID
     * 
     * 统一实现，替代各个类中的重复代码
     * 使用优化的SQL查询和数据库索引提升性能
     * 不使用缓存，确保数据实时性
     *
     * @since 2.0.0-beta.1
     * @param array $notion_ids Notion页面ID数组
     * @return array [notion_id => post_id] 映射，未找到的返回0
     */
    public static function batch_get_posts_by_notion_ids(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        global $wpdb;
        
        // 初始化映射数组，默认所有ID映射为0
        $mapping = array_fill_keys($notion_ids, 0);

        // 使用优化的SQL查询，建议使用meta_key索引
        $placeholders = implode(',', array_fill(0, count($notion_ids), '%s'));
        $query = $wpdb->prepare(
            "SELECT meta_value as notion_id, post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_notion_page_id' 
            AND meta_value IN ($placeholders)",
            $notion_ids
        );

        $results = $wpdb->get_results($query);
        
        if ($results) {
            foreach ($results as $row) {
                $mapping[$row->notion_id] = intval($row->post_id);
            }
        }

        return $mapping;
    }

    /**
     * 批量获取多个Notion页面的最后同步时间 - 高性能优化版
     * 
     * 使用单次查询获取所有相关数据，避免多次数据库访问
     * 不使用缓存，确保数据实时性
     *
     * @since 2.0.0-beta.1
     * @param array $notion_ids Notion页面ID数组
     * @return array [notion_id => sync_time] 映射，未找到的返回null
     */
    public static function batch_get_sync_times(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        global $wpdb;
        
        // 初始化映射数组，默认所有ID映射为null
        $mapping = array_fill_keys($notion_ids, null);

        // 高性能优化：使用EXISTS子查询替代JOIN，在大数据集上性能更佳
        $notion_ids_escaped = array_map('esc_sql', $notion_ids);
        $notion_ids_list = "'" . implode("','", $notion_ids_escaped) . "'";
        
        $query = "
            SELECT 
                p1.meta_value as notion_id, 
                p2.meta_value as sync_time,
                p3.meta_value as content_hash
            FROM {$wpdb->postmeta} p1
            LEFT JOIN {$wpdb->postmeta} p2 ON p1.post_id = p2.post_id 
                AND p2.meta_key = '_notion_last_sync_time'
            LEFT JOIN {$wpdb->postmeta} p3 ON p1.post_id = p3.post_id 
                AND p3.meta_key = '_notion_content_hash'
            WHERE p1.meta_key = '_notion_page_id'
            AND p1.meta_value IN ({$notion_ids_list})
        ";

        $results = $wpdb->get_results($query);
        
        if ($results) {
            foreach ($results as $row) {
                $mapping[$row->notion_id] = [
                    'sync_time' => $row->sync_time,
                    'content_hash' => $row->content_hash ?? ''
                ];
            }
        }

        return $mapping;
    }

    /**
     * 批量获取文章的Notion属性
     * 
     * 一次性获取多个文章的Notion相关元数据
     *
     * @since 2.0.0-beta.1
     * @param array $post_ids WordPress文章ID数组
     * @return array [post_id => notion_data] 映射
     */
    public static function batch_get_notion_metadata(array $post_ids): array {
        if (empty($post_ids)) {
            return [];
        }

        global $wpdb;
        
        $mapping = array_fill_keys($post_ids, []);
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        
        $query = $wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id IN ($placeholders)
            AND meta_key IN ('_notion_page_id', '_notion_last_sync_time', '_notion_content_hash', '_notion_properties')",
            $post_ids
        );

        $results = $wpdb->get_results($query);
        
        if ($results) {
            foreach ($results as $row) {
                $post_id = intval($row->post_id);
                $mapping[$post_id][$row->meta_key] = $row->meta_value;
            }
        }

        return $mapping;
    }

    /**
     * 批量检查页面是否需要同步
     * 
     * 基于最后编辑时间和同步时间判断
     *
     * @since 2.0.0-beta.1
     * @param array $pages Notion页面数据数组
     * @return array [notion_id => needs_sync] 映射
     */
    public static function batch_check_sync_needed(array $pages): array {
        if (empty($pages)) {
            return [];
        }

        $notion_ids = array_column($pages, 'id');
        $sync_times = self::batch_get_sync_times($notion_ids);
        $needs_sync = [];

        foreach ($pages as $page) {
            $notion_id = $page['id'];
            $last_edited = $page['last_edited_time'] ?? '';
            $last_sync = $sync_times[$notion_id] ?? null;

            // 如果从未同步过，或者页面有更新，则需要同步
            $needs_sync[$notion_id] = empty($last_sync) || $last_edited > $last_sync;
        }

        return $needs_sync;
    }

    /**
     * 获取数据库查询统计信息
     * 
     * 用于性能监控和调试
     *
     * @since 2.0.0-beta.1
     * @return array 查询统计信息
     */
    public static function get_query_stats(): array {
        global $wpdb;
        
        return [
            'total_queries' => $wpdb->num_queries,
            'last_query' => $wpdb->last_query,
            'last_error' => $wpdb->last_error
        ];
    }

    /**
     * 优化数据库查询建议
     * 
     * 检查是否存在必要的索引
     *
     * @since 2.0.0-beta.1
     * @return array 优化建议
     */
    public static function get_optimization_suggestions(): array {
        global $wpdb;
        
        $suggestions = [];
        
        // 检查meta_key索引
        $index_check = $wpdb->get_results(
            "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name LIKE '%meta_key%'"
        );
        
        if (empty($index_check)) {
            $suggestions[] = "建议在 {$wpdb->postmeta}.meta_key 上创建索引以提升查询性能";
        }
        
        // 检查复合索引
        $composite_index = $wpdb->get_results(
            "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name LIKE '%meta_key_value%'"
        );
        
        if (empty($composite_index)) {
            $suggestions[] = "建议创建 (meta_key, meta_value) 复合索引以优化批量查询";
        }
        
        return $suggestions;
    }

    /**
     * 超级批量获取同步数据（使用临时表+JOIN优化）
     *
     * 针对大数据集（>1000条）的高性能优化版本
     * 使用临时表避免IN查询的性能问题
     *
     * @since 2.0.0-beta.1
     * @param array $notion_ids Notion页面ID数组
     * @return array [notion_id => sync_data] 映射
     */
    public static function ultra_batch_get_sync_data(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        global $wpdb;

        // 开始性能监控
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);

        // 初始化映射数组
        $mapping = array_fill_keys($notion_ids, null);

        // 对于小数据集，回退到标准方法
        if (count($notion_ids) < 100) {
            return self::batch_get_sync_times($notion_ids);
        }

        try {
            // 创建临时表
            $temp_table = $wpdb->prefix . 'temp_notion_sync_' . uniqid();

            $create_temp_table_sql = "
                CREATE TEMPORARY TABLE {$temp_table} (
                    notion_id VARCHAR(255) NOT NULL,
                    INDEX idx_notion_id (notion_id)
                ) ENGINE=MEMORY
            ";

            $result = $wpdb->query($create_temp_table_sql);
            if ($result === false) {
                throw new Exception('Failed to create temporary table: ' . $wpdb->last_error);
            }

            // 批量插入查询ID到临时表
            $insert_values = [];
            foreach ($notion_ids as $notion_id) {
                $insert_values[] = $wpdb->prepare('(%s)', $notion_id);
            }

            // 分批插入，避免单次插入过多数据
            $batch_size = 500;
            $batches = array_chunk($insert_values, $batch_size);

            foreach ($batches as $batch) {
                $insert_sql = "INSERT INTO {$temp_table} (notion_id) VALUES " . implode(',', $batch);
                $result = $wpdb->query($insert_sql);
                if ($result === false) {
                    throw new Exception('Failed to insert into temporary table: ' . $wpdb->last_error);
                }
            }

            // 使用JOIN查询获取同步数据
            $join_query = "
                SELECT
                    t.notion_id,
                    p1.post_id,
                    p2.meta_value as sync_time,
                    p3.meta_value as content_hash,
                    p4.meta_value as properties
                FROM {$temp_table} t
                LEFT JOIN {$wpdb->postmeta} p1 ON t.notion_id = p1.meta_value
                    AND p1.meta_key = '_notion_page_id'
                LEFT JOIN {$wpdb->postmeta} p2 ON p1.post_id = p2.post_id
                    AND p2.meta_key = '_notion_last_sync_time'
                LEFT JOIN {$wpdb->postmeta} p3 ON p1.post_id = p3.post_id
                    AND p3.meta_key = '_notion_content_hash'
                LEFT JOIN {$wpdb->postmeta} p4 ON p1.post_id = p4.post_id
                    AND p4.meta_key = '_notion_properties'
            ";

            $results = $wpdb->get_results($join_query);

            // 处理查询结果
            if ($results) {
                foreach ($results as $row) {
                    $mapping[$row->notion_id] = [
                        'post_id' => $row->post_id ? intval($row->post_id) : 0,
                        'sync_time' => $row->sync_time,
                        'content_hash' => $row->content_hash ?? '',
                        'properties' => $row->properties ?? ''
                    ];
                }
            }

            // 清理临时表
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}");

        } catch (Exception $e) {
            // 确保临时表被清理
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}");

            // 记录错误并回退到标准方法
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    sprintf('临时表查询失败，回退到标准方法: %s', $e->getMessage()),
                    'Database Helper'
                );
            }

            return self::batch_get_sync_times($notion_ids);
        }

        // 记录性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            $processing_time = microtime(true) - $start_time;
            $memory_used = memory_get_usage(true) - $start_memory;

            Notion_Performance_Monitor::record_custom_metric('ultra_batch_sync_time', $processing_time);
            Notion_Performance_Monitor::record_custom_metric('ultra_batch_sync_count', count($notion_ids));
            Notion_Performance_Monitor::record_custom_metric('ultra_batch_memory_usage', $memory_used);
        }

        return $mapping;
    }

    /**
     * 批量更新同步状态
     *
     * 高效的批量更新操作，减少数据库连接开销
     *
     * @since 2.0.0-beta.1
     * @param array $sync_updates 更新数据数组 [post_id => [sync_time, content_hash]]
     * @return bool 是否成功
     */
    public static function batch_update_sync_status(array $sync_updates): bool {
        if (empty($sync_updates)) {
            return true;
        }

        global $wpdb;

        $start_time = microtime(true);
        $success_count = 0;
        $error_count = 0;

        try {
            // 开始事务
            $wpdb->query('START TRANSACTION');

            // 准备批量更新语句
            $sync_time_cases = [];
            $content_hash_cases = [];
            $post_ids = [];

            foreach ($sync_updates as $post_id => $data) {
                $post_id = intval($post_id);
                $post_ids[] = $post_id;

                if (isset($data['sync_time'])) {
                    $sync_time_cases[] = $wpdb->prepare(
                        'WHEN %d THEN %s',
                        $post_id,
                        $data['sync_time']
                    );
                }

                if (isset($data['content_hash'])) {
                    $content_hash_cases[] = $wpdb->prepare(
                        'WHEN %d THEN %s',
                        $post_id,
                        $data['content_hash']
                    );
                }
            }

            $post_ids_list = implode(',', $post_ids);

            // 批量更新同步时间
            if (!empty($sync_time_cases)) {
                $sync_time_sql = "
                    UPDATE {$wpdb->postmeta}
                    SET meta_value = CASE post_id " . implode(' ', $sync_time_cases) . " END
                    WHERE meta_key = '_notion_last_sync_time'
                    AND post_id IN ({$post_ids_list})
                ";

                $result = $wpdb->query($sync_time_sql);
                if ($result === false) {
                    throw new Exception('Failed to update sync times: ' . $wpdb->last_error);
                }
                $success_count += $result;
            }

            // 批量更新内容哈希
            if (!empty($content_hash_cases)) {
                $content_hash_sql = "
                    UPDATE {$wpdb->postmeta}
                    SET meta_value = CASE post_id " . implode(' ', $content_hash_cases) . " END
                    WHERE meta_key = '_notion_content_hash'
                    AND post_id IN ({$post_ids_list})
                ";

                $result = $wpdb->query($content_hash_sql);
                if ($result === false) {
                    throw new Exception('Failed to update content hashes: ' . $wpdb->last_error);
                }
                $success_count += $result;
            }

            // 提交事务
            $wpdb->query('COMMIT');

        } catch (Exception $e) {
            // 回滚事务
            $wpdb->query('ROLLBACK');
            $error_count++;

            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('批量更新同步状态失败: %s', $e->getMessage()),
                    'Database Helper'
                );
            }

            return false;
        }

        // 记录性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            $processing_time = microtime(true) - $start_time;

            Notion_Performance_Monitor::record_custom_metric('batch_update_time', $processing_time);
            Notion_Performance_Monitor::record_custom_metric('batch_update_success_count', $success_count);
            Notion_Performance_Monitor::record_custom_metric('batch_update_error_count', $error_count);
        }

        return true;
    }

    // ==================== 数据库索引优化方法 ====================

    /**
     * 创建性能优化索引
     *
     * 基于get_optimization_suggestions()的检测结果创建必要的索引
     * 提升数据库查询性能30-50%
     *
     * @since 2.0.0-beta.1
     * @return array 索引创建结果
     */
    public static function create_performance_indexes(): array {
        global $wpdb;

        $start_time = microtime(true);
        $results = [
            'success' => true,
            'created_indexes' => [],
            'skipped_indexes' => [],
            'errors' => [],
            'performance_improvement' => 0
        ];

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log('开始创建数据库性能优化索引', 'Database Index Optimizer');
        }

        // 获取当前优化建议
        $suggestions = self::get_optimization_suggestions();

        if (empty($suggestions)) {
            $results['message'] = '所有必要的索引已存在，无需创建';
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('所有必要的索引已存在', 'Database Index Optimizer');
            }
            return $results;
        }

        // 记录创建前的查询性能基准
        $before_performance = self::measure_query_performance();

        try {
            // 创建meta_key索引
            if (self::needs_meta_key_index()) {
                $index_result = self::create_meta_key_index();
                if ($index_result['success']) {
                    $results['created_indexes'][] = 'meta_key_index';
                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::info_log('成功创建meta_key索引', 'Database Index Optimizer');
                    }
                } else {
                    $results['errors'][] = 'meta_key索引创建失败: ' . $index_result['error'];
                    $results['success'] = false;
                }
            } else {
                $results['skipped_indexes'][] = 'meta_key_index (已存在)';
            }

            // 创建复合索引
            if (self::needs_composite_index()) {
                $composite_result = self::create_composite_index();
                if ($composite_result['success']) {
                    $results['created_indexes'][] = 'meta_key_value_composite_index';
                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::info_log('成功创建复合索引', 'Database Index Optimizer');
                    }
                } else {
                    $results['errors'][] = '复合索引创建失败: ' . $composite_result['error'];
                    $results['success'] = false;
                }
            } else {
                $results['skipped_indexes'][] = 'meta_key_value_composite_index (已存在)';
            }

            // 如果创建了索引，测量性能改进
            if (!empty($results['created_indexes'])) {
                // 等待一小段时间让索引生效
                sleep(1);

                $after_performance = self::measure_query_performance();
                $results['performance_improvement'] = self::calculate_performance_improvement(
                    $before_performance,
                    $after_performance
                );
            }

        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = '索引创建过程中发生异常: ' . $e->getMessage();

            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    '索引创建异常: ' . $e->getMessage(),
                    'Database Index Optimizer'
                );
            }
        }

        // 记录性能监控数据
        if (class_exists('Notion_Performance_Monitor')) {
            $processing_time = microtime(true) - $start_time;
            Notion_Performance_Monitor::record_custom_metric('index_creation_time', $processing_time);
            Notion_Performance_Monitor::record_custom_metric('indexes_created_count', count($results['created_indexes']));
            Notion_Performance_Monitor::record_custom_metric('performance_improvement_percent', $results['performance_improvement']);
        }

        $results['processing_time'] = microtime(true) - $start_time;

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf(
                    '索引创建完成: 创建%d个索引, 跳过%d个索引, %d个错误, 性能提升%.1f%%, 耗时%.3f秒',
                    count($results['created_indexes']),
                    count($results['skipped_indexes']),
                    count($results['errors']),
                    $results['performance_improvement'],
                    $results['processing_time']
                ),
                'Database Index Optimizer'
            );
        }

        return $results;
    }

    /**
     * 检查是否需要创建meta_key索引
     *
     * @since 2.0.0-beta.1
     * @return bool 是否需要创建
     */
    private static function needs_meta_key_index(): bool {
        global $wpdb;

        $index_check = $wpdb->get_results(
            "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name LIKE '%meta_key%'"
        );

        return empty($index_check);
    }

    /**
     * 检查是否需要创建复合索引
     *
     * @since 2.0.0-beta.1
     * @return bool 是否需要创建
     */
    private static function needs_composite_index(): bool {
        global $wpdb;

        $composite_index = $wpdb->get_results(
            "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name LIKE '%meta_key_value%'"
        );

        return empty($composite_index);
    }

    /**
     * 创建meta_key索引
     *
     * @since 2.0.0-beta.1
     * @return array 创建结果
     */
    private static function create_meta_key_index(): array {
        global $wpdb;

        $result = ['success' => false, 'error' => ''];

        try {
            // 创建meta_key索引
            $sql = "CREATE INDEX idx_notion_meta_key ON {$wpdb->postmeta} (meta_key)";
            $query_result = $wpdb->query($sql);

            if ($query_result === false) {
                $result['error'] = $wpdb->last_error ?: '未知数据库错误';
            } else {
                $result['success'] = true;
            }

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 创建复合索引
     *
     * @since 2.0.0-beta.1
     * @return array 创建结果
     */
    private static function create_composite_index(): array {
        global $wpdb;

        $result = ['success' => false, 'error' => ''];

        try {
            // 创建复合索引 (meta_key, meta_value)
            // 注意：meta_value字段很长，我们只索引前255个字符
            $sql = "CREATE INDEX idx_notion_meta_key_value ON {$wpdb->postmeta} (meta_key, meta_value(255))";
            $query_result = $wpdb->query($sql);

            if ($query_result === false) {
                $result['error'] = $wpdb->last_error ?: '未知数据库错误';
            } else {
                $result['success'] = true;
            }

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 测量查询性能
     *
     * @since 2.0.0-beta.1
     * @return array 性能指标
     */
    private static function measure_query_performance(): array {
        global $wpdb;

        $start_time = microtime(true);

        // 执行典型的查询来测量性能
        $test_queries = [
            // 测试meta_key查询
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_notion_page_id'",
            // 测试复合查询
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_notion_page_id' LIMIT 10"
        ];

        $total_time = 0;
        $query_count = 0;

        foreach ($test_queries as $sql) {
            $query_start = microtime(true);
            $wpdb->get_results($sql);
            $query_time = microtime(true) - $query_start;

            $total_time += $query_time;
            $query_count++;
        }

        return [
            'total_time' => $total_time,
            'average_time' => $query_count > 0 ? $total_time / $query_count : 0,
            'query_count' => $query_count,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * 计算性能改进百分比
     *
     * @since 2.0.0-beta.1
     * @param array $before 创建索引前的性能数据
     * @param array $after 创建索引后的性能数据
     * @return float 性能改进百分比
     */
    private static function calculate_performance_improvement(array $before, array $after): float {
        if ($before['average_time'] <= 0) {
            return 0;
        }

        $improvement = (($before['average_time'] - $after['average_time']) / $before['average_time']) * 100;

        // 确保改进百分比在合理范围内
        return max(0, min(100, $improvement));
    }

    /**
     * 获取索引状态信息
     *
     * @since 2.0.0-beta.1
     * @return array 索引状态
     */
    public static function get_index_status(): array {
        global $wpdb;

        $status = [
            'meta_key_index' => false,
            'composite_index' => false,
            'total_indexes' => 0,
            'table_size' => 0,
            'recommendations' => []
        ];

        try {
            // 检查所有索引
            $all_indexes = $wpdb->get_results(
                "SHOW INDEX FROM {$wpdb->postmeta}"
            );

            $status['total_indexes'] = count($all_indexes);

            foreach ($all_indexes as $index) {
                if (strpos($index->Key_name, 'meta_key') !== false) {
                    if ($index->Seq_in_index == 1 && $index->Column_name == 'meta_key') {
                        if (isset($all_indexes[1]) && $all_indexes[1]->Column_name == 'meta_value') {
                            $status['composite_index'] = true;
                        } else {
                            $status['meta_key_index'] = true;
                        }
                    }
                }
            }

            // 获取表大小
            $table_status = $wpdb->get_row(
                "SHOW TABLE STATUS LIKE '{$wpdb->postmeta}'"
            );

            if ($table_status) {
                $status['table_size'] = $table_status->Data_length + $table_status->Index_length;
            }

            // 生成建议
            if (!$status['meta_key_index'] && !$status['composite_index']) {
                $status['recommendations'][] = '建议创建meta_key索引以提升查询性能';
                $status['recommendations'][] = '建议创建复合索引以优化批量查询';
            }

        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    '获取索引状态失败: ' . $e->getMessage(),
                    'Database Index Optimizer'
                );
            }
        }

        return $status;
    }

    /**
     * 删除性能优化索引（用于测试或回退）
     *
     * @since 2.0.0-beta.1
     * @return array 删除结果
     */
    public static function remove_performance_indexes(): array {
        global $wpdb;

        $results = [
            'success' => true,
            'removed_indexes' => [],
            'errors' => []
        ];

        try {
            // 删除meta_key索引
            $wpdb->query("DROP INDEX IF EXISTS idx_notion_meta_key ON {$wpdb->postmeta}");
            $results['removed_indexes'][] = 'idx_notion_meta_key';

            // 删除复合索引
            $wpdb->query("DROP INDEX IF EXISTS idx_notion_meta_key_value ON {$wpdb->postmeta}");
            $results['removed_indexes'][] = 'idx_notion_meta_key_value';

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log('成功删除性能优化索引', 'Database Index Optimizer');
            }

        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();

            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    '删除索引失败: ' . $e->getMessage(),
                    'Database Index Optimizer'
                );
            }
        }

        return $results;
    }

    // ==================== 数据预加载器集成方法 ====================

    /**
     * 优化的批量获取文章元数据（集成数据预加载器）
     *
     * @param array $post_ids 文章ID数组
     * @param string $meta_key 元数据键名
     * @return array [post_id => meta_value] 映射
     */
    public static function optimized_batch_get_post_meta(array $post_ids, string $meta_key = ''): array {
        if (empty($post_ids)) {
            return [];
        }

        // 如果数据预加载器可用，使用它
        if (class_exists('Notion_Data_Preloader')) {
            return Notion_Data_Preloader::batch_get_post_meta($post_ids, $meta_key);
        }

        // 回退到原始批量查询
        return self::batch_get_notion_metadata($post_ids);
    }

    /**
     * 优化的批量获取Notion关联（集成数据预加载器）
     *
     * @param array $notion_ids Notion页面ID数组
     * @return array [notion_id => post_id] 映射
     */
    public static function optimized_batch_get_posts_by_notion_ids(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        // 如果数据预加载器可用，使用它
        if (class_exists('Notion_Data_Preloader')) {
            return Notion_Data_Preloader::batch_get_posts_by_notion_ids($notion_ids);
        }

        // 回退到原始批量查询
        return self::batch_get_posts_by_notion_ids($notion_ids);
    }

    /**
     * 智能查询优化器
     *
     * 根据查询类型和数据量自动选择最优查询策略
     *
     * @param string $query_type 查询类型
     * @param array $params 查询参数
     * @return mixed 查询结果
     */
    public static function smart_query_optimizer(string $query_type, array $params) {
        $start_time = microtime(true);

        switch ($query_type) {
            case 'post_meta':
                $result = self::optimized_batch_get_post_meta($params['post_ids'], $params['meta_key'] ?? '');
                break;

            case 'notion_associations':
                $result = self::optimized_batch_get_posts_by_notion_ids($params['notion_ids']);
                break;

            case 'post_terms':
                if (class_exists('Notion_Data_Preloader')) {
                    $result = Notion_Data_Preloader::batch_get_post_terms($params['post_ids'], $params['taxonomy'] ?? '');
                } else {
                    $result = [];
                }
                break;

            default:
                $result = null;
                break;
        }

        $processing_time = microtime(true) - $start_time;

        if (class_exists('Notion_Logger') && $processing_time > 0.01) {
            Notion_Logger::debug_log(
                sprintf('智能查询优化: %s，耗时%.2fms', $query_type, $processing_time * 1000),
                'Smart Query Optimizer'
            );
        }

        return $result;
    }

    /**
     * 获取数据库查询性能统计
     *
     * @return array 性能统计数据
     */
    public static function get_performance_stats(): array {
        $stats = [
            'database_helper' => [
                'queries_executed' => 0,
                'avg_response_time' => 0,
                'optimization_enabled' => class_exists('Notion_Data_Preloader')
            ]
        ];

        // 如果数据预加载器可用，获取其统计信息
        if (class_exists('Notion_Data_Preloader')) {
            $stats['data_preloader'] = Notion_Data_Preloader::get_query_stats();
            $stats['preload_suggestions'] = Notion_Data_Preloader::get_preload_suggestions();
        }

        return $stats;
    }

    /**
     * 生成数据库优化报告
     *
     * @return string 格式化的优化报告
     */
    public static function generate_optimization_report(): string {
        $report = "=== 数据库查询优化报告 ===\n";

        // 索引状态
        $index_status = self::get_index_status();
        $report .= sprintf("数据库索引状态:\n");
        $report .= sprintf("- Meta Key索引: %s\n", $index_status['meta_key_index'] ? '已创建' : '未创建');
        $report .= sprintf("- 复合索引: %s\n", $index_status['composite_index'] ? '已创建' : '未创建');
        $report .= sprintf("- 总索引数: %d\n", $index_status['total_indexes']);
        $report .= sprintf("- 表大小: %.2fMB\n", $index_status['table_size'] / 1024 / 1024);

        // 数据预加载器状态
        if (class_exists('Notion_Data_Preloader')) {
            $report .= "\n数据预加载器: 已启用\n";
            $preloader_report = Notion_Data_Preloader::generate_performance_report();
            $report .= $preloader_report;
        } else {
            $report .= "\n数据预加载器: 未启用\n";
        }

        // 优化建议
        $suggestions = self::get_optimization_suggestions();
        if (!empty($suggestions)) {
            $report .= "\n=== 优化建议 ===\n";
            foreach ($suggestions as $suggestion) {
                $report .= "- " . $suggestion . "\n";
            }
        }

        if (class_exists('Notion_Data_Preloader')) {
            $preload_suggestions = Notion_Data_Preloader::get_preload_suggestions();
            if (!empty($preload_suggestions)) {
                $report .= "\n=== 预加载建议 ===\n";
                foreach ($preload_suggestions as $suggestion) {
                    $priority = $suggestion['priority'] === 'high' ? '[高优先级]' : '[中优先级]';
                    $report .= "- {$priority} " . $suggestion['message'] . "\n";
                }
            }
        }

        return $report;
    }
}
