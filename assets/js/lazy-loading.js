/**
 * 图片懒加载和渐进式内容加载脚本
 * 
 * 使用 Intersection Observer API 实现图片的延迟加载，并为 Notion 数据库视图提供渐进式加载功能。
 *
 * @since 1.1.1
 * @version 2.0.0-beta.1
 * @package Notion_To_WordPress
 * @author Frank-Loong
 * @license GPL-3.0-or-later
 * @link https://github.com/Frank-Loong/Notion-to-WordPress
 */

(function() {
    'use strict';

    // 懒加载配置
    const LAZY_CONFIG = {
        rootMargin: '50px 0px',
        threshold: 0.1,
        loadingClass: 'notion-lazy-loading',
        loadedClass: 'notion-lazy-loaded',
        errorClass: 'notion-lazy-error'
    };

    // Intersection Observer 支持检测
    const supportsIntersectionObserver = 'IntersectionObserver' in window;

    /**
     * 创建 Intersection Observer
     */
    function createObserver() {
        return new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    loadImage(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, LAZY_CONFIG);
    }

    /**
     * 加载图片
     */
    function loadImage(img) {
        const src = img.dataset.src;
        if (!src) return;

        // 添加加载状态
        img.classList.add(LAZY_CONFIG.loadingClass);

        // 创建新的图片对象来预加载
        const imageLoader = new Image();
        
        imageLoader.onload = function() {
            // 加载成功
            img.src = src;
            img.classList.remove(LAZY_CONFIG.loadingClass);
            img.classList.add(LAZY_CONFIG.loadedClass);
            
            // 触发自定义事件
            img.dispatchEvent(new CustomEvent('lazyLoaded', {
                detail: { src: src }
            }));
        };

        imageLoader.onerror = function() {
            // 加载失败
            img.classList.remove(LAZY_CONFIG.loadingClass);
            img.classList.add(LAZY_CONFIG.errorClass);
            
            // 显示占位符或默认图片
            img.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZjBmMGYwIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPuWbvueJh+WKoOi9veWksei0pTwvdGV4dD48L3N2Zz4=';
            
            // 触发自定义事件
            img.dispatchEvent(new CustomEvent('lazyError', {
                detail: { src: src }
            }));
        };

        imageLoader.src = src;
    }

    /**
     * 降级处理（不支持 Intersection Observer 时）
     */
    function fallbackLoad() {
        const lazyImages = document.querySelectorAll('img[data-src]');
        lazyImages.forEach(img => {
            loadImage(img);
        });
    }

    // 初始化懒加载
    let observer;
    
    function initLazyLoading() {
        if (supportsIntersectionObserver) {
            observer = createObserver();
            
            // 观察所有懒加载图片
            const lazyImages = document.querySelectorAll('img[data-src]');
            lazyImages.forEach(img => {
                observer.observe(img);
            });
            
            console.log('Notion懒加载已启用，观察图片数量:', lazyImages.length);
        } else {
            // 降级处理
            fallbackLoad();
            console.log('Notion懒加载降级模式已启用');
        }
    }

    /**
     * 重新扫描新添加的图片
     */
    function refreshLazyImages() {
        if (!supportsIntersectionObserver) return;
        
        const newLazyImages = document.querySelectorAll('img[data-src]:not(.notion-lazy-observed)');
        newLazyImages.forEach(img => {
            img.classList.add('notion-lazy-observed');
            observer.observe(img);
        });
        
        if (newLazyImages.length > 0) {
            console.log('Notion懒加载新增观察图片:', newLazyImages.length);
        }
    }

    // DOM 加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLazyLoading);
    } else {
        initLazyLoading();
    }

    // 暴露全局方法
    window.NotionLazyLoading = {
        refresh: refreshLazyImages,
        config: LAZY_CONFIG
    };

})();

/**
 * 渐进式加载功能
 */
(function() {
    'use strict';

    window.NotionProgressiveLoader = {
        loadMore: function(button) {
            const container = button.closest('.notion-progressive-loading');
            const recordsData = container.dataset.records;
            const contentContainer = container.querySelector('.notion-progressive-content');
            const loadingText = button.querySelector('.notion-loading-text');
            const loadingSpinner = button.querySelector('.notion-loading-spinner');

            // 显示加载状态
            loadingText.style.display = 'none';
            loadingSpinner.style.display = 'inline';
            button.disabled = true;

            try {
                const data = JSON.parse(atob(recordsData));

                // 模拟API调用延迟（实际项目中这里会是真实的AJAX请求）
                setTimeout(() => {
                    // 渲染剩余记录（这里简化处理，实际应该通过AJAX获取渲染后的HTML）
                    const records = data.records;
                    let html = '';

                    records.forEach(record => {
                        html += this.renderSimpleRecord(record);
                    });

                    contentContainer.innerHTML = html;

                    // 隐藏加载按钮
                    button.parentElement.style.display = 'none';

                    // 刷新懒加载
                    if (window.NotionLazyLoading) {
                        window.NotionLazyLoading.refresh();
                    }

                    console.log('渐进式加载完成，加载记录数:', records.length);
                }, 500);

            } catch (error) {
                console.error('渐进式加载失败:', error);
                loadingText.textContent = '加载失败，请重试';
                loadingText.style.display = 'inline';
                loadingSpinner.style.display = 'none';
                button.disabled = false;
            }
        },

        renderSimpleRecord: function(record) {
            const title = this.extractTitle(record.properties);
            return `<div class="notion-database-record">
                <div class="notion-record-title">${title}</div>
                <div class="notion-record-properties">
                    <div class="notion-record-property">
                        <span class="notion-property-name">ID:</span>
                        <span class="notion-property-value">${record.id.substring(0, 8)}...</span>
                    </div>
                </div>
            </div>`;
        },

        extractTitle: function(properties) {
            for (const property of Object.values(properties)) {
                if (property.type === 'title' && property.title && property.title.length > 0) {
                    return property.title[0].plain_text || '无标题';
                }
            }
            return '无标题';
        }
    };

    /**
     * 外部特色图像处理
     */
    const FeaturedImageHandler = {
        init() {
            this.handleExternalFeaturedImages();
            this.addErrorHandling();
        },

        /**
         * 处理外部特色图像
         */
        handleExternalFeaturedImages() {
            const featuredImages = document.querySelectorAll('.post-thumbnail img[src^="http"], .wp-post-image[src^="http"]');

            featuredImages.forEach(img => {
                // 添加加载状态
                img.classList.add('notion-external-featured');

                // 如果图像已经加载完成
                if (img.complete && img.naturalHeight !== 0) {
                    img.classList.add('loaded');
                } else {
                    // 监听加载事件
                    img.addEventListener('load', () => {
                        img.classList.add('loaded');
                    });

                    // 监听错误事件
                    img.addEventListener('error', () => {
                        this.handleImageError(img);
                    });
                }
            });
        },

        /**
         * 添加错误处理
         */
        addErrorHandling() {
            // 为所有外部图像添加错误处理
            document.addEventListener('error', (e) => {
                if (e.target.tagName === 'IMG' && e.target.src.startsWith('http')) {
                    this.handleImageError(e.target);
                }
            }, true);
        },

        /**
         * 处理图像加载错误
         */
        handleImageError(img) {
            img.classList.add('notion-image-error');

            // 创建错误占位符
            const placeholder = document.createElement('div');
            placeholder.className = 'notion-featured-image-error';
            placeholder.innerHTML = `
                <div class="notion-image-placeholder">
                    <span class="notion-image-icon">🖼️</span>
                    <span class="notion-image-text">特色图像加载失败</span>
                </div>
            `;

            // 替换失败的图像
            if (img.parentNode) {
                img.parentNode.replaceChild(placeholder, img);
            }
        }
    };

    // 初始化外部特色图像处理
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            FeaturedImageHandler.init();
        });
    } else {
        FeaturedImageHandler.init();
    }

})();
