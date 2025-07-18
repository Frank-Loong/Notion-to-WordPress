<?php
declare(strict_types=1);

/**
 * Notion 页面导入主协调器类
 *
 * 重构后的主协调器类，专注于服务协调和流程管理。通过依赖注入模式整合所有专门的功能类，
 * 包括图片处理器(Notion_Image_Processor)、元数据提取器(Notion_Metadata_Extractor)、
 * 内容转换器(Notion_Content_Converter)、同步管理器(Notion_Sync_Manager)和WordPress集成器
 * (Notion_To_WordPress_Integrator)等。
 *
 * 本类采用委托模式，将具体的业务逻辑委托给相应的专门类处理，自身专注于：
 * - 协调各功能模块的交互
 * - 管理导入流程的执行顺序
 * - 提供统一的公共接口
 * - 保持向后兼容性
 *
 * 这种架构设计确保了代码的模块化、可维护性和可扩展性，同时保证所有现有调用代码无需修改。
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

    // ==================== 已移除的向后兼容属性 ====================
    // 已移除：$processed_blocks, $last_processing_stats, $db_query_cache, $batch_query_cache
    // 这些功能已完全委托给专门的功能类

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
     * 导入单个Notion页面（主协调器方法）
     *
     * @since    1.0.5
     * @param    array     $page         Notion页面数据
     * @return   boolean                 导入是否成功
     */
    public function import_notion_page(array $page): bool {
        Notion_To_WordPress_Helper::debug_log('import_notion_page() 开始执行（主协调器）', 'Page Import');

        // 1. 验证输入数据
        if (empty($page) || !isset($page['id'])) {
            Notion_To_WordPress_Helper::error_log('页面数据为空或缺少ID', 'Page Import');
            return false;
        }

        $page_id = $page['id'];

        try {
            // 2. 协调元数据提取
            $metadata = $this->coordinate_metadata_extraction($page);
            if (empty($metadata['title'])) {
                Notion_To_WordPress_Helper::debug_log('页面标题为空，跳过导入', 'Page Import');
                return false;
            }

            // 3. 协调内容获取和转换
            $content = $this->coordinate_content_processing($page_id);
            if ($content === false) {
                return false;
            }

            // 4. 协调WordPress集成
            $post_id = $this->coordinate_wordpress_integration($metadata, $content, $page_id);
            if (is_wp_error($post_id)) {
                return false;
            }

            // 5. 协调同步状态更新
            $this->coordinate_sync_status_update($page_id, $page['last_edited_time'] ?? '');

            Notion_To_WordPress_Helper::debug_log('页面导入完成', 'Page Import');
            return true;

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log('页面导入异常: ' . $e->getMessage(), 'Page Import');
            return false;
        }
    }

    /**
     * 从Notion页面中提取元数据
     *
     * @since    1.0.5
     * @deprecated 2.0.0-beta.1 使用 Notion_Metadata_Extractor::extract_page_metadata() 代替
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
    private function get_property_value(array $props, array $names, string $type, ?string $key = null, $default = null) {
        // 委托给元数据提取器
        return Notion_Metadata_Extractor::get_property_value($props, $names, $type, $key, $default);
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
    private function extract_property_value($prop, string $type, ?string $key = null, $default = null) {
        // 委托给元数据提取器
        return Notion_Metadata_Extractor::extract_property_value($prop, $type, $key, $default);
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
    /**
     * 递归获取并转换子块
     *
     * @since    1.0.5
     * @deprecated 2.0.0-beta.1 使用 Notion_Content_Converter::_convert_child_blocks() 代替
     * @param    array       $block        父块数据
     * @param    Notion_API  $notion_api   API实例
     * @return   string                    子块HTML内容
     */
    private function _convert_child_blocks(array $block, Notion_API $notion_api): string {
        // 委托给内容转换器
        return Notion_Content_Converter::_convert_child_blocks($block, $notion_api);
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

    // ==================== 内容转换方法已移除 ====================
    // 所有_convert_block_*方法已移至Notion_Content_Converter类
    // 以下方法已被移除：_convert_block_paragraph, _convert_block_heading_1/2/3,
    // _convert_block_bulleted_list_item, _convert_block_numbered_list_item,
    // _convert_block_to_do, _convert_block_toggle

    // 已移除：_convert_block_child_page, _convert_block_child_database

    // 已移除：_convert_block_image, is_notion_temp_url, _convert_block_code, _convert_block_quote, _convert_block_divider

    // 已移除：_convert_block_table

    // 已移除：_convert_block_table_row

    // 已移除：_convert_block_callout, _convert_block_bookmark, _convert_block_equation

    // 已移除：_convert_block_embed

    // 已移除：_convert_block_video

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
    /**
     * 提取富文本内容
     *
     * @since    1.0.5
     * @deprecated 2.0.0-beta.1 使用 Notion_Content_Converter::extract_rich_text() 代替
     * @param    array     $rich_text    富文本数据
     * @return   string                  格式化的HTML文本
     */
    private function extract_rich_text($rich_text) {
        // 委托给内容转换器
        return Notion_Content_Converter::extract_rich_text($rich_text);
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
    /**
     * 批量获取多个Notion页面ID对应的WordPress文章ID
     *
     * @since    1.1.2
     * @deprecated 2.0.0-beta.1 使用 Notion_To_WordPress_Integrator::batch_get_posts_by_notion_ids() 代替
     * @param    array    $notion_ids    Notion页面ID数组
     * @return   array                   [notion_id => post_id] 映射
     */
    private function batch_get_posts_by_notion_ids(array $notion_ids): array {
        // 委托给WordPress集成器
        return Notion_To_WordPress_Integrator::batch_get_posts_by_notion_ids($notion_ids);
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
    /**
     * 设置特色图片
     *
     * @since    1.0.5
     * @deprecated 2.0.0-beta.1 使用 Notion_Image_Processor::set_featured_image() 代替
     * @param    int       $post_id     文章ID
     * @param    string    $image_url   图片URL
     */
    private function set_featured_image($post_id, $image_url) {
        // 委托给图片处理器
        Notion_Image_Processor::set_featured_image($post_id, $image_url);
    }

    /**
     * 下载并插入图片到媒体库（支持异步模式）
     *
     * @since    1.0.5
     * @deprecated 2.0.0-beta.1 使用 Notion_Image_Processor::collect_image_for_download() 代替
     * @param    string    $url       图片URL
     * @param    string    $caption   图片标题
     * @param    bool      $async     是否使用异步模式
     * @return   int|string           WordPress附件ID或占位符
     */
    private function download_and_insert_image( string $url, string $caption = '', bool $async = false ) {
        // 委托给图片处理器
        if ( $async || Notion_Image_Processor::is_async_image_mode_enabled() ) {
            return Notion_Image_Processor::collect_image_for_download( $url, $caption );
        }

        // 同步模式暂时保持简单处理
        return Notion_Image_Processor::collect_image_for_download( $url, $caption );
    }

    /**
     * 根据URL获取附件ID
     *
     * @since    1.0.5
     * @deprecated 2.0.0-beta.1 使用 Notion_Image_Processor::get_attachment_by_url() 代替
     * @param    string    $search_url    图片URL
     * @return   int               WordPress附件ID
     */
    private function get_attachment_by_url( string $search_url ) {
        // 委托给图片处理器
        return Notion_Image_Processor::get_attachment_by_url( $search_url );
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

            // 缓存已禁用，直接使用实时数据库查询
            Notion_To_WordPress_Helper::info_log('使用实时数据库查询，确保数据一致性', 'Data Management');

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

                // 缓存已禁用，记录无页面更新状态
                Notion_To_WordPress_Helper::debug_log(
                    '增量同步完成，无页面需要更新（缓存已禁用）',
                    'Incremental Sync'
                );

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

            // 缓存已禁用，记录性能统计
            Notion_To_WordPress_Helper::debug_log(
                '性能统计: ' . print_r($performance_stats, true),
                'Performance Stats'
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

            // 缓存已禁用，无需清理操作
            Notion_To_WordPress_Helper::debug_log(
                '同步完成，缓存已禁用无需清理',
                'Performance Stats'
            );

            return $stats;

        } catch (Exception $e) {
            Notion_To_WordPress_Helper::error_log('import_pages() 异常: ' . $e->getMessage(), 'Pages Import');
            Notion_To_WordPress_Helper::error_log('异常堆栈: ' . $e->getTraceAsString(), 'Pages Import');

            // 缓存已禁用，记录异常状态
            Notion_To_WordPress_Helper::debug_log(
                '导入异常，缓存已禁用无需清理',
                'Exception Handling'
            );

            return new WP_Error('import_failed', __('导入失败: ', 'notion-to-wordpress') . $e->getMessage());
        }
    }

    // --- Column Blocks ---

    // 已移除：_convert_block_column_list, _convert_block_column, _convert_block_file

    // 已移除：_convert_block_pdf

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

    // 已移除：_convert_block_synced_block, _convert_block_link_to_page



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
    /**
     * 过滤需要增量同步的页面
     *
     * @since    1.1.0
     * @deprecated 2.0.0-beta.1 使用 Notion_Sync_Manager::filter_pages_for_incremental_sync() 代替
     * @param    array     $pages    页面数组
     * @return   array               需要同步的页面数组
     */
    private function filter_pages_for_incremental_sync(array $pages): array {
        // 委托给同步管理器
        return Notion_Sync_Manager::filter_pages_for_incremental_sync($pages);
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
    /**
     * 更新页面同步时间
     *
     * @since    1.1.0
     * @deprecated 2.0.0-beta.1 使用 Notion_Sync_Manager::update_page_sync_time() 代替
     * @param    string    $page_id              Notion页面ID
     * @param    string    $notion_last_edited   Notion最后编辑时间
     */
    private function update_page_sync_time(string $page_id, string $notion_last_edited): void {
        // 委托给同步管理器
        Notion_Sync_Manager::update_page_sync_time($page_id, $notion_last_edited);
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
    /**
     * 判断文件是否为图片文件
     *
     * @since    1.1.1
     * @deprecated 2.0.0-beta.1 使用 Notion_Image_Processor::is_image_file() 代替
     * @param    string    $filename    文件名
     * @return   bool                   是否为图片文件
     */
    private function is_image_file(string $filename): bool {
        // 委托给图片处理器
        return Notion_Image_Processor::is_image_file($filename);
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
        // 缓存已禁用，返回基本性能统计
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
            'cache_status' => 'disabled'
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



    // 已移除：_convert_block_child_database_with_data



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

    // ==================== 向后兼容的委托方法 ====================

    /**
     * 收集图片信息用于批量下载
     *
     * @since    1.9.0-beta.1
     * @deprecated 2.0.0-beta.1 使用 Notion_Image_Processor::collect_image_for_download() 代替
     * @param    string    $url       图片URL
     * @param    string    $caption   图片标题
     * @return   string               占位符标识
     */
    private function collect_image_for_download( string $url, string $caption = '' ): string {
        // 委托给图片处理器
        return Notion_Image_Processor::collect_image_for_download( $url, $caption );
    }

    /**
     * 批量下载所有待处理的图片
     *
     * @since    1.9.0-beta.1
     * @deprecated 2.0.0-beta.1 使用 Notion_Image_Processor::batch_download_images() 代替
     * @return   array                下载结果映射
     */
    private function batch_download_images(): array {
        // 委托给图片处理器
        Notion_Image_Processor::batch_download_images();
        return [];
    }

    /**
     * 并发下载图片
     *
     * @since    1.9.0-beta.1
     * @deprecated 2.0.0-beta.1 功能已迁移到 Notion_Image_Processor 类
     * @param    array     $requests      请求数组
     * @return   array                    响应数组
     */
    private function concurrent_download_images( array $requests ): array {
        // 委托给图片处理器（此方法已废弃）
        return [];
    }

    /**
     * 执行并发HTTP请求
     *
     * @since    1.9.0-beta.1
     * @deprecated 2.0.0-beta.1 功能已迁移到 Notion_Image_Processor 类
     * @param    array     $multi_requests    多个请求
     * @return   array                        响应数组
     */
    private function execute_concurrent_requests( array $multi_requests ): array {
        // 委托给图片处理器（此方法已废弃）
        return [];
    }

    /**
     * 处理下载的图片响应并插入到媒体库
     *
     * @since    1.9.0-beta.1
     * @deprecated 2.0.0-beta.1 功能已迁移到 Notion_Image_Processor 类
     * @param    array     $image_info    图片信息
     * @param    array     $response      HTTP响应数据
     * @return   int|null                 附件ID或null
     */
    private function process_downloaded_image_response( array $image_info, array $response ): ?int {
        // 委托给图片处理器（此方法已废弃）
        return null;
    }

    /**
     * 替换HTML内容中的图片占位符
     *
     * @since    1.9.0-beta.1
     * @deprecated 2.0.0-beta.1 使用 Notion_Image_Processor::replace_image_placeholders() 代替
     * @param    string    $html    包含占位符的HTML内容
     * @return   string             替换后的HTML内容
     */
    private function replace_image_placeholders( string $html ): string {
        // 委托给图片处理器
        return Notion_Image_Processor::replace_image_placeholders( $html );
    }

    /**
     * 清理图片队列和占位符
     *
     * @since    1.9.0-beta.1
     * @deprecated 2.0.0-beta.1 使用 Notion_Image_Processor::clear_image_queue() 代替
     */
    private function clear_image_queue(): void {
        // 委托给图片处理器
        Notion_Image_Processor::clear_image_queue();
    }

    /**
     * 获取图片队列统计信息
     *
     * @since    1.9.0-beta.1
     * @param    bool     $use_last_stats    是否使用最后一次处理的统计
     * @return   array                       统计信息
     */
    public function get_image_queue_stats( bool $use_last_stats = false ): array {
        // 委托给图片处理器
        return Notion_Image_Processor::get_queue_status();
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

    // ==================== 核心协调方法 ====================

    /**
     * 协调元数据提取
     *
     * @since    2.0.0-beta.1
     * @param    array     $page    Notion页面数据
     * @return   array              页面元数据
     */
    private function coordinate_metadata_extraction(array $page): array {
        Notion_To_WordPress_Helper::debug_log('协调元数据提取开始', 'Page Import');

        $metadata = Notion_Metadata_Extractor::extract_page_metadata(
            $page,
            $this->field_mapping ?? [],
            $this->custom_field_mappings ?? []
        );

        Notion_To_WordPress_Helper::debug_log(
            '元数据提取完成，标题: ' . ($metadata['title'] ?? 'unknown'),
            'Page Import'
        );

        return $metadata;
    }

    /**
     * 协调内容获取和转换
     *
     * @since    2.0.0-beta.1
     * @param    string    $page_id    页面ID
     * @return   string|false          转换后的HTML内容或false
     */
    private function coordinate_content_processing(string $page_id) {
        Notion_To_WordPress_Helper::debug_log('协调内容处理开始', 'Page Import');

        // 获取页面内容
        $blocks = $this->notion_api->get_page_content($page_id);
        Notion_To_WordPress_Helper::debug_log('获取到内容块数量: ' . count($blocks), 'Page Import');

        if (empty($blocks)) {
            return false;
        }

        // 检查是否启用并发优化
        $concurrent_enabled = $this->is_concurrent_optimization_enabled();

        if ($concurrent_enabled) {
            return $this->process_content_with_concurrent_optimization($blocks);
        } else {
            return $this->process_content_traditional_mode($blocks);
        }
    }

    /**
     * 使用并发优化处理内容
     *
     * @since    2.0.0-beta.1
     * @param    array     $blocks    内容块数组
     * @return   string               处理后的HTML内容
     */
    private function process_content_with_concurrent_optimization(array $blocks): string {
        Notion_To_WordPress_Helper::debug_log('使用并发优化模式处理内容', 'Page Import');

        try {
            // 启用异步图片下载模式
            Notion_Image_Processor::enable_async_image_mode();

            // 转换内容为 HTML（收集图片占位符）
            $raw_content = Notion_Content_Converter::convert_blocks_to_html($blocks, $this->notion_api);

            // 处理异步图片下载并替换占位符
            $processed_content = Notion_Image_Processor::process_async_images($raw_content);

            // 获取图片处理统计
            $image_stats = Notion_Image_Processor::get_performance_stats();
            Notion_To_WordPress_Helper::debug_log(
                sprintf(
                    '并发图片处理完成: 成功 %d 个，失败 %d 个',
                    $image_stats['success_count'] ?? 0,
                    $image_stats['error_count'] ?? 0
                ),
                'Page Import'
            );

            return Notion_To_WordPress_Helper::custom_kses($processed_content);

        } catch (Exception $e) {
            // 并发处理失败时回退到传统模式
            Notion_To_WordPress_Helper::error_log(
                '并发图片处理失败，回退到传统模式: ' . $e->getMessage(),
                'Page Import'
            );

            return $this->process_content_traditional_mode($blocks);
        } finally {
            // 确保异步模式被禁用
            Notion_Image_Processor::disable_async_image_mode();
        }
    }

    /**
     * 使用传统模式处理内容
     *
     * @since    2.0.0-beta.1
     * @param    array     $blocks    内容块数组
     * @return   string               处理后的HTML内容
     */
    private function process_content_traditional_mode(array $blocks): string {
        Notion_To_WordPress_Helper::debug_log('使用传统模式处理内容', 'Page Import');

        // 传统模式：直接处理，不使用并发优化
        $raw_content = Notion_Content_Converter::convert_blocks_to_html($blocks, $this->notion_api);
        return Notion_To_WordPress_Helper::custom_kses($raw_content);
    }

    /**
     * 协调WordPress集成
     *
     * @since    2.0.0-beta.1
     * @param    array     $metadata    页面元数据
     * @param    string    $content     页面内容
     * @param    string    $page_id     页面ID
     * @return   int|WP_Error           文章ID或错误
     */
    private function coordinate_wordpress_integration(array $metadata, string $content, string $page_id) {
        Notion_To_WordPress_Helper::debug_log('协调WordPress集成开始', 'Page Import');

        $existing_post_id = Notion_To_WordPress_Integrator::get_post_by_notion_id($page_id);
        $author_id = Notion_To_WordPress_Integrator::get_default_author_id();

        // 创建或更新文章
        $post_id = Notion_To_WordPress_Integrator::create_or_update_post(
            $metadata,
            $content,
            $author_id,
            $page_id,
            $existing_post_id
        );

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // 应用分类、标签和特色图
        Notion_To_WordPress_Integrator::apply_taxonomies($post_id, $metadata);
        Notion_To_WordPress_Integrator::apply_featured_image($post_id, $metadata);

        Notion_To_WordPress_Helper::debug_log('WordPress集成完成，文章ID: ' . $post_id, 'Page Import');

        return $post_id;
    }

    /**
     * 协调同步状态更新
     *
     * @since    2.0.0-beta.1
     * @param    string    $page_id           页面ID
     * @param    string    $last_edited_time  最后编辑时间
     */
    private function coordinate_sync_status_update(string $page_id, string $last_edited_time): void {
        if (!empty($last_edited_time)) {
            Notion_Sync_Manager::update_page_sync_time($page_id, $last_edited_time);
            Notion_To_WordPress_Helper::debug_log('同步状态更新完成', 'Page Import');
        }
    }
}