<?php
declare(strict_types=1);

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

class Notion_Network_Retry {

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
    private static $temporary_errors = [
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

    /**
     * 永久性错误类型
     *
     * @since    1.1.2
     * @access   private
     * @var      array    $permanent_errors    永久性错误类型数组
     */
    private static $permanent_errors = [
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
        'total_delay_time' => 0
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
        $last_exception = null;
        $start_time = microtime(true);
        
        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            self::$retry_stats['total_attempts']++;
            
            try {
                $result = call_user_func($callback);
                
                // 成功执行
                if ($attempt > 0) {
                    self::$retry_stats['successful_retries']++;
                    
                    Notion_Logger::debug_log(
                        sprintf(
                            '重试成功：第 %d 次尝试成功，总耗时: %.2f秒',
                            $attempt + 1,
                            microtime(true) - $start_time
                        ),
                        'Network Retry'
                    );
                }
                
                return $result;
                
            } catch (Exception $e) {
                $last_exception = $e;
                
                // 检查是否为永久性错误
                if (self::is_permanent_error($e)) {
                    self::$retry_stats['permanent_errors']++;
                    
                    Notion_Logger::debug_log(
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
                    
                    Notion_Logger::error_log(
                        sprintf(
                            '重试失败：已达到最大重试次数 %d，最后错误: %s',
                            $max_retries,
                            $e->getMessage()
                        ),
                        'Network Retry'
                    );
                    
                    break;
                }
                
                // 计算延迟时间（指数退避）
                $delay = $base_delay * pow(2, $attempt);
                self::$retry_stats['total_delay_time'] += $delay;
                
                Notion_Logger::debug_log(
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
            'total_delay_time' => 0
        ];

        Notion_Logger::debug_log(
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

            Notion_Logger::debug_log(
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

            Notion_Logger::debug_log(
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
                throw new Exception($response->get_error_message());
            }

            // 检查HTTP状态码
            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code >= 400) {
                throw new Exception("HTTP错误 {$http_code}");
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
            Notion_Logger::debug_log(
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
            Notion_Logger::error_log(
                sprintf(
                    '[%s] 重试失败: %s',
                    $error_type,
                    $exception->getMessage()
                ),
                'Network Retry'
            );
        }
    }
}
