<?php
declare(strict_types=1);

namespace NTWP\Services\Sync;

use NTWP\Core\Foundation\Logger;
use NTWP\Services\Api\NotionApi;
use NTWP\Infrastructure\Cache\CacheManager;
use NTWP\Infrastructure\Concurrency\ConcurrencyManager;
use NTWP\Utils\ApiResult;

/**
 * 增量同步服务
 *
 * 从原始API.php中提取的智能增量同步功能
 * 负责高效的增量数据获取和同步策略
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
class IncrementalSyncService {
    
    private NotionApi $notion_api;
    private CacheManager $cache;
    private ConcurrencyManager $concurrency;
    
    /**
     * 增量同步统计数据
     * @var array
     */
    private array $sync_stats = [
        'total_databases_processed' => 0,
        'total_pages_fetched' => 0,
        'incremental_queries' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'data_size_saved' => 0,
        'time_saved_seconds' => 0
    ];
    
    /**
     * 数据大小估算缓存
     * @var array
     */
    private array $size_estimation_cache = [];
    
    public function __construct(
        NotionApi $notion_api,
        CacheManager $cache,
        ConcurrencyManager $concurrency
    ) {
        $this->notion_api = $notion_api;
        $this->cache = $cache;
        $this->concurrency = $concurrency;
    }
    
    /**
     * 智能增量获取增强版 (从原API.php恢复)
     *
     * @param string $database_id 数据库ID
     * @param string $last_sync_time 上次同步时间
     * @param array $additional_filters 额外筛选条件
     * @param bool $with_details 是否包含详细信息
     * @return ApiResult 增量同步结果
     */
    public function smart_incremental_fetch_enhanced(
        string $database_id, 
        string $last_sync_time = '', 
        array $additional_filters = [], 
        bool $with_details = false
    ): ApiResult {
        
        $start_time = microtime(true);
        
        Logger::debug_log(
            sprintf('开始智能增量同步: %s, 上次同步: %s', $database_id, $last_sync_time),
            'IncrementalSyncService'
        );
        
        // 构建增量筛选条件
        $incremental_filter = $this->build_incremental_filter($last_sync_time, $additional_filters);
        
        // 估算数据大小以选择最优策略
        $estimated_size = $this->estimate_database_size($database_id, $incremental_filter);
        
        try {
            // 根据数据大小选择同步策略
            if ($estimated_size > 1000) {
                $result = $this->execute_large_dataset_sync($database_id, $incremental_filter, $with_details);
            } elseif ($estimated_size > 100) {
                $result = $this->execute_medium_dataset_sync($database_id, $incremental_filter, $with_details);
            } else {
                $result = $this->execute_small_dataset_sync($database_id, $incremental_filter, $with_details);
            }
            
            // 更新统计
            $execution_time = microtime(true) - $start_time;
            $this->update_sync_stats($database_id, $result, $execution_time, $estimated_size);
            
            return $result;
            
        } catch (\Exception $e) {
            Logger::debug_log(
                sprintf('增量同步失败: %s - %s', $database_id, $e->getMessage()),
                'IncrementalSyncService'
            );
            
            return new ApiResult(false, [], $e->getMessage());
        }
    }
    
    /**
     * 批量智能增量获取
     *
     * @param array $database_configs 数据库配置数组
     * @param bool $with_details 是否包含详细信息
     * @return array 批量同步结果
     */
    public function batch_smart_incremental_fetch(array $database_configs, bool $with_details = false): array {
        if (empty($database_configs)) {
            return [];
        }
        
        Logger::debug_log(
            sprintf('开始批量增量同步: %d 个数据库', count($database_configs)),
            'IncrementalSyncService'
        );
        
        $results = [];
        $concurrent_requests = [];
        
        // 准备并发请求
        foreach ($database_configs as $config) {
            $database_id = $config['database_id'];
            $last_sync_time = $config['last_sync_time'] ?? '';
            $filters = $config['filters'] ?? [];
            
            $concurrent_requests[$database_id] = function() use ($database_id, $last_sync_time, $filters, $with_details) {
                return $this->smart_incremental_fetch_enhanced($database_id, $last_sync_time, $filters, $with_details);
            };
        }
        
        // 执行并发同步
        foreach ($concurrent_requests as $database_id => $sync_function) {
            $results[$database_id] = $sync_function();
        }
        
        return $results;
    }
    
    /**
     * 估算数据库数据大小
     *
     * @param string $database_id 数据库ID
     * @param array $filter 筛选条件
     * @return int 估算的数据量
     */
    public function estimate_database_size(string $database_id, array $filter = []): int {
        $cache_key = "db_size_estimate_{$database_id}_" . md5(serialize($filter));
        
        // 检查缓存
        if (isset($this->size_estimation_cache[$cache_key])) {
            return $this->size_estimation_cache[$cache_key];
        }
        
        $cached_estimate = $this->cache->get($cache_key, 'database_structure');
        if ($cached_estimate !== null) {
            $this->size_estimation_cache[$cache_key] = $cached_estimate;
            return $cached_estimate;
        }
        
        try {
            // 使用小页面大小进行初步查询
            $sample_filter = array_merge($filter, ['page_size' => 10]);
            $sample_response = $this->notion_api->get_database_pages($database_id, $sample_filter);
            
            if (empty($sample_response)) {
                $estimated_size = 0;
            } else {
                // 基于样本估算总数
                $sample_count = count($sample_response);
                $has_more = isset($sample_response['has_more']) && $sample_response['has_more'];
                
                if ($has_more) {
                    // 如果还有更多数据，估算为样本的10-50倍
                    $estimated_size = $sample_count * 25;
                } else {
                    $estimated_size = $sample_count;
                }
            }
            
            // 缓存估算结果
            $this->cache->set($cache_key, $estimated_size, 1800, 'database_structure');
            $this->size_estimation_cache[$cache_key] = $estimated_size;
            
            Logger::debug_log(
                sprintf('数据库大小估算: %s = %d 条记录', $database_id, $estimated_size),
                'IncrementalSyncService'
            );
            
            return $estimated_size;
            
        } catch (\Exception $e) {
            Logger::debug_log(
                sprintf('数据库大小估算失败: %s - %s', $database_id, $e->getMessage()),
                'IncrementalSyncService'
            );
            
            // 返回保守估算
            return 100;
        }
    }
    
    /**
     * 获取增量同步统计
     *
     * @return array 统计数据
     */
    public function get_incremental_sync_stats(): array {
        $stats = $this->sync_stats;
        
        // 添加计算字段
        $stats['average_pages_per_database'] = $stats['total_databases_processed'] > 0 
            ? round($stats['total_pages_fetched'] / $stats['total_databases_processed'], 2)
            : 0;
            
        $stats['cache_hit_rate'] = ($stats['cache_hits'] + $stats['cache_misses']) > 0
            ? round($stats['cache_hits'] / ($stats['cache_hits'] + $stats['cache_misses']) * 100, 2) . '%'
            : '0%';
            
        $stats['data_size_saved_mb'] = round($stats['data_size_saved'] / 1024 / 1024, 2);
        
        return $stats;
    }
    
    /**
     * 构建增量筛选条件
     *
     * @param string $last_sync_time 上次同步时间
     * @param array $additional_filters 额外筛选条件
     * @return array 完整的筛选条件
     */
    private function build_incremental_filter(string $last_sync_time, array $additional_filters = []): array {
        $filter = $additional_filters;
        
        if (!empty($last_sync_time)) {
            // 添加时间筛选条件
            $time_filter = [
                'property' => 'last_edited_time',
                'date' => [
                    'after' => $this->format_timestamp_for_api($last_sync_time)
                ]
            ];
            
            if (isset($filter['and'])) {
                $filter['and'][] = $time_filter;
            } elseif (isset($filter['or'])) {
                // 如果已有OR条件，包装成AND
                $filter = [
                    'and' => [
                        $filter,
                        $time_filter
                    ]
                ];
            } else {
                $filter = [
                    'and' => [
                        $filter,
                        $time_filter
                    ]
                ];
            }
        }
        
        return $filter;
    }
    
    /**
     * 格式化时间戳供API使用
     *
     * @param string $timestamp 时间戳
     * @return string 格式化的时间戳
     */
    private function format_timestamp_for_api(string $timestamp): string {
        // 如果已经是ISO 8601格式，直接返回
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $timestamp)) {
            return $timestamp;
        }
        
        // 转换为ISO 8601格式
        $datetime = new \DateTime($timestamp);
        return $datetime->format('c');
    }
    
    /**
     * 执行大数据集同步 (>1000条记录)
     */
    private function execute_large_dataset_sync(string $database_id, array $filter, bool $with_details): ApiResult {
        Logger::debug_log('使用大数据集同步策略', 'IncrementalSyncService');
        
        // 使用分页和并发处理
        $all_pages = [];
        $page_size = 100;
        $next_cursor = null;
        
        do {
            $current_filter = array_merge($filter, [
                'page_size' => $page_size,
                'start_cursor' => $next_cursor
            ]);
            
            $response = $this->notion_api->get_database_pages($database_id, $current_filter);
            
            if (!empty($response)) {
                $all_pages = array_merge($all_pages, $response);
                $next_cursor = $response['next_cursor'] ?? null;
            } else {
                break;
            }
            
        } while ($next_cursor);
        
        return new ApiResult(true, $all_pages, '大数据集同步完成');
    }
    
    /**
     * 执行中等数据集同步 (100-1000条记录)
     */
    private function execute_medium_dataset_sync(string $database_id, array $filter, bool $with_details): ApiResult {
        Logger::debug_log('使用中等数据集同步策略', 'IncrementalSyncService');
        
        $pages = $this->notion_api->get_database_pages($database_id, $filter);
        
        if ($with_details && !empty($pages)) {
            // 批量获取详细信息
            $page_ids = array_column($pages, 'id');
            $detailed_pages = $this->notion_api->batch_get_pages($page_ids);
            
            // 合并详细信息
            foreach ($pages as &$page) {
                if (isset($detailed_pages[$page['id']])) {
                    $page = array_merge($page, $detailed_pages[$page['id']]);
                }
            }
        }
        
        return new ApiResult(true, $pages, '中等数据集同步完成');
    }
    
    /**
     * 执行小数据集同步 (<100条记录)
     */
    private function execute_small_dataset_sync(string $database_id, array $filter, bool $with_details): ApiResult {
        Logger::debug_log('使用小数据集同步策略', 'IncrementalSyncService');
        
        $pages = $this->notion_api->get_database_pages($database_id, $filter);
        
        if ($with_details && !empty($pages)) {
            // 为每个页面获取完整内容
            foreach ($pages as &$page) {
                $page['content'] = $this->notion_api->get_page_content($page['id']);
            }
        }
        
        return new ApiResult(true, $pages, '小数据集同步完成');
    }
    
    /**
     * 更新同步统计
     */
    private function update_sync_stats(string $database_id, ApiResult $result, float $execution_time, int $estimated_size): void {
        $this->sync_stats['total_databases_processed']++;
        $this->sync_stats['total_pages_fetched'] += count($result->get_data());
        $this->sync_stats['incremental_queries']++;
        
        // 估算节省的数据传输量
        if ($estimated_size > count($result->get_data())) {
            $data_saved = ($estimated_size - count($result->get_data())) * 5120; // 假设每页面5KB
            $this->sync_stats['data_size_saved'] += $data_saved;
        }
        
        Logger::debug_log(
            sprintf('增量同步完成: %s, 获取 %d 页面, 耗时 %.2f 秒', 
                $database_id, count($result->get_data()), $execution_time),
            'IncrementalSyncService'
        );
    }
}