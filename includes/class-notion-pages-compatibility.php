<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Notion Pages 向后兼容性类
 *
 * 提供与原始 Notion_Pages 类的向后兼容性
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */

/**
 * 向后兼容的 Notion_Pages 类
 * 
 * 这个类作为原始 Notion_Pages 类的替代，
 * 内部使用重构后的组件来实现功能
 */
class Notion_Pages {

    /**
     * 重构后的页面处理器实例
     *
     * @since 1.1.0
     * @var Notion_Pages_Refactored
     */
    private Notion_Pages_Refactored $refactored_pages;

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
        $this->refactored_pages = new Notion_Pages_Refactored($notion_api, $database_id, $field_mapping, $lock_timeout);
    }

    /**
     * 设置自定义字段映射
     *
     * @since 1.1.0
     * @param array $mappings 自定义字段映射数组
     */
    public function set_custom_field_mappings(array $mappings): void {
        $this->refactored_pages->set_custom_field_mappings($mappings);
    }

    /**
     * 导入单个Notion页面
     *
     * @since 1.1.0
     * @param array $page Notion页面数据
     * @return bool 导入是否成功
     */
    public function import_notion_page(array $page): bool {
        return $this->refactored_pages->import_notion_page($page);
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
        return $this->refactored_pages->import_pages($force_update, $filter_page_id);
    }

    /**
     * 根据Notion页面ID获取WordPress文章ID
     *
     * @since 1.1.0
     * @param string $notion_id Notion页面ID
     * @return int WordPress文章ID
     */
    public function get_post_by_notion_id(string $notion_id): int {
        return $this->refactored_pages->get_post_by_notion_id($notion_id);
    }

    /**
     * 处理单个页面
     *
     * @since 1.1.0
     * @param array $page        Notion页面数据
     * @param bool  $force_update 是否强制更新
     * @return array 处理结果
     */
    public function process_single_page(array $page, bool $force_update = false): array {
        return $this->refactored_pages->process_single_page($page, $force_update);
    }

    /**
     * 转换单个块
     *
     * @since 1.1.0
     * @param array      $block 块数据
     * @param Notion_API $api   API实例
     * @return string HTML内容
     */
    public function convert_single_block(array $block, Notion_API $api): string {
        return $this->refactored_pages->convert_single_block($block, $api);
    }

    /**
     * 提取页面标题
     *
     * @since 1.1.0
     * @param array $page Notion页面数据
     * @return string 页面标题
     */
    public function extract_page_title(array $page): string {
        return $this->refactored_pages->extract_page_title($page);
    }

    /**
     * 清理静态缓存
     *
     * @since 1.1.0
     */
    public static function clear_static_cache(): void {
        // 清理重构版本的静态缓存
        if (class_exists('Notion_Pages_Refactored') && method_exists('Notion_Pages_Refactored', 'clear_static_cache')) {
            Notion_Pages_Refactored::clear_static_cache();
        }

        // 清理API缓存
        if (class_exists('Notion_API') && method_exists('Notion_API', 'clear_cache')) {
            Notion_API::clear_cache();
        }

        // 记录缓存清理
        if (class_exists('Notion_To_WordPress_Helper')) {
            Notion_To_WordPress_Helper::debug_log(
                '已清理Notion_Pages静态缓存',
                'Cache Manager',
                Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO
            );
        }
    }

    /**
     * 获取数据库ID
     *
     * @since 1.1.0
     * @return string 数据库ID
     */
    public function get_database_id(): string {
        return $this->refactored_pages->get_database_id();
    }

    /**
     * 获取字段映射
     *
     * @since 1.1.0
     * @return array 字段映射
     */
    public function get_field_mapping(): array {
        return $this->refactored_pages->get_field_mapping();
    }

    /**
     * 获取自定义字段映射
     *
     * @since 1.1.0
     * @return array 自定义字段映射
     */
    public function get_custom_field_mappings(): array {
        return $this->refactored_pages->get_custom_field_mappings();
    }

    /**
     * 魔术方法：处理未定义的方法调用
     * 
     * 这个方法确保对原始类中存在但在重构版本中可能缺失的方法的调用
     * 不会导致致命错误
     *
     * @since 1.1.0
     * @param string $method 方法名
     * @param array  $args   参数
     * @return mixed
     */
    public function __call(string $method, array $args) {
        // 记录未实现的方法调用
        Notion_To_WordPress_Error_Handler::log_warning(
            "调用了未实现的方法: {$method}",
            Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
            ['method' => $method, 'args_count' => count($args)]
        );

        // 尝试在重构版本中查找方法
        if (method_exists($this->refactored_pages, $method)) {
            return call_user_func_array([$this->refactored_pages, $method], $args);
        }

        // 返回默认值以避免错误
        switch ($method) {
            case 'extract_page_metadata':
                return [];
            case 'convert_blocks_to_html':
                return '';
            case 'get_author_id':
                return 1;
            default:
                return null;
        }
    }

    /**
     * 魔术方法：处理未定义的静态方法调用
     *
     * @since 1.1.0
     * @param string $method 方法名
     * @param array  $args   参数
     * @return mixed
     */
    public static function __callStatic(string $method, array $args) {
        // 记录未实现的静态方法调用
        Notion_To_WordPress_Error_Handler::log_warning(
            "调用了未实现的静态方法: {$method}",
            Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
            ['method' => $method, 'args_count' => count($args)]
        );

        // 尝试在重构版本中查找静态方法
        if (method_exists('Notion_Pages_Refactored', $method)) {
            return call_user_func_array(['Notion_Pages_Refactored', $method], $args);
        }

        // 返回默认值
        return null;
    }
}
