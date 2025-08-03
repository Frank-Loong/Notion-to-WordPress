/**
 * 统一API服务层
 * 
 * @since 2.0.0
 */

import axios, { AxiosInstance, AxiosRequestConfig, AxiosResponse } from 'axios';
import type {
  BaseResponse,
  SyncRequest,
  SyncResponse,
  SyncStatus,
  StatsData,
  SettingsData,
  ConnectionTestRequest,
  ConnectionTestResponse,
  LogViewRequest,
  LogViewResponse,
  SystemInfo,
  IndexStatus,
  QueueStatus,
  SmartRecommendation,
} from '../types';
import type {
  WPNotionConfig,
  WPAjaxResponse,
  WPAjaxErrorResponse,
  WPAjaxSuccessResponse,
} from '../types/wordpress';

/**
 * API服务类
 * 封装所有AJAX请求，保持与现有PHP后端的完全兼容
 */
export class ApiService {
  private client: AxiosInstance;
  private config: WPNotionConfig;

  constructor() {
    // 获取WordPress配置
    this.config = this.getWPConfig();
    
    // 创建axios实例
    this.client = axios.create({
      baseURL: this.config.ajaxUrl,
      timeout: 60000,
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
    });

    this.setupInterceptors();
  }

  /**
   * 获取WordPress配置
   */
  private getWPConfig(): WPNotionConfig {
    if (typeof window !== 'undefined' && window.wpNotionConfig) {
      return window.wpNotionConfig;
    }
    
    // 兼容旧版本配置
    if (typeof window !== 'undefined' && window.notionToWp) {
      return window.notionToWp;
    }

    throw new Error('WordPress配置未找到，请确保页面已正确加载');
  }

  /**
   * 设置请求拦截器
   */
  private setupInterceptors(): void {
    // 请求拦截器：自动添加nonce和action
    this.client.interceptors.request.use(
      (config) => {
        // 确保data存在
        if (!config.data) {
          config.data = {};
        }

        // 如果data是FormData，直接添加字段
        if (config.data instanceof FormData) {
          config.data.append('nonce', this.config.nonce);
        } else {
          // 转换为URLSearchParams格式
          const params = new URLSearchParams();
          
          // 添加现有数据
          if (typeof config.data === 'object') {
            Object.entries(config.data).forEach(([key, value]) => {
              if (value !== undefined && value !== null) {
                params.append(key, String(value));
              }
            });
          }
          
          // 添加nonce
          params.append('nonce', this.config.nonce);
          
          config.data = params;
        }

        return config;
      },
      (error) => {
        return Promise.reject(error);
      }
    );

    // 响应拦截器：统一错误处理
    this.client.interceptors.response.use(
      (response: AxiosResponse<WPAjaxResponse>) => {
        const data = response.data;
        
        // WordPress AJAX响应格式验证
        if (typeof data !== 'object' || typeof data.success !== 'boolean') {
          throw new Error('无效的响应格式');
        }

        // 如果WordPress返回错误
        if (!data.success) {
          const errorData = data as WPAjaxErrorResponse;
          const errorMessage = typeof errorData.data === 'string' 
            ? errorData.data 
            : errorData.data?.message || '未知错误';
          throw new Error(errorMessage);
        }

        return response;
      },
      (error) => {
        // 网络错误处理
        if (error.code === 'ECONNABORTED') {
          throw new Error('请求超时，请检查网络连接');
        }
        
        if (error.response) {
          // 服务器响应错误
          const status = error.response.status;
          if (status === 403) {
            throw new Error('权限不足，请刷新页面重试');
          } else if (status === 404) {
            throw new Error('请求的资源不存在');
          } else if (status >= 500) {
            throw new Error('服务器内部错误，请稍后重试');
          }
        }
        
        throw error;
      }
    );
  }

  /**
   * 通用请求方法
   */
  private async request<T = any>(
    action: string, 
    data: Record<string, any> = {},
    config: AxiosRequestConfig = {}
  ): Promise<T> {
    try {
      const response = await this.client.post<WPAjaxSuccessResponse<T>>('', {
        action,
        ...data,
      }, config);

      return response.data.data;
    } catch (error) {
      // 重新抛出错误，保持错误信息
      throw error;
    }
  }

  // ==================== 同步相关API ====================

  /**
   * 开始手动同步
   */
  async startSync(request: SyncRequest = {}): Promise<SyncResponse> {
    return this.request<SyncResponse>('notion_to_wordpress_manual_sync', request);
  }

  /**
   * 取消同步
   */
  async cancelSync(taskId?: string): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_cancel_sync', {
      task_id: taskId,
    });
  }

  /**
   * 重试失败的任务
   */
  async retryFailed(): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_retry_failed');
  }

  /**
   * 获取同步状态
   */
  async getSyncStatus(): Promise<SyncStatus> {
    return this.request<SyncStatus>('notion_to_wordpress_get_async_status');
  }

  // ==================== 连接测试API ====================

  /**
   * 测试Notion API连接
   */
  async testConnection(request: ConnectionTestRequest): Promise<ConnectionTestResponse> {
    return this.request<ConnectionTestResponse>('notion_to_wordpress_test_connection', request);
  }

  // ==================== 统计数据API ====================

  /**
   * 获取统计数据
   */
  async getStats(): Promise<StatsData> {
    return this.request<StatsData>('notion_to_wordpress_get_stats');
  }

  /**
   * 刷新性能统计
   */
  async refreshPerformanceStats(): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_refresh_performance_stats');
  }

  /**
   * 重置性能统计
   */
  async resetPerformanceStats(): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_reset_performance_stats');
  }

  // ==================== 设置管理API ====================

  /**
   * 获取设置数据
   * 注意：这个端点可能需要在PHP后端实现
   */
  async getSettings(): Promise<SettingsData> {
    return this.request<SettingsData>('notion_to_wordpress_get_settings');
  }

  /**
   * 保存设置数据
   * 注意：这个端点可能需要在PHP后端实现
   */
  async saveSettings(settings: Partial<SettingsData>): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_save_settings', settings);
  }

  // ==================== 日志管理API ====================

  /**
   * 查看日志文件
   */
  async viewLog(request: LogViewRequest): Promise<LogViewResponse> {
    return this.request<LogViewResponse>('notion_to_wordpress_view_log', request);
  }

  /**
   * 清除日志文件
   */
  async clearLogs(): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_clear_logs');
  }

  // ==================== 系统信息API ====================

  /**
   * 获取系统信息
   */
  async getSystemInfo(): Promise<SystemInfo> {
    return this.request<SystemInfo>('notion_to_wordpress_test_debug');
  }

  // ==================== 数据库优化API ====================

  /**
   * 获取索引状态
   */
  async getIndexStatus(): Promise<IndexStatus> {
    return this.request<IndexStatus>('notion_to_wordpress_get_index_status');
  }

  /**
   * 创建数据库索引
   */
  async createDatabaseIndexes(): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_create_database_indexes');
  }

  /**
   * 优化所有索引
   */
  async optimizeAllIndexes(): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_optimize_all_indexes');
  }

  /**
   * 移除数据库索引
   */
  async removeDatabaseIndexes(): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_remove_database_indexes');
  }

  /**
   * 分析查询性能
   */
  async analyzeQueryPerformance(): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_analyze_query_performance');
  }

  // ==================== 队列管理API ====================

  /**
   * 获取队列状态
   */
  async getQueueStatus(): Promise<QueueStatus> {
    return this.request<QueueStatus>('notion_to_wordpress_get_queue_status');
  }

  /**
   * 控制异步操作
   */
  async controlAsyncOperation(actionType: 'pause' | 'resume' | 'stop'): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_control_async_operation', {
      action_type: actionType,
    });
  }

  /**
   * 清理队列
   */
  async cleanupQueue(): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_cleanup_queue');
  }

  /**
   * 取消队列任务
   */
  async cancelQueueTask(taskId: string): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_cancel_queue_task', {
      task_id: taskId,
    });
  }

  // ==================== 智能推荐API ====================

  /**
   * 获取智能推荐
   */
  async getSmartRecommendations(): Promise<SmartRecommendation[]> {
    return this.request<SmartRecommendation[]>('notion_to_wordpress_get_smart_recommendations');
  }

  // ==================== Webhook相关API ====================

  /**
   * 刷新验证令牌
   */
  async refreshVerificationToken(): Promise<BaseResponse> {
    return this.request<BaseResponse>('notion_to_wordpress_refresh_verification_token');
  }

  // ==================== SSE连接方法 ====================

  /**
   * 创建SSE连接
   */
  createSSEConnection(taskId?: string): EventSource {
    const url = new URL(this.config.ajaxUrl);
    url.searchParams.append('action', 'notion_to_wordpress_sse_progress');
    url.searchParams.append('nonce', this.config.nonce);

    if (taskId) {
      url.searchParams.append('task_id', taskId);
    }

    return new EventSource(url.toString());
  }



  // ==================== 错误重试机制 ====================

  /**
   * 带重试的请求方法
   */
  async requestWithRetry<T = any>(
    action: string,
    data: Record<string, any> = {},
    maxRetries: number = 3,
    retryDelay: number = 1000
  ): Promise<T> {
    let lastError: Error;

    for (let attempt = 1; attempt <= maxRetries; attempt++) {
      try {
        return await this.request<T>(action, data);
      } catch (error) {
        lastError = error as Error;

        // 如果是最后一次尝试，直接抛出错误
        if (attempt === maxRetries) {
          break;
        }

        // 如果是权限错误或nonce错误，不重试
        if (lastError.message.includes('权限') || lastError.message.includes('nonce')) {
          break;
        }

        // 等待后重试
        await new Promise(resolve => setTimeout(resolve, retryDelay * attempt));
      }
    }

    throw lastError!;
  }

  // ==================== 批量请求方法 ====================

  /**
   * 批量请求方法
   */
  async batchRequest<T = any>(
    requests: Array<{ action: string; data?: Record<string, any> }>
  ): Promise<Array<T | { error: string }>> {
    const promises = requests.map(({ action, data }) =>
      this.request<T>(action, data).catch(error => ({ error: error.message }))
    );

    return Promise.all(promises);
  }

  // ==================== 配置更新方法 ====================

  /**
   * 更新配置（用于动态更新nonce等）
   */
  updateConfig(newConfig: Partial<WPNotionConfig>): void {
    this.config = { ...this.config, ...newConfig };
  }

  /**
   * 获取当前配置
   */
  getConfig(): WPNotionConfig {
    return { ...this.config };
  }
}

// ==================== 单例实例 ====================

let apiServiceInstance: ApiService | null = null;

/**
 * 获取API服务单例实例
 */
export function getApiService(): ApiService {
  if (!apiServiceInstance) {
    apiServiceInstance = new ApiService();
  }
  return apiServiceInstance;
}

/**
 * 重置API服务实例（用于测试或配置更新）
 */
export function resetApiService(): void {
  apiServiceInstance = null;
}

// ==================== 默认导出 ====================

export default ApiService;