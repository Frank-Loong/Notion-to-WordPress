<?php
declare(strict_types=1);

namespace NTWP\Services;

/**
 * 内容同步服务
 * 
 * 分离数据访问层和业务逻辑层，提供统一的服务接口
 * 实现业务逻辑的封装和复用
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

/**
 * 内容同步服务
 */
class ContentSyncService extends Abstract_Service {
    
    /**
     * 数据访问层
     * @var DatabaseHelper
     */
    private $database;
    
    /**
     * 缓存服务
     * @var SmartCache
     */
    private $cache;
    
    /**
     * 初始化服务
     */
    public function init(): void {
        if ($this->initialized) {
            return;
        }
        
        $this->database = Notion_Container::get('database');
        $this->cache = Notion_Container::get('cache');
        
        $this->initialized = true;
    }
    
    /**
     * 同步单个页面
     *
     * @param array $page_data 页面数据
     * @param array $options 同步选项
     * @return array 同步结果
     */
    public function sync_page(array $page_data, array $options = []): array {
        $this->init();
        
        $page_id = $page_data['id'] ?? '';
        if (empty($page_id)) {
            return ['success' => false, 'error' => 'Invalid page ID'];
        }
        
        try {
            // 检查缓存
            $cache_key = "page_sync_{$page_id}";
            $cached_result = $this->cache->get_tiered('page_content', $cache_key);

            if ($cached_result !== false && !($options['force_refresh'] ?? false)) {
                return $cached_result;
            }
            
            // 执行同步逻辑
            $result = $this->perform_page_sync($page_data, $options);
            
            // 缓存结果
            if ($result['success']) {
                $this->cache->set_tiered('page_content', $cache_key, $result, [], 300);
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'page_id' => $page_id
            ];
        }
    }
    
    /**
     * 批量同步页面
     *
     * @param array $pages_data 页面数据数组
     * @param array $options 同步选项
     * @return array 批量同步结果
     */
    public function sync_pages_batch(array $pages_data, array $options = []): array {
        $this->init();
        
        $results = [
            'total' => count($pages_data),
            'success' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        foreach ($pages_data as $page_data) {
            $result = $this->sync_page($page_data, $options);
            
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            
            $results['details'][] = $result;
        }
        
        return $results;
    }
    
    /**
     * 执行页面同步
     *
     * @param array $page_data 页面数据
     * @param array $options 同步选项
     * @return array 同步结果
     */
    private function perform_page_sync(array $page_data, array $options): array {
        // 这里实现具体的同步逻辑
        // 分离了业务逻辑和数据访问
        
        $page_id = $page_data['id'];
        $existing_post_id = $this->database->get_post_by_notion_id($page_id);
        
        if ($existing_post_id) {
            // 更新现有文章
            return $this->update_existing_post($existing_post_id, $page_data, $options);
        } else {
            // 创建新文章
            return $this->create_new_post($page_data, $options);
        }
    }
    
    /**
     * 更新现有文章
     *
     * @param int $post_id WordPress文章ID
     * @param array $page_data 页面数据
     * @param array $options 选项
     * @return array 更新结果
     */
    private function update_existing_post(int $post_id, array $page_data, array $options): array {
        // 实现更新逻辑
        return [
            'success' => true,
            'action' => 'updated',
            'post_id' => $post_id,
            'notion_id' => $page_data['id']
        ];
    }
    
    /**
     * 创建新文章
     *
     * @param array $page_data 页面数据
     * @param array $options 选项
     * @return array 创建结果
     */
    private function create_new_post(array $page_data, array $options): array {
        // 实现创建逻辑
        return [
            'success' => true,
            'action' => 'created',
            'post_id' => 0, // 实际创建后的ID
            'notion_id' => $page_data['id']
        ];
    }
}
