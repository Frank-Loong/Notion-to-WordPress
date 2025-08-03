<?php
declare(strict_types=1);

namespace NTWP\Tests\Infrastructure;

/**
 * ConcurrencyManager 单元测试
 */
class ConcurrencyManagerTest {
    
    private $manager;
    
    public function setUp(): void {
        $this->manager = new \NTWP\Infrastructure\ConcurrencyManager();
    }
    
    /**
     * 测试并发请求执行
     */
    public function test_concurrent_requests_execution(): void {
        $requests = [
            ['url' => 'https://httpbin.org/delay/1'],
            ['url' => 'https://httpbin.org/delay/1'],
            ['url' => 'https://httpbin.org/delay/1'],
        ];
        
        $start_time = microtime(true);
        $results = $this->manager->execute_concurrent_requests($requests);
        $execution_time = microtime(true) - $start_time;
        
        // 断言
        assert(count($results) === 3, '应该返回3个结果');
        assert($execution_time < 2.5, '并发应该比顺序执行快');
        
        foreach ($results as $result) {
            assert($result['success'] === true, '请求应该成功');
            assert($result['http_code'] === 200, 'HTTP状态码应该是200');
        }
        
        echo "✅ 并发请求测试通过 - 执行时间: " . round($execution_time, 2) . "s\n";
    }
    
    /**
     * 测试最优并发数计算
     */
    public function test_optimal_concurrency_calculation(): void {
        $concurrency = $this->manager->get_optimal_concurrency();
        
        assert($concurrency > 0, '并发数应该大于0');
        assert($concurrency <= 10, '并发数不应该超过10');
        
        echo "✅ 最优并发数测试通过 - 当前并发数: {$concurrency}\n";
    }
    
    /**
     * 测试配置管理
     */
    public function test_configuration_management(): void {
        $config = [
            'max_concurrent_requests' => 8,
            'request_timeout' => 45,
            'memory_threshold' => 0.85
        ];
        
        $this->manager->configure_limits($config);
        $performance_data = $this->manager->monitor_performance();
        
        assert(isset($performance_data['current_concurrency']), '应该包含当前并发数');
        
        echo "✅ 配置管理测试通过\n";
    }
    
    public function run_all_tests(): void {
        echo "🧪 开始 ConcurrencyManager 测试...\n";
        
        $this->test_concurrent_requests_execution();
        $this->test_optimal_concurrency_calculation(); 
        $this->test_configuration_management();
        
        echo "🎉 所有 ConcurrencyManager 测试通过!\n\n";
    }
}

/**
 * CacheManager 单元测试
 */
class CacheManagerTest {
    
    /**
     * 测试基本缓存操作
     */
    public function test_basic_cache_operations(): void {
        $key = 'test_key';
        $value = ['data' => 'test_value', 'timestamp' => time()];
        
        // 设置缓存
        $set_result = \NTWP\Infrastructure\CacheManager::set($key, $value, 300, 'session');
        assert($set_result === true, '缓存设置应该成功');
        
        // 获取缓存
        $cached_value = \NTWP\Infrastructure\CacheManager::get($key, 'session');
        assert($cached_value === $value, '缓存值应该匹配');
        
        echo "✅ 基本缓存操作测试通过\n";
    }
    
    /**
     * 测试L1缓存性能
     */
    public function test_l1_cache_performance(): void {
        $iterations = 100;
        
        // L1缓存写入性能
        $start_time = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            \NTWP\Infrastructure\CacheManager::set("key_$i", "value_$i", 300, 'session');
        }
        $write_time = microtime(true) - $start_time;
        
        // L1缓存读取性能
        $start_time = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            \NTWP\Infrastructure\CacheManager::get("key_$i", 'session');
        }
        $read_time = microtime(true) - $start_time;
        
        assert($write_time < 0.1, '写入应在100ms内');
        assert($read_time < 0.05, '读取应在50ms内');
        
        echo "✅ L1缓存性能测试通过 - 写入: " . round($write_time * 1000, 2) . "ms, 读取: " . round($read_time * 1000, 2) . "ms\n";
    }
    
    /**
     * 测试缓存统计
     */
    public function test_cache_statistics(): void {
        // 清理之前的统计
        \NTWP\Infrastructure\CacheManager::invalidate_pattern('*');
        
        // 执行一些操作
        \NTWP\Infrastructure\CacheManager::set('stat_test', 'value', 300, 'session');
        \NTWP\Infrastructure\CacheManager::get('stat_test', 'session');
        \NTWP\Infrastructure\CacheManager::get('non_existent', 'session');
        
        $stats = \NTWP\Infrastructure\CacheManager::get_stats();
        
        assert(isset($stats['l1_hit_rate']), '应该包含命中率统计');
        assert($stats['l1_hit_rate'] >= 0, '命中率应该>=0');
        
        echo "✅ 缓存统计测试通过 - L1命中率: " . $stats['l1_hit_rate'] . "%\n";
    }
    
    public function run_all_tests(): void {
        echo "🧪 开始 CacheManager 测试...\n";
        
        $this->test_basic_cache_operations();
        $this->test_l1_cache_performance();
        $this->test_cache_statistics();
        
        echo "🎉 所有 CacheManager 测试通过!\n\n";
    }
}

/**
 * 内存管理专职类测试
 */
class MemoryManagementTest {
    
    /**
     * 测试内存监控
     */
    public function test_memory_monitoring(): void {
        $usage = \NTWP\Core\MemoryMonitor::get_memory_usage();
        
        assert(isset($usage['current']), '应该包含当前内存使用');
        assert(isset($usage['peak']), '应该包含峰值内存使用');
        assert(isset($usage['usage_percentage']), '应该包含使用百分比');
        assert($usage['current'] > 0, '当前内存使用应该大于0');
        
        echo "✅ 内存监控测试通过 - 当前使用: " . $usage['current_mb'] . "MB (" . $usage['usage_percentage'] . "%)\n";
    }
    
    /**
     * 测试流处理性能
     */
    public function test_stream_processing(): void {
        $test_data = range(1, 1000);
        
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        $results = \NTWP\Core\StreamProcessor::stream_process($test_data, function($chunk) {
            return array_map(function($x) { return $x * 2; }, $chunk);
        }, 50);
        
        $end_time = microtime(true);
        $end_memory = memory_get_usage(true);
        
        assert(count($results) === 1000, '应该处理所有数据');
        assert($results[0] === 2, '第一个结果应该是2');
        assert($results[999] === 2000, '最后一个结果应该是2000');
        
        $execution_time = $end_time - $start_time;
        $memory_used = $end_memory - $start_memory;
        
        echo "✅ 流处理测试通过 - 耗时: " . round($execution_time * 1000, 2) . "ms, 内存: " . round($memory_used / 1024, 2) . "KB\n";
    }
    
    /**
     * 测试批处理优化
     */
    public function test_batch_optimization(): void {
        $batch_size = \NTWP\Core\BatchOptimizer::get_optimal_batch_size('api_requests');
        $concurrent_limit = \NTWP\Core\BatchOptimizer::get_concurrent_limit();
        
        assert($batch_size >= 10, '批处理大小应该至少为10');
        assert($batch_size <= 200, '批处理大小不应该超过200');
        assert($concurrent_limit >= 2, '并发限制应该至少为2');
        assert($concurrent_limit <= 15, '并发限制不应该超过15');
        
        echo "✅ 批处理优化测试通过 - 批大小: {$batch_size}, 并发限制: {$concurrent_limit}\n";
    }
    
    public function run_all_tests(): void {
        echo "🧪 开始内存管理专职类测试...\n";
        
        $this->test_memory_monitoring();
        $this->test_stream_processing();
        $this->test_batch_optimization();
        
        echo "🎉 所有内存管理测试通过!\n\n";
    }
}

// 主测试入口
if (defined('WP_CLI') && WP_CLI) {
    echo "🚀 开始重构代码测试...\n\n";
    
    $concurrency_test = new ConcurrencyManagerTest();
    $concurrency_test->run_all_tests();
    
    $cache_test = new CacheManagerTest();
    $cache_test->run_all_tests();
    
    $memory_test = new MemoryManagementTest();
    $memory_test->run_all_tests();
    
    echo "🎊 所有重构测试完成! 重构代码工作正常。\n";
}