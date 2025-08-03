<?php
declare(strict_types=1);

namespace NTWP\Infrastructure;

/**
 * 统一缓存管理器 - L1内存 + L2持久化双层架构
 * 
 * 功能整合:
 * ✅ 会话级内存缓存 (from Session_Cache)
 * ✅ 持久化缓存 (from Smart_Cache)
 * ✅ LRU策略 + 智能TTL管理
 */
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
}