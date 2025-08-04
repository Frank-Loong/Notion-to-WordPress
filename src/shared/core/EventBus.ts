/**
 * 事件总线系统
 */

// 本地类型定义
export type EventCallback = (event: any, ...args: any[]) => void;

export interface CustomEventData<T = any> {
  type: string;
  detail: T;
  timestamp: number;
}

export interface EventBus {
  on(event: string, callback: EventCallback): void;
  off(event: string, callback?: EventCallback): void;
  emit(event: string, ...args: any[]): void;
  once(event: string, callback: EventCallback): void;
}

/**
 * 事件监听器接口
 */
interface EventListener {
  callback: EventCallback;
  once: boolean;
  priority: number;
}

/**
 * 事件总线实现
 */
export class EventBusImpl implements EventBus {
  private listeners: Map<string, EventListener[]> = new Map();
  private maxListeners = 100;
  private debug = false;

  /**
   * 设置调试模式
   */
  setDebug(debug: boolean): void {
    this.debug = debug;
  }

  /**
   * 设置最大监听器数量
   */
  setMaxListeners(max: number): void {
    this.maxListeners = max;
  }

  /**
   * 添加事件监听器
   */
  on(event: string, callback: EventCallback, priority = 10): void {
    this.addListener(event, callback, false, priority);
  }

  /**
   * 添加一次性事件监听器
   */
  once(event: string, callback: EventCallback, priority = 10): void {
    this.addListener(event, callback, true, priority);
  }

  /**
   * 移除事件监听器
   */
  off(event: string, callback?: EventCallback): void {
    if (!this.listeners.has(event)) {
      return;
    }

    const listeners = this.listeners.get(event)!;

    if (!callback) {
      // 移除所有监听器
      this.listeners.delete(event);
      this.log(`Removed all listeners for event: ${event}`);
      return;
    }

    // 移除特定监听器
    const index = listeners.findIndex(listener => listener.callback === callback);
    if (index !== -1) {
      listeners.splice(index, 1);
      this.log(`Removed listener for event: ${event}`);

      if (listeners.length === 0) {
        this.listeners.delete(event);
      }
    }
  }

  /**
   * 触发事件
   */
  emit(event: string, ...args: any[]): void {
    if (!this.listeners.has(event)) {
      this.log(`No listeners for event: ${event}`);
      return;
    }

    const listeners = this.listeners.get(event)!.slice(); // 复制数组避免修改原数组
    const customEvent: CustomEventData = {
      type: event,
      detail: args[0],
      timestamp: Date.now()
    };

    this.log(`Emitting event: ${event} with ${listeners.length} listeners`);

    listeners.forEach(listener => {
      try {
        listener.callback(customEvent, ...args);

        // 如果是一次性监听器，移除它
        if (listener.once) {
          this.off(event, listener.callback);
        }
      } catch (error) {
        console.error(`Error in event listener for ${event}:`, error);
      }
    });
  }

  /**
   * 获取事件的监听器数量
   */
  listenerCount(event: string): number {
    return this.listeners.get(event)?.length || 0;
  }

  /**
   * 获取所有事件名称
   */
  eventNames(): string[] {
    return Array.from(this.listeners.keys());
  }

  /**
   * 移除所有监听器
   */
  removeAllListeners(event?: string): void {
    if (event) {
      this.listeners.delete(event);
      this.log(`Removed all listeners for event: ${event}`);
    } else {
      this.listeners.clear();
      this.log('Removed all listeners for all events');
    }
  }

  /**
   * 检查是否有监听器
   */
  hasListeners(event: string): boolean {
    return this.listeners.has(event) && this.listeners.get(event)!.length > 0;
  }

  /**
   * 添加监听器的内部方法
   */
  private addListener(event: string, callback: EventCallback, once: boolean, priority: number): void {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, []);
    }

    const listeners = this.listeners.get(event)!;

    // 检查最大监听器数量
    if (listeners.length >= this.maxListeners) {
      console.warn(`Maximum listeners (${this.maxListeners}) exceeded for event: ${event}`);
    }

    // 创建监听器对象
    const listener: EventListener = {
      callback,
      once,
      priority
    };

    // 按优先级插入（优先级越小越先执行）
    let insertIndex = listeners.length;
    for (let i = 0; i < listeners.length; i++) {
      if (listeners[i].priority > priority) {
        insertIndex = i;
        break;
      }
    }

    listeners.splice(insertIndex, 0, listener);
    this.log(`Added ${once ? 'once' : 'on'} listener for event: ${event} (priority: ${priority})`);
  }

  /**
   * 调试日志
   */
  private log(message: string): void {
    if (this.debug) {
      console.log(`[EventBus] ${message}`);
    }
  }
}

/**
 * 全局事件总线实例
 */
export const eventBus = new EventBusImpl();

/**
 * 便捷的全局函数
 */
export const on = eventBus.on.bind(eventBus);
export const once = eventBus.once.bind(eventBus);
export const off = eventBus.off.bind(eventBus);
export const emit = eventBus.emit.bind(eventBus);

/**
 * WordPress钩子系统集成
 */
export class WordPressHooks {
  private eventBus: EventBusImpl;

  constructor(eventBus: EventBusImpl) {
    this.eventBus = eventBus;
  }

  /**
   * 添加WordPress动作钩子
   */
  addAction(tag: string, callback: EventCallback, priority = 10): void {
    this.eventBus.on(`action:${tag}`, callback, priority);
  }

  /**
   * 执行WordPress动作钩子
   */
  doAction(tag: string, ...args: any[]): void {
    this.eventBus.emit(`action:${tag}`, ...args);
  }

  /**
   * 添加WordPress过滤器钩子
   */
  addFilter<T = any>(tag: string, callback: (value: T, ...args: any[]) => T, priority = 10): void {
    this.eventBus.on(`filter:${tag}`, (event: any, value: T, ...args: any[]) => {
      const result = callback(value, ...args);
      // 将结果存储在事件对象中
      event.result = result;
    }, priority);
  }

  /**
   * 应用WordPress过滤器钩子
   */
  applyFilters<T = any>(tag: string, value: T, ...args: any[]): T {
    const event = {
      type: `filter:${tag}`,
      detail: value,
      timestamp: Date.now(),
      result: value
    } as any;

    this.eventBus.emit(`filter:${tag}`, event, value, ...args);
    return event.result;
  }
}

/**
 * WordPress钩子实例
 */
export const wpHooks = new WordPressHooks(eventBus);

// 如果WordPress钩子系统存在，集成到全局
if (typeof window !== 'undefined' && window.wp?.hooks) {
  // 将我们的事件系统与WordPress钩子系统集成
  const originalAddAction = window.wp.hooks.addAction;
  const originalDoAction = window.wp.hooks.doAction;
  const originalAddFilter = window.wp.hooks.addFilter;
  const originalApplyFilters = window.wp.hooks.applyFilters;

  // 扩展WordPress钩子系统
  window.wp.hooks.addAction = function(tag: string, callback: Function, priority = 10) {
    originalAddAction.call(this, tag, callback, priority);
    wpHooks.addAction(tag, callback as EventCallback, priority);
  };

  window.wp.hooks.doAction = function(tag: string, ...args: any[]) {
    originalDoAction.call(this, tag, ...args);
    wpHooks.doAction(tag, ...args);
  };

  window.wp.hooks.addFilter = function(tag: string, callback: Function, priority = 10) {
    originalAddFilter.call(this, tag, callback, priority);
    wpHooks.addFilter(tag, callback as any, priority);
  };

  window.wp.hooks.applyFilters = function(tag: string, value: any, ...args: any[]) {
    const wpResult = originalApplyFilters.call(this, tag, value, ...args);
    return wpHooks.applyFilters(tag, wpResult, ...args);
  };
}
