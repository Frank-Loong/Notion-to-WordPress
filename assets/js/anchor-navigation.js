/**
 * Notion 区块锚点导航功能
 *
 * @since      1.1.1
 * @package    Notion_To_WordPress
 */

(function($) {
'use strict';

/* ---------------- 锚点导航核心功能 ---------------- */

/**
 * 平滑滚动到目标区块
 * @param {string} targetId 目标区块的 ID
 */
function smoothScrollToAnchor(targetId) {
    // 移除 # 前缀（如果存在）
    const cleanId = targetId.replace(/^#/, '');
    const target = document.getElementById(cleanId);
    
    if (target) {
        console.log('🎯 [Notion to WordPress] 跳转到区块:', cleanId);
        
        // 使用现代浏览器的平滑滚动
        target.scrollIntoView({ 
            behavior: 'smooth',
            block: 'start',
            inline: 'nearest'
        });
        
        // 添加高亮效果
        highlightBlock(target);
        
        // 更新 URL hash（不触发滚动）
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, null, '#' + cleanId);
        }
    } else {
        console.warn('⚠️ [Notion to WordPress] 未找到目标区块:', cleanId);
    }
}

/**
 * 为目标区块添加高亮动画效果
 * @param {Element} element 目标元素
 */
function highlightBlock(element) {
    // 移除可能存在的高亮类
    element.classList.remove('notion-block-highlight');
    
    // 强制重绘，确保动画能正确触发
    element.offsetHeight;
    
    // 添加高亮类
    element.classList.add('notion-block-highlight');
    
    // 2秒后移除高亮效果
    setTimeout(() => {
        element.classList.remove('notion-block-highlight');
    }, 2000);
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
    $(document).on('click', 'a[href^="#notion-block-"]', handleAnchorClick);
    
    // 监听 URL hash 变化
    $(window).on('hashchange', handleHashChange);
    
    // 页面加载时检查 URL hash
    $(document).ready(() => {
        const hash = window.location.hash;
        if (isNotionBlockAnchor(hash)) {
            // 延迟执行，确保页面完全加载
            setTimeout(() => {
                smoothScrollToAnchor(hash);
            }, 500);
        }
    });
    
    console.log('✅ [Notion to WordPress] 锚点导航功能初始化完成');
}

/* ---------------- 主题兼容性处理 ---------------- */

/**
 * 检测主题是否有自定义滚动行为
 */
function detectThemeScrollBehavior() {
    // 检测是否有其他滚动相关的脚本
    const hasCustomScroll = window.smoothScroll || 
                           window.SmoothScroll || 
                           $('body').hasClass('smooth-scroll') ||
                           $('html').css('scroll-behavior') === 'smooth';
    
    if (hasCustomScroll) {
        console.info('🔍 [Notion to WordPress] 检测到主题可能有自定义滚动行为，将与之协调工作');
    }
    
    return hasCustomScroll;
}

/* ---------------- 初始化 ---------------- */

// 页面准备就绪时初始化
$(function() {
    // 检测主题兼容性
    detectThemeScrollBehavior();
    
    // 初始化锚点导航
    initAnchorNavigation();
});

// 暴露核心函数到全局作用域，供调试和扩展使用
window.NotionToWordPressAnchor = {
    smoothScrollToAnchor: smoothScrollToAnchor,
    highlightBlock: highlightBlock,
    isNotionBlockAnchor: isNotionBlockAnchor
};

})(jQuery);
