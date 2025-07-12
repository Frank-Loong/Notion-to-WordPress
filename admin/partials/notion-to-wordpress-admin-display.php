<?php
// 声明严格类型
declare(strict_types=1);

/**
 * 插件后台管理页面视图
 *
 * 本文件用于标记插件后台界面相关内容。
 *
 * @since      1.0.9
 * @package    Notion_To_WordPress
 */

// 如果直接访问本文件，则终止执行。
if (!defined('WPINC')) {
    die;
}

// 一次性获取所有选项
$options = get_option('notion_to_wordpress_options', []);

// 从选项数组中安全获取值，带默认值
$api_key               = $options['notion_api_key'] ?? '';
$database_id           = $options['notion_database_id'] ?? '';
$sync_schedule         = $options['sync_schedule'] ?? 'manual';
$delete_on_uninstall   = $options['delete_on_uninstall'] ?? 0;
$field_mapping         = $options['field_mapping'] ?? [
    'title'          => 'Title,标题',
    'status'         => 'Status,状态',
    'post_type'      => 'Type,类型',
    'date'           => 'Date,日期',
    'excerpt'        => 'Summary,摘要,Excerpt',
    'featured_image' => 'Featured Image,特色图片',
    'categories'     => 'Categories,分类,Category',
    'tags'           => 'Tags,标签,Tag',
    'password'       => 'Password,密码',
];
$debug_level           = $options['debug_level'] ?? Notion_To_WordPress_Helper::DEBUG_LEVEL_ERROR;
$max_image_size        = $options['max_image_size'] ?? 5;
$plugin_language       = $options['plugin_language'] ?? 'auto';

// 为内联脚本生成 nonce
$script_nonce = wp_create_nonce('notion_wp_script_nonce');

?>
<div class="wrap notion-wp-admin">
    <div class="notion-wp-header">
        <div class="notion-wp-header-content">
            <h1 class="wp-heading-inline">
                <span class="notion-wp-logo"></span>
                <?php _e('Notion to WordPress', 'notion-to-wordpress'); ?>
            </h1>
            <div class="notion-wp-version"><?php echo esc_html( NOTION_TO_WORDPRESS_VERSION ); ?></div>
        </div>
    </div>

    <?php settings_errors('notion_wp_messages'); ?>
    
    <div class="notion-wp-layout">
        <div class="notion-wp-sidebar">
            <div class="notion-wp-menu">
                <button class="notion-wp-menu-item active" data-tab="api-settings">
                    <?php esc_html_e('🛠️ 主要设置', 'notion-to-wordpress'); ?>
                </button>
                <button class="notion-wp-menu-item" data-tab="field-mapping">
                    <?php esc_html_e('🔗 字段映射', 'notion-to-wordpress'); ?>
                </button>
                <button class="notion-wp-menu-item" data-tab="other-settings">
                    <?php esc_html_e('⚙️ 其他设置', 'notion-to-wordpress'); ?>
                </button>
                <button class="notion-wp-menu-item" data-tab="advanced-config">
                    <?php esc_html_e('🛠️ 高级配置', 'notion-to-wordpress'); ?>
                </button>
                <button class="notion-wp-menu-item" data-tab="debug">
                    <?php esc_html_e('🐞 调试工具', 'notion-to-wordpress'); ?>
                </button>
                <button class="notion-wp-menu-item" data-tab="help">
                    <?php esc_html_e('📖 帮助与指南', 'notion-to-wordpress'); ?>
                </button>
                <button class="notion-wp-menu-item" data-tab="about-author">
                    <?php esc_html_e('👨‍💻 关于作者', 'notion-to-wordpress'); ?>
                </button>
            </div>
        </div>
        
        <div class="notion-wp-content">
            <form id="notion-to-wordpress-settings-form" method="post" action="admin-post.php">
                <input type="hidden" name="action" value="notion_to_wordpress_options">
                <?php wp_nonce_field('notion_to_wordpress_options_update', 'notion_to_wordpress_options_nonce'); ?>

                <div class="notion-wp-tab-content active" id="api-settings">
                    <div class="notion-wp-settings-section">
                        <h2><?php esc_html_e('Notion API 设置', 'notion-to-wordpress'); ?></h2>
                        
                        <div class="notion-stats-grid">
                            <div class="stat-card">
                                <h3 class="stat-imported-count">0</h3>
                                <span><?php esc_html_e('已导入页面', 'notion-to-wordpress'); ?></span>
                            </div>
                            <div class="stat-card">
                                <h3 class="stat-published-count">0</h3>
                                <span><?php esc_html_e('已发布页面', 'notion-to-wordpress'); ?></span>
                            </div>
                            <div class="stat-card">
                                <h3 class="stat-last-update"><?php esc_html_e('从未', 'notion-to-wordpress'); ?></h3>
                                <span><?php esc_html_e('最后同步', 'notion-to-wordpress'); ?></span>
                            </div>
                            <div class="stat-card">
                                <h3 class="stat-next-run"><?php esc_html_e('未计划', 'notion-to-wordpress'); ?></h3>
                                <span><?php esc_html_e('下次同步', 'notion-to-wordpress'); ?></span>
                            </div>
                        </div>
                        
                        <p class="description">
                            <?php esc_html_e('连接到您的Notion数据库所需的设置。', 'notion-to-wordpress'); ?>
                            <a href="https://developers.notion.com/docs/getting-started" target="_blank"><?php esc_html_e('了解如何获取API密钥', 'notion-to-wordpress'); ?></a>
                        </p>
                        
                        <table class="form-table">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="notion_to_wordpress_api_key"><?php esc_html_e('API密钥', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <div class="input-with-button">
                                            <input type="password" id="notion_to_wordpress_api_key" name="notion_to_wordpress_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e('输入您的Notion API密钥', 'notion-to-wordpress'); ?>">
                                            <button type="button" class="button button-secondary show-hide-password" title="<?php esc_attr_e('显示/隐藏密钥', 'notion-to-wordpress'); ?>"><span class="dashicons dashicons-visibility"></span></button>
                                        </div>
                                        <p class="description"><?php esc_html_e('在Notion的"我的集成"页面创建并获取API密钥。', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="notion_to_wordpress_database_id"><?php esc_html_e('数据库ID', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <input type="text" id="notion_to_wordpress_database_id" name="notion_to_wordpress_database_id" value="<?php echo esc_attr($database_id); ?>" class="regular-text" placeholder="<?php esc_attr_e('输入您的Notion数据库ID', 'notion-to-wordpress'); ?>">
                                        <p class="description"><?php echo wp_kses( __('可以从Notion数据库URL中找到，格式如：https://www.notion.so/xxx/<strong>数据库ID</strong>?v=xxx', 'notion-to-wordpress'), ['strong' => []] ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sync_schedule"><?php esc_html_e('自动同步频率', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <select id="sync_schedule" name="sync_schedule" class="regular-text">
                                            <?php
                                            $schedules = [
                                                'manual'     => __('手动同步', 'notion-to-wordpress'),
                                                'twicedaily' => __('每天两次', 'notion-to-wordpress'),
                                                'daily'      => __('每天一次', 'notion-to-wordpress'),
                                                'weekly'     => __('每周一次', 'notion-to-wordpress'),
                                                'biweekly'   => __('每两周一次', 'notion-to-wordpress'),
                                                'monthly'    => __('每月一次', 'notion-to-wordpress'),
                                            ];
                                            foreach ($schedules as $value => $label) {
                                                echo '<option value="' . esc_attr($value) . '" ' . selected($sync_schedule, $value, false) . '>' . esc_html($label) . '</option>';
                                            }
                                            ?>
                                        </select>
                                        <p class="description"><?php esc_html_e('选择 "手动同步" 以禁用定时任务。', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('定时同步选项', 'notion-to-wordpress'); ?></th>
                                    <td>
                                        <?php
                                        $cron_incremental_sync = $options['cron_incremental_sync'] ?? 1;
                                        $cron_check_deletions = $options['cron_check_deletions'] ?? 1;
                                        ?>
                                        <fieldset>
                                            <label>
                                                <input type="checkbox" name="cron_incremental_sync" value="1" <?php checked($cron_incremental_sync, 1); ?>>
                                                <?php esc_html_e('启用增量同步', 'notion-to-wordpress'); ?>
                                            </label>
                                            <p class="description"><?php esc_html_e('仅同步有变化的页面，提高同步速度', 'notion-to-wordpress'); ?></p>

                                            <label>
                                                <input type="checkbox" name="cron_check_deletions" value="1" <?php checked($cron_check_deletions, 1); ?>>
                                                <?php esc_html_e('检查删除的页面', 'notion-to-wordpress'); ?>
                                            </label>
                                            <p class="description"><?php esc_html_e('自动删除在Notion中已删除但WordPress中仍存在的文章', 'notion-to-wordpress'); ?></p>
                                        </fieldset>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="webhook_enabled"><?php esc_html_e('Webhook 支持', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <?php 
                                        $webhook_enabled = $options['webhook_enabled'] ?? 0;
                                        $verification_token = $options['webhook_verify_token'] ?? '';
                                        $webhook_token = $options['webhook_token'] ?? Notion_To_WordPress_Helper::generate_token(32);
                                        $webhook_url = site_url('wp-json/notion-to-wordpress/v1/webhook/' . $webhook_token);
                                        ?>
                                        <label for="webhook_enabled" class="checkbox-with-label">
                                            <input type="checkbox" id="webhook_enabled" name="webhook_enabled" value="1" <?php checked(1, $webhook_enabled); ?>>
                                            <span><?php esc_html_e('启用 Webhook 支持', 'notion-to-wordpress'); ?></span>
                                        </label>
                                        <p class="description"><?php esc_html_e('启用后，您可以设置 Notion 集成的 Webhook 以在内容变更时自动触发同步。', 'notion-to-wordpress'); ?></p>
                                        
                                        <div id="webhook-settings" style="<?php echo $webhook_enabled ? '' : 'display: none;'; ?>" class="notion-wp-subsetting">
                                            <div class="notion-wp-field">
                                                <label for="verification_token"><?php esc_html_e('验证令牌', 'notion-to-wordpress'); ?></label>
                                                <div class="input-with-button">
                                                    <input type="text" id="verification_token" value="<?php echo esc_attr($verification_token); ?>" class="regular-text" readonly placeholder="<?php esc_attr_e('等待 Notion 返回…', 'notion-to-wordpress'); ?>">
                                                    <button type="button" class="button button-secondary" id="refresh-verification-token"
                                                        title="<?php esc_attr_e('刷新验证令牌', 'notion-to-wordpress'); ?>">
                                                        <span class="dashicons dashicons-update"></span>
                                                    </button>
                                                    <button type="button" class="button button-secondary copy-to-clipboard"
                                                        data-clipboard-target="#verification_token"
                                                        onclick="window.copyTextToClipboard(document.getElementById('verification_token').value, function(success) { if(success) window.showModal(notionToWp.i18n.copied, 'success'); });"
                                                        title="<?php esc_attr_e('复制令牌', 'notion-to-wordpress'); ?>">
                                                        <span class="dashicons dashicons-clipboard"></span>
                                                    </button>
                                                </div>
                                                <p class="description"><?php esc_html_e('首次发送 Webhook 时，Notion 将返回 verification_token，此处会自动展示。点击刷新按钮可获取最新的令牌。', 'notion-to-wordpress'); ?></p>
                                            </div>
                                            <div class="notion-wp-field">
                                                <label for="webhook_url"><?php esc_html_e('Webhook 地址', 'notion-to-wordpress'); ?></label>
                                                <div class="input-with-button">
                                                    <input type="text" id="webhook_url" value="<?php echo esc_url($webhook_url); ?>" class="regular-text" readonly>
                                                    <button type="button" class="button button-secondary copy-to-clipboard"
                                                        data-clipboard-target="#webhook_url"
                                                        title="<?php esc_attr_e('复制 URL', 'notion-to-wordpress'); ?>">
                                                        <span class="dashicons dashicons-clipboard"></span>
                                                    </button>
                                                </div>
                                                <p class="description"><?php esc_html_e('在 Notion 开发者平台设置此 URL 作为您集成的 Webhook 终端点。', 'notion-to-wordpress'); ?></p>
                                            </div>

                                            <div class="notion-wp-field">
                                                <label><?php esc_html_e('Webhook 同步选项', 'notion-to-wordpress'); ?></label>
                                                <?php
                                                $webhook_incremental = $options['webhook_incremental_sync'] ?? 1;
                                                $webhook_check_deletions = $options['webhook_check_deletions'] ?? 1;
                                                ?>
                                                <fieldset>
                                                    <label>
                                                        <input type="checkbox" name="webhook_incremental_sync" value="1" <?php checked($webhook_incremental, 1); ?>>
                                                        <?php esc_html_e('启用增量同步', 'notion-to-wordpress'); ?>
                                                    </label>
                                                    <p class="description"><?php esc_html_e('Webhook触发时仅同步有变化的页面，提高响应速度', 'notion-to-wordpress'); ?></p>

                                                    <label>
                                                        <input type="checkbox" name="webhook_check_deletions" value="1" <?php checked($webhook_check_deletions, 1); ?>>
                                                        <?php esc_html_e('数据库事件检查删除', 'notion-to-wordpress'); ?>
                                                    </label>
                                                    <p class="description"><?php esc_html_e('数据库结构变化时检查删除的页面（单页面事件不受影响）', 'notion-to-wordpress'); ?></p>
                                                </fieldset>
                                            </div>
                                        </div>
                                        <script>
                                            document.addEventListener('DOMContentLoaded', function() {
                                                const webhookEnabledCheckbox = document.getElementById('webhook_enabled');
                                                const webhookSettings = document.getElementById('webhook-settings');
                                                
                                                webhookEnabledCheckbox.addEventListener('change', function() {
                                                    webhookSettings.style.display = this.checked ? 'block' : 'none';
                                                });
                                            });
                                        </script>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="notion-wp-button-row">
                            <button type="button" id="notion-test-connection" class="button button-secondary">
                                <span class="dashicons dashicons-admin-network"></span> <?php esc_html_e('测试连接', 'notion-to-wordpress'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="notion-wp-tab-content" id="field-mapping">
                    <div class="notion-wp-settings-section">
                        <h2><?php esc_html_e('字段映射', 'notion-to-wordpress'); ?></h2>
                        <p class="description"><?php esc_html_e('设置您的Notion数据库属性名称与WordPress字段的对应关系。多个备选名称请用英文逗号隔开。', 'notion-to-wordpress'); ?></p>
                        <table class="form-table">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="mapping_title"><?php esc_html_e('文章标题', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <input name="field_mapping[title]" type="text" id="mapping_title" value="<?php echo esc_attr($field_mapping['title']); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('用于设置WordPress文章标题的Notion属性名称', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="mapping_status"><?php esc_html_e('状态', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <input name="field_mapping[status]" type="text" id="mapping_status" value="<?php echo esc_attr($field_mapping['status']); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('值为 "Published" 或 "已发布" 的页面会被设为 "已发布" 状态，其他则为 "草稿"。', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="mapping_post_type"><?php esc_html_e('文章类型', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <input name="field_mapping[post_type]" type="text" id="mapping_post_type" value="<?php echo esc_attr($field_mapping['post_type']); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('用于确定WordPress文章类型的Notion属性名称', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="mapping_date"><?php esc_html_e('日期', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <input name="field_mapping[date]" type="text" id="mapping_date" value="<?php echo esc_attr($field_mapping['date']); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('用于设置WordPress文章发布日期的Notion属性名称', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="mapping_excerpt"><?php esc_html_e('摘要', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <input name="field_mapping[excerpt]" type="text" id="mapping_excerpt" value="<?php echo esc_attr($field_mapping['excerpt']); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('用于设置WordPress文章摘要的Notion属性名称', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="mapping_featured_image"><?php esc_html_e('特色图片', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <input name="field_mapping[featured_image]" type="text" id="mapping_featured_image" value="<?php echo esc_attr($field_mapping['featured_image']); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('用于设置WordPress特色图片的Notion属性名称（应为URL或文件类型）', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="mapping_categories"><?php esc_html_e('分类', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <input name="field_mapping[categories]" type="text" id="mapping_categories" value="<?php echo esc_attr($field_mapping['categories']); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('用于设置WordPress文章分类的Notion属性名称', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="mapping_tags"><?php esc_html_e('标签', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <input name="field_mapping[tags]" type="text" id="mapping_tags" value="<?php echo esc_attr($field_mapping['tags']); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('用于设置WordPress文章标签的Notion属性名称', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="mapping_password"><?php esc_html_e('文章密码', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <input name="field_mapping[password]" type="text" id="mapping_password" value="<?php echo esc_attr($field_mapping['password'] ?? 'Password,密码'); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('用于设置WordPress文章密码的Notion属性名称', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="notion-wp-settings-section">
                            <h3><?php esc_html_e('自定义字段映射', 'notion-to-wordpress'); ?></h3>
                            <p class="description"><?php esc_html_e('将Notion属性映射到WordPress自定义字段。您可以添加任意数量的自定义字段映射。', 'notion-to-wordpress'); ?></p>
                            
                            <div id="custom-field-mappings">
                                <?php
                                // 获取已保存的自定义字段映射
                                $custom_field_mappings = $options['custom_field_mappings'] ?? [];
                                
                                // 如果不存在映射，则添加一个空的默认映射
                                if (empty($custom_field_mappings)) {
                                    $custom_field_mappings = [
                                        [
                                            'notion_property' => '',
                                            'wp_field' => '',
                                            'field_type' => 'text'
                                        ]
                                    ];
                                }
                                
                                // 字段类型选项
                                $field_types = [
                                    'text' => __('文本', 'notion-to-wordpress'),
                                    'number' => __('数字', 'notion-to-wordpress'),
                                    'date' => __('日期', 'notion-to-wordpress'),
                                    'checkbox' => __('复选框', 'notion-to-wordpress'),
                                    'select' => __('选择', 'notion-to-wordpress'),
                                    'multi_select' => __('多选', 'notion-to-wordpress'),
                                    'url' => __('URL', 'notion-to-wordpress'),
                                    'email' => __('电子邮件', 'notion-to-wordpress'),
                                    'phone' => __('电话', 'notion-to-wordpress'),
                                    'rich_text' => __('富文本', 'notion-to-wordpress'),
                                ];
                                
                                foreach ($custom_field_mappings as $index => $mapping) :
                                ?>
                                <div class="custom-field-mapping">
                                    <div class="custom-field-row">
                                        <div class="custom-field-col">
                                            <label><?php esc_html_e('Notion属性名称', 'notion-to-wordpress'); ?></label>
                                            <input type="text" name="custom_field_mappings[<?php echo $index; ?>][notion_property]" 
                                                value="<?php echo esc_attr($mapping['notion_property'] ?? ''); ?>" 
                                                class="regular-text" placeholder="<?php esc_attr_e('例如：Author,作者', 'notion-to-wordpress'); ?>">
                                            <p class="description"><?php esc_html_e('Notion中的属性名称，多个备选名称请用英文逗号分隔', 'notion-to-wordpress'); ?></p>
                                        </div>
                                        <div class="custom-field-col">
                                            <label><?php esc_html_e('WordPress字段名称', 'notion-to-wordpress'); ?></label>
                                            <input type="text" name="custom_field_mappings[<?php echo $index; ?>][wp_field]" 
                                                value="<?php echo esc_attr($mapping['wp_field'] ?? ''); ?>" 
                                                class="regular-text" placeholder="<?php esc_attr_e('例如：author', 'notion-to-wordpress'); ?>">
                                            <p class="description"><?php esc_html_e('WordPress中的自定义字段名称', 'notion-to-wordpress'); ?></p>
                                        </div>
                                        <div class="custom-field-col">
                                            <label><?php esc_html_e('字段类型', 'notion-to-wordpress'); ?></label>
                                            <select name="custom_field_mappings[<?php echo $index; ?>][field_type]" class="regular-text">
                                                <?php foreach ($field_types as $type => $label) : ?>
                                                    <option value="<?php echo esc_attr($type); ?>" <?php selected($mapping['field_type'] ?? 'text', $type); ?>><?php echo esc_html($label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description"><?php esc_html_e('Notion属性的数据类型', 'notion-to-wordpress'); ?></p>
                                        </div>
                                        <div class="custom-field-actions">
                                            <button type="button" class="button remove-field" <?php echo (count($custom_field_mappings) <= 1) ? 'style="display:none;"' : ''; ?>>
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="notion-wp-button-row">
                                <button type="button" id="add-custom-field" class="button button-secondary">
                                    <span class="dashicons dashicons-database-import"></span> <?php esc_html_e('添加自定义字段', 'notion-to-wordpress'); ?>
                                </button>
                            </div>
                            
                            <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    const container = document.getElementById('custom-field-mappings');
                                    const addButton = document.getElementById('add-custom-field');
                                    
                                    // 添加新字段
                                    addButton.addEventListener('click', function() {
                                        const fields = container.querySelectorAll('.custom-field-mapping');
                                        const newIndex = fields.length;
                                        const fieldTemplate = fields[0].cloneNode(true);
                                        
                                        // 重置字段值
                                        const inputs = fieldTemplate.querySelectorAll('input');
                                        inputs.forEach(input => {
                                            input.value = '';
                                            input.name = input.name.replace(/\[\d+\]/, '[' + newIndex + ']');
                                        });
                                        
                                        // 更新选择框名称
                                        const selects = fieldTemplate.querySelectorAll('select');
                                        selects.forEach(select => {
                                            select.name = select.name.replace(/\[\d+\]/, '[' + newIndex + ']');
                                        });
                                        
                                        // 显示删除按钮
                                        const removeButton = fieldTemplate.querySelector('.remove-field');
                                        removeButton.style.display = 'inline-block';
                                        
                                        container.appendChild(fieldTemplate);
                                        
                                        // 确保所有删除按钮可见
                                        document.querySelectorAll('.remove-field').forEach(btn => {
                                            btn.style.display = 'inline-block';
                                        });
                                    });
                                    
                                    // 删除字段（使用事件委托）
                                    container.addEventListener('click', function(e) {
                                        if (e.target.classList.contains('remove-field') || e.target.closest('.remove-field')) {
                                            const fieldRow = e.target.closest('.custom-field-mapping');
                                            
                                            // 如果只剩一个字段，则不删除
                                            const fields = container.querySelectorAll('.custom-field-mapping');
                                            if (fields.length > 1) {
                                                fieldRow.remove();
                                                
                                                // 如果只剩两个字段，则隐藏删除按钮
                                                if (fields.length === 2) {
                                                    container.querySelector('.remove-field').style.display = 'none';
                                                }
                                                
                                                // 重新索引字段
                                                reindexFields();
                                            }
                                        }
                                    });
                                    
                                    // 重新索引字段
                                    function reindexFields() {
                                        const fields = container.querySelectorAll('.custom-field-mapping');
                                        fields.forEach((field, index) => {
                                            const inputs = field.querySelectorAll('input');
                                            inputs.forEach(input => {
                                                input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
                                            });
                                            
                                            const selects = field.querySelectorAll('select');
                                            selects.forEach(select => {
                                                select.name = select.name.replace(/\[\d+\]/, '[' + index + ']');
                                            });
                                        });
                                    }
                                });
                            </script>
                            
                            <style>
                                .custom-field-mapping {
                                    margin-bottom: 15px;
                                    padding: 15px;
                                    background-color: #f9f9f9;
                                    border: 1px solid #e5e5e5;
                                    border-radius: 4px;
                                }
                                .custom-field-row {
                                    display: flex;
                                    flex-wrap: wrap;
                                    gap: 15px;
                                    align-items: flex-start;
                                }
                                .custom-field-col {
                                    flex: 1;
                                    min-width: 200px;
                                }
                                .custom-field-col label {
                                    display: block;
                                    margin-bottom: 5px;
                                    font-weight: 500;
                                }
                                .custom-field-actions {
                                    display: flex;
                                    align-items: center;
                                    padding-top: 25px;
                                }
                                .remove-field {
                                    color: #cc0000;
                                }
                                .remove-field .dashicons {
                                    margin-top: 3px;
                                }
                            </style>
                        </div>
                    </div>
                </div>

                <div class="notion-wp-tab-content" id="other-settings">
                    <div class="notion-wp-settings-section">
                        <h2><?php esc_html_e('其他设置', 'notion-to-wordpress'); ?></h2>
                        <table class="form-table">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e('卸载设置', 'notion-to-wordpress'); ?></th>
                                    <td>
                                        <fieldset>
                                            <legend class="screen-reader-text"><span><?php esc_html_e('卸载时删除所有同步内容', 'notion-to-wordpress'); ?></span></legend>
                                            <label for="delete_on_uninstall" class="checkbox-with-label">
                                                <input type="checkbox" id="delete_on_uninstall" name="delete_on_uninstall" value="1" <?php checked(1, $delete_on_uninstall); ?>>
                                                <span><?php esc_html_e('卸载插件时，删除所有从Notion同步的文章和页面', 'notion-to-wordpress'); ?></span>
                                            </label>
                                            <p class="description notion-wp-warning"><?php esc_html_e('警告：此操作不可逆！所有通过Notion同步的内容将被永久删除。', 'notion-to-wordpress'); ?></p>
                                        </fieldset>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row"><label for="iframe_whitelist"><?php esc_html_e('iframe 白名单域名', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <?php 
                                        $iframe_whitelist = $options['iframe_whitelist'] ?? 'www.youtube.com,youtu.be,player.bilibili.com,b23.tv,v.qq.com';
                                        ?>
                                        <textarea id="iframe_whitelist" name="iframe_whitelist" class="large-text" rows="3"><?php echo esc_textarea($iframe_whitelist); ?></textarea>
                                        <p class="description"><?php esc_html_e('允许在内容中嵌入的 iframe 域名白名单，多个域名请用英文逗号分隔。输入 * 表示允许所有域名（不推荐）。', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="allowed_image_types"><?php esc_html_e('允许的图片格式', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <?php 
                                        $allowed_image_types = $options['allowed_image_types'] ?? 'image/jpeg,image/png,image/gif,image/webp';
                                        ?>
                                        <textarea id="allowed_image_types" name="allowed_image_types" class="large-text" rows="2"><?php echo esc_textarea($allowed_image_types); ?></textarea>
                                        <p class="description"><?php esc_html_e('允许下载和导入的图片 MIME 类型，多个类型请用英文逗号分隔。输入 * 表示允许所有格式。', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="plugin_language"><?php esc_html_e('插件界面语言', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <?php
                                        // 处理向后兼容：将旧的 force_english_ui 转换为新的 plugin_language
                                        $plugin_language = $options['plugin_language'] ?? 'auto';
                                        if (empty($options['plugin_language']) && !empty($force_english_ui)) {
                                            $plugin_language = 'en_US';
                                        }
                                        ?>
                                        <select id="plugin_language" name="plugin_language">
                                            <option value="auto" <?php selected('auto', $plugin_language); ?>><?php esc_html_e('自动检测（跟随站点语言）', 'notion-to-wordpress'); ?></option>
                                            <option value="zh_CN" <?php selected('zh_CN', $plugin_language); ?>><?php esc_html_e('简体中文', 'notion-to-wordpress'); ?></option>
                                            <option value="en_US" <?php selected('en_US', $plugin_language); ?>><?php esc_html_e('English', 'notion-to-wordpress'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('选择插件界面显示的语言。自动检测将跟随WordPress站点语言设置。', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="max_image_size"><?php esc_html_e('最大图片大小', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <?php 
                                        $max_image_size = $options['max_image_size'] ?? 5;
                                        ?>
                                        <input type="number" id="max_image_size" name="max_image_size" value="<?php echo esc_attr($max_image_size); ?>" class="small-text" min="1" max="20" step="1">
                                        <span><?php esc_html_e('MB', 'notion-to-wordpress'); ?></span>
                                        <p class="description"><?php esc_html_e('允许下载的最大图片大小（以 MB 为单位）。建议不超过 10MB。', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>

                                <!-- 文件安全设置 -->
                                <tr>
                                    <th scope="row">
                                        <label for="file_security_level"><?php esc_html_e('文件安全级别', 'notion-to-wordpress'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $file_security_level = $options['file_security_level'] ?? 'strict';
                                        ?>
                                        <select id="file_security_level" name="file_security_level">
                                            <option value="strict" <?php selected($file_security_level, 'strict'); ?>><?php esc_html_e('严格（推荐）', 'notion-to-wordpress'); ?></option>
                                            <option value="moderate" <?php selected($file_security_level, 'moderate'); ?>><?php esc_html_e('中等', 'notion-to-wordpress'); ?></option>
                                            <option value="permissive" <?php selected($file_security_level, 'permissive'); ?>><?php esc_html_e('宽松（不推荐）', 'notion-to-wordpress'); ?></option>
                                        </select>
                                        <p class="description">
                                            <?php esc_html_e('严格：只允许安全的文件类型；中等：允许常见文件类型但加强验证；宽松：允许更多文件类型（存在安全风险）。', 'notion-to-wordpress'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="allowed_file_types"><?php esc_html_e('额外允许的文件类型', 'notion-to-wordpress'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $allowed_file_types = $options['allowed_file_types'] ?? '';
                                        ?>
                                        <textarea id="allowed_file_types" name="allowed_file_types" rows="3" cols="50" class="large-text"><?php echo esc_textarea($allowed_file_types); ?></textarea>
                                        <p class="description">
                                            <?php esc_html_e('用逗号分隔的文件扩展名，例如：svg,zip,docx。留空使用默认安全设置。', 'notion-to-wordpress'); ?><br>
                                            <strong><?php esc_html_e('警告：', 'notion-to-wordpress'); ?></strong> <?php esc_html_e('某些文件类型可能包含恶意代码，请谨慎添加。', 'notion-to-wordpress'); ?>
                                        </p>

                                        <div class="notion-file-types-help" style="margin-top: 10px;">
                                            <details>
                                                <summary style="cursor: pointer; font-weight: bold;"><?php esc_html_e('查看支持的文件类型', 'notion-to-wordpress'); ?></summary>
                                                <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                                    <p><strong><?php esc_html_e('默认安全类型（无需配置）：', 'notion-to-wordpress'); ?></strong></p>
                                                    <ul style="margin-left: 20px;">
                                                        <li><?php esc_html_e('图片：jpg, jpeg, png, gif, webp, bmp, ico', 'notion-to-wordpress'); ?></li>
                                                        <li><?php esc_html_e('文档：pdf, txt, rtf, csv', 'notion-to-wordpress'); ?></li>
                                                        <li><?php esc_html_e('音频：mp3, wav, ogg, flac, m4a', 'notion-to-wordpress'); ?></li>
                                                        <li><?php esc_html_e('视频：mp4, webm', 'notion-to-wordpress'); ?></li>
                                                        <li><?php esc_html_e('数据：json, xml', 'notion-to-wordpress'); ?></li>
                                                    </ul>

                                                    <p><strong><?php esc_html_e('可选类型（需要配置启用）：', 'notion-to-wordpress'); ?></strong></p>
                                                    <ul style="margin-left: 20px;">
                                                        <li><?php esc_html_e('Office文档：doc, docx, xls, xlsx, ppt, pptx', 'notion-to-wordpress'); ?></li>
                                                        <li><?php esc_html_e('矢量图：svg', 'notion-to-wordpress'); ?></li>
                                                        <li><?php esc_html_e('其他视频：avi, mov, wmv, flv', 'notion-to-wordpress'); ?></li>
                                                        <li><?php esc_html_e('压缩文件：zip, rar, 7z, tar, gz', 'notion-to-wordpress'); ?></li>
                                                    </ul>

                                                    <p style="color: #d63638;"><strong><?php esc_html_e('安全提示：', 'notion-to-wordpress'); ?></strong></p>
                                                    <ul style="margin-left: 20px; color: #d63638;">
                                                        <li><?php esc_html_e('SVG文件可能包含恶意脚本', 'notion-to-wordpress'); ?></li>
                                                        <li><?php esc_html_e('Office文档可能包含宏病毒', 'notion-to-wordpress'); ?></li>
                                                        <li><?php esc_html_e('压缩文件可能包含恶意软件', 'notion-to-wordpress'); ?></li>
                                                    </ul>
                                                </div>
                                            </details>
                                        </div>
                                    </td>
                                </tr>

                                <!-- 缓存性能设置 -->
                                <tr>
                                    <th scope="row" colspan="2">
                                        <h3 style="margin: 20px 0 10px 0; color: #1d2327;"><?php esc_html_e('缓存性能设置', 'notion-to-wordpress'); ?></h3>
                                        <p style="margin: 0; color: #646970; font-weight: normal;"><?php esc_html_e('优化同步性能，减少内存使用和API调用次数', 'notion-to-wordpress'); ?></p>
                                    </th>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="cache_max_items"><?php esc_html_e('最大缓存条目数', 'notion-to-wordpress'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $cache_max_items = $options['cache_max_items'] ?? 1000;
                                        ?>
                                        <input type="number" id="cache_max_items" name="cache_max_items" value="<?php echo esc_attr($cache_max_items); ?>" min="100" max="10000" step="100" />
                                        <p class="description">
                                            <?php esc_html_e('缓存中最多保存的条目数量。数值越大占用内存越多，但缓存命中率越高。推荐值：1000-5000。', 'notion-to-wordpress'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="cache_memory_limit"><?php esc_html_e('缓存内存限制 (MB)', 'notion-to-wordpress'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $cache_memory_limit = $options['cache_memory_limit'] ?? 50;
                                        ?>
                                        <input type="number" id="cache_memory_limit" name="cache_memory_limit" value="<?php echo esc_attr($cache_memory_limit); ?>" min="10" max="500" step="10" />
                                        <p class="description">
                                            <?php esc_html_e('缓存使用的最大内存限制。超过此限制时会自动清理最少使用的缓存。推荐值：50-200MB。', 'notion-to-wordpress'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="cache_ttl"><?php esc_html_e('缓存有效期 (秒)', 'notion-to-wordpress'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $cache_ttl = $options['cache_ttl'] ?? 300;
                                        ?>
                                        <input type="number" id="cache_ttl" name="cache_ttl" value="<?php echo esc_attr($cache_ttl); ?>" min="60" max="3600" step="60" />
                                        <p class="description">
                                            <?php esc_html_e('缓存数据的有效期。过期后会重新从Notion API获取数据。推荐值：300秒（5分钟）。', 'notion-to-wordpress'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="2">
                                        <div style="background: #f0f6fc; border: 1px solid #0969da; border-radius: 6px; padding: 15px; margin: 10px 0;">
                                            <h4 style="margin: 0 0 10px 0; color: #0969da;">💡 <?php esc_html_e('缓存性能提示', 'notion-to-wordpress'); ?></h4>
                                            <ul style="margin: 0; padding-left: 20px; color: #656d76;">
                                                <li><?php esc_html_e('缓存可以显著提高同步速度，减少API调用次数', 'notion-to-wordpress'); ?></li>
                                                <li><?php esc_html_e('内存充足的服务器可以适当增加缓存限制', 'notion-to-wordpress'); ?></li>
                                                <li><?php esc_html_e('频繁更新的内容可以适当减少缓存有效期', 'notion-to-wordpress'); ?></li>
                                                <li><?php esc_html_e('系统会自动清理过期和最少使用的缓存', 'notion-to-wordpress'); ?></li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="notion-wp-tab-content" id="advanced-config">
                    <div class="notion-wp-settings-section">
                        <h2><?php esc_html_e('高级配置', 'notion-to-wordpress'); ?></h2>
                        <p class="description"><?php esc_html_e('调整插件的高级设置以优化性能、安全性和功能。这些设置适用于有经验的用户。', 'notion-to-wordpress'); ?></p>

                        <!-- 配置管理工具 -->
                        <div class="notion-wp-config-management" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin: 20px 0;">
                            <h3><?php esc_html_e('配置管理工具', 'notion-to-wordpress'); ?></h3>
                            <p class="description"><?php esc_html_e('验证、重置、导出配置设置。', 'notion-to-wordpress'); ?></p>

                            <div class="notion-wp-config-tools" style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
                                <button type="button" id="validate-config" class="button button-secondary">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php esc_html_e('验证配置', 'notion-to-wordpress'); ?>
                                </button>

                                <button type="button" id="reset-config" class="button button-secondary" style="color: #d63384;">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php esc_html_e('重置为默认值', 'notion-to-wordpress'); ?>
                                </button>

                                <button type="button" id="export-config" class="button button-secondary">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e('导出配置', 'notion-to-wordpress'); ?>
                                </button>
                            </div>

                            <div id="config-validation-result" style="margin-top: 15px; display: none;"></div>
                        </div>

                        <!-- 查询性能监控 -->
                        <div class="notion-wp-query-performance" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin: 20px 0;">
                            <h3><?php esc_html_e('查询性能监控', 'notion-to-wordpress'); ?></h3>
                            <p class="description"><?php esc_html_e('监控数据库查询性能，识别慢查询和优化机会。', 'notion-to-wordpress'); ?></p>

                            <div class="notion-wp-performance-tools" style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
                                <button type="button" id="refresh-query-stats" class="button button-secondary">
                                    <span class="dashicons dashicons-chart-area"></span>
                                    <?php esc_html_e('刷新统计', 'notion-to-wordpress'); ?>
                                </button>
                            </div>

                            <div id="query-performance-stats" style="margin-top: 15px;">
                                <div class="query-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                                    <div class="stat-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                        <h4 style="margin: 0 0 10px 0; color: #666;">总查询数</h4>
                                        <div class="stat-value" id="total-queries" style="font-size: 24px; font-weight: bold; color: #2271b1;">-</div>
                                    </div>
                                    <div class="stat-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                        <h4 style="margin: 0 0 10px 0; color: #666;">慢查询数</h4>
                                        <div class="stat-value" id="slow-queries" style="font-size: 24px; font-weight: bold; color: #d63384;">-</div>
                                    </div>
                                    <div class="stat-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                        <h4 style="margin: 0 0 10px 0; color: #666;">平均耗时</h4>
                                        <div class="stat-value" id="avg-time" style="font-size: 24px; font-weight: bold; color: #198754;">-</div>
                                    </div>
                                    <div class="stat-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                        <h4 style="margin: 0 0 10px 0; color: #666;">最大耗时</h4>
                                        <div class="stat-value" id="max-time" style="font-size: 24px; font-weight: bold; color: #fd7e14;">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php
                        // 获取配置表单字段
                        $config_fields = Notion_To_WordPress_Helper::get_config_form_fields();
                        
                        // 创建配置分组
                        $sections = [
                            'api' => __('API设置', 'notion-to-wordpress'),
                            'cache' => __('缓存配置', 'notion-to-wordpress'),
                            'files' => __('文件处理', 'notion-to-wordpress'),
                            'security' => __('安全设置', 'notion-to-wordpress'),
                            'performance' => __('性能优化', 'notion-to-wordpress'),
                            'logging' => __('日志记录', 'notion-to-wordpress'),
                        ];
                        
                        // 遍历所有配置节点
                        foreach ($sections as $section_key => $section_title) :
                            if (isset($config_fields[$section_key])) :
                        ?>
                            <div class="notion-wp-config-section">
                                <h3><?php echo esc_html($section_title); ?></h3>
                                <table class="form-table">
                                    <tbody>
                                        <?php foreach ($config_fields[$section_key] as $field) : ?>
                                            <tr>
                                                <th scope="row">
                                                    <label for="config_<?php echo esc_attr($section_key . '_' . $field['name']); ?>">
                                                        <?php echo esc_html($field['label']); ?>
                                                    </label>
                                                </th>
                                                <td>
                                                    <?php if ($field['type'] === 'integer') : ?>
                                                        <input 
                                                            type="number" 
                                                            id="config_<?php echo esc_attr($section_key . '_' . $field['name']); ?>"
                                                            name="notion_to_wordpress_config[<?php echo esc_attr($section_key); ?>][<?php echo esc_attr($field['name']); ?>]"
                                                            value="<?php echo esc_attr($field['value']); ?>"
                                                            class="regular-text"
                                                            <?php if (isset($field['min'])) : ?>min="<?php echo esc_attr($field['min']); ?>"<?php endif; ?>
                                                            <?php if (isset($field['max'])) : ?>max="<?php echo esc_attr($field['max']); ?>"<?php endif; ?>
                                                        >
                                                    <?php elseif ($field['type'] === 'select') : ?>
                                                        <select 
                                                            id="config_<?php echo esc_attr($section_key . '_' . $field['name']); ?>"
                                                            name="notion_to_wordpress_config[<?php echo esc_attr($section_key); ?>][<?php echo esc_attr($field['name']); ?>]"
                                                            class="regular-text"
                                                        >
                                                            <?php foreach ($field['options'] as $option) : ?>
                                                                <option value="<?php echo esc_attr($option); ?>" <?php selected($field['value'], $option); ?>>
                                                                    <?php echo esc_html($option); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php else : ?>
                                                        <input 
                                                            type="text" 
                                                            id="config_<?php echo esc_attr($section_key . '_' . $field['name']); ?>"
                                                            name="notion_to_wordpress_config[<?php echo esc_attr($section_key); ?>][<?php echo esc_attr($field['name']); ?>]"
                                                            value="<?php echo esc_attr($field['value']); ?>"
                                                            class="regular-text"
                                                        >
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>

                        <div class="notion-wp-button-row">
                            <button type="button" id="reset-all-config" class="button button-secondary">
                                <span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e('重置所有配置', 'notion-to-wordpress'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="notion-wp-tab-content" id="debug">
                    <div class="notion-wp-settings-section">
                        <h2><?php esc_html_e('调试工具', 'notion-to-wordpress'); ?></h2>
                        <p><?php esc_html_e('在这里，您可以管理日志级别、查看和清除日志文件。', 'notion-to-wordpress'); ?></p>
                        <table class="form-table">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="debug_level"><?php esc_html_e('日志记录级别', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <select id="debug_level" name="debug_level">
                                            <option value="<?php echo Notion_To_WordPress_Helper::DEBUG_LEVEL_NONE; ?>" <?php selected($debug_level, Notion_To_WordPress_Helper::DEBUG_LEVEL_NONE); ?>><?php esc_html_e('无日志', 'notion-to-wordpress'); ?></option>
                                            <option value="<?php echo Notion_To_WordPress_Helper::DEBUG_LEVEL_ERROR; ?>" <?php selected($debug_level, Notion_To_WordPress_Helper::DEBUG_LEVEL_ERROR); ?>><?php esc_html_e('仅错误', 'notion-to-wordpress'); ?></option>
                                            <option value="<?php echo Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO; ?>" <?php selected($debug_level, Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO); ?>><?php esc_html_e('信息和错误', 'notion-to-wordpress'); ?></option>
                                            <option value="<?php echo Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG; ?>" <?php selected($debug_level, Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG); ?>><?php esc_html_e('所有日志 (调试)', 'notion-to-wordpress'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('设置日志记录的详细程度。建议在生产环境中设置为"仅错误"。', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="log_retention_days"><?php esc_html_e('日志保留期限', 'notion-to-wordpress'); ?></label></th>
                                    <td>
                                        <?php
                                        $log_retention_days = $options['log_retention_days'] ?? 0;
                                        $retention_options = [
                                            '0'  => __('从不自动清理', 'notion-to-wordpress'),
                                            '7'  => __('7 天', 'notion-to-wordpress'),
                                            '14' => __('14 天', 'notion-to-wordpress'),
                                            '30' => __('30 天', 'notion-to-wordpress'),
                                            '60' => __('60 天', 'notion-to-wordpress'),
                                        ];
                                        ?>
                                        <select id="log_retention_days" name="log_retention_days">
                                            <?php foreach ($retention_options as $days => $label): ?>
                                            <option value="<?php echo esc_attr($days); ?>" <?php selected($log_retention_days, $days); ?>><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description"><?php esc_html_e('自动删除超过指定天数的旧日志文件。设置为"从不"以禁用。', 'notion-to-wordpress'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('错误日志', 'notion-to-wordpress'); ?></th>
                                    <td>
                                        <div id="log-viewer-container">
                                            <select id="log-file-selector">
                                                <?php foreach (Notion_To_WordPress_Helper::get_log_files() as $file): ?>
                                                    <option value="<?php echo esc_attr($file); ?>"><?php echo esc_html($file); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="button button-secondary" id="view-log-button"><?php esc_html_e('查看日志', 'notion-to-wordpress'); ?></button>
                                            <button type="button" class="button button-danger" id="clear-logs-button"><?php esc_html_e('清除所有日志', 'notion-to-wordpress'); ?></button>
                                            <textarea id="log-viewer" class="large-text code" rows="18" readonly
                                                style="width:100%; max-height:480px; font-family:monospace; white-space:pre; overflow:auto;"></textarea>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="notion-wp-tab-content" id="help">
                    <div class="notion-wp-settings-section">
                        <h2><?php esc_html_e('使用帮助', 'notion-to-wordpress'); ?></h2>
                        
                        <div class="notion-wp-help-section">
                            <h3><?php esc_html_e('快速开始', 'notion-to-wordpress'); ?></h3>
                            <ol>
                                <li><?php esc_html_e('在Notion创建一个集成并获取API密钥', 'notion-to-wordpress'); ?></li>
                                <li><?php esc_html_e('在Notion中创建一个数据库，并与您的集成共享', 'notion-to-wordpress'); ?></li>
                                <li><?php esc_html_e('复制数据库ID（从URL中获取）', 'notion-to-wordpress'); ?></li>
                                <li><?php esc_html_e('在此页面配置API密钥和数据库ID', 'notion-to-wordpress'); ?></li>
                                <li><?php esc_html_e('配置字段映射，确保Notion属性名称与WordPress字段正确对应', 'notion-to-wordpress'); ?></li>
                                <li><?php esc_html_e('点击"测试连接"确认设置正确', 'notion-to-wordpress'); ?></li>
                                <li><?php esc_html_e('点击"保存所有设置"保存您的配置', 'notion-to-wordpress'); ?></li>
                                <li><?php esc_html_e('点击"手动同步"或设置自动同步频率开始导入内容', 'notion-to-wordpress'); ?></li>
                            </ol>
                        </div>
                        
                        <div class="notion-wp-help-section">
                            <h3><?php esc_html_e('常见问题', 'notion-to-wordpress'); ?></h3>
                            <p><strong><?php esc_html_e('问：为什么我的Notion页面没有导入？', 'notion-to-wordpress'); ?></strong></p>
                            <p><?php esc_html_e('答：请检查以下几点：', 'notion-to-wordpress'); ?></p>
                            <ul>
                                <li><?php esc_html_e('确认您的API密钥和数据库ID正确', 'notion-to-wordpress'); ?></li>
                                <li><?php esc_html_e('确认您的Notion集成已与数据库共享', 'notion-to-wordpress'); ?></li>
                                <li><?php esc_html_e('检查字段映射是否正确对应Notion中的属性名称', 'notion-to-wordpress'); ?></li>
                                <li><?php esc_html_e('尝试使用"刷新全部内容"按钮重新同步', 'notion-to-wordpress'); ?></li>
                            </ul>
                            
                            <p><strong><?php esc_html_e('问：如何自定义导入的内容格式？', 'notion-to-wordpress'); ?></strong></p>
                            <p><?php esc_html_e('答：本插件会尽可能保留Notion中的格式，包括标题、列表、表格、代码块等。对于特殊内容（如数学公式、图表），插件也提供了支持。', 'notion-to-wordpress'); ?></p>
                            
                            <p><strong><?php esc_html_e('问：导入后如何更新内容？', 'notion-to-wordpress'); ?></strong></p>
                            <p><?php esc_html_e('答：当您在Notion中更新内容后，可以点击"刷新全部内容"按钮手动更新，或等待自动同步（如果已设置）。', 'notion-to-wordpress'); ?></p>
                        </div>
                        
                        <div class="notion-wp-help-section">
                            <h3><?php esc_html_e('获取支持', 'notion-to-wordpress'); ?></h3>
                            <p><?php esc_html_e('如果您遇到任何问题或需要帮助，请访问我们的GitHub仓库：', 'notion-to-wordpress'); ?></p>
                            <p><a href="https://github.com/Frank-Loong/Notion-to-WordPress" target="_blank">https://github.com/Frank-Loong/Notion-to-WordPress</a></p>
                        </div>
                    </div>
                </div>

                <div class="notion-wp-tab-content" id="about-author">
                    <div class="notion-wp-settings-section">
                        <h2><?php esc_html_e('关于作者', 'notion-to-wordpress'); ?></h2>

                        <div class="author-info">
                            <div class="author-avatar">
                                <img src="https://s21.ax1x.com/2024/10/11/pAYE3WQ.jpg" alt="Frank-Loong" onerror="this.style.display='none'">
                            </div>
                            <div class="author-details">
                                <h3>Frank-Loong</h3>
                                <p class="author-title"><?php esc_html_e('科技爱好者 & AI玩家', 'notion-to-wordpress'); ?></p>
                                <p class="author-description">
                                    <?php esc_html_e('对互联网、计算机等科技行业充满热情，擅长 AI 工具的使用与调教。', 'notion-to-wordpress'); ?>
                                    <?php esc_html_e('此插件在强大的 AI 编程助手 Cursor 和 Augment 的协助下完成，现在将这个有趣的项目分享给大家。', 'notion-to-wordpress'); ?>
                                </p>
                                <div class="author-links">
                                    <a href="https://frankloong.com" target="_blank" class="author-link">
                                        <span class="link-icon">🌐</span>
                                        <?php esc_html_e('个人网站', 'notion-to-wordpress'); ?>
                                    </a>
                                    <a href="mailto:frankloong@qq.com" class="author-link">
                                        <span class="link-icon">📧</span>
                                        <?php esc_html_e('联系邮箱', 'notion-to-wordpress'); ?>
                                    </a>
                                    <a href="https://github.com/Frank-Loong/Notion-to-WordPress" target="_blank" class="author-link">
                                        <span class="link-icon">💻</span>
                                        GitHub
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="plugin-info">
                            <h4><?php esc_html_e('插件信息', 'notion-to-wordpress'); ?></h4>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label"><?php esc_html_e('版本：', 'notion-to-wordpress'); ?></span>
                                    <span class="info-value"><?php echo esc_html( NOTION_TO_WORDPRESS_VERSION ); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label"><?php esc_html_e('许可证：', 'notion-to-wordpress'); ?></span>
                                    <span class="info-value">GPL v3</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label"><?php esc_html_e('兼容性：', 'notion-to-wordpress'); ?></span>
                                    <span class="info-value">WordPress 5.0+</span>
                                </div>
                            </div>
                        </div>

                        <div class="acknowledgments">
                            <h4><?php esc_html_e('致谢与参考', 'notion-to-wordpress'); ?></h4>
                            <p><?php esc_html_e('本项目的开发过程中参考了以下优秀的开源项目：', 'notion-to-wordpress'); ?></p>
                            <div class="reference-projects">
                                <div class="reference-item">
                                    <a href="https://github.com/tangly1024/NotionNext" target="_blank">NotionNext</a>
                                    <p><?php esc_html_e('基于 Notion 的强大静态博客系统', 'notion-to-wordpress'); ?></p>
                                </div>
                                <div class="reference-item">
                                    <a href="https://github.com/LetTTGACO/elog" target="_blank">Elog</a>
                                    <p><?php esc_html_e('支持多平台的开源博客写作客户端', 'notion-to-wordpress'); ?></p>
                                </div>
                                <div class="reference-item">
                                    <a href="https://github.com/pchang78/notion-content" target="_blank">notion-content</a>
                                    <p><?php esc_html_e('Notion 内容管理解决方案', 'notion-to-wordpress'); ?></p>
                                </div>
                            </div>
                            <p class="acknowledgments-footer"><em><?php esc_html_e('感谢这些项目及其维护者对开源社区的贡献！', 'notion-to-wordpress'); ?></em></p>
                        </div>
                    </div>
                </div>

                <div class="notion-wp-actions-bar">
                    <div class="left-actions">
                        <div class="sync-options-group">
                            <button type="button" id="notion-manual-import" class="button button-secondary">
                                <span class="dashicons dashicons-lightbulb"></span> <?php esc_html_e('智能同步', 'notion-to-wordpress'); ?>
                            </button>
                            <button type="button" id="notion-full-import" class="button button-secondary">
                                <span class="dashicons dashicons-database-import"></span> <?php esc_html_e('完全同步', 'notion-to-wordpress'); ?>
                            </button>
                            <button type="button" class="button button-secondary refresh-all-content">
                                <span class="dashicons dashicons-update-alt"></span> <?php esc_html_e('刷新全部内容', 'notion-to-wordpress'); ?>
                            </button>
                        </div>
                        <div class="sync-options-info">
                            <small class="description">
                                <strong><?php esc_html_e('智能同步', 'notion-to-wordpress'); ?></strong>: <?php esc_html_e('只同步有变化的页面，速度更快', 'notion-to-wordpress'); ?><br>
                                <strong><?php esc_html_e('完全同步', 'notion-to-wordpress'); ?></strong>: <?php esc_html_e('同步所有页面，确保数据一致性', 'notion-to-wordpress'); ?>
                            </small>
                        </div>
                    </div>
                    <?php submit_button(__('保存所有设置', 'notion-to-wordpress'), 'primary', 'submit', false); ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast提示组件 -->
<div id="notion-wp-toast" class="notion-wp-toast">
    <div class="notion-wp-toast-icon">
        <span class="dashicons"></span>
    </div>
    <div class="notion-wp-toast-content"></div>
    <button class="notion-wp-toast-close">
        <span class="dashicons dashicons-no-alt"></span>
    </button>
</div>

<div id="loading-overlay" style="display: none;">
    <div class="loading-message">
        <span class="spinner is-active"></span>
        <?php esc_html_e('处理中，请稍候...', 'notion-to-wordpress'); ?>
    </div>
</div>