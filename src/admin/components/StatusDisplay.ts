/**
 * 状态显示组件
 */

import { BaseComponent, ComponentOptions } from './BaseComponent';
import { addClass, removeClass } from '../../shared/utils/dom';
import { formatTimeDiff } from '../../shared/utils/common';

export interface StatusDisplayOptions extends ComponentOptions {
  type: 'sync' | 'queue' | 'async';
  refreshInterval?: number;
  autoRefresh?: boolean;
}

export interface StatusData {
  status: string;
  progress?: number;
  total?: number;
  current_item?: string;
  start_time?: number;
  estimated_completion?: number;
  errors?: any[];
  warnings?: any[];
  [key: string]: any;
}

/**
 * 状态显示组件
 */
export class StatusDisplay extends BaseComponent {
  private statusOptions: StatusDisplayOptions;
  private refreshTimer: NodeJS.Timeout | null = null;
  private statusData: StatusData | null = null;

  constructor(options: StatusDisplayOptions) {
    super(options);
    this.statusOptions = {
      refreshInterval: 5000,
      autoRefresh: true,
      ...options
    };
  }

  protected onInit(): void {
    if (this.element) {
      addClass(this.element, 'status-display', `status-display-${this.statusOptions.type}`);
    }
  }

  protected onMount(): void {
    this.render();
    
    if (this.statusOptions.autoRefresh) {
      this.startAutoRefresh();
    }
  }

  protected onUnmount(): void {
    this.stopAutoRefresh();
  }

  protected onDestroy(): void {
    this.stopAutoRefresh();
  }

  protected onRender(): void {
    this.updateDisplay();
  }

  protected bindEvents(): void {
    // 绑定刷新按钮事件
    const refreshButton = this.$('.status-refresh-button');
    if (refreshButton) {
      this.addEventListener(refreshButton, 'click', this.handleRefresh.bind(this));
    }
  }

  protected onStateChange(state: any, prevState: any, _action: any): void {
    let stateChanged = false;

    switch (this.statusOptions.type) {
      case 'sync':
        if (state.sync !== prevState.sync) {
          this.statusData = state.sync;
          stateChanged = true;
        }
        break;
      case 'queue':
        if (state.queue !== prevState.queue) {
          this.statusData = state.queue;
          stateChanged = true;
        }
        break;
      case 'async':
        // 异步状态可能需要特殊处理
        break;
    }

    if (stateChanged) {
      this.render();
    }
  }

  /**
   * 更新显示
   */
  private updateDisplay(): void {
    if (!this.element || !this.statusData) {
      return;
    }

    // 更新状态指示器
    this.updateStatusIndicator();

    // 更新详细信息
    this.updateDetails();

    // 更新进度条
    this.updateProgress();

    // 更新时间信息
    this.updateTimeInfo();
  }

  /**
   * 更新状态指示器
   */
  private updateStatusIndicator(): void {
    const indicator = this.$('.status-indicator');
    if (!indicator || !this.statusData) return;

    const status = this.statusData.status;
    
    // 移除所有状态类
    removeClass(indicator, 'status-idle', 'status-running', 'status-paused', 'status-completed', 'status-error');
    
    // 添加当前状态类
    addClass(indicator, `status-${status}`);

    // 更新状态文本
    const statusText = this.$('.status-text');
    if (statusText) {
      statusText.textContent = this.getStatusText(status);
    }

    // 更新状态图标
    const statusIcon = this.$('.status-icon');
    if (statusIcon) {
      statusIcon.innerHTML = this.getStatusIcon(status);
    }
  }

  /**
   * 更新详细信息
   */
  private updateDetails(): void {
    if (!this.statusData) return;

    switch (this.statusOptions.type) {
      case 'sync':
        this.updateSyncDetails();
        break;
      case 'queue':
        this.updateQueueDetails();
        break;
      case 'async':
        this.updateAsyncDetails();
        break;
    }
  }

  /**
   * 更新同步详细信息
   */
  private updateSyncDetails(): void {
    if (!this.statusData) return;

    const currentItem = this.$('.current-item');
    if (currentItem && this.statusData.current_item) {
      currentItem.textContent = `正在处理: ${this.statusData.current_item}`;
    }

    const errorCount = this.$('.error-count');
    if (errorCount && this.statusData.errors) {
      errorCount.textContent = this.statusData.errors.length.toString();
    }

    const warningCount = this.$('.warning-count');
    if (warningCount && this.statusData.warnings) {
      warningCount.textContent = this.statusData.warnings.length.toString();
    }
  }

  /**
   * 更新队列详细信息
   */
  private updateQueueDetails(): void {
    if (!this.statusData) return;

    const stats = ['total_jobs', 'pending_jobs', 'processing_jobs', 'completed_jobs', 'failed_jobs'];
    
    stats.forEach(stat => {
      const element = this.$(`.${stat.replace('_', '-')}`);
      if (element && this.statusData![stat] !== undefined) {
        element.textContent = this.statusData![stat].toString();
      }
    });
  }

  /**
   * 更新异步详细信息
   */
  private updateAsyncDetails(): void {
    // 异步状态的特殊处理
    if (!this.statusData) return;

    // 可以根据需要添加异步状态的特殊显示逻辑
  }

  /**
   * 更新进度条
   */
  private updateProgress(): void {
    const progressBar = this.$('.progress-bar');
    const progressText = this.$('.progress-text');
    
    if (!progressBar || !this.statusData) return;

    const { progress = 0, total = 0 } = this.statusData;
    const percentage = total > 0 ? Math.round((progress / total) * 100) : 0;

    // 更新进度条
    const progressFill = progressBar.querySelector('.progress-fill') as HTMLElement;
    if (progressFill) {
      progressFill.style.width = `${percentage}%`;
    }

    // 更新进度文本
    if (progressText) {
      if (total > 0) {
        progressText.textContent = `${percentage}% (${progress}/${total})`;
      } else {
        progressText.textContent = `${percentage}%`;
      }
    }
  }

  /**
   * 更新时间信息
   */
  private updateTimeInfo(): void {
    if (!this.statusData) return;

    const startTime = this.$('.start-time');
    const elapsedTime = this.$('.elapsed-time');
    const estimatedTime = this.$('.estimated-time');

    if (this.statusData.start_time) {
      const elapsed = Date.now() - this.statusData.start_time;
      
      if (startTime) {
        startTime.textContent = new Date(this.statusData.start_time).toLocaleTimeString();
      }
      
      if (elapsedTime) {
        elapsedTime.textContent = formatTimeDiff(elapsed);
      }
    }

    if (estimatedTime && this.statusData.estimated_completion) {
      const remaining = this.statusData.estimated_completion - Date.now();
      estimatedTime.textContent = remaining > 0 ? formatTimeDiff(remaining) : '即将完成';
    }
  }

  /**
   * 获取状态文本
   */
  private getStatusText(status: string): string {
    const statusTexts: Record<string, string> = {
      idle: '空闲',
      running: '运行中',
      paused: '已暂停',
      completed: '已完成',
      error: '错误',
      cancelled: '已取消'
    };

    return statusTexts[status] || status;
  }

  /**
   * 获取状态图标
   */
  private getStatusIcon(status: string): string {
    const statusIcons: Record<string, string> = {
      idle: '<span class="dashicons dashicons-clock"></span>',
      running: '<span class="dashicons dashicons-update spin"></span>',
      paused: '<span class="dashicons dashicons-controls-pause"></span>',
      completed: '<span class="dashicons dashicons-yes-alt"></span>',
      error: '<span class="dashicons dashicons-warning"></span>',
      cancelled: '<span class="dashicons dashicons-dismiss"></span>'
    };

    return statusIcons[status] || '<span class="dashicons dashicons-info"></span>';
  }

  /**
   * 处理刷新事件
   */
  private async handleRefresh(event: Event): Promise<void> {
    event.preventDefault();
    
    const button = event.target as HTMLElement;
    const originalText = button.textContent;
    
    // 设置加载状态
    button.setAttribute('disabled', 'true');
    button.innerHTML = '<span class="spinner is-active"></span> 刷新中...';

    try {
      await this.refreshStatus();
    } catch (error) {
      console.error('Refresh status error:', error);
    } finally {
      // 恢复按钮状态
      button.removeAttribute('disabled');
      button.textContent = originalText || '刷新';
    }
  }

  /**
   * 刷新状态
   */
  private async refreshStatus(): Promise<void> {
    // 根据类型调用不同的刷新方法
    switch (this.statusOptions.type) {
      case 'sync':
        await this.refreshSyncStatus();
        break;
      case 'queue':
        await this.refreshQueueStatus();
        break;
      case 'async':
        await this.refreshAsyncStatus();
        break;
    }
  }

  /**
   * 刷新同步状态
   */
  private async refreshSyncStatus(): Promise<void> {
    // 这里应该调用同步状态API
    // 暂时使用状态管理器的数据
    const state = this.getState();
    this.statusData = state.sync;
    this.render();
  }

  /**
   * 刷新队列状态
   */
  private async refreshQueueStatus(): Promise<void> {
    // 这里应该调用队列状态API
    const state = this.getState();
    this.statusData = state.queue;
    this.render();
  }

  /**
   * 刷新异步状态
   */
  private async refreshAsyncStatus(): Promise<void> {
    // 调用异步状态API
    const response = await fetch(window.ajaxurl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'notion_to_wordpress_get_async_status',
        nonce: (window as any).notionToWp?.nonce || ''
      })
    });

    const result = await response.json();
    
    if (result.success) {
      this.statusData = result.data.status;
      this.render();
    }
  }

  /**
   * 开始自动刷新
   */
  private startAutoRefresh(): void {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
    }

    this.refreshTimer = setInterval(() => {
      if (this.isMounted() && !document.hidden) {
        this.refreshStatus().catch(console.error);
      }
    }, this.statusOptions.refreshInterval);
  }

  /**
   * 停止自动刷新
   */
  private stopAutoRefresh(): void {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
      this.refreshTimer = null;
    }
  }

  /**
   * 设置状态数据
   */
  public setStatusData(data: StatusData): void {
    this.statusData = data;
    this.render();
  }

  /**
   * 获取状态数据
   */
  public getStatusData(): StatusData | null {
    return this.statusData;
  }

  /**
   * 设置自动刷新
   */
  public setAutoRefresh(enabled: boolean): void {
    this.statusOptions.autoRefresh = enabled;
    
    if (enabled) {
      this.startAutoRefresh();
    } else {
      this.stopAutoRefresh();
    }
  }
}
