<?php
declare(strict_types=1);

/**
 * Notion 导入协调器类
 *
 * 重构后的导入协调器类，专注于服务协调和流程管理。通过依赖注入模式整合所有专门的功能类，
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

class Notion_Import_Coordinator {

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

        // 智能判断：小数据集禁用并发优化，避免开销大于收益
        $force_disable = isset($performance_config['enable_concurrent_optimization']) &&
                        $performance_config['enable_concurrent_optimization'] === false;
        if ($force_disable) {
            return false;
        }

        // 默认策略：根据数据量智能启用
        // 对于小于20页的数据集，并发优化通常会降低性能
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
     * @return   bool|string             导入成功返回true，失败返回false，跳过返回'skipped'
     */
    public function import_notion_page(array $page) {
        Notion_Logger::debug_log('import_notion_page() 开始执行（主协调器）', 'Page Import');

        // 1. 验证输入数据
        if (empty($page) || !isset($page['id'])) {
            Notion_Logger::error_log('页面数据为空或缺少ID', 'Page Import');
            return false;
        }

        $page_id = $page['id'];

        try {
            // 2. 增量检测（如果可用）
            if (class_exists('Notion_Incremental_Detector')) {
                // 检查是否存在对应的WordPress文章
                $existing_post_id = Notion_Database_Helper::batch_get_posts_by_notion_ids([$page_id])[$page_id] ?? 0;

                if ($existing_post_id > 0) {
                    // 使用增量检测器判断是否需要同步
                    if (Notion_Incremental_Detector::should_skip_sync($page, $existing_post_id)) {
                        Notion_Logger::debug_log(
                            "增量检测器判断页面无需同步，跳过导入: {$page_id}",
                            'Page Import'
                        );
                        return 'skipped'; // 返回特殊值表示跳过
                    } else {
                        Notion_Logger::debug_log(
                            "增量检测器确认页面需要同步: {$page_id}",
                            'Page Import'
                        );
                    }
                }
            }

            // 3. 协调元数据提取
            $metadata = $this->coordinate_metadata_extraction($page);
            if (empty($metadata['title'])) {
                Notion_Logger::debug_log('页面标题为空，跳过导入', 'Page Import');
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

            // 6. 更新增量检测哈希值
            if (class_exists('Notion_Incremental_Detector')) {
                Notion_Incremental_Detector::update_sync_hashes($page, $post_id);
            }

            Notion_Logger::debug_log('页面导入完成', 'Page Import');
            return true;

        } catch (Exception $e) {
            Notion_Logger::error_log('页面导入异常: ' . $e->getMessage(), 'Page Import');
            return false;
        }
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
     * 创建或更新 WordPress 文章
     *
     * @return int|WP_Error
     */
    private function create_or_update_post(array $metadata, string $content, int $author_id, string $page_id, int $existing_post_id = 0) {
        // 委托给WordPress集成器
        return Notion_To_WordPress_Integrator::create_or_update_post($metadata, $content, $author_id, $page_id, $existing_post_id);
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
            // 动态设置执行时间限制（修复：智能超时管理）
            $original_time_limit = intval(ini_get('max_execution_time'));
            $optimal_time_limit = $this->calculate_optimal_timeout($incremental);

            if ($original_time_limit < $optimal_time_limit) {
                set_time_limit($optimal_time_limit);
                Notion_Logger::info_log(
                    sprintf(
                        '已动态调整PHP执行时间限制：%d秒 → %d秒 [模式: %s]',
                        $original_time_limit,
                        $optimal_time_limit,
                        $incremental ? '增量' : '全量'
                    ),
                    'Performance'
                );
            } else {
                Notion_Logger::debug_log(
                    sprintf(
                        '当前执行时间限制充足：%d秒 [推荐: %d秒]',
                        $original_time_limit,
                        $optimal_time_limit
                    ),
                    'Performance'
                );
            }

            $performance_stats = [
                'total_time' => 0,
                'api_calls' => 0,
                'images_processed' => 0,
                'concurrent_operations' => 0
            ];

            // 缓存已禁用，直接使用实时数据库查询
            Notion_Logger::info_log('使用实时数据库查询，确保数据一致性', 'Data Management');

            // 添加调试日志
            Notion_Logger::info_log('import_pages() 开始执行（主协调器）', 'Pages Import');
            Notion_Logger::info_log('Database ID: ' . $this->database_id, 'Pages Import');
            Notion_Logger::info_log('检查删除: ' . ($check_deletions ? 'yes' : 'no'), 'Pages Import');
            Notion_Logger::info_log('增量同步: ' . ($incremental ? 'yes' : 'no'), 'Pages Import');
            Notion_Logger::info_log('强制刷新: ' . ($force_refresh ? 'yes' : 'no'), 'Pages Import');
            // 获取数据库中的页面 - 支持增量同步前置过滤
            Notion_Logger::debug_log('调用get_database_pages()', 'Pages Import');
            
            // 如果启用增量同步且不是强制刷新，使用API层面的前置过滤
            if ($incremental && !$force_refresh) {
                Notion_Logger::info_log('启用增量同步前置过滤，减少不必要的数据传输', 'Pages Import');
                
                // 获取最后同步时间作为过滤基准
                $last_sync_time = $this->get_last_sync_timestamp();
                $pages = $this->get_changed_pages_only($last_sync_time);
                
                Notion_Logger::info_log('前置过滤获取到变更页面数量: ' . count($pages), 'Pages Import');
            } else {
                // 非增量模式，获取所有页面
                $pages = $this->notion_api->get_database_pages($this->database_id);
                Notion_Logger::info_log('获取到所有页面数量: ' . count($pages), 'Pages Import');
            }

            if (empty($pages)) {
                return new WP_Error('no_pages', __('未检索到任何页面。', 'notion-to-wordpress'));
            }

            $stats = [
                'total' => count($pages),
                'imported' => 0,
                'updated' => 0,
                'failed' => 0,
                'deleted' => 0,
                'skipped' => 0,
                'created' => 0,
                'errors' => 0
            ];

            // 如果启用删除检测，先处理删除的页面（使用完整页面列表）
            if ($check_deletions) {
                Notion_Logger::info_log('开始执行删除检测...', 'Pages Import');

                // 安全检查：确保页面列表不为空，避免误删除所有文章
                if (empty($pages)) {
                    Notion_Logger::error_log('删除检测跳过：页面列表为空，可能是API调用失败', 'Pages Import');
                    $stats['deleted'] = 0;
                } else {
                    try {
                        $deleted_count = $this->cleanup_deleted_pages($pages);
                        $stats['deleted'] = $deleted_count;
                        Notion_Logger::info_log('删除检测完成，删除了 ' . $deleted_count . ' 个页面', 'Pages Import');
                    } catch (Exception $e) {
                        Notion_Logger::error_log('删除检测失败: ' . $e->getMessage(), 'Pages Import');
                        Notion_Logger::error_log('删除检测异常堆栈: ' . $e->getTraceAsString(), 'Pages Import');
                        $stats['deleted'] = 0;

                        // 记录详细的错误信息以便调试
                        Notion_Logger::error_log(
                            '删除检测失败详情 - 页面数量: ' . count($pages) . ', 错误: ' . $e->getMessage(),
                            'Pages Import'
                        );
                    }
                }
            }

            // 如果启用增量同步且不是强制刷新，且未使用前置过滤，则进行后置过滤
            if ($incremental && !$force_refresh && count($pages) > 50) {
                // 仅当页面数量较多时才进行后置精细过滤（作为双重保险）
                Notion_Logger::info_log('页面数量较多，进行后置精细过滤', 'Pages Import');
                $pages = Notion_Sync_Manager::filter_pages_for_incremental_sync($pages);
                Notion_Logger::info_log('后置过滤后页面数量: ' . count($pages), 'Pages Import');
                
                // 更新统计中的总数为实际处理的页面数
                $stats['total'] = count($pages);
            } elseif ($incremental && !$force_refresh) {
                // 前置过滤已完成，无需后置过滤
                Notion_Logger::info_log('使用前置过滤结果，跳过后置过滤', 'Pages Import');
                $stats['total'] = count($pages);
            } elseif ($force_refresh) {
                Notion_Logger::info_log('强制刷新模式，将处理所有 ' . count($pages) . ' 个页面', 'Pages Import');
            }

            if (empty($pages)) {
                // 如果增量同步后没有页面需要处理，返回成功但无操作的结果
                Notion_Logger::info_log('增量同步无页面需要更新', 'Pages Import');

                // 缓存已禁用，记录无页面更新状态
                Notion_Logger::debug_log(
                    '增量同步完成，无页面需要更新（缓存已禁用）',
                    'Incremental Sync'
                );

                return $stats;
            }

            Notion_Logger::info_log('开始处理页面，总数: ' . count($pages), 'Pages Import');

            // 检查是否启用超级批量模式
            $options = get_option('notion_to_wordpress_options', []);
            $performance_mode = $options['enable_performance_mode'] ?? 1;

            if ($performance_mode && count($pages) >= 10) {
                // 使用超级批量同步模式（适用于大量页面）
                Notion_Logger::info_log('启用超级批量同步模式', 'Pages Import');
                $batch_stats = Notion_Sync_Manager::super_batch_sync($pages, $this->notion_api);

                // 更新统计数据
                $stats['created'] += $batch_stats['created'];
                $stats['updated'] += $batch_stats['updated'];
                $stats['skipped'] += $batch_stats['skipped'];
                $stats['errors'] += $batch_stats['errors'];

                Notion_Logger::info_log(
                    sprintf('超级批量同步完成: 创建=%d, 更新=%d, 跳过=%d, 错误=%d',
                        $batch_stats['created'], $batch_stats['updated'],
                        $batch_stats['skipped'], $batch_stats['errors']),
                    'Pages Import'
                );
            } else {
                // 使用传统逐个处理模式（优化内存使用）
                $processed_count = 0;
                foreach ($pages as $index => $page) {
                    // 智能超时检查和预警（修复：动态超时管理）
                    $timeout_status = $this->check_timeout_status($import_start_time, $optimal_time_limit);

                    if ($timeout_status['should_stop']) {
                        Notion_Logger::warning_log(
                            sprintf(
                                '同步已运行%.1f秒，达到安全阈值(%.1f%%)，为防止超时将在处理完当前页面后停止',
                                $timeout_status['elapsed_time'],
                                $timeout_status['usage_percentage']
                            ),
                            'Performance'
                        );

                        // 设置处理状态，下次同步将从这里继续
                        if ($processed_count > 0) {
                            Notion_Logger::info_log(
                                sprintf(
                                    '本次成功处理了%d个页面，剩余%d个页面将在下次同步时处理 [效率: %.2f页面/秒]',
                                    $processed_count,
                                    count($pages) - $index,
                                    $processed_count / $timeout_status['elapsed_time']
                                ),
                                'Performance'
                            );
                        }
                        break;
                    } elseif ($timeout_status['should_warn']) {
                        Notion_Logger::info_log(
                            sprintf(
                                '超时预警：已使用%.1f%%执行时间 [%d/%d页面已处理]',
                                $timeout_status['usage_percentage'],
                                $processed_count,
                                count($pages)
                            ),
                            'Performance Warning'
                        );
                    }

                    if (!$performance_mode) {
                        Notion_Logger::debug_log('处理页面 ' . ($index + 1) . '/' . count($pages) . ', ID: ' . ($page['id'] ?? 'unknown'), 'Pages Import');
                    }

                    try {
                        // 检查页面是否已存在
                        $existing_post_id = Notion_To_WordPress_Integrator::get_post_by_notion_id($page['id']);
                        if (!$performance_mode) {
                            Notion_Logger::debug_log('页面已存在检查结果: ' . ($existing_post_id ? 'exists (ID: ' . $existing_post_id . ')' : 'new'), 'Pages Import');
                        }

                        if (!$performance_mode) {
                            Notion_Logger::debug_log('开始导入单个页面...', 'Pages Import');
                        }
                        $result = $this->import_notion_page($page);
                        if (!$performance_mode) {
                            Notion_Logger::debug_log('单个页面导入结果: ' . ($result ? 'success' : 'failed'), 'Pages Import');
                        }
                        
                        // 简单的内存管理：每处理10个页面清理一次内存
                        $processed_count++;
                        if ($processed_count % 10 === 0) {
                            // 释放未使用的变量
                            unset($existing_post_id, $result);
                            // 强制垃圾回收
                            gc_collect_cycles();
                            
                            // 记录内存使用情况
                            $memory_usage = memory_get_usage(true);
                            $memory_mb = round($memory_usage / 1024 / 1024, 2);
                            Notion_Logger::debug_log("已处理 {$processed_count} 个页面，当前内存使用: {$memory_mb}MB", 'Memory Management');
                        }

                        if ($result === 'skipped') {
                            // 增量检测器跳过的页面
                            $stats['skipped']++;
                        } elseif ($result) {
                            // 成功同步的页面
                            if ($existing_post_id) {
                                $stats['updated']++;
                            } else {
                                $stats['imported']++;
                            }
                        } else {
                            // 同步失败的页面
                            $stats['failed']++;
                        }
                    } catch (Exception $e) {
                        Notion_Logger::error_log('处理页面异常: ' . $e->getMessage(), 'Pages Import');
                        $stats['failed']++;
                    } catch (Error $e) {
                        Notion_Logger::error_log('处理页面错误: ' . $e->getMessage(), 'Pages Import');
                        $stats['failed']++;
                    }

                    if (!$performance_mode) {
                        Notion_Logger::debug_log('页面 ' . ($index + 1) . ' 处理完成', 'Pages Import');
                    }
                }
            }

            Notion_Logger::info_log('所有页面处理完成，统计: ' . print_r($stats, true), 'Pages Import');

            // 计算性能统计
            $performance_stats['total_time'] = microtime(true) - $import_start_time;

            // 缓存已禁用，记录性能统计
            Notion_Logger::debug_log(
                '性能统计: ' . print_r($performance_stats, true),
                'Performance Stats'
            );

            // 记录性能统计
            Notion_Logger::info_log(
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

            // 执行延迟链接转换处理
            Notion_Logger::debug_log('开始执行延迟链接转换处理', 'Pages Import');
            $link_conversion_stats = Notion_To_WordPress_Integrator::process_delayed_link_conversion();

            // 将链接转换统计添加到返回结果
            $stats['link_conversion'] = $link_conversion_stats;

            Notion_Logger::info_log(
                sprintf('延迟链接转换统计: 处理=%d, 更新=%d, 错误=%d',
                    $link_conversion_stats['processed'],
                    $link_conversion_stats['updated'],
                    $link_conversion_stats['errors']
                ),
                'Pages Import'
            );

            // 缓存已禁用，无需清理操作
            Notion_Logger::debug_log(
                '同步完成，缓存已禁用无需清理',
                'Performance Stats'
            );

            // 恢复原始执行时间限制（修复：执行时间管理）
            if (isset($original_time_limit) && $original_time_limit != intval(ini_get('max_execution_time'))) {
                set_time_limit(intval($original_time_limit));
                Notion_Logger::debug_log('已恢复原始PHP执行时间限制', 'Performance');
            }

            return $stats;

        } catch (Exception $e) {
            Notion_Logger::error_log('import_pages() 异常: ' . $e->getMessage(), 'Pages Import');
            Notion_Logger::error_log('异常堆栈: ' . $e->getTraceAsString(), 'Pages Import');

            // 缓存已禁用，记录异常状态
            Notion_Logger::debug_log(
                '导入异常，缓存已禁用无需清理',
                'Exception Handling'
            );

            return new WP_Error('import_failed', __('导入失败: ', 'notion-to-wordpress') . $e->getMessage());
        }
    }


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
            Notion_Logger::error_log( __('下载附件失败: ', 'notion-to-wordpress') . $tmp->get_error_message(), 'Notion File' );
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
            Notion_Logger::error_log( __('media_handle_sideload 错误: ', 'notion-to-wordpress') . $attachment_id->get_error_message(), 'Notion File' );
            @unlink( $tmp );
            return $attachment_id;
        }

        // 存储原始 URL 及 base_url，避免重复下载
        update_post_meta( $attachment_id, '_notion_original_url', esc_url( $url ) );
        update_post_meta( $attachment_id, '_notion_base_url', esc_url( $base_url ) );

        return (int) $attachment_id;
    }

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
            Notion_Logger::error_log(
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

        Notion_Logger::debug_log(
            '找到 ' . count($results) . ' 个WordPress文章有Notion ID',
            'Cleanup'
        );

        foreach ($results as $row) {
            // 如果这个Notion ID不在当前页面列表中，说明已被删除
            if (!in_array($row->notion_id, $current_notion_ids)) {
                Notion_Logger::debug_log(
                    '发现孤儿文章，WordPress ID: ' . $row->post_id . ', Notion ID: ' . $row->notion_id,
                    'Cleanup'
                );

                $result = wp_delete_post($row->post_id, true); // true表示彻底删除

                if ($result) {
                    $deleted_count++;
                    Notion_Logger::info_log(
                        '删除孤儿文章成功，WordPress ID: ' . $row->post_id . ', Notion ID: ' . $row->notion_id,
                        'Cleanup'
                    );
                } else {
                    Notion_Logger::error_log(
                        '删除孤儿文章失败，WordPress ID: ' . $row->post_id . ', Notion ID: ' . $row->notion_id
                    );
                }
            }
        }

        if ($deleted_count > 0) {
            Notion_Logger::info_log(
                '删除检测完成，共删除 ' . $deleted_count . ' 个孤儿文章',
                'Cleanup'
            );
        }

        return $deleted_count;
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
                Notion_Logger::debug_log(
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
                    Notion_Logger::debug_log(
                        '文件缩略图下载成功: ' . $file_name,
                        'Record Files'
                    );
                } else {
                    Notion_Logger::error_log(
                        '文件缩略图下载后获取本地URL失败: ' . $file_name,
                        'Record Files'
                    );
                    return $this->render_file_link($file_url, $file_name);
                }
            } else {
                Notion_Logger::error_log(
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
            Notion_Logger::error_log(
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

    /**
     * 禁用异步图片下载模式
     *
     * @since    1.9.0-beta.1
     * @param    string    $state_id    状态管理器ID，用于状态隔离
     */
    public function disable_async_image_mode(string $state_id = null): void {
        // 委托给图片处理器
        Notion_Image_Processor::disable_async_image_mode($state_id);
    }

    /**
     * 处理异步图片下载并替换占位符
     *
     * @since    1.9.0-beta.1
     * @param    string    $html       包含占位符的HTML内容
     * @param    string    $state_id   状态管理器ID，用于状态隔离
     * @return   string                处理后的HTML内容
     */
    public function process_async_images(string $html, string $state_id = null): string {
        // 委托给图片处理器
        return Notion_Image_Processor::process_async_images($html, $state_id);
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
        Notion_Logger::debug_log('协调元数据提取开始', 'Page Import');

        $metadata = Notion_Metadata_Extractor::extract_page_metadata(
            $page,
            $this->field_mapping ?? [],
            $this->custom_field_mappings ?? []
        );

        Notion_Logger::debug_log(
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
        // 检查是否启用性能模式来决定日志级别
        $options = get_option('notion_to_wordpress_options', []);
        $performance_mode = $options['enable_performance_mode'] ?? 1;

        if (!$performance_mode) {
            Notion_Logger::debug_log('协调内容处理开始', 'Page Import');
        }

        // 获取页面内容
        $blocks = $this->notion_api->get_page_content($page_id);

        if (!$performance_mode) {
            Notion_Logger::debug_log('获取到内容块数量: ' . count($blocks), 'Page Import');
        }

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
        Notion_Logger::debug_log('使用并发优化模式处理内容', 'Page Import');

        // 为当前页面导入创建唯一的状态ID
        $state_id = 'page_import_' . uniqid();

        try {
            // 启用异步图片下载模式（使用独立状态）
            Notion_Image_Processor::enable_async_image_mode($state_id);

            // 转换内容为 HTML（收集图片占位符）
            $raw_content = Notion_Content_Converter::convert_blocks_to_html($blocks, $this->notion_api, $state_id);

            // 处理异步图片下载并替换占位符
            $processed_content = Notion_Image_Processor::process_async_images($raw_content, $state_id);

            // 获取图片处理统计
            $image_stats = Notion_Image_Processor::get_performance_stats();
            Notion_Logger::debug_log(
                sprintf(
                    '并发图片处理完成: 成功 %d 个，失败 %d 个',
                    $image_stats['success_count'] ?? 0,
                    $image_stats['error_count'] ?? 0
                ),
                'Page Import'
            );

            return Notion_Security::custom_kses($processed_content);

        } catch (Exception $e) {
            // 并发处理失败时回退到传统模式
            Notion_Logger::error_log(
                '并发图片处理失败，回退到传统模式: ' . $e->getMessage(),
                'Page Import'
            );

            return $this->process_content_traditional_mode($blocks);
        } finally {
            // 确保异步模式被禁用并清理状态
            Notion_Image_Processor::disable_async_image_mode($state_id);
            Notion_Image_Processor::reset($state_id);
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
        Notion_Logger::debug_log('使用传统模式处理内容', 'Page Import');

        // 传统模式：直接处理，不使用并发优化
        $raw_content = Notion_Content_Converter::convert_blocks_to_html($blocks, $this->notion_api);
        return Notion_Security::custom_kses($raw_content);
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
        Notion_Logger::debug_log('协调WordPress集成开始', 'Page Import');

        // 诊断日志：记录内容保存前的状态
        $content_length = strlen($content);
        $has_notion_links = strpos($content, 'notion.so') !== false;
        $has_wordpress_links = strpos($content, 'frankloong.local') !== false;

        Notion_Logger::debug_log(
            sprintf('内容保存前诊断: 长度=%d, 包含Notion链接=%s, 包含WordPress链接=%s',
                $content_length,
                $has_notion_links ? '是' : '否',
                $has_wordpress_links ? '是' : '否'
            ),
            'Content Diagnosis'
        );

        // 如果包含转换后的链接，记录详细信息
        if ($has_wordpress_links) {
            preg_match_all('/frankloong\.local[^\s"\'<>]*/', $content, $matches);
            $wordpress_links = $matches[0] ?? [];
            Notion_Logger::debug_log(
                '发现转换后的WordPress链接: ' . implode(', ', array_slice($wordpress_links, 0, 3)),
                'Content Diagnosis'
            );
        }

        $existing_post_id = Notion_To_WordPress_Integrator::get_post_by_notion_id($page_id);
        $author_id = Notion_To_WordPress_Integrator::get_default_author_id();

        Notion_Logger::debug_log(
            sprintf('文章信息: 页面ID=%s, 现有文章ID=%d, 作者ID=%d',
                $page_id,
                $existing_post_id,
                $author_id
            ),
            'Content Diagnosis'
        );

        // 创建或更新文章
        $post_id = Notion_To_WordPress_Integrator::create_or_update_post(
            $metadata,
            $content,
            $author_id,
            $page_id,
            $existing_post_id
        );

        if (is_wp_error($post_id)) {
            Notion_Logger::error_log(
                '文章保存失败: ' . $post_id->get_error_message(),
                'Content Diagnosis'
            );
            return $post_id;
        }

        // 诊断日志：验证保存后的内容
        $saved_post = get_post($post_id);
        if ($saved_post) {
            $saved_content = $saved_post->post_content;
            $saved_length = strlen($saved_content);
            $saved_has_notion_links = strpos($saved_content, 'notion.so') !== false;
            $saved_has_wordpress_links = strpos($saved_content, 'frankloong.local') !== false;

            Notion_Logger::debug_log(
                sprintf('内容保存后诊断: 长度=%d, 包含Notion链接=%s, 包含WordPress链接=%s',
                    $saved_length,
                    $saved_has_notion_links ? '是' : '否',
                    $saved_has_wordpress_links ? '是' : '否'
                ),
                'Content Diagnosis'
            );

            // 比较保存前后的内容
            if ($content_length !== $saved_length) {
                Notion_Logger::warning_log(
                    sprintf('内容长度变化: 保存前=%d, 保存后=%d',
                        $content_length,
                        $saved_length
                    ),
                    'Content Diagnosis'
                );
            }

            // 检查链接转换是否丢失
            if ($has_wordpress_links && !$saved_has_wordpress_links) {
                Notion_Logger::error_log(
                    '严重问题：转换后的WordPress链接在保存后丢失！',
                    'Content Diagnosis'
                );
            } elseif ($has_wordpress_links && $saved_has_wordpress_links) {
                Notion_Logger::debug_log(
                    '链接转换保存成功',
                    'Content Diagnosis'
                );
            }
        } else {
            Notion_Logger::error_log(
                '无法获取保存后的文章内容进行验证',
                'Content Diagnosis'
            );
        }

        // 应用分类、标签和特色图
        Notion_To_WordPress_Integrator::apply_taxonomies($post_id, $metadata);
        Notion_To_WordPress_Integrator::apply_featured_image($post_id, $metadata);

        Notion_Logger::debug_log('WordPress集成完成，文章ID: ' . $post_id, 'Page Import');

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
            Notion_Logger::debug_log('同步状态更新完成', 'Page Import');
        }
    }

    /**
     * 获取最后同步时间戳
     * 用于API层面的增量过滤
     *
     * @since 2.0.0-beta.1
     * @return string|null ISO 8601 格式的时间戳，null表示首次同步
     */
    private function get_last_sync_timestamp(): ?string {
        $options = get_option('notion_to_wordpress_options', []);
        $last_sync = $options['last_sync_time'] ?? '';
        
        // 处理空值、无效值和MySQL默认值
        if (empty($last_sync) ||
            $last_sync === '0000-00-00 00:00:00' ||
            strtotime($last_sync) === false) {
            // 首次同步：不使用时间过滤，获取所有页面
            $last_sync = null;
            Notion_Logger::info_log(
                "首次同步：将获取所有页面（不使用时间过滤）",
                'Incremental Sync'
            );
        } else {
            // 转换为ISO 8601格式
            $timestamp = strtotime($last_sync);
            $last_sync = date('c', $timestamp);
            Notion_Logger::debug_log(
                "使用上次同步时间: {$last_sync}",
                'Incremental Sync'
            );
        }
        
        return $last_sync;
    }

    /**
     * 仅获取变更的页面（API层面前置过滤）
     *
     * 这是核心的前置过滤方法，直接在API层面过滤，
     * 大幅减少数据传输和处理时间
     *
     * @since 2.0.0-beta.1
     * @param string|null $last_sync_time 最后同步时间，null表示首次同步
     * @return array 变更的页面列表
     */
    private function get_changed_pages_only(?string $last_sync_time): array {
        // 首次同步：获取所有页面
        if (is_null($last_sync_time) || empty($last_sync_time) || trim($last_sync_time) === '') {
            Notion_Logger::info_log(
                "首次同步或无效同步时间戳，获取所有页面",
                'Incremental Sync'
            );
            return $this->notion_api->get_database_pages($this->database_id);
        }

        // 使用API类的时间戳格式化方法确保格式正确
        $formatted_time = $this->notion_api->format_timestamp_for_api($last_sync_time);
        if (empty($formatted_time)) {
            Notion_Logger::warning_log(
                "时间戳格式化失败，使用全量获取: {$last_sync_time}",
                'Incremental Sync'
            );
            return $this->notion_api->get_database_pages($this->database_id);
        }

        // 构建符合Notion API规范的时间戳过滤条件
        // 根据官方文档：https://developers.notion.com/reference/post-database-query-filter#timestamp
        $filter = [
            'timestamp' => 'last_edited_time',
            'last_edited_time' => [
                'after' => $formatted_time
            ]
        ];

        // 调试：记录过滤器内容
        Notion_Logger::debug_log(
            "构建的时间戳过滤器: " . json_encode($filter, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'Filter Debug'
        );

        // 验证过滤器结构并记录调试信息
        $is_valid = $this->notion_api->is_valid_filter($filter);
        Notion_Logger::debug_log(
            sprintf(
                "过滤器构建完成: %s, 验证结果: %s",
                json_encode($filter, JSON_UNESCAPED_UNICODE),
                $is_valid ? '有效' : '无效'
            ),
            'Filter Debug'
        );

        Notion_Logger::info_log(
            "API前置过滤条件: last_edited_time > {$formatted_time}",
            'Incremental Sync'
        );

        try {
            // 使用过滤条件获取页面
            $pages = $this->notion_api->get_database_pages($this->database_id, $filter, true);
            
            Notion_Logger::info_log(
                sprintf(
                    "前置过滤成功：从API层面过滤，只获取 %d 个变更页面（vs 全量获取）",
                    count($pages)
                ),
                'Incremental Sync'
            );
            
            // 记录节省的网络传输估算
            if (count($pages) < 50) {
                $estimated_total = max(100, count($pages) * 10); // 估算总数
                $saved_percentage = round((1 - count($pages) / $estimated_total) * 100, 1);
                Notion_Logger::info_log(
                    "估算节省网络传输: {$saved_percentage}%（获取 " . count($pages) . " 页面 vs 估算 {$estimated_total} 总页面）",
                    'Performance'
                );
            }
            
            return $pages;
            
        } catch (Exception $e) {
            // 分析错误类型并记录详细信息
            $error_type = $this->classify_api_error($e);
            $error_context = [
                'database_id' => $this->database_id,
                'last_sync_time' => $last_sync_time,
                'formatted_time' => $formatted_time ?? 'N/A',
                'error_type' => $error_type,
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ];

            Notion_Logger::error_log(
                sprintf(
                    "增量同步API过滤失败 [%s]: %s",
                    $error_type,
                    $e->getMessage()
                ),
                'Incremental Sync Error'
            );

            Notion_Logger::debug_log(
                "错误详细上下文: " . print_r($error_context, true),
                'Incremental Sync Debug'
            );

            // 根据错误类型决定是否重试
            if ($this->should_retry_api_call($error_type)) {
                Notion_Logger::warning_log(
                    "检测到临时性错误，尝试重试一次",
                    'Incremental Sync Retry'
                );

                try {
                    // 等待1秒后重试
                    sleep(1);
                    $pages = $this->notion_api->get_database_pages($this->database_id, $filter, true);

                    Notion_Logger::info_log(
                        "重试成功，获取到 " . count($pages) . " 个页面",
                        'Incremental Sync Retry'
                    );

                    return $pages;

                } catch (Exception $retry_e) {
                    Notion_Logger::warning_log(
                        "重试失败: " . $retry_e->getMessage(),
                        'Incremental Sync Retry'
                    );
                }
            }

            // 记录降级操作详细信息
            $fallback_context = [
                'original_error_type' => $error_type,
                'original_error_message' => $e->getMessage(),
                'original_error_code' => $e->getCode(),
                'last_sync_time' => $last_sync_time,
                'formatted_time' => $formatted_time ?? 'N/A',
                'fallback_reason' => '增量同步失败，尝试全量获取'
            ];

            Notion_Logger::warning_log(
                sprintf(
                    "增量同步失败，降级到全量获取 [原因: %s]",
                    $error_type
                ),
                'Incremental Sync Fallback'
            );

            Notion_Logger::debug_log(
                "降级操作详细上下文: " . print_r($fallback_context, true),
                'Fallback Debug'
            );

            // 降级到全量获取
            try {
                Notion_Logger::info_log(
                    "开始执行全量获取作为降级方案",
                    'Incremental Sync Fallback'
                );

                $fallback_pages = $this->notion_api->get_database_pages($this->database_id);

                Notion_Logger::info_log(
                    sprintf(
                        "全量获取成功，共获取 %d 个页面（降级模式）",
                        count($fallback_pages)
                    ),
                    'Incremental Sync Fallback'
                );

                // 记录降级成功的性能影响
                if (count($fallback_pages) > 50) {
                    Notion_Logger::warning_log(
                        sprintf(
                            "降级到全量获取导致性能影响：获取了 %d 个页面，建议检查过滤器配置",
                            count($fallback_pages)
                        ),
                        'Performance Impact'
                    );
                }

                return $fallback_pages;

            } catch (Exception $fallback_e) {
                $fallback_error_type = $this->classify_api_error($fallback_e);

                Notion_Logger::error_log(
                    sprintf(
                        "全量获取也失败 [错误类型: %s]: %s",
                        $fallback_error_type,
                        $fallback_e->getMessage()
                    ),
                    'Incremental Sync Critical'
                );

                // 记录完整的错误上下文用于调试
                $critical_context = [
                    'original_error' => $error_context,
                    'fallback_error_type' => $fallback_error_type,
                    'fallback_error_message' => $fallback_e->getMessage(),
                    'fallback_error_code' => $fallback_e->getCode(),
                    'database_id' => $this->database_id,
                    'api_key_status' => !empty($this->notion_api) ? 'available' : 'missing'
                ];

                Notion_Logger::debug_log(
                    "关键错误完整上下文: " . print_r($critical_context, true),
                    'Critical Error Debug'
                );

                // 返回空数组，避免完全中断同步流程
                Notion_Logger::warning_log(
                    "所有获取方式均失败，返回空结果以避免中断同步流程",
                    'Incremental Sync Critical'
                );

                return [];
            }
        }
    }

    /**
     * 计算最优超时时间
     *
     * 根据同步模式和系统性能动态计算合适的超时时间
     *
     * @since 2.0.0-beta.1
     * @param bool $incremental 是否为增量同步
     * @return int 推荐的超时时间（秒）
     */
    private function calculate_optimal_timeout(bool $incremental): int {
        // 基础超时时间
        $base_timeout = $incremental ? 300 : 600; // 增量5分钟，全量10分钟

        // 根据系统性能调整
        $memory_limit = $this->parse_memory_limit(ini_get('memory_limit'));
        $performance_factor = 1.0;

        // 内存充足时可以延长超时时间
        if ($memory_limit >= 512 * 1024 * 1024) { // 512MB+
            $performance_factor = 1.5;
        } elseif ($memory_limit >= 256 * 1024 * 1024) { // 256MB+
            $performance_factor = 1.2;
        } elseif ($memory_limit < 128 * 1024 * 1024) { // <128MB
            $performance_factor = 0.8;
        }

        // 检查是否在CLI环境（通常有更宽松的限制）
        if (php_sapi_name() === 'cli') {
            $performance_factor *= 2.0;
        }

        $optimal_timeout = intval($base_timeout * $performance_factor);

        // 确保不超过合理范围
        $optimal_timeout = max(300, min(1800, $optimal_timeout)); // 5分钟到30分钟

        return $optimal_timeout;
    }

    /**
     * 解析内存限制字符串为字节数
     *
     * @since 2.0.0-beta.1
     * @param string $memory_limit 内存限制字符串（如 "256M", "1G"）
     * @return int 字节数
     */
    private function parse_memory_limit(string $memory_limit): int {
        $memory_limit = trim($memory_limit);
        $last_char = strtolower(substr($memory_limit, -1));
        $value = intval($memory_limit);

        switch ($last_char) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * 检查超时状态
     *
     * 检查当前执行时间状态，决定是否需要预警或停止
     *
     * @since 2.0.0-beta.1
     * @param float $start_time 开始时间
     * @param int $time_limit 时间限制
     * @return array 超时状态信息
     */
    private function check_timeout_status(float $start_time, int $time_limit): array {
        $elapsed_time = microtime(true) - $start_time;
        $usage_percentage = ($elapsed_time / $time_limit) * 100;

        return [
            'elapsed_time' => $elapsed_time,
            'time_limit' => $time_limit,
            'usage_percentage' => $usage_percentage,
            'should_warn' => $usage_percentage >= 60 && $usage_percentage < 80, // 60-80%预警
            'should_stop' => $usage_percentage >= 80 // 80%停止
        ];
    }

    /**
     * 分类API错误类型
     *
     * 根据异常信息判断错误类型，用于决定处理策略
     *
     * @since 2.0.0-beta.1
     * @param Exception $e 异常对象
     * @return string 错误类型
     */
    private function classify_api_error(Exception $e): string {
        $message = strtolower($e->getMessage());
        $code = $e->getCode();

        // 记录错误分类开始
        Notion_Logger::debug_log(
            sprintf(
                "开始分类API错误: 代码=%d, 消息=%s",
                $code,
                $e->getMessage()
            ),
            'Error Classification'
        );

        // 过滤器相关错误（优先检查，因为这是当前修复的重点）
        if (strpos($message, 'filter') !== false ||
            strpos($message, 'should be defined') !== false ||
            strpos($message, 'invalid filter') !== false ||
            strpos($message, 'filter validation') !== false ||
            strpos($message, 'property') !== false && strpos($message, 'does not exist') !== false ||
            strpos($message, 'timestamp') !== false && strpos($message, 'invalid') !== false ||
            ($code === 400 && (strpos($message, 'body') !== false || strpos($message, 'request') !== false))) {

            Notion_Logger::debug_log(
                "错误分类结果: FILTER_ERROR - 过滤器相关错误",
                'Error Classification'
            );
            return 'FILTER_ERROR';
        }

        // 网络相关错误
        if (strpos($message, 'timeout') !== false ||
            strpos($message, 'connection') !== false ||
            strpos($message, 'network') !== false ||
            strpos($message, 'curl') !== false ||
            $code === 28 || $code === 7 || $code === 6) {

            Notion_Logger::debug_log(
                "错误分类结果: NETWORK_ERROR - 网络连接错误",
                'Error Classification'
            );
            return 'NETWORK_ERROR';
        }

        // API限制错误
        if (strpos($message, 'rate limit') !== false ||
            strpos($message, 'too many requests') !== false ||
            strpos($message, 'quota') !== false ||
            $code === 429) {

            Notion_Logger::debug_log(
                "错误分类结果: RATE_LIMIT_ERROR - API限制错误",
                'Error Classification'
            );
            return 'RATE_LIMIT_ERROR';
        }

        // 服务器错误
        if ($code >= 500 && $code < 600) {
            Notion_Logger::debug_log(
                "错误分类结果: SERVER_ERROR - 服务器错误",
                'Error Classification'
            );
            return 'SERVER_ERROR';
        }

        // 认证错误
        if (strpos($message, 'unauthorized') !== false ||
            strpos($message, 'authentication') !== false ||
            strpos($message, 'api key') !== false ||
            strpos($message, 'forbidden') !== false ||
            $code === 401 || $code === 403) {

            Notion_Logger::debug_log(
                "错误分类结果: AUTH_ERROR - 认证错误",
                'Error Classification'
            );
            return 'AUTH_ERROR';
        }

        // 数据库访问错误
        if (strpos($message, 'database') !== false &&
            (strpos($message, 'not found') !== false || strpos($message, 'access') !== false) ||
            $code === 404) {

            Notion_Logger::debug_log(
                "错误分类结果: DATABASE_ERROR - 数据库访问错误",
                'Error Classification'
            );
            return 'DATABASE_ERROR';
        }

        // 其他错误
        Notion_Logger::debug_log(
            "错误分类结果: UNKNOWN_ERROR - 未知错误类型",
            'Error Classification'
        );
        return 'UNKNOWN_ERROR';
    }

    /**
     * 判断是否应该重试API调用
     *
     * 根据错误类型决定是否值得重试
     *
     * @since 2.0.0-beta.1
     * @param string $error_type 错误类型
     * @return bool 是否应该重试
     */
    private function should_retry_api_call(string $error_type): bool {
        // 这些错误类型值得重试
        $retryable_errors = [
            'NETWORK_ERROR',
            'RATE_LIMIT_ERROR',
            'SERVER_ERROR'
        ];

        // 记录重试决策
        $should_retry = in_array($error_type, $retryable_errors);
        Notion_Logger::debug_log(
            sprintf(
                "重试决策: 错误类型=%s, 是否重试=%s",
                $error_type,
                $should_retry ? '是' : '否'
            ),
            'Retry Decision'
        );

        return $should_retry;
    }


}