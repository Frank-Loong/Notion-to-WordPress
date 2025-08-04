/**
 * 懒加载系统 - 现代化TypeScript版本
 * 
 * 从原有lazy-loading.js完全迁移，包括：
 * - Intersection Observer API图片懒加载
 * - 渐进式内容加载
 * - 外部特色图像处理
 * - 错误处理和降级支持
 */

import { emit } from '../../shared/core/EventBus';
import { ready } from '../../shared/utils/dom';

export interface LazyLoadConfig {
  rootMargin: string;
  threshold: number;
  loadingClass: string;
  loadedClass: string;
  errorClass: string;
  observedClass: string;
  retryAttempts: number;
  retryDelay: number;
}

export interface LazyLoadStats {
  totalImages: number;
  loadedImages: number;
  errorImages: number;
  observerSupported: boolean;
  retryAttempts: number;
}

export interface LazyImageElement extends HTMLImageElement {
  _lazyRetryCount?: number;
  _lazyOriginalSrc?: string;
}

/**
 * 懒加载系统类
 */
export class LazyLoader {
  private static instance: LazyLoader | null = null;

  private config!: LazyLoadConfig;
  private observer: IntersectionObserver | null = null;
  private supportsIntersectionObserver!: boolean;
  private loadedImages = new Set<string>();
  private errorImages = new Set<string>();
  private retryQueue = new Map<HTMLImageElement, number>();

  constructor(config: Partial<LazyLoadConfig> = {}) {
    if (LazyLoader.instance) {
      return LazyLoader.instance;
    }
    
    LazyLoader.instance = this;
    
    this.config = {
      rootMargin: '50px 0px',
      threshold: 0.1,
      loadingClass: 'notion-lazy-loading',
      loadedClass: 'notion-lazy-loaded',
      errorClass: 'notion-lazy-error',
      observedClass: 'notion-lazy-observed',
      retryAttempts: 3,
      retryDelay: 1000,
      ...config
    };
    
    this.supportsIntersectionObserver = 'IntersectionObserver' in window;
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(config?: Partial<LazyLoadConfig>): LazyLoader {
    if (!LazyLoader.instance) {
      LazyLoader.instance = new LazyLoader(config);
    }
    return LazyLoader.instance;
  }

  /**
   * 初始化懒加载系统
   */
  private init(): void {
    if (this.supportsIntersectionObserver) {
      this.createObserver();
      this.observeImages();
    } else {
      this.fallbackLoad();
    }
    
    console.log(`🖼️ [懒加载] 已初始化 (${this.supportsIntersectionObserver ? 'Observer模式' : '降级模式'})`);
    emit('lazy:loader:initialized', { observerSupported: this.supportsIntersectionObserver });
  }

  /**
   * 创建Intersection Observer
   */
  private createObserver(): void {
    this.observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const img = entry.target as LazyImageElement;
          this.loadImage(img);
          this.observer!.unobserve(img);
        }
      });
    }, {
      rootMargin: this.config.rootMargin,
      threshold: this.config.threshold
    });
  }

  /**
   * 观察所有懒加载图片
   */
  private observeImages(): void {
    const lazyImages = document.querySelectorAll('img[data-src]:not(.notion-lazy-observed)');
    
    lazyImages.forEach(img => {
      if (img instanceof HTMLImageElement) {
        img.classList.add(this.config.observedClass);
        this.observer!.observe(img);
      }
    });
    
    if (lazyImages.length > 0) {
      console.log(`🖼️ [懒加载] 观察图片数量: ${lazyImages.length}`);
    }
  }

  /**
   * 加载图片
   */
  private async loadImage(img: LazyImageElement): Promise<void> {
    const src = img.dataset.src;
    if (!src) return;

    // 添加加载状态
    img.classList.add(this.config.loadingClass);
    img._lazyOriginalSrc = src;

    try {
      await this.preloadImage(src);
      
      // 加载成功
      img.src = src;
      img.classList.remove(this.config.loadingClass);
      img.classList.add(this.config.loadedClass);
      
      this.loadedImages.add(src);
      
      // 清理数据属性
      delete img.dataset.src;
      
      // 触发自定义事件
      img.dispatchEvent(new CustomEvent('lazyLoaded', {
        detail: { src, element: img }
      }));
      
      emit('lazy:image:loaded', { src, element: img });
      
    } catch (error) {
      await this.handleImageError(img, error as Error);
    }
  }

  /**
   * 预加载图片
   */
  private preloadImage(src: string): Promise<void> {
    return new Promise((resolve, reject) => {
      const imageLoader = new Image();
      
      imageLoader.onload = () => resolve();
      imageLoader.onerror = () => reject(new Error(`图片加载失败: ${src}`));
      
      imageLoader.src = src;
    });
  }

  /**
   * 处理图片加载错误
   */
  private async handleImageError(img: LazyImageElement, error: Error): Promise<void> {
    const src = img._lazyOriginalSrc || img.dataset.src || '';
    const retryCount = img._lazyRetryCount || 0;
    
    if (retryCount < this.config.retryAttempts) {
      // 重试加载
      img._lazyRetryCount = retryCount + 1;
      
      console.warn(`🖼️ [懒加载] 重试加载图片 (${retryCount + 1}/${this.config.retryAttempts}): ${src}`);
      
      setTimeout(() => {
        this.loadImage(img);
      }, this.config.retryDelay * (retryCount + 1));
      
      return;
    }
    
    // 重试次数用完，显示错误状态
    img.classList.remove(this.config.loadingClass);
    img.classList.add(this.config.errorClass);
    
    this.errorImages.add(src);
    
    // 设置错误占位符
    this.setErrorPlaceholder(img);
    
    // 触发自定义事件
    img.dispatchEvent(new CustomEvent('lazyError', {
      detail: { src, error, element: img, retryCount }
    }));
    
    emit('lazy:image:error', { src, error, element: img, retryCount });
    
    console.error(`🖼️ [懒加载] 图片加载失败: ${src}`, error);
  }

  /**
   * 设置错误占位符
   */
  private setErrorPlaceholder(img: HTMLImageElement): void {
    const placeholder = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZjBmMGYwIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPuWbvueJh+WKoOi9veWksei0pTwvdGV4dD48L3N2Zz4=';
    img.src = placeholder;
  }

  /**
   * 降级处理（不支持Intersection Observer时）
   */
  private fallbackLoad(): void {
    const lazyImages = document.querySelectorAll('img[data-src]');
    
    lazyImages.forEach(img => {
      if (img instanceof HTMLImageElement) {
        this.loadImage(img);
      }
    });
    
    console.log(`🖼️ [懒加载] 降级模式加载图片数量: ${lazyImages.length}`);
  }

  /**
   * 刷新懒加载图片
   */
  refresh(): void {
    if (!this.supportsIntersectionObserver) return;
    
    this.observeImages();
    emit('lazy:loader:refreshed');
  }

  /**
   * 预加载指定图片
   */
  async preloadImages(urls: string[]): Promise<void> {
    if (!Array.isArray(urls)) return;

    const promises = urls.map(async (url) => {
      try {
        await this.preloadImage(url);
        console.log(`🖼️ [预加载] ${url} 完成`);
      } catch (error) {
        console.warn(`🖼️ [预加载] ${url} 失败:`, error);
      }
    });

    await Promise.allSettled(promises);
    emit('lazy:preload:completed', { urls, count: urls.length });
  }

  /**
   * 手动触发图片加载
   */
  loadImageManually(img: HTMLImageElement): void {
    if (img.dataset.src) {
      this.loadImage(img);
    }
  }

  /**
   * 获取懒加载统计信息
   */
  getStats(): LazyLoadStats {
    const totalImages = document.querySelectorAll('img[data-src]').length;
    const loadedImages = document.querySelectorAll(`.${this.config.loadedClass}`).length;
    const errorImages = document.querySelectorAll(`.${this.config.errorClass}`).length;
    
    return {
      totalImages,
      loadedImages,
      errorImages,
      observerSupported: this.supportsIntersectionObserver,
      retryAttempts: this.retryQueue.size
    };
  }

  /**
   * 获取配置
   */
  getConfig(): LazyLoadConfig {
    return { ...this.config };
  }

  /**
   * 更新配置
   */
  updateConfig(newConfig: Partial<LazyLoadConfig>): void {
    this.config = { ...this.config, ...newConfig };
    
    // 如果Observer相关配置改变，重新创建Observer
    if (this.observer && (newConfig.rootMargin || newConfig.threshold)) {
      this.observer.disconnect();
      this.createObserver();
      this.observeImages();
    }
    
    emit('lazy:config:updated', this.config);
  }

  /**
   * 获取Observer实例
   */
  getObserver(): IntersectionObserver | null {
    return this.observer;
  }

  /**
   * 检查是否支持Intersection Observer
   */
  isObserverSupported(): boolean {
    return this.supportsIntersectionObserver;
  }

  /**
   * 销毁懒加载系统
   */
  destroy(): void {
    if (this.observer) {
      this.observer.disconnect();
      this.observer = null;
    }
    
    this.loadedImages.clear();
    this.errorImages.clear();
    this.retryQueue.clear();
    
    LazyLoader.instance = null;
    emit('lazy:loader:destroyed');
    console.log('🖼️ [懒加载] 已销毁');
  }
}

// 导出单例实例
export const lazyLoader = LazyLoader.getInstance();

// 自动初始化
ready(() => {
  lazyLoader;
});

export default LazyLoader;
