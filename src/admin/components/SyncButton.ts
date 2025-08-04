/**
 * 同步按钮组件
 */

import { BaseComponent, ComponentOptions } from './BaseComponent';
import { syncManager } from '../../shared/core/SyncManager';
import { showSuccess, showError, showInfo } from '../../shared/utils/toast';
import { addClass, removeClass } from '../../shared/utils/dom';

export interface SyncButtonOptions extends ComponentOptions {
  syncType: 'smart' | 'full' | 'test';
  incremental?: boolean;
  checkDeletions?: boolean;
  confirmMessage?: string;
}

/**
 * 同步按钮组件
 */
export class SyncButton extends BaseComponent {
  private syncOptions: SyncButtonOptions;
  private originalText: string = '';
  private isProcessing: boolean = false;

  constructor(options: SyncButtonOptions) {
    super(options);
    this.syncOptions = options;
  }

  protected onInit(): void {
    if (this.element) {
      this.originalText = this.element.textContent || '';
      
      // 设置按钮属性
      this.element.setAttribute('data-sync-type', this.syncOptions.syncType);
      
      // 添加CSS类
      addClass(this.element, 'sync-button', `sync-button-${this.syncOptions.syncType}`);
    }
  }

  protected onMount(): void {
    this.updateButtonState();
  }

  protected onUnmount(): void {
    // 清理状态
    this.isProcessing = false;
  }

  protected onDestroy(): void {
    // 清理资源
  }

  protected onRender(): void {
    this.updateButtonState();
  }

  protected bindEvents(): void {
    if (!this.element) return;

    this.addEventListener(this.element, 'click', this.handleClick.bind(this));
  }

  protected onStateChange(state: any, prevState: any, _action: any): void {
    // 监听同步状态变化
    if (state.sync !== prevState.sync) {
      this.updateButtonState();
    }
  }

  /**
   * 处理点击事件
   */
  private async handleClick(event: Event): Promise<void> {
    event.preventDefault();

    if (this.isProcessing || !this.element) {
      return;
    }

    // 确认操作
    if (this.syncOptions.confirmMessage) {
      if (!confirm(this.syncOptions.confirmMessage)) {
        return;
      }
    }

    // 检查必要的配置
    if (this.syncOptions.syncType !== 'test' && !this.validateConfiguration()) {
      return;
    }

    this.isProcessing = true;
    this.setLoadingState(true);

    try {
      await this.performSync();
    } catch (error) {
      console.error('Sync error:', error);
      showError(`同步失败: ${(error as Error).message}`);
    } finally {
      this.isProcessing = false;
      this.setLoadingState(false);
    }
  }

  /**
   * 执行同步
   */
  private async performSync(): Promise<void> {
    const syncType = this.getSyncTypeName();

    switch (this.syncOptions.syncType) {
      case 'smart':
        await this.performSmartSync(syncType);
        break;
      case 'full':
        await this.performFullSync(syncType);
        break;
      case 'test':
        await this.performTestConnection();
        break;
      default:
        throw new Error(`未知的同步类型: ${this.syncOptions.syncType}`);
    }
  }

  /**
   * 执行智能同步
   */
  private async performSmartSync(syncType: string): Promise<void> {
    const result = await syncManager.startSync({
      syncType,
      incremental: true,
      checkDeletions: this.syncOptions.checkDeletions || true,
      batchSize: 10
    });

    if (result.success) {
      showInfo(`${syncType}已开始`);
    } else {
      throw new Error(result.message);
    }
  }

  /**
   * 执行完全同步
   */
  private async performFullSync(syncType: string): Promise<void> {
    const result = await syncManager.startSync({
      syncType,
      incremental: false,
      checkDeletions: this.syncOptions.checkDeletions || true,
      batchSize: 10
    });

    if (result.success) {
      showInfo(`${syncType}已开始`);
    } else {
      throw new Error(result.message);
    }
  }

  /**
   * 执行测试连接
   */
  private async performTestConnection(): Promise<void> {
    // 这里应该调用测试连接的API
    // 暂时使用模拟实现
    const response = await fetch(window.ajaxurl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'notion_to_wordpress_test_connection',
        nonce: (window as any).notionToWp?.nonce || '',
        api_key: this.getConfigValue('notion_to_wordpress_api_key'),
        database_id: this.getConfigValue('notion_to_wordpress_database_id')
      })
    });

    const result = await response.json();

    if (result.success) {
      showSuccess(result.data.message || '连接测试成功');
    } else {
      throw new Error(result.data.message || '连接测试失败');
    }
  }

  /**
   * 验证配置
   */
  private validateConfiguration(): boolean {
    const apiKey = this.getConfigValue('notion_to_wordpress_api_key');
    const databaseId = this.getConfigValue('notion_to_wordpress_database_id');

    if (!apiKey || !databaseId) {
      showError('请先配置API密钥和数据库ID');
      
      // 高亮空字段
      if (!apiKey) {
        this.highlightField('notion_to_wordpress_api_key');
      }
      if (!databaseId) {
        this.highlightField('notion_to_wordpress_database_id');
      }
      
      return false;
    }

    return true;
  }

  /**
   * 获取配置值
   */
  private getConfigValue(fieldName: string): string {
    const field = document.querySelector(`#${fieldName}`) as HTMLInputElement;
    return field ? field.value.trim() : '';
  }

  /**
   * 高亮字段
   */
  private highlightField(fieldName: string): void {
    const field = document.querySelector(`#${fieldName}`) as HTMLElement;
    if (field) {
      addClass(field, 'error');
      if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
        field.focus();
      }

      setTimeout(() => {
        removeClass(field, 'error');
      }, 2000);
    }
  }

  /**
   * 获取同步类型名称
   */
  private getSyncTypeName(): string {
    const typeNames = {
      smart: '智能同步',
      full: '完全同步',
      test: '测试连接'
    };

    return typeNames[this.syncOptions.syncType] || '同步';
  }

  /**
   * 设置加载状态
   */
  private setLoadingState(loading: boolean): void {
    if (!this.element) return;

    if (loading) {
      this.element.setAttribute('disabled', 'true');
      addClass(this.element, 'loading');
      
      const spinner = '<span class="spinner is-active"></span> ';
      const syncType = this.getSyncTypeName();
      this.element.innerHTML = `${spinner}${syncType}中...`;
    } else {
      this.element.removeAttribute('disabled');
      removeClass(this.element, 'loading');
      this.element.innerHTML = this.originalText;
    }
  }

  /**
   * 更新按钮状态
   */
  private updateButtonState(): void {
    if (!this.element) return;

    const state = this.getState();
    const syncState = state.sync;

    // 根据同步状态更新按钮
    if (syncState.status === 'running') {
      if (!this.isProcessing) {
        this.element.setAttribute('disabled', 'true');
        addClass(this.element, 'sync-running');
      }
    } else {
      if (!this.isProcessing) {
        this.element.removeAttribute('disabled');
        removeClass(this.element, 'sync-running');
      }
    }

    // 更新按钮样式
    this.updateButtonStyle(syncState.status);
  }

  /**
   * 更新按钮样式
   */
  private updateButtonStyle(status: string): void {
    if (!this.element) return;

    // 移除所有状态类
    removeClass(this.element, 'status-idle', 'status-running', 'status-completed', 'status-error', 'status-paused');

    // 添加当前状态类
    addClass(this.element, `status-${status}`);
  }

  /**
   * 设置按钮文本
   */
  public setText(text: string): void {
    if (this.element && !this.isProcessing) {
      this.element.textContent = text;
      this.originalText = text;
    }
  }

  /**
   * 获取按钮文本
   */
  public getText(): string {
    return this.element ? this.element.textContent || '' : '';
  }

  /**
   * 启用按钮
   */
  public enable(): void {
    if (this.element && !this.isProcessing) {
      this.element.removeAttribute('disabled');
      removeClass(this.element, 'disabled');
    }
  }

  /**
   * 禁用按钮
   */
  public disable(): void {
    if (this.element) {
      this.element.setAttribute('disabled', 'true');
      addClass(this.element, 'disabled');
    }
  }

  /**
   * 检查按钮是否正在处理
   */
  public isLoading(): boolean {
    return this.isProcessing;
  }
}
