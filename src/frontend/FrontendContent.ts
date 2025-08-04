/**
 * 前端内容渲染系统 - 现代化TypeScript版本
 * 
 * 完全替代原有的前端内容处理JavaScript文件，包括：
 * - 所有前端组件的统一初始化和管理
 * - 全局事件处理和协调
 * - 性能优化和用户体验增强
 * - 向后兼容性支持
 */

import { emit, on } from '../shared/core/EventBus';
import { ready } from '../shared/utils/dom';
import { AnchorNavigation, anchorNavigation } from './components/AnchorNavigation';
import { LazyLoader, lazyLoader } from './components/LazyLoader';
import { ProgressiveLoader, progressiveLoader } from './components/ProgressiveLoader';
import { ResourceOptimizer, resourceOptimizer } from './components/ResourceOptimizer';

export interface FrontendContentConfig {
  enableAnchorNavigation?: boolean;
  enableLazyLoading?: boolean;
  enableProgressiveLoading?: boolean;
  enableResourceOptimization?: boolean;
  enablePerformanceMonitoring?: boolean;
}

/**
 * 前端内容渲染系统主类
 */
export class FrontendContent {
  private static instance: FrontendContent | null = null;
  private initialized = false;
  private config!: FrontendContentConfig;

  // 组件实例
  private anchorNavigation!: AnchorNavigation;
  private lazyLoader!: LazyLoader;
  private progressiveLoader!: ProgressiveLoader;
  private resourceOptimizer!: ResourceOptimizer;

  constructor(config: FrontendContentConfig = {}) {
    if (FrontendContent.instance) {
      return FrontendContent.instance;
    }
    
    FrontendContent.instance = this;
    
    this.config = {
      enableAnchorNavigation: true,
      enableLazyLoading: true,
      enableProgressiveLoading: true,
      enableResourceOptimization: true,
      enablePerformanceMonitoring: true,
      ...config
    };
    
    // 初始化组件实例
    this.anchorNavigation = anchorNavigation;
    this.lazyLoader = lazyLoader;
    this.progressiveLoader = progressiveLoader;
    this.resourceOptimizer = resourceOptimizer;
  }

  /**
   * 获取单例实例
   */
  static getInstance(config?: FrontendContentConfig): FrontendContent {
    if (!FrontendContent.instance) {
      FrontendContent.instance = new FrontendContent(config);
    }
    return FrontendContent.instance;
  }

  /**
   * 初始化前端内容渲染系统
   */
  init(): void {
    if (this.initialized) {
      console.warn('⚠️ [前端内容] 已经初始化，跳过重复初始化');
      return;
    }

    console.log('🚀 [前端内容] 开始初始化...');

    try {
      this.setupGlobalEventHandlers();
      this.setupComponentCoordination();
      this.setupPerformanceMonitoring();
      this.setupCompatibilityLayer();
      this.applyConfiguration();
      
      this.initialized = true;
      
      emit('frontend:content:initialized');
      console.log('✅ [前端内容] 初始化完成');
      
    } catch (error) {
      console.error('❌ [前端内容] 初始化失败:', error);
      throw error;
    }
  }

  /**
   * 设置全局事件处理器
   */
  private setupGlobalEventHandlers(): void {
    // 页面卸载前的清理
    window.addEventListener('beforeunload', () => {
      this.cleanup();
    });

    // 页面可见性变化处理
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        emit('frontend:page:hidden');
      } else {
        emit('frontend:page:visible');
      }
    });

    // DOM变化监听（用于动态内容）
    if ('MutationObserver' in window) {
      const observer = new MutationObserver((mutations) => {
        let hasNewContent = false;
        
        mutations.forEach(mutation => {
          if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
            mutation.addedNodes.forEach(node => {
              if (node.nodeType === Node.ELEMENT_NODE) {
                const element = node as HTMLElement;
                if (element.querySelector && (
                  element.querySelector('img[data-src]') ||
                  element.querySelector('[id^="notion-block-"]') ||
                  element.querySelector('.notion-progressive-loading')
                )) {
                  hasNewContent = true;
                }
              }
            });
          }
        });
        
        if (hasNewContent) {
          this.handleDynamicContent();
        }
      });
      
      observer.observe(document.body, {
        childList: true,
        subtree: true
      });
    }

    console.log('🎯 [全局事件] 已设置');
  }

  /**
   * 设置组件协调
   */
  private setupComponentCoordination(): void {
    // 懒加载完成后刷新锚点导航
    on('lazy:image:loaded', () => {
      this.anchorNavigation.updateHeaderOffset();
    });

    // 渐进式加载完成后刷新懒加载
    on('progressive:load:success', () => {
      this.lazyLoader.refresh();
    });

    // 锚点导航时暂停资源优化
    on('anchor:scrolled', () => {
      // 可以在这里暂停预测性加载等
    });

    console.log('🔗 [组件协调] 已设置');
  }

  /**
   * 设置性能监控
   */
  private setupPerformanceMonitoring(): void {
    if (!this.config.enablePerformanceMonitoring) return;

    // 监控页面加载性能
    window.addEventListener('load', () => {
      setTimeout(() => {
        this.reportPagePerformance();
      }, 1000);
    });

    // 监控组件性能
    on('anchor:scrolled', (_event, data) => {
      this.trackComponentPerformance('anchor_navigation', data);
    });

    on('lazy:image:loaded', (_event, data) => {
      this.trackComponentPerformance('lazy_loading', data);
    });

    on('progressive:load:success', (_event, data) => {
      this.trackComponentPerformance('progressive_loading', data);
    });

    console.log('📊 [性能监控] 已设置');
  }

  /**
   * 设置兼容性层
   */
  private setupCompatibilityLayer(): void {
    // 为了向后兼容，在全局对象上暴露一些功能
    const globalNotionWp = (window as any).notionToWp || {};
    
    // 暴露组件实例
    globalNotionWp.frontend = {
      anchorNavigation: this.anchorNavigation,
      lazyLoader: this.lazyLoader,
      progressiveLoader: this.progressiveLoader,
      resourceOptimizer: this.resourceOptimizer
    };

    // 暴露主实例
    globalNotionWp.frontendContent = this;

    // 暴露常用方法
    globalNotionWp.scrollToAnchor = (targetId: string) => {
      return this.anchorNavigation.scrollToAnchor(targetId);
    };

    globalNotionWp.refreshLazyLoading = () => {
      this.lazyLoader.refresh();
    };

    (window as any).notionToWp = globalNotionWp;

    console.log('🔄 [兼容性层] 已设置');
  }

  /**
   * 应用配置
   */
  private applyConfiguration(): void {
    // 根据配置启用/禁用功能
    if (!this.config.enableAnchorNavigation) {
      // 可以在这里禁用锚点导航
    }

    if (!this.config.enableLazyLoading) {
      // 可以在这里禁用懒加载
    }

    if (!this.config.enableProgressiveLoading) {
      // 可以在这里禁用渐进式加载
    }

    if (!this.config.enableResourceOptimization) {
      // 可以在这里禁用资源优化
    }

    console.log('⚙️ [配置应用] 完成:', this.config);
  }

  /**
   * 处理动态内容
   */
  private handleDynamicContent(): void {
    console.log('🔄 [动态内容] 检测到新内容，刷新组件...');
    
    // 刷新懒加载
    this.lazyLoader.refresh();
    
    // 更新锚点导航
    this.anchorNavigation.updateHeaderOffset();
    
    emit('frontend:dynamic:content:detected');
  }

  /**
   * 报告页面性能
   */
  private reportPagePerformance(): void {
    if (!('performance' in window)) return;

    const navigation = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming;
    if (!navigation) return;

    const metrics = {
      domContentLoaded: navigation.domContentLoadedEventEnd - navigation.domContentLoadedEventStart,
      loadComplete: navigation.loadEventEnd - navigation.loadEventStart,
      firstPaint: 0,
      firstContentfulPaint: 0
    };

    // 获取绘制指标
    const paintEntries = performance.getEntriesByType('paint');
    paintEntries.forEach(entry => {
      if (entry.name === 'first-paint') {
        metrics.firstPaint = entry.startTime;
      } else if (entry.name === 'first-contentful-paint') {
        metrics.firstContentfulPaint = entry.startTime;
      }
    });

    console.log('📊 [页面性能]', metrics);
    emit('frontend:performance:report', metrics);
  }

  /**
   * 追踪组件性能
   */
  private trackComponentPerformance(component: string, data: any): void {
    const timestamp = Date.now();
    
    console.log(`📊 [组件性能] ${component}:`, data);
    emit('frontend:component:performance', { component, data, timestamp });
  }

  /**
   * 获取组件实例
   */
  getAnchorNavigation(): AnchorNavigation {
    return this.anchorNavigation;
  }

  getLazyLoader(): LazyLoader {
    return this.lazyLoader;
  }

  getProgressiveLoader(): ProgressiveLoader {
    return this.progressiveLoader;
  }

  getResourceOptimizer(): ResourceOptimizer {
    return this.resourceOptimizer;
  }

  /**
   * 获取配置
   */
  getConfig(): FrontendContentConfig {
    return { ...this.config };
  }

  /**
   * 更新配置
   */
  updateConfig(newConfig: Partial<FrontendContentConfig>): void {
    this.config = { ...this.config, ...newConfig };
    this.applyConfiguration();
    emit('frontend:config:updated', this.config);
  }

  /**
   * 检查是否已初始化
   */
  isInitialized(): boolean {
    return this.initialized;
  }

  /**
   * 获取系统状态
   */
  getSystemStatus(): {
    initialized: boolean;
    componentsActive: {
      anchorNavigation: boolean;
      lazyLoader: boolean;
      progressiveLoader: boolean;
      resourceOptimizer: boolean;
    };
    config: FrontendContentConfig;
  } {
    return {
      initialized: this.initialized,
      componentsActive: {
        anchorNavigation: true, // AnchorNavigation 总是活跃的
        lazyLoader: this.lazyLoader.isObserverSupported(),
        progressiveLoader: true, // ProgressiveLoader 总是活跃的
        resourceOptimizer: true // ResourceOptimizer 总是活跃的
      },
      config: this.config
    };
  }

  /**
   * 清理资源
   */
  cleanup(): void {
    if (!this.initialized) return;

    console.log('🧹 [前端内容] 开始清理...');

    try {
      this.anchorNavigation.destroy();
      this.lazyLoader.destroy();
      this.progressiveLoader.destroy();
      this.resourceOptimizer.destroy();
      
      this.initialized = false;
      FrontendContent.instance = null;
      
      emit('frontend:content:destroyed');
      console.log('✅ [前端内容] 清理完成');
    } catch (error) {
      console.error('❌ [前端内容] 清理失败:', error);
    }
  }

  /**
   * 销毁实例
   */
  destroy(): void {
    this.cleanup();
  }
}

// 导出单例实例
export const frontendContent = FrontendContent.getInstance();

// 自动初始化
ready(() => {
  frontendContent.init();
});

export default FrontendContent;
