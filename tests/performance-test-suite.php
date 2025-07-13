<?php
/**
 * 性能测试套件
 * 
 * 对Notion-to-WordPress插件进行全面的性能测试
 *
 * @package Notion_To_WordPress
 * @subpackage Tests
 * @since 1.1.1
 */

class Notion_Performance_Test_Suite {
    
    /**
     * 测试结果
     *
     * @var array
     */
    private $test_results = [];
    
    /**
     * 性能基准
     *
     * @var array
     */
    private $performance_benchmarks = [
        'query_time_threshold' => 100,  // 毫秒
        'memory_usage_threshold' => 50 * 1024 * 1024,  // 50MB
        'batch_query_improvement' => 50,  // 50%性能提升
        'cache_hit_rate' => 80  // 80%缓存命中率
    ];

    /**
     * 运行所有性能测试
     *
     * @return array 测试结果
     */
    public function run_all_tests(): array {
        $this->log_test_start('性能测试套件开始执行');
        
        // 1. 数据库查询性能测试
        $this->test_database_query_performance();
        
        // 2. 批量操作性能测试
        $this->test_batch_operations_performance();
        
        // 3. 内存使用测试
        $this->test_memory_usage();
        
        // 4. 缓存性能测试
        $this->test_cache_performance();
        
        // 5. API响应时间测试
        $this->test_api_response_time();
        
        // 6. 并发处理测试
        $this->test_concurrent_processing();
        
        // 7. 大数据量处理测试
        $this->test_large_data_processing();
        
        $this->log_test_end('性能测试套件执行完成');
        
        return [
            'results' => $this->test_results,
            'summary' => $this->generate_performance_summary()
        ];
    }

    /**
     * 测试数据库查询性能
     */
    private function test_database_query_performance(): void {
        $this->log_test_section('数据库查询性能测试');
        
        // 测试1: 单个查询性能
        $test_name = '数据库查询 - 单个查询性能';
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        try {
            // 执行典型的查询操作
            $args = [
                'post_type' => 'post',
                'posts_per_page' => 10,
                'meta_query' => [
                    [
                        'key' => '_notion_page_id',
                        'compare' => 'EXISTS'
                    ]
                ]
            ];
            
            $posts = get_posts($args);
            
            $end_time = microtime(true);
            $end_memory = memory_get_usage(true);
            
            $duration = ($end_time - $start_time) * 1000;  // 转换为毫秒
            $memory_used = $end_memory - $start_memory;
            
            if ($duration < $this->performance_benchmarks['query_time_threshold']) {
                $this->record_performance_result($test_name, 'PASS', 
                    "查询耗时: {$duration}ms, 内存使用: " . size_format($memory_used));
            } else {
                $this->record_performance_result($test_name, 'FAIL', 
                    "查询耗时过长: {$duration}ms (阈值: {$this->performance_benchmarks['query_time_threshold']}ms)");
            }
        } catch (Exception $e) {
            $this->record_performance_result($test_name, 'FAIL', '查询性能测试异常: ' . $e->getMessage());
        }
        
        // 测试2: 批量查询vs单个查询性能对比
        $test_name = '数据库查询 - 批量查询性能优势';
        try {
            $notion_ids = ['test_id_1', 'test_id_2', 'test_id_3', 'test_id_4', 'test_id_5'];
            
            // 测试单个查询方式
            $start_time = microtime(true);
            $single_results = [];
            foreach ($notion_ids as $notion_id) {
                $args = [
                    'post_type' => 'post',
                    'posts_per_page' => 1,
                    'meta_query' => [
                        [
                            'key' => '_notion_page_id',
                            'value' => $notion_id,
                            'compare' => '='
                        ]
                    ],
                    'fields' => 'ids'
                ];
                $single_results[] = get_posts($args);
            }
            $single_query_time = (microtime(true) - $start_time) * 1000;
            
            // 测试批量查询方式
            $start_time = microtime(true);
            $batch_results = Notion_To_WordPress_Helper::get_posts_by_notion_ids_batch($notion_ids);
            $batch_query_time = (microtime(true) - $start_time) * 1000;
            
            $improvement = (($single_query_time - $batch_query_time) / $single_query_time) * 100;
            
            if ($improvement >= $this->performance_benchmarks['batch_query_improvement']) {
                $this->record_performance_result($test_name, 'PASS', 
                    "批量查询性能提升: {$improvement}% (单个: {$single_query_time}ms, 批量: {$batch_query_time}ms)");
            } else {
                $this->record_performance_result($test_name, 'WARNING', 
                    "批量查询性能提升不足: {$improvement}% (期望: {$this->performance_benchmarks['batch_query_improvement']}%)");
            }
        } catch (Exception $e) {
            $this->record_performance_result($test_name, 'FAIL', '批量查询性能测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试批量操作性能
     */
    private function test_batch_operations_performance(): void {
        $this->log_test_section('批量操作性能测试');
        
        // 测试1: 批量元数据查询
        $test_name = '批量操作 - 元数据查询';
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        try {
            // 创建测试数据
            $test_post_ids = [];
            for ($i = 0; $i < 10; $i++) {
                $post_id = wp_insert_post([
                    'post_title' => "Test Post {$i}",
                    'post_content' => 'Test content',
                    'post_status' => 'publish'
                ]);
                $test_post_ids[] = $post_id;
                update_post_meta($post_id, '_notion_page_id', "test_notion_id_{$i}");
            }
            
            // 执行批量查询
            $meta_results = Notion_To_WordPress_Helper::get_posts_meta_batch($test_post_ids, '_notion_page_id');
            
            $end_time = microtime(true);
            $end_memory = memory_get_usage(true);
            
            $duration = ($end_time - $start_time) * 1000;
            $memory_used = $end_memory - $start_memory;
            
            // 清理测试数据
            foreach ($test_post_ids as $post_id) {
                wp_delete_post($post_id, true);
            }
            
            if (count($meta_results) === count($test_post_ids)) {
                $this->record_performance_result($test_name, 'PASS', 
                    "批量元数据查询成功: {$duration}ms, 内存: " . size_format($memory_used));
            } else {
                $this->record_performance_result($test_name, 'FAIL', 
                    "批量元数据查询结果不完整: 期望 " . count($test_post_ids) . " 个，实际 " . count($meta_results) . " 个");
            }
        } catch (Exception $e) {
            $this->record_performance_result($test_name, 'FAIL', '批量元数据查询测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试内存使用
     */
    private function test_memory_usage(): void {
        $this->log_test_section('内存使用测试');
        
        // 测试1: 大量数据处理内存使用
        $test_name = '内存使用 - 大量数据处理';
        $start_memory = memory_get_usage(true);
        
        try {
            // 模拟处理大量页面数据
            $large_pages_data = [];
            for ($i = 0; $i < 100; $i++) {
                $large_pages_data[] = [
                    'id' => "test_page_{$i}",
                    'properties' => [
                        'title' => ['title' => [['text' => ['content' => "Test Page {$i}"]]]],
                        'content' => str_repeat('Test content ', 100)  // 模拟大内容
                    ]
                ];
            }
            
            // 模拟页面处理
            $pages_processor = new Notion_Pages();
            $reflection = new ReflectionClass($pages_processor);
            $method = $reflection->getMethod('extract_page_properties');
            $method->setAccessible(true);
            
            foreach ($large_pages_data as $page_data) {
                $method->invoke($pages_processor, $page_data);
            }
            
            $end_memory = memory_get_usage(true);
            $memory_used = $end_memory - $start_memory;
            
            if ($memory_used < $this->performance_benchmarks['memory_usage_threshold']) {
                $this->record_performance_result($test_name, 'PASS', 
                    "内存使用合理: " . size_format($memory_used));
            } else {
                $this->record_performance_result($test_name, 'FAIL', 
                    "内存使用过高: " . size_format($memory_used) . 
                    " (阈值: " . size_format($this->performance_benchmarks['memory_usage_threshold']) . ")");
            }
        } catch (Exception $e) {
            $this->record_performance_result($test_name, 'FAIL', '内存使用测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试缓存性能
     */
    private function test_cache_performance(): void {
        $this->log_test_section('缓存性能测试');
        
        // 测试1: 缓存命中率
        $test_name = '缓存性能 - 命中率测试';
        try {
            $api = new Notion_API('test_key');
            
            // 清空缓存
            $reflection = new ReflectionClass($api);
            $cache_property = $reflection->getProperty('cache');
            $cache_property->setAccessible(true);
            $cache_property->setValue($api, []);
            
            // 模拟缓存操作
            $test_key = 'test_cache_key';
            $test_data = ['test' => 'data'];
            
            // 第一次访问（缓存未命中）
            $cache_method = $reflection->getMethod('get_from_cache');
            $cache_method->setAccessible(true);
            $result1 = $cache_method->invoke($api, $test_key);
            
            // 设置缓存
            $set_cache_method = $reflection->getMethod('set_cache');
            $set_cache_method->setAccessible(true);
            $set_cache_method->invoke($api, $test_key, $test_data);
            
            // 第二次访问（缓存命中）
            $result2 = $cache_method->invoke($api, $test_key);
            
            if ($result1 === null && $result2 === $test_data) {
                $this->record_performance_result($test_name, 'PASS', '缓存机制正常工作');
            } else {
                $this->record_performance_result($test_name, 'FAIL', '缓存机制异常');
            }
        } catch (Exception $e) {
            $this->record_performance_result($test_name, 'FAIL', '缓存性能测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 记录性能测试结果
     */
    private function record_performance_result(string $test_name, string $status, string $message): void {
        $this->test_results[] = [
            'test' => $test_name,
            'status' => $status,
            'message' => $message,
            'timestamp' => current_time('mysql')
        ];
    }

    /**
     * 记录测试日志
     */
    private function log_test_start(string $message): void {
        error_log("=== {$message} ===");
    }
    
    private function log_test_end(string $message): void {
        error_log("=== {$message} ===");
    }
    
    private function log_test_section(string $section): void {
        error_log("--- {$section} ---");
    }

    /**
     * 生成性能测试摘要
     */
    private function generate_performance_summary(): string {
        $total = count($this->test_results);
        $passed = count(array_filter($this->test_results, function($result) {
            return $result['status'] === 'PASS';
        }));
        $failed = count(array_filter($this->test_results, function($result) {
            return $result['status'] === 'FAIL';
        }));
        $warnings = count(array_filter($this->test_results, function($result) {
            return $result['status'] === 'WARNING';
        }));
        
        $pass_rate = $total > 0 ? round(($passed / $total) * 100, 2) : 0;
        
        return "性能测试完成: 总计 {$total} 项，通过 {$passed} 项，失败 {$failed} 项，警告 {$warnings} 项。通过率: {$pass_rate}%";
    }
}
