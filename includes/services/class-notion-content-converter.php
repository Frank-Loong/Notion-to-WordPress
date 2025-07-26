<?php
declare(strict_types=1);

/**
 * Notion 内容转换器类
 *
 * 专门处理 Notion 块到 HTML 的转换功能，支持各种 Notion 块类型（段落、标题、
 * 列表、图片、数据库等）到 HTML 的转换。
 *
 * 设计模式：静态工具类
 * - 所有方法均为静态方法，无状态管理
 * - 专注于数据转换，不涉及业务逻辑
 * - 统一使用 Notion_Logger 进行日志记录
 * - 统一的错误处理和异常管理
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

class Notion_Content_Converter {

    /**
     * 将 Notion 块数组转换为 HTML 内容
     *
     * @since 2.0.0-beta.1
     * @param array $blocks Notion 块数组
     * @param Notion_API $notion_api Notion API 实例
     * @param string $state_id 状态管理器ID，用于图片处理状态隔离
     * @return string HTML 内容
     */
    public static function convert_blocks_to_html(array $blocks, Notion_API $notion_api, string $state_id = null): string {
        // 检查是否启用性能模式
        $options = get_option('notion_to_wordpress_options', []);
        $performance_mode = $options['enable_performance_mode'] ?? 1;

        if ($performance_mode) {
            return self::convert_blocks_to_html_optimized($blocks, $notion_api, $state_id);
        }

        // 传统模式处理
        $html = '';
        $list_wrapper = null;

        // 预处理：识别所有子数据库块并批量获取数据
        $database_blocks = [];
        $database_data = [];

        foreach ($blocks as $block) {
            if (isset($block['type']) && $block['type'] === 'child_database') {
                $database_blocks[] = $block;
            }
        }

        // 如果有子数据库块，使用新的批量处理器（修复：增强错误处理）
        if (!empty($database_blocks)) {
            try {
                $database_data = Notion_Database_Renderer::batch_process_child_databases($database_blocks, $notion_api);
                
                // 验证批量处理结果
                if (empty($database_data)) {
                    Notion_Logger::debug_log(
                        '批量处理返回空数据，将使用标准处理模式',
                        'Child Database Batch'
                    );
                } else {
                    Notion_Logger::debug_log(
                        sprintf('批量处理成功，获取到 %d 个数据库的预处理数据', count($database_data)),
                        'Child Database Batch'
                    );
                }
            } catch (Exception $e) {
                Notion_Logger::error_log(
                    '子数据库批量处理失败: ' . $e->getMessage(),
                    'Child Database Batch'
                );
                $database_data = []; // 确保是空数组，触发标准处理
            }
        }

        // 为这次转换创建本地的已处理块列表，避免跨调用的状态污染
        $local_processed_blocks = [];

        foreach ($blocks as $block) {
            if (in_array($block['id'], $local_processed_blocks)) {
                continue;
            }
            $local_processed_blocks[] = $block['id'];

            $block_type = $block['type'];
            $converter_method = '_convert_block_' . $block_type;

            // -------- 列表块处理（含待办 to_do） --------
            $is_standard_list_item = in_array($block_type, ['bulleted_list_item', 'numbered_list_item']);
            $is_todo_item         = ($block_type === 'to_do');

            if ($is_standard_list_item) {
                // 无序/有序列表
                $current_list_tag = ($block_type === 'bulleted_list_item') ? 'ul' : 'ol';
                if ($list_wrapper !== $current_list_tag) {
                    if ($list_wrapper !== null) {
                        // 关闭之前的列表（包括 todo 列表）
                        $html .= ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
                    }
                    $html .= '<' . $current_list_tag . '>';
                    $list_wrapper = $current_list_tag;
                }
            } elseif ($is_todo_item) {
                // 待办事项列表，统一使用 ul，并带有 class 方便样式
                if ($list_wrapper !== 'todo') {
                    if ($list_wrapper !== null) {
                        $html .= ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
                    }
                    $html .= '<ul class="notion-to-do-list">';
                    $list_wrapper = 'todo';
                }
            } elseif ($list_wrapper !== null) {
                // 当前块非列表项，关闭现有列表
                $html .= ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
                $list_wrapper = null;
            }

            if (method_exists(self::class, $converter_method)) {
                try {
                    // 特殊处理子数据库块，使用预处理的数据
                    if ($block_type === 'child_database') {
                        $block_html = self::_convert_block_child_database_with_data($block, $notion_api, $database_data);
                    } else {
                        // 尝试转换块
                        $block_html = self::{$converter_method}($block, $notion_api);
                    }

                    // 为所有区块添加 ID 包装，支持锚点跳转
                    // 注意：列表项也需要 ID 以支持锚点跳转
                    $block_html = self::wrap_block_with_id($block_html, $block['id'], $block_type);

                    $html .= $block_html;

                    // 特别记录数据库区块的成功转换
                    if ($block_type === 'child_database') {
                        Notion_Logger::info_log(
                            '数据库区块转换成功: ' . ($block['id'] ?? 'unknown'),
                            'Database Block'
                        );
                    }

                } catch (Exception $e) {
                    Notion_Logger::error_log(
                        "转换块失败 [{$block_type}]: " . $e->getMessage(),
                        'Block Converter'
                    );
                    // 继续处理其他块，不中断整个转换过程
                    $html .= '<!-- 块转换失败: ' . esc_html($block_type) . ' -->';
                }
            } else {
                // 未知块类型，记录并跳过
                Notion_Logger::debug_log(
                    "未知块类型: {$block_type}",
                    'Block Converter'
                );
                $html .= '<!-- 未支持的块类型: ' . esc_html($block_type) . ' -->';
            }
        }

        // 确保最后关闭任何未关闭的列表
        if ($list_wrapper !== null) {
            $html .= ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
        }

        return $html;
    }

    /**
     * 转换子块为 HTML
     *
     * @since 2.0.0-beta.1
     * @param array $block 包含子块的块
     * @param Notion_API $notion_api Notion API 实例
     * @return string 子块的 HTML 内容
     */
    private static function _convert_child_blocks(array $block, Notion_API $notion_api): string {
        $child_blocks = [];

        if (isset($block['children']) && is_array($block['children'])) {
            $child_blocks = $block['children'];
        } elseif ($block['has_children'] ?? false) {
            try {
                $child_blocks = $notion_api->get_block_children($block['id']);
            } catch (Exception $e) {
                Notion_Logger::error_log(
                    "获取子块失败: " . $e->getMessage(),
                    'Block Children'
                );
            }
        }

        return !empty($child_blocks) ? self::convert_blocks_to_html($child_blocks, $notion_api) : '';
    }

    /**
     * 为块添加 ID 和类名，支持锚点跳转（直接修改第一层标签，避免额外嵌套）
     *
     * @since 2.0.0-beta.1
     * @param mixed $block_html 块的 HTML 内容（可能是字符串或数组）
     * @param string $block_id 块 ID
     * @param string $block_type 块类型
     * @return string 添加ID和类名后的 HTML
     */
    public static function wrap_block_with_id($block_html, string $block_id, string $block_type): string {
        // 类型安全检查：确保 block_html 是字符串
        if (!is_string($block_html)) {
            if (is_array($block_html)) {
                // 如果是数组，尝试转换为字符串
                Notion_Logger::error_log(
                    "块转换返回了数组而不是字符串: {$block_type} (ID: {$block_id})",
                    'Block Conversion Error'
                );
                $block_html = '<!-- 块转换错误：返回了数组 -->';
            } else {
                // 其他类型，强制转换为字符串
                Notion_Logger::error_log(
                    "块转换返回了非字符串类型: {$block_type} (ID: {$block_id}) - 类型: " . gettype($block_html),
                    'Block Conversion Error'
                );
                $block_html = '<!-- 块转换错误：类型不匹配 -->';
            }
        }

        // 检查是否有 data-skip-wrapper 属性，如果有则跳过包装
        if (strpos($block_html, 'data-skip-wrapper="true"') !== false) {
            // 只添加 ID，不添加额外的类名
            $safe_id = esc_attr('notion-block-' . $block_id);
            return self::add_id_only_to_first_tag($block_html, $safe_id);
        }

        // 确保 ID 和类名安全
        // 保持UUID格式的连字符，生成完整的notion-block-前缀ID，以匹配锚点链接
        $safe_id = esc_attr('notion-block-' . $block_id);
        $safe_class = esc_attr('notion-block notion-' . $block_type);

        // 尝试直接在第一层HTML标签上添加ID和类名，避免额外嵌套
        return self::add_attributes_to_first_tag($block_html, $safe_id, $safe_class);
    }

    /**
     * 在HTML的第一个标签上只添加ID属性（用于特殊块如Mermaid）
     *
     * @param string $html HTML内容
     * @param string $id 要添加的ID
     * @return string 修改后的HTML
     */
    private static function add_id_only_to_first_tag(string $html, string $id): string {
        // 如果HTML为空或不包含标签，则用div包装
        if (empty($html) || !preg_match('/<[^>]+>/', $html)) {
            return '<div id="' . $id . '">' . $html . '</div>';
        }

        // 查找第一个HTML标签
        if (preg_match('/^(\s*)<([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^>]*)?)(\s*\/?>)/', $html, $matches)) {
            $before_tag = $matches[1]; // 标签前的空白
            $tag_name = $matches[2];   // 标签名
            $existing_attrs = $matches[3]; // 现有属性
            $tag_end = $matches[4];    // 标签结束部分

            // 检查是否已有ID属性
            if (!preg_match('/\bid\s*=/', $existing_attrs)) {
                $id_attr = ' id="' . $id . '"';
                $new_attrs = $existing_attrs . $id_attr;
            } else {
                $new_attrs = $existing_attrs;
            }

            // 构建新的开始标签
            $new_opening_tag = $before_tag . '<' . $tag_name . $new_attrs . $tag_end;

            // 替换原始HTML中的第一个标签
            return preg_replace('/^(\s*)<([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^>]*)?)(\s*\/?>)/', $new_opening_tag, $html, 1);
        }

        // 如果无法解析第一个标签，则用div包装（兜底方案）
        return '<div id="' . $id . '">' . $html . '</div>';
    }

    /**
     * 在HTML的第一个标签上添加ID和类名属性
     *
     * @param string $html HTML内容
     * @param string $id 要添加的ID
     * @param string $class 要添加的类名
     * @return string 修改后的HTML
     */
    private static function add_attributes_to_first_tag(string $html, string $id, string $class): string {
        // 如果HTML为空或不包含标签，则用div包装
        if (empty($html) || !preg_match('/<[^>]+>/', $html)) {
            return '<div id="' . $id . '" class="' . $class . '">' . $html . '</div>';
        }

        // 查找第一个HTML标签
        if (preg_match('/^(\s*)<([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^>]*)?)(\s*\/?>)/', $html, $matches)) {
            $before_tag = $matches[1]; // 标签前的空白
            $tag_name = $matches[2];   // 标签名
            $existing_attrs = $matches[3]; // 现有属性
            $tag_end = $matches[4];    // 标签结束部分

            // 检查是否已有ID属性
            $id_attr = '';
            if (!preg_match('/\bid\s*=/', $existing_attrs)) {
                $id_attr = ' id="' . $id . '"';
            }

            // 检查是否已有class属性
            if (preg_match('/\bclass\s*=\s*["\']([^"\']*)["\']/', $existing_attrs, $class_matches)) {
                // 已有class，合并类名
                $existing_classes = $class_matches[1];
                $new_class = ' class="' . $existing_classes . ' ' . $class . '"';
                $new_attrs = preg_replace('/\bclass\s*=\s*["\'][^"\']*["\']/', $new_class, $existing_attrs);
            } else {
                // 没有class，添加新的
                $new_attrs = $existing_attrs . ' class="' . $class . '"';
            }

            // 构建新的开始标签
            $new_opening_tag = $before_tag . '<' . $tag_name . $id_attr . $new_attrs . $tag_end;

            // 替换原始HTML中的第一个标签
            return preg_replace('/^(\s*)<([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^>]*)?)(\s*\/?>)/', $new_opening_tag, $html, 1);
        }

        // 如果无法解析第一个标签，则用div包装（兜底方案）
        return '<div id="' . $id . '" class="' . $class . '">' . $html . '</div>';
    }

    // ==================== 基础块转换方法 ====================

    /**
     * 转换段落块
     */
    private static function _convert_block_paragraph(array $block, Notion_API $notion_api): string {
        $text = self::extract_rich_text($block['paragraph']['rich_text']);
        $html = empty($text) ? '<p>&nbsp;</p>' : '<p>' . $text . '</p>';
        $html .= self::_convert_child_blocks($block, $notion_api);
        return $html;
    }

    /**
     * 转换一级标题块
     */
    private static function _convert_block_heading_1(array $block, Notion_API $notion_api): string {
        $text = self::extract_rich_text($block['heading_1']['rich_text']);
        return '<h1>' . $text . '</h1>' . self::_convert_child_blocks($block, $notion_api);
    }

    /**
     * 转换二级标题块
     */
    private static function _convert_block_heading_2(array $block, Notion_API $notion_api): string {
        $text = self::extract_rich_text($block['heading_2']['rich_text']);
        return '<h2>' . $text . '</h2>' . self::_convert_child_blocks($block, $notion_api);
    }

    /**
     * 转换三级标题块
     */
    private static function _convert_block_heading_3(array $block, Notion_API $notion_api): string {
        $text = self::extract_rich_text($block['heading_3']['rich_text']);
        return '<h3>' . $text . '</h3>' . self::_convert_child_blocks($block, $notion_api);
    }

    /**
     * 转换无序列表项块
     */
    private static function _convert_block_bulleted_list_item(array $block, Notion_API $notion_api): string {
        $text = self::extract_rich_text($block['bulleted_list_item']['rich_text']);
        return '<li>' . $text . self::_convert_child_blocks($block, $notion_api) . '</li>';
    }

    /**
     * 转换有序列表项块
     */
    private static function _convert_block_numbered_list_item(array $block, Notion_API $notion_api): string {
        $text = self::extract_rich_text($block['numbered_list_item']['rich_text']);
        return '<li>' . $text . self::_convert_child_blocks($block, $notion_api) . '</li>';
    }

    /**
     * 转换待办事项块
     */
    private static function _convert_block_to_do(array $block, Notion_API $notion_api): string {
        $text    = self::extract_rich_text($block['to_do']['rich_text']);
        $checked = isset($block['to_do']['checked']) && $block['to_do']['checked'] ? ' checked' : '';

        // 构建列表项，包含 checkbox 与文本
        $html  = '<li class="notion-to-do">';
        $html .= '<input type="checkbox"' . $checked . ' disabled>'; // 仅展示，不可改动
        $html .= '<span class="notion-to-do-text">' . $text . '</span>';

        // 递归处理子块（支持多级待办）
        $html .= self::_convert_child_blocks($block, $notion_api);
        $html .= '</li>';

        return $html;
    }

    /**
     * 转换折叠块
     */
    private static function _convert_block_toggle(array $block, Notion_API $notion_api): string {
        $text = self::extract_rich_text($block['toggle']['rich_text']);
        return '<details class="notion-toggle"><summary>' . $text . '</summary>' . self::_convert_child_blocks($block, $notion_api) . '</details>';
    }

    /**
     * 转换子页面块
     */
    private static function _convert_block_child_page(array $block, Notion_API $notion_api): string {
        $title = $block['child_page']['title'];
        return '<div class="notion-child-page"><span>' . esc_html($title) . '</span></div>';
    }

    /**
     * 转换分割线块
     */
    private static function _convert_block_divider(array $block, Notion_API $notion_api): string {
        return '<hr>';
    }

    /**
     * 转换引用块
     */
    private static function _convert_block_quote(array $block, Notion_API $notion_api): string {
        $text = self::extract_rich_text($block['quote']['rich_text']);
        $child_content = self::_convert_child_blocks($block, $notion_api);
        return '<blockquote>' . $text . $child_content . '</blockquote>';
    }

    /**
     * 转换代码块
     */
    private static function _convert_block_code(array $block, Notion_API $notion_api): string {
        $language = strtolower($block['code']['language'] ?? 'text');

        // 提取代码内容
        $code_content = '';
        if (isset($block['code']['rich_text']) && is_array($block['code']['rich_text'])) {
            foreach ($block['code']['rich_text'] as $text_obj) {
                $code_content .= $text_obj['plain_text'] ?? '';
            }
        }

        // 特殊处理Mermaid图表 - 修复字符编码问题
        if ($language === 'mermaid') {
            // 直接使用原始代码，只做最基本的清理
            $mermaid_code = trim($code_content);
            
            // 处理HTML实体和特殊字符
            $mermaid_code = str_replace([
                // HTML实体
                '--&gt;', 
                '-&gt;',
                '&gt;&gt;',
                '&gt;',
                '&lt;',
                '&quot;',
                '&#39;',
                '&amp;',
                // Unicode字符问题
                '–>', // em dash + >
                '—>', // em dash + >
                '–', // em dash
                '—', // em dash
                // 其他可能的箭头问题
                '»', // right guillemet
                '«', // left guillemet
            ], [
                // 正确的Mermaid语法
                '-->', 
                '->',
                '>>',
                '>',
                '<',
                '"',
                "'",
                '&',
                // 修复为正确的连字符
                '-->', 
                '-->', 
                '-', 
                '-',
                // 修复guillemets
                '>',
                '<',
            ], $mermaid_code);
            
            // 直接输出，不进行额外的HTML转义
            return '<div class="mermaid">' . $mermaid_code . '</div>';
        }

        $escaped_code = esc_html($code_content);
        return '<pre><code class="language-' . esc_attr($language) . '">' . $escaped_code . '</code></pre>';
    }

    /**
     * 转换图片块
     */
    private static function _convert_block_image(array $block, Notion_API $notion_api): string {
        $image_data = $block['image'];
        $type       = $image_data['type'] ?? 'external';
        $url        = '';
        $caption    = '';

        // 获取图片URL
        if ($type === 'external' && isset($image_data['external']['url'])) {
            $url = $image_data['external']['url'];
        } elseif ($type === 'file' && isset($image_data['file']['url'])) {
            $url = $image_data['file']['url'];
        }

        // 获取图片说明
        if (isset($image_data['caption']) && is_array($image_data['caption'])) {
            $caption = self::extract_rich_text($image_data['caption']);
        }

        if (empty($url)) {
            return '<!-- 图片URL为空 -->';
        }

        // 检查是否启用了异步图片模式
        // 注意：这里使用默认状态ID，因为方法签名限制
        if (Notion_Image_Processor::is_async_image_mode_enabled()) {
            // 异步模式：收集图片信息并返回占位符
            return Notion_Image_Processor::collect_image_for_download($url, $caption);
        } else {
            // 同步模式：直接生成图片HTML
            $alt_text = !empty($caption) ? esc_attr($caption) : '';
            $html = '<figure class="notion-image">';
            $html .= '<img src="' . esc_url($url) . '" alt="' . $alt_text . '" loading="lazy">';
            if (!empty($caption)) {
                $html .= '<figcaption>' . $caption . '</figcaption>';
            }
            $html .= '</figure>';
            return $html;
        }
    }

    /**
     * 转换标注块
     */
    private static function _convert_block_callout(array $block, Notion_API $notion_api): string {
        $text = self::extract_rich_text($block['callout']['rich_text']);
        $icon = '';
        if (isset($block['callout']['icon'])) {
            if (isset($block['callout']['icon']['emoji'])) {
                $icon = $block['callout']['icon']['emoji'];
            } elseif (isset($block['callout']['icon']['external']['url'])) {
                $icon = '<img src="' . esc_url($block['callout']['icon']['external']['url']) . '" class="notion-callout-icon" alt="icon">';
            }
        }

        // 现在直接返回完整的callout结构，因为wrap_block_with_id会在第一层标签上添加ID和类
        return '<div class="notion-callout">' . $icon . '<div class="notion-callout-content">' . $text . '</div></div>';
    }

    /**
     * 转换书签块
     */
    private static function _convert_block_bookmark(array $block, Notion_API $notion_api): string {
        $url = esc_url($block['bookmark']['url']);
        $caption = self::extract_rich_text($block['bookmark']['caption'] ?? []);

        $html = '<div class="notion-bookmark">';
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
        if (!empty($caption)) {
            $html .= '<div class="notion-bookmark-caption">' . $caption . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * 转换数学公式块
     */
    private static function _convert_block_equation(array $block, Notion_API $notion_api): string {
        $expression = $block['equation']['expression'] ?? '';

        // 使用统一的公式处理方法
        return Notion_Text_Processor::process_math_expression($expression, 'block');
    }

    /**
     * 转换表格块
     */
    private static function _convert_block_table(array $block, Notion_API $notion_api): string {
        // 获取所有行（优先复用 children）
        if (isset($block['children']) && is_array($block['children'])) {
            $rows = $block['children'];
        } elseif ($block['has_children'] ?? false) {
            try {
                $rows = $notion_api->get_block_children($block['id']);
            } catch (Exception $e) {
                Notion_Logger::error_log("获取表格行失败: " . $e->getMessage(), 'Table Block');
                return '<!-- 表格加载失败 -->';
            }
        } else {
            return '<!-- 空表格 -->';
        }

        if (empty($rows)) {
            return '<!-- 空表格 -->';
        }

        $html = '<table class="notion-table">';
        $has_header = $block['table']['has_row_header'] ?? false;
        $has_column_header = $block['table']['has_column_header'] ?? false;

        foreach ($rows as $index => $row) {
            if ($row['type'] !== 'table_row') {
                continue;
            }

            $is_header_row = $has_column_header && $index === 0;
            $tag = $is_header_row ? 'th' : 'td';

            $html .= '<tr>';

            if (isset($row['table_row']['cells']) && is_array($row['table_row']['cells'])) {
                foreach ($row['table_row']['cells'] as $cell_index => $cell) {
                    $is_header_cell = $has_header && $cell_index === 0 && !$is_header_row;
                    $cell_tag = $is_header_cell ? 'th' : $tag;

                    $cell_content = self::extract_rich_text($cell);
                    $html .= '<' . $cell_tag . '>' . $cell_content . '</' . $cell_tag . '>';
                }
            }

            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * 转换表格行块
     */
    private static function _convert_block_table_row(array $block, Notion_API $notion_api): string {
        // 这个方法通常不会被直接调用，因为表格行在 _convert_block_table 中处理
        // 但为了完整性，我们提供一个基本实现
        $html = '<tr>';

        if (isset($block['table_row']['cells']) && is_array($block['table_row']['cells'])) {
            foreach ($block['table_row']['cells'] as $cell) {
                $cell_content = self::extract_rich_text($cell);
                $html .= '<td>' . $cell_content . '</td>';
            }
        }

        $html .= '</tr>';
        return $html;
    }

    /**
     * 转换嵌入块
     */
    private static function _convert_block_embed(array $block, Notion_API $notion_api): string {
        $url = isset($block['embed']['url']) ? $block['embed']['url'] : '';
        if (empty($url)) {
            return '<!-- 嵌入URL为空 -->';
        }

        $caption = self::extract_rich_text($block['embed']['caption'] ?? []);

        // 根据URL类型处理不同的嵌入
        if (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
            // YouTube视频
            $video_id = '';
            if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches)) {
                $video_id = $matches[1];
            }
            if ($video_id) {
                return '<div class="notion-embed notion-embed-youtube"><iframe width="560" height="315" src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
            }
        } elseif (strpos($url, 'vimeo.com') !== false) {
            // Vimeo视频
            $video_id = '';
            if (preg_match('/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|)(\d+)(?:|\/\?)/', $url, $matches)) {
                $video_id = $matches[2];
            }
            if ($video_id) {
                return '<div class="notion-embed notion-embed-vimeo"><iframe src="https://player.vimeo.com/video/' . esc_attr($video_id) . '" width="560" height="315" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
            }
        } elseif (strpos($url, 'bilibili.com') !== false) {
            // Bilibili视频
            $video_id = '';
            if (preg_match('/bilibili\.com\/video\/([^\/\?&]+)/', $url, $matches)) {
                $video_id = $matches[1];
            }
            if ($video_id) {
                return '<div class="notion-embed notion-embed-bilibili"><iframe src="//player.bilibili.com/player.html?bvid=' . esc_attr($video_id) . '&page=1" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true" width="560" height="315"></iframe></div>';
            }
        }

        // 通用网页嵌入
        $html = '<div class="notion-embed">';
        $html .= '<iframe src="' . esc_url($url) . '" width="100%" height="500" frameborder="0"></iframe>';
        if (!empty($caption)) {
            $html .= '<div class="notion-embed-caption">' . $caption . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * 转换视频块
     */
    private static function _convert_block_video(array $block, Notion_API $notion_api): string {
        $type = isset($block['video']['type']) ? $block['video']['type'] : '';
        $url = '';

        if ($type === 'external') {
            $url = isset($block['video']['external']['url']) ? $block['video']['external']['url'] : '';
        } elseif ($type === 'file') {
            $url = isset($block['video']['file']['url']) ? $block['video']['file']['url'] : '';
        }

        if (empty($url)) {
            return '<!-- 无效的视频URL -->';
        }

        // 处理不同的视频平台
        if (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
            // YouTube视频
            $video_id = '';
            if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches)) {
                $video_id = $matches[1];
            }
            if ($video_id) {
                return '<div class="notion-video notion-video-youtube"><iframe width="560" height="315" src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
            }
        } elseif (strpos($url, 'vimeo.com') !== false) {
            // Vimeo视频
            $video_id = '';
            if (preg_match('/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|)(\d+)(?:|\/\?)/', $url, $matches)) {
                $video_id = $matches[2];
            }
            if ($video_id) {
                return '<div class="notion-video notion-video-vimeo"><iframe src="https://player.vimeo.com/video/' . esc_attr($video_id) . '" width="560" height="315" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
            }
        } elseif (strpos($url, 'bilibili.com') !== false) {
            // Bilibili视频
            $video_id = '';
            if (preg_match('/bilibili\.com\/video\/([^\/\?&]+)/', $url, $matches)) {
                $video_id = $matches[1];
            }
            if ($video_id) {
                return '<div class="notion-video notion-video-bilibili"><iframe src="//player.bilibili.com/player.html?bvid=' . esc_attr($video_id) . '&page=1" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true" width="560" height="315"></iframe></div>';
            }
        }

        // 对于其他视频文件，使用HTML5 video标签
        $caption = self::extract_rich_text($block['video']['caption'] ?? []);
        $html = '<div class="notion-video">';
        $html .= '<video controls>';
        $html .= '<source src="' . esc_url($url) . '">';
        $html .= '您的浏览器不支持视频播放。';
        $html .= '</video>';
        if (!empty($caption)) {
            $html .= '<div class="notion-video-caption">' . $caption . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    // ==================== 布局和高级块 ====================

    /**
     * 转换列列表块
     */
    private static function _convert_block_column_list(array $block, Notion_API $notion_api): string {
        // 列表容器
        $html = '<div class="notion-column-list">';
        $html .= self::_convert_child_blocks($block, $notion_api);
        $html .= '</div>';
        return $html;
    }

    /**
     * 转换列块
     */
    private static function _convert_block_column(array $block, Notion_API $notion_api): string {
        // 计算列宽（Notion API 提供 width_ratio，直接用作 flex-grow 值）
        $ratio = $block['column']['width_ratio'] ?? 1;
        $style = 'flex-grow: ' . esc_attr($ratio) . ';';

        $html = '<div class="notion-column" style="' . $style . '">';
        $html .= self::_convert_child_blocks($block, $notion_api);
        $html .= '</div>';
        return $html;
    }

    /**
     * 转换文件块
     */
    private static function _convert_block_file(array $block, Notion_API $notion_api): string {
        $file_data = $block['file'];
        $type      = $file_data['type'] ?? 'external';
        $url       = '';
        $name      = '';

        if ($type === 'external' && isset($file_data['external']['url'])) {
            $url = $file_data['external']['url'];
        } elseif ($type === 'file' && isset($file_data['file']['url'])) {
            $url = $file_data['file']['url'];
        }

        // 获取文件名
        if (isset($file_data['name'])) {
            $name = $file_data['name'];
        } else {
            $name = basename(parse_url($url, PHP_URL_PATH)) ?: '下载文件';
        }

        $caption = self::extract_rich_text($file_data['caption'] ?? []);

        $html = '<div class="notion-file">';
        $html .= '<a href="' . esc_url($url) . '" download>';
        $html .= '<span class="notion-file-icon">📁</span>';
        $html .= '<span class="notion-file-name">' . esc_html($name) . '</span>';
        $html .= '</a>';
        if (!empty($caption)) {
            $html .= '<div class="notion-file-caption">' . $caption . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * 转换PDF块
     */
    private static function _convert_block_pdf(array $block, Notion_API $notion_api): string {
        $pdf_data = $block['pdf'] ?? [];
        $type     = $pdf_data['type'] ?? 'external';
        $url      = '';

        if ($type === 'external' && isset($pdf_data['external']['url'])) {
            $url = $pdf_data['external']['url'];
        } elseif ($type === 'file' && isset($pdf_data['file']['url'])) {
            $url = $pdf_data['file']['url'];
        }

        if (empty($url)) {
            return '<!-- PDF URL为空 -->';
        }

        $caption = self::extract_rich_text($pdf_data['caption'] ?? []);

        $html = '<div class="notion-pdf-container">';
        $html .= '<iframe src="' . esc_url($url) . '" width="100%" height="600" frameborder="0" type="application/pdf" style="max-width: 100%; height: 600px;"></iframe>';
        $html .= '</div>';
        $html .= '<div class="notion-pdf-fallback">';
        $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">查看PDF文档</a>';
        $html .= '</div>';
        if (!empty($caption)) {
            $html .= '<div class="notion-pdf-caption">' . $caption . '</div>';
        }

        return $html;
    }

    /**
     * 转换同步块
     */
    private static function _convert_block_synced_block(array $block, Notion_API $notion_api): string {
        // 直接渲染其子块
        return self::_convert_child_blocks($block, $notion_api);
    }

    /**
     * 转换页面链接块
     */
    private static function _convert_block_link_to_page(array $block, Notion_API $notion_api): string {
        $data = $block['link_to_page'] ?? [];

        if (isset($data['page_id'])) {
            $page_id = $data['page_id'];
            // 这里可以根据需要获取页面标题或生成链接
            return '<div class="notion-link-to-page">链接到页面: ' . esc_html($page_id) . '</div>';
        } elseif (isset($data['database_id'])) {
            $database_id = $data['database_id'];
            return '<div class="notion-link-to-page">链接到数据库: ' . esc_html($database_id) . '</div>';
        }

        return '<div class="notion-link-to-page">未知页面链接</div>';
    }

    /**
     * 转换子数据库块（标准版本）
     */
    private static function _convert_block_child_database(array $block, Notion_API $notion_api): string {
        $database_title = $block['child_database']['title'] ?? '未命名数据库';
        $database_id = $block['id'];

        // 调试：输出完整的child_database块结构
        Notion_Logger::debug_log(
            'child_database块完整结构: ' . json_encode($block, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'Child Database Block Debug'
        );

        try {
            // 使用数据库渲染器处理子数据库
            $rendered_content = Notion_Database_Renderer::render_child_database($database_id, $database_title, $notion_api);

            if (!empty($rendered_content)) {
                Notion_Logger::info_log(
                    "子数据库渲染成功: {$database_title} (ID: {$database_id})",
                    'Child Database'
                );
                return $rendered_content;
            } else {
                Notion_Logger::warning_log(
                    "子数据库渲染为空: {$database_title} (ID: {$database_id})",
                    'Child Database'
                );
                return '<div class="notion-child-database-empty">数据库 "' . esc_html($database_title) . '" 暂无内容</div>';
            }
        } catch (Exception $e) {
            Notion_Logger::error_log(
                "子数据库渲染失败: {$database_title} (ID: {$database_id}) - " . $e->getMessage(),
                'Child Database'
            );
            return '<div class="notion-child-database-error">数据库 "' . esc_html($database_title) . '" 加载失败</div>';
        }
    }

    /**
     * 转换子数据库块（使用预处理数据）
     */
    private static function _convert_block_child_database_with_data(array $block, Notion_API $notion_api, array $database_data): string {
        $database_title = $block['child_database']['title'] ?? '未命名数据库';
        $database_id = $block['id'];

        // 从预处理的数据中获取渲染结果
        if (isset($database_data[$database_id])) {
            $data = $database_data[$database_id];

            if (!empty($data) && is_array($data) && isset($data['info']) && isset($data['records'])) {
                // 调用适当的渲染方法将原始数据转换为HTML字符串
                $rendered_content = Notion_Database_Renderer::render_database_preview_records_with_data(
                    $database_id,
                    $data['info'],
                    $data['records']
                );

                if (!empty($rendered_content)) {
                    Notion_Logger::info_log(
                        "子数据库批量渲染成功: {$database_title} (ID: {$database_id})",
                        'Child Database Batch'
                    );
                    return $rendered_content;
                }
            }
        }

        // 如果预处理数据中没有，回退到标准处理
        Notion_Logger::debug_log(
            "子数据库预处理数据缺失，回退到标准处理: {$database_title} (ID: {$database_id})",
            'Child Database Batch'
        );

        return self::_convert_block_child_database($block, $notion_api);
    }

    // ==================== 辅助方法 ====================

    /**
     * 提取富文本内容并转换为HTML
     */
    private static function extract_rich_text($rich_text): string {
        if (empty($rich_text)) {
            return '';
        }
        
        $result = '';
        
        foreach ($rich_text as $text) {
            // 处理行内公式 - 使用统一的处理方法
            if ( isset( $text['type'] ) && $text['type'] === 'equation' ) {
                $expr_raw = $text['equation']['expression'] ?? '';
                $content = Notion_Text_Processor::process_math_expression($expr_raw, 'inline');
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
                if (Notion_Text_Processor::is_notion_anchor_link($href)) {
                    // 转换为本地锚点链接，不添加 target="_blank"
                    $local_href = Notion_Text_Processor::convert_notion_anchor_to_local($href);
                    $content = '<a href="' . esc_attr($local_href) . '">' . $content . '</a>';
                } elseif (Notion_Text_Processor::is_notion_page_link($href)) {
                    // 转换为 WordPress 永久链接，不添加 target="_blank"
                    $wordpress_href = Notion_Text_Processor::convert_notion_page_to_wordpress($href);
                    $content = '<a href="' . esc_url($wordpress_href) . '">' . $content . '</a>';
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
     * 从YouTube URL提取视频ID
     */
    private static function extract_youtube_id(string $url): ?string {
        $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/';
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    // ==================== 单个块转换方法 ====================

    /**
     * 转换单个Notion块为HTML
     *
     * 提供单个块转换的公共入口接口，内部调用相应的私有转换方法。
     * 这个方法是对现有批量转换方法的补充，确保转换逻辑的一致性。
     *
     * @since 2.0.0-beta.1
     * @param array $block Notion块数据
     * @param Notion_API $notion_api Notion API实例
     * @return string 转换后的HTML内容
     */
    public static function convert_block_to_html(array $block, Notion_API $notion_api): string {
        if (empty($block) || !isset($block['type']) || empty($block['type'])) {
            Notion_Logger::debug_log(
                '无效的块数据：缺少type字段或type为空',
                'Block Conversion'
            );
            return '<!-- 无效的块数据 -->';
        }

        $block_type = $block['type'];
        $converter_method = '_convert_block_' . $block_type;

        Notion_Logger::debug_log(
            "转换单个块: {$block_type}",
            'Block Conversion'
        );

        // 检查转换方法是否存在
        if (method_exists(self::class, $converter_method)) {
            try {
                // 调用相应的私有转换方法
                $html = self::{$converter_method}($block, $notion_api);

                Notion_Logger::debug_log(
                    "块转换成功: {$block_type}",
                    'Block Conversion'
                );

                return $html;
            } catch (Exception $e) {
                Notion_Logger::error_log(
                    "块转换失败: {$block_type} - " . $e->getMessage(),
                    'Block Conversion'
                );

                return '<!-- 块转换失败: ' . esc_html($block_type) . ' -->';
            }
        } else {
            Notion_Logger::debug_log(
                "未支持的块类型: {$block_type}",
                'Block Conversion'
            );

            return '<!-- 未支持的块类型: ' . esc_html($block_type) . ' -->';
        }
    }

    /**
     * 优化版本的块转换方法
     *
     * 使用性能优化技术：
     * - 减少日志调用
     * - 使用数组拼接而非字符串拼接
     * - 批量处理相同类型的块
     * - 减少重复的HTML转义操作
     *
     * @since 2.0.0-beta.1
     * @param array $blocks Notion 块数组
     * @param Notion_API $notion_api Notion API 实例
     * @param string $state_id 状态管理器ID
     * @return string HTML 内容
     */
    private static function convert_blocks_to_html_optimized(array $blocks, Notion_API $notion_api, string $state_id = null): string {
        if (empty($blocks)) {
            return '';
        }

        // 开始性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::start_timer('content_conversion');
        }

        // 智能选择处理器
        $block_count = count($blocks);
        $use_lightweight = false;

        if (class_exists('Notion_Memory_Manager')) {
            $use_lightweight = Notion_Memory_Manager::is_lightweight_mode($block_count, true);
            Notion_Memory_Manager::monitor_memory_usage('Content Conversion');
        }

        // 智能选择处理方式
        if ($use_lightweight && class_exists('Notion_Stream_Processor')) {
            return self::convert_blocks_lightweight($blocks, $notion_api, $state_id);
        }

        // 对于中等大小的数据集，使用超级优化版本
        if ($block_count >= 20 && $block_count <= 200) {
            return self::convert_blocks_to_html_ultra_optimized($blocks, $notion_api, $state_id);
        }

        // 使用算法优化器预处理文本内容（只对大量内容启用）
        if (class_exists('Notion_Algorithm_Optimizer') && class_exists('Notion_Text_Processor') && count($blocks) > 10) {
            $blocks = Notion_Text_Processor::optimized_batch_text_processing($blocks);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    sprintf('算法优化器预处理了 %d 个块（超过阈值10）', count($blocks)),
                    'Content Converter'
                );
            }
        } elseif (class_exists('Notion_Logger') && count($blocks) <= 10) {
            Notion_Logger::debug_log(
                sprintf('跳过算法优化器预处理：块数量 %d 未超过阈值10', count($blocks)),
                'Content Converter'
            );
        }

        // 使用数组收集HTML片段，最后一次性拼接
        $html_parts = [];
        $list_wrapper = null;
        $local_processed_blocks = [];

        // 预处理：批量识别和处理特殊块类型
        $database_blocks = [];
        $database_data = [];

        // 一次遍历识别所有特殊块
        foreach ($blocks as $block) {
            if (isset($block['type']) && $block['type'] === 'child_database') {
                $database_blocks[] = $block;
            }
        }

        // 批量处理子数据库块（修复：增强错误处理）
        if (!empty($database_blocks)) {
            try {
                $database_data = Notion_Database_Renderer::batch_process_child_databases($database_blocks, $notion_api);
                
                // 验证批量处理结果
                if (empty($database_data)) {
                    Notion_Logger::debug_log(
                        '优化模式：批量处理返回空数据，将回退到标准处理',
                        'Child Database Batch'
                    );
                } else {
                    Notion_Logger::debug_log(
                        sprintf('优化模式：批量处理成功，获取到 %d 个数据库的预处理数据', count($database_data)),
                        'Child Database Batch'
                    );
                }
            } catch (Exception $e) {
                Notion_Logger::error_log(
                    '优化模式：子数据库批量处理失败: ' . $e->getMessage(),
                    'Child Database Batch'
                );
                $database_data = []; // 确保是空数组，触发标准处理
            }
        }

        // 主要转换循环 - 优化版本
        foreach ($blocks as $block_index => $block) {
            if (in_array($block['id'], $local_processed_blocks)) {
                continue;
            }
            $local_processed_blocks[] = $block['id'];

            // 定期检查内存使用情况（使用自适应频率）
            $check_frequency = 50;
            if (class_exists('Notion_Adaptive_Batch')) {
                // 根据系统性能调整检查频率
                $stats = Notion_Adaptive_Batch::get_adaptive_stats();
                if ($stats['memory_usage_percent'] > 70) {
                    $check_frequency = 25; // 内存紧张时更频繁检查
                } elseif ($stats['memory_usage_percent'] < 30) {
                    $check_frequency = 100; // 内存充足时减少检查频率
                }
            }

            if ($block_index % $check_frequency === 0 && class_exists('Notion_Memory_Manager')) {
                if (Notion_Memory_Manager::is_memory_warning()) {
                    Notion_Memory_Manager::force_garbage_collection();
                }
            }

            $block_type = $block['type'];

            // 列表处理逻辑（保持原有逻辑）
            $is_standard_list_item = in_array($block_type, ['bulleted_list_item', 'numbered_list_item']);
            $is_todo_item = ($block_type === 'to_do');

            if ($is_standard_list_item || $is_todo_item) {
                $required_wrapper = $is_todo_item ? 'todo' : ($block_type === 'bulleted_list_item' ? 'ul' : 'ol');

                if ($list_wrapper !== $required_wrapper) {
                    if ($list_wrapper !== null) {
                        $html_parts[] = ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
                    }
                    $html_parts[] = ($required_wrapper === 'todo') ? '<ul class="notion-todo-list">' : '<' . $required_wrapper . '>';
                    $list_wrapper = $required_wrapper;
                }
            } elseif ($list_wrapper !== null) {
                $html_parts[] = ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
                $list_wrapper = null;
            }

            // 块转换 - 使用优化的方法调用
            $block_html = self::convert_single_block_optimized($block, $notion_api, $database_data);

            if (!empty($block_html)) {
                // 为块添加ID包装
                $block_html = self::wrap_block_with_id($block_html, $block['id'], $block_type);
                $html_parts[] = $block_html;
            }
        }

        // 关闭未关闭的列表
        if ($list_wrapper !== null) {
            $html_parts[] = ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
        }

        // 一次性拼接所有HTML片段
        $result = implode('', $html_parts);

        // 处理异步图片（如果启用了异步模式）
        if (class_exists('Notion_Image_Processor') && Notion_Image_Processor::is_async_image_mode_enabled()) {
            // 使用并行图片处理器处理收集到的图片
            $result = Notion_Image_Processor::process_async_images($result);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    '异步图片处理完成',
                    'Content Conversion'
                );
            }
        }

        // 结束性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::end_timer('content_conversion');
        }

        // 清理大型变量以释放内存
        unset($html_parts, $local_processed_blocks, $database_blocks, $database_data);

        // 强制垃圾回收
        if (class_exists('Notion_Memory_Manager')) {
            Notion_Memory_Manager::force_garbage_collection();
        }

        return $result;
    }

    /**
     * 优化版本的单个块转换
     *
     * @since 2.0.0-beta.1
     * @param array $block 块数据
     * @param Notion_API $notion_api API实例
     * @param array $database_data 预处理的数据库数据
     * @return string HTML内容
     */
    private static function convert_single_block_optimized(array $block, Notion_API $notion_api, array $database_data = []): string {
        $block_type = $block['type'];
        $converter_method = '_convert_block_' . $block_type;

        if (!method_exists(self::class, $converter_method)) {
            // 减少日志调用 - 只在调试级别记录
            if (defined('WP_DEBUG') && WP_DEBUG) {
                Notion_Logger::debug_log("未支持的块类型: {$block_type}", 'Block Conversion');
            }
            return '<!-- 未支持的块类型: ' . esc_html($block_type) . ' -->';
        }

        try {
            // 特殊处理子数据库块
            if ($block_type === 'child_database') {
                return self::_convert_block_child_database_with_data($block, $notion_api, $database_data);
            }

            // 调用相应的转换方法
            return self::{$converter_method}($block, $notion_api);

        } catch (Exception $e) {
            // 只记录错误级别的日志
            Notion_Logger::error_log(
                "块转换失败: {$block_type} - " . $e->getMessage(),
                'Block Conversion'
            );
            return '<!-- 块转换失败: ' . esc_html($block_type) . ' -->';
        }
    }

    /**
     * 清理Mermaid代码，移除HTML标签和修复特殊字符
     *
     * @param string $mermaid_code 原始Mermaid代码
     * @return string 清理后的Mermaid代码
     */
    private static function clean_mermaid_code($mermaid_code) {
        // 首先处理HTML标签，将<br/>等标签转换为换行符
        $cleaned = str_replace(['<br/>', '<br>', '<BR/>', '<BR>'], "\n", $mermaid_code);

        // 移除其他HTML标签
        $cleaned = strip_tags($cleaned);

        // 修复常见的字符转换问题
        $replacements = [
            // 修复箭头字符 - 添加更多HTML实体编码处理
            '–>' => '-->',  // em dash 转换为标准箭头
            '—>' => '-->',  // em dash 转换为标准箭头
            '&ndash;>' => '-->', // HTML实体转换
            '&mdash;>' => '-->', // HTML实体转换
            '--&gt;' => '-->', // HTML实体转换（最常见的问题）
            '-&gt;' => '->',   // 单箭头HTML实体转换
            '&gt;&gt;' => '>>', // 双大于号转换（sequence diagram）
            '&lt;&lt;' => '<<', // 双小于号转换
            '-&gt;&gt;' => '->>', // sequence diagram箭头
            '&lt;-' => '<-',   // 左箭头
            '&lt;--' => '<--', // 左双线箭头

            // 修复其他特殊字符
            '&lt;' => '<',
            '&gt;' => '>',
            '&amp;' => '&',
            '&quot;' => '"',
            '&#39;' => "'",

            // 标准化换行符
            "\r\n" => "\n",
            "\r" => "\n",
        ];

        foreach ($replacements as $search => $replace) {
            $cleaned = str_replace($search, $replace, $cleaned);
        }

        // 清理多余的空行，但保留必要的换行结构
        $lines = explode("\n", $cleaned);
        $cleaned_lines = [];

        foreach ($lines as $line) {
            $trimmed_line = trim($line);
            if (!empty($trimmed_line)) {
                $cleaned_lines[] = $trimmed_line;
            }
        }

        return implode("\n", $cleaned_lines);
    }

    /**
     * 轻量级块转换方法
     *
     * 专为小数据集优化的简化转换流程，减少不必要的性能开销
     *
     * @since 2.0.0-beta.1
     * @param array $blocks Notion 块数组
     * @param Notion_API $notion_api Notion API 实例
     * @param string $state_id 状态管理器ID
     * @return string HTML 内容
     */
    private static function convert_blocks_lightweight(array $blocks, Notion_API $notion_api, string $state_id = null): string {
        if (empty($blocks)) {
            return '';
        }

        // 使用轻量级流式处理器
        $processor = function($block_chunk) use ($notion_api, $state_id) {
            $html_parts = [];
            $list_wrapper = null;

            foreach ($block_chunk as $block) {
                $block_type = $block['type'];

                // 简化的列表处理
                $is_list_item = in_array($block_type, ['bulleted_list_item', 'numbered_list_item', 'to_do']);

                if ($is_list_item) {
                    $required_wrapper = ($block_type === 'to_do') ? 'todo' :
                                       ($block_type === 'bulleted_list_item' ? 'ul' : 'ol');

                    if ($list_wrapper !== $required_wrapper) {
                        if ($list_wrapper !== null) {
                            $html_parts[] = ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
                        }
                        $html_parts[] = ($required_wrapper === 'todo') ? '<ul class="notion-todo-list">' : '<' . $required_wrapper . '>';
                        $list_wrapper = $required_wrapper;
                    }
                } elseif ($list_wrapper !== null) {
                    $html_parts[] = ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
                    $list_wrapper = null;
                }

                // 简化的块转换（不使用复杂的优化）
                $block_html = self::convert_single_block_simple($block, $notion_api);

                if (!empty($block_html)) {
                    $html_parts[] = $block_html;
                }
            }

            // 关闭未关闭的列表
            if ($list_wrapper !== null) {
                $html_parts[] = ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
            }

            return $html_parts;
        };

        // 使用轻量级流式处理器处理数据
        $config = class_exists('Notion_Memory_Manager') ?
                  Notion_Memory_Manager::get_lightweight_config() :
                  ['chunk_size' => 50];

        $html_chunks = Notion_Stream_Processor::process_data_stream($blocks, $processor, $config['chunk_size']);

        // 合并结果
        $all_html_parts = [];
        foreach ($html_chunks as $chunk) {
            if (is_array($chunk)) {
                $all_html_parts = array_merge($all_html_parts, $chunk);
            }
        }

        $final_html = implode("\n", $all_html_parts);

        // 结束性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::end_timer('content_conversion');
        }

        // 记录轻量级处理统计
        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('轻量级内容转换完成: %d个块', count($blocks)),
                'Content Converter Lightweight'
            );
        }

        return $final_html;
    }

    /**
     * 简化的单块转换方法
     *
     * 为轻量级模式提供的简化块转换，减少复杂的优化逻辑
     *
     * @since 2.0.0-beta.1
     * @param array $block Notion 块数据
     * @param Notion_API $notion_api Notion API 实例
     * @return string 块的HTML内容
     */
    private static function convert_single_block_simple(array $block, Notion_API $notion_api): string {
        $block_type = $block['type'];
        $block_id = $block['id'];

        // 基本块类型处理（简化版本）
        switch ($block_type) {
            case 'paragraph':
                return self::convert_paragraph_simple($block);

            case 'heading_1':
            case 'heading_2':
            case 'heading_3':
                return self::convert_heading_simple($block);

            case 'bulleted_list_item':
            case 'numbered_list_item':
            case 'to_do':
                return self::convert_list_item_simple($block);

            case 'quote':
                return self::convert_quote_simple($block);

            case 'code':
                return self::convert_code_simple($block);

            case 'divider':
                return '<hr class="notion-divider">';

            default:
                // 对于复杂块类型，回退到原始方法
                return self::convert_single_block_optimized($block, $notion_api, []);
        }
    }

    /**
     * 简化的段落转换
     */
    private static function convert_paragraph_simple(array $block): string {
        $content = self::convert_rich_text_simple($block['paragraph']['rich_text'] ?? []);
        return empty($content) ? '' : "<p>{$content}</p>";
    }

    /**
     * 简化的标题转换
     */
    private static function convert_heading_simple(array $block): string {
        $level = substr($block['type'], -1); // 获取标题级别
        $content = self::convert_rich_text_simple($block[$block['type']]['rich_text'] ?? []);
        return empty($content) ? '' : "<h{$level}>{$content}</h{$level}>";
    }

    /**
     * 简化的列表项转换
     */
    private static function convert_list_item_simple(array $block): string {
        $block_type = $block['type'];
        $content = self::convert_rich_text_simple($block[$block_type]['rich_text'] ?? []);

        if ($block_type === 'to_do') {
            $checked = $block['to_do']['checked'] ?? false;
            $checkbox = $checked ? '☑' : '☐';
            return "<li class=\"notion-todo-item\">{$checkbox} {$content}</li>";
        }

        return "<li>{$content}</li>";
    }

    /**
     * 简化的引用转换
     */
    private static function convert_quote_simple(array $block): string {
        $content = self::convert_rich_text_simple($block['quote']['rich_text'] ?? []);
        return empty($content) ? '' : "<blockquote>{$content}</blockquote>";
    }

    /**
     * 简化的代码块转换
     */
    private static function convert_code_simple(array $block): string {
        $content = self::convert_rich_text_simple($block['code']['rich_text'] ?? []);
        $language = $block['code']['language'] ?? 'text';
        return "<pre><code class=\"language-{$language}\">" . htmlspecialchars($content) . "</code></pre>";
    }

    /**
     * 简化的富文本转换
     */
    private static function convert_rich_text_simple(array $rich_text): string {
        if (empty($rich_text)) {
            return '';
        }

        $text_parts = [];
        foreach ($rich_text as $text_obj) {
            $text = $text_obj['plain_text'] ?? '';

            // 简化的格式处理
            if (!empty($text_obj['annotations'])) {
                $annotations = $text_obj['annotations'];
                if ($annotations['bold'] ?? false) $text = "<strong>{$text}</strong>";
                if ($annotations['italic'] ?? false) $text = "<em>{$text}</em>";
                if ($annotations['code'] ?? false) $text = "<code>{$text}</code>";
            }

            $text_parts[] = $text;
        }

        return implode('', $text_parts);
    }

    /**
     * 批量获取子内容优化方法
     *
     * 减少API调用次数，提升性能
     *
     * @since 2.0.0-beta.1
     * @param array $blocks 块数组
     * @param Notion_API $notion_api API实例
     * @return array 子内容数据
     */
    private static function batch_get_children_content(array $blocks, Notion_API $notion_api): array {
        $children_content = [];
        $blocks_with_children = [];

        // 识别需要获取子内容的块
        foreach ($blocks as $block) {
            if (isset($block['has_children']) && $block['has_children']) {
                $blocks_with_children[] = $block['id'];
            }
        }

        if (empty($blocks_with_children)) {
            return $children_content;
        }

        // 批量获取子内容（如果API支持）
        $start_time = microtime(true);

        foreach ($blocks_with_children as $block_id) {
            try {
                $children = $notion_api->get_block_children($block_id);
                $children_content[$block_id] = $children;
            } catch (Exception $e) {
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::warning_log(
                        sprintf('获取子内容失败: %s - %s', $block_id, $e->getMessage()),
                        'Content Converter'
                    );
                }
                $children_content[$block_id] = [];
            }
        }

        // 记录批量获取性能
        if (class_exists('Notion_Performance_Monitor')) {
            $processing_time = microtime(true) - $start_time;
            Notion_Performance_Monitor::record_custom_metric('batch_children_fetch_time', $processing_time);
            Notion_Performance_Monitor::record_custom_metric('batch_children_count', count($blocks_with_children));
        }

        return $children_content;
    }

    /**
     * 优化的HTML生成方法
     *
     * 使用预构建的HTML模板和数组拼接
     *
     * @since 2.0.0-beta.1
     * @param array $blocks 块数组
     * @param Notion_API $notion_api API实例
     * @param string $state_id 状态ID
     * @return string HTML内容
     */
    public static function convert_blocks_to_html_ultra_optimized(array $blocks, Notion_API $notion_api, string $state_id = null): string {
        if (empty($blocks)) {
            return '';
        }

        // 开始性能监控
        $start_time = microtime(true);
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::start_timer('ultra_optimized_conversion');
        }

        // 批量预处理
        $children_content = self::batch_get_children_content($blocks, $notion_api);

        // 批量处理富文本（如果有多个富文本块）
        $rich_text_blocks = [];
        foreach ($blocks as $index => $block) {
            if (isset($block['paragraph']['rich_text']) && !empty($block['paragraph']['rich_text'])) {
                $rich_text_blocks[$index] = $block['paragraph']['rich_text'];
            }
        }

        $processed_rich_texts = [];
        if (!empty($rich_text_blocks) && class_exists('Notion_Text_Processor')) {
            $processed_rich_texts = Notion_Text_Processor::batch_extract_rich_text($rich_text_blocks);
        }

        // 使用数组收集HTML片段
        $html_parts = [];
        $list_wrapper = null;

        foreach ($blocks as $index => $block) {
            $block_type = $block['type'];

            // 列表处理逻辑
            $is_list_item = in_array($block_type, ['bulleted_list_item', 'numbered_list_item', 'to_do']);

            if ($is_list_item) {
                $required_wrapper = ($block_type === 'to_do') ? 'todo' :
                                   ($block_type === 'bulleted_list_item' ? 'ul' : 'ol');

                if ($list_wrapper !== $required_wrapper) {
                    if ($list_wrapper !== null) {
                        $html_parts[] = ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
                    }
                    $html_parts[] = ($required_wrapper === 'todo') ? '<ul class="notion-todo-list">' : '<' . $required_wrapper . '>';
                    $list_wrapper = $required_wrapper;
                }
            } elseif ($list_wrapper !== null) {
                $html_parts[] = ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
                $list_wrapper = null;
            }

            // 使用预处理的富文本（如果可用）
            if (isset($processed_rich_texts[$index])) {
                $block_html = self::convert_block_with_processed_text($block, $processed_rich_texts[$index], $children_content);
            } else {
                $block_html = self::convert_single_block_optimized($block, $notion_api, $children_content);
            }

            if (!empty($block_html)) {
                $html_parts[] = $block_html;
            }
        }

        // 关闭未关闭的列表
        if ($list_wrapper !== null) {
            $html_parts[] = ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
        }

        // 一次性拼接所有HTML
        $result = implode('', $html_parts);

        // 结束性能监控
        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::end_timer('ultra_optimized_conversion');
            $processing_time = microtime(true) - $start_time;
            Notion_Performance_Monitor::record_custom_metric('ultra_optimized_conversion_time', $processing_time);
            Notion_Performance_Monitor::record_custom_metric('ultra_optimized_blocks_count', count($blocks));
        }

        return $result;
    }

    /**
     * 使用预处理富文本转换块
     *
     * @since 2.0.0-beta.1
     * @param array $block 块数据
     * @param string $processed_rich_text 预处理的富文本HTML
     * @param array $children_content 子内容数据
     * @return string 块HTML
     */
    private static function convert_block_with_processed_text(array $block, string $processed_rich_text, array $children_content): string {
        $block_type = $block['type'];

        switch ($block_type) {
            case 'paragraph':
                return empty($processed_rich_text) ? '' : "<p>{$processed_rich_text}</p>";

            case 'heading_1':
            case 'heading_2':
            case 'heading_3':
                $level = substr($block_type, -1);
                return empty($processed_rich_text) ? '' : "<h{$level}>{$processed_rich_text}</h{$level}>";

            case 'bulleted_list_item':
            case 'numbered_list_item':
                return "<li>{$processed_rich_text}</li>";

            case 'to_do':
                $checked = $block['to_do']['checked'] ?? false;
                $checkbox = $checked ? '☑' : '☐';
                return "<li class=\"notion-todo-item\">{$checkbox} {$processed_rich_text}</li>";

            default:
                // 对于复杂块类型，回退到标准方法
                return self::convert_single_block_optimized($block, null, $children_content);
        }
    }

}
