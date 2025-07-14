<?php
/**
 * 性能监视器页面。
 * 此文件负责渲染性能监视器页面，包括实时数据卡片和操作按钮。
 * @since      1.8.2
 * @version    1.8.3-beta.1
 * @package    Notion_To_WordPress
 * @subpackage Notion_To_WordPress/admin/partials
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 如果直接访问此文件，则退出
if (!defined('WPINC')) {
    die;
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="notice notice-info">
        <p><strong>性能监控说明：</strong>此页面显示插件各模块的实时性能数据，帮助您了解系统运行状况并优化配置。</p>
    </div>

    <!-- 操作按钮 -->
    <div class="performance-actions" style="margin: 20px 0;">
        <button type="button" class="button button-primary" id="refresh-performance-data">
            <span class="dashicons dashicons-update"></span> 刷新数据
        </button>
        <button type="button" class="button" id="reset-performance-stats">
            <span class="dashicons dashicons-trash"></span> 重置统计
        </button>
        <button type="button" class="button button-secondary" id="run-performance-test">
            <span class="dashicons dashicons-performance"></span> 运行性能测试
        </button>
        <span id="performance-loading" style="display: none;">
            <span class="spinner is-active"></span> 处理中...
        </span>
    </div>

    <!-- 性能概览 -->
    <div class="performance-overview">
        <h2>性能概览</h2>
        <div class="performance-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
            
            <!-- 并发性能卡片 -->
            <div class="performance-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px;">
                <h3 style="margin-top: 0; color: #1d2327;">
                    <span class="dashicons dashicons-networking"></span> 并发性能
                </h3>
                <div class="performance-metrics">
                    <?php if (!empty($performance_data['concurrent'])): ?>
                        <p><strong>最大并发数：</strong> <?php echo esc_html($performance_data['concurrent']['concurrent_stats']['max_concurrent'] ?? 'N/A'); ?></p>
                        <p><strong>成功率：</strong> <?php echo esc_html($performance_data['concurrent']['concurrent_stats']['success_rate'] ?? 'N/A'); ?>%</p>
                        <p><strong>平均响应时间：</strong> <?php echo esc_html($performance_data['concurrent']['concurrent_stats']['average_response_time'] ?? 'N/A'); ?>ms</p>
                        <p><strong>网络质量：</strong> 
                            <span class="performance-grade grade-<?php echo strtolower($performance_data['concurrent']['network_stats']['performance_grade'] ?? 'unknown'); ?>">
                                <?php echo esc_html($performance_data['concurrent']['network_stats']['performance_grade'] ?? 'N/A'); ?>
                            </span>
                        </p>
                    <?php else: ?>
                        <p><em>暂无数据</em></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- API缓存卡片 -->
            <div class="performance-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px;">
                <h3 style="margin-top: 0; color: #1d2327;">
                    <span class="dashicons dashicons-database-view"></span> API缓存
                </h3>
                <div class="performance-metrics">
                    <?php if (!empty($performance_data['api'])): ?>
                        <p><strong>缓存命中率：</strong> <?php echo esc_html($performance_data['api']['cache_performance']['overall_hit_rate'] ?? 'N/A'); ?></p>
                        <p><strong>请求合并率：</strong> <?php echo esc_html($performance_data['api']['request_merge_performance']['merge_ratio_percent'] ?? 'N/A'); ?></p>
                        <p><strong>综合评分：</strong> <?php echo esc_html($performance_data['api']['comprehensive_score'] ?? 'N/A'); ?></p>
                        <p><strong>性能等级：</strong> 
                            <span class="performance-grade grade-<?php echo strtolower($performance_data['api']['performance_grade'] ?? 'unknown'); ?>">
                                <?php echo esc_html($performance_data['api']['performance_grade'] ?? 'N/A'); ?>
                            </span>
                        </p>
                    <?php else: ?>
                        <p><em>暂无数据</em></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 内存使用卡片 -->
            <div class="performance-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px;">
                <h3 style="margin-top: 0; color: #1d2327;">
                    <span class="dashicons dashicons-chart-area"></span> 内存使用
                </h3>
                <div class="performance-metrics">
                    <?php if (!empty($performance_data['memory'])): ?>
                        <p><strong>当前使用：</strong> <?php echo esc_html($performance_data['memory']['current_memory_formatted'] ?? 'N/A'); ?></p>
                        <p><strong>使用率：</strong> <?php echo esc_html($performance_data['memory']['current_usage_ratio'] ?? 'N/A'); ?>%</p>
                        <p><strong>峰值使用：</strong> <?php echo esc_html($performance_data['memory']['peak_memory_formatted'] ?? 'N/A'); ?></p>
                        <p><strong>警告次数：</strong> <?php echo esc_html($performance_data['memory']['warning_count'] ?? 0); ?></p>
                    <?php else: ?>
                        <p><em>暂无数据</em></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 分页处理卡片 -->
            <div class="performance-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px;">
                <h3 style="margin-top: 0; color: #1d2327;">
                    <span class="dashicons dashicons-admin-page"></span> 分页处理
                </h3>
                <div class="performance-metrics">
                    <?php if (!empty($performance_data['pagination'])): ?>
                        <p><strong>处理页面数：</strong> <?php echo esc_html($performance_data['pagination']['total_pages_processed'] ?? 0); ?></p>
                        <p><strong>批次数：</strong> <?php echo esc_html($performance_data['pagination']['total_batches'] ?? 0); ?></p>
                        <p><strong>平均批次大小：</strong> <?php echo esc_html(round($performance_data['pagination']['avg_batch_size'] ?? 0, 1)); ?></p>
                        <p><strong>效率评分：</strong> <?php echo esc_html($performance_data['pagination']['efficiency_score'] ?? 'N/A'); ?></p>
                    <?php else: ?>
                        <p><em>暂无数据</em></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 详细统计 -->
    <div class="performance-details">
        <h2>详细统计</h2>
        
        <!-- 并发管理器详细统计 -->
        <?php if (!empty($performance_data['concurrent'])): ?>
        <div class="performance-section">
            <h3>并发管理器统计</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>指标</th>
                        <th>当前值</th>
                        <th>说明</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>总请求数</td>
                        <td><?php echo esc_html($performance_data['concurrent']['concurrent_stats']['total_requests'] ?? 0); ?></td>
                        <td>自启动以来的总请求数量</td>
                    </tr>
                    <tr>
                        <td>成功请求数</td>
                        <td><?php echo esc_html($performance_data['concurrent']['concurrent_stats']['successful_requests'] ?? 0); ?></td>
                        <td>成功完成的请求数量</td>
                    </tr>
                    <tr>
                        <td>失败请求数</td>
                        <td><?php echo esc_html($performance_data['concurrent']['concurrent_stats']['failed_requests'] ?? 0); ?></td>
                        <td>失败的请求数量</td>
                    </tr>
                    <tr>
                        <td>网络质量评分</td>
                        <td><?php echo esc_html($performance_data['concurrent']['network_stats']['network_quality_score'] ?? 'N/A'); ?></td>
                        <td>基于响应时间和失败率的网络质量评分</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- API缓存详细统计 -->
        <?php if (!empty($performance_data['api'])): ?>
        <div class="performance-section">
            <h3>API缓存统计</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>指标</th>
                        <th>当前值</th>
                        <th>说明</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>内存缓存命中</td>
                        <td><?php echo esc_html($performance_data['api']['cache_performance']['memory_hits'] ?? 0); ?></td>
                        <td>内存缓存命中次数</td>
                    </tr>
                    <tr>
                        <td>持久化缓存命中</td>
                        <td><?php echo esc_html($performance_data['api']['cache_performance']['persistent_hits'] ?? 0); ?></td>
                        <td>持久化缓存命中次数</td>
                    </tr>
                    <tr>
                        <td>缓存未命中</td>
                        <td><?php echo esc_html($performance_data['api']['cache_performance']['cache_misses'] ?? 0); ?></td>
                        <td>缓存未命中次数</td>
                    </tr>
                    <tr>
                        <td>节省的API调用</td>
                        <td><?php echo esc_html($performance_data['api']['request_merge_performance']['saved_api_calls'] ?? 0); ?></td>
                        <td>通过请求合并节省的API调用次数</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- 性能测试结果 -->
    <div id="performance-test-results" style="display: none;">
        <h2>性能测试结果</h2>
        <div id="test-results-content"></div>
    </div>

    <!-- 数据更新时间 -->
    <div class="performance-timestamp" style="margin-top: 30px; padding: 10px; background: #f0f0f1; border-radius: 4px;">
        <p><strong>数据更新时间：</strong> <?php echo esc_html($performance_data['formatted_time'] ?? '未知'); ?></p>
    </div>
</div>

<style>
.performance-grade {
    padding: 2px 8px;
    border-radius: 3px;
    font-weight: bold;
}
.grade-a { background: #46b450; color: white; }
.grade-b { background: #00a0d2; color: white; }
.grade-c { background: #ffb900; color: white; }
.grade-d { background: #dc3232; color: white; }
.grade-f { background: #666; color: white; }
.grade-unknown { background: #ddd; color: #666; }

.performance-section {
    margin: 20px 0;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.performance-section h3 {
    margin: 0;
    padding: 15px 20px;
    background: #f6f7f7;
    border-bottom: 1px solid #ccd0d4;
}

.performance-section table {
    margin: 0;
}
</style>

<script>
jQuery(document).ready(function($) {
    // 刷新性能数据
    $('#refresh-performance-data').on('click', function() {
        var $button = $(this);
        var $loading = $('#performance-loading');
        
        $button.prop('disabled', true);
        $loading.show();
        
        $.post(ajaxurl, {
            action: 'refresh_performance_data',
            action_type: 'get_stats',
            nonce: '<?php echo wp_create_nonce('performance_ajax'); ?>'
        }, function(response) {
            if (response.success) {
                location.reload(); // 简单的页面刷新
            } else {
                alert('刷新失败：' + (response.error || '未知错误'));
            }
        }).always(function() {
            $button.prop('disabled', false);
            $loading.hide();
        });
    });
    
    // 重置统计数据
    $('#reset-performance-stats').on('click', function() {
        if (!confirm('确定要重置所有性能统计数据吗？此操作不可撤销。')) {
            return;
        }
        
        var $button = $(this);
        var $loading = $('#performance-loading');
        
        $button.prop('disabled', true);
        $loading.show();
        
        $.post(ajaxurl, {
            action: 'refresh_performance_data',
            action_type: 'reset_stats',
            nonce: '<?php echo wp_create_nonce('performance_ajax'); ?>'
        }, function(response) {
            if (response.success) {
                alert('统计数据已重置');
                location.reload();
            } else {
                alert('重置失败：' + (response.error || '未知错误'));
            }
        }).always(function() {
            $button.prop('disabled', false);
            $loading.hide();
        });
    });
    
    // 运行性能测试
    $('#run-performance-test').on('click', function() {
        var $button = $(this);
        var $loading = $('#performance-loading');
        var $results = $('#performance-test-results');
        
        $button.prop('disabled', true);
        $loading.show();
        
        $.post(ajaxurl, {
            action: 'refresh_performance_data',
            action_type: 'run_performance_test',
            nonce: '<?php echo wp_create_nonce('performance_ajax'); ?>'
        }, function(response) {
            if (response.success) {
                displayTestResults(response.data);
                $results.show();
            } else {
                alert('测试失败：' + (response.error || '未知错误'));
            }
        }).always(function() {
            $button.prop('disabled', false);
            $loading.hide();
        });
    });
    
    function displayTestResults(data) {
        var html = '<div class="test-results">';
        html += '<h3>测试概览</h3>';
        html += '<p><strong>综合评分：</strong> ' + data.overall_score + '/100</p>';
        html += '<p><strong>测试时长：</strong> ' + data.test_duration + 'ms</p>';
        
        if (data.api_test && data.api_test.response_time) {
            html += '<h4>API性能测试</h4>';
            html += '<p><strong>响应时间：</strong> ' + data.api_test.response_time + 'ms</p>';
            html += '<p><strong>状态：</strong> ' + data.api_test.status + '</p>';
        }
        
        if (data.recommendations && data.recommendations.length > 0) {
            html += '<h4>优化建议</h4>';
            html += '<ul>';
            data.recommendations.forEach(function(rec) {
                html += '<li>' + rec + '</li>';
            });
            html += '</ul>';
        }
        
        html += '</div>';
        
        $('#test-results-content').html(html);
    }
});
</script>
