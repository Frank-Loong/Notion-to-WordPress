<?php
declare(strict_types=1);

namespace NTWP\Services;

/**
 * Notion 增量内容检测器类
 * 
 * 实现精确的内容变化检测，只同步真正变化的内容部分，大幅提升同步效率
 * 实时检测内容变化，确保检测的准确性和实时性
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

class IncrementalDetector {
    
    /**
     * 哈希算法常量
     */
    const HASH_ALGORITHM = 'sha256';
    const HASH_SEPARATOR = '|';
    
    /**
     * 元数据键常量
     */
    const META_CONTENT_HASH = '_notion_content_hash';
    const META_PROPERTIES_HASH = '_notion_properties_hash';
    const META_BLOCKS_HASH = '_notion_blocks_hash';
    const META_TITLE_HASH = '_notion_title_hash';
    const META_LAST_CHECKED = '_notion_last_checked';
    const META_PROPERTIES = '_notion_properties';
    
    /**
     * 检测内容变化（只检测，不更新）
     *
     * 实时检测Notion页面的内容变化，不使用任何缓存
     * 这是核心的增量检测方法，只检测不更新状态
     *
     * @since 2.0.0-beta.1
     * @param array $notion_page Notion页面数据
     * @param int $post_id WordPress文章ID
     * @param bool $update_hashes 是否更新哈希值（默认false）
     * @return array 变化详情数组
     */
    public static function detect_content_changes(array $notion_page, int $post_id, bool $update_hashes = false): array {
        $changes = [];

        // 实时获取存储的哈希值，不使用缓存
        $stored_hash = get_post_meta($post_id, self::META_CONTENT_HASH, true);

        // 计算当前内容哈希
        $current_hash = self::calculate_content_hash($notion_page);

        if ($stored_hash !== $current_hash) {
            // 详细检测变化部分（不更新状态）
            $changes = self::detect_detailed_changes($notion_page, $post_id, $update_hashes);

            // 只有明确要求时才更新哈希值
            if ($update_hashes) {
                update_post_meta($post_id, self::META_CONTENT_HASH, $current_hash);
                update_post_meta($post_id, self::META_LAST_CHECKED, current_time('mysql'));

                // 记录变化检测结果
                if (class_exists('\\NTWP\\Core\\Logger')) {
                    \NTWP\Core\Foundation\Logger::debug_log(
                        sprintf(
                            '检测到内容变化并更新哈希 (文章ID: %d): %s',
                            $post_id,
                            implode(', ', array_keys($changes))
                        ),
                        'Incremental Detector'
                    );
                }
            } else {
                // 只记录检测结果，不更新状态
                if (class_exists('\\NTWP\\Core\\Logger')) {
                    \NTWP\Core\Foundation\Logger::debug_log(
                        sprintf(
                            '检测到内容变化（未更新哈希） (文章ID: %d): %s',
                            $post_id,
                            implode(', ', array_keys($changes))
                        ),
                        'Incremental Detector'
                    );
                }
            }
        } else {
            // 只有明确要求时才更新最后检查时间
            if ($update_hashes) {
                update_post_meta($post_id, self::META_LAST_CHECKED, current_time('mysql'));
            }
        }

        return $changes;
    }
    
    /**
     * 计算内容哈希
     * 
     * 计算页面的综合哈希值，包括标题、属性、内容、最后编辑时间
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @return string 内容哈希值
     */
    private static function calculate_content_hash(array $page): string {
        $content_parts = [
            $page['properties']['Name']['title'][0]['plain_text'] ?? '',
            json_encode($page['properties'] ?? []),
            $page['last_edited_time'] ?? '',
            json_encode($page['children'] ?? [])
        ];
        
        return hash(self::HASH_ALGORITHM, implode(self::HASH_SEPARATOR, $content_parts));
    }
    
    /**
     * 检测详细变化
     *
     * 分别检测标题、属性、内容的具体变化
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @param int $post_id WordPress文章ID
     * @param bool $update_hashes 是否更新哈希值
     * @return array 详细变化信息
     */
    private static function detect_detailed_changes(array $page, int $post_id, bool $update_hashes = false): array {
        $changes = [];
        
        // 检测标题变化
        $title_changes = self::detect_title_changes($page, $post_id);
        if (!empty($title_changes)) {
            $changes['title'] = $title_changes;
        }
        
        // 检测属性变化
        $properties_changes = self::detect_properties_changes($page, $post_id);
        if (!empty($properties_changes)) {
            $changes['properties'] = $properties_changes;
        }
        
        // 检测内容变化
        $content_changes = self::detect_content_blocks_changes($page, $post_id);
        if (!empty($content_changes)) {
            $changes['content'] = $content_changes;
        }
        
        // 检测最后编辑时间变化
        $time_changes = self::detect_time_changes($page, $post_id);
        if (!empty($time_changes)) {
            $changes['last_edited_time'] = $time_changes;
        }
        
        return $changes;
    }
    
    /**
     * 检测标题变化
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @param int $post_id WordPress文章ID
     * @return array 标题变化信息
     */
    private static function detect_title_changes(array $page, int $post_id): array {
        $current_title = $page['properties']['Name']['title'][0]['plain_text'] ?? '';
        $stored_title = get_the_title($post_id);
        
        if ($current_title !== $stored_title) {
            $current_title_hash = hash(self::HASH_ALGORITHM, $current_title);
            update_post_meta($post_id, self::META_TITLE_HASH, $current_title_hash);
            
            return [
                'old_title' => $stored_title,
                'new_title' => $current_title,
                'hash' => $current_title_hash
            ];
        }
        
        return [];
    }
    
    /**
     * 检测属性变化
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @param int $post_id WordPress文章ID
     * @return array 属性变化信息
     */
    private static function detect_properties_changes(array $page, int $post_id): array {
        $current_properties = $page['properties'] ?? [];
        $current_properties_json = json_encode($current_properties);
        $current_properties_hash = hash(self::HASH_ALGORITHM, $current_properties_json);
        
        $stored_properties = get_post_meta($post_id, self::META_PROPERTIES, true);
        $stored_properties_hash = get_post_meta($post_id, self::META_PROPERTIES_HASH, true);
        
        if ($stored_properties_hash !== $current_properties_hash) {
            // 更新存储的属性和哈希
            update_post_meta($post_id, self::META_PROPERTIES, $current_properties_json);
            update_post_meta($post_id, self::META_PROPERTIES_HASH, $current_properties_hash);
            
            // 分析具体变化的属性
            $changed_properties = self::analyze_properties_changes($stored_properties, $current_properties_json);
            
            return [
                'hash' => $current_properties_hash,
                'changed_properties' => $changed_properties,
                'total_properties' => count($current_properties)
            ];
        }
        
        return [];
    }
    
    /**
     * 检测内容块变化
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @param int $post_id WordPress文章ID
     * @return array 内容变化信息
     */
    private static function detect_content_blocks_changes(array $page, int $post_id): array {
        $current_blocks = $page['children'] ?? [];
        $current_blocks_json = json_encode($current_blocks);
        $current_blocks_hash = hash(self::HASH_ALGORITHM, $current_blocks_json);
        
        $stored_blocks_hash = get_post_meta($post_id, self::META_BLOCKS_HASH, true);
        
        if ($stored_blocks_hash !== $current_blocks_hash) {
            update_post_meta($post_id, self::META_BLOCKS_HASH, $current_blocks_hash);
            
            return [
                'hash' => $current_blocks_hash,
                'blocks_count' => count($current_blocks),
                'content_length' => strlen($current_blocks_json)
            ];
        }
        
        return [];
    }
    
    /**
     * 检测时间变化
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @param int $post_id WordPress文章ID
     * @return array 时间变化信息
     */
    private static function detect_time_changes(array $page, int $post_id): array {
        $current_time = $page['last_edited_time'] ?? '';
        $stored_time = get_post_meta($post_id, '_notion_last_edited_time', true);
        
        if ($current_time !== $stored_time) {
            update_post_meta($post_id, '_notion_last_edited_time', $current_time);
            
            return [
                'old_time' => $stored_time,
                'new_time' => $current_time
            ];
        }
        
        return [];
    }
    
    /**
     * 分析属性具体变化
     *
     * @since 2.0.0-beta.1
     * @param string $old_properties_json 旧属性JSON
     * @param string $new_properties_json 新属性JSON
     * @return array 变化的属性列表
     */
    private static function analyze_properties_changes(string $old_properties_json, string $new_properties_json): array {
        $old_properties = json_decode($old_properties_json, true) ?: [];
        $new_properties = json_decode($new_properties_json, true) ?: [];
        
        $changed = [];
        
        // 检查新增和修改的属性
        foreach ($new_properties as $key => $value) {
            if (!isset($old_properties[$key]) || $old_properties[$key] !== $value) {
                $changed[] = $key;
            }
        }
        
        // 检查删除的属性
        foreach ($old_properties as $key => $value) {
            if (!isset($new_properties[$key])) {
                $changed[] = $key . ' (deleted)';
            }
        }
        
        return array_unique($changed);
    }
    
    /**
     * 判断是否应该跳过同步
     *
     * 只对已同步过的页面进行增量检测
     * 新页面（从未同步过的）应该直接同步
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @param int $post_id WordPress文章ID
     * @return bool 是否应该跳过同步
     */
    public static function should_skip_sync(array $page, int $post_id): bool {
        // 检查是否是已同步过的页面
        $last_sync_time = get_post_meta($post_id, '_notion_last_sync_time', true);
        $content_hash = get_post_meta($post_id, self::META_CONTENT_HASH, true);

        // 如果从未同步过，不应该跳过
        if (empty($last_sync_time) || empty($content_hash)) {
            if (class_exists('\\NTWP\\Core\\Logger')) {
                \NTWP\Core\Foundation\Logger::debug_log(
                    "页面从未同步过，不跳过同步 (文章ID: {$post_id})",
                    'Incremental Detector'
                );
            }
            return false;
        }

        // 对已同步的页面进行增量检测（不更新哈希）
        $changes = self::detect_content_changes($page, $post_id, false);
        $should_skip = empty($changes);

        if (class_exists('\\NTWP\\Core\\Logger')) {
            if ($should_skip) {
                \NTWP\Core\Foundation\Logger::debug_log(
                    "已同步页面无变化，跳过同步 (文章ID: {$post_id})",
                    'Incremental Detector'
                );
            } else {
                \NTWP\Core\Foundation\Logger::debug_log(
                    "已同步页面有变化，需要同步 (文章ID: {$post_id}): " . implode(', ', array_keys($changes)),
                    'Incremental Detector'
                );
            }
        }

        return $should_skip;
    }

    /**
     * 更新页面的哈希值（同步完成后调用）
     *
     * 在页面同步完成后更新所有相关的哈希值
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @param int $post_id WordPress文章ID
     */
    public static function update_sync_hashes(array $page, int $post_id): void {
        // 更新所有哈希值
        $content_hash = self::calculate_content_hash($page);
        update_post_meta($post_id, self::META_CONTENT_HASH, $content_hash);

        // 更新详细哈希值
        $title = $page['properties']['Name']['title'][0]['plain_text'] ?? '';
        $title_hash = hash(self::HASH_ALGORITHM, $title);
        update_post_meta($post_id, self::META_TITLE_HASH, $title_hash);

        $properties_json = json_encode($page['properties'] ?? []);
        $properties_hash = hash(self::HASH_ALGORITHM, $properties_json);
        update_post_meta($post_id, self::META_PROPERTIES_HASH, $properties_hash);
        update_post_meta($post_id, self::META_PROPERTIES, $properties_json);

        $blocks_json = json_encode($page['children'] ?? []);
        $blocks_hash = hash(self::HASH_ALGORITHM, $blocks_json);
        update_post_meta($post_id, self::META_BLOCKS_HASH, $blocks_hash);

        // 更新时间戳
        update_post_meta($post_id, '_notion_last_edited_time', $page['last_edited_time'] ?? '');
        update_post_meta($post_id, self::META_LAST_CHECKED, current_time('mysql'));

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Foundation\Logger::debug_log(
                "已更新页面哈希值 (文章ID: {$post_id})",
                'Incremental Detector'
            );
        }
    }

    /**
     * 批量检测多个页面的变化
     * 
     * 高效地检测多个页面的变化情况
     *
     * @since 2.0.0-beta.1
     * @param array $pages Notion页面数组
     * @param array $post_mapping [notion_id => post_id] 映射
     * @return array [notion_id => changes] 变化映射
     */
    public static function batch_detect_changes(array $pages, array $post_mapping): array {
        $changes_map = [];
        
        foreach ($pages as $page) {
            $notion_id = $page['id'];
            $post_id = $post_mapping[$notion_id] ?? 0;
            
            if ($post_id > 0) {
                $changes = self::detect_content_changes($page, $post_id);
                if (!empty($changes)) {
                    $changes_map[$notion_id] = $changes;
                }
            }
        }
        
        return $changes_map;
    }
    
    /**
     * 获取增量检测统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public static function get_detection_stats(): array {
        global $wpdb;
        
        // 统计有哈希值的文章数量
        $total_tracked = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                self::META_CONTENT_HASH
            )
        );
        
        // 统计最近检查的文章数量
        $recently_checked = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} 
                WHERE meta_key = %s 
                AND meta_value > %s",
                self::META_LAST_CHECKED,
                date('Y-m-d H:i:s', strtotime('-1 hour'))
            )
        );
        
        return [
            'total_tracked_posts' => intval($total_tracked),
            'recently_checked_posts' => intval($recently_checked),
            'hash_algorithm' => self::HASH_ALGORITHM,
            'meta_keys' => [
                'content_hash' => self::META_CONTENT_HASH,
                'properties_hash' => self::META_PROPERTIES_HASH,
                'blocks_hash' => self::META_BLOCKS_HASH,
                'title_hash' => self::META_TITLE_HASH,
                'last_checked' => self::META_LAST_CHECKED
            ]
        ];
    }
    
    /**
     * 清理过期的检测数据
     *
     * @since 2.0.0-beta.1
     * @param int $days_old 清理多少天前的数据
     * @return int 清理的记录数
     */
    public static function cleanup_old_detection_data(int $days_old = 30): int {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} 
                WHERE meta_key = %s 
                AND meta_value < %s",
                self::META_LAST_CHECKED,
                $cutoff_date
            )
        );
        
        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Foundation\Logger::info_log(
                "清理了 {$deleted} 条过期的增量检测数据",
                'Incremental Detector'
            );
        }
        
        return intval($deleted);
    }

    /**
     * 与API层过滤的兼容性检测
     *
     * 验证API层过滤的结果与本地增量检测的一致性
     *
     * @since 2.0.0-beta.1
     * @param array $api_filtered_pages API层过滤的页面
     * @param array $local_pages 本地检测需要同步的页面
     * @return array 兼容性检测结果
     */
    public static function validate_api_filter_compatibility(array $api_filtered_pages, array $local_pages): array {
        $stats = [
            'api_count' => count($api_filtered_pages),
            'local_count' => count($local_pages),
            'matched_count' => 0,
            'api_only_count' => 0,
            'local_only_count' => 0,
            'compatibility_rate' => 0,
            'recommendations' => []
        ];

        // 提取页面ID进行对比
        $api_ids = array_column($api_filtered_pages, 'id');
        $local_ids = array_column($local_pages, 'id');

        // 计算交集和差集
        $matched_ids = array_intersect($api_ids, $local_ids);
        $api_only_ids = array_diff($api_ids, $local_ids);
        $local_only_ids = array_diff($local_ids, $api_ids);

        $stats['matched_count'] = count($matched_ids);
        $stats['api_only_count'] = count($api_only_ids);
        $stats['local_only_count'] = count($local_only_ids);

        // 计算兼容性率
        $total_unique = count(array_unique(array_merge($api_ids, $local_ids)));
        $stats['compatibility_rate'] = $total_unique > 0 ?
            round(($stats['matched_count'] / $total_unique) * 100, 1) : 100;

        // 生成建议
        if ($stats['compatibility_rate'] < 90) {
            $stats['recommendations'][] = 'API过滤与本地检测兼容性较低，建议检查时间戳同步';
        }

        if ($stats['api_only_count'] > $stats['local_count'] * 0.1) {
            $stats['recommendations'][] = 'API过滤获取了较多本地检测未发现的页面，可能存在时间戳偏差';
        }

        if ($stats['local_only_count'] > $stats['api_count'] * 0.1) {
            $stats['recommendations'][] = '本地检测发现了API过滤遗漏的页面，建议调整API过滤条件';
        }

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Foundation\Logger::info_log(
                sprintf(
                    'API过滤兼容性检测: 兼容率%.1f%%, API=%d, 本地=%d, 匹配=%d',
                    $stats['compatibility_rate'],
                    $stats['api_count'],
                    $stats['local_count'],
                    $stats['matched_count']
                ),
                'API Filter Compatibility'
            );
        }

        return $stats;
    }

    /**
     * 混合增量检测策略
     *
     * 结合API层过滤和本地增量检测的优势
     *
     * @since 2.0.0-beta.1
     * @param array $api_filtered_pages API层过滤的页面
     * @param string $database_id 数据库ID
     * @return array 最终需要同步的页面
     */
    public static function hybrid_incremental_detection(array $api_filtered_pages, string $database_id): array {
        $start_time = microtime(true);

        if (empty($api_filtered_pages)) {
            return [];
        }

        $final_pages = [];
        $api_trust_count = 0;
        $local_verify_count = 0;

        foreach ($api_filtered_pages as $page) {
            $notion_id = $page['id'];

            // 获取对应的WordPress文章ID
            $post_mapping = \NTWP\Infrastructure\Database\DatabaseHelper::batch_get_posts_by_notion_ids([$notion_id]);
            $post_id = $post_mapping[$notion_id] ?? 0;

            if ($post_id > 0) {
                // 对于已存在的文章，使用本地增量检测验证
                $change_detection = self::detect_content_changes($page, $post_id, false);

                if ($change_detection['has_changes']) {
                    $final_pages[] = $page;
                    $local_verify_count++;
                } else {
                    // API认为有变化但本地检测无变化，记录但不同步
                    if (class_exists('\\NTWP\\Core\\Logger')) {
                        \NTWP\Core\Foundation\Logger::debug_log(
                            sprintf('页面 %s API过滤通过但本地检测无变化', $notion_id),
                            'Hybrid Detection'
                        );
                    }
                }
            } else {
                // 对于新页面，直接信任API过滤结果
                $final_pages[] = $page;
                $api_trust_count++;
            }
        }

        $processing_time = microtime(true) - $start_time;

        // 记录混合检测统计
        if (class_exists('\\NTWP\\Core\\Performance\\PerformanceMonitor')) {
            \NTWP\Core\Foundation\Performance\PerformanceMonitor::record_custom_metric('hybrid_detection_time', $processing_time);
            \NTWP\Core\Foundation\Performance\PerformanceMonitor::record_custom_metric('hybrid_api_trust_count', $api_trust_count);
            \NTWP\Core\Foundation\Performance\PerformanceMonitor::record_custom_metric('hybrid_local_verify_count', $local_verify_count);
        }

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Foundation\Logger::info_log(
                sprintf(
                    '混合增量检测完成: API输入%d个, 最终%d个, API信任%d个, 本地验证%d个, 耗时%.3fs',
                    count($api_filtered_pages),
                    count($final_pages),
                    $api_trust_count,
                    $local_verify_count,
                    $processing_time
                ),
                'Hybrid Detection'
            );
        }

        return $final_pages;
    }

    /**
     * 获取API过滤建议配置
     *
     * 基于历史同步数据生成API过滤的最优配置
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @return array 建议的API过滤配置
     */
    public static function get_api_filter_recommendations(string $database_id): array {
        global $wpdb;

        $recommendations = [
            'time_buffer_minutes' => 5, // 默认5分钟缓冲
            'suggested_filters' => [],
            'confidence_level' => 'medium'
        ];

        // 分析最近的同步模式
        $recent_syncs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value as last_sync
                FROM {$wpdb->postmeta}
                WHERE meta_key = %s
                ORDER BY meta_id DESC
                LIMIT 10",
                '_notion_last_sync_time'
            )
        );

        if (!empty($recent_syncs)) {
            $sync_intervals = [];
            for ($i = 1; $i < count($recent_syncs); $i++) {
                $current = strtotime($recent_syncs[$i-1]->last_sync);
                $previous = strtotime($recent_syncs[$i]->last_sync);
                if ($current && $previous) {
                    $sync_intervals[] = ($current - $previous) / 60; // 转换为分钟
                }
            }

            if (!empty($sync_intervals)) {
                $avg_interval = array_sum($sync_intervals) / count($sync_intervals);

                // 根据平均同步间隔调整时间缓冲
                if ($avg_interval < 30) {
                    $recommendations['time_buffer_minutes'] = 2;
                    $recommendations['confidence_level'] = 'high';
                } elseif ($avg_interval > 120) {
                    $recommendations['time_buffer_minutes'] = 10;
                    $recommendations['confidence_level'] = 'low';
                }
            }
        }

        // 添加常用过滤建议
        $recommendations['suggested_filters'] = [
            [
                'property' => 'Status',
                'select' => ['does_not_equal' => 'Archived'],
                'description' => '排除已归档的页面'
            ],
            [
                'property' => 'Published',
                'checkbox' => ['equals' => true],
                'description' => '仅同步已发布的页面'
            ]
        ];

        return $recommendations;
    }
}
