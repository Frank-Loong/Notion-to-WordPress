<?php
declare(strict_types=1);

namespace NTWP\Infrastructure\Database;

use NTWP\Core\Foundation\Logger;

/**
 * 数据库查询构建器
 *
 * 提供优化的批量查询功能，替代Database_Helper的查询职责
 * 专注于高性能的数据库操作，使用索引优化和批量处理
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
class QueryBuilder {
    
    /**
     * 批量获取多个Notion页面ID对应的WordPress文章ID
     *
     * 使用优化的SQL查询和数据库索引提升性能
     * 不使用缓存，确保数据实时性
     *
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

        // 优化：使用WHERE IN替代多次单独查询
        $placeholders = implode(',', array_fill(0, count($notion_ids), '%s'));
        $query = $wpdb->prepare(
            "SELECT pm.meta_value as notion_id, pm.post_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
            AND pm.meta_value IN ($placeholders)
            AND p.post_status != 'trash'",
            '_notion_page_id',
            ...$notion_ids
        );

        $results = $wpdb->get_results($query);

        if ($results) {
            foreach ($results as $row) {
                $mapping[$row->notion_id] = intval($row->post_id);
            }

            Logger::debug_log(
                sprintf('批量查询结果: 找到 %d 个有效文章', count($results)),
                'QueryBuilder'
            );
        }

        return $mapping;
    }

    /**
     * 批量获取文章元数据
     *
     * @param array $post_ids 文章ID数组
     * @param string $meta_key 元数据键名，空字符串获取所有
     * @return array 元数据映射数组
     */
    public static function batch_get_post_meta(array $post_ids, string $meta_key = ''): array {
        if (empty($post_ids)) {
            return [];
        }

        global $wpdb;
        
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        
        if (!empty($meta_key)) {
            $prepare_args = array_merge($post_ids, [$meta_key]);
            $query = $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value 
                FROM {$wpdb->postmeta} 
                WHERE post_id IN ($placeholders) 
                AND meta_key = %s",
                $prepare_args
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value 
                FROM {$wpdb->postmeta} 
                WHERE post_id IN ($placeholders)",
                $post_ids
            );
        }

        $results = $wpdb->get_results($query);
        $meta_data = [];

        foreach ($results as $row) {
            $meta_data[$row->post_id][$row->meta_key] = $row->meta_value;
        }

        return $meta_data;
    }

    /**
     * 批量更新文章元数据
     *
     * @param array $updates 更新数据 [post_id => [meta_key => meta_value]]
     * @return array 操作结果
     */
    public static function batch_update_post_meta(array $updates): array {
        if (empty($updates)) {
            return ['success' => 0, 'errors' => []];
        }

        $success_count = 0;
        $errors = [];

        foreach ($updates as $post_id => $meta_data) {
            foreach ($meta_data as $meta_key => $meta_value) {
                $result = update_post_meta($post_id, $meta_key, $meta_value);
                
                if ($result !== false) {
                    $success_count++;
                } else {
                    $errors[] = "Failed to update meta for post {$post_id}, key {$meta_key}";
                }
            }
        }

        Logger::debug_log(
            sprintf('批量更新元数据: %d 成功, %d 错误', $success_count, count($errors)),
            'QueryBuilder'
        );

        return [
            'success' => $success_count,
            'errors' => $errors
        ];
    }

    /**
     * 优化查询：获取需要同步的文章
     *
     * @param string $since 时间戳，获取此时间之后修改的文章
     * @return array 需要同步的文章ID数组
     */
    public static function get_posts_needing_sync(string $since = ''): array {
        global $wpdb;

        $where_clause = "WHERE pm.meta_key = '_notion_page_id' AND p.post_status = 'publish'";
        
        if (!empty($since)) {
            $where_clause .= $wpdb->prepare(" AND p.post_modified > %s", $since);
        }

        $query = "
            SELECT p.ID, pm.meta_value as notion_id, p.post_modified
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            {$where_clause}
            ORDER BY p.post_modified DESC
        ";

        return $wpdb->get_results($query, ARRAY_A) ?: [];
    }
}
