<?php
declare(strict_types=1);

namespace NTWP\Infrastructure\Cache;

/**
 * 统一缓存管理器 - L1内存 + L2持久化双层架构
 *
 * 功能整合:
 * ✅ 会话级内存缓存 (from SessionCache)
 * ✅ 持久化缓存 (from Smart_Cache)
 * ✅ LRU策略 + 智能TTL管理
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
class CacheManager {
    
    // L1缓存配置
    private static array $l1_cache = [];
    private static int $l1_max_size = 100;
    private static int $l1_current_size = 0;
    
    // 缓存类型配置
    private const CACHE_TYPES = [
        'user_info' => [
            'ttl' => 3600,           // 1小时
            'l1_eligible' => true,   // 适合L1缓存
            'description' => '用户信息缓存'
        ],
        'database_structure' => [
            'ttl' => 1800,           // 30分钟
            'l1_eligible' => true,
            'description' => '数据库结构缓存'
        ],
        'page_content' => [
            'ttl' => 300,            // 5分钟
            'l1_eligible' => false,  // 内容较大，不适合L1
            'description' => '页面内容缓存'
        ],
        'api_response' => [
            'ttl' => 60,             // 1分钟
            'l1_eligible' => true,
            'description' => 'API响应缓存'
        ],
        'session' => [
            'ttl' => 600,            // 10分钟
            'l1_eligible' => true,
            'description' => '会话级缓存'
        ]
    ];
    
    // 统计数据
    private static array $stats = [
        'l1_hits' => 0,
        'l1_misses' => 0,
        'l2_hits' => 0,
        'l2_misses' => 0,
        'evictions' => 0,
    ];
    
    /**
     * 获取缓存 - 多层策略
     * 
     * @param string $key 缓存键
     * @param string $type 缓存类型
     * @return mixed 缓存数据或null
     */
    public static function get(string $key, string $type = 'session'): mixed {
        // L1缓存检查（内存级）
        $l1_result = self::get_from_l1($key);
        if ($l1_result !== null) {
            self::$stats['l1_hits']++;
            return $l1_result;
        }
        self::$stats['l1_misses']++;
        
        // L2缓存检查（持久化级）
        $l2_result = self::get_from_l2($key, $type);
        if ($l2_result !== null) {
            self::$stats['l2_hits']++;
            
            // 热点数据提升到L1缓存
            if (self::is_l1_eligible($type, $l2_result)) {
                self::set_to_l1($key, $l2_result);
            }
            
            return $l2_result;
        }
        self::$stats['l2_misses']++;
        
        return null;
    }
    
    /**
     * 设置缓存 - 智能分层存储
     * 
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int|null $ttl 生存时间
     * @param string $type 缓存类型
     * @return bool 是否成功
     */
    public static function set(string $key, mixed $value, ?int $ttl = null, string $type = 'session'): bool {
        $cache_config = self::CACHE_TYPES[$type] ?? self::CACHE_TYPES['session'];
        $effective_ttl = $ttl ?? $cache_config['ttl'];
        
        // L2持久化存储
        $l2_success = self::set_to_l2($key, $value, $effective_ttl);
        
        // L1内存存储（根据条件）
        if (self::is_l1_eligible($type, $value)) {
            self::set_to_l1($key, $value, $effective_ttl);
        }
        
        return $l2_success;
    }
    
    /**
     * 批量删除缓存 - 模式匹配
     * 
     * @param string $pattern 匹配模式（支持*通配符）
     * @return int 删除数量
     */
    public static function invalidate_pattern(string $pattern): int {
        $deleted_count = 0;
        
        // L1缓存模式删除
        $deleted_count += self::invalidate_l1_pattern($pattern);
        
        // L2缓存模式删除
        $deleted_count += self::invalidate_l2_pattern($pattern);
        
        return $deleted_count;
    }
    
    /**
     * 获取缓存统计
     * 
     * @return array 统计数据
     */
    public static function get_stats(): array {
        $total_requests = self::$stats['l1_hits'] + self::$stats['l1_misses'];
        $l1_hit_rate = $total_requests > 0 ? (self::$stats['l1_hits'] / $total_requests) * 100 : 0;
        
        $total_l2_requests = self::$stats['l2_hits'] + self::$stats['l2_misses'];
        $l2_hit_rate = $total_l2_requests > 0 ? (self::$stats['l2_hits'] / $total_l2_requests) * 100 : 0;
        
        return array_merge(self::$stats, [
            'l1_hit_rate' => round($l1_hit_rate, 2),
            'l2_hit_rate' => round($l2_hit_rate, 2),
            'l1_cache_size' => self::$l1_current_size,
            'l1_cache_usage' => round((self::$l1_current_size / self::$l1_max_size) * 100, 2),
        ]);
    }
    
    // === L1缓存方法（内存级，LRU策略） ===
    
    private static function get_from_l1(string $key): mixed {
        if (!isset(self::$l1_cache[$key])) {
            return null;
        }
        
        $cache_item = self::$l1_cache[$key];
        
        // 过期检查
        if (time() > $cache_item['expires_at']) {
            self::delete_from_l1($key);
            return null;
        }
        
        // 更新访问时间（LRU）
        self::$l1_cache[$key]['last_access'] = time();
        
        return $cache_item['data'];
    }
    
    private static function set_to_l1(string $key, mixed $value, int $ttl = 600): void {
        // 容量检查，LRU清理
        if (self::$l1_current_size >= self::$l1_max_size) {
            self::evict_l1_lru();
        }
        
        $now = time();
        $is_new = !isset(self::$l1_cache[$key]);
        
        self::$l1_cache[$key] = [
            'data' => $value,
            'created_at' => $now,
            'last_access' => $now,
            'expires_at' => $now + $ttl,
        ];
        
        if ($is_new) {
            self::$l1_current_size++;
        }
    }
    
    private static function evict_l1_lru(): void {
        if (empty(self::$l1_cache)) return;
        
        // 找到最少使用的项
        $oldest_key = null;
        $oldest_access = PHP_INT_MAX;
        
        foreach (self::$l1_cache as $key => $item) {
            if ($item['last_access'] < $oldest_access) {
                $oldest_access = $item['last_access'];
                $oldest_key = $key;
            }
        }
        
        if ($oldest_key) {
            self::delete_from_l1($oldest_key);
            self::$stats['evictions']++;
        }
    }
    
    private static function delete_from_l1(string $key): void {
        if (isset(self::$l1_cache[$key])) {
            unset(self::$l1_cache[$key]);
            self::$l1_current_size--;
        }
    }
    
    private static function invalidate_l1_pattern(string $pattern): int {
        $deleted = 0;
        $regex_pattern = str_replace('*', '.*', preg_quote($pattern, '/'));
        
        foreach (array_keys(self::$l1_cache) as $key) {
            if (preg_match("/^{$regex_pattern}$/", $key)) {
                self::delete_from_l1($key);
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    // === L2缓存方法（持久化级，WordPress transient） ===
    
    private static function get_from_l2(string $key, string $type): mixed {
        $cache_key = self::generate_cache_key($key, $type);
        $cache_data = get_transient($cache_key);
        
        if ($cache_data === false) {
            return null;
        }
        
        // 数据完整性检查
        if (!is_array($cache_data) || !isset($cache_data['data'], $cache_data['timestamp'])) {
            delete_transient($cache_key);
            return null;
        }
        
        return $cache_data['data'];
    }
    
    private static function set_to_l2(string $key, mixed $value, int $ttl): bool {
        $cache_key = self::generate_cache_key($key, 'persistent');
        
        $cache_data = [
            'data' => $value,
            'timestamp' => time(),
            'ttl' => $ttl,
        ];
        
        return set_transient($cache_key, $cache_data, $ttl);
    }
    
    private static function invalidate_l2_pattern(string $pattern): int {
        global $wpdb;
        
        $regex_pattern = str_replace('*', '%', $pattern);
        $cache_prefix = 'ntwp_cache_';
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s",
                '_transient_' . $cache_prefix . $regex_pattern
            )
        );
        
        return intval($deleted / 2); // 每个transient有两个记录
    }
    
    // === 辅助方法 ===
    
    private static function generate_cache_key(string $key, string $type): string {
        return 'ntwp_cache_' . $type . '_' . md5($key);
    }
    
    private static function is_l1_eligible(string $type, mixed $value): bool {
        $cache_config = self::CACHE_TYPES[$type] ?? ['l1_eligible' => true];

        if (!$cache_config['l1_eligible']) {
            return false;
        }

        // 大小检查（>10KB不适合L1）
        $data_size = strlen(serialize($value));
        return $data_size <= 10240;
    }

    // === Smart_Cache兼容方法 ===

    /**
     * Smart_Cache兼容：生成缓存键
     *
     * @param string $type 缓存类型
     * @param string $identifier 标识符
     * @param array $params 额外参数
     * @return string 缓存键
     */
    public static function generate_cache_key_smart(string $type, string $identifier, array $params = []): string {
        $key_parts = [
            'notion_cache',
            $type,
            $identifier
        ];

        if (!empty($params)) {
            $key_parts[] = md5(serialize($params));
        }

        return implode('_', $key_parts);
    }

    /**
     * Smart_Cache兼容：分层获取缓存
     *
     * @param string $type 缓存类型
     * @param string $identifier 标识符
     * @param array $params 额外参数
     * @return mixed 缓存数据或false
     */
    public static function get_tiered(string $type, string $identifier, array $params = []) {
        $cache_key = self::generate_cache_key_smart($type, $identifier, $params);
        $result = self::get($cache_key, $type);

        // Smart_Cache返回false表示未命中，我们需要保持兼容
        return $result !== null ? $result : false;
    }

    /**
     * Smart_Cache兼容：分层设置缓存
     *
     * @param string $type 缓存类型
     * @param string $identifier 标识符
     * @param mixed $data 缓存数据
     * @param array $params 额外参数
     * @param int $custom_ttl 自定义TTL
     * @return bool 设置是否成功
     */
    public static function set_tiered(string $type, string $identifier, $data, array $params = [], int $custom_ttl = null): bool {
        $cache_key = self::generate_cache_key_smart($type, $identifier, $params);
        return self::set($cache_key, $data, $custom_ttl, $type);
    }

    // === SessionCache兼容方法 ===

    /**
     * SessionCache兼容：生成API缓存键
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
     * SessionCache兼容：缓存API响应
     *
     * @param string $endpoint API端点
     * @param array $params 请求参数
     * @param mixed $response API响应
     * @param int $ttl 缓存时间
     */
    public static function cache_api_response(string $endpoint, array $params, $response, int $ttl = 300): void {
        $cache_key = self::generate_api_cache_key($endpoint, $params);
        self::set($cache_key, $response, $ttl, 'api_response');
    }

    /**
     * SessionCache兼容：获取缓存的API响应
     *
     * @param string $endpoint API端点
     * @param array $params 请求参数
     * @return mixed|null 缓存的响应或null
     */
    public static function get_cached_api_response(string $endpoint, array $params = []) {
        $cache_key = self::generate_api_cache_key($endpoint, $params);
        return self::get($cache_key, 'api_response');
    }

    /**
     * Smart_Cache兼容：清理所有缓存
     *
     * @return int 清理的缓存数量
     */
    public static function clear_all(): int {
        global $wpdb;

        // 清理L1缓存
        self::$l1_cache = [];
        self::$l1_current_size = 0;

        $deleted = 0;

        // 清理L2缓存（WordPress transients）
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'query')) {
            $deleted = $wpdb->query(
                "DELETE FROM {$wpdb->options}
                WHERE option_name LIKE '_transient_ntwp_cache_%'
                OR option_name LIKE '_transient_timeout_ntwp_cache_%'"
            );
        } elseif (isset($wpdb) && is_object($wpdb) && is_callable($wpdb->query)) {
            // 处理模拟的wpdb对象
            $deleted = call_user_func($wpdb->query,
                "DELETE FROM {$wpdb->options}
                WHERE option_name LIKE '_transient_ntwp_cache_%'
                OR option_name LIKE '_transient_timeout_ntwp_cache_%'"
            );
        }

        // 重置统计
        self::$stats = [
            'l1_hits' => 0,
            'l1_misses' => 0,
            'l2_hits' => 0,
            'l2_misses' => 0,
            'evictions' => 0,
        ];

        return intval($deleted / 2); // 每个transient有两个记录
    }

    /**
     * Smart_Cache兼容：获取缓存统计
     *
     * @return array 缓存统计
     */
    public static function get_cache_stats(): array {
        return self::get_stats();
    }

    /**
     * Smart_Cache兼容：获取分层缓存统计
     *
     * @return array 分层缓存统计
     */
    public static function get_tiered_stats(): array {
        $stats = self::get_stats();

        return [
            'l1_cache' => [
                'size' => self::$l1_current_size,
                'max_size' => self::$l1_max_size,
                'hit_rate' => $stats['l1_hit_rate'],
                'usage_percent' => $stats['l1_cache_usage']
            ],
            'l2_cache' => [
                'hit_rate' => $stats['l2_hit_rate'],
                'storage' => 'WordPress Transients'
            ],
            'performance' => [
                'total_hits' => $stats['l1_hits'] + $stats['l2_hits'],
                'total_misses' => $stats['l1_misses'] + $stats['l2_misses'],
                'evictions' => $stats['evictions']
            ]
        ];
    }

    // ========================================
    // 补充的删除和类型管理方法 (Smart_Cache兼容)
    // ========================================

    /**
     * Smart_Cache兼容：删除缓存
     *
     * @since 2.0.0-beta.1
     * @param string $type 缓存类型
     * @param string $identifier 标识符
     * @param array $params 额外参数
     * @return bool 删除是否成功
     */
    public static function delete(string $type, string $identifier, array $params = []): bool {
        $cache_key = self::generate_cache_key_smart($type, $identifier, $params);

        // 删除L1缓存
        self::delete_from_l1($cache_key);

        // 删除L2缓存
        $l2_cache_key = self::generate_cache_key($cache_key, $type);
        $result = delete_transient($l2_cache_key);

        // 同时删除超时记录
        delete_transient($l2_cache_key . '_timeout');

        return $result;
    }

    /**
     * Smart_Cache兼容：清理指定类型的所有缓存
     *
     * @since 2.0.0-beta.1
     * @param string $type 缓存类型
     * @return int 清理的缓存数量
     */
    public static function clear_type(string $type): int {
        global $wpdb;

        $deleted_count = 0;

        // 清理L1缓存中的相关项
        $l1_pattern = "notion_cache_{$type}_*";
        $deleted_count += self::invalidate_l1_pattern($l1_pattern);

        // 清理L2缓存（WordPress transients）
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'query')) {
            $l2_pattern = "notion_cache_{$type}_%";
            $l2_deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options}
                    WHERE option_name LIKE %s OR option_name LIKE %s",
                    '_transient_' . $l2_pattern,
                    '_transient_timeout_' . $l2_pattern
                )
            );
            $deleted_count += intval($l2_deleted / 2); // 每个transient有两个记录
        }

        // 同时清理新格式的缓存键
        $ntwp_pattern = "ntwp_cache_{$type}_%";
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'query')) {
            $ntwp_deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options}
                    WHERE option_name LIKE %s OR option_name LIKE %s",
                    '_transient_' . $ntwp_pattern,
                    '_transient_timeout_' . $ntwp_pattern
                )
            );
            $deleted_count += intval($ntwp_deleted / 2);
        }

        return $deleted_count;
    }

    /**
     * Smart_Cache兼容：检查缓存是否启用
     *
     * @since 2.0.0-beta.1
     * @return bool 缓存是否启用
     */
    public static function is_cache_enabled(): bool {
        // 检查WordPress选项
        $options = get_option('notion_to_wordpress_options', []);
        $cache_enabled = $options['enable_smart_cache'] ?? true;

        // 检查是否在调试模式下禁用缓存
        if (defined('WP_DEBUG') && WP_DEBUG && defined('NOTION_DISABLE_CACHE') && NOTION_DISABLE_CACHE) {
            return false;
        }

        // 检查是否有足够的内存用于缓存
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $current_memory = memory_get_usage(true);
        $memory_usage_percent = ($current_memory / $memory_limit) * 100;

        // 如果内存使用超过90%，禁用缓存
        if ($memory_usage_percent > 90) {
            return false;
        }

        return $cache_enabled;
    }

    /**
     * Smart_Cache兼容：智能缓存策略判断
     *
     * @since 2.0.0-beta.1
     * @param string $endpoint API端点
     * @param array $params 请求参数
     * @return array 缓存策略信息
     */
    public static function get_cache_strategy(string $endpoint, array $params = []): array {
        // 用户信息 - 长期缓存
        if (strpos($endpoint, '/users/') !== false) {
            return [
                'type' => 'user_info',
                'cacheable' => true,
                'ttl' => self::CACHE_TYPES['user_info']['ttl']
            ];
        }

        // 数据库结构 - 中期缓存
        if (strpos($endpoint, '/databases/') !== false && !isset($params['filter'])) {
            return [
                'type' => 'database_structure',
                'cacheable' => true,
                'ttl' => self::CACHE_TYPES['database_structure']['ttl']
            ];
        }

        // 页面内容 - 短期缓存
        if (strpos($endpoint, '/pages/') !== false) {
            return [
                'type' => 'page_content',
                'cacheable' => true,
                'ttl' => self::CACHE_TYPES['page_content']['ttl']
            ];
        }

        // 其他API响应 - 极短期缓存
        return [
            'type' => 'api_response',
            'cacheable' => true,
            'ttl' => self::CACHE_TYPES['api_response']['ttl']
        ];
    }

    /**
     * 获取缓存大小 (兼容Smart_Cache)
     *
     * @since 2.0.0-beta.1
     * @return int 缓存大小（字节）
     */
    public static function get_cache_size(): int {
        $total_size = 0;

        // L1缓存大小
        $total_size += strlen(serialize(self::$l1_cache));

        // L2缓存大小（估算）
        $cache_keys = wp_cache_get_multiple(array_keys(self::$stats['cache_hits']));
        foreach ($cache_keys as $value) {
            if ($value !== false) {
                $total_size += strlen(serialize($value));
            }
        }

        return $total_size;
    }

    /**
     * 更新缓存大小统计 (兼容Smart_Cache)
     *
     * @since 2.0.0-beta.1
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     */
    public static function update_cache_size(string $key, $value): void {
        $size = strlen(serialize($value));

        // 更新统计
        if (!isset(self::$stats['cache_sizes'])) {
            self::$stats['cache_sizes'] = [];
        }

        self::$stats['cache_sizes'][$key] = $size;
        self::$stats['total_cache_size'] = array_sum(self::$stats['cache_sizes']);
    }

    /**
     * 设置L1缓存 (兼容Smart_Cache)
     *
     * @since 2.0.0-beta.1
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int $ttl 过期时间
     * @return bool 是否成功
     */
    public static function set_l1_cache(string $key, $value, int $ttl = 300): bool {
        // 检查L1缓存容量
        if (self::$l1_current_size >= self::$l1_max_size) {
            // 清理最旧的缓存项
            $oldest_key = array_key_first(self::$l1_cache);
            if ($oldest_key) {
                unset(self::$l1_cache[$oldest_key]);
                self::$l1_current_size--;
            }
        }

        // 添加到L1缓存
        self::$l1_cache[$key] = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];

        self::$l1_current_size++;
        self::update_cache_size($key, $value);

        return true;
    }

    /**
     * 清理L1缓存 (兼容Smart_Cache)
     *
     * @since 2.0.0-beta.1
     * @param string $pattern 清理模式，支持通配符
     * @return int 清理的项目数
     */
    public static function clear_l1_cache(string $pattern = '*'): int {
        $cleared = 0;

        if ($pattern === '*') {
            // 清理所有L1缓存
            $cleared = count(self::$l1_cache);
            self::$l1_cache = [];
            self::$l1_current_size = 0;
        } else {
            // 按模式清理
            foreach (self::$l1_cache as $key => $data) {
                if (fnmatch($pattern, $key)) {
                    unset(self::$l1_cache[$key]);
                    self::$l1_current_size--;
                    $cleared++;
                }
            }
        }

        return $cleared;
    }

    /**
     * 计算缓存效率 (兼容Smart_Cache)
     *
     * @since 2.0.0-beta.1
     * @return array 缓存效率统计
     */
    public static function calculate_cache_efficiency(): array {
        $total_requests = self::$stats['cache_hits'] + self::$stats['cache_misses'];
        $hit_rate = $total_requests > 0 ? (self::$stats['cache_hits'] / $total_requests) * 100 : 0;

        return [
            'hit_rate' => round($hit_rate, 2),
            'total_hits' => self::$stats['cache_hits'],
            'total_misses' => self::$stats['cache_misses'],
            'total_requests' => $total_requests,
            'l1_cache_size' => self::$l1_current_size,
            'l1_cache_max' => self::$l1_max_size,
            'l1_utilization' => round((self::$l1_current_size / self::$l1_max_size) * 100, 2),
            'total_cache_size_bytes' => self::$stats['total_cache_size'] ?? 0,
            'average_cache_size' => count(self::$stats['cache_sizes'] ?? []) > 0
                ? round(array_sum(self::$stats['cache_sizes']) / count(self::$stats['cache_sizes']), 2)
                : 0
        ];
    }
}