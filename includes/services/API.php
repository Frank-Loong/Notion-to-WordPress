<?php
declare(strict_types=1);

namespace NTWP\Services;

use NTWP\Contracts\API_Interface;

/**
 * Notion API 交互类
 *
 * 封装了与 Notion API 通信的所有方法，包括获取数据库、页面、块等内容。
 * 处理 API 认证、请求发送、响应解析和错误处理。提供了完整的 Notion API
 * 功能封装，支持数据库查询、页面操作、内容同步等核心功能。
 *
 * @since      1.0.9
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

// 加载并发网络管理器和重试机制（Utils层）
require_once plugin_dir_path(__FILE__) . '../utils/Concurrent_Network_Manager.php';
// // 已迁移到PSR-4
require_once plugin_dir_path(__FILE__) . '../utils/Smart_API_Merger.php';

class API implements API_Interface {

    /**
     * Notion API 密钥。
     *
     * @since    1.0.8
     * @access   private
     * @var      string
     */
    private string $api_key;

    /**
     * Notion API 的基础 URL。
     *
     * @since    1.0.8
     * @access   private
     * @var      string
     */
    private string $api_base = 'https://api.notion.com/v1/';

    /**
     * 智能API调用合并器实例
     *
     * @since    2.0.0-beta.1
     * @access   private
     * @var      Notion_Smart_API_Merger|null
     */
    private ?\NTWP\Utils\Smart_API_Merger $api_merger = null;

    /**
     * 是否启用智能API合并
     *
     * @since    2.0.0-beta.1
     * @access   private
     * @var      bool
     */
    private bool $enable_api_merging = true;

    /**
     * 增强的重试配置
     *
     * @since 2.0.0-beta.1
     * @var array
     */
    private static $retry_config = [
        'NETWORK_ERROR' => ['max_retries' => 3, 'backoff' => [1, 3, 9], 'should_retry' => true],
        'RATE_LIMIT_ERROR' => ['max_retries' => 5, 'backoff' => [5, 15, 45, 120, 300], 'should_retry' => true],
        'SERVER_ERROR' => ['max_retries' => 2, 'backoff' => [2, 8], 'should_retry' => true],
        'FILTER_ERROR' => ['max_retries' => 1, 'backoff' => [1], 'should_retry' => false], // 快速降级
        'AUTH_ERROR' => ['max_retries' => 0, 'backoff' => [], 'should_retry' => false],
        'CLIENT_ERROR' => ['max_retries' => 0, 'backoff' => [], 'should_retry' => false]
    ];

    /**
     * 智能缓存策略配置
     *
     * @since 2.0.0-beta.1
     * @var array
     */
    private static $cache_strategies = [
        // 静态数据 - 长期缓存
        'users/me' => ['ttl' => 3600, 'real_time' => false, 'type' => 'user_info'],
        'databases/' => ['ttl' => 1800, 'real_time' => false, 'type' => 'database_structure'],
        
        // 内容数据 - 动态缓存策略
        'pages/' => ['ttl' => 300, 'real_time' => true, 'type' => 'page_content'],
        'blocks/' => ['ttl' => 180, 'real_time' => true, 'type' => 'block_content'],
        
        // 查询数据 - 短期缓存
        'databases/*/query' => ['ttl' => 60, 'real_time' => true, 'type' => 'query_results']
    ];

    /**
     * 当前同步模式
     *
     * @since 2.0.0-beta.1
     * @var string
     */
    private string $sync_mode = 'full'; // 'full', 'incremental', 'manual'

    /**
     * 智能缓存策略：根据数据特性和同步模式选择性启用缓存
     * - 静态数据（用户信息、数据库结构）：长期缓存
     * - 内容数据：根据同步模式动态缓存（增量同步时短期缓存，全量同步时中期缓存）
     * - 实时性数据：仅会话级缓存，确保不影响增量同步的时间戳比较
     */

    /**
     * 设置同步模式
     *
     * @since 2.0.0-beta.1
     * @param string $mode 同步模式 ('full', 'incremental', 'manual')
     */
    public function set_sync_mode(string $mode): void {
        $this->sync_mode = $mode;
    }

    /**
     * 获取端点的缓存策略
     *
     * @since 2.0.0-beta.1
     * @param string $endpoint API端点
     * @param string $method HTTP方法
     * @return array|null 缓存策略配置
     */
    private function get_cache_strategy(string $endpoint, string $method = 'GET'): ?array {
        // 只对GET请求使用缓存
        if ($method !== 'GET') {
            return null;
        }

        // 匹配端点模式
        foreach (self::$cache_strategies as $pattern => $strategy) {
            if ($this->endpoint_matches_pattern($endpoint, $pattern)) {
                return $strategy;
            }
        }

        return null;
    }

    /**
     * 检查端点是否匹配模式
     *
     * @since 2.0.0-beta.1
     * @param string $endpoint 端点
     * @param string $pattern 模式
     * @return bool 是否匹配
     */
    private function endpoint_matches_pattern(string $endpoint, string $pattern): bool {
        // 简单模式匹配
        $pattern = str_replace('*', '.*', preg_quote($pattern, '/'));
        return preg_match('/^' . $pattern . '/', $endpoint) === 1;
    }

    /**
     * 智能缓存检查：根据同步模式和数据特性决定是否使用缓存
     *
     * @since 2.0.0-beta.1
     * @param string $endpoint API端点
     * @param array $strategy 缓存策略
     * @return bool 是否应该使用缓存
     */
    private function should_use_cache(string $endpoint, array $strategy): bool {
        // 静态数据总是可以缓存
        if (!$strategy['real_time']) {
            return true;
        }

        // 根据同步模式调整缓存策略
        switch ($this->sync_mode) {
            case 'incremental':
                // 增量同步时，只使用极短期缓存
                return false; // 完全禁用缓存以确保实时性
                
            case 'manual':
                // 手动同步时，使用短期缓存
                return $strategy['ttl'] <= 60;
                
            case 'full':
            default:
                // 全量同步时，可以使用所有缓存
                return true;
        }
    }

    /**
     * 生成智能缓存键
     *
     * @since 2.0.0-beta.1
     * @param string $endpoint API端点
     * @param array $data 请求数据
     * @param string $type 缓存类型
     * @return string 缓存键
     */
    private function generate_smart_cache_key(string $endpoint, array $data, string $type): string {
        $base_key = md5($endpoint . serialize($data));
        
        // 根据同步模式添加前缀
        $prefix = "ntwp_smart_cache_{$this->sync_mode}_{$type}";
        
        return "{$prefix}_{$base_key}";
    }

    /**
     * 精确的API错误分类
     *
     * @since 2.0.0-beta.1
     * @param Exception $exception 异常对象
     * @return string 错误类型
     */
    private function classify_api_error_precise(Exception $exception): string {
        $message = strtolower($exception->getMessage());
        $code = $exception->getCode();

        // 获取HTTP状态码（如果可用）
        $http_code = $this->extract_http_code($exception);

        \NTWP\Core\Logger::debug_log(
            "精确错误分类: 消息='{$message}', 代码={$code}, HTTP={$http_code}",
            'Enhanced Error Classification'
        );

        // 过滤器错误 - 使用正则表达式精确匹配
        $filter_patterns = [
            '/filter.*validation.*failed/i',
            '/property.*last_edited_time.*not.*exist/i',
            '/invalid.*timestamp.*format/i',
            '/filter.*property.*does.*not.*exist/i',
            '/bad.*request.*filter/i',
            '/unsupported.*filter.*type/i'
        ];

        foreach ($filter_patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                \NTWP\Core\Logger::debug_log(
                    "匹配过滤器错误模式: {$pattern}",
                    'Enhanced Error Classification'
                );
                return 'FILTER_ERROR';
            }
        }

        // 认证错误
        if ($http_code === 401 || $http_code === 403 || 
            preg_match('/unauthorized|forbidden|invalid.*token|expired.*token/i', $message)) {
            return 'AUTH_ERROR';
        }

        // 限流错误
        if ($http_code === 429 || preg_match('/rate.*limit|too.*many.*requests/i', $message)) {
            return 'RATE_LIMIT_ERROR';
        }

        // 网络错误
        $network_patterns = [
            '/timeout|connection.*refused|connection.*reset/i',
            '/curl.*error|ssl.*error|network.*unreachable/i',
            '/dns.*resolution.*failed|host.*not.*found/i'
        ];

        foreach ($network_patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return 'NETWORK_ERROR';
            }
        }

        // 服务器错误
        if ($http_code >= 500 || preg_match('/internal.*server|service.*unavailable|bad.*gateway/i', $message)) {
            return 'SERVER_ERROR';
        }

        // 客户端错误
        if ($http_code >= 400 && $http_code < 500) {
            return 'CLIENT_ERROR';
        }

        // 未知错误
        return 'UNKNOWN_ERROR';
    }

    /**
     * 从异常中提取HTTP状态码
     *
     * @since 2.0.0-beta.1
     * @param Exception $exception 异常对象
     * @return int HTTP状态码
     */
    private function extract_http_code(Exception $exception): int {
        $message = $exception->getMessage();
        
        // 尝试从消息中提取HTTP状态码
        if (preg_match('/\b(\d{3})\b/', $message, $matches)) {
            $code = intval($matches[1]);
            if ($code >= 100 && $code < 600) { // 有效的HTTP状态码范围
                return $code;
            }
        }

        // 从异常代码获取
        $code = $exception->getCode();
        if ($code >= 100 && $code < 600) {
            return $code;
        }

        return 0; // 未知状态码
    }

    /**
     * 指数退避重试机制
     *
     * @since 2.0.0-beta.1
     * @param callable $operation 要执行的操作
     * @param string $operation_name 操作名称（用于日志）
     * @param array $context 操作上下文
     * @return mixed 操作结果
     * @throws Exception 如果所有重试都失败
     */
    private function retry_with_backoff(callable $operation, string $operation_name = 'API调用', array $context = []) {
        $last_exception = null;
        $attempt = 0;

        while ($attempt <= 3) { // 最多尝试4次（初始 + 3次重试）
            try {
                $attempt++;
                
                if ($attempt > 1) {
                    \NTWP\Core\Logger::info_log(
                        "开始第 {$attempt} 次尝试: {$operation_name}",
                        'Enhanced Retry'
                    );
                }

                return $operation();

            } catch (\Exception $e) {
                $last_exception = $e;
                $error_type = $this->classify_api_error_precise($e);
                
                // 检查是否应该重试
                if (!$this->should_retry_enhanced($error_type, $attempt)) {
                    \NTWP\Core\Logger::warning_log(
                        "不应重试的错误类型: {$error_type}, 尝试次数: {$attempt}",
                        'Enhanced Retry'
                    );
                    break;
                }

                // 计算退避时间
                $backoff_time = $this->calculate_backoff_time($error_type, $attempt);
                
                \NTWP\Core\Logger::warning_log(
                    "第 {$attempt} 次尝试失败 ({$error_type}): {$e->getMessage()}, {$backoff_time}秒后重试",
                    'Enhanced Retry'
                );

                if ($attempt <= 3) { // 不在最后一次尝试后等待
                    sleep($backoff_time);
                }
            }
        }

        // 所有重试都失败，抛出最后一个异常
        if ($last_exception) {
            \NTWP\Core\Logger::error_log(
                "所有重试失败，操作: {$operation_name}, 最终错误: " . $last_exception->getMessage(),
                'Enhanced Retry'
            );
            throw $last_exception;
        }

        throw new \Exception("未知的重试失败: {$operation_name}");
    }

    /**
     * 增强的重试判断逻辑
     *
     * @since 2.0.0-beta.1
     * @param string $error_type 错误类型
     * @param int $attempt_count 当前尝试次数
     * @return bool 是否应该重试
     */
    private function should_retry_enhanced(string $error_type, int $attempt_count): bool {
        if (!isset(self::$retry_config[$error_type])) {
            return false; // 未知错误类型不重试
        }

        $config = self::$retry_config[$error_type];
        
        // 检查是否超过最大重试次数
        if ($attempt_count > $config['max_retries']) {
            return false;
        }

        return $config['should_retry'];
    }

    /**
     * 计算退避时间
     *
     * @since 2.0.0-beta.1
     * @param string $error_type 错误类型
     * @param int $attempt_count 当前尝试次数
     * @return int 退避时间（秒）
     */
    private function calculate_backoff_time(string $error_type, int $attempt_count): int {
        if (!isset(self::$retry_config[$error_type])) {
            return min(pow(2, $attempt_count - 1), 10); // 默认指数退避，最大10秒
        }

        $config = self::$retry_config[$error_type];
        $backoff_array = $config['backoff'];
        
        // 使用预定义的退避时间，如果超出数组范围则使用最后一个值
        $index = min($attempt_count - 2, count($backoff_array) - 1); // attempt_count从2开始（第一次重试）
        
        return $index >= 0 ? $backoff_array[$index] : 1;
    }

    /**
     * 智能降级策略
     *
     * @since 2.0.0-beta.1
     * @param string $error_type 错误类型
     * @param int $estimated_data_size 预估数据量
     * @param array $context 操作上下文
     * @return string 降级策略
     */
    private function get_fallback_strategy(string $error_type, int $estimated_data_size, array $context = []): string {
        \NTWP\Core\Logger::debug_log(
            "智能降级策略分析: 错误={$error_type}, 数据量={$estimated_data_size}",
            'Fallback Strategy'
        );

        if ($error_type === 'FILTER_ERROR') {
            // 过滤器错误 - 根据数据量选择策略
            if ($estimated_data_size < 100) {
                return 'FULL_SYNC'; // 小数据集，直接全量同步
            } elseif ($estimated_data_size < 1000) {
                return 'SIMPLIFIED_FILTER'; // 中等数据集，使用简化过滤器
            } else {
                return 'PAGINATED_SYNC'; // 大数据集，分页同步
            }
        }

        if ($error_type === 'RATE_LIMIT_ERROR') {
            return 'THROTTLED_SYNC'; // 限流错误，使用节流同步
        }

        if ($error_type === 'NETWORK_ERROR') {
            return 'RETRY_WITH_BACKOFF'; // 网络错误，退避重试
        }

        if ($error_type === 'AUTH_ERROR') {
            return 'ABORT_SYNC'; // 认证错误，终止同步
        }

        return 'CONSERVATIVE_SYNC'; // 默认保守策略
    }

    /**
     * 执行智能降级同步
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $original_filter 原始过滤器
     * @param string $fallback_strategy 降级策略
     * @param array $context 上下文信息
     * @return array 同步结果
     */
    private function execute_fallback_sync(string $database_id, array $original_filter, string $fallback_strategy, array $context = []): array {
        \NTWP\Core\Logger::info_log(
            "执行降级同步: 策略={$fallback_strategy}, 数据库={$database_id}",
            'Fallback Sync'
        );

        switch ($fallback_strategy) {
            case 'FULL_SYNC':
                // 移除所有过滤器，进行全量同步
                return $this->get_database_pages($database_id, [], true);

            case 'SIMPLIFIED_FILTER':
                // 使用简化的过滤器
                $simplified_filter = $this->create_simplified_filter($original_filter);
                return $this->get_database_pages($database_id, $simplified_filter, true);

            case 'PAGINATED_SYNC':
                // 分页同步
                return $this->execute_paginated_sync($database_id, $original_filter);

            case 'THROTTLED_SYNC':
                // 节流同步
                return $this->execute_throttled_sync($database_id, $original_filter);

            case 'CONSERVATIVE_SYNC':
                // 保守同步（小批量）
                return $this->execute_conservative_sync($database_id, $original_filter);

            case 'ABORT_SYNC':
                // 终止同步
                throw new \Exception('同步被终止: 无法解决的错误');

            default:
                // 默认回退到无过滤器同步
                \NTWP\Core\Logger::warning_log(
                    "未知降级策略: {$fallback_strategy}, 使用默认全量同步",
                    'Fallback Sync'
                );
                return $this->get_database_pages($database_id, [], true);
        }
    }

    /**
     * 创建简化的过滤器
     *
     * @since 2.0.0-beta.1
     * @param array $original_filter 原始过滤器
     * @return array 简化的过滤器
     */
    private function create_simplified_filter(array $original_filter): array {
        // 移除复杂的时间戳过滤器，保留简单的属性过滤器
        if (isset($original_filter['and'])) {
            $simplified = [];
            foreach ($original_filter['and'] as $condition) {
                // 跳过时间戳相关的过滤条件
                if (!isset($condition['timestamp']) && !isset($condition['last_edited_time'])) {
                    $simplified[] = $condition;
                }
            }
            return count($simplified) > 0 ? ['and' => $simplified] : [];
        }

        // 如果不是复合过滤器，检查是否是时间戳过滤器
        if (isset($original_filter['timestamp']) || isset($original_filter['last_edited_time'])) {
            return []; // 移除时间戳过滤器
        }

        return $original_filter; // 保持原样
    }

    /**
     * 执行分页同步
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $filter 过滤器
     * @return array 同步结果
     */
    private function execute_paginated_sync(string $database_id, array $filter): array {
        $all_results = [];
        $page_size = 25; // 小批量分页
        $max_pages = 20; // 最多20页，防止无限循环
        $page_count = 0;

        \NTWP\Core\Logger::info_log(
            "开始分页同步: 页面大小={$page_size}, 最大页数={$max_pages}",
            'Paginated Sync'
        );

        $has_more = true;
        $start_cursor = null;

        while ($has_more && $page_count < $max_pages) {
            try {
                $endpoint = 'databases/' . $database_id . '/query';
                $data = ['page_size' => $page_size];

                if (!empty($filter)) {
                    $data['filter'] = $filter;
                }

                if ($start_cursor) {
                    $data['start_cursor'] = $start_cursor;
                }

                $response = $this->send_request($endpoint, 'POST', $data);

                if (isset($response['results'])) {
                    $all_results = array_merge($all_results, $response['results']);
                }

                $has_more = $response['has_more'] ?? false;
                $start_cursor = $response['next_cursor'] ?? null;
                $page_count++;

                // 页面间延迟，避免过于频繁的请求
                if ($has_more) {
                    usleep(500000); // 0.5秒延迟
                }

            } catch (\Exception $e) {
                \NTWP\Core\Logger::warning_log(
                    "分页同步第 {$page_count} 页失败: " . $e->getMessage(),
                    'Paginated Sync'
                );
                break; // 停止分页，返回已获取的结果
            }
        }

        \NTWP\Core\Logger::info_log(
            "分页同步完成: 总页数={$page_count}, 总结果={$all_results}",
            'Paginated Sync'
        );

        return $all_results;
    }

    /**
     * 执行节流同步
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $filter 过滤器
     * @return array 同步结果
     */
    private function execute_throttled_sync(string $database_id, array $filter): array {
        \NTWP\Core\Logger::info_log(
            "开始节流同步: 数据库={$database_id}",
            'Throttled Sync'
        );

        // 增加延迟，避免触发限流
        sleep(2);

        try {
            return $this->get_database_pages($database_id, $filter, true);
        } catch (\Exception $e) {
            // 如果仍然失败，进一步降级
            \NTWP\Core\Logger::warning_log(
                "节流同步失败，进一步降级: " . $e->getMessage(),
                'Throttled Sync'
            );
            return $this->execute_conservative_sync($database_id, []);
        }
    }

    /**
     * 执行保守同步
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $filter 过滤器
     * @return array 同步结果
     */
    private function execute_conservative_sync(string $database_id, array $filter): array {
        \NTWP\Core\Logger::info_log(
            "开始保守同步: 数据库={$database_id}",
            'Conservative Sync'
        );

        // 使用极小的分页大小
        return $this->execute_paginated_sync($database_id, $filter);
    }

    /**
     * 净化错误上下文，防止内存泄漏
     *
     * @since 2.0.0-beta.1
     * @param array $context 原始上下文
     * @return array 净化后的上下文
     */
    private function sanitize_error_context(array $context): array {
        $max_size = 1024; // 1KB限制
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                if (strlen($value) > $max_size) {
                    $sanitized[$key] = substr($value, 0, $max_size) . '...[truncated]';
                } else {
                    $sanitized[$key] = $value;
                }
            } elseif (is_array($value)) {
                // 递归净化数组
                $sanitized[$key] = $this->sanitize_error_context($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * 构造函数，初始化 API 客户端。
     *
     * @since    1.0.8
     * @param    string    $api_key    Notion API 密钥。
     * @throws   \InvalidArgumentException 当API密钥格式无效时
     */
    public function __construct(string $api_key) {
        // 验证API密钥格式
        if (class_exists('\\NTWP\\Core\\Security')) {
            $validation_result = \NTWP\Core\Security::validate_notion_api_key($api_key);
            if (!$validation_result['is_valid']) {
                throw new \InvalidArgumentException('Invalid API key: ' . $validation_result['error_message']);
            }
        }

        $this->api_key = $api_key;

        // 🚀 性能优化：单例模式初始化智能API合并器（避免重复初始化和内存泄漏）
        $options = get_option('notion_to_wordpress_options', []);
        $this->enable_api_merging = $options['enable_api_merging'] ?? true;

        // 定义性能模式常量以减少日志记录
        if (!defined('NOTION_PERFORMANCE_MODE')) {
            define('NOTION_PERFORMANCE_MODE', true);
        }

        if ($this->enable_api_merging && class_exists('\\NTWP\\Utils\\Smart_API_Merger')) {
            // 单例检查：避免重复创建API合并器实例
            static $global_api_merger = null;

            if ($global_api_merger === null) {
                $global_api_merger = new \NTWP\Utils\Smart_API_Merger();
            }

            $this->api_merger = $global_api_merger;
            $this->api_merger->set_notion_api($this);
        }
    }

    /**
     * 检查 API 密钥是否已设置。
     * 
     * @since    1.0.8
     * @return   bool     如果 API 密钥已设置，则返回 true，否则返回 false。
     */
    public function is_api_key_set(): bool {
        return !empty($this->api_key);
    }

    /**
     * 向 Notion API 发送请求（智能合并版本）
     *
     * @since    2.0.0-beta.1
     * @param    string    $endpoint    API 端点，不包含基础 URL。
     * @param    string    $method      HTTP 请求方法 (e.g., 'GET', 'POST')。
     * @param    array<string, mixed>     $data        要发送的请求数据。
     * @param    bool      $force_immediate 是否强制立即执行，跳过合并
     * @return   array<string, mixed>                  解码后的 JSON 响应。
     * @throws   Exception             如果 API 请求失败或返回错误。
     */
    public function send_request_with_merging(string $endpoint, string $method = 'GET', array $data = [], bool $force_immediate = false): array {
        // 如果启用了智能合并且不是强制立即执行且是GET请求
        if ($this->enable_api_merging && $this->api_merger && !$force_immediate && $method === 'GET') {
            // 🔧 修复：使用同步等待机制处理合并请求
            $request_id = uniqid('sync_req_', true);
            $result = null;
            $exception = null;
            $completed = false;

            // 创建同步回调
            $callback = function($response, $error) use (&$result, &$exception, &$completed) {
                if ($error) {
                    $exception = $error;
                } else {
                    $result = $response;
                }
                $completed = true;
            };

            // 将请求加入队列
            $immediate_result = $this->api_merger->queue_request($endpoint, $method, $data, $callback);

            // 如果立即返回了结果（批处理被触发）
            if ($immediate_result !== null) {
                if ($completed) {
                    if ($exception) {
                        throw $exception;
                    }
                    return $result;
                }
            }

            // 如果没有立即完成，等待一个短暂的时间让批处理完成
            $max_wait_time = 100; // 100ms最大等待时间
            $wait_interval = 5;   // 5ms检查间隔
            $waited = 0;

            while (!$completed && $waited < $max_wait_time) {
                usleep($wait_interval * 1000); // 转换为微秒
                $waited += $wait_interval;

                // 检查是否需要强制刷新
                if ($waited >= 50 && $this->api_merger->get_queue_size() > 0) {
                    $this->api_merger->force_flush();
                }
            }

            // 如果在等待时间内完成了
            if ($completed) {
                if ($exception) {
                    throw $exception;
                }
                if ($result !== null) {
                    return $result;
                }
            }

            // 如果仍未完成，强制刷新并回退
            if (!$completed) {
                $this->api_merger->force_flush();

                if (class_exists('\\NTWP\\Core\\Logger')) {
                    \NTWP\Core\Logger::debug_log(
                        "智能合并超时，回退到直接API调用: {$endpoint}",
                        'API Merger Fallback'
                    );
                }
            }
        }

        // 回退到直接发送请求
        return $this->send_request($endpoint, $method, $data);
    }

    /**
     * 向 Notion API 发送请求（智能缓存版本）
     * 这是一个通用的方法，用于处理所有类型的 API 请求。
     * @since    1.0.8
     * @param    string    $endpoint    API 端点，不包含基础 URL。
     * @param    string    $method      HTTP 请求方法 (e.g., 'GET', 'POST')。
     * @param    array<string, mixed>     $data        要发送的请求数据。
     * @return   array<string, mixed>                  解码后的 JSON 响应。
     * @throws   Exception             如果 API 请求失败或返回错误。
     */
    public function send_request(string $endpoint, string $method = 'GET', array $data = []): array {
        // 🚀 智能缓存：根据端点和同步模式决定缓存策略
        $cache_strategy = $this->get_cache_strategy($endpoint, $method);
        $use_smart_cache = false;
        $cache_key = '';

        if ($cache_strategy && $this->should_use_cache($endpoint, $cache_strategy)) {
            $use_smart_cache = true;
            $cache_key = $this->generate_smart_cache_key($endpoint, $data, $cache_strategy['type']);

            // 检查智能缓存
            if (class_exists('\\NTWP\\Utils\\Smart_Cache')) {
                $cached_response = \NTWP\Utils\Smart_Cache::get_tiered(
                    $cache_strategy['type'], 
                    $cache_key
                );
                
                if ($cached_response !== false) {
                    \NTWP\Core\Logger::debug_log(
                        "智能缓存命中: {$endpoint} (模式: {$this->sync_mode})",
                        'Smart Cache'
                    );
                    return $cached_response;
                }
            }
        }

        // 🚀 会话缓存：检查会话级缓存（仅用于减少重复调用）
        if ($method === 'GET' && class_exists('\\NTWP\\Utils\\Session_Cache')) {
            $cached_response = \NTWP\Utils\Session_Cache::get_cached_api_response($endpoint, $data);
            if ($cached_response !== null) {
                \NTWP\Core\Logger::debug_log(
                    "会话缓存命中: {$endpoint}",
                    'Session Cache'
                );
                return $cached_response;
            }
        }

        $url = $this->api_base . $endpoint;
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization'  => 'Bearer ' . $this->api_key,
                'Content-Type'   => 'application/json',
                'Notion-Version' => '2022-06-28'
            ],
            'timeout' => 45,  // 增加超时时间以处理SSL连接问题
            'sslverify' => false,  // 在Windows环境下禁用SSL验证以避免SSL_ERROR_SYSCALL错误
            'httpversion' => '1.1',  // 强制使用HTTP/1.1避免HTTP/2问题
            'user-agent' => 'Notion-to-WordPress/2.0.0-beta.1 (WordPress)',
            'redirection' => 5,
            'blocking' => true,
            // 添加额外的cURL选项来改善Windows SSL兼容性
            'curl' => [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TCP_NODELAY => true,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true
            ]
        ];

        if (!empty($data) && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }

        // 使用重试机制处理SSL连接问题
        $max_retries = 3;
        $retry_delay = 1; // 秒
        $last_error = null;

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_request($url, $args);

            if (!is_wp_error($response)) {
                break; // 成功，退出重试循环
            }

            $error_message = $response->get_error_message();
            $last_error = $response;

            // 检查是否为SSL相关错误
            if (strpos($error_message, 'SSL_ERROR_SYSCALL') !== false ||
                strpos($error_message, 'SSL_connect') !== false ||
                strpos($error_message, 'cURL error 35') !== false) {

                \NTWP\Core\Logger::debug_log(
                    "SSL连接错误 (尝试 {$attempt}/{$max_retries}): {$error_message}",
                    'SSL Retry'
                );

                if ($attempt < $max_retries) {
                    sleep($retry_delay);
                    $retry_delay *= 2; // 指数退避
                    continue;
                }
            } else {
                // 非SSL错误，直接退出
                break;
            }
        }

        $response = $last_error ?: $response;

        if (is_wp_error($response)) {
            throw new \Exception(__('API请求失败: ', 'notion-to-wordpress') . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            $error_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $error_body['message'] ?? wp_remote_retrieve_body($response);
            throw new \Exception(__('API错误 (', 'notion-to-wordpress') . $response_code . '): ' . $error_message);
        }

        $body = wp_remote_retrieve_body($response);
        $decoded_response = json_decode($body, true) ?: [];

        // 🚀 智能缓存：存储响应到适当的缓存层
        if ($use_smart_cache && $cache_strategy && class_exists('\\NTWP\\Utils\\Smart_Cache')) {
            // 根据同步模式调整TTL
            $ttl = $cache_strategy['ttl'];
            if ($this->sync_mode === 'manual') {
                $ttl = min($ttl, 60); // 手动同步最多缓存1分钟
            }

            \NTWP\Utils\Smart_Cache::set_tiered(
                $cache_strategy['type'],
                $cache_key,
                $decoded_response,
                [],
                $ttl
            );

            \NTWP\Core\Logger::debug_log(
                "智能缓存存储: {$endpoint} (TTL: {$ttl}s, 模式: {$this->sync_mode})",
                'Smart Cache'
            );
        }

        // 🚀 会话缓存：总是存储到会话缓存（用于减少同一会话内的重复调用）
        if ($method === 'GET' && class_exists('\\NTWP\\Utils\\Session_Cache')) {
            // 根据端点类型设置不同的会话缓存时间
            $session_ttl = 60; // 默认1分钟会话缓存
            if (strpos($endpoint, '/children') !== false) {
                $session_ttl = 120; // 子内容会话缓存2分钟
            } elseif (strpos($endpoint, '/databases/') !== false && strpos($endpoint, '/query') === false) {
                $session_ttl = 300; // 数据库结构会话缓存5分钟
            }

            \NTWP\Utils\Session_Cache::cache_api_response($endpoint, $data, $decoded_response, $session_ttl);
        }

        return $decoded_response;
    }

    /**
     * 获取指定数据库中的所有页面（处理分页）。
     *
     * @since    1.0.8
     * @param    string    $database_id    Notion 数据库的 ID。
     * @param    array<string, mixed>     $filter         应用于查询的筛选条件。
     * @param    bool      $with_details   是否获取页面详细信息（包括cover、icon等）。
     * @return   array<string, mixed>                     页面对象数组。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_database_pages(string $database_id, array $filter = [], bool $with_details = false): array {

        \NTWP\Core\Logger::debug_log(
            "获取数据库页面: {$database_id}, 详细信息: {$with_details}, 缓存模式: {$this->sync_mode}",
            'Database Pages'
        );

        // P1-07 API 响应缓存：未开启 Webhook 时，对数据库页面列表做 Transient 缓存，
        // 避免短时间内重复同步导致大量 Notion API 调用。Webhook 触发的同步会主动清除缓存。
        $cache_enabled = $this->is_api_cache_enabled();
        if ( $cache_enabled && empty( $filter ) && ! $with_details ) {
            $cache_key = 'ntwp_api_dbpages_' . md5( $database_id );
            $cached = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                \NTWP\Core\Logger::debug_log(
                    sprintf( 'API 缓存命中，数据库 %s 返回 %d 个页面（跳过 API 调用）', $database_id, count( $cached ) ),
                    'API Cache'
                );
                return $cached;
            }
        }

        $all_results = [];
        $has_more = true;
        $start_cursor = null;

        while ($has_more) {
            $endpoint = 'databases/' . $database_id . '/query';
            // 智能分页大小优化：根据数据量动态调整
            $options = get_option('notion_to_wordpress_options', []);
            $base_page_size = $options['api_page_size'] ?? 100;

            // 动态优化：首次请求使用较大分页，后续根据结果调整
            $page_size = empty($all_results) ? min(100, $base_page_size) : min(100, $base_page_size * 1.5);

            $data = [
                'page_size' => $page_size
            ];

            if ($this->is_valid_filter($filter)) {
                $data['filter'] = $filter;
            }

            if ($start_cursor) {
                $data['start_cursor'] = $start_cursor;
            }

            $response = $this->send_request($endpoint, 'POST', $data);

            if (isset($response['results'])) {
                $all_results = array_merge($all_results, $response['results']);
                \NTWP\Core\Logger::debug_log(
                    '获取数据库页面批次: ' . count($response['results']) . ', 总计: ' . count($all_results),
                    'Database Pages'
                );
            }

            $has_more = $response['has_more'] ?? false;
            $start_cursor = $response['next_cursor'] ?? null;
        }

        // 如果需要详细信息，批量获取页面详情
        if ($with_details && !empty($all_results)) {
            $all_results = $this->enrich_pages_with_details($all_results);
        }

        \NTWP\Core\Logger::debug_log(
            '数据库页面获取完成，总数: ' . count($all_results) . ', 详细信息: ' . ($with_details ? '是' : '否'),
            'Database Pages'
        );

        // 写入 API 缓存（仅在无 filter/无 details 时缓存，TTL 可配置）
        if ( $cache_enabled && empty( $filter ) && ! $with_details && ! empty( $all_results ) ) {
            $cache_key = 'ntwp_api_dbpages_' . md5( $database_id );
            $ttl = $this->get_api_cache_ttl();
            set_transient( $cache_key, $all_results, $ttl );
            \NTWP\Core\Logger::debug_log(
                sprintf( 'API 缓存已写入，数据库 %s，%d 个页面，TTL %d 秒', $database_id, count( $all_results ), $ttl ),
                'API Cache'
            );
        }

        return $all_results;
    }

    /**
     * 检查 API 响应缓存是否启用。
     *
     * 启用条件：选项中 api_cache_enabled 为真，且未启用 Webhook
     * （Webhook 模式下由事件驱动同步，缓存会导致延迟）。
     *
     * @since 2.0.0
     * @return bool
     */
    public function is_api_cache_enabled(): bool {
        $options = get_option( 'notion_to_wordpress_options', [] );
        $cache_enabled = $options['api_cache_enabled'] ?? false;
        $webhook_enabled = $options['webhook_enabled'] ?? 0;

        // Webhook 启用时不缓存（事件驱动已足够实时，缓存反而引入延迟）
        return ! empty( $cache_enabled ) && empty( $webhook_enabled );
    }

    /**
     * 获取 API 缓存 TTL（秒），可配置 1-60 分钟。
     *
     * @since 2.0.0
     * @return int TTL 秒数，默认 300（5 分钟）
     */
    public function get_api_cache_ttl(): int {
        $options = get_option( 'notion_to_wordpress_options', [] );
        $ttl_minutes = (int) ( $options['api_cache_ttl_minutes'] ?? 5 );
        $ttl_minutes = max( 1, min( 60, $ttl_minutes ) ); // 限制 1-60 分钟

        return $ttl_minutes * MINUTE_IN_SECONDS;
    }

    /**
     * 清除指定数据库的 API 响应缓存。
     *
     * Webhook 触发同步时应调用此方法，确保下次同步获取最新数据。
     *
     * @since 2.0.0
     * @param string $database_id Notion 数据库 ID
     */
    public function clear_api_cache( string $database_id = '' ): void {
        if ( empty( $database_id ) ) {
            $options = get_option( 'notion_to_wordpress_options', [] );
            $database_id = $options['notion_database_id'] ?? '';
        }
        if ( ! empty( $database_id ) ) {
            delete_transient( 'ntwp_api_dbpages_' . md5( $database_id ) );
            \NTWP\Core\Logger::debug_log( "API 缓存已清除，数据库: {$database_id}", 'API Cache' );
        }
    }

    /**
     * 递归获取一个块的所有子块内容，优化版本。
     *
     * @since    1.0.8
     * @param    string    $block_id    块或页面的 ID。
     * @param    int       $depth       当前递归深度，用于限制递归层数。
     * @param    int       $max_depth   最大递归深度，默认为5层。
     * @return   array<string, mixed>   子块对象数组。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_page_content(string $block_id, int $depth = 0, int $max_depth = 5): array {
        // 🚀 性能优化：使用批量并发获取替代递归
        if ($depth === 0) {
            return $this->get_page_content_batch_optimized($block_id, $max_depth);
        }

        // 检查递归深度限制
        if ($depth >= $max_depth) {
            return [];
        }

        $blocks = $this->get_block_children($block_id);

        foreach ($blocks as $i => $block) {
            if ($block['has_children']) {
                // 跳过已知会导致404错误的块类型
                if (isset($block['type']) && in_array($block['type'], [
                    'child_database',
                    'child_page',
                    'link_preview',
                    'unsupported'
                ])) {
                    continue;
                }

                try {
                    $blocks[$i]['children'] = $this->get_page_content($block['id'], $depth + 1, $max_depth);
                } catch (\Exception $e) {
                    // 快速跳过404错误，不记录详细日志
                    if (strpos($e->getMessage(), '404') !== false) {
                        continue;
                    }
                    // 对于其他类型的错误，重新抛出
                    throw $e;
                }
            }
        }

        return $blocks;
    }

    /**
     * 🚀 批量并发获取页面内容（高性能版本）
     *
     * 使用广度优先遍历 + 批量API调用，替代递归方式
     *
     * @param string $page_id 页面ID
     * @param int $max_depth 最大深度
     * @return array 完整的内容块数组
     */
    private function get_page_content_batch_optimized(string $page_id, int $max_depth = 5): array {
        $all_blocks = [];
        $blocks_to_process = [$page_id => 0]; // block_id => depth
        $processed_blocks = [];
        $start_time = microtime(true);
        $timeout_limit = 18; // 18秒超时保护

        while (!empty($blocks_to_process) && count($processed_blocks) < 1000) { // 安全限制
            // 🚀 超时保护：避免深层递归导致超时
            if ((microtime(true) - $start_time) > $timeout_limit) {
                \NTWP\Core\Logger::warning_log(
                    "批量内容获取超时保护触发，已处理 " . count($processed_blocks) . " 个块",
                    'Timeout Protection'
                );
                break;
            }
            $current_batch = [];
            $current_depths = [];

            // 收集当前批次要处理的块
            foreach ($blocks_to_process as $block_id => $depth) {
                if ($depth < $max_depth && !isset($processed_blocks[$block_id])) {
                    $current_batch[] = $block_id;
                    $current_depths[$block_id] = $depth;
                    $processed_blocks[$block_id] = true;
                }
            }

            if (empty($current_batch)) {
                break;
            }

            // 🚀 批量获取所有块的子内容
            try {
                $batch_results = $this->batch_get_block_children_optimized($current_batch);

                foreach ($batch_results as $block_id => $children) {
                    $depth = $current_depths[$block_id];

                    if ($block_id === $page_id) {
                        // 根页面的内容
                        $all_blocks = $children;
                    } else {
                        // 找到父块并添加子内容
                        $this->attach_children_to_parent($all_blocks, $block_id, $children);
                    }

                    // 收集下一层需要处理的块
                    foreach ($children as $child) {
                        if ($child['has_children'] && !isset($processed_blocks[$child['id']])) {
                            // 跳过已知问题块类型
                            if (!isset($child['type']) || !in_array($child['type'], [
                                'child_database', 'child_page', 'link_preview', 'unsupported'
                            ])) {
                                $blocks_to_process[$child['id']] = $depth + 1;
                            }
                        }
                    }
                }

            } catch (\Exception $e) {
                \NTWP\Core\Logger::warning_log(
                    '批量获取块内容失败: ' . $e->getMessage(),
                    'Batch Content'
                );
                break;
            }

            // 清理已处理的块
            foreach ($current_batch as $block_id) {
                unset($blocks_to_process[$block_id]);
            }
        }

        return $all_blocks;
    }

    /**
     * 将子内容附加到父块
     *
     * @param array &$blocks 块数组（引用传递）
     * @param string $parent_id 父块ID
     * @param array $children 子块数组
     */
    private function attach_children_to_parent(array &$blocks, string $parent_id, array $children): void {
        foreach ($blocks as &$block) {
            if ($block['id'] === $parent_id) {
                $block['children'] = $children;
                return;
            }

            if (isset($block['children']) && is_array($block['children'])) {
                $this->attach_children_to_parent($block['children'], $parent_id, $children);
            }
        }
    }

    /**
     * 🚀 批量获取多个块的子内容（优化版本）
     *
     * @param array $block_ids 块ID数组
     * @return array 块ID => 子内容数组的映射
     */
    private function batch_get_block_children_optimized(array $block_ids): array {
        if (empty($block_ids)) {
            return [];
        }

        // 🚀 智能分批：根据测试结果，每批5个以避免超时
        $batch_size = 5;
        $all_results = [];
        $batches = array_chunk($block_ids, $batch_size);

        foreach ($batches as $batch_index => $batch) {
            try {
                \NTWP\Core\Logger::debug_log(
                    "处理批次 " . ($batch_index + 1) . "/" . count($batches) . "，包含 " . count($batch) . " 个块",
                    'Batch Optimization'
                );

                $batch_results = $this->batch_get_block_children($batch);
                $all_results = array_merge($all_results, $batch_results);

                // 批次间短暂延迟，避免API限制
                if ($batch_index < count($batches) - 1) {
                    usleep(200000); // 0.2秒延迟
                }

            } catch (\Exception $e) {
                \NTWP\Core\Logger::warning_log(
                    "批次 " . ($batch_index + 1) . " 处理失败，回退到单个处理: " . $e->getMessage(),
                    'Batch Fallback'
                );

                // 回退到单个处理
                foreach ($batch as $block_id) {
                    try {
                        $all_results[$block_id] = $this->get_block_children($block_id);
                    } catch (\Exception $e) {
                        $all_results[$block_id] = [];
                    }
                }
            }
        }

        return $all_results;
    }

    /**
     * 并发获取块子内容
     *
     * @param array $block_ids 块ID数组
     * @return array 结果数组
     */
    private function concurrent_get_block_children(array $block_ids): array {
        $requests = [];
        $results = [];

        // 准备并发请求
        foreach ($block_ids as $block_id) {
            $requests[] = [
                'url' => $this->api_base . "blocks/{$block_id}/children",
                'method' => 'GET',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                    'Notion-Version' => '2022-06-28'
                ],
                'block_id' => $block_id
            ];
        }

        // 发送并发请求
        try {
            $responses = $this->batch_send_requests($requests);

            foreach ($responses as $i => $response) {
                $block_id = $requests[$i]['block_id'];

                if (isset($response['results'])) {
                    $results[$block_id] = $response['results'];
                } else {
                    $results[$block_id] = [];
                }
            }

        } catch (\Exception $e) {
            // 并发失败时回退到串行处理
            \NTWP\Core\Logger::warning_log(
                '并发获取失败，回退到串行: ' . $e->getMessage(),
                'Concurrent Fallback'
            );

            foreach ($block_ids as $block_id) {
                try {
                    $results[$block_id] = $this->get_block_children($block_id);
                } catch (\Exception $e) {
                    $results[$block_id] = [];
                }
            }
        }

        return $results;
    }

    /**
     * 获取一个块的直接子块（处理分页）。
     *
     * @since 1.0.8
     * @param string $block_id 块的 ID。
     * @return array<string, mixed> 子块对象数组。
     * @throws Exception 如果 API 请求失败。
     */
    public function get_block_children(string $block_id): array {
        $all_results = [];
        $has_more = true;
        $start_cursor = null;

        while ($has_more) {
            // 使用最大页面大小以减少API调用次数
            $endpoint = 'blocks/' . $block_id . '/children?page_size=100';
            if ($start_cursor) {
                $endpoint .= '&start_cursor=' . $start_cursor;
            }

            try {
                // 🚀 性能优化：使用智能合并发送请求
                $response = $this->send_request_with_merging($endpoint, 'GET');

                if (isset($response['results'])) {
                    $all_results = array_merge($all_results, $response['results']);
                }

                $has_more = $response['has_more'] ?? false;
                $start_cursor = $response['next_cursor'] ?? null;

            } catch (\Exception $e) {
                // 快速跳过404错误，不记录详细日志
                if (strpos($e->getMessage(), '404') !== false) {
                    break;
                }

                // 对于其他错误，记录并重新抛出
                \NTWP\Core\Logger::error_log(
                    'get_block_children异常: ' . $e->getMessage(),
                    'API Error'
                );
                throw $e;
            }
        }

        return $all_results;
    }

    /**
     * 获取数据库的元数据。
     *
     * @since 1.0.8
     * @param string $database_id Notion 数据库的 ID。
     * @return array<string, mixed> 数据库对象。
     * @throws Exception 如果 API 请求失败。
     */
    public function get_database(string $database_id): array
    {
        $endpoint = 'databases/' . $database_id;
        return $this->send_request($endpoint);
    }

    /**
     * 获取页面的元数据 - 智能缓存策略（根据同步模式动态缓存）
     *
     * @since    1.0.8
     * @param    string    $page_id    Notion 页面的 ID。
     * @return   array<string, mixed>                 页面对象。
     * @throws   Exception             如果 API 请求失败。
     */
    public function get_page_metadata(string $page_id): array {
        $endpoint = 'pages/' . $page_id;
        $result = $this->send_request($endpoint);

        \NTWP\Core\Logger::debug_log(
            "获取页面元数据: {$page_id} (缓存模式: {$this->sync_mode})",
            'Page Metadata'
        );

        return $result;
    }

    /**
     * 检查与 Notion API 的连接是否正常。
     *
     * @since    1.0.8
     * @return   bool       如果连接成功，则返回 true，否则返回 false。
     */
    public function check_connection(): bool {
        try {
            $endpoint = 'users/me';
            $this->send_request($endpoint);
            return true;
        } catch (\Exception $e) {
            // 可以在这里记录具体的错误信息 $e->getMessage()
            return false;
        }
    }

    /**
     * 测试 API 连接并返回详细的错误信息。
     *
     * @since    1.0.8
     * @param    string    $database_id    要测试的数据库 ID（可选）。
     * @return   true|WP_Error           如果成功，则返回 true；如果失败，则返回 WP_Error 对象。
     */
    public function test_connection(string $database_id = '') {
        try {
            // 1. 检查API密钥本身是否有效
            $this->send_request('users/me');

            // 2. 如果提供了数据库ID，检查数据库是否可访问
            if (!empty($database_id)) {
                $this->get_database($database_id);
            }

            return true;
        } catch (\Exception $e) {
            return new WP_Error('connection_failed', __('连接测试失败: ', 'notion-to-wordpress') . $e->getMessage());
        }
    }

    /**
     * 获取单个页面对象 - 智能缓存策略（根据同步模式动态缓存）
     *
     * @param string $page_id 页面ID
     * @return array<string, mixed>
     * @throws Exception
     */
    public function get_page(string $page_id): array {
        $endpoint = 'pages/' . $page_id;
        $result = $this->send_request($endpoint);

        \NTWP\Core\Logger::debug_log(
            "获取页面数据: {$page_id} (缓存模式: {$this->sync_mode})",
            'Page Data'
        );

        return $result;
    }

    /**
     * 安全获取数据库信息，支持优雅降级 - 智能缓存策略
     *
     * @since 1.0.9
     * @param string $database_id 数据库ID
     * @return array<string, mixed> 数据库信息数组，失败时返回空数组
     */
    public function get_database_info(string $database_id): array {
            try {
                $database_info = $this->get_database($database_id);

                \NTWP\Core\Logger::debug_log(
                    "数据库信息获取成功: {$database_id} (缓存模式: {$this->sync_mode})",
                    'Database Info'
                );

                return $database_info;
            } catch (\Exception $e) {
                \NTWP\Core\Logger::debug_log(
                    '数据库信息获取失败: ' . $e->getMessage(),
                    'Database Info'
                );
                return [];
            }
    }

    /**
     * 获取页面详细信息，包括cover、icon等完整属性 - 智能缓存策略
     *
     * @since 1.1.1
     * @param string $page_id 页面ID
     * @return array<string, mixed> 页面详细信息数组，失败时返回空数组
     */
    public function get_page_details(string $page_id): array {
            try {
                $page_data = $this->get_page($page_id);

            \NTWP\Core\Logger::debug_log(
                "获取页面详情: {$page_id} (缓存模式: {$this->sync_mode})",
                'Page Details'
            );

                \NTWP\Core\Logger::debug_log(
                    '页面详情获取成功: ' . $page_id . ', 包含cover: ' . (isset($page_data['cover']) ? '是' : '否') . ', 包含icon: ' . (isset($page_data['icon']) ? '是' : '否'),
                    'Page Details'
                );

                return $page_data;
            } catch (\Exception $e) {
                \NTWP\Core\Logger::error_log(
                    '页面详情获取失败: ' . $page_id . ', 错误: ' . $e->getMessage(),
                    'Page Details'
                );
                return [];
            }
    }

    /**
     * 批量为页面添加详细信息 - 优化版本
     *
     * @since 1.1.1
     * @param array<string, mixed> $pages 页面数组
     * @return array<string, mixed> 包含详细信息的页面数组
     */
    private function enrich_pages_with_details(array $pages): array {
        // 对于大量页面，跳过详细信息获取以提高性能
        if (count($pages) > 20) {
            \NTWP\Core\Logger::debug_log(
                '页面数量过多(' . count($pages) . ')，跳过详细信息获取以提高性能',
                'Performance Optimization'
            );
            return $pages;
        }

        $enriched_pages = [];
        $failed_count = 0;
        $max_failures = 5; // 最多允许5次失败

        foreach ($pages as $page) {
            $page_id = $page['id'] ?? '';
            if (empty($page_id)) {
                $enriched_pages[] = $page;
                continue;
            }

            // 如果失败次数过多，跳过剩余页面的详细信息获取
            if ($failed_count >= $max_failures) {
                $enriched_pages[] = $page;
                continue;
            }

            try {
                // 获取页面详细信息
                $page_details = $this->get_page_details($page_id);

                if (!empty($page_details)) {
                    // 合并基本信息和详细信息
                    $enriched_page = array_merge($page, [
                        'cover' => $page_details['cover'] ?? null,
                        'icon' => $page_details['icon'] ?? null,
                        'url' => $page_details['url'] ?? $page['url'] ?? null,
                    ]);
                    $enriched_pages[] = $enriched_page;
                } else {
                    $enriched_pages[] = $page;
                    $failed_count++;
                }
            } catch (\Exception $e) {
                // 快速跳过失败的页面，不记录详细日志
                $enriched_pages[] = $page;
                $failed_count++;
            }
        }

        return $enriched_pages;
    }

    // ========================================
    // 批量并发请求方法
    // ========================================

    /**
     * 批量发送API请求（并发处理）
     *
     * @since    1.9.0-beta.1
     * @param    array     $endpoints    API端点数组
     * @param    string    $method       HTTP方法
     * @param    array     $data_array   请求数据数组（可选）
     * @param    int       $max_retries  最大重试次数
     * @param    int       $base_delay   基础延迟时间（毫秒）
     * @return   array                   响应结果数组
     * @throws   Exception               如果批量请求失败
     */
    public function batch_send_requests(array $endpoints, string $method = 'GET', array $data_array = [], int $max_retries = 2, int $base_delay = 1000): array {
        if (empty($endpoints)) {
            return [];
        }

        $start_time = microtime(true);

        // 记录批量API请求开始
        \NTWP\Core\Logger::debug_log(
            sprintf('开始批量API请求: %d个端点，方法: %s', count($endpoints), $method),
            'Batch API'
        );

        try {
            // 优先使用动态并发管理器
            if (class_exists('\\NTWP\\Core\\Dynamic_Concurrency_Manager')) {
                $concurrency_manager = new \NTWP\Core\Dynamic_Concurrency_Manager();
                $concurrent_requests = $concurrency_manager->calculate_optimal_concurrency();

                if (class_exists('\\NTWP\\Core\\Logger')) {
                    \NTWP\Core\Logger::debug_log(
                        "动态并发数: {$concurrent_requests} (API请求)",
                        'API Concurrent'
                    );
                }
            } elseif (class_exists('\\NTWP\\Core\\Memory_Manager')) {
                $concurrent_requests = \NTWP\Core\Memory_Manager::get_concurrent_limit();

                if (class_exists('\\NTWP\\Core\\Logger')) {
                    \NTWP\Core\Logger::debug_log(
                        "自适应并发数: {$concurrent_requests} (API请求)",
                        'API Concurrent'
                    );
                }
            } else {
                $options = get_option('notion_to_wordpress_options', []);
                $concurrent_requests = $options['concurrent_requests'] ?? 5;
            }

            // 创建并发网络管理器
            $manager = new \NTWP\Utils\Concurrent_Network_Manager($concurrent_requests);

            // 添加所有请求到队列
            foreach ($endpoints as $index => $endpoint) {
                $url = $this->api_base . $endpoint;
                $data = $data_array[$index] ?? [];

                // 根据并发数动态调整超时时间
                $timeout = 30; // 默认30秒
                if ($concurrent_requests > 8) {
                    $timeout = 45; // 高并发时增加超时时间
                } elseif ($concurrent_requests <= 3) {
                    $timeout = 20; // 低并发时减少超时时间
                }

                $args = [
                    'method'  => $method,
                    'headers' => [
                        'Authorization'  => 'Bearer ' . $this->api_key,
                        'Content-Type'   => 'application/json',
                        'Notion-Version' => '2022-06-28'
                    ],
                    'timeout' => $timeout
                ];

                if (!empty($data) && $method !== 'GET') {
                    $args['body'] = json_encode($data);
                }

                $manager->add_request($url, $args);
            }

            // 执行并发请求（带重试）
            $responses = $manager->execute_with_retry($max_retries, $base_delay);

            // 处理响应结果
            $results = [];
            $success_count = 0;
            $error_count = 0;

            foreach ($responses as $index => $response) {
                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message();
                    $results[$index] = new \Exception("批量请求失败 (#{$index}): " . $error_message);
                    $error_count++;

                    \NTWP\Core\Logger::error_log(
                        "批量请求失败 (#{$index}): {$error_message}",
                        'Batch API'
                    );
                } else {
                    $response_code = $response['response']['code'];

                    if ($response_code < 200 || $response_code >= 300) {
                        $error_body = json_decode($response['body'], true);
                        $error_message = $error_body['message'] ?? $response['body'];
                        $results[$index] = new \Exception("API错误 (#{$index}, {$response_code}): " . $error_message);
                        $error_count++;

                        \NTWP\Core\Logger::error_log(
                            "批量请求API错误 (#{$index}): {$response_code} - {$error_message}",
                            'Batch API'
                        );
                    } else {
                        $body = json_decode($response['body'], true) ?: [];
                        $results[$index] = $body;
                        $success_count++;
                    }
                }
            }

            $execution_time = microtime(true) - $start_time;

            \NTWP\Core\Logger::debug_log(
                sprintf(
                    '批量API请求完成: 成功 %d, 失败 %d, 耗时 %.2f秒',
                    $success_count,
                    $error_count,
                    $execution_time
                ),
                'Batch API'
            );

            return $results;

        } catch (\Exception $e) {
            \NTWP\Core\Logger::error_log(
                '批量API请求异常: ' . $e->getMessage(),
                'Batch API'
            );
            throw $e;
        }
    }

    /**
     * 批量获取页面详情
     *
     * @since    1.9.0-beta.1
     * @param    array    $page_ids    页面ID数组
     * @return   array                 页面详情数组，键为页面ID
     */
    public function batch_get_pages(array $page_ids): array {
        if (empty($page_ids)) {
            return [];
        }

        // 禁用缓存，直接进行API请求以确保数据实时性
        \NTWP\Core\Logger::debug_log(
            sprintf('批量获取页面（无缓存）: 总计 %d', count($page_ids)),
            'Batch Pages'
        );

        // 构建批量请求端点
        $endpoints = [];
        foreach ($page_ids as $page_id) {
            $endpoints[] = 'pages/' . $page_id;
        }

        try {
            // 执行批量请求
            $responses = $this->batch_send_requests($endpoints);

            // 处理响应（无缓存）
            $fetched_pages = [];
            foreach ($responses as $index => $response) {
                $page_id = $page_ids[$index];

                if ($response instanceof \Exception) {
                    \NTWP\Core\Logger::error_log(
                        "获取页面失败 ({$page_id}): " . $response->getMessage(),
                        'Batch Pages'
                    );
                    continue;
                }

                $fetched_pages[$page_id] = $response;
            }

            return $fetched_pages;

        } catch (\Exception $e) {
            \NTWP\Core\Logger::error_log(
                '批量获取页面异常: ' . $e->getMessage(),
                'Batch Pages'
            );

            return [];
        }
    }

    /**
     * 批量获取块内容
     *
     * @since    1.9.0-beta.1
     * @param    array    $block_ids    块ID数组
     * @return   array                  块内容数组，键为块ID
     */
    public function batch_get_block_children(array $block_ids): array {
        if (empty($block_ids)) {
            return [];
        }

        \NTWP\Core\Logger::debug_log(
            sprintf('批量获取块内容: %d个块', count($block_ids)),
            'Batch Blocks'
        );

        // 构建批量请求端点
        $endpoints = [];
        foreach ($block_ids as $block_id) {
            $endpoints[] = 'blocks/' . $block_id . '/children';
        }

        try {
            // 执行批量请求
            $responses = $this->batch_send_requests($endpoints);

            // 处理响应
            $block_contents = [];
            foreach ($responses as $index => $response) {
                $block_id = $block_ids[$index];

                if ($response instanceof \Exception) {
                    \NTWP\Core\Logger::error_log(
                        "获取块内容失败 ({$block_id}): " . $response->getMessage(),
                        'Batch Blocks'
                    );
                    $block_contents[$block_id] = [];
                    continue;
                }

                $block_contents[$block_id] = $response['results'] ?? [];
            }

            return $block_contents;

        } catch (\Exception $e) {
            \NTWP\Core\Logger::error_log(
                '批量获取块内容异常: ' . $e->getMessage(),
                'Batch Blocks'
            );

            // 返回空数组
            $empty_results = [];
            foreach ($block_ids as $block_id) {
                $empty_results[$block_id] = [];
            }
            return $empty_results;
        }
    }

    /**
     * 批量查询数据库
     *
     * @since    1.9.0-beta.1   
     * @param    array    $database_ids    数据库ID数组
     * @param    array    $filters         筛选条件数组（可选）
     * @return   array                     数据库查询结果数组，键为数据库ID
     */
    public function batch_query_databases(array $database_ids, array $filters = []): array {
        if (empty($database_ids)) {
            return [];
        }

        \NTWP\Core\Logger::debug_log(
            sprintf('批量查询数据库: %d个数据库', count($database_ids)),
            'Batch Databases'
        );

        // 构建批量请求端点和数据
        $endpoints = [];
        $data_array = [];

        foreach ($database_ids as $index => $database_id) {
            $endpoints[] = 'databases/' . $database_id . '/query';
            $data_array[] = $filters[$index] ?? [];
        }

        try {
            // 执行批量POST请求
            $responses = $this->batch_send_requests($endpoints, 'POST', $data_array);

            // 处理响应
            $database_results = [];
            foreach ($responses as $index => $response) {
                $database_id = $database_ids[$index];

                if ($response instanceof \Exception) {
                    \NTWP\Core\Logger::error_log(
                        "查询数据库失败 ({$database_id}): " . $response->getMessage(),
                        'Batch Databases'
                    );
                    $database_results[$database_id] = [];
                    continue;
                }

                $database_results[$database_id] = $response['results'] ?? [];
            }

            return $database_results;

        } catch (\Exception $e) {
            \NTWP\Core\Logger::error_log(
                '批量查询数据库异常: ' . $e->getMessage(),
                'Batch Databases'
            );

            // 返回空数组
            $empty_results = [];
            foreach ($database_ids as $database_id) {
                $empty_results[$database_id] = [];
            }
            return $empty_results;
        }
    }

    /**
     * 批量获取数据库信息
     *
     * @since    1.9.0-beta.1
     * @param    array    $database_ids    数据库ID数组
     * @return   array                     数据库信息数组，键为数据库ID
     */
    public function batch_get_databases(array $database_ids): array {
        if (empty($database_ids)) {
            return [];
        }

        // 禁用缓存，直接进行API请求以确保数据实时性
        \NTWP\Core\Logger::debug_log(
            sprintf('批量获取数据库信息（无缓存）: 总计 %d', count($database_ids)),
            'Batch Database Info'
        );

        // 构建批量请求端点
        $endpoints = [];
        foreach ($database_ids as $database_id) {
            $endpoints[] = 'databases/' . $database_id;
        }

        try {
            // 执行批量请求
            $responses = $this->batch_send_requests($endpoints);

            // 处理响应（无缓存）
            $fetched_databases = [];
            foreach ($responses as $index => $response) {
                $database_id = $database_ids[$index];

                if ($response instanceof \Exception) {
                    \NTWP\Core\Logger::error_log(
                        "获取数据库信息失败 ({$database_id}): " . $response->getMessage(),
                        'Batch Database Info'
                    );
                    continue;
                }

                $fetched_databases[$database_id] = $response;
            }

            return $fetched_databases;

        } catch (\Exception $e) {
            \NTWP\Core\Logger::error_log(
                '批量获取数据库信息异常: ' . $e->getMessage(),
                'Batch Database Info'
            );

            // 返回空数组
            return [];
        }
    }

    /**
     * 智能增量获取数据库页面（增强错误处理版本）
     *
     * 在API层面过滤变更内容，避免拉取全量数据后本地过滤的带宽浪费
     * 集成增强的错误处理、重试机制和智能降级策略
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param string $last_sync_time 最后同步时间（ISO 8601格式）
     * @param array $additional_filters 额外的过滤条件
     * @param bool $with_details 是否获取详细信息
     * @return \NTWP\Utils\API_Result 增强的结果对象
     */
    public function smart_incremental_fetch_enhanced(string $database_id, string $last_sync_time = '', array $additional_filters = [], bool $with_details = false): \NTWP\Utils\API_Result {
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);

        if (class_exists('\\NTWP\\Core\\Performance_Monitor')) {
            \NTWP\Core\Performance_Monitor::start_timer('smart_incremental_fetch_enhanced');
        }

        // 构建时间戳过滤器
        $time_filter = [];
        if (!empty($last_sync_time)) {
            $formatted_time = $this->format_timestamp_for_api($last_sync_time);

            if (!empty($formatted_time)) {
                $time_filter = [
                    'timestamp' => 'last_edited_time',
                    'last_edited_time' => [
                        'after' => $formatted_time
                    ]
                ];
            }
        }

        // 构建复合过滤器
        $filters = [];
        if (!empty($time_filter)) {
            $filters[] = $time_filter;
        }

        foreach ($additional_filters as $filter) {
            $filters[] = $filter;
        }

        $final_filter = [];
        if (count($filters) === 1) {
            $final_filter = $filters[0];
        } elseif (count($filters) > 1) {
            $final_filter = ['and' => $filters];
        }

        // 验证过滤器有效性
        if (!$this->is_valid_filter($final_filter)) {
            \NTWP\Core\Logger::warning_log(
                "API层增量过滤器无效，使用全量查询: 数据库={$database_id}",
                'Enhanced Incremental Fetch'
            );
            $final_filter = [];
        }

        // 预估数据量（用于降级策略决策）
        $estimated_data_size = $this->estimate_database_size($database_id);

        // 使用增强的重试机制执行API调用
        try {
            $operation = function() use ($database_id, $final_filter, $with_details) {
                return $this->is_valid_filter($final_filter) 
                    ? $this->get_database_pages($database_id, $final_filter, $with_details)
                    : $this->get_database_pages($database_id, [], $with_details);
            };

            $filtered_pages = $this->retry_with_backoff(
                $operation,
                "增量获取数据库页面 ({$database_id})",
                [
                    'database_id' => $database_id,
                    'filter' => $final_filter,
                    'estimated_size' => $estimated_data_size
                ]
            );

            // 记录性能统计
            $processing_time = round((microtime(true) - $start_time) * 1000);
            $memory_used = memory_get_usage(true) - $start_memory;

            if (class_exists('\\NTWP\\Core\\Performance_Monitor')) {
                \NTWP\Core\Performance_Monitor::end_timer('smart_incremental_fetch_enhanced');
                \NTWP\Core\Performance_Monitor::record_custom_metric('enhanced_fetch_time', $processing_time);
                \NTWP\Core\Performance_Monitor::record_custom_metric('enhanced_fetch_count', count($filtered_pages));
                \NTWP\Core\Performance_Monitor::record_custom_metric('enhanced_fetch_memory', $memory_used);
            }

            $result = \NTWP\Utils\API_Result::success(
                $filtered_pages,
                false,
                null,
                0,
                $processing_time,
                [
                    'database_id' => $database_id,
                    'filter_used' => !empty($final_filter),
                    'page_count' => count($filtered_pages),
                    'memory_used' => $this->format_bytes($memory_used)
                ]
            );

            $result->log_result("增量获取数据库页面 ({$database_id})", 'Enhanced Incremental Fetch');

            return $result;

        } catch (\Exception $e) {
            $error_type = $this->classify_api_error_precise($e);
            $processing_time = round((microtime(true) - $start_time) * 1000);

            \NTWP\Core\Logger::error_log(
                "增量获取失败: {$error_type} - {$e->getMessage()}",
                'Enhanced Incremental Fetch'
            );

            // 尝试智能降级
            try {
                $fallback_strategy = $this->get_fallback_strategy($error_type, $estimated_data_size, [
                    'database_id' => $database_id,
                    'original_filter' => $final_filter
                ]);

                \NTWP\Core\Logger::info_log(
                    "执行降级策略: {$fallback_strategy}",
                    'Enhanced Incremental Fetch'
                );

                $fallback_pages = $this->execute_fallback_sync($database_id, $final_filter, $fallback_strategy);

                $result = \NTWP\Utils\API_Result::fallback_success(
                    $fallback_pages,
                    $fallback_strategy,
                    1, // 至少经历了一次重试
                    $processing_time,
                    [
                        'database_id' => $database_id,
                        'original_error' => $error_type,
                        'fallback_strategy' => $fallback_strategy,
                        'page_count' => count($fallback_pages)
                    ]
                );

                $result->log_result("降级成功获取数据库页面 ({$database_id})", 'Enhanced Incremental Fetch');

                return $result;

            } catch (\Exception $fallback_exception) {
                $context = $this->sanitize_error_context([
                    'database_id' => $database_id,
                    'original_error' => $e->getMessage(),
                    'fallback_error' => $fallback_exception->getMessage(),
                    'estimated_size' => $estimated_data_size
                ]);

                $result = \NTWP\Utils\API_Result::failure(
                    $error_type,
                    "原始错误: {$e->getMessage()}, 降级失败: {$fallback_exception->getMessage()}",
                    1,
                    $processing_time,
                    $context
                );

                $result->log_result("完全失败获取数据库页面 ({$database_id})", 'Enhanced Incremental Fetch');

                return $result;
            }
        }
    }

    /**
     * 预估数据库大小
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @return int 预估的页面数量
     */
    private function estimate_database_size(string $database_id): int {
        try {
            // 获取少量页面来预估总数
            $endpoint = 'databases/' . $database_id . '/query';
            $data = ['page_size' => 1]; // 只获取1个页面

            $response = $this->send_request($endpoint, 'POST', $data);

            // 如果有has_more标志，说明数据量较大
            if (isset($response['has_more']) && $response['has_more']) {
                return 1000; // 预估大数据集
            }

            $count = count($response['results'] ?? []);
            return $count;

        } catch (\Exception $e) {
            // 预估失败，返回中等数据集大小
            \NTWP\Core\Logger::debug_log(
                "数据库大小预估失败: {$e->getMessage()}",
                'Database Size Estimation'
            );
            return 500; // 默认中等数据集
        }
    }

    /**
     * 批量增量获取多个数据库的页面
     *
     * 为多个数据库同时执行增量同步，提升效率
     *
     * @since 2.0.0-beta.1
     * @param array $database_configs 数据库配置数组 [database_id => [last_sync_time, filters]]
     * @param bool $with_details 是否获取详细信息
     * @return array [database_id => pages] 映射
     */
    public function batch_smart_incremental_fetch(array $database_configs, bool $with_details = false): array {
        $results = [];
        $start_time = microtime(true);

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::info_log(
                sprintf('开始批量增量获取 %d 个数据库', count($database_configs)),
                'Batch Incremental Fetch'
            );
        }

        foreach ($database_configs as $database_id => $config) {
            $last_sync_time = $config['last_sync_time'] ?? '';
            $additional_filters = $config['filters'] ?? [];

            try {
                $results[$database_id] = $this->smart_incremental_fetch(
                    $database_id,
                    $last_sync_time,
                    $additional_filters,
                    $with_details
                );

            } catch (\Exception $e) {
                if (class_exists('\\NTWP\\Core\\Logger')) {
                    \NTWP\Core\Logger::warning_log(
                        sprintf('数据库 %s 增量获取失败: %s', $database_id, $e->getMessage()),
                        'Batch Incremental Fetch'
                    );
                }
                $results[$database_id] = [];
            }
        }

        $total_time = microtime(true) - $start_time;
        $total_pages = array_sum(array_map('count', $results));

        if (class_exists('\\NTWP\\Core\\Performance_Monitor')) {
            \NTWP\Core\Performance_Monitor::record_custom_metric('batch_incremental_time', $total_time);
            \NTWP\Core\Performance_Monitor::record_custom_metric('batch_incremental_databases', count($database_configs));
            \NTWP\Core\Performance_Monitor::record_custom_metric('batch_incremental_total_pages', $total_pages);
        }

        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::info_log(
                sprintf(
                    '批量增量获取完成: %d个数据库, 总计%d个页面, 耗时%.3fs',
                    count($database_configs),
                    $total_pages,
                    $total_time
                ),
                'Batch Incremental Fetch'
            );
        }

        return $results;
    }

    /**
     * 格式化时间戳为API兼容格式
     *
     * @since 2.0.0-beta.1
     * @param string $timestamp 时间戳
     * @return string 格式化后的时间戳
     */
    public function format_timestamp_for_api(string $timestamp): string {
        // 如果为空或无效，返回null以避免API错误
        if (empty($timestamp) || trim($timestamp) === '') {
            return '';
        }

        // 如果已经是ISO 8601格式，直接返回
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z?$/', $timestamp)) {
            // 确保时间戳以Z结尾（UTC格式）
            return rtrim($timestamp, 'Z') . 'Z';
        }

        // 尝试解析并转换为ISO 8601格式
        try {
            $date = new DateTime($timestamp);
            // 转换为UTC时间并格式化为ISO 8601
            $date->setTimezone(new DateTimeZone('UTC'));
            return $date->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            // 如果解析失败，记录错误并返回空字符串
            if (class_exists('\\NTWP\\Core\\Logger')) {
                \NTWP\Core\Logger::warning_log(
                    "时间戳格式化失败: {$timestamp} - " . $e->getMessage(),
                    'API Time Format'
                );
            }
            return '';
        }
    }

    /**
     * 验证过滤器是否有效
     * 
     * 检查过滤器是否包含有效的过滤条件，避免传递空过滤器导致API错误
     *
     * @since 2.0.0-beta.1
     * @param array $filter 过滤器数组
     * @return bool 是否为有效过滤器
     */
    public function is_valid_filter(array $filter): bool {
        // 记录过滤器验证开始
        if (class_exists('\\NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::debug_log(
                sprintf(
                    "开始验证过滤器: %s",
                    json_encode($filter, JSON_UNESCAPED_UNICODE)
                ),
                'Filter Validation'
            );
        }

        // 如果过滤器为空，返回false
        if (empty($filter)) {
            if (class_exists('\\NTWP\\Core\\Logger')) {
                \NTWP\Core\Logger::debug_log(
                    "无过滤器：将获取所有页面（首次同步或全量获取）",
                    'Filter Validation'
                );
            }
            return false;
        }

        // 检查是否包含有效的过滤条件
        $valid_filter_keys = [
            'and', 'or', 'title', 'rich_text', 'number', 'checkbox', 'select',
            'multi_select', 'status', 'date', 'people', 'files', 'url', 'email',
            'phone_number', 'relation', 'created_by', 'created_time',
            'last_edited_by', 'last_edited_time', 'formula', 'unique_id', 'rollup',
            'timestamp', 'property'  // 添加时间戳过滤器和属性过滤器支持
        ];

        $found_valid_keys = [];
        $invalid_keys = [];

        // 检查过滤器是否包含至少一个有效的键
        foreach ($filter as $key => $value) {
            if (in_array($key, $valid_filter_keys)) {
                if (!empty($value)) {
                    $found_valid_keys[] = $key;
                }
            } else {
                $invalid_keys[] = $key;
            }
        }

        // 记录详细的验证结果
        if (class_exists('\\NTWP\\Core\\Logger')) {
            if (!empty($found_valid_keys)) {
                \NTWP\Core\Logger::debug_log(
                    sprintf(
                        "过滤器验证成功: 找到有效键 [%s]",
                        implode(', ', $found_valid_keys)
                    ),
                    'Filter Validation'
                );
            }

            if (!empty($invalid_keys)) {
                \NTWP\Core\Logger::warning_log(
                    sprintf(
                        "过滤器包含无效键: [%s]",
                        implode(', ', $invalid_keys)
                    ),
                    'Filter Validation'
                );
            }

            if (empty($found_valid_keys)) {
                \NTWP\Core\Logger::debug_log(
                    "过滤器验证失败: 未找到有效的过滤键",
                    'Filter Validation'
                );
            }
        }

        return !empty($found_valid_keys);
    }

    /**
     * 格式化字节数为可读格式
     *
     * @since 2.0.0-beta.1
     * @param int $bytes 字节数
     * @return string 格式化后的字符串
     */
    private function format_bytes(int $bytes): string {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . 'MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . 'KB';
        } else {
            return $bytes . 'B';
        }
    }

    /**
     * 获取增量同步统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public function get_incremental_sync_stats(): array {
        $stats = [
            'api_filter_enabled' => true,
            'supported_filters' => [
                'timestamp' => ['last_edited_time', 'created_time'],
                'property' => ['text', 'number', 'select', 'multi_select', 'date', 'checkbox'],
                'compound' => ['and', 'or']
            ],
            'performance_optimizations' => [
                'server_side_filtering' => true,
                'batch_processing' => true,
                'memory_optimization' => true
            ]
        ];

        if (class_exists('\\NTWP\\Core\\Performance_Monitor')) {
            $metrics = \NTWP\Core\Performance_Monitor::get_metrics();
            $stats['recent_performance'] = [
                'last_fetch_time' => $metrics['incremental_fetch_time'] ?? 0,
                'last_fetch_count' => $metrics['incremental_fetch_count'] ?? 0,
                'last_memory_usage' => $metrics['incremental_fetch_memory'] ?? 0
            ];
        }

        return $stats;
    }

    /**
     * 获取智能缓存统计信息和性能指标
     *
     * @since 2.0.0-beta.1
     * @return array 智能缓存统计信息
     */
    public function get_smart_cache_stats(): array {
        $stats = [
            'sync_mode' => $this->sync_mode,
            'cache_strategies' => self::$cache_strategies,
            'performance_optimization' => [
                'api_call_reduction' => true,
                'intelligent_caching' => true,
                'sync_mode_awareness' => true
            ]
        ];

        // 获取Smart_Cache统计
        if (class_exists('\\NTWP\\Utils\\Smart_Cache')) {
            $stats['smart_cache'] = \NTWP\Utils\Smart_Cache::get_cache_stats();
            $stats['tiered_cache'] = \NTWP\Utils\Smart_Cache::get_tiered_stats();
        }

        // 获取Session_Cache统计  
        if (class_exists('\\NTWP\\Utils\\Session_Cache')) {
            $stats['session_cache'] = [
                'enabled' => true,
                'scope' => 'single_sync_session',
                'ttl_range' => '60-300 seconds'
            ];
        }

        return $stats;
    }

    /**
     * 清理过期的智能缓存
     *
     * @since 2.0.0-beta.1
     * @return array 清理结果
     */
    public function cleanup_smart_cache(): array {
        $results = [
            'smart_cache_cleared' => 0,
            'session_cache_cleared' => 0
        ];

        // 清理Smart_Cache
        if (class_exists('\\NTWP\\Utils\\Smart_Cache')) {
            $results['smart_cache_cleared'] = \NTWP\Utils\Smart_Cache::clear_all();
        }

        // 清理Session_Cache
        if (class_exists('\\NTWP\\Utils\\Session_Cache') && method_exists('\\NTWP\\Utils\\Session_Cache', 'clear_expired')) {
            $results['session_cache_cleared'] = \NTWP\Utils\Session_Cache::clear_expired();
        }

        \NTWP\Core\Logger::info_log(
            "智能缓存清理完成: Smart Cache {$results['smart_cache_cleared']} 项, Session Cache {$results['session_cache_cleared']} 项",
            'Cache Cleanup'
        );

        return $results;
    }

    /**
     * 获取增强错误处理统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 错误处理统计信息
     */
    public function get_enhanced_error_handling_stats(): array {
        $stats = [
            'retry_config' => self::$retry_config,
            'error_classification' => [
                'precise_patterns' => true,
                'http_code_extraction' => true,
                'context_sanitization' => true
            ],
            'fallback_strategies' => [
                'FILTER_ERROR' => 'data_size_based',
                'RATE_LIMIT_ERROR' => 'throttled_sync',
                'NETWORK_ERROR' => 'retry_with_backoff',
                'AUTH_ERROR' => 'abort_sync',
                'DEFAULT' => 'conservative_sync'
            ],
            'resource_management' => [
                'context_size_limit' => '1KB',
                'memory_monitoring' => true,
                'execution_time_tracking' => true
            ],
            'result_standardization' => [
                'api_result_object' => true,
                'detailed_context' => true,
                'automatic_logging' => true
            ]
        ];

        // 获取Smart_Cache统计
        if (class_exists('\\NTWP\\Utils\\Smart_Cache')) {
            $stats['smart_cache'] = \NTWP\Utils\Smart_Cache::get_cache_stats();
        }

        // 获取性能监控统计
        if (class_exists('\\NTWP\\Core\\Performance_Monitor')) {
            $metrics = \NTWP\Core\Performance_Monitor::get_metrics();
            $stats['performance_metrics'] = [
                'enhanced_fetch_time' => $metrics['enhanced_fetch_time'] ?? 0,
                'enhanced_fetch_count' => $metrics['enhanced_fetch_count'] ?? 0,
                'enhanced_fetch_memory' => $metrics['enhanced_fetch_memory'] ?? 0
            ];
        }

        return $stats;
    }

    /**
     * 测试增强的错误处理机制
     *
     * @since 2.0.0-beta.1
     * @param string $test_scenario 测试场景
     * @return \NTWP\Utils\API_Result 测试结果
     */
    public function test_enhanced_error_handling(string $test_scenario = 'filter_error'): \NTWP\Utils\API_Result {
        $start_time = microtime(true);

        try {
            switch ($test_scenario) {
                case 'filter_error':
                    // 模拟过滤器错误
                    throw new \Exception('filter validation failed: property last_edited_time does not exist', 400);

                case 'network_error':
                    // 模拟网络错误
                    throw new \Exception('timeout occurred during network request', 0);

                case 'rate_limit_error':
                    // 模拟限流错误
                    throw new \Exception('rate limit exceeded: too many requests', 429);

                case 'auth_error':
                    // 模拟认证错误
                    throw new \Exception('unauthorized: invalid token', 401);

                default:
                    throw new \Exception('unknown test scenario', 500);
            }

        } catch (\Exception $e) {
            $error_type = $this->classify_api_error_precise($e);
            $processing_time = round((microtime(true) - $start_time) * 1000);

            $fallback_strategy = $this->get_fallback_strategy($error_type, 100, [
                'test_scenario' => $test_scenario
            ]);

            $context = $this->sanitize_error_context([
                'test_scenario' => $test_scenario,
                'classified_as' => $error_type,
                'fallback_strategy' => $fallback_strategy,
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            $result = \NTWP\Utils\API_Result::failure(
                $error_type,
                "测试错误处理: {$e->getMessage()}",
                0,
                $processing_time,
                $context
            );

            $result->log_result("错误处理测试 ({$test_scenario})", 'Enhanced Error Handling Test');

            return $result;
        }
    }

    /**
     * 并发获取数据库页面（高性能版本）
     *
     * 使用并发网络管理器实现真正的并发数据库页面获取
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $filter 过滤条件
     * @param bool $with_details 是否获取详细信息
     * @return array 页面数组
     */
    public function get_database_pages_concurrent(string $database_id, array $filter = [], bool $with_details = false): array {
        // 开始性能监控
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);

        if (class_exists('\\NTWP\\Core\\Performance_Monitor')) {
            \NTWP\Core\Performance_Monitor::start_timer('concurrent_database_fetch');
        }

        try {
            // 初始化并发网络管理器
            $concurrent_manager = new \NTWP\Utils\Concurrent_Network_Manager();
            $concurrent_manager->init_connection_pool();

            // 预估数据库大小
            $estimated_size = $concurrent_manager->estimate_database_size($database_id, $filter);

            // 计算最优并发数
            $optimal_concurrency = $concurrent_manager->calculate_optimal_concurrency($estimated_size);

            // 重新初始化管理器使用最优并发数
            $concurrent_manager = new \NTWP\Utils\Concurrent_Network_Manager($optimal_concurrency);

            if (class_exists('\\NTWP\\Core\\Logger')) {
                \NTWP\Core\Logger::info_log(
                    sprintf(
                        '开始并发获取数据库页面: ID=%s, 预估大小=%d, 并发数=%d',
                        $database_id,
                        $estimated_size,
                        $optimal_concurrency
                    ),
                    'Concurrent Database Fetch'
                );
            }

            // 首先获取第一页来确定总页数
            $first_page_data = $this->get_single_page_data($database_id, $filter, null, 100);
            $all_results = $first_page_data['results'] ?? [];
            $has_more = $first_page_data['has_more'] ?? false;
            $next_cursor = $first_page_data['next_cursor'] ?? null;

            // 如果有更多页面，准备并发请求
            if ($has_more && $next_cursor) {
                $concurrent_requests = [];
                $cursors = [$next_cursor];

                // 预先获取几个cursor来准备并发请求
                $max_concurrent_pages = min($optimal_concurrency, 10); // 限制最大并发页数

                for ($i = 1; $i < $max_concurrent_pages && $has_more; $i++) {
                    $temp_data = $this->get_single_page_data($database_id, $filter, $next_cursor, 100);
                    $all_results = array_merge($all_results, $temp_data['results'] ?? []);
                    $has_more = $temp_data['has_more'] ?? false;
                    $next_cursor = $temp_data['next_cursor'] ?? null;

                    if ($has_more && $next_cursor) {
                        $cursors[] = $next_cursor;
                    }
                }

                // 如果还有更多页面，使用并发获取
                if ($has_more && count($cursors) > 1) {
                    foreach ($cursors as $cursor) {
                        if ($cursor) {
                            $request_data = [
                                'page_size' => 100,
                                'start_cursor' => $cursor
                            ];

                            if (!empty($filter)) {
                                $request_data['filter'] = $filter;
                            }

                            $concurrent_manager->add_request(
                                $this->api_base_url . 'databases/' . $database_id . '/query',
                                [
                                    'method' => 'POST',
                                    'headers' => $this->get_headers(),
                                    'body' => json_encode($request_data),
                                    'timeout' => 30
                                ]
                            );
                        }
                    }

                    // 执行并发请求
                    $concurrent_responses = $concurrent_manager->execute_with_retry();

                    // 处理并发响应
                    foreach ($concurrent_responses as $response) {
                        if ($response['success'] && !empty($response['body'])) {
                            $response_data = json_decode($response['body'], true);
                            if (isset($response_data['results'])) {
                                $all_results = array_merge($all_results, $response_data['results']);
                            }
                        }
                    }
                }
            }

            // 如果需要详细信息，批量获取页面详情
            if ($with_details && !empty($all_results)) {
                $all_results = $this->enrich_pages_with_details_concurrent($all_results, $concurrent_manager);
            }

            // 清理连接池
            $concurrent_manager->cleanup_connection_pool();

            // 记录性能统计
            $processing_time = microtime(true) - $start_time;
            $memory_used = memory_get_usage(true) - $start_memory;

            if (class_exists('\\NTWP\\Core\\Performance_Monitor')) {
                \NTWP\Core\Performance_Monitor::end_timer('concurrent_database_fetch');
                \NTWP\Core\Performance_Monitor::record_custom_metric('concurrent_fetch_time', $processing_time);
                \NTWP\Core\Performance_Monitor::record_custom_metric('concurrent_fetch_count', count($all_results));
                \NTWP\Core\Performance_Monitor::record_custom_metric('concurrent_fetch_memory', $memory_used);
            }

            if (class_exists('\\NTWP\\Core\\Logger')) {
                \NTWP\Core\Logger::info_log(
                    sprintf(
                        '并发获取完成: 获取%d个页面, 耗时%.3fs, 内存%s',
                        count($all_results),
                        $processing_time,
                        $this->format_bytes($memory_used)
                    ),
                    'Concurrent Database Fetch'
                );
            }

            return $all_results;

        } catch (\Exception $e) {
            if (class_exists('\\NTWP\\Core\\Logger')) {
                \NTWP\Core\Logger::error_log(
                    sprintf('并发数据库获取失败: %s', $e->getMessage()),
                    'Concurrent Database Fetch'
                );
            }

            // 失败时回退到标准方法
            return $this->get_database_pages($database_id, $filter, $with_details);
        }
    }

    /**
     * 获取单页数据
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $filter 过滤条件
     * @param string|null $start_cursor 起始游标
     * @param int $page_size 页面大小
     * @return array 页面数据
     */
    private function get_single_page_data(string $database_id, array $filter, ?string $start_cursor, int $page_size = 100): array {
        $endpoint = 'databases/' . $database_id . '/query';
        $data = ['page_size' => $page_size];

        if (!empty($filter)) {
            $data['filter'] = $filter;
        }

        if ($start_cursor) {
            $data['start_cursor'] = $start_cursor;
        }

        return $this->send_request($endpoint, 'POST', $data);
    }

    /**
     * 并发获取页面详细信息
     *
     * @since 2.0.0-beta.1
     * @param array $pages 页面数组
     * @param \NTWP\Utils\Concurrent_Network_Manager $concurrent_manager 并发管理器
     * @return array 包含详细信息的页面数组
     */
    private function enrich_pages_with_details_concurrent(array $pages, \NTWP\Utils\Concurrent_Network_Manager $concurrent_manager): array {
        if (empty($pages)) {
            return $pages;
        }

        // 为每个页面添加获取详细信息的请求
        $page_ids = [];
        foreach ($pages as $page) {
            $page_id = $page['id'];
            $page_ids[] = $page_id;

            $concurrent_manager->add_request(
                $this->api_base_url . 'pages/' . $page_id,
                [
                    'method' => 'GET',
                    'headers' => $this->get_headers(),
                    'timeout' => 20
                ]
            );
        }

        // 执行并发请求获取详细信息
        $detail_responses = $concurrent_manager->execute_with_retry();

        // 将详细信息合并到页面数据中
        foreach ($detail_responses as $index => $response) {
            if ($response['success'] && !empty($response['body'])) {
                $detail_data = json_decode($response['body'], true);
                if (isset($detail_data['id']) && isset($pages[$index])) {
                    // 合并详细信息到原始页面数据
                    $pages[$index] = array_merge($pages[$index], $detail_data);
                }
            }
        }

        return $pages;
    }

    // ==================== API服务层功能（从API_Service合并） ====================

    /**
     * 高级API请求方法（带缓存策略）
     *
     * 提供更高层次的API请求抽象，集成智能缓存和网络管理
     *
     * @since 2.0.0-beta.1
     * @param string $endpoint API端点
     * @param array $params 请求参数
     * @param array $options 请求选项
     * @return array API响应
     */
    public function request(string $endpoint, array $params = [], array $options = []): array {
        // 检查缓存策略
        $cache_strategy = $this->get_cache_strategy($endpoint, 'GET');

        if ($cache_strategy && $this->should_use_cache($endpoint, $cache_strategy)) {
            $cache_key = md5($endpoint . serialize($params));

            if (class_exists('\\NTWP\\Utils\\Smart_Cache')) {
                $cached_response = \NTWP\Utils\Smart_Cache::get_tiered($cache_strategy['type'], $cache_key);

                if ($cached_response !== false) {
                    return $cached_response;
                }
            }
        }

        // 发起实际请求
        $response = $this->send_request($endpoint, 'GET', $params);

        // 缓存响应
        if ($cache_strategy && $this->should_use_cache($endpoint, $cache_strategy) &&
            class_exists('\\NTWP\\Utils\\Smart_Cache')) {
            \NTWP\Utils\Smart_Cache::set_tiered(
                $cache_strategy['type'],
                $cache_key,
                $response,
                [],
                $cache_strategy['ttl']
            );
        }

        return $response;
    }

    /**
     * 批量API请求（高级版本）
     *
     * 使用API合并器优化批量请求性能
     *
     * @since 2.0.0-beta.1
     * @param array $requests 请求数组
     * @param array $options 选项
     * @return array 批量响应
     */
    public function batch_request(array $requests, array $options = []): array {
        // 如果启用了API合并器，使用智能合并
        if ($this->enable_api_merging && $this->api_merger) {
            try {
                return $this->api_merger->merge_and_execute($requests, $options);
            } catch (\Exception $e) {
                \NTWP\Core\Logger::warning_log(
                    "API合并器执行失败，回退到标准批量请求: " . $e->getMessage(),
                    'API Service'
                );
            }
        }

        // 回退到标准批量请求
        $endpoints = [];
        $data_array = [];
        $method = 'GET';

        foreach ($requests as $request) {
            $endpoints[] = $request['endpoint'] ?? '';
            $data_array[] = $request['data'] ?? [];
            if (isset($request['method'])) {
                $method = $request['method'];
            }
        }

        return $this->batch_send_requests($endpoints, $method, $data_array);
    }
}