<?php
declare(strict_types=1);

namespace NTWP\Core;

use NTWP\Infrastructure\ConcurrencyManager;
use NTWP\Infrastructure\CacheManager;

/**
 * 重构后的依赖注入容器配置
 * 
 * 注册新的统一服务
 */
class RefactoredDependencyContainer {
    
    private static array $services = [];
    private static bool $initialized = false;
    
    /**
     * 初始化重构后的服务
     */
    public static function initialize(): void {
        if (self::$initialized) {
            return;
        }
        
        // 注册新的统一服务
        self::register('concurrency_manager', function() {
            return new ConcurrencyManager();
        });
        
        self::register('cache_manager', function() {
            return new CacheManager();
        });
        
        // 内存管理专职服务
        self::register('memory_monitor', function() {
            return new \NTWP\Core\MemoryMonitor();
        });
        
        self::register('stream_processor', function() {
            return new \NTWP\Core\StreamProcessor();
        });
        
        self::register('batch_optimizer', function() {
            return new \NTWP\Core\BatchOptimizer();
        });
        
        self::register('garbage_collector', function() {
            return new \NTWP\Core\GarbageCollector();
        });
        
        self::$initialized = true;
        
        // 记录重构完成日志
        if (class_exists('NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::info_log('重构后依赖容器初始化完成', 'DI Container');
        }
    }
    
    /**
     * 注册服务
     */
    public static function register(string $name, callable $factory): void {
        self::$services[$name] = $factory;
    }
    
    /**
     * 获取服务
     */
    public static function get(string $name) {
        if (!isset(self::$services[$name])) {
            throw new \Exception("Service '{$name}' not found");
        }
        
        return self::$services[$name]();
    }
    
    /**
     * 检查重构状态
     */
    public static function is_refactored(): bool {
        return self::$initialized && 
               class_exists('NTWP\\Infrastructure\\ConcurrencyManager') &&
               class_exists('NTWP\\Infrastructure\\CacheManager');
    }
    
    /**
     * 获取重构统计
     */
    public static function get_refactoring_stats(): array {
        return [
            'refactored_services' => count(self::$services),
            'services_available' => array_keys(self::$services),
            'memory_classes_split' => [
                'MemoryMonitor',
                'StreamProcessor', 
                'BatchOptimizer',
                'GarbageCollector'
            ],
            'unified_classes' => [
                'ConcurrencyManager',
                'CacheManager'
            ],
            'compatibility_adapters' => [
                'Memory_Manager_Adapter',
                'Unified_Concurrency_Manager_Adapter'
            ]
        ];
    }
}

// 在插件加载时初始化
add_action('plugins_loaded', [RefactoredDependencyContainer::class, 'initialize'], 5);