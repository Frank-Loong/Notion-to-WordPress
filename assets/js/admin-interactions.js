/**
 * 管理界面交互脚本
 *
 * @since      1.0.8
 * @package    Notion_To_WordPress
 */

jQuery(document).ready(function($) {
    // 安全检查
    if (!notionToWp || !notionToWp.ajax_url || typeof notionToWp.ajax_url !== 'string' || !notionToWp.nonce || typeof notionToWp.nonce !== 'string') {
      console.error(notionToWp.i18n.security_missing || '安全验证参数缺失或无效');
      return;
    }

    // 模块化设计
    const NotionWP = {};
    
    // 加载覆盖
    const $overlay = $('#loading-overlay');
    
    // UI模块
    NotionWP.UI = {
        // 初始化UI组件
        init: function() {
            this.initTabSwitcher();
            this.initPasswordToggle();
            this.initCopyButtons();
            this.initSettingsTracking();
            this.initConfigReset();
        },
        
        // 标签切换
        initTabSwitcher: function() {
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
        },
    
        // 密码切换
        initPasswordToggle: function() {
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
        },
        
        // 复制按钮功能
        initCopyButtons: function() {
            // 延迟初始化复制按钮，确保DOM已完全加载
            setTimeout(function() {
                $('.copy-button').each(function() {
                    var $button = $(this);
                    var targetSelector = $button.data('copy-target');
                    
                    if (!targetSelector) {
                        return;
                    }
                    
                    $button.on('click', function(e) {
                        e.preventDefault();
                        var $target = $(targetSelector);
                        
                        if ($target.length === 0) {
                            NotionWP.UI.showModal(notionToWp.i18n.copy_failed_not_found || '复制失败: 未找到目标元素', 'error');
                            return;
                        }
                        
                        var textToCopy = $target.val() || $target.text();
                        NotionWP.Utils.copyToClipboard(textToCopy, function(success, error) {
                            if (success) {
                                var $originalContent = $button.html();
                                $button.html('<span class="dashicons dashicons-yes"></span> ' + (notionToWp.i18n.copied_success || '已复制!'));
                                
                                setTimeout(function() {
                                    $button.html($originalContent);
                                }, 2000);
                            } else {
                                NotionWP.UI.showModal(notionToWp.i18n.copy_failed || '复制失败: ' + (error || '未知原因'), 'error');
                            }
                        });
                    });
                });
            }, 500);
        },
        
        // 设置变化跟踪
        initSettingsTracking: function() {
            // 记录页面加载时的原始语言设置，用于检测变化
            var originalLanguage = $('#plugin_language').val();
            // 记录页面加载时的原始webhook设置，用于检测变化
            var originalWebhookEnabled = $('#webhook_enabled').is(':checked');
            
            // 监听语言选择器的变化
            $('#plugin_language').on('change', function() {
                var currentValue = $(this).val();
            });
            
            // 监听webhook设置的变化
            $('#webhook_enabled').on('change', function() {
                var currentValue = $(this).is(':checked');
            });
        },
        
        // 初始化配置重置功能
        initConfigReset: function() {
            // 全部重置
            $('#reset-all-config').on('click', function(e) {
        e.preventDefault();
                
                if (confirm(notionToWp.i18n.confirm_reset_all_config || '确定要将所有配置重置为默认值吗？这将无法撤销。')) {
                    NotionWP.Config.resetConfigs();
                }
    });

            // 单节点重置按钮（如果有的话）
            $('.reset-section-config').on('click', function(e) {
        e.preventDefault();
                
                var section = $(this).data('section');
                if (section && confirm(notionToWp.i18n.confirm_reset_section || '确定要将此节配置重置为默认值吗？')) {
                    NotionWP.Config.resetConfigs(section);
                }
            });
        },
        
        // 显示模态弹窗
        showModal: function(message, status = 'success') {
            var modalClass = status === 'error' ? 'error-modal' : 'success-modal';
            var icon = status === 'error' ? 'dashicons-warning' : 'dashicons-yes';
            
            // 创建模态HTML
            var $modal = $('<div class="notion-wp-modal ' + modalClass + '"></div>');
            var $content = $('<div class="modal-content"></div>').appendTo($modal);
            var $icon = $('<span class="dashicons ' + icon + '"></span>').appendTo($content);
            var $message = $('<span class="modal-message"></span>').text(message).appendTo($content);
            var $closeBtn = $('<button class="close-modal" title="关闭"><span class="dashicons dashicons-no-alt"></span></button>').appendTo($content);
            
            // 添加到DOM
            $('body').append($modal);
            
            // 显示模态
            setTimeout(function() {
                $modal.addClass('show');
            }, 10);
            
            // 自动关闭
            var timeout = setTimeout(function() {
                closeModal();
            }, 5000);
            
            // 点击关闭按钮
            $closeBtn.on('click', function() {
                clearTimeout(timeout);
                closeModal();
            });
            
            // 关闭函数
            function closeModal() {
                $modal.removeClass('show');
                setTimeout(function() {
                    $modal.remove();
                }, 300);
            }
        }
    };

    // API模块
    NotionWP.API = {
        // 执行同步
        performSync: function(button, incremental, checkDeletions, syncTypeName) {
        // 确认操作
        var confirmMessage = incremental ?
            notionToWp.i18n.confirm_smart_sync :
            notionToWp.i18n.confirm_full_sync;

        if (!confirm(confirmMessage)) {
            return;
        }

        var originalHtml = button.html();
        // 安全地构建HTML内容，防止XSS
        var spinnerHtml = $('<span>').addClass('spinner is-active');
        var syncText = $('<span>').text(syncTypeName + notionToWp.i18n.syncing);
        button.prop('disabled', true).empty().append(spinnerHtml).append(' ').append(syncText);

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

                    NotionWP.UI.showModal(message, status);

                // 如果成功，刷新统计信息
                if (response.success) {
                        NotionWP.Stats.fetchStats();
                }
            },
            error: function() {
                    NotionWP.UI.showModal(syncTypeName + notionToWp.i18n.sync_failed, 'error');
            },
            complete: function() {
                button.prop('disabled', false).html(originalHtml);
            }
        });
        },
        
        // 测试API连接
        testConnection: function() {
            var button = $('#notion-test-connection');
        var api_key = $('#notion_to_wordpress_api_key').val();
        var database_id = $('#notion_to_wordpress_database_id').val();
        
        if (!api_key || !database_id) {
                NotionWP.UI.showModal(notionToWp.i18n.fill_fields, 'error');
            
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
        
        // 安全地构建HTML内容，防止XSS
        var spinnerHtml = $('<span>').addClass('spinner is-active');
        var testingText = $('<span>').text(notionToWp.i18n.testing);
        button.prop('disabled', true).empty().append(spinnerHtml).append(' ').append(testingText);
        
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
                
                    NotionWP.UI.showModal(message, status);
            },
            error: function() {
                    NotionWP.UI.showModal(notionToWp.i18n.test_error, 'error');
            },
            complete: function() {
                // 安全地构建HTML内容，防止XSS
                var iconHtml = $('<span>').addClass('dashicons dashicons-yes-alt');
                var buttonText = $('<span>').text(notionToWp.i18n.test_connection);
                button.prop('disabled', false).empty().append(iconHtml).append(' ').append(buttonText);
            }
        });
        }
    };
    
    // 配置管理模块
    NotionWP.Config = {
        // 重置配置
        resetConfigs: function(section = '') {
            // 显示加载中状态
            $overlay.fadeIn();
            
            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                data: {
                    action: 'notion_to_wordpress_reset_config',
                    nonce: notionToWp.nonce,
                    section: section
                },
                success: function(response) {
                    if (response.success) {
                        NotionWP.UI.showModal(response.data.message || notionToWp.i18n.config_reset_success || '配置已重置为默认值', 'success');
                        
                        // 延迟刷新页面以显示更新后的值
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        NotionWP.UI.showModal(response.data.message || notionToWp.i18n.config_reset_error || '重置配置时出错', 'error');
                    }
                },
                error: function() {
                    NotionWP.UI.showModal(notionToWp.i18n.config_reset_error || '重置配置时出错', 'error');
                },
                complete: function() {
                    $overlay.fadeOut();
                }
            });
        },
        
        // 验证配置值
        validateConfigValue: function(section, key, value, callback) {
            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                data: {
                    action: 'notion_to_wordpress_validate_config',
                    nonce: notionToWp.nonce,
                    section: section,
                    key: key,
                    value: value
                },
                success: function(response) {
                    if (callback) {
                        callback(response.success, response.data);
                    }
                },
                error: function() {
                    if (callback) {
                        callback(false, { message: notionToWp.i18n.validation_error || '验证配置值时出错' });
                    }
                }
            });
        }
    };
    
    // 统计数据模块
    NotionWP.Stats = {
        fetchStats: function() {
            if ($('.notion-stats-grid').length === 0) {
                return;
            }
            
            $('.notion-stats-grid').addClass('loading');
            
            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                data: {
                    action: 'notion_to_wordpress_get_stats',
                    nonce: notionToWp.nonce
                },
                success: function(response) {
                    if (!response.success) {
                        NotionWP.UI.showModal(notionToWp.i18n.stats_error, 'error');
                        return;
                    }
                    
                    // 更新统计数据
                    const stats = response.data;
                    
                    // 格式化日期时间函数
                    const formatDateTime = (dt) => {
                        if (!dt || dt === 'never') {
                            return notionToWp.i18n.never || '从未';
                        }
                        
                        // 尝试解析日期
                        const date = new Date(dt);
                        if (isNaN(date.getTime())) {
                            return dt;
                        }
                        
                        return date.toLocaleString();
                    };
                    
                    // 填充统计数据
                    $('#notion-stat-imported').text(stats.imported || 0);
                    $('#notion-stat-published').text(stats.published || 0);
                    $('#notion-stat-drafts').text(stats.drafts || 0);
                    $('#notion-stat-last-sync').text(formatDateTime(stats.lastSync));
                    
                    // 计划同步信息
                    if (stats.nextSync && stats.nextSync !== 'not_scheduled') {
                        $('#notion-stat-next-sync').text(formatDateTime(stats.nextSync));
                    } else {
                        $('#notion-stat-next-sync').text(notionToWp.i18n.not_scheduled || '未计划');
                    }
                },
                error: function() {
                    NotionWP.UI.showModal(notionToWp.i18n.stats_error, 'error');
                },
                complete: function() {
                    $('.notion-stats-grid').removeClass('loading');
                }
            });
        }
    };
    
    // 工具函数模块
    NotionWP.Utils = {
        // 复制到剪贴板
        copyToClipboard: function(text, callback) {
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
                            this.fallbackCopyToClipboard(text, callback);
                    });
            } else {
                    this.fallbackCopyToClipboard(text, callback);
            }
        } catch (e) {
            console.error(notionToWp.i18n.copy_failed || '复制过程中发生错误:', e);
            if (callback) callback(false, e.message);
        }
        },
    
    // 备用复制方法
        fallbackCopyToClipboard: function(text, callback) {
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
                console.error(notionToWp.i18n.copy_manual || '请手动复制文本:', e);
            if (callback) callback(false, e.message);
        }
    }
    };
    
    // 事件绑定
    function bindEvents() {
        // 智能同步（增量同步）
        $('#notion-manual-import').on('click', function(e) {
        e.preventDefault();
            NotionWP.API.performSync($(this), true, true, notionToWp.i18n.smart_sync); // 增量同步，检查删除
        });

        // 完全同步（全量同步）
        $('#notion-full-import').on('click', function(e) {
        e.preventDefault();
            NotionWP.API.performSync($(this), false, true, notionToWp.i18n.full_sync); // 全量同步，检查删除
        });
        
        // 测试连接
        $('#notion-test-connection').on('click', function(e) {
            e.preventDefault();
            NotionWP.API.testConnection();
        });
        
        // 重置所有配置
        $('#reset-all-config').on('click', function(e) {
        e.preventDefault();
            if (confirm(notionToWp.i18n.confirm_reset_all_config || '确定要将所有配置重置为默认值吗？这将无法撤销。')) {
                NotionWP.Config.resetConfigs();
            }
        });
        
        // 其他事件绑定...
    }
    
    // 初始化应用
    function init() {
        NotionWP.UI.init();
        bindEvents();
        
        // 页面加载时获取统计信息
        if ($('.notion-stats-grid').length > 0) {
            NotionWP.Stats.fetchStats();
        }
    }
    
    // 配置管理模块
    NotionWP.ConfigManager = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#validate-config').on('click', this.validateConfig);
            $('#reset-config').on('click', this.resetConfig);
            $('#export-config').on('click', this.exportConfig);
        },

        validateConfig: function() {
            const $button = $(this);
            const $result = $('#config-validation-result');

            $button.prop('disabled', true).find('.dashicons').addClass('spin');

            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                data: {
                    action: 'notion_config_management',
                    config_action: 'validate',
                    nonce: notionToWp.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const result = response.data;
                        let html = '<div class="notice notice-' + (result.valid ? 'success' : 'error') + ' inline">';
                        html += '<p><strong>' + (result.valid ? '✅ 配置验证通过' : '❌ 配置验证失败') + '</strong></p>';

                        if (result.errors && result.errors.length > 0) {
                            html += '<ul>';
                            result.errors.forEach(function(error) {
                                html += '<li>❌ ' + error + '</li>';
                            });
                            html += '</ul>';
                        }

                        if (result.warnings && result.warnings.length > 0) {
                            html += '<ul>';
                            result.warnings.forEach(function(warning) {
                                html += '<li>⚠️ ' + warning + '</li>';
                            });
                            html += '</ul>';
                        }

                        html += '</div>';
                        $result.html(html).show();
                    } else {
                        NotionWP.Utils.showNotice('配置验证失败: ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    NotionWP.Utils.showNotice('配置验证请求失败', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).find('.dashicons').removeClass('spin');
                }
            });
        },

        resetConfig: function() {
            if (!confirm('确定要重置所有配置为默认值吗？此操作不可撤销。')) {
                return;
            }

            const $button = $(this);
            $button.prop('disabled', true).find('.dashicons').addClass('spin');

            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                data: {
                    action: 'notion_config_management',
                    config_action: 'reset',
                    nonce: notionToWp.nonce
                },
                success: function(response) {
                    if (response.success) {
                        NotionWP.Utils.showNotice('配置已重置为默认值，页面将刷新', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        NotionWP.Utils.showNotice('配置重置失败: ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    NotionWP.Utils.showNotice('配置重置请求失败', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).find('.dashicons').removeClass('spin');
                }
            });
        },

        exportConfig: function() {
            const $button = $(this);
            $button.prop('disabled', true).find('.dashicons').addClass('spin');

            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                data: {
                    action: 'notion_config_management',
                    config_action: 'export',
                    nonce: notionToWp.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const config = response.data.config;
                        const dataStr = JSON.stringify(config, null, 2);
                        const dataBlob = new Blob([dataStr], {type: 'application/json'});

                        const link = document.createElement('a');
                        link.href = URL.createObjectURL(dataBlob);
                        link.download = 'notion-to-wordpress-config-' + new Date().toISOString().split('T')[0] + '.json';
                        link.click();

                        NotionWP.Utils.showNotice('配置已导出', 'success');
                    } else {
                        NotionWP.Utils.showNotice('配置导出失败: ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    NotionWP.Utils.showNotice('配置导出请求失败', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).find('.dashicons').removeClass('spin');
                }
            });
        }
    };

    // 查询性能监控模块
    NotionWP.QueryPerformance = {
        init: function() {
            this.bindEvents();
            this.loadStats();
        },

        bindEvents: function() {
            $('#refresh-query-stats').on('click', this.loadStats.bind(this));
        },

        loadStats: function() {
            const $button = $('#refresh-query-stats');
            $button.prop('disabled', true).find('.dashicons').addClass('spin');

            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                data: {
                    action: 'notion_query_performance',
                    nonce: notionToWp.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const stats = response.data;
                        $('#total-queries').text(stats.total_queries);
                        $('#slow-queries').text(stats.slow_queries);
                        $('#avg-time').text(stats.avg_time_ms + ' ms');
                        $('#max-time').text(stats.max_time_ms + ' ms');

                        // 更新慢查询警告
                        const $slowQueries = $('#slow-queries');
                        if (stats.slow_queries > 0) {
                            $slowQueries.css('color', '#d63384');
                            if (stats.slow_queries > 10) {
                                $slowQueries.parent().append(
                                    '<div class="notice notice-warning inline" style="margin-top: 10px;">' +
                                    '<p>⚠️ 检测到较多慢查询，建议优化数据库查询性能</p>' +
                                    '</div>'
                                );
                            }
                        } else {
                            $slowQueries.css('color', '#198754');
                        }

                        NotionWP.Utils.showNotice('查询统计已更新', 'success');
                    } else {
                        NotionWP.Utils.showNotice('获取查询统计失败: ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    NotionWP.Utils.showNotice('获取查询统计请求失败', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).find('.dashicons').removeClass('spin');
                }
            });
        }
    };

    // 测试验证模块
    NotionWP.TestValidation = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#run-quick-test').on('click', () => this.runTest('quick'));
            $('#run-security-test').on('click', () => this.runTest('security'));
            $('#run-performance-test').on('click', () => this.runTest('performance'));
            $('#run-functional-test').on('click', () => this.runTest('functional'));
        },

        runTest: function(testType) {
            const $button = $(`#run-${testType}-test`);
            const $results = $('#test-validation-results');

            $button.prop('disabled', true).find('.dashicons').addClass('spin');
            $results.hide();

            $.ajax({
                url: notionToWp.ajax_url,
                type: 'POST',
                data: {
                    action: 'notion_test_validation',
                    test_type: testType,
                    nonce: notionToWp.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.displayTestResults(response.data, testType);
                        $results.show();
                    } else {
                        NotionWP.Utils.showNotice('测试执行失败: ' + response.data.message, 'error');
                    }
                },
                error: () => {
                    NotionWP.Utils.showNotice('测试请求失败', 'error');
                },
                complete: () => {
                    $button.prop('disabled', false).find('.dashicons').removeClass('spin');
                }
            });
        },

        displayTestResults: function(data, testType) {
            const $summary = $('#test-summary');
            const $details = $('#test-details');

            // 显示测试摘要
            if (data.summary) {
                const summary = data.summary;
                const statusColor = this.getStatusColor(summary.overall_status);

                $summary.html(`
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h4 style="margin: 0; color: ${statusColor};">
                                ${this.getStatusText(summary.overall_status)} (${summary.pass_rate}%)
                            </h4>
                            <p style="margin: 5px 0 0 0; color: #666;">
                                总计: ${summary.total_checks} 项，通过: ${summary.passed_checks} 项，
                                失败: ${summary.failed_checks} 项，警告: ${summary.warning_checks} 项
                            </p>
                        </div>
                        <div style="text-align: right; color: #666;">
                            <small>执行时间: ${summary.execution_time}秒</small>
                        </div>
                    </div>
                `);
            }

            // 显示测试详情
            $details.empty();

            if (data.results) {
                Object.keys(data.results).forEach(category => {
                    const categoryResults = data.results[category];
                    const categoryCard = this.createCategoryCard(category, categoryResults);
                    $details.append(categoryCard);
                });
            }
        },

        createCategoryCard: function(category, results) {
            const categoryName = {
                'security': '安全检查',
                'performance': '性能检查',
                'functional': '功能检查'
            }[category] || category;

            let cardHtml = `
                <div class="test-category-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                    <h5 style="margin: 0 0 15px 0; color: #333;">${categoryName}</h5>
                    <div class="test-items">
            `;

            Object.keys(results).forEach(testName => {
                const result = results[testName];
                const statusIcon = this.getStatusIcon(result.status);
                const statusColor = this.getStatusColor(result.status);

                cardHtml += `
                    <div class="test-item" style="display: flex; align-items: center; margin-bottom: 10px; padding: 8px; background: #f9f9f9; border-radius: 4px;">
                        <span style="color: ${statusColor}; margin-right: 8px;">${statusIcon}</span>
                        <div style="flex: 1;">
                            <div style="font-weight: 500; color: #333;">${testName}</div>
                            <div style="font-size: 12px; color: #666;">${result.message}</div>
                        </div>
                    </div>
                `;
            });

            cardHtml += `
                    </div>
                </div>
            `;

            return cardHtml;
        },

        getStatusIcon: function(status) {
            const icons = {
                'PASS': '✅',
                'FAIL': '❌',
                'WARNING': '⚠️',
                'EXCELLENT': '🌟',
                'GOOD': '✅',
                'ACCEPTABLE': '⚠️',
                'NEEDS_ATTENTION': '❌'
            };
            return icons[status] || '❓';
        },

        getStatusColor: function(status) {
            const colors = {
                'PASS': '#198754',
                'FAIL': '#dc3545',
                'WARNING': '#fd7e14',
                'EXCELLENT': '#198754',
                'GOOD': '#198754',
                'ACCEPTABLE': '#fd7e14',
                'NEEDS_ATTENTION': '#dc3545'
            };
            return colors[status] || '#6c757d';
        },

        getStatusText: function(status) {
            const texts = {
                'EXCELLENT': '优秀',
                'GOOD': '良好',
                'ACCEPTABLE': '可接受',
                'NEEDS_ATTENTION': '需要关注'
            };
            return texts[status] || status;
        }
    };

    // 暴露全局函数（必要的）
    window.copyTextToClipboard = function(text, callback) {
        NotionWP.Utils.copyToClipboard(text, callback);
    };

    // 初始化函数
    function init() {
        NotionWP.UI.init();
        NotionWP.Sync.init();
        NotionWP.Connection.init();
        NotionWP.Logs.init();
        NotionWP.Stats.init();
        NotionWP.ConfigManager.init(); // 添加配置管理初始化
        NotionWP.QueryPerformance.init(); // 添加查询性能监控初始化
        NotionWP.TestValidation.init(); // 添加测试验证初始化

        // 页面加载时获取统计信息
        if ($('.notion-stats-grid').length > 0) {
            NotionWP.Stats.fetchStats();
        }
    }

    // 初始化
    init();
});
