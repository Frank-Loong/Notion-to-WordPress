<?php
declare(strict_types=1);

/**
 * Notion 页面导入协调器类
 *
 * 重构后的主协调器类，通过依赖注入整合所有专门类，保持向后兼容的公共接口。
 * 实现服务协调和流程管理，确保所有现有调用代码无需修改。
 *
 * @since      1.0.9
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

class Notion_Pages {

    // ==================== 核心依赖服务 ====================

    /**
     * Notion API 实例
     *
     * @since 2.0.0-beta.1
     * @var Notion_API
     */
    public Notion_API $notion_api;

    /**
     * 数据库ID
     *
     * @since 2.0.0-beta.1
     * @var string
     */
    private string $database_id;

    /**
     * 字段映射配置
     *
     * @since 2.0.0-beta.1
     * @var array
     */
    private array $field_mapping;

    /**
     * 自定义字段映射
     *
     * @since 2.0.0-beta.1
     * @var array
     */
    private array $custom_field_mappings = [];

    // ==================== 向后兼容属性 ====================

    /**
     * 存储已导入的块ID，防止重复处理（向后兼容）
     *
     * @since    1.0.5
     * @access   private
     * @var      array    $processed_blocks    已处理的块ID
     */
    private array $processed_blocks = [];

    // ==================== 向后兼容的遗留属性 ====================

    /**
     * 会话级数据库查询缓存（向后兼容）
     * @deprecated 2.0.0-beta.1 使用 Notion_Cache_Manager 代替
     * @var array
     */
    private static $db_query_cache = [];

    /**
     * 会话级批量查询缓存（向后兼容）
     * @deprecated 2.0.0-beta.1 使用 Notion_Cache_Manager 代替
     * @var array
     */
    private static $batch_query_cache = [];

    /**
     * 最后一次处理的统计信息（向后兼容）
     *
     * @since    1.9.0-beta.1
     * @access   private
     * @var      array    $last_processing_stats    最后一次处理的统计信息
     */
    private array $last_processing_stats = [];

    // ==================== 辅助方法 ====================

    /**
     * 检查是否启用并发优化功能
     *
     * @since    1.9.0-beta.1
     * @return   bool    是否启用并发优化
     */
    private function is_concurrent_optimization_enabled(): bool {
        // 从性能配置中读取并发优化设置
        $performance_config = get_option('notion_to_wordpress_performance_config', []);

        // 默认启用并发优化，除非明确禁用
        return $performance_config['enable_concurrent_optimization'] ?? true;
    }

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
     * 从Notion页面导入到WordPress（主协调器方法）
     *
     * @since    1.0.5
     * @param    array     $page         Notion页面数据
     * @return   boolean                 导入是否成功
     */
    public function import_notion_page(array $page): bool {
        Notion_To_WordPress_Helper::debug_log('import_notion_page() 开始执行（主协调器）', 'Page Import');

        if (empty($page) || !isset($page['id'])) {
            Notion_To_WordPress_Helper::error_log('页面数据为空或缺少ID', 'Page Import');
            return false;
        }

        $page_id = $page['id'];

        // 使用元数据提取器提取页面元数据
        $metadata = Notion_Metadata_Extractor::extract_page_metadata($page, $this->field_mapping, $this->custom_field_mappings);
        Notion_To_WordPress_Helper::debug_log('元数据提取完成，标题: ' . ($metadata['title'] ?? 'unknown'), 'Page Import');

        if (empty($metadata['title'])) {
            Notion_To_WordPress_Helper::debug_log('页面标题为空，跳过导入', 'Page Import');
            return false;
        }

        // 获取页面内容
        $blocks = $this->notion_api->get_page_content($page_id);
        Notion_To_WordPress_Helper::debug_log('获取到内容块数量: ' . count($blocks), 'Page Import');
        if (empty($blocks)) {
            return false;
        }

        // 检查是否启用并发优化
        $concurrent_enabled = $this->is_concurrent_optimization_enabled();

        if ($concurrent_enabled) {
            // 启用异步图片下载模式
            Notion_Image_Processor::enable_async_image_mode();
            Notion_To_WordPress_Helper::debug_log('并发优化已启用，异步图片下载模式已启用', 'Page Import');
        } else {
            Notion_To_WordPress_Helper::debug_log('并发优化已禁用，使用传统模式', 'Page Import');
        }

        if ($concurrent_enabled) {
            try {
                // 转换内容为 HTML（收集图片占位符）
                $raw_content = Notion_Content_Converter::convert_blocks_to_html($blocks, $this->notion_api);

                // 处理异步图片下载并替换占位符
                $processed_content = Notion_Image_Processor::process_async_images($raw_content);

                // 获取图片处理统计
                $image_stats = Notion_Image_Processor::get_performance_stats();
                Notion_To_WordPress_Helper::debug_log(
                    sprintf(
                        '并发图片处理完成: 成功 %d 个，失败 %d 个',
                        $image_stats['success_count'],
                        $image_stats['error_count']
                    ),
                    'Page Import'
                );

                $content = Notion_To_WordPress_Helper::custom_kses($processed_content);

            } catch (Exception $e) {
                // 并发处理失败时回退到传统模式
                Notion_To_WordPress_Helper::error_log(
                    '并发图片处理失败，回退到传统模式: ' . $e->getMessage(),
                    'Page Import'
                );

                // 禁用异步模式并重新处理
                Notion_Image_Processor::disable_async_image_mode();
                $raw_content = Notion_Content_Converter::convert_blocks_to_html($blocks, $this->notion_api);
                $content = Notion_To_WordPress_Helper::custom_kses($raw_content);

                // 重新启用异步模式以保持状态一致
                Notion_Image_Processor::enable_async_image_mode();
            }
        } else {
            // 传统模式：直接处理，不使用并发优化
            $raw_content = Notion_Content_Converter::convert_blocks_to_html($blocks, $this->notion_api);
            $content = Notion_To_WordPress_Helper::custom_kses($raw_content);
        }
        
        $existing_post_id = Notion_To_WordPress_Integrator::get_post_by_notion_id($page_id);

        // 获取文章作者
        $author_id = $this->get_author_id();

        // 创建或更新文章
        $post_id = Notion_To_WordPress_Integrator::create_or_update_post($metadata, $content, $author_id, $page_id, $existing_post_id);

        if (is_wp_error($post_id)) {
            return false;
        }

        // 分类 / 标签 / 特色图
        Notion_To_WordPress_Integrator::apply_taxonomies($post_id, $metadata);
        Notion_To_WordPress_Integrator::apply_featured_image($post_id, $metadata);

        // 更新同步时间戳
        $notion_last_edited = $page['last_edited_time'] ?? '';
        if ($notion_last_edited) {
            Notion_Sync_Manager::update_page_sync_time($page_id, $notion_last_edited);
        }

        // 如果启用了并发优化，禁用异步图片下载模式
        if ($concurrent_enabled) {
            Notion_Image_Processor::disable_async_image_mode();
            Notion_To_WordPress_Helper::debug_log('页面导入完成，异步图片下载模式已禁用', 'Page Import');
        } else {
            Notion_To_WordPress_Helper::debug_log('页面导入完成（传统模式）', 'Page Import');
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
        // 委托给元数据提取器
        return Notion_Metadata_Extractor::extract_page_metadata(
            $page,
            $this->field_mapping ?? [],
            $this->custom_field_mappings ?? []
        );


    }

    /**
     * 从属性列表中安全地获取一个值
     *
     * @since 1.0.5
     * @deprecated 2.0.0-beta.1 使用 Notion_Metadata_Extractor::get_property_value() 代替
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
     * @deprecated 2.0.0-beta.1 使用 Notion_Metadata_Extractor::extract_property_value() 代替
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
        // 委托给内容转换器
        return Notion_Content_Converter::convert_blocks_to_html($blocks, $notion_api);
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
        // 委托给内容转换器
        return Notion_Content_Converter::wrap_block_with_id($block_html, $block_id, $block_type);
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

        // 调试：输出完整的child_database块结构
        Notion_To_WordPress_Helper::debug_log(
            'child_database块完整结构: ' . json_encode($block, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'Child Database Block Debug'
        );

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

                // 调试：输出完整的数据库信息结构
                Notion_To_WordPress_Helper::debug_log(
                    '数据库完整信息结构: ' . json_encode($database_info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    'Database Structure Debug'
                );

                $html .= Notion_Database_Renderer::render_database_properties($database_info);

                // 使用新的数据库渲染器
                $html .= Notion_Database_Renderer::render_database_preview_records($database_id, $database_info, $notion_api);
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

        // Notion 临时链接 —— 下载到媒体库（支持异步模式）
        $attachment_id = $this->download_and_insert_image( $url, $caption, $this->async_image_mode );

        if ( is_numeric( $attachment_id ) && $attachment_id > 0 ) {
            return '<figure class="wp-block-image size-large"><img src="' . esc_url( wp_get_attachment_url( $attachment_id ) ) . '" alt="' . esc_attr( $caption ) . '"><figcaption>' . esc_html( $caption ) . '</figcaption></figure>';
        } elseif ( is_string( $attachment_id ) && strpos( $attachment_id, 'pending_image_' ) === 0 ) {
            // 异步模式：返回占位符
            return $attachment_id;
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
            'files.notion.com',
            'notion-static.com'
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
        $child_content = $this->_convert_child_blocks($block, $notion_api);
        return '<blockquote>' . $text . $child_content . '</blockquote>';
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
                // 直接使用外链图标，无需下载
                $icon = '<img src="' . esc_url($block['callout']['icon']['external']['url']) . '" class="notion-callout-icon" alt="icon">';
            } elseif (isset($block['callout']['icon']['file']['url'])) {
                // 处理Notion文件图标
                $icon_url = $block['callout']['icon']['file']['url'];
                if ( $this->is_notion_temp_url( $icon_url ) ) {
                    // Notion临时URL需要下载
                    $attachment_id = $this->download_and_insert_image($icon_url, 'Callout Icon', $this->async_image_mode);
                    if (is_numeric($attachment_id) && $attachment_id > 0) {
                        $local_url = wp_get_attachment_url($attachment_id);
                        $icon = '<img src="' . esc_url($local_url) . '" class="notion-callout-icon" alt="icon">';
                    } elseif ( is_string( $attachment_id ) && strpos( $attachment_id, 'pending_image_' ) === 0 ) {
                        // 异步模式占位符
                        $icon = $attachment_id;
                    }
                } else {
                    // 直接使用外链
                    $icon = '<img src="' . esc_url($icon_url) . '" class="notion-callout-icon" alt="icon">';
                }
            }
        }
        // 添加子块处理
        $child_content = $this->_convert_child_blocks($block, $notion_api);
        return '<div class="notion-callout">' . $icon . '<div class="notion-callout-content">' . $text . $child_content . '</div></div>';
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
     * 根据Notion页面ID获取WordPress文章 - 优化版本（会话级缓存）
     *
     * @since    1.0.5
     * @param    string    $notion_id    Notion页面ID
     * @return   int                     WordPress文章ID
     */
    private function get_post_by_notion_id($notion_id) {
        // 委托给WordPress集成器
        return Notion_To_WordPress_Integrator::get_post_by_notion_id($notion_id);
    }

    /**
     * 批量获取多个Notion页面ID对应的WordPress文章ID - 优化版本（会话级缓存）
     *
     * @since    1.1.2
     * @param    array    $notion_ids    Notion页面ID数组
     * @return   array                   [notion_id => post_id] 映射
     */
    private function batch_get_posts_by_notion_ids(array $notion_ids): array {
        if (empty($notion_ids)) {
            return [];
        }

        // 检查会话级缓存
        $cache_key = 'batch_posts_' . md5(serialize($notion_ids));
        $cached_result = Notion_Cache_Manager::get_batch_cache_value($cache_key);
        if ($cached_result !== null) {
            return $cached_result;
        }

        // 检查单个缓存，减少需要查询的ID
        $uncached_ids = [];
        $mapping = [];

        foreach ($notion_ids as $notion_id) {
            $single_cache_key = 'post_by_notion_id_' . $notion_id;
            $cached_value = Notion_Cache_Manager::get_db_cache_value($single_cache_key);
            if ($cached_value !== null) {
                $mapping[$notion_id] = $cached_value;
            } else {
                $uncached_ids[] = $notion_id;
                $mapping[$notion_id] = 0; // 默认值
            }
        }

        // 如果还有未缓存的ID，执行批量查询
        if (!empty($uncached_ids)) {
            global $wpdb;

            // 准备SQL占位符
            $placeholders = implode(',', array_fill(0, count($uncached_ids), '%s'));

            // 执行批量查询
            $query = $wpdb->prepare(
                "SELECT post_id, meta_value as notion_id
                FROM {$wpdb->postmeta}
                WHERE meta_key = '_notion_page_id'
                AND meta_value IN ($placeholders)",
                $uncached_ids
            );

            $results = $wpdb->get_results($query);

            // 更新映射和单个缓存
            foreach ($results as $row) {
                $mapping[$row->notion_id] = (int)$row->post_id;
                $single_cache_key = 'post_by_notion_id_' . $row->notion_id;
                Notion_Cache_Manager::set_db_cache_value($single_cache_key, (int)$row->post_id);

                // 保持向后兼容的本地缓存
                self::$db_query_cache[$single_cache_key] = (int)$row->post_id;
            }
        }

        // 存储到批量查询缓存
        Notion_Cache_Manager::set_batch_cache_value($cache_key, $mapping);

        // 保持向后兼容的本地缓存
        self::$batch_query_cache[$cache_key] = $mapping;

        return $mapping;
    }

    /**
     * 批量获取页面同步时间 - 优化版本（会话级缓存）
     *
     * @since    1.1.2
     * @param    array    $notion_ids    Notion页面ID数组
     * @return   array                   [notion_id => sync_time] 映射
     */
    private function batch_get_page_sync_times(array $notion_ids): array {
        // 委托给同步管理器
        return Notion_Sync_Manager::batch_get_sync_times($notion_ids);
    }

    /**
     * 获取合适的文章作者 ID
     *
     * @deprecated 2.0.0-beta.1 使用 Notion_To_WordPress_Integrator::get_default_author_id() 代替
     */
    private function get_author_id(): int {
        // 委托给WordPress集成器
        return Notion_To_WordPress_Integrator::get_default_author_id();
    }

    /**
     * 创建或更新 WordPress 文章
     *
     * @return int|WP_Error
     */
    private function create_or_update_post(array $metadata, string $content, int $author_id, string $page_id, int $existing_post_id = 0) {
        // 委托给WordPress集成器
        return Notion_To_WordPress_Integrator::create_or_update_post($metadata, $content, $author_id, $page_id, $existing_post_id);
    }

    /**
     * 应用自定义字段
     *
     * @deprecated 2.0.0-beta.1 使用 Notion_To_WordPress_Integrator::apply_custom_fields() 代替
     */
    private function apply_custom_fields(int $post_id, array $custom_fields): void {
        // 委托给WordPress集成器
        Notion_To_WordPress_Integrator::apply_custom_fields($post_id, $custom_fields);
    }

    /**
     * 设置分类与标签
     *
     * @deprecated 2.0.0-beta.1 使用 Notion_To_WordPress_Integrator::apply_taxonomies() 代替
     */
    private function apply_taxonomies(int $post_id, array $metadata): void {
        // 委托给WordPress集成器
        Notion_To_WordPress_Integrator::apply_taxonomies($post_id, $metadata);
    }

    /**
     * 处理特色图片
     *
     * @deprecated 2.0.0-beta.1 使用 Notion_To_WordPress_Integrator::apply_featured_image() 代替
     */
    private function apply_featured_image(int $post_id, array $metadata): void {
        // 委托给WordPress集成器
        Notion_To_WordPress_Integrator::apply_featured_image($post_id, $metadata);
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

        // 如果不是Notion临时链接，尝试直接使用外链
        if ( ! $this->is_notion_temp_url( $image_url ) ) {
            // 对于外链，我们可以选择直接使用或者仍然下载
            // 这里提供一个选项，默认直接使用外链以节约资源
            $use_external_featured_image = apply_filters( 'notion_to_wordpress_use_external_featured_image', true );

            if ( $use_external_featured_image ) {
                // 直接使用外链作为特色图片（通过自定义字段）
                update_post_meta( $post_id, '_notion_featured_image_url', esc_url_raw( $image_url ) );

                Notion_To_WordPress_Helper::debug_log(
                    'Using external featured image URL: ' . $image_url,
                    'Featured Image'
                );
                return;
            }
        }

        // Notion临时链接或选择下载外链的情况
        $attachment_id = $this->download_and_insert_image($image_url, get_the_title($post_id), $this->async_image_mode);

        if ( is_numeric( $attachment_id ) && $attachment_id > 0 ) {
            set_post_thumbnail($post_id, $attachment_id);
        } elseif ( is_string( $attachment_id ) && strpos( $attachment_id, 'pending_image_' ) === 0 ) {
            // 异步模式：存储占位符，稍后处理
            update_post_meta( $post_id, '_notion_featured_image_placeholder', $attachment_id );
        } elseif ( is_wp_error( $attachment_id ) ) {
            Notion_To_WordPress_Helper::debug_log('Featured image download failed: ' . $attachment_id->get_error_message());
        }
    }

    /**
     * 下载并插入图片到媒体库（支持异步模式）
     *
     * @since    1.0.5
     * @param    string    $url       图片URL
     * @param    string    $caption   图片标题
     * @param    bool      $async     是否使用异步模式
     * @return   int|string           WordPress附件ID或占位符
     */
    private function download_and_insert_image( string $url, string $caption = '', bool $async = false ) {
        // 去掉查询参数用于去重
        $base_url = strtok( $url, '?' );

        // 若已存在同源附件，直接返回ID
        $existing = $this->get_attachment_by_url( $base_url );
        if ( $existing ) {
            Notion_To_WordPress_Helper::debug_log( "Image already exists in media library (Attachment ID: {$existing}).", 'Notion Info' );
            return $existing;
        }

        // 异步模式：收集图片信息，返回占位符
        if ( $async ) {
            return $this->collect_image_for_download( $url, $caption );
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
     * 导入所有Notion页面（主协调器方法）
     *
     * @since    1.0.8
     * @param    bool    $check_deletions    是否检查删除的页面
     * @param    bool    $incremental        是否启用增量同步
     * @param    bool    $force_refresh      是否强制刷新所有内容（忽略时间戳）
     * @return   array|WP_Error    导入结果统计或错误
     */
    public function import_pages($check_deletions = true, $incremental = true, $force_refresh = false) {
        try {
            // 开始性能监控
            $import_start_time = microtime(true);
            $performance_stats = [
                'total_time' => 0,
                'api_calls' => 0,
                'images_processed' => 0,
                'concurrent_operations' => 0
            ];

            // 初始化会话级缓存
            Notion_Cache_Manager::init_session_cache();

            // 添加调试日志
            Notion_To_WordPress_Helper::info_log('import_pages() 开始执行（主协调器）', 'Pages Import');
            Notion_To_WordPress_Helper::info_log('Database ID: ' . $this->database_id, 'Pages Import');
            Notion_To_WordPress_Helper::info_log('检查删除: ' . ($check_deletions ? 'yes' : 'no'), 'Pages Import');
            Notion_To_WordPress_Helper::info_log('增量同步: ' . ($incremental ? 'yes' : 'no'), 'Pages Import');
            Notion_To_WordPress_Helper::info_log('强制刷新: ' . ($force_refresh ? 'yes' : 'no'), 'Pages Import');
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

            // 如果启用增量同步且不是强制刷新，过滤出需要更新的页面
            if ($incremental && !$force_refresh) {
                $pages = $this->filter_pages_for_incremental_sync($pages);
                error_log('Notion to WordPress: 增量同步过滤后页面数量: ' . count($pages));

                // 更新统计中的总数为实际处理的页面数
                $stats['total'] = count($pages);
            } elseif ($force_refresh) {
                error_log('Notion to WordPress: 强制刷新模式，将处理所有 ' . count($pages) . ' 个页面');
            }

            if (empty($pages)) {
                // 如果增量同步后没有页面需要处理，返回成功但无操作的结果
                error_log('Notion to WordPress: 增量同步无页面需要更新');

                // 获取会话缓存统计
                $cache_stats = self::get_session_cache_stats();
                Notion_To_WordPress_Helper::debug_log(
                    '会话缓存统计（无页面更新）: ' . print_r($cache_stats, true),
                    'Session Cache'
                );

                // 清理会话级缓存
                self::clear_session_cache();

                return $stats;
            }

            error_log('Notion to WordPress: 开始处理页面，总数: ' . count($pages));

            foreach ($pages as $index => $page) {
                error_log('Notion to WordPress: 处理页面 ' . ($index + 1) . '/' . count($pages) . ', ID: ' . ($page['id'] ?? 'unknown'));

                try {
                    // 检查页面是否已存在
                    $existing_post_id = Notion_To_WordPress_Integrator::get_post_by_notion_id($page['id']);
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

            // 计算性能统计
            $performance_stats['total_time'] = microtime(true) - $import_start_time;

            // 获取会话缓存统计
            $cache_stats = self::get_session_cache_stats();
            Notion_To_WordPress_Helper::debug_log(
                '会话缓存统计: ' . print_r($cache_stats, true),
                'Session Cache'
            );

            // 记录性能统计
            Notion_To_WordPress_Helper::info_log(
                sprintf(
                    '并发优化性能统计: 总耗时 %.4f 秒，处理 %d 个页面，平均每页 %.4f 秒',
                    $performance_stats['total_time'],
                    $stats['total'],
                    $performance_stats['total_time'] / max($stats['total'], 1)
                ),
                'Performance'
            );

            // 添加性能统计到返回结果
            $stats['performance'] = $performance_stats;

            // 清理会话级缓存
            self::clear_session_cache();

            return $stats;

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log('import_pages() 异常: ' . $e->getMessage(), 'Pages Import');
            Notion_To_WordPress_Helper::error_log('异常堆栈: ' . $e->getTraceAsString(), 'Pages Import');

            // 异常时也要清理会话级缓存
            self::clear_session_cache();

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
     * 清理已删除的页面 - 优化版本
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

        global $wpdb;

        // 使用单个SQL查询获取所有WordPress文章及其Notion ID
        $query = "
            SELECT p.ID as post_id, pm.meta_value as notion_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_notion_page_id'
        ";

        $results = $wpdb->get_results($query);
        $deleted_count = 0;

        Notion_To_WordPress_Helper::debug_log(
            '找到 ' . count($results) . ' 个WordPress文章有Notion ID',
            'Cleanup'
        );

        foreach ($results as $row) {
            // 如果这个Notion ID不在当前页面列表中，说明已被删除
            if (!in_array($row->notion_id, $current_notion_ids)) {
                Notion_To_WordPress_Helper::debug_log(
                    '发现孤儿文章，WordPress ID: ' . $row->post_id . ', Notion ID: ' . $row->notion_id,
                    'Cleanup'
                );

                $result = wp_delete_post($row->post_id, true); // true表示彻底删除

                if ($result) {
                    $deleted_count++;
                    Notion_To_WordPress_Helper::info_log(
                        '删除孤儿文章成功，WordPress ID: ' . $row->post_id . ', Notion ID: ' . $row->notion_id,
                        'Cleanup'
                    );
                } else {
                    Notion_To_WordPress_Helper::error_log(
                        '删除孤儿文章失败，WordPress ID: ' . $row->post_id . ', Notion ID: ' . $row->notion_id
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
     * 过滤出需要增量同步的页面 - 优化版本
     *
     * @since    1.1.0
     * @param    array    $pages    所有Notion页面
     * @return   array              需要同步的页面
     */
    private function filter_pages_for_incremental_sync(array $pages): array {
        if (empty($pages)) {
            return [];
        }

        // 提取所有页面ID
        $notion_ids = array_map(function($page) {
            return $page['id'];
        }, $pages);

        // 批量获取同步时间
        $sync_times = $this->batch_get_page_sync_times($notion_ids);

        $pages_to_sync = [];

        foreach ($pages as $page) {
            $page_id = $page['id'];
            $notion_last_edited = $page['last_edited_time'] ?? '';

            if (empty($notion_last_edited)) {
                // 如果没有编辑时间，默认需要同步
                $pages_to_sync[] = $page;
                continue;
            }

            // 获取本地记录的最后同步时间（从批量查询结果中获取）
            $local_last_sync = $sync_times[$page_id] ?? '';

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

            // 使用更宽松的时间比较，允许1秒的误差
            if ($notion_timestamp > $local_timestamp + 1) {
                // Notion页面更新时间晚于本地同步时间，需要同步
                Notion_To_WordPress_Helper::debug_log(
                    "页面有更新需要同步: {$page_id}, Notion: {$notion_last_edited}, Local: {$local_last_sync}",
                    'Incremental Sync'
                );
                $pages_to_sync[] = $page;
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
     * @deprecated 2.0.0-beta.1 使用 Notion_Sync_Manager::get_page_last_sync_time() 代替
     * @param    string    $page_id    Notion页面ID
     * @return   string                最后同步时间
     */
    private function get_page_last_sync_time(string $page_id): string {
        // 委托给同步管理器
        return Notion_Sync_Manager::get_page_last_sync_time($page_id);
    }

    /**
     * 更新页面同步时间 - 优化版本
     *
     * @since    1.1.0
     * @param    string    $page_id              Notion页面ID
     * @param    string    $notion_last_edited   Notion最后编辑时间
     */
    private function update_page_sync_time(string $page_id, string $notion_last_edited): void {
        $post_id = Notion_To_WordPress_Integrator::get_post_by_notion_id($page_id);

        if (!$post_id) {
            return;
        }

        // 更新同步时间戳（统一使用UTC时间）
        $current_utc_time = gmdate('Y-m-d H:i:s');

        // 使用事务包装批量更新
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            update_post_meta($post_id, '_notion_last_sync_time', $current_utc_time);
            update_post_meta($post_id, '_notion_last_edited_time', $notion_last_edited);
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        Notion_To_WordPress_Helper::debug_log(
            "更新页面同步时间: {$page_id}, 编辑时间: {$notion_last_edited}",
            'Incremental Sync'
        );
    }

    /**
     * 批量更新页面同步时间
     *
     * @since    1.1.2
     * @deprecated 2.0.0-beta.1 使用 Notion_Sync_Manager::batch_update_page_sync_times() 代替
     * @param    array    $page_updates    [page_id => notion_last_edited] 映射
     */
    private function batch_update_page_sync_times(array $page_updates): void {
        // 委托给同步管理器
        Notion_Sync_Manager::batch_update_page_sync_times($page_updates);

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
     * 获取缓存统计信息
     *
     * @since 1.1.1
     * @return array
     */
    public function get_performance_stats(): array {
        // 使用统一的缓存管理器获取统计
        $cache_stats = Notion_Cache_Manager::get_cache_stats();

        return [
            'api_cache' => $cache_stats['api_cache'],
            'session_cache' => $cache_stats['session_cache'],
            'total_cache_items' => $cache_stats['total_cache_items'],
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

    /**
     * 清理会话级缓存
     *
     * @since 1.1.2
     */
    public static function clear_session_cache(): void {
        // 委托给缓存管理器
        Notion_Cache_Manager::clear_session_cache();

        // 保持向后兼容的本地缓存清理
        self::$db_query_cache = [];
        self::$batch_query_cache = [];
    }

    /**
     * 获取会话级缓存统计
     *
     * @since 1.1.2
     * @return array
     */
    public static function get_session_cache_stats(): array {
        // 委托给缓存管理器
        return Notion_Cache_Manager::get_session_cache_stats();
    }

    /**
     * 在同步开始时初始化会话缓存
     *
     * @since 1.1.2
     */
    public static function init_session_cache(): void {
        // 委托给缓存管理器
        Notion_Cache_Manager::init_session_cache();
    }

    /**
     * 使用预处理数据转换子数据库块
     *
     * @since    1.9.0-beta.1
     * @param    array       $block          数据库块
     * @param    Notion_API  $notion_api     API实例
     * @param    array       $database_data  预处理的数据库数据
     * @return   string                      HTML内容
     */
    private function _convert_block_child_database_with_data(array $block, Notion_API $notion_api, array $database_data): string {
        $database_title = $block['child_database']['title'] ?? '未命名数据库';
        $database_id = $block['id'];

        // 调试：输出完整的child_database块结构
        Notion_To_WordPress_Helper::debug_log(
            'child_database块完整结构: ' . json_encode($block, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'Child Database Block Debug'
        );

        // 记录数据库区块处理开始
        Notion_To_WordPress_Helper::debug_log(
            '开始处理数据库区块（批量模式）: ' . $database_id . ', 标题: ' . $database_title,
            'Database Block'
        );

        $html = '<div class="notion-child-database">';
        $html .= '<div class="notion-database-header">';
        $html .= '<h4 class="notion-database-title">' . esc_html($database_title) . '</h4>';

        // 使用预处理的数据
        $data = $database_data[$database_id] ?? null;

        if ($data && $data['info'] && !($data['info'] instanceof Exception)) {
            $database_info = $data['info'];

            Notion_To_WordPress_Helper::info_log(
                '数据库信息获取成功（批量模式）: ' . $database_id . ', 属性数量: ' . count($database_info['properties'] ?? []),
                'Database Block'
            );

            // 调试：输出完整的数据库信息结构
            Notion_To_WordPress_Helper::debug_log(
                '数据库完整信息结构: ' . json_encode($database_info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'Database Structure Debug'
            );

            $html .= Notion_Database_Renderer::render_database_properties($database_info);

            // 使用新的数据库渲染器（预处理数据）
            $html .= Notion_Database_Renderer::render_database_preview_records_with_data($database_id, $database_info, $data['records']);
        } else {
            Notion_To_WordPress_Helper::debug_log(
                '数据库信息为空或获取失败（批量模式），可能是权限问题: ' . $database_id,
                'Database Block'
            );
            $html .= '<p class="notion-database-fallback">数据库内容需要在Notion中查看</p>';
        }

        $html .= '</div></div>';

        Notion_To_WordPress_Helper::debug_log(
            '数据库区块处理完成（批量模式）: ' . $database_id,
            'Database Block'
        );

        return $html;
    }



    /**
     * 公共方法：转换块为HTML（用于测试）
     *
     * @since    1.9.0-beta.1
     * @param    array       $blocks       Notion块数据
     * @param    Notion_API  $notion_api   Notion API实例
     * @return   string                    HTML内容
     */
    public function test_convert_blocks_to_html(array $blocks, Notion_API $notion_api): string {
        // 委托给内容转换器
        return Notion_Content_Converter::test_convert_blocks_to_html($blocks, $notion_api);
    }

    // ========================================
    // 异步图片下载队列系统
    // ========================================

    /**
     * 收集图片信息用于批量下载
     *
     * @since    1.9.0-beta.1
     * @param    string    $url       图片URL
     * @param    string    $caption   图片标题
     * @return   string               占位符标识
     */
    private function collect_image_for_download( string $url, string $caption = '' ): string {
        $placeholder_id = 'pending_image_' . count( $this->pending_images );

        $this->pending_images[] = [
            'url' => $url,
            'caption' => $caption,
            'placeholder' => $placeholder_id,
            'base_url' => strtok( $url, '?' )
        ];

        Notion_To_WordPress_Helper::debug_log(
            sprintf( 'Collected image for batch download: %s (placeholder: %s)', $url, $placeholder_id ),
            'Async Image'
        );

        return $placeholder_id;
    }

    /**
     * 批量下载所有待处理的图片
     *
     * @since    1.9.0-beta.1
     * @return   array                下载结果映射
     */
    private function batch_download_images(): array {
        if ( empty( $this->pending_images ) ) {
            return [];
        }

        $start_time = microtime( true );
        $total_images = count( $this->pending_images );

        Notion_To_WordPress_Helper::debug_log(
            sprintf( 'Starting batch image download: %d images', $total_images ),
            'Async Image'
        );

        // 过滤掉已存在的图片
        $images_to_download = [];
        foreach ( $this->pending_images as $image_info ) {
            $existing = $this->get_attachment_by_url( $image_info['base_url'] );
            if ( $existing ) {
                $this->image_placeholders[ $image_info['placeholder'] ] = $existing;
                Notion_To_WordPress_Helper::debug_log(
                    sprintf( 'Image already exists: %s (ID: %d)', $image_info['url'], $existing ),
                    'Async Image'
                );
            } else {
                $images_to_download[] = $image_info;
            }
        }

        if ( empty( $images_to_download ) ) {
            Notion_To_WordPress_Helper::debug_log(
                'All images already exist in media library',
                'Async Image'
            );
            return $this->image_placeholders;
        }

        Notion_To_WordPress_Helper::debug_log(
            sprintf( 'Need to download %d new images', count( $images_to_download ) ),
            'Async Image'
        );

        // 使用WordPress内置的并发下载功能
        try {
            // 准备并发下载请求
            $requests = [];
            foreach ( $images_to_download as $index => $image_info ) {
                $requests[ $index ] = [
                    'url' => $image_info['url'],
                    'args' => [
                        'timeout' => 30,
                        'user-agent' => 'Notion-to-WordPress/1.9.0'
                    ]
                ];
            }

            // 执行并发下载
            $responses = $this->concurrent_download_images( $requests );

            // 处理下载结果
            foreach ( $images_to_download as $index => $image_info ) {
                $response = $responses[ $index ] ?? null;

                if ( is_wp_error( $response ) ) {
                    Notion_To_WordPress_Helper::error_log(
                        sprintf( 'Image download failed: %s - %s', $image_info['url'], $response->get_error_message() ),
                        'Async Image'
                    );
                    $this->image_placeholders[ $image_info['placeholder'] ] = null;
                    continue;
                }

                // 处理下载的图片数据
                $attachment_id = $this->process_downloaded_image_response( $image_info, $response );
                $this->image_placeholders[ $image_info['placeholder'] ] = $attachment_id;
            }

        } catch ( Exception $e ) {
            Notion_To_WordPress_Helper::error_log(
                'Batch image download failed: ' . $e->getMessage(),
                'Async Image'
            );

            // 标记所有图片为失败
            foreach ( $images_to_download as $image_info ) {
                $this->image_placeholders[ $image_info['placeholder'] ] = null;
            }
        }

        $execution_time = microtime( true ) - $start_time;
        $success_count = count( array_filter( $this->image_placeholders, function( $id ) {
            return is_numeric( $id ) && $id > 0;
        } ) );

        Notion_To_WordPress_Helper::debug_log(
            sprintf(
                'Batch image download completed: %d/%d successful, %.4f seconds',
                $success_count,
                $total_images,
                $execution_time
            ),
            'Async Image'
        );

        return $this->image_placeholders;
    }

    /**
     * 并发下载图片
     *
     * @since    1.9.0-beta.1
     * @param    array     $requests      请求数组
     * @return   array                    响应数组
     */
    private function concurrent_download_images( array $requests ): array {
        if ( empty( $requests ) ) {
            return [];
        }

        // 使用WordPress的HTTP API进行并发请求
        $multi_requests = [];

        // 准备并发请求
        foreach ( $requests as $index => $request ) {
            $multi_requests[ $index ] = [
                'url' => $request['url'],
                'args' => array_merge( $request['args'], [
                    'blocking' => false, // 非阻塞模式
                    'timeout' => 30,
                    'user-agent' => 'Notion-to-WordPress/1.9.0'
                ] )
            ];
        }

        Notion_To_WordPress_Helper::debug_log(
            sprintf( 'Starting concurrent download of %d images', count( $multi_requests ) ),
            'Concurrent Download'
        );

        // 使用cURL多句柄进行并发下载
        return $this->execute_concurrent_requests( $multi_requests );
    }

    /**
     * 执行并发HTTP请求
     *
     * @since    1.9.0-beta.1
     * @param    array     $multi_requests    多个请求
     * @return   array                        响应数组
     */
    private function execute_concurrent_requests( array $multi_requests ): array {
        $responses = [];

        // 检查是否支持cURL
        if ( ! function_exists( 'curl_multi_init' ) ) {
            Notion_To_WordPress_Helper::debug_log(
                'cURL multi not available, falling back to sequential requests',
                'Concurrent Download'
            );

            // 降级到顺序请求
            foreach ( $multi_requests as $index => $request ) {
                $response = wp_remote_get( $request['url'], $request['args'] );
                $responses[ $index ] = $response;
            }
            return $responses;
        }

        // 创建cURL多句柄
        $multi_handle = curl_multi_init();
        $curl_handles = [];

        // 添加所有请求到多句柄
        foreach ( $multi_requests as $index => $request ) {
            $ch = curl_init();

            curl_setopt_array( $ch, [
                CURLOPT_URL => $request['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'Notion-to-WordPress/1.9.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ] );

            curl_multi_add_handle( $multi_handle, $ch );
            $curl_handles[ $index ] = $ch;
        }

        // 执行并发请求
        $running = null;
        do {
            curl_multi_exec( $multi_handle, $running );
            curl_multi_select( $multi_handle );
        } while ( $running > 0 );

        // 收集响应
        foreach ( $curl_handles as $index => $ch ) {
            $content = curl_multi_getcontent( $ch );
            $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $error = curl_error( $ch );

            if ( ! empty( $error ) ) {
                $responses[ $index ] = new WP_Error( 'curl_error', $error );
            } else {
                // 模拟WordPress HTTP API响应格式
                $responses[ $index ] = [
                    'body' => $content,
                    'response' => [
                        'code' => $http_code,
                        'message' => ''
                    ],
                    'headers' => []
                ];
            }

            curl_multi_remove_handle( $multi_handle, $ch );
            curl_close( $ch );
        }

        curl_multi_close( $multi_handle );

        Notion_To_WordPress_Helper::debug_log(
            sprintf( 'Concurrent download completed: %d responses', count( $responses ) ),
            'Concurrent Download'
        );

        return $responses;
    }

    /**
     * 处理下载的图片响应并插入到媒体库
     *
     * @since    1.9.0-beta.1
     * @param    array     $image_info    图片信息
     * @param    array     $response      HTTP响应数据
     * @return   int|null                 附件ID或null
     */
    private function process_downloaded_image_response( array $image_info, array $response ): ?int {
        try {
            // 检查响应状态
            $response_code = wp_remote_retrieve_response_code( $response );
            if ( $response_code !== 200 ) {
                throw new Exception( sprintf( 'HTTP error %d', $response_code ) );
            }

            $body = wp_remote_retrieve_body( $response );
            if ( empty( $body ) ) {
                throw new Exception( 'Empty response body' );
            }

            // 引入WordPress媒体处理函数
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            // 创建临时文件
            $tmp_file = wp_tempnam();
            if ( ! $tmp_file ) {
                throw new Exception( 'Could not create temporary file' );
            }

            // 写入图片数据
            $bytes_written = file_put_contents( $tmp_file, $body );
            if ( $bytes_written === false ) {
                unlink( $tmp_file );
                throw new Exception( 'Could not write image data to temporary file' );
            }

            // 获取文件名
            $file_name = basename( parse_url( $image_info['url'], PHP_URL_PATH ) );
            if ( ! $file_name ) {
                $file_name = 'notion-image-' . time() . '.jpg';
            }

            // 准备文件数组
            $file = [
                'name'     => $file_name,
                'tmp_name' => $tmp_file,
            ];

            // 保存到媒体库
            $attachment_id = media_handle_sideload( $file, 0, $image_info['caption'] );

            if ( is_wp_error( $attachment_id ) ) {
                throw new Exception( $attachment_id->get_error_message() );
            }

            // 存储源URL方便后续去重
            update_post_meta( $attachment_id, '_notion_original_url', esc_url_raw( $image_info['url'] ) );
            update_post_meta( $attachment_id, '_notion_base_url', esc_url_raw( $image_info['base_url'] ) );

            Notion_To_WordPress_Helper::debug_log(
                sprintf( 'Image processed successfully: %s (ID: %d)', $image_info['url'], $attachment_id ),
                'Async Image'
            );

            return $attachment_id;

        } catch ( Exception $e ) {
            Notion_To_WordPress_Helper::error_log(
                sprintf( 'Failed to process downloaded image: %s - %s', $image_info['url'], $e->getMessage() ),
                'Async Image'
            );
            return null;
        }
    }

    /**
     * 替换HTML内容中的图片占位符
     *
     * @since    1.9.0-beta.1
     * @param    string    $html    包含占位符的HTML内容
     * @return   string             替换后的HTML内容
     */
    private function replace_image_placeholders( string $html ): string {
        if ( empty( $this->image_placeholders ) ) {
            return $html;
        }

        $replacements = 0;

        foreach ( $this->image_placeholders as $placeholder => $attachment_id ) {
            if ( is_numeric( $attachment_id ) && $attachment_id > 0 ) {
                // 成功下载的图片，替换为实际的图片HTML
                $image_url = wp_get_attachment_url( $attachment_id );
                if ( $image_url ) {
                    // 查找对应的图片信息
                    $image_info = null;
                    foreach ( $this->pending_images as $info ) {
                        if ( $info['placeholder'] === $placeholder ) {
                            $image_info = $info;
                            break;
                        }
                    }

                    $caption = $image_info['caption'] ?? '';
                    $img_html = '<figure class="wp-block-image size-large"><img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $caption ) . '"><figcaption>' . esc_html( $caption ) . '</figcaption></figure>';

                    $html = str_replace( $placeholder, $img_html, $html );
                    $replacements++;
                }
            } else {
                // 下载失败的图片，替换为错误提示或移除
                $html = str_replace( $placeholder, '<!-- Image download failed -->', $html );
                $replacements++;
            }
        }

        if ( $replacements > 0 ) {
            Notion_To_WordPress_Helper::debug_log(
                sprintf( 'Replaced %d image placeholders in HTML content', $replacements ),
                'Async Image'
            );
        }

        return $html;
    }

    /**
     * 清理图片队列和占位符
     *
     * @since    1.9.0-beta.1
     */
    private function clear_image_queue(): void {
        $this->pending_images = [];
        $this->image_placeholders = [];

        Notion_To_WordPress_Helper::debug_log(
            'Image queue and placeholders cleared',
            'Async Image'
        );
    }

    /**
     * 获取图片队列统计信息
     *
     * @since    1.9.0-beta.1
     * @param    bool     $use_last_stats    是否使用最后一次处理的统计
     * @return   array                       统计信息
     */
    public function get_image_queue_stats( bool $use_last_stats = false ): array {
        // 如果队列已清空但需要统计信息，使用最后一次保存的统计
        if ( $use_last_stats && empty( $this->pending_images ) && empty( $this->image_placeholders ) && ! empty( $this->last_processing_stats ) ) {
            return $this->last_processing_stats;
        }

        return [
            'pending_count' => count( $this->pending_images ),
            'placeholder_count' => count( $this->image_placeholders ),
            'successful_downloads' => count( array_filter( $this->image_placeholders, function( $id ) {
                return is_numeric( $id ) && $id > 0;
            } ) ),
            'failed_downloads' => count( array_filter( $this->image_placeholders, function( $id ) {
                return $id === null;
            } ) )
        ];
    }

    /**
     * 启用异步图片下载模式
     *
     * @since    1.9.0-beta.1
     * @deprecated 2.0.0-beta.1 使用 Notion_Image_Processor::enable_async_image_mode() 代替
     */
    public function enable_async_image_mode(): void {
        // 委托给图片处理器
        Notion_Image_Processor::enable_async_image_mode();
    }

    /**
     * 禁用异步图片下载模式
     *
     * @since    1.9.0-beta.1
     */
    public function disable_async_image_mode(): void {
        // 委托给图片处理器
        Notion_Image_Processor::disable_async_image_mode();
    }

    /**
     * 处理异步图片下载并替换占位符
     *
     * @since    1.9.0-beta.1
     * @param    string    $html    包含占位符的HTML内容
     * @return   string             处理后的HTML内容
     */
    public function process_async_images( string $html ): string {
        // 委托给图片处理器
        return Notion_Image_Processor::process_async_images($html);
    }

    // ==================== 向后兼容的委托方法 ====================
}