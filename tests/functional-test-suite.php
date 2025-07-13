<?php
/**
 * 功能回归测试套件
 * 
 * 对Notion-to-WordPress插件进行全面的功能测试
 *
 * @package Notion_To_WordPress
 * @subpackage Tests
 * @since 1.1.1
 */

class Notion_Functional_Test_Suite {
    
    /**
     * 测试结果
     *
     * @var array
     */
    private $test_results = [];

    /**
     * 运行所有功能测试
     *
     * @return array 测试结果
     */
    public function run_all_tests(): array {
        $this->log_test_start('功能回归测试套件开始执行');
        
        // 1. 核心功能测试
        $this->test_core_functionality();
        
        // 2. API连接测试
        $this->test_api_connectivity();
        
        // 3. 页面同步测试
        $this->test_page_synchronization();
        
        // 4. 配置管理测试
        $this->test_configuration_management();
        
        // 5. 错误处理测试
        $this->test_error_handling();
        
        // 6. 用户界面测试
        $this->test_user_interface();
        
        $this->log_test_end('功能回归测试套件执行完成');
        
        return [
            'results' => $this->test_results,
            'summary' => $this->generate_functional_summary()
        ];
    }

    /**
     * 测试核心功能
     */
    private function test_core_functionality(): void {
        $this->log_test_section('核心功能测试');
        
        // 测试1: 插件激活和初始化
        $test_name = '核心功能 - 插件初始化';
        try {
            $plugin = new Notion_To_WordPress();
            
            if ($plugin instanceof Notion_To_WordPress) {
                $this->record_functional_result($test_name, 'PASS', '插件成功初始化');
            } else {
                $this->record_functional_result($test_name, 'FAIL', '插件初始化失败');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', '插件初始化异常: ' . $e->getMessage());
        }
        
        // 测试2: Helper类功能
        $test_name = '核心功能 - Helper类';
        try {
            // 测试日志功能
            Notion_To_WordPress_Helper::debug_log('测试日志', 'Test');
            
            // 测试配置功能
            $config_value = Notion_To_WordPress_Helper::get_config('api.timeout', 30);
            
            // 测试错误处理功能
            $error = Notion_To_WordPress_Helper::create_error('test_error', '测试错误');
            
            if (is_numeric($config_value) && is_wp_error($error)) {
                $this->record_functional_result($test_name, 'PASS', 'Helper类功能正常');
            } else {
                $this->record_functional_result($test_name, 'FAIL', 'Helper类功能异常');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', 'Helper类测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试API连接
     */
    private function test_api_connectivity(): void {
        $this->log_test_section('API连接测试');
        
        // 测试1: API类实例化
        $test_name = 'API连接 - 类实例化';
        try {
            $api = new Notion_API('test_key');
            
            if ($api instanceof Notion_API) {
                $this->record_functional_result($test_name, 'PASS', 'API类成功实例化');
            } else {
                $this->record_functional_result($test_name, 'FAIL', 'API类实例化失败');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', 'API类实例化异常: ' . $e->getMessage());
        }
        
        // 测试2: 错误处理机制
        $test_name = 'API连接 - 错误处理';
        try {
            $api = new Notion_API('invalid_key');
            $result = $api->test_connection();
            
            if (is_wp_error($result)) {
                $this->record_functional_result($test_name, 'PASS', 'API错误处理正常');
            } else {
                $this->record_functional_result($test_name, 'WARNING', 'API错误处理可能异常');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'PASS', 'API错误处理正常（异常捕获）');
        }
    }

    /**
     * 测试页面同步
     */
    private function test_page_synchronization(): void {
        $this->log_test_section('页面同步测试');
        
        // 测试1: Pages类实例化
        $test_name = '页面同步 - 类实例化';
        try {
            $pages = new Notion_Pages();
            
            if ($pages instanceof Notion_Pages) {
                $this->record_functional_result($test_name, 'PASS', 'Pages类成功实例化');
            } else {
                $this->record_functional_result($test_name, 'FAIL', 'Pages类实例化失败');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', 'Pages类实例化异常: ' . $e->getMessage());
        }
        
        // 测试2: 页面属性提取
        $test_name = '页面同步 - 属性提取';
        try {
            $pages = new Notion_Pages();
            $reflection = new ReflectionClass($pages);
            $method = $reflection->getMethod('extract_page_properties');
            $method->setAccessible(true);
            
            $test_page = [
                'properties' => [
                    'title' => [
                        'title' => [
                            ['text' => ['content' => 'Test Title']]
                        ]
                    ]
                ]
            ];
            
            $result = $method->invoke($pages, $test_page);
            
            if (is_array($result) && isset($result['title'])) {
                $this->record_functional_result($test_name, 'PASS', '页面属性提取正常');
            } else {
                $this->record_functional_result($test_name, 'FAIL', '页面属性提取失败');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', '页面属性提取异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试配置管理
     */
    private function test_configuration_management(): void {
        $this->log_test_section('配置管理测试');
        
        // 测试1: 配置读取
        $test_name = '配置管理 - 配置读取';
        try {
            $config_value = Notion_To_WordPress_Helper::get_config('api.timeout');
            
            if (is_numeric($config_value)) {
                $this->record_functional_result($test_name, 'PASS', '配置读取正常');
            } else {
                $this->record_functional_result($test_name, 'FAIL', '配置读取失败');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', '配置读取异常: ' . $e->getMessage());
        }
        
        // 测试2: 配置验证
        $test_name = '配置管理 - 配置验证';
        try {
            $validation_result = Notion_To_WordPress_Helper::validate_config();
            
            if (is_array($validation_result) && isset($validation_result['valid'])) {
                $this->record_functional_result($test_name, 'PASS', '配置验证功能正常');
            } else {
                $this->record_functional_result($test_name, 'FAIL', '配置验证功能异常');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', '配置验证异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试错误处理
     */
    private function test_error_handling(): void {
        $this->log_test_section('错误处理测试');
        
        // 测试1: 错误创建
        $test_name = '错误处理 - 错误创建';
        try {
            $error = Notion_To_WordPress_Helper::create_error(
                'test_error',
                '测试错误消息',
                Notion_To_WordPress_Helper::ERROR_TYPE_SYSTEM,
                Notion_To_WordPress_Helper::ERROR_SEVERITY_MEDIUM
            );
            
            if (is_wp_error($error) && $error->get_error_code() === 'test_error') {
                $this->record_functional_result($test_name, 'PASS', '错误创建功能正常');
            } else {
                $this->record_functional_result($test_name, 'FAIL', '错误创建功能异常');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', '错误创建测试异常: ' . $e->getMessage());
        }
        
        // 测试2: 异常转换
        $test_name = '错误处理 - 异常转换';
        try {
            $exception = new Exception('测试异常');
            $error = Notion_To_WordPress_Helper::exception_to_wp_error($exception);
            
            if (is_wp_error($error)) {
                $this->record_functional_result($test_name, 'PASS', '异常转换功能正常');
            } else {
                $this->record_functional_result($test_name, 'FAIL', '异常转换功能异常');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', '异常转换测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试用户界面
     */
    private function test_user_interface(): void {
        $this->log_test_section('用户界面测试');
        
        // 测试1: Admin类实例化
        $test_name = '用户界面 - Admin类';
        try {
            $admin = new Notion_To_WordPress_Admin('test', '1.1.1');
            
            if ($admin instanceof Notion_To_WordPress_Admin) {
                $this->record_functional_result($test_name, 'PASS', 'Admin类成功实例化');
            } else {
                $this->record_functional_result($test_name, 'FAIL', 'Admin类实例化失败');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', 'Admin类实例化异常: ' . $e->getMessage());
        }
        
        // 测试2: 安全验证中间件
        $test_name = '用户界面 - 安全验证';
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
            $output = ob_get_clean();
            
            if ($result === false) {
                $this->record_functional_result($test_name, 'PASS', '安全验证中间件正常工作');
            } else {
                $this->record_functional_result($test_name, 'FAIL', '安全验证中间件异常');
            }
        } catch (Exception $e) {
            $this->record_functional_result($test_name, 'FAIL', '安全验证测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 记录功能测试结果
     */
    private function record_functional_result(string $test_name, string $status, string $message): void {
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
     * 生成功能测试摘要
     */
    private function generate_functional_summary(): string {
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
        
        return "功能测试完成: 总计 {$total} 项，通过 {$passed} 项，失败 {$failed} 项，警告 {$warnings} 项。通过率: {$pass_rate}%";
    }
}
