<?php
declare(strict_types=1);

namespace NTWP\Core;

/**
 * 垃圾收集器 - 负责内存垃圾回收
 * 
 * 从Memory_Manager拆分出的专职类
 */
class GarbageCollector {
    
    /**
     * 强制垃圾回收
     */
    public static function force_garbage_collection(): void {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // 额外的内存清理
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }
    
    /**
     * 执行循环回收
     */
    public static function collect_cycles(): void {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
    
    /**
     * 启用垃圾收集
     */
    public static function enable(): void {
        if (function_exists('gc_enable')) {
            gc_enable();
        }
    }
    
    /**
     * 禁用垃圾收集
     */
    public static function disable(): void {
        if (function_exists('gc_disable')) {
            gc_disable();
        }
    }
    
    /**
     * 获取垃圾收集状态
     */
    public static function get_status(): array {
        return [
            'enabled' => function_exists('gc_enabled') ? gc_enabled() : false,
            'collected' => function_exists('gc_collected') ? gc_collected() : 0,
            'threshold' => function_exists('gc_threshold') ? gc_threshold() : null,
        ];
    }
}