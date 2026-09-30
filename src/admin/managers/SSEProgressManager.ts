/**
 * SSE进度管理器 - 现代化TypeScript版本
 *
 * 从原有sync-progress-manager.js的SSE功能完全迁移，包括：
 * - Server-Sent Events连接管理
 * - 实时进度更新
 * - 连接状态监控
 * - 错误处理和重连机制
 */

import { emit } from '../../shared/core/EventBus';

export interface SSEProgressOptions {
  onProgress?: (data: ProgressData) => void;
  onComplete?: (data: CompletionData) => void;
  onError?: (error: Error) => void;
  onConnect?: () => void;
  onDisconnect?: () => void;
  reconnectAttempts?: number;
  reconnectDelay?: number;
  timeout?: number;
}

export interface ProgressData {
  progress: {
    percentage: number;
    current: number;
    total: number;
    message?: string;
    step?: string;
  };
  status: 'running' | 'completed' | 'failed' | 'cancelled';
  timestamp: number;
  taskId: string;
}

export interface CompletionData {
  status: 'completed' | 'failed' | 'cancelled';
  message: string;
  summary?: {
    total: number;
    success: number;
    failed: number;
    duration: number;
  };
  timestamp: number;
  taskId: string;
}

/**
 * SSE进度管理器类
 */
export class SSEProgressManager {
  private taskId: string;
  private options: Required<SSEProgressOptions>;
  private eventSource: EventSource | null = null;
  private reconnectTimer: NodeJS.Timeout | null = null;
  private reconnectAttempts = 0;
  private isConnected = false;
  private isStopped = false;

  constructor(taskId: string, options: SSEProgressOptions = {}) {
    this.taskId = taskId;
    this.options = {
      onProgress: () => {},
      onComplete: () => {},
      onError: () => {},
      onConnect: () => {},
      onDisconnect: () => {},
      reconnectAttempts: 3,
      reconnectDelay: 2000,
      timeout: 30000,
      ...options,
    };
  }

  /**
   * 开始SSE连接
   */
  start(): void {
    if (this.eventSource || this.isStopped) {
      return;
    }

    // 检查SSE支持
    if (typeof EventSource === 'undefined') {
      const error = new Error('浏览器不支持Server-Sent Events');
      this.options.onError(error);
      emit('sse:error', { taskId: this.taskId, error });
      return;
    }

    this.connect();
  }

  /**
   * 建立SSE连接
   */
  private connect(): void {
    try {
      const url = this.buildSSEUrl();
      console.log(`🔗 [SSE进度管理器] 连接到: ${url}`);

      this.eventSource = new EventSource(url);

      this.setupEventListeners();

      // 设置连接超时
      setTimeout(() => {
        if (!this.isConnected && this.eventSource) {
          console.warn('⏰ [SSE进度管理器] 连接超时');
          this.handleConnectionError(new Error('连接超时'));
        }
      }, this.options.timeout);
    } catch (error) {
      console.error('❌ [SSE进度管理器] 连接失败:', error);
      this.handleConnectionError(error as Error);
    }
  }

  /**
   * 构建SSE URL
   */
  private buildSSEUrl(): string {
    const baseUrl =
      (window as any).notionToWp?.sse_endpoint || '/wp-admin/admin-ajax.php';
    const params = new URLSearchParams({
      action: 'notion_to_wordpress_sse_progress',
      task_id: this.taskId,
      nonce: (window as any).notionToWp?.nonce || '',
    });

    return `${baseUrl}?${params.toString()}`;
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    if (!this.eventSource) return;

    // 连接打开
    this.eventSource.onopen = () => {
      console.log('✅ [SSE进度管理器] 连接已建立');
      this.isConnected = true;
      this.reconnectAttempts = 0;
      this.options.onConnect();
      emit('sse:connected', { taskId: this.taskId });
    };

    // 接收消息
    this.eventSource.onmessage = event => {
      try {
        const data = JSON.parse(event.data);
        this.handleMessage(data);
      } catch (error) {
        console.error('❌ [SSE进度管理器] 消息解析失败:', error);
        this.options.onError(error as Error);
      }
    };

    // 连接错误
    this.eventSource.onerror = event => {
      console.error('❌ [SSE进度管理器] 连接错误:', event);
      this.handleConnectionError(new Error('SSE连接错误'));
    };

    // 自定义事件监听
    this.eventSource.addEventListener('progress', event => {
      try {
        const data = JSON.parse((event as MessageEvent).data);
        this.handleProgressUpdate(data);
      } catch (error) {
        console.error('❌ [SSE进度管理器] 进度数据解析失败:', error);
      }
    });

    this.eventSource.addEventListener('complete', event => {
      try {
        const data = JSON.parse((event as MessageEvent).data);
        this.handleCompletion(data);
      } catch (error) {
        console.error('❌ [SSE进度管理器] 完成数据解析失败:', error);
      }
    });

    this.eventSource.addEventListener('error', event => {
      try {
        const data = JSON.parse((event as MessageEvent).data);
        this.handleServerError(data);
      } catch (error) {
        console.error('❌ [SSE进度管理器] 错误数据解析失败:', error);
      }
    });
  }

  /**
   * 处理消息
   */
  private handleMessage(data: any): void {
    switch (data.type) {
      case 'progress':
        this.handleProgressUpdate(data);
        break;
      case 'complete':
        this.handleCompletion(data);
        break;
      case 'error':
        this.handleServerError(data);
        break;
      case 'heartbeat':
        // 心跳消息，保持连接活跃
        break;
      default:
        console.log('🔍 [SSE进度管理器] 未知消息类型:', data.type);
    }
  }

  /**
   * 处理进度更新
   */
  private handleProgressUpdate(data: any): void {
    const progressData: ProgressData = {
      progress: {
        percentage: data.percentage || 0,
        current: data.current || 0,
        total: data.total || 0,
        message: data.message,
        step: data.step,
      },
      status: data.status || 'running',
      timestamp: Date.now(),
      taskId: this.taskId,
    };

    this.options.onProgress(progressData);
    emit('sse:progress', progressData);
  }

  /**
   * 处理完成事件
   */
  private handleCompletion(data: any): void {
    const completionData: CompletionData = {
      status: data.status || 'completed',
      message: data.message || '任务完成',
      summary: data.summary,
      timestamp: Date.now(),
      taskId: this.taskId,
    };

    this.options.onComplete(completionData);
    emit('sse:complete', completionData);

    // 完成后关闭连接
    this.stop();
  }

  /**
   * 处理服务器错误
   */
  private handleServerError(data: any): void {
    const error = new Error(data.message || '服务器错误');
    this.options.onError(error);
    emit('sse:server:error', { taskId: this.taskId, error, data });
  }

  /**
   * 处理连接错误
   */
  private handleConnectionError(error: Error): void {
    this.isConnected = false;
    this.options.onDisconnect();
    emit('sse:disconnected', { taskId: this.taskId, error });

    // 尝试重连
    if (
      this.reconnectAttempts < this.options.reconnectAttempts &&
      !this.isStopped
    ) {
      this.reconnectAttempts++;
      console.log(
        `🔄 [SSE进度管理器] 尝试重连 (${this.reconnectAttempts}/${this.options.reconnectAttempts})`
      );

      this.reconnectTimer = setTimeout(() => {
        this.disconnect();
        this.connect();
      }, this.options.reconnectDelay * this.reconnectAttempts);
    } else {
      console.error('❌ [SSE进度管理器] 重连失败，已达到最大尝试次数');
      this.options.onError(error);
    }
  }

  /**
   * 断开连接
   */
  private disconnect(): void {
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }

    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }

    this.isConnected = false;
  }

  /**
   * 停止SSE连接
   */
  stop(): void {
    this.isStopped = true;
    this.disconnect();

    console.log('🔌 [SSE进度管理器] 已停止');
    emit('sse:stopped', { taskId: this.taskId });
  }

  /**
   * 获取连接状态
   */
  isConnectedToServer(): boolean {
    return this.isConnected;
  }

  /**
   * 获取任务ID
   */
  getTaskId(): string {
    return this.taskId;
  }

  /**
   * 获取重连次数
   */
  getReconnectAttempts(): number {
    return this.reconnectAttempts;
  }

  /**
   * 重置重连计数
   */
  resetReconnectAttempts(): void {
    this.reconnectAttempts = 0;
  }
}

export default SSEProgressManager;
