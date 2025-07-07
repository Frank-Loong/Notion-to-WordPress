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
            'excerpt'        => 'Excerpt,摘要',
            'featured_image' => 'Featured Image,特色图片',
            'categories'     => 'Categories,分类',
            'tags'           => 'Tags,标签',
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
                    $html .= $block_html;
                    
                    // 检查是否有子块
                    if (isset($block['has_children']) && $block['has_children'] && !$is_list_item) {
                        $html .= $this->_convert_child_blocks($block, $notion_api);
                    }
                } catch (Exception $e) {
                    // 记录错误并添加注释
                    Notion_To_WordPress_Helper::error_log('Notion块转换错误: ' . $e->getMessage(), 'Block Convert');
                    $html .= '<!-- 块转换错误: ' . esc_html($block_type) . ' -->';
                }
            } else {
                // 未知块类型，添加调试注释
                $html .= '<!-- 未支持的块类型: ' . esc_html($block_type) . ' -->';
                Notion_To_WordPress_Helper::debug_log('未支持的Notion块类型: ' . $block_type, 'Block Convert');
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

        // 优先使用已递归获取的 children，避免重复调用 API
        if ( isset( $block['children'] ) && is_array( $block['children'] ) ) {
            $child_blocks = $block['children'];
        } else {
            $child_blocks = $notion_api->get_page_content( $block['id'] );
        }

        return ! empty( $child_blocks ) ? $this->convert_blocks_to_html( $child_blocks, $notion_api ) : '';
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
            return '<!-- 无效的嵌入URL -->';
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
        
        // 通用视频
        $extension = pathinfo($url, PATHINFO_EXTENSION);
        if (in_array(strtolower($extension), ['mp4', 'webm', 'ogg'])) {
            return '<div class="notion-video"><video controls width="100%"><source src="' . esc_url($url) . '" type="video/' . esc_attr($extension) . '">您的浏览器不支持视频标签。</video></div>';
        }
        
        // 无法识别的视频格式，提供链接
        return '<div class="notion-video-link"><a href="' . esc_url($url) . '" target="_blank">查看视频</a></div>';
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
                $content = '<a href="' . esc_url($href) . '" target="_blank">' . $content . '</a>';
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
                    Notion_To_WordPress_Helper::error_log( '不允许的图片类型：' . $mime_type, 'Notion Image' );
                    // 继续，但记录日志
                }
            }

            // 检查大小
            if ( $file_size > 0 && $file_size > $max_size_bytes ) {
                Notion_To_WordPress_Helper::error_log( sprintf( '图片文件过大（%sMB），超过限制（%sMB）', round( $file_size / ( 1024 * 1024 ), 2 ), $max_size_mb ), 'Notion Image' );
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
                $deleted_count = $this->cleanup_deleted_pages($pages);
                $stats['deleted'] = $deleted_count;
                error_log('Notion to WordPress: 删除检测完成，删除了 ' . $deleted_count . ' 个页面');
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
        // 计算列宽（Notion API 提供 ratio，可选）
        $ratio = $block['column']['width_ratio'] ?? 1;
        $width_percent = 100 / max(1, $ratio); // 简化处理
        $html = '<div class="notion-column" style="flex:1 1 ' . esc_attr($width_percent) . '%;">';
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
            return '<!-- 无效的 PDF URL -->';
        }

        $caption = $this->extract_rich_text( $pdf_data['caption'] ?? [] );

        // 生成预览 HTML（使用多种预览方案）
        $build_html = function ( string $src, string $download ) use ( $caption ) : string {
            $html = '<div class="notion-pdf">';

            // 尝试使用浏览器原生PDF预览
            $html .= '<div class="pdf-preview-container">';
            $html .= '<object data="' . esc_url( $src ) . '" type="application/pdf" width="100%" height="600">';
            $html .= '<embed src="' . esc_url( $src ) . '" type="application/pdf" width="100%" height="600" />';
            $html .= '<p>您的浏览器不支持PDF预览。<a href="' . esc_url( $download ) . '" target="_blank" rel="noopener">点击下载PDF文件</a></p>';
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
            return '<!-- PDF 下载失败 -->';
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
            Notion_To_WordPress_Helper::error_log( '下载附件失败: ' . $tmp->get_error_message(), 'Notion File' );
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
                return new WP_Error( 'invalid_pdf', '无效的PDF文件或包含不安全内容' );
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
            Notion_To_WordPress_Helper::error_log( 'media_handle_sideload 错误: ' . $attachment_id->get_error_message(), 'Notion File' );
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
     * @since    1.0.10
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
     * @since    1.0.10
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

            // 比较时间戳
            $notion_timestamp = strtotime($notion_last_edited);
            $local_timestamp = strtotime($local_last_sync);

            if ($notion_timestamp > $local_timestamp) {
                // Notion页面更新时间晚于本地同步时间，需要同步
                Notion_To_WordPress_Helper::debug_log(
                    "页面有更新需要同步: {$page_id}, Notion: {$notion_last_edited}, Local: {$local_last_sync}",
                    'Incremental Sync'
                );
                $pages_to_sync[] = $page;
            } else {
                // 页面无变化，跳过
                Notion_To_WordPress_Helper::debug_log(
                    "页面无变化跳过: {$page_id}",
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
     * @since    1.0.10
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
     * @since    1.0.10
     * @param    string    $page_id              Notion页面ID
     * @param    string    $notion_last_edited   Notion最后编辑时间
     */
    private function update_page_sync_time(string $page_id, string $notion_last_edited): void {
        $post_id = $this->get_post_by_notion_id($page_id);

        if (!$post_id) {
            return;
        }

        // 更新同步时间戳
        update_post_meta($post_id, '_notion_last_sync_time', current_time('mysql'));
        update_post_meta($post_id, '_notion_last_edited_time', $notion_last_edited);

        Notion_To_WordPress_Helper::debug_log(
            "更新页面同步时间: {$page_id}, 编辑时间: {$notion_last_edited}",
            'Incremental Sync'
        );
    }
}