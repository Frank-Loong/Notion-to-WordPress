/**
 * 数据库交互功能 - 增强版本，包含排序功能
 * 专注于Notion原生体验，包含记录展开、搜索和排序功能
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
        animationDuration: 300,
        sortOptions: {
            'created': '创建时间',
            'title': '标题',
            'properties': '属性值'
        }
    };

    // 全局状态
    let searchTimeout = null;
    let currentSortBy = 'created';
    let currentSortOrder = 'desc';

    /**
     * 初始化数据库交互功能
     */
    function initDatabaseInteractions() {
        initRecordExpansion();
        initSearchFilter();
        initSortControls();

        console.log('数据库交互功能已初始化（包含排序功能）');
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
     * 初始化排序控件
     */
    function initSortControls() {
        const databasePreviews = document.querySelectorAll('.notion-database-preview');
        databasePreviews.forEach(preview => {
            if (!preview.querySelector('.notion-sort-controls')) {
                const sortControls = createSortControls();
                const searchFilter = preview.querySelector('.notion-search-filter');
                if (searchFilter) {
                    searchFilter.appendChild(sortControls);
                } else {
                    preview.insertBefore(sortControls, preview.firstChild);
                }
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
     * 创建排序控件
     */
    function createSortControls() {
        const controls = document.createElement('div');
        controls.className = 'notion-sort-controls';

        controls.innerHTML = `
            <div class="notion-sort-box">
                <label class="notion-sort-label">排序:</label>
                <select class="notion-sort-select" aria-label="选择排序方式">
                    <option value="created-desc">创建时间 ↓</option>
                    <option value="created-asc">创建时间 ↑</option>
                    <option value="title-asc">标题 A-Z</option>
                    <option value="title-desc">标题 Z-A</option>
                </select>
            </div>
        `;

        const select = controls.querySelector('.notion-sort-select');

        // 排序选择事件
        select.addEventListener('change', function() {
            const [sortBy, sortOrder] = this.value.split('-');
            currentSortBy = sortBy;
            currentSortOrder = sortOrder;

            const preview = this.closest('.notion-database-preview');
            performSort(preview, sortBy, sortOrder);
        });

        return controls;
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
     * 执行排序
     */
    function performSort(preview, sortBy, sortOrder) {
        // 检测视图类型
        const isBoard = preview.classList.contains('notion-database-board');
        const isFeed = preview.classList.contains('notion-database-feed');
        const isCalendar = preview.classList.contains('notion-database-calendar');
        const isTimeline = preview.classList.contains('notion-database-timeline');
        const isChart = preview.classList.contains('notion-database-chart');

        if (isBoard) {
            performBoardSort(preview, sortBy, sortOrder);
        } else if (isFeed) {
            performFeedSort(preview, sortBy, sortOrder);
        } else if (isCalendar) {
            // 日历视图不支持排序，因为按日期自然排序
            console.log('日历视图按日期自然排序，无需手动排序');
        } else if (isTimeline) {
            // 时间轴视图按时间自然排序
            console.log('时间轴视图按时间自然排序，无需手动排序');
        } else if (isChart) {
            // 图表视图不支持排序
            console.log('图表视图不支持排序');
        } else {
            performStandardSort(preview, sortBy, sortOrder);
        }
    }

    /**
     * 标准视图排序（列表、表格、画廊）
     */
    function performStandardSort(preview, sortBy, sortOrder) {
        const recordsContainer = preview.querySelector('.notion-database-records');
        if (!recordsContainer) return;

        const records = Array.from(recordsContainer.querySelectorAll('.notion-database-record'));

        records.sort((a, b) => {
            return compareRecords(a, b, sortBy, sortOrder);
        });

        // 重新排列DOM元素
        records.forEach(record => {
            recordsContainer.appendChild(record);
        });

        console.log(`标准排序完成: ${sortBy} ${sortOrder}, ${records.length} 条记录`);
    }

    /**
     * 看板视图排序
     */
    function performBoardSort(preview, sortBy, sortOrder) {
        const columns = preview.querySelectorAll('.notion-board-column-content');

        columns.forEach(column => {
            const cards = Array.from(column.querySelectorAll('.notion-board-card'));

            cards.sort((a, b) => {
                return compareRecords(a, b, sortBy, sortOrder);
            });

            cards.forEach(card => {
                column.appendChild(card);
            });
        });

        console.log(`看板排序完成: ${sortBy} ${sortOrder}`);
    }

    /**
     * Feed视图排序
     */
    function performFeedSort(preview, sortBy, sortOrder) {
        const feedContainer = preview.querySelector('.notion-feed-container');
        if (!feedContainer) return;

        const items = Array.from(feedContainer.querySelectorAll('.notion-feed-item'));

        items.sort((a, b) => {
            return compareRecords(a, b, sortBy, sortOrder);
        });

        items.forEach(item => {
            feedContainer.appendChild(item);
        });

        console.log(`Feed排序完成: ${sortBy} ${sortOrder}, ${items.length} 条记录`);
    }

    /**
     * 比较记录
     */
    function compareRecords(a, b, sortBy, sortOrder) {
        let valueA, valueB;

        switch (sortBy) {
            case 'created':
                valueA = a.getAttribute('data-created') || '';
                valueB = b.getAttribute('data-created') || '';
                break;
            case 'title':
                // 支持不同视图的标题选择器
                const titleSelectors = [
                    '.notion-record-title',
                    '.notion-board-card-title',
                    '.notion-feed-title'
                ];

                for (const selector of titleSelectors) {
                    const titleA = a.querySelector(selector);
                    const titleB = b.querySelector(selector);
                    if (titleA && titleB) {
                        valueA = titleA.textContent.trim();
                        valueB = titleB.textContent.trim();
                        break;
                    }
                }

                if (!valueA || !valueB) {
                    valueA = valueA || '';
                    valueB = valueB || '';
                }
                break;
            default:
                return 0;
        }

        // 字符串比较
        if (sortOrder === 'asc') {
            return valueA.localeCompare(valueB, 'zh-CN');
        } else {
            return valueB.localeCompare(valueA, 'zh-CN');
        }
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
