/**
 * 同步进度管理器
 * 
 * 为 Notion to WordPress 插件提供可视化的同步进度展示功能
 * 支持实时进度更新、步骤指示器、统计信息和错误处理
 * 
 * @since      2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 */

(function($) {
    'use strict';

    // 同步步骤定义
    const SYNC_STEPS = [
        { id: 'validate', name: '验证连接', icon: '🔗', weight: 5 },
        { id: 'fetch_pages', name: '获取页面', icon: '📄', weight: 15 },
        { id: 'process_content', name: '处理内容', icon: '⚙️', weight: 40 },
        { id: 'download_images', name: '下载图片', icon: '🖼️', weight: 25 },
        { id: 'save_posts', name: '保存文章', icon: '💾', weight: 10 },
        { id: 'update_index', name: '更新索引', icon: '🔍', weight: 5 }
    ];

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
            this.updateFrequency = 2000; // 2秒更新间隔
            
            // 绑定方法上下文
            this.updateProgress = this.updateProgress.bind(this);
            this.handleVisibilityChange = this.handleVisibilityChange.bind(this);
            
            // 监听页面可见性变化
            document.addEventListener('visibilitychange', this.handleVisibilityChange);
            
            console.log('🎯 [进度管理器] 已初始化');
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
            
            // 创建进度UI
            this.createProgressUI();
            
            // 开始进度更新
            this.startProgressUpdates();
            
            // 显示进度容器
            this.container.removeClass('notion-wp-hidden').slideDown(300);
            this.isVisible = true;
            
            console.log(`🚀 [进度管理器] 开始显示进度: ${taskId} (${syncType})`);
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
                this.container.slideUp(300, () => {
                    this.container.remove();
                    this.container = null;
                });
            }
            
            this.isVisible = false;
            this.taskId = null;
            
            console.log('🏁 [进度管理器] 进度界面已隐藏');
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

            console.log('🎨 [进度管理器] 进度UI已创建，插入位置:', $insertTarget ? $insertTarget[0] : 'body');
        }
        
        /**
         * 生成简洁进度HTML
         */
        generateProgressHTML() {
            return `
                <div class="notion-sync-progress-container notion-wp-hidden">
                    <div class="sync-progress-header">
                        <div class="sync-progress-title">
                            <span class="sync-progress-icon">🔄</span>
                            ${this.syncType}进度
                        </div>
                        <div class="sync-progress-actions">
                            <button type="button" class="button button-secondary sync-cancel-btn" title="取消同步">
                                取消
                            </button>
                        </div>
                    </div>

                    <div class="sync-progress-main">
                        <!-- 主进度条 -->
                        <div class="sync-main-progress">
                            <div class="progress-bar-container">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 0%"></div>
                                </div>
                                <span class="progress-percentage">0%</span>
                            </div>
                            <div class="progress-status">
                                <span class="current-step-text">准备开始...</span>
                                <span class="progress-eta"></span>
                            </div>
                        </div>

                        <!-- 步骤指示器 -->
                        <div class="sync-steps-indicator">
                            ${this.generateStepsHTML()}
                        </div>

                        <!-- 统计信息 -->
                        <div class="sync-stats-panel">
                            <div class="sync-stats-grid">
                                <div class="stat-item">
                                    <span class="stat-icon">📊</span>
                                    <div class="stat-content">
                                        <span class="stat-label">已处理</span>
                                        <span class="stat-value" data-stat="processed">0/0</span>
                                    </div>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-icon">✅</span>
                                    <div class="stat-content">
                                        <span class="stat-label">成功</span>
                                        <span class="stat-value" data-stat="success">0</span>
                                    </div>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-icon">❌</span>
                                    <div class="stat-content">
                                        <span class="stat-label">失败</span>
                                        <span class="stat-value" data-stat="failed">0</span>
                                    </div>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-icon">⏱️</span>
                                    <div class="stat-content">
                                        <span class="stat-label">用时</span>
                                        <span class="stat-value" data-stat="elapsed">0秒</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 错误信息面板 -->
                        <div class="sync-errors-panel notion-wp-hidden">
                            <div class="errors-header">
                                <h5>错误信息</h5>
                                <button type="button" class="button button-link errors-toggle">
                                    <span class="dashicons dashicons-arrow-down"></span>
                                </button>
                            </div>
                            <div class="errors-content">
                                <ul class="errors-list"></ul>
                                <div class="errors-actions">
                                    <button type="button" class="button button-secondary retry-failed-btn">
                                        重试失败项
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        /**
         * 生成简洁步骤指示器HTML
         */
        generateStepsHTML() {
            return SYNC_STEPS.map((step, index) => `
                <div class="step-item" data-step="${step.id}">
                    <div class="step-indicator">
                        <div class="step-circle">
                            <span class="step-icon">${step.icon}</span>
                        </div>
                        ${index < SYNC_STEPS.length - 1 ? '<div class="step-connector"></div>' : ''}
                    </div>
                    <div class="step-label">${step.name}</div>
                </div>
            `).join('');
        }
        
        /**
         * 绑定进度事件
         */
        bindProgressEvents() {
            if (!this.container) return;
            
            // 取消按钮
            this.container.find('.sync-cancel-btn').on('click', (e) => {
                e.preventDefault();
                this.handleCancelSync();
            });
            
            // 错误面板切换
            this.container.find('.errors-toggle').on('click', (e) => {
                e.preventDefault();
                this.toggleErrorsPanel();
            });
            
            // 重试失败项
            this.container.find('.retry-failed-btn').on('click', (e) => {
                e.preventDefault();
                this.handleRetryFailed();
            });
        }
        
        /**
         * 初始化步骤指示器
         */
        initializeStepsIndicator() {
            if (!this.container) return;
            
            // 重置所有步骤状态
            this.container.find('.step-item').removeClass('active completed failed');
        }
        
        /**
         * 开始进度更新
         */
        startProgressUpdates() {
            if (this.updateInterval) {
                clearInterval(this.updateInterval);
            }
            
            // 立即执行一次更新
            this.fetchAndUpdateProgress();
            
            // 设置定期更新
            this.updateInterval = setInterval(() => {
                this.fetchAndUpdateProgress();
            }, this.updateFrequency);
            
            console.log(`⏰ [进度管理器] 开始定期更新 (${this.updateFrequency}ms)`);
        }
        
        /**
         * 停止进度更新
         */
        stopProgressUpdates() {
            if (this.updateInterval) {
                clearInterval(this.updateInterval);
                this.updateInterval = null;
            }
            
            console.log('⏹️ [进度管理器] 停止进度更新');
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
         * 更新进度显示
         * @param {Object} progressData 进度数据
         */
        updateProgress(progressData) {
            if (!this.container || !progressData) return;
            
            const { progress = {}, currentStep, status, timing = {}, errors = [] } = progressData;
            
            // 更新主进度条
            this.updateMainProgress(progress);
            
            // 更新步骤指示器
            this.updateStepsIndicator(currentStep, status);
            
            // 更新统计信息
            this.updateStats(progress, timing);
            
            // 更新错误信息
            this.updateErrors(errors);
            
            // 检查是否完成
            if (status === 'completed' || status === 'failed' || status === 'cancelled') {
                this.handleSyncComplete(status, progressData);
            }
            
            console.log(`📊 [进度管理器] 进度更新: ${progress.percentage || 0}% (${currentStep || 'unknown'})`);
        }
        
        /**
         * 更新主进度条
         */
        updateMainProgress(progress) {
            const percentage = Math.min(100, Math.max(0, progress.percentage || 0));
            
            // 更新进度条宽度
            this.container.find('.progress-fill').css('width', percentage + '%');
            
            // 更新百分比文本
            this.container.find('.progress-percentage').text(percentage.toFixed(1) + '%');
        }
        
        /**
         * 更新步骤指示器
         */
        updateStepsIndicator(currentStep, status) {
            if (!currentStep) return;
            
            const currentStepIndex = SYNC_STEPS.findIndex(step => step.id === currentStep);
            
            this.container.find('.step-item').each((index, element) => {
                const $step = $(element);
                
                $step.removeClass('active completed failed');
                
                if (index < currentStepIndex) {
                    $step.addClass('completed');
                } else if (index === currentStepIndex) {
                    $step.addClass(status === 'failed' ? 'failed' : 'active');
                }
            });
            
            // 更新当前步骤文本
            const stepInfo = SYNC_STEPS[currentStepIndex];
            if (stepInfo) {
                this.container.find('.current-step-text').text(`${stepInfo.icon} ${stepInfo.name}`);
            }
        }
        
        /**
         * 更新统计信息
         */
        updateStats(progress, timing) {
            const { total = 0, processed = 0, success = 0, failed = 0 } = progress;
            const { elapsedTime = 0, estimatedRemaining = 0 } = timing;
            
            // 更新统计数值
            this.container.find('[data-stat="processed"]').text(`${processed}/${total}`);
            this.container.find('[data-stat="success"]').text(success);
            this.container.find('[data-stat="failed"]').text(failed);
            this.container.find('[data-stat="elapsed"]').text(this.formatDuration(elapsedTime));
            
            // 更新预估时间
            if (estimatedRemaining > 0) {
                this.container.find('.progress-eta').text(`预计剩余: ${this.formatDuration(estimatedRemaining)}`);
            } else {
                this.container.find('.progress-eta').text('');
            }
        }
        
        /**
         * 更新错误信息
         */
        updateErrors(errors) {
            if (!errors || errors.length === 0) {
                this.container.find('.sync-errors-panel').addClass('notion-wp-hidden');
                return;
            }
            
            const $errorsList = this.container.find('.errors-list');
            $errorsList.empty();
            
            errors.slice(-10).forEach(error => { // 只显示最近10个错误
                const $errorItem = $(`
                    <li class="error-item">
                        <span class="error-time">${new Date(error.timestamp * 1000).toLocaleTimeString()}</span>
                        <span class="error-message">${error.message}</span>
                    </li>
                `);
                $errorsList.append($errorItem);
            });
            
            this.container.find('.sync-errors-panel').removeClass('notion-wp-hidden');
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
            
            // 延迟隐藏进度界面
            setTimeout(() => {
                this.hideProgress();
            }, status === 'completed' ? 3000 : 5000);
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
                
                console.log(`👁️ [进度管理器] 页面可见性变化，更新频率调整为 ${newFrequency}ms`);
            }
        }
        
        /**
         * 处理取消同步
         */
        handleCancelSync() {
            if (!this.taskId) return;
            
            if (!confirm('确定要取消当前同步操作吗？')) {
                return;
            }
            
            // 发送取消请求
            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'notion_to_wordpress_cancel_sync',
                    nonce: notionToWp.nonce,
                    task_id: this.taskId
                },
                success: (response) => {
                    if (response.success) {
                        console.log('🛑 [进度管理器] 同步已取消');
                        this.handleSyncComplete('cancelled', { progress: {} });
                    } else {
                        console.error('❌ [进度管理器] 取消同步失败:', response.data?.message);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('❌ [进度管理器] 取消同步网络错误:', error);
                }
            });
        }
        
        /**
         * 切换错误面板
         */
        toggleErrorsPanel() {
            const $panel = this.container.find('.errors-content');
            const $toggle = this.container.find('.errors-toggle .dashicons');
            
            $panel.slideToggle(200);
            $toggle.toggleClass('dashicons-arrow-down dashicons-arrow-up');
        }
        
        /**
         * 处理重试失败项
         */
        handleRetryFailed() {
            if (!this.taskId) return;
            
            // 发送重试请求
            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'notion_to_wordpress_retry_failed',
                    nonce: notionToWp.nonce,
                    task_id: this.taskId
                },
                success: (response) => {
                    if (response.success) {
                        console.log('🔄 [进度管理器] 重试失败项已启动');
                        // 重新开始进度更新
                        this.startProgressUpdates();
                    } else {
                        console.error('❌ [进度管理器] 重试失败:', response.data?.message);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('❌ [进度管理器] 重试网络错误:', error);
                }
            });
        }
        
        /**
         * 格式化持续时间
         */
        formatDuration(milliseconds) {
            if (!milliseconds || milliseconds < 0) return '0秒';
            
            const seconds = Math.floor(milliseconds / 1000);
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);
            
            if (hours > 0) {
                return `${hours}小时${minutes % 60}分钟`;
            } else if (minutes > 0) {
                return `${minutes}分钟${seconds % 60}秒`;
            } else {
                return `${seconds}秒`;
            }
        }
        
        /**
         * 销毁进度管理器
         */
        destroy() {
            this.stopProgressUpdates();
            this.hideProgress();
            
            // 移除事件监听
            document.removeEventListener('visibilitychange', this.handleVisibilityChange);
            
            console.log('🗑️ [进度管理器] 已销毁');
        }
    };

})(jQuery);
