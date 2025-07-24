/**
 * Notion 区块锚点导航脚本
 *
 * 实现平滑滚动到 Notion 区块锚点，并处理固定头部的偏移
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

    // 缓存常用值
    let headerOffset = 0;
    let supportsSmoothScroll = 'scrollBehavior' in document.documentElement.style;

    /**
     * 检测固定头部高度
     */
    function getHeaderOffset() {
        const selectors = [
            'header[style*="position: fixed"]',
            '.fixed-header',
            '.sticky-header',
            '#masthead',
            '.site-header'
        ];
        
        let maxHeight = 0;
        selectors.forEach(selector => {
            const element = document.querySelector(selector);
            if (element) {
                const style = window.getComputedStyle(element);
                if (style.position === 'fixed' || style.position === 'sticky') {
                    maxHeight = Math.max(maxHeight, element.offsetHeight);
                }
            }
        });
        
        return maxHeight;
    }

    /**
     * 更新头部偏移
     */
    function updateHeaderOffset() {
        headerOffset = getHeaderOffset();
        document.documentElement.style.setProperty('--ntw-header-offset', headerOffset + 'px');
    }

    /**
     * 平滑滚动到锚点
     */
    function scrollToAnchor(targetId) {
        if (!targetId || !targetId.startsWith('#notion-block-')) return;
        
        const cleanId = targetId.replace('#', '');
        const target = document.getElementById(cleanId);
        if (!target) return;

        // 滚动到目标位置
        const scrollOptions = { block: 'center' };
        if (supportsSmoothScroll) {
            scrollOptions.behavior = 'smooth';
        }
        target.scrollIntoView(scrollOptions);

        // 调整头部偏移
        setTimeout(() => {
            const rect = target.getBoundingClientRect();
            if (rect.top < headerOffset) {
                const offset = rect.top - headerOffset;
                if (supportsSmoothScroll) {
                    window.scrollBy({ top: offset, behavior: 'smooth' });
                } else {
                    window.scrollBy(0, offset);
                }
            }
        }, 100);

        // 高亮效果
        highlightBlock(target);
        
        // 更新URL
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, null, targetId);
        }
    }

    /**
     * 高亮区块
     */
    function highlightBlock(element) {
        if (!element || !element.classList) return;
        
        element.classList.remove('notion-block-highlight');
        element.offsetWidth; // 强制重绘
        element.classList.add('notion-block-highlight');
        
        // 使用现有的CSS动画，动画结束后自动移除类
        element.addEventListener('animationend', function removeHighlight() {
            element.classList.remove('notion-block-highlight');
            element.removeEventListener('animationend', removeHighlight);
        }, { once: true });
    }

    /**
     * 防抖函数
     */
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    /**
     * 处理锚点点击
     */
    function handleAnchorClick(event) {
        const link = event.target.closest('a[href^="#notion-block-"]');
        if (link) {
            event.preventDefault();
            scrollToAnchor(link.getAttribute('href'));
        }
    }

    /**
     * 处理hash变化
     */
    function handleHashChange() {
        const hash = window.location.hash;
        if (hash && hash.startsWith('#notion-block-')) {
            scrollToAnchor(hash);
        }
    }

    /**
     * 初始化
     */
    function init() {
        // 更新头部偏移
        updateHeaderOffset();
        
        // 绑定事件
        document.addEventListener('click', handleAnchorClick);
        window.addEventListener('hashchange', debounce(handleHashChange, 100));
        window.addEventListener('resize', debounce(updateHeaderOffset, 250));
        
        // 处理初始hash
        const hash = window.location.hash;
        if (hash && hash.startsWith('#notion-block-')) {
            setTimeout(() => scrollToAnchor(hash), 500);
        }
    }

    // 自动初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // 暴露API
    window.NotionToWordPressAnchor = {
        scrollToAnchor: scrollToAnchor,
        highlightBlock: highlightBlock
    };

})();
