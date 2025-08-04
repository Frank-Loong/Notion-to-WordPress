/**
 * 资源预加载管理器
 */

import { eventBus } from './EventBus';

export interface PreloadResource {
  url: string;
  type: 'script' | 'style' | 'image' | 'font' | 'fetch';
  priority?: 'high' | 'low';
  crossorigin?: 'anonymous' | 'use-credentials';
  integrity?: string;
}

export interface PreloadResult {
  url: string;
  success: boolean;
  loadTime: number;
  error?: Error;
}

/**
 * 资源预加载管理器类
 */
export class ResourcePreloader {
  private loadedResources: Set<string> = new Set();
  private loadingResources: Map<string, Promise<PreloadResult>> = new Map();
  private preloadSupported: boolean;
  private prefetchSupported: boolean;

  constructor() {
    this.preloadSupported = this.checkPreloadSupport();
    this.prefetchSupported = this.checkPrefetchSupport();
    
    console.log('ResourcePreloader initialized:', {
      preloadSupported: this.preloadSupported,
      prefetchSupported: this.prefetchSupported
    });
  }

  /**
   * 检查preload支持
   */
  private checkPreloadSupport(): boolean {
    const link = document.createElement('link');
    return link.relList && link.relList.supports && link.relList.supports('preload');
  }

  /**
   * 检查prefetch支持
   */
  private checkPrefetchSupport(): boolean {
    const link = document.createElement('link');
    return link.relList && link.relList.supports && link.relList.supports('prefetch');
  }

  /**
   * 预加载资源
   */
  async preload(resource: PreloadResource): Promise<PreloadResult> {
    const { url } = resource;

    // 如果已经加载过，直接返回成功
    if (this.loadedResources.has(url)) {
      return {
        url,
        success: true,
        loadTime: 0
      };
    }

    // 如果正在加载，返回现有的Promise
    if (this.loadingResources.has(url)) {
      return this.loadingResources.get(url)!;
    }

    const startTime = performance.now();
    const loadPromise = this.performPreload(resource, startTime);
    this.loadingResources.set(url, loadPromise);

    try {
      const result = await loadPromise;
      
      if (result.success) {
        this.loadedResources.add(url);
      }
      
      eventBus.emit('resource:preloaded', result);
      return result;
    } finally {
      this.loadingResources.delete(url);
    }
  }

  /**
   * 执行预加载
   */
  private async performPreload(resource: PreloadResource, startTime: number): Promise<PreloadResult> {
    const { url, type } = resource;

    try {
      let result: PreloadResult;

      if (this.preloadSupported && (type === 'script' || type === 'style' || type === 'font')) {
        result = await this.preloadWithLink(resource, startTime);
      } else {
        result = await this.preloadWithFetch(resource, startTime);
      }

      return result;
    } catch (error) {
      const loadTime = performance.now() - startTime;
      return {
        url,
        success: false,
        loadTime,
        error: error as Error
      };
    }
  }

  /**
   * 使用link标签预加载
   */
  private preloadWithLink(resource: PreloadResource, startTime: number): Promise<PreloadResult> {
    const { url, type, priority, crossorigin, integrity } = resource;

    return new Promise((resolve) => {
      const link = document.createElement('link');
      link.rel = priority === 'low' && this.prefetchSupported ? 'prefetch' : 'preload';
      link.href = url;

      // 设置as属性
      if (type === 'script') {
        link.as = 'script';
      } else if (type === 'style') {
        link.as = 'style';
      } else if (type === 'font') {
        link.as = 'font';
        link.crossOrigin = crossorigin || 'anonymous';
      }

      if (crossorigin) {
        link.crossOrigin = crossorigin;
      }

      if (integrity) {
        link.integrity = integrity;
      }

      link.onload = () => {
        const loadTime = performance.now() - startTime;
        resolve({
          url,
          success: true,
          loadTime
        });
        document.head.removeChild(link);
      };

      link.onerror = (_error) => {
        const loadTime = performance.now() - startTime;
        resolve({
          url,
          success: false,
          loadTime,
          error: new Error(`Failed to preload ${url}`)
        });
        document.head.removeChild(link);
      };

      document.head.appendChild(link);
    });
  }

  /**
   * 使用fetch预加载
   */
  private async preloadWithFetch(resource: PreloadResource, startTime: number): Promise<PreloadResult> {
    const { url, crossorigin, integrity } = resource;

    try {
      const fetchOptions: RequestInit = {};

      if (crossorigin) {
        fetchOptions.mode = crossorigin === 'anonymous' ? 'cors' : 'same-origin';
      }

      if (integrity) {
        fetchOptions.integrity = integrity;
      }

      const response = await fetch(url, fetchOptions);
      
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      // 读取响应以确保完全加载
      await response.blob();

      const loadTime = performance.now() - startTime;
      return {
        url,
        success: true,
        loadTime
      };
    } catch (error) {
      const loadTime = performance.now() - startTime;
      return {
        url,
        success: false,
        loadTime,
        error: error as Error
      };
    }
  }

  /**
   * 批量预加载资源
   */
  async preloadBatch(resources: PreloadResource[]): Promise<PreloadResult[]> {
    const promises = resources.map(resource => 
      this.preload(resource).catch(error => ({
        url: resource.url,
        success: false,
        loadTime: 0,
        error: error as Error
      }))
    );

    return Promise.all(promises);
  }

  /**
   * 预加载脚本
   */
  async preloadScript(url: string, options: Partial<PreloadResource> = {}): Promise<PreloadResult> {
    return this.preload({
      url,
      type: 'script',
      ...options
    });
  }

  /**
   * 预加载样式
   */
  async preloadStyle(url: string, options: Partial<PreloadResource> = {}): Promise<PreloadResult> {
    return this.preload({
      url,
      type: 'style',
      ...options
    });
  }

  /**
   * 预加载图片
   */
  async preloadImage(url: string, options: Partial<PreloadResource> = {}): Promise<PreloadResult> {
    return this.preload({
      url,
      type: 'image',
      ...options
    });
  }

  /**
   * 预加载字体
   */
  async preloadFont(url: string, options: Partial<PreloadResource> = {}): Promise<PreloadResult> {
    return this.preload({
      url,
      type: 'font',
      crossorigin: 'anonymous',
      ...options
    });
  }

  /**
   * 智能预加载 - 根据连接速度和设备性能调整
   */
  async smartPreload(resources: PreloadResource[]): Promise<PreloadResult[]> {
    const connection = (navigator as any).connection;
    const deviceMemory = (navigator as any).deviceMemory;

    // 根据网络条件过滤资源
    let filteredResources = resources;

    if (connection) {
      const effectiveType = connection.effectiveType;
      
      if (effectiveType === 'slow-2g' || effectiveType === '2g') {
        // 慢速网络只预加载高优先级资源
        filteredResources = resources.filter(r => r.priority === 'high');
      } else if (effectiveType === '3g') {
        // 3G网络预加载前50%的资源
        filteredResources = resources.slice(0, Math.ceil(resources.length * 0.5));
      }
    }

    // 根据设备内存调整并发数
    let concurrency = 4;
    if (deviceMemory && deviceMemory < 4) {
      concurrency = 2;
    } else if (deviceMemory && deviceMemory >= 8) {
      concurrency = 6;
    }

    // 分批预加载
    const results: PreloadResult[] = [];
    for (let i = 0; i < filteredResources.length; i += concurrency) {
      const batch = filteredResources.slice(i, i + concurrency);
      const batchResults = await this.preloadBatch(batch);
      results.push(...batchResults);
    }

    return results;
  }

  /**
   * 预加载关键资源
   */
  async preloadCritical(): Promise<void> {
    const criticalResources: PreloadResource[] = [
      // 关键CSS
      {
        url: '/wp-content/plugins/Notion-to-WordPress/assets/dist/css/admin.css',
        type: 'style',
        priority: 'high'
      },
      // 关键字体
      {
        url: '/wp-includes/css/dashicons.css',
        type: 'style',
        priority: 'high'
      }
    ];

    try {
      await this.preloadBatch(criticalResources);
      console.log('Critical resources preloaded');
    } catch (error) {
      console.error('Failed to preload critical resources:', error);
    }
  }

  /**
   * 检查资源是否已加载
   */
  isResourceLoaded(url: string): boolean {
    return this.loadedResources.has(url);
  }

  /**
   * 检查资源是否正在加载
   */
  isResourceLoading(url: string): boolean {
    return this.loadingResources.has(url);
  }

  /**
   * 获取加载统计
   */
  getStats(): {
    loaded: number;
    loading: number;
    total: number;
  } {
    return {
      loaded: this.loadedResources.size,
      loading: this.loadingResources.size,
      total: this.loadedResources.size + this.loadingResources.size
    };
  }

  /**
   * 清理缓存
   */
  cleanup(): void {
    this.loadedResources.clear();
    this.loadingResources.clear();
    console.log('ResourcePreloader cleaned up');
  }
}

// 全局资源预加载器实例
export const resourcePreloader = new ResourcePreloader();
