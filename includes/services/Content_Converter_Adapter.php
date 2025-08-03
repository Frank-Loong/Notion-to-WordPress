<?php
declare(strict_types=1);

namespace NTWP\Services;

use NTWP\Core\Logger;

/**
 * Content_Converter向后兼容适配器
 * 
 * 将旧的Content_Converter调用适配到新的ContentProcessingService
 * 
 * @deprecated 使用 NTWP\Services\ContentProcessingService 替代
 */
class Content_Converter_Adapter {
    
    /**
     * 将 Notion 块数组转换为 HTML 内容 (适配器方法)
     *
     * @param array $blocks Notion 块数组
     * @param \NTWP\Services\API $notion_api Notion API 实例
     * @param string $state_id 状态管理器ID，用于图片处理状态隔离
     * @return string HTML 内容
     */
    public static function convert_blocks_to_html(array $blocks, $notion_api, string $state_id = null): string {
        Logger::debug_log('使用Content_Converter适配器 - convert_blocks_to_html', 'Content Adapter');
        
        // 优先使用新的ContentProcessingService
        if (class_exists('\\NTWP\\Services\\ContentProcessingService')) {
            return \NTWP\Services\ContentProcessingService::convert_blocks_to_html_static($blocks, $notion_api, $state_id);
        }
        
        // 回退到原始Content_Converter
        if (class_exists('\\NTWP\\Services\\Content_Converter')) {
            return \NTWP\Services\Content_Converter::convert_blocks_to_html($blocks, $notion_api, $state_id);
        }
        
        // 最后的回退：简化处理
        Logger::warning_log('Content_Converter和ContentProcessingService都不可用，使用简化处理', 'Content Adapter');
        return self::simple_blocks_conversion($blocks);
    }
    
    /**
     * 转换单个Notion块为HTML (适配器方法)
     *
     * @param array $block Notion块数据
     * @param \NTWP\Services\API $notion_api Notion API实例
     * @return string 转换后的HTML内容
     */
    public static function convert_block_to_html(array $block, $notion_api): string {
        Logger::debug_log('使用Content_Converter适配器 - convert_block_to_html', 'Content Adapter');
        
        // 委托给原始Content_Converter
        if (class_exists('\\NTWP\\Services\\Content_Converter')) {
            return \NTWP\Services\Content_Converter::convert_block_to_html($block, $notion_api);
        }
        
        // 简化的单块转换
        return self::simple_block_conversion($block);
    }
    
    /**
     * 简化的块转换处理
     */
    private static function simple_blocks_conversion(array $blocks): string {
        if (empty($blocks)) {
            return '';
        }
        
        $html_parts = [];
        
        foreach ($blocks as $block) {
            $html_parts[] = self::simple_block_conversion($block);
        }
        
        return implode("\n", array_filter($html_parts));
    }
    
    /**
     * 简化的单块转换处理
     */
    private static function simple_block_conversion(array $block): string {
        if (empty($block) || !isset($block['type'])) {
            return '';
        }
        
        $type = $block['type'];
        $content = '';
        
        switch ($type) {
            case 'paragraph':
                $content = self::extract_rich_text($block['paragraph']['rich_text'] ?? []);
                return $content ? "<p>{$content}</p>" : '';
                
            case 'heading_1':
                $content = self::extract_rich_text($block['heading_1']['rich_text'] ?? []);
                return $content ? "<h1>{$content}</h1>" : '';
                
            case 'heading_2':
                $content = self::extract_rich_text($block['heading_2']['rich_text'] ?? []);
                return $content ? "<h2>{$content}</h2>" : '';
                
            case 'heading_3':
                $content = self::extract_rich_text($block['heading_3']['rich_text'] ?? []);
                return $content ? "<h3>{$content}</h3>" : '';
                
            case 'bulleted_list_item':
                $content = self::extract_rich_text($block['bulleted_list_item']['rich_text'] ?? []);
                return $content ? "<li>{$content}</li>" : '';
                
            case 'numbered_list_item':
                $content = self::extract_rich_text($block['numbered_list_item']['rich_text'] ?? []);
                return $content ? "<li>{$content}</li>" : '';
                
            case 'quote':
                $content = self::extract_rich_text($block['quote']['rich_text'] ?? []);
                return $content ? "<blockquote>{$content}</blockquote>" : '';
                
            case 'code':
                $code_content = self::extract_rich_text($block['code']['rich_text'] ?? []);
                $language = $block['code']['language'] ?? 'text';
                return $code_content ? "<pre><code class=\"language-{$language}\">{$code_content}</code></pre>" : '';
                
            case 'image':
                $url = $block['image']['file']['url'] ?? $block['image']['external']['url'] ?? '';
                $caption = self::extract_rich_text($block['image']['caption'] ?? []);
                if ($url) {
                    $alt = $caption ? esc_attr($caption) : '';
                    $html = '<figure class="notion-image">';
                    $html .= '<img src="' . esc_url($url) . '" alt="' . $alt . '" loading="lazy">';
                    if ($caption) {
                        $html .= '<figcaption>' . $caption . '</figcaption>';
                    }
                    $html .= '</figure>';
                    return $html;
                }
                return '';
                
            case 'divider':
                return '<hr>';
                
            default:
                Logger::debug_log("未处理的块类型: {$type}", 'Content Adapter');
                return "<!-- 未处理的块类型: {$type} -->";
        }
    }
    
    /**
     * 提取富文本内容
     */
    private static function extract_rich_text(array $rich_text): string {
        if (empty($rich_text)) {
            return '';
        }
        
        $text_parts = [];
        
        foreach ($rich_text as $text_obj) {
            $text = $text_obj['text']['content'] ?? '';
            $annotations = $text_obj['annotations'] ?? [];
            
            // 应用格式化
            if ($annotations['bold'] ?? false) {
                $text = "<strong>{$text}</strong>";
            }
            if ($annotations['italic'] ?? false) {
                $text = "<em>{$text}</em>";
            }
            if ($annotations['strikethrough'] ?? false) {
                $text = "<del>{$text}</del>";
            }
            if ($annotations['underline'] ?? false) {
                $text = "<u>{$text}</u>";
            }
            if ($annotations['code'] ?? false) {
                $text = "<code>{$text}</code>";
            }
            
            // 处理链接
            if (isset($text_obj['text']['link']['url'])) {
                $url = $text_obj['text']['link']['url'];
                $text = '<a href="' . esc_url($url) . '">' . $text . '</a>';
            }
            
            $text_parts[] = $text;
        }
        
        return implode('', $text_parts);
    }
    
    /**
     * 获取预处理的数据库数据 (适配器方法)
     */
    public static function get_preprocessed_database_data(): array {
        Logger::debug_log('使用Content_Converter适配器 - get_preprocessed_database_data', 'Content Adapter');
        
        if (class_exists('\\NTWP\\Services\\Content_Converter')) {
            // 通过反射访问私有静态属性
            try {
                $reflection = new \ReflectionClass('\\NTWP\\Services\\Content_Converter');
                $property = $reflection->getProperty('preprocessed_database_data');
                $property->setAccessible(true);
                return $property->getValue() ?? [];
            } catch (\Exception $e) {
                Logger::error_log('无法访问预处理数据库数据: ' . $e->getMessage(), 'Content Adapter');
                return [];
            }
        }
        
        return [];
    }
    
    /**
     * 设置预处理的数据库数据 (适配器方法)
     */
    public static function set_preprocessed_database_data(array $data): void {
        Logger::debug_log('使用Content_Converter适配器 - set_preprocessed_database_data', 'Content Adapter');
        
        if (class_exists('\\NTWP\\Services\\Content_Converter')) {
            // 通过反射访问私有静态属性
            try {
                $reflection = new \ReflectionClass('\\NTWP\\Services\\Content_Converter');
                $property = $reflection->getProperty('preprocessed_database_data');
                $property->setAccessible(true);
                $property->setValue($data);
            } catch (\Exception $e) {
                Logger::error_log('无法设置预处理数据库数据: ' . $e->getMessage(), 'Content Adapter');
            }
        }
    }
    
    /**
     * 清理预处理的数据库数据 (适配器方法)
     */
    public static function clear_preprocessed_database_data(): void {
        Logger::debug_log('使用Content_Converter适配器 - clear_preprocessed_database_data', 'Content Adapter');
        
        if (class_exists('\\NTWP\\Services\\Content_Converter')) {
            // 通过反射访问私有静态属性
            try {
                $reflection = new \ReflectionClass('\\NTWP\\Services\\Content_Converter');
                $property = $reflection->getProperty('preprocessed_database_data');
                $property->setAccessible(true);
                $property->setValue([]);
            } catch (\Exception $e) {
                Logger::error_log('无法清理预处理数据库数据: ' . $e->getMessage(), 'Content Adapter');
            }
        }
    }
    
    /**
     * 获取转换统计信息 (适配器方法)
     */
    public static function get_conversion_stats(): array {
        Logger::debug_log('使用Content_Converter适配器 - get_conversion_stats', 'Content Adapter');
        
        // 返回基本统计信息
        return [
            'blocks_processed' => 0,
            'conversion_time' => 0,
            'memory_usage' => memory_get_usage(true),
            'adapter_mode' => true
        ];
    }
    
    /**
     * 重置转换统计信息 (适配器方法)
     */
    public static function reset_conversion_stats(): void {
        Logger::debug_log('使用Content_Converter适配器 - reset_conversion_stats', 'Content Adapter');
        // 适配器模式下无需实际重置
    }
}
