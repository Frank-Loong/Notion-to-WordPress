/**
 * 同步进度组件
 */

import { eventBus } from '../../shared/core/EventBus';

export class SyncProgress {
  private element: HTMLElement | null = null;
  private progressBar: HTMLElement | null = null;
  private statusText: HTMLElement | null = null;

  constructor(selector: string) {
    this.element = document.querySelector(selector);
    if (this.element) {
      this.init();
    }
  }

  private init(): void {
    if (!this.element) return;

    // 创建进度条HTML结构
    this.element.innerHTML = `
      <div class="sync-progress">
        <div class="progress-bar">
          <div class="progress-fill"></div>
        </div>
        <div class="status-text">准备中...</div>
      </div>
    `;

    this.progressBar = this.element.querySelector('.progress-fill');
    this.statusText = this.element.querySelector('.status-text');

    // 监听同步事件
    eventBus.on('sync:start', this.onSyncStart.bind(this));
    eventBus.on('sync:progress', this.onSyncProgress.bind(this));
    eventBus.on('sync:complete', this.onSyncComplete.bind(this));
    eventBus.on('sync:error', this.onSyncError.bind(this));
  }

  private onSyncStart(): void {
    this.updateProgress(0, '开始同步...');
  }

  private onSyncProgress(_event: any, data: { progress: number; message: string }): void {
    this.updateProgress(data.progress, data.message);
  }

  private onSyncComplete(): void {
    this.updateProgress(100, '同步完成');
  }

  private onSyncError(_event: any, error: Error): void {
    this.updateProgress(0, `同步失败: ${error.message}`);
  }

  private updateProgress(progress: number, message: string): void {
    if (this.progressBar) {
      this.progressBar.style.width = `${progress}%`;
    }
    if (this.statusText) {
      this.statusText.textContent = message;
    }
  }

  public destroy(): void {
    eventBus.off('sync:start', this.onSyncStart.bind(this));
    eventBus.off('sync:progress', this.onSyncProgress.bind(this));
    eventBus.off('sync:complete', this.onSyncComplete.bind(this));
    eventBus.off('sync:error', this.onSyncError.bind(this));
  }
}

// 自动初始化
document.addEventListener('DOMContentLoaded', () => {
  const progressElement = document.querySelector('.sync-progress-container');
  if (progressElement) {
    new SyncProgress('.sync-progress-container');
  }
});

export default SyncProgress;
