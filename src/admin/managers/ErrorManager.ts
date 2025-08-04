/**
 * 错误管理器 - 现代化TypeScript版本
 * 
 * 从原有Error_Handler.php和admin-interactions.js的错误处理功能完全迁移，包括：
 * - 全局错误捕获和处理
 * - 错误分类和恢复策略
 * - 错误日志记录和报告
 * - 重试机制和通知系统
 */

import { emit } from '../../shared/core/EventBus';
import { post } from '../../shared/utils/ajax';
import { showError, showWarning } from '../../shared/utils/toast';

export type ErrorType = 
  | 'FILTER_ERROR'
  | 'AUTH_ERROR'
  | 'RATE_LIMIT_ERROR'
  | 'NETWORK_ERROR'
  | 'SERVER_ERROR'
  | 'CLIENT_ERROR'
  | 'VALIDATION_ERROR'
  | 'PERMISSION_ERROR'
  | 'DATA_ERROR'
  | 'UNKNOWN_ERROR';

export type ErrorSeverity = 'low' | 'medium' | 'high' | 'critical';

export interface ErrorInfo {
  id: string;
  type: ErrorType;
  severity: ErrorSeverity;
  message: string;
  originalError: Error | any;
  context: Record<string, any>;
  timestamp: number;
  stack?: string;
  userAgent?: string;
  url?: string;
  retryCount?: number;
  resolved?: boolean;
}

export interface RetryConfig {
  maxAttempts: number;
  baseDelay: number;
  maxDelay: number;
  backoffMultiplier: number;
  retryableErrors: ErrorType[];
}

export interface ErrorManagerOptions {
  enableGlobalHandling?: boolean;
  enableRetry?: boolean;
  enableNotifications?: boolean;
  enableReporting?: boolean;
  maxErrorHistory?: number;
  reportingEndpoint?: string;
}

/**
 * 错误管理器类
 */
export class ErrorManager {
  private static instance: ErrorManager | null = null;
  
  private options!: Required<ErrorManagerOptions>;
  private errorHistory: ErrorInfo[] = [];
  private retryConfig: RetryConfig = {
    maxAttempts: 3,
    baseDelay: 1000,
    maxDelay: 30000,
    backoffMultiplier: 2,
    retryableErrors: ['NETWORK_ERROR', 'SERVER_ERROR', 'RATE_LIMIT_ERROR']
  };
  private retryTimers = new Map<string, NodeJS.Timeout>();

  constructor(options: ErrorManagerOptions = {}) {
    if (ErrorManager.instance) {
      return ErrorManager.instance;
    }
    
    ErrorManager.instance = this;
    
    this.options = {
      enableGlobalHandling: true,
      enableRetry: true,
      enableNotifications: true,
      enableReporting: false,
      maxErrorHistory: 100,
      reportingEndpoint: '',
      ...options
    };
    
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(options?: ErrorManagerOptions): ErrorManager {
    if (!ErrorManager.instance) {
      ErrorManager.instance = new ErrorManager(options);
    }
    return ErrorManager.instance;
  }

  /**
   * 初始化管理器
   */
  private init(): void {
    if (this.options.enableGlobalHandling) {
      this.setupGlobalErrorHandling();
    }
    
    console.log('🛡️ [错误管理器] 已初始化');
    emit('error:manager:initialized');
  }

  /**
   * 设置全局错误处理
   */
  private setupGlobalErrorHandling(): void {
    // 全局错误捕获
    window.addEventListener('error', (event) => {
      this.handleGlobalError(event.error, {
        filename: event.filename,
        lineno: event.lineno,
        colno: event.colno,
        type: 'javascript'
      });
    });

    // Promise 拒绝处理
    window.addEventListener('unhandledrejection', (event) => {
      this.handleGlobalError(event.reason, {
        type: 'promise_rejection'
      });
      
      // 防止默认的控制台错误
      event.preventDefault();
    });

    console.log('🛡️ [错误处理] 全局错误处理已设置');
  }

  /**
   * 处理全局错误
   */
  private handleGlobalError(error: any, context: Record<string, any> = {}): void {
    // 只处理我们的代码错误
    if (this.isOurError(error, context)) {
      const errorInfo = this.createErrorInfo(error, context);
      this.handleError(errorInfo);
    }
  }

  /**
   * 判断是否是我们的错误
   */
  private isOurError(error: any, context: Record<string, any>): boolean {
    const filename = context.filename || '';
    const stack = error?.stack || '';
    
    return filename.includes('notion-to-wordpress') || 
           stack.includes('notion-to-wordpress') ||
           context.type === 'manual'; // 手动报告的错误
  }

  /**
   * 创建错误信息对象
   */
  private createErrorInfo(error: any, context: Record<string, any> = {}): ErrorInfo {
    const errorInfo: ErrorInfo = {
      id: this.generateErrorId(),
      type: this.classifyError(error),
      severity: this.determineSeverity(error),
      message: this.extractMessage(error),
      originalError: error,
      context,
      timestamp: Date.now(),
      stack: error?.stack,
      userAgent: navigator.userAgent,
      url: window.location.href,
      retryCount: 0,
      resolved: false
    };

    return errorInfo;
  }

  /**
   * 生成错误ID
   */
  private generateErrorId(): string {
    return `error_${Date.now()}_${Math.random().toString(36).substring(2, 11)}`;
  }

  /**
   * 分类错误类型
   */
  private classifyError(error: any): ErrorType {
    const message = String(error?.message || error || '').toLowerCase();
    
    // 过滤器错误
    if (message.includes('filter') && message.includes('validation')) {
      return 'FILTER_ERROR';
    }
    
    // 认证错误
    if (message.includes('unauthorized') || message.includes('auth') || 
        message.includes('api key') || message.includes('token')) {
      return 'AUTH_ERROR';
    }
    
    // 速率限制错误
    if (message.includes('rate limit') || message.includes('too many requests') ||
        message.includes('quota exceeded')) {
      return 'RATE_LIMIT_ERROR';
    }
    
    // 网络错误
    if (message.includes('network') || message.includes('timeout') ||
        message.includes('connection') || message.includes('fetch')) {
      return 'NETWORK_ERROR';
    }
    
    // 服务器错误
    if (message.includes('server error') || message.includes('internal error') ||
        error?.status >= 500) {
      return 'SERVER_ERROR';
    }
    
    // 客户端错误
    if (message.includes('bad request') || message.includes('invalid') ||
        (error?.status >= 400 && error?.status < 500)) {
      return 'CLIENT_ERROR';
    }
    
    // 验证错误
    if (message.includes('validation') || message.includes('invalid format') ||
        message.includes('required field')) {
      return 'VALIDATION_ERROR';
    }
    
    // 权限错误
    if (message.includes('permission') || message.includes('forbidden') ||
        message.includes('access denied')) {
      return 'PERMISSION_ERROR';
    }
    
    // 数据错误
    if (message.includes('data') || message.includes('parse') ||
        message.includes('json') || message.includes('format')) {
      return 'DATA_ERROR';
    }
    
    return 'UNKNOWN_ERROR';
  }

  /**
   * 确定错误严重性
   */
  private determineSeverity(error: any): ErrorSeverity {
    const type = this.classifyError(error);
    
    switch (type) {
      case 'AUTH_ERROR':
      case 'PERMISSION_ERROR':
        return 'critical';
      
      case 'SERVER_ERROR':
      case 'DATA_ERROR':
        return 'high';
      
      case 'RATE_LIMIT_ERROR':
      case 'NETWORK_ERROR':
      case 'VALIDATION_ERROR':
        return 'medium';
      
      default:
        return 'low';
    }
  }

  /**
   * 提取错误消息
   */
  private extractMessage(error: any): string {
    if (typeof error === 'string') {
      return error;
    }
    
    if (error?.message) {
      return error.message;
    }
    
    if (error?.data?.message) {
      return error.data.message;
    }
    
    return '未知错误';
  }

  /**
   * 处理错误
   */
  handleError(errorInfo: ErrorInfo): void {
    // 添加到错误历史
    this.addToHistory(errorInfo);
    
    // 记录错误日志
    this.logError(errorInfo);
    
    // 根据错误类型采取不同策略
    this.executeErrorStrategy(errorInfo);
    
    // 发送事件
    emit('error:handled', { errorInfo });
    
    console.error('🚨 [错误处理]:', errorInfo);
  }

  /**
   * 添加到错误历史
   */
  private addToHistory(errorInfo: ErrorInfo): void {
    this.errorHistory.unshift(errorInfo);
    
    // 限制历史记录数量
    if (this.errorHistory.length > this.options.maxErrorHistory) {
      this.errorHistory = this.errorHistory.slice(0, this.options.maxErrorHistory);
    }
  }

  /**
   * 记录错误日志
   */
  private logError(errorInfo: ErrorInfo): void {
    const logData = {
      id: errorInfo.id,
      type: errorInfo.type,
      severity: errorInfo.severity,
      message: errorInfo.message,
      context: errorInfo.context,
      timestamp: errorInfo.timestamp,
      stack: errorInfo.stack,
      url: errorInfo.url
    };

    // 发送到后端记录
    if (this.options.enableReporting) {
      this.reportError(logData).catch(console.error);
    }
  }

  /**
   * 执行错误策略
   */
  private executeErrorStrategy(errorInfo: ErrorInfo): void {
    switch (errorInfo.type) {
      case 'RATE_LIMIT_ERROR':
        this.handleRateLimitError(errorInfo);
        break;
      
      case 'AUTH_ERROR':
        this.handleAuthError(errorInfo);
        break;
      
      case 'NETWORK_ERROR':
        this.handleNetworkError(errorInfo);
        break;
      
      case 'SERVER_ERROR':
        this.handleServerError(errorInfo);
        break;
      
      case 'VALIDATION_ERROR':
        this.handleValidationError(errorInfo);
        break;
      
      default:
        this.handleGenericError(errorInfo);
    }
  }

  /**
   * 处理速率限制错误
   */
  private handleRateLimitError(errorInfo: ErrorInfo): void {
    if (this.options.enableNotifications) {
      showWarning('请求过于频繁，请稍后重试');
    }
    
    if (this.options.enableRetry) {
      this.scheduleRetry(errorInfo, 60000); // 60秒后重试
    }
  }

  /**
   * 处理认证错误
   */
  private handleAuthError(errorInfo: ErrorInfo): void {
    if (this.options.enableNotifications) {
      showError('认证失败，请检查API密钥配置');
    }
    
    // 通知管理员
    this.notifyAdmin('认证失败', errorInfo);
  }

  /**
   * 处理网络错误
   */
  private handleNetworkError(errorInfo: ErrorInfo): void {
    if (this.options.enableNotifications) {
      showError('网络连接失败，请检查网络连接');
    }
    
    if (this.options.enableRetry) {
      this.scheduleRetry(errorInfo, 5000); // 5秒后重试
    }
  }

  /**
   * 处理服务器错误
   */
  private handleServerError(errorInfo: ErrorInfo): void {
    if (this.options.enableNotifications) {
      showError('服务器错误，请稍后重试');
    }
    
    if (this.options.enableRetry) {
      this.scheduleRetry(errorInfo, 10000); // 10秒后重试
    }
  }

  /**
   * 处理验证错误
   */
  private handleValidationError(errorInfo: ErrorInfo): void {
    if (this.options.enableNotifications) {
      showError(`数据验证失败: ${errorInfo.message}`);
    }
    
    // 验证错误通常不需要重试
  }

  /**
   * 处理通用错误
   */
  private handleGenericError(errorInfo: ErrorInfo): void {
    if (this.options.enableNotifications && errorInfo.severity !== 'low') {
      showError('发生了一个错误，请刷新页面重试');
    }
  }

  /**
   * 安排重试
   */
  private scheduleRetry(errorInfo: ErrorInfo, delay: number): void {
    if (!this.retryConfig.retryableErrors.includes(errorInfo.type)) {
      return;
    }
    
    const retryCount = errorInfo.retryCount || 0;
    
    if (retryCount >= this.retryConfig.maxAttempts) {
      console.warn('🔄 [重试] 达到最大重试次数:', errorInfo.id);
      return;
    }
    
    // 计算退避延迟
    const backoffDelay = Math.min(
      delay * Math.pow(this.retryConfig.backoffMultiplier, retryCount),
      this.retryConfig.maxDelay
    );
    
    const timer = setTimeout(() => {
      this.executeRetry(errorInfo);
      this.retryTimers.delete(errorInfo.id);
    }, backoffDelay);
    
    this.retryTimers.set(errorInfo.id, timer);
    
    console.log(`🔄 [重试] 安排重试: ${errorInfo.id}, 延迟: ${backoffDelay}ms`);
  }

  /**
   * 执行重试
   */
  private executeRetry(errorInfo: ErrorInfo): void {
    const retryCount = (errorInfo.retryCount || 0) + 1;
    
    console.log(`🔄 [重试] 执行第${retryCount}次重试: ${errorInfo.id}`);
    
    // 更新重试次数
    errorInfo.retryCount = retryCount;
    
    // 发送重试事件
    emit('error:retry', { errorInfo, retryCount });
  }

  /**
   * 通知管理员
   */
  private notifyAdmin(message: string, errorInfo: ErrorInfo): void {
    console.warn(`📧 [管理员通知] ${message}:`, errorInfo);
    
    // 这里可以集成邮件通知或其他通知机制
    emit('error:admin:notify', { message, errorInfo });
  }

  /**
   * 报告错误到服务器
   */
  private async reportError(errorData: any): Promise<void> {
    try {
      await post('notion_to_wordpress_report_error', errorData);
    } catch (error) {
      console.error('错误报告失败:', error);
    }
  }

  /**
   * 手动报告错误
   */
  reportManualError(error: any, context: Record<string, any> = {}): string {
    const errorInfo = this.createErrorInfo(error, { ...context, type: 'manual' });
    this.handleError(errorInfo);
    return errorInfo.id;
  }

  /**
   * 标记错误为已解决
   */
  resolveError(errorId: string): void {
    const errorInfo = this.errorHistory.find(e => e.id === errorId);
    if (errorInfo) {
      errorInfo.resolved = true;
      emit('error:resolved', { errorInfo });
    }
  }

  /**
   * 获取错误历史
   */
  getErrorHistory(): ErrorInfo[] {
    return [...this.errorHistory];
  }

  /**
   * 获取未解决的错误
   */
  getUnresolvedErrors(): ErrorInfo[] {
    return this.errorHistory.filter(e => !e.resolved);
  }

  /**
   * 获取错误统计
   */
  getErrorStats(): Record<string, any> {
    const stats = {
      total: this.errorHistory.length,
      unresolved: this.getUnresolvedErrors().length,
      byType: {} as Record<ErrorType, number>,
      bySeverity: {} as Record<ErrorSeverity, number>,
      recentErrors: this.errorHistory.slice(0, 10)
    };

    this.errorHistory.forEach(error => {
      stats.byType[error.type] = (stats.byType[error.type] || 0) + 1;
      stats.bySeverity[error.severity] = (stats.bySeverity[error.severity] || 0) + 1;
    });

    return stats;
  }

  /**
   * 清除错误历史
   */
  clearErrorHistory(): void {
    this.errorHistory = [];
    emit('error:history:cleared');
  }

  /**
   * 更新重试配置
   */
  updateRetryConfig(config: Partial<RetryConfig>): void {
    this.retryConfig = { ...this.retryConfig, ...config };
    emit('error:retry:config:updated', this.retryConfig);
  }

  /**
   * 获取配置选项
   */
  getOptions(): Required<ErrorManagerOptions> {
    return { ...this.options };
  }

  /**
   * 更新配置选项
   */
  updateOptions(options: Partial<ErrorManagerOptions>): void {
    this.options = { ...this.options, ...options };
    emit('error:options:updated', this.options);
  }

  /**
   * 销毁管理器
   */
  destroy(): void {
    // 清理重试定时器
    this.retryTimers.forEach(timer => clearTimeout(timer));
    this.retryTimers.clear();
    
    // 清理事件监听器
    if (this.options.enableGlobalHandling) {
      window.removeEventListener('error', this.handleGlobalError);
      window.removeEventListener('unhandledrejection', this.handleGlobalError);
    }
    
    ErrorManager.instance = null;
    emit('error:manager:destroyed');
    console.log('🛡️ [错误管理器] 已销毁');
  }
}

// 导出单例实例
export const errorManager = ErrorManager.getInstance();

export default ErrorManager;
