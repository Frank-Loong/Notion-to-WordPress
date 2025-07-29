<?php
declare(strict_types=1);

namespace NTWP\Services;

/**
 * API管理服务
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
 * API管理服务
 */
class API_Service extends Abstract_Service {
    
    /**
     * 网络管理器
     * @var Concurrent_Network_Manager
     */
    private $network;
    
    /**
     * 缓存服务
     * @var Smart_Cache
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
