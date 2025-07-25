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
}
