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
        Notion_Logger::info_log(
            "开始增量同步过滤，输入页面数量: " . count($pages),
            'Incremental Sync Debug'
        );

        if (empty($pages)) {
            return [];
        }

        // 提取所有页面ID
        $notion_ids = array_map(function($page) {
            return $page['id'];
        }, $pages);

        // 批量获取同步时间
        $sync_times = Notion_Database_Helper::batch_get_sync_times($notion_ids);

        // 使用增量内容检测器进行精确变化检测
        if (class_exists('Notion_Incremental_Detector')) {
            $post_mapping = Notion_Database_Helper::batch_get_posts_by_notion_ids($notion_ids);
            $changes_map = Notion_Incremental_Detector::batch_detect_changes($pages, $post_mapping);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    sprintf('增量检测结果: %d个页面有变化', count($changes_map)),
                    'Incremental Sync'
                );
            }
        }

        // 调试：输出同步时间查询结果
        Notion_Logger::info_log(
            "批量查询同步时间结果: " . print_r($sync_times, true),
            'Incremental Sync Debug'
        );

        $pages_to_sync = [];

        foreach ($pages as $page) {
            $page_id = $page['id'];
            $notion_last_edited = $page['last_edited_time'] ?? '';

            // 调试：输出每个页面的详细信息
            Notion_Logger::info_log(
                "检查页面: {$page_id}, Notion编辑时间: {$notion_last_edited}",
                'Incremental Sync Debug'
            );

            if (empty($notion_last_edited)) {
                // 如果没有编辑时间，默认需要同步
                Notion_Logger::debug_log(
                    "页面无编辑时间，需要同步: {$page_id}",
                    'Incremental Sync Debug'
                );
                $pages_to_sync[] = $page;
                continue;
            }

            // 获取本地记录的最后同步时间（从批量查询结果中获取）
            $local_last_sync = $sync_times[$page_id] ?? '';

            // 调试：输出本地同步时间
            Notion_Logger::debug_log(
                "页面 {$page_id} 本地同步时间: '{$local_last_sync}'",
                'Incremental Sync Debug'
            );

            if (empty($local_last_sync)) {
                // 新页面，需要同步
                Notion_Logger::debug_log(
                    "新页面需要同步: {$page_id}",
                    'Incremental Sync'
                );
                $pages_to_sync[] = $page;
                continue;
            }

            // 优先使用增量检测器进行精确判断
            $should_sync = false;
            $detection_method = 'time';

            if (class_exists('Notion_Incremental_Detector') && isset($post_mapping[$page_id]) && $post_mapping[$page_id] > 0) {
                // 使用增量检测器进行精确检测（优先级最高）
                if (Notion_Incremental_Detector::should_skip_sync($page, $post_mapping[$page_id])) {
                    $should_sync = false;
                    $detection_method = 'incremental_skip';

                    Notion_Logger::debug_log(
                        "增量检测器判断无需同步: {$page_id}",
                        'Incremental Sync'
                    );
                } else {
                    $should_sync = true;
                    $detection_method = 'incremental_sync';

                    Notion_Logger::debug_log(
                        "增量检测器确认需要同步: {$page_id}",
                        'Incremental Sync'
                    );
                }
            } else {
                // 降级到时间检测（当增量检测器不可用时）
                $should_sync = self::should_sync_page($notion_last_edited, $local_last_sync);
                $detection_method = $should_sync ? 'time_sync' : 'time_skip';

                Notion_Logger::debug_log(
                    "使用时间检测（增量检测器不可用）: {$page_id}, 结果: " . ($should_sync ? 'SYNC' : 'SKIP'),
                    'Incremental Sync'
                );
            }

            // 调试：输出检测结果
            Notion_Logger::debug_log(
                "页面 {$page_id} 检测结果: 方法={$detection_method}, Notion={$notion_last_edited}, Local={$local_last_sync}, 需要同步=" . ($should_sync ? 'YES' : 'NO'),
                'Incremental Sync Debug'
            );

            if ($should_sync) {
                Notion_Logger::debug_log(
                    "页面有更新需要同步: {$page_id}, Notion: {$notion_last_edited}, Local: {$local_last_sync}",
                    'Incremental Sync'
                );
                $pages_to_sync[] = $page;
            } else {
                Notion_Logger::debug_log(
                    "页面无需同步: {$page_id}, Notion: {$notion_last_edited}, Local: {$local_last_sync}",
                    'Incremental Sync Debug'
                );
            }
        }

        Notion_Logger::info_log(
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
        // 处理Notion时间（ISO 8601格式）
        $notion_timestamp = strtotime($notion_last_edited);

        // 处理本地时间（MySQL UTC格式）
        // 本地时间已经是UTC格式存储的，直接转换即可
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $local_last_sync)) {
            // MySQL格式的UTC时间，直接转换
            $local_timestamp = strtotime($local_last_sync);
        } else {
            // 其他格式，尝试直接转换
            $local_timestamp = strtotime($local_last_sync);
        }

        // 验证时间戳转换是否成功
        if ($notion_timestamp === false || $local_timestamp === false) {
            // 如果时间转换失败，记录错误并默认需要同步
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    "时间戳转换失败: Notion='{$notion_last_edited}', Local='{$local_last_sync}'",
                    'Time Comparison Error'
                );
            }
            return true; // 转换失败时默认需要同步
        }

        // 调试：输出时间戳转换结果
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf(
                    "时间戳比较: Notion='%s'(%d), Local='%s'(%d), 差值=%d秒, 容错=%d秒, 结果=%s",
                    $notion_last_edited,
                    $notion_timestamp,
                    $local_last_sync,
                    $local_timestamp,
                    $notion_timestamp - $local_timestamp,
                    self::$timestamp_tolerance,
                    ($notion_timestamp > $local_timestamp + self::$timestamp_tolerance) ? 'SYNC' : 'SKIP'
                ),
                'Time Comparison Debug'
            );
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

        Notion_Logger::debug_log(
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
        $post_mapping = Notion_Database_Helper::batch_get_posts_by_notion_ids($notion_ids);

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

            Notion_Logger::debug_log(
                "批量更新页面同步时间完成，更新了 " . count($page_updates) . " 个页面",
                'Incremental Sync'
            );
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            Notion_Logger::error_log(
                "批量更新页面同步时间失败: " . $e->getMessage()
            );
            throw $e;
        }
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
            'last_sync_time' => $last_sync_time ?: ''
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
        // 禁用缓存，直接执行数据库查询以确保数据实时性
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

        return $result;
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

    /**
     * 超级批量同步模式
     *
     * 使用最激进的性能优化策略进行同步
     *
     * @since 2.0.0-beta.1
     * @param array $pages 页面数据
     * @param Notion_API $notion_api API实例
     * @return array 同步统计
     */
    public static function super_batch_sync(array $pages, Notion_API $notion_api): array {
        $options = get_option('notion_to_wordpress_options', []);
        $performance_mode = $options['enable_performance_mode'] ?? 1;

        if (!$performance_mode) {
            // 如果未启用性能模式，使用标准同步
            return self::sync_pages($pages, $notion_api);
        }

        $start_time = microtime(true);
        $stats = [
            'total' => count($pages),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'processing_time' => 0
        ];

        if (empty($pages)) {
            return $stats;
        }

        // 开始性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::start_timer('super_batch_sync');
        }

        // 第一步：批量预处理 - 提取所有Notion ID
        $notion_ids = array_column($pages, 'id');

        // 第二步：批量获取现有映射（一次查询）
        $existing_mapping = Notion_Database_Helper::batch_get_posts_by_notion_ids($notion_ids);

        // 第三步：批量获取同步时间（一次查询）
        $sync_times = Notion_Database_Helper::batch_get_sync_times($notion_ids);

        // 第四步：分类处理 - 分离新建和更新
        $pages_to_create = [];
        $pages_to_update = [];

        foreach ($pages as $page) {
            $notion_id = $page['id'];
            $post_id = $existing_mapping[$notion_id] ?? 0;
            $last_sync = $sync_times[$notion_id] ?? null;

            $page_last_edited = $page['last_edited_time'] ?? '';

            if ($post_id === 0) {
                // 新页面
                $pages_to_create[] = $page;
            } elseif (empty($last_sync) || $page_last_edited > $last_sync) {
                // 需要更新的页面
                $page['post_id'] = $post_id;
                $pages_to_update[] = $page;
            } else {
                // 跳过未变更的页面
                $stats['skipped']++;
            }
        }

        // 第五步：超级批量创建（使用内存优化）
        if (!empty($pages_to_create)) {
            $create_stats = self::super_batch_create_posts($pages_to_create, $notion_api);
            $stats['created'] += $create_stats['success'];
            $stats['errors'] += $create_stats['errors'];
        }

        // 第六步：超级批量更新（使用内存优化）
        if (!empty($pages_to_update)) {
            $update_stats = self::super_batch_update_posts($pages_to_update, $notion_api);
            $stats['updated'] += $update_stats['success'];
            $stats['errors'] += $update_stats['errors'];
        }

        // 结束性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::end_timer('super_batch_sync');
        }

        $stats['processing_time'] = microtime(true) - $start_time;

        return $stats;
    }

    /**
     * 超级批量创建文章
     *
     * @since 2.0.0-beta.1
     * @param array $pages 页面数据
     * @param Notion_API $notion_api API实例
     * @return array 创建统计
     */
    private static function super_batch_create_posts(array $pages, Notion_API $notion_api): array {
        $stats = ['success' => 0, 'errors' => 0];

        if (empty($pages)) {
            return $stats;
        }

        // 监控内存使用
        Notion_Memory_Manager::monitor_memory_usage('Batch Create Posts');

        $options = get_option('notion_to_wordpress_options', []);

        // 使用自适应批量大小调整器
        if (class_exists('Notion_Adaptive_Batch')) {
            $batch_size = Notion_Adaptive_Batch::get_optimal_batch_size('database_operations');

            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    "自适应批量创建大小: {$batch_size} (操作类型: database_operations)",
                    'Batch Create'
                );
            }
        } else {
            $batch_size = $options['batch_size'] ?? 20;
        }

        // 使用内存优化的分块处理
        $chunks = array_chunk($pages, $batch_size);

        foreach ($chunks as $chunk_index => $chunk) {
            $posts_data = [];

            // 准备批量数据
            foreach ($chunk as $page) {
                $post_data = [
                    'post_title' => $page['properties']['Name']['title'][0]['plain_text'] ?? 'Untitled',
                    'post_status' => 'publish',
                    'post_type' => 'post',
                    'meta_input' => [
                        '_notion_page_id' => $page['id'],
                        '_notion_last_sync' => current_time('mysql')
                    ]
                ];
                $posts_data[] = $post_data;
            }

            // 批量插入
            $result = Notion_To_WordPress_Integrator::batch_insert_posts($posts_data);
            $stats['success'] += count($result['success']);
            $stats['errors'] += count($result['failed']);

            // 清理临时数据
            unset($posts_data, $result);

            // 定期垃圾回收
            if ($chunk_index % 3 === 0) {
                Notion_Memory_Manager::force_garbage_collection();
            }
        }

        return $stats;
    }

    /**
     * 超级批量更新文章
     *
     * @since 2.0.0-beta.1
     * @param array $pages 页面数据
     * @param Notion_API $notion_api API实例
     * @return array 更新统计
     */
    private static function super_batch_update_posts(array $pages, Notion_API $notion_api): array {
        $stats = ['success' => 0, 'errors' => 0];

        if (empty($pages)) {
            return $stats;
        }

        // 监控内存使用
        Notion_Memory_Manager::monitor_memory_usage('Batch Update Posts');

        $options = get_option('notion_to_wordpress_options', []);

        // 使用自适应批量大小调整器
        if (class_exists('Notion_Adaptive_Batch')) {
            $batch_size = Notion_Adaptive_Batch::get_optimal_batch_size('database_operations');

            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    "自适应批量更新大小: {$batch_size} (操作类型: database_operations)",
                    'Batch Update'
                );
            }
        } else {
            $batch_size = $options['batch_size'] ?? 20;
        }

        // 使用内存优化的分块处理
        $chunks = array_chunk($pages, $batch_size);

        foreach ($chunks as $chunk_index => $chunk) {
            $meta_updates = [];

            foreach ($chunk as $page) {
                $post_id = $page['post_id'];

                try {
                    // 更新文章标题
                    wp_update_post([
                        'ID' => $post_id,
                        'post_title' => $page['properties']['Name']['title'][0]['plain_text'] ?? 'Untitled'
                    ]);

                    // 准备元数据更新
                    $meta_updates[$post_id] = [
                        '_notion_last_sync' => current_time('mysql')
                    ];

                    $stats['success']++;
                } catch (Exception $e) {
                    $stats['errors']++;
                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::error_log(
                            "更新文章失败 (ID: {$post_id}): " . $e->getMessage(),
                            'Batch Update'
                        );
                    }
                }
            }

            // 批量更新元数据
            if (!empty($meta_updates)) {
                Notion_To_WordPress_Integrator::batch_update_post_meta($meta_updates);
            }

            // 清理临时数据
            unset($meta_updates);

            // 定期垃圾回收
            if ($chunk_index % 3 === 0) {
                Notion_Memory_Manager::force_garbage_collection();
            }
        }

        return $stats;
    }

    /**
     * 超级优化的同步数据获取
     *
     * 使用临时表+JOIN优化大数据集的同步数据查询
     *
     * @since 2.0.0-beta.1
     * @param array $notion_ids Notion页面ID数组
     * @return array 同步数据映射
     */
    public static function ultra_optimized_get_sync_data(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        // 开始性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::start_timer('ultra_optimized_sync_data');
        }

        $start_time = microtime(true);

        // 根据数据量选择最优查询方式
        if (count($notion_ids) >= 1000) {
            // 大数据集使用临时表+JOIN优化
            $sync_data = Notion_Database_Helper::ultra_batch_get_sync_data($notion_ids);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('使用临时表优化查询 %d 个页面的同步数据', count($notion_ids)),
                    'Sync Manager Ultra'
                );
            }
        } else {
            // 中小数据集使用标准批量查询
            $sync_data = Notion_Database_Helper::batch_get_sync_times($notion_ids);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    sprintf('使用标准批量查询 %d 个页面的同步数据', count($notion_ids)),
                    'Sync Manager'
                );
            }
        }

        // 结束性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::end_timer('ultra_optimized_sync_data');
            $processing_time = microtime(true) - $start_time;
            Notion_Performance_Monitor::record_custom_metric('sync_data_query_time', $processing_time);
        }

        return $sync_data;
    }

    /**
     * 批量更新同步状态（优化版本）
     *
     * 使用优化的数据库批量更新操作
     *
     * @since 2.0.0-beta.1
     * @param array $sync_updates 同步更新数据
     * @return bool 是否成功
     */
    public static function ultra_batch_update_sync_status(array $sync_updates): bool {
        if (empty($sync_updates)) {
            return true;
        }

        // 开始性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::start_timer('ultra_batch_sync_update');
        }

        $start_time = microtime(true);
        $success = false;

        try {
            // 使用优化的批量更新方法
            $success = Notion_Database_Helper::batch_update_sync_status($sync_updates);

            if ($success) {
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::info_log(
                        sprintf('成功批量更新 %d 个页面的同步状态', count($sync_updates)),
                        'Sync Manager Ultra'
                    );
                }
            } else {
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::warning_log(
                        sprintf('批量更新 %d 个页面的同步状态失败', count($sync_updates)),
                        'Sync Manager Ultra'
                    );
                }
            }

        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('批量更新同步状态异常: %s', $e->getMessage()),
                    'Sync Manager Ultra'
                );
            }
            $success = false;
        }

        // 结束性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::end_timer('ultra_batch_sync_update');
            $processing_time = microtime(true) - $start_time;
            Notion_Performance_Monitor::record_custom_metric('sync_status_update_time', $processing_time);
        }

        return $success;
    }

    /**
     * 智能同步数据处理
     *
     * 根据数据量和系统负载智能选择最优的处理策略
     *
     * @since 2.0.0-beta.1
     * @param array $pages 页面数据数组
     * @return array 处理结果统计
     */
    public static function smart_sync_data_processing(array $pages): array {
        $stats = [
            'total_pages' => count($pages),
            'query_time' => 0,
            'update_time' => 0,
            'method_used' => '',
            'success' => true
        ];

        if (empty($pages)) {
            return $stats;
        }

        $start_time = microtime(true);

        // 提取Notion ID
        $notion_ids = array_column($pages, 'id');

        // 智能选择查询方法
        if (count($notion_ids) >= 1000) {
            $stats['method_used'] = 'ultra_optimized_temp_table';
            $sync_data = self::ultra_optimized_get_sync_data($notion_ids);
        } elseif (count($notion_ids) >= 100) {
            $stats['method_used'] = 'standard_batch';
            $sync_data = Notion_Database_Helper::batch_get_sync_times($notion_ids);
        } else {
            $stats['method_used'] = 'lightweight';
            $sync_data = Notion_Database_Helper::batch_get_sync_times($notion_ids);
        }

        $stats['query_time'] = microtime(true) - $start_time;

        // 准备批量更新数据
        $update_start = microtime(true);
        $sync_updates = [];

        foreach ($pages as $page) {
            $notion_id = $page['id'];
            $sync_info = $sync_data[$notion_id] ?? null;

            if ($sync_info && isset($sync_info['post_id']) && $sync_info['post_id'] > 0) {
                $sync_updates[$sync_info['post_id']] = [
                    'sync_time' => current_time('mysql'),
                    'content_hash' => md5(serialize($page))
                ];
            }
        }

        // 执行批量更新
        if (!empty($sync_updates)) {
            $update_success = self::ultra_batch_update_sync_status($sync_updates);
            $stats['success'] = $update_success;
        }

        $stats['update_time'] = microtime(true) - $update_start;

        // 记录统计信息
        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf(
                    '智能同步处理完成: 方法=%s, 页面=%d, 查询耗时=%.3fs, 更新耗时=%.3fs',
                    $stats['method_used'],
                    $stats['total_pages'],
                    $stats['query_time'],
                    $stats['update_time']
                ),
                'Smart Sync Processing'
            );
        }

        return $stats;
    }

    /**
     * API层前置过滤的增量同步
     *
     * 在API层面过滤变更内容，显著减少数据传输量
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param Notion_API $notion_api API实例
     * @param array $sync_options 同步选项
     * @return array 同步结果统计
     */
    public static function api_filtered_incremental_sync(string $database_id, $notion_api, array $sync_options = []): array {
        $stats = [
            'total_pages' => 0,
            'filtered_pages' => 0,
            'bandwidth_saved' => 0,
            'processing_time' => 0,
            'method_used' => 'api_filtered',
            'success' => true
        ];

        // 开始性能监控
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);

        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::start_timer('api_filtered_sync');
        }

        try {
            // 获取最后同步时间
            $last_sync_time = get_option("notion_last_sync_time_{$database_id}", '');

            // 如果没有最后同步时间，使用24小时前
            if (empty($last_sync_time)) {
                $date = new DateTime();
                $date->modify('-24 hours');
                $last_sync_time = $date->format('c');

                if (class_exists('Notion_Logger')) {
                    Notion_Logger::info_log(
                        '首次同步，使用24小时前作为起始时间',
                        'API Filtered Sync'
                    );
                }
            }

            // 构建额外过滤条件
            $additional_filters = [];

            // 添加状态过滤（如果配置了）
            if (isset($sync_options['exclude_archived']) && $sync_options['exclude_archived']) {
                $additional_filters[] = [
                    'property' => 'Status',
                    'select' => [
                        'does_not_equal' => 'Archived'
                    ]
                ];
            }

            // 添加自定义过滤条件
            if (isset($sync_options['custom_filters']) && is_array($sync_options['custom_filters'])) {
                $additional_filters = array_merge($additional_filters, $sync_options['custom_filters']);
            }

            // 首先获取全量数据（用于对比）
            $full_pages = [];
            if (isset($sync_options['compare_bandwidth']) && $sync_options['compare_bandwidth']) {
                $full_start = microtime(true);
                $full_pages = $notion_api->get_database_pages($database_id, [], false);
                $full_time = microtime(true) - $full_start;

                $stats['full_fetch_time'] = $full_time;
                $stats['total_pages'] = count($full_pages);
            }

            // 使用API层过滤获取增量数据
            $filtered_pages = $notion_api->smart_incremental_fetch(
                $database_id,
                $last_sync_time,
                $additional_filters,
                true // 获取详细信息
            );

            $stats['filtered_pages'] = count($filtered_pages);

            // 计算带宽节省
            if (!empty($full_pages)) {
                $full_size = strlen(serialize($full_pages));
                $filtered_size = strlen(serialize($filtered_pages));
                $stats['bandwidth_saved'] = max(0, $full_size - $filtered_size);
                $stats['bandwidth_save_percentage'] = $full_size > 0 ?
                    round(($stats['bandwidth_saved'] / $full_size) * 100, 1) : 0;
            }

            // 处理过滤后的页面
            if (!empty($filtered_pages)) {
                // 使用优化的数据库操作处理同步数据
                $sync_processing_stats = self::smart_sync_data_processing($filtered_pages);
                $stats = array_merge($stats, $sync_processing_stats);

                // 更新最后同步时间
                $current_time = current_time('c');
                update_option("notion_last_sync_time_{$database_id}", $current_time);

                if (class_exists('Notion_Logger')) {
                    Notion_Logger::info_log(
                        sprintf(
                            'API过滤同步完成: 过滤获取%d个页面（总计%d个），带宽节省%s',
                            $stats['filtered_pages'],
                            $stats['total_pages'],
                            isset($stats['bandwidth_save_percentage']) ?
                                $stats['bandwidth_save_percentage'] . '%' : '未计算'
                        ),
                        'API Filtered Sync'
                    );
                }
            } else {
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::info_log(
                        '没有需要同步的页面（API层过滤结果为空）',
                        'API Filtered Sync'
                    );
                }
            }

        } catch (Exception $e) {
            $stats['success'] = false;
            $stats['error'] = $e->getMessage();

            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('API过滤同步失败: %s', $e->getMessage()),
                    'API Filtered Sync'
                );
            }
        }

        // 结束性能监控
        $stats['processing_time'] = microtime(true) - $start_time;
        $stats['memory_used'] = memory_get_usage(true) - $start_memory;

        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::end_timer('api_filtered_sync');
            Notion_Performance_Monitor::record_custom_metric('api_filtered_sync_time', $stats['processing_time']);
            Notion_Performance_Monitor::record_custom_metric('api_filtered_pages', $stats['filtered_pages']);
            if (isset($stats['bandwidth_saved'])) {
                Notion_Performance_Monitor::record_custom_metric('api_bandwidth_saved', $stats['bandwidth_saved']);
            }
        }

        return $stats;
    }

    /**
     * 批量API过滤同步多个数据库
     *
     * 为多个数据库同时执行API层过滤同步
     *
     * @since 2.0.0-beta.1
     * @param array $database_configs 数据库配置数组
     * @param Notion_API $notion_api API实例
     * @return array 批量同步结果
     */
    public static function batch_api_filtered_sync(array $database_configs, $notion_api): array {
        $batch_stats = [
            'total_databases' => count($database_configs),
            'successful_syncs' => 0,
            'failed_syncs' => 0,
            'total_pages_filtered' => 0,
            'total_bandwidth_saved' => 0,
            'total_processing_time' => 0
        ];

        $start_time = microtime(true);

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf('开始批量API过滤同步 %d 个数据库', count($database_configs)),
                'Batch API Filtered Sync'
            );
        }

        // 准备批量增量获取配置
        $batch_configs = [];
        foreach ($database_configs as $database_id => $config) {
            $last_sync_time = get_option("notion_last_sync_time_{$database_id}", '');
            if (empty($last_sync_time)) {
                $date = new DateTime();
                $date->modify('-24 hours');
                $last_sync_time = $date->format('c');
            }

            $batch_configs[$database_id] = [
                'last_sync_time' => $last_sync_time,
                'filters' => $config['filters'] ?? []
            ];
        }

        try {
            // 批量获取所有数据库的增量数据
            $batch_results = $notion_api->batch_smart_incremental_fetch($batch_configs, true);

            // 处理每个数据库的结果
            foreach ($batch_results as $database_id => $pages) {
                try {
                    if (!empty($pages)) {
                        $sync_stats = self::smart_sync_data_processing($pages);

                        if ($sync_stats['success']) {
                            $batch_stats['successful_syncs']++;

                            // 更新最后同步时间
                            $current_time = current_time('c');
                            update_option("notion_last_sync_time_{$database_id}", $current_time);
                        } else {
                            $batch_stats['failed_syncs']++;
                        }

                        $batch_stats['total_pages_filtered'] += count($pages);
                    } else {
                        $batch_stats['successful_syncs']++; // 空结果也算成功
                    }

                } catch (Exception $e) {
                    $batch_stats['failed_syncs']++;

                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::warning_log(
                            sprintf('数据库 %s 同步处理失败: %s', $database_id, $e->getMessage()),
                            'Batch API Filtered Sync'
                        );
                    }
                }
            }

        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('批量API过滤获取失败: %s', $e->getMessage()),
                    'Batch API Filtered Sync'
                );
            }

            $batch_stats['failed_syncs'] = count($database_configs);
        }

        $batch_stats['total_processing_time'] = microtime(true) - $start_time;

        // 记录批量同步统计
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::record_custom_metric('batch_api_sync_time', $batch_stats['total_processing_time']);
            Notion_Performance_Monitor::record_custom_metric('batch_api_sync_databases', $batch_stats['total_databases']);
            Notion_Performance_Monitor::record_custom_metric('batch_api_sync_pages', $batch_stats['total_pages_filtered']);
        }

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf(
                    '批量API过滤同步完成: 成功%d个, 失败%d个, 总页面%d个, 耗时%.3fs',
                    $batch_stats['successful_syncs'],
                    $batch_stats['failed_syncs'],
                    $batch_stats['total_pages_filtered'],
                    $batch_stats['total_processing_time']
                ),
                'Batch API Filtered Sync'
            );
        }

        return $batch_stats;
    }
}
