/**
 * 日志模块 - 懒加载
 */

import { BaseComponent } from '../components/BaseComponent';
import { showSuccess, showError } from '../../shared/utils/toast';
import { post } from '../../shared/utils/ajax';
import { formatTimeDiff } from '../../shared/utils/common';

export interface LogEntry {
  id: string;
  timestamp: number;
  level: 'debug' | 'info' | 'warning' | 'error';
  message: string;
  context?: any;
  source: string;
}

export interface LogFilter {
  level?: string;
  source?: string;
  dateFrom?: string;
  dateTo?: string;
  search?: string;
}

/**
 * 日志模块类
 */
export class LogsModule extends BaseComponent {
  private logs: LogEntry[] = [];
  private filteredLogs: LogEntry[] = [];
  private currentFilter: LogFilter = {};
  private autoRefresh = false;
  private refreshTimer: NodeJS.Timeout | null = null;
  private refreshInterval = 10000; // 10秒

  protected onInit(): void {
    console.log('Logs module initialized');
  }

  protected onMount(): void {
    this.loadLogs();
    this.setupAutoRefresh();
  }

  protected onUnmount(): void {
    this.stopAutoRefresh();
  }

  protected onDestroy(): void {
    this.stopAutoRefresh();
  }

  protected onRender(): void {
    this.renderLogs();
  }

  protected bindEvents(): void {
    // 绑定刷新按钮
    const refreshButton = this.$('#refresh-logs');
    if (refreshButton) {
      this.addEventListener(refreshButton, 'click', this.handleRefresh.bind(this));
    }

    // 绑定清空日志按钮
    const clearButton = this.$('#clear-logs');
    if (clearButton) {
      this.addEventListener(clearButton, 'click', this.handleClear.bind(this));
    }

    // 绑定导出按钮
    const exportButton = this.$('#export-logs');
    if (exportButton) {
      this.addEventListener(exportButton, 'click', this.handleExport.bind(this));
    }

    // 绑定自动刷新开关
    const autoRefreshToggle = this.$('#auto-refresh') as HTMLInputElement;
    if (autoRefreshToggle) {
      this.addEventListener(autoRefreshToggle, 'change', this.handleAutoRefreshToggle.bind(this));
    }

    // 绑定过滤器
    this.bindFilterEvents();
  }

  protected onStateChange(_state: any, _prevState: any, _action: any): void {
    // 响应状态变化
  }

  /**
   * 绑定过滤器事件
   */
  private bindFilterEvents(): void {
    const filterElements = this.$$('.log-filter');
    
    filterElements.forEach(element => {
      this.addEventListener(element, 'change', this.handleFilterChange.bind(this));
    });

    // 搜索框
    const searchInput = this.$('#log-search') as HTMLInputElement;
    if (searchInput) {
      let searchTimeout: NodeJS.Timeout;
      
      this.addEventListener(searchInput, 'input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
          this.handleFilterChange();
        }, 300);
      });
    }
  }

  /**
   * 加载日志
   */
  private async loadLogs(): Promise<void> {
    try {
      const response = await post('notion_to_wordpress_get_logs', {
        limit: 1000,
        ...this.currentFilter
      });

      if (response.data.success) {
        this.logs = response.data.data;
        this.applyFilters();
        this.render();
        console.log(`Loaded ${this.logs.length} log entries`);
      } else {
        throw new Error(response.data.message || '加载日志失败');
      }
    } catch (error) {
      console.error('Failed to load logs:', error);
      showError(`加载日志失败: ${(error as Error).message}`);
    }
  }

  /**
   * 渲染日志
   */
  private renderLogs(): void {
    const container = this.$('#logs-container');
    if (!container) return;

    if (this.filteredLogs.length === 0) {
      container.innerHTML = '<div class="no-logs">没有找到日志记录</div>';
      return;
    }

    const logsHtml = this.filteredLogs.map(log => this.renderLogEntry(log)).join('');
    container.innerHTML = `<div class="logs-list">${logsHtml}</div>`;

    // 更新统计信息
    this.updateStats();
  }

  /**
   * 渲染单个日志条目
   */
  private renderLogEntry(log: LogEntry): string {
    const timeAgo = formatTimeDiff(Date.now() - log.timestamp);
    const contextHtml = log.context ? 
      `<div class="log-context">${JSON.stringify(log.context, null, 2)}</div>` : '';

    return `
      <div class="log-entry log-${log.level}" data-log-id="${log.id}">
        <div class="log-header">
          <span class="log-level">${log.level.toUpperCase()}</span>
          <span class="log-source">${log.source}</span>
          <span class="log-time" title="${new Date(log.timestamp).toLocaleString()}">
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
   * 应用过滤器
   */
  private applyFilters(): void {
    this.filteredLogs = this.logs.filter(log => {
      // 级别过滤
      if (this.currentFilter.level && log.level !== this.currentFilter.level) {
        return false;
      }

      // 来源过滤
      if (this.currentFilter.source && log.source !== this.currentFilter.source) {
        return false;
      }

      // 日期过滤
      if (this.currentFilter.dateFrom) {
        const fromDate = new Date(this.currentFilter.dateFrom).getTime();
        if (log.timestamp < fromDate) {
          return false;
        }
      }

      if (this.currentFilter.dateTo) {
        const toDate = new Date(this.currentFilter.dateTo).getTime() + 24 * 60 * 60 * 1000; // 包含整天
        if (log.timestamp > toDate) {
          return false;
        }
      }

      // 搜索过滤
      if (this.currentFilter.search) {
        const searchTerm = this.currentFilter.search.toLowerCase();
        const searchableText = `${log.message} ${log.source}`.toLowerCase();
        if (!searchableText.includes(searchTerm)) {
          return false;
        }
      }

      return true;
    });

    // 按时间倒序排列
    this.filteredLogs.sort((a, b) => b.timestamp - a.timestamp);
  }

  /**
   * 更新统计信息
   */
  private updateStats(): void {
    const statsContainer = this.$('#logs-stats');
    if (!statsContainer) return;

    const stats = {
      total: this.logs.length,
      filtered: this.filteredLogs.length,
      error: this.filteredLogs.filter(log => log.level === 'error').length,
      warning: this.filteredLogs.filter(log => log.level === 'warning').length,
      info: this.filteredLogs.filter(log => log.level === 'info').length,
      debug: this.filteredLogs.filter(log => log.level === 'debug').length
    };

    statsContainer.innerHTML = `
      <div class="stats-item">
        <span class="stats-label">总计:</span>
        <span class="stats-value">${stats.total}</span>
      </div>
      <div class="stats-item">
        <span class="stats-label">显示:</span>
        <span class="stats-value">${stats.filtered}</span>
      </div>
      <div class="stats-item error">
        <span class="stats-label">错误:</span>
        <span class="stats-value">${stats.error}</span>
      </div>
      <div class="stats-item warning">
        <span class="stats-label">警告:</span>
        <span class="stats-value">${stats.warning}</span>
      </div>
      <div class="stats-item info">
        <span class="stats-label">信息:</span>
        <span class="stats-value">${stats.info}</span>
      </div>
      <div class="stats-item debug">
        <span class="stats-label">调试:</span>
        <span class="stats-value">${stats.debug}</span>
      </div>
    `;
  }

  /**
   * 处理刷新
   */
  private async handleRefresh(event: Event): Promise<void> {
    event.preventDefault();
    
    const button = event.target as HTMLButtonElement;
    const originalText = button.textContent;
    
    button.disabled = true;
    button.textContent = '刷新中...';

    try {
      await this.loadLogs();
      showSuccess('日志已刷新');
    } finally {
      button.disabled = false;
      button.textContent = originalText;
    }
  }

  /**
   * 处理清空日志
   */
  private async handleClear(event: Event): Promise<void> {
    event.preventDefault();

    if (!confirm('确定要清空所有日志吗？此操作不可撤销。')) {
      return;
    }

    try {
      const response = await post('notion_to_wordpress_clear_logs', {});
      
      if (response.data.success) {
        this.logs = [];
        this.filteredLogs = [];
        this.render();
        showSuccess('日志已清空');
      } else {
        throw new Error(response.data.message || '清空日志失败');
      }
    } catch (error) {
      console.error('Failed to clear logs:', error);
      showError(`清空日志失败: ${(error as Error).message}`);
    }
  }

  /**
   * 处理导出日志
   */
  private async handleExport(event: Event): Promise<void> {
    event.preventDefault();

    try {
      const csvContent = this.generateCSV();
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      
      if (link.download !== undefined) {
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `notion-wp-logs-${new Date().toISOString().split('T')[0]}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        showSuccess('日志已导出');
      }
    } catch (error) {
      console.error('Failed to export logs:', error);
      showError(`导出日志失败: ${(error as Error).message}`);
    }
  }

  /**
   * 生成CSV内容
   */
  private generateCSV(): string {
    const headers = ['时间', '级别', '来源', '消息', '上下文'];
    const rows = this.filteredLogs.map(log => [
      new Date(log.timestamp).toISOString(),
      log.level,
      log.source,
      `"${log.message.replace(/"/g, '""')}"`,
      log.context ? `"${JSON.stringify(log.context).replace(/"/g, '""')}"` : ''
    ]);

    return [headers, ...rows].map(row => row.join(',')).join('\n');
  }

  /**
   * 处理自动刷新开关
   */
  private handleAutoRefreshToggle(event: Event): void {
    const checkbox = event.target as HTMLInputElement;
    this.autoRefresh = checkbox.checked;

    if (this.autoRefresh) {
      this.startAutoRefresh();
    } else {
      this.stopAutoRefresh();
    }
  }

  /**
   * 处理过滤器变化
   */
  private handleFilterChange(): void {
    this.currentFilter = {
      level: (this.$('#filter-level') as HTMLSelectElement)?.value || undefined,
      source: (this.$('#filter-source') as HTMLSelectElement)?.value || undefined,
      dateFrom: (this.$('#filter-date-from') as HTMLInputElement)?.value || undefined,
      dateTo: (this.$('#filter-date-to') as HTMLInputElement)?.value || undefined,
      search: (this.$('#log-search') as HTMLInputElement)?.value || undefined
    };

    // 移除空值
    Object.keys(this.currentFilter).forEach(key => {
      if (!this.currentFilter[key as keyof LogFilter]) {
        delete this.currentFilter[key as keyof LogFilter];
      }
    });

    this.applyFilters();
    this.render();
  }

  /**
   * 设置自动刷新
   */
  private setupAutoRefresh(): void {
    const autoRefreshToggle = this.$('#auto-refresh') as HTMLInputElement;
    if (autoRefreshToggle && autoRefreshToggle.checked) {
      this.autoRefresh = true;
      this.startAutoRefresh();
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
      this.loadLogs().catch(console.error);
    }, this.refreshInterval);

    console.log('Auto refresh started');
  }

  /**
   * 停止自动刷新
   */
  private stopAutoRefresh(): void {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
      this.refreshTimer = null;
    }

    console.log('Auto refresh stopped');
  }

  /**
   * 获取当前日志
   */
  public getLogs(): LogEntry[] {
    return this.logs;
  }

  /**
   * 获取过滤后的日志
   */
  public getFilteredLogs(): LogEntry[] {
    return this.filteredLogs;
  }
}

// 导出模块创建函数
export default function createLogsModule(element?: HTMLElement): LogsModule {
  return new LogsModule({
    element,
    selector: element ? undefined : '#logs-container'
  });
}
