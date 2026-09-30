/**
 * 同步进度UI管理器 - 现代化TypeScript版本
 *
 * 从原有sync-progress-manager.js的UI功能完全迁移，包括：
 * - 进度条UI创建和管理
 * - 动画效果和状态显示
 * - 响应式布局和主题支持
 */

import { emit } from '../../shared/core/EventBus';

export interface ProgressUIOptions {
  title?: string;
  syncType?: string;
  showPercentage?: boolean;
  showETA?: boolean;
  showCurrentItem?: boolean;
  theme?: 'default' | 'minimal' | 'detailed';
  position?: 'top' | 'center' | 'bottom';
  closable?: boolean;
  autoHide?: boolean;
  autoHideDelay?: number;
}

export interface ProgressUIData {
  percentage: number;
  current: number;
  total: number;
  message?: string;
  step?: string;
  eta?: number;
  speed?: number;
}

/**
 * 同步进度UI管理器类
 */
export class SyncProgressUI {
  private static instance: SyncProgressUI | null = null;

  private container: HTMLElement | null = null;
  private progressFill: HTMLElement | null = null;
  private statusText: HTMLElement | null = null;
  private percentageText: HTMLElement | null = null;
  private etaText: HTMLElement | null = null;
  private currentItemText: HTMLElement | null = null;
  private iconElement: HTMLElement | null = null;

  private isVisible = false;
  private currentTaskId: string | null = null;
  private options!: Required<ProgressUIOptions>;
  private startTime: number = 0;
  private hideTimer: NodeJS.Timeout | null = null;

  constructor(options: ProgressUIOptions = {}) {
    if (SyncProgressUI.instance) {
      return SyncProgressUI.instance;
    }

    SyncProgressUI.instance = this;

    this.options = {
      title: '同步进度',
      syncType: '同步',
      showPercentage: true,
      showETA: true,
      showCurrentItem: true,
      theme: 'default',
      position: 'top',
      closable: false,
      autoHide: true,
      autoHideDelay: 2000,
      ...options,
    };
  }

  /**
   * 获取单例实例
   */
  static getInstance(options?: ProgressUIOptions): SyncProgressUI {
    if (!SyncProgressUI.instance) {
      SyncProgressUI.instance = new SyncProgressUI(options);
    }
    return SyncProgressUI.instance;
  }

  /**
   * 显示进度界面
   */
  show(taskId: string, options: Partial<ProgressUIOptions> = {}): void {
    // 更新选项
    this.options = { ...this.options, ...options };
    this.currentTaskId = taskId;
    this.startTime = Date.now();

    // 清除自动隐藏定时器
    if (this.hideTimer) {
      clearTimeout(this.hideTimer);
      this.hideTimer = null;
    }

    // 创建或更新UI
    this.createOrUpdateUI();

    // 显示容器
    this.showContainer();

    this.isVisible = true;
    emit('progress:ui:shown', { taskId, options: this.options });

    console.log('📊 [进度UI] 已显示');
  }

  /**
   * 隐藏进度界面
   */
  hide(): void {
    if (!this.isVisible || !this.container) return;

    // 添加淡出动画
    this.container.style.transition =
      'opacity 0.3s ease-out, transform 0.3s ease-out';
    this.container.style.opacity = '0';
    this.container.style.transform = 'translateY(-10px)';

    setTimeout(() => {
      if (this.container) {
        this.container.remove();
        this.container = null;
      }
      this.resetReferences();
    }, 300);

    this.isVisible = false;
    this.currentTaskId = null;

    emit('progress:ui:hidden');
    console.log('📊 [进度UI] 已隐藏');
  }

  /**
   * 更新进度
   */
  updateProgress(data: ProgressUIData): void {
    if (!this.isVisible || !this.container) return;

    // 更新进度条
    if (this.progressFill) {
      this.progressFill.style.width = `${Math.max(0, Math.min(100, data.percentage))}%`;
    }

    // 更新百分比
    if (this.percentageText && this.options.showPercentage) {
      this.percentageText.textContent = `${Math.round(data.percentage)}%`;
    }

    // 更新状态文本
    if (this.statusText && data.message) {
      this.statusText.textContent = data.message;
    }

    // 更新当前项目
    if (this.currentItemText && this.options.showCurrentItem && data.step) {
      this.currentItemText.textContent = data.step;
    }

    // 更新ETA
    if (this.etaText && this.options.showETA && data.eta) {
      this.etaText.textContent = this.formatETA(data.eta);
    }

    // 更新进度条动画
    this.updateProgressAnimation(data.percentage);

    emit('progress:ui:updated', { data, taskId: this.currentTaskId });
  }

  /**
   * 设置状态
   */
  setStatus(
    status: 'running' | 'completed' | 'failed' | 'cancelled',
    message?: string
  ): void {
    if (!this.isVisible || !this.container) return;

    // 更新图标
    if (this.iconElement) {
      const icons = {
        running: '🔄',
        completed: '✅',
        failed: '❌',
        cancelled: '⏹️',
      };
      this.iconElement.textContent = icons[status];
    }

    // 更新状态文本
    if (this.statusText && message) {
      this.statusText.textContent = message;
    }

    // 更新容器样式
    this.container.className = this.container.className.replace(
      /status-\w+/g,
      ''
    );
    this.container.classList.add(`status-${status}`);

    // 完成状态的特殊处理
    if (status === 'completed') {
      if (this.progressFill) {
        this.progressFill.style.width = '100%';
      }
      if (this.percentageText) {
        this.percentageText.textContent = '100%';
      }
    }

    // 自动隐藏
    if (
      this.options.autoHide &&
      (status === 'completed' || status === 'failed' || status === 'cancelled')
    ) {
      this.hideTimer = setTimeout(() => {
        this.hide();
      }, this.options.autoHideDelay);
    }

    emit('progress:ui:status:changed', {
      status,
      message,
      taskId: this.currentTaskId,
    });
  }

  /**
   * 创建或更新UI
   */
  private createOrUpdateUI(): void {
    // 移除现有容器
    const existingContainer = document.querySelector(
      '.notion-sync-progress-container'
    );
    if (existingContainer) {
      existingContainer.remove();
    }

    // 创建新容器
    this.container = this.createElement();

    // 查找插入位置
    const insertTarget = this.findInsertTarget();
    if (insertTarget) {
      insertTarget.appendChild(this.container);
    } else {
      document.body.appendChild(this.container);
    }

    // 获取元素引用
    this.getElementReferences();
  }

  /**
   * 创建进度UI元素
   */
  private createElement(): HTMLElement {
    const container = document.createElement('div');
    container.className = `notion-sync-progress-container theme-${this.options.theme} position-${this.options.position}`;
    container.style.display = 'none';

    container.innerHTML = `
      <div class="sync-progress-content">
        <div class="sync-progress-header">
          <div class="sync-progress-title">
            <span class="sync-progress-icon">🔄</span>
            <span class="sync-progress-text">${this.options.title}</span>
          </div>
          ${this.options.closable ? '<button class="sync-progress-close" type="button">×</button>' : ''}
        </div>
        
        <div class="sync-progress-body">
          <div class="progress-bar-container">
            <div class="progress-bar">
              <div class="progress-fill"></div>
              <div class="progress-shine"></div>
            </div>
            ${this.options.showPercentage ? '<div class="progress-percentage">0%</div>' : ''}
          </div>
          
          <div class="progress-info">
            <div class="progress-status">准备中...</div>
            ${this.options.showCurrentItem ? '<div class="progress-current-item"></div>' : ''}
            ${this.options.showETA ? '<div class="progress-eta"></div>' : ''}
          </div>
        </div>
      </div>
    `;

    // 绑定关闭按钮事件
    if (this.options.closable) {
      const closeButton = container.querySelector(
        '.sync-progress-close'
      ) as HTMLButtonElement;
      if (closeButton) {
        closeButton.addEventListener('click', () => {
          this.hide();
        });
      }
    }

    return container;
  }

  /**
   * 查找插入目标
   */
  private findInsertTarget(): HTMLElement | null {
    // 优先级顺序查找插入位置
    const selectors = [
      '.notion-wp-sync-actions',
      '.notion-wp-admin .wrap',
      '.wrap',
      'body',
    ];

    for (const selector of selectors) {
      const element = document.querySelector(selector) as HTMLElement;
      if (element) {
        return element;
      }
    }

    return document.body;
  }

  /**
   * 获取元素引用
   */
  private getElementReferences(): void {
    if (!this.container) return;

    this.progressFill = this.container.querySelector('.progress-fill');
    this.statusText = this.container.querySelector('.progress-status');
    this.percentageText = this.container.querySelector('.progress-percentage');
    this.etaText = this.container.querySelector('.progress-eta');
    this.currentItemText = this.container.querySelector(
      '.progress-current-item'
    );
    this.iconElement = this.container.querySelector('.sync-progress-icon');
  }

  /**
   * 显示容器
   */
  private showContainer(): void {
    if (!this.container) return;

    // 初始状态
    this.container.style.opacity = '0';
    this.container.style.transform = 'translateY(-10px)';
    this.container.style.display = 'block';

    // 触发动画
    setTimeout(() => {
      if (this.container) {
        this.container.style.transition =
          'opacity 0.3s ease-out, transform 0.3s ease-out';
        this.container.style.opacity = '1';
        this.container.style.transform = 'translateY(0)';
      }
    }, 10);
  }

  /**
   * 更新进度条动画
   */
  private updateProgressAnimation(percentage: number): void {
    if (!this.progressFill) return;

    // 添加脉冲效果
    if (percentage > 0 && percentage < 100) {
      this.progressFill.classList.add('progress-active');
    } else {
      this.progressFill.classList.remove('progress-active');
    }
  }

  /**
   * 格式化ETA
   */
  private formatETA(eta: number): string {
    if (eta <= 0) return '';

    const minutes = Math.floor(eta / 60);
    const seconds = Math.floor(eta % 60);

    if (minutes > 0) {
      return `预计剩余: ${minutes}分${seconds}秒`;
    } else {
      return `预计剩余: ${seconds}秒`;
    }
  }

  /**
   * 重置元素引用
   */
  private resetReferences(): void {
    this.progressFill = null;
    this.statusText = null;
    this.percentageText = null;
    this.etaText = null;
    this.currentItemText = null;
    this.iconElement = null;
  }

  /**
   * 获取当前任务ID
   */
  getCurrentTaskId(): string | null {
    return this.currentTaskId;
  }

  /**
   * 检查是否可见
   */
  isProgressVisible(): boolean {
    return this.isVisible;
  }

  /**
   * 获取运行时长
   */
  getDuration(): number {
    return this.startTime > 0 ? Date.now() - this.startTime : 0;
  }

  /**
   * 更新配置
   */
  updateOptions(options: Partial<ProgressUIOptions>): void {
    this.options = { ...this.options, ...options };
    emit('progress:ui:options:updated', this.options);
  }

  /**
   * 销毁实例
   */
  destroy(): void {
    this.hide();

    if (this.hideTimer) {
      clearTimeout(this.hideTimer);
      this.hideTimer = null;
    }

    SyncProgressUI.instance = null;
    emit('progress:ui:destroyed');
    console.log('📊 [进度UI] 已销毁');
  }
}

// 导出单例实例
export const syncProgressUI = SyncProgressUI.getInstance();

export default SyncProgressUI;
