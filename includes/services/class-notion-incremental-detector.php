<?php
declare(strict_types=1);

/**
 * Notion 增量内容检测器类
 * 
 * 实现精确的内容变化检测，只同步真正变化的内容部分，大幅提升同步效率
 * 实时检测内容变化，不使用任何缓存，确保检测的准确性和实时性
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

class Notion_Incremental_Detector {
    
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
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::debug_log(
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
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::debug_log(
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
            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    "页面从未同步过，不跳过同步 (文章ID: {$post_id})",
                    'Incremental Detector'
                );
            }
            return false;
        }

        // 对已同步的页面进行增量检测（不更新哈希）
        $changes = self::detect_content_changes($page, $post_id, false);
        $should_skip = empty($changes);

        if (class_exists('Notion_Logger')) {
            if ($should_skip) {
                Notion_Logger::debug_log(
                    "已同步页面无变化，跳过同步 (文章ID: {$post_id})",
                    'Incremental Detector'
                );
            } else {
                Notion_Logger::debug_log(
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

        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
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
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                "清理了 {$deleted} 条过期的增量检测数据",
                'Incremental Detector'
            );
        }
        
        return intval($deleted);
    }
}
