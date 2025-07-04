<?php
/**
 * 负责处理导入过程中的并发控制（锁）
 *
 * 实现基于数据库的分布式锁机制，支持自动过期和强制释放
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notion_To_WordPress_Lock {

    /**
     * 锁的唯一标识符
     *
     * @since 1.1.0
     * @var string
     */
    private string $lock_key;

    /**
     * 锁的过期时间（秒）
     *
     * @since 1.1.0
     * @var int
     */
    private int $lock_expiration;

    /**
     * 锁的持有者标识
     *
     * @since 1.1.0
     * @var string
     */
    private string $lock_holder;

    /**
     * 是否已获取锁
     *
     * @since 1.1.0
     * @var bool
     */
    private bool $is_locked = false;

    /**
     * 最大重试次数
     *
     * @since 1.1.0
     * @var int
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * 重试间隔（毫秒）
     *
     * @since 1.1.0
     * @var int
     */
    private const RETRY_DELAY_MS = 500;

    /**
     * Notion_To_WordPress_Lock constructor.
     *
     * @since 1.1.0
     * @param string $database_id 用于生成唯一锁的数据库ID
     * @param int    $expiration  锁的持续时间（秒），默认5分钟
     */
    public function __construct(string $database_id, int $expiration = 300) {
        $this->lock_key = 'ntw_import_lock_' . md5($database_id);
        $this->lock_expiration = max(60, min(3600, $expiration)); // 限制在1分钟到1小时之间
        $this->lock_holder = $this->generate_lock_holder_id();
    }

    /**
     * 尝试获取锁
     *
     * @since 1.1.0
     * @param int $max_wait_seconds 最大等待时间（秒）
     * @return bool 是否成功获取锁
     */
    public function acquire(int $max_wait_seconds = 30): bool {
        $start_time = time();
        $attempt = 0;

        while ($attempt < self::MAX_RETRY_ATTEMPTS && (time() - $start_time) < $max_wait_seconds) {
            $attempt++;

            // 清理过期锁
            $this->cleanup_expired_locks();

            // 尝试获取锁
            if ($this->try_acquire_lock()) {
                $this->is_locked = true;
                Notion_To_WordPress_Helper::debug_log(
                    "成功获取锁: {$this->lock_key} (尝试 {$attempt})",
                    'Lock Manager',
                    Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
                );
                return true;
            }

            // 如果不是最后一次尝试，等待一段时间
            if ($attempt < self::MAX_RETRY_ATTEMPTS && (time() - $start_time) < $max_wait_seconds) {
                usleep(self::RETRY_DELAY_MS * 1000 * $attempt); // 指数退避
            }
        }

        Notion_To_WordPress_Helper::debug_log(
            "获取锁失败: {$this->lock_key} (尝试 {$attempt} 次)",
            'Lock Manager',
            Notion_To_WordPress_Helper::DEBUG_LEVEL_ERROR
        );
        return false;
    }

    /**
     * 释放锁
     *
     * @since 1.1.0
     * @return bool 是否成功释放锁
     */
    public function release(): bool {
        if (!$this->is_locked) {
            return true;
        }

        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->options,
            [
                'option_name' => $this->lock_key,
                'option_value' => $this->lock_holder
            ],
            ['%s', '%s']
        );

        if ($result !== false) {
            $this->is_locked = false;
            Notion_To_WordPress_Helper::debug_log(
                "成功释放锁: {$this->lock_key}",
                'Lock Manager',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
            );
            return true;
        }

        Notion_To_WordPress_Helper::error_log(
            "释放锁失败: {$this->lock_key}",
            'Lock Manager'
        );
        return false;
    }

    /**
     * 检查锁是否仍然有效
     *
     * @since 1.1.0
     * @return bool 锁是否有效
     */
    public function is_valid(): bool {
        if (!$this->is_locked) {
            return false;
        }

        global $wpdb;

        $lock_data = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $this->lock_key
            )
        );

        if (!$lock_data) {
            $this->is_locked = false;
            return false;
        }

        // 解析锁数据
        $parsed_data = json_decode($lock_data, true);
        if (!$parsed_data || !is_array($parsed_data)) {
            // 兼容旧格式：如果不是JSON，尝试作为简单的holder值处理
            if ($lock_data !== $this->lock_holder) {
                $this->is_locked = false;
                return false;
            }
            // 旧格式无过期时间，认为有效
            return true;
        }

        // 检查是否是当前持有者
        if (($parsed_data['holder'] ?? '') !== $this->lock_holder) {
            $this->is_locked = false;
            return false;
        }

        // 检查是否过期
        $expire_time = (int) ($parsed_data['expire_time'] ?? 0);
        if ($expire_time > 0 && time() > $expire_time) {
            $this->cleanup_expired_locks();
            $this->is_locked = false;
            return false;
        }

        return true;
    }

    /**
     * 强制释放锁（管理员功能）
     *
     * @since 1.1.0
     * @param string $database_id 数据库ID
     * @return bool 是否成功释放
     */
    public static function force_release(string $database_id): bool {
        $lock_key = 'ntw_import_lock_' . md5($database_id);

        global $wpdb;
        $result = $wpdb->delete(
            $wpdb->options,
            ['option_name' => $lock_key],
            ['%s']
        );

        if ($result !== false) {
            Notion_To_WordPress_Helper::debug_log(
                "强制释放锁成功: {$lock_key}",
                'Lock Manager',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
            );
            return true;
        }

        return false;
    }

    /**
     * 获取锁状态信息
     *
     * @since 1.1.0
     * @param string $database_id 数据库ID
     * @return array|null 锁状态信息
     */
    public static function get_lock_status(string $database_id): ?array {
        $lock_key = 'ntw_import_lock_' . md5($database_id);

        global $wpdb;
        $lock_data = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $lock_key
            )
        );

        if (!$lock_data) {
            return null;
        }

        // 解析锁数据
        $parsed_data = json_decode($lock_data, true);
        if (!$parsed_data || !is_array($parsed_data)) {
            // 兼容旧格式
            return [
                'holder' => $lock_data,
                'expire_time' => 0,
                'is_expired' => false,
                'remaining_seconds' => 0,
                'format' => 'legacy'
            ];
        }

        $expire_time = (int) ($parsed_data['expire_time'] ?? 0);
        $is_expired = $expire_time > 0 && time() > $expire_time;

        return [
            'holder' => $parsed_data['holder'] ?? '',
            'expire_time' => $expire_time,
            'is_expired' => $is_expired,
            'remaining_seconds' => $is_expired ? 0 : max(0, $expire_time - time()),
            'created_at' => $parsed_data['created_at'] ?? 0,
            'process_id' => $parsed_data['process_id'] ?? 0,
            'format' => 'json'
        ];
    }

    /**
     * 析构函数 - 自动释放锁
     *
     * @since 1.1.0
     */
    public function __destruct() {
        if ($this->is_locked) {
            $this->release();
        }
    }

    /**
     * 尝试获取锁的核心逻辑
     *
     * @since 1.1.0
     * @return bool 是否成功获取锁
     */
    private function try_acquire_lock(): bool {
        global $wpdb;

        $expire_time = time() + $this->lock_expiration;

        // 改进的锁数据格式：使用JSON存储锁信息，autoload设为no以提高性能
        $lock_data = wp_json_encode([
            'holder' => $this->lock_holder,
            'expire_time' => $expire_time,
            'created_at' => time(),
            'process_id' => getmypid()
        ]);

        // 使用INSERT IGNORE确保原子性，autoload设为no避免影响WordPress启动性能
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $this->lock_key,
                $lock_data
            )
        );

        return $result === 1;
    }

    /**
     * 清理过期的锁
     *
     * @since 1.1.0
     * @return int 清理的锁数量
     */
    private function cleanup_expired_locks(): int {
        global $wpdb;

        // 获取所有锁记录
        $locks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                'ntw_import_lock_%'
            )
        );

        $expired_locks = [];
        $current_time = time();

        foreach ($locks as $lock) {
            $parsed_data = json_decode($lock->option_value, true);

            if ($parsed_data && is_array($parsed_data)) {
                // 新格式：检查JSON中的过期时间
                $expire_time = (int) ($parsed_data['expire_time'] ?? 0);
                if ($expire_time > 0 && $current_time > $expire_time) {
                    $expired_locks[] = $lock->option_name;
                }
            }
            // 旧格式的锁不会被自动清理，需要手动处理
        }

        // 批量删除过期锁
        $deleted_count = 0;
        if (!empty($expired_locks)) {
            $placeholders = implode(',', array_fill(0, count($expired_locks), '%s'));
            $deleted_count = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
                    ...$expired_locks
                )
            );
        }

        if ($deleted_count > 0) {
            Notion_To_WordPress_Helper::debug_log(
                "清理了 {$deleted_count} 个过期锁",
                'Lock Manager',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
            );
        }

        return $deleted_count;
    }

    /**
     * 生成锁持有者唯一标识
     *
     * @since 1.1.0
     * @return string 锁持有者ID
     */
    private function generate_lock_holder_id(): string {
        return sprintf(
            '%s_%d_%s',
            gethostname() ?: 'unknown',
            getmypid(),
            uniqid('', true)
        );
    }
}