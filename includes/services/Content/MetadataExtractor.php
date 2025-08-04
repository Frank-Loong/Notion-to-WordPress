<?php
declare(strict_types=1);

namespace NTWP\Services\Content;

/**
 * Notion 元数据提取器类
 *
 * 专门处理 Notion 页面属性到 WordPress 字段的映射转换，包括标题、状态、
 * 分类、标签、自定义字段等。支持多种字段类型和复杂的映射规则。
 *
 * 设计模式：静态工具类
 * - 所有方法均为静态方法，无状态管理
 * - 专注于元数据提取和转换，不涉及业务逻辑
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

class MetadataExtractor {

    /**
     * 默认字段映射配置
     * 
     * @since 2.0.0-beta.1
     * @var array
     */
    private static array $default_field_mapping = [
        'title'          => 'Title,标题',
        'status'         => 'Status,状态',
        'post_type'      => 'Type,类型',
        'date'           => 'Date,日期',
        'excerpt'        => 'Summary,摘要,Excerpt',
        'featured_image' => 'Featured Image,特色图片',
        'categories'     => 'Categories,分类,Category',
        'tags'           => 'Tags,标签,Tag',
        'password'       => 'Password,密码',
    ];

    /**
     * 从Notion页面中提取元数据
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @param array $field_mapping 字段映射配置
     * @param array $custom_field_mappings 自定义字段映射
     * @return array 页面元数据
     */
    public static function extract_page_metadata(array $page, array $field_mapping = [], array $custom_field_mappings = []): array {
        $metadata = [];
        $props = $page['properties'] ?? [];

        // 检测并处理page类型页面
        $is_page_type = ($page['object'] ?? '') === 'page';
        if ($is_page_type) {
            \NTWP\Core\Foundation\Logger::debug_log('检测到page类型页面，使用专门的处理逻辑', 'Metadata Extraction');
            return self::extract_page_type_metadata($page, $field_mapping, $custom_field_mappings);
        }

        // 如果没有提供字段映射，从选项中获取
        if (empty($field_mapping)) {
            $options = get_option('notion_to_wordpress_options', []);
            $field_mapping_saved = $options['field_mapping'] ?? [];
            $field_mapping = array_merge(self::$default_field_mapping, $field_mapping_saved);
        } else {
            // 确保默认字段映射不丢失
            $field_mapping = array_merge(self::$default_field_mapping, $field_mapping);
        }

        // 将逗号分隔的字符串转换为数组（如果还不是数组的话）
        foreach ($field_mapping as $key => $value) {
            if (is_string($value)) {
                $field_mapping[$key] = array_map('trim', explode(',', $value));
            } elseif (!is_array($value)) {
                $field_mapping[$key] = [$value];
            }
            // 如果已经是数组，保持不变
        }

        // 提取基本字段
        $metadata = self::extract_basic_fields($props, $field_mapping);

        // 处理分类和标签（仅在有相关字段映射时）
        if (isset($field_mapping['categories']) || isset($field_mapping['tags'])) {
            $metadata = self::extract_taxonomies($props, $field_mapping, $metadata);
        }
        
        // 处理自定义字段
        if (!empty($custom_field_mappings)) {
            $metadata['custom_fields'] = self::extract_custom_fields($props, $custom_field_mappings);
        }

        return $metadata;
    }

    /**
     * 提取基本字段（标题、状态、类型、日期、摘要、特色图片、密码）
     *
     * @since 2.0.0-beta.1
     * @param array $props Notion页面属性
     * @param array $field_mapping 字段映射
     * @return array 基本字段元数据
     */
    private static function extract_basic_fields(array $props, array $field_mapping): array {
        $metadata = [];

        // 提取标题
        $metadata['title'] = '';
        if (isset($field_mapping['title']) && is_array($field_mapping['title'])) {
            $metadata['title'] = self::get_property_value($props, $field_mapping['title'], 'title', 'plain_text');
        }

        // 提取状态（兼容新版 status 和旧版 select）
        $status_val = '';
        if (isset($field_mapping['status']) && is_array($field_mapping['status'])) {
            $status_val = self::get_property_value($props, $field_mapping['status'], 'select', 'name');
            if (!$status_val) {
                $status_val = self::get_property_value($props, $field_mapping['status'], 'status', 'name');
            }
        }

        // 提取密码字段
        $password_val = '';
        if (isset($field_mapping['password']) && is_array($field_mapping['password'])) {
            $password_val = self::get_property_value($props, $field_mapping['password'], 'rich_text', 'plain_text');
            if (!$password_val) {
                $password_val = self::get_property_value($props, $field_mapping['password'], 'title', 'plain_text');
            }
        }

        // 处理文章状态和密码
        if ($status_val) {
            $metadata['status'] = \NTWP\Infrastructure\Helper::normalize_post_status(trim($status_val));
        } else {
            $metadata['status'] = 'draft';
        }

        // 如果有密码，设置为加密文章
        if (!empty($password_val)) {
            $metadata['password'] = trim($password_val);
            // 有密码的文章应该是已发布状态，否则密码无效
            if ($metadata['status'] === 'draft') {
                $metadata['status'] = 'publish';
            }
        }

        // 记录状态处理日志
        \NTWP\Core\Foundation\Logger::debug_log(
            sprintf('Notion页面状态: %s, 密码: %s, 转换为WordPress状态: %s, 密码保护: %s',
                $status_val ?: 'null',
                !empty($password_val) ? '***' : 'null',
                $metadata['status'],
                !empty($metadata['password']) ? '是' : '否'
            ),
            'Status Processing'
        );

        // 提取文章类型
        $metadata['post_type'] = 'post';
        if (isset($field_mapping['post_type']) && is_array($field_mapping['post_type'])) {
            $metadata['post_type'] = self::get_property_value($props, $field_mapping['post_type'], 'select', 'name') ?? 'post';
        }

        // 提取日期
        $metadata['date'] = '';
        if (isset($field_mapping['date']) && is_array($field_mapping['date'])) {
            $metadata['date'] = self::get_property_value($props, $field_mapping['date'], 'date', 'start');
        }

        // 提取摘要
        $metadata['excerpt'] = '';
        if (isset($field_mapping['excerpt']) && is_array($field_mapping['excerpt'])) {
            $metadata['excerpt'] = self::get_property_value($props, $field_mapping['excerpt'], 'rich_text', 'plain_text');
        }

        // 提取特色图片
        $metadata['featured_image'] = '';
        if (isset($field_mapping['featured_image']) && is_array($field_mapping['featured_image'])) {
            $metadata['featured_image'] = self::get_property_value($props, $field_mapping['featured_image'], 'files', 'url');
        }

        return $metadata;
    }

    /**
     * 提取分类和标签
     *
     * @since 2.0.0-beta.1
     * @param array $props Notion页面属性
     * @param array $field_mapping 字段映射
     * @param array $metadata 现有元数据
     * @return array 包含分类标签的元数据
     */
    private static function extract_taxonomies(array $props, array $field_mapping, array $metadata): array {
        // 处理分类
        if (isset($field_mapping['categories']) && is_array($field_mapping['categories'])) {
            $categories_prop = self::get_property_value($props, $field_mapping['categories'], 'multi_select');
            if ($categories_prop) {
                $categories = [];
                foreach ($categories_prop as $category) {
                    // 检查WordPress分类函数是否可用
                    if (!function_exists('get_term_by') || !function_exists('wp_create_term')) {
                        // 在测试环境中，直接使用分类名称
                        $categories[] = $category['name'];
                        continue;
                    }

                    $term = get_term_by('name', $category['name'], 'category');
                    if (!$term) {
                        $term_data = wp_create_term($category['name'], 'category');
                        if (!is_wp_error($term_data)) {
                            $categories[] = $term_data['term_id'];
                        }
                    } else {
                        $categories[] = $term->term_id;
                    }
                }
                $metadata['categories'] = array_filter($categories);
            }
        }

        // 处理标签
        if (isset($field_mapping['tags']) && is_array($field_mapping['tags'])) {
            $tags_prop = self::get_property_value($props, $field_mapping['tags'], 'multi_select');
            if ($tags_prop) {
                $tags = [];
                foreach ($tags_prop as $tag) {
                    $tags[] = $tag['name'];
                }
                $metadata['tags'] = $tags;
            }
        }

        return $metadata;
    }

    /**
     * 提取自定义字段
     *
     * @since 2.0.0-beta.1
     * @param array $props Notion页面属性
     * @param array $custom_field_mappings 自定义字段映射配置
     * @return array 自定义字段数据
     */
    private static function extract_custom_fields(array $props, array $custom_field_mappings): array {
        $custom_fields = [];

        foreach ($custom_field_mappings as $mapping) {
            $notion_property = $mapping['notion_property'] ?? '';
            $wp_field = $mapping['wp_field'] ?? '';
            $field_type = $mapping['field_type'] ?? 'text';

            if (empty($notion_property) || empty($wp_field)) {
                continue;
            }

            // 将Notion属性名转换为数组
            $property_names = array_map('trim', explode(',', $notion_property));

            // 根据字段类型获取属性值
            $value = self::extract_custom_field_value($props, $property_names, $field_type);

            if ($value !== null) {
                $custom_fields[$wp_field] = $value;
            }
        }

        return $custom_fields;
    }

    /**
     * 根据字段类型提取自定义字段值
     *
     * @since 2.0.0-beta.1
     * @param array $props Notion页面属性
     * @param array $property_names 属性名称数组
     * @param string $field_type 字段类型
     * @return mixed 字段值
     */
    private static function extract_custom_field_value(array $props, array $property_names, string $field_type) {
        $value = null;

        switch ($field_type) {
            case 'text':
                $value = self::get_property_value($props, $property_names, 'rich_text', 'plain_text');
                break;

            case 'number':
                $value = self::get_property_value($props, $property_names, 'number');
                break;

            case 'date':
                $value = self::get_property_value($props, $property_names, 'date', 'start');
                break;

            case 'checkbox':
                $value = self::get_property_value($props, $property_names, 'checkbox');
                break;

            case 'select':
                $value = self::get_property_value($props, $property_names, 'select', 'name');
                break;

            case 'multi_select':
                $multi_select_values = self::get_property_value($props, $property_names, 'multi_select');
                if ($multi_select_values) {
                    $value = array_map(function($item) {
                        return $item['name'];
                    }, $multi_select_values);
                    $value = implode(',', $value);
                }
                break;

            case 'url':
                $value = self::get_property_value($props, $property_names, 'url');
                break;

            case 'email':
                $value = self::get_property_value($props, $property_names, 'email');
                break;

            case 'phone':
                $value = self::get_property_value($props, $property_names, 'phone_number');
                break;

            case 'rich_text':
                $rich_text = self::get_property_value($props, $property_names, 'rich_text');
                if ($rich_text) {
                    $value = self::extract_rich_text($rich_text);
                }
                break;
        }

        return $value;
    }

    // ==================== 核心属性值提取方法 ====================

    /**
     * 从属性列表中安全地获取一个值
     *
     * @since 2.0.0-beta.1
     * @param array $props 属性列表
     * @param array $names 可能的属性名称
     * @param string $type 属性类型 (e.g., 'title', 'select', 'url')
     * @param string|null $key 如果是嵌套数组，需要提取的键名
     * @param mixed $default 默认值
     * @return mixed
     */
    private static function get_property_value(array $props, array $names, string $type, string $key = null, $default = null) {
        foreach ($names as $name) {
            // 首先尝试精确匹配
            if (isset($props[$name][$type])) {
                $prop = $props[$name][$type];
                return self::extract_property_value($prop, $type, $key, $default);
            }

            // 如果精确匹配失败，尝试大小写不敏感匹配
            foreach ($props as $prop_name => $prop_data) {
                if (strcasecmp($prop_name, $name) === 0 && isset($prop_data[$type])) {
                    $prop = $prop_data[$type];
                    return self::extract_property_value($prop, $type, $key, $default);
                }
            }
        }
        return $default;
    }

    /**
     * 从属性值中提取具体数据
     *
     * @since 2.0.0-beta.1
     * @param mixed $prop 属性值
     * @param string $type 属性类型
     * @param string|null $key 要提取的键名
     * @param mixed $default 默认值
     * @return mixed
     */
    private static function extract_property_value($prop, string $type, string $key = null, $default = null) {
        if ('url' === $key && 'files' === $type) { // 特殊处理文件URL
            return $prop[0]['file']['url'] ?? $prop[0]['external']['url'] ?? $default;
        }

        if ($key) {
            // 先检查属性本身是否直接包含所需键（例如 select、date 等关联数组）
            if (is_array($prop) && isset($prop[$key])) {
                return $prop[$key];
            }

            // 再检查类似 title、rich_text 这类列表结构的第一个元素
            if (is_array($prop) && isset($prop[0][$key])) {
                return $prop[0][$key];
            }
        } else {
            return $prop;
        }

        return $default;
    }

    /**
     * 从富文本数组中提取HTML内容
     *
     * @since 2.0.0-beta.1
     * @param array $rich_text 富文本数组
     * @return string HTML内容
     */
    private static function extract_rich_text(array $rich_text): string {
        $html = '';

        foreach ($rich_text as $text_item) {
            $content = $text_item['plain_text'] ?? '';

            if (!empty($content)) {
                // 处理格式化
                if (isset($text_item['annotations'])) {
                    $annotations = $text_item['annotations'];

                    if ($annotations['bold'] ?? false) {
                        $content = '<strong>' . $content . '</strong>';
                    }
                    if ($annotations['italic'] ?? false) {
                        $content = '<em>' . $content . '</em>';
                    }
                    if ($annotations['strikethrough'] ?? false) {
                        $content = '<del>' . $content . '</del>';
                    }
                    if ($annotations['underline'] ?? false) {
                        $content = '<u>' . $content . '</u>';
                    }
                    if ($annotations['code'] ?? false) {
                        $content = '<code>' . $content . '</code>';
                    }
                }

                // 处理链接
                if (isset($text_item['href'])) {
                    $content = '<a href="' . esc_url($text_item['href']) . '">' . $content . '</a>';
                }

                $html .= $content;
            }
        }

        return $html;
    }

    /**
     * 专门处理page类型页面的元数据提取
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @param array $field_mapping 字段映射配置
     * @param array $custom_field_mappings 自定义字段映射
     * @return array 页面元数据
     */
    private static function extract_page_type_metadata(array $page, array $field_mapping = [], array $custom_field_mappings = []): array {
        $metadata = [
            'title' => self::extract_page_title($page),
            'status' => 'publish', // page类型默认为已发布
            'post_type' => 'post',
            'date' => $page['created_time'] ?? '',
            'excerpt' => '',
            'featured_image' => '',
            'categories' => [],
            'tags' => [],
        ];

        // 尝试从properties中提取额外信息（如果存在）
        $props = $page['properties'] ?? [];
        if (!empty($props)) {
            // 合并字段映射配置
            if (empty($field_mapping)) {
                $options = get_option('notion_to_wordpress_options', []);
                $field_mapping_saved = $options['field_mapping'] ?? [];
                $field_mapping = array_merge(self::$default_field_mapping, $field_mapping_saved);
            }

            // 标准化字段映射
            foreach ($field_mapping as $key => $value) {
                if (is_string($value)) {
                    $field_mapping[$key] = array_map('trim', explode(',', $value));
                }
            }

            // 尝试提取额外的元数据
            $additional_metadata = self::extract_basic_fields($props, $field_mapping);

            // 保留page类型的标题，但合并其他字段
            $page_title = $metadata['title'];
            $metadata = array_merge($metadata, $additional_metadata);
            if (empty($additional_metadata['title'])) {
                $metadata['title'] = $page_title;
            }
        }

        // 处理自定义字段
        if (!empty($custom_field_mappings) && !empty($props)) {
            $metadata['custom_fields'] = self::extract_custom_fields($props, $custom_field_mappings);
        }

        \NTWP\Core\Foundation\Logger::debug_log(
            sprintf('Page类型元数据提取完成，标题: %s', $metadata['title']),
            'Metadata Extraction'
        );

        return $metadata;
    }

    /**
     * 从page类型页面中提取标题
     *
     * @since 2.0.0-beta.1
     * @param array $page Notion页面数据
     * @return string 页面标题
     */
    private static function extract_page_title(array $page): string {
        // 方法1：尝试从properties.title中提取
        if (isset($page['properties']['title']['title'][0]['plain_text'])) {
            return trim($page['properties']['title']['title'][0]['plain_text']);
        }

        // 方法2：尝试从properties.Name中提取（某些page可能使用Name字段）
        if (isset($page['properties']['Name']['title'][0]['plain_text'])) {
            return trim($page['properties']['Name']['title'][0]['plain_text']);
        }

        // 方法3：尝试从根级别的title字段提取
        if (isset($page['title']) && is_string($page['title'])) {
            return trim($page['title']);
        }

        // 方法4：如果都没有，使用页面URL作为标题
        if (isset($page['url'])) {
            $url_parts = parse_url($page['url']);
            $path = $url_parts['path'] ?? '';
            $title = basename($path);
            if (!empty($title) && $title !== '/') {
                return ucfirst(str_replace(['-', '_'], ' ', $title));
            }
        }

        // 最后的备选方案：使用页面ID的后8位
        $page_id = $page['id'] ?? 'unknown';
        return sprintf('Page %s', substr($page_id, -8));
    }
}
