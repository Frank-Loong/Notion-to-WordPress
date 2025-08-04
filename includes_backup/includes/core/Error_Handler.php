<?php
declare(strict_types=1);

namespace NTWP\Core;

/**
 * 统一错误处理器
 * 
 * 提供Exception到WP_Error的适配，统一错误分类和处理流程
 * 基于现有API.php的错误分类逻辑，确保兼容性
 * 
 * @since      2.0.0-beta.1
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

/**
 * 统一错误处理器类
 */
class Error_Handler {
    
    /**
     * 错误类型常量
     */
    const ERROR_TYPES = [
        'FILTER_ERROR' => 'Filter validation error',
        'AUTH_ERROR' => 'Authentication error', 
        'RATE_LIMIT_ERROR' => 'Rate limit error',
        'NETWORK_ERROR' => 'Network connection error',
        'SERVER_ERROR' => 'Server error',
        'CLIENT_ERROR' => 'Client error',
        'VALIDATION_ERROR' => 'Data validation error',
        'PERMISSION_ERROR' => 'Permission error',
        'DATA_ERROR' => 'Data processing error',
        'UNKNOWN_ERROR' => 'Unknown error'
    ];
    
    /**
     * 重试配置（基于API.php的配置）
     * 
     * @var array
     */
    private static $retry_config = [
        'NETWORK_ERROR' => ['max_retries' => 3, 'backoff' => [1, 3, 9], 'should_retry' => true],
        'RATE_LIMIT_ERROR' => ['max_retries' => 5, 'backoff' => [5, 15, 45, 120, 300], 'should_retry' => true],
        'SERVER_ERROR' => ['max_retries' => 2, 'backoff' => [2, 8], 'should_retry' => true],
        'FILTER_ERROR' => ['max_retries' => 1, 'backoff' => [1], 'should_retry' => false],
        'AUTH_ERROR' => ['max_retries' => 0, 'backoff' => [], 'should_retry' => false],
        'CLIENT_ERROR' => ['max_retries' => 0, 'backoff' => [], 'should_retry' => false],
        'VALIDATION_ERROR' => ['max_retries' => 0, 'backoff' => [], 'should_retry' => false],
        'PERMISSION_ERROR' => ['max_retries' => 0, 'backoff' => [], 'should_retry' => false],
        'DATA_ERROR' => ['max_retries' => 1, 'backoff' => [1], 'should_retry' => false],
        'UNKNOWN_ERROR' => ['max_retries' => 1, 'backoff' => [2], 'should_retry' => true]
    ];
    
    /**
     * 将Exception转换为WP_Error
     * 
     * @since 2.0.0-beta.1
     * @param Exception $exception 异常对象
     * @param array $context 额外上下文信息
     * @return \WP_Error WP_Error对象
     */
    public static function exception_to_wp_error(\Exception $exception, array $context = []): \WP_Error {
        $error_type = self::classify_error($exception);
        $error_code = self::get_wp_error_code($error_type);
        
        // 构建错误数据
        $error_data = [
            'error_type' => $error_type,
            'exception_code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'context' => $context
        ];
        
        // 记录错误日志
        self::log_error($exception, $error_type, $context);
        
        return new \WP_Error(
            $error_code,
            $exception->getMessage(),
            $error_data
        );
    }
    
    /**
     * 分类错误类型（基于API.php的classify_api_error_precise方法）
     * 
     * @since 2.0.0-beta.1
     * @param Exception $exception 异常对象
     * @return string 错误类型
     */
    public static function classify_error(\Exception $exception): string {
        $message = strtolower($exception->getMessage());
        $code = $exception->getCode();
        
        // 获取HTTP状态码（如果可用）
        $http_code = self::extract_http_code($exception);
        
        Logger::debug_log(
            "统一错误分类: 消息='{$message}', 代码={$code}, HTTP={$http_code}",
            'Unified Error Handler'
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
                Logger::debug_log(
                    "匹配过滤器错误模式: {$pattern}",
                    'Unified Error Handler'
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
        
        // 验证错误
        if (preg_match('/invalid.*argument|validation.*failed|missing.*required/i', $message) ||
            $exception instanceof \InvalidArgumentException) {
            return 'VALIDATION_ERROR';
        }
        
        // 权限错误
        if (preg_match('/permission.*denied|access.*denied|not.*allowed/i', $message)) {
            return 'PERMISSION_ERROR';
        }
        
        // 数据错误
        if (preg_match('/data.*error|parse.*error|format.*error/i', $message)) {
            return 'DATA_ERROR';
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
     * 从异常中提取HTTP状态码（基于API.php的extract_http_code方法）
     * 
     * @since 2.0.0-beta.1
     * @param Exception $exception 异常对象
     * @return int HTTP状态码
     */
    private static function extract_http_code(\Exception $exception): int {
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
     * 获取WordPress错误代码
     * 
     * @since 2.0.0-beta.1
     * @param string $error_type 错误类型
     * @return string WordPress错误代码
     */
    private static function get_wp_error_code(string $error_type): string {
        $error_code_map = [
            'FILTER_ERROR' => 'notion_filter_error',
            'AUTH_ERROR' => 'notion_auth_error',
            'RATE_LIMIT_ERROR' => 'notion_rate_limit',
            'NETWORK_ERROR' => 'notion_network_error',
            'SERVER_ERROR' => 'notion_server_error',
            'CLIENT_ERROR' => 'notion_client_error',
            'VALIDATION_ERROR' => 'notion_validation_error',
            'PERMISSION_ERROR' => 'notion_permission_error',
            'DATA_ERROR' => 'notion_data_error',
            'UNKNOWN_ERROR' => 'notion_unknown_error'
        ];
        
        return $error_code_map[$error_type] ?? 'notion_unknown_error';
    }
    
    /**
     * 记录错误日志
     * 
     * @since 2.0.0-beta.1
     * @param Exception $exception 异常对象
     * @param string $error_type 错误类型
     * @param array $context 上下文信息
     */
    private static function log_error(\Exception $exception, string $error_type, array $context = []): void {
        $log_message = sprintf(
            "错误类型: %s | 消息: %s | 文件: %s:%d",
            $error_type,
            $exception->getMessage(),
            basename($exception->getFile()),
            $exception->getLine()
        );
        
        if (!empty($context)) {
            $log_message .= " | 上下文: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        Logger::error_log($log_message, 'Unified Error Handler');
    }

    /**
     * 统一错误处理（基于文档中的handle_error方法）
     *
     * @since 2.0.0-beta.1
     * @param mixed $error 错误对象（WP_Error或Exception）
     * @param array $context 上下文信息
     * @return \WP_Error 处理后的WP_Error对象
     */
    public static function handle_error($error, array $context = []): \WP_Error {
        // 如果是Exception，先转换为WP_Error
        if ($error instanceof \Exception) {
            $error = self::exception_to_wp_error($error, $context);
        }

        // 如果不是WP_Error，创建一个通用错误
        if (!is_wp_error($error)) {
            $error = new \WP_Error(
                'notion_unknown_error',
                is_string($error) ? $error : 'Unknown error occurred',
                ['original_error' => $error, 'context' => $context]
            );
        }

        $error_code = $error->get_error_code();
        $error_message = $error->get_error_message();
        $error_data = $error->get_error_data();

        // 根据错误类型采取不同策略
        switch ($error_code) {
            case 'notion_rate_limit':
            case 'api_rate_limit':
                // 速率限制：等待后重试
                self::schedule_retry($context, 60);
                break;

            case 'notion_auth_error':
            case 'api_unauthorized':
                // 认证错误：通知管理员
                self::notify_admin('认证失败，请检查API密钥', $error);
                break;

            case 'notion_network_error':
            case 'network_timeout':
                // 网络超时：短时间后重试
                self::schedule_retry($context, 30);
                break;

            default:
                // 其他错误：记录日志
                Logger::error_log(
                    sprintf('未分类错误: %s', $error_message),
                    'Unified Error Handler'
                );
        }

        return $error;
    }

    /**
     * 检查是否应该重试（基于API.php的should_retry_enhanced方法）
     *
     * @since 2.0.0-beta.1
     * @param string $error_type 错误类型
     * @param int $attempt_count 当前尝试次数
     * @return bool 是否应该重试
     */
    public static function should_retry(string $error_type, int $attempt_count): bool {
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
     * 计算退避时间（基于API.php的calculate_backoff_time方法）
     *
     * @since 2.0.0-beta.1
     * @param string $error_type 错误类型
     * @param int $attempt_count 当前尝试次数
     * @return int 退避时间（秒）
     */
    public static function calculate_backoff_time(string $error_type, int $attempt_count): int {
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
     * 安排重试任务
     *
     * @since 2.0.0-beta.1
     * @param array $context 上下文信息
     * @param int $delay 延迟时间（秒）
     */
    private static function schedule_retry(array $context, int $delay): void {
        // 这里可以集成WordPress的cron系统或其他重试机制
        Logger::info_log(
            sprintf('安排重试任务，延迟 %d 秒', $delay),
            'Unified Error Handler'
        );

        // 可以在这里添加具体的重试逻辑
        // 例如：wp_schedule_single_event(time() + $delay, 'notion_retry_hook', $context);
    }

    /**
     * 通知管理员
     *
     * @since 2.0.0-beta.1
     * @param string $message 通知消息
     * @param \WP_Error $error 错误对象
     */
    private static function notify_admin(string $message, \WP_Error $error): void {
        Logger::warning_log(
            sprintf('管理员通知: %s | 错误: %s', $message, $error->get_error_message()),
            'Unified Error Handler'
        );

        // 可以在这里添加邮件通知或其他通知机制
        // 例如：wp_mail(get_option('admin_email'), 'Notion插件错误', $message);
    }

    /**
     * 获取重试配置
     *
     * @since 2.0.0-beta.1
     * @return array 重试配置
     */
    public static function get_retry_config(): array {
        return self::$retry_config;
    }

    /**
     * 获取错误类型描述
     *
     * @since 2.0.0-beta.1
     * @param string $error_type 错误类型
     * @return string 错误描述
     */
    public static function get_error_description(string $error_type): string {
        return self::ERROR_TYPES[$error_type] ?? 'Unknown error type';
    }

    /**
     * 验证错误处理器是否正常工作
     *
     * @since 2.0.0-beta.1
     * @return bool 是否正常工作
     */
    public static function validate_handler(): bool {
        try {
            // 测试Exception转换
            $test_exception = new \Exception('Test exception', 500);
            $wp_error = self::exception_to_wp_error($test_exception);

            if (!is_wp_error($wp_error)) {
                return false;
            }

            // 测试错误分类
            $error_type = self::classify_error($test_exception);
            if (!isset(self::ERROR_TYPES[$error_type])) {
                return false;
            }

            Logger::info_log('错误处理器验证通过', 'Unified Error Handler');
            return true;

        } catch (\Exception $e) {
            Logger::error_log(
                sprintf('错误处理器验证失败: %s', $e->getMessage()),
                'Unified Error Handler'
            );
            return false;
        }
    }
}
