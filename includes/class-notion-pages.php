<?php
declare(strict_types=1);

/**
 * 负责处理Notion页面转换和导入的类
 *
 * @since      1.0.9
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */
// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

class Notion_Pages {

    /**
     * 存储已导入的块ID，防止重复处理
     *
     * @since    1.0.5
     * @access   private
     * @var      array    $processed_blocks    已处理的块ID
     */
    private array $processed_blocks = [];
    
    /**
     * Notion API实例
     *
     * @since    1.0.8
     * @access   private
     * @var      Notion_API    $notion_api    Notion API实例
     */
    private Notion_API $notion_api;
    
    /**
     * 数据库ID
     *
     * @since    1.0.8
     * @access   private
     * @var      string    $database_id    Notion数据库ID
     */
    private string $database_id;
    
    /**
     * 字段映射
     *
     * @since    1.0.8
     * @access   private
     * @var      array    $field_mapping    字段映射配置
     */
    private array $field_mapping;

    /**
     * 自定义字段映射
     *
     * @since    1.1.0
     * @access   private
     * @var      array    $custom_field_mappings    自定义字段映射配置
     */
    private array $custom_field_mappings = [];

    /**
     * 构造函数
     *
     * @since    1.0.8
     * @param    Notion_API    $notion_api     Notion API实例
     * @param    string        $database_id    数据库ID
     * @param    array         $field_mapping  字段映射
     */
    public function __construct(Notion_API $notion_api, string $database_id, array $field_mapping = []) {
        $this->notion_api = $notion_api;
        $this->database_id = $database_id;
        $this->field_mapping = $field_mapping;
    }

    /**
     * 设置自定义字段映射
     *
     * @since    1.1.0
     * @param    array    $mappings    自定义字段映射数组
     */
    public function set_custom_field_mappings(array $mappings) {
        $this->custom_field_mappings = $mappings;
    }

    /**
     * 从Notion页面导入到WordPress
     *
     * @since    1.0.5
     * @param    array     $page         Notion页面数据
     * @param    object    $notion_api   Notion API实例
     * @return   boolean                 导入是否成功
     */
    public function import_notion_page(array $page): bool {
        Notion_To_WordPress_Helper::debug_log('import_notion_page() 开始执行', 'Page Import');

        if (empty($page) || !isset($page['id'])) {
            Notion_To_WordPress_Helper::error_log('页面数据为空或缺少ID', 'Page Import');
            return false;
        }

        $page_id  = $page['id'];
        error_log('Notion to WordPress: 处理页面ID: ' . $page_id);

        error_log('Notion to WordPress: 提取页面元数据...');
        $metadata = $this->extract_page_metadata($page);
        error_log('Notion to WordPress: 元数据提取完成，标题: ' . ($metadata['title'] ?? 'unknown'));
        error_log('Notion to WordPress: 元数据详情: ' . print_r($metadata, true));

        if (empty($metadata['title'])) {
            error_log('Notion to WordPress: 页面标题为空，跳过导入');
            return false;
        }

        // 获取页面内容
        error_log('Notion to WordPress: 获取页面内容...');
        $blocks = $this->notion_api->get_page_content($page_id);
        error_log('Notion to WordPress: 获取到内容块数量: ' . count($blocks));
        if (empty($blocks)) {
            return false;
        }

        // 转换内容为 HTML 并做 KSES 过滤
        $raw_content = $this->convert_blocks_to_html($blocks, $this->notion_api);

        $content     = Notion_To_WordPress_Helper::custom_kses($raw_content);
        
        $existing_post_id = $this->get_post_by_notion_id($page_id);

        // 获取文章作者
        $author_id = $this->get_author_id();

        // 创建或更新文章
        $post_id = $this->create_or_update_post($metadata, $content, $author_id, $page_id, $existing_post_id);

        if (is_wp_error($post_id)) {
            return false;
        }

        // 分类 / 标签 / 特色图
        $this->apply_taxonomies($post_id, $metadata);
        $this->apply_featured_image($post_id, $metadata);

        // 更新同步时间戳
        $notion_last_edited = $page['last_edited_time'] ?? '';
        if ($notion_last_edited) {
            $this->update_page_sync_time($page_id, $notion_last_edited);
        }

        return true;
    }

    /**
     * 从Notion页面中提取元数据
     *
     * @since    1.0.5
     * @param    array     $page    Notion页面数据
     * @return   array              页面元数据
     */
    private function extract_page_metadata($page) {
        $metadata = [];
        $props    = $page['properties'] ?? [];

        // 获取保存的选项，包括字段映射
        $options = get_option( 'notion_to_wordpress_options', [] );

        /*
         * 默认字段映射。即使用户在后台保存设置时遗漏了某些字段（例如密码映射），
         * 依旧可以通过与默认值合并来保证关键字段存在，避免导入过程出错。
         */
        $default_mapping = [
            'title'          => 'Title,标题',
            'status'         => 'Status,状态',
            'post_type'      => 'Type,类型',
            'date'           => 'Date,日期',
            'excerpt'        => 'Summary,摘要,Excerpt',
            'featured_image' => 'Featured Image,特色图片',
            'categories'     => 'Categories,分类,Category',
            'tags'           => 'Tags,标签,Tag',
            // 新增：密码字段映射，支持密码保护文章
            'password'       => 'Password,密码',
        ];

        // 合并用户自定义映射，保持默认键不丢失
        $field_mapping_saved = $options['field_mapping'] ?? [];
        $field_mapping       = array_merge( $default_mapping, $field_mapping_saved );

        // 将逗号分隔的字符串转换为数组
        foreach ( $field_mapping as $key => $value ) {
            $field_mapping[ $key ] = array_map( 'trim', explode( ',', $value ) );
        }

        $metadata['title'] = '';
        if (isset($field_mapping['title']) && is_array($field_mapping['title'])) {
            $metadata['title'] = $this->get_property_value( $props, $field_mapping['title'], 'title', 'plain_text' );
        }

        // 兼容新版 Notion "Status" 属性（类型为 status）以及旧版 select
        $status_val = '';
        if (isset($field_mapping['status']) && is_array($field_mapping['status'])) {
            $status_val = $this->get_property_value( $props, $field_mapping['status'], 'select', 'name' );
            if ( ! $status_val ) {
                $status_val = $this->get_property_value( $props, $field_mapping['status'], 'status', 'name' );
            }
        }

        // 获取密码字段
        $password_val = '';
        if (isset($field_mapping['password']) && is_array($field_mapping['password'])) {
            $password_val = $this->get_property_value( $props, $field_mapping['password'], 'rich_text', 'plain_text' );
            if ( ! $password_val ) {
                // 也尝试从其他类型获取密码
                $password_val = $this->get_property_value( $props, $field_mapping['password'], 'title', 'plain_text' );
            }
        }

        // 处理文章状态和密码
        if ( $status_val ) {
            // 使用规范化函数来统一处理状态映射
            $metadata['status'] = Notion_To_WordPress_Helper::normalize_post_status(trim($status_val));
        } else {
            $metadata['status'] = 'draft';
        }

        // 如果有密码，设置为加密文章
        if ( !empty($password_val) ) {
            $metadata['password'] = trim($password_val);
            // 有密码的文章应该是已发布状态，否则密码无效
            if ( $metadata['status'] === 'draft' ) {
                $metadata['status'] = 'publish';
            }
        }

        // 添加详细调试日志
        Notion_To_WordPress_Helper::debug_log(
            sprintf('Notion页面状态: %s, 密码: %s, 转换为WordPress状态: %s, 密码保护: %s',
                $status_val ?: 'null',
                !empty($password_val) ? '***' : 'null',
                $metadata['status'],
                !empty($metadata['password']) ? '是' : '否'
            ),
            'Notion Status',
            Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
        );

        $metadata['post_type'] = 'post';
        if (isset($field_mapping['post_type']) && is_array($field_mapping['post_type'])) {
            $metadata['post_type'] = $this->get_property_value( $props, $field_mapping['post_type'], 'select', 'name' ) ?? 'post';
        }

        $metadata['date'] = '';
        if (isset($field_mapping['date']) && is_array($field_mapping['date'])) {
            $metadata['date'] = $this->get_property_value( $props, $field_mapping['date'], 'date', 'start' );
        }

        $metadata['excerpt'] = '';
        if (isset($field_mapping['excerpt']) && is_array($field_mapping['excerpt'])) {
            $metadata['excerpt'] = $this->get_property_value( $props, $field_mapping['excerpt'], 'rich_text', 'plain_text' );
        }

        $metadata['featured_image'] = '';
        if (isset($field_mapping['featured_image']) && is_array($field_mapping['featured_image'])) {
            $metadata['featured_image'] = $this->get_property_value( $props, $field_mapping['featured_image'], 'files', 'url' );
        }
        


        // 处理分类和标签
        if (isset($field_mapping['categories']) && is_array($field_mapping['categories'])) {
            $categories_prop = $this->get_property_value( $props, $field_mapping['categories'], 'multi_select' );
            if ( $categories_prop ) {
                $categories = [];
                foreach ( $categories_prop as $category ) {
                    $term = get_term_by( 'name', $category['name'], 'category' );
                    if ( ! $term ) {
                        $term_data = wp_create_term( $category['name'], 'category' );
                        if ( ! is_wp_error( $term_data ) ) {
                            $categories[] = $term_data['term_id'];
                        }
                    } else {
                        $categories[] = $term->term_id;
                    }
                }
                $metadata['categories'] = array_filter( $categories );
            }
        }

        if (isset($field_mapping['tags']) && is_array($field_mapping['tags'])) {
            $tags_prop = $this->get_property_value( $props, $field_mapping['tags'], 'multi_select' );
            if ( $tags_prop ) {
                $tags = [];
                foreach ( $tags_prop as $tag ) {
                    $tags[] = $tag['name'];
                }
                $metadata['tags'] = $tags;
            }
        }
        
        // 处理自定义字段映射
        $custom_field_mappings = $this->custom_field_mappings ?? [];
        if (!empty($custom_field_mappings)) {
            $metadata['custom_fields'] = [];
            
            foreach ($custom_field_mappings as $mapping) {
                $notion_property = $mapping['notion_property'];
                $wp_field = $mapping['wp_field'];
                $field_type = $mapping['field_type'];
                
                if (empty($notion_property) || empty($wp_field)) {
                    continue;
                }
                
                // 将Notion属性名转换为数组
                $property_names = array_map('trim', explode(',', $notion_property));
                
                // 根据字段类型获取属性值
                $value = null;
                
                switch ($field_type) {
                    case 'text':
                        $value = $this->get_property_value($props, $property_names, 'rich_text', 'plain_text');
                        break;
                        
                    case 'number':
                        $value = $this->get_property_value($props, $property_names, 'number');
                        break;
                        
                    case 'date':
                        $value = $this->get_property_value($props, $property_names, 'date', 'start');
                        break;
                        
                    case 'checkbox':
                        $value = $this->get_property_value($props, $property_names, 'checkbox');
                        break;
                        
                    case 'select':
                        $value = $this->get_property_value($props, $property_names, 'select', 'name');
                        break;
                        
                    case 'multi_select':
                        $multi_select_values = $this->get_property_value($props, $property_names, 'multi_select');
                        if ($multi_select_values) {
                            $value = array_map(function($item) {
                                return $item['name'];
                            }, $multi_select_values);
                            $value = implode(',', $value);
                        }
                        break;
                        
                    case 'url':
                        $value = $this->get_property_value($props, $property_names, 'url');
                        break;
                        
                    case 'email':
                        $value = $this->get_property_value($props, $property_names, 'email');
                        break;
                        
                    case 'phone':
                        $value = $this->get_property_value($props, $property_names, 'phone_number');
                        break;
                        
                    case 'rich_text':
                        $rich_text = $this->get_property_value($props, $property_names, 'rich_text');
                        if ($rich_text) {
                            $value = $this->extract_rich_text($rich_text);
                        }
                        break;
                }
                
                if ($value !== null) {
                    $metadata['custom_fields'][$wp_field] = $value;
                }
            }
        }

        return $metadata;
    }

    /**
     * 从属性列表中安全地获取一个值
     *
     * @since 1.0.5
     * @access private
     * @param array $props 属性列表
     * @param array $names 可能的属性名称
     * @param string $type 属性类型 (e.g., 'title', 'select', 'url')
     * @param string|null $key 如果是嵌套数组，需要提取的键名
     * @param mixed $default 默认值
     * @return mixed
     */
    private function get_property_value(array $props, array $names, string $type, string $key = null, $default = null) {
        foreach ($names as $name) {
            // 首先尝试精确匹配
            if (isset($props[$name][$type])) {
                $prop = $props[$name][$type];
                return $this->extract_property_value($prop, $type, $key, $default);
            }

            // 如果精确匹配失败，尝试大小写不敏感匹配
            foreach ($props as $prop_name => $prop_data) {
                if (strcasecmp($prop_name, $name) === 0 && isset($prop_data[$type])) {
                    $prop = $prop_data[$type];
                    return $this->extract_property_value($prop, $type, $key, $default);
                }
            }
        }
        return $default;
    }

    /**
     * 从属性值中提取具体数据
     *
     * @since 1.0.9
     * @param mixed $prop 属性值
     * @param string $type 属性类型
     * @param string|null $key 要提取的键名
     * @param mixed $default 默认值
     * @return mixed
     */
    private function extract_property_value($prop, string $type, string $key = null, $default = null) {
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
     * 将Notion块转换为HTML
     *
     * @since    1.0.5
     * @param    array     $blocks       Notion块数据
     * @param    Notion_API $notion_api   Notion API实例
     * @return   string                  HTML内容
     */
    private function convert_blocks_to_html(array $blocks, Notion_API $notion_api): string {
        $html = '';
        $list_wrapper = null;

        foreach ($blocks as $block) {
            if (in_array($block['id'], $this->processed_blocks)) {
                continue;
            }
            $this->processed_blocks[] = $block['id'];

            $block_type = $block['type'];
            $converter_method = '_convert_block_' . $block_type;

            // -------- 列表块处理（含待办 to_do） --------
            $is_standard_list_item = in_array($block_type, ['bulleted_list_item', 'numbered_list_item']);
            $is_todo_item         = ($block_type === 'to_do');
            $is_list_item         = $is_standard_list_item || $is_todo_item;

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

            if (method_exists($this, $converter_method)) {
                try {
                    // 尝试转换块
                    $block_html = $this->{$converter_method}($block, $notion_api);

                    // 为所有区块添加 ID 包装，支持锚点跳转
                    // 注意：列表项也需要 ID 以支持锚点跳转
                    $block_html = $this->wrap_block_with_id($block_html, $block['id'], $block_type);

                    $html .= $block_html;

                    // 特别记录数据库区块的成功转换
                    if ($block_type === 'child_database') {
                        Notion_To_WordPress_Helper::info_log(
                            '数据库区块转换成功: ' . ($block['id'] ?? 'unknown'),
                            'Database Block'
                        );
                    }

                    // 检查是否有子块（数据库区块不处理子块）
                    if (isset($block['has_children']) && $block['has_children'] && !$is_list_item && $block_type !== 'child_database') {
                        $html .= $this->_convert_child_blocks($block, $notion_api);
                    }
                } catch (Exception $e) {
                    // 记录错误并添加注释
                    if ($block_type === 'child_database') {
                        Notion_To_WordPress_Helper::error_log(
                            '数据库区块转换失败: ' . ($block['id'] ?? 'unknown') . ', 错误: ' . $e->getMessage(),
                            'Database Block'
                        );
                    } else {
                        Notion_To_WordPress_Helper::error_log('Notion块转换错误: ' . $e->getMessage(), 'Block Convert');
                    }
                    $html .= '<!-- 块转换错误: ' . esc_html($block_type) . ' -->';
                }
            } else {
                // 未知块类型，添加调试注释
                if ($block_type === 'child_database') {
                    Notion_To_WordPress_Helper::error_log(
                        '数据库区块转换方法不存在: ' . ($block['id'] ?? 'unknown'),
                        'Database Block'
                    );
                } else {
                    Notion_To_WordPress_Helper::debug_log('未支持的Notion块类型: ' . $block_type, 'Block Convert');
                }
                $html .= '<!-- 未支持的块类型: ' . esc_html($block_type) . ' -->';
            }
        }

        // 确保所有列表都正确关闭
        if ($list_wrapper !== null) {
            $html .= ($list_wrapper === 'todo') ? '</ul>' : '</' . $list_wrapper . '>';
        }

        return $html;
    }
    
    /**
     * 递归获取并转换子块
     */
    private function _convert_child_blocks(array $block, Notion_API $notion_api): string {
        if ( ! ( $block['has_children'] ?? false ) ) {
            return '';
        }

        // 特殊处理：数据库区块不尝试获取子内容，避免API 404错误
        if ( $block['type'] === 'child_database' ) {
            Notion_To_WordPress_Helper::debug_log(
                '跳过数据库区块子内容获取: ' . ($block['id'] ?? 'unknown'),
                'Database Block'
            );
            return '';
        }

        // 优先使用已递归获取的 children，避免重复调用 API
        if ( isset( $block['children'] ) && is_array( $block['children'] ) ) {
            $child_blocks = $block['children'];
        } else {
            $child_blocks = $notion_api->get_page_content( $block['id'] );
        }

        return ! empty( $child_blocks ) ? $this->convert_blocks_to_html( $child_blocks, $notion_api ) : '';
    }

    /**
     * 为区块添加唯一 ID 包装，支持锚点跳转
     *
     * @since    1.1.1
     * @param    string    $block_html    区块的 HTML 内容
     * @param    string    $block_id      区块的唯一 ID
     * @param    string    $block_type    区块类型
     * @return   string                   包装后的 HTML
     */
    private function wrap_block_with_id(string $block_html, string $block_id, string $block_type): string {
        // 调试日志：记录函数调用
        Notion_To_WordPress_Helper::info_log("包装区块 ID: $block_id, 类型: $block_type");

        // 确保 ID 和类名安全
        $safe_id = esc_attr('notion-block-' . $block_id);
        $safe_type = esc_attr($block_type);

        // 对于列表项，直接在 <li> 元素上添加 ID，避免破坏列表结构
        if (in_array($block_type, ['bulleted_list_item', 'numbered_list_item', 'to_do'])) {
            // 检查是否已有 class 属性
            if (preg_match('/^<li\s+class="([^"]*)"([^>]*)>/', $block_html, $matches)) {
                // 已有 class，合并类名
                $existing_class = $matches[1];
                $other_attrs = $matches[2];
                $new_class = 'notion-block notion-' . $safe_type . ' ' . $existing_class;
                return '<li id="' . $safe_id . '" class="' . $new_class . '"' . $other_attrs . '>' .
                       substr($block_html, strlen($matches[0]));
            } else {
                // 没有 class，直接添加
                $pattern = '/^<li(\s[^>]*)?>/';
                $replacement = '<li id="' . $safe_id . '" class="notion-block notion-' . $safe_type . '"$1>';
                return preg_replace($pattern, $replacement, $block_html);
            }
        }

        // 对于已有合适容器的区块，直接在现有 div 上添加 ID，避免双层嵌套
        if (in_array($block_type, ['callout', 'bookmark', 'toggle', 'equation', 'child_database', 'child_page', 'column', 'column_list'])) {
            // 查找第一个 <div> 标签并添加 ID
            if (preg_match('/^<div\s+class="([^"]*)"([^>]*)>/', $block_html, $matches)) {
                // 已有 class 属性
                $existing_class = $matches[1];
                $other_attrs = $matches[2];

                // 构建新的类名，避免重复
                $classes = array_filter(array_unique(array_merge(
                    ['notion-block', 'notion-' . $safe_type],
                    explode(' ', $existing_class)
                )));
                $new_class = implode(' ', $classes);

                $replacement = '<div id="' . $safe_id . '" class="' . $new_class . '"' . $other_attrs . '>';
                return preg_replace('/^<div\s+class="[^"]*"[^>]*>/', $replacement, $block_html);
            } elseif (preg_match('/^<div([^>]*)>/', $block_html, $matches)) {
                // 没有 class 属性
                $other_attrs = $matches[1];
                $replacement = '<div id="' . $safe_id . '" class="notion-block notion-' . $safe_type . '"' . $other_attrs . '>';
                return preg_replace('/^<div[^>]*>/', $replacement, $block_html);
            }
        }

        // 对于其他区块，使用 div 包装
        $wrapped_html = '<div id="' . $safe_id . '" class="notion-block notion-' . $safe_type . '">' . $block_html . '</div>';
        Notion_To_WordPress_Helper::info_log("区块包装完成: $block_id -> " . substr($wrapped_html, 0, 100) . "...");
        return $wrapped_html;
    }

    // --- Block Converters ---

    private function _convert_block_paragraph(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['paragraph']['rich_text']);
        $html = empty($text) ? '<p>&nbsp;</p>' : '<p>' . $text . '</p>';
        $html .= $this->_convert_child_blocks($block, $notion_api);
        return $html;
    }

    private function _convert_block_heading_1(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['heading_1']['rich_text']);
        return '<h1>' . $text . '</h1>' . $this->_convert_child_blocks($block, $notion_api);
    }
    
    private function _convert_block_heading_2(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['heading_2']['rich_text']);
        return '<h2>' . $text . '</h2>' . $this->_convert_child_blocks($block, $notion_api);
    }

    private function _convert_block_heading_3(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['heading_3']['rich_text']);
        return '<h3>' . $text . '</h3>' . $this->_convert_child_blocks($block, $notion_api);
    }

    private function _convert_block_bulleted_list_item(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['bulleted_list_item']['rich_text']);
        return '<li>' . $text . $this->_convert_child_blocks($block, $notion_api) . '</li>';
    }

    private function _convert_block_numbered_list_item(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['numbered_list_item']['rich_text']);
        return '<li>' . $text . $this->_convert_child_blocks($block, $notion_api) . '</li>';
    }

    private function _convert_block_to_do(array $block, Notion_API $notion_api): string {
        $text    = $this->extract_rich_text($block['to_do']['rich_text']);
        $checked = isset($block['to_do']['checked']) && $block['to_do']['checked'] ? ' checked' : '';

        // 构建列表项，包含 checkbox 与文本
        $html  = '<li class="notion-to-do">';
        $html .= '<input type="checkbox"' . $checked . ' disabled>'; // 仅展示，不可改动
        $html .= '<span class="notion-to-do-text">' . $text . '</span>';

        // 递归处理子块（支持多级待办）
        $html .= $this->_convert_child_blocks($block, $notion_api);
        $html .= '</li>';

        return $html;
    }
    
    private function _convert_block_toggle(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['toggle']['rich_text']);
        return '<details class="notion-toggle"><summary>' . $text . '</summary>' . $this->_convert_child_blocks($block, $notion_api) . '</details>';
    }

    private function _convert_block_child_page(array $block, Notion_API $notion_api): string {
        $title = $block['child_page']['title'];
        return '<div class="notion-child-page"><span>' . esc_html($title) . '</span></div>';
    }

    private function _convert_block_child_database(array $block, Notion_API $notion_api): string {
        $database_title = $block['child_database']['title'] ?? '未命名数据库';
        $database_id = $block['id'];

        // 记录数据库区块处理开始
        Notion_To_WordPress_Helper::debug_log(
            '开始处理数据库区块: ' . $database_id . ', 标题: ' . $database_title,
            'Database Block'
        );

        $html = '<div class="notion-child-database">';
        $html .= '<div class="notion-database-header">';
        $html .= '<h4 class="notion-database-title">' . esc_html($database_title) . '</h4>';

        // 尝试获取数据库详细信息
        try {
            $database_info = $notion_api->get_database_info($database_id);
            if (!empty($database_info)) {
                Notion_To_WordPress_Helper::info_log(
                    '数据库信息获取成功: ' . $database_id . ', 属性数量: ' . count($database_info['properties'] ?? []),
                    'Database Block'
                );
                $html .= $this->render_database_properties($database_info);

                // 尝试获取并显示数据库记录预览
                $html .= $this->render_database_preview_records($database_id, $database_info, $notion_api);
            } else {
                Notion_To_WordPress_Helper::debug_log(
                    '数据库信息为空，可能是权限问题: ' . $database_id,
                    'Database Block'
                );
                $html .= '<p class="notion-database-fallback">数据库内容需要在Notion中查看</p>';
            }
        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log(
                '数据库区块处理异常: ' . $database_id . ', 错误: ' . $e->getMessage(),
                'Database Block'
            );
            $html .= '<p class="notion-database-fallback">数据库内容暂时无法加载</p>';
        }

        $html .= '</div></div>';

        Notion_To_WordPress_Helper::debug_log(
            '数据库区块处理完成: ' . $database_id,
            'Database Block'
        );

        return $html;
    }

    private function _convert_block_image(array $block, Notion_API $notion_api): string {
        $image_data = $block['image'];
        $type       = $image_data['type'] ?? 'external';
        $url        = '';

        if ($type === 'file') {
            $url = $image_data['file']['url'] ?? '';
        } else { // external
            $url = $image_data['external']['url'] ?? '';
        }

        $caption = $this->extract_rich_text($image_data['caption'] ?? []);

        if (empty($url)) {
            return '<!-- Empty image URL -->';
        }

        // 非 Notion 临时受签名保护的图片直接引用
        if ( ! $this->is_notion_temp_url( $url ) ) {
            return '<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $caption ) . '"><figcaption>' . esc_html( $caption ) . '</figcaption></figure>';
        }

        // Notion 临时链接 —— 尝试下载到媒体库
        $attachment_id = $this->download_and_insert_image( $url, $caption );

        if ( is_numeric( $attachment_id ) && $attachment_id > 0 ) {
            return '<figure class="wp-block-image size-large"><img src="' . esc_url( wp_get_attachment_url( $attachment_id ) ) . '" alt="' . esc_attr( $caption ) . '"><figcaption>' . esc_html( $caption ) . '</figcaption></figure>';
        }

        // 下载失败：直接使用原始 Notion URL，并提示可能过期
        $notice = esc_html__( '此为 Notion 临时图片链接，可能会过期。请考虑替换为图床或本地媒体库图片。', 'notion-to-wordpress' );
        $figcaption = $caption ? esc_html( $caption ) . ' - ' . $notice : $notice;

        return '<figure class="wp-block-image size-large notion-temp-image"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $caption ) . '"><figcaption>' . $figcaption . '</figcaption></figure>';
    }

    /**
     * 判断图片 URL 是否为 Notion 临时受签名保护资源，需要下载到本地
     */
    private function is_notion_temp_url( string $url ): bool {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) {
            return false;
        }
        $notion_hosts = [
            'secure.notion-static.com',
            'www.notion.so',
            'prod-files-secure.s3.us-west-2.amazonaws.com',
            'prod-files-secure.s3.amazonaws.com',
        ];
        foreach ( $notion_hosts as $nh ) {
            if ( str_contains( $host, $nh ) ) {
                return true;
            }
        }
        return false;
    }

    private function _convert_block_code(array $block, Notion_API $notion_api): string {
        $language = strtolower($block['code']['language'] ?? 'text');
        
        // 特殊处理Mermaid图表
        if ($language === 'mermaid') {
            $raw_code = Notion_To_WordPress_Helper::get_text_from_rich_text($block['code']['rich_text']);
            // Mermaid代码不应该被HTML转义
            return '<pre class="mermaid">' . $raw_code . '</pre>';
        }
        
        // 对于其他代码，正常提取并转义
        $escaped_code = $this->extract_rich_text($block['code']['rich_text']);
        
        return "<pre><code class=\"language-{$language}\">{$escaped_code}</code></pre>";
    }

    private function _convert_block_quote(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['quote']['rich_text']);
        return '<blockquote>' . $text . '</blockquote>';
    }

    private function _convert_block_divider(array $block, Notion_API $notion_api): string {
        return '<hr>';
    }

    private function _convert_block_table(array $block, Notion_API $notion_api): string {
        // 获取所有行（优先复用 children）
        if ( isset( $block['children'] ) && is_array( $block['children'] ) ) {
            $rows = $block['children'];
        } else {
            // 记录表格内容获取
            Notion_To_WordPress_Helper::debug_log(
                '表格区块获取子内容: ' . $block['id'],
                'Database Block'
            );
            $rows = $notion_api->get_page_content( $block['id'] );
        }

        if ( empty( $rows ) ) {
            return '<!-- Empty table -->';
        }

        $has_col_header = $block['table']['has_column_header'] ?? false;
        $has_row_header = $block['table']['has_row_header'] ?? false;

        $thead_html = '';
        $tbody_html = '';

        $is_first_row = true;

        foreach ( $rows as $row ) {
            // 标记子块已处理，避免重复递归
            $this->processed_blocks[] = $row['id'];

            $cells = $row['table_row']['cells'] ?? [];
            $row_html  = '';
            foreach ( $cells as $idx => $cell_rich ) {
                $cell_text = $this->extract_rich_text( $cell_rich );

                $use_th = false;
                if ( $has_col_header && $is_first_row ) {
                    $use_th = true;
                } elseif ( $has_row_header && $idx === 0 ) {
                    $use_th = true;
                }

                $tag = $use_th ? 'th' : 'td';
                $row_html .= "<{$tag}>{$cell_text}</{$tag}>";
            }

            $row_html = '<tr>' . $row_html . '</tr>';

            if ( $has_col_header && $is_first_row ) {
                $thead_html .= $row_html;
            } else {
                $tbody_html .= $row_html;
            }

            $is_first_row = false;
        }

        $thead = $thead_html ? '<thead>' . $thead_html . '</thead>' : '';
        $tbody = '<tbody>' . $tbody_html . '</tbody>';

        return '<table>' . $thead . $tbody . '</table>';
    }

    private function _convert_block_table_row(array $block, Notion_API $notion_api): string {
        // 优先使用 children 避免额外请求
        if ( isset( $block['children'] ) && is_array( $block['children'] ) ) {
            $cells = $block['children'];
        } else {
            // 记录表格行内容获取
            Notion_To_WordPress_Helper::debug_log(
                '表格行区块获取子内容: ' . $block['id'],
                'Database Block'
            );
            $cells = $notion_api->get_page_content( $block['id'] );
        }

        if (empty($cells)) {
            return '';
        }

        $html = '<tr>';
        foreach ($cells as $cell) {
            if (isset($cell['table_cell']['rich_text'])) {
                $cell_text = $this->extract_rich_text($cell['table_cell']['rich_text']);
            } else {
                $cell_text = $this->extract_rich_text($cell);
            }
            $html .= '<td>' . $cell_text . '</td>';
        }
        $html .= '</tr>';
        return $html;
    }

    private function _convert_block_callout(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['callout']['rich_text']);
        $icon = '';
        if (isset($block['callout']['icon'])) {
            if (isset($block['callout']['icon']['emoji'])) {
                $icon = $block['callout']['icon']['emoji'];
            } elseif (isset($block['callout']['icon']['external']['url'])) {
                $icon = '<img src="' . esc_url($block['callout']['icon']['external']['url']) . '" class="notion-callout-icon" alt="icon">';
            }
        }
        return '<div class="notion-callout">' . $icon . '<div class="notion-callout-content">' . $text . '</div></div>';
    }

    private function _convert_block_bookmark(array $block, Notion_API $notion_api): string {
        $url = esc_url($block['bookmark']['url']);
        $caption = $this->extract_rich_text($block['bookmark']['caption'] ?? []);
        $caption_html = $caption ? '<div class="notion-bookmark-caption">' . esc_html($caption) . '</div>' : '';
        return '<div class="notion-bookmark"><a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $caption_html . '</div>';
    }

    /**
     * 转换块级公式 - 重构后的统一处理逻辑
     *
     * @since 1.0.9 重构公式处理系统，解决转义问题
     * @param array $block 公式块数据
     * @param Notion_API $notion_api Notion API实例
     * @return string 转换后的HTML
     */
    private function _convert_block_equation(array $block, Notion_API $notion_api): string {
        $expression = $block['equation']['expression'] ?? '';

        // 保留化学公式的特殊处理（确保\ce前缀）
        if (strpos($expression, 'ce{') !== false && strpos($expression, '\\ce{') === false) {
            $expression = preg_replace('/(?<!\\\\)ce\{/', '\\ce{', $expression);
        }

        // 对反斜杠进行一次加倍保护，确保正确传递给KaTeX
        $expression = str_replace( '\\', '\\\\', $expression );

        // 使用旧版本的简单类名，确保JavaScript能正确识别
        return '<div class="notion-equation notion-equation-block">$$' . $expression . '$$</div>';
    }

    private function _convert_block_embed(array $block, Notion_API $notion_api): string {
        $url = isset($block['embed']['url']) ? $block['embed']['url'] : '';
        if (empty($url)) {
            return '<!-- ' . __('无效的嵌入URL', 'notion-to-wordpress') . ' -->';
        }
        
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
        return '<div class="notion-embed"><iframe src="' . esc_url($url) . '" width="100%" height="500" frameborder="0"></iframe></div>';
    }

    private function _convert_block_video(array $block, Notion_API $notion_api): string {
        $type = isset($block['video']['type']) ? $block['video']['type'] : '';
        $url = '';
        
        if ($type === 'external') {
            $url = isset($block['video']['external']['url']) ? $block['video']['external']['url'] : '';
        } elseif ($type === 'file') {
            $url = isset($block['video']['file']['url']) ? $block['video']['file']['url'] : '';
        }
        
        if (empty($url)) {
            return '<!-- ' . __('无效的视频URL', 'notion-to-wordpress') . ' -->';
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
        
        // 通用视频
        $extension = pathinfo($url, PATHINFO_EXTENSION);
        if (in_array(strtolower($extension), ['mp4', 'webm', 'ogg'])) {
            return '<div class="notion-video"><video controls width="100%"><source src="' . esc_url($url) . '" type="video/' . esc_attr($extension) . '">' . __('您的浏览器不支持视频标签。', 'notion-to-wordpress') . '</video></div>';
        }
        
        // 无法识别的视频格式，提供链接
        return '<div class="notion-video-link"><a href="' . esc_url($url) . '" target="_blank">' . __('查看视频', 'notion-to-wordpress') . '</a></div>';
    }

    /**
     * 检测是否为 Notion 页面内锚点链接
     *
     * @since    1.1.1
     * @param    string    $href    链接地址
     * @return   bool              是否为 Notion 锚点链接
     */
    private function is_notion_anchor_link(string $href): bool {
        // 检测是否为 Notion 页面内链接，支持多种格式：
        // 1. https://www.notion.so/page-title-123abc#456def
        // 2. https://notion.so/123abc#456def
        // 3. #456def (相对锚点)
        return (bool) preg_match('/(?:notion\.so.*)?#[a-f0-9-]{8,}/', $href);
    }

    /**
     * 将 Notion 锚点链接转换为本地锚点
     *
     * @since    1.1.1
     * @param    string    $href    原始链接地址
     * @return   string             转换后的本地锚点链接
     */
    private function convert_notion_anchor_to_local(string $href): string {
        // 提取区块 ID 并转换为本地锚点
        if (preg_match('/#([a-f0-9-]{8,})/', $href, $matches)) {
            $block_id = $matches[1];

            // 调试日志：记录原始 ID
            Notion_To_WordPress_Helper::debug_log("锚点链接原始 ID: $block_id", 'Anchor Link');

            // 如果是32位无连字符格式，转换为36位带连字符格式
            if (strlen($block_id) === 32 && strpos($block_id, '-') === false) {
                // 将32位 ID 转换为标准的36位 UUID 格式
                $formatted_id = substr($block_id, 0, 8) . '-' .
                               substr($block_id, 8, 4) . '-' .
                               substr($block_id, 12, 4) . '-' .
                               substr($block_id, 16, 4) . '-' .
                               substr($block_id, 20, 12);

                Notion_To_WordPress_Helper::debug_log("锚点链接转换后 ID: $formatted_id", 'Anchor Link');
                return '#notion-block-' . $formatted_id;
            }

            // 如果已经是正确格式，直接使用
            return '#notion-block-' . $block_id;
        }
        // 如果无法提取有效的区块 ID，记录警告并返回原始链接
        Notion_To_WordPress_Helper::warning_log('无法从锚点链接中提取有效的区块 ID: ' . $href);
        return $href;
    }

    /**
     * 从富文本数组中提取文本内容
     *
     * @since    1.0.5
     * @param    array     $rich_text    富文本数组
     * @return   string                  格式化的HTML文本
     */
    private function extract_rich_text($rich_text) {
        if (empty($rich_text)) {
            return '';
        }
        
        $result = '';
        
        foreach ($rich_text as $text) {
            // 处理行内公式 - 恢复到旧版本逻辑
            if ( isset( $text['type'] ) && $text['type'] === 'equation' ) {
                $expr_raw = $text['equation']['expression'] ?? '';

                // 保留化学公式的特殊处理（确保\ce前缀）
                if (strpos($expr_raw, 'ce{') !== false && strpos($expr_raw, '\\ce{') === false) {
                    $expr_raw = preg_replace('/(?<!\\\\)ce\{/', '\\ce{', $expr_raw);
                }

                // 对反斜杠进行一次加倍保护，确保正确传递给KaTeX
                $expr_escaped = str_replace( '\\', '\\\\', $expr_raw );
                $content = '<span class="notion-equation notion-equation-inline">$' . $expr_escaped . '$</span>';
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
                if ($this->is_notion_anchor_link($href)) {
                    // 转换为本地锚点链接，不添加 target="_blank"
                    $local_href = $this->convert_notion_anchor_to_local($href);
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
     * 根据Notion页面ID获取WordPress文章
     *
     * @since    1.0.5
     * @param    string    $notion_id    Notion页面ID
     * @return   int                     WordPress文章ID
     */
    private function get_post_by_notion_id($notion_id) {
        $args = array(
            'post_type'      => 'any',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'     => '_notion_page_id',
                    'value'   => $notion_id,
                    'compare' => '='
                )
            ),
            'fields' => 'ids' // 仅获取ID以提高性能
        );
        
        $posts = get_posts($args);
        
        return !empty($posts) ? $posts[0] : 0;
    }

    /**
     * 获取合适的文章作者 ID
     */
    private function get_author_id(): int {
        $author_id = get_current_user_id();
        if ($author_id) {
            return $author_id;
        }
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        return !empty($admins) ? (int) $admins[0]->ID : 1;
    }

    /**
     * 创建或更新 WordPress 文章
     *
     * @return int|WP_Error
     */
    private function create_or_update_post(array $metadata, string $content, int $author_id, string $page_id, int $existing_post_id = 0) {
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
        if ( !empty($metadata['password']) ) {
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
            $this->apply_custom_fields($post_id, $metadata['custom_fields']);
        }

        return $post_id;
    }

    /**
     * 应用自定义字段
     */
    private function apply_custom_fields(int $post_id, array $custom_fields): void {
        foreach ($custom_fields as $field_name => $field_value) {
            update_post_meta($post_id, $field_name, $field_value);
            Notion_To_WordPress_Helper::debug_log("应用自定义字段: {$field_name} = {$field_value}", 'Notion Info', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO);
        }
    }

    /**
     * 设置分类与标签
     */
    private function apply_taxonomies(int $post_id, array $metadata): void {
        if (!empty($metadata['categories'])) {
            wp_set_post_categories($post_id, $metadata['categories']);
        }
        if (!empty($metadata['tags'])) {
            wp_set_post_tags($post_id, $metadata['tags']);
        }
    }

    /**
     * 处理特色图片
     */
    private function apply_featured_image(int $post_id, array $metadata): void {
        if (!empty($metadata['featured_image'])) {
            $this->set_featured_image($post_id, $metadata['featured_image']);
        }
    }

    /**
     * 设置特色图片
     *
     * @since    1.0.5
     * @param    int       $post_id    WordPress文章ID
     * @param    string    $image_url  图片URL
     * @return   boolean               是否成功
     */
    private function set_featured_image($post_id, $image_url) {
        if (empty($image_url)) {
            return;
        }

        $attachment_id = $this->download_and_insert_image($image_url, get_the_title($post_id));

        if ( ! is_wp_error($attachment_id) ) {
            set_post_thumbnail($post_id, $attachment_id);
        } else {
            Notion_To_WordPress_Helper::debug_log('Featured image download failed: ' . $attachment_id->get_error_message());
        }
    }

    /**
     * 下载并插入图片到媒体库
     *
     * @since    1.0.5
     * @param    string    $url       图片URL
     * @param    string    $caption   图片标题
     * @return   int                  WordPress附件ID
     */
    private function download_and_insert_image( string $url, string $caption = '' ) {
        // 去掉查询参数用于去重
        $base_url = strtok( $url, '?' );

        // 若已存在同源附件，直接返回ID
        $existing = $this->get_attachment_by_url( $base_url );
        if ( $existing ) {
            Notion_To_WordPress_Helper::debug_log( "Image already exists in media library (Attachment ID: {$existing}).", 'Notion Info' );
            return $existing;
        }

        // 读取插件设置
        $options            = get_option( 'notion_to_wordpress_options', [] );
        $allowed_mime_string = $options['allowed_image_types'] ?? 'image/jpeg,image/png,image/gif,image/webp';
        $max_size_mb         = isset( $options['max_image_size'] ) ? (int) $options['max_image_size'] : 5;
        $max_size_bytes      = $max_size_mb * 1024 * 1024;

        // HEAD 请求仅用于获取大小和 MIME，可容错
        $head = wp_remote_head( $url, [ 'timeout' => 10 ] );
        if ( ! is_wp_error( $head ) ) {
            $mime_type = wp_remote_retrieve_header( $head, 'content-type' );
            $file_size = intval( wp_remote_retrieve_header( $head, 'content-length' ) );

            // 检查类型（若未设置为 *）
            if ( trim( $allowed_mime_string ) !== '*' ) {
                $allowed_mime = array_filter( array_map( 'trim', explode( ',', $allowed_mime_string ) ) );
                if ( ! in_array( $mime_type, $allowed_mime, true ) ) {
                    Notion_To_WordPress_Helper::error_log( __('不允许的图片类型：', 'notion-to-wordpress') . $mime_type, 'Notion Image' );
                    // 继续，但记录日志
                }
            }

            // 检查大小
            if ( $file_size > 0 && $file_size > $max_size_bytes ) {
                Notion_To_WordPress_Helper::error_log( sprintf( __('图片文件过大（%sMB），超过限制（%sMB）', 'notion-to-wordpress'), round( $file_size / ( 1024 * 1024 ), 2 ), $max_size_mb ), 'Notion Image' );
                // 继续，但记录日志
            }
        } else {
            Notion_To_WordPress_Helper::debug_log( 'HEAD 获取失败：' . $head->get_error_message() . '，尝试直接下载。', 'Notion Image' );
        }

        Notion_To_WordPress_Helper::debug_log( 'Downloading image: ' . $url, 'Notion Image' );

        // 引入核心文件
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // 下载到临时文件
        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            return $tmp; // 由调用方处理
        }

        $file_name = basename( parse_url( $url, PHP_URL_PATH ) );
        if ( ! $file_name ) {
            $file_name = 'notion-image-' . time();
        }

        $file = [
            'name'     => $file_name,
            'tmp_name' => $tmp,
        ];

        // 保存到媒体库
        $attachment_id = media_handle_sideload( $file, 0, $caption );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        // 存储源 URL 方便后续去重
        update_post_meta( $attachment_id, '_notion_original_url', esc_url_raw( $url ) );
        update_post_meta( $attachment_id, '_notion_base_url', esc_url_raw( $base_url ) );

        return $attachment_id;
    }

    /**
     * 根据URL获取附件ID
     *
     * @since    1.0.5
     * @param    string    $search_url    图片URL
     * @return   int               WordPress附件ID
     */
    private function get_attachment_by_url( string $search_url ) {
        // 先按 _notion_base_url 比对（去掉 query 后更稳固）
        $posts = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'     => '_notion_base_url',
                    'value'   => esc_url( $search_url ),
                    'compare' => '=',
                ),
            ),
            'fields' => 'ids',
        ) );

        if ( ! empty( $posts ) ) {
            return $posts[0];
        }

        // 再检查完整 original_url（兼容旧数据）
        $posts = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'     => '_notion_original_url',
                    'value'   => esc_url( $search_url ),
                    'compare' => '=',
                ),
            ),
            'fields' => 'ids',
        ) );

        if ( ! empty( $posts ) ) {
            return $posts[0];
        }

        // 备用：通过 guid 精确匹配
        global $wpdb;
        $attachment = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid=%s LIMIT 1", $search_url ) );
        if ( isset( $attachment[0] ) ) {
            return (int) $attachment[0];
        }

        return 0;
    }

    /**
     * 导入所有Notion页面
     *
     * @since    1.0.8
     * @param    bool    $check_deletions    是否检查删除的页面
     * @param    bool    $incremental        是否启用增量同步
     * @return   array|WP_Error    导入结果统计或错误
     */
    public function import_pages($check_deletions = true, $incremental = true) {
        try {
            // 添加调试日志
            Notion_To_WordPress_Helper::info_log('import_pages() 开始执行', 'Pages Import');
            Notion_To_WordPress_Helper::info_log('Database ID: ' . $this->database_id, 'Pages Import');
            Notion_To_WordPress_Helper::info_log('检查删除: ' . ($check_deletions ? 'yes' : 'no'), 'Pages Import');
            Notion_To_WordPress_Helper::info_log('增量同步: ' . ($incremental ? 'yes' : 'no'), 'Pages Import');

            // 获取数据库中的所有页面
            Notion_To_WordPress_Helper::debug_log('调用get_database_pages()', 'Pages Import');
            $pages = $this->notion_api->get_database_pages($this->database_id);
            Notion_To_WordPress_Helper::info_log('获取到页面数量: ' . count($pages), 'Pages Import');

            if (empty($pages)) {
                return new WP_Error('no_pages', __('未检索到任何页面。', 'notion-to-wordpress'));
            }

            $stats = [
                'total' => count($pages),
                'imported' => 0,
                'updated' => 0,
                'failed' => 0,
                'deleted' => 0
            ];

            // 如果启用删除检测，先处理删除的页面（使用完整页面列表）
            if ($check_deletions) {
                error_log('Notion to WordPress: 开始执行删除检测...');
                try {
                    $deleted_count = $this->cleanup_deleted_pages($pages);
                    $stats['deleted'] = $deleted_count;
                    error_log('Notion to WordPress: 删除检测完成，删除了 ' . $deleted_count . ' 个页面');
                } catch (Exception $e) {
                    error_log('Notion to WordPress: 删除检测失败: ' . $e->getMessage());
                    $stats['deleted'] = 0;
                }
            }

            // 如果启用增量同步，过滤出需要更新的页面
            if ($incremental) {
                $pages = $this->filter_pages_for_incremental_sync($pages);
                error_log('Notion to WordPress: 增量同步过滤后页面数量: ' . count($pages));

                // 更新统计中的总数为实际处理的页面数
                $stats['total'] = count($pages);
            }

            if (empty($pages)) {
                // 如果增量同步后没有页面需要处理，返回成功但无操作的结果
                error_log('Notion to WordPress: 增量同步无页面需要更新');
                return $stats;
            }

            error_log('Notion to WordPress: 开始处理页面，总数: ' . count($pages));

            foreach ($pages as $index => $page) {
                error_log('Notion to WordPress: 处理页面 ' . ($index + 1) . '/' . count($pages) . ', ID: ' . ($page['id'] ?? 'unknown'));

                try {
                    // 检查页面是否已存在
                    $existing_post_id = $this->get_post_by_notion_id($page['id']);
                    Notion_To_WordPress_Helper::debug_log('页面已存在检查结果: ' . ($existing_post_id ? 'exists (ID: ' . $existing_post_id . ')' : 'new'), 'Pages Import');

                    Notion_To_WordPress_Helper::debug_log('开始导入单个页面...', 'Pages Import');
                    $result = $this->import_notion_page($page);
                    Notion_To_WordPress_Helper::debug_log('单个页面导入结果: ' . ($result ? 'success' : 'failed'), 'Pages Import');

                    if ($result) {
                        if ($existing_post_id) {
                            $stats['updated']++;
                        } else {
                            $stats['imported']++;
                        }
                    } else {
                        $stats['failed']++;
                    }
                } catch (Exception $e) {
                    Notion_To_WordPress_Helper::error_log('处理页面异常: ' . $e->getMessage(), 'Pages Import');
                    $stats['failed']++;
                } catch (Error $e) {
                    Notion_To_WordPress_Helper::error_log('处理页面错误: ' . $e->getMessage(), 'Pages Import');
                    $stats['failed']++;
                }

                Notion_To_WordPress_Helper::debug_log('页面 ' . ($index + 1) . ' 处理完成', 'Pages Import');
            }

            Notion_To_WordPress_Helper::info_log('所有页面处理完成，统计: ' . print_r($stats, true), 'Pages Import');
            return $stats;

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log('import_pages() 异常: ' . $e->getMessage(), 'Pages Import');
            Notion_To_WordPress_Helper::error_log('异常堆栈: ' . $e->getTraceAsString(), 'Pages Import');
            return new WP_Error('import_failed', __('导入失败: ', 'notion-to-wordpress') . $e->getMessage());
        }
    }

    // --- Column Blocks ---

    private function _convert_block_column_list(array $block, Notion_API $notion_api): string {
        // 列表容器
        $html = '<div class="notion-column-list">';
        $html .= $this->_convert_child_blocks($block, $notion_api);
        $html .= '</div>';
        return $html;
    }

    private function _convert_block_column(array $block, Notion_API $notion_api): string {
        // 计算列宽（Notion API 提供 width_ratio，直接用作 flex-grow 值）
        $ratio = $block['column']['width_ratio'] ?? 1;

        // 调试日志：记录列宽比例
        Notion_To_WordPress_Helper::debug_log("分栏列宽比例: $ratio", 'Column Layout');

        $html = '<div class="notion-column" style="flex:' . esc_attr($ratio) . ' 1 0;">';
        $html .= $this->_convert_child_blocks($block, $notion_api);
        $html .= '</div>';
        return $html;
    }

    // --- File Block ---

    private function _convert_block_file(array $block, Notion_API $notion_api): string {
        $file_data = $block['file'];
        $type      = $file_data['type'] ?? 'external';
        $url       = $type === 'file' ? ( $file_data['file']['url'] ?? '' ) : ( $file_data['external']['url'] ?? '' );

        if ( empty( $url ) ) {
            return '<!-- Empty file block -->';
        }

        $caption   = $this->extract_rich_text( $file_data['caption'] ?? [] );
        $file_name = basename( parse_url( $url, PHP_URL_PATH ) );
        $display   = $caption ?: $file_name;

        // 如果是外链文件，直接引用
        if ( ! $this->is_notion_temp_url( $url ) ) {
            return '<div class="file-download-box"><span class="file-download-name">' . esc_html( $display ) . '</span> <a class="file-download-btn" href="' . esc_url( $url ) . '" download target="_blank" rel="noopener">' . __( '下载附件', 'notion-to-wordpress' ) . '</a></div>';
        }

        // Notion 临时文件：下载并保存到媒体库
        $attachment_id = $this->download_and_insert_file( $url, $caption, $file_name );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return '<!-- File download error -->';
        }

        $local_url = wp_get_attachment_url( $attachment_id );

        return '<div class="file-download-box"><span class="file-download-name">' . esc_html( $display ) . '</span> <a class="file-download-btn" href="' . esc_url( $local_url ) . '" download target="_blank" rel="noopener">' . __( '下载附件', 'notion-to-wordpress' ) . '</a></div>';
    }

    // --- PDF Block ---

    private function _convert_block_pdf(array $block, Notion_API $notion_api): string {
        $pdf_data = $block['pdf'] ?? [];
        $type     = $pdf_data['type'] ?? 'external';
        $url      = ( 'file' === $type ) ? ( $pdf_data['file']['url'] ?? '' ) : ( $pdf_data['external']['url'] ?? '' );
        if ( empty( $url ) ) {
            return '<!-- ' . __('无效的 PDF URL', 'notion-to-wordpress') . ' -->';
        }

        $caption = $this->extract_rich_text( $pdf_data['caption'] ?? [] );

        // 生成预览 HTML（使用多种预览方案）
        $build_html = function ( string $src, string $download ) use ( $caption ) : string {
            $html = '<div class="notion-pdf">';

            // 尝试使用浏览器原生PDF预览
            $html .= '<div class="pdf-preview-container">';
            $html .= '<object data="' . esc_url( $src ) . '" type="application/pdf" width="100%" height="600">';
            $html .= '<embed src="' . esc_url( $src ) . '" type="application/pdf" width="100%" height="600" />';
            $html .= '<p>' . __('您的浏览器不支持PDF预览。', 'notion-to-wordpress') . '<a href="' . esc_url( $download ) . '" target="_blank" rel="noopener">' . __('点击下载PDF文件', 'notion-to-wordpress') . '</a></p>';
            $html .= '</object>';
            $html .= '</div>';

            $html .= '<div class="pdf-actions">';
            $html .= '<a href="' . esc_url( $download ) . '" target="_blank" rel="noopener" class="pdf-download-btn">' . __( '下载 PDF', 'notion-to-wordpress' ) . '</a>';
            $html .= '<a href="' . esc_url( $src ) . '" target="_blank" rel="noopener" class="pdf-open-btn">' . __( '在新窗口打开', 'notion-to-wordpress' ) . '</a>';
            $html .= '</div>';

            if ( $caption ) {
                $html .= '<p class="notion-pdf-caption">' . esc_html( $caption ) . '</p>';
            }
            $html .= '</div>';
            return $html;
        };

        // 非 Notion 临时链接直接使用
        if ( ! $this->is_notion_temp_url( $url ) ) {
            return $build_html( $url, $url );
        }

        // Notion 临时文件：下载并保存到媒体库
        $attachment_id = $this->download_and_insert_file( $url, $caption, '' );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return '<!-- ' . __('PDF 下载失败', 'notion-to-wordpress') . ' -->';
        }

        $local_url = wp_get_attachment_url( $attachment_id );
        return $build_html( $local_url, $local_url );
    }

    /**
     * 下载任意文件并插入媒体库
     *
     * @param string $url          远程文件 URL
     * @param string $caption      说明文字
     * @param string $override_name 指定文件名（可选）
     * @return int|WP_Error        附件 ID 或错误
     */
    private function download_and_insert_file( string $url, string $caption = '', string $override_name = '' ) {
        // 检查是否已下载过
        $base_url = strtok( $url, '?' );
        $existing = $this->get_attachment_by_url( $base_url );
        if ( $existing ) {
            return (int) $existing;
        }

        // 引入 WP 媒体处理
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // 下载到临时文件
        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            Notion_To_WordPress_Helper::error_log( __('下载附件失败: ', 'notion-to-wordpress') . $tmp->get_error_message(), 'Notion File' );
            return $tmp;
        }

        // 文件名
        $file_name = $override_name ?: basename( parse_url( $url, PHP_URL_PATH ) );
        if ( empty( $file_name ) ) {
            $file_name = 'notion-file-' . time();
        }

        // PDF文件验证
        if ( strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) === 'pdf' ) {
            if ( ! $this->validate_pdf_file( $tmp ) ) {
                @unlink( $tmp );
                return new WP_Error( 'invalid_pdf', __('无效的PDF文件或包含不安全内容', 'notion-to-wordpress') );
            }
        }

        // 构造 $_FILES 兼容数组
        $file = [
            'name'     => $file_name,
            'tmp_name' => $tmp,
        ];

        // 上传到媒体库
        $attachment_id = media_handle_sideload( $file, 0, $caption );

        if ( is_wp_error( $attachment_id ) ) {
            Notion_To_WordPress_Helper::error_log( __('media_handle_sideload 错误: ', 'notion-to-wordpress') . $attachment_id->get_error_message(), 'Notion File' );
            @unlink( $tmp );
            return $attachment_id;
        }

        // 存储原始 URL 及 base_url，避免重复下载
        update_post_meta( $attachment_id, '_notion_original_url', esc_url( $url ) );
        update_post_meta( $attachment_id, '_notion_base_url', esc_url( $base_url ) );

        return (int) $attachment_id;
    }

    // --- Synced Block ---

    private function _convert_block_synced_block(array $block, Notion_API $notion_api): string {
        // 直接渲染其子块
        return $this->_convert_child_blocks($block, $notion_api);
    }

    // --- Link to Page Block ---

    private function _convert_block_link_to_page(array $block, Notion_API $notion_api): string {
        $data = $block['link_to_page'] ?? [];

        $url   = '';
        $label = '';

        try {
            switch ( $data['type'] ?? '' ) {
                case 'page_id':
                    $page_id = $data['page_id'];
                    $page    = $notion_api->get_page( $page_id );
                    $url     = $page['url'] ?? 'https://www.notion.so/' . str_replace( '-', '', $page_id );
                    // 尝试读取系统 title 属性
                    if ( isset( $page['properties']['title']['title'][0]['plain_text'] ) ) {
                        $label = $page['properties']['title']['title'][0]['plain_text'];
                    }
                    break;

                case 'database_id':
                    $db_id = $data['database_id'];
                    $db    = $notion_api->get_database( $db_id );
                    $url   = $db['url'] ?? 'https://www.notion.so/' . str_replace( '-', '', $db_id );
                    if ( isset( $db['title'][0]['plain_text'] ) ) {
                        $label = $db['title'][0]['plain_text'];
                    }
                    break;

                case 'url':
                    $url   = $data['url'];
                    break;
            }
        } catch ( Exception $e ) {
            // 回退：使用 Notion 默认链接
        }

        if ( empty( $url ) ) {
            return '<!-- Empty link_to_page -->';
        }

        if ( empty( $label ) ) {
            // 若无标题，使用 URL 或占位符
            $label = parse_url( $url, PHP_URL_HOST ) ?: 'Notion Page';
        }

        return '<p class="notion-link-to-page"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></p>';
    }



    /**
     * 验证PDF文件
     *
     * @since 1.0.9
     * @param string $file_path 文件路径
     * @return bool 是否为有效PDF
     */
    private function validate_pdf_file(string $file_path): bool {
        $file_handle = fopen($file_path, 'rb');
        if (!$file_handle) {
            return false;
        }

        $header = fread($file_handle, 1024);
        fclose($file_handle);

        // 检查PDF头部
        if (strpos($header, '%PDF-') !== 0) {
            return false;
        }

        // 检查是否包含JavaScript（可能的安全风险）
        if (stripos($header, '/JavaScript') !== false || stripos($header, '/JS') !== false) {
            Notion_To_WordPress_Helper::error_log(
                "PDF文件包含JavaScript代码，可能存在安全风险",
                'Notion PDF'
            );
            return false;
        }

        return true;
    }

    /**
     * 清理已删除的页面
     *
     * @since    1.1.0
     * @param    array    $current_pages    当前Notion数据库中的页面
     * @return   int                        删除的页面数量
     */
    private function cleanup_deleted_pages(array $current_pages): int {
        // 获取当前Notion页面的ID列表
        $current_notion_ids = array_map(function($page) {
            return $page['id'];
        }, $current_pages);

        error_log('Notion to WordPress: 当前Notion页面ID: ' . implode(', ', $current_notion_ids));

        // 查找所有WordPress中有Notion ID的文章
        $args = array(
            'post_type'      => 'any',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_notion_page_id',
                    'compare' => 'EXISTS'
                )
            ),
            'fields' => 'ids'
        );

        $wordpress_posts = get_posts($args);
        $deleted_count = 0;

        error_log('Notion to WordPress: 找到 ' . count($wordpress_posts) . ' 个WordPress文章有Notion ID');

        foreach ($wordpress_posts as $post_id) {
            $notion_id = get_post_meta($post_id, '_notion_page_id', true);

            // 如果这个Notion ID不在当前页面列表中，说明已被删除
            if (!in_array($notion_id, $current_notion_ids)) {
                error_log('Notion to WordPress: 发现孤儿文章，WordPress ID: ' . $post_id . ', Notion ID: ' . $notion_id);

                $result = wp_delete_post($post_id, true); // true表示彻底删除

                if ($result) {
                    $deleted_count++;
                    Notion_To_WordPress_Helper::info_log(
                        '删除孤儿文章成功，WordPress ID: ' . $post_id . ', Notion ID: ' . $notion_id,
                        'Cleanup'
                    );
                } else {
                    Notion_To_WordPress_Helper::error_log(
                        '删除孤儿文章失败，WordPress ID: ' . $post_id . ', Notion ID: ' . $notion_id
                    );
                }
            }
        }

        if ($deleted_count > 0) {
            Notion_To_WordPress_Helper::info_log(
                '删除检测完成，共删除 ' . $deleted_count . ' 个孤儿文章',
                'Cleanup'
            );
        }

        return $deleted_count;
    }

    /**
     * 过滤出需要增量同步的页面
     *
     * @since    1.1.0
     * @param    array    $pages    所有Notion页面
     * @return   array              需要同步的页面
     */
    private function filter_pages_for_incremental_sync(array $pages): array {
        $pages_to_sync = [];

        foreach ($pages as $page) {
            $page_id = $page['id'];
            $notion_last_edited = $page['last_edited_time'] ?? '';

            if (empty($notion_last_edited)) {
                // 如果没有编辑时间，默认需要同步
                $pages_to_sync[] = $page;
                continue;
            }

            // 获取本地记录的最后同步时间
            $local_last_sync = $this->get_page_last_sync_time($page_id);

            if (empty($local_last_sync)) {
                // 新页面，需要同步
                Notion_To_WordPress_Helper::debug_log(
                    "新页面需要同步: {$page_id}",
                    'Incremental Sync'
                );
                $pages_to_sync[] = $page;
                continue;
            }

            // 比较时间戳（统一转换为UTC时间戳）
            $notion_timestamp = strtotime($notion_last_edited);

            // 确保本地时间也是UTC格式进行比较
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $local_last_sync)) {
                // 如果是MySQL格式，假设为UTC时间
                $local_timestamp = strtotime($local_last_sync . ' UTC');
            } else {
                // 如果是ISO格式，直接转换
                $local_timestamp = strtotime($local_last_sync);
            }

            // 添加详细的时间比较日志
            Notion_To_WordPress_Helper::debug_log(
                "时间比较 - 页面ID: {$page_id}, Notion编辑时间: {$notion_last_edited} ({$notion_timestamp}), 本地同步时间: {$local_last_sync} ({$local_timestamp})",
                'Incremental Sync'
            );

            // 使用更宽松的时间比较，允许1秒的误差
            if ($notion_timestamp > $local_timestamp + 1) {
                // Notion页面更新时间晚于本地同步时间，需要同步
                Notion_To_WordPress_Helper::debug_log(
                    "页面有更新需要同步: {$page_id}, Notion: {$notion_last_edited}, Local: {$local_last_sync}",
                    'Incremental Sync'
                );
                $pages_to_sync[] = $page;
            } else {
                // 页面无变化，跳过
                Notion_To_WordPress_Helper::debug_log(
                    "页面无变化跳过: {$page_id}, Notion: {$notion_last_edited}, Local: {$local_last_sync}",
                    'Incremental Sync'
                );
            }
        }

        Notion_To_WordPress_Helper::info_log(
            sprintf('增量同步检测完成，总页面: %d, 需要同步: %d', count($pages), count($pages_to_sync)),
            'Incremental Sync'
        );

        return $pages_to_sync;
    }

    /**
     * 获取页面最后同步时间
     *
     * @since    1.1.0
     * @param    string    $page_id    Notion页面ID
     * @return   string                最后同步时间
     */
    private function get_page_last_sync_time(string $page_id): string {
        $post_id = $this->get_post_by_notion_id($page_id);

        if (!$post_id) {
            return '';
        }

        return get_post_meta($post_id, '_notion_last_sync_time', true) ?: '';
    }

    /**
     * 更新页面同步时间
     *
     * @since    1.1.0
     * @param    string    $page_id              Notion页面ID
     * @param    string    $notion_last_edited   Notion最后编辑时间
     */
    private function update_page_sync_time(string $page_id, string $notion_last_edited): void {
        $post_id = $this->get_post_by_notion_id($page_id);

        if (!$post_id) {
            return;
        }

        // 更新同步时间戳（统一使用UTC时间）
        $current_utc_time = gmdate('Y-m-d H:i:s');
        update_post_meta($post_id, '_notion_last_sync_time', $current_utc_time);
        update_post_meta($post_id, '_notion_last_edited_time', $notion_last_edited);

        Notion_To_WordPress_Helper::debug_log(
            "更新页面同步时间: {$page_id}, 编辑时间: {$notion_last_edited}",
            'Incremental Sync'
        );
    }

    /**
     * 获取单个页面数据（用于webhook强制同步）
     *
     * @since    1.1.0
     * @param    string    $page_id    页面ID
     * @return   array                 页面数据
     * @throws   Exception             如果获取失败
     */
    public function get_page_data(string $page_id): array {
        return $this->notion_api->get_page($page_id);
    }

    /**
     * 渲染数据库属性信息
     *
     * @since 1.0.9
     * @param array $database_info 数据库信息数组
     * @return string HTML内容
     */
    private function render_database_properties(array $database_info): string {
        $html = '';
        $database_id = $database_info['id'] ?? 'unknown';

        Notion_To_WordPress_Helper::debug_log(
            '开始渲染数据库属性: ' . $database_id,
            'Database Block'
        );

        try {
            // 显示数据库描述
            if (!empty($database_info['description'])) {
                $description = $this->extract_rich_text($database_info['description']);
                if (!empty($description)) {
                    $html .= '<p class="notion-database-description">' . $description . '</p>';
                    Notion_To_WordPress_Helper::debug_log(
                        '数据库描述渲染成功: ' . $database_id,
                        'Database Block'
                    );
                }
            }

            // 显示数据库标题（如果与区块标题不同）
            if (!empty($database_info['title']) && is_array($database_info['title'])) {
                $db_title = $this->extract_rich_text($database_info['title']);
                if (!empty($db_title)) {
                    $html .= '<div class="notion-database-info"><strong>数据库：</strong>' . esc_html($db_title) . '</div>';
                }
            }

            // 显示属性列表和类型信息
            if (!empty($database_info['properties'])) {
                $properties = $database_info['properties'];
                $property_count = count($properties);

                Notion_To_WordPress_Helper::debug_log(
                    '开始处理数据库属性: ' . $database_id . ', 属性数量: ' . $property_count,
                    'Database Block'
                );

                $property_info = $this->format_database_properties($properties);

                if (!empty($property_info)) {
                    $html .= '<div class="notion-database-properties">';
                    $html .= '<strong>属性：</strong>' . $property_info;
                    $html .= '</div>';

                    Notion_To_WordPress_Helper::info_log(
                        '数据库属性渲染成功: ' . $database_id . ', 属性数量: ' . $property_count,
                        'Database Block'
                    );
                } else {
                    Notion_To_WordPress_Helper::debug_log(
                        '数据库属性格式化结果为空: ' . $database_id,
                        'Database Block'
                    );
                }
            } else {
                Notion_To_WordPress_Helper::debug_log(
                    '数据库无属性信息: ' . $database_id,
                    'Database Block'
                );
            }

            // 显示数据库URL（如果可用）
            if (!empty($database_info['url'])) {
                $html .= '<div class="notion-database-link">';
                $html .= '<a href="' . esc_url($database_info['url']) . '" target="_blank" rel="noopener noreferrer">在Notion中查看</a>';
                $html .= '</div>';
            }

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log(
                '数据库属性渲染异常: ' . $database_id . ', 错误: ' . $e->getMessage(),
                'Database Block'
            );
            // 发生异常时返回基础HTML，确保不影响页面渲染
            if (empty($html)) {
                $html = '<p class="notion-database-fallback">数据库信息渲染出错</p>';
            }
        }

        Notion_To_WordPress_Helper::debug_log(
            '数据库属性渲染完成: ' . $database_id,
            'Database Block'
        );

        return $html;
    }

    /**
     * 格式化数据库属性信息
     *
     * @since 1.0.9
     * @param array $properties 属性数组
     * @return string 格式化的属性信息
     */
    private function format_database_properties(array $properties): string {
        if (empty($properties)) {
            Notion_To_WordPress_Helper::debug_log(
                '属性数组为空，跳过格式化',
                'Database Block'
            );
            return '';
        }

        try {
            $property_names = [];
            $property_types = [];

            foreach ($properties as $name => $config) {
                if (!is_array($config)) {
                    Notion_To_WordPress_Helper::debug_log(
                        '属性配置格式异常，跳过: ' . $name,
                        'Database Block'
                    );
                    continue;
                }

                $property_names[] = $name;
                $type = $config['type'] ?? 'unknown';

                // 统计属性类型
                if (!isset($property_types[$type])) {
                    $property_types[$type] = 0;
                }
                $property_types[$type]++;
            }

            if (empty($property_names)) {
                Notion_To_WordPress_Helper::debug_log(
                    '没有有效的属性名称',
                    'Database Block'
                );
                return '';
            }

            // 显示前5个属性名称
            $display_names = array_slice($property_names, 0, 5);
            $result = esc_html(implode(', ', $display_names));

            // 如果属性超过5个，显示总数
            if (count($property_names) > 5) {
                $result .= ' 等' . count($property_names) . '个属性';
            }

            // 添加属性类型统计（如果有多种类型）
            if (count($property_types) > 1) {
                $type_info = [];
                foreach ($property_types as $type => $count) {
                    $type_name = $this->get_property_type_name($type);
                    if ($count > 1) {
                        $type_info[] = $type_name . '(' . $count . ')';
                    } else {
                        $type_info[] = $type_name;
                    }
                }
                $result .= ' <span class="notion-property-types">(' . implode(', ', array_slice($type_info, 0, 3)) . ')</span>';
            }

            Notion_To_WordPress_Helper::debug_log(
                '属性格式化成功，总数: ' . count($property_names) . ', 类型数: ' . count($property_types),
                'Database Block'
            );

            return $result;

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log(
                '属性格式化异常: ' . $e->getMessage(),
                'Database Block'
            );
            return '属性信息处理出错';
        }
    }

    /**
     * 获取属性类型的中文名称
     *
     * @since 1.0.9
     * @param string $type 属性类型
     * @return string 中文名称
     */
    private function get_property_type_name(string $type): string {
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
            'formula' => '公式',
            'relation' => '关联',
            'rollup' => '汇总',
            'people' => '人员',
            'files' => '文件',
            'created_time' => '创建时间',
            'created_by' => '创建者',
            'last_edited_time' => '编辑时间',
            'last_edited_by' => '编辑者'
        ];

        return $type_names[$type] ?? $type;
    }

    /**
     * 渲染数据库记录预览
     *
     * @since 1.0.9
     * @param string $database_id 数据库ID
     * @param array $database_info 数据库信息
     * @param Notion_API $notion_api API实例
     * @return string HTML内容
     */
    private function render_database_preview_records(string $database_id, array $database_info, Notion_API $notion_api): string {
        try {
            // 获取数据库中的前几条记录（限制数量以提高性能）
            // 使用with_details=true获取包含封面图片和图标的完整信息
            $records = $notion_api->get_database_pages($database_id, [], true);

            if (empty($records)) {
                Notion_To_WordPress_Helper::debug_log(
                    '数据库无记录或无权限访问: ' . $database_id,
                    'Database Block'
                );
                return '<div class="notion-database-empty">' . __('暂无记录', 'notion-to-wordpress') . '</div>';
            }

            // 显示所有记录的预览
            Notion_To_WordPress_Helper::debug_log(
                '获取数据库记录成功: ' . $database_id . ', 总记录: ' . count($records),
                'Database Block'
            );

            // 智能选择视图类型
            $view_type = $this->detect_optimal_view_type($records, $database_info);

            Notion_To_WordPress_Helper::debug_log(
                '选择视图类型: ' . $view_type . ' for database: ' . $database_id,
                'Database View'
            );

            // 实现渐进式加载：先显示基本信息，后续加载详细内容
            $initial_load_count = min(6, count($records)); // 首次加载最多6条记录
            $initial_records = array_slice($records, 0, $initial_load_count);
            $remaining_records = array_slice($records, $initial_load_count);

            // 渲染初始内容
            $html = $this->render_database_with_view($initial_records, $database_info, $view_type);

            // 如果有剩余记录，添加懒加载容器
            if (!empty($remaining_records)) {
                $html .= $this->render_progressive_loading_container($remaining_records, $database_info, $view_type, $database_id);
            }

            return $html;

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log(
                '数据库记录预览异常: ' . $database_id . ', 错误: ' . $e->getMessage(),
                'Database Block'
            );
            return '<div class="notion-database-preview-error">记录预览暂时无法加载</div>';
        }
    }

    /**
     * 渲染单个数据库记录
     *
     * @since 1.0.9
     * @param array $record 记录数据
     * @param array $database_info 数据库信息
     * @return string HTML内容
     */
    private function render_single_database_record(array $record, array $database_info): string {
        $properties = $record['properties'] ?? [];
        $record_id = $record['id'] ?? '';
        $created_time = $record['created_time'] ?? '';

        $html = '<div class="notion-database-record" data-record-id="' . esc_attr($record_id) . '" data-created="' . esc_attr($created_time) . '">';

        // 渲染封面图片（如果存在）
        $cover_html = $this->render_record_cover($record);
        if (!empty($cover_html)) {
            $html .= $cover_html;
        }

        // 获取记录标题和图标
        $title = $this->extract_record_title($properties);
        $icon_html = $this->render_record_icon($record);

        if (!empty($title)) {
            $html .= '<div class="notion-record-title">';
            if (!empty($icon_html)) {
                $html .= $icon_html;
            }
            $html .= esc_html($title);
            $html .= '</div>';
        }

        // 获取并显示关键属性
        $key_properties = $this->extract_key_properties($properties, $database_info);
        if (!empty($key_properties)) {
            $html .= '<div class="notion-record-properties">';
            foreach ($key_properties as $prop_name => $prop_value) {
                if (!empty($prop_value) && $prop_value !== '未知' && $prop_value !== 'unknown') {
                    $html .= '<div class="notion-record-property">';
                    $html .= '<span class="notion-property-name">' . esc_html($prop_name) . ':</span> ';
                    $html .= '<span class="notion-property-value">' . wp_kses_post($prop_value) . '</span>';
                    $html .= '</div>';
                }
            }
            $html .= '</div>';
        }

        // 如果记录有URL，添加链接
        if (!empty($record['url']) && filter_var($record['url'], FILTER_VALIDATE_URL)) {
            $html .= '<div class="notion-record-link">';
            $html .= '<a href="' . esc_url($record['url']) . '" target="_blank" rel="noopener noreferrer">查看详情</a>';
            $html .= '</div>';
        } elseif (!empty($record_id)) {
            // 如果没有URL但有记录ID，生成Notion链接
            $notion_url = 'https://www.notion.so/' . str_replace('-', '', $record_id);
            $html .= '<div class="notion-record-link">';
            $html .= '<a href="' . esc_url($notion_url) . '" target="_blank" rel="noopener noreferrer">在Notion中查看</a>';
            $html .= '</div>';
        }

        $html .= '</div>'; // 关闭 notion-database-record

        return $html;
    }

    /**
     * 提取记录标题
     *
     * @since 1.0.9
     * @param array $properties 记录属性
     * @return string 标题
     */
    private function extract_record_title(array $properties): string {
        // 查找title类型的属性
        foreach ($properties as $name => $property) {
            if (isset($property['type']) && $property['type'] === 'title') {
                if (!empty($property['title'])) {
                    return $this->extract_rich_text($property['title']);
                }
            }
        }

        // 如果没有找到title属性，尝试查找名为"Name"或"标题"的属性
        $title_candidates = ['Name', '标题', 'Title', 'name', 'title'];
        foreach ($title_candidates as $candidate) {
            if (isset($properties[$candidate])) {
                $prop = $properties[$candidate];
                if (isset($prop['rich_text']) && !empty($prop['rich_text'])) {
                    return $this->extract_rich_text($prop['rich_text']);
                }
                if (isset($prop['title']) && !empty($prop['title'])) {
                    return $this->extract_rich_text($prop['title']);
                }
            }
        }

        return __('无标题', 'notion-to-wordpress');
    }

    /**
     * 提取关键属性用于预览显示
     *
     * @since 1.0.9
     * @param array $properties 记录属性
     * @param array $database_info 数据库信息
     * @return array 关键属性数组
     */
    private function extract_key_properties(array $properties, array $database_info): array {
        $key_props = [];
        $db_properties = $database_info['properties'] ?? [];

        // 优先显示的属性类型
        $priority_types = ['select', 'status', 'date', 'number', 'checkbox', 'files', 'url', 'email', 'phone_number', 'multi_select', 'people'];

        foreach ($priority_types as $type) {
            foreach ($db_properties as $prop_name => $prop_config) {
                if (($prop_config['type'] ?? '') === $type && isset($properties[$prop_name])) {
                    $value = $this->format_property_for_preview($properties[$prop_name], $type);
                    if (!empty($value) && count($key_props) < 3) { // 最多显示3个关键属性
                        $key_props[$prop_name] = $value;
                    }
                }
            }
        }

        return $key_props;
    }

    /**
     * 格式化属性值用于预览显示
     *
     * @since 1.0.9
     * @param array $property 属性数据
     * @param string $type 属性类型
     * @return string 格式化后的值
     */
    private function format_property_for_preview(array $property, string $type): string {
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
                    $text = $this->extract_rich_text($property['rich_text']);
                    return mb_strlen($text) > 50 ? mb_substr($text, 0, 50) . '...' : $text;
                }
                return '';

            case 'files':
                return $this->render_record_files($property);

            case 'url':
                $url = $property['url'] ?? '';
                if (!empty($url)) {
                    $display_url = mb_strlen($url) > 30 ? mb_substr($url, 0, 30) . '...' : $url;
                    return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($display_url) . '</a>';
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
     * 渲染数据库记录的封面图片
     *
     * @since 1.1.1
     * @param array $record 记录数据
     * @return string HTML内容
     */
    private function render_record_cover(array $record): string {
        $cover = $record['cover'] ?? null;
        if (empty($cover)) {
            return '';
        }

        $cover_type = $cover['type'] ?? '';
        $cover_url = '';

        // 处理不同类型的封面
        switch ($cover_type) {
            case 'file':
                $cover_url = $cover['file']['url'] ?? '';
                break;
            case 'external':
                $cover_url = $cover['external']['url'] ?? '';
                break;
            default:
                Notion_To_WordPress_Helper::debug_log(
                    '未知的封面类型: ' . $cover_type,
                    'Record Cover'
                );
                return '';
        }

        if (empty($cover_url)) {
            return '';
        }

        // 处理Notion临时URL
        if ($this->is_notion_temp_url($cover_url)) {
            $attachment_id = $this->download_and_insert_image($cover_url, __('数据库记录封面', 'notion-to-wordpress'));

            if (is_numeric($attachment_id) && $attachment_id > 0) {
                $local_url = wp_get_attachment_url($attachment_id);
                if ($local_url) {
                    $cover_url = $local_url;
                    Notion_To_WordPress_Helper::debug_log(
                        '封面图片下载成功，本地URL: ' . $local_url,
                        'Record Cover'
                    );
                } else {
                    Notion_To_WordPress_Helper::error_log(
                        '封面图片下载后获取本地URL失败',
                        'Record Cover'
                    );
                    return '';
                }
            } else {
                Notion_To_WordPress_Helper::error_log(
                    '封面图片下载失败: ' . $cover_url,
                    'Record Cover'
                );
                return '';
            }
        }

        return '<div class="notion-record-cover">' .
               '<img data-src="' . esc_url($cover_url) . '" alt="' . esc_attr__('封面图片', 'notion-to-wordpress') . '" class="notion-lazy-image" src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjEyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZjBmMGYwIi8+PC9zdmc+">' .
               '</div>';
    }

    /**
     * 渲染数据库记录的图标
     *
     * @since 1.1.1
     * @param array $record 记录数据
     * @return string HTML内容
     */
    private function render_record_icon(array $record): string {
        $icon = $record['icon'] ?? null;
        if (empty($icon)) {
            return '';
        }

        $icon_type = $icon['type'] ?? '';

        switch ($icon_type) {
            case 'emoji':
                $emoji = $icon['emoji'] ?? '';
                if (!empty($emoji)) {
                    return '<span class="notion-record-icon notion-record-icon-emoji">' . esc_html($emoji) . '</span>';
                }
                break;

            case 'file':
                $icon_url = $icon['file']['url'] ?? '';
                if (!empty($icon_url)) {
                    return $this->render_icon_image($icon_url);
                }
                break;

            case 'external':
                $icon_url = $icon['external']['url'] ?? '';
                if (!empty($icon_url)) {
                    return $this->render_icon_image($icon_url);
                }
                break;

            default:
                Notion_To_WordPress_Helper::debug_log(
                    '未知的图标类型: ' . $icon_type,
                    'Record Icon'
                );
                break;
        }

        return '';
    }

    /**
     * 渲染图标图片
     *
     * @since 1.1.1
     * @param string $icon_url 图标URL
     * @return string HTML内容
     */
    private function render_icon_image(string $icon_url): string {
        if (empty($icon_url)) {
            return '';
        }

        // 处理Notion临时URL
        if ($this->is_notion_temp_url($icon_url)) {
            $attachment_id = $this->download_and_insert_image($icon_url, __('数据库记录图标', 'notion-to-wordpress'));

            if (is_numeric($attachment_id) && $attachment_id > 0) {
                $local_url = wp_get_attachment_url($attachment_id);
                if ($local_url) {
                    $icon_url = $local_url;
                    Notion_To_WordPress_Helper::debug_log(
                        '图标图片下载成功，本地URL: ' . $local_url,
                        'Record Icon'
                    );
                } else {
                    Notion_To_WordPress_Helper::error_log(
                        '图标图片下载后获取本地URL失败',
                        'Record Icon'
                    );
                    return '';
                }
            } else {
                Notion_To_WordPress_Helper::error_log(
                    '图标图片下载失败: ' . $icon_url,
                    'Record Icon'
                );
                return '';
            }
        }

        return '<img class="notion-record-icon notion-record-icon-image notion-lazy-image" data-src="' . esc_url($icon_url) . '" alt="' . esc_attr__('图标', 'notion-to-wordpress') . '" src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTYiIGhlaWdodD0iMTYiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHJlY3Qgd2lkdGg9IjEwMCUiIGhlaWdodD0iMTAwJSIgZmlsbD0iI2YwZjBmMCIvPjwvc3ZnPg==">';
    }

    /**
     * 渲染文件属性
     *
     * @since 1.1.1
     * @param array $property 文件属性数据
     * @return string HTML内容
     */
    private function render_record_files(array $property): string {
        $files = $property['files'] ?? [];
        if (empty($files)) {
            return '';
        }

        $html = '<div class="notion-record-files">';
        $file_count = 0;
        $max_files = 3; // 最多显示3个文件

        foreach ($files as $file) {
            if ($file_count >= $max_files) {
                $remaining = count($files) - $max_files;
                if ($remaining > 0) {
                    $html .= '<span class="notion-files-more">+' . $remaining . ' 个文件</span>';
                }
                break;
            }

            $file_html = $this->render_single_file($file);
            if (!empty($file_html)) {
                $html .= $file_html;
                $file_count++;
            }
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * 渲染单个文件
     *
     * @since 1.1.1
     * @param array $file 文件数据
     * @return string HTML内容
     */
    private function render_single_file(array $file): string {
        $file_type = $file['type'] ?? '';
        $file_name = '';
        $file_url = '';

        // 处理不同类型的文件
        switch ($file_type) {
            case 'file':
                $file_data = $file['file'] ?? [];
                $file_url = $file_data['url'] ?? '';
                $file_name = $file['name'] ?? basename($file_url);
                break;
            case 'external':
                $file_data = $file['external'] ?? [];
                $file_url = $file_data['url'] ?? '';
                $file_name = $file['name'] ?? basename($file_url);
                break;
            default:
                Notion_To_WordPress_Helper::debug_log(
                    '未知的文件类型: ' . $file_type,
                    'Record Files'
                );
                return '';
        }

        if (empty($file_url) || empty($file_name)) {
            return '';
        }

        // 检查是否为图片文件
        if ($this->is_image_file($file_name)) {
            return $this->render_file_thumbnail($file_url, $file_name);
        } else {
            return $this->render_file_link($file_url, $file_name);
        }
    }

    /**
     * 检查是否为图片文件
     *
     * @since 1.1.1
     * @param string $filename 文件名
     * @return bool
     */
    private function is_image_file(string $filename): bool {
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $image_extensions);
    }

    /**
     * 渲染文件缩略图（用于图片文件）
     *
     * @since 1.1.1
     * @param string $file_url 文件URL
     * @param string $file_name 文件名
     * @return string HTML内容
     */
    private function render_file_thumbnail(string $file_url, string $file_name): string {
        $display_url = $file_url;

        // 处理Notion临时URL
        if ($this->is_notion_temp_url($file_url)) {
            $attachment_id = $this->download_and_insert_image($file_url, $file_name);

            if (is_numeric($attachment_id) && $attachment_id > 0) {
                $local_url = wp_get_attachment_url($attachment_id);
                if ($local_url) {
                    $display_url = $local_url;
                    Notion_To_WordPress_Helper::debug_log(
                        '文件缩略图下载成功: ' . $file_name,
                        'Record Files'
                    );
                } else {
                    Notion_To_WordPress_Helper::error_log(
                        '文件缩略图下载后获取本地URL失败: ' . $file_name,
                        'Record Files'
                    );
                    return $this->render_file_link($file_url, $file_name);
                }
            } else {
                Notion_To_WordPress_Helper::error_log(
                    '文件缩略图下载失败: ' . $file_name,
                    'Record Files'
                );
                return $this->render_file_link($file_url, $file_name);
            }
        }

        return '<div class="notion-file-thumbnail">' .
               '<img class="notion-lazy-image" data-src="' . esc_url($display_url) . '" alt="' . esc_attr($file_name) . '" src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHJlY3Qgd2lkdGg9IjEwMCUiIGhlaWdodD0iMTAwJSIgZmlsbD0iI2YwZjBmMCIvPjwvc3ZnPg==">' .
               '<span class="notion-file-name">' . esc_html($file_name) . '</span>' .
               '</div>';
    }

    /**
     * 渲染文件链接（用于非图片文件）
     *
     * @since 1.1.1
     * @param string $file_url 文件URL
     * @param string $file_name 文件名
     * @return string HTML内容
     */
    private function render_file_link(string $file_url, string $file_name): string {
        return '<div class="notion-file-link">' .
               '<a href="' . esc_url($file_url) . '" target="_blank" rel="noopener noreferrer" download>' .
               '<span class="notion-file-icon">📎</span>' .
               '<span class="notion-file-name">' . esc_html($file_name) . '</span>' .
               '</a>' .
               '</div>';
    }

    /**
     * 智能检测最适合的视图类型
     *
     * @since 1.1.1
     * @param array $records 记录数组
     * @param array $database_info 数据库信息
     * @return string 视图类型
     */
    private function detect_optimal_view_type(array $records, array $database_info): string {
        // 检查是否有封面图片
        $has_covers = false;
        $cover_count = 0;

        foreach ($records as $record) {
            if (!empty($record['cover'])) {
                $cover_count++;
            }
        }

        // 如果超过50%的记录有封面图片，使用画廊视图
        if ($cover_count > 0 && ($cover_count / count($records)) >= 0.5) {
            $has_covers = true;
        }

        // 检查数据库属性数量
        $properties = $database_info['properties'] ?? [];
        $property_count = count($properties);

        // 视图选择逻辑
        if ($has_covers) {
            Notion_To_WordPress_Helper::debug_log(
                '检测到封面图片，选择画廊视图。封面数量: ' . $cover_count . '/' . count($records),
                'Database View'
            );
            return 'gallery';
        } elseif ($property_count > 5) {
            Notion_To_WordPress_Helper::debug_log(
                '检测到多属性，选择表格视图。属性数量: ' . $property_count,
                'Database View'
            );
            return 'table';
        } else {
            Notion_To_WordPress_Helper::debug_log(
                '使用默认列表视图。属性数量: ' . $property_count,
                'Database View'
            );
            return 'list';
        }
    }

    /**
     * 使用指定视图类型渲染数据库
     *
     * @since 1.1.1
     * @param array $records 记录数组
     * @param array $database_info 数据库信息
     * @param string $view_type 视图类型
     * @return string HTML内容
     */
    private function render_database_with_view(array $records, array $database_info, string $view_type): string {
        switch ($view_type) {
            case 'gallery':
                return $this->render_gallery_view($records, $database_info);
            case 'table':
                return $this->render_table_view($records, $database_info);
            case 'list':
            default:
                return $this->render_list_view($records, $database_info);
        }
    }

    /**
     * 渲染列表视图（默认视图）
     *
     * @since 1.1.1
     * @param array $records 记录数组
     * @param array $database_info 数据库信息
     * @return string HTML内容
     */
    private function render_list_view(array $records, array $database_info): string {
        $html = '<div class="notion-database-preview notion-database-list">';
        $html .= '<div class="notion-database-records">';

        foreach ($records as $record) {
            $html .= $this->render_single_database_record($record, $database_info);
        }

        $html .= '</div>'; // 关闭 notion-database-records
        $html .= '</div>'; // 关闭 notion-database-preview

        return $html;
    }

    /**
     * 渲染画廊视图
     *
     * @since 1.1.1
     * @param array $records 记录数组
     * @param array $database_info 数据库信息
     * @return string HTML内容
     */
    private function render_gallery_view(array $records, array $database_info): string {
        $html = '<div class="notion-database-preview notion-database-gallery">';
        $html .= '<div class="notion-database-records notion-gallery-grid">';

        foreach ($records as $record) {
            $html .= $this->render_single_database_record($record, $database_info);
        }

        $html .= '</div>'; // 关闭 notion-database-records
        $html .= '</div>'; // 关闭 notion-database-preview

        return $html;
    }

    /**
     * 渲染表格视图
     *
     * @since 1.1.1
     * @param array $records 记录数组
     * @param array $database_info 数据库信息
     * @return string HTML内容
     */
    private function render_table_view(array $records, array $database_info): string {
        $html = '<div class="notion-database-preview notion-database-table">';

        // 渲染表格头部
        $html .= $this->render_table_header($database_info);

        // 渲染表格内容
        $html .= '<div class="notion-table-body">';
        foreach ($records as $record) {
            $html .= $this->render_table_row($record, $database_info);
        }
        $html .= '</div>'; // 关闭 notion-table-body

        $html .= '</div>'; // 关闭 notion-database-preview

        return $html;
    }

    /**
     * 渲染表格头部
     *
     * @since 1.1.1
     * @param array $database_info 数据库信息
     * @return string HTML内容
     */
    private function render_table_header(array $database_info): string {
        $properties = $database_info['properties'] ?? [];

        $html = '<div class="notion-table-header">';
        $html .= '<div class="notion-table-row notion-table-header-row">';

        // 标题列
        $html .= '<div class="notion-table-cell notion-table-header-cell">' . __('标题', 'notion-to-wordpress') . '</div>';

        // 属性列（最多显示5个主要属性）
        $displayed_props = 0;
        $max_props = 5;

        foreach ($properties as $prop_name => $prop_config) {
            if ($displayed_props >= $max_props) break;

            $prop_type = $prop_config['type'] ?? '';
            // 跳过title类型（已经有标题列了）
            if ($prop_type === 'title') continue;

            $html .= '<div class="notion-table-cell notion-table-header-cell">' . esc_html($prop_name) . '</div>';
            $displayed_props++;
        }

        $html .= '</div>'; // 关闭 notion-table-header-row
        $html .= '</div>'; // 关闭 notion-table-header

        return $html;
    }

    /**
     * 渲染表格行
     *
     * @since 1.1.1
     * @param array $record 记录数据
     * @param array $database_info 数据库信息
     * @return string HTML内容
     */
    private function render_table_row(array $record, array $database_info): string {
        $properties = $record['properties'] ?? [];
        $db_properties = $database_info['properties'] ?? [];

        $html = '<div class="notion-table-row">';

        // 标题单元格（包含图标）
        $title = $this->extract_record_title($properties);
        $icon_html = $this->render_record_icon($record);

        $html .= '<div class="notion-table-cell notion-table-title-cell">';
        if (!empty($icon_html)) {
            $html .= $icon_html;
        }
        $html .= esc_html($title);
        $html .= '</div>';

        // 属性单元格
        $displayed_props = 0;
        $max_props = 5;

        foreach ($db_properties as $prop_name => $prop_config) {
            if ($displayed_props >= $max_props) break;

            $prop_type = $prop_config['type'] ?? '';
            // 跳过title类型
            if ($prop_type === 'title') continue;

            $prop_value = '';
            if (isset($properties[$prop_name])) {
                $prop_value = $this->format_property_for_preview($properties[$prop_name], $prop_type);
            }

            $html .= '<div class="notion-table-cell">' . $prop_value . '</div>';
            $displayed_props++;
        }

        $html .= '</div>'; // 关闭 notion-table-row

        return $html;
    }

    /**
     * 渲染渐进式加载容器
     *
     * @since 1.1.1
     * @param array $records 剩余记录
     * @param array $database_info 数据库信息
     * @param string $view_type 视图类型
     * @param string $database_id 数据库ID
     * @return string HTML内容
     */
    private function render_progressive_loading_container(array $records, array $database_info, string $view_type, string $database_id): string {
        $records_json = base64_encode(json_encode([
            'records' => $records,
            'database_info' => $database_info,
            'view_type' => $view_type,
            'database_id' => $database_id
        ]));

        $html = '<div class="notion-progressive-loading" data-records="' . esc_attr($records_json) . '">';
        $html .= '<div class="notion-loading-trigger">';
        $html .= '<button class="notion-load-more-btn" onclick="NotionProgressiveLoader.loadMore(this)">';
        $html .= '<span class="notion-loading-text">' . sprintf(__('加载更多记录 (%d)', 'notion-to-wordpress'), count($records)) . '</span>';
        $html .= '<span class="notion-loading-spinner" style="display: none;">⏳ ' . __('加载中...', 'notion-to-wordpress') . '</span>';
        $html .= '</button>';
        $html .= '</div>';
        $html .= '<div class="notion-progressive-content"></div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * 获取缓存统计信息
     *
     * @since 1.1.1
     * @return array
     */
    public function get_performance_stats(): array {
        $api_stats = Notion_API::get_cache_stats();

        return [
            'api_cache' => $api_stats,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
        ];
    }

    /**
     * 处理AJAX请求获取记录详情
     *
     * @since 1.1.1
     */
    public function ajax_get_record_details(): void {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'notion_record_details')) {
            wp_die(__('安全验证失败', 'notion-to-wordpress'));
        }

        $record_id = sanitize_text_field($_POST['record_id'] ?? '');
        if (empty($record_id)) {
            wp_send_json_error(__('记录ID不能为空', 'notion-to-wordpress'));
        }

        try {
            $notion_api = new Notion_API(get_option('notion_to_wordpress_options')['api_key'] ?? '');
            $record_details = $notion_api->get_page_details($record_id);

            if (empty($record_details)) {
                wp_send_json_error(__('无法获取记录详情', 'notion-to-wordpress'));
            }

            // 格式化返回数据
            $formatted_details = [
                'id' => $record_details['id'] ?? '',
                'created_time' => $record_details['created_time'] ?? '',
                'last_edited_time' => $record_details['last_edited_time'] ?? '',
                'url' => $record_details['url'] ?? '',
                'properties_count' => count($record_details['properties'] ?? [])
            ];

            wp_send_json_success($formatted_details);

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log(
                'AJAX获取记录详情失败: ' . $e->getMessage(),
                'AJAX Record Details'
            );
            wp_send_json_error(sprintf(__('获取记录详情失败: %s', 'notion-to-wordpress'), $e->getMessage()));
        }
    }

    /**
     * 注册AJAX处理器
     *
     * @since 1.1.1
     */
    public function register_ajax_handlers(): void {
        add_action('wp_ajax_notion_get_record_details', [$this, 'ajax_get_record_details']);
        add_action('wp_ajax_nopriv_notion_get_record_details', [$this, 'ajax_get_record_details']);
    }
}