/**
 * 现代化状态管理系统
 */

import { eventBus } from './EventBus';
import { localStorage } from '../utils/storage';

// 状态类型定义
export interface SyncState {
  status: 'idle' | 'running' | 'paused' | 'completed' | 'error' | 'cancelled';
  progress: number;
  total: number;
  current_item: string;
  start_time: number;
  estimated_completion?: number;
  sync_type: string;
  sync_id: string;
  errors: SyncError[];
  warnings: SyncWarning[];
}

export interface QueueState {
  total_jobs: number;
  pending_jobs: number;
  processing_jobs: number;
  completed_jobs: number;
  failed_jobs: number;
  queue_size: number;
  is_processing: boolean;
  last_processed: string;
}

export interface SyncError {
  id: string;
  message: string;
  code: string;
  timestamp: number;
  context?: any;
}

export interface SyncWarning {
  id: string;
  message: string;
  timestamp: number;
  context?: any;
}

export interface AppState {
  sync: SyncState;
  queue: QueueState;
  ui: {
    activeTab: string;
    loading: boolean;
    notifications: Notification[];
  };
  config: {
    auto_refresh: boolean;
    refresh_interval: number;
    page_visible: boolean;
  };
}

export interface Notification {
  id: string;
  type: 'success' | 'error' | 'warning' | 'info';
  message: string;
  timestamp: number;
  persistent?: boolean;
}

// 状态动作类型
export interface StateAction {
  type: string;
  payload?: any;
}

// 状态监听器类型
export type StateListener<T = any> = (state: T, prevState: T, action: StateAction) => void;

/**
 * 状态管理器基类
 */
export class StateManager<T = any> {
  private state: T;
  private listeners: Set<StateListener<T>> = new Set();
  private middleware: Array<(action: StateAction, state: T) => StateAction | null> = [];
  private storageKey?: string;
  private autoSave: boolean = false;

  constructor(initialState: T, options: { storageKey?: string; autoSave?: boolean } = {}) {
    this.state = initialState;
    this.storageKey = options.storageKey;
    this.autoSave = options.autoSave || false;

    // 从存储中恢复状态
    if (this.storageKey) {
      this.restoreState();
    }
  }

  /**
   * 获取当前状态
   */
  getState(): T {
    return { ...this.state } as T;
  }

  /**
   * 设置状态
   */
  setState(newState: Partial<T>, action?: StateAction): void {
    const prevState = { ...this.state } as T;
    this.state = { ...this.state, ...newState } as T;

    // 触发监听器
    this.listeners.forEach(listener => {
      try {
        listener(this.state, prevState, action || { type: 'SET_STATE' });
      } catch (error) {
        console.error('State listener error:', error);
      }
    });

    // 自动保存
    if (this.autoSave && this.storageKey) {
      this.saveState();
    }

    // 发送全局事件
    eventBus.emit('state:change', {
      state: this.state,
      prevState,
      action: action || { type: 'SET_STATE' }
    });
  }

  /**
   * 分发动作
   */
  dispatch(action: StateAction): void {
    // 应用中间件
    let processedAction: StateAction | null = action;
    for (const middleware of this.middleware) {
      processedAction = middleware(processedAction, this.state);
      if (!processedAction) break;
    }

    if (!processedAction) return;

    // 发送动作事件
    eventBus.emit('state:action', processedAction);

    // 这里可以添加reducer逻辑
    // 目前直接通过setState更新状态
    eventBus.emit(`action:${processedAction.type}`, processedAction);
  }

  /**
   * 订阅状态变化
   */
  subscribe(listener: StateListener<T>): () => void {
    this.listeners.add(listener);
    
    return () => {
      this.listeners.delete(listener);
    };
  }

  /**
   * 添加中间件
   */
  use(middleware: (action: StateAction, state: T) => StateAction | null): void {
    this.middleware.push(middleware);
  }

  /**
   * 保存状态到存储
   */
  saveState(): void {
    if (!this.storageKey) return;
    
    try {
      localStorage.set(this.storageKey, this.state, { ttl: 3600000 }); // 1小时TTL
    } catch (error) {
      console.error('Failed to save state:', error);
    }
  }

  /**
   * 从存储恢复状态
   */
  restoreState(): void {
    if (!this.storageKey) return;

    try {
      const savedState = localStorage.get<T>(this.storageKey);
      if (savedState) {
        this.state = { ...this.state, ...savedState } as T;
        console.log(`State restored from storage: ${this.storageKey}`);
      }
    } catch (error) {
      console.error('Failed to restore state:', error);
    }
  }

  /**
   * 清除存储的状态
   */
  clearStoredState(): void {
    if (!this.storageKey) return;
    localStorage.remove(this.storageKey);
  }

  /**
   * 重置状态
   */
  reset(initialState?: T): void {
    if (initialState) {
      this.state = initialState;
    }
    this.clearStoredState();
    eventBus.emit('state:reset', { state: this.state });
  }
}

/**
 * 应用状态管理器
 */
export class AppStateManager extends StateManager<AppState> {
  constructor() {
    const initialState: AppState = {
      sync: {
        status: 'idle',
        progress: 0,
        total: 0,
        current_item: '',
        start_time: 0,
        sync_type: '',
        sync_id: '',
        errors: [],
        warnings: []
      },
      queue: {
        total_jobs: 0,
        pending_jobs: 0,
        processing_jobs: 0,
        completed_jobs: 0,
        failed_jobs: 0,
        queue_size: 0,
        is_processing: false,
        last_processed: ''
      },
      ui: {
        activeTab: 'sync',
        loading: false,
        notifications: []
      },
      config: {
        auto_refresh: true,
        refresh_interval: 5000,
        page_visible: true
      }
    };

    super(initialState, {
      storageKey: 'notion_wp_app_state',
      autoSave: true
    });

    this.setupActionHandlers();
    this.setupVisibilityHandling();
  }

  /**
   * 设置动作处理器
   */
  private setupActionHandlers(): void {
    // 同步相关动作
    eventBus.on('action:START_SYNC', (_event, action) => {
      this.setState({
        sync: {
          ...this.getState().sync,
          status: 'running',
          start_time: Date.now(),
          sync_type: action.payload.syncType,
          sync_id: action.payload.syncId,
          progress: 0,
          errors: [],
          warnings: []
        }
      }, action);
    });

    eventBus.on('action:UPDATE_SYNC_PROGRESS', (_event, action) => {
      this.setState({
        sync: {
          ...this.getState().sync,
          progress: action.payload.progress,
          total: action.payload.total,
          current_item: action.payload.currentItem || ''
        }
      }, action);
    });

    eventBus.on('action:COMPLETE_SYNC', (_event, action) => {
      this.setState({
        sync: {
          ...this.getState().sync,
          status: 'completed',
          progress: this.getState().sync.total
        }
      }, action);
    });

    eventBus.on('action:ERROR_SYNC', (_event, action) => {
      const currentState = this.getState();
      this.setState({
        sync: {
          ...currentState.sync,
          status: 'error',
          errors: [...currentState.sync.errors, action.payload.error]
        }
      }, action);
    });

    // 队列相关动作
    eventBus.on('action:UPDATE_QUEUE_STATUS', (_event, action) => {
      this.setState({
        queue: {
          ...this.getState().queue,
          ...action.payload
        }
      }, action);
    });

    // UI相关动作
    eventBus.on('action:SET_ACTIVE_TAB', (_event, action) => {
      this.setState({
        ui: {
          ...this.getState().ui,
          activeTab: action.payload.tab
        }
      }, action);
    });

    eventBus.on('action:ADD_NOTIFICATION', (_event, action) => {
      const currentState = this.getState();
      this.setState({
        ui: {
          ...currentState.ui,
          notifications: [...currentState.ui.notifications, action.payload.notification]
        }
      }, action);
    });
  }

  /**
   * 设置页面可见性处理
   */
  private setupVisibilityHandling(): void {
    document.addEventListener('visibilitychange', () => {
      this.setState({
        config: {
          ...this.getState().config,
          page_visible: !document.hidden
        }
      });
    });

    window.addEventListener('focus', () => {
      this.setState({
        config: {
          ...this.getState().config,
          page_visible: true
        }
      });
    });
  }

  /**
   * 便捷方法：开始同步
   */
  startSync(syncType: string, syncId: string): void {
    this.dispatch({
      type: 'START_SYNC',
      payload: { syncType, syncId }
    });
  }

  /**
   * 便捷方法：更新同步进度
   */
  updateSyncProgress(progress: number, total: number, currentItem?: string): void {
    this.dispatch({
      type: 'UPDATE_SYNC_PROGRESS',
      payload: { progress, total, currentItem }
    });
  }

  /**
   * 便捷方法：完成同步
   */
  completeSync(): void {
    this.dispatch({
      type: 'COMPLETE_SYNC',
      payload: {}
    });
  }

  /**
   * 便捷方法：同步错误
   */
  syncError(error: SyncError): void {
    this.dispatch({
      type: 'ERROR_SYNC',
      payload: { error }
    });
  }

  /**
   * 便捷方法：更新队列状态
   */
  updateQueueStatus(queueData: Partial<QueueState>): void {
    this.dispatch({
      type: 'UPDATE_QUEUE_STATUS',
      payload: queueData
    });
  }

  /**
   * 便捷方法：添加通知
   */
  addNotification(notification: Omit<Notification, 'id' | 'timestamp'>): void {
    const fullNotification: Notification = {
      ...notification,
      id: `notification_${Date.now()}_${Math.random().toString(36).substring(2, 11)}`,
      timestamp: Date.now()
    };

    this.dispatch({
      type: 'ADD_NOTIFICATION',
      payload: { notification: fullNotification }
    });
  }
}

// 全局状态管理器实例
export const appStateManager = new AppStateManager();
