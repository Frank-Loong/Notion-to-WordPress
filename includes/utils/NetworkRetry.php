<?php
declare(strict_types=1);

namespace NTWP\Utils;

/**
 * 网络错误重试机制类。
 *
 * 提供智能的网络错误重试功能，支持指数退避策略和错误类型分类。
 *
 * @since      1.9.0-beta.1
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

class NetworkRetry {

    /**
     * 初始化cURL常量（如果未定义）
     */
    private static function init_curl_constants() {
        if (!defined('CURLE_OPERATION_TIMEOUTED')) define('CURLE_OPERATION_TIMEOUTED', 28);
        if (!defined('CURLE_COULDNT_CONNECT')) define('CURLE_COULDNT_CONNECT', 7);
        if (!defined('CURLE_COULDNT_RESOLVE_HOST')) define('CURLE_COULDNT_RESOLVE_HOST', 6);
        if (!defined('CURLE_RECV_ERROR')) define('CURLE_RECV_ERROR', 56);
        if (!defined('CURLE_SEND_ERROR')) define('CURLE_SEND_ERROR', 55);
        if (!defined('CURLE_GOT_NOTHING')) define('CURLE_GOT_NOTHING', 52);
        if (!defined('CURLE_PARTIAL_FILE')) define('CURLE_PARTIAL_FILE', 18);
        if (!defined('CURLE_URL_MALFORMAT')) define('CURLE_URL_MALFORMAT', 3);
        if (!defined('CURLE_UNSUPPORTED_PROTOCOL')) define('CURLE_UNSUPPORTED_PROTOCOL', 1);
        if (!defined('CURLE_SSL_CONNECT_ERROR')) define('CURLE_SSL_CONNECT_ERROR', 35);
        if (!defined('CURLE_SSL_PEER_CERTIFICATE')) define('CURLE_SSL_PEER_CERTIFICATE', 51);
    }

    /**
     * 初始化错误类型数组
     */
    private static function init_error_arrays() {
        self::init_curl_constants();

        if (self::$temporary_errors === null) {
            self::$temporary_errors = [
                // cURL错误
                CURLE_OPERATION_TIMEOUTED,      // 28 - 操作超时
                CURLE_COULDNT_CONNECT,          // 7  - 无法连接
                CURLE_COULDNT_RESOLVE_HOST,     // 6  - 无法解析主机
                CURLE_RECV_ERROR,               // 56 - 接收数据错误
                CURLE_SEND_ERROR,               // 55 - 发送数据错误
                CURLE_GOT_NOTHING,              // 52 - 服务器未返回任何内容
                CURLE_PARTIAL_FILE,             // 18 - 文件传输不完整

                // HTTP状态码
                429, // Too Many Requests
                500, // Internal Server Error
                502, // Bad Gateway
                503, // Service Unavailable
                504, // Gateway Timeout
            ];
        }

        if (self::$permanent_errors === null) {
            self::$permanent_errors = [
                // HTTP状态码
                400, // Bad Request
                401, // Unauthorized
                403, // Forbidden
                404, // Not Found
                405, // Method Not Allowed
                406, // Not Acceptable
                410, // Gone
                422, // Unprocessable Entity

                // cURL错误
                CURLE_URL_MALFORMAT,            // 3  - URL格式错误
                CURLE_UNSUPPORTED_PROTOCOL,     // 1  - 不支持的协议
                CURLE_SSL_CONNECT_ERROR,        // 35 - SSL连接错误
                CURLE_SSL_PEER_CERTIFICATE,     // 51 - SSL证书验证失败
            ];
        }
    }

    /**
     * 默认最大重试次数
     *
     * @since    1.1.2
     * @access   public
     * @var      int    DEFAULT_MAX_RETRIES    默认最大重试次数
     */
    const DEFAULT_MAX_RETRIES = 3;

    /**
     * 默认基础延迟时间（毫秒）
     *
     * @since    1.1.2
     * @access   public
     * @var      int    DEFAULT_BASE_DELAY    默认基础延迟时间
     */
    const DEFAULT_BASE_DELAY = 1000;

    /**
     * 临时性错误类型
     *
     * @since    1.1.2
     * @access   private
     * @var      array    $temporary_errors    临时性错误类型数组
     */
    private static $temporary_errors;

    /**
     * 永久性错误类型
     *
     * @since    1.1.2
     * @access   private
     * @var      array    $permanent_errors    永久性错误类型数组
     */
    private static $permanent_errors;

    /**
     * 重试统计信息
     *
     * @since    1.1.2
     * @access   private
     * @var      array    $retry_stats    重试统计信息
     */
    private static $retry_stats = [
        'total_attempts' => 0,
        'successful_retries' => 0,
        'failed_retries' => 0,
        'permanent_errors' => 0,
        'total_delay_time' => 0,
        'smart_retries' => 0,
        'rate_limit_retries' => 0,
        'server_error_retries' => 0,
        'network_error_retries' => 0,
        'avg_delay_time' => 0
    ];

    /**
     * 带重试的函数调用（指数退避策略）
     *
     * @since    1.1.2
     * @param    callable    $callback       要重试的回调函数
     * @param    int         $max_retries    最大重试次数
     * @param    int         $base_delay     基础延迟时间（毫秒）
     * @return   mixed                       回调函数的返回值
     * @throws   Exception                   如果所有重试都失败
     */
    public static function with_retry(callable $callback, $max_retries = self::DEFAULT_MAX_RETRIES, $base_delay = self::DEFAULT_BASE_DELAY) {
        // 初始化错误数组
        self::init_error_arrays();

        $last_exception = null;
        $start_time = microtime(true);
        
        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            self::$retry_stats['total_attempts']++;
            
            try {
                $result = call_user_func($callback);
                
                // 成功执行
                if ($attempt > 0) {
                    self::$retry_stats['successful_retries']++;
                    
                    \NTWP\Core\Foundation\Logger::debug_log(
                        sprintf(
                            '重试成功：第 %d 次尝试成功，总耗时: %.2f秒',
                            $attempt + 1,
                            microtime(true) - $start_time
                        ),
                        'Network Retry'
                    );
                }

                // 集成性能监控数据（成功情况）
                self::integrate_with_performance_monitor();

                return $result;
                
            } catch (Exception $e) {
                $last_exception = $e;
                
                // 检查是否为永久性错误（使用增强版本）
                if (self::is_permanent_error_enhanced($e)) {
                    self::$retry_stats['permanent_errors']++;
                    
                    \NTWP\Core\Foundation\Logger::debug_log(
                        sprintf(
                            '检测到永久性错误，停止重试: %s',
                            $e->getMessage()
                        ),
                        'Network Retry'
                    );
                    
                    throw $e;
                }
                
                // 如果已达到最大重试次数
                if ($attempt >= $max_retries) {
                    self::$retry_stats['failed_retries']++;
                    
                    \NTWP\Core\Foundation\Logger::error_log(
                        sprintf(
                            '重试失败：已达到最大重试次数 %d，最后错误: %s',
                            $max_retries,
                            $e->getMessage()
                        ),
                        'Network Retry'
                    );
                    
                    break;
                }
                
                // 使用智能延迟计算
                $delay = self::calculate_smart_delay($attempt, $e, $base_delay);
                self::$retry_stats['total_delay_time'] += $delay;
                self::$retry_stats['smart_retries']++;

                // 记录错误类型统计
                self::record_error_type_stats($e);
                
                \NTWP\Core\Foundation\Logger::debug_log(
                    sprintf(
                        '重试 %d/%d 失败: %s，%d毫秒后重试',
                        $attempt + 1,
                        $max_retries,
                        $e->getMessage(),
                        $delay
                    ),
                    'Network Retry'
                );
                
                // 延迟执行
                usleep($delay * 1000); // 转换为微秒
            }
        }

        // 集成性能监控数据
        self::integrate_with_performance_monitor();

        // 所有重试都失败，抛出最后一个异常
        throw $last_exception;
    }

    /**
     * 检查是否为永久性错误
     *
     * @since    1.1.2
     * @param    Exception    $exception    异常对象
     * @return   bool                       是否为永久性错误
     */
    public static function is_permanent_error($exception) {
        self::init_error_arrays();
        $message = $exception->getMessage();
        
        // 检查HTTP状态码
        if (preg_match('/HTTP错误\s+(\d+)/', $message, $matches)) {
            $http_code = (int)$matches[1];
            return in_array($http_code, self::$permanent_errors);
        }
        
        // 检查cURL错误
        if (preg_match('/cURL错误\s+(\d+)/', $message, $matches)) {
            $curl_code = (int)$matches[1];
            return in_array($curl_code, self::$permanent_errors);
        }
        
        // 检查WordPress错误
        if (is_wp_error($exception)) {
            $error_code = $exception->get_error_code();
            
            // 认证相关错误通常是永久性的
            if (in_array($error_code, ['invalid_url', 'invalid_credentials', 'forbidden'])) {
                return true;
            }
        }
        
        // 检查错误消息中的关键词
        $permanent_keywords = [
            'unauthorized',
            'forbidden',
            'not found',
            'bad request',
            'invalid',
            'malformed',
            'authentication',
            'permission',
            '认证失败',
            '授权失败',
            '权限不足',
            '无效',
            '格式错误'
        ];
        
        $message_lower = strtolower($message);
        foreach ($permanent_keywords as $keyword) {
            if (strpos($message_lower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 检查是否为临时性错误
     *
     * @since    1.1.2
     * @param    Exception    $exception    异常对象
     * @return   bool                       是否为临时性错误
     */
    public static function is_temporary_error($exception) {
        self::init_error_arrays();
        $message = $exception->getMessage();
        
        // 检查HTTP状态码
        if (preg_match('/HTTP错误\s+(\d+)/', $message, $matches)) {
            $http_code = (int)$matches[1];
            return in_array($http_code, self::$temporary_errors);
        }
        
        // 检查cURL错误
        if (preg_match('/cURL错误\s+(\d+)/', $message, $matches)) {
            $curl_code = (int)$matches[1];
            return in_array($curl_code, self::$temporary_errors);
        }
        
        // 检查错误消息中的关键词
        $temporary_keywords = [
            'timeout',
            'connection',
            'network',
            'temporary',
            'unavailable',
            'overload',
            'rate limit',
            'too many requests',
            '超时',
            '连接',
            '网络',
            '临时',
            '不可用',
            '过载',
            '限制'
        ];
        
        $message_lower = strtolower($message);
        foreach ($temporary_keywords as $keyword) {
            if (strpos($message_lower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 获取重试统计信息
     *
     * @since    1.1.2
     * @return   array    重试统计信息数组
     */
    public static function get_retry_stats() {
        return self::$retry_stats;
    }

    /**
     * 重置重试统计信息
     *
     * @since    1.1.2
     */
    public static function reset_retry_stats() {
        self::$retry_stats = [
            'total_attempts' => 0,
            'successful_retries' => 0,
            'failed_retries' => 0,
            'permanent_errors' => 0,
            'total_delay_time' => 0,
            'smart_retries' => 0,
            'rate_limit_retries' => 0,
            'server_error_retries' => 0,
            'network_error_retries' => 0,
            'avg_delay_time' => 0
        ];

        \NTWP\Core\Foundation\Logger::debug_log(
            '重试统计信息已重置',
            'Network Retry'
        );
    }

    /**
     * 添加自定义临时性错误类型
     *
     * @since    1.1.2
     * @param    mixed    $error_code    错误代码（HTTP状态码或cURL错误码）
     */
    public static function add_temporary_error($error_code) {
        if (!in_array($error_code, self::$temporary_errors)) {
            self::$temporary_errors[] = $error_code;

            \NTWP\Core\Foundation\Logger::debug_log(
                "添加临时性错误类型: {$error_code}",
                'Network Retry'
            );
        }
    }

    /**
     * 添加自定义永久性错误类型
     *
     * @since    1.1.2
     * @param    mixed    $error_code    错误代码（HTTP状态码或cURL错误码）
     */
    public static function add_permanent_error($error_code) {
        if (!in_array($error_code, self::$permanent_errors)) {
            self::$permanent_errors[] = $error_code;

            \NTWP\Core\Foundation\Logger::debug_log(
                "添加永久性错误类型: {$error_code}",
                'Network Retry'
            );
        }
    }

    /**
     * 为WordPress HTTP请求添加重试功能
     *
     * @since    1.1.2
     * @param    string    $url         请求URL
     * @param    array     $args        请求参数
     * @param    int       $max_retries 最大重试次数
     * @param    int       $base_delay  基础延迟时间（毫秒）
     * @return   array|WP_Error         响应数组或错误对象
     */
    public static function wp_remote_request_with_retry($url, $args = [], $max_retries = self::DEFAULT_MAX_RETRIES, $base_delay = self::DEFAULT_BASE_DELAY) {
        return self::with_retry(function() use ($url, $args) {
            $response = wp_remote_request($url, $args);

            // 检查WordPress错误
            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }

            // 检查HTTP状态码
            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code >= 400) {
                throw new \Exception("HTTP错误 {$http_code}");
            }

            return $response;

        }, $max_retries, $base_delay);
    }

    /**
     * 为WordPress GET请求添加重试功能
     *
     * @since    1.1.2
     * @param    string    $url         请求URL
     * @param    array     $args        请求参数
     * @param    int       $max_retries 最大重试次数
     * @param    int       $base_delay  基础延迟时间（毫秒）
     * @return   array|WP_Error         响应数组或错误对象
     */
    public static function wp_remote_get_with_retry($url, $args = [], $max_retries = self::DEFAULT_MAX_RETRIES, $base_delay = self::DEFAULT_BASE_DELAY) {
        $args['method'] = 'GET';
        return self::wp_remote_request_with_retry($url, $args, $max_retries, $base_delay);
    }

    /**
     * 为WordPress POST请求添加重试功能
     *
     * @since    1.1.2
     * @param    string    $url         请求URL
     * @param    array     $args        请求参数
     * @param    int       $max_retries 最大重试次数
     * @param    int       $base_delay  基础延迟时间（毫秒）
     * @return   array|WP_Error         响应数组或错误对象
     */
    public static function wp_remote_post_with_retry($url, $args = [], $max_retries = self::DEFAULT_MAX_RETRIES, $base_delay = self::DEFAULT_BASE_DELAY) {
        $args['method'] = 'POST';
        return self::wp_remote_request_with_retry($url, $args, $max_retries, $base_delay);
    }

    /**
     * 计算下一次重试的延迟时间
     *
     * @since    1.1.2
     * @param    int    $attempt     当前尝试次数（从0开始）
     * @param    int    $base_delay  基础延迟时间（毫秒）
     * @return   int                 延迟时间（毫秒）
     */
    public static function calculate_delay($attempt, $base_delay = self::DEFAULT_BASE_DELAY) {
        return $base_delay * pow(2, $attempt);
    }

    /**
     * 获取错误类型描述
     *
     * @since    1.1.2
     * @param    Exception    $exception    异常对象
     * @return   string                     错误类型描述
     */
    public static function get_error_type_description($exception) {
        if (self::is_permanent_error($exception)) {
            return '永久性错误';
        } elseif (self::is_temporary_error($exception)) {
            return '临时性错误';
        } else {
            return '未知错误类型';
        }
    }

    /**
     * 记录重试详细日志
     *
     * @since    1.1.2
     * @param    int         $attempt      当前尝试次数
     * @param    int         $max_retries  最大重试次数
     * @param    Exception   $exception    异常对象
     * @param    int         $delay        延迟时间（毫秒）
     */
    public static function log_retry_attempt($attempt, $max_retries, $exception, $delay = 0) {
        $error_type = self::get_error_type_description($exception);

        if ($delay > 0) {
            \NTWP\Core\Foundation\Logger::debug_log(
                sprintf(
                    '[%s] 重试 %d/%d: %s，%d毫秒后重试',
                    $error_type,
                    $attempt + 1,
                    $max_retries,
                    $exception->getMessage(),
                    $delay
                ),
                'Network Retry'
            );
        } else {
            \NTWP\Core\Foundation\Logger::error_log(
                sprintf(
                    '[%s] 重试失败: %s',
                    $error_type,
                    $exception->getMessage()
                ),
                'Network Retry'
            );
        }
    }

    /**
     * 智能延迟计算方法
     * 根据错误类型动态调整延迟时间
     *
     * @since    2.0.0-beta.1
     * @param    int         $attempt     当前尝试次数（从0开始）
     * @param    Exception   $exception   异常对象
     * @param    int         $base_delay  基础延迟时间（毫秒）
     * @return   int                      计算后的延迟时间（毫秒）
     */
    private static function calculate_smart_delay($attempt, $exception, $base_delay = self::DEFAULT_BASE_DELAY) {
        $message = $exception->getMessage();
        $message_lower = strtolower($message);

        // 检查是否为WordPress错误
        if (is_wp_error($exception)) {
            $error_code = $exception->get_error_code();

            switch ($error_code) {
                case 'http_request_failed':
                case 'rate_limit':
                case 'too_many_requests':
                    // 速率限制：使用更长的延迟，指数增长系数为2.5
                    return (int)($base_delay * pow(2.5, $attempt) * 2);

                case 'server_error':
                case 'internal_server_error':
                case 'bad_gateway':
                case 'service_unavailable':
                case 'gateway_timeout':
                    // 服务器错误：使用中等延迟，指数增长系数为1.8
                    return (int)($base_delay * pow(1.8, $attempt) * 1.5);

                case 'network_timeout':
                case 'connection_timeout':
                case 'connect_error':
                    // 网络超时：使用标准延迟，指数增长系数为2
                    return (int)($base_delay * pow(2, $attempt));

                default:
                    // 其他错误：使用标准指数退避
                    return (int)($base_delay * pow(2, $attempt));
            }
        }

        // 检查HTTP状态码
        if (preg_match('/HTTP错误\s+(\d+)/', $message, $matches)) {
            $http_code = (int)$matches[1];

            switch ($http_code) {
                case 429: // Too Many Requests
                    return (int)($base_delay * pow(2.5, $attempt) * 3); // 最长延迟

                case 500: // Internal Server Error
                case 502: // Bad Gateway
                case 503: // Service Unavailable
                case 504: // Gateway Timeout
                    return (int)($base_delay * pow(1.8, $attempt) * 1.5);

                default:
                    return (int)($base_delay * pow(2, $attempt));
            }
        }

        // 检查cURL错误
        if (preg_match('/cURL错误\s+(\d+)/', $message, $matches)) {
            $curl_code = (int)$matches[1];

            switch ($curl_code) {
                case CURLE_OPERATION_TIMEOUTED: // 28 - 操作超时
                case CURLE_RECV_ERROR:          // 56 - 接收数据错误
                case CURLE_SEND_ERROR:          // 55 - 发送数据错误
                    return (int)($base_delay * pow(2, $attempt) * 1.2);

                case CURLE_COULDNT_CONNECT:     // 7 - 无法连接
                case CURLE_COULDNT_RESOLVE_HOST: // 6 - 无法解析主机
                    return (int)($base_delay * pow(2.2, $attempt) * 1.5);

                default:
                    return (int)($base_delay * pow(2, $attempt));
            }
        }

        // 检查错误消息关键词
        if (strpos($message_lower, 'rate limit') !== false ||
            strpos($message_lower, 'too many requests') !== false ||
            strpos($message_lower, '限制') !== false) {
            return (int)($base_delay * pow(2.5, $attempt) * 2);
        }

        if (strpos($message_lower, 'server error') !== false ||
            strpos($message_lower, 'internal error') !== false ||
            strpos($message_lower, '服务器错误') !== false) {
            return (int)($base_delay * pow(1.8, $attempt) * 1.5);
        }

        if (strpos($message_lower, 'timeout') !== false ||
            strpos($message_lower, 'connection') !== false ||
            strpos($message_lower, '超时') !== false ||
            strpos($message_lower, '连接') !== false) {
            return (int)($base_delay * pow(2, $attempt) * 1.2);
        }

        // 默认使用标准指数退避
        return (int)($base_delay * pow(2, $attempt));
    }

    /**
     * 记录错误类型统计
     *
     * @since    2.0.0-beta.1
     * @param    Exception   $exception   异常对象
     */
    private static function record_error_type_stats($exception) {
        $message = $exception->getMessage();
        $message_lower = strtolower($message);

        // 检查是否为WordPress错误
        if (is_wp_error($exception)) {
            $error_code = $exception->get_error_code();

            if (in_array($error_code, ['rate_limit', 'too_many_requests'])) {
                self::$retry_stats['rate_limit_retries']++;
                return;
            }

            if (in_array($error_code, ['server_error', 'internal_server_error', 'bad_gateway', 'service_unavailable', 'gateway_timeout'])) {
                self::$retry_stats['server_error_retries']++;
                return;
            }

            if (in_array($error_code, ['network_timeout', 'connection_timeout', 'connect_error'])) {
                self::$retry_stats['network_error_retries']++;
                return;
            }
        }

        // 检查HTTP状态码
        if (preg_match('/HTTP错误\s+(\d+)/', $message, $matches)) {
            $http_code = (int)$matches[1];

            if ($http_code === 429) {
                self::$retry_stats['rate_limit_retries']++;
                return;
            }

            if (in_array($http_code, [500, 502, 503, 504])) {
                self::$retry_stats['server_error_retries']++;
                return;
            }
        }

        // 检查错误消息关键词
        if (strpos($message_lower, 'rate limit') !== false ||
            strpos($message_lower, 'too many requests') !== false ||
            strpos($message_lower, '限制') !== false) {
            self::$retry_stats['rate_limit_retries']++;
        } elseif (strpos($message_lower, 'server error') !== false ||
                  strpos($message_lower, 'internal error') !== false ||
                  strpos($message_lower, '服务器错误') !== false) {
            self::$retry_stats['server_error_retries']++;
        } elseif (strpos($message_lower, 'timeout') !== false ||
                  strpos($message_lower, 'connection') !== false ||
                  strpos($message_lower, '超时') !== false ||
                  strpos($message_lower, '连接') !== false) {
            self::$retry_stats['network_error_retries']++;
        }
    }

    /**
     * 获取增强的重试统计信息，包含智能重试数据
     *
     * @since    2.0.0-beta.1
     * @return   array    增强的重试统计信息数组
     */
    public static function get_enhanced_retry_stats() {
        $stats = self::$retry_stats;

        // 计算平均延迟时间
        if ($stats['smart_retries'] > 0) {
            $stats['avg_delay_time'] = round($stats['total_delay_time'] / $stats['smart_retries'], 2);
        }

        // 计算成功率
        if ($stats['total_attempts'] > 0) {
            $stats['success_rate'] = round(($stats['successful_retries'] / $stats['total_attempts']) * 100, 2);
        } else {
            $stats['success_rate'] = 0;
        }

        // 计算错误类型分布
        $total_errors = $stats['rate_limit_retries'] + $stats['server_error_retries'] + $stats['network_error_retries'];
        if ($total_errors > 0) {
            $stats['error_distribution'] = [
                'rate_limit_percentage' => round(($stats['rate_limit_retries'] / $total_errors) * 100, 2),
                'server_error_percentage' => round(($stats['server_error_retries'] / $total_errors) * 100, 2),
                'network_error_percentage' => round(($stats['network_error_retries'] / $total_errors) * 100, 2)
            ];
        } else {
            $stats['error_distribution'] = [
                'rate_limit_percentage' => 0,
                'server_error_percentage' => 0,
                'network_error_percentage' => 0
            ];
        }

        return $stats;
    }

    /**
     * 集成到性能监控系统
     * 将重试统计信息发送到PerformanceMonitor
     *
     * @since    2.0.0-beta.1
     */
    public static function integrate_with_performance_monitor() {
        if (!class_exists('\\NTWP\\Core\\Performance\\PerformanceMonitor')) {
            return;
        }

        $stats = self::get_enhanced_retry_stats();

        // 记录重试性能数据
        if (method_exists('PerformanceMonitor', 'record_custom_metric')) {
            \NTWP\Core\Foundation\Performance\PerformanceMonitor::record_custom_metric('network_retry_success_rate', $stats['success_rate']);
            \NTWP\Core\Foundation\Performance\PerformanceMonitor::record_custom_metric('network_retry_avg_delay', $stats['avg_delay_time']);
            \NTWP\Core\Foundation\Performance\PerformanceMonitor::record_custom_metric('network_retry_smart_count', $stats['smart_retries']);
        }

        // 记录到日志以便监控
        if ($stats['total_attempts'] > 0) {
            \NTWP\Core\Foundation\Logger::info_log(
                sprintf(
                    '智能重试统计: 总尝试=%d, 成功率=%.2f%%, 平均延迟=%.2fms, 智能重试=%d',
                    $stats['total_attempts'],
                    $stats['success_rate'],
                    $stats['avg_delay_time'],
                    $stats['smart_retries']
                ),
                'Network Retry Performance'
            );
        }
    }

    /**
     * 扩展的is_permanent_error方法，增加更多错误类型判断
     *
     * @since    2.0.0-beta.1
     * @param    Exception    $exception    异常对象
     * @return   bool                       是否为永久性错误
     */
    public static function is_permanent_error_enhanced($exception) {
        // 首先使用原有的判断逻辑
        if (self::is_permanent_error($exception)) {
            return true;
        }

        $message = $exception->getMessage();
        $message_lower = strtolower($message);

        // 增加更多永久性错误类型判断
        $additional_permanent_keywords = [
            'api key',
            'api_key',
            'access denied',
            'insufficient permissions',
            'quota exceeded',
            'account suspended',
            'invalid token',
            'expired token',
            'malformed request',
            'unsupported operation',
            'resource not found',
            'method not allowed',
            'content too large',
            'payload too large',
            'API密钥',
            '访问被拒绝',
            '权限不足',
            '配额超出',
            '账户暂停',
            '令牌无效',
            '令牌过期',
            '请求格式错误',
            '不支持的操作',
            '资源未找到',
            '方法不允许',
            '内容过大'
        ];

        foreach ($additional_permanent_keywords as $keyword) {
            if (strpos($message_lower, $keyword) !== false) {
                return true;
            }
        }

        // 检查特定的HTTP状态码
        if (preg_match('/HTTP错误\s+(\d+)/', $message, $matches)) {
            $http_code = (int)$matches[1];
            $additional_permanent_codes = [407, 408, 409, 411, 412, 413, 414, 415, 416, 417, 426, 428, 431, 451];
            if (in_array($http_code, $additional_permanent_codes)) {
                return true;
            }
        }

        return false;
    }
}
