/**
 * 数据库交互功能
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
    let currentView = 'auto';
    let searchTimeout = null;
    let isFullscreen = false;

    /**
     * 初始化数据库交互功能
     */
    function initDatabaseInteractions() {
        initRecordExpansion();
        initViewSwitcher();
        initSearchFilter();
        initSortingControls();
        initKeyboardShortcuts();
        initFullscreenMode();
        initAccessibilityFeatures();
        
        console.log('数据库交互功能已初始化');
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
        
        // 添加展开内容
        let expandedContent = record.querySelector('.notion-record-expanded-content');
        if (!expandedContent) {
            expandedContent = createExpandedContent(record);
            record.appendChild(expandedContent);
        }
        
        // 动画效果
        expandedContent.style.maxHeight = '0px';
        expandedContent.style.opacity = '0';
        
        requestAnimationFrame(() => {
            expandedContent.style.maxHeight = expandedContent.scrollHeight + 'px';
            expandedContent.style.opacity = '1';
        });

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
        
        const expandedContent = record.querySelector('.notion-record-expanded-content');
        if (expandedContent) {
            expandedContent.style.maxHeight = '0px';
            expandedContent.style.opacity = '0';
            
            setTimeout(() => {
                if (!record.classList.contains(INTERACTION_CONFIG.expandedClass)) {
                    expandedContent.remove();
                }
            }, INTERACTION_CONFIG.animationDuration);
        }

        // 触发自定义事件
        record.dispatchEvent(new CustomEvent('recordCollapsed', {
            detail: { record: record }
        }));
    }

    /**
     * 创建展开内容
     */
    function createExpandedContent(record) {
        const content = document.createElement('div');
        content.className = 'notion-record-expanded-content';
        
        // 获取记录ID
        const recordId = record.dataset.recordId || '未知';
        
        content.innerHTML = `
            <div class="notion-expanded-details">
                <div class="notion-expanded-section">
                    <h4>记录详情</h4>
                    <p><strong>记录ID:</strong> ${recordId}</p>
                    <p><strong>创建时间:</strong> ${new Date().toLocaleString()}</p>
                </div>
                <div class="notion-expanded-actions">
                    <button class="notion-action-btn" onclick="NotionDatabaseInteractions.copyRecordId('${recordId}')">
                        📋 复制ID
                    </button>
                    <button class="notion-action-btn" onclick="NotionDatabaseInteractions.openInNotion('${recordId}')">
                        🔗 在Notion中打开
                    </button>
                </div>
            </div>
        `;
        
        return content;
    }

    /**
     * 视图切换器
     */
    function initViewSwitcher() {
        // 为每个数据库预览添加视图切换器
        const databasePreviews = document.querySelectorAll('.notion-database-preview');
        databasePreviews.forEach(preview => {
            if (!preview.querySelector('.notion-view-switcher')) {
                const switcher = createViewSwitcher();
                preview.insertBefore(switcher, preview.firstChild);
            }
        });
    }

    /**
     * 创建视图切换器
     */
    function createViewSwitcher() {
        const switcher = document.createElement('div');
        switcher.className = 'notion-view-switcher';
        
        switcher.innerHTML = `
            <div class="notion-view-controls">
                <button class="notion-view-btn active" data-view="auto" title="自动选择视图">
                    🤖 自动
                </button>
                <button class="notion-view-btn" data-view="list" title="列表视图">
                    📋 列表
                </button>
                <button class="notion-view-btn" data-view="gallery" title="画廊视图">
                    🖼️ 画廊
                </button>
                <button class="notion-view-btn" data-view="table" title="表格视图">
                    📊 表格
                </button>
            </div>
        `;
        
        // 添加点击事件
        switcher.addEventListener('click', function(e) {
            const btn = e.target.closest('.notion-view-btn');
            if (!btn) return;
            
            const viewType = btn.dataset.view;
            switchView(switcher.parentElement, viewType);
            
            // 更新按钮状态
            switcher.querySelectorAll('.notion-view-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        });
        
        return switcher;
    }

    /**
     * 切换视图
     */
    function switchView(preview, viewType) {
        // 移除现有视图类
        preview.classList.remove('notion-database-list', 'notion-database-gallery', 'notion-database-table');
        
        // 添加新视图类
        if (viewType !== 'auto') {
            preview.classList.add(`notion-database-${viewType}`);
        }
        
        currentView = viewType;
        
        // 触发自定义事件
        preview.dispatchEvent(new CustomEvent('viewChanged', {
            detail: { viewType: viewType }
        }));
        
        console.log('视图已切换到:', viewType);
    }

    /**
     * 搜索过滤功能
     */
    function initSearchFilter() {
        const databasePreviews = document.querySelectorAll('.notion-database-preview');
        databasePreviews.forEach(preview => {
            if (!preview.querySelector('.notion-search-filter')) {
                const searchFilter = createSearchFilter();
                const viewSwitcher = preview.querySelector('.notion-view-switcher');
                if (viewSwitcher) {
                    viewSwitcher.appendChild(searchFilter);
                } else {
                    preview.insertBefore(searchFilter, preview.firstChild);
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
                <button class="notion-search-clear" title="清除搜索" style="display: none;">
                    ✕
                </button>
            </div>
        `;
        
        const input = filter.querySelector('.notion-search-input');
        const clearBtn = filter.querySelector('.notion-search-clear');
        
        // 搜索输入事件
        input.addEventListener('input', function() {
            const query = this.value.trim();
            
            if (query) {
                clearBtn.style.display = 'block';
            } else {
                clearBtn.style.display = 'none';
            }
            
            // 防抖搜索
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                performSearch(this.closest('.notion-database-preview'), query);
            }, INTERACTION_CONFIG.searchDelay);
        });
        
        // 清除按钮事件
        clearBtn.addEventListener('click', function() {
            input.value = '';
            this.style.display = 'none';
            performSearch(filter.closest('.notion-database-preview'), '');
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
            const text = record.textContent.toLowerCase();
            const matches = !query || text.includes(query.toLowerCase());
            
            if (matches) {
                record.style.display = '';
                visibleCount++;
            } else {
                record.style.display = 'none';
            }
        });
        
        // 显示搜索结果统计
        updateSearchStats(preview, visibleCount, records.length, query);
        
        console.log(`搜索完成: "${query}", 显示 ${visibleCount}/${records.length} 条记录`);
    }

    /**
     * 更新搜索统计
     */
    function updateSearchStats(preview, visible, total, query) {
        let stats = preview.querySelector('.notion-search-stats');
        if (!stats) {
            stats = document.createElement('div');
            stats.className = 'notion-search-stats';
            const searchFilter = preview.querySelector('.notion-search-filter');
            if (searchFilter) {
                searchFilter.appendChild(stats);
            }
        }
        
        if (query) {
            stats.textContent = `显示 ${visible} / ${total} 条记录`;
            stats.style.display = 'block';
        } else {
            stats.style.display = 'none';
        }
    }

    // DOM 加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDatabaseInteractions);
    } else {
        initDatabaseInteractions();
    }

    /**
     * 排序控制功能
     */
    function initSortingControls() {
        const databasePreviews = document.querySelectorAll('.notion-database-preview');
        databasePreviews.forEach(preview => {
            if (!preview.querySelector('.notion-sort-controls')) {
                const sortControls = createSortControls();
                const viewSwitcher = preview.querySelector('.notion-view-switcher');
                if (viewSwitcher) {
                    viewSwitcher.appendChild(sortControls);
                }
            }
        });
    }

    /**
     * 创建排序控制器
     */
    function createSortControls() {
        const controls = document.createElement('div');
        controls.className = 'notion-sort-controls';

        controls.innerHTML = `
            <select class="notion-sort-select" aria-label="选择排序方式">
                <option value="">默认排序</option>
                <option value="title-asc">标题 A-Z</option>
                <option value="title-desc">标题 Z-A</option>
                <option value="created-desc">最新创建</option>
                <option value="created-asc">最早创建</option>
            </select>
        `;

        const select = controls.querySelector('.notion-sort-select');
        select.addEventListener('change', function() {
            const preview = this.closest('.notion-database-preview');
            sortRecords(preview, this.value);
        });

        return controls;
    }

    /**
     * 排序记录
     */
    function sortRecords(preview, sortType) {
        const recordsContainer = preview.querySelector('.notion-database-records, .notion-gallery-grid, .notion-table-body');
        if (!recordsContainer) return;

        const records = Array.from(recordsContainer.querySelectorAll('.notion-database-record, .notion-table-row'));

        if (sortType) {
            records.sort((a, b) => {
                return compareRecords(a, b, sortType);
            });
        }

        // 重新排列DOM元素
        records.forEach(record => {
            recordsContainer.appendChild(record);
        });

        console.log('记录已按', sortType, '排序');
    }

    /**
     * 比较记录
     */
    function compareRecords(a, b, sortType) {
        const [field, order] = sortType.split('-');
        let valueA, valueB;

        switch (field) {
            case 'title':
                valueA = a.querySelector('.notion-record-title, .notion-table-title-cell')?.textContent || '';
                valueB = b.querySelector('.notion-record-title, .notion-table-title-cell')?.textContent || '';
                break;
            case 'created':
                // 简化处理，实际应该从数据中获取创建时间
                valueA = a.dataset.created || '0';
                valueB = b.dataset.created || '0';
                break;
            default:
                return 0;
        }

        const comparison = valueA.localeCompare(valueB);
        return order === 'desc' ? -comparison : comparison;
    }

    /**
     * 键盘快捷键支持
     */
    function initKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            // 只在没有输入焦点时响应快捷键
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            switch (e.key) {
                case 'f':
                case 'F':
                    if (e.ctrlKey || e.metaKey) {
                        e.preventDefault();
                        focusSearch();
                    }
                    break;
                case 'Escape':
                    if (isFullscreen) {
                        exitFullscreen();
                    } else {
                        clearSearch();
                    }
                    break;
                case 'Enter':
                    if (e.target.classList.contains('notion-database-record')) {
                        toggleRecordExpansion(e.target);
                    }
                    break;
            }
        });
    }

    /**
     * 聚焦搜索框
     */
    function focusSearch() {
        const searchInput = document.querySelector('.notion-search-input');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }

    /**
     * 清除搜索
     */
    function clearSearch() {
        const searchInputs = document.querySelectorAll('.notion-search-input');
        searchInputs.forEach(input => {
            input.value = '';
            input.dispatchEvent(new Event('input'));
        });
    }

    /**
     * 全屏模式
     */
    function initFullscreenMode() {
        const databasePreviews = document.querySelectorAll('.notion-database-preview');
        databasePreviews.forEach(preview => {
            if (!preview.querySelector('.notion-fullscreen-btn')) {
                const fullscreenBtn = createFullscreenButton();
                const viewSwitcher = preview.querySelector('.notion-view-switcher');
                if (viewSwitcher) {
                    viewSwitcher.appendChild(fullscreenBtn);
                }
            }
        });
    }

    /**
     * 创建全屏按钮
     */
    function createFullscreenButton() {
        const button = document.createElement('button');
        button.className = 'notion-fullscreen-btn';
        button.innerHTML = '⛶ 全屏';
        button.title = '全屏查看';

        button.addEventListener('click', function() {
            const preview = this.closest('.notion-database-preview');
            toggleFullscreen(preview);
        });

        return button;
    }

    /**
     * 切换全屏模式
     */
    function toggleFullscreen(preview) {
        if (isFullscreen) {
            exitFullscreen();
        } else {
            enterFullscreen(preview);
        }
    }

    /**
     * 进入全屏模式
     */
    function enterFullscreen(preview) {
        const overlay = document.createElement('div');
        overlay.className = 'notion-fullscreen-overlay';
        overlay.innerHTML = `
            <div class="notion-fullscreen-header">
                <h3>数据库全屏查看</h3>
                <button class="notion-fullscreen-close">✕ 关闭</button>
            </div>
            <div class="notion-fullscreen-content"></div>
        `;

        // 克隆预览内容
        const clonedPreview = preview.cloneNode(true);
        overlay.querySelector('.notion-fullscreen-content').appendChild(clonedPreview);

        // 添加关闭事件
        overlay.querySelector('.notion-fullscreen-close').addEventListener('click', exitFullscreen);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                exitFullscreen();
            }
        });

        document.body.appendChild(overlay);
        document.body.classList.add('notion-fullscreen-active');
        isFullscreen = true;

        console.log('已进入全屏模式');
    }

    /**
     * 退出全屏模式
     */
    function exitFullscreen() {
        const overlay = document.querySelector('.notion-fullscreen-overlay');
        if (overlay) {
            overlay.remove();
        }
        document.body.classList.remove('notion-fullscreen-active');
        isFullscreen = false;

        console.log('已退出全屏模式');
    }

    /**
     * 无障碍访问支持
     */
    function initAccessibilityFeatures() {
        // 为记录添加键盘导航支持
        const records = document.querySelectorAll('.notion-database-record');
        records.forEach((record, index) => {
            record.setAttribute('tabindex', '0');
            record.setAttribute('role', 'button');
            record.setAttribute('aria-label', `数据库记录 ${index + 1}`);
            record.setAttribute('aria-expanded', 'false');

            // 键盘事件
            record.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleRecordExpansion(this);
                }
            });
        });

        // 为按钮添加ARIA标签
        const buttons = document.querySelectorAll('.notion-view-btn, .notion-action-btn');
        buttons.forEach(button => {
            if (!button.getAttribute('aria-label')) {
                button.setAttribute('aria-label', button.textContent.trim());
            }
        });
    }

    // 暴露全局方法
    window.NotionDatabaseInteractions = {
        switchView: switchView,
        performSearch: performSearch,
        expandRecord: expandRecord,
        collapseRecord: collapseRecord,
        sortRecords: sortRecords,
        toggleFullscreen: toggleFullscreen,
        focusSearch: focusSearch,
        clearSearch: clearSearch,
        copyRecordId: function(recordId) {
            navigator.clipboard.writeText(recordId).then(() => {
                console.log('记录ID已复制:', recordId);
                showToast('记录ID已复制到剪贴板');
            }).catch(() => {
                console.log('复制失败，记录ID:', recordId);
                showToast('复制失败，请手动复制');
            });
        },
        openInNotion: function(recordId) {
            const url = `https://notion.so/${recordId.replace(/-/g, '')}`;
            window.open(url, '_blank');
        }
    };

    /**
     * 显示提示消息
     */
    function showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'notion-toast';
        toast.textContent = message;

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('notion-toast-show');
        }, 10);

        setTimeout(() => {
            toast.classList.remove('notion-toast-show');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 2000);
    }

})();
