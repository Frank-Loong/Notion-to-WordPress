<?php
declare(strict_types=1);

namespace NTWP\Services;

use NTWP\Core\Logger;
use NTWP\Infrastructure\CacheManager;

/**
 * 统一同步服务 - 合并SyncManager + ContentSyncService
 * 
 * 职责: 统一处理所有同步相关操作
 */
class SyncService {
    
    private CacheManager $cache;
    private ApiService $api_service;
    
    public function __construct(CacheManager $cache, ApiService $api_service) {
        $this->cache = $cache;
        $this->api_service = $api_service;
    }
    
    /**
     * 获取数据库页面
     */
    public function fetch_pages(string $database_id, array $options = []): array {
        return $this->api_service->fetch_database_pages($database_id, $options);
    }
    
    /**
     * 保存到WordPress
     */
    public function save_to_wordpress(array $page_data, $config): array {
        // 检查是否已存在
        $existing_post = $this->find_existing_post($page_data['notion_id']);
        
        if ($existing_post) {
            return $this->update_existing_post($existing_post, $page_data);
        } else {
            return $this->create_new_post($page_data);
        }
    }
    
    /**
     * 批量同步
     */
    public function batch_sync(array $pages, $config): array {
        $results = [];
        
        foreach ($pages as $page) {
            try {
                $result = $this->save_to_wordpress($page, $config);
                $results[] = $result;
            } catch (\Exception $e) {
                Logger::error_log("同步失败: " . $e->getMessage(), 'SyncService');
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'notion_id' => $page['notion_id'] ?? 'unknown'
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * 增量同步检测
     */
    public function detect_changes(string $database_id): array {
        $cache_key = "last_sync_{$database_id}";
        $last_sync = $this->cache->get($cache_key, 'session');
        
        if (!$last_sync) {
            // 首次同步，获取所有数据
            $pages = $this->fetch_pages($database_id);
        } else {
            // 增量同步，只获取变更数据
            $pages = $this->fetch_pages($database_id, [
                'filter' => [
                    'property' => 'last_edited_time',
                    'date' => ['after' => $last_sync]
                ]
            ]);
        }
        
        // 更新最后同步时间
        $this->cache->set($cache_key, date('c'), 3600, 'session');
        
        return $pages;
    }
    
    /**
     * 同步状态监控
     */
    public function get_sync_status(string $database_id): array {
        return [
            'last_sync' => $this->cache->get("last_sync_{$database_id}", 'session'),
            'total_synced' => $this->get_synced_count($database_id),
            'failed_count' => $this->get_failed_count($database_id),
            'next_scheduled' => wp_next_scheduled('ntwp_scheduled_sync', [$database_id])
        ];
    }
    
    // === 私有方法 ===
    
    private function find_existing_post(string $notion_id): ?\WP_Post {
        $posts = get_posts([
            'meta_key' => 'notion_id',
            'meta_value' => $notion_id,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ]);
        
        return $posts ? $posts[0] : null;
    }
    
    private function update_existing_post(\WP_Post $post, array $page_data): array {
        $updated_data = [
            'ID' => $post->ID,
            'post_title' => $page_data['title'],
            'post_content' => $page_data['content'],
            'post_status' => $page_data['status'],
            'meta_input' => $page_data['metadata']
        ];
        
        $result = wp_update_post($updated_data, true);
        
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        
        return [
            'success' => true,
            'post_id' => $result,
            'notion_id' => $page_data['notion_id'],
            'is_update' => true,
            'status' => 'updated'
        ];
    }
    
    private function create_new_post(array $page_data): array {
        $post_data = [
            'post_title' => $page_data['title'],
            'post_content' => $page_data['content'],
            'post_status' => $page_data['status'],
            'post_type' => $page_data['post_type'],
            'meta_input' => array_merge($page_data['metadata'], [
                'notion_id' => $page_data['notion_id']
            ])
        ];
        
        $result = wp_insert_post($post_data, true);
        
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        
        return [
            'success' => true,
            'post_id' => $result,
            'notion_id' => $page_data['notion_id'],
            'is_update' => false,
            'status' => 'created'
        ];
    }
    
    private function get_synced_count(string $database_id): int {
        global $wpdb;
        
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = 'notion_database_id' 
             AND meta_value = %s",
            $database_id
        );
    }
    
    private function get_failed_count(string $database_id): int {
        return (int) $this->cache->get("failed_count_{$database_id}", 'session') ?: 0;
    }
}