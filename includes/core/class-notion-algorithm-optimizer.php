<?php
declare(strict_types=1);

/**
 * Notion 算法优化器类
 * 
 * 优化内容处理算法，提升字符串处理、HTML生成、数据转换的效率，减少CPU使用
 * 专注于算法层面的优化，提升基础操作的效率
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

class Notion_Algorithm_Optimizer {
    
    /**
     * 字符串操作缓存
     */
    private static array $string_cache = [];
    private static int $cache_max_size = 1000;
    
    /**
     * HTML模板缓存
     */
    private static array $html_template_cache = [];
    
    /**
     * 优化的字符串操作 - 减少正则表达式使用
     * 
     * 使用更高效的字符串操作算法，避免昂贵的正则表达式
     *
     * @since 2.0.0-beta.1
     * @param array $strings 待处理的字符串数组
     * @return array 优化后的字符串数组
     */
    public static function optimize_string_operations(array $strings): array {
        if (empty($strings)) {
            return [];
        }
        
        $results = [];
        
        // 预编译的替换映射，避免重复创建
        static $search_replace_map = [
            "\r\n" => "\n",
            "\r" => "\n",
            "\t" => "    "
        ];
        
        foreach ($strings as $index => $string) {
            // 跳过空字符串
            if (empty($string)) {
                $results[$index] = '';
                continue;
            }
            
            // 检查缓存（保留有效的缓存机制）
            $cache_key = hash('xxh64', $string);
            if (isset(self::$string_cache[$cache_key])) {
                $results[$index] = self::$string_cache[$cache_key];
                continue;
            }
            
            // 一次性替换多个字符（比多次str_replace快50%）
            $optimized = strtr($string, $search_replace_map);
            
            // 使用更快的算法替代正则表达式
            // 移除连续空行 - 避免正则表达式
            while (strpos($optimized, "\n\n\n") !== false) {
                $optimized = str_replace("\n\n\n", "\n\n", $optimized);
            }
            
            // 快速trim，避免复杂的正则
            $optimized = trim($optimized);
            
            // 移除行首空格 - 仅在必要时使用
            if (strpos($optimized, "\n ") !== false || strpos($optimized, "\n\t") !== false) {
                $lines = explode("\n", $optimized);
                $lines = array_map('trim', $lines);
                $lines = array_filter($lines, function($line) {
                    return $line !== '';
                });
                $optimized = implode("\n", $lines);
            }
            
            $results[$index] = $optimized;
            
            // 缓存结果（使用更快的哈希算法）
            if (count(self::$string_cache) < self::$cache_max_size) {
                self::$string_cache[$cache_key] = $optimized;
            }
        }
        
        return $results;
    }
    
    /**
     * 快速HTML生成
     * 
     * 使用数组拼接和模板缓存优化HTML生成速度
     *
     * @since 2.0.0-beta.1
     * @param array $elements HTML元素数组
     * @return string 生成的HTML字符串
     */
    public static function fast_html_generation(array $elements): string {
        if (empty($elements)) {
            return '';
        }
        
        // 使用数组拼接替代字符串拼接（更高效）
        $html_parts = [];
        
        foreach ($elements as $element) {
            $tag = $element['tag'] ?? 'div';
            $content = $element['content'] ?? '';
            $attributes = $element['attributes'] ?? [];
            $self_closing = $element['self_closing'] ?? false;
            
            // 生成模板缓存键
            $template_key = $tag . '_' . ($self_closing ? 'self' : 'normal') . '_' . count($attributes);
            
            // 检查模板缓存
            if (!isset(self::$html_template_cache[$template_key])) {
                self::$html_template_cache[$template_key] = self::generate_html_template($tag, $self_closing);
            }
            
            $template = self::$html_template_cache[$template_key];
            
            // 快速属性生成
            $attr_string = self::fast_attribute_generation($attributes);
            
            // 使用模板生成HTML
            if ($self_closing) {
                $html_parts[] = sprintf($template, $attr_string);
            } else {
                $html_parts[] = sprintf($template, $attr_string, $content);
            }
        }
        
        return implode('', $html_parts);
    }
    
    /**
     * 生成HTML模板
     *
     * @since 2.0.0-beta.1
     * @param string $tag HTML标签
     * @param bool $self_closing 是否自闭合标签
     * @return string HTML模板
     */
    private static function generate_html_template(string $tag, bool $self_closing): string {
        if ($self_closing) {
            return "<{$tag}%s />";
        } else {
            return "<{$tag}%s>%s</{$tag}>";
        }
    }
    
    /**
     * 快速属性生成
     *
     * @since 2.0.0-beta.1
     * @param array $attributes 属性数组
     * @return string 属性字符串
     */
    private static function fast_attribute_generation(array $attributes): string {
        if (empty($attributes)) {
            return '';
        }
        
        $attr_parts = [];
        foreach ($attributes as $key => $value) {
            // 使用更快的属性转义
            $escaped_value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $attr_parts[] = "{$key}=\"{$escaped_value}\"";
        }
        
        return ' ' . implode(' ', $attr_parts);
    }
    
    /**
     * 高效数据转换
     * 
     * 优化数据映射和转换算法，减少循环嵌套
     *
     * @since 2.0.0-beta.1
     * @param array $data 源数据数组
     * @param array $mapping 映射规则 [source_key => target_key]
     * @return array 转换后的数据
     */
    public static function efficient_data_transformation(array $data, array $mapping): array {
        if (empty($data) || empty($mapping)) {
            return [];
        }
        
        $result = [];
        $mapping_keys = array_keys($mapping);
        
        foreach ($data as $index => $item) {
            $transformed = [];
            
            // 使用array_intersect_key优化键查找
            $available_data = array_intersect_key($item, array_flip($mapping_keys));
            
            foreach ($available_data as $source_key => $value) {
                $target_key = $mapping[$source_key];
                $transformed[$target_key] = $value;
            }
            
            $result[$index] = $transformed;
        }
        
        return $result;
    }
    
    /**
     * 批量字符串清理
     * 
     * 优化的批量字符串清理算法
     *
     * @since 2.0.0-beta.1
     * @param array $strings 字符串数组
     * @param array $options 清理选项
     * @return array 清理后的字符串数组
     */
    public static function batch_string_cleanup(array $strings, array $options = []): array {
        if (empty($strings)) {
            return [];
        }
        
        $default_options = [
            'trim' => true,
            'remove_extra_spaces' => true,
            'normalize_newlines' => true,
            'remove_empty_lines' => true
        ];
        
        $options = array_merge($default_options, $options);
        $results = [];
        
        // 预编译正则表达式
        static $patterns = null;
        if ($patterns === null) {
            $patterns = [
                'extra_spaces' => '/\s{2,}/',
                'extra_newlines' => '/\n{3,}/',
                'empty_lines' => '/^\s*\n/m'
            ];
        }
        
        foreach ($strings as $index => $string) {
            $cleaned = $string;
            
            if ($options['normalize_newlines']) {
                $cleaned = strtr($cleaned, ["\r\n" => "\n", "\r" => "\n"]);
            }
            
            if ($options['remove_extra_spaces']) {
                $cleaned = preg_replace($patterns['extra_spaces'], ' ', $cleaned);
            }
            
            if ($options['remove_empty_lines']) {
                $cleaned = preg_replace($patterns['empty_lines'], '', $cleaned);
                $cleaned = preg_replace($patterns['extra_newlines'], "\n\n", $cleaned);
            }
            
            if ($options['trim']) {
                $cleaned = trim($cleaned);
            }
            
            $results[$index] = $cleaned;
        }
        
        return $results;
    }
    
    /**
     * 快速数组合并
     * 
     * 优化的数组合并算法，比array_merge更快
     *
     * @since 2.0.0-beta.1
     * @param array ...$arrays 要合并的数组
     * @return array 合并后的数组
     */
    public static function fast_array_merge(array ...$arrays): array {
        if (empty($arrays)) {
            return [];
        }
        
        if (count($arrays) === 1) {
            return $arrays[0];
        }
        
        $result = [];
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (is_int($key)) {
                    $result[] = $value;
                } else {
                    $result[$key] = $value;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * 优化的JSON编码
     * 
     * 使用更高效的JSON编码选项
     *
     * @since 2.0.0-beta.1
     * @param mixed $data 要编码的数据
     * @param int $flags JSON编码标志
     * @return string JSON字符串
     */
    public static function optimized_json_encode($data, int $flags = 0): string {
        // 使用优化的JSON编码标志
        $optimized_flags = $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        
        return json_encode($data, $optimized_flags);
    }
    
    /**
     * 清理缓存
     * 
     * 清理内部缓存以释放内存
     *
     * @since 2.0.0-beta.1
     */
    public static function clear_cache(): void {
        self::$string_cache = [];
        self::$html_template_cache = [];
        
        if (class_exists('Notion_Logger') && !defined('NOTION_PERFORMANCE_MODE')) {
            Notion_Logger::debug_log(
                '算法优化器缓存已清理',
                'Algorithm Optimizer'
            );
        }
    }
    
    /**
     * 获取缓存统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 缓存统计
     */
    public static function get_cache_stats(): array {
        return [
            'string_cache_size' => count(self::$string_cache),
            'string_cache_max' => self::$cache_max_size,
            'html_template_cache_size' => count(self::$html_template_cache),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
}
