/**
 * 同步管理器
 */

import { eventBus } from './EventBus';
import { appStateManager, SyncState, SyncError } from './StateManager';
import { post } from '../utils/ajax';
import { showError, showSuccess, showInfo } from '../utils/toast';

export interface SyncOptions {
  syncType: string;
  incremental?: boolean;
  checkDeletions?: boolean;
  batchSize?: number;
}

export interface SyncResult {
  success: boolean;
  message: string;
  data?: any;
  errors?: SyncError[];
}

/**
 * 同步管理器类
 */
export class SyncManager {
  private currentSyncId: string | null = null;
  private statusCheckTimer: NodeJS.Timeout | null = null;
  private sseConnection: EventSource | null = null;
  private checkInterval = 5000; // 5秒
  private maxRetries = 3;
  private retryCount = 0;

  constructor() {
    this.setupEventListeners();
    this.setupVisibilityHandling();
  }

  /**
   * 开始同步
   */
  async startSync(options: SyncOptions): Promise<SyncResult> {
    if (this.isRunning()) {
      return {
        success: false,
        message: '已有同步任务在运行中'
      };
    }

    const syncId = this.generateSyncId();
    this.currentSyncId = syncId;

    // 更新状态
    appStateManager.startSync(options.syncType, syncId);

    try {
      // 发送同步请求
      const response = await post('notion_to_wordpress_sync', {
        sync_type: options.syncType,
        sync_id: syncId,
        incremental: options.incremental || false,
        check_deletions: options.checkDeletions || false,
        batch_size: options.batchSize || 10
      });

      if (response.data.success) {
        // 开始状态监控
        this.startStatusMonitoring();
        
        // 尝试建立SSE连接
        this.setupSSEConnection(syncId);

        showInfo(`${options.syncType}已开始`);

        return {
          success: true,
          message: '同步已开始',
          data: response.data.data
        };
      } else {
        this.handleSyncError(new Error(response.data.message || '同步启动失败'));
        return {
          success: false,
          message: response.data.message || '同步启动失败'
        };
      }
    } catch (error) {
      this.handleSyncError(error as Error);
      return {
        success: false,
        message: (error as Error).message || '同步请求失败'
      };
    }
  }

  /**
   * 停止同步
   */
  async stopSync(): Promise<SyncResult> {
    if (!this.currentSyncId) {
      return {
        success: false,
        message: '没有正在运行的同步任务'
      };
    }

    try {
      const response = await post('notion_to_wordpress_stop_sync', {
        sync_id: this.currentSyncId
      });

      if (response.data.success) {
        this.cleanup();
        showInfo('同步已停止');
        
        return {
          success: true,
          message: '同步已停止'
        };
      } else {
        return {
          success: false,
          message: response.data.message || '停止同步失败'
        };
      }
    } catch (error) {
      return {
        success: false,
        message: (error as Error).message || '停止同步请求失败'
      };
    }
  }

  /**
   * 暂停同步
   */
  async pauseSync(): Promise<SyncResult> {
    if (!this.currentSyncId) {
      return {
        success: false,
        message: '没有正在运行的同步任务'
      };
    }

    try {
      const response = await post('notion_to_wordpress_pause_sync', {
        sync_id: this.currentSyncId
      });

      if (response.data.success) {
        appStateManager.setState({
          sync: {
            ...appStateManager.getState().sync,
            status: 'paused'
          }
        });

        showInfo('同步已暂停');
        
        return {
          success: true,
          message: '同步已暂停'
        };
      } else {
        return {
          success: false,
          message: response.data.message || '暂停同步失败'
        };
      }
    } catch (error) {
      return {
        success: false,
        message: (error as Error).message || '暂停同步请求失败'
      };
    }
  }

  /**
   * 恢复同步
   */
  async resumeSync(): Promise<SyncResult> {
    if (!this.currentSyncId) {
      return {
        success: false,
        message: '没有暂停的同步任务'
      };
    }

    try {
      const response = await post('notion_to_wordpress_resume_sync', {
        sync_id: this.currentSyncId
      });

      if (response.data.success) {
        appStateManager.setState({
          sync: {
            ...appStateManager.getState().sync,
            status: 'running'
          }
        });

        showInfo('同步已恢复');
        
        return {
          success: true,
          message: '同步已恢复'
        };
      } else {
        return {
          success: false,
          message: response.data.message || '恢复同步失败'
        };
      }
    } catch (error) {
      return {
        success: false,
        message: (error as Error).message || '恢复同步请求失败'
      };
    }
  }

  /**
   * 获取同步状态
   */
  async getSyncStatus(): Promise<SyncState | null> {
    if (!this.currentSyncId) {
      return null;
    }

    try {
      const response = await post('notion_to_wordpress_get_sync_status', {
        sync_id: this.currentSyncId
      });

      if (response.data.success) {
        return response.data.data;
      } else {
        console.error('获取同步状态失败:', response.data.message);
        return null;
      }
    } catch (error) {
      console.error('获取同步状态请求失败:', error);
      return null;
    }
  }

  /**
   * 检查是否有同步在运行
   */
  isRunning(): boolean {
    const state = appStateManager.getState().sync;
    return state.status === 'running' || state.status === 'paused';
  }

  /**
   * 获取当前同步ID
   */
  getCurrentSyncId(): string | null {
    return this.currentSyncId;
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 监听状态变化
    appStateManager.subscribe((state, prevState) => {
      const syncState = state.sync;
      const prevSyncState = prevState.sync;

      // 同步完成或错误时清理
      if (syncState.status === 'completed' || syncState.status === 'error') {
        if (prevSyncState.status === 'running' || prevSyncState.status === 'paused') {
          setTimeout(() => {
            this.cleanup();
          }, 2000); // 2秒后清理
        }
      }
    });

    // 监听页面卸载
    window.addEventListener('beforeunload', () => {
      this.cleanup();
    });
  }

  /**
   * 设置页面可见性处理
   */
  private setupVisibilityHandling(): void {
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        // 页面隐藏时降低检查频率
        this.checkInterval = 15000; // 15秒
      } else {
        // 页面可见时恢复正常频率
        this.checkInterval = 5000; // 5秒
        // 立即检查一次状态
        if (this.currentSyncId) {
          this.checkSyncStatus();
        }
      }

      // 重新设置定时器
      if (this.statusCheckTimer) {
        this.restartStatusMonitoring();
      }
    });
  }

  /**
   * 开始状态监控
   */
  private startStatusMonitoring(): void {
    this.stopStatusMonitoring();
    
    this.statusCheckTimer = setInterval(() => {
      this.checkSyncStatus();
    }, this.checkInterval);

    console.log(`开始状态监控，检查间隔: ${this.checkInterval}ms`);
  }

  /**
   * 停止状态监控
   */
  private stopStatusMonitoring(): void {
    if (this.statusCheckTimer) {
      clearInterval(this.statusCheckTimer);
      this.statusCheckTimer = null;
    }
  }

  /**
   * 重启状态监控
   */
  private restartStatusMonitoring(): void {
    this.stopStatusMonitoring();
    this.startStatusMonitoring();
  }

  /**
   * 检查同步状态
   */
  private async checkSyncStatus(): Promise<void> {
    if (!this.currentSyncId) {
      this.stopStatusMonitoring();
      return;
    }

    try {
      const status = await this.getSyncStatus();
      if (status) {
        // 更新状态
        appStateManager.setState({ sync: status });

        // 检查是否完成
        if (status.status === 'completed') {
          this.handleSyncComplete();
        } else if (status.status === 'error') {
          this.handleSyncError(new Error('同步过程中发生错误'));
        }

        this.retryCount = 0; // 重置重试计数
      } else {
        this.handleStatusCheckError();
      }
    } catch (error) {
      this.handleStatusCheckError();
    }
  }

  /**
   * 处理状态检查错误
   */
  private handleStatusCheckError(): void {
    this.retryCount++;
    
    if (this.retryCount >= this.maxRetries) {
      console.error('状态检查失败次数过多，停止监控');
      this.stopStatusMonitoring();
      this.retryCount = 0;
    }
  }

  /**
   * 设置SSE连接
   */
  private setupSSEConnection(syncId: string): void {
    if (!window.EventSource) {
      console.warn('浏览器不支持SSE，使用轮询方式');
      return;
    }

    try {
      const sseUrl = `${window.ajaxurl}?action=notion_to_wordpress_sync_sse&sync_id=${syncId}&nonce=${window.notionToWp?.nonce}`;
      this.sseConnection = new EventSource(sseUrl);

      this.sseConnection.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data);
          this.handleSSEMessage(data);
        } catch (error) {
          console.error('SSE消息解析失败:', error);
        }
      };

      this.sseConnection.onerror = (error) => {
        console.error('SSE连接错误:', error);
        this.closeSSEConnection();
      };

      console.log('SSE连接已建立');
    } catch (error) {
      console.error('建立SSE连接失败:', error);
    }
  }

  /**
   * 处理SSE消息
   */
  private handleSSEMessage(data: any): void {
    switch (data.type) {
      case 'progress':
        appStateManager.updateSyncProgress(data.progress, data.total, data.current_item);
        break;
      case 'complete':
        this.handleSyncComplete();
        break;
      case 'error':
        this.handleSyncError(new Error(data.message));
        break;
      default:
        console.log('未知SSE消息类型:', data.type);
    }
  }

  /**
   * 关闭SSE连接
   */
  private closeSSEConnection(): void {
    if (this.sseConnection) {
      this.sseConnection.close();
      this.sseConnection = null;
      console.log('SSE连接已关闭');
    }
  }

  /**
   * 处理同步完成
   */
  private handleSyncComplete(): void {
    appStateManager.completeSync();
    showSuccess('同步完成');
    eventBus.emit('sync:complete', { syncId: this.currentSyncId });
  }

  /**
   * 处理同步错误
   */
  private handleSyncError(error: Error): void {
    const syncError: SyncError = {
      id: `error_${Date.now()}`,
      message: error.message,
      code: 'SYNC_ERROR',
      timestamp: Date.now()
    };

    appStateManager.syncError(syncError);
    showError(`同步失败: ${error.message}`);
    eventBus.emit('sync:error', { error: syncError, syncId: this.currentSyncId });
  }

  /**
   * 清理资源
   */
  private cleanup(): void {
    this.stopStatusMonitoring();
    this.closeSSEConnection();
    this.currentSyncId = null;
    this.retryCount = 0;
    
    // 清理存储的状态
    appStateManager.clearStoredState();
    
    console.log('同步管理器已清理');
  }

  /**
   * 生成同步ID
   */
  private generateSyncId(): string {
    return `sync_${Date.now()}_${Math.random().toString(36).substring(2, 11)}`;
  }
}

// 全局同步管理器实例
export const syncManager = new SyncManager();
