<?php
/**
 * Admin测试执行器
 * 
 * 在WordPress管理界面中执行测试的简化版本
 *
 * @package Notion_To_WordPress
 * @subpackage Tests
 * @since 1.1.1
 */

class Notion_Admin_Test_Executor {
    
    /**
     * 执行快速安全检查
     *
     * @return array 检查结果
     */
    public static function run_quick_security_check(): array {
        $results = [];
        
        // 1. 检查CSRF保护
        try {
            $admin = new Notion_To_WordPress_Admin('test', '1.1.1');
            $reflection = new ReflectionClass($admin);
            $method = $reflection->getMethod('validate_ajax_security');
            $method->setAccessible(true);
            
            // 模拟无效请求
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            
            ob_start();
            $result = $method->invoke($admin);
            ob_get_clean();
            
            $results['csrf_protection'] = [
                'status' => $result === false ? 'PASS' : 'FAIL',
                'message' => $result === false ? 'CSRF保护正常' : 'CSRF保护异常'
            ];
        } catch (Exception $e) {
            $results['csrf_protection'] = [
                'status' => 'FAIL',
                'message' => 'CSRF检查异常: ' . $e->getMessage()
            ];
        }
        
        // 2. 检查配置安全
        try {
            $config_result = Notion_To_WordPress_Helper::validate_config();
            $results['config_security'] = [
                'status' => $config_result['valid'] ? 'PASS' : 'WARNING',
                'message' => $config_result['valid'] ? '配置验证通过' : '配置存在问题: ' . implode(', ', $config_result['errors'])
            ];
        } catch (Exception $e) {
            $results['config_security'] = [
                'status' => 'FAIL',
                'message' => '配置检查异常: ' . $e->getMessage()
            ];
        }
        
        // 3. 检查文件安全
        try {
            $allowed_types = Notion_To_WordPress_Helper::get_config('files.allowed_image_types', '');
            $dangerous_types = ['application/x-php', 'text/x-php', 'application/x-httpd-php'];
            $has_dangerous = false;
            
            foreach ($dangerous_types as $type) {
                if (strpos($allowed_types, $type) !== false) {
                    $has_dangerous = true;
                    break;
                }
            }
            
            $results['file_security'] = [
                'status' => $has_dangerous ? 'FAIL' : 'PASS',
                'message' => $has_dangerous ? '允许了危险的文件类型' : '文件类型限制安全'
            ];
        } catch (Exception $e) {
            $results['file_security'] = [
                'status' => 'FAIL',
                'message' => '文件安全检查异常: ' . $e->getMessage()
            ];
        }
        
        return $results;
    }
    
    /**
     * 执行快速性能检查
     *
     * @return array 检查结果
     */
    public static function run_quick_performance_check(): array {
        $results = [];
        
        // 1. 检查查询性能统计
        try {
            $query_stats = Notion_To_WordPress_Helper::get_query_stats();
            $slow_query_rate = $query_stats['total_queries'] > 0 ? 
                ($query_stats['slow_queries'] / $query_stats['total_queries']) * 100 : 0;
            
            $results['query_performance'] = [
                'status' => $slow_query_rate < 10 ? 'PASS' : ($slow_query_rate < 25 ? 'WARNING' : 'FAIL'),
                'message' => "慢查询率: {$slow_query_rate}%, 平均耗时: {$query_stats['avg_time_ms']}ms"
            ];
        } catch (Exception $e) {
            $results['query_performance'] = [
                'status' => 'FAIL',
                'message' => '查询性能检查异常: ' . $e->getMessage()
            ];
        }
        
        // 2. 检查内存使用
        try {
            $memory_usage = memory_get_usage(true);
            $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
            $memory_usage_percent = ($memory_usage / $memory_limit) * 100;
            
            $results['memory_usage'] = [
                'status' => $memory_usage_percent < 70 ? 'PASS' : ($memory_usage_percent < 85 ? 'WARNING' : 'FAIL'),
                'message' => "内存使用: " . size_format($memory_usage) . " ({$memory_usage_percent}%)"
            ];
        } catch (Exception $e) {
            $results['memory_usage'] = [
                'status' => 'FAIL',
                'message' => '内存使用检查异常: ' . $e->getMessage()
            ];
        }
        
        // 3. 检查缓存配置
        try {
            $cache_config = [
                'max_items' => Notion_To_WordPress_Helper::get_config('cache.max_items', 1000),
                'memory_limit' => Notion_To_WordPress_Helper::get_config('cache.memory_limit_mb', 50),
                'ttl' => Notion_To_WordPress_Helper::get_config('cache.ttl', 300)
            ];
            
            $config_optimal = $cache_config['max_items'] >= 100 && 
                             $cache_config['max_items'] <= 5000 &&
                             $cache_config['memory_limit'] >= 10 &&
                             $cache_config['memory_limit'] <= 200 &&
                             $cache_config['ttl'] >= 60 &&
                             $cache_config['ttl'] <= 3600;
            
            $results['cache_config'] = [
                'status' => $config_optimal ? 'PASS' : 'WARNING',
                'message' => $config_optimal ? '缓存配置合理' : '缓存配置可能需要优化'
            ];
        } catch (Exception $e) {
            $results['cache_config'] = [
                'status' => 'FAIL',
                'message' => '缓存配置检查异常: ' . $e->getMessage()
            ];
        }
        
        return $results;
    }
    
    /**
     * 执行快速功能检查
     *
     * @return array 检查结果
     */
    public static function run_quick_functional_check(): array {
        $results = [];
        
        // 1. 检查核心类加载
        try {
            $core_classes = [
                'Notion_To_WordPress' => class_exists('Notion_To_WordPress'),
                'Notion_API' => class_exists('Notion_API'),
                'Notion_Pages' => class_exists('Notion_Pages'),
                'Notion_To_WordPress_Helper' => class_exists('Notion_To_WordPress_Helper'),
                'Notion_To_WordPress_Admin' => class_exists('Notion_To_WordPress_Admin')
            ];
            
            $missing_classes = array_filter($core_classes, function($exists) { return !$exists; });
            
            $results['core_classes'] = [
                'status' => empty($missing_classes) ? 'PASS' : 'FAIL',
                'message' => empty($missing_classes) ? '所有核心类正常加载' : '缺少核心类: ' . implode(', ', array_keys($missing_classes))
            ];
        } catch (Exception $e) {
            $results['core_classes'] = [
                'status' => 'FAIL',
                'message' => '核心类检查异常: ' . $e->getMessage()
            ];
        }
        
        // 2. 检查数据库表
        try {
            global $wpdb;
            $required_tables = [
                $wpdb->posts,
                $wpdb->postmeta,
                $wpdb->options
            ];
            
            $missing_tables = [];
            foreach ($required_tables as $table) {
                $result = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
                if ($result !== $table) {
                    $missing_tables[] = $table;
                }
            }
            
            $results['database_tables'] = [
                'status' => empty($missing_tables) ? 'PASS' : 'FAIL',
                'message' => empty($missing_tables) ? '数据库表完整' : '缺少数据库表: ' . implode(', ', $missing_tables)
            ];
        } catch (Exception $e) {
            $results['database_tables'] = [
                'status' => 'FAIL',
                'message' => '数据库表检查异常: ' . $e->getMessage()
            ];
        }
        
        // 3. 检查WordPress钩子
        try {
            $required_hooks = [
                'wp_ajax_notion_to_wordpress_sync' => has_action('wp_ajax_notion_to_wordpress_sync'),
                'wp_ajax_notion_to_wordpress_test_connection' => has_action('wp_ajax_notion_to_wordpress_test_connection'),
                'wp_ajax_notion_config_management' => has_action('wp_ajax_notion_config_management')
            ];
            
            $missing_hooks = array_filter($required_hooks, function($exists) { return !$exists; });
            
            $results['wordpress_hooks'] = [
                'status' => empty($missing_hooks) ? 'PASS' : 'WARNING',
                'message' => empty($missing_hooks) ? 'WordPress钩子正常注册' : '部分钩子未注册: ' . implode(', ', array_keys($missing_hooks))
            ];
        } catch (Exception $e) {
            $results['wordpress_hooks'] = [
                'status' => 'FAIL',
                'message' => 'WordPress钩子检查异常: ' . $e->getMessage()
            ];
        }
        
        return $results;
    }
    
    /**
     * 执行完整的快速检查
     *
     * @return array 完整检查结果
     */
    public static function run_complete_quick_check(): array {
        $start_time = microtime(true);
        
        $results = [
            'security' => self::run_quick_security_check(),
            'performance' => self::run_quick_performance_check(),
            'functional' => self::run_quick_functional_check()
        ];
        
        $end_time = microtime(true);
        $execution_time = round($end_time - $start_time, 2);
        
        // 计算总体统计
        $total_checks = 0;
        $passed_checks = 0;
        $failed_checks = 0;
        $warning_checks = 0;
        
        foreach ($results as $category => $checks) {
            foreach ($checks as $check) {
                $total_checks++;
                switch ($check['status']) {
                    case 'PASS':
                        $passed_checks++;
                        break;
                    case 'FAIL':
                        $failed_checks++;
                        break;
                    case 'WARNING':
                        $warning_checks++;
                        break;
                }
            }
        }
        
        $pass_rate = $total_checks > 0 ? round(($passed_checks / $total_checks) * 100, 2) : 0;
        
        return [
            'results' => $results,
            'summary' => [
                'total_checks' => $total_checks,
                'passed_checks' => $passed_checks,
                'failed_checks' => $failed_checks,
                'warning_checks' => $warning_checks,
                'pass_rate' => $pass_rate,
                'execution_time' => $execution_time,
                'overall_status' => self::determine_overall_status($pass_rate, $failed_checks)
            ],
            'timestamp' => current_time('mysql')
        ];
    }
    
    /**
     * 确定总体状态
     *
     * @param float $pass_rate 通过率
     * @param int $failed_count 失败数量
     * @return string 总体状态
     */
    private static function determine_overall_status(float $pass_rate, int $failed_count): string {
        if ($pass_rate >= 95 && $failed_count === 0) {
            return 'EXCELLENT';
        } elseif ($pass_rate >= 85 && $failed_count <= 1) {
            return 'GOOD';
        } elseif ($pass_rate >= 70) {
            return 'ACCEPTABLE';
        } else {
            return 'NEEDS_ATTENTION';
        }
    }
}
