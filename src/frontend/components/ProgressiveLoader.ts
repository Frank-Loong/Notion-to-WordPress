/**
 * 渐进式加载器 - 现代化TypeScript版本
 *
 * 从原有lazy-loading.js的渐进式加载功能完全迁移，包括：
 * - 数据库视图的渐进式加载
 * - 加载状态管理
 * - 内容渲染和集成
 */

import { emit } from '../../shared/core/EventBus';
import { post } from '../../shared/utils/ajax';
import { lazyLoader } from './LazyLoader';

export interface ProgressiveLoadConfig {
  loadingDelay: number;
  retryAttempts: number;
  retryDelay: number;
  batchSize: number;
}

export interface DatabaseRecord {
  id: string;
  properties: Record<string, any>;
  created_time: string;
  last_edited_time: string;
}

export interface ProgressiveLoadData {
  records: DatabaseRecord[];
  hasMore: boolean;
  nextCursor?: string;
}

export interface LoadMoreOptions {
  container: HTMLElement;
  button: HTMLButtonElement;
  endpoint?: string;
  params?: Record<string, any>;
}

/**
 * 渐进式加载器类
 */
export class ProgressiveLoader {
  private static instance: ProgressiveLoader | null = null;

  private config!: ProgressiveLoadConfig;
  private loadingStates = new Map<string, boolean>();
  private loadedData = new Map<string, ProgressiveLoadData>();

  constructor(config: Partial<ProgressiveLoadConfig> = {}) {
    if (ProgressiveLoader.instance) {
      return ProgressiveLoader.instance;
    }

    ProgressiveLoader.instance = this;

    this.config = {
      loadingDelay: 500,
      retryAttempts: 3,
      retryDelay: 1000,
      batchSize: 10,
      ...config,
    };

    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(
    config?: Partial<ProgressiveLoadConfig>
  ): ProgressiveLoader {
    if (!ProgressiveLoader.instance) {
      ProgressiveLoader.instance = new ProgressiveLoader(config);
    }
    return ProgressiveLoader.instance;
  }

  /**
   * 初始化渐进式加载器
   */
  private init(): void {
    this.setupEventListeners();
    console.log('📄 [渐进式加载] 已初始化');
    emit('progressive:loader:initialized');
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 使用事件委托处理加载更多按钮
    document.addEventListener('click', this.handleLoadMoreClick.bind(this));
  }

  /**
   * 处理加载更多按钮点击
   */
  private handleLoadMoreClick(event: Event): void {
    const target = event.target as HTMLElement;
    const button = target.closest(
      '.notion-load-more-button'
    ) as HTMLButtonElement;

    if (button) {
      event.preventDefault();
      this.loadMore(button);
    }
  }

  /**
   * 加载更多内容
   */
  async loadMore(button: HTMLButtonElement): Promise<void> {
    const container = button.closest(
      '.notion-progressive-loading'
    ) as HTMLElement;
    if (!container) {
      console.error('📄 [渐进式加载] 未找到容器元素');
      return;
    }

    const containerId = container.id || this.generateContainerId();

    // 防止重复加载
    if (this.loadingStates.get(containerId)) {
      return;
    }

    try {
      this.setLoadingState(button, true);
      this.loadingStates.set(containerId, true);

      const data = await this.fetchMoreData(container);

      if (data && data.records.length > 0) {
        await this.renderRecords(container, data.records);

        // 如果没有更多数据，隐藏按钮
        if (!data.hasMore) {
          this.hideLoadMoreButton(button);
        }

        // 刷新懒加载
        lazyLoader.refresh();

        emit('progressive:load:success', {
          containerId,
          recordCount: data.records.length,
          hasMore: data.hasMore,
        });

        console.log(`📄 [渐进式加载] 加载完成，记录数: ${data.records.length}`);
      } else {
        this.hideLoadMoreButton(button);
        console.log('📄 [渐进式加载] 没有更多数据');
      }
    } catch (error) {
      this.handleLoadError(button, error as Error);
      emit('progressive:load:error', { containerId, error });
    } finally {
      this.setLoadingState(button, false);
      this.loadingStates.set(containerId, false);
    }
  }

  /**
   * 获取更多数据
   */
  private async fetchMoreData(
    container: HTMLElement
  ): Promise<ProgressiveLoadData | null> {
    const recordsData = container.dataset.records;

    if (recordsData) {
      // 从数据属性中解析数据（静态数据）
      try {
        const data = JSON.parse(atob(recordsData));

        // 模拟API延迟
        await new Promise(resolve =>
          setTimeout(resolve, this.config.loadingDelay)
        );

        return data;
      } catch {
        throw new Error('数据解析失败');
      }
    } else {
      // 从API获取数据（动态数据）
      const endpoint = container.dataset.endpoint;
      if (!endpoint) {
        throw new Error('未配置数据端点');
      }

      const params = this.getLoadParams(container);
      const response = await post(endpoint, params);

      if (response.data.success) {
        return response.data.data;
      } else {
        throw new Error(response.data.message || '数据获取失败');
      }
    }
  }

  /**
   * 获取加载参数
   */
  private getLoadParams(container: HTMLElement): Record<string, any> {
    const params: Record<string, any> = {
      batch_size: this.config.batchSize,
    };

    // 从数据属性中获取参数
    Object.keys(container.dataset).forEach(key => {
      if (key.startsWith('param')) {
        const paramName = key.replace('param', '').toLowerCase();
        params[paramName] = container.dataset[key];
      }
    });

    return params;
  }

  /**
   * 渲染记录
   */
  private async renderRecords(
    container: HTMLElement,
    records: DatabaseRecord[]
  ): Promise<void> {
    const contentContainer = container.querySelector(
      '.notion-progressive-content'
    ) as HTMLElement;
    if (!contentContainer) {
      throw new Error('未找到内容容器');
    }

    const html = records.map(record => this.renderRecord(record)).join('');

    // 使用淡入动画添加内容
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = html;
    tempDiv.style.opacity = '0';
    tempDiv.style.transition = 'opacity 0.3s ease-in-out';

    contentContainer.appendChild(tempDiv);

    // 触发淡入动画
    setTimeout(() => {
      tempDiv.style.opacity = '1';
    }, 10);

    // 动画完成后移除包装div
    setTimeout(() => {
      while (tempDiv.firstChild) {
        contentContainer.appendChild(tempDiv.firstChild);
      }
      tempDiv.remove();
    }, 300);
  }

  /**
   * 渲染单个记录
   */
  private renderRecord(record: DatabaseRecord): string {
    const title = this.extractTitle(record.properties);
    const id = record.id.substring(0, 8);

    return `
      <div class="notion-database-record" data-record-id="${record.id}">
        <div class="notion-record-title">${this.escapeHtml(title)}</div>
        <div class="notion-record-properties">
          <div class="notion-record-property">
            <span class="notion-property-name">ID:</span>
            <span class="notion-property-value">${id}...</span>
          </div>
          <div class="notion-record-property">
            <span class="notion-property-name">创建时间:</span>
            <span class="notion-property-value">${this.formatDate(record.created_time)}</span>
          </div>
        </div>
      </div>
    `;
  }

  /**
   * 提取标题
   */
  private extractTitle(properties: Record<string, any>): string {
    for (const property of Object.values(properties)) {
      if (
        property.type === 'title' &&
        property.title &&
        property.title.length > 0
      ) {
        return property.title[0].plain_text || '无标题';
      }
    }
    return '无标题';
  }

  /**
   * 设置加载状态
   */
  private setLoadingState(button: HTMLButtonElement, loading: boolean): void {
    const loadingText = button.querySelector(
      '.notion-loading-text'
    ) as HTMLElement;
    const loadingSpinner = button.querySelector(
      '.notion-loading-spinner'
    ) as HTMLElement;
    const buttonText = button.querySelector(
      '.notion-button-text'
    ) as HTMLElement;

    if (loading) {
      button.disabled = true;
      if (buttonText) buttonText.style.display = 'none';
      if (loadingText) loadingText.style.display = 'inline';
      if (loadingSpinner) loadingSpinner.style.display = 'inline';
    } else {
      button.disabled = false;
      if (buttonText) buttonText.style.display = 'inline';
      if (loadingText) loadingText.style.display = 'none';
      if (loadingSpinner) loadingSpinner.style.display = 'none';
    }
  }

  /**
   * 处理加载错误
   */
  private handleLoadError(button: HTMLButtonElement, error: Error): void {
    const loadingText = button.querySelector(
      '.notion-loading-text'
    ) as HTMLElement;

    if (loadingText) {
      loadingText.textContent = '加载失败，请重试';
      loadingText.style.display = 'inline';
    }

    console.error('📄 [渐进式加载] 加载失败:', error);

    // 3秒后恢复按钮状态
    setTimeout(() => {
      if (loadingText) {
        loadingText.textContent = '加载中...';
      }
      this.setLoadingState(button, false);
    }, 3000);
  }

  /**
   * 隐藏加载更多按钮
   */
  private hideLoadMoreButton(button: HTMLButtonElement): void {
    const buttonContainer = button.parentElement;
    if (buttonContainer) {
      buttonContainer.style.transition = 'opacity 0.3s ease-out';
      buttonContainer.style.opacity = '0';

      setTimeout(() => {
        buttonContainer.style.display = 'none';
      }, 300);
    }
  }

  /**
   * 生成容器ID
   */
  private generateContainerId(): string {
    return `progressive-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
  }

  /**
   * 转义HTML
   */
  private escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * 格式化日期
   */
  private formatDate(dateString: string): string {
    try {
      const date = new Date(dateString);
      return date.toLocaleDateString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
      });
    } catch {
      return dateString;
    }
  }

  /**
   * 获取配置
   */
  getConfig(): ProgressiveLoadConfig {
    return { ...this.config };
  }

  /**
   * 更新配置
   */
  updateConfig(newConfig: Partial<ProgressiveLoadConfig>): void {
    this.config = { ...this.config, ...newConfig };
    emit('progressive:config:updated', this.config);
  }

  /**
   * 获取加载状态
   */
  getLoadingStates(): Map<string, boolean> {
    return new Map(this.loadingStates);
  }

  /**
   * 清除加载状态
   */
  clearLoadingState(containerId: string): void {
    this.loadingStates.delete(containerId);
    this.loadedData.delete(containerId);
  }

  /**
   * 销毁渐进式加载器
   */
  destroy(): void {
    document.removeEventListener('click', this.handleLoadMoreClick);

    this.loadingStates.clear();
    this.loadedData.clear();

    ProgressiveLoader.instance = null;
    emit('progressive:loader:destroyed');
    console.log('📄 [渐进式加载] 已销毁');
  }
}

// 导出单例实例
export const progressiveLoader = ProgressiveLoader.getInstance();

export default ProgressiveLoader;
