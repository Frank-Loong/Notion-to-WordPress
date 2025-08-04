/**
 * 进度管理器
 */

import { eventBus } from './EventBus';
import { appStateManager } from './StateManager';
import { createElement, addClass, removeClass } from '../utils/dom';

export interface ProgressOptions {
  title?: string;
  showPercentage?: boolean;
  showETA?: boolean;
  showCurrentItem?: boolean;
  closable?: boolean;
  position?: 'top' | 'center' | 'bottom';
  theme?: 'default' | 'minimal' | 'detailed';
}

export interface ProgressData {
  progress: number;
  total: number;
  currentItem?: string;
  eta?: number;
  speed?: number;
  message?: string;
}

/**
 * 进度管理器类
 */
export class ProgressManager {
  private container: HTMLElement | null = null;
  private progressBar: HTMLElement | null = null;
  private progressText: HTMLElement | null = null;
  private currentItemText: HTMLElement | null = null;
  private etaText: HTMLElement | null = null;
  private closeButton: HTMLElement | null = null;
  private isVisible = false;
  private options: ProgressOptions = {};
  private startTime = 0;
  private progressHistory: Array<{ progress: number; timestamp: number }> = [];

  constructor() {
    this.setupEventListeners();
    this.injectStyles();
  }

  /**
   * 显示进度条
   */
  show(options: ProgressOptions = {}): void {
    this.options = {
      title: '处理中...',
      showPercentage: true,
      showETA: true,
      showCurrentItem: true,
      closable: false,
      position: 'top',
      theme: 'default',
      ...options
    };

    this.startTime = Date.now();
    this.progressHistory = [];

    this.createProgressUI();
    this.showContainer();
    this.isVisible = true;

    eventBus.emit('progress:show', { options: this.options });
  }

  /**
   * 隐藏进度条
   */
  hide(): void {
    if (!this.isVisible) return;

    this.hideContainer();
    this.isVisible = false;

    eventBus.emit('progress:hide');
  }

  /**
   * 更新进度
   */
  update(data: ProgressData): void {
    if (!this.isVisible || !this.container) return;

    const percentage = data.total > 0 ? Math.round((data.progress / data.total) * 100) : 0;
    
    // 更新进度条
    if (this.progressBar) {
      this.progressBar.style.width = `${percentage}%`;
      this.progressBar.setAttribute('aria-valuenow', percentage.toString());
    }

    // 更新进度文本
    if (this.progressText && this.options.showPercentage) {
      let text = `${percentage}%`;
      if (data.total > 0) {
        text += ` (${data.progress}/${data.total})`;
      }
      if (data.message) {
        text = `${data.message} - ${text}`;
      }
      this.progressText.textContent = text;
    }

    // 更新当前项目
    if (this.currentItemText && this.options.showCurrentItem && data.currentItem) {
      this.currentItemText.textContent = `正在处理: ${data.currentItem}`;
    }

    // 更新ETA
    if (this.etaText && this.options.showETA) {
      const eta = this.calculateETA(data.progress, data.total);
      if (eta > 0) {
        this.etaText.textContent = `预计剩余: ${this.formatTime(eta)}`;
      }
    }

    // 记录进度历史用于ETA计算
    this.updateProgressHistory(data.progress);

    eventBus.emit('progress:update', data);
  }

  /**
   * 设置进度条状态
   */
  setStatus(status: 'running' | 'completed' | 'error' | 'paused', message?: string): void {
    if (!this.container) return;

    // 移除所有状态类
    removeClass(this.container, 'progress-running', 'progress-completed', 'progress-error', 'progress-paused');
    
    // 添加新状态类
    addClass(this.container, `progress-${status}`);

    // 更新消息
    if (message && this.progressText) {
      this.progressText.textContent = message;
    }

    // 如果完成或错误，延迟隐藏
    if (status === 'completed' || status === 'error') {
      setTimeout(() => {
        this.hide();
      }, status === 'completed' ? 2000 : 5000);
    }

    eventBus.emit('progress:status', { status, message });
  }

  /**
   * 检查是否可见
   */
  isShowing(): boolean {
    return this.isVisible;
  }

  /**
   * 创建进度UI
   */
  private createProgressUI(): void {
    // 移除现有容器
    this.removeContainer();

    // 创建主容器
    this.container = createElement('div', {
      class: `progress-container progress-${this.options.position} progress-theme-${this.options.theme}`,
      role: 'progressbar',
      'aria-valuemin': '0',
      'aria-valuemax': '100',
      'aria-valuenow': '0'
    });

    // 创建内容区域
    const content = createElement('div', { class: 'progress-content' });

    // 标题
    if (this.options.title) {
      const title = createElement('div', { class: 'progress-title' }, this.options.title);
      content.appendChild(title);
    }

    // 进度条容器
    const progressContainer = createElement('div', { class: 'progress-bar-container' });
    
    // 进度条背景
    const progressTrack = createElement('div', { class: 'progress-track' });
    
    // 进度条
    this.progressBar = createElement('div', { class: 'progress-bar' });
    progressTrack.appendChild(this.progressBar);
    progressContainer.appendChild(progressTrack);

    // 进度文本
    if (this.options.showPercentage) {
      this.progressText = createElement('div', { class: 'progress-text' }, '0%');
      progressContainer.appendChild(this.progressText);
    }

    content.appendChild(progressContainer);

    // 当前项目文本
    if (this.options.showCurrentItem) {
      this.currentItemText = createElement('div', { class: 'progress-current-item' });
      content.appendChild(this.currentItemText);
    }

    // ETA文本
    if (this.options.showETA) {
      this.etaText = createElement('div', { class: 'progress-eta' });
      content.appendChild(this.etaText);
    }

    // 关闭按钮
    if (this.options.closable) {
      this.closeButton = createElement('button', { 
        class: 'progress-close',
        type: 'button',
        'aria-label': '关闭'
      }, '×');
      
      this.closeButton.addEventListener('click', () => {
        this.hide();
      });
      
      content.appendChild(this.closeButton);
    }

    this.container.appendChild(content);
    document.body.appendChild(this.container);
  }

  /**
   * 显示容器
   */
  private showContainer(): void {
    if (!this.container) return;

    // 添加显示动画
    addClass(this.container, 'progress-show');
    
    // 触发重排以确保动画生效
    this.container.offsetHeight;
    
    addClass(this.container, 'progress-visible');
  }

  /**
   * 隐藏容器
   */
  private hideContainer(): void {
    if (!this.container) return;

    removeClass(this.container, 'progress-visible');
    
    setTimeout(() => {
      this.removeContainer();
    }, 300); // 等待动画完成
  }

  /**
   * 移除容器
   */
  private removeContainer(): void {
    if (this.container && this.container.parentNode) {
      this.container.parentNode.removeChild(this.container);
    }
    
    this.container = null;
    this.progressBar = null;
    this.progressText = null;
    this.currentItemText = null;
    this.etaText = null;
    this.closeButton = null;
  }

  /**
   * 计算ETA
   */
  private calculateETA(current: number, total: number): number {
    if (current <= 0 || total <= 0 || current >= total) return 0;

    const now = Date.now();
    const elapsed = now - this.startTime;
    
    if (elapsed < 1000) return 0; // 至少等待1秒

    const rate = current / elapsed; // 每毫秒处理的项目数
    const remaining = total - current;
    
    return remaining / rate;
  }

  /**
   * 更新进度历史
   */
  private updateProgressHistory(progress: number): void {
    const now = Date.now();

    // 只保留最近10秒的数据
    this.progressHistory = this.progressHistory.filter(entry =>
      now - entry.timestamp < 10000
    );

    this.progressHistory.push({
      progress,
      timestamp: now
    });
  }

  /**
   * 格式化时间
   */
  private formatTime(milliseconds: number): string {
    const seconds = Math.floor(milliseconds / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);

    if (hours > 0) {
      return `${hours}小时${minutes % 60}分钟`;
    } else if (minutes > 0) {
      return `${minutes}分钟${seconds % 60}秒`;
    } else {
      return `${seconds}秒`;
    }
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 监听状态变化
    appStateManager.subscribe((state, prevState) => {
      const syncState = state.sync;
      const prevSyncState = prevState.sync;

      // 同步开始时显示进度条
      if (syncState.status === 'running' && prevSyncState.status !== 'running') {
        this.show({
          title: `${syncState.sync_type}进行中`,
          showPercentage: true,
          showETA: true,
          showCurrentItem: true
        });
      }

      // 更新进度
      if (syncState.status === 'running' && this.isVisible) {
        this.update({
          progress: syncState.progress,
          total: syncState.total,
          currentItem: syncState.current_item
        });
      }

      // 同步完成时设置状态
      if (syncState.status === 'completed' && prevSyncState.status === 'running') {
        this.setStatus('completed', '同步完成');
      }

      // 同步错误时设置状态
      if (syncState.status === 'error' && prevSyncState.status === 'running') {
        this.setStatus('error', '同步失败');
      }

      // 同步暂停时设置状态
      if (syncState.status === 'paused' && prevSyncState.status === 'running') {
        this.setStatus('paused', '同步已暂停');
      }
    });

    // 监听键盘事件
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && this.isVisible && this.options.closable) {
        this.hide();
      }
    });
  }

  /**
   * 注入样式
   */
  private injectStyles(): void {
    if (document.querySelector('#progress-manager-styles')) return;

    const style = createElement('style', { id: 'progress-manager-styles' });
    style.textContent = `
      .progress-container {
        position: fixed;
        left: 50%;
        transform: translateX(-50%);
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        padding: 20px;
        min-width: 400px;
        max-width: 600px;
        z-index: 10000;
        opacity: 0;
        transition: all 0.3s ease;
      }

      .progress-container.progress-top {
        top: 20px;
      }

      .progress-container.progress-center {
        top: 50%;
        transform: translate(-50%, -50%);
      }

      .progress-container.progress-bottom {
        bottom: 20px;
      }

      .progress-container.progress-visible {
        opacity: 1;
      }

      .progress-title {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 15px;
        color: #374151;
      }

      .progress-bar-container {
        position: relative;
        margin-bottom: 10px;
      }

      .progress-track {
        width: 100%;
        height: 8px;
        background: #e5e7eb;
        border-radius: 4px;
        overflow: hidden;
      }

      .progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #3b82f6, #1d4ed8);
        border-radius: 4px;
        transition: width 0.3s ease;
        position: relative;
      }

      .progress-bar::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        animation: shimmer 2s infinite;
      }

      @keyframes shimmer {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
      }

      .progress-text {
        text-align: center;
        font-size: 14px;
        color: #6b7280;
        margin-top: 8px;
      }

      .progress-current-item {
        font-size: 13px;
        color: #9ca3af;
        margin-bottom: 5px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .progress-eta {
        font-size: 13px;
        color: #9ca3af;
        text-align: right;
      }

      .progress-close {
        position: absolute;
        top: 10px;
        right: 10px;
        background: none;
        border: none;
        font-size: 20px;
        color: #9ca3af;
        cursor: pointer;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.2s ease;
      }

      .progress-close:hover {
        background: #f3f4f6;
        color: #374151;
      }

      .progress-container.progress-completed .progress-bar {
        background: linear-gradient(90deg, #10b981, #059669);
      }

      .progress-container.progress-error .progress-bar {
        background: linear-gradient(90deg, #ef4444, #dc2626);
      }

      .progress-container.progress-paused .progress-bar {
        background: linear-gradient(90deg, #f59e0b, #d97706);
      }

      @media (max-width: 640px) {
        .progress-container {
          left: 10px;
          right: 10px;
          transform: none;
          min-width: auto;
        }

        .progress-container.progress-center {
          transform: translateY(-50%);
        }
      }
    `;

    document.head.appendChild(style);
  }
}

// 全局进度管理器实例
export const progressManager = new ProgressManager();
