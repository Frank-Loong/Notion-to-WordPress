<?php
declare(strict_types=1);

/**
 * Notion 配置简化器类
 *
 * 将复杂的技术配置简化为用户友好的选项，同时保持向后兼容性。
 * 提供性能级别预设、字段映射模板和智能配置推荐功能。
 *
 * 核心功能：
 * - 性能级别预设（保守/平衡/激进）
 * - 字段映射模板（英文/中文/自定义）
 * - 配置迁移和兼容性处理
 * - 智能环境检测和配置推荐
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

class Notion_Config_Simplifier {

    /**
     * 性能级别预设配置
     * @var array
     */
    const PERFORMANCE_PRESETS = [
        'conservative' => [
            'api_page_size' => 50,
            'concurrent_requests' => 3,
            'batch_size' => 10,
            'log_buffer_size' => 20,
            'enable_performance_mode' => 1,
            'description' => '保守模式 - 适合配置较低的服务器'
        ],
        'balanced' => [
            'api_page_size' => 100,
            'concurrent_requests' => 5,
            'batch_size' => 20,
            'log_buffer_size' => 50,
            'enable_performance_mode' => 1,
            'description' => '平衡模式 - 推荐的默认配置'
        ],
        'aggressive' => [
            'api_page_size' => 200,
            'concurrent_requests' => 10,
            'batch_size' => 50,
            'log_buffer_size' => 100,
            'enable_performance_mode' => 1,
            'description' => '激进模式 - 适合高性能服务器'
        ]
    ];

    /**
     * 字段映射模板预设
     * @var array
     */
    const FIELD_MAPPING_TEMPLATES = [
        'english' => [
            'title' => 'Title',
            'status' => 'Status',
            'post_type' => 'Type',
            'date' => 'Date',
            'excerpt' => 'Summary,Excerpt',
            'featured_image' => 'Featured Image',
            'categories' => 'Categories,Category',
            'tags' => 'Tags,Tag',
            'password' => 'Password',
            'description' => '英文模板 - 适合英文Notion数据库'
        ],
        'chinese' => [
            'title' => '标题,Title',
            'status' => '状态,Status',
            'post_type' => '类型,Type',
            'date' => '日期,Date',
            'excerpt' => '摘要,Summary,Excerpt',
            'featured_image' => '特色图片,Featured Image',
            'categories' => '分类,Categories,Category',
            'tags' => '标签,Tags,Tag',
            'password' => '密码,Password',
            'description' => '中文模板 - 适合中文Notion数据库'
        ],
        'mixed' => [
            'title' => 'Title,标题',
            'status' => 'Status,状态',
            'post_type' => 'Type,类型',
            'date' => 'Date,日期',
            'excerpt' => 'Summary,摘要,Excerpt',
            'featured_image' => 'Featured Image,特色图片',
            'categories' => 'Categories,分类,Category',
            'tags' => 'Tags,标签,Tag',
            'password' => 'Password,密码',
            'description' => '混合模板 - 中英文兼容'
        ]
    ];

    /**
     * 获取性能配置
     *
     * @param string $level 性能级别
     * @return array 性能配置数组
     */
    public static function get_performance_config(string $level): array {
        return self::PERFORMANCE_PRESETS[$level] ?? self::PERFORMANCE_PRESETS['balanced'];
    }

    /**
     * 获取字段映射模板
     *
     * @param string $template 模板名称
     * @return array 字段映射数组
     */
    public static function get_field_mapping_template(string $template): array {
        $template_data = self::FIELD_MAPPING_TEMPLATES[$template] ?? self::FIELD_MAPPING_TEMPLATES['mixed'];
        
        // 移除description字段，只返回映射数据
        unset($template_data['description']);
        return $template_data;
    }

    /**
     * 获取所有可用的性能级别
     *
     * @return array 性能级别列表
     */
    public static function get_available_performance_levels(): array {
        $levels = [];
        foreach (self::PERFORMANCE_PRESETS as $key => $config) {
            $levels[$key] = $config['description'];
        }
        return $levels;
    }

    /**
     * 获取所有可用的字段映射模板
     *
     * @return array 模板列表
     */
    public static function get_available_field_templates(): array {
        $templates = [];
        foreach (self::FIELD_MAPPING_TEMPLATES as $key => $config) {
            $templates[$key] = $config['description'];
        }
        return $templates;
    }

    /**
     * 从现有配置推断性能级别
     *
     * @param array $options 现有配置选项
     * @return string 推断的性能级别
     */
    public static function detect_performance_level(array $options): string {
        $api_page_size = $options['api_page_size'] ?? 100;
        $concurrent_requests = $options['concurrent_requests'] ?? 5;
        $batch_size = $options['batch_size'] ?? 20;

        // 计算配置的"激进程度"分数
        $score = 0;
        
        // API分页大小评分
        if ($api_page_size >= 150) $score += 2;
        elseif ($api_page_size >= 100) $score += 1;
        
        // 并发请求数评分
        if ($concurrent_requests >= 8) $score += 2;
        elseif ($concurrent_requests >= 5) $score += 1;
        
        // 批量大小评分
        if ($batch_size >= 40) $score += 2;
        elseif ($batch_size >= 20) $score += 1;

        // 根据分数确定级别
        if ($score >= 5) return 'aggressive';
        elseif ($score >= 2) return 'balanced';
        else return 'conservative';
    }

    /**
     * 从现有配置推断字段映射模板
     *
     * @param array $field_mapping 现有字段映射
     * @return string 推断的模板类型
     */
    public static function detect_field_template(array $field_mapping): string {
        $chinese_count = 0;
        $english_count = 0;
        $total_fields = 0;

        foreach ($field_mapping as $field => $mapping) {
            if (empty($mapping)) continue;
            
            $total_fields++;
            
            // 检查是否包含中文字符
            if (preg_match('/[\x{4e00}-\x{9fff}]/u', $mapping)) {
                $chinese_count++;
            }
            
            // 检查是否包含英文单词
            if (preg_match('/[a-zA-Z]/', $mapping)) {
                $english_count++;
            }
        }

        if ($total_fields === 0) return 'mixed';

        // 如果大部分字段都包含中文
        if ($chinese_count / $total_fields > 0.6) {
            return $english_count > 0 ? 'mixed' : 'chinese';
        }
        
        // 如果主要是英文
        if ($english_count / $total_fields > 0.8 && $chinese_count === 0) {
            return 'english';
        }

        return 'mixed';
    }

    /**
     * 智能检测最优配置
     *
     * @return array 推荐的配置
     */
    public static function detect_optimal_config(): array {
        $recommendations = [];

        // 检测服务器内存
        $memory_limit = ini_get('memory_limit');
        $memory_mb = self::parse_memory_limit($memory_limit);

        if ($memory_mb >= 512) {
            $recommendations['performance_level'] = 'aggressive';
            $recommendations['reason'][] = '服务器内存充足，推荐激进模式';
        } elseif ($memory_mb >= 256) {
            $recommendations['performance_level'] = 'balanced';
            $recommendations['reason'][] = '服务器内存适中，推荐平衡模式';
        } else {
            $recommendations['performance_level'] = 'conservative';
            $recommendations['reason'][] = '服务器内存有限，推荐保守模式';
        }

        // 检测WordPress语言
        $locale = get_locale();
        if (strpos($locale, 'zh') === 0) {
            $recommendations['field_template'] = 'chinese';
            $recommendations['reason'][] = '检测到中文环境，推荐中文字段模板';
        } else {
            $recommendations['field_template'] = 'english';
            $recommendations['reason'][] = '检测到英文环境，推荐英文字段模板';
        }

        return $recommendations;
    }

    /**
     * 解析内存限制字符串
     *
     * @param string $memory_limit 内存限制字符串
     * @return int 内存大小（MB）
     */
    private static function parse_memory_limit(string $memory_limit): int {
        $memory_limit = trim($memory_limit);
        $last_char = strtolower($memory_limit[strlen($memory_limit) - 1]);
        $number = (int) $memory_limit;

        switch ($last_char) {
            case 'g':
                return $number * 1024;
            case 'm':
                return $number;
            case 'k':
                return $number / 1024;
            default:
                return $number / (1024 * 1024);
        }
    }

    /**
     * 迁移现有配置到简化格式
     *
     * @param array $options 现有配置选项
     * @return array 迁移后的配置
     */
    public static function migrate_legacy_config(array $options): array {
        $migrated = $options;

        // 检测并设置性能级别
        if (!isset($options['performance_level'])) {
            $migrated['performance_level'] = self::detect_performance_level($options);
        }

        // 检测并设置字段映射模板
        if (!isset($options['field_template']) && isset($options['field_mapping'])) {
            $migrated['field_template'] = self::detect_field_template($options['field_mapping']);
        }

        // 标记已迁移
        $migrated['config_migrated'] = true;

        return $migrated;
    }

    /**
     * 应用简化配置到详细配置
     *
     * @param array $options 当前配置选项
     * @return array 应用后的配置
     */
    public static function apply_simplified_config(array $options): array {
        $updated = $options;

        // 应用性能级别配置
        if (isset($options['performance_level'])) {
            $performance_config = self::get_performance_config($options['performance_level']);
            foreach ($performance_config as $key => $value) {
                if ($key !== 'description') {
                    $updated[$key] = $value;
                }
            }
        }

        // 应用字段映射模板
        if (isset($options['field_template']) && $options['field_template'] !== 'custom') {
            $template_mapping = self::get_field_mapping_template($options['field_template']);
            $updated['field_mapping'] = array_merge($updated['field_mapping'] ?? [], $template_mapping);
        }

        return $updated;
    }
}
