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
    const DEBUG_LEVEL_NONE = 'none';    // 不记录任何日志
    const DEBUG_LEVEL_ERROR = 'error';   // 只记录错误
    const DEBUG_LEVEL_INFO = 'info';    // 记录错误和信息
    const DEBUG_LEVEL_DEBUG = 'debug';   // 记录所有内容，包括详细调试信息
    
    /**
     * 当前日志记录级别。
     *
     * @access private
     * @var string
     */
    private static string $debug_level = self::DEBUG_LEVEL_ERROR;
    
    /**
     * 根据WordPress设置初始化日志级别。
     *
     * @since 1.0.8
     */
    public static function init() {
        // 确保WordPress函数可用
        if (!function_exists('get_option')) {
            return;
        }

        // 从选项中获取调试级别
        $options = get_option('notion_to_wordpress_options', []);
        // 修复：保持字符串类型一致性，不进行int转换
        self::$debug_level = isset($options['debug_level']) ? $options['debug_level'] : self::DEBUG_LEVEL_ERROR;

        // 验证调试级别的有效性
        $valid_levels = [self::DEBUG_LEVEL_NONE, self::DEBUG_LEVEL_ERROR, self::DEBUG_LEVEL_INFO, self::DEBUG_LEVEL_DEBUG];
        if (!in_array(self::$debug_level, $valid_levels)) {
            self::$debug_level = self::DEBUG_LEVEL_ERROR;
        }

        // 如果定义了WP_DEBUG并且为true，则至少启用错误级别日志
        if (defined('WP_DEBUG') && WP_DEBUG === true && self::$debug_level === self::DEBUG_LEVEL_NONE) {
            self::$debug_level = self::DEBUG_LEVEL_ERROR;
        }

        // 使用Helper类的方法记录日志，避免直接使用error_log
        if (function_exists('__')) {
            self::debug_log(__('调试级别设置为: ', 'notion-to-wordpress') . self::$debug_level, 'Notion Init', self::DEBUG_LEVEL_INFO);
        }
    }

    /**
     * 检查是否应该记录指定级别的日志
     *
     * @since 1.0.8
     * @param string $level 要检查的日志级别
     * @return bool 是否应该记录
     */
    private static function should_log($level) {
        // 定义日志级别的优先级（数字越大，级别越高）
        $level_priority = [
            self::DEBUG_LEVEL_NONE => 0,
            self::DEBUG_LEVEL_ERROR => 1,
            self::DEBUG_LEVEL_INFO => 2,
            self::DEBUG_LEVEL_DEBUG => 3
        ];

        $current_priority = isset($level_priority[self::$debug_level]) ? $level_priority[self::$debug_level] : 0;
        $required_priority = isset($level_priority[$level]) ? $level_priority[$level] : 3;

        return $current_priority >= $required_priority;
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
        // 检查是否应该记录此级别的日志
        if (!self::should_log($level)) {
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
        if (self::should_log(self::DEBUG_LEVEL_DEBUG) && defined('WP_DEBUG') && WP_DEBUG === true) {
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

        // 检测并过滤HTML内容（可能包含文章内容）
        if (preg_match('/<[^>]+>/', $content) && strlen($content) > 1000) {
            // 如果包含HTML标签且内容很长，可能是文章内容，进行脱敏处理
            $content = preg_replace('/<[^>]+>/', '[HTML标签已过滤]', $content);
            if (strlen($content) > 500) {
                $content = substr($content, 0, 500) . '... [HTML内容已过滤]';
            }
        }

        // 过滤包含大量文本的数组输出（可能是文章内容）
        if (strpos($content, 'Array') === 0 && strlen($content) > 2000) {
            $content = '[数组内容已过滤，长度: ' . strlen($content) . ' 字符]';
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
        // 获取 WordPress 默认允许的 HTML 标签
        $default_allowed = wp_kses_allowed_html('post');

        // 为所有默认标签添加 id 属性支持（用于锚点跳转）
        foreach ($default_allowed as $tag => $attributes) {
            $default_allowed[$tag]['id'] = true;
        }

        $allowed_html = array_merge(
            $default_allowed,
            [
                'pre'  => [
                    'class' => true,
                ],
                'div'  => [
                    'id' => true, // 允许 id 属性，用于锚点跳转
                    'class' => true,
                    'style' => true, // 允许 style 属性，例如用于 equation
                    'data-latex' => true, // 允许 data-latex 属性，用于公式渲染
                ],
                'span' => [
                    'id' => true, // 允许 id 属性，用于锚点跳转
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
     * 错误类型常量
     *
     * @since 1.1.1
     */
    const ERROR_TYPE_API = 'api_error';
    const ERROR_TYPE_VALIDATION = 'validation_error';
    const ERROR_TYPE_PERMISSION = 'permission_error';
    const ERROR_TYPE_RATE_LIMIT = 'rate_limit_error';
    const ERROR_TYPE_NETWORK = 'network_error';
    const ERROR_TYPE_DATA = 'data_error';
    const ERROR_TYPE_SECURITY = 'security_error';
    const ERROR_TYPE_SYSTEM = 'system_error';

    /**
     * 错误严重级别常量
     *
     * @since 1.1.1
     */
    const ERROR_SEVERITY_LOW = 'low';
    const ERROR_SEVERITY_MEDIUM = 'medium';
    const ERROR_SEVERITY_HIGH = 'high';
    const ERROR_SEVERITY_CRITICAL = 'critical';

    /**
     * 创建标准化的WP_Error对象
     *
     * @since 1.1.1
     * @param string $code 错误代码
     * @param string $message 错误消息
     * @param string $type 错误类型
     * @param string $severity 错误严重级别
     * @param array $data 额外数据
     * @param Exception|null $exception 原始异常（如果有）
     * @return WP_Error
     */
    public static function create_error(
        string $code,
        string $message,
        string $type = self::ERROR_TYPE_SYSTEM,
        string $severity = self::ERROR_SEVERITY_MEDIUM,
        array $data = [],
        ?Exception $exception = null
    ): WP_Error {
        // 构建错误数据
        $error_data = array_merge($data, [
            'type' => $type,
            'severity' => $severity,
            'timestamp' => current_time('mysql'),
            'context' => [
                'file' => $exception ? $exception->getFile() : '',
                'line' => $exception ? $exception->getLine() : '',
                'trace' => $exception ? $exception->getTraceAsString() : ''
            ]
        ]);

        // 记录错误日志
        self::log_error($code, $message, $type, $severity, $error_data);

        return new WP_Error($code, $message, $error_data);
    }

    /**
     * 将Exception转换为WP_Error
     *
     * @since 1.1.1
     * @param Exception $exception 异常对象
     * @param string $type 错误类型
     * @param string $severity 错误严重级别
     * @param array $additional_data 额外数据
     * @return WP_Error
     */
    public static function exception_to_wp_error(
        Exception $exception,
        string $type = self::ERROR_TYPE_SYSTEM,
        string $severity = self::ERROR_SEVERITY_MEDIUM,
        array $additional_data = []
    ): WP_Error {
        // 根据异常类型自动确定错误类型
        $auto_type = self::determine_error_type_from_exception($exception);
        if ($auto_type !== self::ERROR_TYPE_SYSTEM) {
            $type = $auto_type;
        }

        // 根据异常消息自动确定严重级别
        $auto_severity = self::determine_severity_from_exception($exception);
        if ($auto_severity !== self::ERROR_SEVERITY_MEDIUM) {
            $severity = $auto_severity;
        }

        $code = 'exception_' . strtolower(str_replace('Exception', '', get_class($exception)));
        $message = $exception->getMessage();

        return self::create_error($code, $message, $type, $severity, $additional_data, $exception);
    }

    /**
     * 根据异常类型确定错误类型
     *
     * @since 1.1.1
     * @param Exception $exception
     * @return string
     */
    private static function determine_error_type_from_exception(Exception $exception): string {
        $class_name = get_class($exception);
        $message = $exception->getMessage();

        // 根据异常类名判断
        if (strpos($class_name, 'InvalidArgument') !== false) {
            return self::ERROR_TYPE_VALIDATION;
        }
        if (strpos($class_name, 'Permission') !== false || strpos($class_name, 'Unauthorized') !== false) {
            return self::ERROR_TYPE_PERMISSION;
        }

        // 根据异常消息判断
        if (strpos($message, 'API') !== false || strpos($message, 'api') !== false) {
            return self::ERROR_TYPE_API;
        }
        if (strpos($message, 'network') !== false || strpos($message, 'timeout') !== false) {
            return self::ERROR_TYPE_NETWORK;
        }
        if (strpos($message, 'rate limit') !== false || strpos($message, '429') !== false) {
            return self::ERROR_TYPE_RATE_LIMIT;
        }

        return self::ERROR_TYPE_SYSTEM;
    }

    /**
     * 根据异常确定严重级别
     *
     * @since 1.1.1
     * @param Exception $exception
     * @return string
     */
    private static function determine_severity_from_exception(Exception $exception): string {
        $message = strtolower($exception->getMessage());

        // 关键词匹配
        if (strpos($message, 'critical') !== false || strpos($message, 'fatal') !== false) {
            return self::ERROR_SEVERITY_CRITICAL;
        }
        if (strpos($message, 'unauthorized') !== false || strpos($message, 'permission') !== false) {
            return self::ERROR_SEVERITY_HIGH;
        }
        if (strpos($message, 'timeout') !== false || strpos($message, 'rate limit') !== false) {
            return self::ERROR_SEVERITY_MEDIUM;
        }
        if (strpos($message, 'validation') !== false || strpos($message, 'invalid') !== false) {
            return self::ERROR_SEVERITY_LOW;
        }

        return self::ERROR_SEVERITY_MEDIUM;
    }

    /**
     * 记录错误日志
     *
     * @since 1.1.1
     * @param string $code 错误代码
     * @param string $message 错误消息
     * @param string $type 错误类型
     * @param string $severity 错误严重级别
     * @param array $data 错误数据
     */
    private static function log_error(string $code, string $message, string $type, string $severity, array $data): void {
        $log_message = sprintf(
            '[%s] %s: %s (类型: %s, 严重级别: %s)',
            $code,
            $message,
            $type,
            $severity
        );

        // 根据严重级别选择日志级别
        switch ($severity) {
            case self::ERROR_SEVERITY_CRITICAL:
                self::error_log($log_message, 'CRITICAL ERROR');
                break;
            case self::ERROR_SEVERITY_HIGH:
                self::error_log($log_message, 'HIGH ERROR');
                break;
            case self::ERROR_SEVERITY_MEDIUM:
                self::error_log($log_message, 'MEDIUM ERROR');
                break;
            case self::ERROR_SEVERITY_LOW:
                self::debug_log($log_message, 'LOW ERROR');
                break;
        }

        // 记录详细的错误上下文（仅在调试模式下）
        if (self::should_log(self::DEBUG_LEVEL_DEBUG) && !empty($data['context'])) {
            self::debug_log('错误上下文: ' . print_r($data['context'], true), 'ERROR CONTEXT');
        }
    }

    /**
     * 统一的错误处理器
     *
     * @since 1.1.1
     * @param mixed $error 错误对象（WP_Error或Exception）
     * @param array $context 上下文信息
     * @return WP_Error 标准化的WP_Error对象
     */
    public static function handle_error($error, array $context = []): WP_Error {
        // 如果已经是WP_Error，直接返回
        if (is_wp_error($error)) {
            return $error;
        }

        // 如果是Exception，转换为WP_Error
        if ($error instanceof Exception) {
            return self::exception_to_wp_error($error, self::ERROR_TYPE_SYSTEM, self::ERROR_SEVERITY_MEDIUM, $context);
        }

        // 如果是其他类型，创建通用错误
        $message = is_string($error) ? $error : '未知错误';
        return self::create_error('unknown_error', $message, self::ERROR_TYPE_SYSTEM, self::ERROR_SEVERITY_MEDIUM, $context);
    }

    /**
     * 错误恢复机制
     *
     * @since 1.1.1
     * @param WP_Error $error 错误对象
     * @param callable|null $retry_callback 重试回调函数
     * @param int $max_retries 最大重试次数
     * @return mixed 恢复结果或原错误
     */
    public static function attempt_error_recovery(WP_Error $error, ?callable $retry_callback = null, int $max_retries = 3) {
        $error_data = $error->get_error_data();
        $error_type = $error_data['type'] ?? self::ERROR_TYPE_SYSTEM;
        $error_code = $error->get_error_code();

        // 根据错误类型决定恢复策略
        switch ($error_type) {
            case self::ERROR_TYPE_RATE_LIMIT:
                return self::handle_rate_limit_error($error, $retry_callback, $max_retries);

            case self::ERROR_TYPE_NETWORK:
                return self::handle_network_error($error, $retry_callback, $max_retries);

            case self::ERROR_TYPE_API:
                return self::handle_api_error($error, $retry_callback, $max_retries);

            default:
                // 对于其他类型的错误，如果提供了重试回调，尝试重试
                if ($retry_callback && is_callable($retry_callback)) {
                    return self::retry_with_backoff($retry_callback, $max_retries);
                }
                return $error;
        }
    }

    /**
     * 处理速率限制错误
     *
     * @since 1.1.1
     * @param WP_Error $error
     * @param callable|null $retry_callback
     * @param int $max_retries
     * @return mixed
     */
    private static function handle_rate_limit_error(WP_Error $error, ?callable $retry_callback, int $max_retries) {
        if (!$retry_callback || !is_callable($retry_callback)) {
            return $error;
        }

        // 速率限制：等待60秒后重试
        self::info_log('检测到速率限制，等待60秒后重试', 'Error Recovery');
        sleep(60);

        return self::retry_with_backoff($retry_callback, min($max_retries, 2)); // 速率限制最多重试2次
    }

    /**
     * 处理网络错误
     *
     * @since 1.1.1
     * @param WP_Error $error
     * @param callable|null $retry_callback
     * @param int $max_retries
     * @return mixed
     */
    private static function handle_network_error(WP_Error $error, ?callable $retry_callback, int $max_retries) {
        if (!$retry_callback || !is_callable($retry_callback)) {
            return $error;
        }

        // 网络错误：短时间后重试
        self::info_log('检测到网络错误，准备重试', 'Error Recovery');
        return self::retry_with_backoff($retry_callback, $max_retries, 5); // 从5秒开始
    }

    /**
     * 处理API错误
     *
     * @since 1.1.1
     * @param WP_Error $error
     * @param callable|null $retry_callback
     * @param int $max_retries
     * @return mixed
     */
    private static function handle_api_error(WP_Error $error, ?callable $retry_callback, int $max_retries) {
        $error_code = $error->get_error_code();

        // 对于认证错误，不重试
        if (strpos($error_code, 'unauthorized') !== false || strpos($error_code, 'permission') !== false) {
            self::error_log('检测到认证/权限错误，不进行重试', 'Error Recovery');
            return $error;
        }

        if (!$retry_callback || !is_callable($retry_callback)) {
            return $error;
        }

        // 其他API错误：适度重试
        self::info_log('检测到API错误，准备重试', 'Error Recovery');
        return self::retry_with_backoff($retry_callback, min($max_retries, 2), 10); // 从10秒开始，最多2次
    }

    /**
     * 带指数退避的重试机制
     *
     * @since 1.1.1
     * @param callable $callback 重试的回调函数
     * @param int $max_retries 最大重试次数
     * @param int $base_delay 基础延迟秒数
     * @return mixed 回调结果或最后的错误
     */
    private static function retry_with_backoff(callable $callback, int $max_retries = 3, int $base_delay = 1) {
        $last_error = null;

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            try {
                $result = call_user_func($callback);

                // 如果结果不是错误，返回成功结果
                if (!is_wp_error($result)) {
                    if ($attempt > 0) {
                        self::info_log("重试成功，尝试次数: " . ($attempt + 1), 'Error Recovery');
                    }
                    return $result;
                }

                $last_error = $result;
            } catch (Exception $e) {
                $last_error = self::exception_to_wp_error($e);
            }

            // 如果不是最后一次尝试，等待后重试
            if ($attempt < $max_retries) {
                $delay = $base_delay * pow(2, $attempt); // 指数退避
                self::debug_log("重试失败，等待 {$delay} 秒后进行第 " . ($attempt + 2) . " 次尝试", 'Error Recovery');
                sleep($delay);
            }
        }

        self::error_log("重试失败，已达到最大重试次数: " . ($max_retries + 1), 'Error Recovery');
        return $last_error;
    }

    /**
     * 配置管理系统
     *
     * @since 1.1.1
     */

    /**
     * 获取默认配置定义
     *
     * @since 1.1.1
     * @return array 默认配置数组
     */
    public static function get_default_config_schema(): array {
        return [
            // API配置
            'api' => [
                'timeout' => [
                    'default' => 30,
                    'type' => 'integer',
                    'min' => 5,
                    'max' => 300,
                    'description' => 'API请求超时时间（秒）'
                ],
                'retry_attempts' => [
                    'default' => 3,
                    'type' => 'integer',
                    'min' => 1,
                    'max' => 10,
                    'description' => 'API请求重试次数'
                ],
                'retry_delay' => [
                    'default' => 1000,
                    'type' => 'integer',
                    'min' => 100,
                    'max' => 10000,
                    'description' => 'API请求重试延迟（毫秒）'
                ]
            ],

            // 缓存配置
            'cache' => [
                'max_items' => [
                    'default' => 1000,
                    'type' => 'integer',
                    'min' => 100,
                    'max' => 10000,
                    'description' => '最大缓存条目数'
                ],
                'memory_limit_mb' => [
                    'default' => 50,
                    'type' => 'integer',
                    'min' => 10,
                    'max' => 500,
                    'description' => '缓存内存限制（MB）'
                ],
                'ttl' => [
                    'default' => 300,
                    'type' => 'integer',
                    'min' => 60,
                    'max' => 3600,
                    'description' => '缓存有效期（秒）'
                ]
            ],

            // 文件处理配置
            'files' => [
                'max_image_size_mb' => [
                    'default' => 5,
                    'type' => 'integer',
                    'min' => 1,
                    'max' => 20,
                    'description' => '最大图片大小（MB）'
                ],
                'allowed_image_types' => [
                    'default' => 'image/jpeg,image/png,image/gif,image/webp',
                    'type' => 'string',
                    'validation' => 'mime_types',
                    'description' => '允许的图片MIME类型'
                ],
                'allowed_file_types' => [
                    'default' => '',
                    'type' => 'string',
                    'validation' => 'file_extensions',
                    'description' => '额外允许的文件扩展名'
                ],
                'security_level' => [
                    'default' => 'strict',
                    'type' => 'select',
                    'options' => ['strict', 'moderate', 'permissive'],
                    'description' => '文件安全级别'
                ]
            ],

            // 安全配置
            'security' => [
                'iframe_whitelist' => [
                    'default' => 'www.youtube.com,youtu.be,player.bilibili.com,b23.tv,v.qq.com',
                    'type' => 'string',
                    'validation' => 'domain_list',
                    'description' => 'iframe白名单域名'
                ],
                'rate_limit_requests' => [
                    'default' => 30,
                    'type' => 'integer',
                    'min' => 10,
                    'max' => 100,
                    'description' => '每分钟最大请求数'
                ],
                'webhook_rate_limit' => [
                    'default' => 10,
                    'type' => 'integer',
                    'min' => 5,
                    'max' => 50,
                    'description' => 'Webhook每分钟最大请求数'
                ]
            ],

            // 性能配置
            'performance' => [
                'execution_time_limit' => [
                    'default' => 300,
                    'type' => 'integer',
                    'min' => 60,
                    'max' => 600,
                    'description' => '脚本执行时间限制（秒）'
                ],
                'memory_limit_mb' => [
                    'default' => 256,
                    'type' => 'integer',
                    'min' => 128,
                    'max' => 1024,
                    'description' => '内存限制（MB）'
                ],
                'batch_size' => [
                    'default' => 50,
                    'type' => 'integer',
                    'min' => 10,
                    'max' => 200,
                    'description' => '批处理大小'
                ]
            ],

            // 日志配置
            'logging' => [
                'debug_level' => [
                    'default' => self::DEBUG_LEVEL_ERROR,
                    'type' => 'select',
                    'options' => [
                        self::DEBUG_LEVEL_NONE,
                        self::DEBUG_LEVEL_ERROR,
                        self::DEBUG_LEVEL_INFO,
                        self::DEBUG_LEVEL_DEBUG
                    ],
                    'description' => '调试日志级别'
                ],
                'log_retention_days' => [
                    'default' => 7,
                    'type' => 'integer',
                    'min' => 1,
                    'max' => 30,
                    'description' => '日志保留天数'
                ]
            ]
        ];
    }

    /**
     * 获取配置项
     *
     * @since 1.1.1
     * @param string $section 配置节点
     * @param string $key 配置键名
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    public static function get_config($section, $key = '', $default = null) {
        $options = get_option('notion_to_wordpress_config', []);
        
        // 如果只指定了节点，返回整个节点配置
        if (empty($key)) {
            return isset($options[$section]) ? $options[$section] : [];
        }
        
        // 如果指定了键名，返回具体配置值
        if (isset($options[$section][$key])) {
            return $options[$section][$key];
        }
        
        // 如果配置不存在，获取默认配置
        if ($default === null) {
            $schema = self::get_default_config_schema();
            if (isset($schema[$section][$key]['default'])) {
                return $schema[$section][$key]['default'];
            }
        }
        
        return $default;
    }
    
    /**
     * 设置配置项
     *
     * @since 1.1.1
     * @param string $section 配置节点
     * @param string|array $key 配置键名或配置数组
     * @param mixed $value 配置值（当$key为数组时忽略）
     * @return bool 是否成功设置
     */
    public static function set_config($section, $key, $value = null) {
        $options = get_option('notion_to_wordpress_config', []);
        
        // 确保节点存在
        if (!isset($options[$section])) {
            $options[$section] = [];
        }
        
        // 如果key是数组，批量设置配置
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $options[$section][$k] = $v;
            }
        } else {
            // 设置单个配置
            $options[$section][$key] = $value;
        }
        
        // 保存配置到数据库
        return update_option('notion_to_wordpress_config', $options);
    }
    
    /**
     * 验证配置值
     *
     * @since 1.1.1
     * @param string $section 配置节点
     * @param string $key 配置键名
     * @param mixed $value 待验证的值
     * @return array 验证结果，包含 'valid' 和 'message' 键
     */
    public static function validate_config_value($section, $key, $value) {
        $schema = self::get_default_config_schema();
        
        // 检查配置是否存在于架构中
        if (!isset($schema[$section][$key])) {
            return [
                'valid' => false,
                'message' => sprintf('配置 %s.%s 不存在', $section, $key)
            ];
        }
        
        $config_def = $schema[$section][$key];
        $type = $config_def['type'];
        
        // 根据类型验证
        switch ($type) {
            case 'integer':
                // 整数类型验证
                if (!is_numeric($value) || intval($value) != $value) {
                    return [
                        'valid' => false,
                        'message' => '必须是整数值'
                    ];
                }
                
                $int_value = intval($value);
                
                // 验证最小值
                if (isset($config_def['min']) && $int_value < $config_def['min']) {
                    return [
                        'valid' => false,
                        'message' => sprintf('不能小于 %d', $config_def['min'])
                    ];
                }
                
                // 验证最大值
                if (isset($config_def['max']) && $int_value > $config_def['max']) {
                    return [
                        'valid' => false,
                        'message' => sprintf('不能大于 %d', $config_def['max'])
                    ];
                }
                break;
                
            case 'string':
                // 字符串验证
                if (!is_string($value)) {
                    return [
                        'valid' => false,
                        'message' => '必须是字符串'
                    ];
                }
                
                // 特定验证规则
                if (isset($config_def['validation'])) {
                    switch ($config_def['validation']) {
                        case 'mime_types':
                            // MIME类型验证
                            $mime_types = explode(',', $value);
                            foreach ($mime_types as $mime) {
                                $mime = trim($mime);
                                if (!empty($mime) && !preg_match('/^[a-z0-9\.\-\/\+]+$/i', $mime)) {
                                    return [
                                        'valid' => false,
                                        'message' => sprintf('无效的MIME类型: %s', $mime)
                                    ];
                                }
                            }
                            break;
                            
                        case 'file_extensions':
                            // 文件扩展名验证
                            if (!empty($value)) {
                                $extensions = explode(',', $value);
                                foreach ($extensions as $ext) {
                                    $ext = trim($ext);
                                    if (!empty($ext) && !preg_match('/^[a-z0-9]+$/i', $ext)) {
                                        return [
                                            'valid' => false,
                                            'message' => sprintf('无效的文件扩展名: %s', $ext)
                                        ];
                                    }
                                }
                            }
                            break;
                            
                        case 'domain_list':
                            // 域名列表验证
                            $domains = explode(',', $value);
                            foreach ($domains as $domain) {
                                $domain = trim($domain);
                                if (!empty($domain) && !preg_match('/^[a-z0-9\.\-]+$/i', $domain)) {
                                    return [
                                        'valid' => false,
                                        'message' => sprintf('无效的域名: %s', $domain)
                                    ];
                                }
                            }
                            break;
                    }
                }
                break;
                
            case 'select':
                // 选择类型验证
                if (!in_array($value, $config_def['options'])) {
                    return [
                        'valid' => false,
                        'message' => sprintf('值必须是以下之一: %s', implode(', ', $config_def['options']))
                    ];
                }
                break;
        }
        
        return [
            'valid' => true,
            'message' => '验证通过'
        ];
    }
    
    /**
     * 应用配置到系统
     *
     * @since 1.1.1
     * @return void
     */
    public static function apply_runtime_config() {
        // 应用性能配置
        $execution_time = self::get_config('performance', 'execution_time_limit');
        if ($execution_time > 0) {
            @set_time_limit($execution_time);
        }
        
        $memory_limit = self::get_config('performance', 'memory_limit_mb');
        if ($memory_limit > 0) {
            @ini_set('memory_limit', $memory_limit . 'M');
        }
        
        // 应用日志配置
        $debug_level = self::get_config('logging', 'debug_level');
        if ($debug_level !== self::DEBUG_LEVEL_NONE) {
            // 在系统运行中设置调试级别
            self::$debug_level = $debug_level;
        }
    }
    
    /**
     * 重置配置到默认值
     *
     * @since 1.1.1
     * @param string $section 可选，指定要重置的配置节点
     * @return bool 是否成功重置
     */
    public static function reset_config($section = '') {
        $schema = self::get_default_config_schema();
        $options = get_option('notion_to_wordpress_config', []);
        
        // 如果指定了节点，只重置该节点
        if (!empty($section) && isset($schema[$section])) {
            $defaults = [];
            foreach ($schema[$section] as $key => $def) {
                $defaults[$key] = $def['default'];
            }
            $options[$section] = $defaults;
        } else {
            // 重置所有配置
            $options = [];
            foreach ($schema as $section => $configs) {
                $options[$section] = [];
                foreach ($configs as $key => $def) {
                    $options[$section][$key] = $def['default'];
                }
            }
        }
        
        return update_option('notion_to_wordpress_config', $options);
    }
    
    /**
     * 初始化默认配置
     *
     * @since 1.1.1
     * @return void
     */
    public static function initialize_config() {
        $options = get_option('notion_to_wordpress_config');
        
        // 如果配置不存在，创建默认配置
        if ($options === false) {
            self::reset_config();
        }
    }

    /**
     * 获取配置管理页面的表单字段
     *
     * @since 1.1.1
     * @return array 配置表单字段
     */
    public static function get_config_form_fields() {
        $schema = self::get_default_config_schema();
        $options = get_option('notion_to_wordpress_config', []);
        $form_fields = [];
        
        foreach ($schema as $section => $configs) {
            $form_fields[$section] = [];
            
            foreach ($configs as $key => $def) {
                $current_value = isset($options[$section][$key]) ? $options[$section][$key] : $def['default'];
                
                $field = [
                    'name' => $key,
                    'label' => $def['description'],
                    'type' => $def['type'],
                    'value' => $current_value
                ];
                
                // 添加类型特定的属性
                switch ($def['type']) {
                    case 'integer':
                        if (isset($def['min'])) {
                            $field['min'] = $def['min'];
                        }
                        if (isset($def['max'])) {
                            $field['max'] = $def['max'];
                        }
                        break;
                    case 'select':
                        $field['options'] = $def['options'];
                        break;
                }
                
                $form_fields[$section][] = $field;
            }
        }
        
        return $form_fields;
    }

    /**
     * 
     */

    /**
     * 检查文件名是否包含危险字符
     *
     * @since    1.8.1
     * @param    string    $filename    文件名
     * @return   bool                   是否包含危险字符
     */
    public static function has_dangerous_filename(string $filename): bool {
        // 检查危险字符
        $dangerous_chars = [';', '&', '|', '`', '$', '(', ')', '{', '}', '[', ']', '<', '>', "\0", "\n", "\r"];
        foreach ($dangerous_chars as $char) {
            if (strpos($filename, $char) !== false) {
                self::warning_log('文件名包含危险字符: ' . $filename);
                return true;
            }
        }

        // 检查双扩展名（可能隐藏真实扩展名）
        if (preg_match('/\.(php|html|js|exe|bat|sh|pl|py)\./i', $filename)) {
            self::warning_log('文件名包含可疑双扩展名: ' . $filename);
                return true;
            }

        return false;
    }

    /**
     * 检查文件类型是否安全
     *
     * @since    1.8.1
     * @param    string    $filename    文件名
     * @return   bool                   是否为安全文件类型
     */
    public static function is_safe_file_type(string $filename): bool {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // 黑名单扩展名（始终拒绝）
        $blacklist = [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phpt', 
            'exe', 'bat', 'cmd', 'sh', 'pl', 'py', 'cgi', 'asp', 'aspx', 'jsp',
            'dll', 'so', 'bin', 'msi', 'com', 'htaccess', 'htpasswd', 'config',
            'inc', 'ini'
        ];
        
        if (in_array($extension, $blacklist)) {
            self::warning_log('文件扩展名在黑名单中: ' . $extension);
        return false;
        }
        
        // 允许的扩展名列表（白名单）
        $whitelist = [
            // 图像
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'svg',
            // 文档
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv', 'odt', 'ods', 'odp',
            // 音频
            'mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac',
            // 视频
            'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', 'mpeg',
            // 压缩
            'zip', 'rar', '7z', 'tar', 'gz', 'bz2',
            // 数据
            'json', 'xml', 'csv', 'md'
        ];
        
        if (!in_array($extension, $whitelist)) {
            self::warning_log('文件扩展名不在白名单中: ' . $extension);
            return false;
        }
        
        return true;
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
     * 记录性能指标
     *
     * @since 1.1.1
     * @param string $operation 操作名称
     * @param float $start_time 开始时间
     * @param array $additional_data 额外数据
     */
    public static function log_performance(string $operation, float $start_time, array $additional_data = []): void {
        if (!self::should_log(self::DEBUG_LEVEL_DEBUG)) {
            return;
        }

        $execution_time = microtime(true) - $start_time;
        $memory_usage = memory_get_usage(true);
        $peak_memory = memory_get_peak_usage(true);

        $performance_data = [
            'operation' => $operation,
            'execution_time' => round($execution_time * 1000, 2) . 'ms',
            'memory_usage' => self::format_bytes($memory_usage),
            'peak_memory' => self::format_bytes($peak_memory),
            'timestamp' => current_time('mysql')
        ];

        if (!empty($additional_data)) {
            $performance_data = array_merge($performance_data, $additional_data);
        }

        self::debug_log(
            '性能监控 - ' . $operation . ': ' . json_encode($performance_data, JSON_UNESCAPED_UNICODE),
            'Performance'
        );
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

    /**
     * 开始性能计时
     *
     * @since 1.1.1
     * @param string $operation 操作名称
     * @return float 开始时间
     */
    public static function start_performance_timer(string $operation): float {
        $start_time = microtime(true);

        if (self::should_log(self::DEBUG_LEVEL_DEBUG)) {
            self::debug_log(
                '开始性能计时: ' . $operation,
                'Performance Timer'
            );
        }

        return $start_time;
    }

    /**
     * 结束性能计时并记录
     *
     * @since 1.1.1
     * @param string $operation 操作名称
     * @param float $start_time 开始时间
     * @param array $additional_data 额外数据
     */
    public static function end_performance_timer(string $operation, float $start_time, array $additional_data = []): void {
        self::log_performance($operation, $start_time, $additional_data);
    }

    /**
     * 加密API密钥
     *
     * @since    1.8.1
     * @param    string    $api_key    需要加密的API密钥
     * @return   string                加密后的API密钥
     */
    public static function encrypt_api_key(string $api_key): string {
        if (empty($api_key)) {
            return '';
        }

        // 获取加密密钥，如果不存在则创建一个
        $encryption_key = get_option('notion_to_wordpress_encryption_key');
        if (empty($encryption_key)) {
            $encryption_key = bin2hex(random_bytes(32));
            update_option('notion_to_wordpress_encryption_key', $encryption_key, false);
        }

        // 创建初始化向量
        $iv = random_bytes(16);
        
        // 加密API密钥
        $encrypted = openssl_encrypt(
            $api_key,
            'AES-256-CBC',
            hex2bin($encryption_key),
            0,
            $iv
        );

        // 返回初始化向量和加密后的数据
        if ($encrypted === false) {
            return $api_key; // 加密失败，返回原始密钥
        }

        return base64_encode($iv . base64_decode($encrypted));
    }

    /**
     * 解密API密钥
     *
     * @since    1.8.1
     * @param    string    $encrypted_api_key    加密后的API密钥
     * @return   string                          解密后的API密钥
     */
    public static function decrypt_api_key(string $encrypted_api_key): string {
        if (empty($encrypted_api_key)) {
            return '';
        }

        // 获取加密密钥
        $encryption_key = get_option('notion_to_wordpress_encryption_key');
        if (empty($encryption_key)) {
            return $encrypted_api_key; // 找不到密钥，返回加密的密钥
        }

        // 解码加密的数据
        $decoded = base64_decode($encrypted_api_key);
        if ($decoded === false) {
            return $encrypted_api_key; // 解码失败，返回加密的密钥
        }

        // 提取初始化向量和加密数据
        $iv = substr($decoded, 0, 16);
        $encrypted_data = base64_encode(substr($decoded, 16));
        
        // 解密数据
        $decrypted = openssl_decrypt(
            $encrypted_data,
            'AES-256-CBC',
            hex2bin($encryption_key),
            0,
            $iv
        );

        if ($decrypted === false) {
            return $encrypted_api_key; // 解密失败，返回加密的密钥
        }

        return $decrypted;
    }

    /**
     * 检查文件是否为可执行文件
     *
     * @since    1.8.1
     * @param    string    $file_path    文件路径
     * @return   bool                    是否为可执行文件
     */
    public static function is_executable_file(string $file_path): bool {
        // 检查文件扩展名
        $dangerous_extensions = [
            // 可执行文件
            'exe', 'bat', 'cmd', 'sh', 'php', 'pl', 'py', 'js', 'vbs', 'cgi',
            // 系统文件
            'dll', 'so', 'bin', 'msi', 'com',
            // 脚本文件
            'htaccess', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar'
        ];

        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (in_array($extension, $dangerous_extensions)) {
            self::warning_log('文件扩展名可能是可执行文件: ' . $extension);
            return true;
        }

        // 检查文件头部是否包含shebang
        $fp = @fopen($file_path, 'rb');
        if ($fp) {
            $line = fgets($fp, 100);
            fclose($fp);
            if ($line && strpos($line, '#!') === 0) {
                self::warning_log('文件包含shebang标记，可能是脚本文件');
                return true;
            }
        }

        return false;
    }

    /**
     * 检查图片文件是否包含隐藏的PHP代码
     *
     * @since    1.8.1
     * @param    string    $file_path    文件路径
     * @return   bool                    是否包含隐藏代码
     */
    public static function image_contains_php(string $file_path): bool {
        // 检查文件是否为有效图像
        $image_info = @getimagesize($file_path);
        if ($image_info === false) {
            // 不是有效的图像
            return false;
        }

        // 读取文件内容
        $content = @file_get_contents($file_path);
        if ($content === false) {
            return false;
        }

        // 检查PHP标记
        $php_patterns = [
            '/<\?php/i',
            '/<\?=/i',
            '/<\?/i',
            '/eval\s*\(/i',
            '/base64_decode\s*\(/i',
            '/system\s*\(/i',
            '/exec\s*\(/i',
            '/shell_exec\s*\(/i',
            '/passthru\s*\(/i',
        ];

        foreach ($php_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                self::warning_log('检测到图像文件可能包含PHP代码');
                return true;
            }
        }

        return false;
    }

    /**
     * 数据库查询优化工具
     *
     * @since 1.1.1
     */

    /**
     * 查询性能监控
     *
     * @since 1.1.1
     * @var array
     */
    private static array $query_stats = [
        'total_queries' => 0,
        'slow_queries' => 0,
        'query_times' => [],
        'query_details' => []
    ];

    /**
     * 慢查询阈值（毫秒）
     *
     * @since 1.1.1
     * @var float
     */
    private static float $slow_query_threshold = 100.0;

    /**
     * 开始查询性能监控
     *
     * @since 1.1.1
     * @param string $query_name 查询名称
     * @param array $params 查询参数
     * @return string 查询ID
     */
    public static function start_query_monitor(string $query_name, array $params = []): string {
        $query_id = uniqid('query_', true);
        $start_time = microtime(true);

        self::$query_stats['query_details'][$query_id] = [
            'name' => $query_name,
            'params' => $params,
            'start_time' => $start_time,
            'memory_start' => memory_get_usage(true)
        ];

        return $query_id;
    }

    /**
     * 结束查询性能监控
     *
     * @since 1.1.1
     * @param string $query_id 查询ID
     * @param array $result_info 结果信息
     */
    public static function end_query_monitor(string $query_id, array $result_info = []): void {
        if (!isset(self::$query_stats['query_details'][$query_id])) {
            return;
        }

        $query_detail = self::$query_stats['query_details'][$query_id];
        $end_time = microtime(true);
        $duration = ($end_time - $query_detail['start_time']) * 1000; // 转换为毫秒
        $memory_used = memory_get_usage(true) - $query_detail['memory_start'];

        // 更新统计信息
        self::$query_stats['total_queries']++;
        self::$query_stats['query_times'][] = $duration;

        if ($duration > self::$slow_query_threshold) {
            self::$query_stats['slow_queries']++;
        }

        // 记录查询详情
        self::$query_stats['query_details'][$query_id] = array_merge($query_detail, [
            'end_time' => $end_time,
            'duration_ms' => $duration,
            'memory_used' => $memory_used,
            'result_info' => $result_info,
            'is_slow' => $duration > self::$slow_query_threshold
        ]);

        // 记录慢查询日志
        if ($duration > self::$slow_query_threshold) {
            self::debug_log(
                sprintf(
                    '慢查询检测: %s 耗时 %.2fms, 内存使用 %s, 参数: %s',
                    $query_detail['name'],
                    $duration,
                    size_format($memory_used),
                    json_encode($query_detail['params'])
                ),
                'Slow Query'
            );
        }

        // 清理旧的查询详情（保留最近100个）
        if (count(self::$query_stats['query_details']) > 100) {
            $keys = array_keys(self::$query_stats['query_details']);
            $old_keys = array_slice($keys, 0, -100);
            foreach ($old_keys as $old_key) {
                unset(self::$query_stats['query_details'][$old_key]);
            }
        }
    }

    /**
     * 获取查询性能统计
     *
     * @since 1.1.1
     * @return array 查询统计信息
     */
    public static function get_query_stats(): array {
        $query_times = self::$query_stats['query_times'];
        $total_queries = self::$query_stats['total_queries'];

        if (empty($query_times)) {
            return [
                'total_queries' => 0,
                'slow_queries' => 0,
                'avg_time_ms' => 0,
                'max_time_ms' => 0,
                'min_time_ms' => 0,
                'total_time_ms' => 0
            ];
        }

        return [
            'total_queries' => $total_queries,
            'slow_queries' => self::$query_stats['slow_queries'],
            'avg_time_ms' => round(array_sum($query_times) / count($query_times), 2),
            'max_time_ms' => round(max($query_times), 2),
            'min_time_ms' => round(min($query_times), 2),
            'total_time_ms' => round(array_sum($query_times), 2),
            'slow_query_threshold' => self::$slow_query_threshold
        ];
    }

    /**
     * 批量获取文章元数据
     *
     * @since 1.1.1
     * @param array $post_ids 文章ID数组
     * @param string|array $meta_keys 元数据键名
     * @return array 批量元数据结果
     */
    public static function get_posts_meta_batch(array $post_ids, $meta_keys): array {
        if (empty($post_ids)) {
            return [];
        }

        $query_id = self::start_query_monitor('get_posts_meta_batch', [
            'post_ids_count' => count($post_ids),
            'meta_keys' => $meta_keys
        ]);

        global $wpdb;
        $post_ids_str = implode(',', array_map('intval', $post_ids));
        $meta_keys_array = is_array($meta_keys) ? $meta_keys : [$meta_keys];
        $meta_keys_placeholders = implode(',', array_fill(0, count($meta_keys_array), '%s'));

        $query = $wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$post_ids_str})
             AND meta_key IN ({$meta_keys_placeholders})",
            ...$meta_keys_array
        );

        $results = $wpdb->get_results($query);

        // 组织结果
        $organized_results = [];
        foreach ($results as $row) {
            $organized_results[$row->post_id][$row->meta_key] = $row->meta_value;
        }

        self::end_query_monitor($query_id, [
            'results_count' => count($results),
            'posts_with_meta' => count($organized_results)
        ]);

        return $organized_results;
    }

    /**
     * 批量查询文章通过Notion ID
     *
     * @since 1.1.1
     * @param array $notion_ids Notion ID数组
     * @return array 文章ID映射数组 [notion_id => post_id]
     */
    public static function get_posts_by_notion_ids_batch(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        $query_id = self::start_query_monitor('get_posts_by_notion_ids_batch', [
            'notion_ids_count' => count($notion_ids)
        ]);

        global $wpdb;
        $notion_ids_placeholders = implode(',', array_fill(0, count($notion_ids), '%s'));

        $query = $wpdb->prepare(
            "SELECT p.ID, pm.meta_value as notion_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_notion_page_id'
             AND pm.meta_value IN ({$notion_ids_placeholders})",
            ...$notion_ids
        );

        $results = $wpdb->get_results($query);

        // 组织结果
        $organized_results = [];
        foreach ($results as $row) {
            $organized_results[$row->notion_id] = (int)$row->ID;
        }

        self::end_query_monitor($query_id, [
            'results_count' => count($results),
            'matched_posts' => count($organized_results)
        ]);

        return $organized_results;
    }

    /**
     * 批量更新文章元数据
     *
     * @since 1.1.1
     * @param array $updates 更新数据 [post_id => [meta_key => meta_value]]
     * @return bool 是否成功
     */
    public static function update_posts_meta_batch(array $updates): bool {
        if (empty($updates)) {
            return true;
        }

        $query_id = self::start_query_monitor('update_posts_meta_batch', [
            'posts_count' => count($updates),
            'total_updates' => array_sum(array_map('count', $updates))
        ]);

        global $wpdb;
        $success = true;

        // 开始事务
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($updates as $post_id => $meta_data) {
                foreach ($meta_data as $meta_key => $meta_value) {
                    $result = $wpdb->replace(
                        $wpdb->postmeta,
                        [
                            'post_id' => $post_id,
                            'meta_key' => $meta_key,
                            'meta_value' => $meta_value
                        ],
                        ['%d', '%s', '%s']
                    );

                    if ($result === false) {
                        throw new Exception("Failed to update meta for post {$post_id}");
                    }
                }
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $success = false;
            self::error_log('批量更新元数据失败: ' . $e->getMessage(), 'Database Batch');
        }

        self::end_query_monitor($query_id, [
            'success' => $success,
            'updates_processed' => $success ? array_sum(array_map('count', $updates)) : 0
        ]);

        return $success;
    }

    /**
     * 优化的分页查询
     *
     * @since 1.1.1
     * @param array $args 查询参数
     * @param int $page 页码
     * @param int $per_page 每页数量
     * @return array 查询结果
     */
    public static function get_posts_paginated(array $args, int $page = 1, int $per_page = 50): array {
        $query_id = self::start_query_monitor('get_posts_paginated', [
            'page' => $page,
            'per_page' => $per_page,
            'args' => $args
        ]);

        // 设置分页参数
        $args['paged'] = $page;
        $args['posts_per_page'] = $per_page;

        // 优化查询参数
        if (!isset($args['fields'])) {
            $args['fields'] = 'ids'; // 默认只获取ID以提高性能
        }

        if (!isset($args['no_found_rows'])) {
            $args['no_found_rows'] = true; // 不计算总行数以提高性能
        }

        $query = new WP_Query($args);
        $results = [
            'posts' => $query->posts,
            'found_posts' => $query->found_posts,
            'max_num_pages' => $query->max_num_pages
        ];

        self::end_query_monitor($query_id, [
            'posts_found' => count($query->posts),
            'total_found' => $query->found_posts
        ]);

        return $results;
    }

}

// 注意：不在此处立即初始化，而是在WordPress环境完全加载后初始化
// 初始化将在主插件类的构造函数中调用