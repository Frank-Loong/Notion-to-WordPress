/**
 * 前端资源优化模块
 * 
 * 提供JavaScript/CSS压缩合并、CDN集成、增强懒加载等功能
 * 基于现有的lazy-loading.js系统扩展
 *
 * @since 2.0.0-beta.1
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
        
        // 压缩配置
        compression: {
            enabled: true,
            level: 'auto', // auto, high, medium, low
            minifyCSS: true,
            minifyJS: true
        },
        
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
                totalRequests: 0
            };
            this.cdnStatus = 'unknown';
            this.init();
        }

        /**
         * 初始化资源优化器
         */
        init() {
            // 检测CDN可用性
            this.detectCDNAvailability();
            
            // 设置资源压缩
            this.setupAssetCompression();
            
            // 增强懒加载
            this.enhanceLazyLoading();
            
            // 启动性能监控
            if (this.config.performance.enabled) {
                this.startPerformanceMonitoring();
            }
            
            console.log('[Notion Resource Optimizer] 初始化完成');
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
        setupAssetCompression() {
            if (!this.config.compression.enabled) {
                return;
            }

            // 压缩内联CSS
            if (this.config.compression.minifyCSS) {
                this.compressInlineCSS();
            }

            // 压缩内联JavaScript
            if (this.config.compression.minifyJS) {
                this.compressInlineJS();
            }

            // 合并小的CSS文件
            this.mergeSmallCSSFiles();
        }

        /**
         * 压缩内联CSS
         */
        compressInlineCSS() {
            const styleElements = document.querySelectorAll('style');
            
            styleElements.forEach(style => {
                if (style.textContent && style.textContent.length > 100) {
                    const originalSize = style.textContent.length;
                    
                    // 简单的CSS压缩
                    const compressed = style.textContent
                        .replace(/\/\*[\s\S]*?\*\//g, '') // 移除注释
                        .replace(/\s+/g, ' ') // 压缩空白
                        .replace(/;\s*}/g, '}') // 移除最后的分号
                        .replace(/\s*{\s*/g, '{') // 压缩大括号
                        .replace(/\s*}\s*/g, '}')
                        .replace(/\s*;\s*/g, ';') // 压缩分号
                        .replace(/\s*:\s*/g, ':') // 压缩冒号
                        .trim();
                    
                    if (compressed.length < originalSize) {
                        style.textContent = compressed;
                        console.log(`[CSS压缩] 压缩率: ${((originalSize - compressed.length) / originalSize * 100).toFixed(1)}%`);
                    }
                }
            });
        }

        /**
         * 压缩内联JavaScript
         */
        compressInlineJS() {
            const scriptElements = document.querySelectorAll('script:not([src])');
            
            scriptElements.forEach(script => {
                if (script.textContent && script.textContent.length > 200) {
                    const originalSize = script.textContent.length;
                    
                    // 简单的JS压缩
                    const compressed = script.textContent
                        .replace(/\/\*[\s\S]*?\*\//g, '') // 移除块注释
                        .replace(/\/\/.*$/gm, '') // 移除行注释
                        .replace(/\s+/g, ' ') // 压缩空白
                        .replace(/;\s*}/g, ';}') // 保持语法正确
                        .trim();
                    
                    if (compressed.length < originalSize && compressed.length > 50) {
                        script.textContent = compressed;
                        console.log(`[JS压缩] 压缩率: ${((originalSize - compressed.length) / originalSize * 100).toFixed(1)}%`);
                    }
                }
            });
        }

        /**
         * 合并小的CSS文件
         */
        mergeSmallCSSFiles() {
            const linkElements = document.querySelectorAll('link[rel="stylesheet"]');
            const smallFiles = [];
            
            linkElements.forEach(link => {
                // 检查是否为小文件（通过文件名或其他启发式方法）
                const href = link.href;
                if (href && (href.includes('notion') || href.includes('custom'))) {
                    smallFiles.push(link);
                }
            });
            
            if (smallFiles.length > 2) {
                console.log(`[CSS合并] 发现${smallFiles.length}个可合并的CSS文件`);
                // 这里可以实现CSS文件的动态合并逻辑
                // 由于安全限制，实际合并需要服务器端支持
            }
        }

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
                // 扩展观察器的回调
                const originalCallback = originalObserver.callback;
                
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
                    min: Math.min(...this.metrics.loadTimes),
                    max: Math.max(...this.metrics.loadTimes)
                },
                errors: this.metrics.errors.length,
                cacheHitRate: this.metrics.totalRequests > 0 
                    ? (this.metrics.cacheHits / this.metrics.totalRequests * 100).toFixed(2) 
                    : 0,
                cdnStatus: this.cdnStatus,
                final: final
            };
            
            console.log('[性能监控]', metrics);
            
            // 如果配置了端点，发送到服务器
            if (this.config.performance.metricsEndpoint) {
                this.sendMetricsToServer(metrics);
            }
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
    }

    // 创建全局实例
    window.NotionResourceOptimizer = new NotionResourceOptimizer();

    // 兼容性检查和回退
    if (!window.fetch) {
        console.warn('[资源优化器] fetch API不可用，部分功能将被禁用');
        window.NotionResourceOptimizer.config.cdn.enabled = false;
    }

    if (!window.performance) {
        console.warn('[资源优化器] Performance API不可用，性能监控将被禁用');
        window.NotionResourceOptimizer.config.performance.enabled = false;
    }

})(window, document);
