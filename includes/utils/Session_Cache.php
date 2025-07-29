<?php
declare(strict_types=1);

namespace NTWP\Utils;

/**
 * Notion Session Cache - 会话级智能缓存
 *
 * 在单次同步会话中缓存API响应，避免重复请求
 *
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 会话级智能缓存类
 *
 * 提供内存级缓存，仅在当前同步会话中有效
 * 自动管理缓存大小，防止内存溢出
 *
 * @since      2.0.0-performance-1
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/includes/utils
 * @author     Notion to WordPress Team
 */
class Session_Cache {

    /**
     * 缓存存储
     * @var array
     */
    private static $cache = [];

    /**
     * 缓存命中次数
     * @var int
     */
    private static $cache_hits = 0;

    /**
     * 缓存未命中次数
     * @var int
     */
    private static $cache_misses = 0;

    /**
     * 最大缓存条目数（防止内存溢出）
     * @var int
     */
    private static $max_cache_size = 100;

    /**
     * 缓存清理阈值
     * @var int
     */
    private static $cleanup_threshold = 20;

    /**
     * 获取缓存数据
     * 
     * @param string $key 缓存键
     * @return mixed|null 缓存数据或null
     */
    public static function get(string $key) {
        if (isset(self::$cache[$key])) {
            self::$cache_hits++;
            
            // 更新访问时间（LRU策略）
            self::$cache[$key]['last_access'] = time();
            
            return self::$cache[$key]['data'];
        }

        self::$cache_misses++;
        return null;
    }

    /**
     * 设置缓存数据
     * 
     * @param string $key 缓存键
     * @param mixed $data 要缓存的数据
     * @param int $ttl 生存时间（秒），默认600秒
     */
    public static function set(string $key, $data, int $ttl = 600): void {
        // 检查缓存大小，必要时清理
        if (count(self::$cache) >= self::$max_cache_size) {
            self::cleanup_cache();
        }

        self::$cache[$key] = [
            'data' => $data,
            'created_at' => time(),
            'last_access' => time(),
            'ttl' => $ttl
        ];
    }

    /**
     * 检查缓存是否存在且有效
     * 
     * @param string $key 缓存键
     * @return bool
     */
    public static function has(string $key): bool {
        if (!isset(self::$cache[$key])) {
            return false;
        }

        $cache_item = self::$cache[$key];
        $is_expired = (time() - $cache_item['created_at']) > $cache_item['ttl'];

        if ($is_expired) {
            unset(self::$cache[$key]);
            return false;
        }

        return true;
    }

    /**
     * 删除指定缓存
     * 
     * @param string $key 缓存键
     */
    public static function delete(string $key): void {
        unset(self::$cache[$key]);
    }

    /**
     * 清理过期和最少使用的缓存
     */
    private static function cleanup_cache(): void {
        $current_time = time();
        $removed_count = 0;

        // 第一步：移除过期缓存
        foreach (self::$cache as $key => $item) {
            if (($current_time - $item['created_at']) > $item['ttl']) {
                unset(self::$cache[$key]);
                $removed_count++;
            }
        }

        // 第二步：如果还是太多，移除最少使用的缓存
        if (count(self::$cache) > (self::$max_cache_size - self::$cleanup_threshold)) {
            // 按最后访问时间排序
            uasort(self::$cache, function($a, $b) {
                return $a['last_access'] - $b['last_access'];
            });

            // 移除最早的条目
            $keys_to_remove = array_slice(array_keys(self::$cache), 0, self::$cleanup_threshold);
            foreach ($keys_to_remove as $key) {
                unset(self::$cache[$key]);
                $removed_count++;
            }
        }

        if ((class_exists('\\NTWP\\Core\\Logger')) && $removed_count > 0) {
            \NTWP\Core\Logger::debug_log(
                "会话缓存清理完成，移除 {$removed_count} 个条目，当前缓存数: " . count(self::$cache),
                'Session Cache'
            );
        }
    }

    /**
     * 获取缓存统计信息
     * 
     * @return array 缓存统计
     */
    public static function get_stats(): array {
        $total_requests = self::$cache_hits + self::$cache_misses;
        $hit_rate = $total_requests > 0 ? (self::$cache_hits / $total_requests) * 100 : 0;

        return [
            'hits' => self::$cache_hits,
            'misses' => self::$cache_misses,
            'hit_rate' => round($hit_rate, 2),
            'entries' => count(self::$cache),
            'memory_usage' => self::estimate_memory_usage()
        ];
    }

    /**
     * 估算缓存内存使用量
     * 
     * @return string 格式化的内存使用量
     */
    private static function estimate_memory_usage(): string {
        $size = strlen(serialize(self::$cache));
        
        if ($size < 1024) {
            return $size . ' B';
        } elseif ($size < 1048576) {
            return round($size / 1024, 2) . ' KB';
        } else {
            return round($size / 1048576, 2) . ' MB';
        }
    }

    /**
     * 清空所有缓存
     */
    public static function clear_all(): void {
        $count = count(self::$cache);
        self::$cache = [];
        self::$cache_hits = 0;
        self::$cache_misses = 0;

        if ((class_exists('\\NTWP\\Core\\Logger')) && $count > 0) {
            \NTWP\Core\Logger::debug_log(
                "会话缓存已清空，移除 {$count} 个条目",
                'Session Cache'
            );
        }
    }

    /**
     * 生成API请求的缓存键
     * 
     * @param string $endpoint API端点
     * @param array $params 请求参数
     * @return string 缓存键
     */
    public static function generate_api_cache_key(string $endpoint, array $params = []): string {
        $key_data = [
            'endpoint' => $endpoint,
            'params' => $params
        ];
        
        return 'api_' . md5(serialize($key_data));
    }

    /**
     * 缓存API响应
     * 
     * @param string $endpoint API端点
     * @param array $params 请求参数
     * @param mixed $response API响应
     * @param int $ttl 缓存时间
     */
    public static function cache_api_response(string $endpoint, array $params, $response, int $ttl = 300): void {
        $cache_key = self::generate_api_cache_key($endpoint, $params);
        self::set($cache_key, $response, $ttl);
    }

    /**
     * 获取缓存的API响应
     * 
     * @param string $endpoint API端点
     * @param array $params 请求参数
     * @return mixed|null 缓存的响应或null
     */
    public static function get_cached_api_response(string $endpoint, array $params = []) {
        $cache_key = self::generate_api_cache_key($endpoint, $params);
        return self::get($cache_key);
    }
}
