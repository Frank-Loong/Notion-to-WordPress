/**
 * 管理界面交互脚本
 *
 * 处理 Notion to WordPress 插件后台页面的所有用户交互，包括表单提交、AJAX 请求、标签页切换和动态内容更新。
 *
 * @since 1.0.8
 * @version 2.0.0-beta.1
 * @package Notion_To_WordPress
 * @author Frank-Loong
 * @license GPL-3.0-or-later
 * @link https://github.com/Frank-Loong/Notion-to-WordPress
 */

/**
 * 性能优化工具函数集合
 * 提供防抖、节流、错误处理、按钮状态管理等通用功能
 */
const NotionUtils = {
    /**
     * 防抖函数 - 延迟执行函数，在指定时间内多次调用只执行最后一次
     * @param {Function} func - 要防抖的函数
     * @param {number} wait - 延迟时间（毫秒）
     * @param {boolean} immediate - 是否立即执行
     * @returns {Function} 防抖后的函数
     */
    debounce: function(func, wait, immediate) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                timeout = null;
                if (!immediate) func.apply(this, args);
            };
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func.apply(this, args);
        };
    },

    // 节流函数
    throttle: function(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },

    // 统一的AJAX错误处理
    handleAjaxError: function(xhr, status, error, context = '') {
        console.error(`AJAX Error ${context}:`, { xhr, status, error });
        const message = xhr.responseJSON?.data?.message || error || '网络请求失败';
        showToast(message, 'error');
    },

    // 按钮状态管理
    setButtonLoading: function($button, loading = true) {
        if (loading) {
            $button.prop('disabled', true).addClass('loading');
            const originalText = $button.data('original-text') || $button.text();
            $button.data('original-text', originalText);
            if ($button.is('input')) {
                $button.val('处理中...');
            } else {
                $button.text('处理中...');
            }
        } else {
            $button.prop('disabled', false).removeClass('loading');
            const originalText = $button.data('original-text');
            if (originalText) {
                if ($button.is('input')) {
                    $button.val(originalText);
                } else {
                    $button.text(originalText);
                }
            }
        }
    },

    // 进度管理工具
    updateProgress: function(percentage, stepText) {
        const $progress = $('#sync-progress');
        const $fill = $progress.find('.progress-fill');
        const $step = $progress.find('.current-step');
        const $percentage = $progress.find('.progress-percentage');

        if ($progress.hasClass('notion-wp-hidden')) {
            $progress.removeClass('notion-wp-hidden').slideDown(300);
        }

        $fill.css('width', percentage + '%');
        $step.text(stepText || '处理中...');
        $percentage.text(Math.round(percentage) + '%');
    },

    hideProgress: function() {
        const $progress = $('#sync-progress');
        $progress.slideUp(300, function() {
            $(this).addClass('notion-wp-hidden');
            $(this).find('.progress-fill').css('width', '0%');
            $(this).find('.current-step').text('准备同步...');
            $(this).find('.progress-percentage').text('0%');
        });
    },

    setSyncButtonState: function($button, state, message) {
        $button.removeClass('loading success error');

        switch(state) {
            case 'loading':
                this.setButtonLoading($button, true);
                break;
            case 'success':
                this.setButtonLoading($button, false);
                $button.addClass('success');
                if (message) $button.find('.button-text').text(message);
                setTimeout(() => {
                    $button.removeClass('success');
                    const originalText = $button.data('original-text');
                    if (originalText) $button.find('.button-text').text(originalText);
                }, 3000);
                break;
            case 'error':
                this.setButtonLoading($button, false);
                $button.addClass('error');
                if (message) $button.find('.button-text').text(message);
                setTimeout(() => {
                    $button.removeClass('error');
                    const originalText = $button.data('original-text');
                    if (originalText) $button.find('.button-text').text(originalText);
                }, 3000);
                break;
            default:
                this.setButtonLoading($button, false);
        }
    },

    // 表单验证工具
    validateInput: function($input, type) {
        const value = $input.val().trim();
        const $feedback = $input.closest('.input-with-validation').find('.validation-feedback');

        let isValid = false;
        let message = '';
        let level = 'error';

        switch(type) {
            case 'api-key':
                if (!value) {
                    message = 'API密钥不能为空';
                } else if (value.length < 30 || value.length > 80) {
                    message = 'API密钥长度可能不正确，请检查是否完整';
                    level = 'warning';
                } else if (!/^[a-zA-Z0-9_-]+$/.test(value)) {
                    message = 'API密钥格式可能不正确，应只包含字母、数字、下划线和连字符';
                    level = 'warning';
                } else {
                    message = 'API密钥格式正确';
                    level = 'success';
                    isValid = true;
                }
                break;

            case 'database-id':
                if (!value) {
                    message = '数据库ID不能为空';
                } else if (value.length !== 32) {
                    message = '数据库ID长度应为32位字符';
                } else if (!/^[a-f0-9]{32}$/i.test(value)) {
                    message = '数据库ID格式不正确，应为32位十六进制字符';
                } else {
                    message = '数据库ID格式正确';
                    level = 'success';
                    isValid = true;
                }
                break;
        }

        // 更新反馈显示
        $feedback.removeClass('success error warning').addClass(level).text(message);
        $input.removeClass('valid invalid warning').addClass(isValid ? 'valid' : (level === 'warning' ? 'warning' : 'invalid'));

        return isValid;
    },


};

// ==================== 同步状态管理器 ====================
const SyncStatusManager = {
    // 状态存储键
    STORAGE_KEY: 'notion_wp_sync_status',

    // 检查间隔（毫秒）
    CHECK_INTERVAL_VISIBLE: 5000,    // 页面可见时：5秒
    CHECK_INTERVAL_HIDDEN: 30000,    // 页面隐藏时：30秒

    // 内部状态
    checkTimer: null,
    isPageVisible: true,
    currentSyncId: null,

    /**
     * 初始化同步状态管理器
     */
    init: function() {
        this.setupVisibilityHandling();
        this.restoreSyncStatus();
        this.startStatusMonitoring();

        console.log('🔄 [同步状态管理器] 已初始化');
    },

    /**
     * 设置页面可见性处理
     */
    setupVisibilityHandling: function() {
        const self = this;

        // 监听页面可见性变化
        document.addEventListener('visibilitychange', function() {
            self.isPageVisible = !document.hidden;

            if (self.isPageVisible) {
                console.log('📱 [页面可见性] 页面重新可见，立即检查同步状态');
                self.checkSyncStatus();
                self.adjustCheckInterval();
            } else {
                console.log('📱 [页面可见性] 页面隐藏，降低检查频率');
                self.adjustCheckInterval();
            }
        });

        // 监听页面焦点变化
        window.addEventListener('focus', function() {
            console.log('🎯 [页面焦点] 页面重新获得焦点，检查同步状态');
            self.checkSyncStatus();
        });
    },

    /**
     * 保存同步状态
     */
    saveSyncStatus: function(syncData) {
        const statusData = {
            isActive: true,
            syncType: syncData.syncType || 'unknown',
            startTime: Date.now(),
            syncId: this.generateSyncId(),
            ...syncData
        };

        this.currentSyncId = statusData.syncId;
        localStorage.setItem(this.STORAGE_KEY, JSON.stringify(statusData));

        console.log('💾 [状态保存] 同步状态已保存:', statusData);
    },

    /**
     * 清除同步状态
     */
    clearSyncStatus: function() {
        localStorage.removeItem(this.STORAGE_KEY);
        this.currentSyncId = null;

        console.log('🗑️ [状态清除] 同步状态已清除');
    },

    /**
     * 恢复同步状态
     */
    restoreSyncStatus: function() {
        const savedStatus = localStorage.getItem(this.STORAGE_KEY);

        if (savedStatus) {
            try {
                const statusData = JSON.parse(savedStatus);

                // 检查状态是否过期（超过1小时自动清除）
                const elapsed = Date.now() - statusData.startTime;
                if (elapsed > 3600000) { // 1小时
                    this.clearSyncStatus();
                    return;
                }

                console.log('🔄 [状态恢复] 发现保存的同步状态:', statusData);
                this.currentSyncId = statusData.syncId;
                this.showSyncStatusRecovery(statusData);

            } catch (e) {
                console.error('❌ [状态恢复] 解析保存状态失败:', e);
                this.clearSyncStatus();
            }
        }
    },

    /**
     * 显示同步状态恢复提示
     */
    showSyncStatusRecovery: function(statusData) {
        const $ = jQuery;
        const elapsed = Math.floor((Date.now() - statusData.startTime) / 1000);
        const elapsedText = elapsed < 60 ? `${elapsed}秒` : `${Math.floor(elapsed / 60)}分${elapsed % 60}秒`;

        // 显示恢复提示
        const $recoveryNotice = $(`
            <div class="notice notice-info is-dismissible" id="sync-status-recovery">
                <p>
                    <strong>🔄 检测到进行中的同步操作</strong><br>
                    同步类型：${statusData.syncType || '未知'}<br>
                    已运行：${elapsedText}<br>
                    <button type="button" class="button button-secondary" id="check-sync-status-now">立即检查状态</button>
                    <button type="button" class="button button-link" id="clear-sync-status">清除状态</button>
                </p>
            </div>
        `);

        $('.wrap.notion-wp-admin').prepend($recoveryNotice);

        // 绑定事件
        $('#check-sync-status-now').on('click', () => {
            this.checkSyncStatus();
            $recoveryNotice.fadeOut();
        });

        $('#clear-sync-status').on('click', () => {
            this.clearSyncStatus();
            $recoveryNotice.fadeOut();
        });
    },

    /**
     * 生成同步ID
     */
    generateSyncId: function() {
        return 'sync_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    },

    /**
     * 调整检查间隔
     */
    adjustCheckInterval: function() {
        if (this.checkTimer) {
            clearInterval(this.checkTimer);
        }

        const interval = this.isPageVisible ? this.CHECK_INTERVAL_VISIBLE : this.CHECK_INTERVAL_HIDDEN;

        this.checkTimer = setInterval(() => {
            this.checkSyncStatus();
        }, interval);

        console.log(`⏱️ [检查间隔] 已调整为 ${interval/1000}秒 (页面${this.isPageVisible ? '可见' : '隐藏'})`);
    },

    /**
     * 开始状态监控
     */
    startStatusMonitoring: function() {
        this.adjustCheckInterval();
    },

    /**
     * 停止状态监控
     */
    stopStatusMonitoring: function() {
        if (this.checkTimer) {
            clearInterval(this.checkTimer);
            this.checkTimer = null;
        }
    },

    /**
     * 检查同步状态
     */
    checkSyncStatus: function() {
        // 如果没有活跃的同步，跳过检查
        if (!this.currentSyncId) {
            return;
        }

        console.log('🔍 [状态检查] 正在检查同步状态...');
        refreshAsyncStatus();
    }
};

// 全局函数：刷新异步状态
function refreshAsyncStatus() {
    const $ = jQuery;
    const $refreshButton = $('#refresh-async-status');

    // 显示加载状态
    $refreshButton.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0; visibility: visible;"></span> 正在刷新状态...');

    // 获取异步状态
    $.ajax({
        url: notionToWp.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'notion_to_wordpress_get_async_status',
            nonce: notionToWp.nonce
        },
        success: function(response) {
            if (response.success) {
                updateAsyncStatusDisplay(response.data.status);

                // 检查同步是否完成
                if (response.data.status && response.data.status.status === 'idle') {
                    SyncStatusManager.clearSyncStatus();
                }
            } else {
                showStatusError('async', '获取异步状态失败: ' + (response.data.message || '未知错误'));
            }
        },
        error: function(xhr, status, error) {
            NotionUtils.handleAjaxError(xhr, status, error, '获取异步状态');
            showStatusError('async', '网络错误，无法获取异步状态');
        },
        complete: function() {
            // 恢复按钮状态
            $refreshButton.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> 刷新状态');
        }
    });

    // 获取队列状态
    $.ajax({
        url: notionToWp.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'notion_to_wordpress_get_queue_status',
            nonce: notionToWp.nonce
        },
        success: function(response) {
            if (response.success) {
                updateQueueStatusDisplay(response.data.status);
            } else {
                showStatusError('queue', '获取队列状态失败: ' + (response.data.message || '未知错误'));
            }
        },
        error: function(xhr, status, error) {
            NotionUtils.handleAjaxError(xhr, status, error, '获取队列状态');
            showStatusError('queue', '网络错误，无法获取队列状态');
        },
        complete: function() {
            // 恢复按钮状态
            $refreshButton.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> 刷新状态');
        }
    });
}

// 辅助函数：显示状态错误
function showStatusError(type, message) {
    const $ = jQuery;
    const containerId = type === 'async' ? '#async-status-container' : '#queue-status-container';
    const $container = $(containerId);

    $container.html('<div class="error-message" style="color: #d63638; padding: 10px; background: #fef7f7; border: 1px solid #d63638; border-radius: 4px;">' + message + '</div>');
}

// 辅助函数：更新异步状态显示
function updateAsyncStatusDisplay(statusData) {
    const $ = jQuery;
    const $container = $('#async-status-container');

    // 更新状态指示器
    const statusValue = typeof statusData === 'object' ? statusData.status : statusData;
    const $statusDisplay = $container.find('.async-status-display');

    // 移除所有状态类
    $statusDisplay.removeClass('status-idle status-running status-paused status-error');

    // 添加新的状态类和文本
    let statusClass = 'status-idle';
    let statusText = '空闲';

    if (statusValue === 'running') {
        statusClass = 'status-running';
        statusText = '运行中';
    } else if (statusValue === 'paused') {
        statusClass = 'status-paused';
        statusText = '已暂停';
    } else if (statusValue === 'error') {
        statusClass = 'status-error';
        statusText = '错误';
    }

    $statusDisplay.addClass(statusClass);
    $statusDisplay.find('.status-value').text(statusText);

    // 更新详细信息（如果有的话）
    if (typeof statusData === 'object' && statusData.operation) {
        // 这里可以添加更多的详细信息更新逻辑
        console.log('异步状态详情:', statusData);
    }
}

// 辅助函数：更新队列状态显示
function updateQueueStatusDisplay(queueData) {
    const $ = jQuery;
    const $container = $('#queue-status-container');

    // 更新各个统计数字
    const stats = ['total_tasks', 'pending', 'processing', 'completed', 'failed'];
    stats.forEach(function(stat) {
        const value = queueData[stat] || queueData[stat === 'total_tasks' ? 'total' : stat] || 0;
        $container.find('.queue-stat-item').each(function() {
            const $item = $(this);
            const label = $item.find('.stat-label').text();

            if ((stat === 'total_tasks' && label.includes('总任务')) ||
                (stat === 'pending' && label.includes('等待中')) ||
                (stat === 'processing' && label.includes('处理中')) ||
                (stat === 'completed' && label.includes('已完成')) ||
                (stat === 'failed' && label.includes('失败'))) {
                $item.find('.stat-value').text(value);
            }
        });
    });
}

// 全局函数：显示异步状态（保持向后兼容）
function displayAsyncStatus(statusData) {
    // 直接调用新的更新函数
    updateAsyncStatusDisplay(statusData);
}

// 全局函数：显示队列状态（保持向后兼容）
function displayQueueStatus(status) {
    // 直接调用新的更新函数
    updateQueueStatusDisplay(status);
}

jQuery(document).ready(function($) {
    const $overlay = $('#loading-overlay');

    // 初始化同步状态管理器
    SyncStatusManager.init();

    // 初始化进度管理器
    window.syncProgressManager = new SyncProgressManager();

    // 页面加载时获取统计信息
    if ($('.notion-stats-grid').length > 0) {
      fetchStats();
    }

    // 验证必要的安全参数
    if (!notionToWp || !notionToWp.ajax_url || typeof notionToWp.ajax_url !== 'string' || !notionToWp.nonce || typeof notionToWp.nonce !== 'string') {
      console.error(notionToWp.i18n.security_missing || '安全验证参数缺失或无效');
      // 安全检查失败，禁用所有AJAX功能
      $('.notion-wp-admin-page').addClass('security-check-failed');
      return false;
    }

    // 记录页面加载时的原始语言设置，用于检测变化
    let originalLanguage = $('#plugin_language').val();

    // 记录页面加载时的原始webhook设置，用于检测变化
    let originalWebhookEnabled = $('#webhook_enabled').is(':checked');

    // 实时表单验证
    $('.notion-wp-validated-input').on('input blur', NotionUtils.debounce(function() {
        const $input = $(this);
        const validationType = $input.data('validation');

        if (validationType) {
            NotionUtils.validateInput($input, validationType);
        }
    }, 500));

    // 监听语言选择器的变化，使用防抖优化
    $('#plugin_language').on('change', NotionUtils.debounce(function() {
        // 🚀 性能优化：移除未使用的变量和调试日志
        // 可以在这里添加实时预览或验证逻辑
    }, 300));

    // 监听webhook设置的变化，使用防抖优化
    $('#webhook_enabled').on('change', NotionUtils.debounce(function() {
        // 🚀 性能优化：移除未使用的变量和调试日志
        // 可以在这里添加实时预览或验证逻辑
    }, 300));

    // 标签切换动画效果
    $('.notion-wp-menu-item').on('click', function(e) {
        e.preventDefault();
        const tabId = $(this).data('tab');

        $('.notion-wp-menu-item').removeClass('active');
        $('.notion-wp-tab-content').removeClass('active');

        $(this).addClass('active');

        // 添加淡入效果
        $('#' + tabId).addClass('active').hide().fadeIn(300);

        // 保存用户的标签选择到本地存储
        localStorage.setItem('notion_wp_active_tab', tabId);

        // 当切换到性能监控tab时，重新初始化相关功能
        if (tabId === 'performance') {
            setTimeout(function() {
                // 重新检查异步状态
                if ($('#async-status-container').length > 0) {
                    refreshAsyncStatus();
                }
            }, 350); // 等待淡入动画完成后再执行
        }
    });
    
    // 从本地存储中恢复上次选择的标签，如果没有则默认激活性能监控标签页
    const lastActiveTab = localStorage.getItem('notion_wp_active_tab');
    if (lastActiveTab) {
        $('.notion-wp-menu-item[data-tab="' + lastActiveTab + '"]').trigger('click');
    } else {
        // 默认激活性能监控标签页
        console.log('Notion to WordPress: 默认激活性能监控标签页');
        $('.notion-wp-menu-item[data-tab="performance"]').trigger('click');
    }
    
    // 显示/隐藏密码
    $('.show-hide-password').on('click', function() {
        const input = $(this).prev('input[type="password"], input[type="text"]');
        const icon = $(this).find('.dashicons');
        
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            $(this).attr('title', notionToWp.i18n.hide_key);
        } else {
            input.attr('type', 'password');
            icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            $(this).attr('title', notionToWp.i18n.show_key);
        }
    });
    
    // 智能同步（增量同步）
    $('#notion-manual-import').on('click', function(e) {
        e.preventDefault();
        performSync($(this), true, true, notionToWp.i18n.smart_sync); // 增量同步，检查删除
    });

    // 完全同步（全量同步）
    $('#notion-full-import').on('click', function(e) {
        e.preventDefault();
        performSync($(this), false, true, notionToWp.i18n.full_sync); // 全量同步，检查删除
    });

    // 统一的同步处理函数
    function performSync(button, incremental, checkDeletions, syncTypeName) {
        // 确认操作
        const confirmMessage = incremental ?
            notionToWp.i18n.confirm_smart_sync :
            notionToWp.i18n.confirm_full_sync;

        if (!confirm(confirmMessage)) {
            return;
        }

        const originalHtml = button.html();
        button.prop('disabled', true).html('<span class="spinner is-active"></span> ' + syncTypeName + notionToWp.i18n.syncing);

        // 生成任务ID
        const taskId = 'sync_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);

        // 保存同步状态
        SyncStatusManager.saveSyncStatus({
            syncType: syncTypeName,
            incremental: incremental,
            checkDeletions: checkDeletions,
            buttonId: button.attr('id'),
            taskId: taskId
        });

        // 显示进度界面
        if (window.syncProgressManager) {
            window.syncProgressManager.showProgress(taskId, syncTypeName);
        }

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_manual_sync',
                nonce: notionToWp.nonce,
                incremental: incremental,
                check_deletions: checkDeletions,
                task_id: taskId // 传递任务ID到后端
            },
            success: function(response) {
                const message = response.success ? response.data.message : response.data.message;
                const status = response.success ? 'success' : 'error';

                if (response.success) {
                    console.log('✅ [同步] 同步成功:', message);

                    // 显示成功消息
                    showModal(message, status);

                    // 隐藏进度界面
                    if (window.syncProgressManager) {
                        setTimeout(() => {
                            window.syncProgressManager.hideProgress();
                        }, 2000); // 2秒后自动隐藏
                    }

                    // 清除同步状态
                    SyncStatusManager.clearSyncStatus();

                    // 刷新统计信息
                    fetchStats();
                } else {
                    // 显示错误消息
                    showModal(message, status);

                    // 隐藏进度界面
                    if (window.syncProgressManager) {
                        window.syncProgressManager.hideProgress();
                    }

                    // 清除同步状态
                    SyncStatusManager.clearSyncStatus();
                }
            },
            error: function(xhr, status, error) {
                const errorMessage = syncTypeName + notionToWp.i18n.sync_failed;
                showModal(errorMessage, 'error');

                console.error('❌ [同步] 网络错误:', error);

                // 隐藏进度界面
                if (window.syncProgressManager) {
                    window.syncProgressManager.hideProgress();
                }

                // 清除同步状态
                SyncStatusManager.clearSyncStatus();
            },
            complete: function() {
                button.prop('disabled', false).html(originalHtml);
            }
        });
    }
    
    // 测试连接
    $('#notion-test-connection').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const api_key = $('#notion_to_wordpress_api_key').val();
        const database_id = $('#notion_to_wordpress_database_id').val();
        
        if (!api_key || !database_id) {
            showModal(notionToWp.i18n.fill_fields, 'error');
            
            // 高亮空字段
            if (!api_key) {
                $('#notion_to_wordpress_api_key').addClass('error').focus();
                setTimeout(function() {
                    $('#notion_to_wordpress_api_key').removeClass('error');
                }, 2000);
            }
            if (!database_id) {
                $('#notion_to_wordpress_database_id').addClass('error').focus();
                setTimeout(function() {
                    $('#notion_to_wordpress_database_id').removeClass('error');
                }, 2000);
            }
            
            return;
        }
        
        button.prop('disabled', true).html('<span class="spinner is-active"></span> ' + notionToWp.i18n.testing);

        // 保存测试连接状态
        SyncStatusManager.saveSyncStatus({
            syncType: '测试连接',
            buttonId: button.attr('id')
        });

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_test_connection',
                nonce: notionToWp.nonce,
                api_key: api_key,
                database_id: database_id
            },
            success: function(response) {
                var message = response.success ? response.data.message : response.data.message;
                var status = response.success ? 'success' : 'error';

                showModal(message, status);

                // 清除状态
                SyncStatusManager.clearSyncStatus();
            },
            error: function() {
                showModal(notionToWp.i18n.test_error, 'error');

                // 清除状态
                SyncStatusManager.clearSyncStatus();
            },
            complete: function() {
                button.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> ' + notionToWp.i18n.test_connection);
            }
        });
    });
    
    // 全局复制函数
    window.copyTextToClipboard = function(text, callback) {
        if (!text) {
            if (callback) callback(false, notionToWp.i18n.copy_text_empty);
            return;
        }
        
        try {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text)
                    .then(() => {
                        if (callback) callback(true);
                    })
                    .catch(err => {
                        console.error(notionToWp.i18n.copy_failed || '使用 Clipboard API 复制失败:', err);
                        fallbackCopyToClipboard(text, callback);
                    });
            } else {
                fallbackCopyToClipboard(text, callback);
            }
        } catch (e) {
            console.error(notionToWp.i18n.copy_failed || '复制过程中发生错误:', e);
            if (callback) callback(false, e.message);
        }
    };
    
    // 现代化复制功能 - 优先使用Clipboard API
    async function copyToClipboard(text) {
        try {
            // 优先使用现代Clipboard API
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return { success: true };
            } else {
                // 降级到传统方法
                return fallbackCopyToClipboard(text);
            }
        } catch (error) {
            console.error('复制失败:', error);
            return { success: false, error: error.message };
        }
    }

    // 备用复制方法
    function fallbackCopyToClipboard(text) {
        try {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();

            const successful = document.execCommand('copy');
            document.body.removeChild(textarea);

            return {
                success: successful,
                error: successful ? null : 'execCommand 复制命令失败'
            };
        } catch (error) {
            console.error('备用复制方法错误:', error);
            return { success: false, error: error.message };
        }
    }
    
    // 复制到剪贴板 - 使用现代化复制功能和防抖
    $('.copy-to-clipboard').on('click', NotionUtils.debounce(async function(e) {
        e.preventDefault();
        const $button = $(this);
        const targetSelector = $button.data('clipboard-target');

        if (!targetSelector) {
            console.error('复制按钮缺少 data-clipboard-target 属性');
            showToast('复制失败：缺少目标元素', 'error');
            return;
        }

        const $target = $(targetSelector);

        if ($target.length === 0) {
            console.error('未找到目标元素:', targetSelector);
            showToast('复制失败：未找到目标元素', 'error');
            return;
        }

        const textToCopy = $target.val() || $target.text();

        if (!textToCopy.trim()) {
            showToast('没有内容可复制', 'warning');
            return;
        }

        // 设置按钮加载状态
        NotionUtils.setButtonLoading($button, true);

        try {
            const result = await copyToClipboard(textToCopy);
            if (result.success) {
                showToast('已复制到剪贴板', 'success');
                // 添加视觉反馈
                $button.addClass('copied');
                setTimeout(() => $button.removeClass('copied'), 2000);
            } else {
                showToast('复制失败: ' + (result.error || '未知错误'), 'error');
            }
        } catch (error) {
            console.error('复制操作失败:', error);
            showToast('复制失败: ' + error.message, 'error');
        } finally {
            NotionUtils.setButtonLoading($button, false);
        }
    }, 300));
    
    // 清除日志按钮点击事件
    $('#clear-logs-button').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(notionToWp.i18n.confirm_clear_logs)) {
            return;
        }
        
        const button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> ' + notionToWp.i18n.clearing);
        
        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_clear_logs',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                var message = response.success ? response.data.message : (response.data.message || notionToWp.i18n.unknown_error);
                var status = response.success ? 'success' : 'error';
                
                showModal(message, status);
                
                if (response.success) {
                    $('#log-file-selector').empty();
                    $('#log-viewer').val('');
                    // location.reload();
                }
            },
            error: function() {
                showModal(notionToWp.i18n.clear_error, 'error');
            },
            complete: function() {
                button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> ' + notionToWp.i18n.clear_logs);
            }
        });
    });
    
    // 查看日志
    $('#view-log-button').on('click', function() {
        const logFile = $('#log-file-selector').val();
        const viewer = $('#log-viewer');
        const button = $(this);

        if (!logFile) {
            viewer.val(notionToWp.i18n.select_log_file);
            return;
        }

        button.prop('disabled', true);
        viewer.val(notionToWp.i18n.loading_logs);

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_view_log',
                nonce: notionToWp.nonce,
                file: logFile
            },
            success: function(response) {
                if (response.success) {
                    viewer.val(response.data);
                } else {
                    viewer.val(notionToWp.i18n.load_logs_failed + response.data.message);
                }
            },
            error: function() {
                viewer.val(notionToWp.i18n.log_request_error);
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    // 全局显示消息函数
    window.showModal = function(message, status) {
        const toast = $('<div class="notion-wp-toast ' + (status || 'info') + '"></div>');
        const icon = $('<div class="notion-wp-toast-icon"></div>');
        const content = $('<div class="notion-wp-toast-content">' + message + '</div>');
        const close = $('<button class="notion-wp-toast-close"><span class="dashicons dashicons-no-alt"></span></button>');
        
        // 根据状态设置 Emoji 图标
        let emoji = 'ℹ️';
        if (status === 'success') {
            emoji = '✅';
        } else if (status === 'error') {
            emoji = '❌';
        }
        icon.text(emoji);
        
        toast.append(icon).append(content).append(close);
        
        // 添加到页面
        $('body').append(toast);
        
        // 显示动画
        setTimeout(function() {
            toast.addClass('show');
        }, 10);
        
        // 3秒后自动关闭
        const timeout = setTimeout(function() {
            closeToast();
        }, 3000);
        
        // 点击关闭按钮
        close.on('click', function() {
            clearTimeout(timeout);
            closeToast();
        });
        
        function closeToast() {
            toast.removeClass('show');
            setTimeout(function() {
                toast.remove();
            }, 300);
        }
    };
    
    // 显示/隐藏导入频率选项 - 使用防抖和动画优化
    $('#notion_to_wordpress_auto_import').on('change', NotionUtils.debounce(function() {
        const $scheduleField = $('#auto_import_schedule_field');
        const isChecked = $(this).is(':checked');

        if (isChecked) {
            $scheduleField.slideDown(200);
        } else {
            $scheduleField.slideUp(200);
        }
    }, 200));



    // 刷新单个页面
    $('table').on('click', '.refresh-single', function (e) {
      e.preventDefault();
      const pageId = $(this).data('page-id');
      
      // 验证页面ID和安全参数
      if (!pageId || typeof pageId !== 'string' || pageId.trim() === '') {
        showModal(notionToWp.i18n.invalid_page_id, 'error');
        return;
      }
      
      if (!notionToWp.nonce || !notionToWp.ajax_url) {
        showModal(notionToWp.i18n.security_missing, 'error');
        return;
      }
      
      $overlay.fadeIn(300);

      $.ajax({
        url: notionToWp.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'notion_to_wordpress_refresh_single',
          nonce: notionToWp.nonce,
          page_id: pageId
        },
        timeout: 60000, // 1分钟超时
        success: function(resp) {
          $overlay.fadeOut(300);
          if (resp.success) {
            showModal(notionToWp.i18n.page_refreshed, 'success');
            // 刷新统计信息
            fetchStats();
          } else {
            showModal(notionToWp.i18n.refresh_failed + (resp.data?.message || notionToWp.i18n.unknown_error), 'error');
          }
        },
        error: function(xhr, status) {
          $overlay.fadeOut(300);
          let errorMsg = notionToWp.i18n.network_error;
          if (status === 'timeout') {
            errorMsg = notionToWp.i18n.timeout_error;
          } else if (xhr.responseJSON && xhr.responseJSON.data) {
            errorMsg += ' ' + (notionToWp.i18n.details || '详细信息') + ': ' + xhr.responseJSON.data.message;
          }
          showModal(errorMsg, 'error');
        }
      });
    });

    // 获取统计信息
    function fetchStats() {
        $('.notion-stats-grid .stat-card h3, .notion-stats-grid .stat-card span').addClass('loading');
        
        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_get_stats',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    const stats = response.data;
                    $('.stat-imported-count').text(stats.imported_count || 0);
                    $('.stat-published-count').text(stats.published_count || 0);

                    /* 格式化日期字符串，将时间换行展示 */
                    const formatDateTime = (dt) => {
                        if (!dt) return notionToWp.i18n.never;
                        if (dt.indexOf(' ') === -1) return dt; // 无空格，直接返回
                        const firstSpace = dt.indexOf(' ');
                        return dt.slice(0, firstSpace) + '<br>' + dt.slice(firstSpace + 1);
                    };

                    $('.stat-last-update').html(formatDateTime(stats.last_update));
                    $('.stat-next-run').html(formatDateTime(stats.next_run || notionToWp.i18n.not_scheduled));
                } else {
                    showModal(notionToWp.i18n.load_logs_failed + (response.data.message || notionToWp.i18n.unknown_error), 'error');
                }
            },
            error: function() {
                showModal(notionToWp.i18n.stats_error, 'error');
            },
            complete: function() {
                 $('.notion-stats-grid .stat-card h3, .notion-stats-grid .stat-card span').removeClass('loading');
            }
        });
    }

    // 刷新验证令牌 - 使用防抖和优化的按钮状态管理
    $('#refresh-verification-token').on('click', NotionUtils.debounce(function(e) {
        e.preventDefault();

        const $button = $(this);
        const $tokenInput = $('#verification_token');

        // 防止重复点击
        if ($button.prop('disabled')) {
            return;
        }

        // 使用工具函数设置加载状态
        NotionUtils.setButtonLoading($button, true);

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_refresh_verification_token',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    $tokenInput.val(response.data.verification_token || '');
                    if (response.data.verification_token) {
                        showToast(response.data.message || '验证令牌已更新', 'success');
                    } else {
                        showToast('没有新的验证令牌', 'info');
                    }
                } else {
                    showToast(response.data.message || '刷新失败', 'error');
                }
            },
            error: function(xhr, status, error) {
                NotionUtils.handleAjaxError(xhr, status, error, '刷新验证令牌');
            },
            complete: function() {
                // 恢复按钮状态
                NotionUtils.setButtonLoading($button, false);
            }
        });
    }, 500));

    // 表单验证和 AJAX 提交
    $('#notion-to-wordpress-settings-form').on('submit', function(e) {
        e.preventDefault(); // 阻止默认的表单提交

        var $form = $(this);
        // 精确查找保存设置按钮，现在使用正确的ID
        var $submitButton = $('#notion-save-settings');

        // 如果没有找到保存按钮，尝试备用选择器
        if ($submitButton.length === 0) {
            $submitButton = $form.find('input[type="submit"][name="submit"]');
        }

        // 最终验证：确保找到了按钮
        if ($submitButton.length === 0) {
            console.error('Notion to WordPress: 无法找到保存按钮');
            showModal('无法找到保存按钮，请刷新页面重试', 'error');
            return false;
        }

        const originalButtonText = $submitButton.val() || $submitButton.text();

        // 防止重复提交
        if ($submitButton.prop('disabled')) {
            // 🚀 性能优化：移除生产环境不必要的调试日志
            return false;
        }

        // 基础验证
        const apiKey = $('#notion_to_wordpress_api_key').val();
        const dbId = $('#notion_to_wordpress_database_id').val();
        if (!apiKey || !dbId) {
            showModal(notionToWp.i18n.required_fields, 'error');
            if (!apiKey) $('#notion_to_wordpress_api_key').addClass('error');
            if (!dbId) $('#notion_to_wordpress_database_id').addClass('error');
            setTimeout(() => $('.error').removeClass('error'), 2000);
            return;
        }

        // 获取当前语言设置值（用户选择的新值）
        const newLanguage = $('#plugin_language').val();

        // 获取当前webhook设置值（用户选择的新值）
        const newWebhookEnabled = $('#webhook_enabled').is(':checked');

        // 禁用按钮并显示加载状态（只针对保存按钮）
        $submitButton.prop('disabled', true);

        // 根据按钮类型设置文本
        if ($submitButton.is('input')) {
            $submitButton.val(notionToWp.i18n.saving);
        } else {
            $submitButton.text(notionToWp.i18n.saving);
        }

        const formData = new FormData(this);
        formData.set('action', 'save_notion_settings'); // 确保action正确

        // 确保nonce字段存在
        if (!formData.has('notion_to_wordpress_options_nonce')) {
            const nonceField = $form.find('input[name="notion_to_wordpress_options_nonce"]');
            if (nonceField.length) {
                formData.set('notion_to_wordpress_options_nonce', nonceField.val());
            }
        }



        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: formData,
            processData: false, // 告诉jQuery不要处理数据
            contentType: false, // 告诉jQuery不要设置contentType
            success: function(response) {
                if (response.success) {
                    // 检查语言设置是否发生变化（比较原始值和用户选择的新值）
                    var languageChanged = (originalLanguage !== newLanguage);

                    // 检查webhook设置是否发生变化（比较原始值和用户选择的新值）
                    var webhookChanged = (originalWebhookEnabled !== newWebhookEnabled);

                    // 🚀 性能优化：移除生产环境不必要的调试日志

                    // 检查是否需要刷新页面（语言或webhook设置发生变化）
                    var needsRefresh = languageChanged || webhookChanged;

                    if (needsRefresh) {
                        // 设置发生变化，显示消息后刷新页面
                        var refreshReasons = [];
                        if (languageChanged) {
                            refreshReasons.push(notionToWp.i18n.language_settings || '语言设置');
                        }
                        if (webhookChanged) {
                            refreshReasons.push(notionToWp.i18n.webhook_settings || 'Webhook设置');
                        }

                        var refreshMessage = notionToWp.i18n.page_refreshing || '页面即将刷新以应用设置变更...';
                        var fullMessage = notionToWp.i18n.settings_saved + ' ' + refreshMessage.replace((notionToWp.i18n.language_settings || '语言设置'), refreshReasons.join(notionToWp.i18n.and || '和'));
                        showModal(fullMessage, 'success');

                        console.log('Notion to WordPress: Settings changed (' + refreshReasons.join(', ') + '), refreshing page in 1.5 seconds');

                        // 延迟1.5秒后刷新页面，让用户看到成功消息
                        setTimeout(function() {
                            console.log('Notion to WordPress: Refreshing page now');
                            window.location.reload();
                        }, 1500);
                    } else {
                        // 设置没有变化，使用正常的AJAX响应
                        console.log('Notion to WordPress: No critical settings changed, using normal AJAX response');
                        showModal(notionToWp.i18n.settings_saved, 'success');

                        // 更新原始值为当前值，为下次比较做准备
                        originalLanguage = newLanguage;
                        originalWebhookEnabled = newWebhookEnabled;
                        console.log('Notion to WordPress: Updated original values - language:', originalLanguage, 'webhook:', originalWebhookEnabled);

                        // 恢复按钮状态
                        $submitButton.prop('disabled', false);
                        if ($submitButton.is('input')) {
                            $submitButton.val(originalButtonText);
                        } else {
                            $submitButton.text(originalButtonText);
                        }
                    }
                } else {
                    showModal(response.data.message || notionToWp.i18n.unknown_error, 'error');
                    // 恢复按钮状态
                    $submitButton.prop('disabled', false);
                    if ($submitButton.is('input')) {
                        $submitButton.val(originalButtonText);
                    } else {
                        $submitButton.text(originalButtonText);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Notion to WordPress: AJAX保存设置失败', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText,
                    error: error
                });

                let errorMessage = '保存设置时发生网络错误';

                // 详细的错误信息处理
                if (xhr.status === 400) {
                    errorMessage = '请求参数错误 (400)';
                    if (xhr.responseJSON?.data?.message) {
                        errorMessage += '：' + xhr.responseJSON.data.message;
                    }
                } else if (xhr.status === 403) {
                    errorMessage = '权限不足 (403)';
                } else if (xhr.status === 500) {
                    errorMessage = '服务器内部错误 (500)';
                } else if (xhr.status === 0) {
                    errorMessage = '网络连接失败，请检查网络连接';
                } else if (xhr.responseJSON?.data?.message) {
                    errorMessage = xhr.responseJSON.data.message;
                }

                showModal(errorMessage, 'error');

                // 恢复按钮状态
                $submitButton.prop('disabled', false);
                if ($submitButton.is('input')) {
                    $submitButton.val(originalButtonText);
                } else {
                    $submitButton.text(originalButtonText);
                }
            },
            complete: function() {
                // 注意：如果语言发生变化，按钮状态恢复会在页面刷新前处理
                // 如果没有语言变化，按钮状态已在success/error回调中处理
            }
        });
    });

    // 添加CSS样式
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .error { border-color: #d63638 !important; box-shadow: 0 0 0 1px #d63638 !important; }
            .highlight { animation: highlight 1.5s ease-in-out; }
            @keyframes highlight {
                0% { color: var(--notion-primary); }
                50% { color: var(--notion-accent); }
                100% { color: var(--notion-primary); }
            }
            .spin { animation: spin 1.5s infinite linear; display: inline-block; }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `)
        .appendTo('head');

    // 初始化复制按钮
    initCopyButtons();

    // 初始化复制按钮函数
    function initCopyButtons() {
        const copyButtons = $('.copy-to-clipboard');

        copyButtons.each(function() {
            const $btn = $(this);

            // 确保按钮有正确的提示
            if (!$btn.attr('title')) {
                $btn.attr('title', notionToWp.i18n.copy_to_clipboard);
            }
        });
    }
});

// 从 copy_button.js 合并的代码
(function($) {
    $(document).ready(function() {
        // 为代码块添加复制按钮
        $('pre code').each(function() {
            var $code = $(this);
            var $pre = $code.parent('pre');
            
            // 如果没有复制按钮，则添加一个
            if ($pre.find('.copy-button').length === 0) {
                var $button = $('<button class="copy-button"></button>').attr('title', notionToWp.i18n.copy_code);
                $pre.css('position', 'relative').append($button); // 确保pre是相对定位
                
                // 添加复制功能
                $button.on('click', function() {
                    var text = $code.text();
                    // 🚀 性能优化：使用全局现代化复制函数
                    copyToClipboard(text).then(() => {
                        const originalText = $button.text();
                        $button.text(notionToWp.i18n.copied_success);
                        setTimeout(() => $button.text(originalText), 2000);
                    }).catch(() => {
                        alert(notionToWp.i18n.copy_manual);
                    });
                });
            }
        });
        
        // 🚀 性能优化：使用全局的现代化复制函数
        // 重复的函数定义已移除，使用全局 copyToClipboard 函数
    });

    // ==================== 数据库索引管理功能 ====================

    /**
     * 刷新索引状态
     */
    function refreshIndexStatus() {
        const $container = $('#index-status-container');
        const $removeBtn = $('#remove-database-indexes');

        $container.html('<div class="loading-placeholder"><span class="spinner is-active"></span> 正在检查索引状态...</div>');
        $removeBtn.hide();

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'notion_to_wordpress_get_index_status',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    const status = response.data.status;
                    const suggestions = response.data.suggestions;

                    let html = '<div class="index-status-grid">';

                    // 索引状态显示
                    html += '<div class="index-status-item">';
                    html += '<span class="index-label">meta_key索引:</span>';
                    html += '<span class="index-status ' + (status.meta_key_index ? 'status-active' : 'status-inactive') + '">';
                    html += status.meta_key_index ? '✅ 已创建' : '❌ 未创建';
                    html += '</span></div>';

                    html += '<div class="index-status-item">';
                    html += '<span class="index-label">复合索引:</span>';
                    html += '<span class="index-status ' + (status.composite_index ? 'status-active' : 'status-inactive') + '">';
                    html += status.composite_index ? '✅ 已创建' : '❌ 未创建';
                    html += '</span></div>';

                    html += '<div class="index-status-item">';
                    html += '<span class="index-label">总索引数:</span>';
                    html += '<span class="index-value">' + status.total_indexes + '</span>';
                    html += '</div>';

                    if (status.table_size > 0) {
                        html += '<div class="index-status-item">';
                        html += '<span class="index-label">表大小:</span>';
                        html += '<span class="index-value">' + formatBytes(status.table_size) + '</span>';
                        html += '</div>';
                    }

                    html += '</div>';

                    // 显示建议
                    if (suggestions && suggestions.length > 0) {
                        html += '<div class="index-suggestions">';
                        html += '<h4>优化建议:</h4><ul>';
                        suggestions.forEach(function(suggestion) {
                            html += '<li>' + suggestion + '</li>';
                        });
                        html += '</ul></div>';
                    }

                    $container.html(html);

                    // 更新按钮状态（变量已移除，直接使用状态判断）

                } else {
                    $container.html('<div class="error-message">获取索引状态失败: ' + (response.data.message || '未知错误') + '</div>');
                }
            },
            error: function() {
                $container.html('<div class="error-message">网络错误，无法获取索引状态</div>');
            }
        });
    }

    /**
     * 删除数据库索引
     */
    function removeDatabaseIndexes() {
        if (!confirm('确定要删除数据库索引吗？这将降低查询性能。')) {
            return;
        }

        const $button = $('#remove-database-indexes');
        const originalText = $button.text();

        $button.prop('disabled', true).html('<span class="spinner is-active"></span> 正在删除索引...');

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'notion_to_wordpress_remove_database_indexes',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    showModal(response.data.message, 'success');

                    // 刷新状态
                    setTimeout(refreshIndexStatus, 1000);
                } else {
                    showModal('索引删除失败: ' + (response.data.message || '未知错误'), 'error');
                }
            },
            error: function() {
                showModal('网络错误，索引删除失败', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

    /**
     * 一键优化所有索引
     */
    function optimizeAllIndexes() {
        if (!confirm('🚀 确定要执行一键索引优化吗？\n\n这将创建所有推荐的性能索引，预计提升20-30%查询速度。\n操作安全，不会影响现有数据。')) {
            return;
        }

        const $button = $('#optimize-all-indexes');
        const originalText = $button.text();

        $button.prop('disabled', true).html('<span class="spinner is-active"></span> 🚀 正在优化索引...');

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'notion_to_wordpress_optimize_all_indexes',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    // 显示成功消息，包含性能提升信息
                    const data = response.data.data || {};
                    const performanceGain = data.details ? data.details.estimated_performance_gain : response.data.performance_improvement;
                    
                    let message = response.data.message;
                    if (performanceGain > 0) {
                        message += `\n\n📈 预计性能提升: ${performanceGain.toFixed(1)}%`;
                    }
                    
                    showModal(message, 'success');

                    // 显示详细结果到控制台
                    if (data.details) {
                        console.log('索引优化详情:', {
                            '创建的索引': data.created_indexes,
                            '跳过的索引': data.skipped_indexes,
                            '失败的索引': data.failed_indexes,
                            '总耗时': data.total_time + '秒',
                            '性能提升': performanceGain.toFixed(1) + '%'
                        });
                    }

                    // 刷新状态
                    setTimeout(refreshIndexStatus, 1500);
                } else {
                    showModal(response.data.message || '索引优化失败', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('索引优化AJAX错误:', {xhr, status, error});
                showModal('🔥 网络错误，索引优化失败。请检查网络连接。', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

    /**
     * 格式化字节数
     */
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // 绑定事件处理器
    $(document).ready(function() {
        // 页面加载时刷新索引状态
        if ($('#index-status-container').length > 0) {
            refreshIndexStatus();
        }

        // 绑定按钮事件
        $('#refresh-index-status').on('click', refreshIndexStatus);
        $('#remove-database-indexes').on('click', removeDatabaseIndexes);
        $('#optimize-all-indexes').on('click', optimizeAllIndexes);

        // CDN配置显示/隐藏 - 使用防抖优化
        $('#cdn_provider').on('change', NotionUtils.debounce(function() {
            const $customUrlField = $('#custom_cdn_url');
            const selectedValue = $(this).val();

            if (selectedValue === 'custom') {
                $customUrlField.removeClass('notion-wp-hidden').show();
                $customUrlField.focus(); // 自动聚焦到输入框
            } else {
                $customUrlField.addClass('notion-wp-hidden').hide();
            }
        }, 200));

        // 前端资源优化状态检查
        if ($('#enable_asset_compression').length > 0) {
            checkResourceOptimizationStatus();
        }

        // 异步处理状态检查 - 内容现在是服务器端渲染的，不需要自动刷新
        // if ($('#async-status-container').length > 0) {
        //     refreshAsyncStatus();
        // }

        // 绑定异步处理按钮事件
        $('#refresh-async-status').on('click', refreshAsyncStatus);
        $('#pause-async-operation').on('click', function() { controlAsyncOperation('pause'); });
        $('#resume-async-operation').on('click', function() { controlAsyncOperation('resume'); });
        $('#stop-async-operation').on('click', function() { controlAsyncOperation('stop'); });
        $('#cleanup-queue').on('click', cleanupQueue);

        // Webhook配置显示/隐藏 - 使用防抖优化
        $('#webhook_enabled').on('change', NotionUtils.debounce(function() {
            const $webhookSettings = $('#webhook-settings');
            const isChecked = $(this).is(':checked');

            if (isChecked) {
                $webhookSettings.removeClass('notion-wp-hidden').show();
            } else {
                $webhookSettings.addClass('notion-wp-hidden').hide();
            }
        }, 200));
    });

    /**
     * 检查前端资源优化状态
     */
    function checkResourceOptimizationStatus() {
        // 检查是否支持现代浏览器特性
        const features = {
            intersectionObserver: 'IntersectionObserver' in window,
            fetch: 'fetch' in window,
            performance: 'performance' in window,
            webp: checkWebPSupport()
        };

        let statusHtml = '<div class="resource-optimization-status">';
        statusHtml += '<h4>浏览器兼容性检查:</h4><ul>';

        Object.entries(features).forEach(([feature, supported]) => {
            const status = supported ? '✅ 支持' : '❌ 不支持';
            const featureName = {
                intersectionObserver: 'Intersection Observer (懒加载)',
                fetch: 'Fetch API (CDN检测)',
                performance: 'Performance API (性能监控)',
                webp: 'WebP格式 (图片优化)'
            }[feature];

            statusHtml += `<li>${featureName}: ${status}</li>`;
        });

        statusHtml += '</ul></div>';

        // 在前端资源优化设置后添加状态信息
        $('#performance_monitoring').closest('fieldset').after(statusHtml);
    }

    /**
     * 检查WebP支持
     */
    function checkWebPSupport() {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        return canvas.toDataURL('image/webp').indexOf('data:image/webp') === 0;
    }

    // ==================== 异步处理管理功能 ====================
    // 注意：异步状态相关的全局函数已在文件开头定义

    // 性能监控页面事件处理
    $('#refresh-performance-stats').on('click', function() {
        const $button = $(this);
        const originalText = $button.text();

        $button.prop('disabled', true).text('刷新中...');

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_refresh_performance_stats',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast('success', '性能统计已刷新');
                    // 刷新页面以显示最新数据
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showToast('error', response.data || '刷新失败');
                }
            },
            error: function() {
                showToast('error', '网络错误，请重试');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    });

    $('#reset-performance-stats').on('click', function() {
        if (!confirm('确定要重置所有性能统计数据吗？此操作不可撤销。')) {
            return;
        }

        const $button = $(this);
        const originalText = $button.text();

        $button.prop('disabled', true).text('重置中...');

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_reset_performance_stats',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast('success', '性能统计已重置');
                    // 刷新页面以显示重置后的数据
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showToast('error', response.data || '重置失败');
                }
            },
            error: function() {
                showToast('error', '网络错误，请重试');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    });

    /**
     * 清理队列
     */
    function cleanupQueue() {
        if (!confirm('确定要清理已完成的队列任务吗？')) {
            return;
        }

        const $button = $('#cleanup-queue');
        const originalText = $button.text();

        $button.prop('disabled', true).text('清理中...');

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_cleanup_queue',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast('success', '队列清理完成');
                    // 刷新状态
                    setTimeout(refreshAsyncStatus, 1000);
                } else {
                    showToast('error', '清理失败: ' + (response.data || '未知错误'));
                }
            },
            error: function() {
                showToast('error', '网络错误，清理失败');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

})(jQuery);