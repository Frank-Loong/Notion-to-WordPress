/**
 * 标签页管理组件
 */

import { BaseComponent, ComponentOptions } from './BaseComponent';
import { addClass, removeClass, hasClass } from '../../shared/utils/dom';
import { localStorage } from '../../shared/utils/storage';

export interface TabManagerOptions extends ComponentOptions {
  activeTabClass?: string;
  tabContentClass?: string;
  saveActiveTab?: boolean;
  storageKey?: string;
  animationDuration?: number;
  defaultTab?: string;
}

export interface TabInfo {
  id: string;
  element: HTMLElement;
  contentElement: HTMLElement | null;
  title: string;
  disabled: boolean;
}

/**
 * 标签页管理组件
 */
export class TabManager extends BaseComponent {
  private tabOptions: TabManagerOptions;
  private tabs: Map<string, TabInfo> = new Map();
  private activeTabId: string | null = null;

  constructor(options: TabManagerOptions) {
    super(options);
    this.tabOptions = {
      activeTabClass: 'active',
      tabContentClass: 'tab-content',
      saveActiveTab: true,
      storageKey: 'active_tab',
      animationDuration: 300,
      ...options
    };
  }

  protected onInit(): void {
    if (this.element) {
      addClass(this.element, 'tab-manager');
      this.discoverTabs();
      this.restoreActiveTab();
    }
  }

  protected onMount(): void {
    this.updateTabStates();
  }

  protected onUnmount(): void {
    // 清理状态
  }

  protected onDestroy(): void {
    this.tabs.clear();
  }

  protected onRender(): void {
    this.updateTabStates();
  }

  protected bindEvents(): void {
    this.tabs.forEach((tab, tabId) => {
      this.addEventListener(tab.element, 'click', (event: Event) => {
        event.preventDefault();
        this.activateTab(tabId);
      });

      // 支持键盘导航
      this.addEventListener(tab.element, 'keydown', (event: Event) => {
        const keyEvent = event as KeyboardEvent;
        if (keyEvent.key === 'Enter' || keyEvent.key === ' ') {
          keyEvent.preventDefault();
          this.activateTab(tabId);
        }
      });
    });
  }

  protected onStateChange(state: any, prevState: any, _action: any): void {
    // 监听应用状态中的活动标签变化
    if (state.ui?.activeTab !== prevState.ui?.activeTab) {
      this.activateTab(state.ui.activeTab);
    }
  }

  /**
   * 发现标签页
   */
  private discoverTabs(): void {
    if (!this.element) return;

    const tabElements = this.element.querySelectorAll('[data-tab]');
    
    tabElements.forEach(tabElement => {
      const element = tabElement as HTMLElement;
      const tabId = element.getAttribute('data-tab');
      
      if (tabId) {
        const contentElement = document.querySelector(`#${tabId}`) as HTMLElement;
        const title = element.textContent?.trim() || tabId;
        const disabled = element.hasAttribute('disabled') || hasClass(element, 'disabled');

        this.tabs.set(tabId, {
          id: tabId,
          element,
          contentElement,
          title,
          disabled
        });

        // 设置ARIA属性
        element.setAttribute('role', 'tab');
        element.setAttribute('aria-controls', tabId);
        element.setAttribute('aria-selected', 'false');
        element.setAttribute('tabindex', '-1');

        if (contentElement) {
          contentElement.setAttribute('role', 'tabpanel');
          contentElement.setAttribute('aria-labelledby', element.id || `tab-${tabId}`);
          addClass(contentElement, this.tabOptions.tabContentClass!);
        }
      }
    });

    // 设置容器的ARIA属性
    this.element.setAttribute('role', 'tablist');
  }

  /**
   * 激活标签页
   */
  public activateTab(tabId: string): void {
    const tab = this.tabs.get(tabId);
    
    if (!tab || tab.disabled) {
      return;
    }

    // 如果已经是活动标签，不需要切换
    if (this.activeTabId === tabId) {
      return;
    }

    const previousTabId = this.activeTabId;
    this.activeTabId = tabId;

    // 更新标签状态
    this.updateTabStates();

    // 切换内容显示
    this.switchTabContent(previousTabId, tabId);

    // 保存活动标签
    if (this.tabOptions.saveActiveTab) {
      this.saveActiveTab(tabId);
    }

    // 更新应用状态
    this.setState({
      ui: {
        ...this.getState().ui,
        activeTab: tabId
      }
    });

    // 发送事件
    this.emit('tab:change', {
      activeTab: tabId,
      previousTab: previousTabId,
      tabInfo: tab
    });

    console.log(`Tab activated: ${tabId}`);
  }

  /**
   * 切换标签内容
   */
  private switchTabContent(previousTabId: string | null, activeTabId: string): void {
    const activeTab = this.tabs.get(activeTabId);
    
    if (!activeTab?.contentElement) {
      return;
    }

    // 隐藏之前的内容
    if (previousTabId) {
      const previousTab = this.tabs.get(previousTabId);
      if (previousTab?.contentElement) {
        this.hideTabContent(previousTab.contentElement);
      }
    }

    // 显示当前内容
    this.showTabContent(activeTab.contentElement);
  }

  /**
   * 显示标签内容
   */
  private showTabContent(contentElement: HTMLElement): void {
    removeClass(contentElement, 'hidden');
    addClass(contentElement, this.tabOptions.activeTabClass!);
    
    // 添加淡入动画
    contentElement.style.display = 'none';
    contentElement.style.display = 'block';
    
    // 使用jQuery风格的淡入效果（如果需要）
    if (this.tabOptions.animationDuration! > 0) {
      contentElement.style.opacity = '0';
      contentElement.style.transition = `opacity ${this.tabOptions.animationDuration}ms ease`;
      
      // 触发重排
      contentElement.offsetHeight;
      
      contentElement.style.opacity = '1';
    }

    // 发送内容显示事件
    this.emit('tab:content:show', { contentElement });
  }

  /**
   * 隐藏标签内容
   */
  private hideTabContent(contentElement: HTMLElement): void {
    removeClass(contentElement, this.tabOptions.activeTabClass!);
    
    if (this.tabOptions.animationDuration! > 0) {
      contentElement.style.transition = `opacity ${this.tabOptions.animationDuration}ms ease`;
      contentElement.style.opacity = '0';
      
      setTimeout(() => {
        addClass(contentElement, 'hidden');
        contentElement.style.transition = '';
        contentElement.style.opacity = '';
      }, this.tabOptions.animationDuration);
    } else {
      addClass(contentElement, 'hidden');
    }

    // 发送内容隐藏事件
    this.emit('tab:content:hide', { contentElement });
  }

  /**
   * 更新标签状态
   */
  private updateTabStates(): void {
    this.tabs.forEach((tab, tabId) => {
      const isActive = tabId === this.activeTabId;
      
      // 更新标签样式
      if (isActive) {
        addClass(tab.element, this.tabOptions.activeTabClass!);
        tab.element.setAttribute('aria-selected', 'true');
        tab.element.setAttribute('tabindex', '0');
      } else {
        removeClass(tab.element, this.tabOptions.activeTabClass!);
        tab.element.setAttribute('aria-selected', 'false');
        tab.element.setAttribute('tabindex', '-1');
      }

      // 更新禁用状态
      if (tab.disabled) {
        addClass(tab.element, 'disabled');
        tab.element.setAttribute('aria-disabled', 'true');
      } else {
        removeClass(tab.element, 'disabled');
        tab.element.removeAttribute('aria-disabled');
      }
    });
  }

  /**
   * 恢复活动标签
   */
  private restoreActiveTab(): void {
    let activeTabId = this.tabOptions.defaultTab;

    // 从存储中恢复
    if (this.tabOptions.saveActiveTab && this.tabOptions.storageKey) {
      const savedTabId = localStorage.get<string>(this.tabOptions.storageKey);
      if (savedTabId && this.tabs.has(savedTabId)) {
        activeTabId = savedTabId;
      }
    }

    // 从URL参数中获取
    const urlParams = new URLSearchParams(window.location.search);
    const urlTabId = urlParams.get('tab');
    if (urlTabId && this.tabs.has(urlTabId)) {
      activeTabId = urlTabId;
    }

    // 如果没有指定活动标签，使用第一个可用标签
    if (!activeTabId) {
      const firstTab = Array.from(this.tabs.values()).find(tab => !tab.disabled);
      activeTabId = firstTab?.id;
    }

    if (activeTabId) {
      this.activateTab(activeTabId);
    }
  }

  /**
   * 保存活动标签
   */
  private saveActiveTab(tabId: string): void {
    if (this.tabOptions.storageKey) {
      localStorage.set(this.tabOptions.storageKey, tabId);
    }
  }

  /**
   * 添加标签
   */
  public addTab(tabInfo: Omit<TabInfo, 'disabled'> & { disabled?: boolean }): void {
    const tab: TabInfo = {
      disabled: false,
      ...tabInfo
    };

    this.tabs.set(tab.id, tab);

    // 设置ARIA属性
    tab.element.setAttribute('role', 'tab');
    tab.element.setAttribute('aria-controls', tab.id);
    tab.element.setAttribute('aria-selected', 'false');
    tab.element.setAttribute('tabindex', '-1');

    if (tab.contentElement) {
      tab.contentElement.setAttribute('role', 'tabpanel');
      tab.contentElement.setAttribute('aria-labelledby', tab.element.id || `tab-${tab.id}`);
      addClass(tab.contentElement, this.tabOptions.tabContentClass!);
    }

    // 绑定事件
    this.addEventListener(tab.element, 'click', (event: Event) => {
      event.preventDefault();
      this.activateTab(tab.id);
    });

    this.updateTabStates();
  }

  /**
   * 移除标签
   */
  public removeTab(tabId: string): void {
    const tab = this.tabs.get(tabId);
    if (!tab) return;

    // 如果是活动标签，切换到其他标签
    if (this.activeTabId === tabId) {
      const remainingTabs = Array.from(this.tabs.keys()).filter(id => id !== tabId);
      if (remainingTabs.length > 0) {
        this.activateTab(remainingTabs[0]);
      } else {
        this.activeTabId = null;
      }
    }

    this.tabs.delete(tabId);
    this.updateTabStates();
  }

  /**
   * 启用标签
   */
  public enableTab(tabId: string): void {
    const tab = this.tabs.get(tabId);
    if (tab) {
      tab.disabled = false;
      this.updateTabStates();
    }
  }

  /**
   * 禁用标签
   */
  public disableTab(tabId: string): void {
    const tab = this.tabs.get(tabId);
    if (tab) {
      tab.disabled = true;
      
      // 如果是活动标签，切换到其他标签
      if (this.activeTabId === tabId) {
        const enabledTabs = Array.from(this.tabs.values()).filter(t => !t.disabled);
        if (enabledTabs.length > 0) {
          this.activateTab(enabledTabs[0].id);
        }
      }
      
      this.updateTabStates();
    }
  }

  /**
   * 获取活动标签ID
   */
  public getActiveTabId(): string | null {
    return this.activeTabId;
  }

  /**
   * 获取标签信息
   */
  public getTabInfo(tabId: string): TabInfo | undefined {
    return this.tabs.get(tabId);
  }

  /**
   * 获取所有标签
   */
  public getAllTabs(): TabInfo[] {
    return Array.from(this.tabs.values());
  }

  /**
   * 检查标签是否存在
   */
  public hasTab(tabId: string): boolean {
    return this.tabs.has(tabId);
  }
}
