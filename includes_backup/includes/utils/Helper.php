<?php
declare(strict_types=1);

namespace NTWP\Utils;

/**
 * 纯工具辅助类
 *
 * 提供一系列静态工具方法，包括数据处理、路径管理、状态映射等基础功能。
 * 专门的功能已迁移到对应的专门类中：
 * - 日志功能 → NTWP\Core\Logger
 * - 安全过滤 → NTWP\Core\Security
 * - 文本处理 → NTWP\Core\Text_Processor
 * - HTTP请求 → NTWP\Core\HTTP_Client
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

class Helper {

    // ==================== 已移除废弃常量 ====================
    // 注意：调试级别常量已移除，请使用 NTWP\Core\Logger 类中的常量

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

    // ==================== 已移除废弃的委托方法 ====================
    // 注意：所有委托方法已移除，请直接使用对应的专门类：
    // - 日志功能 → NTWP\Core\Logger
    // - 安全过滤 → NTWP\Core\Security
    // - 文本处理 → NTWP\Core\Text_Processor
    // - HTTP请求 → NTWP\Core\HTTP_Client


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
        if (class_exists('NTWP\\Core\\Logger')) {
            \NTWP\Core\Logger::init();
        }
    }

}