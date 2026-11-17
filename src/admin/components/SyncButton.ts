/**
 * 同步按钮组件
 */

import { BaseComponent, ComponentOptions } from './BaseComponent';
import { syncManager } from '../../shared/core/SyncManager';
import { showSuccess, showError, showInfo } from '../../shared/utils/toast';
import { addClass, removeClass } from '../../shared/utils/dom';
import { post } from '../../shared/utils/ajax';
import type { AjaxResponse } from '../../shared/types/wordpress';

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
  /**
   * 按钮初始的 HTML（含 dashicons 图标）。
   *
   * 收口迭代（2026-11-16）：原实现保存 `textContent`，加载结束后用
   * `innerHTML = originalText` 还原，会把 `<span class="dashicons">` 图标
   * 永久抹掉（纯文本无法还原标签）。改为保存 innerHTML。
   */
  private originalHTML: string = '';
  private isProcessing: boolean = false;

  constructor(options: SyncButtonOptions) {
    super(options);
    this.syncOptions = options;

    if (this.options.autoInit) {
      this.init();
    }
  }

  protected onInit(): void {
    if (this.element) {
      this.originalHTML = this.element.innerHTML;

      // 设置按钮属性
      this.element.setAttribute('data-sync-type', this.syncOptions.syncType);

      // 添加CSS类
      addClass(
        this.element,
        'sync-button',
        `sync-button-${this.syncOptions.syncType}`
      );
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
   *
   * 收口迭代（2026-11-16）：原实现把 `getSyncTypeName()` 返回的中文名
   * （"智能同步"/"完全同步"）当作 syncType 传给 syncManager，最终以
   * `sync_type` POST 给 PHP —— 后端只能拿到中文，无法区分同步类型。
   * 现改为：传给 PHP 的一律是 ASCII 枚举 'smart' | 'full' | 'test'，
   * 中文名只用于界面提示。
   */
  private async performSync(): Promise<void> {
    switch (this.syncOptions.syncType) {
      case 'smart':
        await this.performSmartSync();
        break;
      case 'full':
        await this.performFullSync();
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
   *
   * 增量默认开启（与 PHP 端 `$incremental` 缺省 true 保持一致），
   * 但允许通过 data-incremental 属性显式覆盖。
   */
  private async performSmartSync(): Promise<void> {
    const result = await syncManager.startSync({
      syncType: this.syncOptions.syncType,
      incremental: this.syncOptions.incremental ?? true,
      checkDeletions: this.syncOptions.checkDeletions ?? true,
      batchSize: 10,
    });

    if (result.success) {
      showInfo(`${this.getSyncTypeName()}已开始`);
    } else {
      throw new Error(result.message);
    }
  }

  /**
   * 执行完全同步
   *
   * 全量同步默认 incremental=false（与智能同步区分），同样允许覆盖。
   */
  private async performFullSync(): Promise<void> {
    const result = await syncManager.startSync({
      syncType: this.syncOptions.syncType,
      incremental: this.syncOptions.incremental ?? false,
      checkDeletions: this.syncOptions.checkDeletions ?? true,
      batchSize: 10,
    });

    if (result.success) {
      showInfo(`${this.getSyncTypeName()}已开始`);
    } else {
      throw new Error(result.message);
    }
  }

  /**
   * 执行测试连接
   *
   * 收口迭代（2026-11-16）：原实现手写 fetch + URLSearchParams 绕过
   * `shared/utils/ajax` 的 nonce/错误/超时约定，且注释自认「暂时使用模拟实现」。
   * 现统一走 post()，与 AdminControls.bindConnectionTest() 保持一致。
   */
  private async performTestConnection(): Promise<void> {
    const apiKey = this.getConfigValue('notion_to_wordpress_api_key');
    const databaseId = this.getConfigValue('notion_to_wordpress_database_id');

    const response = await post<AjaxResponse<{ message: string }>>(
      'notion_to_wordpress_test_connection',
      { api_key: apiKey, database_id: databaseId }
    );

    if (!response.data.success) {
      throw new Error(response.data.data?.message || '连接测试失败');
    }

    showSuccess(response.data.data?.message || '连接测试成功');
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
      if (
        field instanceof HTMLInputElement ||
        field instanceof HTMLTextAreaElement
      ) {
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
      test: '测试连接',
    };

    return typeNames[this.syncOptions.syncType] || '同步';
  }

  /**
   * 设置加载状态
   *
   * 收口迭代（2026-11-16）：还原时用 originalHTML（保存 innerHTML），
   * 保证 `<span class="dashicons">` 图标不会在一次同步后永久消失。
   */
  private setLoadingState(loading: boolean): void {
    if (!this.element) return;

    if (loading) {
      this.element.setAttribute('disabled', 'true');
      addClass(this.element, 'loading');

      const spinner = '<span class="spinner is-active"></span> ';
      this.element.innerHTML = `${spinner}${this.getSyncTypeName()}中...`;
    } else {
      this.element.removeAttribute('disabled');
      removeClass(this.element, 'loading');
      this.element.innerHTML = this.originalHTML;
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
    removeClass(
      this.element,
      'status-idle',
      'status-running',
      'status-completed',
      'status-error',
      'status-paused'
    );

    // 添加当前状态类
    addClass(this.element, `status-${status}`);
  }

  /**
   * 设置按钮文本
   *
   * 注意：这里改的是纯文本，会丢掉图标，因此同步更新 originalHTML，
   * 避免下一次加载还原时把已设置的文本又改回去。
   */
  public setText(text: string): void {
    if (this.element && !this.isProcessing) {
      this.element.textContent = text;
      this.originalHTML = text;
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
