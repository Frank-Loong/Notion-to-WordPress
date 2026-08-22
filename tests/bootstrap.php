<?php
/**
 * PHPUnit 测试引导文件
 *
 * 为单元测试提供最小的 WordPress 函数桩（stub），
 * 使核心纯逻辑类（如 Incremental_Detector）可在无完整 WordPress 环境下测试。
 *
 * @since 2.0.0
 */

declare(strict_types=1);

// 防止直接访问
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// ==================== 最小 WordPress 全局函数桩 ====================

// 全局 post meta 存储（模拟数据库）
$GLOBALS['__wp_post_meta'] = [];
$GLOBALS['__wp_options'] = [];
$GLOBALS['__wp_posts'] = [];

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        $meta = $GLOBALS['__wp_post_meta'][$post_id] ?? [];
        if ($key === '') {
            return $single ? ($meta ?: '') : $meta;
        }
        if ($single) {
            return $meta[$key] ?? '';
        }
        return isset($meta[$key]) ? [$meta[$key]] : [];
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        if (!isset($GLOBALS['__wp_post_meta'][$post_id])) {
            $GLOBALS['__wp_post_meta'][$post_id] = [];
        }
        $GLOBALS['__wp_post_meta'][$post_id][$key] = $value;
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $GLOBALS['__wp_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        $GLOBALS['__wp_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title($post_id = 0) {
        return $GLOBALS['__wp_posts'][$post_id]['post_title'] ?? '';
    }
}

if (!function_exists('get_post')) {
    function get_post($post_id) {
        return isset($GLOBALS['__wp_posts'][$post_id]) ? (object) $GLOBALS['__wp_posts'][$post_id] : null;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

// 载入 Composer 自动加载（若存在）
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // 无 composer autoload 时的最小 PSR-4 自动加载
    spl_autoload_register(function ($class) {
        $prefix = 'NTWP\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $parts = explode('\\', $relative);
        // NTWP\Core\Foo -> includes/core/Foo.php
        // NTWP\Services\Foo -> includes/services/Foo.php
        // NTWP\Handlers\Foo -> includes/handlers/Foo.php
        // NTWP\Utils\Foo -> includes/utils/Foo.php
        $top = strtolower($parts[0]);
        $rest = implode('/', array_slice($parts, 1));
        $path = __DIR__ . "/../includes/{$top}/{$rest}.php";
        if (file_exists($path)) {
            require_once $path;
        }
    });
}
