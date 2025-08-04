<?php
declare(strict_types=1);

namespace NTWP\Services\Import;

use NTWP\Services\{SyncService, ContentProcessingService, ApiService};
use NTWP\Core\{Logger, ProgressTracker, EventDispatcher};
use NTWP\Infrastructure\CacheManager;

/**
 * 导入工作流 - 重构自ImportHandler
 *
 * 设计模式: 工作流模式 + 依赖注入
 * 职责: 编排导入流程，不处理具体业务逻辑
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
class ImportWorkflow {
    
    private SyncService $sync_service;
    private ContentProcessingService $content_service;
    private ApiService $api_service;
    private ProgressTracker $progress;
    private EventDispatcher $events;
    private CacheManager $cache;
    
    public function __construct(
        SyncService $sync_service,
        ContentProcessingService $content_service,
        ApiService $api_service,
        ProgressTracker $progress,
        EventDispatcher $events,
        CacheManager $cache
    ) {
        $this->sync_service = $sync_service;
        $this->content_service = $content_service;
        $this->api_service = $api_service;
        $this->progress = $progress;
        $this->events = $events;
        $this->cache = $cache;
    }
    
    /**
     * 执行导入工作流
     * 
     * @param ImportConfig $config 导入配置
     * @return WorkflowResult 执行结果
     */
    public function execute(ImportConfig $config): WorkflowResult {
        $workflow_id = $this->generate_workflow_id();
        
        try {
            // 初始化进度跟踪
            $this->progress->start($workflow_id, [
                'total_steps' => 5,
                'description' => '导入Notion数据到WordPress'
            ]);
            
            // 步骤1: 配置验证
            $this->progress->update($workflow_id, 1, '验证导入配置...');
            $this->validate_config($config);
            $this->events->dispatch('import.config_validated', ['config' => $config]);
            
            // 步骤2: 获取源数据
            $this->progress->update($workflow_id, 2, '获取Notion数据...');
            $pages = $this->fetch_notion_data($config);
            $this->events->dispatch('import.data_fetched', ['page_count' => count($pages)]);
            
            // 步骤3: 内容处理
            $this->progress->update($workflow_id, 3, '处理内容格式...');
            $processed_pages = $this->process_content($pages, $config);
            $this->events->dispatch('import.content_processed', ['processed_count' => count($processed_pages)]);
            
            // 步骤4: 保存到WordPress
            $this->progress->update($workflow_id, 4, '保存到WordPress...');
            $import_results = $this->save_to_wordpress($processed_pages, $config);
            $this->events->dispatch('import.data_saved', ['results' => $import_results]);
            
            // 步骤5: 完成清理
            $this->progress->update($workflow_id, 5, '完成导入...');
            $this->cleanup_import($workflow_id, $config);
            
            $result = new WorkflowResult(
                success: true,
                workflow_id: $workflow_id,
                imported_count: $import_results['success_count'],
                failed_count: $import_results['failed_count'],
                execution_time: microtime(true) - $this->progress->get_start_time($workflow_id),
                details: $import_results
            );
            
            $this->progress->complete($workflow_id, $result->to_array());
            $this->events->dispatch('import.completed', ['result' => $result]);
            
            return $result;
            
        } catch (\Exception $e) {
            $error_result = new WorkflowResult(
                success: false,
                workflow_id: $workflow_id,
                error: $e->getMessage(),
                execution_time: microtime(true) - $this->progress->get_start_time($workflow_id)
            );
            
            $this->progress->fail($workflow_id, $e->getMessage());
            $this->events->dispatch('import.failed', ['error' => $e->getMessage()]);
            
            Logger::error_log('导入工作流失败: ' . $e->getMessage(), 'ImportWorkflow');
            
            throw new ImportWorkflowException('导入失败: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * 验证导入配置
     */
    private function validate_config(ImportConfig $config): void {
        if (empty($config->database_id)) {
            throw new \InvalidArgumentException('数据库ID不能为空');
        }
        
        if (empty($config->post_type)) {
            $config->post_type = 'post';
        }
        
        if (!post_type_exists($config->post_type)) {
            throw new \InvalidArgumentException("文章类型 '{$config->post_type}' 不存在");
        }
        
        if (!empty($config->field_mapping)) {
            $this->validate_field_mapping($config->field_mapping);
        }
        
        Logger::debug_log('导入配置验证通过', 'ImportWorkflow');
    }
    
    /**
     * 获取Notion数据
     */
    private function fetch_notion_data(ImportConfig $config): array {
        // 检查缓存
        $cache_key = "notion_data_{$config->database_id}";
        $cached_data = $this->cache->get($cache_key, 'api_response');
        
        if ($cached_data !== null && !$config->force_refresh) {
            Logger::debug_log('使用缓存的Notion数据', 'ImportWorkflow');
            return $cached_data;
        }
        
        // 获取新数据
        $pages = $this->api_service->fetch_database_pages($config->database_id, [
            'filter' => $config->filter ?? [],
            'sorts' => $config->sorts ?? [],
            'page_size' => $config->batch_size ?? 100
        ]);
        
        // 缓存数据
        $this->cache->set($cache_key, $pages, 300, 'api_response');
        
        Logger::debug_log(sprintf('获取到 %d 个Notion页面', count($pages)), 'ImportWorkflow');
        
        return $pages;
    }
    
    /**
     * 处理内容
     */
    private function process_content(array $pages, ImportConfig $config): array {
        $processed_pages = [];
        
        foreach ($pages as $index => $page) {
            try {
                // 转换内容格式
                $html_content = $this->content_service->convert_blocks_to_html($page['blocks'] ?? []);
                
                // 提取元数据
                $metadata = $this->content_service->extract_metadata($page, $config->field_mapping ?? []);
                
                // 处理图片
                if ($config->download_images) {
                    $html_content = $this->content_service->process_images($html_content);
                }
                
                $processed_pages[] = [
                    'notion_id' => $page['id'],
                    'title' => $metadata['title'] ?? '无标题',
                    'content' => $html_content,
                    'metadata' => $metadata,
                    'status' => $this->determine_post_status($page, $config),
                    'post_type' => $config->post_type,
                ];
                
            } catch (\Exception $e) {
                Logger::error_log(
                    sprintf('处理页面失败 [%s]: %s', $page['id'] ?? 'unknown', $e->getMessage()),
                    'ImportWorkflow'
                );
                
                $processed_pages[] = [
                    'notion_id' => $page['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'failed' => true
                ];
            }
        }
        
        return $processed_pages;
    }
    
    /**
     * 保存到WordPress
     */
    private function save_to_wordpress(array $processed_pages, ImportConfig $config): array {
        $results = [
            'success_count' => 0,
            'failed_count' => 0,
            'updated_count' => 0,
            'created_count' => 0,
            'details' => []
        ];
        
        foreach ($processed_pages as $page_data) {
            if (isset($page_data['failed'])) {
                $results['failed_count']++;
                $results['details'][] = [
                    'notion_id' => $page_data['notion_id'],
                    'status' => 'failed',
                    'error' => $page_data['error']
                ];
                continue;
            }
            
            try {
                $save_result = $this->sync_service->save_to_wordpress($page_data, $config);
                
                if ($save_result['success']) {
                    $results['success_count']++;
                    if ($save_result['is_update']) {
                        $results['updated_count']++;
                    } else {
                        $results['created_count']++;
                    }
                } else {
                    $results['failed_count']++;
                }
                
                $results['details'][] = $save_result;
                
            } catch (\Exception $e) {
                $results['failed_count']++;
                $results['details'][] = [
                    'notion_id' => $page_data['notion_id'],
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
                
                Logger::error_log(
                    sprintf('保存页面失败 [%s]: %s', $page_data['notion_id'], $e->getMessage()),
                    'ImportWorkflow'
                );
            }
        }
        
        return $results;
    }
    
    /**
     * 清理导入过程
     */
    private function cleanup_import(string $workflow_id, ImportConfig $config): void {
        // 清理临时文件
        if ($config->cleanup_temp_files) {
            $this->clean_temp_files($workflow_id);
        }
        
        // 清理过期缓存
        $this->cache->invalidate_pattern("import_temp_{$workflow_id}_*");
        
        // 触发清理钩子
        do_action('ntwp_import_cleanup', $workflow_id, $config);
        
        Logger::debug_log('导入清理完成', 'ImportWorkflow');
    }
    
    // === 辅助方法 ===
    
    private function generate_workflow_id(): string {
        return 'import_' . date('Y-m-d_H-i-s') . '_' . wp_generate_uuid4();
    }
    
    private function validate_field_mapping(array $field_mapping): void {
        foreach ($field_mapping as $notion_field => $wp_field) {
            if (empty($notion_field) || empty($wp_field)) {
                throw new \InvalidArgumentException('字段映射不能包含空值');
            }
        }
    }
    
    private function determine_post_status(array $page, ImportConfig $config): string {
        $notion_status = $page['properties']['Status']['select']['name'] ?? '';
        
        return match($notion_status) {
            'Published' => 'publish',
            'Draft' => 'draft',
            'Private' => 'private',
            default => $config->default_post_status ?? 'draft'
        };
    }
    
    private function clean_temp_files(string $workflow_id): void {
        $temp_dir = wp_upload_dir()['basedir'] . "/notion-import-temp/{$workflow_id}";
        if (is_dir($temp_dir)) {
            $files = glob($temp_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($temp_dir);
        }
    }
}

/**
 * 导入配置类
 */
class ImportConfig {
    public function __construct(
        public string $database_id,
        public string $post_type = 'post',
        public array $field_mapping = [],
        public array $filter = [],
        public array $sorts = [],
        public int $batch_size = 100,
        public bool $download_images = true,
        public bool $force_refresh = false,
        public bool $cleanup_temp_files = true,
        public string $default_post_status = 'draft'
    ) {}
}

/**
 * 工作流结果类
 */
class WorkflowResult {
    public function __construct(
        public bool $success,
        public string $workflow_id,
        public int $imported_count = 0,
        public int $failed_count = 0,
        public float $execution_time = 0,
        public ?string $error = null,
        public array $details = []
    ) {}
    
    public function to_array(): array {
        return [
            'success' => $this->success,
            'workflow_id' => $this->workflow_id,
            'imported_count' => $this->imported_count,
            'failed_count' => $this->failed_count,
            'execution_time' => $this->execution_time,
            'error' => $this->error,
            'details' => $this->details
        ];
    }
}

/**
 * 导入工作流异常类
 */
class ImportWorkflowException extends \Exception {}