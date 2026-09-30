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

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('NOTION_TO_WORDPRESS_VERSION')) {
    define('NOTION_TO_WORDPRESS_VERSION', '2.0.0-beta.1');
}

// ==================== 最小 WordPress 全局函数桩 ====================

// 全局 post meta 存储（模拟数据库）
$GLOBALS['__wp_post_meta'] = [];
$GLOBALS['__wp_options'] = [];
$GLOBALS['__wp_posts'] = [];

if (!class_exists('WP_Error')) {
    class WP_Error {
        private string $code;
        private string $message;

        public function __construct(string $code = '', string $message = '') {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return $thing instanceof WP_Error;
    }
}

if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $postmeta = 'wp_postmeta';
        public string $posts = 'wp_posts';

        public function prepare(string $query, ...$arguments): string {
            return $query;
        }

        public function get_var(string $query) {
            return 0;
        }
    };
}

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

if (!function_exists('size_format')) {
    function size_format($bytes, $decimals = 0): string {
        return number_format((float) $bytes, (int) $decimals) . ' B';
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists($post_type): bool {
        return $post_type === 'post';
    }
}

if (!function_exists('get_post_statuses')) {
    function get_post_statuses(): array {
        return [ 'draft' => 'Draft', 'publish' => 'Published' ];
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return rtrim(dirname((string) $file), '/\\') . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url($url, $component);
    }
}

if (!function_exists('home_url')) {
    function home_url($path = ''): string {
        return 'https://example.test' . $path;
    }
}

if (!function_exists('wp_kses_allowed_html')) {
    function wp_kses_allowed_html($context = 'post'): array {
        return [
            'a' => [ 'href' => true, 'class' => true, 'id' => true ],
            'div' => [ 'class' => true, 'id' => true ],
            'span' => [ 'class' => true, 'id' => true ],
            'p' => [ 'class' => true, 'id' => true ],
        ];
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses($content, $allowed_html): string {
        return (string) $content;
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

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var((string) $url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $filename);
        return trim((string) $filename, '-');
    }
}

if (!function_exists('wp_convert_hr_to_bytes')) {
    function wp_convert_hr_to_bytes($value): int {
        $value = strtolower(trim((string) $value));
        $bytes = (int) $value;
        if (str_ends_with($value, 'g')) {
            $bytes *= 1024 * 1024 * 1024;
        } elseif (str_ends_with($value, 'm')) {
            $bytes *= 1024 * 1024;
        } elseif (str_ends_with($value, 'k')) {
            $bytes *= 1024;
        }
        return $bytes;
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
