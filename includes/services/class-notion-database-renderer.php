<?php
declare(strict_types=1);

/**
 * Notion 子数据库渲染器类
 *
 * 提供了将Notion子数据库渲染为表格、画廊、看板等多种视图的功能。
 *
 * 设计模式：静态工具类
 * - 所有方法均为静态方法，无状态管理
 * - 专注于数据库渲染，不涉及业务逻辑
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

class Notion_Database_Renderer {

    // 数据库视图类型常量
    const VIEW_TYPE_GALLERY = 'gallery';
    const VIEW_TYPE_TABLE = 'table';
    const VIEW_TYPE_BOARD = 'board';

    /**
     * 渲染子数据库
     *
     * @since 1.1.3
     * @param array $database_data 数据库数据
     * @return string HTML内容
     */
    public static function render_database(array $database_data): string {
        if (empty($database_data)) {
            return '';
        }

        $database_info = $database_data['database_info'] ?? [];
        $records = $database_data['records'] ?? [];

        // 正确提取数据库标题
        $title = '';
        if (isset($database_info['title']) && is_array($database_info['title'])) {
            foreach ($database_info['title'] as $title_part) {
                if (isset($title_part['plain_text'])) {
                    $title .= $title_part['plain_text'];
                }
            }
        }

        if (empty($records)) {
            return self::render_empty_database($title);
        }

        // 根据标题判断视图类型
        $view_type = self::detect_view_type($title);
        
        switch ($view_type) {
            case 'table':
                return self::render_table_view($records, $database_info);
            case 'gallery':
                return self::render_gallery_view($records, $database_info);
            case 'board':
                return self::render_board_view($records, $database_info);
            default:
                return self::render_table_view($records, $database_info); // 默认表格视图
        }
    }

    /**
     * 检测视图类型
     *
     * @since 1.1.3
     * @param string $title 数据库标题
     * @return string 视图类型：table|gallery|board
     */
    private static function detect_view_type(string $title): string {
        $title_lower = strtolower($title);
        
        if (strpos($title_lower, 'gallery') !== false || strpos($title_lower, '画廊') !== false) {
            return 'gallery';
        }
        
        if (strpos($title_lower, 'board') !== false || strpos($title_lower, '看板') !== false) {
            return 'board';
        }
        
        // 默认为表格视图
        return 'table';
    }

    /**
     * 渲染空数据库
     *
     * @since 1.1.3
     * @param string $title 数据库标题
     * @return string HTML内容
     */
    private static function render_empty_database(string $title): string {
        return sprintf(
            '<div class="notion-database-empty"><h4>%s</h4><p>%s</p></div>',
            esc_html($title ?: __('数据库', 'notion-to-wordpress')),
            esc_html__('暂无数据', 'notion-to-wordpress')
        );
    }    /**
     * 渲染表格视图 - 简化版
     *
     * @since 1.1.3
     * @param array $records 记录数组
     * @param array $database_info 数据库信息
     * @return string HTML内容
     */
    private static function render_table_view(array $records, array $database_info): string {
        // 正确提取数据库标题
        $title = '';
        if (isset($database_info['title']) && is_array($database_info['title'])) {
            foreach ($database_info['title'] as $title_part) {
                if (isset($title_part['plain_text'])) {
                    $title .= $title_part['plain_text'];
                }
            }
        }
        $title = $title ?: __('表格视图', 'notion-to-wordpress');
        $properties = $database_info['properties'] ?? [];
        
        $html = '<div class="notion-database notion-database-table">';
        $html .= '<h4 class="notion-database-title">' . esc_html($title) . '</h4>';
        
        // 使用真正的HTML table
        $html .= '<table class="notion-table">';
        $html .= self::render_table_header($properties);
        $html .= '<tbody>';
        
        foreach ($records as $record) {
            $html .= self::render_table_row($record, $properties);
        }
        
        $html .= '</tbody></table></div>';
        
        return $html;
    }

    /**
     * 渲染表格头部
     *
     * @since 1.1.3
     * @param array $properties 属性配置
     * @return string HTML内容
     */
    private static function render_table_header(array $properties): string {
        $html = '<thead><tr>';
        $html .= '<th class="notion-table-header-cell">' . __('标题', 'notion-to-wordpress') . '</th>';
        
        foreach ($properties as $prop_name => $prop_config) {
            $prop_type = $prop_config['type'] ?? '';
            if ($prop_type === 'title') continue; // 跳过标题类型
            
            $html .= '<th class="notion-table-header-cell">' . esc_html($prop_name) . '</th>';
        }
        
        $html .= '</tr></thead>';
        return $html;
    }    /**
     * 渲染表格行
     *
     * @since 1.1.3
     * @param array $record 记录数据
     * @param array $properties 属性配置
     * @return string HTML内容
     */
    private static function render_table_row(array $record, array $properties): string {
        $record_properties = $record['properties'] ?? [];
        
        $html = '<tr>';
        
        // 标题单元格
        $title = self::extract_title($record_properties);
        $icon = self::extract_icon($record);
        
        $html .= '<td class="notion-table-title-cell">';
        if ($icon) {
            $html .= '<span class="notion-icon">' . $icon . '</span>';
        }
        $html .= esc_html($title);
        $html .= '</td>';
        
        // 属性单元格
        foreach ($properties as $prop_name => $prop_config) {
            $prop_type = $prop_config['type'] ?? '';
            if ($prop_type === 'title') continue;
            
            $prop_value = $record_properties[$prop_name] ?? null;
            $formatted_value = self::format_property_value($prop_value, $prop_type);
            
            $html .= '<td class="notion-table-cell">' . $formatted_value . '</td>';
        }
        
        $html .= '</tr>';
        return $html;
    }    /**
     * 渲染画廊视图 - 简化版
     *
     * @since 1.1.3
     * @param array $records 记录数组
     * @param array $database_info 数据库信息
     * @return string HTML内容
     */
    private static function render_gallery_view(array $records, array $database_info): string {
        // 正确提取数据库标题
        $title = '';
        if (isset($database_info['title']) && is_array($database_info['title'])) {
            foreach ($database_info['title'] as $title_part) {
                if (isset($title_part['plain_text'])) {
                    $title .= $title_part['plain_text'];
                }
            }
        }
        $title = $title ?: __('画廊视图', 'notion-to-wordpress');
        
        $html = '<div class="notion-database notion-database-gallery">';
        $html .= '<h4 class="notion-database-title">' . esc_html($title) . '</h4>';
        $html .= '<div class="notion-gallery-grid">';
        
        foreach ($records as $record) {
            $html .= self::render_gallery_item($record);
        }
        
        $html .= '</div></div>';
        
        return $html;
    }

    /**
     * 渲染画廊项目
     *
     * @since 1.1.3
     * @param array $record 记录数据
     * @return string HTML内容
     */
    private static function render_gallery_item(array $record): string {
        $properties = $record['properties'] ?? [];
        $title = self::extract_title($properties);
        $icon = self::extract_icon($record);
        $cover = self::extract_cover($record);
        
        $html = '<div class="notion-gallery-item">';
        
        // 封面图片
        if ($cover) {
            $html .= '<div class="notion-gallery-cover">';
            $html .= '<img src="' . esc_url($cover) . '" alt="' . esc_attr($title) . '" loading="lazy">';
            $html .= '</div>';
        }
        
        // 内容区域
        $html .= '<div class="notion-gallery-content">';
        $html .= '<div class="notion-gallery-title">';
        if ($icon) {
            $html .= '<span class="notion-icon">' . $icon . '</span>';
        }
        $html .= esc_html($title);
        $html .= '</div>';
        
        // 显示主要属性
        $html .= self::render_gallery_properties($properties);
        
        $html .= '</div></div>';
        
        return $html;
    }    /**
     * 渲染看板视图 - 简化版
     *
     * @since 1.1.3
     * @param array $records 记录数组
     * @param array $database_info 数据库信息
     * @return string HTML内容
     */
    private static function render_board_view(array $records, array $database_info): string {
        // 正确提取数据库标题
        $title = '';
        if (isset($database_info['title']) && is_array($database_info['title'])) {
            foreach ($database_info['title'] as $title_part) {
                if (isset($title_part['plain_text'])) {
                    $title .= $title_part['plain_text'];
                }
            }
        }
        $title = $title ?: __('看板视图', 'notion-to-wordpress');
        
        // 按状态分组
        $grouped_records = self::group_records_by_status($records);
        
        $html = '<div class="notion-database notion-database-board">';
        $html .= '<h4 class="notion-database-title">' . esc_html($title) . '</h4>';
        $html .= '<div class="notion-board-columns">';
        
        foreach ($grouped_records as $status => $status_records) {
            $html .= self::render_board_column($status, $status_records);
        }
        
        $html .= '</div></div>';
        
        return $html;
    }

    /**
     * 渲染看板列
     *
     * @since 1.1.3
     * @param string $status 状态名称
     * @param array $records 该状态下的记录
     * @return string HTML内容
     */
    private static function render_board_column(string $status, array $records): string {
        $html = '<div class="notion-board-column">';
        $html .= '<div class="notion-board-header">';
        $html .= '<h5>' . esc_html($status) . '</h5>';
        $html .= '<span class="notion-board-count">' . count($records) . '</span>';
        $html .= '</div>';
        
        $html .= '<div class="notion-board-items">';
        foreach ($records as $record) {
            $html .= self::render_board_item($record);
        }
        $html .= '</div></div>';        
        
        return $html;
    }    /**
     * 渲染看板项目
     *
     * @since 1.1.3
     * @param array $record 记录数据
     * @return string HTML内容
     */
    private static function render_board_item(array $record): string {
        $properties = $record['properties'] ?? [];
        $title = self::extract_title($properties);
        $icon = self::extract_icon($record);
        $cover = self::extract_cover($record);
        
        $html = '<div class="notion-board-item">';
        
        // 封面图片（如果有）
        if ($cover) {
            $html .= '<div class="notion-board-cover">';
            $html .= '<img src="' . esc_url($cover) . '" alt="' . esc_attr($title) . '" loading="lazy">';
            $html .= '</div>';
        }
        
        // 标题
        $html .= '<div class="notion-board-title">';
        if ($icon) {
            $html .= '<span class="notion-icon">' . $icon . '</span>';
        }
        $html .= esc_html($title);
        $html .= '</div>';
        
        $html .= '</div>';
        
        return $html;
    }

    // ==================== 辅助方法 ====================

    /**
     * 提取记录标题
     *
     * @since 1.1.3
     * @param array $properties 记录属性
     * @return string 标题
     */
    private static function extract_title(array $properties): string {
        // 查找title类型的属性
        foreach ($properties as $prop_name => $prop_value) {
            if (is_array($prop_value) && isset($prop_value['type']) && $prop_value['type'] === 'title') {
                $title_array = $prop_value['title'] ?? [];
                if (!empty($title_array) && is_array($title_array)) {
                    return $title_array[0]['plain_text'] ?? '';
                }
            }
        }
        
        return __('无标题', 'notion-to-wordpress');
    }    /**
     * 提取记录图标
     *
     * @since 1.1.3
     * @param array $record 记录数据
     * @return string 图标HTML或空字符串
     */
    private static function extract_icon(array $record): string {
        $icon = $record['icon'] ?? null;
        
        if (!$icon) {
            return '';
        }
        
        if (isset($icon['emoji'])) {
            return $icon['emoji'];
        }
        
        if (isset($icon['external']['url'])) {
            return '<img src="' . esc_url($icon['external']['url']) . '" alt="icon" class="notion-icon-img">';
        }
        
        return '';
    }

    /**
     * 提取封面图片
     *
     * @since 1.1.3
     * @param array $record 记录数据
     * @return string 封面图片URL或空字符串
     */
    private static function extract_cover(array $record): string {
        $cover = $record['cover'] ?? null;
        
        if (!$cover) {
            return '';
        }
        
        if (isset($cover['external']['url'])) {
            return $cover['external']['url'];
        }
        
        if (isset($cover['file']['url'])) {
            return $cover['file']['url'];
        }
        
        return '';
    }    /**
     * 格式化属性值
     *
     * @since 1.1.3
     * @param mixed $prop_value 属性值
     * @param string $prop_type 属性类型
     * @return string 格式化后的HTML
     */
    private static function format_property_value($prop_value, string $prop_type): string {
        if (!$prop_value) {
            return '';
        }
        
        switch ($prop_type) {
            case 'select':
                return isset($prop_value['select']['name']) ?
                    '<span class="notion-select">' . esc_html($prop_value['select']['name']) . '</span>' : '';

            case 'status':
                return isset($prop_value['status']['name']) ?
                    '<span class="notion-status">' . esc_html($prop_value['status']['name']) . '</span>' : '';

            case 'multi_select':
                if (!isset($prop_value['multi_select']) || !is_array($prop_value['multi_select'])) {
                    return '';
                }
                $tags = array_map(function($item) {
                    return '<span class="notion-tag">' . esc_html($item['name'] ?? '') . '</span>';
                }, $prop_value['multi_select']);
                return implode(' ', $tags);
                
            case 'date':
                return isset($prop_value['date']['start']) ? 
                    esc_html($prop_value['date']['start']) : '';
                    
            case 'url':
                $url = $prop_value['url'] ?? '';
                if ($url) {
                    $display_url = strlen($url) > 20 ? substr($url, 0, 17) . '…' : $url;
                    return '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($display_url) . '</a>';
                }
                return '';
                
            case 'rich_text':
                if (!empty($prop_value['rich_text'])) {
                    return Notion_Text_Processor::extract_rich_text_complete($prop_value['rich_text']);
                }
                return '';
                
            default:
                return esc_html(is_string($prop_value) ? $prop_value : '');
        }
    }    /**
     * 渲染画廊属性
     *
     * @since 1.1.3
     * @param array $properties 记录属性
     * @return string HTML内容
     */
    private static function render_gallery_properties(array $properties): string {
        $html = '<div class="notion-gallery-properties">';
        $count = 0;
        $max_props = 2; // 画廊视图只显示2个主要属性
        
        foreach ($properties as $prop_name => $prop_value) {
            if ($count >= $max_props) break;
            if (is_array($prop_value) && isset($prop_value['type']) && $prop_value['type'] === 'title') {
                continue; // 跳过标题
            }
            
            $prop_type = is_array($prop_value) ? ($prop_value['type'] ?? '') : '';
            $formatted_value = self::format_property_value($prop_value, $prop_type);
            
            if ($formatted_value) {
                $html .= '<div class="notion-gallery-property">';
                $html .= '<span class="notion-property-name">' . esc_html($prop_name) . ':</span> ';
                $html .= $formatted_value;
                $html .= '</div>';
                $count++;
            }
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * 按状态分组记录
     *
     * @since 1.1.3
     * @param array $records 记录数组
     * @return array 分组后的记录
     */
    private static function group_records_by_status(array $records): array {
        $grouped = [];

        foreach ($records as $record) {
            $properties = $record['properties'] ?? [];
            $status = __('未分类', 'notion-to-wordpress');

            // 查找状态属性 - 修复逻辑错误
            foreach ($properties as $prop_name => $prop_value) {
                if (is_array($prop_value) && isset($prop_value['type'])) {
                    // 检查是否为状态类型或选择类型
                    if ($prop_value['type'] === 'status') {
                        $status = $prop_value['status']['name'] ?? $status;
                        break;
                    } elseif ($prop_value['type'] === 'select') {
                        // 检查属性名是否包含"状态"相关关键词
                        $prop_name_lower = strtolower($prop_name);
                        if (strpos($prop_name_lower, '状态') !== false ||
                            strpos($prop_name_lower, 'status') !== false ||
                            strpos($prop_name_lower, 'state') !== false) {
                            $status = $prop_value['select']['name'] ?? $status;
                            break;
                        }
                    }
                }
            }

            if (!isset($grouped[$status])) {
                $grouped[$status] = [];
            }
            $grouped[$status][] = $record;
        }

        return $grouped;
    }

    // ==================== 高级渲染方法 ====================

    /**
     * 渲染数据库预览记录（带API调用）
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $database_info 数据库信息
     * @param Notion_API $notion_api API实例
     * @return string HTML内容
     */
    public static function render_database_preview_records(string $database_id, array $database_info, Notion_API $notion_api): string {
        try {
            // 获取数据库中的记录
            // 使用with_details=true获取包含封面图片和图标的完整信息
            $records = $notion_api->get_database_pages($database_id, [], true);

            if (empty($records)) {
                Notion_Logger::debug_log(
                    '数据库无记录或无权限访问: ' . $database_id,
                    'Database Block'
                );
                return '<div class="notion-database-empty">' . __('暂无记录', 'notion-to-wordpress') . '</div>';
            }

            // 显示所有记录的预览
            Notion_Logger::debug_log(
                '获取数据库记录成功: ' . $database_id . ', 总记录: ' . count($records),
                'Database Block'
            );

            // 检测视图类型
            $view_type = self::detect_view_type_advanced($database_info);

            // 实现渐进式加载：先显示基本信息，后续加载详细内容
            $initial_load_count = min(6, count($records)); // 首次加载最多6条记录
            $initial_records = array_slice($records, 0, $initial_load_count);
            $remaining_records = array_slice($records, $initial_load_count);

            // 渲染初始内容
            $database_data = [
                'database_info' => $database_info,
                'records' => $initial_records
            ];
            $html = self::render_database($database_data);

            // 如果有剩余记录，添加懒加载容器
            if (!empty($remaining_records)) {
                $html .= self::render_progressive_loading_container($remaining_records, $database_info, $view_type, $database_id);
            }

            return $html;

        } catch (Exception $e) {
            Notion_Logger::error_log(
                '数据库记录预览异常: ' . $database_id . ', 错误: ' . $e->getMessage(),
                'Database Block'
            );
            return '<div class="notion-database-preview-error">记录预览暂时无法加载</div>';
        }
    }

    /**
     * 高级视图类型检测（支持rich text标题）
     *
     * @since 2.0.0-beta.1
     * @param array $database_info 数据库信息
     * @return string 视图类型
     */
    private static function detect_view_type_advanced(array $database_info): string {
        // 提取数据库标题
        $database_title = '';
        if (isset($database_info['title']) && is_array($database_info['title'])) {
            // title是rich text数组，提取plain_text
            foreach ($database_info['title'] as $title_part) {
                if (isset($title_part['plain_text'])) {
                    $database_title .= $title_part['plain_text'];
                }
            }
        }

        if (!empty($database_title)) {
            $title_lower = strtolower($database_title);

            Notion_Logger::debug_log(
                '数据库标题解析: "' . $database_title . '" -> "' . $title_lower . '"',
                'Database Title Parse'
            );

            // 只支持三种核心视图：画廊、表格、看板
            if (strpos($title_lower, '画廊') !== false || strpos($title_lower, 'gallery') !== false) {
                return self::VIEW_TYPE_GALLERY;
            } elseif (strpos($title_lower, '表格') !== false || strpos($title_lower, 'table') !== false) {
                return self::VIEW_TYPE_TABLE;
            } elseif (strpos($title_lower, '看板') !== false || strpos($title_lower, 'board') !== false) {
                return self::VIEW_TYPE_BOARD;
            }
        }

        // 默认使用表格视图
        return self::VIEW_TYPE_TABLE;
    }

    /**
     * 渐进式加载容器
     *
     * @since 2.0.0-beta.1
     * @param array $records 剩余记录
     * @param array $database_info 数据库信息
     * @param string $view_type 视图类型
     * @param string $database_id 数据库ID
     * @return string HTML内容
     */
    private static function render_progressive_loading_container(array $records, array $database_info, string $view_type, string $database_id): string {
        // 简化版：直接渲染剩余记录，不使用JavaScript懒加载
        $database_data = [
            'database_info' => $database_info,
            'records' => $records
        ];

        $html = '<div class="notion-database-more-records">';
        $html .= '<div class="notion-database-more-header">';
        $html .= '<span>更多记录 (' . count($records) . ')</span>';
        $html .= '</div>';
        $html .= self::render_database($database_data);
        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染数据库预览记录（使用预处理数据）
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $database_info 数据库信息
     * @param array $records 预处理的记录数据
     * @return string HTML内容
     */
    public static function render_database_preview_records_with_data(string $database_id, array $database_info, array $records): string {
        try {
            if (empty($records)) {
                Notion_Logger::debug_log(
                    '数据库无记录或无权限访问（批量模式）: ' . $database_id,
                    'Database Block'
                );
                return '<div class="notion-database-empty">' . __('暂无记录', 'notion-to-wordpress') . '</div>';
            }

            // 显示所有记录的预览
            Notion_Logger::debug_log(
                '获取数据库记录成功（批量模式）: ' . $database_id . ', 总记录: ' . count($records),
                'Database Block'
            );

            // 检测视图类型
            $view_type = self::detect_view_type_advanced($database_info);

            // 提取数据库标题用于日志
            $database_title = '';
            if (isset($database_info['title']) && is_array($database_info['title'])) {
                foreach ($database_info['title'] as $title_part) {
                    if (isset($title_part['plain_text'])) {
                        $database_title .= $title_part['plain_text'];
                    }
                }
            }

            Notion_Logger::debug_log(
                '选择视图类型（批量模式）: ' . $view_type . ' for database: ' . $database_id . ', 标题: ' . $database_title,
                'Database View'
            );

            // 实现渐进式加载：先显示基本信息，后续加载详细内容
            $initial_load_count = min(6, count($records)); // 首次加载最多6条记录
            $initial_records = array_slice($records, 0, $initial_load_count);
            $remaining_records = array_slice($records, $initial_load_count);

            // 渲染初始内容
            $database_data = [
                'database_info' => $database_info,
                'records' => $initial_records
            ];
            $html = self::render_database($database_data);

            // 如果有剩余记录，添加懒加载容器
            if (!empty($remaining_records)) {
                $html .= self::render_progressive_loading_container($remaining_records, $database_info, $view_type, $database_id);
            }

            return $html;

        } catch (Exception $e) {
            Notion_Logger::error_log(
                '数据库记录预览异常（批量模式）: ' . $database_id . ', 错误: ' . $e->getMessage(),
                'Database Block'
            );
            return '<div class="notion-database-preview-error">记录预览暂时无法加载</div>';
        }
    }

    // ==================== 批量处理方法 ====================

    /**
     * 批量处理子数据库
     *
     * @since 2.0.0-beta.1
     * @param array $database_blocks 数据库块数组
     * @param Notion_API $notion_api API实例
     * @return array 处理后的数据库数据
     */
    public static function batch_process_child_databases(array $database_blocks, Notion_API $notion_api): array {
        if (empty($database_blocks)) {
            return [];
        }

        $start_time = microtime(true);
        $database_ids = [];

        // 提取所有数据库ID
        foreach ($database_blocks as $block) {
            $database_ids[] = $block['id'];
        }

        // 去重数据库ID
        $unique_database_ids = array_unique($database_ids);

        Notion_Logger::debug_log(
            sprintf(
                '开始批量处理子数据库: %d个块，%d个唯一数据库ID',
                count($database_blocks),
                count($unique_database_ids)
            ),
            'Batch Database'
        );

        $database_data = [];

        try {
            // 批量获取数据库信息
            $db_info_start = microtime(true);
            $database_infos = $notion_api->batch_get_databases($unique_database_ids);
            $db_info_time = microtime(true) - $db_info_start;

            Notion_Logger::debug_log(
                sprintf('批量获取数据库信息耗时: %.4f秒', $db_info_time),
                'Batch Database Performance'
            );

            // 批量获取数据库记录
            $database_records = [];
            $valid_database_ids = [];

            foreach ($unique_database_ids as $database_id) {
                if (isset($database_infos[$database_id]) && !($database_infos[$database_id] instanceof Exception)) {
                    $valid_database_ids[] = $database_id;
                }
            }

            if (!empty($valid_database_ids)) {
                // 为每个数据库准备查询参数（空数组表示获取所有记录）
                $query_filters = array_fill(0, count($valid_database_ids), []);

                // 批量查询数据库记录
                $db_records_start = microtime(true);
                $database_records = $notion_api->batch_query_databases($valid_database_ids, $query_filters);
                $db_records_time = microtime(true) - $db_records_start;

                Notion_Logger::debug_log(
                    sprintf('批量查询数据库记录耗时: %.4f秒', $db_records_time),
                    'Batch Database Performance'
                );
            }

            // 组织数据 - 为所有原始数据库ID创建数据映射
            foreach ($database_ids as $database_id) {
                $database_data[$database_id] = [
                    'info' => $database_infos[$database_id] ?? null,
                    'records' => $database_records[$database_id] ?? []
                ];
            }

            $execution_time = microtime(true) - $start_time;

            Notion_Logger::debug_log(
                sprintf(
                    '批量数据库处理完成: %d个块，%d个唯一数据库，总耗时 %.4f秒',
                    count($database_blocks),
                    count($unique_database_ids),
                    $execution_time
                ),
                'Batch Database'
            );

            // 详细性能统计
            $api_time = ($db_info_time ?? 0) + ($db_records_time ?? 0);
            $processing_time = $execution_time - $api_time;

            Notion_Logger::debug_log(
                sprintf(
                    '性能分解: API调用 %.4f秒, 数据处理 %.4f秒',
                    $api_time,
                    $processing_time
                ),
                'Batch Database Performance'
            );

        } catch (Exception $e) {
            Notion_Logger::error_log(
                '批量数据库处理异常: ' . $e->getMessage(),
                'Batch Database'
            );

            // 返回空数据结构
            foreach ($database_ids as $database_id) {
                $database_data[$database_id] = [
                    'info' => null,
                    'records' => []
                ];
            }
        }

        return $database_data;
    }

    // ==================== 增强的辅助方法 ====================

    /**
     * 提取关键属性（用于画廊和看板视图）
     *
     * @since 2.0.0-beta.1
     * @param array $properties 记录属性
     * @param array $database_info 数据库信息
     * @param int $max_count 最大属性数量，默认3个
     * @return array 关键属性数组
     */
    private static function extract_key_properties(array $properties, array $database_info, int $max_count = 3): array {
        $key_props = [];
        $db_properties = $database_info['properties'] ?? [];

        // 优先显示的属性类型
        $priority_types = ['select', 'status', 'date', 'number', 'checkbox', 'files', 'url', 'email', 'phone_number', 'multi_select', 'people'];

        foreach ($priority_types as $type) {
            foreach ($db_properties as $prop_name => $prop_config) {
                if (($prop_config['type'] ?? '') === $type && isset($properties[$prop_name])) {
                    $value = self::format_property_for_preview($properties[$prop_name], $type);
                    if (!empty($value) && count($key_props) < $max_count) {
                        $key_props[$prop_name] = $value;
                    }
                }
            }
        }

        return $key_props;
    }

    /**
     * 格式化属性值用于预览显示（增强版）
     *
     * @since 2.0.0-beta.1
     * @param array $property 属性数据
     * @param string $type 属性类型
     * @return string 格式化后的值
     */
    private static function format_property_for_preview(array $property, string $type): string {
        switch ($type) {
            case 'select':
            case 'status':
                return $property[$type]['name'] ?? '';

            case 'date':
                $date_value = $property['date']['start'] ?? '';
                if (!empty($date_value)) {
                    return date('Y-m-d', strtotime($date_value));
                }
                return '';

            case 'number':
                return (string) ($property['number'] ?? '');

            case 'checkbox':
                return $property['checkbox'] ? '是' : '否';

            case 'rich_text':
                if (!empty($property['rich_text'])) {
                    $text = Notion_Text_Processor::extract_rich_text_complete($property['rich_text']);
                    // 移除HTML标签以获取纯文本长度
                    $plain_text = strip_tags($text);
                    return mb_strlen($plain_text) > 50 ? mb_substr($plain_text, 0, 50) . '...' : $text;
                }
                return '';

            case 'files':
                return self::render_record_files($property);

            case 'url':
                $url = $property['url'] ?? '';
                if (!empty($url)) {
                    $display_url = mb_strlen($url) > 50 ? mb_substr($url, 0, 47) . '...' : $url;
                    return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr($url) . '">' . esc_html($display_url) . '</a>';
                }
                return '';

            case 'email':
                $email = $property['email'] ?? '';
                if (!empty($email)) {
                    return '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
                }
                return '';

            case 'phone_number':
                $phone = $property['phone_number'] ?? '';
                if (!empty($phone)) {
                    return '<a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>';
                }
                return '';

            case 'multi_select':
                if (!empty($property['multi_select'])) {
                    $options = array_map(function($option) {
                        return $option['name'] ?? '';
                    }, $property['multi_select']);
                    return implode(', ', array_filter($options));
                }
                return '';

            case 'people':
                if (!empty($property['people'])) {
                    $names = array_map(function($person) {
                        return $person['name'] ?? '';
                    }, $property['people']);
                    return implode(', ', array_filter($names));
                }
                return '';

            default:
                return '';
        }
    }



    /**
     * 渲染记录文件
     *
     * @since 2.0.0-beta.1
     * @param array $property 文件属性
     * @return string HTML内容
     */
    private static function render_record_files(array $property): string {
        if (empty($property['files'])) {
            return '';
        }

        $files = $property['files'];
        if (count($files) === 1) {
            $file = $files[0];
            $name = $file['name'] ?? '文件';
            return esc_html($name);
        } else {
            return count($files) . ' 个文件';
        }
    }

    /**
     * 渲染数据库记录的封面图片
     *
     * @since 2.0.0-beta.1
     * @param array $record 记录数据
     * @return string HTML内容
     */
    private static function render_record_cover(array $record): string {
        $cover = $record['cover'] ?? null;
        if (empty($cover)) {
            return '';
        }

        $cover_url = '';
        if (isset($cover['external']['url'])) {
            $cover_url = $cover['external']['url'];
        } elseif (isset($cover['file']['url'])) {
            $cover_url = $cover['file']['url'];
        }

        if (!empty($cover_url)) {
            return '<div class="notion-record-cover"><img src="' . esc_url($cover_url) . '" alt="封面" loading="lazy"></div>';
        }

        return '';
    }

    /**
     * 渲染记录图标
     *
     * @since 2.0.0-beta.1
     * @param array $record 记录数据
     * @return string HTML内容
     */
    private static function render_record_icon(array $record): string {
        $icon = $record['icon'] ?? null;
        if (empty($icon)) {
            return '';
        }

        if (isset($icon['emoji'])) {
            return '<span class="notion-icon">' . $icon['emoji'] . '</span>';
        }

        if (isset($icon['external']['url'])) {
            return '<img src="' . esc_url($icon['external']['url']) . '" alt="图标" class="notion-icon-img">';
        }

        return '';
    }

    // ==================== 数据库属性处理方法 ====================

    /**
     * 渲染数据库属性信息
     *
     * @since 2.0.0-beta.1
     * @param array $database_info 数据库信息数组
     * @return string HTML内容
     */
    public static function render_database_properties(array $database_info): string {
        $html = '';
        $database_id = $database_info['id'] ?? 'unknown';

        Notion_Logger::debug_log(
            '开始渲染数据库属性: ' . $database_id,
            'Database Block'
        );

        if (isset($database_info['properties']) && is_array($database_info['properties'])) {
            $properties = $database_info['properties'];
            $property_count = count($properties);

            if ($property_count > 0) {
                Notion_Logger::debug_log(
                    '开始处理数据库属性: ' . $database_id . ', 属性数量: ' . $property_count,
                    'Database Block'
                );

                $property_info = self::format_database_properties($properties);

                if (!empty($property_info)) {
                    $html .= '<div class="notion-database-properties">';
                    $html .= '<strong>属性：</strong>' . $property_info;
                    $html .= '</div>';
                }
            } else {
                Notion_Logger::debug_log(
                    '数据库属性为空: ' . $database_id,
                    'Database Block'
                );
            }
        } else {
            Notion_Logger::debug_log(
                '数据库信息中没有属性字段: ' . $database_id,
                'Database Block'
            );
        }

        return $html;
    }

    /**
     * 格式化数据库属性信息
     *
     * @since 2.0.0-beta.1
     * @param array $properties 属性数组
     * @return string 格式化的属性信息
     */
    private static function format_database_properties(array $properties): string {
        if (empty($properties)) {
            Notion_Logger::debug_log(
                '属性数组为空，跳过格式化',
                'Database Block'
            );
            return '';
        }

        $property_names = [];
        $property_types = [];

        foreach ($properties as $name => $config) {
            $type = $config['type'] ?? 'unknown';
            $property_names[] = $name;

            if (!isset($property_types[$type])) {
                $property_types[$type] = 0;
            }
            $property_types[$type]++;
        }

        $result = implode('、', array_slice($property_names, 0, 5));

        if (count($property_names) > 5) {
            $result .= '等' . count($property_names) . '个属性';
        }

        // 添加属性类型统计（如果有多种类型）
        if (count($property_types) > 1) {
            $type_info = [];
            foreach ($property_types as $type => $count) {
                $type_name = self::get_property_type_name($type);
                if ($count > 1) {
                    $type_info[] = $type_name . '(' . $count . ')';
                } else {
                    $type_info[] = $type_name;
                }
            }
            $result .= '（' . implode('、', $type_info) . '）';
        }

        return $result;
    }

    /**
     * 获取属性类型的中文名称
     *
     * @since 2.0.0-beta.1
     * @param string $type 属性类型
     * @return string 中文名称
     */
    private static function get_property_type_name(string $type): string {
        $type_names = [
            'title' => '标题',
            'rich_text' => '文本',
            'number' => '数字',
            'select' => '选择',
            'multi_select' => '多选',
            'date' => '日期',
            'checkbox' => '复选框',
            'url' => '链接',
            'email' => '邮箱',
            'phone_number' => '电话',
            'files' => '文件',
            'people' => '人员',
            'status' => '状态'
        ];

        return $type_names[$type] ?? $type;
    }

    /**
     * 增强的记录标题提取（支持rich text）
     *
     * @since 2.0.0-beta.1
     * @param array $properties 记录属性
     * @return string 标题
     */
    public static function extract_record_title(array $properties): string {
        // 查找title类型的属性
        foreach ($properties as $name => $property) {
            if (isset($property['type']) && $property['type'] === 'title') {
                if (!empty($property['title'])) {
                    return Notion_Text_Processor::extract_rich_text_complete($property['title']);
                }
            }
        }

        // 如果没有找到title类型，返回默认值
        return __('无标题', 'notion-to-wordpress');
    }

    /**
     * 查找状态属性
     *
     * @since 2.0.0-beta.1
     * @param array $properties 数据库属性
     * @return string|null 状态属性名称
     */
    public static function find_status_property(array $properties): ?string {
        // 优先查找status类型
        foreach ($properties as $prop_name => $prop_config) {
            if (($prop_config['type'] ?? '') === 'status') {
                return $prop_name;
            }
        }

        // 如果没有status类型，查找select类型且名称包含"状态"的属性
        foreach ($properties as $prop_name => $prop_config) {
            if (($prop_config['type'] ?? '') === 'select') {
                $name_lower = strtolower($prop_name);
                if (strpos($name_lower, '状态') !== false ||
                    strpos($name_lower, 'status') !== false ||
                    strpos($name_lower, 'state') !== false) {
                    return $prop_name;
                }
            }
        }

        return null;
    }

    /**
     * 渲染子数据库（标准版本）
     *
     * 为_convert_block_child_database方法提供标准的子数据库渲染功能。
     * 获取数据库信息和记录，然后调用现有的渲染逻辑。
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param string $database_title 数据库标题
     * @param Notion_API $notion_api API实例
     * @return string HTML内容
     */
    public static function render_child_database(string $database_id, string $database_title, Notion_API $notion_api): string {
        try {
            Notion_Logger::debug_log(
                "开始渲染子数据库: {$database_title} (ID: {$database_id})",
                'Child Database'
            );

            // 获取数据库信息
            $database_info = $notion_api->get_database($database_id);
            if (empty($database_info)) {
                Notion_Logger::warning_log(
                    "无法获取数据库信息: {$database_title} (ID: {$database_id})",
                    'Child Database'
                );
                return '<div class="notion-database-empty">' . sprintf(__('数据库 "%s" 信息获取失败', 'notion-to-wordpress'), esc_html($database_title)) . '</div>';
            }

            // 获取数据库记录（使用with_details=true获取完整信息）
            $records = $notion_api->get_database_pages($database_id, [], true);
            if (empty($records)) {
                Notion_Logger::debug_log(
                    "数据库无记录或无权限访问: {$database_title} (ID: {$database_id})",
                    'Child Database'
                );
                return '<div class="notion-database-empty">' . sprintf(__('数据库 "%s" 暂无记录', 'notion-to-wordpress'), esc_html($database_title)) . '</div>';
            }

            // 调用现有的渲染逻辑
            $rendered_content = self::render_database_preview_records_with_data($database_id, $database_info, $records);

            if (!empty($rendered_content)) {
                Notion_Logger::info_log(
                    "子数据库渲染成功: {$database_title} (ID: {$database_id}), 记录数: " . count($records),
                    'Child Database'
                );
                return $rendered_content;
            } else {
                Notion_Logger::warning_log(
                    "子数据库渲染结果为空: {$database_title} (ID: {$database_id})",
                    'Child Database'
                );
                return '<div class="notion-database-empty">' . sprintf(__('数据库 "%s" 渲染失败', 'notion-to-wordpress'), esc_html($database_title)) . '</div>';
            }

        } catch (Exception $e) {
            Notion_Logger::error_log(
                "子数据库渲染异常: {$database_title} (ID: {$database_id}) - " . $e->getMessage(),
                'Child Database'
            );
            Notion_Logger::error_log(
                "异常堆栈: " . $e->getTraceAsString(),
                'Child Database'
            );
            return '<div class="notion-database-error">' . sprintf(__('数据库 "%s" 加载失败: %s', 'notion-to-wordpress'), esc_html($database_title), esc_html($e->getMessage())) . '</div>';
        }
    }
}