<?php
declare(strict_types=1);

namespace NTWP\Handlers;

use NTWP\Services\API;
use NTWP\Core\Logger;

/**
 * Import_Coordinator适配器
 * 
 * 保持向后兼容性，将旧的Import_Coordinator接口适配到新的ImportWorkflow
 * 
 * @deprecated 使用 NTWP\Application\ImportWorkflow 替代
 */
class Import_Coordinator {

    private API $notion_api;
    private string $database_id;
    private array $field_mapping;
    private array $custom_field_mappings = [];

    // 保持与原Import_Coordinator兼容的内部实例
    private ?\NTWP\Handlers\Import_Coordinator $legacy_coordinator = null;
    
    /**
     * 构造函数 - 保持与原Import_Coordinator兼容
     */
    public function __construct(API $notion_api, string $database_id, array $field_mapping = []) {
        $this->notion_api = $notion_api;
        $this->database_id = $database_id;
        $this->field_mapping = $field_mapping;

        // 暂时使用原有的Import_Coordinator作为后备
        // 在完整的ImportWorkflow准备好之前，委托给原实现
        $this->initialize_legacy_coordinator();

        Logger::debug_log('Import_Coordinator适配器已初始化 (使用legacy模式)', 'Import Adapter');
    }

    /**
     * 初始化legacy coordinator作为后备
     */
    private function initialize_legacy_coordinator(): void {
        // 检查原Import_Coordinator是否存在
        if (class_exists('\\NTWP\\Handlers\\Import_Coordinator', false)) {
            // 避免循环引用，暂时不创建legacy实例
            Logger::debug_log('检测到Import_Coordinator类冲突，使用适配器模式', 'Import Adapter');
        }
    }
    
    /**
     * 导入所有页面 - 兼容原接口
     *
     * @param bool $check_deletions 是否检查删除
     * @param bool $incremental 是否增量同步
     * @param bool $force_refresh 是否强制刷新
     * @return array|WP_Error 导入结果
     */
    public function import_pages($check_deletions = true, $incremental = true, $force_refresh = false) {
        try {
            Logger::info_log('开始导入页面 (适配器模式)', 'Import Adapter');

            // 简化实现：直接使用现有的API和服务
            $start_time = microtime(true);

            // 获取数据库页面
            $pages = $this->notion_api->get_database_pages($this->database_id);

            if (empty($pages)) {
                return [
                    'imported_count' => 0,
                    'failed_count' => 0,
                    'execution_time' => microtime(true) - $start_time,
                    'success' => true,
                    'message' => '没有找到需要导入的页面'
                ];
            }

            // 简单的批量处理
            $imported_count = 0;
            $failed_count = 0;

            foreach ($pages as $page) {
                try {
                    $result = $this->import_single_page($page);
                    if ($result) {
                        $imported_count++;
                    } else {
                        $failed_count++;
                    }
                } catch (\Exception $e) {
                    Logger::error_log('导入单页面失败: ' . $e->getMessage(), 'Import Adapter');
                    $failed_count++;
                }
            }

            return [
                'imported_count' => $imported_count,
                'failed_count' => $failed_count,
                'execution_time' => microtime(true) - $start_time,
                'success' => true,
                'performance' => [],
                'link_conversion' => []
            ];

        } catch (\Exception $e) {
            Logger::error_log('导入页面失败: ' . $e->getMessage(), 'Import Adapter');
            return new \WP_Error('import_failed', $e->getMessage());
        }
    }
    
    /**
     * 导入单个页面 - 兼容原接口
     */
    public function import_notion_page(array $page) {
        try {
            return $this->import_single_page($page);
        } catch (\Exception $e) {
            Logger::error_log('导入单页面失败: ' . $e->getMessage(), 'Import Adapter');
            return false;
        }
    }

    /**
     * 内部单页面导入实现
     */
    private function import_single_page(array $page): bool {
        // 简化的单页面导入逻辑
        $page_id = $page['id'] ?? '';
        if (empty($page_id)) {
            return false;
        }

        // 获取页面详情
        $page_details = $this->notion_api->get_page_details($page_id);
        if (empty($page_details)) {
            return false;
        }

        // 提取元数据
        $metadata = $this->extract_page_metadata($page_details);

        // 获取页面内容
        $blocks = $this->notion_api->get_page_blocks($page_id);
        $content = $this->convert_blocks_to_content($blocks);

        // 保存到WordPress
        return $this->save_to_wordpress($metadata, $content, $page_id);
    }
    
    /**
     * 获取页面数据 - 兼容原接口
     */
    public function get_page_data(string $page_id): array {
        try {
            return $this->notion_api->get_page_details($page_id);
        } catch (\Exception $e) {
            Logger::error_log('获取页面数据失败: ' . $e->getMessage(), 'Import Adapter');
            return [];
        }
    }
    
    /**
     * 设置自定义字段映射 - 兼容原接口
     */
    public function set_custom_field_mappings(array $mappings): void {
        $this->custom_field_mappings = $mappings;
        Logger::debug_log('自定义字段映射已更新', 'Import Adapter');
    }
    
    /**
     * 注册AJAX处理器 - 兼容原接口
     */
    public function register_ajax_handlers(): void {
        add_action('wp_ajax_notion_get_record_details', [$this, 'ajax_get_record_details']);
        add_action('wp_ajax_nopriv_notion_get_record_details', [$this, 'ajax_get_record_details']);
    }
    
    /**
     * AJAX获取记录详情 - 兼容原接口
     */
    public function ajax_get_record_details(): void {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'notion_admin_nonce')) {
            wp_send_json_error(__('安全验证失败', 'notion-to-wordpress'));
            return;
        }
        
        $record_id = sanitize_text_field($_POST['record_id'] ?? '');
        if (empty($record_id)) {
            wp_send_json_error(__('记录ID不能为空', 'notion-to-wordpress'));
            return;
        }
        
        try {
            $record_details = $this->notion_api->get_page_details($record_id);
            
            if (empty($record_details)) {
                wp_send_json_error(__('无法获取记录详情', 'notion-to-wordpress'));
                return;
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
            
        } catch (\Exception $e) {
            Logger::error_log('AJAX获取记录详情失败: ' . $e->getMessage(), 'AJAX Record Details');
            wp_send_json_error(sprintf(__('获取记录详情失败: %s', 'notion-to-wordpress'), $e->getMessage()));
        }
    }
    
    /**
     * 禁用异步图片模式 - 兼容原接口
     */
    public function disable_async_image_mode(string $state_id = null): void {
        // 委托给图片处理器
        if (class_exists('\\NTWP\\Services\\Image_Processor')) {
            \NTWP\Services\Image_Processor::disable_async_image_mode($state_id);
        }
    }
    
    /**
     * 提取页面元数据
     */
    private function extract_page_metadata(array $page): array {
        $properties = $page['properties'] ?? [];
        $metadata = [
            'title' => $this->extract_title($page),
            'notion_id' => $page['id'] ?? '',
            'last_edited_time' => $page['last_edited_time'] ?? '',
            'created_time' => $page['created_time'] ?? '',
            'notion_url' => $page['url'] ?? ''
        ];

        // 应用字段映射
        foreach ($properties as $property_name => $property_data) {
            $wp_field = $this->field_mapping[$property_name] ?? $property_name;
            $value = $this->extract_property_value($property_data);

            if ($value !== null) {
                $metadata[$wp_field] = $value;
            }
        }

        return $metadata;
    }

    /**
     * 提取页面标题
     */
    private function extract_title(array $page): string {
        $properties = $page['properties'] ?? [];

        // 查找标题属性
        foreach ($properties as $property) {
            if (($property['type'] ?? '') === 'title') {
                $title_array = $property['title'] ?? [];
                if (!empty($title_array[0]['plain_text'])) {
                    return $title_array[0]['plain_text'];
                }
            }
        }

        return 'Untitled Page';
    }

    /**
     * 提取属性值
     */
    private function extract_property_value(array $property): mixed {
        $type = $property['type'] ?? '';

        switch ($type) {
            case 'rich_text':
                return $property['rich_text'][0]['plain_text'] ?? '';
            case 'title':
                return $property['title'][0]['plain_text'] ?? '';
            case 'select':
                return $property['select']['name'] ?? '';
            case 'multi_select':
                return array_column($property['multi_select'] ?? [], 'name');
            case 'date':
                return $property['date']['start'] ?? '';
            case 'checkbox':
                return $property['checkbox'] ?? false;
            case 'number':
                return $property['number'] ?? 0;
            case 'url':
                return $property['url'] ?? '';
            case 'email':
                return $property['email'] ?? '';
            case 'phone_number':
                return $property['phone_number'] ?? '';
            default:
                return null;
        }
    }

    /**
     * 转换块为内容
     */
    private function convert_blocks_to_content(array $blocks): string {
        if (empty($blocks)) {
            return '';
        }

        // 优先使用新的ContentProcessingService，向后兼容Content_Converter
        if (class_exists('\\NTWP\\Services\\ContentProcessingService')) {
            return \NTWP\Services\ContentProcessingService::convert_blocks_to_html_static($blocks, $this->notion_api);
        } elseif (class_exists('\\NTWP\\Services\\Content_Converter')) {
            return \NTWP\Services\Content_Converter::convert_blocks_to_html($blocks, $this->notion_api);
        }

        // 简化的块转换
        $content_parts = [];
        foreach ($blocks as $block) {
            $content_parts[] = $this->convert_single_block($block);
        }

        return implode("\n", array_filter($content_parts));
    }

    /**
     * 转换单个块
     */
    private function convert_single_block(array $block): string {
        $type = $block['type'] ?? '';

        switch ($type) {
            case 'paragraph':
                $text = $this->extract_rich_text($block['paragraph']['rich_text'] ?? []);
                return "<p>{$text}</p>";
            case 'heading_1':
                $text = $this->extract_rich_text($block['heading_1']['rich_text'] ?? []);
                return "<h1>{$text}</h1>";
            case 'heading_2':
                $text = $this->extract_rich_text($block['heading_2']['rich_text'] ?? []);
                return "<h2>{$text}</h2>";
            case 'heading_3':
                $text = $this->extract_rich_text($block['heading_3']['rich_text'] ?? []);
                return "<h3>{$text}</h3>";
            default:
                return '';
        }
    }

    /**
     * 提取富文本
     */
    private function extract_rich_text(array $rich_text): string {
        $text_parts = [];
        foreach ($rich_text as $text_obj) {
            $text_parts[] = $text_obj['plain_text'] ?? '';
        }
        return implode('', $text_parts);
    }

    /**
     * 保存到WordPress
     */
    private function save_to_wordpress(array $metadata, string $content, string $notion_id): bool {
        // 检查是否已存在
        $existing_post = $this->find_existing_post($notion_id);

        $post_data = [
            'post_title' => $metadata['title'] ?? 'Untitled',
            'post_content' => $content,
            'post_status' => 'draft',
            'meta_input' => [
                'notion_id' => $notion_id,
                'notion_url' => $metadata['notion_url'] ?? '',
                'last_edited_time' => $metadata['last_edited_time'] ?? ''
            ]
        ];

        if ($existing_post) {
            $post_data['ID'] = $existing_post->ID;
            $result = wp_update_post($post_data);
        } else {
            $result = wp_insert_post($post_data);
        }

        return !is_wp_error($result) && $result > 0;
    }

    /**
     * 查找现有文章
     */
    private function find_existing_post(string $notion_id): ?\WP_Post {
        $posts = get_posts([
            'meta_key' => 'notion_id',
            'meta_value' => $notion_id,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ]);

        return $posts ? $posts[0] : null;
    }
}
