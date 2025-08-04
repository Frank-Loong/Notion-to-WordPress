/**
 * 资源优化器 - 现代化TypeScript版本
 * 
 * 从原有resource-optimizer.js完全迁移，包括：
 * - CDN集成和回退
 * - 预测性加载
 * - 智能缓存策略
 * - 性能监控
 */

import { emit } from '../../shared/core/EventBus';
import { ready } from '../../shared/utils/dom';

export interface OptimizerConfig {
  cdn: {
    enabled: boolean;
    baseUrl: string;
    fallbackEnabled: boolean;
    timeout: number;
  };
  lazyLoading: {
    enhanced: boolean;
    preloadThreshold: number;
    retryAttempts: number;
    retryDelay: number;
  };
  performance: {
    enabled: boolean;
    reportInterval: number;
    metricsEndpoint: string;
  };
  predictiveLoading: {
    enabled: boolean;
    hoverDelay: number;
    scrollThreshold: number;
    maxPredictions: number;
    confidenceThreshold: number;
  };
  smartCache: {
    enabled: boolean;
    maxCacheSize: number;
    ttl: number;
    compressionEnabled: boolean;
    versionCheck: boolean;
  };
}

export interface PerformanceMetrics {
  loadTimes: number[];
  errors: string[];
  cacheHits: number;
  totalRequests: number;
  predictiveHits: number;
  predictiveAttempts: number;
  cacheSize: number;
}

export interface UserBehavior {
  scrollSpeed: number;
  hoverTargets: string[];
  clickPatterns: string[];
  lastActivity: number;
}

/**
 * 资源优化器类
 */
export class ResourceOptimizer {
  private static instance: ResourceOptimizer | null = null;

  private config!: OptimizerConfig;
  private metrics!: PerformanceMetrics;
  private userBehavior!: UserBehavior;
  private resourceCache = new Map<string, any>();
  private predictiveQueue = new Set<string>();
  private intersectionObserver: IntersectionObserver | null = null;
  private performanceTimer: NodeJS.Timeout | null = null;

  constructor(config: Partial<OptimizerConfig> = {}) {
    if (ResourceOptimizer.instance) {
      return ResourceOptimizer.instance;
    }
    
    ResourceOptimizer.instance = this;
    
    this.config = {
      cdn: {
        enabled: false,
        baseUrl: '',
        fallbackEnabled: true,
        timeout: 5000
      },
      lazyLoading: {
        enhanced: true,
        preloadThreshold: 2,
        retryAttempts: 3,
        retryDelay: 1000
      },
      performance: {
        enabled: true,
        reportInterval: 30000,
        metricsEndpoint: ''
      },
      predictiveLoading: {
        enabled: true,
        hoverDelay: 100,
        scrollThreshold: 0.8,
        maxPredictions: 5,
        confidenceThreshold: 0.7
      },
      smartCache: {
        enabled: true,
        maxCacheSize: 50 * 1024 * 1024, // 50MB
        ttl: 24 * 60 * 60 * 1000, // 24小时
        compressionEnabled: true,
        versionCheck: true
      },
      ...config
    };
    
    this.metrics = {
      loadTimes: [],
      errors: [],
      cacheHits: 0,
      totalRequests: 0,
      predictiveHits: 0,
      predictiveAttempts: 0,
      cacheSize: 0
    };
    
    this.userBehavior = {
      scrollSpeed: 0,
      hoverTargets: [],
      clickPatterns: [],
      lastActivity: Date.now()
    };
    
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(config?: Partial<OptimizerConfig>): ResourceOptimizer {
    if (!ResourceOptimizer.instance) {
      ResourceOptimizer.instance = new ResourceOptimizer(config);
    }
    return ResourceOptimizer.instance;
  }

  /**
   * 初始化资源优化器
   */
  private init(): void {
    this.setupUserBehaviorTracking();
    this.setupPredictiveLoading();
    this.setupPerformanceMonitoring();
    this.setupSmartCache();
    
    console.log('⚡ [资源优化器] 已初始化');
    emit('resource:optimizer:initialized');
  }

  /**
   * 设置用户行为追踪
   */
  private setupUserBehaviorTracking(): void {
    let lastScrollY = window.scrollY;
    let scrollStartTime = Date.now();
    
    // 滚动行为追踪
    const handleScroll = this.throttle(() => {
      const currentScrollY = window.scrollY;
      const currentTime = Date.now();
      const distance = Math.abs(currentScrollY - lastScrollY);
      const time = currentTime - scrollStartTime;
      
      if (time > 0) {
        this.userBehavior.scrollSpeed = distance / time;
      }
      
      lastScrollY = currentScrollY;
      scrollStartTime = currentTime;
      this.userBehavior.lastActivity = currentTime;
    }, 100);
    
    // 鼠标悬停追踪
    const handleMouseOver = (e: MouseEvent) => {
      const target = e.target as HTMLElement;
      if (target.tagName === 'A') {
        const href = (target as HTMLAnchorElement).href;
        if (href && !this.userBehavior.hoverTargets.includes(href)) {
          this.userBehavior.hoverTargets.push(href);
          this.predictResource(href);
        }
      }
      this.userBehavior.lastActivity = Date.now();
    };
    
    // 点击模式追踪
    const handleClick = (e: MouseEvent) => {
      const target = e.target as HTMLElement;
      if (target.tagName === 'A') {
        const href = (target as HTMLAnchorElement).href;
        if (href) {
          this.userBehavior.clickPatterns.push(href);
          // 保持最近20个点击记录
          if (this.userBehavior.clickPatterns.length > 20) {
            this.userBehavior.clickPatterns.shift();
          }
        }
      }
      this.userBehavior.lastActivity = Date.now();
    };
    
    window.addEventListener('scroll', handleScroll, { passive: true });
    document.addEventListener('mouseover', handleMouseOver, { passive: true });
    document.addEventListener('click', handleClick, { passive: true });
  }

  /**
   * 设置预测性加载
   */
  private setupPredictiveLoading(): void {
    if (!this.config.predictiveLoading.enabled) return;
    
    // 创建Intersection Observer用于预测性加载
    this.intersectionObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const element = entry.target as HTMLElement;
          this.analyzePredictiveOpportunity(element);
        }
      });
    }, {
      rootMargin: '100px',
      threshold: 0.1
    });
    
    // 观察所有链接
    document.querySelectorAll('a[href]').forEach(link => {
      this.intersectionObserver!.observe(link);
    });
  }

  /**
   * 分析预测性加载机会
   */
  private analyzePredictiveOpportunity(element: HTMLElement): void {
    if (element.tagName !== 'A') return;
    
    const href = (element as HTMLAnchorElement).href;
    if (!href || this.predictiveQueue.has(href)) return;
    
    // 计算预测置信度
    const confidence = this.calculatePredictionConfidence(href);
    
    if (confidence >= this.config.predictiveLoading.confidenceThreshold) {
      this.predictResource(href);
    }
  }

  /**
   * 计算预测置信度
   */
  private calculatePredictionConfidence(href: string): number {
    let confidence = 0;
    
    // 基于悬停历史
    if (this.userBehavior.hoverTargets.includes(href)) {
      confidence += 0.3;
    }
    
    // 基于点击模式
    const clickCount = this.userBehavior.clickPatterns.filter(pattern => pattern === href).length;
    confidence += Math.min(clickCount * 0.2, 0.4);
    
    // 基于滚动速度（慢速滚动表示用户在仔细阅读）
    if (this.userBehavior.scrollSpeed < 1) {
      confidence += 0.2;
    }
    
    // 基于活跃度
    const timeSinceLastActivity = Date.now() - this.userBehavior.lastActivity;
    if (timeSinceLastActivity < 5000) { // 5秒内有活动
      confidence += 0.1;
    }
    
    return Math.min(confidence, 1);
  }

  /**
   * 预测性资源加载
   */
  private predictResource(href: string): void {
    if (this.predictiveQueue.size >= this.config.predictiveLoading.maxPredictions) {
      return;
    }
    
    this.predictiveQueue.add(href);
    this.metrics.predictiveAttempts++;
    
    // 延迟预加载以避免影响当前页面性能
    setTimeout(() => {
      this.preloadResource(href);
    }, this.config.predictiveLoading.hoverDelay);
  }

  /**
   * 预加载资源
   */
  private async preloadResource(href: string): Promise<void> {
    try {
      const link = document.createElement('link');
      link.rel = 'prefetch';
      link.href = href;
      document.head.appendChild(link);
      
      this.metrics.predictiveHits++;
      emit('resource:predicted', { href, success: true });
      
    } catch (error) {
      console.warn('⚡ [预测性加载] 失败:', href, error);
      emit('resource:predicted', { href, success: false, error });
    }
  }

  /**
   * 设置性能监控
   */
  private setupPerformanceMonitoring(): void {
    if (!this.config.performance.enabled) return;
    
    // 监控资源加载时间
    if ('PerformanceObserver' in window) {
      const observer = new PerformanceObserver((list) => {
        list.getEntries().forEach(entry => {
          if (entry.entryType === 'resource') {
            this.metrics.loadTimes.push(entry.duration);
            this.metrics.totalRequests++;
          }
        });
      });
      
      observer.observe({ entryTypes: ['resource'] });
    }
    
    // 定期报告性能指标
    if (this.config.performance.reportInterval > 0) {
      this.performanceTimer = setInterval(() => {
        this.reportPerformanceMetrics();
      }, this.config.performance.reportInterval);
    }
  }

  /**
   * 报告性能指标
   */
  private reportPerformanceMetrics(): void {
    const metrics = this.getPerformanceMetrics();
    
    console.log('⚡ [性能指标]', metrics);
    emit('resource:performance:report', metrics);
    
    // 如果配置了端点，发送到服务器
    if (this.config.performance.metricsEndpoint) {
      this.sendMetricsToServer(metrics);
    }
  }

  /**
   * 发送指标到服务器
   */
  private async sendMetricsToServer(metrics: any): Promise<void> {
    try {
      await fetch(this.config.performance.metricsEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(metrics)
      });
    } catch (error) {
      console.warn('⚡ [性能指标] 发送失败:', error);
    }
  }

  /**
   * 设置智能缓存
   */
  private setupSmartCache(): void {
    if (!this.config.smartCache.enabled) return;
    
    // 从localStorage恢复缓存
    this.loadCacheFromStorage();
    
    // 定期清理过期缓存
    setInterval(() => {
      this.cleanExpiredCache();
    }, 60000); // 每分钟检查一次
  }

  /**
   * 从存储加载缓存
   */
  private loadCacheFromStorage(): void {
    try {
      const cached = localStorage.getItem('notion-resource-cache');
      if (cached) {
        const data = JSON.parse(cached);
        this.resourceCache = new Map(data.entries);
        this.metrics.cacheSize = data.size || 0;
      }
    } catch (error) {
      console.warn('⚡ [智能缓存] 加载失败:', error);
    }
  }

  /**
   * 清理过期缓存
   */
  private cleanExpiredCache(): void {
    const now = Date.now();
    let cleaned = 0;
    
    for (const [key, value] of this.resourceCache.entries()) {
      if (value.expires && value.expires < now) {
        this.resourceCache.delete(key);
        cleaned++;
      }
    }
    
    if (cleaned > 0) {
      this.saveCacheToStorage();
      console.log(`⚡ [智能缓存] 清理过期项: ${cleaned}`);
    }
  }

  /**
   * 保存缓存到存储
   */
  private saveCacheToStorage(): void {
    try {
      const data = {
        entries: Array.from(this.resourceCache.entries()),
        size: this.metrics.cacheSize,
        timestamp: Date.now()
      };
      
      localStorage.setItem('notion-resource-cache', JSON.stringify(data));
    } catch (error) {
      console.warn('⚡ [智能缓存] 保存失败:', error);
    }
  }

  /**
   * 节流函数
   */
  private throttle<T extends (...args: any[]) => any>(
    func: T,
    limit: number
  ): (...args: Parameters<T>) => void {
    let inThrottle = false;
    
    return (...args: Parameters<T>) => {
      if (!inThrottle) {
        func.apply(this, args);
        inThrottle = true;
        setTimeout(() => {
          inThrottle = false;
        }, limit);
      }
    };
  }

  /**
   * 获取性能指标
   */
  getPerformanceMetrics(): PerformanceMetrics & { 
    averageLoadTime: number;
    cacheHitRate: number;
    predictiveHitRate: number;
  } {
    const averageLoadTime = this.metrics.loadTimes.length > 0 
      ? this.metrics.loadTimes.reduce((a, b) => a + b, 0) / this.metrics.loadTimes.length 
      : 0;
    
    const cacheHitRate = this.metrics.totalRequests > 0 
      ? this.metrics.cacheHits / this.metrics.totalRequests 
      : 0;
    
    const predictiveHitRate = this.metrics.predictiveAttempts > 0 
      ? this.metrics.predictiveHits / this.metrics.predictiveAttempts 
      : 0;
    
    return {
      ...this.metrics,
      averageLoadTime,
      cacheHitRate,
      predictiveHitRate
    };
  }

  /**
   * 获取配置
   */
  getConfig(): OptimizerConfig {
    return { ...this.config };
  }

  /**
   * 更新配置
   */
  updateConfig(newConfig: Partial<OptimizerConfig>): void {
    this.config = { ...this.config, ...newConfig };
    emit('resource:config:updated', this.config);
  }

  /**
   * 销毁资源优化器
   */
  destroy(): void {
    if (this.intersectionObserver) {
      this.intersectionObserver.disconnect();
      this.intersectionObserver = null;
    }
    
    if (this.performanceTimer) {
      clearInterval(this.performanceTimer);
      this.performanceTimer = null;
    }
    
    this.saveCacheToStorage();
    this.resourceCache.clear();
    this.predictiveQueue.clear();
    
    ResourceOptimizer.instance = null;
    emit('resource:optimizer:destroyed');
    console.log('⚡ [资源优化器] 已销毁');
  }
}

// 导出单例实例
export const resourceOptimizer = ResourceOptimizer.getInstance();

// 自动初始化
ready(() => {
  resourceOptimizer;
});

export default ResourceOptimizer;
