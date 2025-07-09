/**
 * 数据库交互功能 - 简化版本，专注于Notion原生体验
 * 移除了全屏、视图切换、排序等功能，只保留基本的记录展开和搜索
 * 
 * @since 1.1.1
 */

(function() {
    'use strict';

    // 交互配置
    const INTERACTION_CONFIG = {
        expandedClass: 'notion-record-expanded',
        collapsedClass: 'notion-record-collapsed',
        searchDelay: 300,
        animationDuration: 300
    };

    // 全局状态
    let searchTimeout = null;

    /**
     * 初始化数据库交互功能
     */
    function initDatabaseInteractions() {
        initRecordExpansion();
        initSearchFilter();
        
        console.log('数据库交互功能已初始化（简化版本）');
    }

    /**
     * 记录详情展开功能
     */
    function initRecordExpansion() {
        document.addEventListener('click', function(e) {
            const record = e.target.closest('.notion-database-record');
            if (!record) return;

            // 避免在点击链接或按钮时触发展开
            if (e.target.closest('a, button, .notion-file-link')) return;

            toggleRecordExpansion(record);
        });
    }

    /**
     * 切换记录展开状态
     */
    function toggleRecordExpansion(record) {
        const isExpanded = record.classList.contains(INTERACTION_CONFIG.expandedClass);
        
        if (isExpanded) {
            collapseRecord(record);
        } else {
            expandRecord(record);
        }
    }

    /**
     * 展开记录
     */
    function expandRecord(record) {
        record.classList.add(INTERACTION_CONFIG.expandedClass);
        record.classList.remove(INTERACTION_CONFIG.collapsedClass);
        
        const details = record.querySelector('.notion-record-details');
        if (details) {
            details.style.display = 'block';
            
            // 平滑动画
            setTimeout(() => {
                details.style.opacity = '1';
                details.style.transform = 'translateY(0)';
            }, 10);
        }
        
        // 触发自定义事件
        record.dispatchEvent(new CustomEvent('recordExpanded', {
            detail: { record: record }
        }));
    }

    /**
     * 收起记录
     */
    function collapseRecord(record) {
        record.classList.remove(INTERACTION_CONFIG.expandedClass);
        record.classList.add(INTERACTION_CONFIG.collapsedClass);
        
        const details = record.querySelector('.notion-record-details');
        if (details) {
            details.style.opacity = '0';
            details.style.transform = 'translateY(-10px)';
            
            setTimeout(() => {
                details.style.display = 'none';
            }, INTERACTION_CONFIG.animationDuration);
        }
        
        // 触发自定义事件
        record.dispatchEvent(new CustomEvent('recordCollapsed', {
            detail: { record: record }
        }));
    }

    /**
     * 搜索过滤功能
     */
    function initSearchFilter() {
        const databasePreviews = document.querySelectorAll('.notion-database-preview');
        databasePreviews.forEach(preview => {
            if (!preview.querySelector('.notion-search-filter')) {
                const searchFilter = createSearchFilter();
                preview.insertBefore(searchFilter, preview.firstChild);
            }
        });
    }

    /**
     * 创建搜索过滤器
     */
    function createSearchFilter() {
        const filter = document.createElement('div');
        filter.className = 'notion-search-filter';
        
        filter.innerHTML = `
            <div class="notion-search-box">
                <input type="text" 
                       class="notion-search-input" 
                       placeholder="搜索记录..." 
                       aria-label="搜索数据库记录">
                <button class="notion-search-clear" 
                        title="清除搜索" 
                        style="display: none;">
                    ✕
                </button>
            </div>
        `;
        
        const input = filter.querySelector('.notion-search-input');
        const clearBtn = filter.querySelector('.notion-search-clear');
        
        // 搜索输入事件
        input.addEventListener('input', function() {
            const query = this.value.trim();
            const preview = this.closest('.notion-database-preview');
            
            // 显示/隐藏清除按钮
            clearBtn.style.display = query ? 'block' : 'none';
            
            // 防抖搜索
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                performSearch(preview, query);
            }, INTERACTION_CONFIG.searchDelay);
        });
        
        // 清除搜索
        clearBtn.addEventListener('click', function() {
            input.value = '';
            this.style.display = 'none';
            const preview = this.closest('.notion-database-preview');
            performSearch(preview, '');
            input.focus();
        });
        
        return filter;
    }

    /**
     * 执行搜索
     */
    function performSearch(preview, query) {
        const records = preview.querySelectorAll('.notion-database-record');
        let visibleCount = 0;
        
        records.forEach(record => {
            const isVisible = !query || recordMatchesQuery(record, query);
            record.style.display = isVisible ? '' : 'none';
            if (isVisible) visibleCount++;
        });
        
        // 更新搜索结果提示
        updateSearchResults(preview, query, visibleCount, records.length);
        
        console.log(`搜索 "${query}": ${visibleCount}/${records.length} 条记录`);
    }

    /**
     * 检查记录是否匹配搜索查询
     */
    function recordMatchesQuery(record, query) {
        const searchText = query.toLowerCase();
        const recordText = record.textContent.toLowerCase();
        return recordText.includes(searchText);
    }

    /**
     * 更新搜索结果提示
     */
    function updateSearchResults(preview, query, visibleCount, totalCount) {
        let resultInfo = preview.querySelector('.notion-search-results');
        
        if (query) {
            if (!resultInfo) {
                resultInfo = document.createElement('div');
                resultInfo.className = 'notion-search-results';
                const searchFilter = preview.querySelector('.notion-search-filter');
                searchFilter.appendChild(resultInfo);
            }
            
            resultInfo.textContent = `找到 ${visibleCount} / ${totalCount} 条记录`;
            resultInfo.style.display = 'block';
        } else if (resultInfo) {
            resultInfo.style.display = 'none';
        }
    }

    // 页面加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDatabaseInteractions);
    } else {
        initDatabaseInteractions();
    }

})();
