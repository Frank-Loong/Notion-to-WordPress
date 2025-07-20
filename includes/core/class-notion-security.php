<?php
declare(strict_types=1);

/**
 * Notion 安全过滤器类
 * 
 * 专门处理插件的安全过滤功能，包括HTML内容过滤、iframe白名单管理、
 * 敏感信息保护等。实现单一职责原则，从Helper类中分离出来。
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

class Notion_Security {
    
    /**
     * 默认iframe白名单域名
     */
    const DEFAULT_IFRAME_WHITELIST = [
        'www.youtube.com',
        'youtu.be',
        'player.bilibili.com',
        'b23.tv',
        'v.qq.com',
    ];

    /**
     * 自定义的 KSES 过滤器，允许更多的 HTML 标签和属性。
     * 
     * 这个方法扩展了 WordPress 的默认 wp_kses 过滤器，以支持
     * 插件功能所需的特定标签和属性（如 iframe、Mermaid 的 pre 标签等）。
     *
     * @since    2.0.0-beta.1
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
     * @since 2.0.0-beta.1
     * @param string $html HTML内容
     * @return string 过滤后的HTML内容
     */
    private static function filter_iframe_src(string $html): string {
        // 从选项中获取白名单域名
        $options = get_option('notion_to_wordpress_options', []);
        $whitelist_string = isset($options['iframe_whitelist']) ? $options['iframe_whitelist'] : '';
        
        // 如果设置为*，则允许所有
        if (trim($whitelist_string) === '*') {
            return $html;
        }
        
        // 获取允许的主机列表
        $allowed_hosts = self::get_allowed_iframe_hosts($whitelist_string);

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
     * 获取允许的iframe主机列表
     *
     * @since 2.0.0-beta.1
     * @param string $whitelist_string 白名单字符串
     * @return array 允许的主机列表
     */
    private static function get_allowed_iframe_hosts(string $whitelist_string): array {
        // 如果没有设置白名单，使用默认值
        if (empty(trim($whitelist_string))) {
            return self::DEFAULT_IFRAME_WHITELIST;
        }
        
        // 将字符串转换为数组
        $allowed_hosts = array_map('trim', explode(',', $whitelist_string));
        
        // 过滤空值
        $allowed_hosts = array_filter($allowed_hosts);
        
        // 如果白名单为空，使用默认值
        if (empty($allowed_hosts)) {
            return self::DEFAULT_IFRAME_WHITELIST;
        }

        return $allowed_hosts;
    }

    /**
     * 清理iframe标签，确保安全性
     *
     * @since 2.0.0-beta.1
     * @param string $content 包含iframe的内容
     * @return string 清理后的内容
     */
    public static function sanitize_iframe(string $content): string {
        // 使用custom_kses进行全面的安全过滤
        return self::custom_kses($content);
    }

    /**
     * 验证URL是否在iframe白名单中
     *
     * @since 2.0.0-beta.1
     * @param string $url 要验证的URL
     * @return bool 是否在白名单中
     */
    public static function is_iframe_url_allowed(string $url): bool {
        $options = get_option('notion_to_wordpress_options', []);
        $whitelist_string = isset($options['iframe_whitelist']) ? $options['iframe_whitelist'] : '';
        
        // 如果设置为*，则允许所有
        if (trim($whitelist_string) === '*') {
            return true;
        }
        
        $allowed_hosts = self::get_allowed_iframe_hosts($whitelist_string);
        $host = wp_parse_url($url, PHP_URL_HOST);
        
        return $host && in_array($host, $allowed_hosts, true);
    }

    /**
     * 获取当前iframe白名单设置
     *
     * @since 2.0.0-beta.1
     * @return array 白名单主机列表
     */
    public static function get_iframe_whitelist(): array {
        $options = get_option('notion_to_wordpress_options', []);
        $whitelist_string = isset($options['iframe_whitelist']) ? $options['iframe_whitelist'] : '';
        
        return self::get_allowed_iframe_hosts($whitelist_string);
    }

    /**
     * 过滤敏感内容，保护用户隐私
     *
     * 注意：此方法主要用于日志记录，已迁移到Notion_Logger类中
     *
     * @since    2.0.0-beta.1
     * @deprecated 2.0.0-beta.1 敏感内容过滤功能已迁移到Notion_Logger类
     * @param    string    $content    要过滤的内容
     * @param    int       $level      日志级别
     * @return   string                过滤后的内容
     */
    public static function filter_sensitive_content($content, $level = 1) {
        // 简化的敏感内容过滤，主要用于向后兼容
        // 详细的过滤逻辑已迁移到Notion_Logger类中

        // 错误级别的内容保持完整
        if ($level <= 1) {
            return $content;
        }

        // 检测并过滤HTML内容（可能包含文章内容）
        if (preg_match('/<[^>]+>/', $content) && strlen($content) > 1000) {
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
     * 验证和清理HTML属性
     *
     * @since 2.0.0-beta.1
     * @param array $attributes 属性数组
     * @param array $allowed_attributes 允许的属性列表
     * @return array 清理后的属性
     */
    public static function sanitize_attributes(array $attributes, array $allowed_attributes): array {
        $clean_attributes = [];
        
        foreach ($attributes as $name => $value) {
            if (in_array($name, $allowed_attributes, true)) {
                $clean_attributes[$name] = sanitize_text_field($value);
            }
        }
        
        return $clean_attributes;
    }

    /**
     * 检查内容是否包含潜在的安全风险
     *
     * @since 2.0.0-beta.1
     * @param string $content 要检查的内容
     * @return array 检查结果，包含风险级别和描述
     */
    public static function security_scan(string $content): array {
        $risks = [];
        
        // 检查是否包含script标签
        if (preg_match('/<script\b[^>]*>/i', $content)) {
            $risks[] = [
                'level' => 'high',
                'type' => 'script_tag',
                'description' => '内容包含script标签，存在XSS风险'
            ];
        }
        
        // 检查是否包含javascript:协议
        if (preg_match('/javascript:/i', $content)) {
            $risks[] = [
                'level' => 'high',
                'type' => 'javascript_protocol',
                'description' => '内容包含javascript:协议，存在XSS风险'
            ];
        }
        
        // 检查是否包含data:协议
        if (preg_match('/data:/i', $content)) {
            $risks[] = [
                'level' => 'medium',
                'type' => 'data_protocol',
                'description' => '内容包含data:协议，需要谨慎处理'
            ];
        }
        
        // 检查是否包含外部iframe
        if (preg_match('/<iframe\b[^>]*src=["\\\']([^"\\\']+)["\\\'][^>]*>/i', $content, $matches)) {
            foreach ($matches as $match) {
                if (!self::is_iframe_url_allowed($match)) {
                    $risks[] = [
                        'level' => 'medium',
                        'type' => 'external_iframe',
                        'description' => '内容包含非白名单iframe：' . $match
                    ];
                }
            }
        }
        
        return $risks;
    }

    /**
     * 验证文件类型是否安全
     *
     * @since 2.0.0-beta.1
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
     * 验证URL是否安全
     *
     * @since 2.0.0-beta.1
     * @param string $url 要验证的URL
     * @return bool 是否为安全的URL
     */
    public static function is_safe_url(string $url): bool {
        // 验证URL格式
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // 解析URL
        $parsed = wp_parse_url($url);
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return false;
        }

        // 只允许HTTP和HTTPS协议
        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            return false;
        }

        // 检查是否为本地地址（可选的安全检查）
        $host = $parsed['host'];
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        // 检查是否为私有IP地址
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }
}
