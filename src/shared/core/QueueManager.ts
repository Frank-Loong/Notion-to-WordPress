/**
 * 队列管理器
 */

import { eventBus } from './EventBus';
import { appStateManager, QueueState } from './StateManager';
import { post } from '../utils/ajax';
import { showSuccess, showInfo } from '../utils/toast';

export interface QueueJob {
  id: string;
  type: string;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  data: any;
  created_at: number;
  started_at?: number;
  completed_at?: number;
  error?: string;
  retry_count: number;
  max_retries: number;
}

export interface QueueOperationResult {
  success: boolean;
  message: string;
  data?: any;
}

/**
 * 队列管理器类
 */
export class QueueManager {
  private statusCheckTimer: NodeJS.Timeout | null = null;
  private checkInterval = 10000; // 10秒
  private isMonitoring = false;

  constructor() {
    this.setupEventListeners();
    this.setupVisibilityHandling();
  }

  /**
   * 开始队列监控
   */
  startMonitoring(): void {
    if (this.isMonitoring) return;

    this.isMonitoring = true;
    this.checkQueueStatus();
    
    this.statusCheckTimer = setInterval(() => {
      this.checkQueueStatus();
    }, this.checkInterval);

    console.log('队列监控已开始');
  }

  /**
   * 停止队列监控
   */
  stopMonitoring(): void {
    if (!this.isMonitoring) return;

    this.isMonitoring = false;
    
    if (this.statusCheckTimer) {
      clearInterval(this.statusCheckTimer);
      this.statusCheckTimer = null;
    }

    console.log('队列监控已停止');
  }

  /**
   * 获取队列状态
   */
  async getQueueStatus(): Promise<QueueState | null> {
    try {
      const response = await post('notion_to_wordpress_get_queue_status', {});

      if (response.data.success) {
        return response.data.data;
      } else {
        console.error('获取队列状态失败:', response.data.message);
        return null;
      }
    } catch (error) {
      console.error('获取队列状态请求失败:', error);
      return null;
    }
  }

  /**
   * 获取队列任务列表
   */
  async getQueueJobs(limit = 50, offset = 0): Promise<QueueJob[]> {
    try {
      const response = await post('notion_to_wordpress_get_queue_jobs', {
        limit,
        offset
      });

      if (response.data.success) {
        return response.data.data;
      } else {
        console.error('获取队列任务失败:', response.data.message);
        return [];
      }
    } catch (error) {
      console.error('获取队列任务请求失败:', error);
      return [];
    }
  }

  /**
   * 暂停队列处理
   */
  async pauseQueue(): Promise<QueueOperationResult> {
    try {
      const response = await post('notion_to_wordpress_pause_queue', {});

      if (response.data.success) {
        showInfo('队列处理已暂停');
        this.checkQueueStatus(); // 立即更新状态
        
        return {
          success: true,
          message: '队列处理已暂停'
        };
      } else {
        return {
          success: false,
          message: response.data.message || '暂停队列失败'
        };
      }
    } catch (error) {
      return {
        success: false,
        message: (error as Error).message || '暂停队列请求失败'
      };
    }
  }

  /**
   * 恢复队列处理
   */
  async resumeQueue(): Promise<QueueOperationResult> {
    try {
      const response = await post('notion_to_wordpress_resume_queue', {});

      if (response.data.success) {
        showInfo('队列处理已恢复');
        this.checkQueueStatus(); // 立即更新状态
        
        return {
          success: true,
          message: '队列处理已恢复'
        };
      } else {
        return {
          success: false,
          message: response.data.message || '恢复队列失败'
        };
      }
    } catch (error) {
      return {
        success: false,
        message: (error as Error).message || '恢复队列请求失败'
      };
    }
  }

  /**
   * 清理队列
   */
  async cleanupQueue(options: {
    removeCompleted?: boolean;
    removeFailed?: boolean;
    olderThan?: number; // 小时
  } = {}): Promise<QueueOperationResult> {
    try {
      const response = await post('notion_to_wordpress_cleanup_queue', {
        remove_completed: options.removeCompleted || false,
        remove_failed: options.removeFailed || false,
        older_than: options.olderThan || 24
      });

      if (response.data.success) {
        const message = `队列清理完成，清理了 ${response.data.data.cleaned_count} 个任务`;
        showSuccess(message);
        this.checkQueueStatus(); // 立即更新状态
        
        return {
          success: true,
          message,
          data: response.data.data
        };
      } else {
        return {
          success: false,
          message: response.data.message || '清理队列失败'
        };
      }
    } catch (error) {
      return {
        success: false,
        message: (error as Error).message || '清理队列请求失败'
      };
    }
  }

  /**
   * 重试失败的任务
   */
  async retryFailedJobs(jobIds?: string[]): Promise<QueueOperationResult> {
    try {
      const response = await post('notion_to_wordpress_retry_failed_jobs', {
        job_ids: jobIds
      });

      if (response.data.success) {
        const message = `已重试 ${response.data.data.retried_count} 个失败任务`;
        showSuccess(message);
        this.checkQueueStatus(); // 立即更新状态
        
        return {
          success: true,
          message,
          data: response.data.data
        };
      } else {
        return {
          success: false,
          message: response.data.message || '重试失败任务失败'
        };
      }
    } catch (error) {
      return {
        success: false,
        message: (error as Error).message || '重试失败任务请求失败'
      };
    }
  }

  /**
   * 删除队列任务
   */
  async deleteJobs(jobIds: string[]): Promise<QueueOperationResult> {
    if (!jobIds.length) {
      return {
        success: false,
        message: '没有指定要删除的任务'
      };
    }

    try {
      const response = await post('notion_to_wordpress_delete_queue_jobs', {
        job_ids: jobIds
      });

      if (response.data.success) {
        const message = `已删除 ${response.data.data.deleted_count} 个任务`;
        showSuccess(message);
        this.checkQueueStatus(); // 立即更新状态
        
        return {
          success: true,
          message,
          data: response.data.data
        };
      } else {
        return {
          success: false,
          message: response.data.message || '删除任务失败'
        };
      }
    } catch (error) {
      return {
        success: false,
        message: (error as Error).message || '删除任务请求失败'
      };
    }
  }

  /**
   * 获取队列统计信息
   */
  async getQueueStats(): Promise<{
    total: number;
    pending: number;
    processing: number;
    completed: number;
    failed: number;
    success_rate: number;
    average_processing_time: number;
  } | null> {
    try {
      const response = await post('notion_to_wordpress_get_queue_stats', {});

      if (response.data.success) {
        return response.data.data;
      } else {
        console.error('获取队列统计失败:', response.data.message);
        return null;
      }
    } catch (error) {
      console.error('获取队列统计请求失败:', error);
      return null;
    }
  }

  /**
   * 检查队列状态
   */
  private async checkQueueStatus(): Promise<void> {
    try {
      const status = await this.getQueueStatus();
      if (status) {
        // 更新应用状态
        appStateManager.updateQueueStatus(status);
        
        // 发送队列状态更新事件
        eventBus.emit('queue:status:update', status);
      }
    } catch (error) {
      console.error('检查队列状态失败:', error);
    }
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 监听同步开始事件，自动开始队列监控
    eventBus.on('sync:start', () => {
      this.startMonitoring();
    });

    // 监听同步完成事件
    eventBus.on('sync:complete', () => {
      // 延迟检查队列状态
      setTimeout(() => {
        this.checkQueueStatus();
      }, 2000);
    });

    // 监听页面卸载
    window.addEventListener('beforeunload', () => {
      this.stopMonitoring();
    });
  }

  /**
   * 设置页面可见性处理
   */
  private setupVisibilityHandling(): void {
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        // 页面隐藏时降低检查频率
        this.checkInterval = 30000; // 30秒
      } else {
        // 页面可见时恢复正常频率
        this.checkInterval = 10000; // 10秒
        // 立即检查一次状态
        if (this.isMonitoring) {
          this.checkQueueStatus();
        }
      }

      // 重新设置定时器
      if (this.statusCheckTimer && this.isMonitoring) {
        this.restartMonitoring();
      }
    });
  }

  /**
   * 重启监控
   */
  private restartMonitoring(): void {
    if (this.isMonitoring) {
      this.stopMonitoring();
      this.startMonitoring();
    }
  }

  /**
   * 获取监控状态
   */
  isMonitoringActive(): boolean {
    return this.isMonitoring;
  }

  /**
   * 设置检查间隔
   */
  setCheckInterval(interval: number): void {
    this.checkInterval = Math.max(1000, interval); // 最小1秒
    
    if (this.isMonitoring) {
      this.restartMonitoring();
    }
  }
}

// 全局队列管理器实例
export const queueManager = new QueueManager();
