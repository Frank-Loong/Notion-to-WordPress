<?php
declare(strict_types=1);

namespace NTWP\Handlers;

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

class Integrator {

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
        // 确保标题不为空，提供默认值
        $title = !empty($metadata['title']) ? $metadata['title'] : sprintf('Untitled Post %s', substr($page_id, -8));

        $post_data = [
            'post_title'   => wp_strip_all_tags($title),
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
                \NTWP\Core\Foundation\Logger::debug_log(
                    '文章有密码但状态为草稿，已自动调整为已发布状态以使密码生效',
                    'Post Status'
                );
            }
        }

        // 记录最终的文章数据
        \NTWP\Core\Foundation\Logger::debug_log(
            sprintf('文章数据: 标题=%s, 状态=%s, 类型=%s, 密码=%s',
                $post_data['post_title'],
                $post_data['post_status'],
                $post_data['post_type'],
                !empty($post_data['post_password']) ? '已设置' : '无'
            ),
            'Post Data'
        );

        if (isset($metadata['date'])) {
            $post_data['post_date'] = $metadata['date'];
        }

        // 诊断日志：记录WordPress函数调用前的状态
        $content_preview = substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '');
        $has_wordpress_links_before = strpos($content, 'frankloong.local') !== false;

        \NTWP\Core\Foundation\Logger::debug_log(
            sprintf('准备调用WordPress函数: 操作=%s, 内容长度=%d, 包含WordPress链接=%s',
                $existing_post_id ? 'UPDATE' : 'INSERT',
                strlen($content),
                $has_wordpress_links_before ? '是' : '否'
            ),
            'WordPress Integration'
        );

        \NTWP\Core\Foundation\Logger::debug_log(
            '内容预览: ' . $content_preview,
            'WordPress Integration'
        );

        $post_id = 0;
        if ($existing_post_id) {
            $post_data['ID'] = $existing_post_id;

            \NTWP\Core\Foundation\Logger::debug_log(
                sprintf('调用wp_update_post: 文章ID=%d', $existing_post_id),
                'WordPress Integration'
            );

            $post_id = wp_update_post($post_data, true);

            if (is_wp_error($post_id)) {
                \NTWP\Core\Foundation\Logger::error_log(
                    'wp_update_post失败: ' . $post_id->get_error_message(),
                    'WordPress Integration'
                );
            } else {
                \NTWP\Core\Foundation\Logger::debug_log(
                    sprintf('wp_update_post成功: 返回ID=%d', $post_id),
                    'WordPress Integration'
                );
            }
        } else {
            \NTWP\Core\Foundation\Logger::debug_log(
                '调用wp_insert_post: 创建新文章',
                'WordPress Integration'
            );

            $post_id = wp_insert_post($post_data, true);

            if (is_wp_error($post_id)) {
                \NTWP\Core\Foundation\Logger::error_log(
                    'wp_insert_post失败: ' . $post_id->get_error_message(),
                    'WordPress Integration'
                );
            } else {
                \NTWP\Core\Foundation\Logger::debug_log(
                    sprintf('wp_insert_post成功: 新文章ID=%d', $post_id),
                    'WordPress Integration'
                );
            }
        }

        // 诊断日志：验证WordPress函数调用后的结果
        if (!is_wp_error($post_id) && $post_id > 0) {
            // 立即从数据库重新获取文章内容进行验证
            $verification_post = get_post($post_id);
            if ($verification_post) {
                $saved_content = $verification_post->post_content;
                $has_wordpress_links_after = strpos($saved_content, 'frankloong.local') !== false;

                \NTWP\Core\Foundation\Logger::debug_log(
                    sprintf('WordPress函数调用后验证: 保存长度=%d, 包含WordPress链接=%s',
                        strlen($saved_content),
                        $has_wordpress_links_after ? '是' : '否'
                    ),
                    'WordPress Integration'
                );

                // 检查内容是否正确保存
                if ($has_wordpress_links_before && !$has_wordpress_links_after) {
                    \NTWP\Core\Foundation\Logger::error_log(
                        '关键问题：WordPress链接在wp_update_post/wp_insert_post后丢失！',
                        'WordPress Integration'
                    );
                } elseif ($has_wordpress_links_before && $has_wordpress_links_after) {
                    \NTWP\Core\Foundation\Logger::debug_log(
                        'WordPress链接成功保存到数据库',
                        'WordPress Integration'
                    );
                }
            } else {
                \NTWP\Core\Foundation\Logger::error_log(
                    '无法重新获取文章进行验证',
                    'WordPress Integration'
                );
            }
        }

        // 如果创建/更新成功，处理自定义字段和缓存清除
        if (!is_wp_error($post_id) && $post_id > 0) {
            // 处理自定义字段
            if (!empty($metadata['custom_fields'])) {
                self::apply_custom_fields($post_id, $metadata['custom_fields']);
            }
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
            \NTWP\Core\Foundation\Logger::debug_log(
                "应用自定义字段: {$field_name} = {$field_value}",
                'Custom Fields'
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
            \NTWP\Core\Foundation\Logger::debug_log(
                "设置分类: " . implode(', ', $metadata['categories']),
                'Taxonomies'
            );
        }
        
        if (!empty($metadata['tags'])) {
            wp_set_post_tags($post_id, $metadata['tags']);
            \NTWP\Core\Foundation\Logger::debug_log(
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

                \NTWP\Core\Foundation\Logger::debug_log(
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
            \NTWP\Core\Foundation\Logger::error_log(
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
            \NTWP\Core\Foundation\Logger::debug_log(
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
        \NTWP\Core\Foundation\Logger::debug_log(
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
        \NTWP\Core\Foundation\Logger::debug_log(
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
        // 确保标题字段存在，如果为空则提供默认值
        if (empty($metadata['title'])) {
            \NTWP\Core\Foundation\Logger::warning_log('文章标题为空，将在创建时提供默认标题', 'WordPress Integration');
            // 不再返回错误，而是在后续处理中提供默认标题
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

    /**
     * 延迟链接转换处理
     *
     * 在所有页面同步完成后，重新处理包含未转换Notion链接的文章
     *
     * @since 2.0.0-beta.1
     * @return array 处理结果统计
     */
    public static function process_delayed_link_conversion(): array {
        $stats = [
            'processed' => 0,
            'updated' => 0,
            'errors' => 0
        ];

        // 查找包含Notion链接的文章（只匹配href属性中的链接）
        global $wpdb;
        $query = "
            SELECT p.ID, p.post_content, pm.meta_value as notion_page_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_notion_page_id'
            AND p.post_content LIKE '%href%notion.so%'
            AND p.post_status = 'publish'
        ";

        $posts_with_notion_links = $wpdb->get_results($query);

        if (empty($posts_with_notion_links)) {
            return $stats;
        }

        foreach ($posts_with_notion_links as $post) {
            $stats['processed']++;

            // 重新处理文章内容中的链接
            $original_content = $post->post_content;
            $updated_content = self::convert_notion_links_in_content($original_content);

            // 检查是否有链接被转换
            if ($updated_content !== $original_content) {
                // 直接更新数据库避免HTML实体转义
                global $wpdb;

                $update_result = $wpdb->update(
                    $wpdb->posts,
                    ['post_content' => $updated_content],
                    ['ID' => $post->ID],
                    ['%s'],
                    ['%d']
                );

                if ($update_result === false) {
                    $stats['errors']++;
                    \NTWP\Core\Foundation\Logger::error_log(
                        sprintf('更新文章 %d 链接转换失败', $post->ID),
                        'Delayed Link Conversion'
                    );
                } else {
                    $stats['updated']++;
                    // 强制清除缓存
                    clean_post_cache($post->ID);
                }
            }
        }

        // 只在有实际更新或错误时记录日志
        if ($stats['updated'] > 0 || $stats['errors'] > 0) {
            \NTWP\Core\Foundation\Logger::info_log(
                sprintf('延迟链接转换完成: 处理=%d, 更新=%d, 错误=%d',
                    $stats['processed'], $stats['updated'], $stats['errors']),
                'Delayed Link Conversion'
            );
        }



        return $stats;
    }

    /**
     * 转换内容中的Notion链接
     *
     * @since 2.0.0-beta.1
     * @param string $content 原始内容
     * @return string 转换后的内容
     */
    private static function convert_notion_links_in_content(string $content): string {
        // 保护公式和Mermaid内容，避免在链接转换过程中被破坏
        $protected_placeholders = [];
        $protected_patterns = [
            '/(<span[^>]*class="[^"]*notion-equation[^"]*"[^>]*>.*?<\/span>)/s',
            '/(<div[^>]*class="[^"]*notion-equation[^"]*"[^>]*>.*?<\/div>)/s',
            '/(<div[^>]*class="[^"]*mermaid[^"]*"[^>]*>.*?<\/div>)/s'  // 保护Mermaid图表
        ];

        // 用占位符替换受保护的内容
        foreach ($protected_patterns as $pattern) {
            $content = preg_replace_callback($pattern, function($matches) use (&$protected_placeholders) {
                $placeholder = '<!--PROTECTED_CONTENT_' . count($protected_placeholders) . '-->';
                $protected_placeholders[$placeholder] = $matches[0];
                return $placeholder;
            }, $content);
        }

        // 使用更精确的正则表达式，只匹配href属性中的Notion链接
        // 这样可以避免匹配HTML元素ID中的UUID
        $pattern = '/href\s*=\s*["\']https?:\/\/(?:www\.)?notion\.so\/(?:[^"\']*-)?([a-f0-9]{32}|[a-f0-9-]{36})(?:[?#][^"\']*)?["\']/i';

        $content = preg_replace_callback($pattern, function($matches) {
            $original_href_attr = $matches[0];

            // 提取完整的URL
            if (preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $original_href_attr, $url_matches)) {
                $original_link = $url_matches[1];

                // 使用现有的链接转换逻辑
                $converted_link = \NTWP\Services\Content\TextProcessor::convert_notion_page_to_wordpress($original_link);

                if ($converted_link !== $original_link) {
                    // 替换href属性中的URL
                    return str_replace($original_link, $converted_link, $original_href_attr);
                }
            }

            return $original_href_attr;
        }, $content);

        // 恢复受保护的内容（公式和Mermaid图表）
        foreach ($protected_placeholders as $placeholder => $protected_content) {
            $content = str_replace($placeholder, $protected_content, $content);
        }

        return $content;
    }

    /**
     * 修复文章中公式的HTML实体问题
     *
     * @since 2.0.0-beta.1
     * @return array 修复结果统计
     */
    public static function fix_formula_html_entities(): array {
        $stats = [
            'processed' => 0,
            'fixed' => 0,
            'errors' => 0
        ];

        // 查找所有包含公式的文章
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => '_notion_page_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);

        foreach ($posts as $post) {
            $stats['processed']++;

            $content = $post->post_content;
            $original_content = $content;

            // 修复公式中的HTML实体
            $content = preg_replace_callback(
                '/(<(?:span|div)[^>]*class="[^"]*notion-equation[^"]*"[^>]*>)(.*?)(<\/(?:span|div)>)/s',
                function($matches) {
                    $opening_tag = $matches[1];
                    $formula_content = $matches[2];
                    $closing_tag = $matches[3];

                    // 解码HTML实体
                    $fixed_content = html_entity_decode($formula_content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    return $opening_tag . $fixed_content . $closing_tag;
                },
                $content
            );

            // 如果内容有变化，更新文章
            if ($content !== $original_content) {
                $result = wp_update_post([
                    'ID' => $post->ID,
                    'post_content' => $content
                ], true);

                if (is_wp_error($result)) {
                    $stats['errors']++;
                    \NTWP\Core\Foundation\Logger::error_log(
                        sprintf('修复文章 %d 的公式HTML实体失败: %s', $post->ID, $result->get_error_message()),
                        'Formula Fix'
                    );
                } else {
                    $stats['fixed']++;
                    \NTWP\Core\Foundation\Logger::debug_log(
                        sprintf('成功修复文章 %d 的公式HTML实体', $post->ID),
                        'Formula Fix'
                    );
                }
            }
        }

        \NTWP\Core\Foundation\Logger::info_log(
            sprintf('公式HTML实体修复完成: 处理=%d, 修复=%d, 错误=%d',
                $stats['processed'], $stats['fixed'], $stats['errors']),
            'Formula Fix'
        );

        return $stats;
    }

    /**
     * 批量更新文章元数据
     *
     * 使用单个SQL语句批量更新多个文章的元数据，提升数据库操作效率
     *
     * @since 2.0.0-beta.1
     * @param array $updates 更新数据，格式：[post_id => [meta_key => meta_value, ...], ...]
     * @return bool 是否成功
     */
    public static function batch_update_post_meta(array $updates): bool {
        if (empty($updates)) {
            return true;
        }

        // 开始性能监控
        if (class_exists('\\NTWP\\Core\\Performance\\PerformanceMonitor')) {
            \NTWP\Core\Foundation\Performance\PerformanceMonitor::start_timer('batch_update_meta');
        }

        try {
            $success_count = 0;
            $total_count = 0;

            // 使用WordPress原生函数逐个更新，但批量处理以减少函数调用开销
            foreach ($updates as $post_id => $meta_data) {
                foreach ($meta_data as $meta_key => $meta_value) {
                    $total_count++;

                    // 使用WordPress原生函数，确保兼容性和数据完整性
                    $result = update_post_meta(intval($post_id), sanitize_text_field($meta_key), $meta_value);

                    if ($result !== false) {
                        $success_count++;
                    }
                }
            }

            // 结束性能监控
            if (class_exists('\\NTWP\\Core\\Performance\\PerformanceMonitor')) {
                \NTWP\Core\Foundation\Performance\PerformanceMonitor::end_timer('batch_update_meta');
                \NTWP\Core\Foundation\Performance\PerformanceMonitor::record_db_operation('batch_update_meta', $total_count, 0);
            }

            if ($success_count === $total_count) {
                \NTWP\Core\Foundation\Logger::debug_log(
                    sprintf('批量更新post_meta成功: %d/%d条记录', $success_count, $total_count),
                    'Batch Update'
                );
                return true;
            } else {
                \NTWP\Core\Foundation\Logger::warning_log(
                    sprintf('批量更新post_meta部分成功: %d/%d条记录', $success_count, $total_count),
                    'Batch Update'
                );
                return $success_count > 0; // 只要有部分成功就返回true
            }

        } catch (Exception $e) {
            \NTWP\Core\Foundation\Logger::error_log(
                '批量更新post_meta异常: ' . $e->getMessage(),
                'Batch Update'
            );
            return false;
        }
    }

    /**
     * 批量插入文章
     *
     * 使用批量操作提升文章创建效率
     *
     * @since 2.0.0-beta.1
     * @param array $posts_data 文章数据数组
     * @return array 插入结果，包含成功和失败的文章ID
     */
    public static function batch_insert_posts(array $posts_data): array {
        $results = [
            'success' => [],
            'failed' => [],
            'total' => count($posts_data)
        ];

        if (empty($posts_data)) {
            return $results;
        }

        // 检查是否启用性能模式
        $options = get_option('notion_to_wordpress_options', []);
        $performance_mode = $options['enable_performance_mode'] ?? 1;
        $batch_size = $options['batch_size'] ?? 20;

        if (!$performance_mode) {
            // 如果未启用性能模式，使用传统方式逐个插入
            foreach ($posts_data as $post_data) {
                $post_id = wp_insert_post($post_data);
                if (is_wp_error($post_id)) {
                    $results['failed'][] = $post_data;
                } else {
                    $results['success'][] = $post_id;
                }
            }
            return $results;
        }

        // 分批处理
        $chunks = array_chunk($posts_data, $batch_size);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $post_data) {
                $post_id = wp_insert_post($post_data);
                if (is_wp_error($post_id)) {
                    $results['failed'][] = $post_data;
                    \NTWP\Core\Foundation\Logger::error_log(
                        '批量插入文章失败: ' . $post_id->get_error_message(),
                        'Batch Insert'
                    );
                } else {
                    $results['success'][] = $post_id;
                }
            }

            // 在批次之间稍作停顿，避免过载
            if (count($chunks) > 1) {
                usleep(100000); // 0.1秒
            }
        }

        \NTWP\Core\Foundation\Logger::debug_log(
            sprintf('批量插入文章完成: 成功=%d, 失败=%d',
                count($results['success']), count($results['failed'])),
            'Batch Insert'
        );

        return $results;
    }
}
