<?php
declare(strict_types=1);

namespace NTWP\Services;

/**
 * 任务管理服务
 * 
 * 分离数据访问层和业务逻辑层，提供统一的服务接口
 * 实现业务逻辑的封装和复用
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

/**
 * 任务管理服务
 */
class Task_Service extends Notion_Abstract_Service {
    
    /**
     * 任务调度器
     * @var Notion_Async_Task_Scheduler
     */
    private $scheduler;
    
    /**
     * 并发管理器
     * @var Notion_Unified_Concurrency_Manager
     */
    private $concurrency;
    
    /**
     * 初始化服务
     */
    public function init(): void {
        if ($this->initialized) {
            return;
        }
        
        $this->scheduler = Notion_Dependency_Container::get('scheduler');
        $this->concurrency = Notion_Dependency_Container::get('concurrency');
        
        $this->initialized = true;
    }
    
    /**
     * 创建同步任务
     *
     * @param array $task_data 任务数据
     * @param array $options 任务选项
     * @return string|false 任务ID或false
     */
    public function create_sync_task(array $task_data, array $options = []) {
        $this->init();
        
        // 智能调度任务
        return $this->scheduler->smart_schedule(
            Notion_Async_Task_Scheduler::TASK_INCREMENTAL_SYNC,
            $task_data,
            $options
        );
    }
    
    /**
     * 创建批量导入任务
     *
     * @param array $pages_data 页面数据
     * @param array $options 任务选项
     * @return string|false 任务ID或false
     */
    public function create_import_task(array $pages_data, array $options = []) {
        $this->init();
        
        // 检查并发限制
        $batch_info = $this->concurrency->manage_batch_tasks('request', count($pages_data));
        
        if ($batch_info['recommendation'] === 'consider_splitting') {
            // 分割大任务
            return $this->create_split_import_tasks($pages_data, $options, $batch_info);
        }
        
        return $this->scheduler->smart_schedule(
            Notion_Async_Task_Scheduler::TASK_BATCH_IMPORT,
            ['pages' => $pages_data],
            $options
        );
    }
    
    /**
     * 创建分割的导入任务
     *
     * @param array $pages_data 页面数据
     * @param array $options 选项
     * @param array $batch_info 批次信息
     * @return array 任务ID数组
     */
    private function create_split_import_tasks(array $pages_data, array $options, array $batch_info): array {
        $task_ids = [];
        $chunks = array_chunk($pages_data, $batch_info['batch_size']);
        
        foreach ($chunks as $chunk) {
            $task_id = $this->scheduler->smart_schedule(
                Notion_Async_Task_Scheduler::TASK_BATCH_IMPORT,
                ['pages' => $chunk],
                $options
            );
            
            if ($task_id) {
                $task_ids[] = $task_id;
            }
        }
        
        return $task_ids;
    }
}
