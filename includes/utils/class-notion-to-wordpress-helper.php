<?php
declare(strict_types=1);

/**
 * 纯工具辅助类
 *
 * 提供一系列静态工具方法，包括数据处理、路径管理、状态映射等基础功能。
 * 专门的功能已迁移到对应的专门类中：
 * - 日志功能 → Notion_Logger
 * - 安全过滤 → Notion_Security
 * - 文本处理 → Notion_Text_Processor
 * - HTTP请求 → Notion_HTTP_Client
 *
 * 本类保留委托方法以确保向后兼容性，建议使用对应的专门类。
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

class Notion_To_WordPress_Helper {

    // ==================== 向后兼容常量 ====================

    /**
     * 调试级别常量 - 保留以确保向后兼容性
     * @deprecated 2.0.0-beta.1 使用 Notion_Logger 类中的常量代替
     */
    const DEBUG_LEVEL_NONE = 0;    // 不记录任何日志
    const DEBUG_LEVEL_ERROR = 1;   // 只记录错误
    const DEBUG_LEVEL_WARNING = 2; // 记录警告
    const DEBUG_LEVEL_INFO = 3;    // 记录错误、警告和信息
    const DEBUG_LEVEL_DEBUG = 4;   // 记录所有内容，包括详细调试信息

    // ==================== 纯工具方法 ====================

    /**
     * 安全地从数组中获取一个值。
     *
     * @since    1.0.8
     * @param    array     $array      目标数组。
     * @param    string|int $key       数组的键。
     * @param    mixed     $default    如果键不存在时返回的默认值。
     * @return   mixed                 数组中的值或默认值。
     */
    public static function get_array_value($array, $key, $default = null) {
        return isset($array[$key]) ? $array[$key] : $default;
    }

    /**
     * 将 Notion 的日期字符串格式化为本地化的 WordPress 日期。
     *
     * @since    1.0.8
     * @param    string    $date_string Notion 返回的 ISO 8601 日期字符串。
     * @param    string    $format      期望的输出日期格式。
     * @return   string                格式化后的日期字符串。
     */
    public static function format_date($date_string, $format = 'Y-m-d H:i:s') {
        if (empty($date_string)) {
            return '';
        }

        $timestamp = strtotime($date_string);
        return date_i18n($format, $timestamp);
    }

    /**
     * 在后台显示通知消息。
     *
     * @since 1.0.8
     * @param string $message 要显示的消息。
     * @param string $type    通知类型 ('success', 'warning', 'error', 'info')。
     */
    public static function admin_notice($message, $type = 'error') {
        add_action('admin_notices', function() use ($message, $type) {
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        });
    }

    /**
     * 获取插件文件或目录的绝对服务器路径。
     *
     * @since 1.1.0
     * @param string $path （可选）相对于插件根目录的路径。
     * @return string 绝对服务器路径。
     */
    public static function plugin_path(string $path = ''): string {
        return plugin_dir_path(NOTION_TO_WORDPRESS_FILE) . ltrim($path, '/\\');
    }

    /**
     * 获取插件文件或目录的URL。
     *
     * @since 1.1.0
     * @param string $path （可选）相对于插件根目录的路径。
     * @return string 插件资源的URL。
     */
    public static function plugin_url(string $path = ''): string {
        return plugin_dir_url(NOTION_TO_WORDPRESS_FILE) . ltrim($path, '/\\');
    }

    /**
     * 获取WordPress文章状态映射
     *
     * @since 1.0.9
     * @return array 状态映射数组
     */
    public static function get_post_status_mapping(): array {
        return [
            // 已发布状态
            'published' => 'publish',
            '已发布' => 'publish',
            'publish' => 'publish',
            'public' => 'publish',
            '公开' => 'publish',
            'live' => 'publish',
            '上线' => 'publish',

            // 私密状态
            'private' => 'private',
            'privacy' => 'private', // 增加可能的变体
            '私密' => 'private',
            'private_post' => 'private',

            // 草稿状态
            'draft' => 'draft',
            '草稿' => 'draft',
            'unpublished' => 'draft',
            '未发布' => 'draft',
        ];
    }

    /**
     * 标准化Notion状态到WordPress状态
     *
     * @since 1.0.9
     * @param string $notion_status Notion中的状态值
     * @return string WordPress标准状态
     */
    public static function normalize_post_status(string $notion_status): string {
        $mapping = self::get_post_status_mapping();
        $status_lower = strtolower(trim($notion_status));

        return $mapping[$status_lower] ?? 'draft';
    }

    /**
     * 根据插件语言设置格式化日期时间
     *
     * @since    1.1.0
     * @param    int|string    $timestamp 时间戳或时间字符串
     * @return   string                   格式化后的日期时间字符串
     */
    public static function format_datetime_by_plugin_language($timestamp) {
        // 如果传入的是空值，返回空字符串
        if (empty($timestamp)) {
            return '';
        }

        // 确保时间戳是整数格式
        if (!is_numeric($timestamp)) {
            $timestamp = strtotime($timestamp);
        }

        // 如果时间戳无效，返回空字符串
        if ($timestamp === false || $timestamp <= 0) {
            return '';
        }

        // 获取插件选项
        $options = get_option('notion_to_wordpress_options', []);
        $plugin_language = $options['plugin_language'] ?? 'auto';

        // 向后兼容：如果没有新设置但有旧设置
        if ($plugin_language === 'auto' && !empty($options['force_english_ui'])) {
            $plugin_language = 'en_US';
        }

        // 根据语言设置选择时间格式
        $format = '';
        switch ($plugin_language) {
            case 'zh_CN':
                $format = 'Y年n月j日 A g:i';
                break;
            case 'en_US':
                $format = 'M j, Y g:i A';
                break;
            case 'auto':
            default:
                // 根据WordPress的locale自动选择格式
                $locale = get_locale();
                if (strpos($locale, 'zh') === 0) {
                    $format = 'Y年n月j日 A g:i';
                } else {
                    $format = 'M j, Y g:i A';
                }
                break;
        }

        // 使用WordPress的国际化日期函数
        return date_i18n($format, $timestamp);
    }

    /**
     * 格式化字节数
     *
     * @since 1.1.1
     * @param int $bytes 字节数
     * @return string 格式化后的字符串
     */
    public static function format_bytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    // ==================== 委托方法（向后兼容） ====================

    /**
     * 优化版的日志记录方法。
     *
     * @since    1.0.8
     * @deprecated 2.0.0-beta.1 使用 Notion_Logger::debug_log() 代替
     * @param    mixed     $data       要记录的数据（字符串、数组或对象）。
     * @param    string    $prefix     日志条目的前缀。
     * @param    int       $level      此日志条目的级别。
     */
    public static function debug_log($data, $prefix = 'Notion Debug', $level = self::DEBUG_LEVEL_DEBUG) {
        // 委托给专门的日志记录器
        Notion_Logger::debug_log($data, $prefix, $level);
    }

    /**
     * 记录错误级别的日志消息。
     *
     * @since    1.0.8
     * @deprecated 2.0.0-beta.1 使用 Notion_Logger::error_log() 代替
     * @param    mixed     $data       要记录的数据。
     * @param    string    $prefix     日志前缀。
     */
    public static function error_log($data, $prefix = 'Notion Error') {
        Notion_Logger::error_log($data, $prefix);
    }

    /**
     * 记录信息级别的日志消息。
     *
     * @since    1.0.8
     * @deprecated 2.0.0-beta.1 使用 Notion_Logger::info_log() 代替
     * @param    mixed     $data       要记录的数据。
     * @param    string    $prefix     日志前缀。
     */
    public static function info_log($data, $prefix = 'Notion Info') {
        Notion_Logger::info_log($data, $prefix);
    }

    /**
     * 记录警告级别的日志消息。
     *
     * @since    2.0.0-beta.1
     * @deprecated 2.0.0-beta.1 使用 Notion_Logger::warning_log() 代替
     * @param    mixed     $data       要记录的数据。
     * @param    string    $prefix     日志前缀。
     */
    public static function warning_log($data, $prefix = 'Notion Warning') {
        Notion_Logger::warning_log($data, $prefix);
    }

    /**
     * 生成一个安全的、唯一的令牌。
     *
     * @since    1.0.8
     * @deprecated 2.0.0-beta.1 使用 Notion_HTTP_Client::generate_token() 代替
     * @param    int       $length     令牌的长度。
     * @return   string                 生成的唯一令牌。
     */
    public static function generate_token($length = 32) {
        // 委托给专门的HTTP客户端
        return Notion_HTTP_Client::generate_token($length);
    }



    /**
     * 完整的 Rich Text 处理方法
     *
     * 支持所有格式化功能：粗体、斜体、删除线、下划线、代码、颜色、链接、公式等
     *
     * @since    2.0.0-beta.1
     * @deprecated 2.0.0-beta.1 使用 Notion_Text_Processor::extract_rich_text_complete() 代替
     * @param    array     $rich_text    富文本数组
     * @return   string                  格式化的HTML文本
     */
    public static function extract_rich_text_complete(array $rich_text): string {
        // 委托给专门的文本处理器
        return Notion_Text_Processor::extract_rich_text_complete($rich_text);
    }



    /**
     * 从 Notion 的富文本（rich_text）数组中提取纯文本内容。
     *
     * @since    1.0.8
     * @deprecated 2.0.0-beta.1 使用 Notion_Text_Processor::get_text_from_rich_text() 代替
     * @param    array   $rich_text  Notion 的富文本对象数组。
     * @return   string              连接后的纯文本字符串。
     */
    public static function get_text_from_rich_text(array $rich_text): string {
        // 委托给专门的文本处理器
        return Notion_Text_Processor::get_text_from_rich_text($rich_text);
    }

    /**
     * 检测是否为 Notion 锚点链接
     *
     * @since    2.0.0-beta.1
     * @deprecated 2.0.0-beta.1 使用 Notion_Text_Processor::is_notion_anchor_link() 代替
     * @param    string    $href    链接地址
     * @return   bool              是否为 Notion 锚点链接
     */
    public static function is_notion_anchor_link(string $href): bool {
        // 委托给专门的文本处理器
        return Notion_Text_Processor::is_notion_anchor_link($href);
    }

    /**
     * 将 Notion 锚点链接转换为本地锚点
     *
     * @since    2.0.0-beta.1
     * @deprecated 2.0.0-beta.1 使用 Notion_Text_Processor::convert_notion_anchor_to_local() 代替
     * @param    string    $href    原始链接地址
     * @return   string             转换后的本地锚点链接
     */
    public static function convert_notion_anchor_to_local(string $href): string {
        // 委托给专门的文本处理器
        return Notion_Text_Processor::convert_notion_anchor_to_local($href);
    }

    /**
     * 使用自定义的规则集来清理 HTML 内容，以确保安全。
     *
     * 此函数扩展了 WordPress 的 `wp_kses_post`，以允许
     * 插件功能所需的特定标签和属性（如 iframe、Mermaid 的 pre 标签等）。
     *
     * @since    1.0.8
     * @deprecated 2.0.0-beta.1 使用 Notion_Security::custom_kses() 代替
     * @param    string    $content    需要清理的 HTML 内容。
     * @return   string                清理和过滤后的安全 HTML。
     */
    public static function custom_kses($content) {
        // 委托给专门的安全过滤器
        return Notion_Security::custom_kses($content);
    }

    /**
     * 获取日志文件列表。
     *
     * @since 1.0.8
     * @deprecated 2.0.0-beta.1 使用 Notion_Logger::get_log_files() 代替
     * @return array 文件名数组。
     */
    public static function get_log_files(): array {
        return Notion_Logger::get_log_files();
    }
    
    /**
     * 获取特定日志文件的内容。
     *
     * @since 1.0.8
     * @deprecated 2.0.0-beta.1 使用 Notion_Logger::get_log_content() 代替
     * @param string $filename 日志文件名。
     * @return string|false 文件内容或在失败时返回 false。
     */
    public static function get_log_content(string $filename): string {
        return Notion_Logger::get_log_content($filename);
    }

    /**
     * 清理所有日志文件。
     *
     * @since 1.0.8
     * @deprecated 2.0.0-beta.1 使用 Notion_Logger::clear_logs() 代替
     * @return bool 是否成功。
     */
    public static function clear_logs(): bool {
        return Notion_Logger::clear_logs();
    }
    
    /**
     * 执行日志清理任务。
     *
     * @since 2.0.0
     * @deprecated 2.0.0-beta.1 使用 Notion_Logger::run_log_cleanup() 代替
     */
    public static function run_log_cleanup() {
        Notion_Logger::run_log_cleanup();
    }

    /**
     * 安全地获取远程内容
     *
     * @since 1.0.9
     * @deprecated 2.0.0-beta.1 使用 Notion_HTTP_Client::safe_remote_get() 代替
     * @param string $url 要获取的URL
     * @param array $args 请求参数
     * @return array|WP_Error 响应数组或错误对象
     */
    public static function safe_remote_get(string $url, array $args = []) {
        // 委托给专门的HTTP客户端
        return Notion_HTTP_Client::safe_remote_get($url, $args);
    }

    /**
     * 验证文件类型是否安全
     *
     * @since 1.0.9
     * @deprecated 2.0.0-beta.1 使用 Notion_Security::is_safe_file_type() 代替
     * @param string $filename 文件名
     * @return bool 是否为安全的文件类型
     */
    public static function is_safe_file_type(string $filename): bool {
        // 委托给专门的安全过滤器
        return Notion_Security::is_safe_file_type($filename);
    }

    /**
     * 初始化助手类
     *
     * 为了向后兼容性而保留的方法
     * 实际初始化工作委托给专门的类
     *
     * @since 2.0.0-beta.1
     */
    public static function init(): void {
        // 委托给专门的日志系统
        Notion_Logger::init();

        // 其他初始化工作（如果需要的话）
        // 目前只需要重新初始化日志系统
    }

}