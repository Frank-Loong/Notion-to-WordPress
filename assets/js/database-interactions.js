/**
 * 数据库交互功能 - 增强版本
 * 专注于三个核心视图：画廊、表格、看板
 * 支持CSS Grid布局、响应式交互和用户体验优化
 *
 * @since 1.1.1
 */

(function() {
    'use strict';

    // 全局配置
    const CONFIG = {
        debounceDelay: 250,
        animationDuration: 150,
        mobileBreakpoint: 768,
        smallMobileBreakpoint: 480,
        maxTableColumns: 6,
        maxBoardHeight: 500,
        intersectionThreshold: 0.1
    };

    // 状态管理
    const state = {
        isInitialized: false,
        observers: new Map(),
        resizeTimeout: null,
        currentViewport: 'desktop'
    };

    /**
     * 防抖函数
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * 初始化数据库交互功能
     */
    function initDatabaseInteractions() {
        if (state.isInitialized) return;

        initViewportDetection();
        initRecordHover();
        initViewOptimization();
        initLazyLoading();
        initResponsiveHandlers();

        state.isInitialized = true;
        console.log('数据库交互功能已初始化（增强版）');
    }

    /**
     * 视口检测和响应式状态管理
     */
    function initViewportDetection() {
        function updateViewportState() {
            const width = window.innerWidth;
            let newViewport;

            if (width <= CONFIG.smallMobileBreakpoint) {
                newViewport = 'small-mobile';
            } else if (width <= CONFIG.mobileBreakpoint) {
                newViewport = 'mobile';
            } else {
                newViewport = 'desktop';
            }

            if (state.currentViewport !== newViewport) {
                const oldViewport = state.currentViewport;
                state.currentViewport = newViewport;

                // 触发视口变化事件
                document.dispatchEvent(new CustomEvent('notionViewportChange', {
                    detail: { oldViewport, newViewport }
                }));

                console.log('视口变化:', oldViewport, '->', newViewport);
            }
        }

        updateViewportState();

        // 监听视口变化
        window.addEventListener('resize', debounce(updateViewportState, CONFIG.debounceDelay));
    }

    /**
     * 记录悬停效果
     */
    function initRecordHover() {
        document.addEventListener('mouseenter', function(e) {
            const record = e.target.closest('.notion-database-record, .notion-board-card');
            if (!record) return;
            record.style.transform = 'translateY(-2px)';
        }, true);

        document.addEventListener('mouseleave', function(e) {
            const record = e.target.closest('.notion-database-record, .notion-board-card');
            if (!record) return;
            record.style.transform = '';
        }, true);
    }

    /**
     * 视图优化
     */
    function initViewOptimization() {
        optimizeGalleryImages();
        optimizeBoardColumns();
        optimizeTableColumns();
    }

    /**
     * 优化画廊视图图片
     */
    function optimizeGalleryImages() {
        const galleryImages = document.querySelectorAll('.notion-database-gallery .notion-record-cover img');
        galleryImages.forEach(img => {
            if (img.complete) {
                img.style.opacity = '1';
            } else {
                img.style.opacity = '0';
                img.addEventListener('load', function() {
                    this.style.transition = 'opacity 0.3s ease';
                    this.style.opacity = '1';
                });
            }
        });
    }

    /**
     * 优化看板列高度
     */
    function optimizeBoardColumns() {
        const boardContainers = document.querySelectorAll('.notion-board-container');
        boardContainers.forEach(container => {
            const columns = container.querySelectorAll('.notion-board-column');
            if (columns.length === 0) return;

            let maxHeight = 0;
            columns.forEach(column => {
                const height = column.scrollHeight;
                if (height > maxHeight) {
                    maxHeight = height;
                }
            });

            const minHeight = Math.min(maxHeight, 600);
            columns.forEach(column => {
                column.style.minHeight = minHeight + 'px';
            });
        });
    }

    /**
     * 优化表格列宽
     */
    function optimizeTableColumns() {
        const tables = document.querySelectorAll('.notion-database-table');
        tables.forEach(table => {
            const headerCells = table.querySelectorAll('.notion-table-header-cell');
            if (headerCells.length === 0) return;

            headerCells.forEach((cell, index) => {
                if (index === 0) {
                    cell.style.flex = '2';
                } else {
                    cell.style.flex = '1';
                }
            });
        });
    }

    /**
     * 懒加载图片
     */
    function initLazyLoading() {
        const lazyImages = document.querySelectorAll('.notion-lazy-image');
        
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('notion-lazy-image');
                        imageObserver.unobserve(img);
                    }
                });
            });

            lazyImages.forEach(img => imageObserver.observe(img));
        } else {
            lazyImages.forEach(img => {
                img.src = img.dataset.src;
                img.classList.remove('notion-lazy-image');
            });
        }
    }

    /**
     * 初始化响应式处理器
     */
    function initResponsiveHandlers() {
        // 监听视口变化事件
        document.addEventListener('notionViewportChange', function(e) {
            const { newViewport } = e.detail;
            console.log('响应式处理器触发:', newViewport);

            // 重新优化所有视图
            optimizeTableColumns();
            optimizeBoardColumns();
            optimizeGalleryImages();
        });

        // 窗口大小变化处理
        window.addEventListener('resize', debounce(() => {
            optimizeTableColumns();
            optimizeBoardColumns();
        }, CONFIG.debounceDelay));

        // 方向变化处理
        window.addEventListener('orientationchange', debounce(() => {
            setTimeout(() => {
                optimizeTableColumns();
                optimizeBoardColumns();
            }, 100); // 等待方向变化完成
        }, CONFIG.debounceDelay));
    }

    // 页面加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDatabaseInteractions);
    } else {
        initDatabaseInteractions();
    }

})();