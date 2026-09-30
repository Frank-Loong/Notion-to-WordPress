/**
 * 同步进度管理器 - 现代化TypeScript版本
 *
 * 完全替代原有的sync-progress-manager.js，包括：
 * - SSE和UI管理器的统一协调
 * - 进度状态管理和持久化
 * - 页面可见性处理
 * - 错误处理和恢复
 */

import { emit, on } from '../shared/core/EventBus';
import {
  SSEProgressManager,
  ProgressData,
  CompletionData,
} from './managers/SSEProgressManager';
import {
  SyncProgressUI,
  ProgressUIData,
  ProgressUIOptions,
} from './managers/SyncProgressUI';

export interface SyncProgressOptions {
  taskId: string;
  syncType?: string;
  title?: string;
  enableSSE?: boolean;
  enableUI?: boolean;
  uiOptions?: ProgressUIOptions;
  onProgress?: (data: ProgressData) => void;
  onComplete?: (data: CompletionData) => void;
  onError?: (error: Error) => void;
}

export interface SyncProgressState {
  taskId: string | null;
  isActive: boolean;
  isVisible: boolean;
  startTime: number;
  lastUpdate: number;
  currentProgress: ProgressUIData | null;
}

/**
 * 同步进度管理器主类
 */
export class SyncProgressManager {
  private static instance: SyncProgressManager | null = null;

  private sseManager: SSEProgressManager | null = null;
  private uiManager!: SyncProgressUI;
  private state!: SyncProgressState;
  private visibilityTimer: NodeJS.Timeout | null = null;

  constructor() {
    if (SyncProgressManager.instance) {
      return SyncProgressManager.instance;
    }

    SyncProgressManager.instance = this;

    this.uiManager = SyncProgressUI.getInstance();
    this.state = {
      taskId: null,
      isActive: false,
      isVisible: false,
      startTime: 0,
      lastUpdate: 0,
      currentProgress: null,
    };

    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(): SyncProgressManager {
    if (!SyncProgressManager.instance) {
      SyncProgressManager.instance = new SyncProgressManager();
    }
    return SyncProgressManager.instance;
  }

  /**
   * 初始化进度管理器
   */
  private init(): void {
    this.setupEventListeners();
    this.setupVisibilityHandling();

    console.log('📊 [同步进度管理器] 已初始化');
    emit('sync:progress:manager:initialized');
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 监听UI事件
    on('progress:ui:shown', () => {
      this.state.isVisible = true;
    });

    on('progress:ui:hidden', () => {
      this.state.isVisible = false;
    });

    // 监听SSE事件
    on('sse:connected', () => {
      console.log('📊 [同步进度管理器] SSE连接已建立');
    });

    on('sse:disconnected', () => {
      console.log('📊 [同步进度管理器] SSE连接已断开');
    });

    on('sse:error', (_event, data) => {
      console.error('📊 [同步进度管理器] SSE错误:', data.error);
    });
  }

  /**
   * 设置页面可见性处理
   */
  private setupVisibilityHandling(): void {
    document.addEventListener('visibilitychange', () => {
      this.handleVisibilityChange();
    });

    window.addEventListener('focus', () => {
      if (this.state.isActive) {
        console.log('📊 [同步进度管理器] 页面重新获得焦点，检查进度状态');
        this.checkProgressStatus();
      }
    });
  }

  /**
   * 显示进度
   */
  showProgress(options: SyncProgressOptions): void {
    const {
      taskId,
      syncType = '同步',
      title,
      enableSSE = true,
      enableUI = true,
      uiOptions = {},
      onProgress,
      onComplete,
      onError,
    } = options;

    // 如果已经有活跃的进度，先停止
    if (this.state.isActive) {
      this.hideProgress();
    }

    // 更新状态
    this.state = {
      taskId,
      isActive: true,
      isVisible: false,
      startTime: Date.now(),
      lastUpdate: Date.now(),
      currentProgress: null,
    };

    // 显示UI
    if (enableUI) {
      const finalUIOptions: ProgressUIOptions = {
        title: title || `${syncType}进行中`,
        syncType,
        ...uiOptions,
      };

      this.uiManager.show(taskId, finalUIOptions);
      this.state.isVisible = true;
    }

    // 启动SSE
    if (enableSSE) {
      this.startSSE(taskId, { onProgress, onComplete, onError });
    }

    emit('sync:progress:started', { taskId, options });
    console.log(`📊 [同步进度管理器] 已启动进度跟踪: ${taskId}`);
  }

  /**
   * 隐藏进度
   */
  hideProgress(): void {
    if (!this.state.isActive) return;

    // 停止SSE
    if (this.sseManager) {
      this.sseManager.stop();
      this.sseManager = null;
    }

    // 隐藏UI
    this.uiManager.hide();

    // 清理定时器
    if (this.visibilityTimer) {
      clearTimeout(this.visibilityTimer);
      this.visibilityTimer = null;
    }

    // 重置状态
    const taskId = this.state.taskId;
    this.state = {
      taskId: null,
      isActive: false,
      isVisible: false,
      startTime: 0,
      lastUpdate: 0,
      currentProgress: null,
    };

    emit('sync:progress:stopped', { taskId });
    console.log('📊 [同步进度管理器] 已停止进度跟踪');
  }

  /**
   * 启动SSE
   */
  private startSSE(
    taskId: string,
    callbacks: {
      onProgress?: (data: ProgressData) => void;
      onComplete?: (data: CompletionData) => void;
      onError?: (error: Error) => void;
    }
  ): void {
    this.sseManager = new SSEProgressManager(taskId, {
      onProgress: (data: ProgressData) => {
        this.handleSSEProgress(data);
        callbacks.onProgress?.(data);
      },
      onComplete: (data: CompletionData) => {
        this.handleSSEComplete(data);
        callbacks.onComplete?.(data);
      },
      onError: (error: Error) => {
        this.handleSSEError(error);
        callbacks.onError?.(error);
      },
      onConnect: () => {
        console.log('📊 [同步进度管理器] SSE连接成功');
      },
      onDisconnect: () => {
        console.log('📊 [同步进度管理器] SSE连接断开');
      },
    });

    this.sseManager.start();
  }

  /**
   * 处理SSE进度更新
   */
  private handleSSEProgress(data: ProgressData): void {
    const uiData: ProgressUIData = {
      percentage: data.progress.percentage,
      current: data.progress.current,
      total: data.progress.total,
      message: data.progress.message,
      step: data.progress.step,
    };

    this.state.currentProgress = uiData;
    this.state.lastUpdate = Date.now();

    // 更新UI
    if (this.state.isVisible) {
      this.uiManager.updateProgress(uiData);
    }

    emit('sync:progress:updated', { data, uiData });
  }

  /**
   * 处理SSE完成
   */
  private handleSSEComplete(data: CompletionData): void {
    // 更新UI状态
    if (this.state.isVisible) {
      this.uiManager.setStatus(data.status, data.message);
    }

    emit('sync:progress:completed', { data });

    // 延迟隐藏进度
    setTimeout(() => {
      this.hideProgress();
    }, 2000);
  }

  /**
   * 处理SSE错误
   */
  private handleSSEError(error: Error): void {
    console.error('📊 [同步进度管理器] SSE错误:', error);

    // 更新UI状态
    if (this.state.isVisible) {
      this.uiManager.setStatus('failed', `连接错误: ${error.message}`);
    }

    emit('sync:progress:error', { error });

    // 延迟隐藏进度
    setTimeout(() => {
      this.hideProgress();
    }, 3000);
  }

  /**
   * 处理页面可见性变化
   */
  private handleVisibilityChange(): void {
    if (!this.state.isActive) return;

    const isVisible = !document.hidden;

    if (isVisible) {
      console.log('📊 [同步进度管理器] 页面重新可见，检查进度状态');
      this.checkProgressStatus();
    } else {
      console.log('📊 [同步进度管理器] 页面隐藏');
    }
  }

  /**
   * 检查进度状态
   */
  private checkProgressStatus(): void {
    if (!this.state.isActive || !this.state.taskId) return;

    // 检查是否长时间没有更新
    const timeSinceLastUpdate = Date.now() - this.state.lastUpdate;
    if (timeSinceLastUpdate > 30000) {
      // 30秒没有更新
      console.warn('📊 [同步进度管理器] 长时间没有进度更新，可能需要重新连接');

      // 尝试重新连接SSE
      if (this.sseManager) {
        this.sseManager.stop();
        setTimeout(() => {
          if (this.state.taskId) {
            this.startSSE(this.state.taskId, {});
          }
        }, 1000);
      }
    }
  }

  /**
   * 手动更新进度
   */
  updateProgress(data: ProgressUIData): void {
    if (!this.state.isActive) return;

    this.state.currentProgress = data;
    this.state.lastUpdate = Date.now();

    if (this.state.isVisible) {
      this.uiManager.updateProgress(data);
    }

    emit('sync:progress:manual:updated', { data });
  }

  /**
   * 设置状态
   */
  setStatus(
    status: 'running' | 'completed' | 'failed' | 'cancelled',
    message?: string
  ): void {
    if (!this.state.isActive) return;

    if (this.state.isVisible) {
      this.uiManager.setStatus(status, message);
    }

    emit('sync:progress:status:changed', { status, message });

    // 完成状态自动隐藏
    if (status !== 'running') {
      setTimeout(
        () => {
          this.hideProgress();
        },
        status === 'completed' ? 1000 : 2000
      );
    }
  }

  /**
   * 获取当前状态
   */
  getState(): SyncProgressState {
    return { ...this.state };
  }

  /**
   * 检查是否活跃
   */
  isActive(): boolean {
    return this.state.isActive;
  }

  /**
   * 检查是否可见
   */
  isVisible(): boolean {
    return this.state.isVisible;
  }

  /**
   * 获取当前任务ID
   */
  getCurrentTaskId(): string | null {
    return this.state.taskId;
  }

  /**
   * 获取运行时长
   */
  getDuration(): number {
    return this.state.startTime > 0 ? Date.now() - this.state.startTime : 0;
  }

  /**
   * 销毁管理器
   */
  destroy(): void {
    this.hideProgress();
    this.uiManager.destroy();

    if (this.visibilityTimer) {
      clearTimeout(this.visibilityTimer);
      this.visibilityTimer = null;
    }

    SyncProgressManager.instance = null;
    emit('sync:progress:manager:destroyed');
    console.log('📊 [同步进度管理器] 已销毁');
  }
}

// 导出单例实例
export const syncProgressManager = SyncProgressManager.getInstance();

export default SyncProgressManager;
