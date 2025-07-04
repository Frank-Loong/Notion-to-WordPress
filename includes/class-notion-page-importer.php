<?php
/**
 * Notion页面导入器
 *
 * 专门负责Notion页面的导入逻辑
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */
declare(strict_types=1);

class Notion_Page_Importer {

    /**
     * Notion API实例
     *
     * @since 1.1.0
     * @var Notion_API
     */
    private Notion_API $notion_api;

    /**
     * 数据库ID
     *
     * @since 1.1.0
     * @var string
     */
    private string $database_id;

    /**
     * 字段映射
     *
     * @since 1.1.0
     * @var array
     */
    private array $field_mapping;

    /**
     * 自定义字段映射
     *
     * @since 1.1.0
     * @var array
     */
    private array $custom_field_mappings = [];

    /**
     * 构造函数
     *
     * @since 1.1.0
     * @param Notion_API $notion_api   Notion API实例
     * @param string     $database_id  数据库ID
     * @param array      $field_mapping 字段映射
     */
    public function __construct(Notion_API $notion_api, string $database_id, array $field_mapping = []) {
        $this->notion_api = $notion_api;
        $this->database_id = $database_id;
        $this->field_mapping = $field_mapping;
    }

    /**
     * 设置自定义字段映射
     *
     * @since 1.1.0
     * @param array $mappings 自定义字段映射数组
     */
    public function set_custom_field_mappings(array $mappings): void {
        $this->custom_field_mappings = $mappings;
    }

    /**
     * 导入单个Notion页面
     *
     * @since 1.1.0
     * @param array $page Notion页面数据
     * @return array 导入结果
     */
    public function import_page(array $page): array {
        if (empty($page) || !isset($page['id'])) {
            return [
                'success' => false,
                'error' => '页面数据为空或缺少ID',
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 1
            ];
        }

        $page_id = $page['id'];
        $page_title = $this->extract_page_title($page);

        try {
            // 检查页面是否已存在
            $existing_post_id = $this->get_post_by_notion_id($page_id);
            
            // 提取页面元数据
            $metadata = $this->extract_page_metadata($page);
            
            // 获取页面内容
            $blocks = $this->notion_api->get_page_content($page_id);
            $content = $this->convert_blocks_to_html($blocks);
            
            // 获取作者ID
            $author_id = $this->get_author_id();
            
            // 创建或更新文章
            $post_result = $this->create_or_update_post(
                $metadata,
                $content,
                $author_id,
                $page_id,
                $existing_post_id
            );

            if (is_wp_error($post_result)) {
                throw new Exception($post_result->get_error_message());
            }

            $post_id = (int) $post_result;
            
            // 应用自定义字段
            if (!empty($metadata['custom_fields'])) {
                $this->apply_custom_fields($post_id, $metadata['custom_fields']);
            }
            
            // 设置分类和标签
            $this->apply_taxonomies($post_id, $metadata);
            
            // 处理特色图片
            $this->apply_featured_image($post_id, $metadata);

            // 记录成功日志
            Notion_To_WordPress_Error_Handler::log_info(
                "页面导入成功: {$page_title} (ID: {$post_id})",
                Notion_To_WordPress_Error_Handler::CODE_IMPORT_ERROR,
                ['page_id' => $page_id, 'post_id' => $post_id]
            );

            return [
                'success' => true,
                'post_id' => $post_id,
                'imported' => $existing_post_id ? 0 : 1,
                'updated' => $existing_post_id ? 1 : 0,
                'skipped' => 0,
                'failed' => 0
            ];

        } catch (Exception $e) {
            Notion_To_WordPress_Error_Handler::exception_to_wp_error(
                $e,
                Notion_To_WordPress_Error_Handler::CODE_IMPORT_ERROR,
                ['page_id' => $page_id, 'page_title' => $page_title]
            );

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 1
            ];
        }
    }

    /**
     * 批量导入页面
     *
     * @since 1.1.0
     * @param array $pages        页面数组
     * @param bool  $force_update 是否强制更新
     * @return array 导入统计
     */
    public function import_pages(array $pages, bool $force_update = false): array {
        $stats = [
            'total' => count($pages),
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => []
        ];

        // 使用内存管理器进行批量处理
        if (class_exists('Notion_To_WordPress_Memory_Manager')) {
            $batch_callback = function($page) use ($force_update, &$stats) {
                $result = $this->import_page($page);
                
                $stats['imported'] += $result['imported'] ?? 0;
                $stats['updated'] += $result['updated'] ?? 0;
                $stats['skipped'] += $result['skipped'] ?? 0;
                $stats['failed'] += $result['failed'] ?? 0;

                if (!$result['success'] && isset($result['error'])) {
                    $stats['errors'][] = $result['error'];
                }
                
                return $result;
            };

            Notion_To_WordPress_Memory_Manager::process_in_batches($pages, $batch_callback, 20);
        } else {
            // 回退到原始处理方式
            foreach ($pages as $page) {
                $result = $this->import_page($page);
                
                $stats['imported'] += $result['imported'] ?? 0;
                $stats['updated'] += $result['updated'] ?? 0;
                $stats['skipped'] += $result['skipped'] ?? 0;
                $stats['failed'] += $result['failed'] ?? 0;

                if (!$result['success'] && isset($result['error'])) {
                    $stats['errors'][] = $result['error'];
                }
            }
        }

        return $stats;
    }

    /**
     * 提取页面标题
     *
     * @since 1.1.0
     * @param array $page Notion页面数据
     * @return string 页面标题
     */
    private function extract_page_title(array $page): string {
        if (!isset($page['properties']) || !is_array($page['properties'])) {
            return '(无标题)';
        }

        // 查找标题字段
        foreach ($page['properties'] as $property) {
            if (isset($property['type']) && $property['type'] === 'title') {
                if (isset($property['title']) && is_array($property['title'])) {
                    return Notion_Rich_Text_Processor::extract_plain_text($property['title']);
                }
            }
        }

        return '(无标题)';
    }

    /**
     * 提取页面元数据
     *
     * @since 1.1.0
     * @param array $page Notion页面数据
     * @return array 页面元数据
     */
    private function extract_page_metadata(array $page): array {
        $metadata = [];
        $props = $page['properties'] ?? [];

        // 获取保存的选项，包括字段映射
        $options = get_option('notion_to_wordpress_options', []);
        $field_mapping = array_merge($this->field_mapping, $options['field_mapping'] ?? []);

        // 提取标题
        $metadata['title'] = $this->extract_page_title($page);

        // 提取其他字段
        foreach ($field_mapping as $notion_field => $wp_field) {
            if (isset($props[$notion_field])) {
                $value = $this->extract_property_value($props[$notion_field]);
                if ($value !== null) {
                    $metadata[$wp_field] = $value;
                }
            }
        }

        // 处理自定义字段映射
        $metadata['custom_fields'] = [];
        foreach ($this->custom_field_mappings as $notion_field => $custom_field) {
            if (isset($props[$notion_field])) {
                $value = $this->extract_property_value($props[$notion_field]);
                if ($value !== null) {
                    $metadata['custom_fields'][$custom_field] = $value;
                }
            }
        }

        return $metadata;
    }

    /**
     * 提取属性值
     *
     * @since 1.1.0
     * @param array $property 属性数据
     * @return mixed 属性值
     */
    private function extract_property_value(array $property) {
        $type = $property['type'] ?? '';

        switch ($type) {
            case 'title':
            case 'rich_text':
                return Notion_Rich_Text_Processor::extract_plain_text($property[$type] ?? []);
            
            case 'select':
                return $property['select']['name'] ?? null;
            
            case 'multi_select':
                $values = [];
                foreach ($property['multi_select'] ?? [] as $item) {
                    $values[] = $item['name'] ?? '';
                }
                return $values;
            
            case 'date':
                return $property['date']['start'] ?? null;
            
            case 'checkbox':
                return $property['checkbox'] ?? false;
            
            case 'number':
                return $property['number'] ?? null;
            
            case 'url':
                return $property['url'] ?? null;
            
            case 'email':
                return $property['email'] ?? null;
            
            case 'phone_number':
                return $property['phone_number'] ?? null;
            
            default:
                return null;
        }
    }

    /**
     * 转换块为HTML
     *
     * @since 1.1.0
     * @param array $blocks 块数组
     * @return string HTML内容
     */
    private function convert_blocks_to_html(array $blocks): string {
        if (class_exists('Notion_Block_Converter')) {
            $converter = new Notion_Block_Converter($this, $this->notion_api);
            return $converter->convert_blocks($blocks);
        }

        // 回退到简单的HTML转换
        $html = '';
        foreach ($blocks as $block) {
            $html .= $this->convert_single_block($block);
        }
        return $html;
    }

    /**
     * 转换单个块
     *
     * @since 1.1.0
     * @param array $block 块数据
     * @return string HTML内容
     */
    private function convert_single_block(array $block): string {
        $type = $block['type'] ?? '';

        switch ($type) {
            case 'paragraph':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['paragraph']['rich_text'] ?? []);
                return empty(trim($text)) ? '' : "<p>{$text}</p>";

            case 'heading_1':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['heading_1']['rich_text'] ?? []);
                return "<h1>{$text}</h1>";

            case 'heading_2':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['heading_2']['rich_text'] ?? []);
                return "<h2>{$text}</h2>";

            case 'heading_3':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['heading_3']['rich_text'] ?? []);
                return "<h3>{$text}</h3>";

            default:
                return '<!-- 不支持的块类型: ' . esc_html($type) . ' -->';
        }
    }

    /**
     * 根据Notion页面ID获取WordPress文章ID
     *
     * @since 1.1.0
     * @param string $notion_id Notion页面ID
     * @return int WordPress文章ID
     */
    private function get_post_by_notion_id(string $notion_id): int {
        return Notion_WordPress_Integrator::get_post_by_notion_id($notion_id);
    }

    /**
     * 创建或更新WordPress文章
     *
     * @since 1.1.0
     * @param array  $metadata        文章元数据
     * @param string $content         文章内容
     * @param int    $author_id       作者ID
     * @param string $page_id         Notion页面ID
     * @param int    $existing_post_id 现有文章ID
     * @return int|WP_Error 文章ID或错误对象
     */
    private function create_or_update_post(
        array $metadata,
        string $content,
        int $author_id,
        string $page_id,
        int $existing_post_id = 0
    ) {
        return Notion_WordPress_Integrator::create_or_update_post(
            $metadata,
            $content,
            $author_id,
            $page_id,
            $existing_post_id
        );
    }

    /**
     * 应用自定义字段
     *
     * @since 1.1.0
     * @param int   $post_id      文章ID
     * @param array $custom_fields 自定义字段
     */
    private function apply_custom_fields(int $post_id, array $custom_fields): void {
        Notion_WordPress_Integrator::apply_custom_fields($post_id, $custom_fields);
    }

    /**
     * 应用分类和标签
     *
     * @since 1.1.0
     * @param int   $post_id  文章ID
     * @param array $metadata 元数据
     */
    private function apply_taxonomies(int $post_id, array $metadata): void {
        Notion_WordPress_Integrator::apply_taxonomies($post_id, $metadata);
    }

    /**
     * 应用特色图片
     *
     * @since 1.1.0
     * @param int   $post_id  文章ID
     * @param array $metadata 元数据
     */
    private function apply_featured_image(int $post_id, array $metadata): void {
        Notion_WordPress_Integrator::apply_featured_image($post_id, $metadata);
    }

    /**
     * 获取作者ID
     *
     * @since 1.1.0
     * @return int 作者ID
     */
    private function get_author_id(): int {
        return Notion_WordPress_Integrator::get_author_id();
    }
}
