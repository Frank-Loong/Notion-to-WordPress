<?php
/**
 * 统一错误处理和日志记录类
 *
 * 提供标准化的错误处理、异常转换和日志记录功能
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notion_To_WordPress_Error_Handler {

    /**
     * 错误级别常量
     *
     * @since 1.1.0
     */
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_CRITICAL = 'critical';

    /**
     * 错误代码常量
     *
     * @since 1.1.0
     */
    public const CODE_API_ERROR = 'notion_api_error';
    public const CODE_IMPORT_ERROR = 'notion_import_error';
    public const CODE_MEDIA_ERROR = 'notion_media_error';
    public const CODE_VALIDATION_ERROR = 'notion_validation_error';
    public const CODE_LOCK_ERROR = 'notion_lock_error';
    public const CODE_CONFIG_ERROR = 'notion_config_error';

    /**
     * 日志上下文映射
     *
     * @since 1.1.0
     * @var array<string, string>
     */
    private static array $context_map = [
        self::CODE_API_ERROR => 'Notion API',
        self::CODE_IMPORT_ERROR => 'Import Process',
        self::CODE_MEDIA_ERROR => 'Media Handler',
        self::CODE_VALIDATION_ERROR => 'Data Validation',
        self::CODE_LOCK_ERROR => 'Lock Manager',
        self::CODE_CONFIG_ERROR => 'Configuration',
    ];

    /**
     * 将异常转换为WP_Error
     *
     * @since 1.1.0
     * @param Exception $exception 要转换的异常
     * @param string    $code      错误代码
     * @param array     $data      附加数据
     * @return WP_Error
     */
    public static function exception_to_wp_error(Exception $exception, string $code = '', array $data = []): WP_Error {
        $error_code = $code ?: self::get_error_code_from_exception($exception);
        $message = self::sanitize_error_message($exception->getMessage());
        
        $error_data = array_merge([
            'exception_class' => get_class($exception),
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine(),
            'trace_hash' => md5($exception->getTraceAsString()),
        ], $data);

        // 记录详细错误信息
        self::log_error($message, $error_code, [
            'exception' => $exception,
            'data' => $error_data
        ]);

        return new WP_Error($error_code, $message, $error_data);
    }

    /**
     * 创建标准化的WP_Error
     *
     * @since 1.1.0
     * @param string $message 错误消息
     * @param string $code    错误代码
     * @param array  $data    附加数据
     * @return WP_Error
     */
    public static function create_wp_error(string $message, string $code, array $data = []): WP_Error {
        $sanitized_message = self::sanitize_error_message($message);
        
        self::log_error($sanitized_message, $code, ['data' => $data]);
        
        return new WP_Error($code, $sanitized_message, $data);
    }

    /**
     * 记录错误日志
     *
     * @since 1.1.0
     * @param string $message   错误消息
     * @param string $code      错误代码
     * @param array  $context   上下文信息
     * @param string $level     日志级别
     */
    public static function log_error(
        string $message, 
        string $code = '', 
        array $context = [], 
        string $level = self::LEVEL_ERROR
    ): void {
        $log_context = self::$context_map[$code] ?? 'General';
        $sanitized_message = self::sanitize_error_message($message);
        
        // 构建日志条目
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'code' => $code,
            'message' => $sanitized_message,
            'context' => self::sanitize_context($context)
        ];

        // 使用现有的日志系统
        switch ($level) {
            case self::LEVEL_DEBUG:
                Notion_To_WordPress_Helper::debug_log(
                    $sanitized_message,
                    $log_context,
                    Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG
                );
                break;
            case self::LEVEL_INFO:
                Notion_To_WordPress_Helper::debug_log(
                    $sanitized_message,
                    $log_context,
                    Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
                );
                break;
            case self::LEVEL_WARNING:
                Notion_To_WordPress_Helper::debug_log(
                    $sanitized_message,
                    $log_context,
                    Notion_To_WordPress_Helper::DEBUG_LEVEL_WARNING
                );
                break;
            case self::LEVEL_ERROR:
            case self::LEVEL_CRITICAL:
                Notion_To_WordPress_Helper::error_log($sanitized_message, $log_context);
                break;
        }

        // 存储结构化日志（用于管理界面显示）
        self::store_structured_log($log_entry);
    }

    /**
     * 记录信息日志
     *
     * @since 1.1.0
     * @param string $message 消息
     * @param string $code    代码
     * @param array  $context 上下文
     */
    public static function log_info(string $message, string $code = '', array $context = []): void {
        self::log_error($message, $code, $context, self::LEVEL_INFO);
    }

    /**
     * 记录调试日志
     *
     * @since 1.1.0
     * @param string $message 消息
     * @param string $code    代码
     * @param array  $context 上下文
     */
    public static function log_debug(string $message, string $code = '', array $context = []): void {
        self::log_error($message, $code, $context, self::LEVEL_DEBUG);
    }

    /**
     * 记录警告日志
     *
     * @since 1.1.0
     * @param string $message 消息
     * @param string $code    代码
     * @param array  $context 上下文
     */
    public static function log_warning(string $message, string $code = '', array $context = []): void {
        self::log_error($message, $code, $context, self::LEVEL_WARNING);
    }

    /**
     * 处理并记录API错误
     *
     * @since 1.1.0
     * @param string $message API错误消息
     * @param array  $context 上下文信息
     * @return WP_Error
     */
    public static function handle_api_error(string $message, array $context = []): WP_Error {
        return self::create_wp_error($message, self::CODE_API_ERROR, $context);
    }

    /**
     * 处理并记录导入错误
     *
     * @since 1.1.0
     * @param string $message 导入错误消息
     * @param array  $context 上下文信息
     * @return WP_Error
     */
    public static function handle_import_error(string $message, array $context = []): WP_Error {
        return self::create_wp_error($message, self::CODE_IMPORT_ERROR, $context);
    }

    /**
     * 处理并记录媒体错误
     *
     * @since 1.1.0
     * @param string $message 媒体错误消息
     * @param array  $context 上下文信息
     * @return WP_Error
     */
    public static function handle_media_error(string $message, array $context = []): WP_Error {
        return self::create_wp_error($message, self::CODE_MEDIA_ERROR, $context);
    }

    /**
     * 处理并记录验证错误
     *
     * @since 1.1.0
     * @param string $message 验证错误消息
     * @param array  $context 上下文信息
     * @return WP_Error
     */
    public static function handle_validation_error(string $message, array $context = []): WP_Error {
        return self::create_wp_error($message, self::CODE_VALIDATION_ERROR, $context);
    }

    /**
     * 获取最近的错误日志
     *
     * @since 1.1.0
     * @param int    $limit 限制数量
     * @param string $level 日志级别过滤
     * @return array
     */
    public static function get_recent_logs(int $limit = 50, string $level = ''): array {
        $logs = get_option('ntw_error_logs', []);
        
        if ($level) {
            $logs = array_filter($logs, function($log) use ($level) {
                return $log['level'] === $level;
            });
        }

        // 按时间戳倒序排列
        usort($logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return array_slice($logs, 0, $limit);
    }

    /**
     * 清理旧的错误日志
     *
     * @since 1.1.0
     * @param int $days 保留天数
     */
    public static function cleanup_old_logs(int $days = 30): void {
        $logs = get_option('ntw_error_logs', []);
        $cutoff_time = time() - ($days * DAY_IN_SECONDS);

        $logs = array_filter($logs, function($log) use ($cutoff_time) {
            return strtotime($log['timestamp']) > $cutoff_time;
        });

        update_option('ntw_error_logs', array_values($logs));
    }

    /**
     * 从异常中推断错误代码
     *
     * @since 1.1.0
     * @param Exception $exception 异常对象
     * @return string 错误代码
     */
    private static function get_error_code_from_exception(Exception $exception): string {
        $class_name = get_class($exception);

        // 根据异常类型推断错误代码
        if (strpos($class_name, 'API') !== false || strpos($exception->getMessage(), 'API') !== false) {
            return self::CODE_API_ERROR;
        }

        if (strpos($class_name, 'Import') !== false || strpos($exception->getMessage(), 'import') !== false) {
            return self::CODE_IMPORT_ERROR;
        }

        if (strpos($class_name, 'Media') !== false || strpos($exception->getMessage(), 'media') !== false) {
            return self::CODE_MEDIA_ERROR;
        }

        if (strpos($class_name, 'Validation') !== false || strpos($exception->getMessage(), 'validation') !== false) {
            return self::CODE_VALIDATION_ERROR;
        }

        return 'notion_general_error';
    }

    /**
     * 清理错误消息，移除敏感信息
     *
     * @since 1.1.0
     * @param string $message 原始错误消息
     * @return string 清理后的消息
     */
    private static function sanitize_error_message(string $message): string {
        // 移除可能的API密钥
        $message = preg_replace('/secret_[a-zA-Z0-9]{43}/', '[API_KEY_HIDDEN]', $message);

        // 移除可能的数据库ID
        $message = preg_replace('/[a-f0-9]{32}/', '[DATABASE_ID_HIDDEN]', $message);

        // 移除文件路径中的敏感部分
        $message = preg_replace('/\/[^\/\s]*\/wp-content\//', '/[PATH_HIDDEN]/wp-content/', $message);

        // 限制消息长度
        if (strlen($message) > 500) {
            $message = substr($message, 0, 497) . '...';
        }

        return $message;
    }

    /**
     * 清理上下文信息，移除敏感数据
     *
     * @since 1.1.0
     * @param array $context 原始上下文
     * @return array 清理后的上下文
     */
    private static function sanitize_context(array $context): array {
        $sanitized = [];

        foreach ($context as $key => $value) {
            // 跳过敏感键
            if (in_array(strtolower($key), ['password', 'api_key', 'secret', 'token', 'auth'])) {
                $sanitized[$key] = '[HIDDEN]';
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = self::sanitize_error_message($value);
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitize_context($value);
            } elseif (is_object($value)) {
                if ($value instanceof Exception) {
                    $sanitized[$key] = [
                        'class' => get_class($value),
                        'message' => self::sanitize_error_message($value->getMessage()),
                        'file' => basename($value->getFile()),
                        'line' => $value->getLine()
                    ];
                } else {
                    $sanitized[$key] = get_class($value);
                }
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * 存储结构化日志
     *
     * @since 1.1.0
     * @param array $log_entry 日志条目
     */
    private static function store_structured_log(array $log_entry): void {
        $logs = get_option('ntw_error_logs', []);

        // 添加新日志
        $logs[] = $log_entry;

        // 限制日志数量，保留最新的1000条
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }

        update_option('ntw_error_logs', $logs, false);
    }
}
