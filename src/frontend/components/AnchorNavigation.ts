/**
 * 锚点导航系统 - 现代化TypeScript版本
 *
 * 从原有anchor-navigation.js完全迁移，包括：
 * - 平滑滚动到Notion区块锚点
 * - 固定头部偏移处理
 * - 区块高亮效果
 * - URL状态管理
 */

import { emit } from '../../shared/core/EventBus';

export interface AnchorNavigationConfig {
  headerSelectors: string[];
  smoothScrollSupported: boolean;
  highlightDuration: number;
  scrollOffset: number;
}

export interface ScrollTarget {
  id: string;
  element: HTMLElement;
  rect: DOMRect;
}

/**
 * 锚点导航系统类
 */
export class AnchorNavigation {
  private static instance: AnchorNavigation | null = null;

  private config!: AnchorNavigationConfig;
  private headerOffset = 0;
  private supportsSmoothScroll!: boolean;
  private resizeObserver: ResizeObserver | null = null;

  constructor(config: Partial<AnchorNavigationConfig> = {}) {
    if (AnchorNavigation.instance) {
      return AnchorNavigation.instance;
    }

    AnchorNavigation.instance = this;

    this.config = {
      headerSelectors: [
        'header[style*="position: fixed"]',
        '.fixed-header',
        '.sticky-header',
        '#masthead',
        '.site-header',
      ],
      smoothScrollSupported: 'scrollBehavior' in document.documentElement.style,
      highlightDuration: 2000,
      scrollOffset: 20,
      ...config,
    };

    this.supportsSmoothScroll = this.config.smoothScrollSupported;
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(
    config?: Partial<AnchorNavigationConfig>
  ): AnchorNavigation {
    if (!AnchorNavigation.instance) {
      AnchorNavigation.instance = new AnchorNavigation(config);
    }
    return AnchorNavigation.instance;
  }

  /**
   * 初始化锚点导航系统
   */
  private init(): void {
    this.updateHeaderOffset();
    this.setupEventListeners();
    this.handleInitialHash();

    console.log('🔗 [锚点导航] 已初始化');
    emit('anchor:navigation:initialized');
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 点击事件委托
    document.addEventListener('click', this.handleAnchorClick.bind(this));

    // Hash变化监听
    window.addEventListener(
      'hashchange',
      this.debounce(this.handleHashChange.bind(this), 100)
    );

    // 窗口大小变化监听
    window.addEventListener(
      'resize',
      this.debounce(this.updateHeaderOffset.bind(this), 250)
    );

    // 使用ResizeObserver监听头部元素变化
    if ('ResizeObserver' in window) {
      this.setupHeaderObserver();
    }
  }

  /**
   * 设置头部观察器
   */
  private setupHeaderObserver(): void {
    this.resizeObserver = new ResizeObserver(
      this.debounce(() => {
        this.updateHeaderOffset();
      }, 100)
    );

    // 观察所有可能的头部元素
    this.config.headerSelectors.forEach(selector => {
      const elements = document.querySelectorAll(selector);
      elements.forEach(element => {
        if (element instanceof HTMLElement) {
          this.resizeObserver!.observe(element);
        }
      });
    });
  }

  /**
   * 检测固定头部高度
   */
  private calculateHeaderOffset(): number {
    let maxHeight = 0;

    this.config.headerSelectors.forEach(selector => {
      const element = document.querySelector(selector) as HTMLElement;
      if (element) {
        const style = window.getComputedStyle(element);
        if (style.position === 'fixed' || style.position === 'sticky') {
          maxHeight = Math.max(maxHeight, element.offsetHeight);
        }
      }
    });

    return maxHeight + this.config.scrollOffset;
  }

  /**
   * 更新头部偏移
   */
  updateHeaderOffset(): void {
    const newOffset = this.calculateHeaderOffset();

    if (newOffset !== this.headerOffset) {
      this.headerOffset = newOffset;
      document.documentElement.style.setProperty(
        '--ntw-header-offset',
        `${this.headerOffset}px`
      );

      emit('anchor:header:offset:updated', { offset: this.headerOffset });
      console.log(`🔗 [锚点导航] 头部偏移已更新: ${this.headerOffset}px`);
    }
  }

  /**
   * 平滑滚动到锚点
   */
  scrollToAnchor(targetId: string): boolean {
    if (!targetId?.startsWith('#notion-block-')) {
      return false;
    }

    const cleanId = targetId.replace('#', '');
    const target = document.getElementById(cleanId);

    if (!target) {
      console.warn(`🔗 [锚点导航] 未找到目标元素: ${targetId}`);
      return false;
    }

    const scrollTarget: ScrollTarget = {
      id: cleanId,
      element: target,
      rect: target.getBoundingClientRect(),
    };

    // 执行滚动
    this.performScroll(scrollTarget);

    // 高亮效果
    this.highlightBlock(target);

    // 更新URL
    this.updateURL(targetId);

    emit('anchor:scrolled', scrollTarget);
    console.log(`🔗 [锚点导航] 滚动到: ${targetId}`);

    return true;
  }

  /**
   * 执行滚动操作
   */
  private performScroll(scrollTarget: ScrollTarget): void {
    const { element } = scrollTarget;

    // 首先滚动到元素中心
    const scrollOptions: ScrollIntoViewOptions = {
      block: 'center',
      behavior: this.supportsSmoothScroll ? 'smooth' : 'auto',
    };

    element.scrollIntoView(scrollOptions);

    // 调整头部偏移
    setTimeout(
      () => {
        const rect = element.getBoundingClientRect();
        if (rect.top < this.headerOffset) {
          const offset = rect.top - this.headerOffset;

          if (this.supportsSmoothScroll) {
            window.scrollBy({ top: offset, behavior: 'smooth' });
          } else {
            window.scrollBy(0, offset);
          }
        }
      },
      this.supportsSmoothScroll ? 100 : 0
    );
  }

  /**
   * 高亮区块
   */
  private highlightBlock(element: HTMLElement): void {
    if (!element?.classList) return;

    // 移除现有高亮
    element.classList.remove('notion-block-highlight');

    // 强制重绘
    element.getBoundingClientRect();

    // 添加高亮
    element.classList.add('notion-block-highlight');

    // 监听动画结束事件
    const removeHighlight = () => {
      element.classList.remove('notion-block-highlight');
      element.removeEventListener('animationend', removeHighlight);
    };

    element.addEventListener('animationend', removeHighlight, { once: true });

    // 备用定时器（防止动画事件不触发）
    setTimeout(() => {
      element.classList.remove('notion-block-highlight');
    }, this.config.highlightDuration);

    emit('anchor:block:highlighted', { element, id: element.id });
  }

  /**
   * 更新URL
   */
  private updateURL(targetId: string): void {
    if (window.history && window.history.replaceState) {
      try {
        window.history.replaceState(null, '', targetId);
        emit('anchor:url:updated', { hash: targetId });
      } catch (error) {
        console.warn('🔗 [锚点导航] URL更新失败:', error);
      }
    }
  }

  /**
   * 处理锚点点击
   */
  private handleAnchorClick(event: Event): void {
    const target = event.target as HTMLElement;
    const link = target.closest(
      'a[href^="#notion-block-"]'
    ) as HTMLAnchorElement;

    if (link) {
      event.preventDefault();
      const href = link.getAttribute('href');
      if (href) {
        this.scrollToAnchor(href);
      }
    }
  }

  /**
   * 处理hash变化
   */
  private handleHashChange(): void {
    const hash = window.location.hash;
    if (hash && hash.startsWith('#notion-block-')) {
      this.scrollToAnchor(hash);
    }
  }

  /**
   * 处理初始hash
   */
  private handleInitialHash(): void {
    const hash = window.location.hash;
    if (hash && hash.startsWith('#notion-block-')) {
      // 延迟处理，确保页面完全加载
      setTimeout(() => {
        this.scrollToAnchor(hash);
      }, 500);
    }
  }

  /**
   * 防抖函数
   */
  private debounce<T extends (...args: any[]) => any>(
    func: T,
    wait: number
  ): (...args: Parameters<T>) => void {
    let timeout: NodeJS.Timeout;

    return (...args: Parameters<T>) => {
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(this, args), wait);
    };
  }

  /**
   * 获取当前配置
   */
  getConfig(): AnchorNavigationConfig {
    return { ...this.config };
  }

  /**
   * 更新配置
   */
  updateConfig(newConfig: Partial<AnchorNavigationConfig>): void {
    this.config = { ...this.config, ...newConfig };
    emit('anchor:config:updated', this.config);
  }

  /**
   * 获取头部偏移
   */
  getHeaderOffset(): number {
    return this.headerOffset;
  }

  /**
   * 获取所有可滚动的锚点
   */
  getAllAnchors(): ScrollTarget[] {
    const anchors: ScrollTarget[] = [];
    const elements = document.querySelectorAll('[id^="notion-block-"]');

    elements.forEach(element => {
      if (element instanceof HTMLElement) {
        anchors.push({
          id: element.id,
          element,
          rect: element.getBoundingClientRect(),
        });
      }
    });

    return anchors;
  }

  /**
   * 销毁实例
   */
  destroy(): void {
    // 移除事件监听器
    document.removeEventListener('click', this.handleAnchorClick);
    window.removeEventListener('hashchange', this.handleHashChange);
    window.removeEventListener('resize', this.updateHeaderOffset);

    // 清理ResizeObserver
    if (this.resizeObserver) {
      this.resizeObserver.disconnect();
      this.resizeObserver = null;
    }

    // 清理CSS变量
    document.documentElement.style.removeProperty('--ntw-header-offset');

    AnchorNavigation.instance = null;
    emit('anchor:navigation:destroyed');
    console.log('🔗 [锚点导航] 已销毁');
  }
}

// 导出单例实例
export const anchorNavigation = AnchorNavigation.getInstance();

export default AnchorNavigation;
