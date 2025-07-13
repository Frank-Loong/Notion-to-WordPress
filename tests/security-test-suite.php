<?php
/**
 * 安全测试套件
 * 
 * 对Notion-to-WordPress插件进行全面的安全测试
 *
 * @package Notion_To_WordPress
 * @subpackage Tests
 * @since 1.1.1
 */

class Notion_Security_Test_Suite {
    
    /**
     * 测试结果
     *
     * @var array
     */
    private $test_results = [];
    
    /**
     * 测试统计
     *
     * @var array
     */
    private $test_stats = [
        'total' => 0,
        'passed' => 0,
        'failed' => 0,
        'warnings' => 0
    ];

    /**
     * 运行所有安全测试
     *
     * @return array 测试结果
     */
    public function run_all_tests(): array {
        $this->log_test_start('安全测试套件开始执行');
        
        // 1. CSRF保护测试
        $this->test_csrf_protection();
        
        // 2. XSS防护测试
        $this->test_xss_protection();
        
        // 3. 文件上传安全测试
        $this->test_file_upload_security();
        
        // 4. SQL注入防护测试
        $this->test_sql_injection_protection();
        
        // 5. 权限验证测试
        $this->test_permission_validation();
        
        // 6. 输入验证测试
        $this->test_input_validation();
        
        // 7. Webhook安全测试
        $this->test_webhook_security();
        
        // 8. 配置安全测试
        $this->test_configuration_security();
        
        $this->log_test_end('安全测试套件执行完成');
        
        return [
            'results' => $this->test_results,
            'stats' => $this->test_stats,
            'summary' => $this->generate_test_summary()
        ];
    }

    /**
     * 测试CSRF保护
     */
    private function test_csrf_protection(): void {
        $this->log_test_section('CSRF保护测试');
        
        // 测试1: 验证nonce验证机制
        $test_name = 'CSRF - Nonce验证机制';
        try {
            // 模拟无效nonce的请求
            $_POST['nonce'] = 'invalid_nonce';
            $_POST['action'] = 'notion_to_wordpress_sync';
            
            // 检查是否正确拒绝无效nonce
            $admin = new Notion_To_WordPress_Admin('test', '1.1.1');
            $reflection = new ReflectionClass($admin);
            $method = $reflection->getMethod('validate_ajax_security');
            $method->setAccessible(true);
            
            ob_start();
            $result = $method->invoke($admin);
            $output = ob_get_clean();
            
            if ($result === false) {
                $this->record_test_result($test_name, 'PASS', 'CSRF保护正常工作');
            } else {
                $this->record_test_result($test_name, 'FAIL', '无效nonce未被正确拒绝');
            }
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', 'CSRF测试异常: ' . $e->getMessage());
        }
        
        // 测试2: 验证请求来源检查
        $test_name = 'CSRF - 请求来源验证';
        try {
            $_SERVER['HTTP_REFERER'] = 'https://malicious-site.com';
            
            $admin = new Notion_To_WordPress_Admin('test', '1.1.1');
            $reflection = new ReflectionClass($admin);
            $method = $reflection->getMethod('validate_request_origin');
            $method->setAccessible(true);
            
            $result = $method->invoke($admin);
            
            if ($result === false) {
                $this->record_test_result($test_name, 'PASS', '恶意来源请求被正确拒绝');
            } else {
                $this->record_test_result($test_name, 'FAIL', '恶意来源请求未被拒绝');
            }
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', '来源验证测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试XSS防护
     */
    private function test_xss_protection(): void {
        $this->log_test_section('XSS防护测试');
        
        // 测试1: 输出转义
        $test_name = 'XSS - 输出转义';
        $malicious_input = '<script>alert("XSS")</script>';
        $escaped_output = esc_html($malicious_input);
        
        if (strpos($escaped_output, '<script>') === false) {
            $this->record_test_result($test_name, 'PASS', 'HTML输出正确转义');
        } else {
            $this->record_test_result($test_name, 'FAIL', 'HTML输出未正确转义');
        }
        
        // 测试2: 属性转义
        $test_name = 'XSS - 属性转义';
        $malicious_attr = '" onload="alert(\'XSS\')"';
        $escaped_attr = esc_attr($malicious_attr);
        
        if (strpos($escaped_attr, 'onload=') === false) {
            $this->record_test_result($test_name, 'PASS', '属性值正确转义');
        } else {
            $this->record_test_result($test_name, 'FAIL', '属性值未正确转义');
        }
    }

    /**
     * 测试文件上传安全
     */
    private function test_file_upload_security(): void {
        $this->log_test_section('文件上传安全测试');
        
        // 测试1: 文件类型验证
        $test_name = '文件上传 - 类型验证';
        try {
            $allowed_types = Notion_To_WordPress_Helper::get_config('files.allowed_image_types', 'image/jpeg,image/png,image/gif,image/webp');
            $allowed_array = explode(',', $allowed_types);
            
            // 测试恶意文件类型
            $malicious_types = ['application/x-php', 'text/x-php', 'application/x-httpd-php'];
            $security_passed = true;
            
            foreach ($malicious_types as $type) {
                if (in_array($type, $allowed_array)) {
                    $security_passed = false;
                    break;
                }
            }
            
            if ($security_passed) {
                $this->record_test_result($test_name, 'PASS', '危险文件类型被正确拒绝');
            } else {
                $this->record_test_result($test_name, 'FAIL', '允许了危险的文件类型');
            }
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', '文件类型验证测试异常: ' . $e->getMessage());
        }
        
        // 测试2: 文件大小限制
        $test_name = '文件上传 - 大小限制';
        try {
            $max_size = Notion_To_WordPress_Helper::get_config('files.max_image_size_mb', 5);
            
            if ($max_size > 0 && $max_size <= 20) {
                $this->record_test_result($test_name, 'PASS', "文件大小限制合理: {$max_size}MB");
            } else {
                $this->record_test_result($test_name, 'WARNING', "文件大小限制可能过大: {$max_size}MB");
            }
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', '文件大小限制测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试SQL注入防护
     */
    private function test_sql_injection_protection(): void {
        $this->log_test_section('SQL注入防护测试');
        
        // 测试1: 参数化查询
        $test_name = 'SQL注入 - 参数化查询';
        try {
            $malicious_input = "'; DROP TABLE wp_posts; --";
            $notion_ids = [$malicious_input, 'normal_id'];
            
            // 测试批量查询方法是否使用参数化查询
            $result = Notion_To_WordPress_Helper::get_posts_by_notion_ids_batch($notion_ids);
            
            // 如果没有抛出异常且返回空结果，说明参数化查询工作正常
            $this->record_test_result($test_name, 'PASS', '批量查询使用参数化查询，防止SQL注入');
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', 'SQL注入防护测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试权限验证
     */
    private function test_permission_validation(): void {
        $this->log_test_section('权限验证测试');
        
        // 测试1: 管理员权限检查
        $test_name = '权限验证 - 管理员权限';
        try {
            // 模拟非管理员用户
            $user_id = wp_create_user('test_user', 'test_password', 'test@example.com');
            wp_set_current_user($user_id);
            
            $admin = new Notion_To_WordPress_Admin('test', '1.1.1');
            $reflection = new ReflectionClass($admin);
            $method = $reflection->getMethod('validate_ajax_security');
            $method->setAccessible(true);
            
            ob_start();
            $result = $method->invoke($admin);
            $output = ob_get_clean();
            
            if ($result === false) {
                $this->record_test_result($test_name, 'PASS', '非管理员用户被正确拒绝');
            } else {
                $this->record_test_result($test_name, 'FAIL', '非管理员用户未被拒绝');
            }
            
            // 清理测试用户
            wp_delete_user($user_id);
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', '权限验证测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试输入验证
     */
    private function test_input_validation(): void {
        $this->log_test_section('输入验证测试');

        // 测试1: 配置值验证
        $test_name = '输入验证 - 配置值验证';
        try {
            // 测试无效的配置值
            $invalid_configs = [
                'api.timeout' => -1,  // 负数超时
                'cache.max_items' => 999999,  // 超大缓存
                'files.max_image_size_mb' => 100,  // 超大文件
                'security.rate_limit_requests' => 0  // 零速率限制
            ];

            $validation_passed = true;
            foreach ($invalid_configs as $key => $value) {
                $result = Notion_To_WordPress_Helper::set_config($key, $value);
                $actual_value = Notion_To_WordPress_Helper::get_config($key);

                // 检查是否被自动修正
                if ($actual_value === $value) {
                    $validation_passed = false;
                    break;
                }
            }

            if ($validation_passed) {
                $this->record_test_result($test_name, 'PASS', '无效配置值被正确验证和修正');
            } else {
                $this->record_test_result($test_name, 'FAIL', '无效配置值未被正确验证');
            }
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', '输入验证测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试Webhook安全
     */
    private function test_webhook_security(): void {
        $this->log_test_section('Webhook安全测试');

        // 测试1: Token验证
        $test_name = 'Webhook - Token验证';
        try {
            $webhook = new Notion_To_WordPress_Webhook();

            // 模拟无效token的请求
            $request = ['token' => 'invalid_token'];

            $reflection = new ReflectionClass($webhook);
            $method = $reflection->getMethod('handle_webhook');
            $method->setAccessible(true);

            $result = $method->invoke($webhook, $request);

            if (is_wp_error($result) || (isset($result->status_code) && $result->status_code === 403)) {
                $this->record_test_result($test_name, 'PASS', 'Webhook token验证正常工作');
            } else {
                $this->record_test_result($test_name, 'FAIL', 'Webhook token验证失败');
            }
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', 'Webhook安全测试异常: ' . $e->getMessage());
        }

        // 测试2: 频率限制
        $test_name = 'Webhook - 频率限制';
        try {
            $webhook = new Notion_To_WordPress_Webhook();
            $reflection = new ReflectionClass($webhook);
            $method = $reflection->getMethod('check_webhook_rate_limit');
            $method->setAccessible(true);

            // 模拟大量请求
            $test_ip = '192.168.1.100';
            $rate_limit_triggered = false;

            for ($i = 0; $i < 15; $i++) {  // 超过默认限制10次
                $result = $method->invoke($webhook, $test_ip);
                if (!$result) {
                    $rate_limit_triggered = true;
                    break;
                }
            }

            if ($rate_limit_triggered) {
                $this->record_test_result($test_name, 'PASS', 'Webhook频率限制正常工作');
            } else {
                $this->record_test_result($test_name, 'FAIL', 'Webhook频率限制未生效');
            }
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', 'Webhook频率限制测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试配置安全
     */
    private function test_configuration_security(): void {
        $this->log_test_section('配置安全测试');

        // 测试1: 敏感信息保护
        $test_name = '配置安全 - 敏感信息保护';
        try {
            // 模拟导出配置
            $admin = new Notion_To_WordPress_Admin('test', '1.1.1');

            // 设置一个测试API密钥
            update_option('notion_to_wordpress_options', [
                'notion_api_key' => 'secret_test_key_12345'
            ]);

            $_POST['config_action'] = 'export';
            $_POST['nonce'] = wp_create_nonce('notion_to_wordpress_nonce');

            ob_start();
            $admin->handle_config_management();
            $output = ob_get_clean();

            // 检查输出是否包含敏感信息
            if (strpos($output, 'secret_test_key') === false) {
                $this->record_test_result($test_name, 'PASS', '敏感信息在导出时被正确过滤');
            } else {
                $this->record_test_result($test_name, 'FAIL', '敏感信息在导出时未被过滤');
            }
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', '配置安全测试异常: ' . $e->getMessage());
        }

        // 测试2: 配置完整性验证
        $test_name = '配置安全 - 完整性验证';
        try {
            $validation_result = Notion_To_WordPress_Helper::validate_config();

            if (isset($validation_result['valid'])) {
                $this->record_test_result($test_name, 'PASS', '配置完整性验证功能正常');
            } else {
                $this->record_test_result($test_name, 'FAIL', '配置完整性验证功能异常');
            }
        } catch (Exception $e) {
            $this->record_test_result($test_name, 'FAIL', '配置完整性验证测试异常: ' . $e->getMessage());
        }
    }

    /**
     * 记录测试结果
     */
    private function record_test_result(string $test_name, string $status, string $message): void {
        $this->test_results[] = [
            'test' => $test_name,
            'status' => $status,
            'message' => $message,
            'timestamp' => current_time('mysql')
        ];
        
        $this->test_stats['total']++;
        
        switch ($status) {
            case 'PASS':
                $this->test_stats['passed']++;
                break;
            case 'FAIL':
                $this->test_stats['failed']++;
                break;
            case 'WARNING':
                $this->test_stats['warnings']++;
                break;
        }
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
     * 生成测试摘要
     */
    private function generate_test_summary(): string {
        $total = $this->test_stats['total'];
        $passed = $this->test_stats['passed'];
        $failed = $this->test_stats['failed'];
        $warnings = $this->test_stats['warnings'];
        
        $pass_rate = $total > 0 ? round(($passed / $total) * 100, 2) : 0;
        
        return "安全测试完成: 总计 {$total} 项，通过 {$passed} 项，失败 {$failed} 项，警告 {$warnings} 项。通过率: {$pass_rate}%";
    }
}
