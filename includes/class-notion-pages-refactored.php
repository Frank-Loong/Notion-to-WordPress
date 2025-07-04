<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 重构后的Notion页面处理器
 *
 * 简化的页面处理器，主要作为协调器使用
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notion_Pages_Refactored {

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
     * 锁超时时间
     *
     * @since 1.1.0
     * @var int
     */
    private int $lock_timeout;

    /**
     * 自定义字段映射
     *
     * @since 1.1.0
     * @var array
     */
    private array $custom_field_mappings = [];

    /**
     * 页面导入器实例
     *
     * @since 1.1.0
     * @var Notion_Page_Importer|null
     */
    private ?Notion_Page_Importer $importer = null;

    /**
     * 构造函数
     *
     * @since 1.1.0
     * @param Notion_API $notion_api   Notion API实例
     * @param string     $database_id  数据库ID
     * @param array      $field_mapping 字段映射
     * @param int        $lock_timeout 锁超时时间
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
     * @since 1.1.0
     * @param array $mappings 自定义字段映射数组
     */
    public function set_custom_field_mappings(array $mappings): void {
        $this->custom_field_mappings = $mappings;
        
        // 如果导入器已创建，也更新它的映射
        if ($this->importer) {
            $this->importer->set_custom_field_mappings($mappings);
        }
    }

    /**
     * 获取页面导入器实例
     *
     * @since 1.1.0
     * @return Notion_Page_Importer
     */
    private function get_importer(): Notion_Page_Importer {
        if (!$this->importer) {
            $this->importer = new Notion_Page_Importer($this->notion_api, $this->database_id, $this->field_mapping);
            $this->importer->set_custom_field_mappings($this->custom_field_mappings);
        }
        return $this->importer;
    }

    /**
     * 导入单个Notion页面
     *
     * @since 1.1.0
     * @param array $page Notion页面数据
     * @return bool 导入是否成功
     */
    public function import_notion_page(array $page): bool {
        $result = $this->get_importer()->import_page($page);
        return $result['success'] ?? false;
    }

    /**
     * 批量导入页面
     *
     * @since 1.1.0
     * @param bool   $force_update    是否强制更新
     * @param string $filter_page_id  过滤特定页面ID
     * @return array 导入统计信息
     */
    public function import_pages(bool $force_update = false, string $filter_page_id = ''): array {
        // 使用导入协调器进行批量导入
        if (class_exists('Notion_Import_Coordinator')) {
            $coordinator = new Notion_Import_Coordinator($this, $this->notion_api, $this->database_id, $this->lock_timeout);
            return $coordinator->run($force_update, $filter_page_id);
        }

        // 回退到直接导入
        try {
            $pages = $this->get_pages_to_import($force_update, $filter_page_id);
            return $this->get_importer()->import_pages($pages, $force_update);
        } catch (Exception $e) {
            Notion_To_WordPress_Error_Handler::exception_to_wp_error(
                $e,
                Notion_To_WordPress_Error_Handler::CODE_IMPORT_ERROR,
                ['filter_page_id' => $filter_page_id, 'force_update' => $force_update]
            );

            return [
                'total' => 0,
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 1,
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * 获取要导入的页面列表
     *
     * @since 1.1.0
     * @param bool   $force_update    是否强制更新
     * @param string $filter_page_id  过滤特定页面ID
     * @return array 页面列表
     */
    private function get_pages_to_import(bool $force_update = false, string $filter_page_id = ''): array {
        if (!empty($filter_page_id)) {
            // 获取单个页面
            $page = $this->notion_api->get_page($filter_page_id);
            return $page ? [$page] : [];
        }

        // 获取数据库中的所有页面
        return $this->notion_api->get_database_pages($this->database_id);
    }

    /**
     * 根据Notion页面ID获取WordPress文章ID
     *
     * @since 1.1.0
     * @param string $notion_id Notion页面ID
     * @return int WordPress文章ID
     */
    public function get_post_by_notion_id(string $notion_id): int {
        return Notion_WordPress_Integrator::get_post_by_notion_id($notion_id);
    }

    /**
     * 处理单个页面（用于导入协调器）
     *
     * @since 1.1.0
     * @param array $page        Notion页面数据
     * @param bool  $force_update 是否强制更新
     * @return array 处理结果
     */
    public function process_single_page(array $page, bool $force_update = false): array {
        return $this->get_importer()->import_page($page);
    }

    /**
     * 转换单个块（用于块转换器）
     *
     * @since 1.1.0
     * @param array      $block 块数据
     * @param Notion_API $api   API实例
     * @return string HTML内容
     */
    public function convert_single_block(array $block, Notion_API $api): string {
        // 这个方法主要用于向后兼容
        // 实际的块转换逻辑已经移到 Notion_Block_Converter
        $type = $block['type'] ?? '';
        
        switch ($type) {
            case 'paragraph':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['paragraph']['rich_text'] ?? []);
                return empty(trim($text)) ? '' : "<p>{$text}</p>";
            
            case 'heading_1':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['heading_1']['rich_text'] ?? []);
                $anchor = str_replace('-', '', $block['id'] ?? '');
                return "<h1 id=\"{$anchor}\">{$text}</h1>";
            
            case 'heading_2':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['heading_2']['rich_text'] ?? []);
                $anchor = str_replace('-', '', $block['id'] ?? '');
                return "<h2 id=\"{$anchor}\">{$text}</h2>";
            
            case 'heading_3':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['heading_3']['rich_text'] ?? []);
                $anchor = str_replace('-', '', $block['id'] ?? '');
                return "<h3 id=\"{$anchor}\">{$text}</h3>";
            
            case 'bulleted_list_item':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['bulleted_list_item']['rich_text'] ?? []);
                return "<li>{$text}</li>";
            
            case 'numbered_list_item':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['numbered_list_item']['rich_text'] ?? []);
                return "<li>{$text}</li>";
            
            case 'quote':
                $text = Notion_Rich_Text_Processor::convert_to_html($block['quote']['rich_text'] ?? []);
                return "<blockquote>{$text}</blockquote>";
            
            case 'divider':
                return '<hr>';
            
            default:
                return '<!-- 不支持的块类型: ' . esc_html($type) . ' -->';
        }
    }

    /**
     * 清理静态缓存
     *
     * @since 1.1.0
     */
    public static function clear_static_cache(): void {
        // 清理WordPress集成器的静态缓存
        if (class_exists('Notion_WordPress_Integrator') && method_exists('Notion_WordPress_Integrator', 'clear_static_cache')) {
            Notion_WordPress_Integrator::clear_static_cache();
        }

        // 记录缓存清理
        if (class_exists('Notion_To_WordPress_Helper')) {
            Notion_To_WordPress_Helper::debug_log(
                '已清理Notion_Pages_Refactored静态缓存',
                'Cache Manager',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG
            );
        }
    }

    /**
     * 提取页面标题（向后兼容方法）
     *
     * @since 1.1.0
     * @param array $page Notion页面数据
     * @return string 页面标题
     */
    public function extract_page_title(array $page): string {
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
     * 获取数据库ID
     *
     * @since 1.1.0
     * @return string 数据库ID
     */
    public function get_database_id(): string {
        return $this->database_id;
    }

    /**
     * 获取字段映射
     *
     * @since 1.1.0
     * @return array 字段映射
     */
    public function get_field_mapping(): array {
        return $this->field_mapping;
    }

    /**
     * 获取自定义字段映射
     *
     * @since 1.1.0
     * @return array 自定义字段映射
     */
    public function get_custom_field_mappings(): array {
        return $this->custom_field_mappings;
    }

}
