/**
 * 懒加载管理器
 */

import { eventBus } from './EventBus';

export interface LazyLoadOptions {
  threshold?: number;
  rootMargin?: string;
  timeout?: number;
  retries?: number;
}

export interface LazyModule {
  id: string;
  loader: () => Promise<any>;
  loaded: boolean;
  loading: boolean;
  error?: Error;
  retryCount: number;
}

/**
 * 懒加载管理器类
 */
export class LazyLoader {
  private modules: Map<string, LazyModule> = new Map();
  private intersectionObserver: IntersectionObserver | null = null;
  private loadingPromises: Map<string, Promise<any>> = new Map();
  private defaultOptions: LazyLoadOptions = {
    threshold: 0.1,
    rootMargin: '50px',
    timeout: 10000,
    retries: 3
  };

  constructor(options: LazyLoadOptions = {}) {
    this.defaultOptions = { ...this.defaultOptions, ...options };
    this.setupIntersectionObserver();
  }

  /**
   * 设置交叉观察器
   */
  private setupIntersectionObserver(): void {
    if (!window.IntersectionObserver) {
      console.warn('IntersectionObserver not supported, falling back to immediate loading');
      return;
    }

    this.intersectionObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            const moduleId = entry.target.getAttribute('data-lazy-module');
            if (moduleId) {
              this.loadModule(moduleId);
              this.intersectionObserver?.unobserve(entry.target);
            }
          }
        });
      },
      {
        threshold: this.defaultOptions.threshold,
        rootMargin: this.defaultOptions.rootMargin
      }
    );
  }

  /**
   * 注册懒加载模块
   */
  registerModule(id: string, loader: () => Promise<any>): void {
    if (this.modules.has(id)) {
      console.warn(`Module ${id} already registered`);
      return;
    }

    this.modules.set(id, {
      id,
      loader,
      loaded: false,
      loading: false,
      retryCount: 0
    });

    console.log(`Lazy module registered: ${id}`);
  }

  /**
   * 观察元素
   */
  observe(element: HTMLElement, moduleId: string): void {
    if (!this.intersectionObserver) {
      // 如果不支持IntersectionObserver，立即加载
      this.loadModule(moduleId);
      return;
    }

    element.setAttribute('data-lazy-module', moduleId);
    this.intersectionObserver.observe(element);
  }

  /**
   * 停止观察元素
   */
  unobserve(element: HTMLElement): void {
    if (this.intersectionObserver) {
      this.intersectionObserver.unobserve(element);
    }
  }

  /**
   * 加载模块
   */
  async loadModule(id: string): Promise<any> {
    const module = this.modules.get(id);
    if (!module) {
      throw new Error(`Module ${id} not registered`);
    }

    // 如果已经加载，直接返回
    if (module.loaded) {
      return;
    }

    // 如果正在加载，返回现有的Promise
    if (module.loading && this.loadingPromises.has(id)) {
      return this.loadingPromises.get(id);
    }

    module.loading = true;
    
    const loadingPromise = this.performLoad(module);
    this.loadingPromises.set(id, loadingPromise);

    try {
      const result = await loadingPromise;
      module.loaded = true;
      module.loading = false;
      module.error = undefined;
      
      // 发送加载完成事件
      eventBus.emit('lazy:loaded', { moduleId: id, result });
      
      console.log(`Lazy module loaded: ${id}`);
      return result;
    } catch (error) {
      module.loading = false;
      module.error = error as Error;
      module.retryCount++;
      
      // 发送加载失败事件
      eventBus.emit('lazy:error', { moduleId: id, error, retryCount: module.retryCount });
      
      console.error(`Failed to load lazy module ${id}:`, error);
      throw error;
    } finally {
      this.loadingPromises.delete(id);
    }
  }

  /**
   * 执行加载
   */
  private async performLoad(module: LazyModule): Promise<any> {
    return new Promise((resolve, reject) => {
      const timeout = setTimeout(() => {
        reject(new Error(`Module ${module.id} load timeout`));
      }, this.defaultOptions.timeout);

      module.loader()
        .then(result => {
          clearTimeout(timeout);
          resolve(result);
        })
        .catch(error => {
          clearTimeout(timeout);
          reject(error);
        });
    });
  }

  /**
   * 重试加载模块
   */
  async retryModule(id: string): Promise<any> {
    const module = this.modules.get(id);
    if (!module) {
      throw new Error(`Module ${id} not registered`);
    }

    if (module.retryCount >= this.defaultOptions.retries!) {
      throw new Error(`Module ${id} exceeded max retries`);
    }

    // 重置状态
    module.loaded = false;
    module.loading = false;
    module.error = undefined;

    return this.loadModule(id);
  }

  /**
   * 预加载模块
   */
  async preloadModule(id: string): Promise<any> {
    return this.loadModule(id);
  }

  /**
   * 批量预加载模块
   */
  async preloadModules(ids: string[]): Promise<any[]> {
    const promises = ids.map(id => this.loadModule(id).catch(error => {
      console.error(`Preload failed for module ${id}:`, error);
      return null;
    }));

    return Promise.all(promises);
  }

  /**
   * 获取模块状态
   */
  getModuleStatus(id: string): LazyModule | undefined {
    return this.modules.get(id);
  }

  /**
   * 获取所有模块状态
   */
  getAllModuleStatus(): LazyModule[] {
    return Array.from(this.modules.values());
  }

  /**
   * 检查模块是否已加载
   */
  isModuleLoaded(id: string): boolean {
    const module = this.modules.get(id);
    return module ? module.loaded : false;
  }

  /**
   * 检查模块是否正在加载
   */
  isModuleLoading(id: string): boolean {
    const module = this.modules.get(id);
    return module ? module.loading : false;
  }

  /**
   * 卸载模块
   */
  unregisterModule(id: string): void {
    this.modules.delete(id);
    this.loadingPromises.delete(id);
    console.log(`Lazy module unregistered: ${id}`);
  }

  /**
   * 清理所有模块
   */
  cleanup(): void {
    if (this.intersectionObserver) {
      this.intersectionObserver.disconnect();
      this.intersectionObserver = null;
    }

    this.modules.clear();
    this.loadingPromises.clear();
    
    console.log('LazyLoader cleaned up');
  }

  /**
   * 获取加载统计
   */
  getStats(): {
    total: number;
    loaded: number;
    loading: number;
    failed: number;
  } {
    const modules = Array.from(this.modules.values());
    
    return {
      total: modules.length,
      loaded: modules.filter(m => m.loaded).length,
      loading: modules.filter(m => m.loading).length,
      failed: modules.filter(m => m.error).length
    };
  }
}

// 全局懒加载管理器实例
export const lazyLoader = new LazyLoader();
