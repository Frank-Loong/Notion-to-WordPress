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
    private static int $debug_level = self::DEBUG_LEVEL_ERROR;
    
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

        // 使用Helper类的方法记录日志，避免直接使用error_log
        self::debug_log(__('调试级别设置为: ', 'notion-to-wordpress') . self::$debug_level, 'Notion Init', self::DEBUG_LEVEL_INFO);
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

        // 过滤敏感内容，保护用户隐私
        $filtered_content = self::filter_sensitive_content($log_content, $level);

        // 仅在最高调试级别且WP_DEBUG启用时才写入WordPress error_log，避免污染
        if (self::$debug_level >= self::DEBUG_LEVEL_DEBUG && defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log($log_prefix . $filtered_content);
        }

        // 总是记录到专用文件，确保用户可以查看日志
        self::log_to_file($log_prefix . $filtered_content);
    }

    /**
     * 过滤敏感内容，保护用户隐私
     *
     * @since    1.0.11
     * @access   private
     * @param    string    $content    要过滤的内容
     * @param    int       $level      日志级别
     * @return   string                过滤后的内容
     */
    private static function filter_sensitive_content($content, $level) {
        // 错误级别的日志保持完整，便于问题诊断
        if ($level <= self::DEBUG_LEVEL_ERROR) {
            return $content;
        }

        // 检查内容长度，超过500字符进行截断
        if (strlen($content) > 500) {
            $content = substr($content, 0, 500) . '... [' . __('内容已截断，完整内容请查看专用日志文件', 'notion-to-wordpress') . ']';
        }

        // 检测并过滤HTML内容（可能包含文章内容）
        if (preg_match('/<[^>]+>/', $content)) {
            // 如果包含HTML标签，进行脱敏处理
            $content = preg_replace('/<[^>]+>/', '[' . __('HTML标签已过滤', 'notion-to-wordpress') . ']', $content);
            if (strlen($content) > 200) {
                $content = substr($content, 0, 200) . '... [' . __('HTML内容已过滤', 'notion-to-wordpress') . ']';
            }
        }

        // 过滤可能的JSON响应内容（API响应）
        if (preg_match('/^\s*[\{\[]/', $content) && strlen($content) > 300) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $content = '[' . sprintf(__('JSON响应已过滤，长度: %d 字符', 'notion-to-wordpress'), strlen($content)) . ']';
            }
        }

        // 过滤包含大量文本的数组输出
        if (strpos($content, 'Array') === 0 && strlen($content) > 400) {
            $content = '[' . sprintf(__('数组内容已过滤，长度: %d 字符', 'notion-to-wordpress'), strlen($content)) . ']';
        }

        return $content;
    }

    /**
     * 将日志消息写入到专用文件。
     *
     * @since    1.0.8
     * @access   private
     * @param    string    $message    要写入文件的日志消息。
     */
    private static function log_to_file($message) {
        // 临时强制启用日志记录用于调试
        // if (self::$debug_level === self::DEBUG_LEVEL_NONE) {
        //     return;
        // }

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
        
        // 日志文件路径
        $log_file = $log_dir . '/debug-' . date('Y-m-d') . '.log';
        
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
                    'data-latex' => true, // 允许 data-latex 属性，用于公式渲染
                ],
                'span' => [
                    'class' => true,
                    'style' => true, // 允许 style 属性，例如用于颜色
                    'data-latex' => true, // 允许 data-latex 属性，用于公式渲染
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
     * @since 1.1.0
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
            return __('无效的文件名。', 'notion-to-wordpress');
        }
        
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/notion-to-wordpress-logs/' . $filename;

        if (file_exists($log_file)) {
            // 只读取最后 1MB 的内容以防止过大的文件拖慢后台
            $size = filesize($log_file);
            $offset = max(0, $size - 1024 * 1024);
            return file_get_contents($log_file, false, null, $offset);
        }

        return __('日志文件不存在。', 'notion-to-wordpress');
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
     * 执行日志清理任务。
     *
     * @since 2.0.0
     */
    public static function run_log_cleanup() {
        $options = get_option('notion_to_wordpress_options', []);
        $retention_days = isset($options['log_retention_days']) ? (int)$options['log_retention_days'] : 0;

        if ($retention_days <= 0) {
            self::info_log(__('日志清理任务跳过：未设置保留期限。', 'notion-to-wordpress'), 'LogCleanup');
            return;
        }

        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';
        $files = self::get_log_files();
        $deleted_count = 0;
        $time_now = time();
        $retention_seconds = $retention_days * DAY_IN_SECONDS;

        foreach ($files as $file) {
            $file_path = $log_dir . '/' . $file;
            if (is_file($file_path) && is_writable($file_path)) {
                $file_mod_time = filemtime($file_path);
                if (($time_now - $file_mod_time) > $retention_seconds) {
                    if (unlink($file_path)) {
                        $deleted_count++;
                    }
                }
            }
        }
        
        if ($deleted_count > 0) {
            self::info_log(sprintf(__('日志清理完成，删除了 %d 个旧日志文件。', 'notion-to-wordpress'), $deleted_count), 'LogCleanup');
        } else {
            self::debug_log(__('日志清理任务运行，没有需要删除的文件。', 'notion-to-wordpress'), 'LogCleanup');
        }
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
     * 安全地获取远程内容
     *
     * @since 1.0.9
     * @param string $url 要获取的URL
     * @param array $args 请求参数
     * @return array|WP_Error 响应数组或错误对象
     */
    public static function safe_remote_get(string $url, array $args = []) {
        // 默认参数
        $default_args = [
            'timeout' => 30,
            'user-agent' => 'Notion-to-WordPress/' . NOTION_TO_WORDPRESS_VERSION,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'sslverify' => true,
        ];

        $args = wp_parse_args($args, $default_args);

        // 验证URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('无效的URL', 'notion-to-wordpress'));
        }

        // 执行请求
        $response = wp_remote_get($url, $args);

        // 检查错误
        if (is_wp_error($response)) {
            self::error_log(__('远程请求失败: ', 'notion-to-wordpress') . $response->get_error_message(), 'HTTP');
            return $response;
        }

        // 检查HTTP状态码
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $error_msg = sprintf(__('HTTP错误 %d: %s', 'notion-to-wordpress'), $status_code, wp_remote_retrieve_response_message($response));
            self::error_log($error_msg, 'HTTP');
            return new WP_Error('http_error', $error_msg);
        }

        return $response;
    }

    /**
     * 验证文件类型是否安全
     *
     * @since 1.0.9
     * @param string $filename 文件名
     * @return bool 是否为安全的文件类型
     */
    public static function is_safe_file_type(string $filename): bool {
        $allowed_extensions = [
            // 图片
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico',
            // 文档
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf',
            // 音频
            'mp3', 'wav', 'ogg', 'flac', 'm4a',
            // 视频
            'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm',
            // 压缩文件
            'zip', 'rar', '7z', 'tar', 'gz',
            // 其他
            'csv', 'json', 'xml'
        ];

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $allowed_extensions);
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


}

// 初始化静态帮助类
Notion_To_WordPress_Helper::init();