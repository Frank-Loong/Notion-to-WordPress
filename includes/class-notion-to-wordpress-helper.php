<?php
declare(strict_types=1);

/**
 * 插件的辅助工具类
 *
 * 提供一系列静态方法，用于日志记录、安全过滤、数据处理等。
 *
 * @since      1.0.9
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */

// 如果直接访问此文件，则退出
if (!defined('WPINC')) {
    die;
}

class Notion_To_WordPress_Helper {
    /**
     * 调试级别常量
     */
    const DEBUG_LEVEL_NONE = 0;    // 不记录任何日志
    const DEBUG_LEVEL_ERROR = 1;   // 只记录错误
    const DEBUG_LEVEL_INFO = 2;    // 记录错误和信息
    const DEBUG_LEVEL_DEBUG = 3;   // 记录所有内容，包括详细调试信息
    
    /**
     * 当前日志记录级别。
     *
     * @access private
     * @var int
     */
    public static int $debug_level = self::DEBUG_LEVEL_ERROR;
    
    /**
     * 根据WordPress设置初始化日志级别。
     *
     * @since 1.0.8
     */
    public static function init() {
        // 从选项中获取调试级别
        $options = get_option('notion_to_wordpress_options', []);
        self::$debug_level = isset($options['debug_level']) ? (int)$options['debug_level'] : self::DEBUG_LEVEL_ERROR;
        
        // 如果定义了WP_DEBUG并且为true，则至少启用错误级别日志
        if (defined('WP_DEBUG') && WP_DEBUG === true && self::$debug_level < self::DEBUG_LEVEL_ERROR) {
            self::$debug_level = self::DEBUG_LEVEL_ERROR;
        }
        
        // 注册自定义错误处理器
        set_error_handler([__CLASS__, 'custom_error_handler']);
    }

    /**
     * 自定义错误处理器，将PHP错误转为日志
     *
     * @since 1.1.0
     * @param int $errno 错误级别
     * @param string $errstr 错误消息
     * @param string $errfile 发生错误的文件
     * @param int $errline 发生错误的行号
     * @return bool 是否继续使用PHP标准错误处理
     */
    public static function custom_error_handler($errno, $errstr, $errfile, $errline) {
        // 根据错误级别确定日志级别
        $level = self::DEBUG_LEVEL_ERROR;
        
        // 错误类型映射
        $error_type = 'Unknown Error';
        switch ($errno) {
            case E_ERROR:
            case E_USER_ERROR:
                $error_type = 'Fatal Error';
                break;
            case E_WARNING:
            case E_USER_WARNING:
                $error_type = 'Warning';
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
                $error_type = 'Notice';
                $level = self::DEBUG_LEVEL_INFO;
                break;
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                $error_type = 'Deprecated';
                $level = self::DEBUG_LEVEL_INFO;
                break;
        }
        
        // 提取文件名（不含路径）
        $file_name = basename($errfile);
        
        // 记录错误
        self::debug_log(
            "PHP {$error_type}: {$errstr} in {$file_name} on line {$errline}",
            'PHP Error',
            $level
        );
        
        // 返回false表示错误应该由标准PHP错误处理程序继续处理
        return false;
    }

    /**
     * 统一的日志记录方法。
     *
     * @since    1.0.8
     * @param    mixed     $data       要记录的数据（字符串、数组或对象）。
     * @param    string    $prefix     日志条目的前缀。
     * @param    int       $level      此日志条目的级别。
     */
    public static function debug_log($data, $prefix = 'Notion Debug', $level = self::DEBUG_LEVEL_DEBUG) {
        // 如果当前调试级别小于指定级别，则不记录
        if (self::$debug_level < $level) {
            return;
        }
        
        // 获取调用者信息
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]) ? $backtrace[1] : [];
        $caller_info = '';
        
        if (!empty($caller)) {
            $caller_info = ' [' . (isset($caller['class']) ? $caller['class'] . '::' : '') . 
                          (isset($caller['function']) ? $caller['function'] : 'unknown') . ']';
        }
        
        // 格式化日志前缀
        $log_prefix = date('Y-m-d H:i:s') . ' ' . $prefix . $caller_info . ': ';
        
        // 准备日志内容
        $log_content = is_array($data) || is_object($data) ? print_r($data, true) : $data;

        // 记录到标准PHP错误日志
        error_log($log_prefix . $log_content);

        // 总是尝试记录到专用文件
        self::log_to_file($log_prefix . $log_content);
    }
    
    /**
     * 将日志消息写入到专用文件。
     *
     * @since    1.0.8
     * @access   private
     * @param    string    $message    要写入文件的日志消息。
     */
    private static function log_to_file($message) {
        // 如果当前调试级别为 NONE，则不记录
        if (self::$debug_level === self::DEBUG_LEVEL_NONE) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';
        
        // 确保日志目录存在并受保护
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            
            // 创建.htaccess文件以保护日志
            $htaccess_content = "Options -Indexes\nRequire all denied";
            file_put_contents($log_dir . '/.htaccess', $htaccess_content);
            
            // 创建index.php文件以防止目录列表
            file_put_contents($log_dir . '/index.php', '<?php // Silence is golden.');
        }
        
        // 日志文件路径（统一使用 debug_log-YYYY-MM-DD.log）
        $log_file = $log_dir . '/debug_log-' . date('Y-m-d') . '.log';
        
        // 写入日志
        file_put_contents($log_file, $message . PHP_EOL, FILE_APPEND);
    }
    
    /**
     * 记录错误级别的日志消息。
     *
     * @since    1.0.8
     * @param    mixed     $data       要记录的数据。
     * @param    string    $prefix     日志前缀。
     */
    public static function error_log($data, $prefix = 'Notion Error') {
        self::debug_log($data, $prefix, self::DEBUG_LEVEL_ERROR);
    }
    
    /**
     * 记录信息级别的日志消息。
     *
     * @since    1.0.8
     * @param    mixed     $data       要记录的数据。
     * @param    string    $prefix     日志前缀。
     */
    public static function info_log($data, $prefix = 'Notion Info') {
        self::debug_log($data, $prefix, self::DEBUG_LEVEL_INFO);
    }

    /**
     * 生成一个安全的、唯一的令牌。
     *
     * @since    1.0.8
     * @param    int       $length     令牌的长度。
     * @return   string                 生成的唯一令牌。
     */
    public static function generate_token($length = 32) {
        return wp_generate_password($length, false, false);
    }

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
     * 从 Notion 的富文本（rich_text）数组中提取纯文本内容。
     *
     * @since    1.0.8
     * @param    array   $rich_text  Notion 的富文本对象数组。
     * @return   string              连接后的纯文本字符串。
     */
    public static function get_text_from_rich_text(array $rich_text): string {
        return implode('', array_map(function($text_part) {
            return $text_part['plain_text'] ?? '';
        }, $rich_text));
    }

    /**
     * 使用自定义的规则集来清理 HTML 内容，以确保安全。
     *
     * 此函数扩展了 WordPress 的 `wp_kses_post`，以允许
     * 插件功能所需的特定标签和属性（如 iframe、Mermaid 的 pre 标签等）。
     *
     * @since    1.0.8
     * @param    string    $content    需要清理的 HTML 内容。
     * @return   string                清理和过滤后的安全 HTML。
     */
    public static function custom_kses($content) {
        $allowed_html = array_merge(
            wp_kses_allowed_html('post'),
            [
                'pre'  => [
                    'class' => true,
                ],
                'div'  => [
                    'class' => true,
                    'style' => true, // 允许 style 属性，例如用于 equation
                ],
                'span' => [
                    'class' => true,
                    'style' => true, // 允许 style 属性，例如用于颜色
                ],
                'iframe' => [
                    'src'             => true,
                    'width'           => true,
                    'height'          => true,
                    'frameborder'     => true,
                    'allowfullscreen' => true,
                    'allow'           => true,
                    'scrolling'       => true,
                    'border'          => true,
                    'framespacing'    => true,
                ],
                'input' => [
                    'type' => true,
                    'checked' => true,
                    'disabled' => true,
                    'class' => true,
                ],
                'details' => [
                    'class' => true,
                    'open'  => true,
                ],
                'summary' => [
                    'class' => true,
                ],
                'video' => [
                    'controls' => true,
                    'width'    => true,
                    'height'   => true,
                    'src'      => true,
                    'poster'   => true,
                ],
                'source' => [
                    'src'  => true,
                    'type' => true,
                ]
            ]
        );
        
        // 先做基本 KSES 过滤
        $clean = wp_kses($content, $allowed_html);

        // 进一步限制 iframe src，只允许白名单域
        $clean = self::filter_iframe_src($clean);

        return $clean;
    }
    
    /**
     * 过滤 iframe，仅保留白名单域
     *
     * @since 1.0.10
     */
    private static function filter_iframe_src(string $html): string {
        // 从选项中获取白名单域名
        $options = get_option('notion_to_wordpress_options', []);
        $whitelist_string = isset($options['iframe_whitelist']) ? $options['iframe_whitelist'] : 'www.youtube.com,youtu.be,player.bilibili.com,b23.tv,v.qq.com';
        
        // 如果设置为*，则允许所有
        if (trim($whitelist_string) === '*') {
            return $html;
        }
        
        // 将字符串转换为数组
        $allowed_hosts = array_map('trim', explode(',', $whitelist_string));
        
        // 过滤空值
        $allowed_hosts = array_filter($allowed_hosts);
        
        // 如果白名单为空，使用默认值
        if (empty($allowed_hosts)) {
            $allowed_hosts = [
                'www.youtube.com',
                'youtu.be',
                'player.bilibili.com',
                'b23.tv',
                'v.qq.com',
            ];
        }

        return preg_replace_callback('/<iframe\b[^>]*src=["\\\']([^"\\\']+)["\\\'][^>]*>(.*?)<\/iframe>/i', function ($matches) use ($allowed_hosts) {
            $src = $matches[1];
            $host = wp_parse_url($src, PHP_URL_HOST);
            if ($host && in_array($host, $allowed_hosts, true)) {
                return $matches[0];
            }
            // 非白名单域，移除 iframe
            return '';
        }, $html);
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
     * 获取日志文件列表。
     *
     * @since 1.0.8
     * @return array 文件名数组。
     */
    public static function get_log_files(): array {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';
        
        if (!is_dir($log_dir)) {
            return [];
        }

        $files = scandir($log_dir, SCANDIR_SORT_DESCENDING);
        return array_filter($files, function($file) {
            return !in_array($file, ['.', '..', '.htaccess', 'index.php']);
        });
    }
    
    /**
     * 获取特定日志文件的内容。
     *
     * @since 1.0.8
     * @param string $filename 日志文件名。
     * @return string|false 文件内容或在失败时返回 false。
     */
    public static function get_log_content(string $filename): string {
        // 安全性检查：确保文件名不包含路径遍历字符
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return '无效的文件名。';
        }
        
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/notion-to-wordpress-logs/' . $filename;

        if (file_exists($log_file)) {
            // 读取文件尾部，大小允许通过过滤器调整（默认 1MB）
            $tail_size = (int) apply_filters( 'ntw_log_tail_size', 1024 * 1024 );
            $tail_size = max( 64 * 1024, $tail_size ); // 最小 64KB

            $size = filesize($log_file);
            $offset = max(0, $size - $tail_size);
            return file_get_contents($log_file, false, null, $offset);
        }

        return '日志文件不存在。';
    }

    /**
     * 清理所有日志文件。
     *
     * @since 1.0.8
     * @return bool 是否成功。
     */
    public static function clear_logs(): bool {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';

        if (!is_dir($log_dir)) {
            return true;
        }

        $files = self::get_log_files();
        foreach ($files as $file) {
            $file_path = $log_dir . '/' . $file;
            if (is_writable($file_path)) {
                unlink($file_path);
            }
        }
        return empty(self::get_log_files());
    }

    /**
     * 获取插件文件或目录的绝对服务器路径。
     *
     * @since 1.0.10
     * @param string $path （可选）相对于插件根目录的路径。
     * @return string 绝对服务器路径。
     */
    public static function plugin_path(string $path = ''): string {
        return plugin_dir_path(NOTION_TO_WORDPRESS_FILE) . ltrim($path, '/\\');
    }

    /**
     * 获取插件文件或目录的URL。
     *
     * @since 1.0.10
     * @param string $path （可选）相对于插件根目录的路径。
     * @return string 插件资源的URL。
     */
    public static function plugin_url(string $path = ''): string {
        return plugin_dir_url(NOTION_TO_WORDPRESS_FILE) . ltrim($path, '/\\');
    }

    /**
     * 从对象缓存获取数据，若未命中则回退 transient。
     *
     * @since 1.1.1
     */
    public static function cache_get( string $key ) {
        if ( function_exists( 'wp_cache_get' ) ) {
            $val = wp_cache_get( $key, 'ntw' );
            if ( false !== $val ) {
                return $val;
            }
        }
        return get_transient( $key );
    }

    /**
     * 将数据写入对象缓存并同步 transient（便于无持久化缓存的环境）。
     *
     * @since 1.1.1
     */
    public static function cache_set( string $key, $value, int $ttl = 300 ): void {
        if ( function_exists( 'wp_cache_set' ) ) {
            wp_cache_set( $key, $value, 'ntw', $ttl );
        }
        set_transient( $key, $value, $ttl );
    }

    /**
     * 删除对象缓存及对应 transient。
     *
     * @since 1.1.1
     */
    public static function cache_delete( string $key ): void {
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( $key, 'ntw' );
        }
        delete_transient( $key );
    }

    public static function get_attachment_id_by_url( string $search_url ): int {
        // 准备搜索URL（移除查询参数）
        $base_search_url = preg_replace( '/\?.*$/', '', $search_url );
        
        // 按 _notion_original_url 或 _notion_base_url 查找
        $posts = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_notion_original_url',
                    'value'   => esc_url( $search_url ),
                    'compare' => '=',
                ],
                [
                    'key'     => '_notion_base_url',
                    'value'   => esc_url( $base_search_url ),
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ]);
        if ( ! empty( $posts ) ) {
            return (int) $posts[0];
        }
        // 兜底通过 guid 精确匹配
        global $wpdb;
        $aid = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid=%s OR guid=%s LIMIT 1", $search_url, $base_search_url ) );
        return $aid ? (int) $aid : 0;
    }
}

// 初始化静态帮助类
Notion_To_WordPress_Helper::init(); 