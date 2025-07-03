<?php
declare(strict_types=1);

/**
 * 负责处理Notion页面转换和导入的类
 *
 * @since      1.0.9
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */

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
     * 导入锁超时时间
     *
     * @since    1.0.10
     * @access   private
     * @var      int    $lock_timeout    导入锁超时时间（秒）
     */
    private int $lock_timeout;
    
    /**
     * 当前正在处理的 Notion 页面 ID（用于内部锚点转换）
     * @var string
     */
    private string $current_page_id = '';
    
    /**
     * 构造函数
     *
     * @since    1.0.8
     * @param    Notion_API    $notion_api     Notion API实例
     * @param    string        $database_id    数据库ID
     * @param    array         $field_mapping  字段映射
     * @param    int           $lock_timeout   导入锁超时时间
     */
    public function __construct(Notion_API $notion_api, string $database_id, array $field_mapping = [], int $lock_timeout = 300) {
        $this->notion_api = $notion_api;
        $this->database_id = $database_id;
        $this->field_mapping = $field_mapping;
        $this->lock_timeout = $lock_timeout;
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
        if (empty($page) || !isset($page['id'])) {
            Notion_To_WordPress_Helper::error_log( '导入失败：页面数据为空或缺少 ID', 'Notion Import' );
            return false;
        }

        $page_id = $page['id'] ?? '';
        $page_title = $page['properties']['title']['title'][0]['plain_text'] ?? '(无标题)';
        Notion_To_WordPress_Helper::debug_log( '开始导入页面内容: ' . $page_title . ' | ' . $page_id, 'Notion Import', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
        
        // 记录开始时间，用于计算执行时间
        $start_time = microtime(true);
        
        // 记录当前页面 ID 供后续锚点处理
        $this->current_page_id = $page_id;
        
        try {
            // 提取页面元数据
            $metadata = $this->extract_page_metadata($page);
            
            if (empty($metadata['title'])) {
                Notion_To_WordPress_Helper::error_log( '导入失败：页面 ' . $page_id . ' 缺少标题', 'Notion Import' );
                return false;
            }
            
            // 如果页面状态为"草稿"，则跳过
            if ($metadata['status'] === 'draft') {
                Notion_To_WordPress_Helper::debug_log( '页面状态为草稿，跳过: ' . $page_title, 'Notion Import', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
                return true;
            }
            
            // 检查内存使用情况
            $memory_usage = round(memory_get_usage() / 1024 / 1024, 2);
            $memory_peak = round(memory_get_peak_usage() / 1024 / 1024, 2);
            Notion_To_WordPress_Helper::debug_log(
                "获取块前内存: {$memory_usage}MB (峰值: {$memory_peak}MB)",
                'Memory',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG
            );
            
            // 获取页面内容
            Notion_To_WordPress_Helper::debug_log( '获取页面块内容: ' . $page_id, 'Notion Import', Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG );
            $blocks = $this->notion_api->get_page_content($page['id']);
            if (empty($blocks)) {
                Notion_To_WordPress_Helper::error_log( '导入失败：页面 ' . $page_id . ' 获取 blocks 为空', 'Notion Import' );
                return false;
            }
            Notion_To_WordPress_Helper::debug_log( '获取到 ' . count($blocks) . ' 个顶级块', 'Notion Import', Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG );
            
            // 检查API调用后的内存使用情况
            $memory_usage = round(memory_get_usage() / 1024 / 1024, 2);
            $memory_peak = round(memory_get_peak_usage() / 1024 / 1024, 2);
            Notion_To_WordPress_Helper::debug_log(
                "获取块后内存: {$memory_usage}MB (峰值: {$memory_peak}MB)",
                'Memory',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG
            );
            
            // 重置处理状态
            $this->processed_blocks = [];
            
            // 将Notion块转换为HTML
            Notion_To_WordPress_Helper::debug_log( '开始转换块为HTML', 'Notion Import', Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG );
            $raw_content = $this->convert_blocks_to_html($blocks, $this->notion_api);
            Notion_To_WordPress_Helper::debug_log( '块转换完成，HTML长度: ' . strlen($raw_content), 'Notion Import', Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG );
            
            // 检查转换后的内存使用情况
            $memory_usage = round(memory_get_usage() / 1024 / 1024, 2);
            $memory_peak = round(memory_get_peak_usage() / 1024 / 1024, 2);
            Notion_To_WordPress_Helper::debug_log(
                "HTML转换后内存: {$memory_usage}MB (峰值: {$memory_peak}MB)",
                'Memory',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG
            );
            
            // 应用内容过滤
            $content = Notion_To_WordPress_Helper::custom_kses($raw_content);
            
            // 检查页面是否已存在
            $existing_post_id = $this->get_post_by_notion_id($page['id']);
            
            // 获取作者ID
            $author_id = $this->get_author_id();
            
            // 创建或更新文章
            Notion_To_WordPress_Helper::debug_log( '准备' . ($existing_post_id ? '更新' : '创建') . 'WordPress文章', 'Notion Import', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
            $post_id = $this->create_or_update_post($metadata, $content, $author_id, $page['id'], $existing_post_id);
            
            if (is_wp_error($post_id)) {
                Notion_To_WordPress_Helper::error_log( '导入失败：WP_Error -> ' . $post_id->get_error_message(), 'Notion Import' );
                return false;
            }
            
            if (!$post_id) {
                Notion_To_WordPress_Helper::error_log( '创建/更新WordPress文章失败', 'Notion Import' );
                return false;
            }
            
            // 分类 / 标签 / 特色图
            $this->apply_taxonomies($post_id, $metadata);
            $this->apply_featured_image($post_id, $metadata);
            
            // 应用自定义字段
            if (!empty($metadata['custom_fields'])) {
                $this->apply_custom_fields($post_id, $metadata['custom_fields']);
            }
            
            // 计算总执行时间
            $execution_time = round(microtime(true) - $start_time, 2);
            Notion_To_WordPress_Helper::debug_log( 
                'WordPress文章ID: ' . $post_id . ' | 执行时间: ' . $execution_time . '秒',
                'Notion Import', 
                Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
            );
            
        } catch (Exception $e) {
            // 计算异常发生时的执行时间
            $execution_time = round(microtime(true) - $start_time, 2);
            $memory_usage = round(memory_get_usage() / 1024 / 1024, 2);
            $memory_peak = round(memory_get_peak_usage() / 1024 / 1024, 2);
            
            Notion_To_WordPress_Helper::error_log( '导入页面异常: ' . $page_title . ' | ' . $page_id . ' - ' . $e->getMessage(), 'Notion Import' );
            Notion_To_WordPress_Helper::error_log( 
                "异常详情: 执行时间 {$execution_time}秒, 内存 {$memory_usage}MB (峰值: {$memory_peak}MB)\n" . $e->getTraceAsString(),
                'Notion Error'
            );
            return false;
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
        $options       = get_option( 'notion_to_wordpress_options', [] );
        $field_mapping = $options['field_mapping'] ?? [
            'title'          => 'title,标题',
            'status'         => 'status,状态',
            'post_type'      => 'type,类型',
            'date'           => 'date,日期',
            'excerpt'        => 'summary,摘要',
            'featured_image' => 'featured image,特色图片',
            'categories'     => 'category,分类',
            'tags'           => 'tags,标签',
        ];

        // 将逗号分隔的字符串转换为数组
        foreach ( $field_mapping as $key => $value ) {
            $field_mapping[ $key ] = array_map( 'trim', explode( ',', $value ) );
        }

        $metadata['title'] = $this->get_property_value( $props, $field_mapping['title'], 'title', 'plain_text' );

        // 兼容新版 Notion "Status" 属性（类型为 status）以及旧版 select
        $status_val = $this->get_property_value( $props, $field_mapping['status'], 'select', 'name' );
        if ( ! $status_val ) {
            $status_val = $this->get_property_value( $props, $field_mapping['status'], 'status', 'name' );
        }

        // 若仍为空，尝试使用 visibility 字段
        if ( ! $status_val && isset( $field_mapping['visibility'] ) ) {
            $status_val = $this->get_property_value( $props, $field_mapping['visibility'], 'select', 'name' );
            if ( ! $status_val ) {
                $status_val = $this->get_property_value( $props, $field_mapping['visibility'], 'status', 'name' );
            }
        }

        // ---- 状态值清洗：去除 Emoji、控制字符、零宽空格等不可见字符 ----
        $raw_status = preg_replace( '/[[:^print:]\p{C}]+/u', '', $status_val );
        $raw_status_lc = strtolower( trim( $raw_status ) );

        if ( false !== strpos( $raw_status_lc, 'private' ) || false !== mb_strpos( $raw_status, '私密' ) ) {
            $metadata['status'] = 'private';
        } elseif ( false !== strpos( $raw_status_lc, 'publish' ) || false !== mb_strpos( $raw_status, '已发布' ) ) {
            $metadata['status'] = 'publish';
        } elseif ( false !== strpos( $raw_status_lc, 'invisible' ) || false !== mb_strpos( $raw_status, '隐藏' ) ) {
            $metadata['status'] = 'draft';
        } else {
            $metadata['status'] = 'draft';
            // 写入调试日志方便追踪
            Notion_To_WordPress_Helper::debug_log(
                '未能识别的状态值: ' . $status_val . ' (清洗后: ' . $raw_status . ')，已回退为 draft',
                'Notion Warn',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_WARN
            );
        }

        // 添加调试日志（使用统一日志助手）
        Notion_To_WordPress_Helper::debug_log(
            'Notion页面状态: ' . $status_val . ' 转换为WordPress状态: ' . $metadata['status'],
            'Notion Info',
            Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
        );

        $metadata['post_type']      = $this->get_property_value( $props, $field_mapping['post_type'], 'select', 'name' ) ?? 'post';
        $metadata['date']           = $this->get_property_value( $props, $field_mapping['date'], 'date', 'start' );
        $metadata['excerpt']        = $this->get_property_value( $props, $field_mapping['excerpt'], 'rich_text', 'plain_text' );
        $metadata['featured_image'] = $this->get_property_value( $props, $field_mapping['featured_image'], 'files', 'url' );
        
        // 若用户在 Notion 创建了 Password 文本属性，则读取其值，供加密文章使用
        $metadata['password']       = $this->get_property_value( $props, [ 'password', '密码', 'encryptpassword' ], 'rich_text', 'plain_text' );

        // 处理分类和标签
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

        $tags_prop = $this->get_property_value( $props, $field_mapping['tags'], 'multi_select' );
        if ( $tags_prop ) {
            $tags = [];
            foreach ( $tags_prop as $tag ) {
                $tags[] = $tag['name'];
            }
            $metadata['tags'] = $tags;
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
        // 构建小写索引映射以实现大小写无关
        $props_ci = [];
        foreach ( $props as $k => $v ) {
            $props_ci[ strtolower( $k ) ] = $v;
        }

        foreach ( $names as $name ) {
            $lookup = strtolower( $name );
            if ( isset( $props_ci[ $lookup ][ $type ] ) ) {
                $prop = $props_ci[ $lookup ][ $type ];
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
            }
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

                    // 为可定位块添加锚点；列容器不需要，避免干扰布局
                    if ( ! in_array( $block_type, [ 'column', 'column_list' ], true ) ) {
                        $anchor_id = str_replace( '-', '', $block['id'] );
                        $html .= '<span id="' . esc_attr( $anchor_id ) . '"></span>' . $block_html;
                    } else {
                        $html .= $block_html;
                    }
                    
                    // 检查是否有子块
                    if (isset($block['has_children']) && $block['has_children'] && !$is_list_item) {
                        $html .= $this->_convert_child_blocks($block, $notion_api);
                    }
                } catch (Exception $e) {
                    // 记录错误并添加注释
                    Notion_To_WordPress_Helper::error_log( '块转换异常 ' . $block_type . ': ' . $e->getMessage(), 'Block Convert' );
                    $html .= '<!-- 块转换错误: ' . esc_html( $block_type ) . ' -->';
                }
            } else {
                // 未知块类型，添加调试注释
                $html .= '<!-- 未支持的块类型: ' . esc_html($block_type) . ' -->';
                Notion_To_WordPress_Helper::debug_log('未支持的Notion块类型: ' . $block_type);
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
        // 若段落无文本且无子块，跳过以避免产生空元素影响布局
        if ( empty( trim( $text ) ) && ! ( $block['has_children'] ?? false ) ) {
            return '';
        }

        $html = '<p>' . ( $text ?: '&nbsp;' ) . '</p>';
        $html .= $this->_convert_child_blocks($block, $notion_api);
        return $html;
    }

    private function _convert_block_heading_1(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['heading_1']['rich_text']);
        $anchor = str_replace( '-', '', $block['id'] );
        return '<h1 id="' . esc_attr( $anchor ) . '">' . $text . '</h1>' . $this->_convert_child_blocks($block, $notion_api);
    }
    
    private function _convert_block_heading_2(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['heading_2']['rich_text']);
        $anchor = str_replace( '-', '', $block['id'] );
        return '<h2 id="' . esc_attr( $anchor ) . '">' . $text . '</h2>' . $this->_convert_child_blocks($block, $notion_api);
    }

    private function _convert_block_heading_3(array $block, Notion_API $notion_api): string {
        $text = $this->extract_rich_text($block['heading_3']['rich_text']);
        $anchor = str_replace( '-', '', $block['id'] );
        return '<h3 id="' . esc_attr( $anchor ) . '">' . $text . '</h3>' . $this->_convert_child_blocks($block, $notion_api);
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

        // 若图片 URL 不是 Notion 临时受保护链接，则直接外链引用
        if ( ! $this->is_notion_temp_url( $url ) ) {
            return '<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $caption ) . '"/><figcaption>' . esc_html( $caption ) . '</figcaption></figure>';
        }

        // Notion 临时链接 —— 尝试下载到媒体库
        $attachment_id = $this->download_and_insert_image( $url, $caption );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            // 图片入队异步下载，但目前还没有本地副本
            // 使用数据占位符，便于后续替换
            $placeholder_id = 'ntw-img-' . substr(md5($url), 0, 10);
            $notice = esc_html__( '图片正在后台下载中...', 'notion-to-wordpress' );
            $figcaption = $caption ? esc_html( $caption ) . ' - ' . $notice : $notice;
            
            // 将原始URL保存为data属性，便于下载完成后替换
            return '<figure class="wp-block-image size-large notion-temp-image" data-ntw-url="' . esc_attr($url) . '" id="' . esc_attr($placeholder_id) . '">
                <img src="' . esc_url( $url ) . '" alt="' . esc_attr( $caption ) . '">
                <figcaption>' . $figcaption . '</figcaption>
            </figure>';
        }

        // 下载成功，使用本地附件 URL
        $local_url = wp_get_attachment_url( $attachment_id );
        return '<figure class="wp-block-image size-large"><img src="' . esc_url( $local_url ) . '" alt="' . esc_attr( $caption ) . '"><figcaption>' . esc_html( $caption ) . '</figcaption></figure>';
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

    private function _convert_block_equation(array $block, Notion_API $notion_api): string {
        $expression = str_replace('\\', '\\\\', $block['equation']['expression']);
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
        
        // PDF 文件预览
        if ( preg_match( '/\.pdf(\?|$)/i', $url ) ) {
            // 尝试浏览器原生 <embed>，如不支持也可点链接下载
            return '<div class="notion-embed notion-embed-pdf"><embed src="' . esc_url( $url ) . '" type="application/pdf" width="100%" height="600px" /><p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . __( '下载 PDF', 'notion-to-wordpress' ) . '</a></p></div>';
        }

        // 通用网页嵌入
        return '<div class="notion-embed"><iframe src="' . esc_url($url) . '" width="100%" height="500" frameborder="0" loading="lazy" referrerpolicy="no-referrer"></iframe></div>';
    }

    /**
     * 转换 Notion PDF 块
     *
     * @param array       $block      块数据
     * @param Notion_API  $notion_api API 实例
     * @return string                  HTML 代码
     * @since 1.1.1
     */
    private function _convert_block_pdf(array $block, Notion_API $notion_api): string {
        $pdf_data = $block['pdf'] ?? [];
        $type     = $pdf_data['type'] ?? 'external';
        $url      = '';

        if ( 'file' === $type ) {
            $url = $pdf_data['file']['url'] ?? '';
        } else {
            $url = $pdf_data['external']['url'] ?? '';
        }

        if ( empty( $url ) ) {
            return '<!-- 无效的 PDF URL -->';
        }

        // 提取 caption（如有）
        $caption = '';
        if ( isset( $pdf_data['caption'] ) ) {
            $caption = $this->extract_rich_text( $pdf_data['caption'] );
        }

        // 非 Notion 临时链接，直接嵌入
        if ( ! $this->is_notion_temp_url( $url ) ) {
            $embed    = '<embed src="' . esc_url( $url ) . '" type="application/pdf" width="100%" height="600px" />';
            $download = '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . __( '下载 PDF', 'notion-to-wordpress' ) . '</a></p>';
            return '<div class="notion-pdf">' . $embed . $download . '</div>';
        }

        // Notion 临时链接：尝试下载
        $file_name      = basename( parse_url( $url, PHP_URL_PATH ) );
        $attachment_id  = $this->download_and_insert_file( $url, $caption, $file_name );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            // 下载失败 => 使用外链（可能过期）
            $embed    = '<embed src="' . esc_url( $url ) . '" type="application/pdf" width="100%" height="600px" />';
            $download = '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . __( '下载 PDF（外链，可能过期）', 'notion-to-wordpress' ) . '</a></p>';
            return '<div class="notion-pdf notion-temp-pdf">' . $embed . $download . '</div>';
        }

        $local_url = wp_get_attachment_url( $attachment_id );
        $embed     = '<embed src="' . esc_url( $local_url ) . '" type="application/pdf" width="100%" height="600px" />';
        $download  = '<p><a href="' . esc_url( $local_url ) . '" target="_blank" rel="noopener" download>' . __( '下载 PDF', 'notion-to-wordpress' ) . '</a></p>';

        return '<div class="notion-pdf">' . $embed . $download . '</div>';
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
            // 处理 inline equation
            if ( isset( $text['type'] ) && $text['type'] === 'equation' ) {
                $expr_raw = $text['equation']['expression'] ?? '';
                $expr = str_replace('\\', '\\\\', $expr_raw);
                $content = '<span class="notion-equation notion-equation-inline">$' . $expr . '$</span>';
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
                // 内/跨文章锚点转换
                $href_converted = $href;

                // ---- Notion 链接转换 ----
                if ( ( str_contains( $href, 'notion.so' ) || str_contains( $href, 'notion.site' ) ) ) {
                    $anchor_part = '';
                    $url_part    = $href;

                    // 拆分锚点
                    if ( str_contains( $href, '#' ) ) {
                        [$url_part, $anchor_part] = explode( '#', $href, 2 );
                    }

                    // 取路径末段尝试获取32位ID
                    $path      = wp_parse_url( $url_part, PHP_URL_PATH );
                    $segments  = explode( '/', rtrim( $path, '/' ) );
                    $last_part = end( $segments );

                    if ( preg_match( '/([0-9a-fA-F]{32})$/', $last_part, $m ) ) {
                        $trimmed   = $m[1];
                        $dashed_id = preg_replace( '/(.{8})(.{4})(.{4})(.{4})(.{12})/', '$1-$2-$3-$4-$5', $trimmed );

                        // 同页锚点
                        if ( $this->current_page_id && $dashed_id === $this->current_page_id ) {
                            $href_converted = $anchor_part ? '#' . $anchor_part : '#';
                        } else {
                            // 查询WordPress文章
                            $post_ids = get_posts([
                                'post_type'   => 'any',
                                'post_status' => 'publish',
                                'meta_query'  => [[
                                    'key'   => '_notion_page_id',
                                    'value' => $dashed_id,
                                ]],
                                'fields'      => 'ids',
                                'numberposts' => 1,
                            ]);

                            if ( ! empty( $post_ids ) ) {
                                $permalink = get_permalink( $post_ids[0] );
                                if ( $permalink ) {
                                    $href_converted = $permalink . ( $anchor_part ? '#' . $anchor_part : '' );
                                }
                            }
                        }
                    }
                }

                $content = '<a href="' . esc_url( $href_converted ) . '">' . $content . '</a>';
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

        // 若提供密码字段，则直接设为密码保护
        if ( ! empty( $metadata['password'] ) ) {
            $post_data['post_password'] = $metadata['password'];
            // 确保已发布状态
            $post_data['post_status'] = 'publish';
        }

        if (isset($metadata['date'])) {
            $post_data['post_date'] = $metadata['date'];
        }

        // ---- 权限兼容：在 WP-Cron 环境下 current_user 为 0，直接插入 "private" 或 "publish" 会被降级为 draft ----
        $switched_user = false;
        if ( 0 === get_current_user_id() && in_array( $post_data['post_status'], [ 'publish', 'private' ], true ) ) {
            wp_set_current_user( $author_id );
            $switched_user = true;
        }

        $post_id = 0;
        if ( $existing_post_id ) {
            $post_data['ID'] = $existing_post_id;
            $post_id = wp_update_post( $post_data, true );
        } else {
            $post_id = wp_insert_post( $post_data, true );
        }

        // 还原用户上下文
        if ( $switched_user ) {
            wp_set_current_user( 0 );
        }
         
        // 如果创建/更新成功，处理自定义字段
        if (!is_wp_error($post_id) && $post_id > 0) {
            // 注意：自定义字段在import_notion_page中统一处理，避免重复应用
            Notion_To_WordPress_Helper::debug_log( '文章' . ($existing_post_id ? '更新' : '创建') . '成功: ID ' . $post_id, 'Post', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );

            // ---- 状态校正：有时因权限或 WP 内部过滤导致状态被降级为 draft ----
            $intended_status = $post_data['post_status'];
            $current_status  = get_post_status($post_id);

            if (in_array($intended_status, ['private', 'publish'], true) && $current_status !== $intended_status) {
                $did_switch = false;
                if (0 === get_current_user_id()) {
                    wp_set_current_user($author_id);
                    $did_switch = true;
                }

                wp_update_post([
                    'ID'          => $post_id,
                    'post_status' => $intended_status,
                ]);

                if ($did_switch) {
                    wp_set_current_user(0);
                }

                Notion_To_WordPress_Helper::debug_log(
                    "强制校正文章状态: {$current_status} → {$intended_status} (Post ID: {$post_id})",
                    'Notion Info',
                    Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
                );
            }
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
        if ( ! empty( $metadata['featured_image'] ) ) {
            $this->set_featured_image( $post_id, $metadata['featured_image'] );
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
        if ( empty( $image_url ) ) {
            return;
        }

        // 如果不是 Notion 临时链接，则先尝试直接作为外链。部分主题可通过自定义字段读取。
        if ( ! $this->is_notion_temp_url( $image_url ) ) {
            update_post_meta( $post_id, '_ntw_external_thumbnail', esc_url_raw( $image_url ) );
        }

        // 直接入队，异步设置特色图
        Notion_Download_Queue::push([
            'type'        => 'image',
            'url'         => $image_url,
            'post_id'     => (int) $post_id,
            'is_featured' => true,
            'caption'     => get_the_title( $post_id ),
        ]);
        return;
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
        // 先检查是否已经有相同URL的附件
        $existing_id = $this->get_attachment_by_url( $url );
        if ( $existing_id > 0 ) {
            Notion_To_WordPress_Helper::debug_log( '找到已存在的图片附件: ' . $existing_id . ' 对应URL: ' . $url, 'Image', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
            return $existing_id;
        }
        
        // 直接入队，后台异步处理
        Notion_Download_Queue::push([
            'type'        => 'image',
            'url'         => $url,
            'post_id'     => 0,
            'is_featured' => false,
            'caption'     => $caption,
        ]);
        
        // 记录日志
        Notion_To_WordPress_Helper::debug_log( '图片入队下载: ' . $url, 'Image', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
        
        // 返回0表示需要后续处理
        return 0;
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
     * @return   array|WP_Error    导入结果统计或错误
     */
    public function import_pages() {
        // 创建锁，防止并发操作
        $lock = new Notion_To_WordPress_Lock($this->database_id, $this->lock_timeout);
        
        if (!$lock->acquire()) {
            return new WP_Error('lock_failed', '已有同步任务在进行中，请稍后再试。');
        }
        
        try {
            // 记录PHP执行环境信息
            $max_execution_time = ini_get('max_execution_time');
            $memory_limit = ini_get('memory_limit');
            Notion_To_WordPress_Helper::info_log(
                "PHP执行环境: 最大执行时间 {$max_execution_time}秒, 内存限制 {$memory_limit}",
                'System Info'
            );
            
            // 尝试增加执行时间
            @set_time_limit(600); // 尝试设置为10分钟
            $new_time_limit = ini_get('max_execution_time');
            if ($new_time_limit != $max_execution_time) {
                Notion_To_WordPress_Helper::info_log(
                    "已调整最大执行时间: {$max_execution_time}秒 → {$new_time_limit}秒",
                    'System Info'
                );
            }
            
            // 获取上次同步时间，若有则增量拉取（向前回溯5分钟以覆盖边缘情况）
            $opts          = get_option( 'notion_to_wordpress_options', [] );
            $last_sync_raw = $opts['last_sync_time'] ?? '';
            $filter        = [];

            if ( $last_sync_raw ) {
                $last_sync_gmt = gmdate( 'c', strtotime( $last_sync_raw ) - 300 ); // 5分钟缓冲
                $filter        = [
                    'timestamp'       => 'last_edited_time',
                    'last_edited_time' => [ 'on_or_after' => $last_sync_gmt ],
                ];
            }

            // 获取数据库中的页面（可能带筛选）
            $pages = $this->notion_api->get_database_pages( $this->database_id, $filter );
            
            // 若增量拉取无结果，则回退全量拉取一次（防止 last_sync_time 错误或 Notion 时间漂移）
            if ( empty( $pages ) && ! empty( $filter ) ) {
                Notion_To_WordPress_Helper::info_log( '增量同步无结果，回退全量拉取' );
                $filter = [];
                $pages  = $this->notion_api->get_database_pages( $this->database_id, [] );
            }

            if ( empty( $pages ) ) {
                $lock->release();
                return new WP_Error( 'no_pages', '未检索到任何页面。' );
            }
            
            Notion_To_WordPress_Helper::info_log( '获取到 ' . count($pages) . ' 个页面准备同步', 'Notion Pages' );
            
            $stats = [
                'total' => count($pages),
                'imported' => 0,
                'updated' => 0,
                'failed' => 0,
                'processed' => 0,
                'done' => false,
                'start_time' => time(),
            ];
            
            // 初始化进度缓存
            set_transient( 'ntw_sync_progress', $stats, 600 );

            foreach ($pages as $page) {
                // 记录内存使用情况
                $memory_usage = round(memory_get_usage() / 1024 / 1024, 2);
                $memory_peak = round(memory_get_peak_usage() / 1024 / 1024, 2);
                Notion_To_WordPress_Helper::debug_log(
                    "内存使用: {$memory_usage}MB (峰值: {$memory_peak}MB)",
                    'System',
                    Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG
                );
                
                // --- 详细日志：开始处理页面 ---
                $dbg_title = $page['properties']['title']['title'][0]['plain_text'] ?? '(无标题)';
                Notion_To_WordPress_Helper::debug_log( "开始处理页面 {$dbg_title} | {$page['id']}", 'Notion Page', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );

                // 检查页面是否已存在
                $existing_post_id = $this->get_post_by_notion_id($page['id']);
                
                try {
                    $result = $this->import_notion_page($page);
                } catch (Exception $page_e) {
                    Notion_To_WordPress_Helper::error_log(
                        "页面处理异常: {$dbg_title} | {$page['id']} - " . $page_e->getMessage() . "\n" . $page_e->getTraceAsString(),
                        'Notion Error'
                    );
                    $result = false;
                }
                
                // --- 处理返回 ---
                if ( is_wp_error( $result ) ) {
                    $stats['failed']++;
                    Notion_To_WordPress_Helper::error_log( '页面导入失败 ' . $dbg_title . ' | ' . $page['id'] . ' - WP_Error: ' . $result->get_error_message(), 'Notion Page' );
                    continue;
                }

                if ( $result ) {
                    if ($existing_post_id) {
                        $stats['updated']++;
                        Notion_To_WordPress_Helper::info_log( '页面更新成功 ' . $dbg_title . ' | ' . $page['id'] . ' - WordPress ID: ' . $existing_post_id, 'Notion Page' );
                    } else {
                        $stats['imported']++;
                        Notion_To_WordPress_Helper::info_log( '页面导入成功 ' . $dbg_title . ' | ' . $page['id'], 'Notion Page' );
                    }
                } else {
                    $stats['failed']++;
                    Notion_To_WordPress_Helper::error_log( '页面导入失败 ' . $dbg_title . ' | ' . $page['id'] . ' - 返回 false', 'Notion Page' );
                }

                $stats['processed']++;

                // 每处理5条或最后一条时更新 transient
                if ( $stats['processed'] % 5 === 0 || $stats['processed'] === $stats['total'] ) {
                    set_transient( 'ntw_sync_progress', $stats, 600 );
                    Notion_To_WordPress_Helper::debug_log( '更新同步进度: ' . $stats['processed'] . '/' . $stats['total'] . ' (导入:' . $stats['imported'] . ' 更新:' . $stats['updated'] . ' 失败:' . $stats['failed'] . ')', 'Notion Page', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
                }
            }

            // 标记完成
            $stats['done']      = true;
            $stats['end_time']  = time();
            set_transient( 'ntw_sync_progress', $stats, 600 );

            // 记录最终统计
            Notion_To_WordPress_Helper::info_log( '同步完成: 总计' . $stats['total'] . '页面, 导入' . $stats['imported'] . ', 更新' . $stats['updated'] . ', 失败' . $stats['failed'], 'Notion Page' );
            
            // 仅在实际处理过页面后更新同步时间
            if ( $stats['processed'] > 0 ) {
                $now = current_time( 'mysql' );
                update_option( 'notion_to_wordpress_last_sync', $now, false );

                // 同时写入主设置数组，供增量同步使用
                $opts = get_option( 'notion_to_wordpress_options', [] );
                $opts['last_sync_time'] = $now;
                update_option( 'notion_to_wordpress_options', $opts, false );
                
                Notion_To_WordPress_Helper::debug_log( '更新最后同步时间: ' . $now, 'Notion Page', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
            }

            $lock->release();
            return $stats;
            
        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log( '同步过程异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'Notion Page' );
            $lock->release();
            return new WP_Error('import_failed', '导入失败: ' . $e->getMessage());
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
        $ratio = $block['column']['ratio'] ?? ( $block['column']['width_ratio'] ?? 1 );
        // Notion API ratio 通常为 0-1 之间的小数，表示占整体比例
        $width_percent = max( 5, round( $ratio * 100, 2 ) );
        $html = '<div class="notion-column" style="flex:0 0 ' . esc_attr( $width_percent ) . '%;">';
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
            // 文件入队异步下载，但目前还没有本地副本
            // 使用数据占位符，便于后续替换
            $placeholder_id = 'ntw-file-' . substr(md5($url), 0, 10);
            
            // 将原始URL保存为data属性，便于下载完成后替换
            return '<div class="file-download-box notion-temp-file" data-ntw-url="' . esc_attr($url) . '" id="' . esc_attr($placeholder_id) . '">
                <span class="file-download-name">' . esc_html( $display ) . '</span> 
                <a class="file-download-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . __( '下载附件（后台处理中...）', 'notion-to-wordpress' ) . '</a>
            </div>';
        }

        $local_url = wp_get_attachment_url( $attachment_id );

        return '<div class="file-download-box"><span class="file-download-name">' . esc_html( $display ) . '</span> <a class="file-download-btn" href="' . esc_url( $local_url ) . '" download target="_blank" rel="noopener">' . __( '下载附件', 'notion-to-wordpress' ) . '</a></div>';
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
        // 先检查是否已经有相同URL的附件
        $existing_id = $this->get_attachment_by_url( $url );
        if ( $existing_id > 0 ) {
            Notion_To_WordPress_Helper::debug_log( '找到已存在的文件附件: ' . $existing_id . ' 对应URL: ' . $url, 'File', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
            return $existing_id;
        }
        
        // 直接入队，后台异步处理
        Notion_Download_Queue::push([
            'type'    => 'file',
            'url'     => $url,
            'post_id' => 0,
            'caption' => $caption,
            'name'    => $override_name,
        ]);
        
        // 记录日志
        Notion_To_WordPress_Helper::debug_log( '文件入队下载: ' . $url . ($override_name ? ' 指定文件名: ' . $override_name : ''), 'File', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
        
        return 0;
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
} 