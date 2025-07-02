<?php
/**
 * 负责处理导入过程中的并发控制（锁）
 *
 * @since      1.0.8
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */
declare(strict_types=1);

class Notion_To_WordPress_Lock {

    /**
     * 锁的transient键
     *
     * @since 1.0.5
     * @var string
     */
    private string $lock_key;

    /**
     * 锁的过期时间（秒）
     *
     * @since 1.0.5
     * @var int
     */
    private int $lock_expiration;

    /**
     * Notion_To_WordPress_Lock constructor.
     *
     * @param string $database_id 用于生成唯一锁的数据库ID
     * @param int    $expiration  锁的持续时间（秒）
     */
    public function __construct(string $database_id, int $expiration = 300) {
        $this->lock_key = 'ntw_import_lock_' . md5($database_id);
        $this->lock_expiration = $expiration;
    }

    /**
     * 尝试获取锁
     *
     * @return bool 如果成功获取锁则返回true，否则返回false
     */
    public function acquire(): bool {
        $existing = get_transient( $this->lock_key );

        if ( $existing ) {
            // 计算锁存在时长，若超过阈值则视为僵尸锁并回收
            if ( time() - (int) $existing > 2 * $this->lock_expiration ) {
                delete_transient( $this->lock_key ); // 清理脏锁
            } else {
                // 正常锁仍有效
                return false;
            }
        }

        // 设置新锁（写入当前时间戳便于后续判断）
        set_transient( $this->lock_key, time(), $this->lock_expiration );
        return true;
    }

    /**
     * 释放锁
     */
    public function release(): void {
        delete_transient($this->lock_key);
    }
} 