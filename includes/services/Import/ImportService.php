<?php
declare(strict_types=1);

namespace NTWP\Services\Import;

use NTWP\Core\Foundation\Logger;
use NTWP\Services\Api\NotionApi;
use NTWP\Services\Content\ContentConverter;
use NTWP\Services\Sync\SyncManager;
use NTWP\Infrastructure\Database\QueryBuilder;

/**
 * 导入服务
 *
 * 替代原有的Import_Coordinator_Legacy.php (2287行)
 * 提供简化、清晰的导入逻辑
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
class ImportService {
    
    private NotionApi $notion_api;
    private ContentConverter $content_converter;
    private SyncManager $sync_manager;
    
    public function __construct(
        NotionApi $notion_api,
        ContentConverter $content_converter,
        SyncManager $sync_manager
    ) {
        $this->notion_api = $notion_api;
        $this->content_converter = $content_converter;
        $this->sync_manager = $sync_manager;
    }
    
    /**
     * 执行完整导入流程
     *
     * @param string $database_id Notion数据库ID
     * @param array $options 导入选项
     * @return array 导入结果
     */
    public function execute_full_import(string $database_id, array $options = []): array {
        Logger::debug_log('开始完整导入流程', 'ImportService');
        
        $start_time = microtime(true);
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'imported_posts' => []
        ];
        
        try {
            // 1. 获取Notion页面
            $pages = $this->notion_api->fetch_database_pages($database_id, $options);
            
            if (empty($pages)) {
                Logger::debug_log('未找到页面，导入结束', 'ImportService');
                return $results;
            }
            
            // 2. 批量检查已存在的文章
            $notion_ids = array_column($pages, 'id');
            $existing_posts = QueryBuilder::batch_get_posts_by_notion_ids($notion_ids);
            
            // 3. 逐个处理页面
            foreach ($pages as $page) {
                $page_id = $page['id'];
                
                // 检查是否已存在
                if (isset($existing_posts[$page_id]) && $existing_posts[$page_id] > 0) {
                    if (!($options['update_existing'] ?? false)) {
                        $results['skipped']++;
                        continue;
                    }
                }
                
                // 导入单个页面
                $import_result = $this->import_single_page($page, $existing_posts[$page_id] ?? 0);
                
                if ($import_result['success']) {
                    $results['success']++;
                    $results['imported_posts'][] = $import_result['post_id'];
                } else {
                    $results['failed']++;
                    $results['errors'][] = $import_result['error'];
                }
            }
            
        } catch (\Exception $e) {
            $results['errors'][] = '导入过程异常: ' . $e->getMessage();
            Logger::debug_log('导入异常: ' . $e->getMessage(), 'ImportService');
        }
        
        $execution_time = microtime(true) - $start_time;
        
        Logger::debug_log(
            sprintf(
                '导入完成: %d成功, %d失败, %d跳过, 耗时%.2fs',
                $results['success'],
                $results['failed'],
                $results['skipped'],
                $execution_time
            ),
            'ImportService'
        );
        
        return $results;
    }
    
    /**
     * 导入单个页面
     *
     * @param array $page Notion页面数据
     * @param int $existing_post_id 已存在的文章ID，0表示新建
     * @return array 导入结果
     */
    public function import_single_page(array $page, int $existing_post_id = 0): array {
        try {
            $page_id = $page['id'];
            
            // 获取页面块内容
            $blocks = $this->notion_api->fetch_page_blocks($page_id);
            
            // 转换内容
            $content = $this->content_converter->convert_blocks_to_html($blocks);
            
            // 提取元数据
            $metadata = $this->extract_page_metadata($page);
            
            // 准备WordPress文章数据
            $post_data = [
                'post_title' => $metadata['title'],
                'post_content' => $content,
                'post_status' => $metadata['status'] ?? 'draft',
                'post_type' => $metadata['post_type'] ?? 'post',
                'post_date' => $metadata['created_time'],
                'post_modified' => $metadata['last_edited_time']
            ];
            
            // 创建或更新文章
            if ($existing_post_id > 0) {
                $post_data['ID'] = $existing_post_id;
                $post_id = wp_update_post($post_data);
            } else {
                $post_id = wp_insert_post($post_data);
            }
            
            if (is_wp_error($post_id)) {
                return [
                    'success' => false,
                    'error' => $post_id->get_error_message()
                ];
            }
            
            // 更新元数据
            update_post_meta($post_id, '_notion_page_id', $page_id);
            update_post_meta($post_id, '_notion_sync_time', current_time('mysql'));
            
            // 更新自定义字段
            foreach ($metadata['custom_fields'] as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }
            
            return [
                'success' => true,
                'post_id' => $post_id,
                'action' => $existing_post_id > 0 ? 'updated' : 'created'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => '页面导入失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 增量导入
     *
     * @param string $database_id 数据库ID
     * @param string $since 时间戳，导入此时间之后的更新
     * @return array 导入结果
     */
    public function execute_incremental_import(string $database_id, string $since = ''): array {
        Logger::debug_log('开始增量导入', 'ImportService');
        
        // 获取需要更新的页面
        $filter = [];
        if (!empty($since)) {
            $filter['filter'] = [
                'property' => 'Last edited time',
                'date' => [
                    'after' => $since
                ]
            ];
        }
        
        return $this->execute_full_import($database_id, $filter);
    }
    
    /**
     * 提取页面元数据
     *
     * @param array $page Notion页面数据
     * @return array 元数据
     */
    private function extract_page_metadata(array $page): array {
        $properties = $page['properties'] ?? [];
        
        // 提取标题
        $title = 'Untitled';
        foreach ($properties as $prop) {
            if ($prop['type'] === 'title' && !empty($prop['title'])) {
                $title = $prop['title'][0]['plain_text'] ?? 'Untitled';
                break;
            }
        }
        
        // 提取时间信息
        $created_time = $page['created_time'] ?? current_time('mysql');
        $last_edited_time = $page['last_edited_time'] ?? current_time('mysql');
        
        // 提取自定义字段
        $custom_fields = [];
        foreach ($properties as $key => $prop) {
            if ($prop['type'] === 'rich_text' && !empty($prop['rich_text'])) {
                $custom_fields['notion_' . sanitize_key($key)] = $prop['rich_text'][0]['plain_text'] ?? '';
            } elseif ($prop['type'] === 'select' && !empty($prop['select'])) {
                $custom_fields['notion_' . sanitize_key($key)] = $prop['select']['name'] ?? '';
            }
        }
        
        return [
            'title' => $title,
            'created_time' => $created_time,
            'last_edited_time' => $last_edited_time,
            'custom_fields' => $custom_fields,
            'status' => 'publish', // 可以基于Notion属性动态设置
            'post_type' => 'post'
        ];
    }
    
    /**
     * 获取导入统计信息
     *
     * @return array 统计数据
     */
    public function get_import_stats(): array {
        global $wpdb;
        
        $total_imported = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_notion_page_id'"
        );
        
        $recent_imports = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key = '_notion_sync_time' 
            AND meta_value > %s",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
        
        return [
            'total_imported' => (int) $total_imported,
            'recent_imports' => (int) $recent_imports,
            'last_import_time' => get_option('notion_last_import_time', ''),
            'import_status' => get_option('notion_import_status', 'idle')
        ];
    }
}
