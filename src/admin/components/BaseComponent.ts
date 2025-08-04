/**
 * 基础组件类
 */

import { eventBus } from '../../shared/core/EventBus';
import { appStateManager } from '../../shared/core/StateManager';
import { querySelector } from '../../shared/utils/dom';

export interface ComponentOptions {
  selector?: string;
  element?: HTMLElement;
  autoInit?: boolean;
  destroyOnUnmount?: boolean;
}

export interface ComponentState {
  mounted: boolean;
  initialized: boolean;
  destroyed: boolean;
}

/**
 * 基础组件类
 */
export abstract class BaseComponent {
  protected element: HTMLElement | null = null;
  protected options: ComponentOptions;
  protected state: ComponentState;
  protected eventListeners: Array<{ element: HTMLElement | Window | Document; event: string; handler: EventListener }> = [];
  protected stateUnsubscribe: (() => void) | null = null;

  constructor(options: ComponentOptions = {}) {
    this.options = {
      autoInit: true,
      destroyOnUnmount: true,
      ...options
    };

    this.state = {
      mounted: false,
      initialized: false,
      destroyed: false
    };

    if (this.options.autoInit) {
      this.init();
    }
  }

  /**
   * 初始化组件
   */
  init(): void {
    if (this.state.initialized || this.state.destroyed) {
      return;
    }

    // 查找元素
    if (this.options.element) {
      this.element = this.options.element;
    } else if (this.options.selector) {
      this.element = querySelector(this.options.selector);
    }

    if (!this.element) {
      console.warn(`Component element not found: ${this.options.selector}`);
      return;
    }

    this.state.initialized = true;

    // 设置状态监听
    this.setupStateListeners();

    // 调用子类的初始化方法
    this.onInit();

    // 挂载组件
    this.mount();

    console.log(`Component initialized: ${this.constructor.name}`);
  }

  /**
   * 挂载组件
   */
  mount(): void {
    if (this.state.mounted || this.state.destroyed || !this.element) {
      return;
    }

    this.state.mounted = true;

    // 绑定事件
    this.bindEvents();

    // 调用子类的挂载方法
    this.onMount();

    // 发送挂载事件
    this.emit('component:mount', { component: this });

    console.log(`Component mounted: ${this.constructor.name}`);
  }

  /**
   * 卸载组件
   */
  unmount(): void {
    if (!this.state.mounted || this.state.destroyed) {
      return;
    }

    this.state.mounted = false;

    // 解绑事件
    this.unbindEvents();

    // 调用子类的卸载方法
    this.onUnmount();

    // 发送卸载事件
    this.emit('component:unmount', { component: this });

    // 如果设置了自动销毁，则销毁组件
    if (this.options.destroyOnUnmount) {
      this.destroy();
    }

    console.log(`Component unmounted: ${this.constructor.name}`);
  }

  /**
   * 销毁组件
   */
  destroy(): void {
    if (this.state.destroyed) {
      return;
    }

    // 先卸载
    if (this.state.mounted) {
      this.unmount();
    }

    this.state.destroyed = true;

    // 清理状态监听
    if (this.stateUnsubscribe) {
      this.stateUnsubscribe();
      this.stateUnsubscribe = null;
    }

    // 调用子类的销毁方法
    this.onDestroy();

    // 清理引用
    this.element = null;
    this.eventListeners = [];

    // 发送销毁事件
    this.emit('component:destroy', { component: this });

    console.log(`Component destroyed: ${this.constructor.name}`);
  }

  /**
   * 重新渲染组件
   */
  render(): void {
    if (!this.state.mounted || this.state.destroyed) {
      return;
    }

    this.onRender();
  }

  /**
   * 添加事件监听器
   */
  protected addEventListener(
    element: HTMLElement | Window | Document,
    event: string,
    handler: EventListener,
    options?: AddEventListenerOptions
  ): void {
    element.addEventListener(event, handler, options);
    this.eventListeners.push({ element, event, handler });
  }

  /**
   * 移除事件监听器
   */
  protected removeEventListener(
    element: HTMLElement | Window | Document,
    event: string,
    handler: EventListener
  ): void {
    element.removeEventListener(event, handler);

    const index = this.eventListeners.findIndex(
      listener => listener.element === element && listener.event === event && listener.handler === handler
    );

    if (index > -1) {
      this.eventListeners.splice(index, 1);
    }
  }

  /**
   * 解绑所有事件监听器
   */
  protected unbindEvents(): void {
    this.eventListeners.forEach(({ element, event, handler }) => {
      element.removeEventListener(event, handler);
    });
    this.eventListeners = [];
  }

  /**
   * 发送事件
   */
  protected emit(event: string, data?: any): void {
    eventBus.emit(event, data);
  }

  /**
   * 监听事件
   */
  protected on(event: string, handler: (event: any, data: any) => void): void {
    eventBus.on(event, handler);
  }

  /**
   * 取消监听事件
   */
  protected off(event: string, handler: (event: any, data: any) => void): void {
    eventBus.off(event, handler);
  }

  /**
   * 获取应用状态
   */
  protected getState(): any {
    return appStateManager.getState();
  }

  /**
   * 更新应用状态
   */
  protected setState(newState: any): void {
    appStateManager.setState(newState);
  }

  /**
   * 查找子元素
   */
  protected $(selector: string): HTMLElement | null {
    return this.element ? this.element.querySelector(selector) : null;
  }

  /**
   * 查找所有子元素
   */
  protected $$(selector: string): NodeListOf<HTMLElement> {
    return this.element ? this.element.querySelectorAll(selector) : document.querySelectorAll('');
  }

  /**
   * 设置状态监听器
   */
  protected setupStateListeners(): void {
    this.stateUnsubscribe = appStateManager.subscribe((state, prevState, action) => {
      this.onStateChange(state, prevState, action);
    });
  }

  /**
   * 检查组件是否已挂载
   */
  isMounted(): boolean {
    return this.state.mounted;
  }

  /**
   * 检查组件是否已初始化
   */
  isInitialized(): boolean {
    return this.state.initialized;
  }

  /**
   * 检查组件是否已销毁
   */
  isDestroyed(): boolean {
    return this.state.destroyed;
  }

  /**
   * 获取组件元素
   */
  getElement(): HTMLElement | null {
    return this.element;
  }

  // 抽象方法，子类必须实现
  protected abstract onInit(): void;
  protected abstract onMount(): void;
  protected abstract onUnmount(): void;
  protected abstract onDestroy(): void;
  protected abstract onRender(): void;
  protected abstract bindEvents(): void;
  protected abstract onStateChange(state: any, prevState: any, action: any): void;
}
