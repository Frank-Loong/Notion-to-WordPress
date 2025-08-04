<?php
declare(strict_types=1);

namespace NTWP\Core\Foundation;

use NTWP\Utils\Validator;

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

class Security {
    
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

    /**
     * 验证Notion API Key格式
     *
     * @since 2.0.0-beta.1
     * @param string $api_key 要验证的API Key
     * @return array 验证结果，包含is_valid和error_message
     */
    public static function validate_notion_api_key(string $api_key): array {
        $api_key = trim($api_key);

        // 检查是否为空
        if (empty($api_key)) {
            return [
                'is_valid' => false,
                'error_message' => __('API Key不能为空', 'notion-to-wordpress')
            ];
        }

        // 检查长度
        $length = strlen($api_key);
        if ($length < Validator::API_KEY_MIN_LENGTH || $length > Validator::API_KEY_MAX_LENGTH) {
            return [
                'is_valid' => false,
                'error_message' => sprintf(
                    __('API Key长度必须在%d到%d个字符之间', 'notion-to-wordpress'),
                    Validator::API_KEY_MIN_LENGTH,
                    Validator::API_KEY_MAX_LENGTH
                )
            ];
        }

        // 检查字符格式
        if (!preg_match(Validator::API_KEY_PATTERN, $api_key)) {
            return [
                'is_valid' => false,
                'error_message' => __('API Key只能包含字母、数字、下划线和连字符', 'notion-to-wordpress')
            ];
        }

        return [
            'is_valid' => true,
            'error_message' => ''
        ];
    }

    /**
     * 验证Notion Database ID格式
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 要验证的Database ID
     * @return array 验证结果，包含is_valid和error_message
     */
    public static function validate_database_id(string $database_id): array {
        $database_id = trim($database_id);

        // 检查是否为空
        if (empty($database_id)) {
            return [
                'is_valid' => false,
                'error_message' => __('Database ID不能为空', 'notion-to-wordpress')
            ];
        }

        // 移除可能的连字符
        $clean_id = str_replace('-', '', $database_id);

        // 检查长度
        if (strlen($clean_id) !== Validator::DATABASE_ID_LENGTH) {
            return [
                'is_valid' => false,
                'error_message' => sprintf(
                    __('Database ID必须是%d位十六进制字符串', 'notion-to-wordpress'),
                    Validator::DATABASE_ID_LENGTH
                )
            ];
        }

        // 检查格式
        if (!preg_match(Validator::DATABASE_ID_PATTERN, $clean_id)) {
            return [
                'is_valid' => false,
                'error_message' => __('Database ID格式不正确，应为32位十六进制字符串', 'notion-to-wordpress')
            ];
        }

        return [
            'is_valid' => true,
            'error_message' => ''
        ];
    }

    /**
     * 验证Notion Page ID格式
     *
     * @since 2.0.0-beta.1
     * @param string $page_id 要验证的Page ID
     * @return array 验证结果，包含is_valid和error_message
     */
    public static function validate_notion_page_id(string $page_id): array {
        $page_id = trim($page_id);

        // 检查是否为空
        if (empty($page_id)) {
            return [
                'is_valid' => false,
                'error_message' => __('Page ID不能为空', 'notion-to-wordpress')
            ];
        }

        // 移除可能的连字符
        $clean_id = str_replace('-', '', $page_id);

        // 检查长度
        if (strlen($clean_id) !== Validator::PAGE_ID_LENGTH) {
            return [
                'is_valid' => false,
                'error_message' => sprintf(
                    __('Page ID必须是%d位十六进制字符串', 'notion-to-wordpress'),
                    Validator::PAGE_ID_LENGTH
                )
            ];
        }

        // 检查格式
        if (!preg_match(Validator::PAGE_ID_PATTERN, $clean_id)) {
            return [
                'is_valid' => false,
                'error_message' => __('Page ID格式不正确，应为32位十六进制字符串', 'notion-to-wordpress')
            ];
        }

        return [
            'is_valid' => true,
            'error_message' => ''
        ];
    }

    /**
     * 批量验证输入数据
     *
     * @since 2.0.0-beta.1
     * @param array $data 要验证的数据数组
     * @param array $rules 验证规则数组
     * @return array 验证结果，包含is_valid、errors和warnings
     */
    public static function validate_batch(array $data, array $rules): array {
        $errors = [];
        $warnings = [];

        foreach ($rules as $field => $rule_config) {
            $value = $data[$field] ?? '';
            $rule_type = $rule_config['type'] ?? '';
            $required = $rule_config['required'] ?? false;

            // 检查必填字段
            if ($required && empty($value)) {
                $errors[] = sprintf(__('字段 %s 是必填的', 'notion-to-wordpress'), $field);
                continue;
            }

            // 如果字段为空且非必填，跳过验证
            if (empty($value) && !$required) {
                continue;
            }

            // 根据类型进行验证
            switch ($rule_type) {
                case 'api_key':
                    $result = self::validate_notion_api_key($value);
                    if (!$result['is_valid']) {
                        $errors[] = sprintf(__('%s: %s', 'notion-to-wordpress'), $field, $result['error_message']);
                    }
                    break;

                case 'database_id':
                    $result = self::validate_database_id($value);
                    if (!$result['is_valid']) {
                        $errors[] = sprintf(__('%s: %s', 'notion-to-wordpress'), $field, $result['error_message']);
                    }
                    break;

                case 'page_id':
                    $result = self::validate_notion_page_id($value);
                    if (!$result['is_valid']) {
                        $errors[] = sprintf(__('%s: %s', 'notion-to-wordpress'), $field, $result['error_message']);
                    }
                    break;

                case 'debug_level':
                    if (!in_array((int)$value, Validator::DEBUG_LEVELS, true)) {
                        $errors[] = sprintf(__('%s: 调试级别无效，必须在0-4之间', 'notion-to-wordpress'), $field);
                    }
                    break;

                case 'sync_schedule':
                    if (!in_array($value, Validator::SYNC_SCHEDULES, true)) {
                        $errors[] = sprintf(__('%s: 同步计划选项无效', 'notion-to-wordpress'), $field);
                    }
                    break;

                case 'iframe_whitelist':
                    if ($value !== '*') {
                        $domains = array_map('trim', explode(',', $value));
                        foreach ($domains as $domain) {
                            if (!empty($domain) && !filter_var('http://' . $domain, FILTER_VALIDATE_URL)) {
                                $warnings[] = sprintf(__('%s: 域名格式可能不正确: %s', 'notion-to-wordpress'), $field, $domain);
                            }
                        }
                    }
                    break;

                case 'image_types':
                    $types = array_map('trim', explode(',', $value));
                    foreach ($types as $type) {
                        if (!empty($type) && !in_array($type, Validator::ALLOWED_IMAGE_TYPES, true)) {
                            $warnings[] = sprintf(__('%s: 图片类型可能不支持: %s', 'notion-to-wordpress'), $field, $type);
                        }
                    }
                    break;

                case 'url':
                    if (!self::is_safe_url($value)) {
                        $errors[] = sprintf(__('%s: URL格式不正确或不安全', 'notion-to-wordpress'), $field);
                    }
                    break;

                default:
                    // 对于未知类型，进行基本的文本清理
                    $sanitized = sanitize_text_field($value);
                    if ($sanitized !== $value) {
                        $warnings[] = sprintf(__('%s: 输入内容已被清理', 'notion-to-wordpress'), $field);
                    }
                    break;
            }
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * 验证配置选项数组
     *
     * @since 2.0.0-beta.1
     * @param array $options 配置选项数组
     * @return array 验证结果，包含is_valid、errors和warnings
     */
    public static function validate_plugin_options(array $options): array {
        $validation_rules = [
            'notion_api_key' => [
                'type' => 'api_key',
                'required' => false
            ],
            'notion_database_id' => [
                'type' => 'database_id',
                'required' => false
            ],
            'debug_level' => [
                'type' => 'debug_level',
                'required' => false
            ],
            'sync_schedule' => [
                'type' => 'sync_schedule',
                'required' => false
            ],
            'iframe_whitelist' => [
                'type' => 'iframe_whitelist',
                'required' => false
            ],
            'allowed_image_types' => [
                'type' => 'image_types',
                'required' => false
            ]
        ];

        return self::validate_batch($options, $validation_rules);
    }
}
