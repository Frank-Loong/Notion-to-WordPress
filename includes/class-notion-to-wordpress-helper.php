<?php
declare(strict_types=1);

/**
 * 辅助工具类
 * 
 * 提供一系列静态辅助方法，用于日志记录、安全过滤、数据处理和性能监控等。
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
    /**
     * 调试级别常量
     */
    const DEBUG_LEVEL_NONE = 0;    // 不记录任何日志
    const DEBUG_LEVEL_ERROR = 1;   // 只记录错误
    const DEBUG_LEVEL_WARNING = 2; // 记录警告
    const DEBUG_LEVEL_INFO = 3;    // 记录错误、警告和信息
    const DEBUG_LEVEL_DEBUG = 4;   // 记录所有内容，包括详细调试信息
    
    /**
     * 当前日志记录级别。
     *
     * @access private
     * @var int
     */
    private static int $debug_level = self::DEBUG_LEVEL_ERROR;

    /**
     * 最大日志文件大小（字节）
     *
     * @access private
     * @var int
     */
    private static int $max_log_size = 5242880; // 5MB

    /**
     * 最大日志文件数量
     *
     * @access private
     * @var int
     */
    private static int $max_log_files = 10;

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

        // 初始化时执行日志清理
        self::cleanup_logs();
    }

    /**
     * 优化版的日志记录方法。
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

        // 格式化日志前缀
        $log_prefix = date('Y-m-d H:i:s') . ' ' . $prefix . ': ';

        // 准备日志内容 - 限制大小
        $log_content = is_array($data) || is_object($data) ? print_r($data, true) : $data;

        // 限制日志内容大小，避免超大日志
        if (strlen($log_content) > 10000) {
            $log_content = substr($log_content, 0, 10000) . '... [日志内容已截断]';
        }

        // 过滤敏感内容，保护用户隐私
        $filtered_content = self::filter_sensitive_content($log_content, $level);

        // 仅在ERROR级别或WP_DEBUG启用时才写入WordPress error_log
        if ($level === self::DEBUG_LEVEL_ERROR && defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log($log_prefix . $filtered_content);
        }

        // 写入专用日志文件
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
     * 将日志消息写入到专用文件，优化版本。
     *
     * @since    1.0.8
     * @access   private
     * @param    string    $message    要写入文件的日志消息。
     */
    private static function log_to_file($message) {
        // 如果完全禁用日志，则直接返回
        if (self::$debug_level === self::DEBUG_LEVEL_NONE) {
            return;
        }

        static $log_dir = null;
        static $log_file = null;

        // 只在第一次调用时初始化目录和文件路径
        if ($log_dir === null) {
            $upload_dir = wp_upload_dir();
            $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';

            // 确保日志目录存在并受保护
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);

                // 创建.htaccess文件以保护日志
                $htaccess_content = "Options -Indexes\nRequire all denied";
                file_put_contents($log_dir . '/.htaccess', $htaccess_content);

                // 创建index.php文件以防止目录列表被直接访问，提升安全性
                file_put_contents($log_dir . '/index.php', '<?php // Silence is golden.');
            }
        }

        // 只在第一次调用或日期变更时更新日志文件路径
        if ($log_file === null || strpos($log_file, date('Y-m-d')) === false) {
            $log_file = $log_dir . '/debug-' . date('Y-m-d') . '.log';

            // 检查日志文件大小，如果超过限制则进行轮转
            if (file_exists($log_file) && filesize($log_file) > self::$max_log_size) {
                self::rotate_log_file($log_file);
            }
        }

        // 写入日志 - 使用锁定以防止并发写入问题
        $fp = fopen($log_file, 'a');
        if ($fp) {
            flock($fp, LOCK_EX);
            fwrite($fp, $message . PHP_EOL);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * 轮转日志文件
     *
     * @since    2.0.0
     * @access   private
     * @param    string    $log_file    日志文件路径
     */
    private static function rotate_log_file($log_file) {
        // 如果文件不存在，直接返回
        if (!file_exists($log_file)) {
            return;
        }

        // 获取不带扩展名的文件名
        $path_info = pathinfo($log_file);
        $base_name = $path_info['dirname'] . '/' . $path_info['filename'];

        // 移动现有的轮转日志
        for ($i = self::$max_log_files - 1; $i > 0; $i--) {
            $old_file = $base_name . '.' . $i . '.log';
            $new_file = $base_name . '.' . ($i + 1) . '.log';

            if (file_exists($old_file)) {
                @rename($old_file, $new_file);
            }
        }

        // 移动当前日志文件
        @rename($log_file, $base_name . '.1.log');
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
     * 记录警告级别的日志消息。
     *
     * @since    2.0.0-beta.1
     * @param    mixed     $data       要记录的数据。
     * @param    string    $prefix     日志前缀。
     */
    public static function warning_log($data, $prefix = 'Notion Warning') {
        self::debug_log($data, $prefix, self::DEBUG_LEVEL_WARNING);
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
     * 完整的 Rich Text 处理方法
     *
     * 支持所有格式化功能：粗体、斜体、删除线、下划线、代码、颜色、链接、公式等
     *
     * @since    2.0.0-beta.1
     * @param    array     $rich_text    富文本数组
     * @return   string                  格式化的HTML文本
     */
    public static function extract_rich_text_complete(array $rich_text): string {
        if (empty($rich_text)) {
            return '';
        }

        $result = '';

        foreach ($rich_text as $text) {
            // 处理行内公式 - 使用统一的公式处理方法
            if ( isset( $text['type'] ) && $text['type'] === 'equation' ) {
                $expr_raw = $text['equation']['expression'] ?? '';
                $content = self::process_math_expression($expr_raw, 'inline');
            } else {
                // 对纯文本内容进行转义
                $content = isset( $text['plain_text'] ) ? esc_html( $text['plain_text'] ) : '';
            }

            if (empty($content)) {
                continue;
            }

            $annotations = isset($text['annotations']) ? $text['annotations'] : array();
            $href = isset($text['href']) ? $text['href'] : '';

            // 应用格式化
            if (!empty($annotations)) {
                if ( isset( $annotations['bold'] ) && $annotations['bold'] ) {
                    $content = '<strong>' . $content . '</strong>';
                }

                if ( isset( $annotations['italic'] ) && $annotations['italic'] ) {
                    $content = '<em>' . $content . '</em>';
                }

                if ( isset( $annotations['strikethrough'] ) && $annotations['strikethrough'] ) {
                    $content = '<del>' . $content . '</del>';
                }

                if ( isset( $annotations['underline'] ) && $annotations['underline'] ) {
                    $content = '<u>' . $content . '</u>';
                }

                if ( isset( $annotations['code'] ) && $annotations['code'] ) {
                    $content = '<code>' . $content . '</code>';
                }

                // 处理颜色
                if ( isset( $annotations['color'] ) && $annotations['color'] !== 'default' ) {
                    $content = '<span class="notion-color-' . esc_attr( $annotations['color'] ) . '">' . $content . '</span>';
                }
            }

            // 处理链接
            if (!empty($href)) {
                // 检测是否为 Notion 锚点链接
                if (self::is_notion_anchor_link($href)) {
                    // 转换为本地锚点链接，不添加 target="_blank"
                    $local_href = self::convert_notion_anchor_to_local($href);
                    $content = '<a href="' . esc_attr($local_href) . '">' . $content . '</a>';
                } else {
                    // 外部链接保持原有处理方式
                    $content = '<a href="' . esc_url($href) . '" target="_blank">' . $content . '</a>';
                }
            }

            $result .= $content;
        }

        return $result;
    }

    /**
     * 统一处理数学公式表达式
     *
     * @since 2.0.0-beta.1
     * @param string $expression 数学表达式
     * @param string $type 类型：'inline' 或 'block'
     * @return string 处理后的HTML
     */
    public static function process_math_expression(string $expression, string $type = 'inline'): string {
        if (empty($expression)) {
            return $type === 'block' ? '<!-- 空的数学公式 -->' : '';
        }

        // 保留化学公式的特殊处理（确保\ce前缀）
        if (strpos($expression, 'ce{') !== false && strpos($expression, '\\ce{') === false) {
            $expression = preg_replace('/(?<!\\\\)ce\{/', '\\ce{', $expression);
        }

        // 对反斜杠进行一次加倍保护，确保正确传递给KaTeX
        $expr_escaped = str_replace('\\', '\\\\', $expression);

        // 根据类型返回不同的HTML结构
        if ($type === 'block') {
            return '<div class="notion-equation notion-equation-block">$$' . $expr_escaped . '$$</div>';
        } else {
            return '<span class="notion-equation notion-equation-inline">$' . $expr_escaped . '$</span>';
        }
    }

    /**
     * 检测是否为 Notion 锚点链接
     *
     * @since    2.0.0-beta.1
     * @param    string    $href    链接地址
     * @return   bool              是否为 Notion 锚点链接
     */
    public static function is_notion_anchor_link(string $href): bool {
        // 检测是否为 Notion 页面内链接，支持多种格式：
        // 1. https://www.notion.so/page-title-123abc#456def
        // 2. https://notion.so/123abc#456def
        // 3. #456def (相对锚点)
        return (bool) preg_match('/(?:notion\.so.*)?#[a-f0-9-]{8,}/', $href);
    }

    /**
     * 将 Notion 锚点链接转换为本地锚点
     *
     * @since    2.0.0-beta.1
     * @param    string    $href    原始链接地址
     * @return   string             转换后的本地锚点链接
     */
    public static function convert_notion_anchor_to_local(string $href): string {
        // 提取区块 ID 并转换为本地锚点
        if (preg_match('/#([a-f0-9-]{8,})/', $href, $matches)) {
            $block_id = $matches[1];

            // 调试日志：记录原始 ID
            self::debug_log("锚点链接原始 ID: $block_id", 'Anchor Link');

            // 如果是32位无连字符格式，转换为36位带连字符格式
            if (strlen($block_id) === 32 && strpos($block_id, '-') === false) {
                // 将32位 ID 转换为标准的36位 UUID 格式
                $formatted_id = substr($block_id, 0, 8) . '-' .
                               substr($block_id, 8, 4) . '-' .
                               substr($block_id, 12, 4) . '-' .
                               substr($block_id, 16, 4) . '-' .
                               substr($block_id, 20, 12);

                self::debug_log("锚点链接转换后 ID: $formatted_id", 'Anchor Link');
                return '#notion-block-' . $formatted_id;
            }

            // 如果已经是正确格式，直接使用
            return '#notion-block-' . $block_id;
        }
        // 如果无法提取有效的区块 ID，记录警告并返回原始链接
        self::warning_log('无法从锚点链接中提取有效的区块 ID: ' . $href);
        return $href;
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
     * 清理过期和过大的日志文件
     *
     * @since    2.0.0
     * @access   private
     */
    private static function cleanup_logs() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';

        if (!is_dir($log_dir)) {
            return;
        }

        $files = glob($log_dir . '/*.log');
        if (empty($files)) {
            return;
        }

        // 按修改时间排序，最新的在前
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $current_time = time();
        $retention_days = 7; // 保留7天的日志
        $retention_seconds = $retention_days * DAY_IN_SECONDS;

        foreach ($files as $index => $file) {
            $file_mod_time = filemtime($file);
            $file_size = filesize($file);

            // 删除过期的日志文件
            if (($current_time - $file_mod_time) > $retention_seconds) {
                @unlink($file);
                continue;
            }

            // 删除超过最大数量的日志文件
            if ($index >= self::$max_log_files) {
                @unlink($file);
                continue;
            }

            // 如果文件过大，进行轮转
            if ($file_size > self::$max_log_size) {
                self::rotate_log_file($file);
            }
        }
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
     * 开始性能计时 - 简化版，不记录日志
     *
     * @since 2.0.0
     * @param string $operation 操作名称
     * @return float 开始时间
     */
    public static function start_performance_timer(string $operation): float {
        return microtime(true);
    }

    /**
     * 结束性能计时 - 简化版，不记录日志
     *
     * @since 2.0.0
     * @param string $operation 操作名称
     * @param float $start_time 开始时间
     * @param array $additional_data 额外数据
     */
    public static function end_performance_timer(string $operation, float $start_time, array $additional_data = []): void {
        // 性能监控已完全禁用以提升同步速度
        return;
    }


}

// 初始化静态帮助类
Notion_To_WordPress_Helper::init();