<?php
/**
 * 性能配置页面。
 * 此文件负责渲染性能配置页面，允许用户调整并发、缓存和网络等参数。
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
        <p><strong>性能配置说明：</strong>调整这些参数可以优化插件性能。建议在了解各参数含义后进行调整，错误的配置可能影响系统稳定性。</p>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field('save_performance_config', 'performance_config_nonce'); ?>
        
        <div class="performance-config-sections">
            
            <!-- 并发管理器配置 -->
            <div class="config-section">
                <h2>并发管理器配置</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="concurrent_max_requests">最大并发请求数</label>
                        </th>
                        <td>
                            <input type="number" id="concurrent_max_requests" name="concurrent_max_requests" 
                                   value="<?php echo esc_attr($current_config['concurrent_max_requests']); ?>" 
                                   min="1" max="100" class="small-text" />
                            <p class="description">同时发送的最大API请求数量。建议值：15-30</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="concurrent_adaptive_enabled">启用自适应调节</label>
                        </th>
                        <td>
                            <input type="checkbox" id="concurrent_adaptive_enabled" name="concurrent_adaptive_enabled" 
                                   <?php checked($current_config['concurrent_adaptive_enabled']); ?> />
                            <p class="description">根据网络状况自动调整并发数量</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="concurrent_target_response_time">目标响应时间 (ms)</label>
                        </th>
                        <td>
                            <input type="number" id="concurrent_target_response_time" name="concurrent_target_response_time" 
                                   value="<?php echo esc_attr($current_config['concurrent_target_response_time']); ?>" 
                                   min="500" max="10000" class="small-text" />
                            <p class="description">自适应调节的目标响应时间。建议值：1500-3000ms</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="concurrent_adjustment_threshold">调节阈值</label>
                        </th>
                        <td>
                            <input type="number" id="concurrent_adjustment_threshold" name="concurrent_adjustment_threshold" 
                                   value="<?php echo esc_attr($current_config['concurrent_adjustment_threshold']); ?>" 
                                   min="0.05" max="0.5" step="0.05" class="small-text" />
                            <p class="description">触发自适应调节的阈值。建议值：0.1-0.2</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 缓存配置 -->
            <div class="config-section">
                <h2>缓存配置</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="cache_memory_ttl">内存缓存TTL (秒)</label>
                        </th>
                        <td>
                            <input type="number" id="cache_memory_ttl" name="cache_memory_ttl" 
                                   value="<?php echo esc_attr($current_config['cache_memory_ttl']); ?>" 
                                   min="300" max="7200" class="small-text" />
                            <p class="description">内存缓存的生存时间。建议值：1800-3600秒</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cache_transient_ttl">持久化缓存TTL (秒)</label>
                        </th>
                        <td>
                            <input type="number" id="cache_transient_ttl" name="cache_transient_ttl" 
                                   value="<?php echo esc_attr($current_config['cache_transient_ttl']); ?>" 
                                   min="600" max="86400" class="small-text" />
                            <p class="description">持久化缓存的生存时间。建议值：3600-7200秒</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cache_preload_enabled">启用缓存预热</label>
                        </th>
                        <td>
                            <input type="checkbox" id="cache_preload_enabled" name="cache_preload_enabled" 
                                   <?php checked($current_config['cache_preload_enabled']); ?> />
                            <p class="description">启用缓存预热可以提升首次访问速度</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cache_preload_max_size">预热最大数量</label>
                        </th>
                        <td>
                            <input type="number" id="cache_preload_max_size" name="cache_preload_max_size" 
                                   value="<?php echo esc_attr($current_config['cache_preload_max_size']); ?>" 
                                   min="10" max="1000" class="small-text" />
                            <p class="description">缓存预热的最大项目数量。建议值：50-200</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 分页处理配置 -->
            <div class="config-section">
                <h2>分页处理配置</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="pagination_enabled">启用分页处理</label>
                        </th>
                        <td>
                            <input type="checkbox" id="pagination_enabled" name="pagination_enabled" 
                                   <?php checked($current_config['pagination_enabled']); ?> />
                            <p class="description">启用分页处理可以防止内存溢出</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pagination_default_size">默认页面大小</label>
                        </th>
                        <td>
                            <input type="number" id="pagination_default_size" name="pagination_default_size" 
                                   value="<?php echo esc_attr($current_config['pagination_default_size']); ?>" 
                                   min="5" max="100" class="small-text" />
                            <p class="description">每批处理的页面数量。建议值：15-30</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pagination_max_size">最大页面大小</label>
                        </th>
                        <td>
                            <input type="number" id="pagination_max_size" name="pagination_max_size" 
                                   value="<?php echo esc_attr($current_config['pagination_max_size']); ?>" 
                                   min="10" max="200" class="small-text" />
                            <p class="description">每批处理的最大页面数量。建议值：40-80</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pagination_memory_threshold">内存阈值 (%)</label>
                        </th>
                        <td>
                            <input type="number" id="pagination_memory_threshold" name="pagination_memory_threshold" 
                                   value="<?php echo esc_attr($current_config['pagination_memory_threshold']); ?>" 
                                   min="50" max="90" class="small-text" />
                            <p class="description">触发分页处理的内存使用率阈值。建议值：65-75%</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 网络配置 -->
            <div class="config-section">
                <h2>网络配置</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="network_base_timeout">基础超时时间 (秒)</label>
                        </th>
                        <td>
                            <input type="number" id="network_base_timeout" name="network_base_timeout" 
                                   value="<?php echo esc_attr($current_config['network_base_timeout']); ?>" 
                                   min="3" max="60" class="small-text" />
                            <p class="description">网络请求的基础超时时间。建议值：6-12秒</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="network_connect_timeout">连接超时时间 (秒)</label>
                        </th>
                        <td>
                            <input type="number" id="network_connect_timeout" name="network_connect_timeout" 
                                   value="<?php echo esc_attr($current_config['network_connect_timeout']); ?>" 
                                   min="1" max="30" class="small-text" />
                            <p class="description">网络连接的超时时间。建议值：2-5秒</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="network_keepalive_enabled">启用Keep-Alive</label>
                        </th>
                        <td>
                            <input type="checkbox" id="network_keepalive_enabled" name="network_keepalive_enabled" 
                                   <?php checked($current_config['network_keepalive_enabled']); ?> />
                            <p class="description">启用TCP Keep-Alive可以提升连接复用效率</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="network_compression_enabled">启用压缩</label>
                        </th>
                        <td>
                            <input type="checkbox" id="network_compression_enabled" name="network_compression_enabled" 
                                   <?php checked($current_config['network_compression_enabled']); ?> />
                            <p class="description">启用数据压缩可以减少传输时间</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="network_adaptive_timeout">启用自适应超时</label>
                        </th>
                        <td>
                            <input type="checkbox" id="network_adaptive_timeout" name="network_adaptive_timeout" 
                                   <?php checked($current_config['network_adaptive_timeout']); ?> />
                            <p class="description">根据网络质量自动调整超时时间</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 内存管理配置 -->
            <div class="config-section">
                <h2>内存管理配置</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="memory_monitoring_enabled">启用内存监控</label>
                        </th>
                        <td>
                            <input type="checkbox" id="memory_monitoring_enabled" name="memory_monitoring_enabled" 
                                   <?php checked($current_config['memory_monitoring_enabled']); ?> />
                            <p class="description">启用内存监控可以及时发现内存问题</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="memory_warning_threshold">警告阈值 (%)</label>
                        </th>
                        <td>
                            <input type="number" id="memory_warning_threshold" name="memory_warning_threshold" 
                                   value="<?php echo esc_attr($current_config['memory_warning_threshold']); ?>" 
                                   min="50" max="90" class="small-text" />
                            <p class="description">内存使用率警告阈值。建议值：65-75%</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="memory_critical_threshold">严重阈值 (%)</label>
                        </th>
                        <td>
                            <input type="number" id="memory_critical_threshold" name="memory_critical_threshold" 
                                   value="<?php echo esc_attr($current_config['memory_critical_threshold']); ?>" 
                                   min="70" max="95" class="small-text" />
                            <p class="description">内存使用率严重警告阈值。建议值：80-90%</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="memory_emergency_threshold">紧急阈值 (%)</label>
                        </th>
                        <td>
                            <input type="number" id="memory_emergency_threshold" name="memory_emergency_threshold" 
                                   value="<?php echo esc_attr($current_config['memory_emergency_threshold']); ?>" 
                                   min="80" max="98" class="small-text" />
                            <p class="description">内存使用率紧急处理阈值。建议值：90-95%</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <p class="submit">
            <input type="submit" name="save_performance_config" class="button-primary" value="保存配置" />
            <button type="button" class="button" id="reset-to-defaults">恢复默认值</button>
        </p>
    </form>

    <!-- 配置预设 -->
    <div class="config-presets" style="margin-top: 30px;">
        <h2>配置预设</h2>
        <p>根据您的使用场景选择合适的配置预设：</p>
        
        <div class="preset-buttons" style="margin: 15px 0;">
            <button type="button" class="button preset-button" data-preset="conservative">
                保守配置 (适合小型网站)
            </button>
            <button type="button" class="button preset-button" data-preset="balanced">
                平衡配置 (适合中型网站)
            </button>
            <button type="button" class="button preset-button" data-preset="aggressive">
                激进配置 (适合大型网站)
            </button>
        </div>
    </div>
</div>

<style>
.config-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    margin: 20px 0;
}

.config-section h2 {
    margin: 0;
    padding: 15px 20px;
    background: #f6f7f7;
    border-bottom: 1px solid #ccd0d4;
}

.config-section .form-table {
    margin: 0;
}

.preset-buttons .button {
    margin-right: 10px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // 恢复默认值
    $('#reset-to-defaults').on('click', function() {
        if (!confirm('确定要恢复所有配置到默认值吗？')) {
            return;
        }
        
        // 设置默认值
        var defaults = <?php echo json_encode($this->performance_config_defaults); ?>;
        
        $.each(defaults, function(key, value) {
            var $field = $('[name="' + key + '"]');
            if ($field.attr('type') === 'checkbox') {
                $field.prop('checked', value);
            } else {
                $field.val(value);
            }
        });
        
        alert('已恢复默认配置，请点击"保存配置"以应用更改。');
    });
    
    // 配置预设
    $('.preset-button').on('click', function() {
        var preset = $(this).data('preset');
        
        if (!confirm('确定要应用 ' + $(this).text() + ' 吗？这将覆盖当前配置。')) {
            return;
        }
        
        var presets = {
            conservative: {
                concurrent_max_requests: 15,
                concurrent_adaptive_enabled: true,
                concurrent_target_response_time: 3000,
                cache_memory_ttl: 1200,
                cache_transient_ttl: 2400,
                pagination_default_size: 15,
                pagination_max_size: 30,
                network_base_timeout: 10,
                network_connect_timeout: 4
            },
            balanced: {
                concurrent_max_requests: 25,
                concurrent_adaptive_enabled: true,
                concurrent_target_response_time: 2000,
                cache_memory_ttl: 1800,
                cache_transient_ttl: 3600,
                pagination_default_size: 20,
                pagination_max_size: 50,
                network_base_timeout: 8,
                network_connect_timeout: 3
            },
            aggressive: {
                concurrent_max_requests: 40,
                concurrent_adaptive_enabled: true,
                concurrent_target_response_time: 1500,
                cache_memory_ttl: 2400,
                cache_transient_ttl: 7200,
                pagination_default_size: 30,
                pagination_max_size: 80,
                network_base_timeout: 6,
                network_connect_timeout: 2
            }
        };
        
        var config = presets[preset];
        if (config) {
            $.each(config, function(key, value) {
                var $field = $('[name="' + key + '"]');
                if ($field.attr('type') === 'checkbox') {
                    $field.prop('checked', value);
                } else {
                    $field.val(value);
                }
            });
            
            alert('已应用 ' + $(this).text() + '，请点击"保存配置"以应用更改。');
        }
    });
});
</script>
