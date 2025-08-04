/**
 * 日志查看器组件 - 现代化TypeScript版本
 * 
 * 提供日志查看的完整用户界面，包括：
 * - 日志文件选择
 * - 实时日志显示
 * - 过滤和搜索
 * - 导出和清理功能
 */

import { BaseComponent, ComponentOptions } from './BaseComponent';
import { logManager, LogEntry, LogFilter } from '../managers/LogManager';
import { on, emit } from '../../shared/core/EventBus';
import { showInfo } from '../../shared/utils/toast';
import { formatTimeDiff } from '../../shared/utils/common';

export interface LogViewerComponentOptions extends ComponentOptions {
  viewMode?: 'structured' | 'raw';
  enableAutoRefresh?: boolean;
  enableExport?: boolean;
  enableClear?: boolean;
  maxDisplayEntries?: number;
  refreshInterval?: number;
}

/**
 * 日志查看器组件类
 */
export class LogViewerComponent extends BaseComponent {
  protected options!: LogViewerComponentOptions;
  
  protected defaultOptions: LogViewerComponentOptions = {
    viewMode: 'structured',
    enableAutoRefresh: true,
    enableExport: true,
    enableClear: true,
    maxDisplayEntries: 500,
    refreshInterval: 10000
  };

  private elements: {
    container?: HTMLElement;
    toolbar?: HTMLElement;
    fileSelector?: HTMLSelectElement;
    viewModeToggle?: HTMLSelectElement;
    searchInput?: HTMLInputElement;
    levelFilter?: HTMLSelectElement;
    sourceFilter?: HTMLSelectElement;
    dateFromInput?: HTMLInputElement;
    dateToInput?: HTMLInputElement;
    autoRefreshToggle?: HTMLInputElement;
    refreshButton?: HTMLButtonElement;
    clearButton?: HTMLButtonElement;
    exportButton?: HTMLButtonElement;
    logContainer?: HTMLElement;
    rawViewer?: HTMLTextAreaElement;
    structuredViewer?: HTMLElement;
    statsContainer?: HTMLElement;
    statusIndicator?: HTMLElement;
  } = {};

  private currentViewMode: 'structured' | 'raw' = 'structured';
  private currentLogs: LogEntry[] = [];
  private currentFilter: LogFilter = {};

  constructor(options: LogViewerComponentOptions) {
    const finalOptions = {
      ...{
        viewMode: 'structured' as const,
        enableAutoRefresh: true,
        enableExport: true,
        enableClear: true,
        maxDisplayEntries: 500,
        refreshInterval: 10000
      },
      ...options
    };
    super(finalOptions);
    this.options = finalOptions;
    this.currentViewMode = finalOptions.viewMode;
  }

  /**
   * 组件初始化回调
   */
  onInit(): void {
    this.createUI();
    this.setupLogManagerIntegration();
    this.loadInitialData();
    
    console.log('📋 [日志查看器组件] 已初始化');
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
    console.log('日志查看器组件状态变化:', state);
  }

  /**
   * 创建UI
   */
  private createUI(): void {
    if (!this.element) return;

    this.element.className = 'notion-log-viewer-component';
    this.element.innerHTML = `
      <div class="log-viewer-toolbar">
        <div class="toolbar-left">
          <select class="log-file-selector">
            <option value="">选择日志文件...</option>
          </select>
          
          <select class="view-mode-toggle">
            <option value="structured">结构化视图</option>
            <option value="raw">原始文本</option>
          </select>
          
          <input type="text" class="log-search-input" placeholder="搜索日志...">
        </div>
        
        <div class="toolbar-center">
          <select class="log-level-filter">
            <option value="">所有级别</option>
            <option value="debug">Debug</option>
            <option value="info">Info</option>
            <option value="warning">Warning</option>
            <option value="error">Error</option>
          </select>
          
          <select class="log-source-filter">
            <option value="">所有来源</option>
          </select>
          
          <input type="date" class="log-date-from" title="开始日期">
          <input type="date" class="log-date-to" title="结束日期">
        </div>
        
        <div class="toolbar-right">
          ${this.options.enableAutoRefresh ? `
            <label class="auto-refresh-toggle">
              <input type="checkbox" class="auto-refresh-checkbox">
              <span>自动刷新</span>
            </label>
          ` : ''}
          
          <button class="refresh-button" type="button">
            <span class="icon">🔄</span>
            刷新
          </button>
          
          ${this.options.enableExport ? `
            <button class="export-button" type="button">
              <span class="icon">📥</span>
              导出
            </button>
          ` : ''}
          
          ${this.options.enableClear ? `
            <button class="clear-button" type="button">
              <span class="icon">🗑️</span>
              清除
            </button>
          ` : ''}
        </div>
      </div>
      
      <div class="log-stats-container">
        <div class="log-stats">
          <span class="stat-item">
            <span class="stat-label">总计:</span>
            <span class="stat-value total-count">0</span>
          </span>
          <span class="stat-item">
            <span class="stat-label">错误:</span>
            <span class="stat-value error-count">0</span>
          </span>
          <span class="stat-item">
            <span class="stat-label">警告:</span>
            <span class="stat-value warning-count">0</span>
          </span>
          <span class="stat-item">
            <span class="stat-label">信息:</span>
            <span class="stat-value info-count">0</span>
          </span>
          <span class="stat-item">
            <span class="stat-label">调试:</span>
            <span class="stat-value debug-count">0</span>
          </span>
        </div>
        
        <div class="log-status">
          <span class="loading-indicator" style="display: none;">⏳ 加载中...</span>
          <span class="status-text">就绪</span>
        </div>
      </div>
      
      <div class="log-content-container">
        <div class="log-structured-viewer" style="display: block;">
          <div class="log-entries-container">
            <div class="empty-placeholder">
              <div class="empty-icon">📋</div>
              <div class="empty-message">选择日志文件开始查看</div>
            </div>
          </div>
        </div>
        
        <div class="log-raw-viewer" style="display: none;">
          <textarea class="log-raw-content" readonly placeholder="选择日志文件开始查看..."></textarea>
        </div>
      </div>
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
      toolbar: this.element.querySelector('.log-viewer-toolbar') as HTMLElement,
      fileSelector: this.element.querySelector('.log-file-selector') as HTMLSelectElement,
      viewModeToggle: this.element.querySelector('.view-mode-toggle') as HTMLSelectElement,
      searchInput: this.element.querySelector('.log-search-input') as HTMLInputElement,
      levelFilter: this.element.querySelector('.log-level-filter') as HTMLSelectElement,
      sourceFilter: this.element.querySelector('.log-source-filter') as HTMLSelectElement,
      dateFromInput: this.element.querySelector('.log-date-from') as HTMLInputElement,
      dateToInput: this.element.querySelector('.log-date-to') as HTMLInputElement,
      autoRefreshToggle: this.element.querySelector('.auto-refresh-checkbox') as HTMLInputElement,
      refreshButton: this.element.querySelector('.refresh-button') as HTMLButtonElement,
      clearButton: this.element.querySelector('.clear-button') as HTMLButtonElement,
      exportButton: this.element.querySelector('.export-button') as HTMLButtonElement,
      logContainer: this.element.querySelector('.log-content-container') as HTMLElement,
      rawViewer: this.element.querySelector('.log-raw-content') as HTMLTextAreaElement,
      structuredViewer: this.element.querySelector('.log-structured-viewer') as HTMLElement,
      statsContainer: this.element.querySelector('.log-stats') as HTMLElement,
      statusIndicator: this.element.querySelector('.log-status') as HTMLElement
    };

    // 设置默认视图模式
    if (this.elements.viewModeToggle) {
      this.elements.viewModeToggle.value = this.currentViewMode;
    }
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 文件选择
    if (this.elements.fileSelector) {
      this.elements.fileSelector.addEventListener('change', (e) => {
        const target = e.target as HTMLSelectElement;
        this.handleFileChange(target.value);
      });
    }

    // 视图模式切换
    if (this.elements.viewModeToggle) {
      this.elements.viewModeToggle.addEventListener('change', (e) => {
        const target = e.target as HTMLSelectElement;
        this.changeViewMode(target.value as 'structured' | 'raw');
      });
    }

    // 搜索
    if (this.elements.searchInput) {
      let searchTimeout: NodeJS.Timeout;
      this.elements.searchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
          this.handleFilterChange();
        }, 500);
      });
    }

    // 过滤器
    [
      this.elements.levelFilter,
      this.elements.sourceFilter,
      this.elements.dateFromInput,
      this.elements.dateToInput
    ].forEach(element => {
      if (element) {
        element.addEventListener('change', () => {
          this.handleFilterChange();
        });
      }
    });

    // 自动刷新
    if (this.elements.autoRefreshToggle) {
      this.elements.autoRefreshToggle.addEventListener('change', (e) => {
        const target = e.target as HTMLInputElement;
        this.handleAutoRefreshToggle(target.checked);
      });
    }

    // 刷新按钮
    if (this.elements.refreshButton) {
      this.elements.refreshButton.addEventListener('click', () => {
        this.handleRefresh();
      });
    }

    // 导出按钮
    if (this.elements.exportButton) {
      this.elements.exportButton.addEventListener('click', () => {
        this.handleExport();
      });
    }

    // 清除按钮
    if (this.elements.clearButton) {
      this.elements.clearButton.addEventListener('click', () => {
        this.handleClear();
      });
    }
  }

  /**
   * 设置日志管理器集成
   */
  private setupLogManagerIntegration(): void {
    // 监听日志管理器事件
    on('log:files:loaded', (_event, data) => {
      this.updateFileSelector(data.files);
    });

    on('log:entries:loaded', (_event, data) => {
      this.handleLogsLoaded(data.logs, data.filtered);
    });

    on('log:content:loaded', (_event, data) => {
      this.handleContentLoaded(data.content);
    });

    on('log:loading:changed', (_event, data) => {
      this.setLoading(data.loading);
    });

    on('log:filtered', () => {
      this.updateStats();
    });

    on('log:cleared', () => {
      this.handleLogsCleared();
    });
  }

  /**
   * 加载初始数据
   */
  private async loadInitialData(): Promise<void> {
    try {
      // 加载日志文件列表
      const files = await logManager.loadLogFiles();
      this.updateFileSelector(files);
      
      // 如果有文件，加载第一个
      if (files.length > 0) {
        this.handleFileChange(files[0]);
      }
    } catch (error) {
      console.error('加载初始数据失败:', error);
      this.showError('加载日志文件失败');
    }
  }

  /**
   * 更新文件选择器
   */
  private updateFileSelector(files: string[]): void {
    if (!this.elements.fileSelector) return;

    // 清空现有选项
    this.elements.fileSelector.innerHTML = '<option value="">选择日志文件...</option>';

    // 添加文件选项
    files.forEach(file => {
      const option = document.createElement('option');
      option.value = file;
      option.textContent = file;
      this.elements.fileSelector!.appendChild(option);
    });
  }

  /**
   * 处理文件变化
   */
  private async handleFileChange(file: string): Promise<void> {
    if (!file) {
      this.clearDisplay();
      return;
    }

    this.setLoading(true);

    try {
      if (this.currentViewMode === 'raw') {
        // 加载原始内容
        const content = await logManager.loadLogContent(file);
        this.handleContentLoaded(content);
      } else {
        // 加载结构化日志
        await logManager.getLogs({ file });
      }
    } catch (error) {
      console.error('加载日志文件失败:', error);
      this.showError(`加载日志文件失败: ${(error as Error).message}`);
    }
  }

  /**
   * 切换视图模式
   */
  private changeViewMode(mode: 'structured' | 'raw'): void {
    this.currentViewMode = mode;

    // 切换显示
    if (this.elements.structuredViewer && this.elements.rawViewer) {
      if (mode === 'structured') {
        this.elements.structuredViewer.style.display = 'block';
        this.elements.rawViewer.style.display = 'none';
      } else {
        this.elements.structuredViewer.style.display = 'none';
        this.elements.rawViewer.style.display = 'block';
      }
    }

    // 重新加载当前文件
    const currentFile = this.elements.fileSelector?.value;
    if (currentFile) {
      this.handleFileChange(currentFile);
    }

    emit('log:viewer:mode:changed', { mode });
  }

  /**
   * 处理过滤器变化
   */
  private handleFilterChange(): void {
    this.currentFilter = {
      level: this.elements.levelFilter?.value || undefined,
      source: this.elements.sourceFilter?.value || undefined,
      dateFrom: this.elements.dateFromInput?.value || undefined,
      dateTo: this.elements.dateToInput?.value || undefined,
      search: this.elements.searchInput?.value || undefined,
      file: this.elements.fileSelector?.value || undefined
    };

    // 移除空值
    Object.keys(this.currentFilter).forEach(key => {
      if (!this.currentFilter[key as keyof LogFilter]) {
        delete this.currentFilter[key as keyof LogFilter];
      }
    });

    logManager.setFilter(this.currentFilter);
  }

  /**
   * 处理自动刷新切换
   */
  private handleAutoRefreshToggle(enabled: boolean): void {
    if (enabled) {
      logManager.startAutoRefresh();
      showInfo('自动刷新已启用');
    } else {
      logManager.stopAutoRefresh();
      showInfo('自动刷新已禁用');
    }
  }

  /**
   * 处理刷新
   */
  private async handleRefresh(): Promise<void> {
    const currentFile = this.elements.fileSelector?.value;
    if (currentFile) {
      await this.handleFileChange(currentFile);
    } else {
      await logManager.refreshLogs();
    }
  }

  /**
   * 处理导出
   */
  private async handleExport(): Promise<void> {
    // 显示导出选项对话框
    const format = await this.showExportDialog();
    if (format) {
      await logManager.exportLogs(format);
    }
  }

  /**
   * 显示导出对话框
   */
  private async showExportDialog(): Promise<'txt' | 'json' | 'csv' | null> {
    return new Promise((resolve) => {
      const dialog = document.createElement('div');
      dialog.className = 'export-dialog-overlay';
      dialog.innerHTML = `
        <div class="export-dialog">
          <h3>导出日志</h3>
          <div class="export-options">
            <label>
              <input type="radio" name="export-format" value="txt" checked>
              文本格式 (.txt)
            </label>
            <label>
              <input type="radio" name="export-format" value="json">
              JSON格式 (.json)
            </label>
            <label>
              <input type="radio" name="export-format" value="csv">
              CSV格式 (.csv)
            </label>
          </div>
          <div class="export-actions">
            <button class="export-confirm">导出</button>
            <button class="export-cancel">取消</button>
          </div>
        </div>
      `;

      document.body.appendChild(dialog);

      const confirmBtn = dialog.querySelector('.export-confirm') as HTMLButtonElement;
      const cancelBtn = dialog.querySelector('.export-cancel') as HTMLButtonElement;

      confirmBtn.addEventListener('click', () => {
        const selectedFormat = dialog.querySelector('input[name="export-format"]:checked') as HTMLInputElement;
        document.body.removeChild(dialog);
        resolve(selectedFormat.value as 'txt' | 'json' | 'csv');
      });

      cancelBtn.addEventListener('click', () => {
        document.body.removeChild(dialog);
        resolve(null);
      });

      dialog.addEventListener('click', (e) => {
        if (e.target === dialog) {
          document.body.removeChild(dialog);
          resolve(null);
        }
      });
    });
  }

  /**
   * 处理清除
   */
  private async handleClear(): Promise<void> {
    if (!confirm('确定要清除所有日志吗？此操作不可撤销。')) {
      return;
    }

    await logManager.clearLogs();
  }

  /**
   * 处理日志加载完成
   */
  private handleLogsLoaded(logs: LogEntry[], filtered: LogEntry[]): void {
    this.currentLogs = filtered;
    
    if (this.currentViewMode === 'structured') {
      this.renderStructuredLogs(filtered);
    }
    
    this.updateStats();
    this.updateSourceFilter(logs);
  }

  /**
   * 处理内容加载完成
   */
  private handleContentLoaded(content: string): void {
    if (this.currentViewMode === 'raw' && this.elements.rawViewer) {
      this.elements.rawViewer.value = content;
    }
  }

  /**
   * 处理日志清除
   */
  private handleLogsCleared(): void {
    this.currentLogs = [];
    this.clearDisplay();
    this.updateStats();
  }

  /**
   * 渲染结构化日志
   */
  private renderStructuredLogs(logs: LogEntry[]): void {
    const container = this.elements.structuredViewer?.querySelector('.log-entries-container');
    if (!container) return;

    if (logs.length === 0) {
      container.innerHTML = `
        <div class="empty-placeholder">
          <div class="empty-icon">📋</div>
          <div class="empty-message">没有找到日志记录</div>
        </div>
      `;
      return;
    }

    // 限制显示数量
    const displayLogs = logs.slice(0, this.options.maxDisplayEntries);
    
    const logsHtml = displayLogs.map(log => this.renderLogEntry(log)).join('');
    container.innerHTML = `<div class="log-entries">${logsHtml}</div>`;

    if (logs.length > this.options.maxDisplayEntries!) {
      const moreInfo = document.createElement('div');
      moreInfo.className = 'log-more-info';
      moreInfo.textContent = `显示前 ${this.options.maxDisplayEntries} 条，共 ${logs.length} 条记录`;
      container.appendChild(moreInfo);
    }
  }

  /**
   * 渲染单个日志条目
   */
  private renderLogEntry(log: LogEntry): string {
    const timeAgo = formatTimeDiff(Date.now() - log.timestamp);
    const timestamp = new Date(log.timestamp).toLocaleString();
    const contextHtml = log.context ? 
      `<div class="log-context"><pre>${JSON.stringify(log.context, null, 2)}</pre></div>` : '';

    return `
      <div class="log-entry log-${log.level}" data-log-id="${log.id}">
        <div class="log-header">
          <span class="log-level">${log.level.toUpperCase()}</span>
          <span class="log-source">${this.escapeHtml(log.source)}</span>
          <span class="log-time" title="${timestamp}">
            ${timeAgo}前
          </span>
        </div>
        <div class="log-message">${this.escapeHtml(log.message)}</div>
        ${contextHtml}
      </div>
    `;
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
   * 更新统计信息
   */
  private updateStats(): void {
    const stats = logManager.getStats();
    
    if (this.elements.statsContainer) {
      const updateStat = (selector: string, value: number) => {
        const element = this.elements.statsContainer!.querySelector(selector);
        if (element) {
          element.textContent = value.toString();
        }
      };

      updateStat('.total-count', stats.total);
      updateStat('.error-count', stats.error);
      updateStat('.warning-count', stats.warning);
      updateStat('.info-count', stats.info);
      updateStat('.debug-count', stats.debug);
    }
  }

  /**
   * 更新来源过滤器
   */
  private updateSourceFilter(logs: LogEntry[]): void {
    if (!this.elements.sourceFilter) return;

    const sources = [...new Set(logs.map(log => log.source))];
    
    // 保存当前选择
    const currentValue = this.elements.sourceFilter.value;
    
    // 清空并重新填充
    this.elements.sourceFilter.innerHTML = '<option value="">所有来源</option>';
    
    sources.forEach(source => {
      const option = document.createElement('option');
      option.value = source;
      option.textContent = source;
      this.elements.sourceFilter!.appendChild(option);
    });
    
    // 恢复选择
    if (currentValue && sources.includes(currentValue)) {
      this.elements.sourceFilter.value = currentValue;
    }
  }

  /**
   * 设置加载状态
   */
  private setLoading(loading: boolean): void {

    // 更新状态指示器
    if (this.elements.statusIndicator) {
      const loadingIndicator = this.elements.statusIndicator.querySelector('.loading-indicator') as HTMLElement;
      const statusText = this.elements.statusIndicator.querySelector('.status-text') as HTMLElement;
      
      if (loadingIndicator && statusText) {
        if (loading) {
          loadingIndicator.style.display = 'inline';
          statusText.textContent = '加载中...';
        } else {
          loadingIndicator.style.display = 'none';
          statusText.textContent = '就绪';
        }
      }
    }

    // 禁用/启用按钮
    [this.elements.refreshButton, this.elements.clearButton, this.elements.exportButton].forEach(button => {
      if (button) {
        button.disabled = loading;
      }
    });
  }

  /**
   * 清除显示
   */
  private clearDisplay(): void {
    // 清除结构化视图
    const structuredContainer = this.elements.structuredViewer?.querySelector('.log-entries-container');
    if (structuredContainer) {
      structuredContainer.innerHTML = `
        <div class="empty-placeholder">
          <div class="empty-icon">📋</div>
          <div class="empty-message">选择日志文件开始查看</div>
        </div>
      `;
    }

    // 清除原始视图
    if (this.elements.rawViewer) {
      this.elements.rawViewer.value = '';
    }
  }

  /**
   * 显示错误
   */
  private showError(message: string): void {
    const container = this.elements.structuredViewer?.querySelector('.log-entries-container');
    if (container) {
      container.innerHTML = `
        <div class="error-placeholder">
          <div class="error-icon">❌</div>
          <div class="error-message">${this.escapeHtml(message)}</div>
        </div>
      `;
    }
  }

  /**
   * 获取当前日志
   */
  getCurrentLogs(): LogEntry[] {
    return [...this.currentLogs];
  }

  /**
   * 获取当前视图模式
   */
  getCurrentViewMode(): 'structured' | 'raw' {
    return this.currentViewMode;
  }

  /**
   * 销毁组件
   */
  destroy(): void {
    // 停止自动刷新
    logManager.stopAutoRefresh();
    
    super.destroy();
    console.log('🗑️ 日志查看器组件已销毁');
  }
}

export default LogViewerComponent;
