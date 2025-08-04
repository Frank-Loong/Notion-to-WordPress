/**
 * 性能优化工具函数
 */

/**
 * 性能监控器
 */
export class PerformanceMonitor {
  private marks = new Map<string, number>();
  private measures = new Map<string, number>();

  /**
   * 标记时间点
   */
  mark(name: string): void {
    this.marks.set(name, performance.now());
    
    // 如果支持Performance API，也使用原生标记
    if (performance.mark) {
      performance.mark(name);
    }
  }

  /**
   * 测量两个时间点之间的耗时
   */
  measure(name: string, startMark?: string, endMark?: string): number {
    const endTime = endMark ? this.marks.get(endMark) : performance.now();
    const startTime = startMark ? this.marks.get(startMark) : 0;
    
    if (startTime === undefined || endTime === undefined) {
      console.warn(`Performance mark not found: ${startMark || endMark}`);
      return 0;
    }
    
    const duration = endTime - startTime;
    this.measures.set(name, duration);
    
    // 如果支持Performance API，也使用原生测量
    if (performance.measure && startMark && endMark) {
      try {
        performance.measure(name, startMark, endMark);
      } catch (error) {
        console.warn('Performance measure failed:', error);
      }
    }
    
    return duration;
  }

  /**
   * 获取测量结果
   */
  getMeasure(name: string): number | undefined {
    return this.measures.get(name);
  }

  /**
   * 获取所有测量结果
   */
  getAllMeasures(): Record<string, number> {
    return Object.fromEntries(this.measures);
  }

  /**
   * 清除标记和测量
   */
  clear(name?: string): void {
    if (name) {
      this.marks.delete(name);
      this.measures.delete(name);
      if (performance.clearMarks) {
        performance.clearMarks(name);
      }
      if (performance.clearMeasures) {
        performance.clearMeasures(name);
      }
    } else {
      this.marks.clear();
      this.measures.clear();
      if (performance.clearMarks) {
        performance.clearMarks();
      }
      if (performance.clearMeasures) {
        performance.clearMeasures();
      }
    }
  }

  /**
   * 获取性能条目
   */
  getEntries(type?: string): PerformanceEntry[] {
    if (!performance.getEntries) return [];
    
    const entries = performance.getEntries();
    return type ? entries.filter(entry => entry.entryType === type) : entries;
  }
}

/**
 * 函数执行时间测量装饰器
 */
export function measureTime(name?: string) {
  return function(target: any, propertyKey: string, descriptor: PropertyDescriptor) {
    const originalMethod = descriptor.value;
    const measureName = name || `${target.constructor.name}.${propertyKey}`;
    
    descriptor.value = function(...args: any[]) {
      const startTime = performance.now();
      const result = originalMethod.apply(this, args);
      const endTime = performance.now();
      
      console.log(`${measureName} executed in ${endTime - startTime}ms`);
      
      return result;
    };
    
    return descriptor;
  };
}

/**
 * 异步函数执行时间测量装饰器
 */
export function measureAsyncTime(name?: string) {
  return function(target: any, propertyKey: string, descriptor: PropertyDescriptor) {
    const originalMethod = descriptor.value;
    const measureName = name || `${target.constructor.name}.${propertyKey}`;
    
    descriptor.value = async function(...args: any[]) {
      const startTime = performance.now();
      const result = await originalMethod.apply(this, args);
      const endTime = performance.now();
      
      console.log(`${measureName} executed in ${endTime - startTime}ms`);
      
      return result;
    };
    
    return descriptor;
  };
}

/**
 * 内存使用监控
 */
export class MemoryMonitor {
  /**
   * 获取内存使用信息
   */
  getMemoryInfo(): any {
    if ('memory' in performance) {
      return (performance as any).memory;
    }
    return null;
  }

  /**
   * 记录内存使用情况
   */
  logMemoryUsage(label = 'Memory Usage'): void {
    const memInfo = this.getMemoryInfo();
    if (memInfo) {
      console.log(`${label}:`, {
        used: `${(memInfo.usedJSHeapSize / 1024 / 1024).toFixed(2)} MB`,
        total: `${(memInfo.totalJSHeapSize / 1024 / 1024).toFixed(2)} MB`,
        limit: `${(memInfo.jsHeapSizeLimit / 1024 / 1024).toFixed(2)} MB`
      });
    }
  }

  /**
   * 监控内存泄漏
   */
  startMemoryLeakDetection(interval = 5000): () => void {
    let previousUsed = 0;
    let increasingCount = 0;
    
    const intervalId = setInterval(() => {
      const memInfo = this.getMemoryInfo();
      if (memInfo) {
        const currentUsed = memInfo.usedJSHeapSize;
        
        if (currentUsed > previousUsed) {
          increasingCount++;
          if (increasingCount > 5) {
            console.warn('Potential memory leak detected - memory usage continuously increasing');
          }
        } else {
          increasingCount = 0;
        }
        
        previousUsed = currentUsed;
      }
    }, interval);
    
    return () => clearInterval(intervalId);
  }
}

/**
 * 资源加载监控
 */
export class ResourceMonitor {
  /**
   * 监控资源加载性能
   */
  getResourceTimings(): PerformanceResourceTiming[] {
    if (!performance.getEntriesByType) return [];
    return performance.getEntriesByType('resource') as PerformanceResourceTiming[];
  }

  /**
   * 获取慢加载资源
   */
  getSlowResources(threshold = 1000): PerformanceResourceTiming[] {
    return this.getResourceTimings().filter(resource => resource.duration > threshold);
  }

  /**
   * 获取资源加载统计
   */
  getResourceStats(): {
    total: number;
    slow: number;
    failed: number;
    averageDuration: number;
  } {
    const resources = this.getResourceTimings();
    const slowResources = this.getSlowResources();
    const failedResources = resources.filter(r => r.transferSize === 0);
    const totalDuration = resources.reduce((sum, r) => sum + r.duration, 0);
    
    return {
      total: resources.length,
      slow: slowResources.length,
      failed: failedResources.length,
      averageDuration: resources.length > 0 ? totalDuration / resources.length : 0
    };
  }
}

/**
 * FPS监控器
 */
export class FPSMonitor {
  private fps = 0;
  private lastTime = 0;
  private frameCount = 0;
  private isRunning = false;
  private callbacks: ((fps: number) => void)[] = [];

  /**
   * 开始FPS监控
   */
  start(): void {
    if (this.isRunning) return;
    
    this.isRunning = true;
    this.lastTime = performance.now();
    this.frameCount = 0;
    this.tick();
  }

  /**
   * 停止FPS监控
   */
  stop(): void {
    this.isRunning = false;
  }

  /**
   * 获取当前FPS
   */
  getFPS(): number {
    return this.fps;
  }

  /**
   * 添加FPS变化回调
   */
  onFPSChange(callback: (fps: number) => void): void {
    this.callbacks.push(callback);
  }

  private tick = (): void => {
    if (!this.isRunning) return;
    
    const currentTime = performance.now();
    this.frameCount++;
    
    if (currentTime - this.lastTime >= 1000) {
      this.fps = Math.round((this.frameCount * 1000) / (currentTime - this.lastTime));
      this.frameCount = 0;
      this.lastTime = currentTime;
      
      // 通知回调
      this.callbacks.forEach(callback => callback(this.fps));
    }
    
    requestAnimationFrame(this.tick);
  };
}

// 默认实例
export const performanceMonitor = new PerformanceMonitor();
export const memoryMonitor = new MemoryMonitor();
export const resourceMonitor = new ResourceMonitor();
export const fpsMonitor = new FPSMonitor();

/**
 * 简单的性能测试函数
 */
export function benchmark(fn: () => void, iterations = 1000): {
  totalTime: number;
  averageTime: number;
  minTime: number;
  maxTime: number;
} {
  const times: number[] = [];
  
  for (let i = 0; i < iterations; i++) {
    const start = performance.now();
    fn();
    const end = performance.now();
    times.push(end - start);
  }
  
  const totalTime = times.reduce((sum, time) => sum + time, 0);
  const averageTime = totalTime / iterations;
  const minTime = Math.min(...times);
  const maxTime = Math.max(...times);
  
  return {
    totalTime,
    averageTime,
    minTime,
    maxTime
  };
}
