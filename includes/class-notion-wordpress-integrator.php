<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Notion WordPress集成器
 *
 * 负责Notion数据与WordPress之间的集成操作
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */

class Notion_WordPress_Integrator {

    /**
     * 静态缓存：Notion ID到WordPress文章ID的映射
     *
     * @since 1.1.0
     * @var array
     */
    private static array $notion_post_cache = [];

    /**
     * 根据Notion页面ID获取WordPress文章ID
     *
     * @since 1.1.0
     * @param string $notion_id Notion页面ID
     * @return int WordPress文章ID，如果不存在返回0
     */
    public static function get_post_by_notion_id(string $notion_id): int {
        // 1. 进程级静态缓存
        if (isset(self::$notion_post_cache[$notion_id])) {
            return self::$notion_post_cache[$notion_id];
        }

        // 2. WordPress对象缓存
        $cache_key = 'ntw_notion_post_' . md5($notion_id);
        $cached_post_id = wp_cache_get($cache_key, 'notion_to_wordpress');
        if ($cached_post_id !== false) {
            self::$notion_post_cache[$notion_id] = (int) $cached_post_id;
            return (int) $cached_post_id;
        }

        // 3. 数据库查询
        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'numberposts' => 1,
            'meta_query' => [
                [
                    'key' => '_notion_page_id',
                    'value' => $notion_id,
                    'compare' => '='
                ]
            ],
            'fields' => 'ids'
        ]);

        $post_id = !empty($posts) ? (int) $posts[0] : 0;

        // 缓存结果
        self::$notion_post_cache[$notion_id] = $post_id;
        wp_cache_set($cache_key, $post_id, 'notion_to_wordpress', 3600); // 1小时缓存

        return $post_id;
    }

    /**
     * 创建或更新WordPress文章
     *
     * @since 1.1.0
     * @param array  $metadata        文章元数据
     * @param string $content         文章内容
     * @param int    $author_id       作者ID
     * @param string $page_id         Notion页面ID
     * @param int    $existing_post_id 现有文章ID（如果是更新）
     * @return int|WP_Error 文章ID或错误对象
     */
    public static function create_or_update_post(
        array $metadata,
        string $content,
        int $author_id,
        string $page_id,
        int $existing_post_id = 0
    ) {
        $post_data = [
            'post_title'   => wp_strip_all_tags($metadata['title'] ?? ''),
            'post_content' => $content,
            'post_status'  => $metadata['status'] ?? 'draft',
            'post_author'  => $author_id,
            'post_type'    => $metadata['post_type'] ?? 'post',
            'meta_input'   => [
                '_notion_page_id' => $page_id,
                '_notion_last_sync' => current_time('mysql'),
                '_notion_url' => "https://notion.so/{$page_id}"
            ]
        ];

        // 如果是更新现有文章
        if ($existing_post_id > 0) {
            $post_data['ID'] = $existing_post_id;
            
            // 检查是否需要更新
            $existing_post = get_post($existing_post_id);
            if ($existing_post) {
                $needs_update = false;
                
                // 比较标题
                if ($existing_post->post_title !== $post_data['post_title']) {
                    $needs_update = true;
                }
                
                // 比较内容（简单的长度比较，避免复杂的内容对比）
                if (strlen($existing_post->post_content) !== strlen($post_data['post_content'])) {
                    $needs_update = true;
                }
                
                // 比较状态
                if ($existing_post->post_status !== $post_data['post_status']) {
                    $needs_update = true;
                }
                
                if (!$needs_update) {
                    // 更新同步时间
                    update_post_meta($existing_post_id, '_notion_last_sync', current_time('mysql'));
                    return $existing_post_id;
                }
            }
            
            $result = wp_update_post($post_data, true);
        } else {
            $result = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $post_id = (int) $result;

        // 更新缓存
        self::$notion_post_cache[$page_id] = $post_id;
        $cache_key = 'ntw_notion_post_' . md5($page_id);
        wp_cache_set($cache_key, $post_id, 'notion_to_wordpress', 3600);

        // 记录日志
        $action = $existing_post_id > 0 ? '更新' : '创建';
        Notion_To_WordPress_Error_Handler::log_info(
            "{$action}文章成功: {$post_data['post_title']} (ID: {$post_id})",
            Notion_To_WordPress_Error_Handler::CODE_IMPORT_ERROR,
            [
                'post_id' => $post_id,
                'notion_page_id' => $page_id,
                'action' => $action
            ]
        );

        return $post_id;
    }

    /**
     * 应用自定义字段
     *
     * @since 1.1.0
     * @param int   $post_id      文章ID
     * @param array $custom_fields 自定义字段数组
     */
    public static function apply_custom_fields(int $post_id, array $custom_fields): void {
        foreach ($custom_fields as $field_name => $field_value) {
            // 清理字段名
            $field_name = sanitize_key($field_name);
            
            // 处理不同类型的字段值
            if (is_array($field_value)) {
                $field_value = array_map('sanitize_text_field', $field_value);
            } else {
                $field_value = sanitize_text_field($field_value);
            }
            
            update_post_meta($post_id, $field_name, $field_value);
            
            Notion_To_WordPress_Error_Handler::log_debug(
                "应用自定义字段: {$field_name}",
                Notion_To_WordPress_Error_Handler::CODE_IMPORT_ERROR,
                ['post_id' => $post_id, 'field_name' => $field_name]
            );
        }
    }

    /**
     * 设置分类与标签
     *
     * @since 1.1.0
     * @param int   $post_id  文章ID
     * @param array $metadata 元数据
     */
    public static function apply_taxonomies(int $post_id, array $metadata): void {
        // 处理分类
        if (!empty($metadata['categories'])) {
            $categories = is_array($metadata['categories']) 
                ? $metadata['categories'] 
                : [$metadata['categories']];
            
            $category_ids = [];
            foreach ($categories as $category_name) {
                $category = get_category_by_slug(sanitize_title($category_name));
                if (!$category) {
                    $category_id = wp_create_category($category_name);
                } else {
                    $category_id = $category->term_id;
                }
                if ($category_id && !is_wp_error($category_id)) {
                    $category_ids[] = $category_id;
                }
            }
            
            if (!empty($category_ids)) {
                wp_set_post_categories($post_id, $category_ids);
            }
        }

        // 处理标签
        if (!empty($metadata['tags'])) {
            $tags = is_array($metadata['tags']) 
                ? $metadata['tags'] 
                : [$metadata['tags']];
            
            wp_set_post_tags($post_id, $tags);
        }
    }

    /**
     * 处理特色图片
     *
     * @since 1.1.0
     * @param int   $post_id  文章ID
     * @param array $metadata 元数据
     */
    public static function apply_featured_image(int $post_id, array $metadata): void {
        if (!empty($metadata['featured_image'])) {
            self::set_featured_image($post_id, $metadata['featured_image']);
        }
    }

    /**
     * 设置特色图片
     *
     * @since 1.1.0
     * @param int    $post_id   文章ID
     * @param string $image_url 图片URL
     */
    private static function set_featured_image(int $post_id, string $image_url): void {
        if (empty($image_url)) {
            return;
        }

        // 使用统一的媒体处理器
        $result = Notion_Media_Handler::download_and_process(
            $image_url,
            $post_id,
            true, // 设置为特色图片
            '', // 无标题
            '' // 无alt文本
        );

        if ($result['success'] && isset($result['attachment_id'])) {
            set_post_thumbnail($post_id, $result['attachment_id']);
            
            Notion_To_WordPress_Error_Handler::log_info(
                "设置特色图片成功: 文章ID {$post_id}, 附件ID {$result['attachment_id']}",
                Notion_To_WordPress_Error_Handler::CODE_MEDIA_ERROR,
                [
                    'post_id' => $post_id,
                    'attachment_id' => $result['attachment_id'],
                    'image_url' => $image_url
                ]
            );
        } else {
            Notion_To_WordPress_Error_Handler::log_warning(
                "设置特色图片失败: " . ($result['error'] ?? '未知错误'),
                Notion_To_WordPress_Error_Handler::CODE_MEDIA_ERROR,
                [
                    'post_id' => $post_id,
                    'image_url' => $image_url
                ]
            );
        }
    }

    /**
     * 获取合适的文章作者ID
     *
     * @since 1.1.0
     * @return int 作者ID
     */
    public static function get_author_id(): int {
        $author_id = get_current_user_id();
        if ($author_id) {
            return $author_id;
        }
        
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        return !empty($admins) ? $admins[0]->ID : 1;
    }

    /**
     * 清理静态缓存
     *
     * @since 1.1.0
     */
    public static function clear_static_cache(): void {
        self::$notion_post_cache = [];
        
        Notion_To_WordPress_Error_Handler::log_debug(
            '已清理WordPress集成器静态缓存',
            Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR
        );
    }

    /**
     * 获取文章的Notion页面ID
     *
     * @since 1.1.0
     * @param int $post_id 文章ID
     * @return string Notion页面ID，如果不存在返回空字符串
     */
    public static function get_notion_id_by_post(int $post_id): string {
        return get_post_meta($post_id, '_notion_page_id', true) ?: '';
    }

    /**
     * 检查文章是否来自Notion
     *
     * @since 1.1.0
     * @param int $post_id 文章ID
     * @return bool 是否来自Notion
     */
    public static function is_notion_post(int $post_id): bool {
        return !empty(self::get_notion_id_by_post($post_id));
    }

    /**
     * 获取文章的最后同步时间
     *
     * @since 1.1.0
     * @param int $post_id 文章ID
     * @return string 最后同步时间，如果不存在返回空字符串
     */
    public static function get_last_sync_time(int $post_id): string {
        return get_post_meta($post_id, '_notion_last_sync', true) ?: '';
    }

    /**
     * 批量清理WordPress缓存
     *
     * @since 1.1.0
     * @param array $notion_ids Notion页面ID数组
     */
    public static function clear_cache_for_pages(array $notion_ids): void {
        foreach ($notion_ids as $notion_id) {
            $cache_key = 'ntw_notion_post_' . md5($notion_id);
            wp_cache_delete($cache_key, 'notion_to_wordpress');
            
            if (isset(self::$notion_post_cache[$notion_id])) {
                unset(self::$notion_post_cache[$notion_id]);
            }
        }
        
        Notion_To_WordPress_Error_Handler::log_debug(
            '已清理 ' . count($notion_ids) . ' 个页面的缓存',
            Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
            ['cleared_pages' => count($notion_ids)]
        );
    }
}
