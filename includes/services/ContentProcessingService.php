<?php
declare(strict_types=1);

namespace NTWP\Services;

use NTWP\Core\Logger;

/**
 * 统一内容处理服务 - 合并ContentConverter + DatabaseRenderer
 * 
 * 职责: 统一处理内容转换、元数据提取、图片处理
 */
class ContentProcessingService {
    
    /**
     * 将Notion块转换为HTML
     */
    public function convert_blocks_to_html(array $blocks): string {
        if (empty($blocks)) {
            return '';
        }
        
        $html_parts = [];
        
        foreach ($blocks as $block) {
            $html_parts[] = $this->convert_single_block($block);
        }
        
        return implode("\n", array_filter($html_parts));
    }
    
    /**
     * 提取页面元数据
     */
    public function extract_metadata(array $page, array $field_mapping = []): array {
        $properties = $page['properties'] ?? [];
        $metadata = [];
        
        foreach ($properties as $property_name => $property_data) {
            $wp_field = $field_mapping[$property_name] ?? $this->normalize_field_name($property_name);
            $value = $this->extract_property_value($property_data);
            
            if ($value !== null) {
                $metadata[$wp_field] = $value;
            }
        }
        
        // 处理特殊字段
        $metadata['title'] = $this->extract_title($page);
        $metadata['last_edited_time'] = $page['last_edited_time'] ?? null;
        $metadata['created_time'] = $page['created_time'] ?? null;
        $metadata['notion_url'] = $page['url'] ?? null;
        
        return $metadata;
    }
    
    /**
     * 处理图片
     */
    public function process_images(string $content): string {
        // 查找所有图片URL
        preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $content, $matches);
        
        if (empty($matches[1])) {
            return $content;
        }
        
        foreach ($matches[1] as $index => $image_url) {
            try {
                $local_url = $this->download_and_save_image($image_url);
                if ($local_url) {
                    $content = str_replace($image_url, $local_url, $content);
                }
            } catch (\Exception $e) {
                Logger::warning_log("图片处理失败: {$image_url} - " . $e->getMessage(), 'ContentProcessing');
            }
        }
        
        return $content;
    }
    
    /**
     * 转换富文本内容
     */
    public function convert_rich_text(array $rich_text): string {
        if (empty($rich_text)) {
            return '';
        }
        
        $text_parts = [];
        
        foreach ($rich_text as $text_item) {
            $text = $text_item['text']['content'] ?? '';
            $annotations = $text_item['annotations'] ?? [];
            
            // 应用格式
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
            if (!empty($text_item['text']['link']['url'])) {
                $url = esc_url($text_item['text']['link']['url']);
                $text = "<a href=\"{$url}\">{$text}</a>";
            }
            
            $text_parts[] = $text;
        }
        
        return implode('', $text_parts);
    }
    
    /**
     * 转换代码块
     */
    public function convert_code_block(array $block): string {
        $language = $block['language'] ?? 'text';
        $code = '';
        
        if (!empty($block['rich_text'])) {
            foreach ($block['rich_text'] as $text_item) {
                $code .= $text_item['text']['content'] ?? '';
            }
        }
        
        return "<pre><code class=\"language-{$language}\">" . esc_html($code) . "</code></pre>";
    }
    
    /**
     * 转换表格
     */
    public function convert_table(array $rows): string {
        if (empty($rows)) {
            return '';
        }
        
        $html = '<table class="notion-table">';
        
        foreach ($rows as $row_index => $row) {
            $tag = $row_index === 0 ? 'th' : 'td';
            $html .= '<tr>';
            
            foreach ($row['cells'] as $cell) {
                $cell_content = $this->convert_rich_text($cell);
                $html .= "<{$tag}>{$cell_content}</{$tag}>";
            }
            
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        
        return $html;
    }
    
    // === 私有方法 ===
    
    private function convert_single_block(array $block): string {
        $type = $block['type'] ?? 'paragraph';
        $content = $block[$type] ?? [];
        
        return match($type) {
            'paragraph' => '<p>' . $this->convert_rich_text($content['rich_text'] ?? []) . '</p>',
            'heading_1' => '<h1>' . $this->convert_rich_text($content['rich_text'] ?? []) . '</h1>',
            'heading_2' => '<h2>' . $this->convert_rich_text($content['rich_text'] ?? []) . '</h2>',
            'heading_3' => '<h3>' . $this->convert_rich_text($content['rich_text'] ?? []) . '</h3>',
            'bulleted_list_item' => '<li>' . $this->convert_rich_text($content['rich_text'] ?? []) . '</li>',
            'numbered_list_item' => '<li>' . $this->convert_rich_text($content['rich_text'] ?? []) . '</li>',
            'code' => $this->convert_code_block($content),
            'quote' => '<blockquote>' . $this->convert_rich_text($content['rich_text'] ?? []) . '</blockquote>',
            'divider' => '<hr>',
            'image' => $this->convert_image_block($content),
            'table' => $this->convert_table($content['children'] ?? []),
            default => '<p>' . $this->convert_rich_text($content['rich_text'] ?? []) . '</p>'
        };
    }
    
    private function convert_image_block(array $content): string {
        $url = '';
        $alt = '';
        
        if (!empty($content['external']['url'])) {
            $url = $content['external']['url'];
        } elseif (!empty($content['file']['url'])) {
            $url = $content['file']['url'];
        }
        
        if (!empty($content['caption'])) {
            $alt = $this->convert_rich_text($content['caption']);
        }
        
        if (empty($url)) {
            return '';
        }
        
        return "<img src=\"{$url}\" alt=\"{$alt}\" class=\"notion-image\">";
    }
    
    private function extract_property_value(array $property): mixed {
        $type = $property['type'] ?? 'text';
        
        return match($type) {
            'title' => $this->convert_rich_text($property['title'] ?? []),
            'rich_text' => $this->convert_rich_text($property['rich_text'] ?? []),
            'number' => $property['number'] ?? null,
            'select' => $property['select']['name'] ?? null,
            'multi_select' => array_column($property['multi_select'] ?? [], 'name'),
            'date' => $property['date']['start'] ?? null,
            'checkbox' => $property['checkbox'] ?? false,
            'url' => $property['url'] ?? null,
            'email' => $property['email'] ?? null,
            'phone_number' => $property['phone_number'] ?? null,
            default => null
        };
    }
    
    private function extract_title(array $page): string {
        $properties = $page['properties'] ?? [];
        
        // 查找标题字段
        foreach ($properties as $property) {
            if (($property['type'] ?? '') === 'title') {
                return $this->convert_rich_text($property['title'] ?? []);
            }
        }
        
        return '无标题';
    }
    
    private function normalize_field_name(string $name): string {
        return strtolower(str_replace([' ', '-'], '_', $name));
    }
    
    private function download_and_save_image(string $url): ?string {
        // 检查是否为Notion图片URL
        if (!str_contains($url, 'notion.so') && !str_contains($url, 'amazonaws.com')) {
            return $url; // 外部图片直接返回
        }
        
        try {
            $response = wp_remote_get($url, [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'Notion-to-WordPress/2.0'
                ]
            ]);
            
            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }
            
            $body = wp_remote_retrieve_body($response);
            $content_type = wp_remote_retrieve_header($response, 'content-type');
            
            // 确定文件扩展名
            $extension = match(true) {
                str_contains($content_type, 'jpeg') => 'jpg',
                str_contains($content_type, 'png') => 'png',
                str_contains($content_type, 'gif') => 'gif',
                str_contains($content_type, 'webp') => 'webp',
                default => 'jpg'
            };
            
            // 生成文件名
            $filename = 'notion-image-' . md5($url) . '.' . $extension;
            
            // 保存到WordPress媒体库
            $upload = wp_upload_bits($filename, null, $body);
            
            if ($upload['error']) {
                throw new \Exception($upload['error']);
            }
            
            return $upload['url'];
            
        } catch (\Exception $e) {
            Logger::error_log("图片下载失败: {$url} - " . $e->getMessage(), 'ContentProcessing');
            return null;
        }
    }
}