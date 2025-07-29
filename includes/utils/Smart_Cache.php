<?php
declare(strict_types=1);

namespace NTWP\Utils;

/**
 * Notion 智能缓存管理器类
 *
 * 实现智能缓存策略，对不变数据使用缓存，对时间敏感数据实时获取
 * 解决API缓存被移除的问题，提供高效的缓存管理
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

class Smart_Cache {
    
    /**
     * 缓存类型配置
     */
    const CACHE_TYPES = [
        'user_info' => [
            'ttl' => 3600,      // 1小时
            'description' => '用户信息缓存'
        ],
        'database_structure' => [
            'ttl' => 1800,      // 30分钟
            'description' => '数据库结构缓存'
        ],
        'page_content' => [
            'ttl' => 300,       // 5分钟
            'description' => '页面内容缓存'
        ],
        'api_response' => [
            'ttl' => 60,        // 1分钟
            'description' => 'API响应缓存'
        ]
    ];
    
    /**
     * 缓存统计
     * @var array
     */
    private static $cache_stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
        'size' => 0
    ];
    
    /**
     * 缓存性能常量
     */
    const L1_CACHE_TTL = 60;                  // L1缓存生存时间（1分钟）
    const L1_CACHE_SIZE_LIMIT = 100;          // L1缓存大小限制
    const L1_CACHE_ITEM_SIZE_THRESHOLD = 10240; // L1缓存项目大小阈值（10KB）
    const DEFAULT_CACHE_TTL = 300;            // 默认缓存时间（5分钟）
    
    /**
     * 生成缓存键
     *
     * @since 2.0.0-beta.1
     * @param string $type 缓存类型
     * @param string $identifier 标识符
     * @param array $params 额外参数
     * @return string 缓存键
     */
    public static function generate_cache_key(string $type, string $identifier, array $params = []): string {
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
     * 设置缓存
     *
     * @since 2.0.0-beta.1
     * @param string $type 缓存类型
     * @param string $identifier 标识符
     * @param mixed $data 缓存数据
     * @param array $params 额外参数
     * @param int $custom_ttl 自定义TTL
     * @return bool 设置是否成功
     */
    public static function set(string $type, string $identifier, $data, array $params = [], int $custom_ttl = null): bool {
        if (!self::is_cache_enabled()) {
            return false;
        }
        
        $cache_key = self::generate_cache_key($type, $identifier, $params);
        $ttl = $custom_ttl ?? self::CACHE_TYPES[$type]['ttl'] ?? self::DEFAULT_CACHE_TTL;
        
        $cache_data = [
            'data' => $data,
            'timestamp' => time(),
            'ttl' => $ttl,
            'type' => $type,
            'identifier' => $identifier
        ];
        
        $result = set_transient($cache_key, $cache_data, $ttl);
        
        if ($result) {
            self::$cache_stats['sets']++;
            self::update_cache_size();
        }
        
        return $result;
    }
    
    /**
     * 获取缓存
     *
     * @since 2.0.0-beta.1
     * @param string $type 缓存类型
     * @param string $identifier 标识符
     * @param array $params 额外参数
     * @return mixed 缓存数据或false
     */
    public static function get(string $type, string $identifier, array $params = []) {
        if (!self::is_cache_enabled()) {
            return false;
        }
        
        $cache_key = self::generate_cache_key($type, $identifier, $params);
        $cache_data = get_transient($cache_key);
        
        if ($cache_data === false) {
            self::$cache_stats['misses']++;
            return false;
        }
        
        // 检查缓存是否过期
        if (isset($cache_data['timestamp'], $cache_data['ttl'])) {
            $age = time() - $cache_data['timestamp'];
            if ($age > $cache_data['ttl']) {
                self::delete($type, $identifier, $params);
                self::$cache_stats['misses']++;
                return false;
            }
        }
        
        self::$cache_stats['hits']++;
        
        return $cache_data['data'] ?? $cache_data;
    }
    
    /**
     * 删除缓存
     *
     * @since 2.0.0-beta.1
     * @param string $type 缓存类型
     * @param string $identifier 标识符
     * @param array $params 额外参数
     * @return bool 删除是否成功
     */
    public static function delete(string $type, string $identifier, array $params = []): bool {
        $cache_key = self::generate_cache_key($type, $identifier, $params);
        $result = delete_transient($cache_key);
        
        if ($result) {
            self::$cache_stats['deletes']++;
            self::update_cache_size();
        }
        
        return $result;
    }
    
    /**
     * 清理指定类型的所有缓存
     *
     * @since 2.0.0-beta.1
     * @param string $type 缓存类型
     * @return int 清理的缓存数量
     */
    public static function clear_type(string $type): int {
        global $wpdb;
        
        $pattern = 'notion_cache_' . $type . '_%';
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s",
                '_transient_' . $pattern
            )
        );
        
        // 同时删除超时记录
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s",
                '_transient_timeout_' . $pattern
            )
        );
        

        
        self::update_cache_size();
        return intval($deleted);
    }
    
    /**
     * 清理所有Notion缓存
     *
     * @since 2.0.0-beta.1
     * @return int 清理的缓存数量
     */
    public static function clear_all(): int {
        global $wpdb;
        
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_notion_cache_%' 
            OR option_name LIKE '_transient_timeout_notion_cache_%'"
        );
        

        
        self::$cache_stats = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'deletes' => 0,
            'size' => 0
        ];
        
        return intval($deleted);
    }
    
    /**
     * 检查缓存是否启用
     *
     * @since 2.0.0-beta.1
     * @return bool 缓存是否启用
     */
    public static function is_cache_enabled(): bool {
        $options = get_option('notion_to_wordpress_options', []);
        return $options['enable_smart_cache'] ?? true;
    }
    
    /**
     * 获取缓存统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 缓存统计
     */
    public static function get_cache_stats(): array {
        $stats = self::$cache_stats;
        
        // 计算命中率
        $total_requests = $stats['hits'] + $stats['misses'];
        $stats['hit_rate'] = $total_requests > 0 ? 
            round(($stats['hits'] / $total_requests) * 100, 2) : 0;
        
        // 获取缓存大小
        $stats['size'] = self::get_cache_size();
        
        return $stats;
    }
    
    /**
     * 获取缓存大小
     *
     * @since 2.0.0-beta.1
     * @return int 缓存条目数量
     */
    private static function get_cache_size(): int {
        global $wpdb;
        
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_notion_cache_%'"
        );
        
        return intval($count);
    }
    
    /**
     * 更新缓存大小统计
     *
     * @since 2.0.0-beta.1
     */
    private static function update_cache_size(): void {
        self::$cache_stats['size'] = self::get_cache_size();
    }
    
    /**
     * 智能缓存策略判断
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
     * L1内存缓存（小数据，高频访问）
     * @var array
     */
    private static $l1_cache = [];

    /**
     * 二级缓存获取
     *
     * @since 2.0.0-beta.1
     * @param string $type 缓存类型
     * @param string $identifier 标识符
     * @param array $params 额外参数
     * @return mixed 缓存数据或false
     */
    public static function get_tiered(string $type, string $identifier, array $params = []) {
        $cache_key = self::generate_cache_key($type, $identifier, $params);

        // L1缓存检查（内存）
        if (isset(self::$l1_cache[$cache_key])) {
            $l1_data = self::$l1_cache[$cache_key];

            // 检查L1缓存是否过期
            if (time() - $l1_data['timestamp'] < self::L1_CACHE_TTL) { // L1缓存有效期
                self::$cache_stats['hits']++;
                return $l1_data['data'];
            } else {
                unset(self::$l1_cache[$cache_key]);
            }
        }

        // L2缓存检查（数据库）
        $l2_data = self::get($type, $identifier, $params);

        if ($l2_data !== false) {
            // 将L2数据提升到L1缓存
            self::set_l1_cache($cache_key, $l2_data);
            return $l2_data;
        }

        return false;
    }

    /**
     * 二级缓存设置
     *
     * @since 2.0.0-beta.1
     * @param string $type 缓存类型
     * @param string $identifier 标识符
     * @param mixed $data 缓存数据
     * @param array $params 额外参数
     * @param int $custom_ttl 自定义TTL
     * @return bool 设置是否成功
     */
    public static function set_tiered(string $type, string $identifier, $data, array $params = [], int $custom_ttl = null): bool {
        $cache_key = self::generate_cache_key($type, $identifier, $params);

        // 设置L1缓存（小数据）
        $data_size = strlen(serialize($data));
        if ($data_size < self::L1_CACHE_ITEM_SIZE_THRESHOLD) { // 小于阈值的数据放入L1缓存
            self::set_l1_cache($cache_key, $data);
        }

        // 设置L2缓存（数据库）
        return self::set($type, $identifier, $data, $params, $custom_ttl);
    }

    /**
     * 设置L1缓存
     *
     * @since 2.0.0-beta.1
     * @param string $cache_key 缓存键
     * @param mixed $data 缓存数据
     */
    private static function set_l1_cache(string $cache_key, $data): void {
        // 检查L1缓存大小限制
        if (count(self::$l1_cache) >= self::L1_CACHE_SIZE_LIMIT) {
            // 移除最旧的缓存项
            $oldest_key = array_key_first(self::$l1_cache);
            unset(self::$l1_cache[$oldest_key]);
        }

        self::$l1_cache[$cache_key] = [
            'data' => $data,
            'timestamp' => time()
        ];
    }

    /**
     * 清理L1缓存
     *
     * @since 2.0.0-beta.1
     */
    public static function clear_l1_cache(): void {
        self::$l1_cache = [];
    }

    /**
     * 获取缓存层级统计
     *
     * @since 2.0.0-beta.1
     * @return array 层级统计
     */
    public static function get_tiered_stats(): array {
        $l1_size = count(self::$l1_cache);
        $l2_size = self::get_cache_size();

        return [
            'l1_cache' => [
                'size' => $l1_size,
                'limit' => self::L1_CACHE_SIZE_LIMIT,
                'usage_percent' => round(($l1_size / self::L1_CACHE_SIZE_LIMIT) * 100, 2)
            ],
            'l2_cache' => [
                'size' => $l2_size,
                'type' => 'database'
            ],
            'total_cache_items' => $l1_size + $l2_size,
            'cache_efficiency' => self::calculate_cache_efficiency()
        ];
    }

    /**
     * 计算缓存效率
     *
     * @since 2.0.0-beta.1
     * @return float 缓存效率分数
     */
    private static function calculate_cache_efficiency(): float {
        $stats = self::get_cache_stats();

        if ($stats['hits'] + $stats['misses'] === 0) {
            return 0.0;
        }

        $hit_rate = $stats['hit_rate'];
        $l1_usage = count(self::$l1_cache) / self::L1_CACHE_SIZE_LIMIT;

        // 综合评分：命中率70% + L1使用率30%
        return round(($hit_rate * 0.7) + ($l1_usage * 30 * 0.3), 2);
    }
}
