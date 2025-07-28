<?php
declare(strict_types=1);

/**
 * Notion 服务层类
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
class Notion_Content_Sync_Service extends Notion_Abstract_Service {
    
    /**
     * 数据访问层
     * @var Notion_Database_Helper
     */
    private $database;
    
    /**
     * 缓存服务
     * @var Notion_Smart_Cache
     */
    private $cache;
    
    /**
     * 初始化服务
     */
    public function init(): void {
        if ($this->initialized) {
            return;
        }
        
        $this->database = Notion_Dependency_Container::get('database');
        $this->cache = Notion_Dependency_Container::get('cache');
        
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

/**
 * API管理服务
 */
class Notion_API_Service extends Notion_Abstract_Service {
    
    /**
     * 网络管理器
     * @var Notion_Concurrent_Network_Manager
     */
    private $network;
    
    /**
     * 缓存服务
     * @var Notion_Smart_Cache
     */
    private $cache;
    
    /**
     * 初始化服务
     */
    public function init(): void {
        if ($this->initialized) {
            return;
        }
        
        $this->network = Notion_Dependency_Container::get('network');
        $this->cache = Notion_Dependency_Container::get('cache');
        
        $this->initialized = true;
    }
    
    /**
     * 发起API请求
     *
     * @param string $endpoint API端点
     * @param array $params 请求参数
     * @param array $options 请求选项
     * @return array API响应
     */
    public function request(string $endpoint, array $params = [], array $options = []): array {
        $this->init();
        
        // 检查缓存策略
        $cache_strategy = $this->cache->get_cache_strategy($endpoint, $params);
        
        if ($cache_strategy['cacheable']) {
            $cache_key = md5($endpoint . serialize($params));
            $cached_response = $this->cache->get_tiered($cache_strategy['type'], $cache_key);
            
            if ($cached_response !== false) {
                return $cached_response;
            }
        }
        
        // 发起实际请求
        $response = $this->network->make_request($endpoint, $params, $options);
        
        // 缓存响应
        if ($cache_strategy['cacheable'] && isset($response['success']) && $response['success']) {
            $this->cache->set_tiered(
                $cache_strategy['type'], 
                $cache_key, 
                $response, 
                [], 
                $cache_strategy['ttl']
            );
        }
        
        return $response;
    }
    
    /**
     * 批量API请求
     *
     * @param array $requests 请求数组
     * @param array $options 选项
     * @return array 批量响应
     */
    public function batch_request(array $requests, array $options = []): array {
        $this->init();
        
        // 使用API合并器优化批量请求
        $merger = Notion_Dependency_Container::get('api_merger');
        return $merger->merge_and_execute($requests, $options);
    }
}

/**
 * 任务管理服务
 */
class Notion_Task_Service extends Notion_Abstract_Service {
    
    /**
     * 任务调度器
     * @var Notion_Async_Task_Scheduler
     */
    private $scheduler;
    
    /**
     * 并发管理器
     * @var Notion_Unified_Concurrency_Manager
     */
    private $concurrency;
    
    /**
     * 初始化服务
     */
    public function init(): void {
        if ($this->initialized) {
            return;
        }
        
        $this->scheduler = Notion_Dependency_Container::get('scheduler');
        $this->concurrency = Notion_Dependency_Container::get('concurrency');
        
        $this->initialized = true;
    }
    
    /**
     * 创建同步任务
     *
     * @param array $task_data 任务数据
     * @param array $options 任务选项
     * @return string|false 任务ID或false
     */
    public function create_sync_task(array $task_data, array $options = []) {
        $this->init();
        
        // 智能调度任务
        return $this->scheduler->smart_schedule(
            Notion_Async_Task_Scheduler::TASK_INCREMENTAL_SYNC,
            $task_data,
            $options
        );
    }
    
    /**
     * 创建批量导入任务
     *
     * @param array $pages_data 页面数据
     * @param array $options 任务选项
     * @return string|false 任务ID或false
     */
    public function create_import_task(array $pages_data, array $options = []) {
        $this->init();
        
        // 检查并发限制
        $batch_info = $this->concurrency->manage_batch_tasks('request', count($pages_data));
        
        if ($batch_info['recommendation'] === 'consider_splitting') {
            // 分割大任务
            return $this->create_split_import_tasks($pages_data, $options, $batch_info);
        }
        
        return $this->scheduler->smart_schedule(
            Notion_Async_Task_Scheduler::TASK_BATCH_IMPORT,
            ['pages' => $pages_data],
            $options
        );
    }
    
    /**
     * 创建分割的导入任务
     *
     * @param array $pages_data 页面数据
     * @param array $options 选项
     * @param array $batch_info 批次信息
     * @return array 任务ID数组
     */
    private function create_split_import_tasks(array $pages_data, array $options, array $batch_info): array {
        $task_ids = [];
        $chunks = array_chunk($pages_data, $batch_info['batch_size']);
        
        foreach ($chunks as $chunk) {
            $task_id = $this->scheduler->smart_schedule(
                Notion_Async_Task_Scheduler::TASK_BATCH_IMPORT,
                ['pages' => $chunk],
                $options
            );
            
            if ($task_id) {
                $task_ids[] = $task_id;
            }
        }
        
        return $task_ids;
    }
}
