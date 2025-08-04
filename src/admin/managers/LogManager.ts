/**
 * 日志管理器 - 现代化TypeScript版本
 * 
 * 从原有admin-interactions.js和Logs.ts的日志功能完全迁移，包括：
 * - 日志获取和显示
 * - 过滤和搜索
 * - 实时刷新
 * - 导出和清理
 */

import { emit } from '../../shared/core/EventBus';
import { post } from '../../shared/utils/ajax';
import { showSuccess, showError, showInfo } from '../../shared/utils/toast';

export interface LogEntry {
  id: string;
  timestamp: number;
  level: 'debug' | 'info' | 'warning' | 'error';
  message: string;
  context?: any;
  source: string;
  file?: string;
}

export interface LogFilter {
  level?: string;
  source?: string;
  dateFrom?: string;
  dateTo?: string;
  search?: string;
  file?: string;
}

export interface LogStats {
  total: number;
  debug: number;
  info: number;
  warning: number;
  error: number;
  sources: Record<string, number>;
}

export interface LogManagerOptions {
  autoRefresh?: boolean;
  refreshInterval?: number;
  maxEntries?: number;
  enableExport?: boolean;
  enableClear?: boolean;
}

/**
 * 日志管理器类
 */
export class LogManager {
  private static instance: LogManager | null = null;
  
  private options!: Required<LogManagerOptions>;
  private logs: LogEntry[] = [];
  private filteredLogs: LogEntry[] = [];
  private currentFilter: LogFilter = {};
  private refreshTimer: NodeJS.Timeout | null = null;
  private isLoading = false;
  private logFiles: string[] = [];

  constructor(options: LogManagerOptions = {}) {
    if (LogManager.instance) {
      return LogManager.instance;
    }
    
    LogManager.instance = this;
    
    this.options = {
      autoRefresh: false,
      refreshInterval: 10000, // 10秒
      maxEntries: 1000,
      enableExport: true,
      enableClear: true,
      ...options
    };
    
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(options?: LogManagerOptions): LogManager {
    if (!LogManager.instance) {
      LogManager.instance = new LogManager(options);
    }
    return LogManager.instance;
  }

  /**
   * 初始化管理器
   */
  private init(): void {
    this.setupEventListeners();
    this.loadLogFiles();
    
    console.log('📋 [日志管理器] 已初始化');
    emit('log:manager:initialized');
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 监听页面可见性变化
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && this.options.autoRefresh) {
        this.refreshLogs();
      }
    });

    // 监听窗口焦点变化
    window.addEventListener('focus', () => {
      if (this.options.autoRefresh) {
        this.refreshLogs();
      }
    });
  }

  /**
   * 加载日志文件列表
   */
  async loadLogFiles(): Promise<string[]> {
    try {
      const response = await post('notion_to_wordpress_get_log_files', {});
      
      if (response && response.data) {
        this.logFiles = response.data;
        emit('log:files:loaded', { files: this.logFiles });
        return this.logFiles;
      } else {
        throw new Error('获取日志文件列表失败');
      }
    } catch (error) {
      console.error('加载日志文件列表失败:', error);
      showError(`加载日志文件列表失败: ${(error as Error).message}`);
      return [];
    }
  }

  /**
   * 加载日志内容
   */
  async loadLogContent(file?: string): Promise<string> {
    if (!file && this.logFiles.length === 0) {
      await this.loadLogFiles();
    }
    
    const targetFile = file || this.logFiles[0];
    if (!targetFile) {
      throw new Error('没有可用的日志文件');
    }

    try {
      const response = await post('notion_to_wordpress_view_log', {
        file: targetFile
      });
      
      if (response && response.data) {
        emit('log:content:loaded', { file: targetFile, content: response.data });
        return response.data;
      } else {
        throw new Error('加载日志内容失败');
      }
    } catch (error) {
      console.error('加载日志内容失败:', error);
      throw error;
    }
  }

  /**
   * 获取结构化日志
   */
  async getLogs(filter: LogFilter = {}): Promise<LogEntry[]> {
    this.setLoading(true);
    
    try {
      const response = await post('notion_to_wordpress_get_logs', {
        limit: this.options.maxEntries,
        ...filter
      });

      if (response && response.data) {
        this.logs = this.parseLogEntries(response.data);
        this.currentFilter = filter;
        this.applyFilters();
        
        emit('log:entries:loaded', { 
          logs: this.logs, 
          filtered: this.filteredLogs,
          filter 
        });
        
        return this.logs;
      } else {
        throw new Error('获取日志失败');
      }
    } catch (error) {
      console.error('获取日志失败:', error);
      showError(`获取日志失败: ${(error as Error).message}`);
      throw error;
    } finally {
      this.setLoading(false);
    }
  }

  /**
   * 解析日志条目
   */
  private parseLogEntries(data: any): LogEntry[] {
    if (Array.isArray(data)) {
      return data.map(entry => this.parseLogEntry(entry));
    }
    
    // 如果是字符串，尝试解析为日志行
    if (typeof data === 'string') {
      return this.parseLogString(data);
    }
    
    return [];
  }

  /**
   * 解析单个日志条目
   */
  private parseLogEntry(entry: any): LogEntry {
    return {
      id: entry.id || this.generateLogId(),
      timestamp: entry.timestamp || Date.now(),
      level: entry.level || 'info',
      message: entry.message || '',
      context: entry.context,
      source: entry.source || 'unknown',
      file: entry.file
    };
  }

  /**
   * 解析日志字符串
   */
  private parseLogString(logString: string): LogEntry[] {
    const lines = logString.split('\n').filter(line => line.trim());
    const entries: LogEntry[] = [];
    
    lines.forEach(line => {
      const entry = this.parseLogLine(line);
      if (entry) {
        entries.push(entry);
      }
    });
    
    return entries;
  }

  /**
   * 解析日志行
   */
  private parseLogLine(line: string): LogEntry | null {
    // 匹配常见的日志格式
    const patterns = [
      // [2024-01-01 12:00:00] ERROR: message
      /^\[([^\]]+)\]\s+(\w+):\s*(.+)$/,
      // 2024-01-01 12:00:00 ERROR message
      /^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(\w+)\s+(.+)$/,
      // ERROR: message
      /^(\w+):\s*(.+)$/
    ];

    for (const pattern of patterns) {
      const match = line.match(pattern);
      if (match) {
        let timestamp = Date.now();
        let level = 'info';
        let message = '';

        if (match.length === 4) {
          // 完整格式
          timestamp = new Date(match[1]).getTime() || Date.now();
          level = match[2].toLowerCase();
          message = match[3];
        } else if (match.length === 3) {
          // 简单格式
          level = match[1].toLowerCase();
          message = match[2];
        }

        return {
          id: this.generateLogId(),
          timestamp,
          level: this.normalizeLogLevel(level),
          message: message.trim(),
          source: 'file',
          file: this.currentFilter.file
        };
      }
    }

    // 如果无法解析，作为普通消息处理
    return {
      id: this.generateLogId(),
      timestamp: Date.now(),
      level: 'info',
      message: line.trim(),
      source: 'file',
      file: this.currentFilter.file
    };
  }

  /**
   * 标准化日志级别
   */
  private normalizeLogLevel(level: string): 'debug' | 'info' | 'warning' | 'error' {
    const normalized = level.toLowerCase();
    
    switch (normalized) {
      case 'debug':
      case 'trace':
        return 'debug';
      case 'info':
      case 'notice':
        return 'info';
      case 'warning':
      case 'warn':
        return 'warning';
      case 'error':
      case 'critical':
      case 'alert':
      case 'emergency':
        return 'error';
      default:
        return 'info';
    }
  }

  /**
   * 生成日志ID
   */
  private generateLogId(): string {
    return `log_${Date.now()}_${Math.random().toString(36).substring(2, 11)}`;
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

    emit('log:filtered', { 
      total: this.logs.length, 
      filtered: this.filteredLogs.length,
      filter: this.currentFilter 
    });
  }

  /**
   * 设置过滤器
   */
  setFilter(filter: LogFilter): void {
    this.currentFilter = { ...this.currentFilter, ...filter };
    this.applyFilters();
    emit('log:filter:changed', { filter: this.currentFilter });
  }

  /**
   * 清除过滤器
   */
  clearFilter(): void {
    this.currentFilter = {};
    this.applyFilters();
    emit('log:filter:cleared');
  }

  /**
   * 刷新日志
   */
  async refreshLogs(): Promise<void> {
    try {
      await this.getLogs(this.currentFilter);
      showInfo('日志已刷新');
    } catch (error) {
      console.error('刷新日志失败:', error);
    }
  }

  /**
   * 清除日志
   */
  async clearLogs(): Promise<void> {
    if (!this.options.enableClear) {
      showError('清除日志功能已禁用');
      return;
    }

    try {
      const response = await post('notion_to_wordpress_clear_logs', {});
      
      if (response && response.data) {
        this.logs = [];
        this.filteredLogs = [];

        emit('log:cleared');
        showSuccess('日志已清除');
      } else {
        throw new Error('清除日志失败');
      }
    } catch (error) {
      console.error('清除日志失败:', error);
      showError(`清除日志失败: ${(error as Error).message}`);
    }
  }

  /**
   * 导出日志
   */
  async exportLogs(format: 'txt' | 'json' | 'csv' = 'txt'): Promise<void> {
    if (!this.options.enableExport) {
      showError('导出日志功能已禁用');
      return;
    }

    try {
      const logs = this.filteredLogs.length > 0 ? this.filteredLogs : this.logs;
      const content = this.formatLogsForExport(logs, format);
      const filename = `logs_${new Date().toISOString().split('T')[0]}.${format}`;
      
      this.downloadFile(content, filename, this.getMimeType(format));
      
      emit('log:exported', { format, count: logs.length });
      showSuccess(`已导出 ${logs.length} 条日志`);
    } catch (error) {
      console.error('导出日志失败:', error);
      showError(`导出日志失败: ${(error as Error).message}`);
    }
  }

  /**
   * 格式化日志用于导出
   */
  private formatLogsForExport(logs: LogEntry[], format: string): string {
    switch (format) {
      case 'json':
        return JSON.stringify(logs, null, 2);
      
      case 'csv':
        const headers = 'Timestamp,Level,Source,Message,Context\n';
        const rows = logs.map(log => {
          const timestamp = new Date(log.timestamp).toISOString();
          const context = log.context ? JSON.stringify(log.context).replace(/"/g, '""') : '';
          const message = log.message.replace(/"/g, '""');
          return `"${timestamp}","${log.level}","${log.source}","${message}","${context}"`;
        }).join('\n');
        return headers + rows;
      
      case 'txt':
      default:
        return logs.map(log => {
          const timestamp = new Date(log.timestamp).toLocaleString();
          const context = log.context ? `\nContext: ${JSON.stringify(log.context, null, 2)}` : '';
          return `[${timestamp}] ${log.level.toUpperCase()}: ${log.message}${context}`;
        }).join('\n\n');
    }
  }

  /**
   * 获取MIME类型
   */
  private getMimeType(format: string): string {
    switch (format) {
      case 'json':
        return 'application/json';
      case 'csv':
        return 'text/csv';
      case 'txt':
      default:
        return 'text/plain';
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
   * 开始自动刷新
   */
  startAutoRefresh(): void {
    if (this.refreshTimer) {
      this.stopAutoRefresh();
    }

    this.refreshTimer = setInterval(() => {
      this.refreshLogs().catch(console.error);
    }, this.options.refreshInterval);

    emit('log:auto:refresh:started');
    console.log('📋 [日志管理器] 自动刷新已启动');
  }

  /**
   * 停止自动刷新
   */
  stopAutoRefresh(): void {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
      this.refreshTimer = null;
    }

    emit('log:auto:refresh:stopped');
    console.log('📋 [日志管理器] 自动刷新已停止');
  }

  /**
   * 设置加载状态
   */
  private setLoading(loading: boolean): void {
    this.isLoading = loading;
    emit('log:loading:changed', { loading });
  }

  /**
   * 获取日志统计
   */
  getStats(): LogStats {
    const stats: LogStats = {
      total: this.filteredLogs.length,
      debug: 0,
      info: 0,
      warning: 0,
      error: 0,
      sources: {}
    };

    this.filteredLogs.forEach(log => {
      stats[log.level]++;
      
      if (!stats.sources[log.source]) {
        stats.sources[log.source] = 0;
      }
      stats.sources[log.source]++;
    });

    return stats;
  }

  /**
   * 获取当前日志
   */
  getCurrentLogs(): LogEntry[] {
    return [...this.logs];
  }

  /**
   * 获取过滤后的日志
   */
  getFilteredLogs(): LogEntry[] {
    return [...this.filteredLogs];
  }

  /**
   * 获取当前过滤器
   */
  getCurrentFilter(): LogFilter {
    return { ...this.currentFilter };
  }

  /**
   * 获取日志文件列表
   */
  getLogFiles(): string[] {
    return [...this.logFiles];
  }

  /**
   * 检查是否正在加载
   */
  isLoadingLogs(): boolean {
    return this.isLoading;
  }

  /**
   * 获取配置选项
   */
  getOptions(): Required<LogManagerOptions> {
    return { ...this.options };
  }

  /**
   * 更新配置选项
   */
  updateOptions(options: Partial<LogManagerOptions>): void {
    this.options = { ...this.options, ...options };
    emit('log:options:updated', this.options);
  }

  /**
   * 销毁管理器
   */
  destroy(): void {
    this.stopAutoRefresh();
    
    // 清理事件监听器
    document.removeEventListener('visibilitychange', this.refreshLogs);
    window.removeEventListener('focus', this.refreshLogs);
    
    LogManager.instance = null;
    emit('log:manager:destroyed');
    console.log('📋 [日志管理器] 已销毁');
  }
}

// 导出单例实例
export const logManager = LogManager.getInstance();

export default LogManager;
