/**
 * 前端资源优化模块
 * 
 * 提供JavaScript/CSS压缩合并、CDN集成、增强懒加载等功能
 * 基于现有的lazy-loading.js系统扩展
 *
 * @since 2.0.0-beta.1
 * @version 2.0.0-beta.1
 * @package Notion_To_WordPress
 * @author Frank-Loong
 * @license GPL-3.0-or-later
 * @link https://github.com/Frank-Loong/Notion-to-WordPress
 */

(function(window, document) {
    'use strict';

    // 资源优化配置
    const OPTIMIZER_CONFIG = {
        // CDN配置
        cdn: {
            enabled: false,
            baseUrl: '',
            fallbackEnabled: true,
            timeout: 5000
        },
        
        // 压缩功能已完全移除
        
        // 懒加载增强配置
        lazyLoading: {
            enhanced: true,
            preloadThreshold: 2, // 预加载前N个图片
            retryAttempts: 3,
            retryDelay: 1000
        },
        
        // 性能监控
        performance: {
            enabled: true,
            reportInterval: 30000, // 30秒
            metricsEndpoint: ''
        },

        // 预测性加载配置
        predictiveLoading: {
            enabled: true,
            hoverDelay: 100, // 鼠标悬停延迟
            scrollThreshold: 0.8, // 滚动阈值
            maxPredictions: 5, // 最大预测数量
            confidenceThreshold: 0.7 // 置信度阈值
        },

        // HTTP/2 Server Push模拟
        serverPush: {
            enabled: true,
            criticalResources: ['css', 'js', 'fonts'], // 关键资源类型
            maxPushResources: 10, // 最大推送资源数
            pushDelay: 50 // 推送延迟
        },

        // 智能缓存策略
        smartCache: {
            enabled: true,
            maxCacheSize: 50 * 1024 * 1024, // 50MB
            ttl: 24 * 60 * 60 * 1000, // 24小时
            compressionEnabled: true,
            versionCheck: true
        }
    };

    /**
     * 资源优化器主类
     */
    class NotionResourceOptimizer {
        constructor() {
            this.config = { ...OPTIMIZER_CONFIG };
            this.metrics = {
                loadTimes: [],
                errors: [],
                cacheHits: 0,
                totalRequests: 0,
                predictiveHits: 0,
                predictiveAttempts: 0,
                serverPushHits: 0,
                cacheSize: 0
            };
            this.cdnStatus = 'unknown';
            this.resourceCache = new Map();
            this.predictiveQueue = new Set();
            this.userBehavior = {
                scrollSpeed: 0,
                hoverTargets: [],
                clickPatterns: [],
                lastActivity: Date.now()
            };
            this.intersectionObserver = null;
            this.init();
        }

        /**
         * 初始化资源优化器
         */
        init() {
            // 检测CDN可用性
            this.detectCDNAvailability();

            // 资源压缩功能已完全移除

            // 增强懒加载
            this.enhanceLazyLoading();

            // 初始化智能缓存
            if (this.config.smartCache.enabled) {
                this.initSmartCache();
            }

            // 初始化预测性加载
            if (this.config.predictiveLoading.enabled) {
                this.initPredictiveLoading();
            }

            // 模拟HTTP/2 Server Push
            if (this.config.serverPush.enabled) {
                this.simulateServerPush();
            }

            // 设置Intersection Observer
            this.setupIntersectionObserver();

            // 启动性能监控
            if (this.config.performance.enabled) {
                this.startPerformanceMonitoring();
            }

            console.log('[Notion Resource Optimizer] 初始化完成 - 增强版');
        }

        /**
         * 检测CDN可用性
         */
        detectCDNAvailability() {
            if (!this.config.cdn.enabled || !this.config.cdn.baseUrl) {
                this.cdnStatus = 'disabled';
                return;
            }

            const testUrl = this.config.cdn.baseUrl + '/test.js';
            const startTime = performance.now();
            
            fetch(testUrl, { 
                method: 'HEAD',
                timeout: this.config.cdn.timeout 
            })
            .then(response => {
                const loadTime = performance.now() - startTime;
                if (response.ok && loadTime < this.config.cdn.timeout) {
                    this.cdnStatus = 'available';
                    console.log(`[CDN] 可用，响应时间: ${loadTime.toFixed(2)}ms`);
                } else {
                    this.cdnStatus = 'slow';
                    console.warn(`[CDN] 响应缓慢: ${loadTime.toFixed(2)}ms`);
                }
            })
            .catch(error => {
                this.cdnStatus = 'unavailable';
                console.warn('[CDN] 不可用，使用本地资源:', error.message);
            });
        }

        /**
         * 设置资源压缩
         */
        // 所有压缩和合并功能已完全移除

        /**
         * 增强懒加载功能
         */
        enhanceLazyLoading() {
            if (!this.config.lazyLoading.enhanced) {
                return;
            }

            // 扩展现有的懒加载系统
            if (window.NotionLazyLoading) {
                this.enhanceExistingLazyLoading();
            } else {
                // 如果懒加载系统还未加载，等待加载完成
                document.addEventListener('DOMContentLoaded', () => {
                    if (window.NotionLazyLoading) {
                        this.enhanceExistingLazyLoading();
                    }
                });
            }

            // 添加预加载功能
            this.setupImagePreloading();
            
            // 添加重试机制
            this.setupImageRetry();
        }

        /**
         * 增强现有的懒加载系统
         */
        enhanceExistingLazyLoading() {
            const originalObserver = window.NotionLazyLoading.observer;

            if (originalObserver) {
                // 扩展观察器的回调功能
                // 这里可以添加额外的懒加载逻辑
                console.log('[懒加载增强] 已集成到现有系统');
            }
        }

        /**
         * 设置图片预加载
         */
        setupImagePreloading() {
            const images = document.querySelectorAll('img[data-src]');
            const preloadCount = Math.min(this.config.lazyLoading.preloadThreshold, images.length);
            
            for (let i = 0; i < preloadCount; i++) {
                const img = images[i];
                if (img && img.dataset.src) {
                    this.preloadImage(img.dataset.src);
                }
            }
            
            if (preloadCount > 0) {
                console.log(`[图片预加载] 预加载前${preloadCount}个图片`);
            }
        }

        /**
         * 预加载单个图片
         */
        preloadImage(src) {
            const img = new Image();
            const startTime = performance.now();
            
            img.onload = () => {
                const loadTime = performance.now() - startTime;
                this.metrics.loadTimes.push(loadTime);
                console.log(`[预加载] ${src} 加载完成，耗时: ${loadTime.toFixed(2)}ms`);
            };
            
            img.onerror = () => {
                this.metrics.errors.push({ src, type: 'preload', time: Date.now() });
                console.warn(`[预加载] ${src} 加载失败`);
            };
            
            img.src = src;
        }

        /**
         * 设置图片重试机制
         */
        setupImageRetry() {
            document.addEventListener('error', (event) => {
                if (event.target.tagName === 'IMG') {
                    this.handleImageError(event.target);
                }
            }, true);
        }

        /**
         * 处理图片加载错误
         */
        handleImageError(img) {
            const retryCount = parseInt(img.dataset.retryCount || '0');
            
            if (retryCount < this.config.lazyLoading.retryAttempts) {
                img.dataset.retryCount = (retryCount + 1).toString();
                
                setTimeout(() => {
                    console.log(`[图片重试] 第${retryCount + 1}次重试: ${img.src}`);
                    img.src = img.src + '?retry=' + (retryCount + 1);
                }, this.config.lazyLoading.retryDelay * (retryCount + 1));
            } else {
                console.error(`[图片加载失败] 重试${retryCount}次后仍然失败: ${img.src}`);
                this.metrics.errors.push({ 
                    src: img.src, 
                    type: 'load_failure', 
                    retries: retryCount,
                    time: Date.now() 
                });
            }
        }

        /**
         * 启动性能监控
         */
        startPerformanceMonitoring() {
            setInterval(() => {
                this.reportPerformanceMetrics();
            }, this.config.performance.reportInterval);
            
            // 页面卸载时报告最终指标
            window.addEventListener('beforeunload', () => {
                this.reportPerformanceMetrics(true);
            });
        }

        /**
         * 报告性能指标
         */
        reportPerformanceMetrics(final = false) {
            const metrics = {
                timestamp: Date.now(),
                loadTimes: {
                    count: this.metrics.loadTimes.length,
                    average: this.metrics.loadTimes.length > 0
                        ? this.metrics.loadTimes.reduce((a, b) => a + b, 0) / this.metrics.loadTimes.length
                        : 0,
                    min: this.metrics.loadTimes.length > 0 ? Math.min(...this.metrics.loadTimes) : 0,
                    max: this.metrics.loadTimes.length > 0 ? Math.max(...this.metrics.loadTimes) : 0
                },
                errors: this.metrics.errors.length,
                cache: {
                    hitRate: this.metrics.totalRequests > 0
                        ? (this.metrics.cacheHits / this.metrics.totalRequests * 100).toFixed(2)
                        : 0,
                    size: this.metrics.cacheSize,
                    entries: this.resourceCache.size
                },
                predictive: {
                    hitRate: this.metrics.predictiveAttempts > 0
                        ? (this.metrics.predictiveHits / this.metrics.predictiveAttempts * 100).toFixed(2)
                        : 0,
                    attempts: this.metrics.predictiveAttempts,
                    hits: this.metrics.predictiveHits,
                    queueSize: this.predictiveQueue.size
                },
                serverPush: {
                    hits: this.metrics.serverPushHits
                },
                userBehavior: {
                    scrollSpeed: this.userBehavior.scrollSpeed.toFixed(2),
                    hoverTargets: this.userBehavior.hoverTargets.length,
                    clickPatterns: this.userBehavior.clickPatterns.length,
                    lastActivity: Date.now() - this.userBehavior.lastActivity
                },
                cdnStatus: this.cdnStatus,
                final: final
            };

            console.log('[性能监控 - 增强版]', metrics);

            // 如果配置了端点，发送到服务器
            if (this.config.performance.metricsEndpoint) {
                this.sendMetricsToServer(metrics);
            }

            // 返回指标供外部使用
            return metrics;
        }

        /**
         * 发送指标到服务器
         */
        sendMetricsToServer(metrics) {
            fetch(this.config.performance.metricsEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(metrics)
            }).catch(error => {
                console.warn('[性能监控] 发送指标失败:', error);
            });
        }

        /**
         * 获取当前配置
         */
        getConfig() {
            return { ...this.config };
        }

        /**
         * 更新配置
         */
        updateConfig(newConfig) {
            this.config = { ...this.config, ...newConfig };
            console.log('[配置更新]', this.config);
        }

        /**
         * 获取性能指标
         */
        getMetrics() {
            return { ...this.metrics };
        }

        /**
         * 初始化智能缓存
         */
        initSmartCache() {
            // 检查浏览器支持
            if (!('caches' in window)) {
                console.warn('[智能缓存] Cache API不支持，使用内存缓存');
                return;
            }

            // 清理过期缓存
            this.cleanExpiredCache();

            // 拦截资源请求
            this.interceptResourceRequests();

            console.log('[智能缓存] 初始化完成');
        }

        /**
         * 清理过期缓存
         */
        async cleanExpiredCache() {
            try {
                const cache = await caches.open('notion-resources');
                const requests = await cache.keys();
                const now = Date.now();

                for (const request of requests) {
                    const response = await cache.match(request);
                    if (response) {
                        const cachedTime = response.headers.get('x-cached-time');
                        if (cachedTime && (now - parseInt(cachedTime)) > this.config.smartCache.ttl) {
                            await cache.delete(request);
                            console.log(`[智能缓存] 清理过期资源: ${request.url}`);
                        }
                    }
                }
            } catch (error) {
                console.warn('[智能缓存] 清理失败:', error);
            }
        }

        /**
         * 拦截资源请求
         */
        interceptResourceRequests() {
            // 重写fetch方法
            const originalFetch = window.fetch;
            const self = this;

            window.fetch = async function(input, init) {
                const url = typeof input === 'string' ? input : input.url;

                // 检查是否为可缓存资源
                if (self.isCacheableResource(url)) {
                    const cachedResponse = await self.getCachedResource(url);
                    if (cachedResponse) {
                        self.metrics.cacheHits++;
                        self.metrics.totalRequests++;
                        console.log(`[智能缓存] 缓存命中: ${url}`);
                        return cachedResponse.clone();
                    }
                }

                self.metrics.totalRequests++;
                const response = await originalFetch.call(this, input, init);

                // 缓存响应
                if (response.ok && self.isCacheableResource(url)) {
                    await self.cacheResource(url, response.clone());
                }

                return response;
            };
        }

        /**
         * 检查是否为可缓存资源
         */
        isCacheableResource(url) {
            const cacheableExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.woff', '.woff2'];
            return cacheableExtensions.some(ext => url.toLowerCase().includes(ext));
        }

        /**
         * 获取缓存资源
         */
        async getCachedResource(url) {
            try {
                if ('caches' in window) {
                    const cache = await caches.open('notion-resources');
                    return await cache.match(url);
                } else {
                    // 使用内存缓存
                    return this.resourceCache.get(url);
                }
            } catch (error) {
                console.warn('[智能缓存] 获取缓存失败:', error);
                return null;
            }
        }

        /**
         * 缓存资源
         */
        async cacheResource(url, response) {
            try {
                if ('caches' in window) {
                    const cache = await caches.open('notion-resources');

                    // 添加缓存时间戳
                    const responseWithTimestamp = new Response(response.body, {
                        status: response.status,
                        statusText: response.statusText,
                        headers: {
                            ...response.headers,
                            'x-cached-time': Date.now().toString()
                        }
                    });

                    await cache.put(url, responseWithTimestamp);
                    this.metrics.cacheSize += response.headers.get('content-length') || 0;
                } else {
                    // 使用内存缓存
                    this.resourceCache.set(url, {
                        response: response.clone(),
                        timestamp: Date.now()
                    });
                }

                console.log(`[智能缓存] 资源已缓存: ${url}`);
            } catch (error) {
                console.warn('[智能缓存] 缓存失败:', error);
            }
        }

        /**
         * 初始化预测性加载
         */
        initPredictiveLoading() {
            // 监听用户行为
            this.trackUserBehavior();

            // 设置预测性预加载
            this.setupPredictivePreload();

            console.log('[预测性加载] 初始化完成');
        }

        /**
         * 跟踪用户行为
         */
        trackUserBehavior() {
            let lastScrollTime = Date.now();
            let lastScrollY = window.scrollY;

            // 跟踪滚动行为
            window.addEventListener('scroll', () => {
                const now = Date.now();
                const currentScrollY = window.scrollY;
                const timeDiff = now - lastScrollTime;
                const scrollDiff = Math.abs(currentScrollY - lastScrollY);

                if (timeDiff > 0) {
                    this.userBehavior.scrollSpeed = scrollDiff / timeDiff;
                }

                lastScrollTime = now;
                lastScrollY = currentScrollY;
                this.userBehavior.lastActivity = now;
            }, { passive: true });

            // 跟踪鼠标悬停
            document.addEventListener('mouseover', (event) => {
                if (event.target.tagName === 'A') {
                    this.userBehavior.hoverTargets.push({
                        url: event.target.href,
                        timestamp: Date.now()
                    });

                    // 预测性预加载
                    setTimeout(() => {
                        this.predictivePreload(event.target.href);
                    }, this.config.predictiveLoading.hoverDelay);
                }
                this.userBehavior.lastActivity = Date.now();
            });

            // 跟踪点击模式
            document.addEventListener('click', (event) => {
                if (event.target.tagName === 'A') {
                    this.userBehavior.clickPatterns.push({
                        url: event.target.href,
                        timestamp: Date.now(),
                        scrollPosition: window.scrollY
                    });
                }
                this.userBehavior.lastActivity = Date.now();
            });
        }

        /**
         * 设置预测性预加载
         */
        setupPredictivePreload() {
            // 基于滚动位置预测
            window.addEventListener('scroll', () => {
                const scrollPercent = window.scrollY / (document.documentElement.scrollHeight - window.innerHeight);

                if (scrollPercent > this.config.predictiveLoading.scrollThreshold) {
                    this.predictNextPageResources();
                }
            }, { passive: true });
        }

        /**
         * 预测性预加载
         */
        predictivePreload(url) {
            if (this.predictiveQueue.has(url) || this.predictiveQueue.size >= this.config.predictiveLoading.maxPredictions) {
                return;
            }

            this.predictiveQueue.add(url);
            this.metrics.predictiveAttempts++;

            // 创建预加载链接
            const link = document.createElement('link');
            link.rel = 'prefetch';
            link.href = url;

            link.onload = () => {
                this.metrics.predictiveHits++;
                console.log(`[预测性加载] 预加载成功: ${url}`);
            };

            link.onerror = () => {
                console.warn(`[预测性加载] 预加载失败: ${url}`);
            };

            document.head.appendChild(link);

            // 清理预加载链接
            setTimeout(() => {
                if (link.parentNode) {
                    link.parentNode.removeChild(link);
                }
                this.predictiveQueue.delete(url);
            }, 30000); // 30秒后清理
        }

        /**
         * 预测下一页资源
         */
        predictNextPageResources() {
            const links = document.querySelectorAll('a[href]');
            const candidates = [];

            links.forEach(link => {
                const rect = link.getBoundingClientRect();
                const isVisible = rect.top < window.innerHeight && rect.bottom > 0;

                if (isVisible && link.href && !this.predictiveQueue.has(link.href)) {
                    candidates.push({
                        url: link.href,
                        confidence: this.calculateLinkConfidence(link)
                    });
                }
            });

            // 按置信度排序并预加载
            candidates
                .filter(candidate => candidate.confidence > this.config.predictiveLoading.confidenceThreshold)
                .sort((a, b) => b.confidence - a.confidence)
                .slice(0, this.config.predictiveLoading.maxPredictions)
                .forEach(candidate => {
                    this.predictivePreload(candidate.url);
                });
        }

        /**
         * 计算链接置信度
         */
        calculateLinkConfidence(link) {
            let confidence = 0.5; // 基础置信度

            // 基于位置的置信度
            const rect = link.getBoundingClientRect();
            const viewportCenter = window.innerHeight / 2;
            const distanceFromCenter = Math.abs(rect.top + rect.height / 2 - viewportCenter);
            const positionScore = Math.max(0, 1 - distanceFromCenter / viewportCenter);
            confidence += positionScore * 0.3;

            // 基于文本内容的置信度
            const text = link.textContent.toLowerCase();
            const highValueKeywords = ['next', 'continue', 'more', 'read more', '下一页', '继续', '更多'];
            if (highValueKeywords.some(keyword => text.includes(keyword))) {
                confidence += 0.2;
            }

            // 基于历史点击模式的置信度
            const similarClicks = this.userBehavior.clickPatterns.filter(pattern =>
                pattern.url.includes(link.hostname) || link.href.includes(pattern.url)
            );
            if (similarClicks.length > 0) {
                confidence += Math.min(0.3, similarClicks.length * 0.1);
            }

            return Math.min(1, confidence);
        }

        /**
         * 模拟HTTP/2 Server Push
         */
        simulateServerPush() {
            // 识别关键资源
            const criticalResources = this.identifyCriticalResources();

            // 预加载关键资源
            criticalResources.slice(0, this.config.serverPush.maxPushResources).forEach((resource, index) => {
                setTimeout(() => {
                    this.pushResource(resource);
                }, index * this.config.serverPush.pushDelay);
            });

            console.log(`[Server Push模拟] 推送${Math.min(criticalResources.length, this.config.serverPush.maxPushResources)}个关键资源`);
        }

        /**
         * 识别关键资源
         */
        identifyCriticalResources() {
            const resources = [];

            // CSS文件
            if (this.config.serverPush.criticalResources.includes('css')) {
                document.querySelectorAll('link[rel="stylesheet"]').forEach(link => {
                    if (link.href && !link.href.includes('admin') && !link.href.includes('login')) {
                        resources.push({
                            url: link.href,
                            type: 'css',
                            priority: this.calculateResourcePriority(link.href, 'css')
                        });
                    }
                });
            }

            // JavaScript文件
            if (this.config.serverPush.criticalResources.includes('js')) {
                document.querySelectorAll('script[src]').forEach(script => {
                    if (script.src && !script.src.includes('admin') && !script.src.includes('analytics')) {
                        resources.push({
                            url: script.src,
                            type: 'js',
                            priority: this.calculateResourcePriority(script.src, 'js')
                        });
                    }
                });
            }

            // 字体文件
            if (this.config.serverPush.criticalResources.includes('fonts')) {
                document.querySelectorAll('link[rel="preload"][as="font"]').forEach(link => {
                    resources.push({
                        url: link.href,
                        type: 'font',
                        priority: this.calculateResourcePriority(link.href, 'font')
                    });
                });
            }

            // 按优先级排序
            return resources.sort((a, b) => b.priority - a.priority);
        }

        /**
         * 计算资源优先级
         */
        calculateResourcePriority(url, type) {
            let priority = 0.5;

            // 基于类型的基础优先级
            const typePriorities = {
                'css': 0.9,
                'js': 0.7,
                'font': 0.8,
                'image': 0.3
            };
            priority = typePriorities[type] || 0.5;

            // 基于文件名的优先级调整
            const fileName = url.split('/').pop().toLowerCase();

            if (fileName.includes('critical') || fileName.includes('above-fold')) {
                priority += 0.2;
            }

            if (fileName.includes('notion') || fileName.includes('main') || fileName.includes('app')) {
                priority += 0.1;
            }

            if (fileName.includes('vendor') || fileName.includes('lib')) {
                priority -= 0.1;
            }

            return Math.min(1, Math.max(0, priority));
        }

        /**
         * 推送资源
         */
        pushResource(resource) {
            const link = document.createElement('link');

            // 根据资源类型设置不同的预加载策略
            switch (resource.type) {
                case 'css':
                    link.rel = 'preload';
                    link.as = 'style';
                    break;
                case 'js':
                    link.rel = 'preload';
                    link.as = 'script';
                    break;
                case 'font':
                    link.rel = 'preload';
                    link.as = 'font';
                    link.crossOrigin = 'anonymous';
                    break;
                default:
                    link.rel = 'prefetch';
                    break;
            }

            link.href = resource.url;

            link.onload = () => {
                this.metrics.serverPushHits++;
                console.log(`[Server Push] 推送成功: ${resource.url}`);
            };

            link.onerror = () => {
                console.warn(`[Server Push] 推送失败: ${resource.url}`);
            };

            document.head.appendChild(link);
        }

        /**
         * 设置Intersection Observer
         */
        setupIntersectionObserver() {
            if (!('IntersectionObserver' in window)) {
                console.warn('[Intersection Observer] 不支持，使用回退方案');
                return;
            }

            const options = {
                root: null,
                rootMargin: '50px',
                threshold: [0, 0.25, 0.5, 0.75, 1]
            };

            this.intersectionObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        this.handleElementInView(entry.target, entry.intersectionRatio);
                    }
                });
            }, options);

            // 观察所有图片和链接
            document.querySelectorAll('img, a[href]').forEach(element => {
                this.intersectionObserver.observe(element);
            });

            console.log('[Intersection Observer] 初始化完成');
        }

        /**
         * 处理元素进入视口
         */
        handleElementInView(element, ratio) {
            if (element.tagName === 'IMG' && ratio > 0.1) {
                // 图片进入视口，可以触发相关资源预加载
                this.preloadRelatedResources(element);
            }

            if (element.tagName === 'A' && ratio > 0.5) {
                // 链接大部分可见，预测用户可能点击
                const confidence = this.calculateLinkConfidence(element);
                if (confidence > this.config.predictiveLoading.confidenceThreshold) {
                    this.predictivePreload(element.href);
                }
            }
        }

        /**
         * 预加载相关资源
         */
        preloadRelatedResources(element) {
            // 如果是图片，预加载同一容器中的其他图片
            const container = element.closest('article, section, .content, .post');
            if (container) {
                const relatedImages = container.querySelectorAll('img[data-src]');
                relatedImages.forEach((img, index) => {
                    if (index < 3 && img !== element) { // 最多预加载3个相关图片
                        setTimeout(() => {
                            this.preloadImage(img.dataset.src || img.src);
                        }, index * 100);
                    }
                });
            }
        }

        /**
         * 获取缓存统计
         */
        getCacheStats() {
            return {
                hitRate: this.metrics.totalRequests > 0
                    ? (this.metrics.cacheHits / this.metrics.totalRequests * 100).toFixed(2)
                    : 0,
                size: this.metrics.cacheSize,
                entries: this.resourceCache.size
            };
        }

        /**
         * 获取预测性加载统计
         */
        getPredictiveStats() {
            return {
                hitRate: this.metrics.predictiveAttempts > 0
                    ? (this.metrics.predictiveHits / this.metrics.predictiveAttempts * 100).toFixed(2)
                    : 0,
                attempts: this.metrics.predictiveAttempts,
                hits: this.metrics.predictiveHits,
                queueSize: this.predictiveQueue.size
            };
        }

        /**
         * 清理资源
         */
        cleanup() {
            if (this.intersectionObserver) {
                this.intersectionObserver.disconnect();
            }

            this.resourceCache.clear();
            this.predictiveQueue.clear();

            console.log('[资源优化器] 清理完成');
        }
    }

    // 创建全局实例
    window.NotionResourceOptimizer = new NotionResourceOptimizer();

    // 兼容性检查和回退
    if (!window.fetch) {
        console.warn('[资源优化器] fetch API不可用，部分功能将被禁用');
        window.NotionResourceOptimizer.config.cdn.enabled = false;
        window.NotionResourceOptimizer.config.smartCache.enabled = false;
    }

    if (!window.performance) {
        console.warn('[资源优化器] Performance API不可用，性能监控将被禁用');
        window.NotionResourceOptimizer.config.performance.enabled = false;
    }

    if (!('IntersectionObserver' in window)) {
        console.warn('[资源优化器] Intersection Observer不可用，部分预测功能将被禁用');
        window.NotionResourceOptimizer.config.predictiveLoading.enabled = false;
    }

    // 页面卸载时清理资源
    window.addEventListener('beforeunload', () => {
        if (window.NotionResourceOptimizer) {
            window.NotionResourceOptimizer.cleanup();
        }
    });

    // 暴露调试接口
    if (window.location.search.includes('debug=1')) {
        window.debugResourceOptimizer = {
            getMetrics: () => window.NotionResourceOptimizer.getMetrics(),
            getCacheStats: () => window.NotionResourceOptimizer.getCacheStats(),
            getPredictiveStats: () => window.NotionResourceOptimizer.getPredictiveStats(),
            reportMetrics: () => window.NotionResourceOptimizer.reportPerformanceMetrics(),
            getConfig: () => window.NotionResourceOptimizer.getConfig()
        };
        console.log('[资源优化器] 调试接口已启用，使用 window.debugResourceOptimizer 访问');
    }

})(window, document);
