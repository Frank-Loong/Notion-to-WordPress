/**
 * Notion 区块锚点导航脚本
 * 实现平滑滚动到 Notion 区块锚点，并处理固定头部的偏移，确保目标区块可见。
 * @since 1.1.1
 * @version 1.8.3-beta.2
 * @package Notion_To_WordPress
 * @author Frank-Loong
 * @license GPL-3.0-or-later
 * @link https://github.com/Frank-Loong/Notion-to-WordPress
 */

(function($) {
'use strict';

// 判断是否有 jQuery 可用
const hasJQuery = typeof $ === 'function' && typeof $.fn !== 'undefined';

// 检测浏览器是否原生支持 smooth scroll
const supportsNativeSmoothScroll = 'scrollBehavior' in document.documentElement.style;

/**
 * 根据固定头部高度设置 CSS 变量，供 scroll-margin-top 使用
 */
function setupHeaderOffsetCss() {
    const offset = detectHeaderOffset();
    document.documentElement.style.setProperty('--ntw-header-offset', offset + 'px');
}

// 在页面加载和窗口尺寸变化时重新计算
window.addEventListener('load', setupHeaderOffsetCss);
window.addEventListener('resize', setupHeaderOffsetCss);
window.addEventListener('orientationchange', setupHeaderOffsetCss);

/* ---------------- 锚点导航核心功能 ---------------- */

/**
 * 平滑滚动到目标区块，并确保其居中显示，兼容头部偏移
 * @param {string} targetId 目标区块的 ID
 */
function smoothScrollToAnchor(targetId) {
    if (!targetId || typeof targetId !== 'string') return;
    const cleanId = targetId.replace(/^#/, '');
    if (!cleanId || cleanId.length < 8) return;
    const target = document.getElementById(cleanId);
    if (!target) return;

    // 使用 scrollIntoView 将目标垂直居中显示
    const scrollOptions = { block: 'center', inline: 'nearest' };
    if (supportsNativeSmoothScroll) scrollOptions.behavior = 'smooth';
    target.scrollIntoView(scrollOptions);

    // 如果目标元素仍被固定头部遮挡，则二次修正
    setTimeout(() => {
        const headerOffset = detectHeaderOffset();
        const rect = target.getBoundingClientRect();
        if (rect.top < headerOffset) {
            const offsetBy = rect.top - headerOffset;
            if (supportsNativeSmoothScroll) {
                window.scrollBy({ top: offsetBy, behavior: 'smooth' });
            } else {
                window.scrollBy(0, offsetBy);
            }
        }
    }, 300);

    // 添加高亮
    highlightBlock(target);

    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, null, '#' + cleanId);
    }
}

/**
 * 滚动使元素垂直居中显示（供外部调用）
 * @param {Element|string} target 目标元素或其 ID
 */
function scrollToCenter(target) {
    if (!target) return;
    const element = typeof target === 'string' ? document.getElementById(target.replace(/^#/, '')) : target;
    if (!element) return;
    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/**
 * 检测页面固定头部的高度偏移
 * @returns {number} 头部偏移高度（像素）
 */
function detectHeaderOffset() {
    const headerSelectors = [
        'header[style*="position: fixed"]',
        'header[style*="position:fixed"]',
        '.fixed-header',
        '.sticky-header',
        '#masthead',
        '.site-header',
        'nav[style*="position: fixed"]',
        'nav[style*="position:fixed"]',
        '.navbar-fixed-top',
        '.fixed-top'
    ];
    let maxOffset = 0;
    headerSelectors.forEach(selector => {
        const elements = document.querySelectorAll(selector);
        elements.forEach(element => {
            const style = window.getComputedStyle(element);
            if (style.position === 'fixed' || style.position === 'sticky') {
                const rect = element.getBoundingClientRect();
                if (rect.top <= 0 && rect.bottom > 0) {
                    maxOffset = Math.max(maxOffset, rect.height);
                }
            }
        });
    });
    return maxOffset > 0 ? maxOffset : 0;
}

/**
 * 为目标区块添加高亮动画效果
 * @param {Element} element 目标元素
 * @param {number} delay 延迟开始高亮的时间（毫秒），默认为0
 */
function highlightBlock(element, delay = 0) {
    if (!element || !element.classList) return;
    setTimeout(() => {
        element.classList.remove('notion-block-highlight');
        void element.offsetWidth; // 强制 reflow 重触发动画
        element.classList.add('notion-block-highlight');
        const removeHandler = () => {
            element.classList.remove('notion-block-highlight');
            element.removeEventListener('animationend', removeHandler);
        };
        element.addEventListener('animationend', removeHandler, { once: true });
    }, delay);
}

/**
 * 防抖函数，避免频繁触发
 * @param {Function} func 要防抖的函数
 * @param {number} wait 等待时间（毫秒）
 * @returns {Function} 防抖后的函数
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
 * 检查是否为 Notion 区块锚点
 * @param {string} href 链接地址
 * @returns {boolean} 是否为 Notion 区块锚点
 */
function isNotionBlockAnchor(href) {
    return href && href.startsWith('#notion-block-');
}

/**
 * 处理锚点链接点击事件
 * @param {Event} event 点击事件
 */
function handleAnchorClick(event) {
    const link = event.currentTarget;
    const href = link.getAttribute('href');
    
    if (isNotionBlockAnchor(href)) {
        event.preventDefault();
        smoothScrollToAnchor(href);
    }
}

/**
 * 处理 URL hash 变化
 */
const handleHashChange = debounce(() => {
    const hash = window.location.hash;
    if (isNotionBlockAnchor(hash)) {
        smoothScrollToAnchor(hash);
    }
}, 100);

/**
 * 初始化锚点导航功能
 */
function initAnchorNavigation() {
    console.log('🚀 [Notion to WordPress] 初始化锚点导航功能');
    
    // 监听所有锚点链接的点击事件
    if (hasJQuery) {
        $(document).on('click', 'a[href^="#notion-block-"]', handleAnchorClick);
    } else {
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href^="#notion-block-"]');
            if (link) {
                handleAnchorClick.call(link, e);
            }
        });
    }
    
    // 监听 URL hash 变化
    if (hasJQuery) {
        $(window).on('hashchange', handleHashChange);
    } else {
        window.addEventListener('hashchange', handleHashChange);
    }
    
    // 页面加载时检查 URL hash
    const onReady = () => {
        const hash = window.location.hash;
        if (isNotionBlockAnchor(hash)) {
            setTimeout(() => smoothScrollToAnchor(hash), 500);
        }
    };
    if (hasJQuery) {
        $(onReady);
    } else {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', onReady);
        } else {
            onReady();
        }
    }
    
    console.log('✅ [Notion to WordPress] 锚点导航功能初始化完成');
}

/* ---------------- 主题兼容性处理 ---------------- */

/**
 * 检测主题是否有自定义滚动行为
 */
function detectThemeScrollBehavior() {
    const hasCustomScroll = window.smoothScroll || 
                           window.SmoothScroll || 
                           (hasJQuery && $('body').hasClass('smooth-scroll')) ||
                           document.documentElement.style.scrollBehavior === 'smooth';
    
    if (hasCustomScroll) {
        console.info('🔍 [Notion to WordPress] 检测到主题可能有自定义滚动行为，禁用原生 smooth 行为以避免冲突');
        document.documentElement.style.scrollBehavior = 'auto';
    }
    return hasCustomScroll;
}

/* ---------------- 初始化 ---------------- */

// 页面准备就绪时初始化
if (hasJQuery) {
    $(function() {
        detectThemeScrollBehavior();
        setupHeaderOffsetCss();
        initAnchorNavigation();
    });
} else {
    const bootstrap = () => {
        detectThemeScrollBehavior();
        setupHeaderOffsetCss();
        initAnchorNavigation();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
}

// 暴露核心函数到全局作用域，供调试和扩展使用
window.NotionToWordPressAnchor = {
    smoothScrollToAnchor: smoothScrollToAnchor,
    scrollToCenter: scrollToCenter,
    detectHeaderOffset: detectHeaderOffset,
    highlightBlock: highlightBlock,
    isNotionBlockAnchor: isNotionBlockAnchor
};

})(typeof jQuery !== 'undefined' ? jQuery : undefined);
