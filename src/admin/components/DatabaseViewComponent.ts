/**
 * 数据库视图组件 - 现代化TypeScript版本
 * 
 * 提供数据库视图的完整用户界面，包括：
 * - 视图类型切换
 * - 搜索和过滤
 * - 分页和加载更多
 * - 记录显示和交互
 */

import { BaseComponent, ComponentOptions } from './BaseComponent';
import { databaseRecordManager, DatabaseRecord, DatabaseInfo } from '../managers/DatabaseRecordManager';
import { databaseViewRenderer, ViewType, RenderOptions } from '../utils/DatabaseViewRenderer';
import { on, emit } from '../../shared/core/EventBus';
import { showError, showInfo } from '../../shared/utils/toast';

export interface DatabaseViewComponentOptions extends ComponentOptions {
  databaseId: string;
  defaultViewType?: ViewType;
  enableSearch?: boolean;
  enableFilter?: boolean;
  enableSort?: boolean;
  enablePagination?: boolean;
  pageSize?: number;
  autoRefresh?: boolean;
}

/**
 * 数据库视图组件类
 */
export class DatabaseViewComponent extends BaseComponent {
  protected options!: DatabaseViewComponentOptions;
  
  protected defaultOptions: DatabaseViewComponentOptions = {
    databaseId: '',
    defaultViewType: 'table',
    enableSearch: true,
    enableFilter: true,
    enableSort: true,
    enablePagination: true,
    pageSize: 20,
    autoRefresh: false
  };

  private elements: {
    container?: HTMLElement;
    toolbar?: HTMLElement;
    viewTypeSelector?: HTMLSelectElement;
    searchInput?: HTMLInputElement;
    filterButton?: HTMLButtonElement;
    sortButton?: HTMLButtonElement;
    refreshButton?: HTMLButtonElement;
    viewContainer?: HTMLElement;
    loadMoreButton?: HTMLButtonElement;
    statusIndicator?: HTMLElement;
    recordCount?: HTMLElement;
  } = {};

  private currentViewType: ViewType = 'table';
  private currentRecords: DatabaseRecord[] = [];
  private databaseInfo: DatabaseInfo | null = null;
  private isLoading = false;

  constructor(options: DatabaseViewComponentOptions) {
    const finalOptions = {
      ...{
        databaseId: '',
        defaultViewType: 'table' as ViewType,
        enableSearch: true,
        enableFilter: true,
        enableSort: true,
        enablePagination: true,
        pageSize: 20,
        autoRefresh: false
      },
      ...options
    };
    super(finalOptions);
    this.options = finalOptions;
    this.currentViewType = finalOptions.defaultViewType;
  }

  /**
   * 组件初始化回调
   */
  onInit(): void {
    this.createUI();
    this.setupDatabaseManagerIntegration();
    this.loadInitialData();
    
    console.log('📊 [数据库视图组件] 已初始化');
  }

  /**
   * 组件挂载回调
   */
  onMount(): void {
    this.setupEventListeners();
  }

  /**
   * 组件卸载回调
   */
  onUnmount(): void {
    // 清理事件监听器
  }

  /**
   * 组件销毁回调
   */
  onDestroy(): void {
    // 清理资源
  }

  /**
   * 渲染组件
   */
  onRender(): void {
    // 渲染逻辑在createUI中处理
  }

  /**
   * 绑定事件
   */
  bindEvents(): void {
    // 事件绑定逻辑在setupEventListeners中处理
  }

  /**
   * 状态变化回调
   */
  onStateChange(state: any): void {
    console.log('数据库视图组件状态变化:', state);
  }

  /**
   * 创建UI
   */
  private createUI(): void {
    if (!this.element) return;

    this.element.className = 'notion-database-view-component';
    this.element.innerHTML = `
      <div class="database-toolbar">
        <div class="toolbar-left">
          <select class="view-type-selector">
            <option value="table">表格视图</option>
            <option value="list">列表视图</option>
            <option value="gallery">画廊视图</option>
            <option value="board">看板视图</option>
            <option value="calendar">日历视图</option>
            <option value="timeline">时间线视图</option>
          </select>
          
          ${this.options.enableSearch ? `
            <input type="text" class="search-input" placeholder="搜索记录...">
          ` : ''}
          
          ${this.options.enableFilter ? `
            <button class="filter-button" type="button">
              <span class="icon">🔍</span>
              过滤
            </button>
          ` : ''}
          
          ${this.options.enableSort ? `
            <button class="sort-button" type="button">
              <span class="icon">↕️</span>
              排序
            </button>
          ` : ''}
        </div>
        
        <div class="toolbar-right">
          <button class="refresh-button" type="button">
            <span class="icon">🔄</span>
            刷新
          </button>
          
          <div class="status-indicator">
            <span class="loading-spinner" style="display: none;">⏳</span>
            <span class="record-count">0 条记录</span>
          </div>
        </div>
      </div>
      
      <div class="database-view-container">
        <div class="loading-placeholder" style="display: none;">
          <div class="spinner"></div>
          <span>加载中...</span>
        </div>
        
        <div class="empty-placeholder" style="display: none;">
          <div class="empty-icon">📭</div>
          <div class="empty-message">暂无记录</div>
        </div>
        
        <div class="error-placeholder" style="display: none;">
          <div class="error-icon">❌</div>
          <div class="error-message">加载失败</div>
          <button class="retry-button" type="button">重试</button>
        </div>
      </div>
      
      ${this.options.enablePagination ? `
        <div class="database-pagination">
          <button class="load-more-button" type="button" style="display: none;">
            加载更多
          </button>
        </div>
      ` : ''}
    `;

    this.bindElements();
  }

  /**
   * 绑定DOM元素
   */
  private bindElements(): void {
    if (!this.element) return;

    this.elements = {
      container: this.element,
      toolbar: this.element.querySelector('.database-toolbar') as HTMLElement,
      viewTypeSelector: this.element.querySelector('.view-type-selector') as HTMLSelectElement,
      searchInput: this.element.querySelector('.search-input') as HTMLInputElement,
      filterButton: this.element.querySelector('.filter-button') as HTMLButtonElement,
      sortButton: this.element.querySelector('.sort-button') as HTMLButtonElement,
      refreshButton: this.element.querySelector('.refresh-button') as HTMLButtonElement,
      viewContainer: this.element.querySelector('.database-view-container') as HTMLElement,
      loadMoreButton: this.element.querySelector('.load-more-button') as HTMLButtonElement,
      statusIndicator: this.element.querySelector('.status-indicator') as HTMLElement,
      recordCount: this.element.querySelector('.record-count') as HTMLElement
    };

    // 设置默认视图类型
    if (this.elements.viewTypeSelector) {
      this.elements.viewTypeSelector.value = this.currentViewType;
    }
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 视图类型切换
    if (this.elements.viewTypeSelector) {
      this.elements.viewTypeSelector.addEventListener('change', (e) => {
        const target = e.target as HTMLSelectElement;
        this.changeViewType(target.value as ViewType);
      });
    }

    // 搜索
    if (this.elements.searchInput) {
      let searchTimeout: NodeJS.Timeout;
      this.elements.searchInput.addEventListener('input', (e) => {
        const target = e.target as HTMLInputElement;
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
          this.handleSearch(target.value);
        }, 500);
      });
    }

    // 过滤
    if (this.elements.filterButton) {
      this.elements.filterButton.addEventListener('click', () => {
        this.handleFilter();
      });
    }

    // 排序
    if (this.elements.sortButton) {
      this.elements.sortButton.addEventListener('click', () => {
        this.handleSort();
      });
    }

    // 刷新
    if (this.elements.refreshButton) {
      this.elements.refreshButton.addEventListener('click', () => {
        this.handleRefresh();
      });
    }

    // 加载更多
    if (this.elements.loadMoreButton) {
      this.elements.loadMoreButton.addEventListener('click', () => {
        this.handleLoadMore();
      });
    }

    // 重试
    const retryButton = this.element?.querySelector('.retry-button') as HTMLButtonElement;
    if (retryButton) {
      retryButton.addEventListener('click', () => {
        this.loadInitialData();
      });
    }
  }

  /**
   * 设置数据库管理器集成
   */
  private setupDatabaseManagerIntegration(): void {
    // 监听数据库记录事件
    on('database:records:loaded', (_event, data) => {
      if (data.databaseId === this.options.databaseId) {
        this.handleRecordsLoaded(data.records);
      }
    });

    on('database:records:more:loaded', (_event, data) => {
      if (data.databaseId === this.options.databaseId) {
        this.handleMoreRecordsLoaded(data.allRecords);
      }
    });

    on('database:records:error', (_event, data) => {
      if (data.databaseId === this.options.databaseId) {
        this.handleLoadError(data.error);
      }
    });

    on('database:state:changed', (_event, data) => {
      if (data.databaseId === this.options.databaseId) {
        this.handleStateChanged(data.state);
      }
    });
  }

  /**
   * 加载初始数据
   */
  private async loadInitialData(): Promise<void> {
    if (!this.options.databaseId) {
      this.showError('数据库ID未配置');
      return;
    }

    this.setLoading(true);

    try {
      // 首先获取数据库信息
      await this.loadDatabaseInfo();
      
      // 然后获取记录
      await databaseRecordManager.getDatabaseRecords(this.options.databaseId, {
        page_size: this.options.pageSize
      });

      // 启动自动刷新
      if (this.options.autoRefresh) {
        databaseRecordManager.startAutoRefresh(this.options.databaseId);
      }

    } catch (error) {
      console.error('加载初始数据失败:', error);
      this.handleLoadError(error as Error);
    }
  }

  /**
   * 加载数据库信息
   */
  private async loadDatabaseInfo(): Promise<void> {
    // 这里应该调用API获取数据库信息
    // 暂时使用模拟数据
    this.databaseInfo = {
      id: this.options.databaseId,
      title: '数据库',
      properties: {},
      created_time: new Date().toISOString(),
      last_edited_time: new Date().toISOString()
    };
  }

  /**
   * 切换视图类型
   */
  private changeViewType(viewType: ViewType): void {
    this.currentViewType = viewType;
    this.renderCurrentView();
    
    emit('database:view:type:changed', { 
      databaseId: this.options.databaseId, 
      viewType 
    });
  }

  /**
   * 处理搜索
   */
  private async handleSearch(searchTerm: string): Promise<void> {
    if (!searchTerm.trim()) {
      // 如果搜索词为空，重新加载所有记录
      await this.loadInitialData();
      return;
    }

    this.setLoading(true);

    try {
      await databaseRecordManager.searchRecords(
        this.options.databaseId,
        searchTerm
      );
    } catch (error) {
      console.error('搜索失败:', error);
      showError(`搜索失败: ${(error as Error).message}`);
    }
  }

  /**
   * 处理过滤
   */
  private handleFilter(): void {
    // 这里可以打开过滤对话框
    showInfo('过滤功能开发中...');
  }

  /**
   * 处理排序
   */
  private handleSort(): void {
    // 这里可以打开排序对话框
    showInfo('排序功能开发中...');
  }

  /**
   * 处理刷新
   */
  private async handleRefresh(): Promise<void> {
    try {
      await databaseRecordManager.refreshDatabase(this.options.databaseId);
    } catch (error) {
      console.error('刷新失败:', error);
      showError(`刷新失败: ${(error as Error).message}`);
    }
  }

  /**
   * 处理加载更多
   */
  private async handleLoadMore(): Promise<void> {
    if (this.isLoading) return;

    this.setLoadMoreLoading(true);

    try {
      await databaseRecordManager.loadMoreRecords(this.options.databaseId);
    } catch (error) {
      console.error('加载更多失败:', error);
      showError(`加载更多失败: ${(error as Error).message}`);
    } finally {
      this.setLoadMoreLoading(false);
    }
  }

  /**
   * 处理记录加载完成
   */
  private handleRecordsLoaded(records: DatabaseRecord[]): void {
    this.currentRecords = records;
    this.setLoading(false);
    this.renderCurrentView();
    this.updateRecordCount(records.length);
    this.updateLoadMoreButton();
  }

  /**
   * 处理更多记录加载完成
   */
  private handleMoreRecordsLoaded(allRecords: DatabaseRecord[]): void {
    this.currentRecords = allRecords;
    this.renderCurrentView();
    this.updateRecordCount(allRecords.length);
    this.updateLoadMoreButton();
  }

  /**
   * 处理加载错误
   */
  private handleLoadError(error: Error): void {
    this.setLoading(false);
    this.showError(error.message);
  }

  /**
   * 处理状态变化
   */
  private handleStateChanged(state: any): void {
    this.updateLoadMoreButton();
    
    if (state.loading !== this.isLoading) {
      this.setLoading(state.loading);
    }
  }

  /**
   * 渲染当前视图
   */
  private renderCurrentView(): void {
    if (!this.elements.viewContainer || !this.databaseInfo) return;

    // 清空容器
    this.elements.viewContainer.innerHTML = '';

    if (this.currentRecords.length === 0) {
      this.showEmpty();
      return;
    }

    // 创建视图容器
    const viewElement = document.createElement('div');
    viewElement.className = `notion-database-view notion-database-view-${this.currentViewType}`;

    // 渲染视图
    const renderOptions: RenderOptions = {
      viewType: this.currentViewType,
      enableInteraction: true,
      responsive: true
    };

    databaseViewRenderer.renderDatabase(
      viewElement,
      this.databaseInfo,
      this.currentRecords,
      renderOptions
    );

    this.elements.viewContainer.appendChild(viewElement);
    this.hideAllPlaceholders();
  }

  /**
   * 设置加载状态
   */
  private setLoading(loading: boolean): void {
    this.isLoading = loading;

    if (loading) {
      this.showLoading();
    } else {
      this.hideLoading();
    }

    // 更新刷新按钮状态
    if (this.elements.refreshButton) {
      this.elements.refreshButton.disabled = loading;
    }
  }

  /**
   * 设置加载更多状态
   */
  private setLoadMoreLoading(loading: boolean): void {
    if (this.elements.loadMoreButton) {
      this.elements.loadMoreButton.disabled = loading;
      this.elements.loadMoreButton.textContent = loading ? '加载中...' : '加载更多';
    }
  }

  /**
   * 显示加载状态
   */
  private showLoading(): void {
    this.hideAllPlaceholders();
    const loadingPlaceholder = this.element?.querySelector('.loading-placeholder') as HTMLElement;
    if (loadingPlaceholder) {
      loadingPlaceholder.style.display = 'flex';
    }

    // 显示加载指示器
    const spinner = this.elements.statusIndicator?.querySelector('.loading-spinner') as HTMLElement;
    if (spinner) {
      spinner.style.display = 'inline';
    }
  }

  /**
   * 隐藏加载状态
   */
  private hideLoading(): void {
    const loadingPlaceholder = this.element?.querySelector('.loading-placeholder') as HTMLElement;
    if (loadingPlaceholder) {
      loadingPlaceholder.style.display = 'none';
    }

    // 隐藏加载指示器
    const spinner = this.elements.statusIndicator?.querySelector('.loading-spinner') as HTMLElement;
    if (spinner) {
      spinner.style.display = 'none';
    }
  }

  /**
   * 显示空状态
   */
  private showEmpty(): void {
    this.hideAllPlaceholders();
    const emptyPlaceholder = this.element?.querySelector('.empty-placeholder') as HTMLElement;
    if (emptyPlaceholder) {
      emptyPlaceholder.style.display = 'flex';
    }
  }

  /**
   * 显示错误状态
   */
  private showError(message: string): void {
    this.hideAllPlaceholders();
    const errorPlaceholder = this.element?.querySelector('.error-placeholder') as HTMLElement;
    if (errorPlaceholder) {
      const errorMessage = errorPlaceholder.querySelector('.error-message') as HTMLElement;
      if (errorMessage) {
        errorMessage.textContent = message;
      }
      errorPlaceholder.style.display = 'flex';
    }
  }

  /**
   * 隐藏所有占位符
   */
  private hideAllPlaceholders(): void {
    const placeholders = this.element?.querySelectorAll('.loading-placeholder, .empty-placeholder, .error-placeholder');
    placeholders?.forEach(placeholder => {
      (placeholder as HTMLElement).style.display = 'none';
    });
  }

  /**
   * 更新记录数量
   */
  private updateRecordCount(count: number): void {
    if (this.elements.recordCount) {
      this.elements.recordCount.textContent = `${count} 条记录`;
    }
  }

  /**
   * 更新加载更多按钮
   */
  private updateLoadMoreButton(): void {
    if (!this.elements.loadMoreButton) return;

    const state = databaseRecordManager.getDatabaseState(this.options.databaseId);
    
    if (state && state.hasMore && !state.loading) {
      this.elements.loadMoreButton.style.display = 'block';
    } else {
      this.elements.loadMoreButton.style.display = 'none';
    }
  }

  /**
   * 获取当前记录
   */
  getCurrentRecords(): DatabaseRecord[] {
    return [...this.currentRecords];
  }

  /**
   * 获取当前视图类型
   */
  getCurrentViewType(): ViewType {
    return this.currentViewType;
  }

  /**
   * 获取数据库信息
   */
  getDatabaseInfo(): DatabaseInfo | null {
    return this.databaseInfo;
  }

  /**
   * 销毁组件
   */
  destroy(): void {
    // 停止自动刷新
    databaseRecordManager.stopAutoRefresh(this.options.databaseId);
    
    // 清理状态
    databaseRecordManager.clearDatabaseState(this.options.databaseId);
    
    super.destroy();
    console.log('🗑️ 数据库视图组件已销毁');
  }
}

export default DatabaseViewComponent;
