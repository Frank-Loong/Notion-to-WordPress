/**
 * 组件管理器
 */

import { eventBus } from '../../shared/core/EventBus';
import { BaseComponent } from './BaseComponent';
import { SyncButton } from './SyncButton';

export interface ComponentConfig {
  type: string;
  selector: string;
  options?: any;
  autoInit?: boolean;
}

export interface ComponentRegistry {
  [key: string]: new (options: any) => BaseComponent;
}

/**
 * 组件管理器类
 */
export class ComponentManager {
  private components: Map<string, BaseComponent> = new Map();
  private registry: ComponentRegistry = {};
  private initialized = false;

  constructor() {
    this.registerBuiltinComponents();
    this.setupEventListeners();
  }

  /**
   * 注册内置组件
   *
   * 收口迭代（2026-11-16）：只保留真实生效的同步按钮。原注册表里的
   * status-display / form-component / tab-manager 三项对应的发现器
   * 在后台模板中 0 命中（无 data-status-type、无 form[data-component]、
   * 无 [data-component="tab-manager"]），从未实例化过任何组件。
   */
  private registerBuiltinComponents(): void {
    this.registry = {
      'sync-button': SyncButton,
    };
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 监听组件生命周期事件
    eventBus.on('component:mount', (_event, data) => {
      console.log('Component mounted:', data.component.constructor.name);
    });

    eventBus.on('component:unmount', (_event, data) => {
      console.log('Component unmounted:', data.component.constructor.name);
    });

    eventBus.on('component:destroy', (_event, data) => {
      console.log('Component destroyed:', data.component.constructor.name);
      this.removeComponentFromRegistry(data.component);
    });

    // 监听页面卸载
    window.addEventListener('beforeunload', () => {
      this.destroyAll();
    });
  }

  /**
   * 初始化组件管理器
   */
  init(): void {
    if (this.initialized) {
      return;
    }

    this.initialized = true;
    this.autoDiscoverComponents();

    console.log('✅ Component Manager initialized');
  }

  /**
   * 自动发现组件
   *
   * 收口迭代（2026-11-16）：后台模板中只有同步按钮带 data-* 标记，
   * 其余三个发现器的选择器（[data-status-type]、form[data-component="form"]、
   * [data-component="tab-manager"]）在模板里 0 命中，永远不会实例化任何组件。
   * 这些死发现器与其组件类已一并移除，只保留真实生效的同步按钮发现。
   */
  private autoDiscoverComponents(): void {
    // 发现同步按钮
    this.discoverSyncButtons();
  }

  /**
   * 发现同步按钮
   */
  private discoverSyncButtons(): void {
    const buttons = document.querySelectorAll('[data-sync-type]');

    buttons.forEach((button, index) => {
      const element = button as HTMLElement;
      const syncType = element.getAttribute('data-sync-type') as
        | 'smart'
        | 'full'
        | 'test';
      // 收口迭代（2026-11-16）：属性缺失时必须传 undefined，而不是
      // hasAttribute() 得到的 false —— 否则 SyncButton 内的 `?? true`
      // 默认值永远不生效（缺属性被当成「显式关闭」）。
      const incremental = this.readBooleanAttribute(
        element,
        'data-incremental'
      );
      const checkDeletions = this.readBooleanAttribute(
        element,
        'data-check-deletions'
      );
      const confirmMessage = element.getAttribute('data-confirm');

      const componentId = `sync-button-${syncType}-${index}`;

      const syncButton = new SyncButton({
        element,
        syncType,
        incremental,
        checkDeletions,
        confirmMessage: confirmMessage || undefined,
      });

      this.components.set(componentId, syncButton);
    });
  }

  /**
   * 读取布尔型 data 属性
   *
   * 返回 undefined 表示「属性不存在，交由调用方的默认值决定」；
   * `data-x` / `data-x="true"` / `data-x="1"` 为 true；
   * `data-x="false"` / `data-x="0"` 为 false。
   */
  private readBooleanAttribute(
    element: HTMLElement,
    name: string
  ): boolean | undefined {
    const raw = element.getAttribute(name);
    if (raw === null) {
      return undefined;
    }
    return raw !== 'false' && raw !== '0';
  }

  /**
   * 注册组件类型
   */
  public registerComponent(
    type: string,
    componentClass: new (options: any) => BaseComponent
  ): void {
    this.registry[type] = componentClass;
  }

  /**
   * 创建组件
   */
  public createComponent(type: string, options: any): BaseComponent | null {
    const ComponentClass = this.registry[type];

    if (!ComponentClass) {
      console.error(`Unknown component type: ${type}`);
      return null;
    }

    try {
      const component = new ComponentClass(options);
      const componentId = this.generateComponentId(type);
      this.components.set(componentId, component);

      return component;
    } catch (error) {
      console.error(`Failed to create component ${type}:`, error);
      return null;
    }
  }

  /**
   * 批量创建组件
   */
  public createComponents(configs: ComponentConfig[]): BaseComponent[] {
    const components: BaseComponent[] = [];

    configs.forEach(config => {
      const component = this.createComponent(config.type, {
        selector: config.selector,
        autoInit: config.autoInit !== false,
        ...config.options,
      });

      if (component) {
        components.push(component);
      }
    });

    return components;
  }

  /**
   * 获取组件
   */
  public getComponent(componentId: string): BaseComponent | undefined {
    return this.components.get(componentId);
  }

  /**
   * 获取指定类型的所有组件
   */
  public getComponentsByType(type: string): BaseComponent[] {
    return Array.from(this.components.values()).filter(component =>
      component.constructor.name.toLowerCase().includes(type.toLowerCase())
    );
  }

  /**
   * 销毁组件
   */
  public destroyComponent(componentId: string): void {
    const component = this.components.get(componentId);

    if (component) {
      component.destroy();
      this.components.delete(componentId);
    }
  }

  /**
   * 销毁所有组件
   */
  public destroyAll(): void {
    this.components.forEach((component, componentId) => {
      try {
        component.destroy();
      } catch (error) {
        console.error(`Error destroying component ${componentId}:`, error);
      }
    });

    this.components.clear();
    this.initialized = false;

    console.log('🔥 All components destroyed');
  }

  /**
   * 重新初始化所有组件
   */
  public reinitialize(): void {
    this.destroyAll();
    this.init();
  }

  /**
   * 从注册表中移除组件
   */
  private removeComponentFromRegistry(component: BaseComponent): void {
    for (const [
      componentId,
      registeredComponent,
    ] of this.components.entries()) {
      if (registeredComponent === component) {
        this.components.delete(componentId);
        break;
      }
    }
  }

  /**
   * 生成组件ID
   */
  private generateComponentId(type: string): string {
    const timestamp = Date.now();
    const random = Math.random().toString(36).substring(2, 8);
    return `${type}-${timestamp}-${random}`;
  }

  /**
   * 获取所有组件
   */
  public getAllComponents(): Map<string, BaseComponent> {
    return new Map(this.components);
  }

  /**
   * 获取组件数量
   */
  public getComponentCount(): number {
    return this.components.size;
  }

  /**
   * 检查组件是否存在
   */
  public hasComponent(componentId: string): boolean {
    return this.components.has(componentId);
  }

  /**
   * 获取已注册的组件类型
   */
  public getRegisteredTypes(): string[] {
    return Object.keys(this.registry);
  }

  /**
   * 检查是否已初始化
   */
  public isInitialized(): boolean {
    return this.initialized;
  }
}

// 全局组件管理器实例
export const componentManager = new ComponentManager();
