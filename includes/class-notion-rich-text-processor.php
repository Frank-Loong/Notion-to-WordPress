<?php
/**
 * Notion富文本处理器
 *
 * 统一处理Notion富文本格式的转换和提取
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notion_Rich_Text_Processor {

    /**
     * 支持的富文本类型
     *
     * @since 1.1.0
     */
    public const TYPE_TEXT = 'text';
    public const TYPE_MENTION = 'mention';
    public const TYPE_EQUATION = 'equation';

    /**
     * 支持的注释类型
     *
     * @since 1.1.0
     */
    public const ANNOTATION_BOLD = 'bold';
    public const ANNOTATION_ITALIC = 'italic';
    public const ANNOTATION_STRIKETHROUGH = 'strikethrough';
    public const ANNOTATION_UNDERLINE = 'underline';
    public const ANNOTATION_CODE = 'code';
    public const ANNOTATION_COLOR = 'color';

    /**
     * 从富文本数组中提取纯文本
     *
     * @since 1.1.0
     * @param array $rich_text_array 富文本数组
     * @return string 提取的纯文本
     */
    public static function extract_plain_text(array $rich_text_array): string {
        if (empty($rich_text_array)) {
            return '';
        }

        $text_parts = [];
        
        foreach ($rich_text_array as $rich_text_item) {
            if (!is_array($rich_text_item)) {
                continue;
            }

            $text_parts[] = self::extract_text_from_item($rich_text_item);
        }

        return implode('', $text_parts);
    }

    /**
     * 将富文本数组转换为HTML
     *
     * @since 1.1.0
     * @param array $rich_text_array 富文本数组
     * @param bool  $preserve_formatting 是否保留格式
     * @return string 转换后的HTML
     */
    public static function convert_to_html(array $rich_text_array, bool $preserve_formatting = true): string {
        if (empty($rich_text_array)) {
            return '';
        }

        $html_parts = [];
        
        foreach ($rich_text_array as $rich_text_item) {
            if (!is_array($rich_text_item)) {
                continue;
            }

            $html_parts[] = self::convert_item_to_html($rich_text_item, $preserve_formatting);
        }

        return implode('', $html_parts);
    }

    /**
     * 将富文本数组转换为Markdown
     *
     * @since 1.1.0
     * @param array $rich_text_array 富文本数组
     * @return string 转换后的Markdown
     */
    public static function convert_to_markdown(array $rich_text_array): string {
        if (empty($rich_text_array)) {
            return '';
        }

        $markdown_parts = [];
        
        foreach ($rich_text_array as $rich_text_item) {
            if (!is_array($rich_text_item)) {
                continue;
            }

            $markdown_parts[] = self::convert_item_to_markdown($rich_text_item);
        }

        return implode('', $markdown_parts);
    }

    /**
     * 检查富文本是否包含特定格式
     *
     * @since 1.1.0
     * @param array  $rich_text_array 富文本数组
     * @param string $annotation      要检查的注释类型
     * @return bool 是否包含指定格式
     */
    public static function has_annotation(array $rich_text_array, string $annotation): bool {
        foreach ($rich_text_array as $rich_text_item) {
            if (!is_array($rich_text_item)) {
                continue;
            }

            $annotations = $rich_text_item['annotations'] ?? [];
            if (!empty($annotations[$annotation])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 提取富文本中的链接
     *
     * @since 1.1.0
     * @param array $rich_text_array 富文本数组
     * @return array 链接数组 [['url' => '...', 'text' => '...'], ...]
     */
    public static function extract_links(array $rich_text_array): array {
        $links = [];
        
        foreach ($rich_text_array as $rich_text_item) {
            if (!is_array($rich_text_item)) {
                continue;
            }

            $href = $rich_text_item['href'] ?? null;
            if ($href) {
                $links[] = [
                    'url' => $href,
                    'text' => self::extract_text_from_item($rich_text_item)
                ];
            }
        }

        return $links;
    }

    /**
     * 清理和验证富文本数据
     *
     * @since 1.1.0
     * @param array $rich_text_array 富文本数组
     * @return array 清理后的富文本数组
     */
    public static function sanitize_rich_text(array $rich_text_array): array {
        $sanitized = [];
        
        foreach ($rich_text_array as $rich_text_item) {
            if (!is_array($rich_text_item)) {
                continue;
            }

            $sanitized_item = self::sanitize_rich_text_item($rich_text_item);
            if ($sanitized_item) {
                $sanitized[] = $sanitized_item;
            }
        }

        return $sanitized;
    }

    /**
     * 从单个富文本项中提取文本
     *
     * @since 1.1.0
     * @param array $rich_text_item 富文本项
     * @return string 提取的文本
     */
    private static function extract_text_from_item(array $rich_text_item): string {
        $type = $rich_text_item['type'] ?? '';
        
        switch ($type) {
            case self::TYPE_TEXT:
                return $rich_text_item['text']['content'] ?? '';
                
            case self::TYPE_MENTION:
                return self::extract_mention_text($rich_text_item);
                
            case self::TYPE_EQUATION:
                return $rich_text_item['equation']['expression'] ?? '';
                
            default:
                return $rich_text_item['plain_text'] ?? '';
        }
    }

    /**
     * 将单个富文本项转换为HTML
     *
     * @since 1.1.0
     * @param array $rich_text_item      富文本项
     * @param bool  $preserve_formatting 是否保留格式
     * @return string 转换后的HTML
     */
    private static function convert_item_to_html(array $rich_text_item, bool $preserve_formatting): string {
        $text = self::extract_text_from_item($rich_text_item);
        
        if (!$preserve_formatting) {
            return esc_html($text);
        }

        $html = esc_html($text);
        $annotations = $rich_text_item['annotations'] ?? [];
        $href = $rich_text_item['href'] ?? null;

        // 应用格式注释
        if (!empty($annotations[self::ANNOTATION_BOLD])) {
            $html = "<strong>{$html}</strong>";
        }
        
        if (!empty($annotations[self::ANNOTATION_ITALIC])) {
            $html = "<em>{$html}</em>";
        }
        
        if (!empty($annotations[self::ANNOTATION_STRIKETHROUGH])) {
            $html = "<del>{$html}</del>";
        }
        
        if (!empty($annotations[self::ANNOTATION_UNDERLINE])) {
            $html = "<u>{$html}</u>";
        }
        
        if (!empty($annotations[self::ANNOTATION_CODE])) {
            $html = "<code>{$html}</code>";
        }

        // 应用链接
        if ($href) {
            $safe_href = esc_url($href);
            $html = "<a href=\"{$safe_href}\">{$html}</a>";
        }

        // 应用颜色（如果需要）
        $color = $annotations[self::ANNOTATION_COLOR] ?? null;
        if ($color && $color !== 'default') {
            $safe_color = esc_attr($color);
            $html = "<span class=\"notion-color-{$safe_color}\">{$html}</span>";
        }

        return $html;
    }

    /**
     * 将单个富文本项转换为Markdown
     *
     * @since 1.1.0
     * @param array $rich_text_item 富文本项
     * @return string 转换后的Markdown
     */
    private static function convert_item_to_markdown(array $rich_text_item): string {
        $text = self::extract_text_from_item($rich_text_item);
        $annotations = $rich_text_item['annotations'] ?? [];
        $href = $rich_text_item['href'] ?? null;

        // 转义Markdown特殊字符
        $text = str_replace(['*', '_', '`', '[', ']'], ['\\*', '\\_', '\\`', '\\[', '\\]'], $text);

        // 应用格式注释
        if (!empty($annotations[self::ANNOTATION_BOLD])) {
            $text = "**{$text}**";
        }
        
        if (!empty($annotations[self::ANNOTATION_ITALIC])) {
            $text = "*{$text}*";
        }
        
        if (!empty($annotations[self::ANNOTATION_STRIKETHROUGH])) {
            $text = "~~{$text}~~";
        }
        
        if (!empty($annotations[self::ANNOTATION_CODE])) {
            $text = "`{$text}`";
        }

        // 应用链接
        if ($href) {
            $text = "[{$text}]({$href})";
        }

        return $text;
    }

    /**
     * 提取提及文本
     *
     * @since 1.1.0
     * @param array $rich_text_item 富文本项
     * @return string 提及文本
     */
    private static function extract_mention_text(array $rich_text_item): string {
        $mention = $rich_text_item['mention'] ?? [];
        $mention_type = $mention['type'] ?? '';
        
        switch ($mention_type) {
            case 'page':
                return $mention['page']['title'] ?? '[Page]';
            case 'user':
                return $mention['user']['name'] ?? '[User]';
            case 'date':
                return $mention['date']['start'] ?? '[Date]';
            default:
                return $rich_text_item['plain_text'] ?? '[Mention]';
        }
    }

    /**
     * 清理单个富文本项
     *
     * @since 1.1.0
     * @param array $rich_text_item 富文本项
     * @return array|null 清理后的富文本项，无效时返回null
     */
    private static function sanitize_rich_text_item(array $rich_text_item): ?array {
        $type = $rich_text_item['type'] ?? '';
        
        if (!in_array($type, [self::TYPE_TEXT, self::TYPE_MENTION, self::TYPE_EQUATION])) {
            return null;
        }

        $sanitized = [
            'type' => $type,
            'annotations' => self::sanitize_annotations($rich_text_item['annotations'] ?? []),
            'plain_text' => sanitize_text_field($rich_text_item['plain_text'] ?? ''),
            'href' => !empty($rich_text_item['href']) ? esc_url_raw($rich_text_item['href']) : null
        ];

        // 根据类型添加特定字段
        switch ($type) {
            case self::TYPE_TEXT:
                $sanitized['text'] = [
                    'content' => sanitize_text_field($rich_text_item['text']['content'] ?? ''),
                    'link' => !empty($rich_text_item['text']['link']['url']) 
                        ? ['url' => esc_url_raw($rich_text_item['text']['link']['url'])]
                        : null
                ];
                break;
                
            case self::TYPE_EQUATION:
                $sanitized['equation'] = [
                    'expression' => sanitize_text_field($rich_text_item['equation']['expression'] ?? '')
                ];
                break;
        }

        return array_filter($sanitized, function($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * 清理注释数据
     *
     * @since 1.1.0
     * @param array $annotations 注释数组
     * @return array 清理后的注释数组
     */
    private static function sanitize_annotations(array $annotations): array {
        $valid_annotations = [
            self::ANNOTATION_BOLD,
            self::ANNOTATION_ITALIC,
            self::ANNOTATION_STRIKETHROUGH,
            self::ANNOTATION_UNDERLINE,
            self::ANNOTATION_CODE,
            self::ANNOTATION_COLOR
        ];

        $sanitized = [];
        
        foreach ($valid_annotations as $annotation) {
            if (isset($annotations[$annotation])) {
                if ($annotation === self::ANNOTATION_COLOR) {
                    $sanitized[$annotation] = sanitize_text_field($annotations[$annotation]);
                } else {
                    $sanitized[$annotation] = (bool) $annotations[$annotation];
                }
            }
        }

        return $sanitized;
    }
}
