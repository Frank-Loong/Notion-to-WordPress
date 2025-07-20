<?php
declare(strict_types=1);

/**
 * Notion 文本处理器类
 * 
 * 专门处理插件的文本处理功能，包括富文本转换、锚点处理、数学公式处理等。
 * 统一所有文本相关的处理逻辑，实现单一职责原则，从Helper类中分离出来。
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

class Notion_Text_Processor {

    /**
     * 从 Notion 的富文本（rich_text）数组中提取纯文本内容。
     *
     * @since    2.0.0-beta.1
     * @param    array   $rich_text  Notion 的富文本对象数组。
     * @return   string              连接后的纯文本字符串。
     */
    public static function get_text_from_rich_text(array $rich_text): string {
        return implode('', array_map(function($text_part) {
            return $text_part['plain_text'] ?? '';
        }, $rich_text));
    }

    /**
     * 处理富文本数据，返回处理后的文本数组
     *
     * 此方法只负责文本处理和验证，不生成HTML
     *
     * @since    2.0.0-beta.1
     * @param    array     $rich_text    富文本数组
     * @return   array                   处理后的文本数据数组
     */




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
            // 处理行内公式
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
            Notion_Logger::debug_log("锚点链接原始 ID: $block_id", 'Anchor Link');

            // 如果是32位无连字符格式，转换为36位带连字符格式
            if (strlen($block_id) === 32 && strpos($block_id, '-') === false) {
                // 将32位 ID 转换为标准的36位 UUID 格式
                $formatted_id = substr($block_id, 0, 8) . '-' .
                               substr($block_id, 8, 4) . '-' .
                               substr($block_id, 12, 4) . '-' .
                               substr($block_id, 16, 4) . '-' .
                               substr($block_id, 20, 12);

                Notion_Logger::debug_log("锚点链接转换后 ID: $formatted_id", 'Anchor Link');
                return '#notion-block-' . $formatted_id;
            }

            // 如果已经是正确格式，直接使用
            return '#notion-block-' . $block_id;
        }
        // 如果无法提取有效的区块 ID，记录警告并返回原始链接
        Notion_Logger::warning_log('无法从锚点链接中提取有效的区块 ID: ' . $href);
        return $href;
    }

    /**
     * 清理和标准化文本内容
     *
     * @since 2.0.0-beta.1
     * @param string $text 要清理的文本
     * @return string 清理后的文本
     */
    public static function sanitize_text(string $text): string {
        // 移除多余的空白字符
        $text = trim($text);
        
        // 标准化换行符
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // 移除连续的空行
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        return $text;
    }

    /**
     * 将HTML转换为纯文本
     *
     * @since 2.0.0-beta.1
     * @param string $html HTML内容
     * @return string 纯文本内容
     */
    public static function html_to_text(string $html): string {
        // 移除HTML标签
        $text = wp_strip_all_tags($html);
        
        // 解码HTML实体
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        // 清理文本
        return self::sanitize_text($text);
    }

    /**
     * 截取文本到指定长度，保持单词完整性
     *
     * @since 2.0.0-beta.1
     * @param string $text 原始文本
     * @param int $length 最大长度
     * @param string $suffix 后缀（如省略号）
     * @return string 截取后的文本
     */
    public static function truncate_text(string $text, int $length = 150, string $suffix = '...'): string {
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        // 截取到指定长度
        $truncated = mb_substr($text, 0, $length, 'UTF-8');
        
        // 查找最后一个空格，避免截断单词
        $last_space = mb_strrpos($truncated, ' ', 0, 'UTF-8');
        if ($last_space !== false && $last_space > $length * 0.8) {
            $truncated = mb_substr($truncated, 0, $last_space, 'UTF-8');
        }
        
        return $truncated . $suffix;
    }

    /**
     * 检测文本语言
     *
     * @since 2.0.0-beta.1
     * @param string $text 要检测的文本
     * @return string 语言代码（如 'zh', 'en'）
     */
    public static function detect_language(string $text): string {
        // 简单的语言检测逻辑
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            return 'zh'; // 中文
        } elseif (preg_match('/[\x{3040}-\x{309f}\x{30a0}-\x{30ff}]/u', $text)) {
            return 'ja'; // 日文
        } elseif (preg_match('/[\x{ac00}-\x{d7af}]/u', $text)) {
            return 'ko'; // 韩文
        } else {
            return 'en'; // 默认英文
        }
    }

    /**
     * 生成文本摘要
     *
     * @since 2.0.0-beta.1
     * @param string $text 原始文本
     * @param int $sentence_count 摘要句子数量
     * @return string 文本摘要
     */
    public static function generate_summary(string $text, int $sentence_count = 2): string {
        // 清理文本
        $text = self::sanitize_text($text);

        // 分割句子
        $sentences = preg_split('/[.!?。！？]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($sentences)) {
            return '';
        }

        // 取前几句作为摘要
        $summary_sentences = array_slice($sentences, 0, $sentence_count);

        return implode('。', $summary_sentences) . '。';
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
            return '';
        }

        // 保留化学公式的特殊处理（确保\ce前缀）
        if (strpos($expression, 'ce{') !== false && strpos($expression, '\\ce{') === false) {
            $expression = preg_replace('/(?<!\\\\)ce\{/', '\\ce{', $expression);
        }

        // 对反斜杠进行一次加倍保护，确保正确传递给KaTeX
        $expr_escaped = str_replace('\\', '\\\\', $expression);

        if ($type === 'block') {
            return '<div class="notion-equation notion-equation-block">$$' . $expr_escaped . '$$</div>';
        } else {
            return '<span class="notion-equation notion-equation-inline">$' . $expr_escaped . '$</span>';
        }
    }
}
