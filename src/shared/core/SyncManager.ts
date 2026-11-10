/**
 * 同步管理器
 *
 * 收口迭代（2026-11-10）：同步在单次长 AJAX 请求内完成，后端当前没有
 * stop/pause/resume/get_sync_status 这几个 action 的 handler，故移除原先
 * 依赖它们的一整套状态轮询与 SSE 连接代码，避免维护永不生效的死链。
 * 进度反馈改由事件驱动：sync:start / sync:complete / sync:error，
 * 由 admin.ts 统一驱动 PHP 直出的进度条 DOM。
 */

import { eventBus } from './EventBus';
import { appStateManager, SyncError } from './StateManager';
import { post } from '../utils/ajax';
import { showError, showSuccess } from '../utils/toast';

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

  constructor() {
    this.setupEventListeners();
  }

  /**
   * 开始同步
   */
  async startSync(options: SyncOptions): Promise<SyncResult> {
    if (this.isRunning()) {
      return {
        success: false,
        message: '已有同步任务在运行中',
      };
    }

    const syncId = this.generateSyncId();
    this.currentSyncId = syncId;

    // 更新状态并广播开始事件（后台进度条据此显示「正在同步」）。
    appStateManager.startSync(options.syncType, syncId);
    eventBus.emit('sync:start', { syncId, syncType: options.syncType });

    try {
      // 发送同步请求
      const response = await post(
        'notion_to_wordpress_manual_sync',
        {
          sync_type: options.syncType,
          task_id: syncId,
          incremental: options.incremental || false,
          check_deletions: options.checkDeletions || false,
          batch_size: options.batchSize || 10,
        },
        { timeout: 300000 }
      );

      if (response.data.success) {
        // 当前 PHP 处理器会在请求内完成同步并返回最终结果。
        appStateManager.completeSync();
        this.currentSyncId = null;
        showSuccess(response.data.data?.message || '同步已完成');
        eventBus.emit('sync:complete', { syncId });

        return {
          success: true,
          message: response.data.data?.message || '同步已完成',
          data: response.data.data,
        };
      } else {
        this.handleSyncError(
          new Error(response.data.message || '同步启动失败')
        );
        return {
          success: false,
          message: response.data.message || '同步启动失败',
        };
      }
    } catch (error) {
      this.handleSyncError(error as Error);
      return {
        success: false,
        message: (error as Error).message || '同步请求失败',
      };
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
        if (
          prevSyncState.status === 'running' ||
          prevSyncState.status === 'paused'
        ) {
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
   * 处理同步错误
   */
  private handleSyncError(error: Error): void {
    const syncError: SyncError = {
      id: `error_${Date.now()}`,
      message: error.message,
      code: 'SYNC_ERROR',
      timestamp: Date.now(),
    };

    appStateManager.syncError(syncError);
    showError(`同步失败: ${error.message}`);
    eventBus.emit('sync:error', {
      error: syncError,
      syncId: this.currentSyncId,
    });
  }

  /**
   * 清理资源
   */
  private cleanup(): void {
    this.currentSyncId = null;

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
