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

jQuery(document).ready(function($) {
    const $overlay = $('#loading-overlay');

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
    var originalLanguage = $('#plugin_language').val();
    console.log('Notion to WordPress: Original language on page load:', originalLanguage);

    // 记录页面加载时的原始webhook设置，用于检测变化
    var originalWebhookEnabled = $('#webhook_enabled').is(':checked');
    console.log('Notion to WordPress: Original webhook enabled on page load:', originalWebhookEnabled);

    // 监听语言选择器的变化，但不立即更新originalLanguage
    // 只有在表单成功提交后才更新originalLanguage
    $('#plugin_language').on('change', function() {
        var currentValue = $(this).val();
        console.log('Notion to WordPress: Language selector changed to:', currentValue);
        console.log('Notion to WordPress: Will compare with original:', originalLanguage);
    });

    // 监听webhook设置的变化，但不立即更新originalWebhookEnabled
    // 只有在表单成功提交后才更新originalWebhookEnabled
    $('#webhook_enabled').on('change', function() {
        var currentValue = $(this).is(':checked');
        console.log('Notion to WordPress: Webhook enabled changed to:', currentValue);
        console.log('Notion to WordPress: Will compare with original:', originalWebhookEnabled);
    });

    // 标签切换动画效果
    $('.notion-wp-menu-item').on('click', function(e) {
        e.preventDefault();
        var tabId = $(this).data('tab');

        $('.notion-wp-menu-item').removeClass('active');
        $('.notion-wp-tab-content').removeClass('active');

        $(this).addClass('active');

        // 添加淡入效果
        $('#' + tabId).addClass('active').hide().fadeIn(300);

        // 保存用户的标签选择到本地存储
        localStorage.setItem('notion_wp_active_tab', tabId);
    });
    
    // 从本地存储中恢复上次选择的标签
    const lastActiveTab = localStorage.getItem('notion_wp_active_tab');
    if (lastActiveTab) {
        $('.notion-wp-menu-item[data-tab="' + lastActiveTab + '"]').click();
    }
    
    // 显示/隐藏密码
    $('.show-hide-password').on('click', function() {
        var input = $(this).prev('input[type="password"], input[type="text"]');
        var icon = $(this).find('.dashicons');
        
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
        var confirmMessage = incremental ?
            notionToWp.i18n.confirm_smart_sync :
            notionToWp.i18n.confirm_full_sync;

        if (!confirm(confirmMessage)) {
            return;
        }

        var originalHtml = button.html();
        button.prop('disabled', true).html('<span class="spinner is-active"></span> ' + syncTypeName + notionToWp.i18n.syncing);

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_manual_sync',
                nonce: notionToWp.nonce,
                incremental: incremental,
                check_deletions: checkDeletions
            },
            success: function(response) {
                var message = response.success ? response.data.message : response.data.message;
                var status = response.success ? 'success' : 'error';

                if (response.success) {
                    message += ' (' + syncTypeName + notionToWp.i18n.sync_completed + ')';
                }

                showModal(message, status);

                // 如果成功，刷新统计信息
                if (response.success) {
                    fetchStats();
                }
            },
            error: function() {
                showModal(syncTypeName + notionToWp.i18n.sync_failed, 'error');
            },
            complete: function() {
                button.prop('disabled', false).html(originalHtml);
            }
        });
    }
    
    // 测试连接
    $('#notion-test-connection').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var api_key = $('#notion_to_wordpress_api_key').val();
        var database_id = $('#notion_to_wordpress_database_id').val();
        
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
            },
            error: function() {
                showModal(notionToWp.i18n.test_error, 'error');
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
    
    // 备用复制方法
    function fallbackCopyToClipboard(text, callback) {
        try {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';  // 防止滚动到底部
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            
            const successful = document.execCommand('copy');
            document.body.removeChild(textarea);
            
            if (successful) {
                if (callback) callback(true);
            } else {
                if (callback) callback(false, notionToWp.i18n.copy_failed || 'execCommand 复制命令失败');
            }
        } catch (e) {
            console.error(notionToWp.i18n.copy_failed || '备用复制方法错误:', e);
            if (callback) callback(false, e.message);
        }
    }
    
    // 复制到剪贴板
    $('.copy-to-clipboard').on('click', function(e) {
        e.preventDefault();
        const targetSelector = $(this).data('clipboard-target');
        
        if (!targetSelector) {
            console.error(notionToWp.i18n.copy_failed_no_target || '复制按钮缺少 data-clipboard-target 属性');
            showModal(notionToWp.i18n.copy_failed_no_target, 'error');
            return;
        }
        
        const $target = $(targetSelector);
        
        if ($target.length === 0) {
            console.error(notionToWp.i18n.copy_failed_not_found || '未找到目标元素:', targetSelector);
            showModal(notionToWp.i18n.copy_failed_not_found, 'error');
            return;
        }
        
        const textToCopy = $target.val() || $target.text();
        
        // 使用全局复制函数
        window.copyTextToClipboard(textToCopy, function(success, errorMsg) {
            if (success) {
                showModal(notionToWp.i18n.copied, 'success');
            } else {
                showModal(notionToWp.i18n.copy_failed + (errorMsg || notionToWp.i18n.unknown_error), 'error');
            }
        });
    });
    
    // 清除日志按钮点击事件
    $('#clear-logs-button').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(notionToWp.i18n.confirm_clear_logs)) {
            return;
        }
        
        var button = $(this);
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
    
    // 显示/隐藏导入频率选项
    $('#notion_to_wordpress_auto_import').on('change', function() {
        if ($(this).is(':checked')) {
            $('#auto_import_schedule_field').show();
        } else {
            $('#auto_import_schedule_field').hide();
        }
    });



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
        error: function(xhr, status, error) {
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

    // 刷新验证令牌
    $('#refresh-verification-token').on('click', function(e) {
        e.preventDefault();

        var button = $(this);
        var tokenInput = $('#verification_token');

        // 防止重复点击
        if (button.prop('disabled')) {
            return;
        }

        button.prop('disabled', true);
        button.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-update').addClass('spin');

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_refresh_verification_token',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    tokenInput.val(response.data.verification_token || '');
                    if (response.data.verification_token) {
                        showModal(response.data.message || notionToWp.i18n.verification_token_updated || '验证令牌已更新', 'success');
                    } else {
                        showModal(notionToWp.i18n.no_new_verification_token, 'info');
                    }
                } else {
                    showModal(response.data.message || notionToWp.i18n.refresh_error, 'error');
                }
            },
            error: function() {
                showModal(notionToWp.i18n.network_error || '网络错误，请稍后重试', 'error');
            },
            complete: function() {
                button.prop('disabled', false);
                button.find('.dashicons').removeClass('spin');
            }
        });
    });
    }

    // 表单验证和 AJAX 提交
    $('#notion-to-wordpress-settings-form').on('submit', function(e) {
        e.preventDefault(); // 阻止默认的表单提交

        var $form = $(this);
        // 精确查找保存设置按钮，避免影响其他按钮
        var $submitButton = $('#notion-save-settings');

        // 如果没有找到保存按钮，尝试备用选择器
        if ($submitButton.length === 0) {
            $submitButton = $form.find('input[type="submit"][name="submit"]');
        }

        var originalButtonText = $submitButton.val() || $submitButton.text();

        // 防止重复提交
        if ($submitButton.prop('disabled')) {
            console.log('Notion to WordPress: Form submission blocked - already in progress');
            return false;
        }

        // 基础验证
        var apiKey = $('#notion_to_wordpress_api_key').val();
        var dbId = $('#notion_to_wordpress_database_id').val();
        if (!apiKey || !dbId) {
            showModal(notionToWp.i18n.required_fields, 'error');
            if (!apiKey) $('#notion_to_wordpress_api_key').addClass('error');
            if (!dbId) $('#notion_to_wordpress_database_id').addClass('error');
            setTimeout(() => $('.error').removeClass('error'), 2000);
            return;
        }

        // 获取当前语言设置值（用户选择的新值）
        var newLanguage = $('#plugin_language').val();

        // 获取当前webhook设置值（用户选择的新值）
        var newWebhookEnabled = $('#webhook_enabled').is(':checked');

        // 禁用按钮并显示加载状态（只针对保存按钮）
        $submitButton.prop('disabled', true);

        // 根据按钮类型设置文本
        if ($submitButton.is('input')) {
            $submitButton.val(notionToWp.i18n.saving);
        } else {
            $submitButton.text(notionToWp.i18n.saving);
        }

        var formData = new FormData(this);
        formData.set('action', 'save_notion_settings'); // 确保action正确

        // 确保nonce字段存在
        if (!formData.has('notion_to_wordpress_options_nonce')) {
            var nonceField = $form.find('input[name="notion_to_wordpress_options_nonce"]');
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

                    // 添加调试日志
                    console.log('Notion to WordPress: Language change detection', {
                        original: originalLanguage,
                        new: newLanguage,
                        changed: languageChanged
                    });

                    console.log('Notion to WordPress: Webhook change detection', {
                        original: originalWebhookEnabled,
                        new: newWebhookEnabled,
                        changed: webhookChanged
                    });

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
            error: function() {
                showModal(notionToWp.i18n.unknown_error, 'error');
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

        copyButtons.each(function(index) {
            const $btn = $(this);
            const target = $btn.data('clipboard-target');
            const $target = $(target);

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
                    copyToClipboard(text, $button);
                });
            }
        });
        
        // 复制到剪贴板的函数
        function copyToClipboard(text, $button) {
            // 创建一个临时的textarea元素
            var $temp = $('<textarea>');
            $('body').append($temp);
            
            // 设置文本并选择
            $temp.val(text).select();
            
            // 执行复制命令
            var success = document.execCommand('copy');
            
            // 移除临时元素
            $temp.remove();
            
            // 根据复制结果更新按钮文本
            if (success) {
                var originalText = $button.text();
                $button.text(notionToWp.i18n.copied_success);
                
                // 2秒后恢复原始文本
                setTimeout(function() {
                    $button.text(originalText);
                }, 2000);
            } else {
                alert(notionToWp.i18n.copy_manual);
            }
        }
    });

    // ==================== 数据库索引管理功能 ====================

    /**
     * 刷新索引状态
     */
    function refreshIndexStatus() {
        const $container = $('#index-status-container');
        const $createBtn = $('#create-database-indexes');
        const $removeBtn = $('#remove-database-indexes');

        $container.html('<div class="loading-placeholder"><span class="spinner is-active"></span> 正在检查索引状态...</div>');
        $createBtn.prop('disabled', true);
        $removeBtn.hide();

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'notion_get_index_status',
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

                    // 更新按钮状态
                    const hasIndexes = status.meta_key_index || status.composite_index;
                    const needsIndexes = suggestions && suggestions.length > 0;

                    $createBtn.prop('disabled', !needsIndexes);
                    if (hasIndexes) {
                        $removeBtn.show();
                    }

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
     * 创建数据库索引
     */
    function createDatabaseIndexes() {
        const $button = $('#create-database-indexes');
        const originalText = $button.text();

        $button.prop('disabled', true).html('<span class="spinner is-active"></span> 正在创建索引...');

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'notion_create_database_indexes',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    showModal(response.data.message, 'success');

                    // 显示详细结果
                    if (response.data.data) {
                        const data = response.data.data;
                        let details = '创建详情:\n';
                        if (data.created_indexes.length > 0) {
                            details += '• 已创建: ' + data.created_indexes.join(', ') + '\n';
                        }
                        if (data.skipped_indexes.length > 0) {
                            details += '• 已跳过: ' + data.skipped_indexes.join(', ') + '\n';
                        }
                        if (data.performance_improvement > 0) {
                            details += '• 性能提升: ' + data.performance_improvement.toFixed(1) + '%\n';
                        }
                        details += '• 处理时间: ' + data.processing_time.toFixed(3) + '秒';

                        console.log('索引创建详情:', details);
                    }

                    // 刷新状态
                    setTimeout(refreshIndexStatus, 1000);
                } else {
                    showModal('索引创建失败: ' + (response.data.message || '未知错误'), 'error');
                }
            },
            error: function() {
                showModal('网络错误，索引创建失败', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
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
                action: 'notion_remove_database_indexes',
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
        $('#create-database-indexes').on('click', createDatabaseIndexes);
        $('#refresh-index-status').on('click', refreshIndexStatus);
        $('#remove-database-indexes').on('click', removeDatabaseIndexes);

        // CDN配置显示/隐藏
        $('#cdn_provider').on('change', function() {
            const customUrlField = $('#custom_cdn_url');
            if ($(this).val() === 'custom') {
                customUrlField.show();
            } else {
                customUrlField.hide();
            }
        });

        // 前端资源优化状态检查
        if ($('#enable_asset_compression').length > 0) {
            checkResourceOptimizationStatus();
        }

        // 异步处理状态检查
        if ($('#async-status-container').length > 0) {
            refreshAsyncStatus();
        }

        // 绑定异步处理按钮事件
        $('#refresh-async-status').on('click', refreshAsyncStatus);
        $('#pause-async-operation').on('click', function() { controlAsyncOperation('pause'); });
        $('#resume-async-operation').on('click', function() { controlAsyncOperation('resume'); });
        $('#stop-async-operation').on('click', function() { controlAsyncOperation('stop'); });
        $('#cleanup-queue').on('click', cleanupQueue);
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

    /**
     * 刷新异步状态
     */
    function refreshAsyncStatus() {
        const $asyncContainer = $('#async-status-container');
        const $queueContainer = $('#queue-status-container');

        $asyncContainer.html('<div class="loading-placeholder"><span class="spinner is-active"></span> 正在检查异步状态...</div>');
        $queueContainer.html('<div class="loading-placeholder"><span class="spinner is-active"></span> 正在检查队列状态...</div>');

        // 获取异步状态
        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'notion_get_async_status',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayAsyncStatus(response.data.status);
                } else {
                    $asyncContainer.html('<div class="error-message">获取异步状态失败: ' + (response.data.message || '未知错误') + '</div>');
                }
            },
            error: function() {
                $asyncContainer.html('<div class="error-message">网络错误，无法获取异步状态</div>');
            }
        });

        // 获取队列状态
        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'notion_get_queue_status',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayQueueStatus(response.data.status);
                } else {
                    $queueContainer.html('<div class="error-message">获取队列状态失败: ' + (response.data.message || '未知错误') + '</div>');
                }
            },
            error: function() {
                $queueContainer.html('<div class="error-message">网络错误，无法获取队列状态</div>');
            }
        });
    }

    /**
     * 显示异步状态
     */
    function displayAsyncStatus(status) {
        const $container = $('#async-status-container');

        let html = '<div class="async-status-grid">';

        // 状态显示
        html += '<div class="async-status-item">';
        html += '<span class="status-label">当前状态:</span>';
        html += '<span class="status-value status-' + status.status + '">';

        const statusText = {
            'idle': '空闲',
            'running': '运行中',
            'paused': '已暂停',
            'error': '错误'
        };

        html += statusText[status.status] || status.status;
        html += '</span></div>';

        if (status.operation) {
            html += '<div class="async-status-item">';
            html += '<span class="status-label">当前操作:</span>';
            html += '<span class="status-value">' + status.operation + '</span>';
            html += '</div>';
        }

        if (status.data_count > 0) {
            html += '<div class="async-status-item">';
            html += '<span class="status-label">数据量:</span>';
            html += '<span class="status-value">' + status.data_count + '</span>';
            html += '</div>';
        }

        if (status.progress > 0) {
            html += '<div class="async-status-item">';
            html += '<span class="status-label">进度:</span>';
            html += '<span class="status-value">' + status.progress + '%</span>';
            html += '</div>';
        }

        html += '</div>';

        $container.html(html);

        // 更新按钮状态
        updateAsyncButtons(status.status);
    }

    /**
     * 显示队列状态
     */
    function displayQueueStatus(status) {
        const $container = $('#queue-status-container');

        let html = '<div class="queue-status-grid">';

        html += '<div class="queue-status-item">';
        html += '<span class="status-label">总任务数:</span>';
        html += '<span class="status-value">' + status.total_tasks + '</span>';
        html += '</div>';

        html += '<div class="queue-status-item">';
        html += '<span class="status-label">待处理:</span>';
        html += '<span class="status-value">' + status.pending + '</span>';
        html += '</div>';

        html += '<div class="queue-status-item">';
        html += '<span class="status-label">处理中:</span>';
        html += '<span class="status-value">' + status.processing + '</span>';
        html += '</div>';

        html += '<div class="queue-status-item">';
        html += '<span class="status-label">已完成:</span>';
        html += '<span class="status-value">' + status.completed + '</span>';
        html += '</div>';

        html += '<div class="queue-status-item">';
        html += '<span class="status-label">失败:</span>';
        html += '<span class="status-value">' + status.failed + '</span>';
        html += '</div>';

        html += '<div class="queue-status-item">';
        html += '<span class="status-label">重试中:</span>';
        html += '<span class="status-value">' + status.retrying + '</span>';
        html += '</div>';

        html += '</div>';

        $container.html(html);
    }

    /**
     * 更新异步操作按钮状态
     */
    function updateAsyncButtons(status) {
        const $pauseBtn = $('#pause-async-operation');
        const $resumeBtn = $('#resume-async-operation');
        const $stopBtn = $('#stop-async-operation');

        // 隐藏所有按钮
        $pauseBtn.hide();
        $resumeBtn.hide();
        $stopBtn.hide();

        // 根据状态显示相应按钮
        switch (status) {
            case 'running':
                $pauseBtn.show();
                $stopBtn.show();
                break;
            case 'paused':
                $resumeBtn.show();
                $stopBtn.show();
                break;
        }
    }

    /**
     * 控制异步操作
     */
    function controlAsyncOperation(actionType) {
        const actionText = {
            'pause': '暂停',
            'resume': '恢复',
            'stop': '停止'
        };

        const $button = $('#' + actionType + '-async-operation');
        const originalText = $button.text();

        $button.prop('disabled', true).html('<span class="spinner is-active"></span> 正在' + actionText[actionType] + '...');

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'notion_control_async_operation',
                action_type: actionType,
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    showModal(response.data.message, 'success');

                    // 刷新状态
                    setTimeout(refreshAsyncStatus, 1000);
                } else {
                    showModal('操作失败: ' + (response.data.message || '未知错误'), 'error');
                }
            },
            error: function() {
                showModal('网络错误，操作失败', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

    /**
     * 清理队列
     */
    function cleanupQueue() {
        if (!confirm('确定要清理已完成的队列任务吗？')) {
            return;
        }

        const $button = $('#cleanup-queue');
        const originalText = $button.text();

        $button.prop('disabled', true).html('<span class="spinner is-active"></span> 正在清理...');

        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'notion_cleanup_queue',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                if (response.success) {
                    showModal(response.data.message, 'success');

                    // 刷新状态
                    setTimeout(refreshAsyncStatus, 1000);
                } else {
                    showModal('清理失败: ' + (response.data.message || '未知错误'), 'error');
                }
            },
            error: function() {
                showModal('网络错误，清理失败', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

})(jQuery);