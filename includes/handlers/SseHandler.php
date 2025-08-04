<?php
declare(strict_types=1);

namespace NTWP\Handlers;

use NTWP\Core\Foundation\Logger;

/**
 * SSE Progress Stream Handler
 * 
 * 实现Server-Sent Events的实时进度推送
 * 替代传统的AJAX轮询，提供更高性能的实时通信
 * 
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SSE Progress Stream Class
 * 
 * 处理Server-Sent Events的进度流推送
 * 使用WordPress transient API进行内存存储，避免文件I/O
 */
class SseHandler {
    
    /**
     * 推送间隔（微秒）
     * 200ms = 200000微秒，提供近实时体验
     */
    private const PUSH_INTERVAL = 200000;
    
    /**
     * 最大连接时间（秒）
     * 防止长时间连接占用资源
     */
    private const MAX_CONNECTION_TIME = 300;
    
    /**
     * 进度数据缓存前缀
     */
    private const CACHE_PREFIX = 'ntwp_progress_';
    
    /**
     * 开始SSE进度流
     * 
     * @param string $task_id 任务ID
     * @return void
     */
    public function stream_progress(string $task_id): void {
        // 验证任务ID
        if (empty($task_id) || !$this->is_valid_task_id($task_id)) {
            $this->send_error('Invalid task ID');
            return;
        }
        
        // 设置SSE响应头
        $this->set_sse_headers();
        
        // 记录开始时间
        $start_time = time();
        
        Logger::info_log("SSE进度流开始: {$task_id}", 'SSE Stream');
        
        try {
            // 发送初始连接确认
            $this->send_event('connected', [
                'task_id' => $task_id,
                'timestamp' => time(),
                'message' => '进度流已连接'
            ]);
            
            // 主循环：持续推送进度数据
            while (connection_aborted() == 0) {
                // 检查连接时间限制
                if (time() - $start_time > self::MAX_CONNECTION_TIME) {
                    $this->send_event('timeout', ['message' => '连接超时']);
                    break;
                }
                
                // 获取进度数据
                $progress = $this->get_progress_data($task_id);
                
                if ($progress) {
                    // 发送进度更新
                    $this->send_event('progress', $progress);
                    
                    // 如果任务完成，结束流
                    if (isset($progress['status']) && $progress['status'] === 'completed') {
                        $this->send_event('completed', [
                            'message' => '任务已完成',
                            'final_progress' => $progress
                        ]);
                        break;
                    }
                    
                    // 如果任务失败，结束流
                    if (isset($progress['status']) && $progress['status'] === 'failed') {
                        $this->send_event('failed', [
                            'message' => '任务执行失败',
                            'error_progress' => $progress
                        ]);
                        break;
                    }
                } else {
                    // 任务不存在或已过期
                    $this->send_event('not_found', [
                        'message' => '任务不存在或已过期',
                        'task_id' => $task_id
                    ]);
                }
                
                // 刷新输出缓冲区
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                
                // 等待下次推送
                usleep(self::PUSH_INTERVAL);
            }
            
        } catch (\Exception $e) {
            Logger::error_log("SSE流异常: " . $e->getMessage(), 'SSE Stream');
            $this->send_event('error', [
                'message' => '服务器内部错误',
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 设置SSE响应头
     * 
     * @return void
     */
    private function set_sse_headers(): void {
        // 禁用输出缓冲
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // 设置SSE必需的响应头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // 禁用Nginx缓冲
        
        // CORS支持（如果需要）
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Cache-Control');
    }
    
    /**
     * 发送SSE事件
     * 
     * @param string $event 事件类型
     * @param array $data 事件数据
     * @return void
     */
    private function send_event(string $event, array $data): void {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    }
    
    /**
     * 发送错误事件并结束连接
     * 
     * @param string $message 错误消息
     * @return void
     */
    private function send_error(string $message): void {
        $this->set_sse_headers();
        $this->send_event('error', ['message' => $message]);
        exit;
    }
    
    /**
     * 从缓存获取进度数据
     * 
     * @param string $task_id 任务ID
     * @return array|null 进度数据或null
     */
    private function get_progress_data(string $task_id): ?array {
        $cache_key = self::CACHE_PREFIX . $task_id;
        $progress = get_transient($cache_key);
        
        return $progress !== false ? $progress : null;
    }
    
    /**
     * 验证任务ID格式
     * 
     * @param string $task_id 任务ID
     * @return bool 是否有效
     */
    private function is_valid_task_id(string $task_id): bool {
        // 检查任务ID格式：sync_timestamp_randomstring
        return preg_match('/^sync_\d+_[a-z0-9]+$/', $task_id) === 1;
    }
    
    /**
     * 清理过期的进度数据
     * 
     * 定期调用此方法清理过期的transient数据
     * 
     * @return int 清理的数据条数
     */
    public static function cleanup_expired_progress(): int {
        global $wpdb;
        
        $cleaned = 0;
        
        try {
            // 查找所有进度相关的transient
            $transients = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} 
                     WHERE option_name LIKE %s 
                     AND option_name NOT LIKE %s",
                    '_transient_' . self::CACHE_PREFIX . '%',
                    '_transient_timeout_' . self::CACHE_PREFIX . '%'
                )
            );
            
            foreach ($transients as $transient) {
                $key = str_replace('_transient_', '', $transient->option_name);
                if (get_transient($key) === false) {
                    delete_transient($key);
                    $cleaned++;
                }
            }
            
            Logger::info_log("清理了 {$cleaned} 个过期的进度数据", 'SSE Cleanup');
            
        } catch (\Exception $e) {
            Logger::error_log("清理进度数据时出错: " . $e->getMessage(), 'SSE Cleanup');
        }
        
        return $cleaned;
    }
}
