<?php
declare(strict_types=1);

namespace NTWP\Utils;

/**
 * 输入验证规则配置类
 *
 * 定义插件中所有输入验证的规则常量和配置，
 * 提供统一的验证标准和规则管理。
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

class Validator {
    
    /**
     * Notion API Key 验证规则
     */
    const API_KEY_MIN_LENGTH = 30;
    const API_KEY_MAX_LENGTH = 80;
    const API_KEY_PATTERN = '/^[a-zA-Z0-9_-]+$/';
    
    /**
     * Notion Database ID 验证规则
     */
    const DATABASE_ID_LENGTH = 32;
    const DATABASE_ID_PATTERN = '/^[a-f0-9]{32}$/i';
    
    /**
     * Notion Page ID 验证规则
     */
    const PAGE_ID_LENGTH = 32;
    const PAGE_ID_PATTERN = '/^[a-f0-9]{32}$/i';
    
    /**
     * 调试级别验证规则
     */
    const DEBUG_LEVELS = [0, 1, 2, 3, 4];
    
    /**
     * 同步计划验证规则
     */
    const SYNC_SCHEDULES = [
        'manual', 
        'hourly', 
        'twicedaily', 
        'daily', 
        'weekly', 
        'biweekly', 
        'monthly'
    ];
    
    /**
     * 支持的图片类型
     */
    const ALLOWED_IMAGE_TYPES = [
        'image/jpeg',
        'image/png', 
        'image/gif',
        'image/webp',
        'image/svg+xml'
    ];
    
    /**
     * URL验证规则
     */
    const ALLOWED_URL_SCHEMES = ['http', 'https'];
    const BLOCKED_HOSTS = ['localhost', '127.0.0.1', '::1'];
    
    /**
     * 文件类型验证规则
     */
    const SAFE_FILE_EXTENSIONS = [
        // 图片
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico',
        // 文档
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf',
        // 音频
        'mp3', 'wav', 'ogg', 'flac', 'm4a',
        // 视频
        'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm',
        // 压缩文件
        'zip', 'rar', '7z', 'tar', 'gz',
        // 其他
        'csv', 'json', 'xml'
    ];
    
    /**
     * 获取所有验证规则配置
     *
     * @since 2.0.0-beta.1
     * @return array 验证规则配置数组
     */
    public static function get_all_rules(): array {
        return [
            'api_key' => [
                'min_length' => self::API_KEY_MIN_LENGTH,
                'max_length' => self::API_KEY_MAX_LENGTH,
                'pattern' => self::API_KEY_PATTERN,
            ],
            'database_id' => [
                'length' => self::DATABASE_ID_LENGTH,
                'pattern' => self::DATABASE_ID_PATTERN,
            ],
            'page_id' => [
                'length' => self::PAGE_ID_LENGTH,
                'pattern' => self::PAGE_ID_PATTERN,
            ],
            'debug_levels' => self::DEBUG_LEVELS,
            'sync_schedules' => self::SYNC_SCHEDULES,
            'image_types' => self::ALLOWED_IMAGE_TYPES,
            'url_schemes' => self::ALLOWED_URL_SCHEMES,
            'blocked_hosts' => self::BLOCKED_HOSTS,
            'file_extensions' => self::SAFE_FILE_EXTENSIONS,
        ];
    }
    
    /**
     * 获取特定类型的验证规则
     *
     * @since 2.0.0-beta.1
     * @param string $type 规则类型
     * @return array|null 验证规则或null
     */
    public static function get_rules(string $type): ?array {
        $all_rules = self::get_all_rules();
        return $all_rules[$type] ?? null;
    }
}
