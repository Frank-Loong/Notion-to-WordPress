/**
 * 管理界面交互脚本
 *
 * @since      1.0.8
 * @package    Notion_To_WordPress
 */

jQuery(document).ready(function($) {
    const $overlay = $('#loading-overlay');
    
    // 页面加载时获取统计信息
    if ($('.notion-stats-grid').length > 0) {
      console.log('正在加载统计信息...');
      fetchStats();
    }

    // 验证必要的安全参数
    if (!notionToWp || !notionToWp.ajax_url || typeof notionToWp.ajax_url !== 'string' || !notionToWp.nonce || typeof notionToWp.nonce !== 'string') {
      console.error('安全验证参数缺失或无效');
      return;
    }

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
            $(this).attr('title', '隐藏密钥');
        } else {
            input.attr('type', 'password');
            icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            $(this).attr('title', '显示密钥');
        }
    });
    
    // 手动导入
    $('#notion-manual-import').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        
        // 确认操作
        if (!confirm('确定要开始同步Notion内容吗？')) {
            return;
        }
        
        button.prop('disabled', true).html('<span class="spinner is-active"></span> ' + notionToWp.i18n.importing);
        
        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_manual_sync',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                var message = response.success ? response.data.message : response.data.message;
                var status = response.success ? 'success' : 'error';
                
                showModal(message, status);
                
                // 如果成功，刷新统计信息
                if (response.success) {
                    fetchStats();
                }
            },
            error: function() {
                showModal(notionToWp.i18n.import_error, 'error');
            },
            complete: function() {
                button.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> ' + notionToWp.i18n.import);
            }
        });
    });
    
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
            console.warn('要复制的文本为空');
            if (callback) callback(false, '要复制的文本为空');
            return;
        }
        
        try {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text)
                    .then(() => {
                        console.log('文本已成功复制到剪贴板');
                        if (callback) callback(true);
                    })
                    .catch(err => {
                        console.error('使用 Clipboard API 复制失败:', err);
                        fallbackCopyToClipboard(text, callback);
                    });
            } else {
                fallbackCopyToClipboard(text, callback);
            }
        } catch (e) {
            console.error('复制过程中发生错误:', e);
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
                console.log('使用备用方法成功复制文本');
                if (callback) callback(true);
            } else {
                console.warn('execCommand 复制命令失败');
                if (callback) callback(false, 'execCommand 复制命令失败');
            }
        } catch (e) {
            console.error('备用复制方法错误:', e);
            if (callback) callback(false, e.message);
        }
    }
    
    // 复制到剪贴板
    $('.copy-to-clipboard').on('click', function(e) {
        e.preventDefault();
        const targetSelector = $(this).data('clipboard-target');
        
        if (!targetSelector) {
            console.error('复制按钮缺少 data-clipboard-target 属性');
            showModal('复制失败: 未指定目标元素', 'error');
            return;
        }
        
        const $target = $(targetSelector);
        
        if ($target.length === 0) {
            console.error('未找到目标元素:', targetSelector);
            showModal('复制失败: 未找到目标元素', 'error');
            return;
        }
        
        const textToCopy = $target.val() || $target.text();
        
        // 使用全局复制函数
        window.copyTextToClipboard(textToCopy, function(success, errorMsg) {
            if (success) {
                showModal(notionToWp.i18n.copied, 'success');
            } else {
                showModal('复制失败: ' + (errorMsg || '未知错误'), 'error');
            }
        });
    });
    
    // 清除日志按钮点击事件
    $('#clear-logs-button').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('确定要清除所有日志文件吗？此操作不可恢复。')) {
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
                var message = response.success ? response.data.message : (response.data.message || '未知错误');
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
            viewer.val('请先选择一个日志文件。');
            return;
        }

        button.prop('disabled', true);
        viewer.val('正在加载日志...');

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
                    viewer.val('无法加载日志: ' + response.data.message);
                }
            },
            error: function() {
                viewer.val('请求日志时发生错误。');
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

    // 刷新全部内容
    $('.refresh-all-content').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        
        if (!confirm('确定要刷新全部内容吗？这将根据Notion的当前状态重新同步所有页面。')) {
            return;
        }
        
        button.prop('disabled', true).html('<span class="spinner is-active"></span> ' + notionToWp.i18n.refreshing);
        
        $.ajax({
            url: notionToWp.ajax_url,
            type: 'POST',
            data: {
                action: 'notion_to_wordpress_refresh_all',
                nonce: notionToWp.nonce
            },
            success: function(response) {
                var message = response.success ? response.data.message : (response.data.message || '未知错误');
                var status = response.success ? 'success' : 'error';
                
                showModal(message, status);
                
                if (response.success) {
                    fetchStats();
                }
            },
            error: function() {
                showModal(notionToWp.i18n.refresh_error, 'error');
            },
            complete: function() {
                button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> ' + notionToWp.i18n.refresh_all);
            }
        });
    });

    // 刷新单个页面
    $('table').on('click', '.refresh-single', function (e) {
      e.preventDefault();
      const pageId = $(this).data('page-id');
      
      // 验证页面ID和安全参数
      if (!pageId || typeof pageId !== 'string' || pageId.trim() === '') {
        showModal('页面ID无效，无法刷新。', 'error');
        return;
      }
      
      if (!notionToWp.nonce || !notionToWp.ajax_url) {
        showModal('安全验证参数缺失，无法继续操作。请刷新页面后重试。', 'error');
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
            showModal('页面已刷新完成！', 'success');
            // 刷新统计信息
            fetchStats();
          } else {
            showModal('刷新失败: ' + (resp.data?.message || '未知错误'), 'error');
          }
        },
        error: function(xhr, status, error) {
          $overlay.fadeOut(300);
          let errorMsg = '网络错误，无法刷新页面。';
          if (status === 'timeout') {
            errorMsg = '操作超时，请检查该Notion页面内容是否过大。';
          } else if (xhr.responseJSON && xhr.responseJSON.data) {
            errorMsg += ' 详细信息: ' + xhr.responseJSON.data.message;
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
                    $('.stat-last-update').text(stats.last_sync || '从未');
                    $('.stat-next-run').text(stats.next_sync || '未计划');
                } else {
                    showModal('无法加载统计信息: ' + (response.data.message || '未知错误'), 'error');
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
    
    // 表单验证
    $('form').on('submit', function(e) {
        var api_key = $('#notion_to_wordpress_api_key').val();
        var database_id = $('#notion_to_wordpress_database_id').val();
        var hasError = false;
        
        if (!api_key) {
            $('#notion_to_wordpress_api_key').addClass('error');
            hasError = true;
        }
        
        if (!database_id) {
            $('#notion_to_wordpress_database_id').addClass('error');
            hasError = true;
        }
        
        if (hasError) {
            e.preventDefault();
            showModal('请填写所有必填字段', 'error');
            
            setTimeout(function() {
                $('.error').removeClass('error');
            }, 2000);
        }
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
        console.log('找到复制按钮数量:', copyButtons.length);
        
        copyButtons.each(function(index) {
            const $btn = $(this);
            const target = $btn.data('clipboard-target');
            const $target = $(target);
            
            console.log(`按钮 ${index + 1}:`, {
                '目标选择器': target,
                '目标元素存在': $target.length > 0,
                '目标元素值': $target.val() || '(空)',
                '按钮HTML': $btn.prop('outerHTML')
            });
            
            // 确保按钮有正确的提示
            if (!$btn.attr('title')) {
                $btn.attr('title', '复制到剪贴板');
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
                var $button = $('<button class="copy-button" title="复制代码"></button>');
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
                $button.text('已复制!');
                
                // 2秒后恢复原始文本
                setTimeout(function() {
                    $button.text(originalText);
                }, 2000);
            } else {
                alert('复制失败，请手动复制。');
            }
        }
    });
})(jQuery); 
