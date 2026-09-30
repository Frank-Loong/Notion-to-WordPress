/**
 * 同步状态管理器 - 现代化TypeScript版本
 *
 * 从原有admin-interactions.js的SyncStatusManager完全迁移，包括：
 * - 同步状态持久化
 * - 页面可见性处理
 * - 自动状态检查
 * - 状态恢复提示
 */

import { emit } from '../../shared/core/EventBus';
import { AdminUtils } from '../utils/AdminUtils';
import { showError } from '../../shared/utils/toast';
import { post } from '../../shared/utils/ajax';

export interface SyncStatusData {
  isActive: boolean;
  syncType: string;
  startTime: number;
  syncId: string;
  [key: string]: any;
}

export interface AsyncStatusData {
  status: 'idle' | 'running' | 'paused' | 'error';
  operation?: string;
  progress?: number;
  message?: string;
}

export interface QueueStatusData {
  pending: number;
  processing: number;
  completed: number;
  failed: number;
}

/**
 * 同步状态管理器类
 */
export class SyncStatusManager {
  private static instance: SyncStatusManager | null = null;

  // 配置常量
  private readonly STORAGE_KEY = 'notion_wp_sync_status';
  private readonly CHECK_INTERVAL_VISIBLE = 5000; // 页面可见时：5秒
  private readonly CHECK_INTERVAL_HIDDEN = 30000; // 页面隐藏时：30秒
  private readonly STATUS_EXPIRE_TIME = 3600000; // 1小时过期

  // 内部状态
  private checkTimer: NodeJS.Timeout | null = null;
  private isPageVisible = true;
  private currentSyncId: string | null = null;

  constructor() {
    if (SyncStatusManager.instance) {
      return SyncStatusManager.instance;
    }
    SyncStatusManager.instance = this;
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(): SyncStatusManager {
    if (!SyncStatusManager.instance) {
      SyncStatusManager.instance = new SyncStatusManager();
    }
    return SyncStatusManager.instance;
  }

  /**
   * 初始化同步状态管理器
   */
  private init(): void {
    this.setupVisibilityHandling();
    this.restoreSyncStatus();
    this.startStatusMonitoring();

    console.log('🔄 [同步状态管理器] 已初始化');
  }

  /**
   * 设置页面可见性处理
   */
  private setupVisibilityHandling(): void {
    // 监听页面可见性变化
    document.addEventListener('visibilitychange', () => {
      this.isPageVisible = !document.hidden;

      if (this.isPageVisible) {
        console.log('📱 [页面可见性] 页面重新可见，立即检查同步状态');
        this.checkSyncStatus();
        this.adjustCheckInterval();
      } else {
        console.log('📱 [页面可见性] 页面隐藏，降低检查频率');
        this.adjustCheckInterval();
      }
    });

    // 监听页面焦点变化
    window.addEventListener('focus', () => {
      console.log('🎯 [页面焦点] 页面重新获得焦点，检查同步状态');
      this.checkSyncStatus();
    });
  }

  /**
   * 保存同步状态
   */
  saveSyncStatus(syncData: Partial<SyncStatusData>): void {
    const statusData: SyncStatusData = {
      isActive: true,
      syncType: syncData.syncType || 'unknown',
      startTime: Date.now(),
      syncId: this.generateSyncId(),
      ...syncData,
    };

    this.currentSyncId = statusData.syncId;
    localStorage.setItem(this.STORAGE_KEY, JSON.stringify(statusData));

    console.log('💾 [状态保存] 同步状态已保存:', statusData);
    emit('sync:status:saved', statusData);
  }

  /**
   * 清除同步状态
   */
  clearSyncStatus(): void {
    localStorage.removeItem(this.STORAGE_KEY);
    this.currentSyncId = null;

    console.log('🗑️ [状态清除] 同步状态已清除');
    emit('sync:status:cleared');
  }

  /**
   * 恢复同步状态
   */
  private restoreSyncStatus(): void {
    const savedStatus = localStorage.getItem(this.STORAGE_KEY);

    if (savedStatus) {
      try {
        const statusData = AdminUtils.safeJsonParse<SyncStatusData>(
          savedStatus,
          {} as SyncStatusData
        );

        // 检查状态是否过期
        const elapsed = Date.now() - statusData.startTime;
        if (elapsed > this.STATUS_EXPIRE_TIME) {
          this.clearSyncStatus();
          return;
        }

        console.log('🔄 [状态恢复] 发现保存的同步状态:', statusData);
        this.currentSyncId = statusData.syncId;
        this.showSyncStatusRecovery(statusData);
      } catch (error) {
        console.error('❌ [状态恢复] 解析保存状态失败:', error);
        this.clearSyncStatus();
      }
    }
  }

  /**
   * 显示同步状态恢复提示
   */
  private showSyncStatusRecovery(statusData: SyncStatusData): void {
    const elapsed = Math.floor((Date.now() - statusData.startTime) / 1000);
    const elapsedText =
      elapsed < 60
        ? `${elapsed}秒`
        : `${Math.floor(elapsed / 60)}分${elapsed % 60}秒`;

    // 创建恢复提示元素
    const recoveryNotice = document.createElement('div');
    recoveryNotice.className = 'notice notice-info is-dismissible';
    recoveryNotice.id = 'sync-status-recovery';
    recoveryNotice.innerHTML = `
      <p>
        <strong>🔄 检测到进行中的同步操作</strong><br>
        同步类型：${statusData.syncType || '未知'}<br>
        已运行：${elapsedText}<br>
        <button type="button" class="button button-secondary" id="check-sync-status-now">立即检查状态</button>
        <button type="button" class="button button-link" id="clear-sync-status">清除状态</button>
      </p>
    `;

    // 插入到页面顶部
    const adminWrap = document.querySelector('.wrap.notion-wp-admin');
    if (adminWrap) {
      adminWrap.insertBefore(recoveryNotice, adminWrap.firstChild);
    }

    // 绑定事件
    const checkButton = document.getElementById('check-sync-status-now');
    const clearButton = document.getElementById('clear-sync-status');

    if (checkButton) {
      checkButton.addEventListener('click', () => {
        this.checkSyncStatus();
        recoveryNotice.style.display = 'none';
      });
    }

    if (clearButton) {
      clearButton.addEventListener('click', () => {
        this.clearSyncStatus();
        recoveryNotice.style.display = 'none';
      });
    }

    emit('sync:status:recovery:shown', statusData);
  }

  /**
   * 生成同步ID
   */
  private generateSyncId(): string {
    return AdminUtils.generateId('sync');
  }

  /**
   * 调整检查间隔
   */
  private adjustCheckInterval(): void {
    if (this.checkTimer) {
      clearInterval(this.checkTimer);
    }

    const interval = this.isPageVisible
      ? this.CHECK_INTERVAL_VISIBLE
      : this.CHECK_INTERVAL_HIDDEN;

    this.checkTimer = setInterval(() => {
      this.checkSyncStatus();
    }, interval);

    console.log(
      `⏱️ [检查间隔] 已调整为 ${interval / 1000}秒 (页面${this.isPageVisible ? '可见' : '隐藏'})`
    );
  }

  /**
   * 开始状态监控
   */
  startStatusMonitoring(): void {
    this.adjustCheckInterval();
    emit('sync:monitoring:started');
  }

  /**
   * 停止状态监控
   */
  stopStatusMonitoring(): void {
    if (this.checkTimer) {
      clearInterval(this.checkTimer);
      this.checkTimer = null;
    }
    emit('sync:monitoring:stopped');
  }

  /**
   * 检查同步状态
   */
  async checkSyncStatus(): Promise<void> {
    // 如果没有活跃的同步，跳过检查
    if (!this.currentSyncId) {
      return;
    }

    console.log('🔍 [状态检查] 正在检查同步状态...');

    try {
      await this.refreshAsyncStatus();
      await this.refreshQueueStatus();
    } catch (error) {
      console.error('❌ [状态检查] 检查失败:', error);
    }
  }

  /**
   * 刷新异步状态
   */
  private async refreshAsyncStatus(): Promise<void> {
    try {
      const response = await post('notion_to_wordpress_get_async_status', {});

      if (response.data.success) {
        this.updateAsyncStatusDisplay(response.data.data.status);

        // 检查同步是否完成
        if (response.data.data.status?.status === 'idle') {
          this.clearSyncStatus();
        }

        emit('sync:async:status:updated', response.data.data.status);
      } else {
        this.showStatusError(
          'async',
          '获取异步状态失败: ' + (response.data.message || '未知错误')
        );
      }
    } catch (error) {
      console.error('❌ [异步状态] 获取失败:', error);
      this.showStatusError('async', '网络错误，无法获取异步状态');
    }
  }

  /**
   * 刷新队列状态
   */
  private async refreshQueueStatus(): Promise<void> {
    try {
      const response = await post('notion_to_wordpress_get_queue_status', {});

      if (response.data.success) {
        this.updateQueueStatusDisplay(response.data.data.status);
        emit('sync:queue:status:updated', response.data.data.status);
      } else {
        this.showStatusError(
          'queue',
          '获取队列状态失败: ' + (response.data.message || '未知错误')
        );
      }
    } catch (error) {
      console.error('❌ [队列状态] 获取失败:', error);
      this.showStatusError('queue', '网络错误，无法获取队列状态');
    }
  }

  /**
   * 更新异步状态显示
   */
  private updateAsyncStatusDisplay(statusData: AsyncStatusData): void {
    const container = document.getElementById('async-status-container');
    if (!container) return;

    const statusDisplay = container.querySelector('.async-status-display');
    if (!statusDisplay) return;

    // 移除所有状态类
    statusDisplay.classList.remove(
      'status-idle',
      'status-running',
      'status-paused',
      'status-error'
    );

    // 确定状态
    const status =
      typeof statusData === 'object' ? statusData.status : statusData;
    let statusClass = 'status-idle';
    let statusText = '空闲';

    switch (status) {
      case 'running':
        statusClass = 'status-running';
        statusText = '运行中';
        break;
      case 'paused':
        statusClass = 'status-paused';
        statusText = '已暂停';
        break;
      case 'error':
        statusClass = 'status-error';
        statusText = '错误';
        break;
    }

    statusDisplay.classList.add(statusClass);

    const statusValue = statusDisplay.querySelector('.status-value');
    if (statusValue) {
      statusValue.textContent = statusText;
    }

    // 更新详细信息
    if (typeof statusData === 'object' && statusData.operation) {
      console.log('异步状态详情:', statusData);
    }
  }

  /**
   * 更新队列状态显示
   */
  private updateQueueStatusDisplay(statusData: QueueStatusData): void {
    const container = document.getElementById('queue-status-container');
    if (!container) return;

    // 更新各项统计
    const updateStat = (selector: string, value: number) => {
      const element = container.querySelector(selector);
      if (element) {
        element.textContent = value.toString();
      }
    };

    updateStat('.queue-pending', statusData.pending || 0);
    updateStat('.queue-processing', statusData.processing || 0);
    updateStat('.queue-completed', statusData.completed || 0);
    updateStat('.queue-failed', statusData.failed || 0);
  }

  /**
   * 显示状态错误
   */
  private showStatusError(type: 'async' | 'queue', message: string): void {
    const containerId =
      type === 'async' ? 'async-status-container' : 'queue-status-container';
    const container = document.getElementById(containerId);

    if (container) {
      container.innerHTML = `
        <div class="error-message" style="color: #d63638; padding: 10px; background: #fef7f7; border: 1px solid #d63638; border-radius: 4px;">
          ${message}
        </div>
      `;
    }

    showError(message);
  }

  /**
   * 获取当前同步ID
   */
  getCurrentSyncId(): string | null {
    return this.currentSyncId;
  }

  /**
   * 检查是否有活跃的同步
   */
  hasActivSync(): boolean {
    return this.currentSyncId !== null;
  }

  /**
   * 销毁管理器
   */
  destroy(): void {
    this.stopStatusMonitoring();
    SyncStatusManager.instance = null;
    console.log('🔄 [同步状态管理器] 已销毁');
  }
}

// 导出单例实例
export const syncStatusManager = SyncStatusManager.getInstance();
