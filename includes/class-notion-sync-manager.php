<?php
declare(strict_types=1);

/**
 * Notion 同步管理器类
 * 
 * 专门处理增量同步、时间戳管理和同步状态跟踪功能。负责管理同步状态、
 * 时间戳比较、增量同步过滤等核心同步逻辑，提升同步效率和准确性。
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

class Notion_Sync_Manager {

    /**
     * 时间戳比较的容错误差（秒）
     * 
     * @since 2.0.0-beta.1
     * @var int
     */
    private static int $timestamp_tolerance = 1;

    /**
     * 过滤页面进行增量同步
     *
     * @since 2.0.0-beta.1
     * @param array $pages 所有Notion页面
     * @return array 需要同步的页面
     */
    public static function filter_pages_for_incremental_sync(array $pages): array {
        if (empty($pages)) {
            return [];
        }

        // 提取所有页面ID
        $notion_ids = array_map(function($page) {
            return $page['id'];
        }, $pages);

        // 批量获取同步时间
        $sync_times = self::batch_get_sync_times($notion_ids);

        $pages_to_sync = [];

        foreach ($pages as $page) {
            $page_id = $page['id'];
            $notion_last_edited = $page['last_edited_time'] ?? '';

            if (empty($notion_last_edited)) {
                // 如果没有编辑时间，默认需要同步
                $pages_to_sync[] = $page;
                continue;
            }

            // 获取本地记录的最后同步时间（从批量查询结果中获取）
            $local_last_sync = $sync_times[$page_id] ?? '';

            if (empty($local_last_sync)) {
                // 新页面，需要同步
                Notion_To_WordPress_Helper::debug_log(
                    "新页面需要同步: {$page_id}",
                    'Incremental Sync'
                );
                $pages_to_sync[] = $page;
                continue;
            }

            // 比较时间戳，判断是否需要同步
            if (self::should_sync_page($notion_last_edited, $local_last_sync)) {
                Notion_To_WordPress_Helper::debug_log(
                    "页面有更新需要同步: {$page_id}, Notion: {$notion_last_edited}, Local: {$local_last_sync}",
                    'Incremental Sync'
                );
                $pages_to_sync[] = $page;
            }
        }

        Notion_To_WordPress_Helper::info_log(
            sprintf('增量同步检测完成，总页面: %d, 需要同步: %d', count($pages), count($pages_to_sync)),
            'Incremental Sync'
        );

        return $pages_to_sync;
    }

    /**
     * 判断页面是否需要同步
     *
     * @since 2.0.0-beta.1
     * @param string $notion_last_edited Notion最后编辑时间
     * @param string $local_last_sync 本地最后同步时间
     * @return bool 是否需要同步
     */
    private static function should_sync_page(string $notion_last_edited, string $local_last_sync): bool {
        // 比较时间戳（统一转换为UTC时间戳）
        $notion_timestamp = strtotime($notion_last_edited);

        // 确保本地时间也是UTC格式进行比较
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $local_last_sync)) {
            // 如果是MySQL格式，假设为UTC时间
            $local_timestamp = strtotime($local_last_sync . ' UTC');
        } else {
            // 如果是ISO格式，直接转换
            $local_timestamp = strtotime($local_last_sync);
        }

        // 使用容错的时间比较，允许指定秒数的误差
        return $notion_timestamp > $local_timestamp + self::$timestamp_tolerance;
    }

    /**
     * 更新页面同步时间
     *
     * @since 2.0.0-beta.1
     * @param string $page_id Notion页面ID
     * @param string $notion_last_edited Notion最后编辑时间
     */
    public static function update_page_sync_time(string $page_id, string $notion_last_edited): void {
        // 获取对应的WordPress文章ID
        $post_id = self::get_post_id_by_notion_id($page_id);

        if (!$post_id) {
            return;
        }

        // 更新同步时间戳（统一使用UTC时间）
        $current_utc_time = gmdate('Y-m-d H:i:s');

        // 使用事务包装批量更新
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            update_post_meta($post_id, '_notion_last_sync_time', $current_utc_time);
            update_post_meta($post_id, '_notion_last_edited_time', $notion_last_edited);
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        Notion_To_WordPress_Helper::debug_log(
            "更新页面同步时间: {$page_id}, 编辑时间: {$notion_last_edited}",
            'Incremental Sync'
        );
    }

    /**
     * 批量更新页面同步时间
     *
     * @since 2.0.0-beta.1
     * @param array $page_updates [page_id => notion_last_edited] 映射
     */
    public static function batch_update_page_sync_times(array $page_updates): void {
        if (empty($page_updates)) {
            return;
        }

        // 批量获取WordPress文章ID
        $notion_ids = array_keys($page_updates);
        $post_mapping = self::batch_get_posts_by_notion_ids($notion_ids);

        $current_utc_time = gmdate('Y-m-d H:i:s');

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($page_updates as $page_id => $notion_last_edited) {
                $post_id = $post_mapping[$page_id] ?? 0;

                if ($post_id) {
                    update_post_meta($post_id, '_notion_last_sync_time', $current_utc_time);
                    update_post_meta($post_id, '_notion_last_edited_time', $notion_last_edited);
                }
            }
            $wpdb->query('COMMIT');

            Notion_To_WordPress_Helper::debug_log(
                "批量更新页面同步时间完成，更新了 " . count($page_updates) . " 个页面",
                'Incremental Sync'
            );
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            Notion_To_WordPress_Helper::error_log(
                "批量更新页面同步时间失败: " . $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * 批量获取页面同步时间
     *
     * @since 2.0.0-beta.1
     * @param array $notion_ids Notion页面ID数组
     * @return array [notion_id => sync_time] 映射
     */
    public static function batch_get_sync_times(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        // 检查会话级缓存
        $cache_key = 'batch_sync_times_' . md5(serialize($notion_ids));
        $cached_result = Notion_Cache_Manager::get_batch_cache_value($cache_key);
        if ($cached_result !== null) {
            return $cached_result;
        }

        global $wpdb;

        // 准备SQL占位符
        $placeholders = implode(',', array_fill(0, count($notion_ids), '%s'));

        // 执行批量查询
        $query = $wpdb->prepare(
            "SELECT pm1.meta_value as notion_id, pm2.meta_value as sync_time
            FROM {$wpdb->postmeta} pm1
            INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
            WHERE pm1.meta_key = '_notion_page_id'
            AND pm1.meta_value IN ($placeholders)
            AND pm2.meta_key = '_notion_last_sync_time'",
            $notion_ids
        );

        $results = $wpdb->get_results($query);

        // 构建映射数组
        $mapping = array_fill_keys($notion_ids, ''); // 默认所有ID映射为空字符串

        foreach ($results as $row) {
            $mapping[$row->notion_id] = $row->sync_time;
        }

        // 存储到会话级缓存
        Notion_Cache_Manager::set_batch_cache_value($cache_key, $mapping);

        return $mapping;
    }

    /**
     * 获取单个页面的最后同步时间
     *
     * @since 2.0.0-beta.1
     * @param string $page_id Notion页面ID
     * @return string 最后同步时间
     */
    public static function get_page_last_sync_time(string $page_id): string {
        $post_id = self::get_post_id_by_notion_id($page_id);

        if (!$post_id) {
            return '';
        }

        return get_post_meta($post_id, '_notion_last_sync_time', true) ?: '';
    }

    /**
     * 检查页面是否存在于WordPress中
     *
     * @since 2.0.0-beta.1
     * @param string $page_id Notion页面ID
     * @return bool 是否存在
     */
    public static function page_exists_in_wordpress(string $page_id): bool {
        return self::get_post_id_by_notion_id($page_id) > 0;
    }

    /**
     * 获取同步统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 同步统计数据
     */
    public static function get_sync_stats(): array {
        global $wpdb;

        // 获取总的已同步页面数
        $total_synced = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_notion_page_id'"
        );

        // 获取最近同步时间
        $last_sync_time = $wpdb->get_var(
            "SELECT MAX(meta_value)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_notion_last_sync_time'"
        );

        return [
            'total_synced_pages' => (int)$total_synced,
            'last_sync_time' => $last_sync_time ?: '',
            'cache_stats' => Notion_Cache_Manager::get_session_cache_stats()
        ];
    }

    // ==================== 辅助方法 ====================

    /**
     * 根据Notion页面ID获取WordPress文章ID
     *
     * @since 2.0.0-beta.1
     * @param string $notion_id Notion页面ID
     * @return int WordPress文章ID，不存在返回0
     */
    private static function get_post_id_by_notion_id(string $notion_id): int {
        // 检查会话级缓存
        $cache_key = 'post_by_notion_id_' . $notion_id;
        $cached_result = Notion_Cache_Manager::get_db_cache_value($cache_key);
        if ($cached_result !== null) {
            return $cached_result;
        }

        global $wpdb;

        // 使用直接SQL查询而不是get_posts，减少开销
        $query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_notion_page_id' AND meta_value = %s
            LIMIT 1",
            $notion_id
        );

        $post_id = $wpdb->get_var($query);
        $result = $post_id ? (int)$post_id : 0;

        // 存储到会话级缓存
        Notion_Cache_Manager::set_db_cache_value($cache_key, $result);

        return $result;
    }

    /**
     * 批量获取WordPress文章ID映射
     *
     * @since 2.0.0-beta.1
     * @param array $notion_ids Notion页面ID数组
     * @return array [notion_id => post_id] 映射
     */
    private static function batch_get_posts_by_notion_ids(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        // 检查会话级缓存
        $cache_key = 'batch_posts_' . md5(serialize($notion_ids));
        $cached_result = Notion_Cache_Manager::get_batch_cache_value($cache_key);
        if ($cached_result !== null) {
            return $cached_result;
        }

        // 检查单个缓存，减少需要查询的ID
        $uncached_ids = [];
        $mapping = [];

        foreach ($notion_ids as $notion_id) {
            $single_cache_key = 'post_by_notion_id_' . $notion_id;
            $cached_value = Notion_Cache_Manager::get_db_cache_value($single_cache_key);
            if ($cached_value !== null) {
                $mapping[$notion_id] = $cached_value;
            } else {
                $uncached_ids[] = $notion_id;
                $mapping[$notion_id] = 0; // 默认值
            }
        }

        // 如果有未缓存的ID，进行批量查询
        if (!empty($uncached_ids)) {
            global $wpdb;

            $placeholders = implode(',', array_fill(0, count($uncached_ids), '%s'));
            $query = $wpdb->prepare(
                "SELECT meta_value as notion_id, post_id
                FROM {$wpdb->postmeta}
                WHERE meta_key = '_notion_page_id'
                AND meta_value IN ($placeholders)",
                $uncached_ids
            );

            $results = $wpdb->get_results($query);

            // 更新映射和单个缓存
            foreach ($results as $row) {
                $mapping[$row->notion_id] = (int)$row->post_id;
                $single_cache_key = 'post_by_notion_id_' . $row->notion_id;
                Notion_Cache_Manager::set_db_cache_value($single_cache_key, (int)$row->post_id);
            }
        }

        // 存储到批量查询缓存
        Notion_Cache_Manager::set_batch_cache_value($cache_key, $mapping);

        return $mapping;
    }

    // ==================== 配置管理 ====================

    /**
     * 设置时间戳比较的容错误差
     *
     * @since 2.0.0-beta.1
     * @param int $tolerance 容错误差（秒）
     */
    public static function set_timestamp_tolerance(int $tolerance): void {
        self::$timestamp_tolerance = max(0, $tolerance);
    }

    /**
     * 获取时间戳比较的容错误差
     *
     * @since 2.0.0-beta.1
     * @return int 容错误差（秒）
     */
    public static function get_timestamp_tolerance(): int {
        return self::$timestamp_tolerance;
    }

    /**
     * 清理同步相关的缓存
     *
     * @since 2.0.0-beta.1
     */
    public static function clear_sync_cache(): void {
        // 清理会话级缓存中的同步相关数据
        Notion_Cache_Manager::clear_session_cache();

        Notion_To_WordPress_Helper::debug_log(
            '清理同步缓存完成',
            'Sync Cache'
        );
    }

    /**
     * 向后兼容：获取页面数据（委托给API）
     *
     * 为了保持与原有代码的兼容性
     *
     * @since 2.0.0-beta.1
     * @param string $page_id 页面ID
     * @param Notion_API $notion_api Notion API实例
     * @return array 页面数据
     * @throws Exception 如果获取失败
     */
    public static function get_page_data(string $page_id, Notion_API $notion_api): array {
        return $notion_api->get_page($page_id);
    }
}
