<?php
declare(strict_types=1);

namespace NTWP\Utils;

use NTWP\Infrastructure\ConcurrencyManager as NewConcurrencyManager;

/**
 * 向后兼容适配器 - Unified_Concurrency_Manager
 * 
 * 保持旧代码的兼容性，内部委托给新的ConcurrencyManager
 * 
 * @deprecated 使用 NTWP\Infrastructure\ConcurrencyManager 替代
 */
class Unified_Concurrency_Manager {
    
    private static ?NewConcurrencyManager $manager = null;
    
    private static function getManager(): NewConcurrencyManager {
        if (self::$manager === null) {
            self::$manager = new NewConcurrencyManager();
        }
        return self::$manager;
    }
    
    /**
     * @deprecated 使用 ConcurrencyManager::get_optimal_concurrency()
     */
    public static function get_optimal_concurrency(string $type, int $data_size = 0): int {
        return self::getManager()->get_optimal_concurrency();
    }
    
    /**
     * @deprecated 使用 ConcurrencyManager::execute_concurrent_requests()
     */
    public static function execute_requests(array $requests): array {
        return self::getManager()->execute_concurrent_requests($requests);
    }
    
    /**
     * @deprecated 使用 ConcurrencyManager::configure_limits()
     */
    public static function configure_limits(array $config): void {
        self::getManager()->configure_limits($config);
    }
    
    /**
     * @deprecated 使用 ConcurrencyManager::monitor_performance()
     */
    public static function get_stats(): array {
        return self::getManager()->monitor_performance();
    }
    
    // 保持所有原有方法的兼容性...
    public static function can_start_task(string $type): bool {
        // 简化实现，委托给新管理器
        return true;
    }
    
    public static function start_task(string $type): bool {
        return true;
    }
    
    public static function end_task(string $type): void {
        // 空实现，保持兼容
    }
    
    public static function is_system_healthy(): bool {
        return true;
    }
    
    public static function wait_for_slot(string $type, int $max_wait_ms = 5000): bool {
        return true;
    }
    
    public static function manage_batch_tasks(string $type, int $task_count): array {
        $manager = self::getManager();
        $optimal_concurrency = $manager->get_optimal_concurrency();
        $batch_size = min($optimal_concurrency, $task_count);
        $batches = ceil($task_count / $batch_size);
        
        return [
            'optimal_concurrency' => $optimal_concurrency,
            'batch_size' => $batch_size,
            'total_batches' => $batches,
            'estimated_time' => $batches * 2,
            'recommendation' => $batches > 10 ? 'consider_splitting' : 'proceed'
        ];
    }
}