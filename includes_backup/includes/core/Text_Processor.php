<?php
declare(strict_types=1);

namespace NTWP\Core;

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

class Text_Processor {

    /**
     * 格式化标签查找表，避免重复的字符串拼接
     */
    private static $format_tags = [
        'bold' => ['<strong>', '</strong>'],
        'italic' => ['<em>', '</em>'],
        'strikethrough' => ['<del>', '</del>'],
        'underline' => ['<u>', '</u>'],
        'code' => ['<code>', '</code>']
    ];

    /**
     * 链接类型检测缓存
     */
    private static $link_cache = [];

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
     * 完整的 Rich Text 处理方法（优化版本）
     *
     * 支持所有格式化功能：粗体、斜体、删除线、下划线、代码、颜色、链接、公式等
     * 使用数组拼接和查找表优化性能
     *
     * @since    2.0.0-beta.1
     * @param    array     $rich_text    富文本数组
     * @return   string                  格式化的HTML文本
     */
    public static function extract_rich_text_complete(array $rich_text): string {
        if (empty($rich_text)) {
            return '';
        }

        // 开始性能监控
        $start_time = microtime(true);

        // 使用数组收集HTML片段，避免频繁字符串拼接
        $html_parts = [];

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

            // 使用查找表和数组拼接优化格式化处理
            if (!empty($annotations)) {
                $format_stack = []; // 用于收集格式化标签

                // 使用查找表快速应用格式化
                foreach (self::$format_tags as $format => $tags) {
                    if (isset($annotations[$format]) && $annotations[$format]) {
                        $format_stack[] = $tags;
                    }
                }

                // 处理颜色（特殊情况）
                if (isset($annotations['color']) && $annotations['color'] !== 'default') {
                    $format_stack[] = [
                        '<span class="notion-color-' . esc_attr($annotations['color']) . '">',
                        '</span>'
                    ];
                }

                // 应用所有格式化（从内到外）
                foreach ($format_stack as $tags) {
                    $content = $tags[0] . $content . $tags[1];
                }
            }

            // 优化的链接处理（使用缓存）
            if (!empty($href)) {
                $content = self::process_link_optimized($content, $href);
            }

            // 将处理后的内容添加到数组中
            $html_parts[] = $content;
        }

        // 一次性拼接所有HTML片段
        $result = implode('', $html_parts);

        // 记录性能监控
        if (class_exists('NTWP\Core\Performance_Monitor')) {
            $processing_time = microtime(true) - $start_time;
            Performance_Monitor::record_custom_metric('rich_text_processing_time', $processing_time);
            Performance_Monitor::record_custom_metric('rich_text_items_processed', count($rich_text));
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
     * 检测是否为 Notion 页面链接
     *
     * @since    2.0.0-beta.1
     * @param    string    $href    链接地址
     * @return   bool              是否为 Notion 页面链接
     */
    public static function is_notion_page_link(string $href): bool {
        // 检测是否为 Notion 页面链接，支持多种格式：
        // 1. https://www.notion.so/22a7544376be81ed8e01e36cf55a6789
        // 2. https://notion.so/22a7544376be81ed8e01e36cf55a6789
        // 3. https://www.notion.so/page-title-22a7544376be81ed8e01e36cf55a6789
        // 4. https://notion.so/page-title-22a75443-76be-81ed-8e01-e36cf55a6789
        $is_page_link = (bool) preg_match('/^https?:\/\/(?:www\.)?notion\.so\/(?:.*-)?([a-f0-9]{32}|[a-f0-9-]{36})(?:[?#].*)?$/i', $href);

        // 调试日志：记录页面链接检测结果
        if ($is_page_link) {
            Logger::debug_log("检测到 Notion 页面链接: $href", 'Page Link Detection');
        }

        return $is_page_link;
    }

    /**
     * 从 Notion 页面链接中提取页面 ID
     *
     * @since    2.0.0-beta.1
     * @param    string    $href    Notion 页面链接
     * @return   string             页面 ID，提取失败返回空字符串
     */
    public static function extract_notion_page_id(string $href): string {
        // 使用正则表达式提取页面 ID
        if (preg_match('/^https?:\/\/(?:www\.)?notion\.so\/(?:.*-)?([a-f0-9]{32}|[a-f0-9-]{36})(?:[?#].*)?$/i', $href, $matches)) {
            $page_id = $matches[1];

            // 调试日志：记录提取的页面 ID
            Logger::debug_log("从链接中提取页面 ID: $page_id", 'Page Link');

            // 标准化页面 ID 格式（转换为32位无连字符格式，与数据库存储一致）
            $normalized_id = str_replace('-', '', $page_id);

            // 验证 ID 长度
            if (strlen($normalized_id) === 32) {
                return $normalized_id;
            } else {
                Logger::warning_log("页面 ID 长度不正确: $normalized_id (长度: " . strlen($normalized_id) . ")", 'Page Link');
                return '';
            }
        }

        Logger::warning_log("无法从链接中提取页面 ID: $href", 'Page Link');
        return '';
    }

    /**
     * 将 Notion 页面链接转换为 WordPress 永久链接
     *
     * @since    2.0.0-beta.1
     * @param    string    $href    原始 Notion 页面链接
     * @return   string             转换后的 WordPress 永久链接，转换失败返回原链接
     */
    public static function convert_notion_page_to_wordpress(string $href): string {
        // 提取页面 ID
        $page_id = self::extract_notion_page_id($href);

        if (empty($page_id)) {
            return $href;
        }

        // 尝试多种格式查找页面ID
        $post_id = 0;
        $found_format = '';

        // 1. 首先尝试无连字符格式查找（32位）
        $post_id = \NTWP\Handlers\Integrator::get_post_by_notion_id($page_id);
        if ($post_id > 0) {
            $found_format = '32位无连字符';
        }

        // 2. 如果无连字符格式未找到，尝试带连字符格式（36位）
        if ($post_id === 0) {
            $formatted_page_id = self::format_page_id_with_hyphens($page_id);

            if (!empty($formatted_page_id)) {
                $post_id = \NTWP\Handlers\Integrator::get_post_by_notion_id($formatted_page_id);

                if ($post_id > 0) {
                    $found_format = '36位带连字符';
                }
            }
        }

        // 3. 如果仍然未找到，尝试反向转换（从36位转32位）
        if ($post_id === 0 && strlen($page_id) === 32) {
            // 检查是否有其他可能的格式变体
            global $wpdb;
            $like_pattern = '%' . substr($page_id, 0, 8) . '%' . substr($page_id, 8, 4) . '%' . substr($page_id, 12, 4) . '%';

            $query = $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                WHERE meta_key = '_notion_page_id' AND meta_value LIKE %s
                LIMIT 5",
                $like_pattern
            );

            $similar_results = $wpdb->get_results($query);

            if (!empty($similar_results)) {
                // 尝试精确匹配
                foreach ($similar_results as $result) {
                    $stored_id = str_replace('-', '', $result->meta_value);
                    if ($stored_id === $page_id) {
                        $post_id = (int)$result->post_id;
                        $found_format = '模糊匹配成功';
                        break;
                    }
                }
            }
        }

        if ($post_id === 0) {
            return $href;
        }

        // 生成 WordPress 永久链接
        $wordpress_url = get_permalink($post_id);

        if (empty($wordpress_url) || $wordpress_url === false) {
            return $href;
        }

        return $wordpress_url;
    }

    /**
     * 将32位无连字符页面ID格式化为36位带连字符格式
     *
     * @since    2.0.0-beta.1
     * @param    string    $page_id    32位无连字符页面ID
     * @return   string                36位带连字符页面ID，格式化失败返回空字符串
     */
    private static function format_page_id_with_hyphens(string $page_id): string {
        // 验证输入是否为32位十六进制字符串
        if (strlen($page_id) !== 32 || !ctype_xdigit($page_id)) {
            return '';
        }

        // 格式化为 8-4-4-4-12 的UUID格式
        $formatted = sprintf(
            '%s-%s-%s-%s-%s',
            substr($page_id, 0, 8),
            substr($page_id, 8, 4),
            substr($page_id, 12, 4),
            substr($page_id, 16, 4),
            substr($page_id, 20, 12)
        );

        return $formatted;
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
            Logger::debug_log("锚点链接原始 ID: $block_id", 'Anchor Link');

            // 如果是32位无连字符格式，转换为36位带连字符格式
            if (strlen($block_id) === 32 && strpos($block_id, '-') === false) {
                // 将32位 ID 转换为标准的36位 UUID 格式
                $formatted_id = substr($block_id, 0, 8) . '-' .
                               substr($block_id, 8, 4) . '-' .
                               substr($block_id, 12, 4) . '-' .
                               substr($block_id, 16, 4) . '-' .
                               substr($block_id, 20, 12);

                Logger::debug_log("锚点链接转换后 ID: $formatted_id", 'Anchor Link');
                return '#notion-block-' . $formatted_id;
            }

            // 如果已经是正确格式，直接使用
            return '#notion-block-' . $block_id;
        }
        // 如果无法提取有效的区块 ID，记录警告并返回原始链接
        Logger::warning_log('无法从锚点链接中提取有效的区块 ID: ' . $href);
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
        // 检查是否启用性能模式
        $options = get_option('notion_to_wordpress_options', []);
        $performance_mode = $options['enable_performance_mode'] ?? 1;

        if ($performance_mode) {
            return self::sanitize_text_optimized($text);
        }

        // 传统模式
        // 移除多余的空白字符
        $text = trim($text);

        // 标准化换行符
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // 移除连续的空行
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return $text;
    }

    /**
     * 优化版本的文本清理
     *
     * @since 2.0.0-beta.1
     * @param string $text 要清理的文本
     * @return string 清理后的文本
     */
    private static function sanitize_text_optimized(string $text): string {
        // 一次性处理多个操作，减少字符串操作次数
        $text = trim($text);

        if (empty($text)) {
            return $text;
        }

        // 使用更高效的字符串替换
        $text = strtr($text, ["\r\n" => "\n", "\r" => "\n"]);

        // 使用更高效的正则表达式
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

        // 解码HTML实体，确保LaTeX符号正确（如 &amp; -> &）
        $expression = html_entity_decode($expression, ENT_QUOTES | ENT_HTML5, 'UTF-8');

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

    /**
     * 使用算法优化器进行批量文本处理
     *
     * 提升字符串处理效率，减少CPU使用
     *
     * @since 2.0.0-beta.1
     * @param array $text_blocks 文本块数组
     * @return array 处理后的文本块
     */
    public static function optimized_batch_text_processing(array $text_blocks): array {
        if (empty($text_blocks)) {
            return [];
        }

        // 提取所有文本内容
        $texts = [];
        foreach ($text_blocks as $index => $block) {
            $texts[$index] = $block['text'] ?? $block['content'] ?? '';
        }

        // 使用算法优化器批量处理
        if (class_exists('NTWP\Core\Algorithm_Optimizer')) {
            $optimized_texts = \NTWP\Core\Algorithm_Optimizer::optimize_string_operations($texts);

            if (class_exists('NTWP\Core\Logger')) {
                Logger::debug_log(
                    sprintf('算法优化器处理了 %d 个文本块', count($texts)),
                    'Text Processor'
                );
            }
        } else {
            $optimized_texts = self::fallback_text_optimization($texts);
        }

        // 将处理后的文本放回块中
        $processed_blocks = [];
        foreach ($text_blocks as $index => $block) {
            if (isset($block['text'])) {
                $block['text'] = $optimized_texts[$index] ?? '';
            } elseif (isset($block['content'])) {
                $block['content'] = $optimized_texts[$index] ?? '';
            }
            $processed_blocks[] = $block;
        }

        return $processed_blocks;
    }

    /**
     * 降级的文本优化（当算法优化器不可用时）
     *
     * @since 2.0.0-beta.1
     * @param array $texts 文本数组
     * @return array 优化后的文本
     */
    private static function fallback_text_optimization(array $texts): array {
        $results = [];

        foreach ($texts as $index => $text) {
            // 基本的文本清理
            $cleaned = str_replace(["\r\n", "\r"], "\n", $text);
            $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);
            $cleaned = trim($cleaned);

            $results[$index] = $cleaned;
        }

        return $results;
    }

    /**
     * 获取文本处理性能统计
     *
     * @since 2.0.0-beta.1
     * @return array 性能统计信息
     */
    public static function get_performance_stats(): array {
        $stats = [
            'optimizer_available' => class_exists('NTWP\Core\Algorithm_Optimizer'),
            'fallback_mode' => !class_exists('NTWP\Core\Algorithm_Optimizer')
        ];

        if (class_exists('NTWP\Core\Algorithm_Optimizer')) {
            $cache_stats = \NTWP\Core\Algorithm_Optimizer::get_cache_stats();
            $stats = array_merge($stats, [
                'cache_efficiency' => $cache_stats['string_cache_size'] > 0,
                'memory_usage_mb' => round($cache_stats['memory_usage'] / 1024 / 1024, 2)
            ]);
        }

        return $stats;
    }

    /**
     * 优化的链接处理方法
     *
     * 使用缓存和优化的检测逻辑处理链接
     *
     * @since 2.0.0-beta.1
     * @param string $content 内容文本
     * @param string $href 链接地址
     * @return string 处理后的HTML内容
     */
    private static function process_link_optimized(string $content, string $href): string {
        // 使用缓存避免重复的链接类型检测
        $cache_key = md5($href);

        if (!isset(self::$link_cache[$cache_key])) {
            // 检测链接类型并缓存结果
            if (self::is_notion_anchor_link($href)) {
                self::$link_cache[$cache_key] = [
                    'type' => 'anchor',
                    'processed_href' => self::convert_notion_anchor_to_local($href)
                ];
            } elseif (self::is_notion_page_link($href)) {
                self::$link_cache[$cache_key] = [
                    'type' => 'page',
                    'processed_href' => self::convert_notion_page_to_wordpress($href)
                ];
            } else {
                self::$link_cache[$cache_key] = [
                    'type' => 'external',
                    'processed_href' => esc_url($href)
                ];
            }
        }

        $link_info = self::$link_cache[$cache_key];

        // 根据链接类型生成HTML
        switch ($link_info['type']) {
            case 'anchor':
                return '<a href="' . esc_attr($link_info['processed_href']) . '">' . $content . '</a>';
            case 'page':
                return '<a href="' . esc_url($link_info['processed_href']) . '">' . $content . '</a>';
            case 'external':
            default:
                return '<a href="' . $link_info['processed_href'] . '" target="_blank">' . $content . '</a>';
        }
    }

    /**
     * 批量优化的富文本处理
     *
     * 专为大量富文本处理优化的方法
     *
     * @since 2.0.0-beta.1
     * @param array $rich_text_array 多个富文本数组
     * @return array 处理后的HTML数组
     */
    public static function batch_extract_rich_text(array $rich_text_array): array {
        if (empty($rich_text_array)) {
            return [];
        }

        $start_time = microtime(true);
        $results = [];

        // 预热链接缓存（如果有重复链接）
        $all_links = [];
        foreach ($rich_text_array as $rich_text) {
            foreach ($rich_text as $text) {
                if (!empty($text['href'])) {
                    $all_links[] = $text['href'];
                }
            }
        }

        // 批量处理每个富文本
        foreach ($rich_text_array as $index => $rich_text) {
            $results[$index] = self::extract_rich_text_complete($rich_text);
        }

        // 记录批量处理性能
        if (class_exists('NTWP\Core\Performance_Monitor')) {
            $processing_time = microtime(true) - $start_time;
            Performance_Monitor::record_custom_metric('batch_rich_text_processing_time', $processing_time);
            Performance_Monitor::record_custom_metric('batch_rich_text_count', count($rich_text_array));
        }

        return $results;
    }

    /**
     * 清理链接缓存
     *
     * 定期清理缓存以避免内存占用过多
     *
     * @since 2.0.0-beta.1
     */
    public static function clear_link_cache(): void {
        self::$link_cache = [];

        if (class_exists('NTWP\Core\Logger')) {
            Logger::debug_log('链接缓存已清理', 'Text Processor');
        }
    }

    /**
     * 获取链接缓存统计
     *
     * @since 2.0.0-beta.1
     * @return array 缓存统计信息
     */
    public static function get_link_cache_stats(): array {
        return [
            'cache_size' => count(self::$link_cache),
            'memory_usage_bytes' => strlen(serialize(self::$link_cache)),
            'cache_enabled' => true
        ];
    }
}
