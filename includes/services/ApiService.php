<?php
declare(strict_types=1);

namespace NTWP\Services;

use NTWP\Core\Logger;
use NTWP\Infrastructure\{ConcurrencyManager, CacheManager};
use NTWP\Utils\Network_Retry;

/**
 * 统一API服务 - 重构自API类
 * 
 * 职责: 统一处理所有Notion API交互
 */
class ApiService {
    
    private string $api_token;
    private string $base_url = 'https://api.notion.com/v1';
    private ConcurrencyManager $concurrency;
    private CacheManager $cache;
    private Network_Retry $retry;
    
    public function __construct(
        string $api_token,
        ConcurrencyManager $concurrency,
        CacheManager $cache,
        Network_Retry $retry
    ) {
        $this->api_token = $api_token;
        $this->concurrency = $concurrency;
        $this->cache = $cache;
        $this->retry = $retry;
    }
    
    /**
     * 获取数据库页面
     */
    public function fetch_database_pages(string $database_id, array $options = []): array {
        $cache_key = "db_pages_{$database_id}_" . md5(serialize($options));
        
        // 检查缓存
        $cached = $this->cache->get($cache_key, 'api_response');
        if ($cached !== null) {
            Logger::debug_log('使用缓存的数据库页面', 'ApiService');
            return $cached;
        }
        
        $all_pages = [];
        $next_cursor = null;
        
        do {
            $query_data = array_merge($options, [
                'page_size' => min($options['page_size'] ?? 100, 100)
            ]);
            
            if ($next_cursor) {
                $query_data['start_cursor'] = $next_cursor;
            }
            
            $response = $this->make_request(
                'POST',
                "/databases/{$database_id}/query",
                $query_data
            );
            
            if (!$response['success']) {
                throw new \Exception("获取数据库页面失败: " . $response['error']);
            }
            
            $data = $response['data'];
            $all_pages = array_merge($all_pages, $data['results'] ?? []);
            $next_cursor = $data['next_cursor'] ?? null;
            
        } while ($next_cursor && $data['has_more']);
        
        // 获取页面详细内容
        $detailed_pages = $this->fetch_pages_content($all_pages);
        
        // 缓存结果
        $this->cache->set($cache_key, $detailed_pages, 300, 'api_response');
        
        Logger::debug_log(sprintf('获取到 %d 个数据库页面', count($detailed_pages)), 'ApiService');
        
        return $detailed_pages;
    }
    
    /**
     * 获取页面内容
     */
    public function fetch_page_content(string $page_id): array {
        $cache_key = "page_content_{$page_id}";
        
        // 检查缓存
        $cached = $this->cache->get($cache_key, 'page_content');
        if ($cached !== null) {
            return $cached;
        }
        
        // 获取页面基本信息
        $page_response = $this->make_request('GET', "/pages/{$page_id}");
        if (!$page_response['success']) {
            throw new \Exception("获取页面失败: " . $page_response['error']);
        }
        
        // 获取页面块内容
        $blocks_response = $this->make_request('GET', "/blocks/{$page_id}/children");
        if (!$blocks_response['success']) {
            throw new \Exception("获取页面块失败: " . $blocks_response['error']);
        }
        
        $page_data = $page_response['data'];
        $page_data['blocks'] = $blocks_response['data']['results'] ?? [];
        
        // 递归获取子块
        $page_data['blocks'] = $this->fetch_child_blocks($page_data['blocks']);
        
        // 缓存结果
        $this->cache->set($cache_key, $page_data, 300, 'page_content');
        
        return $page_data;
    }
    
    /**
     * 获取数据库信息
     */
    public function fetch_database_info(string $database_id): array {
        $cache_key = "db_info_{$database_id}";
        
        // 检查缓存
        $cached = $this->cache->get($cache_key, 'database_structure');
        if ($cached !== null) {
            return $cached;
        }
        
        $response = $this->make_request('GET', "/databases/{$database_id}");
        
        if (!$response['success']) {
            throw new \Exception("获取数据库信息失败: " . $response['error']);
        }
        
        $database_info = $response['data'];
        
        // 缓存结果
        $this->cache->set($cache_key, $database_info, 1800, 'database_structure');
        
        return $database_info;
    }
    
    /**
     * 获取用户信息
     */
    public function fetch_user_info(): array {
        $cache_key = "user_info";
        
        // 检查缓存
        $cached = $this->cache->get($cache_key, 'user_info');
        if ($cached !== null) {
            return $cached;
        }
        
        $response = $this->make_request('GET', '/users/me');
        
        if (!$response['success']) {
            throw new \Exception("获取用户信息失败: " . $response['error']);
        }
        
        $user_info = $response['data'];
        
        // 缓存结果
        $this->cache->set($cache_key, $user_info, 3600, 'user_info');
        
        return $user_info;
    }
    
    /**
     * 批量获取页面
     */
    public function batch_fetch_pages(array $page_ids): array {
        if (empty($page_ids)) {
            return [];
        }
        
        // 准备并发请求
        $requests = [];
        foreach ($page_ids as $page_id) {
            $requests[] = [
                'url' => $this->base_url . "/pages/{$page_id}",
                'headers' => $this->get_headers(),
                'page_id' => $page_id
            ];
        }
        
        // 执行并发请求
        $responses = $this->concurrency->execute_concurrent_requests($requests);
        
        $results = [];
        foreach ($responses as $index => $response) {
            $page_id = $requests[$index]['page_id'];
            
            if ($response['success']) {
                $data = json_decode($response['response'], true);
                if ($data) {
                    $results[$page_id] = $data;
                }
            } else {
                Logger::warning_log("批量获取页面失败: {$page_id}", 'ApiService');
            }
        }
        
        return $results;
    }
    
    // === 私有方法 ===
    
    private function make_request(string $method, string $endpoint, array $data = null): array {
        $url = $this->base_url . $endpoint;
        
        return $this->retry->execute(function() use ($method, $url, $data) {
            $args = [
                'method' => $method,
                'headers' => $this->get_headers(),
                'timeout' => 30,
            ];
            
            if ($data !== null) {
                $args['body'] = json_encode($data);
            }
            
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($status_code >= 400) {
                $error_data = json_decode($body, true);
                $error_message = $error_data['message'] ?? "HTTP {$status_code}";
                throw new \Exception($error_message);
            }
            
            $data = json_decode($body, true);
            if (!$data) {
                throw new \Exception('无效的JSON响应');
            }
            
            return [
                'success' => true,
                'data' => $data,
                'status_code' => $status_code
            ];
            
        }, [
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2
        ]);
    }
    
    private function get_headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->api_token,
            'Content-Type' => 'application/json',
            'Notion-Version' => '2022-06-28',
            'User-Agent' => 'Notion-to-WordPress/2.0'
        ];
    }
    
    private function fetch_pages_content(array $pages): array {
        $page_ids = array_column($pages, 'id');
        
        if (empty($page_ids)) {
            return $pages;
        }
        
        // 批量获取页面内容
        $detailed_pages = [];
        foreach ($pages as $page) {
            try {
                $page_content = $this->fetch_page_content($page['id']);
                $detailed_pages[] = $page_content;
            } catch (\Exception $e) {
                Logger::warning_log("获取页面内容失败: {$page['id']} - " . $e->getMessage(), 'ApiService');
                $detailed_pages[] = $page; // 保留基本信息
            }
        }
        
        return $detailed_pages;
    }
    
    private function fetch_child_blocks(array $blocks): array {
        foreach ($blocks as &$block) {
            if ($block['has_children'] ?? false) {
                try {
                    $children_response = $this->make_request('GET', "/blocks/{$block['id']}/children");
                    if ($children_response['success']) {
                        $block['children'] = $this->fetch_child_blocks($children_response['data']['results'] ?? []);
                    }
                } catch (\Exception $e) {
                    Logger::warning_log("获取子块失败: {$block['id']} - " . $e->getMessage(), 'ApiService');
                    $block['children'] = [];
                }
            }
        }
        
        return $blocks;
    }
}