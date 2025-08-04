/**
 * 代码分割助手
 */

import { lazyLoader } from './LazyLoader';
import { resourcePreloader } from './ResourcePreloader';
import { performanceMonitor } from './PerformanceMonitor';

export interface ChunkInfo {
  name: string;
  path: string;
  size?: number;
  dependencies?: string[];
  priority: 'high' | 'medium' | 'low';
}

export interface LoadOptions {
  timeout?: number;
  retries?: number;
  preload?: boolean;
  cache?: boolean;
}

/**
 * 代码分割助手类
 */
export class CodeSplitter {
  private chunks: Map<string, ChunkInfo> = new Map();
  private loadedChunks: Set<string> = new Set();
  private chunkCache: Map<string, any> = new Map();

  constructor() {
    this.registerBuiltinChunks();
  }

  /**
   * 注册内置代码块
   */
  private registerBuiltinChunks(): void {
    // 注册管理界面的懒加载模块
    this.registerChunk({
      name: 'admin-settings',
      path: () => import('../../admin/modules/Settings'),
      priority: 'medium'
    });

    this.registerChunk({
      name: 'admin-logs',
      path: () => import('../../admin/modules/Logs'),
      priority: 'low'
    });

    // 注册第三方库（如果需要的话）
    // this.registerChunk({
    //   name: 'katex',
    //   path: () => import('katex'),
    //   priority: 'low'
    // });

    console.log('Built-in chunks registered');
  }

  /**
   * 注册代码块
   */
  registerChunk(chunk: {
    name: string;
    path: string | (() => Promise<any>);
    priority?: 'high' | 'medium' | 'low';
    dependencies?: string[];
    size?: number;
  }): void {
    const chunkInfo: ChunkInfo = {
      name: chunk.name,
      path: typeof chunk.path === 'string' ? chunk.path : 'dynamic',
      priority: chunk.priority || 'medium',
      dependencies: chunk.dependencies,
      size: chunk.size
    };

    this.chunks.set(chunk.name, chunkInfo);

    // 注册到懒加载器
    if (typeof chunk.path === 'function') {
      lazyLoader.registerModule(chunk.name, chunk.path);
    }

    console.log(`Code chunk registered: ${chunk.name}`);
  }

  /**
   * 加载代码块
   */
  async loadChunk(name: string, options: LoadOptions = {}): Promise<any> {
    const chunk = this.chunks.get(name);
    if (!chunk) {
      throw new Error(`Chunk ${name} not registered`);
    }

    // 检查缓存
    if (options.cache !== false && this.chunkCache.has(name)) {
      return this.chunkCache.get(name);
    }

    // 检查是否已加载
    if (this.loadedChunks.has(name)) {
      return this.chunkCache.get(name);
    }

    performanceMonitor.startTimer(`chunk_load_${name}`);

    try {
      // 加载依赖
      if (chunk.dependencies) {
        await this.loadDependencies(chunk.dependencies);
      }

      // 预加载相关资源
      if (options.preload) {
        await this.preloadChunkResources(chunk);
      }

      // 加载主模块
      const module = await lazyLoader.loadModule(name);
      
      // 缓存结果
      if (options.cache !== false) {
        this.chunkCache.set(name, module);
      }
      
      this.loadedChunks.add(name);

      const loadTime = performanceMonitor.endTimer(`chunk_load_${name}`, {
        chunk: name,
        priority: chunk.priority
      });

      console.log(`Chunk loaded: ${name} (${loadTime.toFixed(2)}ms)`);
      return module;
    } catch (error) {
      performanceMonitor.endTimer(`chunk_load_${name}`, {
        chunk: name,
        priority: chunk.priority,
        error: 'true'
      });

      console.error(`Failed to load chunk ${name}:`, error);
      throw error;
    }
  }

  /**
   * 加载依赖
   */
  private async loadDependencies(dependencies: string[]): Promise<void> {
    const loadPromises = dependencies.map(dep => 
      this.loadChunk(dep).catch(error => {
        console.error(`Failed to load dependency ${dep}:`, error);
        return null;
      })
    );

    await Promise.all(loadPromises);
  }

  /**
   * 预加载代码块资源
   */
  private async preloadChunkResources(chunk: ChunkInfo): Promise<void> {
    if (chunk.path === 'dynamic') return;

    try {
      await resourcePreloader.preloadScript(chunk.path, {
        priority: chunk.priority === 'high' ? 'high' : 'low'
      });
    } catch (error) {
      console.warn(`Failed to preload chunk resources for ${chunk.name}:`, error);
    }
  }

  /**
   * 批量加载代码块
   */
  async loadChunks(names: string[], options: LoadOptions = {}): Promise<any[]> {
    const loadPromises = names.map(name => 
      this.loadChunk(name, options).catch(error => {
        console.error(`Failed to load chunk ${name}:`, error);
        return null;
      })
    );

    return Promise.all(loadPromises);
  }

  /**
   * 预加载高优先级代码块
   */
  async preloadHighPriorityChunks(): Promise<void> {
    const highPriorityChunks = Array.from(this.chunks.values())
      .filter(chunk => chunk.priority === 'high')
      .map(chunk => chunk.name);

    if (highPriorityChunks.length > 0) {
      console.log('Preloading high priority chunks:', highPriorityChunks);
      await this.loadChunks(highPriorityChunks, { preload: true });
    }
  }

  /**
   * 智能预加载 - 根据用户行为预测需要的代码块
   */
  async smartPreload(): Promise<void> {
    // 根据当前页面预测需要的模块
    const currentPage = this.getCurrentPageType();
    const predictedChunks = this.predictChunksForPage(currentPage);

    if (predictedChunks.length > 0) {
      console.log(`Smart preloading chunks for ${currentPage}:`, predictedChunks);
      
      // 延迟预加载，避免阻塞主线程
      setTimeout(() => {
        this.loadChunks(predictedChunks, { 
          preload: true, 
          cache: true 
        }).catch(console.error);
      }, 1000);
    }
  }

  /**
   * 获取当前页面类型
   */
  private getCurrentPageType(): string {
    const url = window.location.href;
    
    if (url.includes('wp-admin')) {
      if (url.includes('notion-to-wordpress')) {
        return 'admin-plugin';
      }
      return 'admin';
    }
    
    return 'frontend';
  }

  /**
   * 预测页面需要的代码块
   */
  private predictChunksForPage(pageType: string): string[] {
    const predictions: Record<string, string[]> = {
      'admin-plugin': ['admin-settings', 'admin-logs'],
      'admin': [],
      'frontend': ['frontend-comments', 'frontend-search']
    };

    return predictions[pageType] || [];
  }

  /**
   * 按需加载模块
   */
  async loadOnDemand(name: string, trigger: HTMLElement): Promise<any> {
    // 使用Intersection Observer监听触发元素
    lazyLoader.observe(trigger, name);

    // 直接返回加载结果
    return lazyLoader.loadModule(name);
  }

  /**
   * 获取代码块信息
   */
  getChunkInfo(name: string): ChunkInfo | undefined {
    return this.chunks.get(name);
  }

  /**
   * 获取所有代码块信息
   */
  getAllChunks(): ChunkInfo[] {
    return Array.from(this.chunks.values());
  }

  /**
   * 检查代码块是否已加载
   */
  isChunkLoaded(name: string): boolean {
    return this.loadedChunks.has(name);
  }

  /**
   * 获取加载统计
   */
  getLoadStats(): {
    total: number;
    loaded: number;
    cached: number;
    byPriority: Record<string, number>;
  } {
    const chunks = Array.from(this.chunks.values());
    const byPriority: Record<string, number> = {};

    chunks.forEach(chunk => {
      byPriority[chunk.priority] = (byPriority[chunk.priority] || 0) + 1;
    });

    return {
      total: chunks.length,
      loaded: this.loadedChunks.size,
      cached: this.chunkCache.size,
      byPriority
    };
  }

  /**
   * 清理缓存
   */
  clearCache(): void {
    this.chunkCache.clear();
    console.log('Code chunk cache cleared');
  }

  /**
   * 卸载代码块
   */
  unloadChunk(name: string): void {
    this.loadedChunks.delete(name);
    this.chunkCache.delete(name);
    lazyLoader.unregisterModule(name);
    console.log(`Chunk unloaded: ${name}`);
  }

  /**
   * 清理所有资源
   */
  cleanup(): void {
    this.clearCache();
    this.loadedChunks.clear();
    this.chunks.clear();
    console.log('CodeSplitter cleaned up');
  }
}

// 全局代码分割助手实例
export const codeSplitter = new CodeSplitter();
