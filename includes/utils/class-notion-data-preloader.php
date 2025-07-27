<?php
declare(strict_types=1);

/**
 * Notion 数据预加载器类
 *
 * 专门解决N+1查询问题，实现批量数据预加载和缓存机制。
 * 通过智能预加载相关数据，显著减少数据库查询次数，提升性能。
 *
 * 核心功能：
 * - 批量预加载WordPress文章元数据
 * - 批量预加载Notion页面关联数据
 * - 智能缓存和结果复用
 * - 数据库查询性能监控
 * - N+1查询检测和优化
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

class Notion_Data_Preloader {

    /**
     * 预加载的数据缓存
     * @var array
     */
    private static $preloaded_cache = [];

    /**
     * 查询性能统计
     * @var array
     */
    private static $query_stats = [
        'total_queries' => 0,
        'cache_hits' => 0,
        'batch_queries' => 0,
        'single_queries' => 0,
        'query_times' => [],
        'n_plus_one_detected' => 0
    ];

    /**
     * 当前会话的查询历史（用于N+1检测）
     * @var array
     */
    private static $query_history = [];

    /**
     * 预加载相关数据
     *
     * @param array $context 上下文数据，包含需要预加载的信息
     * @return bool 预加载是否成功
     */
    public static function preload_related_data(array $context): bool {
        $start_time = microtime(true);
        
        try {
            // 预加载WordPress文章元数据
            if (!empty($context['post_ids'])) {
                self::preload_post_metadata($context['post_ids']);
            }

            // 预加载Notion页面关联数据
            if (!empty($context['notion_ids'])) {
                self::preload_notion_associations($context['notion_ids']);
            }

            // 预加载分类和标签数据
            if (!empty($context['post_ids'])) {
                self::preload_taxonomy_data($context['post_ids']);
            }

            // 预加载用户数据
            if (!empty($context['author_ids'])) {
                self::preload_author_data($context['author_ids']);
            }

            $processing_time = microtime(true) - $start_time;
            self::$query_stats['query_times'][] = $processing_time;

            // 减少日志记录，仅在非性能模式下记录
            if (class_exists('Notion_Logger') && !defined('NOTION_PERFORMANCE_MODE')) {
                Notion_Logger::debug_log(
                    sprintf('数据预加载完成，耗时%.2fms', $processing_time * 1000),
                    'Data Preloader'
                );
            }

            return true;

        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    '数据预加载失败: ' . $e->getMessage(),
                    'Data Preloader'
                );
            }
            return false;
        }
    }

    /**
     * 批量预加载文章元数据
     *
     * @param array $post_ids 文章ID数组
     */
    private static function preload_post_metadata(array $post_ids): void {
        if (empty($post_ids)) {
            return;
        }

        $start_time = microtime(true);
        self::$query_stats['batch_queries']++;

        // 检查缓存
        $uncached_ids = [];
        foreach ($post_ids as $post_id) {
            if (!isset(self::$preloaded_cache['post_meta'][$post_id])) {
                $uncached_ids[] = $post_id;
            }
        }

        if (empty($uncached_ids)) {
            self::$query_stats['cache_hits']++;
            return;
        }

        global $wpdb;

        // 批量查询所有元数据（优化版）
        $placeholders = implode(',', array_fill(0, count($uncached_ids), '%d'));

        // 限制查询的meta_key，只获取常用的
        $common_meta_keys = [
            'notion_page_id', 'notion_last_sync', 'notion_blocks_hash',
            '_edit_last', '_edit_lock', '_wp_page_template'
        ];
        $meta_key_placeholders = implode(',', array_fill(0, count($common_meta_keys), '%s'));

        $query = $wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id IN ($placeholders)
            AND meta_key IN ($meta_key_placeholders)",
            array_merge($uncached_ids, $common_meta_keys)
        );

        $results = $wpdb->get_results($query);
        self::$query_stats['total_queries']++;

        // 组织数据到缓存
        foreach ($uncached_ids as $post_id) {
            self::$preloaded_cache['post_meta'][$post_id] = [];
        }

        foreach ($results as $row) {
            self::$preloaded_cache['post_meta'][$row->post_id][$row->meta_key] = $row->meta_value;
        }

        $processing_time = microtime(true) - $start_time;
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('批量预加载文章元数据: %d个文章，耗时%.2fms', 
                    count($uncached_ids), $processing_time * 1000),
                'Data Preloader'
            );
        }
    }

    /**
     * 批量预加载Notion关联数据
     *
     * @param array $notion_ids Notion页面ID数组
     */
    private static function preload_notion_associations(array $notion_ids): void {
        if (empty($notion_ids)) {
            return;
        }

        $start_time = microtime(true);
        self::$query_stats['batch_queries']++;

        // 使用现有的批量查询方法
        if (class_exists('Notion_Database_Helper')) {
            $associations = Notion_Database_Helper::batch_get_posts_by_notion_ids($notion_ids);
            
            // 缓存关联数据
            foreach ($associations as $notion_id => $post_id) {
                self::$preloaded_cache['notion_associations'][$notion_id] = $post_id;
            }

            self::$query_stats['total_queries']++;
        }

        $processing_time = microtime(true) - $start_time;
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('批量预加载Notion关联: %d个页面，耗时%.2fms', 
                    count($notion_ids), $processing_time * 1000),
                'Data Preloader'
            );
        }
    }

    /**
     * 批量预加载分类和标签数据
     *
     * @param array $post_ids 文章ID数组
     */
    private static function preload_taxonomy_data(array $post_ids): void {
        if (empty($post_ids)) {
            return;
        }

        $start_time = microtime(true);
        self::$query_stats['batch_queries']++;

        global $wpdb;

        // 批量查询分类关系
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT tr.object_id, tt.taxonomy, t.term_id, t.name, t.slug
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE tr.object_id IN ($placeholders)
            AND tt.taxonomy IN ('category', 'post_tag')",
            $post_ids
        );

        $results = $wpdb->get_results($query);
        self::$query_stats['total_queries']++;

        // 组织数据到缓存
        foreach ($post_ids as $post_id) {
            self::$preloaded_cache['taxonomy_data'][$post_id] = [
                'categories' => [],
                'tags' => []
            ];
        }

        foreach ($results as $row) {
            $taxonomy_key = $row->taxonomy === 'category' ? 'categories' : 'tags';
            self::$preloaded_cache['taxonomy_data'][$row->object_id][$taxonomy_key][] = [
                'term_id' => $row->term_id,
                'name' => $row->name,
                'slug' => $row->slug
            ];
        }

        $processing_time = microtime(true) - $start_time;
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('批量预加载分类数据: %d个文章，耗时%.2fms', 
                    count($post_ids), $processing_time * 1000),
                'Data Preloader'
            );
        }
    }

    /**
     * 批量预加载作者数据
     *
     * @param array $author_ids 作者ID数组
     */
    private static function preload_author_data(array $author_ids): void {
        if (empty($author_ids)) {
            return;
        }

        $start_time = microtime(true);
        self::$query_stats['batch_queries']++;

        // 检查缓存
        $uncached_ids = [];
        foreach ($author_ids as $author_id) {
            if (!isset(self::$preloaded_cache['author_data'][$author_id])) {
                $uncached_ids[] = $author_id;
            }
        }

        if (empty($uncached_ids)) {
            self::$query_stats['cache_hits']++;
            return;
        }

        global $wpdb;

        // 批量查询用户数据
        $placeholders = implode(',', array_fill(0, count($uncached_ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT ID, user_login, user_nicename, user_email, display_name
            FROM {$wpdb->users}
            WHERE ID IN ($placeholders)",
            $uncached_ids
        );

        $results = $wpdb->get_results($query);
        self::$query_stats['total_queries']++;

        // 缓存用户数据
        foreach ($results as $user) {
            self::$preloaded_cache['author_data'][$user->ID] = [
                'login' => $user->user_login,
                'nicename' => $user->user_nicename,
                'email' => $user->user_email,
                'display_name' => $user->display_name
            ];
        }

        $processing_time = microtime(true) - $start_time;
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('批量预加载作者数据: %d个用户，耗时%.2fms',
                    count($uncached_ids), $processing_time * 1000),
                'Data Preloader'
            );
        }
    }

    /**
     * 批量获取文章元数据（优化版get_post_meta）
     *
     * @param array $post_ids 文章ID数组
     * @param string $meta_key 元数据键名，为空则获取所有
     * @return array [post_id => meta_value] 或 [post_id => [meta_key => meta_value]]
     */
    public static function batch_get_post_meta(array $post_ids, string $meta_key = ''): array {
        if (empty($post_ids)) {
            return [];
        }

        // 检测N+1查询模式
        self::detect_n_plus_one_pattern('get_post_meta', $post_ids);

        $start_time = microtime(true);
        $result = [];

        // 确保数据已预加载
        self::preload_post_metadata($post_ids);

        foreach ($post_ids as $post_id) {
            if (isset(self::$preloaded_cache['post_meta'][$post_id])) {
                if ($meta_key) {
                    $result[$post_id] = self::$preloaded_cache['post_meta'][$post_id][$meta_key] ?? '';
                } else {
                    $result[$post_id] = self::$preloaded_cache['post_meta'][$post_id];
                }
            } else {
                $result[$post_id] = $meta_key ? '' : [];
            }
        }

        self::$query_stats['cache_hits']++;
        $processing_time = microtime(true) - $start_time;

        if (class_exists('Notion_Logger') && $processing_time > 0.001) {
            Notion_Logger::debug_log(
                sprintf('批量获取元数据: %d个文章，耗时%.2fms (缓存命中)',
                    count($post_ids), $processing_time * 1000),
                'Data Preloader'
            );
        }

        return $result;
    }

    /**
     * 批量获取Notion页面关联的WordPress文章ID
     *
     * @param array $notion_ids Notion页面ID数组
     * @return array [notion_id => post_id]
     */
    public static function batch_get_posts_by_notion_ids(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        // 检测N+1查询模式
        self::detect_n_plus_one_pattern('get_post_by_notion_id', $notion_ids);

        $start_time = microtime(true);
        $result = [];

        // 确保数据已预加载
        self::preload_notion_associations($notion_ids);

        foreach ($notion_ids as $notion_id) {
            $result[$notion_id] = self::$preloaded_cache['notion_associations'][$notion_id] ?? 0;
        }

        self::$query_stats['cache_hits']++;
        $processing_time = microtime(true) - $start_time;

        if (class_exists('Notion_Logger') && $processing_time > 0.001) {
            Notion_Logger::debug_log(
                sprintf('批量获取Notion关联: %d个页面，耗时%.2fms (缓存命中)',
                    count($notion_ids), $processing_time * 1000),
                'Data Preloader'
            );
        }

        return $result;
    }

    /**
     * 批量获取文章分类和标签
     *
     * @param array $post_ids 文章ID数组
     * @param string $taxonomy 分类法名称，为空则获取所有
     * @return array [post_id => taxonomy_data]
     */
    public static function batch_get_post_terms(array $post_ids, string $taxonomy = ''): array {
        if (empty($post_ids)) {
            return [];
        }

        // 检测N+1查询模式
        self::detect_n_plus_one_pattern('get_post_terms', $post_ids);

        $start_time = microtime(true);
        $result = [];

        // 确保数据已预加载
        self::preload_taxonomy_data($post_ids);

        foreach ($post_ids as $post_id) {
            if (isset(self::$preloaded_cache['taxonomy_data'][$post_id])) {
                if ($taxonomy) {
                    $taxonomy_key = $taxonomy === 'category' ? 'categories' : 'tags';
                    $result[$post_id] = self::$preloaded_cache['taxonomy_data'][$post_id][$taxonomy_key] ?? [];
                } else {
                    $result[$post_id] = self::$preloaded_cache['taxonomy_data'][$post_id];
                }
            } else {
                $result[$post_id] = $taxonomy ? [] : ['categories' => [], 'tags' => []];
            }
        }

        self::$query_stats['cache_hits']++;
        $processing_time = microtime(true) - $start_time;

        if (class_exists('Notion_Logger') && $processing_time > 0.001) {
            Notion_Logger::debug_log(
                sprintf('批量获取分类数据: %d个文章，耗时%.2fms (缓存命中)',
                    count($post_ids), $processing_time * 1000),
                'Data Preloader'
            );
        }

        return $result;
    }

    /**
     * 检测N+1查询模式
     *
     * @param string $operation 操作类型
     * @param array $ids ID数组
     */
    private static function detect_n_plus_one_pattern(string $operation, array $ids): void {
        $current_time = microtime(true);
        $threshold_time = 1.0; // 1秒内的查询被认为是同一批次
        $threshold_count = 5; // 5次以上相同操作被认为是N+1

        // 清理过期的查询历史
        self::$query_history = array_filter(self::$query_history, function($query) use ($current_time, $threshold_time) {
            return ($current_time - $query['time']) <= $threshold_time;
        });

        // 记录当前查询
        self::$query_history[] = [
            'operation' => $operation,
            'count' => count($ids),
            'time' => $current_time
        ];

        // 检测N+1模式
        $recent_operations = array_filter(self::$query_history, function($query) use ($operation) {
            return $query['operation'] === $operation;
        });

        if (count($recent_operations) >= $threshold_count) {
            self::$query_stats['n_plus_one_detected']++;

            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    sprintf('检测到N+1查询模式: %s操作在1秒内执行了%d次，建议使用批量查询',
                        $operation, count($recent_operations)),
                    'N+1 Detection'
                );
            }
        }
    }

    /**
     * 获取缓存的数据
     *
     * @param string $cache_type 缓存类型
     * @param mixed $key 缓存键
     * @return mixed 缓存的数据，不存在返回null
     */
    public static function get_cached_data(string $cache_type, $key) {
        return self::$preloaded_cache[$cache_type][$key] ?? null;
    }

    /**
     * 设置缓存数据
     *
     * @param string $cache_type 缓存类型
     * @param mixed $key 缓存键
     * @param mixed $value 缓存值
     */
    public static function set_cached_data(string $cache_type, $key, $value): void {
        self::$preloaded_cache[$cache_type][$key] = $value;
    }

    /**
     * 清理缓存
     *
     * @param string $cache_type 缓存类型，为空则清理所有
     */
    public static function clear_cache(string $cache_type = ''): void {
        if ($cache_type) {
            unset(self::$preloaded_cache[$cache_type]);
        } else {
            self::$preloaded_cache = [];
        }

        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                $cache_type ? "清理缓存: {$cache_type}" : '清理所有缓存',
                'Data Preloader'
            );
        }
    }

    /**
     * 获取查询性能统计
     *
     * @return array 性能统计数据
     */
    public static function get_query_stats(): array {
        $stats = self::$query_stats;

        // 计算平均查询时间
        if (!empty($stats['query_times'])) {
            $stats['avg_query_time'] = array_sum($stats['query_times']) / count($stats['query_times']);
            $stats['max_query_time'] = max($stats['query_times']);
            $stats['min_query_time'] = min($stats['query_times']);
        } else {
            $stats['avg_query_time'] = 0;
            $stats['max_query_time'] = 0;
            $stats['min_query_time'] = 0;
        }

        // 计算缓存命中率
        if ($stats['total_queries'] > 0) {
            $stats['cache_hit_rate'] = ($stats['cache_hits'] / ($stats['total_queries'] + $stats['cache_hits'])) * 100;
        } else {
            $stats['cache_hit_rate'] = 0;
        }

        // 计算批量查询比例
        if ($stats['total_queries'] > 0) {
            $stats['batch_query_rate'] = ($stats['batch_queries'] / $stats['total_queries']) * 100;
        } else {
            $stats['batch_query_rate'] = 0;
        }

        return $stats;
    }

    /**
     * 重置查询统计
     */
    public static function reset_query_stats(): void {
        self::$query_stats = [
            'total_queries' => 0,
            'cache_hits' => 0,
            'batch_queries' => 0,
            'single_queries' => 0,
            'query_times' => [],
            'n_plus_one_detected' => 0
        ];
        self::$query_history = [];

        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log('查询统计已重置', 'Data Preloader');
        }
    }

    /**
     * 生成性能报告
     *
     * @return string 格式化的性能报告
     */
    public static function generate_performance_report(): string {
        $stats = self::get_query_stats();

        $report = "=== 数据预加载器性能报告 ===\n";
        $report .= sprintf("总查询次数: %d\n", $stats['total_queries']);
        $report .= sprintf("缓存命中次数: %d\n", $stats['cache_hits']);
        $report .= sprintf("批量查询次数: %d\n", $stats['batch_queries']);
        $report .= sprintf("单次查询次数: %d\n", $stats['single_queries']);
        $report .= sprintf("缓存命中率: %.2f%%\n", $stats['cache_hit_rate']);
        $report .= sprintf("批量查询比例: %.2f%%\n", $stats['batch_query_rate']);
        $report .= sprintf("平均查询时间: %.2fms\n", $stats['avg_query_time'] * 1000);
        $report .= sprintf("最大查询时间: %.2fms\n", $stats['max_query_time'] * 1000);
        $report .= sprintf("最小查询时间: %.2fms\n", $stats['min_query_time'] * 1000);
        $report .= sprintf("检测到N+1查询: %d次\n", $stats['n_plus_one_detected']);

        // 缓存状态
        $cache_sizes = [];
        foreach (self::$preloaded_cache as $type => $cache) {
            $cache_sizes[$type] = count($cache);
        }

        $report .= "\n=== 缓存状态 ===\n";
        foreach ($cache_sizes as $type => $size) {
            $report .= sprintf("%s: %d项\n", $type, $size);
        }

        return $report;
    }

    /**
     * 优化现有的数据库查询
     *
     * 这个方法可以被其他类调用来优化它们的查询
     *
     * @param callable $query_callback 查询回调函数
     * @param array $context 查询上下文
     * @return mixed 查询结果
     */
    public static function optimize_query(callable $query_callback, array $context = []) {
        $start_time = microtime(true);

        // 预加载相关数据
        if (!empty($context)) {
            self::preload_related_data($context);
        }

        // 执行查询
        $result = call_user_func($query_callback);

        $processing_time = microtime(true) - $start_time;
        self::$query_stats['query_times'][] = $processing_time;

        if (class_exists('Notion_Logger') && $processing_time > 0.01) {
            Notion_Logger::debug_log(
                sprintf('优化查询完成，耗时%.2fms', $processing_time * 1000),
                'Query Optimizer'
            );
        }

        return $result;
    }

    /**
     * 智能预加载建议
     *
     * 基于查询历史分析，提供预加载建议
     *
     * @return array 预加载建议
     */
    public static function get_preload_suggestions(): array {
        $suggestions = [];

        // 分析N+1查询模式
        if (self::$query_stats['n_plus_one_detected'] > 0) {
            $suggestions[] = [
                'type' => 'n_plus_one',
                'message' => sprintf('检测到%d次N+1查询，建议使用批量查询方法',
                    self::$query_stats['n_plus_one_detected']),
                'priority' => 'high'
            ];
        }

        // 分析缓存命中率
        $stats = self::get_query_stats();
        if ($stats['cache_hit_rate'] < 50 && $stats['total_queries'] > 10) {
            $suggestions[] = [
                'type' => 'cache_hit_rate',
                'message' => sprintf('缓存命中率较低(%.2f%%)，建议增加预加载范围',
                    $stats['cache_hit_rate']),
                'priority' => 'medium'
            ];
        }

        // 分析批量查询比例
        if ($stats['batch_query_rate'] < 70 && $stats['total_queries'] > 5) {
            $suggestions[] = [
                'type' => 'batch_query_rate',
                'message' => sprintf('批量查询比例较低(%.2f%%)，建议更多使用批量方法',
                    $stats['batch_query_rate']),
                'priority' => 'medium'
            ];
        }

        return $suggestions;
    }

    /**
     * 启用查询监控
     *
     * 在WordPress查询钩子上添加监控
     */
    public static function enable_query_monitoring(): void {
        // 监控get_post_meta调用
        add_filter('get_post_metadata', function($value, $object_id, $meta_key, $single) {
            if ($value === null) { // 只在没有被其他过滤器处理时记录
                self::$query_stats['single_queries']++;

                // 检查是否应该使用批量查询
                if (self::should_use_batch_query('get_post_meta', [$object_id])) {
                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::warning_log(
                            sprintf('单次get_post_meta调用检测到，文章ID: %d，键: %s',
                                $object_id, $meta_key),
                            'Query Monitor'
                        );
                    }
                }
            }
            return $value;
        }, 10, 4);

        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log('查询监控已启用', 'Data Preloader');
        }
    }

    /**
     * 判断是否应该使用批量查询
     *
     * @param string $operation 操作类型
     * @param array $ids ID数组
     * @return bool 是否应该使用批量查询
     */
    private static function should_use_batch_query(string $operation, array $ids): bool {
        // 如果ID数量大于1，建议使用批量查询
        if (count($ids) > 1) {
            return true;
        }

        // 检查最近是否有相同操作
        $recent_operations = array_filter(self::$query_history, function($query) use ($operation) {
            return $query['operation'] === $operation &&
                   (microtime(true) - $query['time']) <= 0.5; // 0.5秒内
        });

        return count($recent_operations) >= 3;
    }
}
