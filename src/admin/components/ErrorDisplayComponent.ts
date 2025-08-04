/**
 * 错误显示组件 - 现代化TypeScript版本
 * 
 * 提供错误信息的可视化显示，包括：
 * - 错误列表和详情
 * - 错误统计和分析
 * - 错误解决和重试
 * - 错误导出和报告
 */

import { BaseComponent, ComponentOptions } from './BaseComponent';
import { errorManager, ErrorInfo, ErrorType, ErrorSeverity } from '../managers/ErrorManager';
import { on } from '../../shared/core/EventBus';
import { showSuccess, showError } from '../../shared/utils/toast';
import { formatTimeDiff } from '../../shared/utils/common';

export interface ErrorDisplayComponentOptions extends ComponentOptions {
  showResolved?: boolean;
  maxDisplayErrors?: number;
  enableExport?: boolean;
  enableRetry?: boolean;
  autoRefresh?: boolean;
  refreshInterval?: number;
}

/**
 * 错误显示组件类
 */
export class ErrorDisplayComponent extends BaseComponent {
  protected options!: ErrorDisplayComponentOptions;
  
  protected defaultOptions: ErrorDisplayComponentOptions = {
    showResolved: false,
    maxDisplayErrors: 50,
    enableExport: true,
    enableRetry: true,
    autoRefresh: true,
    refreshInterval: 30000
  };

  private elements: {
    container?: HTMLElement;
    toolbar?: HTMLElement;
    statsContainer?: HTMLElement;
    errorList?: HTMLElement;
    filterSelect?: HTMLSelectElement;
    severitySelect?: HTMLSelectElement;
    showResolvedToggle?: HTMLInputElement;
    refreshButton?: HTMLButtonElement;
    clearButton?: HTMLButtonElement;
    exportButton?: HTMLButtonElement;
    statusIndicator?: HTMLElement;
  } = {};

  private currentErrors: ErrorInfo[] = [];
  private filteredErrors: ErrorInfo[] = [];
  private currentFilter: { type?: ErrorType; severity?: ErrorSeverity } = {};
  private refreshTimer: NodeJS.Timeout | null = null;

  constructor(options: ErrorDisplayComponentOptions) {
    const finalOptions = {
      ...{
        showResolved: false,
        maxDisplayErrors: 50,
        enableExport: true,
        enableRetry: true,
        autoRefresh: true,
        refreshInterval: 30000
      },
      ...options
    };
    super(finalOptions);
    this.options = finalOptions;
  }

  /**
   * 组件初始化回调
   */
  onInit(): void {
    this.createUI();
    this.setupErrorManagerIntegration();
    this.loadInitialData();
    
    console.log('🚨 [错误显示组件] 已初始化');
  }

  /**
   * 组件挂载回调
   */
  onMount(): void {
    this.setupEventListeners();
    this.startAutoRefresh();
  }

  /**
   * 组件卸载回调
   */
  onUnmount(): void {
    this.stopAutoRefresh();
  }

  /**
   * 组件销毁回调
   */
  onDestroy(): void {
    this.stopAutoRefresh();
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
    console.log('错误显示组件状态变化:', state);
  }

  /**
   * 创建UI
   */
  private createUI(): void {
    if (!this.element) return;

    this.element.className = 'notion-error-display-component';
    this.element.innerHTML = `
      <div class="error-display-header">
        <h3>错误监控</h3>
        <div class="error-status">
          <span class="status-indicator">就绪</span>
        </div>
      </div>
      
      <div class="error-stats-container">
        <div class="error-stats">
          <div class="stat-item">
            <span class="stat-label">总计:</span>
            <span class="stat-value total-count">0</span>
          </div>
          <div class="stat-item">
            <span class="stat-label">未解决:</span>
            <span class="stat-value unresolved-count">0</span>
          </div>
          <div class="stat-item">
            <span class="stat-label">严重:</span>
            <span class="stat-value critical-count">0</span>
          </div>
          <div class="stat-item">
            <span class="stat-label">高级:</span>
            <span class="stat-value high-count">0</span>
          </div>
        </div>
      </div>
      
      <div class="error-toolbar">
        <div class="toolbar-left">
          <select class="error-filter-select">
            <option value="">所有类型</option>
            <option value="AUTH_ERROR">认证错误</option>
            <option value="NETWORK_ERROR">网络错误</option>
            <option value="SERVER_ERROR">服务器错误</option>
            <option value="VALIDATION_ERROR">验证错误</option>
            <option value="RATE_LIMIT_ERROR">速率限制</option>
            <option value="DATA_ERROR">数据错误</option>
            <option value="UNKNOWN_ERROR">未知错误</option>
          </select>
          
          <select class="error-severity-select">
            <option value="">所有严重性</option>
            <option value="critical">严重</option>
            <option value="high">高级</option>
            <option value="medium">中等</option>
            <option value="low">低级</option>
          </select>
          
          <label class="show-resolved-toggle">
            <input type="checkbox" class="show-resolved-checkbox">
            <span>显示已解决</span>
          </label>
        </div>
        
        <div class="toolbar-right">
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
          
          <button class="clear-button" type="button">
            <span class="icon">🗑️</span>
            清除
          </button>
        </div>
      </div>
      
      <div class="error-list-container">
        <div class="error-list">
          <div class="empty-placeholder">
            <div class="empty-icon">✅</div>
            <div class="empty-message">暂无错误记录</div>
          </div>
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
      toolbar: this.element.querySelector('.error-toolbar') as HTMLElement,
      statsContainer: this.element.querySelector('.error-stats') as HTMLElement,
      errorList: this.element.querySelector('.error-list') as HTMLElement,
      filterSelect: this.element.querySelector('.error-filter-select') as HTMLSelectElement,
      severitySelect: this.element.querySelector('.error-severity-select') as HTMLSelectElement,
      showResolvedToggle: this.element.querySelector('.show-resolved-checkbox') as HTMLInputElement,
      refreshButton: this.element.querySelector('.refresh-button') as HTMLButtonElement,
      clearButton: this.element.querySelector('.clear-button') as HTMLButtonElement,
      exportButton: this.element.querySelector('.export-button') as HTMLButtonElement,
      statusIndicator: this.element.querySelector('.status-indicator') as HTMLElement
    };

    // 设置默认值
    if (this.elements.showResolvedToggle) {
      this.elements.showResolvedToggle.checked = this.options.showResolved || false;
    }
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 过滤器变化
    if (this.elements.filterSelect) {
      this.elements.filterSelect.addEventListener('change', () => {
        this.handleFilterChange();
      });
    }

    if (this.elements.severitySelect) {
      this.elements.severitySelect.addEventListener('change', () => {
        this.handleFilterChange();
      });
    }

    if (this.elements.showResolvedToggle) {
      this.elements.showResolvedToggle.addEventListener('change', () => {
        this.handleFilterChange();
      });
    }

    // 按钮事件
    if (this.elements.refreshButton) {
      this.elements.refreshButton.addEventListener('click', () => {
        this.handleRefresh();
      });
    }

    if (this.elements.clearButton) {
      this.elements.clearButton.addEventListener('click', () => {
        this.handleClear();
      });
    }

    if (this.elements.exportButton) {
      this.elements.exportButton.addEventListener('click', () => {
        this.handleExport();
      });
    }
  }

  /**
   * 设置错误管理器集成
   */
  private setupErrorManagerIntegration(): void {
    // 监听错误管理器事件
    on('error:handled', (_event, data) => {
      this.handleNewError(data.errorInfo);
    });

    on('error:resolved', (_event, data) => {
      this.handleErrorResolved(data.errorInfo);
    });

    on('error:retry', (_event, data) => {
      this.handleErrorRetry(data.errorInfo, data.retryCount);
    });

    on('error:history:cleared', () => {
      this.handleHistoryCleared();
    });
  }

  /**
   * 加载初始数据
   */
  private loadInitialData(): void {
    this.refreshErrorList();
  }

  /**
   * 刷新错误列表
   */
  private refreshErrorList(): void {
    this.currentErrors = errorManager.getErrorHistory();
    this.applyFilters();
    this.updateStats();
    this.renderErrorList();
  }

  /**
   * 应用过滤器
   */
  private applyFilters(): void {
    let filtered = [...this.currentErrors];

    // 类型过滤
    if (this.currentFilter.type) {
      filtered = filtered.filter(error => error.type === this.currentFilter.type);
    }

    // 严重性过滤
    if (this.currentFilter.severity) {
      filtered = filtered.filter(error => error.severity === this.currentFilter.severity);
    }

    // 已解决状态过滤
    if (!this.options.showResolved) {
      filtered = filtered.filter(error => !error.resolved);
    }

    // 限制显示数量
    this.filteredErrors = filtered.slice(0, this.options.maxDisplayErrors);
  }

  /**
   * 处理过滤器变化
   */
  private handleFilterChange(): void {
    this.currentFilter = {
      type: this.elements.filterSelect?.value as ErrorType || undefined,
      severity: this.elements.severitySelect?.value as ErrorSeverity || undefined
    };

    this.options.showResolved = this.elements.showResolvedToggle?.checked || false;

    this.applyFilters();
    this.renderErrorList();
  }

  /**
   * 渲染错误列表
   */
  private renderErrorList(): void {
    if (!this.elements.errorList) return;

    if (this.filteredErrors.length === 0) {
      this.elements.errorList.innerHTML = `
        <div class="empty-placeholder">
          <div class="empty-icon">✅</div>
          <div class="empty-message">暂无错误记录</div>
        </div>
      `;
      return;
    }

    const errorsHtml = this.filteredErrors.map(error => this.renderErrorItem(error)).join('');
    this.elements.errorList.innerHTML = errorsHtml;
  }

  /**
   * 渲染单个错误项
   */
  private renderErrorItem(error: ErrorInfo): string {
    const timeAgo = formatTimeDiff(Date.now() - error.timestamp);
    const severityClass = `severity-${error.severity}`;
    const typeClass = `type-${error.type.toLowerCase()}`;
    const resolvedClass = error.resolved ? 'resolved' : '';

    return `
      <div class="error-item ${severityClass} ${typeClass} ${resolvedClass}" data-error-id="${error.id}">
        <div class="error-header">
          <div class="error-type-badge">${this.getTypeDisplayName(error.type)}</div>
          <div class="error-severity-badge">${this.getSeverityDisplayName(error.severity)}</div>
          <div class="error-time">${timeAgo}前</div>
          ${error.resolved ? '<div class="error-resolved-badge">已解决</div>' : ''}
        </div>
        
        <div class="error-message">${this.escapeHtml(error.message)}</div>
        
        ${error.context && Object.keys(error.context).length > 0 ? `
          <div class="error-context">
            <strong>上下文:</strong>
            <pre>${this.escapeHtml(JSON.stringify(error.context, null, 2))}</pre>
          </div>
        ` : ''}
        
        ${error.retryCount && error.retryCount > 0 ? `
          <div class="error-retry-info">
            <span class="retry-count">重试次数: ${error.retryCount}</span>
          </div>
        ` : ''}
        
        <div class="error-actions">
          ${!error.resolved ? `
            <button class="resolve-button" data-error-id="${error.id}">
              <span class="icon">✅</span>
              标记为已解决
            </button>
          ` : ''}
          
          ${this.options.enableRetry && this.canRetry(error) ? `
            <button class="retry-button" data-error-id="${error.id}">
              <span class="icon">🔄</span>
              重试
            </button>
          ` : ''}
          
          <button class="details-button" data-error-id="${error.id}">
            <span class="icon">📋</span>
            详情
          </button>
        </div>
      </div>
    `;
  }

  /**
   * 获取类型显示名称
   */
  private getTypeDisplayName(type: ErrorType): string {
    const typeNames: Record<ErrorType, string> = {
      'AUTH_ERROR': '认证错误',
      'NETWORK_ERROR': '网络错误',
      'SERVER_ERROR': '服务器错误',
      'VALIDATION_ERROR': '验证错误',
      'RATE_LIMIT_ERROR': '速率限制',
      'DATA_ERROR': '数据错误',
      'FILTER_ERROR': '过滤器错误',
      'CLIENT_ERROR': '客户端错误',
      'PERMISSION_ERROR': '权限错误',
      'UNKNOWN_ERROR': '未知错误'
    };
    return typeNames[type] || type;
  }

  /**
   * 获取严重性显示名称
   */
  private getSeverityDisplayName(severity: ErrorSeverity): string {
    const severityNames: Record<ErrorSeverity, string> = {
      'critical': '严重',
      'high': '高级',
      'medium': '中等',
      'low': '低级'
    };
    return severityNames[severity] || severity;
  }

  /**
   * 判断是否可以重试
   */
  private canRetry(error: ErrorInfo): boolean {
    const retryableTypes: ErrorType[] = ['NETWORK_ERROR', 'SERVER_ERROR', 'RATE_LIMIT_ERROR'];
    return retryableTypes.includes(error.type) && !error.resolved;
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
    const stats = errorManager.getErrorStats();

    if (this.elements.statsContainer) {
      const updateStat = (selector: string, value: number) => {
        const element = this.elements.statsContainer!.querySelector(selector);
        if (element) {
          element.textContent = value.toString();
        }
      };

      updateStat('.total-count', stats.total);
      updateStat('.unresolved-count', stats.unresolved);
      updateStat('.critical-count', stats.bySeverity.critical || 0);
      updateStat('.high-count', stats.bySeverity.high || 0);
    }
  }

  /**
   * 处理新错误
   */
  private handleNewError(errorInfo: ErrorInfo): void {
    this.refreshErrorList();
    
    // 如果是严重错误，显示通知
    if (errorInfo.severity === 'critical' || errorInfo.severity === 'high') {
      showError(`发生${this.getSeverityDisplayName(errorInfo.severity)}错误: ${errorInfo.message}`);
    }
  }

  /**
   * 处理错误解决
   */
  private handleErrorResolved(_errorInfo: ErrorInfo): void {
    this.refreshErrorList();
    showSuccess('错误已标记为已解决');
  }

  /**
   * 处理错误重试
   */
  private handleErrorRetry(errorInfo: ErrorInfo, retryCount: number): void {
    this.refreshErrorList();
    console.log(`🔄 错误重试: ${errorInfo.id}, 第${retryCount}次`);
  }

  /**
   * 处理历史清除
   */
  private handleHistoryCleared(): void {
    this.refreshErrorList();
    showSuccess('错误历史已清除');
  }

  /**
   * 处理刷新
   */
  private handleRefresh(): void {
    this.refreshErrorList();
    this.updateStatusIndicator('已刷新');
  }

  /**
   * 处理清除
   */
  private handleClear(): void {
    if (!confirm('确定要清除所有错误历史吗？此操作不可撤销。')) {
      return;
    }

    errorManager.clearErrorHistory();
  }

  /**
   * 处理导出
   */
  private handleExport(): void {
    try {
      const errors = this.filteredErrors.length > 0 ? this.filteredErrors : this.currentErrors;
      const exportData = {
        exported_at: new Date().toISOString(),
        total_errors: errors.length,
        errors: errors.map(error => ({
          id: error.id,
          type: error.type,
          severity: error.severity,
          message: error.message,
          context: error.context,
          timestamp: error.timestamp,
          resolved: error.resolved,
          retryCount: error.retryCount
        }))
      };

      const content = JSON.stringify(exportData, null, 2);
      const filename = `error-report-${new Date().toISOString().split('T')[0]}.json`;
      
      this.downloadFile(content, filename, 'application/json');
      
      showSuccess(`已导出 ${errors.length} 条错误记录`);
    } catch (error) {
      showError(`导出失败: ${(error as Error).message}`);
    }
  }

  /**
   * 下载文件
   */
  private downloadFile(content: string, filename: string, mimeType: string): void {
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.style.display = 'none';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    URL.revokeObjectURL(url);
  }

  /**
   * 更新状态指示器
   */
  private updateStatusIndicator(message: string): void {
    if (this.elements.statusIndicator) {
      this.elements.statusIndicator.textContent = message;
      
      setTimeout(() => {
        if (this.elements.statusIndicator) {
          this.elements.statusIndicator.textContent = '就绪';
        }
      }, 2000);
    }
  }

  /**
   * 开始自动刷新
   */
  private startAutoRefresh(): void {
    if (!this.options.autoRefresh) return;

    this.refreshTimer = setInterval(() => {
      this.refreshErrorList();
    }, this.options.refreshInterval);
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
   * 获取当前错误
   */
  getCurrentErrors(): ErrorInfo[] {
    return [...this.currentErrors];
  }

  /**
   * 获取过滤后的错误
   */
  getFilteredErrors(): ErrorInfo[] {
    return [...this.filteredErrors];
  }

  /**
   * 销毁组件
   */
  destroy(): void {
    this.stopAutoRefresh();
    super.destroy();
    console.log('🗑️ 错误显示组件已销毁');
  }
}

export default ErrorDisplayComponent;
