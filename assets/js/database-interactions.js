/**
 * 数据库交互功能 - 精简版本
 * 专注于三个核心视图：画廊、表格、看板
 *
 * @since 1.1.1
 */

(function() {
    'use strict';

    /**
     * 初始化数据库交互功能
     */
    function initDatabaseInteractions() {
        initRecordHover();
        initViewOptimization();
        initLazyLoading();

        console.log('数据库交互功能已初始化（精简版）');
    }

    /**
     * 记录悬停效果
     */
    function initRecordHover() {
        document.addEventListener('mouseenter', function(e) {
            const record = e.target.closest('.notion-database-record');
            if (!record) return;
            record.style.transform = 'translateY(-2px)';
        }, true);

        document.addEventListener('mouseleave', function(e) {
            const record = e.target.closest('.notion-database-record');
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

    // 页面加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDatabaseInteractions);
    } else {
        initDatabaseInteractions();
    }

})();