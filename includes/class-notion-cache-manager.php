<?php
declare(strict_types=1);

/**
 * Notion 缓存管理器类
 * 
 * 统一管理所有缓存功能，包括会话级缓存、API缓存和批量查询缓存。
 * 提供统一的缓存接口和性能统计功能，支持缓存清理和过期管理。
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

class Notion_Cache_Manager {

    /**
     * 会话级数据库查询缓存
     * 
     * @since 2.0.0-beta.1
     * @var array
     */
    private static array $db_query_cache = [];

    /**
     * 会话级批量查询缓存
     * 
     * @since 2.0.0-beta.1
     * @var array
     */
    private static array $batch_query_cache = [];

    // 注意：API缓存已移除以支持增量同步的实时性
    // 增量同步依赖准确的last_edited_time进行时间戳比较
    // API缓存会返回过时的时间戳，破坏增量同步的核心逻辑
    // 保留会话级缓存用于WordPress数据库查询优化

    /**
     * 初始化会话级缓存
     *
     * @since 2.0.0-beta.1
     */
    public static function init_session_cache(): void {
        // 清理之前的缓存
        self::clear_session_cache();

        Notion_To_WordPress_Helper::debug_log(
            '初始化会话级缓存',
            'Session Cache'
        );
    }

    /**
     * 清理会话级缓存
     *
     * @since 2.0.0-beta.1
     */
    public static function clear_session_cache(): void {
        self::$db_query_cache = [];
        self::$batch_query_cache = [];

        Notion_To_WordPress_Helper::debug_log(
            '清理会话级缓存完成（API缓存已移除）',
            'Session Cache'
        );
    }

    /**
     * 清理API缓存 - 向后兼容方法（API缓存已移除）
     *
     * @since 2.0.0-beta.1
     * @param string|null $page_id 特定页面ID，为null时清除所有缓存
     */
    public static function clear_api_cache(?string $page_id = null): void {
        Notion_To_WordPress_Helper::debug_log(
            'API缓存清理请求（API缓存已移除以支持增量同步）',
            'Cache Management'
        );
    }

    /**
     * 获取会话级缓存统计
     *
     * @since 2.0.0-beta.1
     * @return array
     */
    public static function get_session_cache_stats(): array {
        return [
            'db_query_cache_count' => count(self::$db_query_cache),
            'batch_query_cache_count' => count(self::$batch_query_cache),
            'total_session_cache_items' => count(self::$db_query_cache) + count(self::$batch_query_cache)
        ];
    }

    /**
     * 获取API缓存统计 - 向后兼容方法（API缓存已移除）
     *
     * @since 2.0.0-beta.1
     * @return array
     */
    public static function get_api_cache_stats(): array {
        return [
            'page_cache_count' => 0,
            'database_pages_cache_count' => 0,
            'database_info_cache_count' => 0,
            'total_cache_items' => 0,
            'cache_ttl' => 0,
            'note' => 'API缓存已移除以支持增量同步'
        ];
    }

    /**
     * 获取完整的缓存统计
     *
     * @since 2.0.0-beta.1
     * @return array
     */
    public static function get_cache_stats(): array {
        $session_stats = self::get_session_cache_stats();
        $api_stats = self::get_api_cache_stats();

        return [
            'session_cache' => $session_stats,
            'api_cache' => $api_stats,
            'total_cache_items' => $session_stats['total_session_cache_items'],
            'note' => 'API缓存已移除，仅保留会话级缓存'
        ];
    }

    // ==================== 数据库查询缓存方法 ====================

    /**
     * 获取数据库查询缓存值
     *
     * @since 2.0.0-beta.1
     * @param string $key 缓存键
     * @return mixed 缓存值，不存在返回null
     */
    public static function get_db_cache_value(string $key) {
        return self::$db_query_cache[$key] ?? null;
    }

    /**
     * 设置数据库查询缓存值
     *
     * @since 2.0.0-beta.1
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     */
    public static function set_db_cache_value(string $key, $value): void {
        self::$db_query_cache[$key] = $value;
    }

    /**
     * 检查数据库查询缓存是否存在
     *
     * @since 2.0.0-beta.1
     * @param string $key 缓存键
     * @return bool
     */
    public static function has_db_cache(string $key): bool {
        return isset(self::$db_query_cache[$key]);
    }

    // ==================== 会话级缓存方法（通用接口） ====================

    /**
     * 获取会话级缓存值
     *
     * 提供通用的会话级缓存访问接口，内部使用数据库查询缓存实现。
     * 这个方法为测试和外部调用提供了更语义化的接口名称。
     *
     * @since 2.0.0-beta.1
     * @param string $key 缓存键
     * @return mixed 缓存值，不存在返回null
     */
    public static function get_session_cache_value(string $key) {
        $value = self::$db_query_cache[$key] ?? null;

        Notion_To_WordPress_Helper::debug_log(
            "获取会话级缓存: {$key} = " . (is_null($value) ? 'null' : 'found'),
            'Session Cache'
        );

        return $value;
    }

    /**
     * 设置会话级缓存值
     *
     * 提供通用的会话级缓存设置接口，内部使用数据库查询缓存实现。
     * 这个方法为测试和外部调用提供了更语义化的接口名称。
     *
     * @since 2.0.0-beta.1
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @return void
     */
    public static function set_session_cache_value(string $key, $value): void {
        self::$db_query_cache[$key] = $value;

        Notion_To_WordPress_Helper::debug_log(
            "设置会话级缓存: {$key}",
            'Session Cache'
        );
    }

    // ==================== 批量查询缓存方法 ====================

    /**
     * 获取批量查询缓存值
     *
     * @since 2.0.0-beta.1
     * @param string $key 缓存键
     * @return mixed 缓存值，不存在返回null
     */
    public static function get_batch_cache_value(string $key) {
        return self::$batch_query_cache[$key] ?? null;
    }

    /**
     * 设置批量查询缓存值
     *
     * @since 2.0.0-beta.1
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     */
    public static function set_batch_cache_value(string $key, $value): void {
        self::$batch_query_cache[$key] = $value;
    }

    /**
     * 检查批量查询缓存是否存在
     *
     * @since 2.0.0-beta.1
     * @param string $key 缓存键
     * @return bool
     */
    public static function has_batch_cache(string $key): bool {
        return isset(self::$batch_query_cache[$key]);
    }

    // ==================== API缓存方法（已移除以支持增量同步） ====================

    // 注意：所有API缓存方法已移除，因为它们与增量同步存在根本性冲突
    // 增量同步依赖准确的last_edited_time进行时间戳比较
    // API缓存会返回过时的时间戳，破坏增量同步的核心逻辑
    //
    // 移除的方法包括：
    // - get_api_page_cache / set_api_page_cache / has_api_page_cache
    // - get_api_database_pages_cache / set_api_database_pages_cache / has_api_database_pages_cache
    // - get_api_database_info_cache / set_api_database_info_cache / has_api_database_info_cache

    // ==================== 缓存过期管理 ====================

    /**
     * 检查缓存是否有效 - 向后兼容方法（API缓存已移除）
     *
     * @since 2.0.0-beta.1
     * @param string $cache_key 缓存键
     * @return bool
     */
    public static function is_cache_valid(string $cache_key): bool {
        // API缓存已移除，始终返回false
        return false;
    }

    /**
     * 清理过期缓存 - 向后兼容方法（API缓存已移除）
     *
     * @since 2.0.0-beta.1
     */
    public static function cleanup_expired_cache(): void {
        // API缓存已移除，无需清理
        Notion_To_WordPress_Helper::debug_log(
            '缓存清理请求（API缓存已移除，无需清理）',
            'Cache Cleanup'
        );
    }

    /**
     * 设置缓存过期时间 - 向后兼容方法（API缓存已移除）
     *
     * @since 2.0.0-beta.1
     * @param int $ttl 过期时间（秒）
     */
    public static function set_cache_ttl(int $ttl): void {
        // API缓存已移除，无需设置TTL
    }

    /**
     * 获取缓存过期时间 - 向后兼容方法（API缓存已移除）
     *
     * @since 2.0.0-beta.1
     * @return int 过期时间（秒）
     */
    public static function get_cache_ttl(): int {
        return 0; // API缓存已移除
    }

    // ==================== 向后兼容方法 ====================

    /**
     * 向后兼容：清理页面缓存
     *
     * 为了保持与 Notion_API::clear_page_cache() 的兼容性
     * API缓存已移除以支持增量同步的实时性
     *
     * @since 2.0.0-beta.1
     * @param string|null $page_id 特定页面ID，为null时清除所有缓存
     */
    public static function clear_page_cache(?string $page_id = null): void {
        Notion_To_WordPress_Helper::debug_log(
            '页面缓存清理请求（API缓存已移除，无需清理）',
            'Cache Management'
        );
    }
}
