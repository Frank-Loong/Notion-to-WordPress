/**
 * 性能监控器
 */

import { eventBus } from './EventBus';

export interface PerformanceMetric {
  name: string;
  value: number;
  timestamp: number;
  type: 'timing' | 'counter' | 'gauge';
  tags?: Record<string, string>;
}

export interface PerformanceReport {
  metrics: PerformanceMetric[];
  summary: {
    totalMetrics: number;
    timeRange: {
      start: number;
      end: number;
    };
    categories: Record<string, number>;
  };
}

/**
 * 性能监控器类
 */
export class PerformanceMonitor {
  private metrics: PerformanceMetric[] = [];
  private observers: Map<string, PerformanceObserver> = new Map();
  private timers: Map<string, number> = new Map();
  private counters: Map<string, number> = new Map();
  private maxMetrics = 1000;
  private reportInterval = 30000; // 30秒
  private reportTimer: NodeJS.Timeout | null = null;

  constructor() {
    this.setupPerformanceObservers();
    this.startPeriodicReporting();
    this.monitorPageLoad();
  }

  /**
   * 设置性能观察器
   */
  private setupPerformanceObservers(): void {
    if (!window.PerformanceObserver) {
      console.warn('PerformanceObserver not supported');
      return;
    }

    // 监控导航时间
    try {
      const navObserver = new PerformanceObserver((list) => {
        list.getEntries().forEach(entry => {
          if (entry.entryType === 'navigation') {
            const navEntry = entry as PerformanceNavigationTiming;
            this.recordNavigationMetrics(navEntry);
          }
        });
      });
      navObserver.observe({ entryTypes: ['navigation'] });
      this.observers.set('navigation', navObserver);
    } catch (error) {
      console.warn('Failed to setup navigation observer:', error);
    }

    // 监控资源加载
    try {
      const resourceObserver = new PerformanceObserver((list) => {
        list.getEntries().forEach(entry => {
          if (entry.entryType === 'resource') {
            this.recordResourceMetric(entry as PerformanceResourceTiming);
          }
        });
      });
      resourceObserver.observe({ entryTypes: ['resource'] });
      this.observers.set('resource', resourceObserver);
    } catch (error) {
      console.warn('Failed to setup resource observer:', error);
    }

    // 监控长任务
    try {
      const longTaskObserver = new PerformanceObserver((list) => {
        list.getEntries().forEach(entry => {
          if (entry.entryType === 'longtask') {
            this.recordMetric({
              name: 'long_task_duration',
              value: entry.duration,
              timestamp: Date.now(),
              type: 'timing',
              tags: { type: 'longtask' }
            });
          }
        });
      });
      longTaskObserver.observe({ entryTypes: ['longtask'] });
      this.observers.set('longtask', longTaskObserver);
    } catch (error) {
      console.warn('Failed to setup longtask observer:', error);
    }

    // 监控布局偏移
    try {
      const clsObserver = new PerformanceObserver((list) => {
        list.getEntries().forEach(entry => {
          if (entry.entryType === 'layout-shift' && !(entry as any).hadRecentInput) {
            this.recordMetric({
              name: 'cumulative_layout_shift',
              value: (entry as any).value,
              timestamp: Date.now(),
              type: 'gauge',
              tags: { type: 'cls' }
            });
          }
        });
      });
      clsObserver.observe({ entryTypes: ['layout-shift'] });
      this.observers.set('layout-shift', clsObserver);
    } catch (error) {
      console.warn('Failed to setup layout-shift observer:', error);
    }
  }

  /**
   * 记录导航指标
   */
  private recordNavigationMetrics(entry: PerformanceNavigationTiming): void {
    const metrics = [
      { name: 'dns_lookup_time', value: entry.domainLookupEnd - entry.domainLookupStart },
      { name: 'tcp_connect_time', value: entry.connectEnd - entry.connectStart },
      { name: 'request_time', value: entry.responseStart - entry.requestStart },
      { name: 'response_time', value: entry.responseEnd - entry.responseStart },
      { name: 'dom_parse_time', value: entry.domContentLoadedEventStart - entry.responseEnd },
      { name: 'dom_ready_time', value: entry.domContentLoadedEventEnd - entry.fetchStart },
      { name: 'load_complete_time', value: entry.loadEventEnd - entry.fetchStart },
      { name: 'first_paint', value: entry.responseEnd - entry.fetchStart },
    ];

    metrics.forEach(metric => {
      if (metric.value >= 0) {
        this.recordMetric({
          ...metric,
          timestamp: Date.now(),
          type: 'timing',
          tags: { category: 'navigation' }
        });
      }
    });
  }

  /**
   * 记录资源指标
   */
  private recordResourceMetric(entry: PerformanceResourceTiming): void {
    const url = new URL(entry.name);
    const resourceType = this.getResourceType(entry);

    this.recordMetric({
      name: 'resource_load_time',
      value: entry.responseEnd - entry.startTime,
      timestamp: Date.now(),
      type: 'timing',
      tags: {
        category: 'resource',
        type: resourceType,
        domain: url.hostname
      }
    });

    // 记录资源大小
    if (entry.transferSize > 0) {
      this.recordMetric({
        name: 'resource_size',
        value: entry.transferSize,
        timestamp: Date.now(),
        type: 'gauge',
        tags: {
          category: 'resource',
          type: resourceType,
          domain: url.hostname
        }
      });
    }
  }

  /**
   * 获取资源类型
   */
  private getResourceType(entry: PerformanceResourceTiming): string {
    const url = entry.name.toLowerCase();
    
    if (url.includes('.js')) return 'script';
    if (url.includes('.css')) return 'stylesheet';
    if (url.match(/\.(png|jpg|jpeg|gif|webp|svg)$/)) return 'image';
    if (url.match(/\.(woff|woff2|ttf|eot)$/)) return 'font';
    if (entry.initiatorType) return entry.initiatorType;
    
    return 'other';
  }

  /**
   * 监控页面加载
   */
  private monitorPageLoad(): void {
    // 监控First Contentful Paint
    if ('PerformanceObserver' in window) {
      try {
        const paintObserver = new PerformanceObserver((list) => {
          list.getEntries().forEach(entry => {
            if (entry.name === 'first-contentful-paint') {
              this.recordMetric({
                name: 'first_contentful_paint',
                value: entry.startTime,
                timestamp: Date.now(),
                type: 'timing',
                tags: { category: 'paint' }
              });
            }
          });
        });
        paintObserver.observe({ entryTypes: ['paint'] });
        this.observers.set('paint', paintObserver);
      } catch (error) {
        console.warn('Failed to setup paint observer:', error);
      }
    }

    // 监控页面可见性变化
    document.addEventListener('visibilitychange', () => {
      this.recordMetric({
        name: 'page_visibility_change',
        value: document.hidden ? 0 : 1,
        timestamp: Date.now(),
        type: 'counter',
        tags: { 
          category: 'user_interaction',
          state: document.hidden ? 'hidden' : 'visible'
        }
      });
    });
  }

  /**
   * 记录指标
   */
  recordMetric(metric: PerformanceMetric): void {
    this.metrics.push(metric);

    // 限制指标数量
    if (this.metrics.length > this.maxMetrics) {
      this.metrics = this.metrics.slice(-this.maxMetrics);
    }

    // 发送指标事件
    eventBus.emit('performance:metric', metric);
  }

  /**
   * 开始计时
   */
  startTimer(name: string): void {
    this.timers.set(name, performance.now());
  }

  /**
   * 结束计时
   */
  endTimer(name: string, tags?: Record<string, string>): number {
    const startTime = this.timers.get(name);
    if (!startTime) {
      console.warn(`Timer ${name} not found`);
      return 0;
    }

    const duration = performance.now() - startTime;
    this.timers.delete(name);

    this.recordMetric({
      name,
      value: duration,
      timestamp: Date.now(),
      type: 'timing',
      tags
    });

    return duration;
  }

  /**
   * 增加计数器
   */
  incrementCounter(name: string, value = 1, tags?: Record<string, string>): void {
    const currentValue = this.counters.get(name) || 0;
    const newValue = currentValue + value;
    this.counters.set(name, newValue);

    this.recordMetric({
      name,
      value: newValue,
      timestamp: Date.now(),
      type: 'counter',
      tags
    });
  }

  /**
   * 记录内存使用情况
   */
  recordMemoryUsage(): void {
    if ('memory' in performance) {
      const memory = (performance as any).memory;
      
      this.recordMetric({
        name: 'memory_used',
        value: memory.usedJSHeapSize,
        timestamp: Date.now(),
        type: 'gauge',
        tags: { category: 'memory' }
      });

      this.recordMetric({
        name: 'memory_total',
        value: memory.totalJSHeapSize,
        timestamp: Date.now(),
        type: 'gauge',
        tags: { category: 'memory' }
      });

      this.recordMetric({
        name: 'memory_limit',
        value: memory.jsHeapSizeLimit,
        timestamp: Date.now(),
        type: 'gauge',
        tags: { category: 'memory' }
      });
    }
  }

  /**
   * 生成性能报告
   */
  generateReport(): PerformanceReport {
    const now = Date.now();
    const categories: Record<string, number> = {};

    this.metrics.forEach(metric => {
      const category = metric.tags?.category || 'other';
      categories[category] = (categories[category] || 0) + 1;
    });

    return {
      metrics: [...this.metrics],
      summary: {
        totalMetrics: this.metrics.length,
        timeRange: {
          start: this.metrics.length > 0 ? Math.min(...this.metrics.map(m => m.timestamp)) : now,
          end: now
        },
        categories
      }
    };
  }

  /**
   * 开始定期报告
   */
  private startPeriodicReporting(): void {
    this.reportTimer = setInterval(() => {
      const report = this.generateReport();
      eventBus.emit('performance:report', report);
      
      // 记录内存使用情况
      this.recordMemoryUsage();
    }, this.reportInterval);
  }

  /**
   * 停止定期报告
   */
  stopPeriodicReporting(): void {
    if (this.reportTimer) {
      clearInterval(this.reportTimer);
      this.reportTimer = null;
    }
  }

  /**
   * 清理监控器
   */
  cleanup(): void {
    this.stopPeriodicReporting();
    
    this.observers.forEach(observer => {
      observer.disconnect();
    });
    this.observers.clear();

    this.metrics = [];
    this.timers.clear();
    this.counters.clear();

    console.log('PerformanceMonitor cleaned up');
  }

  /**
   * 获取指标统计
   */
  getMetricStats(metricName: string): {
    count: number;
    min: number;
    max: number;
    avg: number;
    latest: number;
  } | null {
    const metrics = this.metrics.filter(m => m.name === metricName);
    
    if (metrics.length === 0) {
      return null;
    }

    const values = metrics.map(m => m.value);
    
    return {
      count: metrics.length,
      min: Math.min(...values),
      max: Math.max(...values),
      avg: values.reduce((sum, val) => sum + val, 0) / values.length,
      latest: values[values.length - 1]
    };
  }
}

// 全局性能监控器实例
export const performanceMonitor = new PerformanceMonitor();
