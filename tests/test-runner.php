<?php
/**
 * 测试运行器
 * 
 * 统一运行所有测试套件并生成报告
 *
 * @package Notion_To_WordPress
 * @subpackage Tests
 * @since 1.1.1
 */

// 确保WordPress环境
if (!defined('ABSPATH')) {
    // 尝试加载WordPress
    $wp_load_paths = [
        '../../../wp-load.php',
        '../../../../wp-load.php',
        '../../../../../wp-load.php'
    ];
    
    foreach ($wp_load_paths as $path) {
        if (file_exists(__DIR__ . '/' . $path)) {
            require_once __DIR__ . '/' . $path;
            break;
        }
    }
    
    if (!defined('ABSPATH')) {
        die('无法加载WordPress环境');
    }
}

// 加载测试套件
require_once __DIR__ . '/security-test-suite.php';
require_once __DIR__ . '/performance-test-suite.php';
require_once __DIR__ . '/functional-test-suite.php';

class Notion_Test_Runner {
    
    /**
     * 所有测试结果
     *
     * @var array
     */
    private $all_results = [];
    
    /**
     * 测试开始时间
     *
     * @var float
     */
    private $start_time;
    
    /**
     * 运行所有测试
     *
     * @return array 完整测试结果
     */
    public function run_all_tests(): array {
        $this->start_time = microtime(true);
        $this->log_message('开始执行Notion-to-WordPress插件全面测试验证');
        
        // 1. 运行安全测试
        $this->log_message('执行安全测试套件...');
        $security_suite = new Notion_Security_Test_Suite();
        $security_results = $security_suite->run_all_tests();
        $this->all_results['security'] = $security_results;
        
        // 2. 运行性能测试
        $this->log_message('执行性能测试套件...');
        $performance_suite = new Notion_Performance_Test_Suite();
        $performance_results = $performance_suite->run_all_tests();
        $this->all_results['performance'] = $performance_results;
        
        // 3. 运行功能测试
        $this->log_message('执行功能回归测试套件...');
        $functional_suite = new Notion_Functional_Test_Suite();
        $functional_results = $functional_suite->run_all_tests();
        $this->all_results['functional'] = $functional_results;
        
        // 4. 生成综合报告
        $this->log_message('生成测试报告...');
        $comprehensive_report = $this->generate_comprehensive_report();
        
        $end_time = microtime(true);
        $total_time = round($end_time - $this->start_time, 2);
        
        $this->log_message("测试验证完成，总耗时: {$total_time}秒");
        
        return [
            'results' => $this->all_results,
            'report' => $comprehensive_report,
            'execution_time' => $total_time,
            'timestamp' => current_time('mysql')
        ];
    }
    
    /**
     * 生成综合测试报告
     *
     * @return array 综合报告
     */
    private function generate_comprehensive_report(): array {
        $total_stats = [
            'total_tests' => 0,
            'passed_tests' => 0,
            'failed_tests' => 0,
            'warning_tests' => 0
        ];
        
        $suite_summaries = [];
        $critical_issues = [];
        $recommendations = [];
        
        // 汇总各测试套件结果
        foreach ($this->all_results as $suite_name => $suite_results) {
            $suite_stats = $this->analyze_suite_results($suite_results);
            $total_stats['total_tests'] += $suite_stats['total'];
            $total_stats['passed_tests'] += $suite_stats['passed'];
            $total_stats['failed_tests'] += $suite_stats['failed'];
            $total_stats['warning_tests'] += $suite_stats['warnings'];
            
            $suite_summaries[$suite_name] = $suite_stats;
            
            // 收集关键问题
            $critical_issues = array_merge($critical_issues, $this->extract_critical_issues($suite_results, $suite_name));
        }
        
        // 计算总体通过率
        $overall_pass_rate = $total_stats['total_tests'] > 0 ? 
            round(($total_stats['passed_tests'] / $total_stats['total_tests']) * 100, 2) : 0;
        
        // 生成建议
        $recommendations = $this->generate_recommendations($total_stats, $critical_issues);
        
        // 确定总体状态
        $overall_status = $this->determine_overall_status($overall_pass_rate, $total_stats['failed_tests']);
        
        return [
            'overall_status' => $overall_status,
            'overall_pass_rate' => $overall_pass_rate,
            'total_statistics' => $total_stats,
            'suite_summaries' => $suite_summaries,
            'critical_issues' => $critical_issues,
            'recommendations' => $recommendations,
            'detailed_results' => $this->all_results
        ];
    }
    
    /**
     * 分析测试套件结果
     *
     * @param array $suite_results 测试套件结果
     * @return array 统计信息
     */
    private function analyze_suite_results(array $suite_results): array {
        $results = $suite_results['results'] ?? [];
        
        $stats = [
            'total' => count($results),
            'passed' => 0,
            'failed' => 0,
            'warnings' => 0
        ];
        
        foreach ($results as $result) {
            switch ($result['status']) {
                case 'PASS':
                    $stats['passed']++;
                    break;
                case 'FAIL':
                    $stats['failed']++;
                    break;
                case 'WARNING':
                    $stats['warnings']++;
                    break;
            }
        }
        
        $stats['pass_rate'] = $stats['total'] > 0 ? 
            round(($stats['passed'] / $stats['total']) * 100, 2) : 0;
        
        return $stats;
    }
    
    /**
     * 提取关键问题
     *
     * @param array $suite_results 测试套件结果
     * @param string $suite_name 测试套件名称
     * @return array 关键问题列表
     */
    private function extract_critical_issues(array $suite_results, string $suite_name): array {
        $critical_issues = [];
        $results = $suite_results['results'] ?? [];
        
        foreach ($results as $result) {
            if ($result['status'] === 'FAIL') {
                $critical_issues[] = [
                    'suite' => $suite_name,
                    'test' => $result['test'],
                    'message' => $result['message'],
                    'severity' => $this->determine_issue_severity($result['test'], $suite_name)
                ];
            }
        }
        
        return $critical_issues;
    }
    
    /**
     * 确定问题严重程度
     *
     * @param string $test_name 测试名称
     * @param string $suite_name 测试套件名称
     * @return string 严重程度
     */
    private function determine_issue_severity(string $test_name, string $suite_name): string {
        // 安全问题通常是高严重性
        if ($suite_name === 'security') {
            if (strpos($test_name, 'CSRF') !== false || strpos($test_name, 'XSS') !== false) {
                return 'CRITICAL';
            }
            return 'HIGH';
        }
        
        // 核心功能问题是高严重性
        if (strpos($test_name, '核心功能') !== false || strpos($test_name, 'API连接') !== false) {
            return 'HIGH';
        }
        
        // 性能问题通常是中等严重性
        if ($suite_name === 'performance') {
            return 'MEDIUM';
        }
        
        return 'LOW';
    }
    
    /**
     * 生成建议
     *
     * @param array $total_stats 总体统计
     * @param array $critical_issues 关键问题
     * @return array 建议列表
     */
    private function generate_recommendations(array $total_stats, array $critical_issues): array {
        $recommendations = [];
        
        // 基于通过率的建议
        $pass_rate = $total_stats['total_tests'] > 0 ? 
            ($total_stats['passed_tests'] / $total_stats['total_tests']) * 100 : 0;
        
        if ($pass_rate < 80) {
            $recommendations[] = [
                'type' => 'URGENT',
                'message' => '测试通过率低于80%，建议立即修复失败的测试项目'
            ];
        } elseif ($pass_rate < 95) {
            $recommendations[] = [
                'type' => 'IMPORTANT',
                'message' => '测试通过率可以进一步提升，建议优化警告项目'
            ];
        }
        
        // 基于关键问题的建议
        $critical_count = count(array_filter($critical_issues, function($issue) {
            return $issue['severity'] === 'CRITICAL';
        }));
        
        if ($critical_count > 0) {
            $recommendations[] = [
                'type' => 'CRITICAL',
                'message' => "发现 {$critical_count} 个关键安全问题，必须立即修复"
            ];
        }
        
        // 基于失败测试数量的建议
        if ($total_stats['failed_tests'] > 5) {
            $recommendations[] = [
                'type' => 'IMPORTANT',
                'message' => '失败测试较多，建议系统性地检查和修复问题'
            ];
        }
        
        // 成功情况的建议
        if ($pass_rate >= 95 && $critical_count === 0) {
            $recommendations[] = [
                'type' => 'SUCCESS',
                'message' => '测试结果优秀，建议建立持续集成流程保持质量'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * 确定总体状态
     *
     * @param float $pass_rate 通过率
     * @param int $failed_count 失败数量
     * @return string 总体状态
     */
    private function determine_overall_status(float $pass_rate, int $failed_count): string {
        if ($pass_rate >= 95 && $failed_count === 0) {
            return 'EXCELLENT';
        } elseif ($pass_rate >= 90 && $failed_count <= 2) {
            return 'GOOD';
        } elseif ($pass_rate >= 80) {
            return 'ACCEPTABLE';
        } elseif ($pass_rate >= 60) {
            return 'NEEDS_IMPROVEMENT';
        } else {
            return 'CRITICAL';
        }
    }
    
    /**
     * 记录日志消息
     *
     * @param string $message 消息内容
     */
    private function log_message(string $message): void {
        $timestamp = current_time('Y-m-d H:i:s');
        error_log("[{$timestamp}] Notion Test Runner: {$message}");
        
        // 如果在命令行环境，也输出到标准输出
        if (php_sapi_name() === 'cli') {
            echo "[{$timestamp}] {$message}\n";
        }
    }
    
    /**
     * 保存测试报告到文件
     *
     * @param array $report 测试报告
     * @param string $filename 文件名
     * @return bool 是否保存成功
     */
    public function save_report_to_file(array $report, string $filename = null): bool {
        if ($filename === null) {
            $filename = 'notion-test-report-' . date('Y-m-d-H-i-s') . '.json';
        }
        
        $report_path = __DIR__ . '/reports/' . $filename;
        
        // 确保报告目录存在
        $report_dir = dirname($report_path);
        if (!is_dir($report_dir)) {
            wp_mkdir_p($report_dir);
        }
        
        $json_report = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if (file_put_contents($report_path, $json_report)) {
            $this->log_message("测试报告已保存到: {$report_path}");
            return true;
        } else {
            $this->log_message("保存测试报告失败: {$report_path}");
            return false;
        }
    }
}

// 如果直接运行此文件，执行测试
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $runner = new Notion_Test_Runner();
    $results = $runner->run_all_tests();
    $runner->save_report_to_file($results);
    
    echo "\n=== 测试验证完成 ===\n";
    echo "总体状态: " . $results['report']['overall_status'] . "\n";
    echo "通过率: " . $results['report']['overall_pass_rate'] . "%\n";
    echo "执行时间: " . $results['execution_time'] . "秒\n";
    
    if (!empty($results['report']['critical_issues'])) {
        echo "\n关键问题:\n";
        foreach ($results['report']['critical_issues'] as $issue) {
            echo "- [{$issue['severity']}] {$issue['test']}: {$issue['message']}\n";
        }
    }
    
    if (!empty($results['report']['recommendations'])) {
        echo "\n建议:\n";
        foreach ($results['report']['recommendations'] as $recommendation) {
            echo "- [{$recommendation['type']}] {$recommendation['message']}\n";
        }
    }
}
