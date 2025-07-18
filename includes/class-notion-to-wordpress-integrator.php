<?php
declare(strict_types=1);

/**
 * Notion To WordPress 集成器类
 *
 * 专门处理所有 WordPress 集成功能，包括文章创建更新、分类标签设置、
 * 特色图片处理、自定义字段应用等 WordPress API 交互。负责管理与
 * WordPress 系统的所有交互，确保数据一致性和错误处理。
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

class Notion_To_WordPress_Integrator {

    /**
     * 创建或更新WordPress文章
     *
     * @since 2.0.0-beta.1
     * @param array $metadata 文章元数据
     * @param string $content 文章内容
     * @param int $author_id 作者ID
     * @param string $page_id Notion页面ID
     * @param int $existing_post_id 现有文章ID（更新时使用）
     * @return int|WP_Error 文章ID或错误对象
     */
    public static function create_or_update_post(array $metadata, string $content, int $author_id, string $page_id, int $existing_post_id = 0) {
        $post_data = [
            'post_title'   => wp_strip_all_tags($metadata['title']),
            'post_content' => $content,
            'post_status'  => $metadata['status'] ?? 'draft',
            'post_author'  => $author_id,
            'post_type'    => $metadata['post_type'] ?? 'post',
            'post_excerpt' => isset($metadata['excerpt']) ? wp_strip_all_tags($metadata['excerpt']) : '',
            'meta_input'   => [
                '_notion_page_id' => $page_id,
            ],
        ];

        // 处理文章密码 - 确保密码保护正确应用
        if (!empty($metadata['password'])) {
            $post_data['post_password'] = $metadata['password'];
            
            // 确保有密码的文章处于发布状态，否则密码保护无效
            if ($post_data['post_status'] === 'draft') {
                $post_data['post_status'] = 'publish';
                Notion_To_WordPress_Helper::debug_log(
                    '文章有密码但状态为草稿，已自动调整为已发布状态以使密码生效',
                    'Post Status',
                    Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
                );
            }
        }

        // 记录最终的文章数据
        Notion_To_WordPress_Helper::debug_log(
            sprintf('文章数据: 标题=%s, 状态=%s, 类型=%s, 密码=%s', 
                $post_data['post_title'],
                $post_data['post_status'],
                $post_data['post_type'],
                !empty($post_data['post_password']) ? '已设置' : '无'
            ),
            'Post Data',
            Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
        );

        if (isset($metadata['date'])) {
            $post_data['post_date'] = $metadata['date'];
        }

        $post_id = 0;
        if ($existing_post_id) {
            $post_data['ID'] = $existing_post_id;
            $post_id = wp_update_post($post_data, true);
        } else {
            $post_id = wp_insert_post($post_data, true);
        }

        // 如果创建/更新成功，处理自定义字段
        if (!is_wp_error($post_id) && $post_id > 0 && !empty($metadata['custom_fields'])) {
            self::apply_custom_fields($post_id, $metadata['custom_fields']);
        }

        return $post_id;
    }

    /**
     * 应用自定义字段
     *
     * @since 2.0.0-beta.1
     * @param int $post_id 文章ID
     * @param array $custom_fields 自定义字段数组
     */
    public static function apply_custom_fields(int $post_id, array $custom_fields): void {
        foreach ($custom_fields as $field_name => $field_value) {
            update_post_meta($post_id, $field_name, $field_value);
            Notion_To_WordPress_Helper::debug_log(
                "应用自定义字段: {$field_name} = {$field_value}", 
                'Custom Fields', 
                Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
            );
        }
    }

    /**
     * 设置分类与标签
     *
     * @since 2.0.0-beta.1
     * @param int $post_id 文章ID
     * @param array $metadata 元数据数组
     */
    public static function apply_taxonomies(int $post_id, array $metadata): void {
        if (!empty($metadata['categories'])) {
            wp_set_post_categories($post_id, $metadata['categories']);
            Notion_To_WordPress_Helper::debug_log(
                "设置分类: " . implode(', ', $metadata['categories']),
                'Taxonomies'
            );
        }
        
        if (!empty($metadata['tags'])) {
            wp_set_post_tags($post_id, $metadata['tags']);
            Notion_To_WordPress_Helper::debug_log(
                "设置标签: " . implode(', ', $metadata['tags']),
                'Taxonomies'
            );
        }
    }

    /**
     * 处理特色图片
     *
     * @since 2.0.0-beta.1
     * @param int $post_id 文章ID
     * @param array $metadata 元数据数组
     */
    public static function apply_featured_image(int $post_id, array $metadata): void {
        if (!empty($metadata['featured_image'])) {
            self::set_featured_image($post_id, $metadata['featured_image']);
        }
    }

    /**
     * 设置特色图片
     *
     * @since 2.0.0-beta.1
     * @param int $post_id WordPress文章ID
     * @param string $image_url 图片URL
     * @return bool 是否成功
     */
    private static function set_featured_image(int $post_id, string $image_url): bool {
        if (empty($image_url)) {
            return false;
        }

        // 如果不是Notion临时链接，尝试直接使用外链
        if (!self::is_notion_temp_url($image_url)) {
            // 对于外链，我们可以选择直接使用或者仍然下载
            // 这里提供一个选项，默认直接使用外链以节约资源
            $use_external_featured_image = apply_filters('notion_to_wordpress_use_external_featured_image', true);

            if ($use_external_featured_image) {
                // 直接使用外链作为特色图片（通过自定义字段）
                update_post_meta($post_id, '_notion_featured_image_url', esc_url_raw($image_url));

                Notion_To_WordPress_Helper::debug_log(
                    'Using external featured image URL: ' . $image_url,
                    'Featured Image'
                );
                return true;
            }
        }

        // Notion临时链接或选择下载外链的情况
        // 这里需要与图片处理器协调，暂时使用简化处理
        $attachment_id = self::download_and_insert_image($image_url, get_the_title($post_id));

        if (is_numeric($attachment_id) && $attachment_id > 0) {
            set_post_thumbnail($post_id, $attachment_id);
            return true;
        } elseif (is_string($attachment_id) && strpos($attachment_id, 'pending_image_') === 0) {
            // 异步模式：存储占位符，稍后处理
            update_post_meta($post_id, '_notion_featured_image_placeholder', $attachment_id);
            return true;
        } elseif (is_wp_error($attachment_id)) {
            Notion_To_WordPress_Helper::error_log(
                'Featured image download failed: ' . $attachment_id->get_error_message()
            );
            return false;
        }

        return false;
    }

    /**
     * 根据Notion页面ID获取WordPress文章ID
     *
     * @since 2.0.0-beta.1
     * @param string $notion_id Notion页面ID
     * @return int WordPress文章ID，不存在返回0
     */
    public static function get_post_by_notion_id(string $notion_id): int {
        // 禁用缓存，直接执行数据库查询以确保数据实时性
        global $wpdb;

        // 使用直接SQL查询而不是get_posts，减少开销
        $query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_notion_page_id' AND meta_value = %s
            LIMIT 1",
            $notion_id
        );

        $post_id = $wpdb->get_var($query);
        $result = $post_id ? (int)$post_id : 0;

        return $result;
    }

    /**
     * 批量获取多个Notion页面ID对应的WordPress文章ID
     *
     * @since 2.0.0-beta.1
     * @param array $notion_ids Notion页面ID数组
     * @return array [notion_id => post_id] 映射
     */
    public static function batch_get_posts_by_notion_ids(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        // 禁用缓存，直接执行批量数据库查询以确保数据实时性
        Notion_To_WordPress_Helper::debug_log(
            sprintf('批量获取文章ID映射（无缓存）: %d个页面', count($notion_ids)),
            'Integrator Batch Query'
        );

        global $wpdb;

        // 初始化映射数组，默认所有ID映射为0
        $mapping = array_fill_keys($notion_ids, 0);

        // 执行批量SQL查询
        $placeholders = implode(',', array_fill(0, count($notion_ids), '%s'));
        $query = $wpdb->prepare(
            "SELECT meta_value as notion_id, post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_notion_page_id'
            AND meta_value IN ($placeholders)",
            $notion_ids
        );

        $results = $wpdb->get_results($query);

        // 更新映射数组
        foreach ($results as $row) {
            $mapping[$row->notion_id] = (int)$row->post_id;
        }

        return $mapping;
    }

    /**
     * 删除WordPress文章
     *
     * @since 2.0.0-beta.1
     * @param int $post_id 文章ID
     * @param bool $force_delete 是否强制删除（跳过回收站）
     * @return bool 是否成功
     */
    public static function delete_post(int $post_id, bool $force_delete = false): bool {
        if ($post_id <= 0) {
            return false;
        }

        $result = wp_delete_post($post_id, $force_delete);

        if ($result) {
            Notion_To_WordPress_Helper::debug_log(
                "文章删除成功: ID {$post_id}",
                'Post Deletion'
            );

            // 清理相关缓存
            self::clear_post_cache($post_id);

            return true;
        }

        return false;
    }

    /**
     * 清理文章相关缓存（缓存已禁用，方法保留以维持兼容性）
     *
     * @since 2.0.0-beta.1
     * @param int $post_id 文章ID
     */
    private static function clear_post_cache(int $post_id): void {
        // 缓存已禁用，无需清理操作
        Notion_To_WordPress_Helper::debug_log(
            "文章缓存清理请求（缓存已禁用）: 文章ID {$post_id}",
            'Integrator Cache'
        );
    }

    // ==================== 辅助方法 ====================

    /**
     * 检查是否为Notion临时URL
     *
     * @since 2.0.0-beta.1
     * @param string $url 图片URL
     * @return bool 是否为临时URL
     */
    private static function is_notion_temp_url(string $url): bool {
        // Notion临时链接通常包含特定的域名和参数
        return strpos($url, 'secure.notion-static.com') !== false ||
               strpos($url, 'prod-files-secure') !== false ||
               strpos($url, 'X-Amz-Algorithm') !== false;
    }

    /**
     * 下载并插入图片到媒体库
     *
     * @since 2.0.0-beta.1
     * @param string $image_url 图片URL
     * @param string $title 图片标题
     * @return int|string|WP_Error 附件ID、占位符字符串或错误对象
     */
    private static function download_and_insert_image(string $image_url, string $title = '') {
        // 这个方法需要与图片处理器协调
        // 暂时返回简化的处理结果

        if (empty($image_url)) {
            return new WP_Error('empty_url', '图片URL为空');
        }

        // 检查URL是否有效
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', '无效的图片URL');
        }

        // 简化处理：直接返回URL作为外部图片
        // 实际的下载和处理逻辑将在图片处理器中实现
        Notion_To_WordPress_Helper::debug_log(
            "图片处理委托给图片处理器: {$image_url}",
            'Image Processing'
        );

        // 返回一个占位符，表示需要异步处理
        return 'pending_image_' . md5($image_url);
    }

    /**
     * 获取文章状态统计
     *
     * @since 2.0.0-beta.1
     * @return array 统计数据
     */
    public static function get_post_stats(): array {
        global $wpdb;

        // 获取总的Notion同步文章数
        $total_posts = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_notion_page_id'"
        );

        // 获取各状态的文章数
        $status_counts = $wpdb->get_results(
            "SELECT p.post_status, COUNT(*) as count
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_notion_page_id'
            GROUP BY p.post_status"
        );

        $stats = [
            'total_posts' => (int)$total_posts,
            'status_breakdown' => []
        ];

        foreach ($status_counts as $status) {
            $stats['status_breakdown'][$status->post_status] = (int)$status->count;
        }

        return $stats;
    }

    /**
     * 验证文章数据
     *
     * @since 2.0.0-beta.1
     * @param array $metadata 文章元数据
     * @return bool|WP_Error 验证结果
     */
    public static function validate_post_data(array $metadata): bool|WP_Error {
        // 检查必需字段
        if (empty($metadata['title'])) {
            return new WP_Error('missing_title', '文章标题不能为空');
        }

        // 检查文章类型
        if (isset($metadata['post_type'])) {
            if (!post_type_exists($metadata['post_type'])) {
                return new WP_Error('invalid_post_type', '无效的文章类型: ' . $metadata['post_type']);
            }
        }

        // 检查文章状态
        if (isset($metadata['status'])) {
            $valid_statuses = get_post_statuses();
            if (!array_key_exists($metadata['status'], $valid_statuses)) {
                return new WP_Error('invalid_status', '无效的文章状态: ' . $metadata['status']);
            }
        }

        return true;
    }



    /**
     * 获取作者ID（向后兼容方法）
     *
     * @since 2.0.0-beta.1
     * @return int 作者ID
     */
    public static function get_default_author_id(): int {
        // 获取默认作者ID，通常是管理员
        $users = get_users(['role' => 'administrator', 'number' => 1]);
        return !empty($users) ? $users[0]->ID : 1;
    }

    /**
     * 初始化特色图像显示支持
     *
     * @since 2.0.0-beta.1
     */
    public static function init_featured_image_support(): void {
        // 添加钩子支持外部特色图像显示
        add_filter('post_thumbnail_html', [self::class, 'filter_post_thumbnail_html'], 10, 5);
        add_filter('get_post_metadata', [self::class, 'filter_thumbnail_id'], 10, 4);
    }

    /**
     * 过滤特色图像HTML输出，支持外部链接
     *
     * @since 2.0.0-beta.1
     * @param string $html 原始HTML
     * @param int $post_id 文章ID
     * @param int $post_thumbnail_id 特色图像ID
     * @param string|array $size 图像尺寸
     * @param string|array $attr 图像属性
     * @return string 过滤后的HTML
     */
    public static function filter_post_thumbnail_html(string $html, int $post_id, int $post_thumbnail_id, $size, $attr): string {
        // 如果已经有特色图像，直接返回
        if (!empty($html) && $post_thumbnail_id > 0) {
            return $html;
        }

        // 检查是否有外部特色图像URL
        $external_url = get_post_meta($post_id, '_notion_featured_image_url', true);
        if (empty($external_url)) {
            return $html;
        }

        // 生成外部图像的HTML
        $alt_text = get_the_title($post_id);
        $class = 'attachment-' . (is_array($size) ? implode('x', $size) : $size) . ' size-' . (is_array($size) ? implode('x', $size) : $size);

        // 处理属性
        $attributes = '';
        if (is_array($attr)) {
            foreach ($attr as $key => $value) {
                if ($key !== 'alt' && $key !== 'class') {
                    $attributes .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
                }
            }
        }

        return sprintf(
            '<img src="%s" alt="%s" class="%s" loading="lazy"%s>',
            esc_url($external_url),
            esc_attr($alt_text),
            esc_attr($class),
            $attributes
        );
    }

    /**
     * 过滤缩略图ID，为外部图像提供虚拟ID
     *
     * @since 2.0.0-beta.1
     * @param mixed $value 元数据值
     * @param int $object_id 对象ID
     * @param string $meta_key 元数据键
     * @param bool $single 是否返回单个值
     * @return mixed 过滤后的值
     */
    public static function filter_thumbnail_id($value, int $object_id, string $meta_key, bool $single) {
        // 只处理特色图像相关的元数据
        if ($meta_key !== '_thumbnail_id') {
            return $value;
        }

        // 如果已经有特色图像ID，不做处理
        if (!empty($value)) {
            return $value;
        }

        // 检查是否有外部特色图像URL
        $external_url = get_post_meta($object_id, '_notion_featured_image_url', true);
        if (!empty($external_url)) {
            // 返回一个虚拟的ID，表示存在外部特色图像
            return $single ? -1 : [-1];
        }

        return $value;
    }
}
