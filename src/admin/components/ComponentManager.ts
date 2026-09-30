/**
 * 组件管理器
 */

import { eventBus } from '../../shared/core/EventBus';
import { BaseComponent } from './BaseComponent';
import { SyncButton } from './SyncButton';
import { StatusDisplay } from './StatusDisplay';
import { FormComponent } from './FormComponent';
import { TabManager } from './TabManager';

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
   */
  private registerBuiltinComponents(): void {
    this.registry = {
      'sync-button': SyncButton,
      'status-display': StatusDisplay,
      'form-component': FormComponent,
      'tab-manager': TabManager,
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
   */
  private autoDiscoverComponents(): void {
    // 发现同步按钮
    this.discoverSyncButtons();

    // 发现状态显示组件
    this.discoverStatusDisplays();

    // 发现表单组件
    this.discoverFormComponents();

    // 发现标签管理器
    this.discoverTabManagers();
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
      const incremental = element.hasAttribute('data-incremental');
      const checkDeletions = element.hasAttribute('data-check-deletions');
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
   * 发现状态显示组件
   */
  private discoverStatusDisplays(): void {
    const displays = document.querySelectorAll('[data-status-type]');

    displays.forEach((display, index) => {
      const element = display as HTMLElement;
      const statusType = element.getAttribute('data-status-type') as
        | 'sync'
        | 'queue'
        | 'async';
      const refreshInterval = parseInt(
        element.getAttribute('data-refresh-interval') || '5000'
      );
      const autoRefresh = !element.hasAttribute('data-no-auto-refresh');

      const componentId = `status-display-${statusType}-${index}`;

      const statusDisplay = new StatusDisplay({
        element,
        type: statusType,
        refreshInterval,
        autoRefresh,
      });

      this.components.set(componentId, statusDisplay);
    });
  }

  /**
   * 发现表单组件
   */
  private discoverFormComponents(): void {
    const forms = document.querySelectorAll('form[data-component="form"]');

    forms.forEach((form, index) => {
      const element = form as HTMLElement;
      const validateOnInput = !element.hasAttribute('data-no-validate-input');
      const validateOnBlur = !element.hasAttribute('data-no-validate-blur');
      const submitOnEnter = element.hasAttribute('data-submit-enter');
      const autoSave = element.hasAttribute('data-auto-save');
      const autoSaveDelay = parseInt(
        element.getAttribute('data-auto-save-delay') || '2000'
      );

      const componentId = `form-component-${index}`;

      const formComponent = new FormComponent({
        element,
        validateOnInput,
        validateOnBlur,
        submitOnEnter,
        autoSave,
        autoSaveDelay,
      });

      this.components.set(componentId, formComponent);
    });
  }

  /**
   * 发现标签管理器
   */
  private discoverTabManagers(): void {
    const tabContainers = document.querySelectorAll(
      '[data-component="tab-manager"]'
    );

    tabContainers.forEach((container, index) => {
      const element = container as HTMLElement;
      const activeTabClass =
        element.getAttribute('data-active-class') || 'active';
      const tabContentClass =
        element.getAttribute('data-content-class') || 'tab-content';
      const saveActiveTab = !element.hasAttribute('data-no-save-tab');
      const storageKey =
        element.getAttribute('data-storage-key') || 'active_tab';
      const animationDuration = parseInt(
        element.getAttribute('data-animation-duration') || '300'
      );
      const defaultTab = element.getAttribute('data-default-tab') || undefined;

      const componentId = `tab-manager-${index}`;

      const tabManager = new TabManager({
        element,
        activeTabClass,
        tabContentClass,
        saveActiveTab,
        storageKey,
        animationDuration,
        defaultTab,
      });

      this.components.set(componentId, tabManager);
    });
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
