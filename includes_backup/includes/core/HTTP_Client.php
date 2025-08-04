<?php
declare(strict_types=1);

namespace NTWP\Core;

/**
 * Notion HTTP客户端类
 * 
 * 专门处理插件的HTTP请求功能，包括安全的远程请求、错误处理、重试机制等。
 * 统一网络请求处理逻辑，实现单一职责原则，从Helper类中分离出来。
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

class HTTP_Client {

    /**
     * 默认请求超时时间（秒）
     */
    const DEFAULT_TIMEOUT = 30;

    /**
     * 默认用户代理
     */
    const DEFAULT_USER_AGENT = 'Notion-to-WordPress';

    /**
     * 安全地获取远程内容
     *
     * @since 2.0.0-beta.1
     * @param string $url 要获取的URL
     * @param array $args 请求参数
     * @return array|WP_Error 响应数组或错误对象
     */
    public static function safe_remote_get(string $url, array $args = []) {
        // 默认参数
        $default_args = [
            'timeout' => self::DEFAULT_TIMEOUT,
            'user-agent' => self::get_user_agent(),
            'headers' => [
                'Accept' => 'application/json',
            ],
            'sslverify' => true,
        ];

        $args = wp_parse_args($args, $default_args);

        // 验证URL
        if (!self::is_valid_url($url)) {
            return new WP_Error('invalid_url', __('无效的URL', 'notion-to-wordpress'));
        }

        // 执行请求
        $response = wp_remote_get($url, $args);

        // 处理响应
        return self::process_response($response, $url);
    }

    /**
     * 安全地发送POST请求
     *
     * @since 2.0.0-beta.1
     * @param string $url 要请求的URL
     * @param array $data 要发送的数据
     * @param array $args 请求参数
     * @return array|WP_Error 响应数组或错误对象
     */
    public static function safe_remote_post(string $url, array $data = [], array $args = []) {
        // 默认参数
        $default_args = [
            'timeout' => self::DEFAULT_TIMEOUT,
            'user-agent' => self::get_user_agent(),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($data),
            'sslverify' => true,
        ];

        $args = wp_parse_args($args, $default_args);

        // 验证URL
        if (!self::is_valid_url($url)) {
            return new WP_Error('invalid_url', __('无效的URL', 'notion-to-wordpress'));
        }

        // 执行请求
        $response = wp_remote_post($url, $args);

        // 处理响应
        return self::process_response($response, $url);
    }

    /**
     * 带重试机制的安全远程请求
     *
     * @since 2.0.0-beta.1
     * @param string $url 要请求的URL
     * @param array $args 请求参数
     * @param int $max_retries 最大重试次数
     * @return array|WP_Error 响应数组或错误对象
     */
    public static function safe_remote_get_with_retry(string $url, array $args = [], int $max_retries = 3) {
        // 如果Network_Retry类存在，使用它的重试机制
        if (class_exists('NTWP\Utils\Network_Retry')) {
            return \NTWP\Utils\Network_Retry::execute_with_retry(function() use ($url, $args) {
                return self::safe_remote_get($url, $args);
            }, $max_retries);
        }

        // 否则使用简单的重试机制
        $last_error = null;
        for ($i = 0; $i <= $max_retries; $i++) {
            $response = self::safe_remote_get($url, $args);
            
            if (!is_wp_error($response)) {
                return $response;
            }
            
            $last_error = $response;
            
            // 如果不是最后一次尝试，等待一段时间
            if ($i < $max_retries) {
                sleep(pow(2, $i)); // 指数退避
            }
        }
        
        return $last_error;
    }

    /**
     * 生成一个安全的、唯一的令牌
     *
     * @since 2.0.0-beta.1
     * @param int $length 令牌的长度
     * @return string 生成的唯一令牌
     */
    public static function generate_token(int $length = 32): string {
        return wp_generate_password($length, false, false);
    }

    /**
     * 生成随机字符串
     *
     * @since 2.0.0-beta.1
     * @param int $length 字符串长度
     * @param bool $include_special 是否包含特殊字符
     * @return string 随机字符串
     */
    public static function generate_random_string(int $length = 16, bool $include_special = false): string {
        return wp_generate_password($length, $include_special, false);
    }

    /**
     * 验证URL是否有效
     *
     * @since 2.0.0-beta.1
     * @param string $url 要验证的URL
     * @return bool 是否有效
     */
    private static function is_valid_url(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 处理HTTP响应
     *
     * @since 2.0.0-beta.1
     * @param array|WP_Error $response HTTP响应
     * @param string $url 请求的URL
     * @return array|WP_Error 处理后的响应
     */
    private static function process_response($response, string $url) {
        // 检查错误
        if (is_wp_error($response)) {
            Logger::error_log(__('远程请求失败: ', 'notion-to-wordpress') . $response->get_error_message(), 'HTTP');
            return $response;
        }

        // 检查HTTP状态码
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $error_msg = sprintf(__('HTTP错误 %d: %s', 'notion-to-wordpress'), $status_code, wp_remote_retrieve_response_message($response));
            Logger::error_log($error_msg, 'HTTP');
            return new WP_Error('http_error', $error_msg, ['status_code' => $status_code]);
        }

        return $response;
    }

    /**
     * 获取用户代理字符串
     *
     * @since 2.0.0-beta.1
     * @return string 用户代理字符串
     */
    private static function get_user_agent(): string {
        $version = defined('NOTION_TO_WORDPRESS_VERSION') ? NOTION_TO_WORDPRESS_VERSION : '2.0.0';
        return self::DEFAULT_USER_AGENT . '/' . $version;
    }

    /**
     * 解析JSON响应
     *
     * @since 2.0.0-beta.1
     * @param array|WP_Error $response HTTP响应
     * @return array|WP_Error 解析后的数据或错误
     */
    public static function parse_json_response($response) {
        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new WP_Error('empty_response', __('响应内容为空', 'notion-to-wordpress'));
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_decode_error', __('JSON解析失败: ', 'notion-to-wordpress') . json_last_error_msg());
        }

        return $data;
    }

    /**
     * 检查响应是否成功
     *
     * @since 2.0.0-beta.1
     * @param array|WP_Error $response HTTP响应
     * @return bool 是否成功
     */
    public static function is_response_successful($response): bool {
        if (is_wp_error($response)) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        return $status_code >= 200 && $status_code < 300;
    }

    /**
     * 获取响应状态码
     *
     * @since 2.0.0-beta.1
     * @param array|WP_Error $response HTTP响应
     * @return int|null 状态码或null
     */
    public static function get_response_status_code($response): ?int {
        if (is_wp_error($response)) {
            return null;
        }

        return wp_remote_retrieve_response_code($response);
    }

    /**
     * 获取响应头
     *
     * @since 2.0.0-beta.1
     * @param array|WP_Error $response HTTP响应
     * @param string|null $header 特定头名称，为null时返回所有头
     * @return array|string|null 响应头
     */
    public static function get_response_header($response, ?string $header = null) {
        if (is_wp_error($response)) {
            return null;
        }

        if ($header) {
            return wp_remote_retrieve_header($response, $header);
        }

        return wp_remote_retrieve_headers($response);
    }

    /**
     * 设置请求认证
     *
     * @since 2.0.0-beta.1
     * @param array $args 请求参数
     * @param string $token 认证令牌
     * @param string $type 认证类型 ('Bearer', 'Basic')
     * @return array 更新后的请求参数
     */
    public static function set_auth(array $args, string $token, string $type = 'Bearer'): array {
        if (!isset($args['headers'])) {
            $args['headers'] = [];
        }

        $args['headers']['Authorization'] = $type . ' ' . $token;
        return $args;
    }

    /**
     * 设置请求超时
     *
     * @since 2.0.0-beta.1
     * @param array $args 请求参数
     * @param int $timeout 超时时间（秒）
     * @return array 更新后的请求参数
     */
    public static function set_timeout(array $args, int $timeout): array {
        $args['timeout'] = $timeout;
        return $args;
    }
}
