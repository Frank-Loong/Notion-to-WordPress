/**
 * 简洁同步进度管理器
 *
 * 为 Notion to WordPress 插件提供简洁的光泽动画进度条
 * 支持实时进度更新和状态显示，去除了复杂的UI组件
 *
 * @since      2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 */

(function($) {
    'use strict';

    // 同步步骤定义（已移除 - 使用简洁进度条）

    /**
     * 同步进度管理器类
     */
    window.SyncProgressManager = class SyncProgressManager {

        constructor() {
            this.taskId = null;
            this.updateInterval = null;
            this.container = null;
            this.isVisible = false;
            this.lastUpdateTime = 0;
            this.updateFrequency = 2000; // 2秒更新间隔（AJAX模式）

            // SSE相关属性
            this.useSSE = true; // 默认使用SSE模式
            this.sseManager = null;
            this.fallbackToAjax = false;

            // 绑定方法上下文
            this.updateProgress = this.updateProgress.bind(this);
            this.handleVisibilityChange = this.handleVisibilityChange.bind(this);
            this.handleSSEProgress = this.handleSSEProgress.bind(this);
            this.handleSSEComplete = this.handleSSEComplete.bind(this);
            this.handleSSEError = this.handleSSEError.bind(this);

            // 监听页面可见性变化
            document.addEventListener('visibilitychange', this.handleVisibilityChange);
        }
        
        /**
         * 显示进度界面
         * @param {string} taskId 任务ID
         * @param {string} syncType 同步类型
         * @param {Object} options 选项配置
         */
        showProgress(taskId, syncType = '同步', options = {}) {
            this.taskId = taskId;
            this.syncType = syncType;

            // 检查是否强制使用AJAX模式
            if (options.forceAjax) {
                this.useSSE = false;
                this.fallbackToAjax = true;
            }

            // 创建进度UI
            this.createProgressUI();

            // 开始进度更新
            this.startProgressUpdates();

            // 显示进度容器
            this.container.removeClass('notion-wp-hidden').slideDown(300);
            this.isVisible = true;
        }
        
        /**
         * 隐藏进度界面
         */
        hideProgress() {
            if (!this.isVisible) return;

            // 停止更新
            this.stopProgressUpdates();

            // 隐藏容器
            if (this.container) {
                // 添加淡出动画
                this.container.fadeOut(300, () => {
                    this.container.remove();
                    this.container = null;
                });
            }

            this.isVisible = false;
            this.taskId = null;

            // 重置同步按钮状态
            this.resetSyncButtons();
        }
        
        /**
         * 创建进度UI
         */
        createProgressUI() {
            // 移除现有容器
            $('.notion-sync-progress-container').remove();

            // 创建进度容器HTML
            const progressHTML = this.generateProgressHTML();

            // 查找插入位置 - 优先级顺序
            let $insertTarget = null;

            // 1. 查找同步操作区域
            const $syncActions = $('.notion-wp-sync-actions');
            if ($syncActions.length > 0) {
                // 插入到同步按钮后面，同步信息前面
                const $syncInfo = $syncActions.find('.sync-info');
                if ($syncInfo.length > 0) {
                    $insertTarget = $syncInfo;
                    this.container = $(progressHTML).insertBefore($insertTarget);
                } else {
                    $insertTarget = $syncActions;
                    this.container = $(progressHTML).appendTo($insertTarget);
                }
            }

            // 2. 备用位置：查找同步按钮
            if (!$insertTarget) {
                const $syncButtons = $('.sync-buttons, .notion-wp-sync-buttons');
                if ($syncButtons.length > 0) {
                    $insertTarget = $syncButtons;
                    this.container = $(progressHTML).insertAfter($insertTarget);
                }
            }

            // 3. 最后备用：查找具体的同步按钮
            if (!$insertTarget) {
                const $manualImport = $('#notion-manual-import');
                const $fullImport = $('#notion-full-import');
                if ($manualImport.length > 0) {
                    $insertTarget = $manualImport.parent();
                    this.container = $(progressHTML).insertAfter($insertTarget);
                } else if ($fullImport.length > 0) {
                    $insertTarget = $fullImport.parent();
                    this.container = $(progressHTML).insertAfter($insertTarget);
                }
            }

            // 4. 如果还是找不到，插入到body（测试环境）
            if (!$insertTarget) {
                console.warn('⚠️ [进度管理器] 未找到合适的插入位置，使用body作为容器');
                this.container = $(progressHTML).appendTo('body');
            }

            // 绑定事件
            this.bindProgressEvents();

            // 初始化步骤指示器
            this.initializeStepsIndicator();
        }
        
        /**
         * 生成简洁光泽进度条HTML
         */
        generateProgressHTML() {
            return `
                <div class="notion-sync-progress-container notion-wp-hidden">
                    <div class="sync-progress-main">
                        <div class="sync-main-progress">
                            <div class="progress-bar-container">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 0%"></div>
                                </div>
                                <span class="progress-percentage">0%</span>
                            </div>
                            <div class="progress-status">
                                <span class="current-step-text">准备开始同步...</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        /**
         * 生成步骤HTML（已简化，不再使用）
         */
        generateStepsHTML() {
            return '';
        }
        
        /**
         * 绑定进度事件（已移除 - 简洁进度条无需事件）
         */
        bindProgressEvents() {
            // 简洁进度条无需事件监听器
        }
        
        /**
         * 初始化步骤指示器（已移除 - 简洁进度条无需步骤）
         */
        initializeStepsIndicator() {
            // 简洁进度条无需步骤指示器
        }
        
        /**
         * 开始进度更新
         */
        startProgressUpdates() {
            if (this.useSSE && !this.fallbackToAjax) {
                // 使用SSE模式
                this.startSSEUpdates();
            } else {
                // 使用AJAX轮询模式
                this.startAjaxUpdates();
            }
        }

        /**
         * 开始SSE进度更新
         */
        startSSEUpdates() {
            try {
                // 检查SSE支持
                if (typeof EventSource === 'undefined') {
                    console.warn('⚠️ [进度管理器] 浏览器不支持SSE，回退到AJAX模式');
                    this.fallbackToAjax = true;
                    this.startAjaxUpdates();
                    return;
                }

                // 检查SSEProgressManager是否可用
                if (typeof SSEProgressManager === 'undefined') {
                    console.warn('⚠️ [进度管理器] SSEProgressManager未加载，回退到AJAX模式');
                    this.fallbackToAjax = true;
                    this.startAjaxUpdates();
                    return;
                }

                // 创建SSE管理器
                this.sseManager = new SSEProgressManager(this.taskId, {
                    onProgress: this.handleSSEProgress,
                    onComplete: this.handleSSEComplete,
                    onError: this.handleSSEError,
                    onConnect: () => {
                        console.log('🔗 [进度管理器] SSE连接已建立');
                    },
                    onDisconnect: () => {
                        console.log('🔌 [进度管理器] SSE连接已断开');
                    }
                });

                // 开始SSE流
                this.sseManager.start();

            } catch (error) {
                console.error('❌ [进度管理器] SSE启动失败，回退到AJAX模式:', error);
                this.fallbackToAjax = true;
                this.startAjaxUpdates();
            }
        }

        /**
         * 开始AJAX轮询更新
         */
        startAjaxUpdates() {
            if (this.updateInterval) {
                clearInterval(this.updateInterval);
            }

            // 立即执行一次更新
            this.fetchAndUpdateProgress();

            // 设置定期更新
            this.updateInterval = setInterval(() => {
                this.fetchAndUpdateProgress();
            }, this.updateFrequency);
        }
        
        /**
         * 停止进度更新
         */
        stopProgressUpdates() {
            // 停止SSE连接
            if (this.sseManager) {
                this.sseManager.stop();
                this.sseManager = null;
            }

            // 停止AJAX轮询
            if (this.updateInterval) {
                clearInterval(this.updateInterval);
                this.updateInterval = null;
            }
        }

        /**
         * 处理SSE进度更新
         *
         * @param {Object} progressData 进度数据
         */
        handleSSEProgress(progressData) {
            this.updateProgress(progressData);
        }

        /**
         * 处理SSE任务完成
         *
         * @param {Object} completionData 完成数据
         */
        handleSSEComplete(completionData) {

            // 更新最终进度
            if (completionData) {
                this.updateProgress(completionData);
            }

            // 延迟隐藏进度界面
            setTimeout(() => {
                this.hideProgress();
            }, 2000);
        }

        /**
         * 处理SSE错误
         *
         * @param {Object} errorData 错误数据
         */
        handleSSEError(errorData) {
            console.error('❌ [SSE] 错误:', errorData);

            // 如果是连接错误，尝试回退到AJAX模式
            if (!this.fallbackToAjax) {
                console.log('🔄 [进度管理器] SSE失败，回退到AJAX模式');
                this.fallbackToAjax = true;
                this.useSSE = false;
                this.startAjaxUpdates();
            } else {
                // 显示错误信息
                this.updateStatusText('error', { message: errorData.message || '同步过程中发生错误' });
            }
        }

        /**
         * 重置同步按钮状态
         */
        resetSyncButtons() {
            // 重置智能同步按钮
            const smartSyncBtn = document.querySelector('.smart-sync-btn');
            if (smartSyncBtn) {
                smartSyncBtn.textContent = ' 智能同步';
                smartSyncBtn.disabled = false;
                smartSyncBtn.classList.remove('syncing');
            }

            // 重置完全同步按钮
            const fullSyncBtn = document.querySelector('.full-sync-btn');
            if (fullSyncBtn) {
                fullSyncBtn.disabled = false;
                fullSyncBtn.classList.remove('syncing');
            }
        }
        
        /**
         * 获取并更新进度
         */
        fetchAndUpdateProgress() {
            if (!this.taskId) return;
            
            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'notion_to_wordpress_get_sync_progress',
                    nonce: notionToWp.nonce,
                    task_id: this.taskId
                },
                success: (response) => {
                    if (response.success && response.data) {
                        this.updateProgress(response.data);
                        this.lastUpdateTime = Date.now();
                    } else {
                        console.warn('⚠️ [进度管理器] 获取进度失败:', response.data?.message || '未知错误');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('❌ [进度管理器] 进度查询网络错误:', error);
                    
                    // 网络错误时降低更新频率
                    this.updateFrequency = Math.min(this.updateFrequency * 1.5, 10000);
                }
            });
        }
        
        /**
         * 更新简洁进度显示
         * @param {Object} progressData 进度数据
         */
        updateProgress(progressData) {
            if (!this.container || !progressData) return;

            const { progress = {}, status } = progressData;

            // 更新主进度条
            this.updateMainProgress(progress);

            // 更新状态文本（传递完整的progress数据以获取message）
            this.updateStatusText(status, progress);

            // 检查是否完成
            if (status === 'completed' || status === 'failed' || status === 'cancelled') {
                this.handleSyncComplete(status, progressData);
            }
        }
        
        /**
         * 更新主进度条（简洁版）
         */
        updateMainProgress(progress) {
            const percentage = Math.min(100, Math.max(0, progress.percentage || 0));
            const progressFill = this.container.find('.progress-fill');

            // 更新进度条宽度
            progressFill.css('width', percentage + '%');

            // 更新百分比文本
            this.container.find('.progress-percentage').text(percentage.toFixed(0) + '%');
        }

        /**
         * 更新状态文本
         * @param {string} status 状态
         * @param {Object} progress 进度数据（包含详细message）
         */
        updateStatusText(status, progress = {}) {
            const statusElement = this.container.find('.current-step-text');

            // 优先使用后端传递的详细message
            if (progress.message && progress.message.trim()) {
                statusElement.text(progress.message);
                return;
            }

            // 如果没有详细message，使用默认状态文本
            let statusText = '准备开始同步...';
            if (status) {
                switch (status) {
                    case 'connecting':
                        statusText = '正在连接 Notion...';
                        break;
                    case 'fetching':
                        statusText = '正在获取页面数据...';
                        break;
                    case 'processing':
                        statusText = '正在处理内容...';
                        break;
                    case 'downloading':
                        statusText = '正在下载图片...';
                        break;
                    case 'saving':
                        statusText = '正在保存文章...';
                        break;
                    case 'indexing':
                        statusText = '正在更新索引...';
                        break;
                    case 'completed':
                        statusText = '同步完成！';
                        break;
                    case 'failed':
                        statusText = '同步失败';
                        break;
                    case 'cancelled':
                        statusText = '同步已取消';
                        break;
                    default:
                        statusText = '正在同步...';
                }
            }

            statusElement.text(statusText);
        }
        
        /**
         * 更新步骤指示器（已移除 - 使用简洁进度条）
         */
        updateStepsIndicator() {
            // 方法已简化，不再使用步骤指示器
        }
        
        /**
         * 更新统计信息（已移除 - 使用简洁进度条）
         */
        updateStats() {
            // 方法已简化，不再使用统计面板
        }
        
        /**
         * 更新错误信息（已移除 - 使用简洁进度条）
         */
        updateErrors() {
            // 方法已简化，不再使用错误面板
        }
        
        /**
         * 处理同步完成
         */
        handleSyncComplete(status, progressData) {
            this.stopProgressUpdates();

            // 更新UI状态
            this.container.find('.sync-progress-title .sync-progress-icon').text(
                status === 'completed' ? '✅' :
                status === 'failed' ? '❌' : '⏹️'
            );

            // 显示完成消息
            const message = this.getCompletionMessage(status, progressData);
            this.showCompletionMessage(message, status);

            // 立即隐藏进度界面（成功时延迟1秒，失败时延迟2秒）
            setTimeout(() => {
                this.hideProgress();
            }, status === 'completed' ? 1000 : 2000);
        }
        
        /**
         * 获取完成消息
         */
        getCompletionMessage(status, progressData) {
            const { progress = {} } = progressData;
            const { total = 0, success = 0, failed = 0 } = progress;
            
            switch (status) {
                case 'completed':
                    return `同步完成！成功处理 ${success}/${total} 项${failed > 0 ? `，${failed} 项失败` : ''}`;
                case 'failed':
                    return `同步失败！已处理 ${success}/${total} 项，${failed} 项失败`;
                case 'cancelled':
                    return `同步已取消！已处理 ${success}/${total} 项`;
                default:
                    return '同步已结束';
            }
        }
        
        /**
         * 显示完成消息
         */
        showCompletionMessage(message, status) {
            const statusClass = status === 'completed' ? 'success' : 
                              status === 'failed' ? 'error' : 'warning';
            
            // 更新当前步骤文本
            this.container.find('.current-step-text').text(message);
            
            // 如果有全局消息显示函数，也显示一下
            if (typeof showModal === 'function') {
                showModal(message, statusClass);
            }
        }
        
        /**
         * 处理页面可见性变化
         */
        handleVisibilityChange() {
            if (!this.isVisible || !this.updateInterval) return;
            
            const isPageVisible = !document.hidden;
            const newFrequency = isPageVisible ? 2000 : 5000; // 页面隐藏时降低频率
            
            if (newFrequency !== this.updateFrequency) {
                this.updateFrequency = newFrequency;
                
                // 重新设置更新间隔
                this.stopProgressUpdates();
                this.startProgressUpdates();
            }
        }
        
        /**
         * 处理取消同步（已移除 - 简洁进度条无取消功能）
         */
        handleCancelSync() {
            // 简洁进度条无取消功能
        }
        
        /**
         * 切换错误面板（已移除 - 简洁进度条无错误面板）
         */
        toggleErrorsPanel() {
            // 简洁进度条无错误面板
        }

        /**
         * 处理重试失败项（已移除 - 简洁进度条无重试功能）
         */
        handleRetryFailed() {
            // 简洁进度条无重试功能
        }
        
        /**
         * 格式化持续时间（已移除 - 使用简洁进度条）
         */
        formatDuration() {
            // 方法已简化，不再使用时间格式化
            return '';
        }
        
        /**
         * 销毁进度管理器
         */
        destroy() {
            this.stopProgressUpdates();
            this.hideProgress();
            
            // 移除事件监听
            document.removeEventListener('visibilitychange', this.handleVisibilityChange);
        }
    };

})(jQuery);
