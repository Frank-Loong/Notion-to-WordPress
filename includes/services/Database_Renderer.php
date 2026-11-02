<?php
declare(strict_types=1);

namespace NTWP\Services;

use Exception;

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
 * @version    2.0.0-beta.2
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

class Database_Renderer {

    /** Share view-aware reads between the legacy rendering entry points. */
    private static function read_view_records(string $database_id, array &$database_info, \NTWP\Services\API $api): array {
        try {
            $snapshot = $api->get_database_view_snapshot($database_id);
            if (!empty($snapshot)) {
                $database_info['notion_view'] = $snapshot['view'];
                return $snapshot['records'];
            }
        } catch (Exception $error) {
            $options = get_option('notion_to_wordpress_options', []);
            $key = strtolower(str_replace('-', '', $database_id));
            if (!empty($options['embedded_database_view_ids'][$key])) {
                throw $error;
            }
            \NTWP\Core\Logger::warning_log('视图读取不可用，使用兼容数据库布局: ' . $error->getMessage(), 'Database View');
        }
        return $api->get_database_pages($database_id, [], false);
    }

    // 数据库视图类型常量
    const VIEW_TYPE_GALLERY = 'gallery';
    const VIEW_TYPE_TABLE = 'table';
    const VIEW_TYPE_BOARD = 'board';

    /**
     * 🚀 批量预处理多个子数据库（优化版本）
     *
     * @param array $child_databases 子数据库数组
     * @param \NTWP\Services\API $notion_api API实例
     * @return array 预处理数据
     */
    public static function batch_preprocess_child_databases_optimized(array $child_databases, \NTWP\Services\API $notion_api): array {
        if (empty($child_databases)) {
            return [];
        }

        \NTWP\Core\Logger::debug_log(
            sprintf('开始批量预处理 %d 个子数据库', count($child_databases)),
            'Database Batch Preprocess'
        );

        $preprocessed_data = [];
        $start_time = microtime(true);

        try {
            // 提取所有数据库ID
            $database_ids = array_map(function($db) {
                return $db['id'];
            }, $child_databases);

            // 🚀 使用批量API调用获取数据库信息
            $batch_info = $notion_api->batch_get_databases($database_ids);

            // 🚀 智能批量查询数据库内容（限制每个数据库的记录数）
            foreach ($database_ids as $db_id) {
                try {
                    // 限制每个数据库最多获取20条记录，避免超时
                    $info = $batch_info[$db_id] ?? [];
                    $records = self::read_view_records($db_id, $info, $notion_api);
                    $limited_records = $records;

                    $preprocessed_data[$db_id] = [
                        'info' => $info,
                        'records' => $limited_records,
                        'processed_at' => time(),
                        'record_count' => count($limited_records),
                        'total_available' => count($records)
                    ];

                } catch (Exception $e) {
                    \NTWP\Core\Logger::warning_log(
                        "预处理数据库 {$db_id} 失败: " . $e->getMessage(),
                        'Database Preprocess Error'
                    );

                    $preprocessed_data[$db_id] = [
                        'info' => [],
                        'records' => [],
                        'processed_at' => time(),
                        'error' => $e->getMessage()
                    ];
                }
            }

            $processing_time = microtime(true) - $start_time;
            \NTWP\Core\Logger::debug_log(
                sprintf('批量预处理完成，耗时 %.2f 秒，成功处理 %d/%d 个数据库',
                    $processing_time,
                    count($preprocessed_data),
                    count($child_databases)
                ),
                'Database Batch Success'
            );

        } catch (Exception $e) {
            \NTWP\Core\Logger::error_log(
                '批量预处理失败: ' . $e->getMessage(),
                'Database Batch Error'
            );
        }

        return $preprocessed_data;
    }

    /**
     * 🚀 从预处理数据渲染数据库
     *
     * @param string $database_id 数据库ID
     * @param string $database_title 数据库标题
     * @param array $preprocessed_data 预处理数据
     * @return string HTML内容
     */
    public static function render_from_preprocessed_data(string $database_id, string $database_title, array $preprocessed_data): string {
        if (isset($preprocessed_data['error'])) {
            return '<div class="notion-child-database-error" style="border: 1px solid #e74c3c; padding: 10px; margin: 10px 0; background: #fdf2f2;">
                <strong>📊 数据库: ' . esc_html($database_title) . '</strong><br>
                <small style="color: #e74c3c;">预处理失败: ' . esc_html($preprocessed_data['error']) . '</small>
            </div>';
        }

        $database_info = $preprocessed_data['info'] ?? [];
        $database_info['id'] = $database_id;
        $records = $preprocessed_data['records'] ?? [];
        $record_count = $preprocessed_data['record_count'] ?? 0;
        $total_available = $preprocessed_data['total_available'] ?? 0;

        if (empty($records)) {
            return '<div class="notion-child-database-empty" style="border: 1px dashed #ccc; padding: 10px; margin: 10px 0; background: #f9f9f9;">
                <strong>📊 数据库: ' . esc_html($database_title) . '</strong><br>
                <small style="color: #666;">暂无数据</small>
            </div>';
        }

        // 构建数据库数据结构
        $database_data = [
            'database_info' => $database_info,
            'records' => $records
        ];

        // 使用标准渲染方法
        $html = self::render_database($database_data);

        // 添加记录数量信息
        if ($total_available > $record_count) {
            $html .= '<div class="notion-database-info" style="font-size: 12px; color: #666; margin-top: 5px;">
                显示 ' . $record_count . ' / ' . $total_available . ' 条记录（为提升性能已限制显示数量）
            </div>';
        }

        return $html;
    }

    /**
     * 🚀 带超时保护的子数据库渲染
     *
     * @param string $database_id 数据库ID
     * @param string $database_title 数据库标题
     * @param \NTWP\Services\API $notion_api API实例
     * @param int $timeout_seconds 超时秒数
     * @return string HTML内容
     */
    public static function render_child_database_with_timeout(string $database_id, string $database_title, \NTWP\Services\API $notion_api, int $timeout_seconds = 10): string {
        $start_time = microtime(true);

        try {
            // 设置超时处理
            set_time_limit($timeout_seconds + 5);

            // 获取数据库信息
            $database_info = $notion_api->get_database($database_id);
            $database_info['id'] = $database_id;

            // 检查超时
            if ((microtime(true) - $start_time) > $timeout_seconds) {
                throw new Exception('获取数据库信息超时');
            }

            // 获取数据库记录（限制数量）
            $records = self::read_view_records($database_id, $database_info, $notion_api);
            $limited_records = $records;

            // 检查超时
            if ((microtime(true) - $start_time) > $timeout_seconds) {
                throw new Exception('获取数据库记录超时');
            }

            $database_data = [
                'database_info' => $database_info,
                'records' => $limited_records
            ];

            $html = self::render_database($database_data);

            $processing_time = microtime(true) - $start_time;
            \NTWP\Core\Logger::debug_log(
                sprintf('子数据库渲染完成: %s，耗时 %.2f 秒', $database_title, $processing_time),
                'Child Database Render'
            );

            return $html;

        } catch (Exception $e) {
            $processing_time = microtime(true) - $start_time;
            \NTWP\Core\Logger::warning_log(
                sprintf('子数据库渲染超时: %s，耗时 %.2f 秒 - %s', $database_title, $processing_time, $e->getMessage()),
                'Child Database Timeout'
            );

            return '<div class="notion-child-database-timeout" style="border: 1px solid #f39c12; padding: 10px; margin: 10px 0; background: #fef9e7;">
                <strong>📊 数据库: ' . esc_html($database_title) . '</strong><br>
                <small style="color: #f39c12;">渲染超时，请稍后重试</small>
            </div>';
        }
    }

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

        // Explicit layout wins; legacy title detection remains compatible.
        $options = get_option('notion_to_wordpress_options', []);
        $database_id = strtolower(str_replace('-', '', $database_info['id'] ?? ''));
        $configured_view = $options['embedded_database_views'][$database_id] ?? ($options['embedded_database_view'] ?? 'auto');
        $source_view = $database_info['notion_view'] ?? [];
        $database_info['_all_properties'] = $database_info['properties'] ?? [];
        $view_type = $database_data['view_type'] ?? $database_info['view_type'] ?? $configured_view;
        if ($view_type === 'auto' && isset($source_view['type'])) {
            $view_type = $source_view['type'];
        }
        // Preserve the view's visible column order using Notion property IDs.
        if (isset($source_view['configuration']['properties'])) {
            $ordered = [];
            foreach ($source_view['configuration']['properties'] as $column) {
                if (empty($column['visible'])) continue;
                foreach (($database_info['properties'] ?? []) as $name => $property) {
                    if (($property['id'] ?? '') === ($column['property_id'] ?? '')) {
                        $ordered[$name] = $property;
                    }
                }
            }
            $database_info['properties'] = $ordered;
        }
        if (!in_array($view_type, ['table', 'gallery', 'list', 'board'], true)) {
            $view_type = self::detect_view_type($title);
        }
        
        switch ($view_type) {
            case 'table':
                return self::render_table_view($records, $database_info);
            case 'gallery':
                return self::render_gallery_view($records, $database_info);
            case 'board':
                return self::render_board_view($records, $database_info);
            case 'list':
                return self::render_list_view($records, $title);
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
        if (strpos($title_lower, 'list') !== false || strpos($title_lower, '列表') !== false) {
            return 'list';
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
        // 延迟翻译：确保在WordPress初始化完成后调用翻译函数
        $default_title = function_exists('__') ? __('数据库', 'notion-to-wordpress') : '数据库';
        $empty_text = function_exists('__') ? __('暂无数据', 'notion-to-wordpress') : '暂无数据';

        return sprintf(
            '<div class="notion-database-empty"><h4>%s</h4><p>%s</p></div>',
            esc_html($title ?: $default_title),
            esc_html($empty_text)
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
        $title = $title ?: (function_exists('__') ? __('表格视图', 'notion-to-wordpress') : '表格视图');
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
        $html .= '<th class="notion-table-header-cell">' . (function_exists('__') ? __('标题', 'notion-to-wordpress') : '标题') . '</th>';
        
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
        $html .= self::render_record_link($record, $title);
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
            $visible = isset($database_info['notion_view']['configuration']['properties']) ? array_keys($database_info['properties']) : null;
            $html .= self::render_gallery_item($record, $visible);
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
    private static function render_gallery_item(array $record, ?array $visible = null): string {
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
        $html .= self::render_record_link($record, $title);
        $html .= '</div>';
        
        // 显示主要属性
        $html .= self::render_gallery_properties($visible === null ? $properties : array_intersect_key($properties, array_flip($visible)), $visible === null ? 2 : PHP_INT_MAX);
        
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
        $grouped_records = self::group_records_by_status($records, $database_info);
        
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
        $html .= self::render_record_link($record, $title);
        $html .= '</div>';
        
        $html .= '</div>';
        
        return $html;
    }

    // ==================== 辅助方法 ====================

    private static function render_list_view(array $records, string $title): string {
        $html = '<div class="notion-database notion-database-list"><h4 class="notion-database-title">' . esc_html($title) . '</h4><ul class="notion-database-list-items">';
        foreach ($records as $record) {
            $html .= '<li>' . self::extract_icon($record) . self::render_record_link($record, self::extract_title($record['properties'] ?? [])) . '</li>';
        }
        return $html . '</ul></div>';
    }

    private static function render_record_link(array $record, string $title): string {
        $notion_id = $record['id'] ?? '';
        if ($notion_id !== '' && function_exists('get_permalink')) {
            $normalized = strtolower(str_replace('-', '', $notion_id));
            if (preg_match('/^[a-f0-9]{32}$/', $normalized)) {
                $hyphenated = substr($normalized, 0, 8) . '-' . substr($normalized, 8, 4) . '-' . substr($normalized, 12, 4) . '-' . substr($normalized, 16, 4) . '-' . substr($normalized, 20);
                foreach (array_unique([$notion_id, $normalized, $hyphenated]) as $candidate) {
                    $post_id = \NTWP\Handlers\Integrator::get_post_by_notion_id($candidate);
                    $post = $post_id > 0 ? get_post($post_id) : null;
                    if ($post && $post->post_status === 'publish') {
                        $permalink = get_permalink($post_id);
                        if ($permalink) {
                            return '<a href="' . esc_url($permalink) . '">' . esc_html($title) . '</a>';
                        }
                    }
                }
            }
        }
        $url = esc_url($record['url'] ?? '');
        if ($url === '') {
            return esc_html($title);
        }
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html($title) . '</a>';
    }

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
                    return implode('', array_map(static function (array $part): string {
                        return $part['plain_text'] ?? ($part['text']['content'] ?? '');
                    }, $title_array));
                }
            }
        }
        
        return function_exists('__') ? __('无标题', 'notion-to-wordpress') : '无标题';
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
            return esc_html($icon['emoji']);
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
            return Attachment_Processor::resolve_url($cover['external']['url'], '', '', 'image');
        }
        
        if (isset($cover['file']['url'])) {
            return Attachment_Processor::resolve_url($cover['file']['url'], '', '', 'image');
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
                    return \NTWP\Core\Text_Processor::extract_rich_text_complete($prop_value['rich_text']);
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
    private static function render_gallery_properties(array $properties, int $max_props = 2): string {
        $html = '<div class="notion-gallery-properties">';
        $count = 0;
        
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
    private static function group_records_by_status(array $records, array $database_info = []): array {
        $grouped = [];
        $config = $database_info['notion_view']['configuration']['group_by'] ?? [];
        $group_property = '';
        $schema = [];
        foreach (($database_info['_all_properties'] ?? $database_info['properties'] ?? []) as $name => $property) {
            if (($property['id'] ?? '') === ($config['property_id'] ?? '') && !empty($config['property_id'])) {
                $group_property = $name;
                $schema = $property;
                break;
            }
        }
        $option_groups = [];
        if (($config['type'] ?? '') === 'status' && ($config['group_by'] ?? '') === 'group') {
            foreach (($schema['status']['groups'] ?? []) as $group) {
                foreach (($group['option_ids'] ?? []) as $option_id) $option_groups[$option_id] = $group['name'];
                $grouped[$group['name']] = [];
            }
        } elseif ($group_property !== '') {
            foreach (($schema[$schema['type']]['options'] ?? []) as $option) {
                $grouped[$option['name']] = [];
            }
        }

        foreach ($records as $record) {
            $properties = $record['properties'] ?? [];
            $status = __('未分类', 'notion-to-wordpress');
            if ($group_property !== '') {
                $value = $properties[$group_property] ?? [];
                $type = $value['type'] ?? '';
                $labels = [];
                if ($type === 'multi_select') {
                    $labels = array_column($value['multi_select'] ?? [], 'name');
                } elseif ($type === 'status' || $type === 'select') {
                    $option = $value[$type] ?? [];
                    $labels[] = $option_groups[$option['id'] ?? ''] ?? ($option['name'] ?? $status);
                } else {
                    $labels[] = strip_tags(self::format_property_value($value, $type)) ?: $status;
                }
                foreach (array_unique($labels ?: [$status]) as $label) $grouped[$label][] = $record;
                continue;
            }

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

        $sort = $config['sort']['type'] ?? 'manual';
        if (!empty($config['hide_empty_groups'])) $grouped = array_filter($grouped, static function (array $items): bool { return !empty($items); });
        if ($sort === 'ascending') ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
        elseif ($sort === 'descending') krsort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
        return $grouped;
    }

    // ==================== 高级渲染方法 ====================

    /**
     * 渲染数据库预览记录（带API调用）
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $database_info 数据库信息
     * @param \NTWP\Services\API $notion_api API实例
     * @return string HTML内容
     */
    public static function render_database_preview_records(string $database_id, array $database_info, \NTWP\Services\API $notion_api): string {
        try {
            // 获取数据库中的记录
            // 使用with_details=true获取包含封面图片和图标的完整信息
            $records = self::read_view_records($database_id, $database_info, $notion_api);

            if (empty($records)) {
                \NTWP\Core\Logger::debug_log(
                    '数据库无记录或无权限访问: ' . $database_id,
                    'Database Block'
                );
                return '<div class="notion-database-empty">' . __('暂无记录', 'notion-to-wordpress') . '</div>';
            }

            // 显示所有记录的预览
            \NTWP\Core\Logger::debug_log(
                '获取数据库记录成功: ' . $database_id . ', 总记录: ' . count($records),
                'Database Block'
            );

            return self::render_database_preview_records_with_data($database_id, $database_info, $records);

        } catch (Exception $e) {
            \NTWP\Core\Logger::error_log(
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

            \NTWP\Core\Logger::debug_log(
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
     * 渲染数据库预览记录（使用预处理数据）
     *
     * @since 2.0.0-beta.1
     * @param string $database_id 数据库ID
     * @param array $database_info 数据库信息
     * @param array $records 预处理的记录数据
     * @return string HTML内容
     */
    public static function render_database_preview_records_with_data(string $database_id, array $database_info, array $records): string {
        $database_info['id'] = $database_id;
        try {
            if (empty($records)) {
                \NTWP\Core\Logger::debug_log(
                    '数据库无记录或无权限访问（批量模式）: ' . $database_id,
                    'Database Block'
                );
                return '<div class="notion-database-empty">' . __('暂无记录', 'notion-to-wordpress') . '</div>';
            }

            // 显示所有记录的预览
            \NTWP\Core\Logger::debug_log(
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

            \NTWP\Core\Logger::debug_log(
                '选择视图类型（批量模式）: ' . $view_type . ' for database: ' . $database_id . ', 标题: ' . $database_title,
                'Database View'
            );

            // Render one complete view: splitting a board repeats groups and
            // splitting a table repeats its title and column headers.
            return self::render_database([
                'database_info' => $database_info,
                'records' => $records,
            ]);

        } catch (Exception $e) {
            \NTWP\Core\Logger::error_log(
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
     * @param \NTWP\Services\API $notion_api API实例
     * @return array 处理后的数据库数据
     */
    public static function batch_process_child_databases(array $database_blocks, \NTWP\Services\API $notion_api): array {
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

        \NTWP\Core\Logger::debug_log(
            sprintf(
                '开始批量处理子数据库: %d个块，%d个唯一数据库ID',
                count($database_blocks),
                count($unique_database_ids)
            ),
            'Batch Database'
        );

        $database_data = [];
        $db_info_time = 0.0;
        $db_records_time = 0.0;

        try {
            // 批量获取数据库信息
            $db_info_start = microtime(true);
            $database_infos = $notion_api->batch_get_databases($unique_database_ids);
            $db_info_time = microtime(true) - $db_info_start;

            \NTWP\Core\Logger::debug_log(
                sprintf('批量获取数据库信息耗时: %.4f秒', $db_info_time),
                'Batch Database Performance'
            );

            // 批量获取数据库记录
            $database_records = [];
            $valid_database_ids = [];

            foreach ($unique_database_ids as $database_id) {
                if (isset($database_infos[$database_id]) && !($database_infos[$database_id] instanceof \Exception)) {
                    $valid_database_ids[] = $database_id;
                }
            }

            if (!empty($valid_database_ids)) {
                // 为每个数据库准备查询参数（空数组表示获取所有记录）
                $query_filters = array_fill(0, count($valid_database_ids), []);

                // 批量查询数据库记录
                $db_records_start = microtime(true);
                foreach ($valid_database_ids as $database_id) {
                    $database_records[$database_id] = self::read_view_records($database_id, $database_infos[$database_id], $notion_api);
                }
                $db_records_time = microtime(true) - $db_records_start;

                \NTWP\Core\Logger::debug_log(
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

            \NTWP\Core\Logger::debug_log(
                sprintf(
                    '批量数据库处理完成: %d个块，%d个唯一数据库，总耗时 %.4f秒',
                    count($database_blocks),
                    count($unique_database_ids),
                    $execution_time
                ),
                'Batch Database'
            );

            // 详细性能统计
            $api_time = $db_info_time + $db_records_time;
            $processing_time = $execution_time - $api_time;

            \NTWP\Core\Logger::debug_log(
                sprintf(
                    '性能分解: API调用 %.4f秒, 数据处理 %.4f秒',
                    $api_time,
                    $processing_time
                ),
                'Batch Database Performance'
            );

        } catch (Exception $e) {
            \NTWP\Core\Logger::error_log(
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

        \NTWP\Core\Logger::debug_log(
            '开始渲染数据库属性: ' . $database_id,
            'Database Block'
        );

        if (isset($database_info['properties']) && is_array($database_info['properties'])) {
            $properties = $database_info['properties'];
            $property_count = count($properties);

            if ($property_count > 0) {
                \NTWP\Core\Logger::debug_log(
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
                \NTWP\Core\Logger::debug_log(
                    '数据库属性为空: ' . $database_id,
                    'Database Block'
                );
            }
        } else {
            \NTWP\Core\Logger::debug_log(
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
            \NTWP\Core\Logger::debug_log(
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
                    return \NTWP\Core\Text_Processor::extract_rich_text_complete($property['title']);
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
     * @param \NTWP\Services\API $notion_api API实例
     * @return string HTML内容
     */
    public static function render_child_database(string $database_id, string $database_title, \NTWP\Services\API $notion_api): string {
        try {
            \NTWP\Core\Logger::debug_log(
                "开始渲染子数据库: {$database_title} (ID: {$database_id})",
                'Child Database'
            );

            // 获取数据库信息
            $database_info = $notion_api->get_database($database_id);
            if (empty($database_info)) {
                \NTWP\Core\Logger::warning_log(
                    "无法获取数据库信息: {$database_title} (ID: {$database_id})",
                    'Child Database'
                );
                return '<div class="notion-database-empty">' . sprintf(__('数据库 "%s" 信息获取失败', 'notion-to-wordpress'), esc_html($database_title)) . '</div>';
            }

            // 获取数据库记录（使用with_details=true获取完整信息）
            $records = self::read_view_records($database_id, $database_info, $notion_api);
            if (empty($records)) {
                \NTWP\Core\Logger::debug_log(
                    "数据库无记录或无权限访问: {$database_title} (ID: {$database_id})",
                    'Child Database'
                );
                return '<div class="notion-database-empty">' . sprintf(__('数据库 "%s" 暂无记录', 'notion-to-wordpress'), esc_html($database_title)) . '</div>';
            }

            // 调用现有的渲染逻辑
            $rendered_content = self::render_database_preview_records_with_data($database_id, $database_info, $records);

            if (!empty($rendered_content)) {
                \NTWP\Core\Logger::info_log(
                    "子数据库渲染成功: {$database_title} (ID: {$database_id}), 记录数: " . count($records),
                    'Child Database'
                );
                return $rendered_content;
            } else {
                \NTWP\Core\Logger::warning_log(
                    "子数据库渲染结果为空: {$database_title} (ID: {$database_id})",
                    'Child Database'
                );
                return '<div class="notion-database-empty">' . sprintf(__('数据库 "%s" 渲染失败', 'notion-to-wordpress'), esc_html($database_title)) . '</div>';
            }

        } catch (Exception $e) {
            \NTWP\Core\Logger::error_log(
                "子数据库渲染异常: {$database_title} (ID: {$database_id}) - " . $e->getMessage(),
                'Child Database'
            );
            \NTWP\Core\Logger::error_log(
                "异常堆栈: " . $e->getTraceAsString(),
                'Child Database'
            );
            return '<div class="notion-database-error">' . sprintf(__('数据库 "%s" 加载失败: %s', 'notion-to-wordpress'), esc_html($database_title), esc_html($e->getMessage())) . '</div>';
        }
    }
}
