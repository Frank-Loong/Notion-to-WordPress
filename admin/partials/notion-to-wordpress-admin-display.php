<?php
declare(strict_types=1);

/**
 * 插件主后台页面
 *
 * 此文件负责渲染插件的主设置页面，包括 API 配置、数据库同步、字段映射等。
 *
 * @since      1.0.9
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 如果直接访问本文件，则退出
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 一次性获取所有选项
$options = get_option( 'notion_to_wordpress_options', array() );

// 从选项数组中安全获取值，带默认值
$api_key              = $options['notion_api_key'] ?? '';
$database_id          = $options['notion_database_id'] ?? '';
$additional_databases = $options['notion_databases'] ?? array();
if ( is_array( $additional_databases ) ) {
	$additional_databases_text = implode(
		"\n",
		array_map(
			function ( $db ) {
				$id   = is_array( $db ) ? ( $db['id'] ?? '' ) : (string) $db;
				$name = is_array( $db ) ? ( $db['name'] ?? '' ) : '';
				return $name !== '' ? ( $name . '|' . $id ) : $id;
			},
			$additional_databases
		)
	);
} else {
	$additional_databases_text = '';
}
$sync_schedule       = $options['sync_schedule'] ?? 'manual';
$delete_on_uninstall = $options['delete_on_uninstall'] ?? 0;
$field_mapping       = $options['field_mapping'] ?? array(
	'title'          => 'Title,标题',
	'status'         => 'Status,状态',
	'post_type'      => 'Type,类型',
	'date'           => 'Date,日期',
	'excerpt'        => 'Summary,摘要,Excerpt',
	'featured_image' => 'Featured Image,特色图片',
	'categories'     => 'Categories,分类,Category',
	'tags'           => 'Tags,标签,Tag',
	'password'       => 'Password,密码',
);
$debug_level         = $options['debug_level'] ?? \NTWP\Core\Logger::DEBUG_LEVEL_ERROR;
$max_image_size      = $options['max_image_size'] ?? 5;
$plugin_language     = $options['plugin_language'] ?? 'auto';

// 简化配置
$performance_level = $options['performance_level'] ?? 'balanced';
$field_template    = $options['field_template'] ?? 'mixed';

// 性能优化配置
$api_page_size           = $options['api_page_size'] ?? 100;
$concurrent_requests     = $options['concurrent_requests'] ?? 5;
$batch_size              = $options['batch_size'] ?? 20;
$log_buffer_size         = $options['log_buffer_size'] ?? 50;
$enable_performance_mode = $options['enable_performance_mode'] ?? 1;

// 为内联脚本生成 nonce
$script_nonce = wp_create_nonce( 'notion_wp_script_nonce' );

?>
<div class="wrap notion-wp-admin">
	<div class="notion-wp-header">
		<div class="notion-wp-header-content">
			<h1 class="wp-heading-inline">
				<span class="notion-wp-logo"></span>
				<?php esc_html_e( 'Notion to WordPress', 'notion-to-wordpress' ); ?>
			</h1>
			<div class="notion-wp-version"><?php echo esc_html( NOTION_TO_WORDPRESS_VERSION ); ?></div>
		</div>
	</div>

	<?php settings_errors( 'notion_wp_messages' ); ?>

	<div class="notion-wp-layout">
		<div class="notion-wp-sidebar">
			<div class="notion-wp-menu">
				<button class="notion-wp-menu-item active" data-tab="api-settings">
					<?php esc_html_e( '🔄 同步设置', 'notion-to-wordpress' ); ?>
				</button>
				<button class="notion-wp-menu-item" data-tab="field-mapping">
					<?php esc_html_e( '🔗 字段映射', 'notion-to-wordpress' ); ?>
				</button>
				<button class="notion-wp-menu-item" data-tab="performance-config">
					<?php esc_html_e( '⚡ 性能配置', 'notion-to-wordpress' ); ?>
				</button>
				<button class="notion-wp-menu-item" data-tab="performance">
					<?php esc_html_e( '📊 性能监控', 'notion-to-wordpress' ); ?>
				</button>
				<button class="notion-wp-menu-item" data-tab="other-settings">
					<?php esc_html_e( '⚙️ 其他设置', 'notion-to-wordpress' ); ?>
				</button>
				<button class="notion-wp-menu-item" data-tab="debug">
					<?php esc_html_e( '🐞 调试工具', 'notion-to-wordpress' ); ?>
				</button>
				<button class="notion-wp-menu-item" data-tab="help">
					<?php esc_html_e( '📖 使用帮助', 'notion-to-wordpress' ); ?>
				</button>
				<button class="notion-wp-menu-item" data-tab="about-author">
					<?php esc_html_e( '👨‍💻 关于作者', 'notion-to-wordpress' ); ?>
				</button>
			</div>
		</div>

		<div class="notion-wp-content">
			<form id="notion-to-wordpress-settings-form" method="post" action="admin-post.php">
				<input type="hidden" name="action" value="notion_to_wordpress_options">
				<?php wp_nonce_field( 'notion_to_wordpress_options_update', 'notion_to_wordpress_options_nonce' ); ?>

				<div class="notion-wp-tab-content active" id="api-settings">
					<div class="notion-wp-settings-section">
						<h2><?php esc_html_e( 'Notion API 设置', 'notion-to-wordpress' ); ?></h2>

						<div class="notion-stats-grid">
							<div class="stat-card">
								<h3 class="stat-imported-count">0</h3>
								<span><?php esc_html_e( '已导入页面', 'notion-to-wordpress' ); ?></span>
							</div>
							<div class="stat-card">
								<h3 class="stat-published-count">0</h3>
								<span><?php esc_html_e( '已发布页面', 'notion-to-wordpress' ); ?></span>
							</div>
							<div class="stat-card">
								<h3 class="stat-last-update"><?php esc_html_e( '从未', 'notion-to-wordpress' ); ?></h3>
								<span><?php esc_html_e( '最后同步', 'notion-to-wordpress' ); ?></span>
							</div>
							<div class="stat-card">
								<h3 class="stat-next-run"><?php esc_html_e( '未计划', 'notion-to-wordpress' ); ?></h3>
								<span><?php esc_html_e( '下次同步', 'notion-to-wordpress' ); ?></span>
							</div>
						</div>

						<p class="description">
							<?php esc_html_e( '连接到您的Notion数据库所需的设置。', 'notion-to-wordpress' ); ?>
							<a href="https://developers.notion.com/docs/getting-started" target="_blank"><?php esc_html_e( '了解如何获取API密钥', 'notion-to-wordpress' ); ?></a>
						</p>

						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row"><label for="notion_to_wordpress_api_key"><?php esc_html_e( 'API密钥', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<div class="input-with-validation">
											<div class="input-with-button">
												<input type="password" id="notion_to_wordpress_api_key" name="notion_to_wordpress_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text notion-wp-validated-input" autocomplete="off" placeholder="<?php esc_attr_e( '输入您的Notion API密钥', 'notion-to-wordpress' ); ?>" data-validation="api-key">
												<button type="button" class="button button-secondary show-hide-password" title="<?php esc_attr_e( '显示/隐藏密钥', 'notion-to-wordpress' ); ?>"><span class="dashicons dashicons-visibility"></span></button>
											</div>
											<div class="validation-feedback" id="api-key-feedback"></div>
										</div>
										<p class="description"><?php esc_html_e( '在Notion的"我的集成"页面创建并获取API密钥。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="notion_to_wordpress_database_id"><?php esc_html_e( '数据库ID', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<div class="input-with-validation">
											<input type="text" id="notion_to_wordpress_database_id" name="notion_to_wordpress_database_id" value="<?php echo esc_attr( $database_id ); ?>" class="regular-text notion-wp-validated-input" placeholder="<?php esc_attr_e( '输入您的Notion数据库ID', 'notion-to-wordpress' ); ?>" data-validation="database-id">
											<div class="validation-feedback" id="database-id-feedback"></div>
										</div>
										<p class="description"><?php echo wp_kses( __( '可以从Notion数据库URL中找到，格式如：https://www.notion.so/xxx/<strong>数据库ID</strong>?v=xxx', 'notion-to-wordpress' ), array( 'strong' => array() ) ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="additional_databases"><?php esc_html_e( '附加数据库（多库同步）', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<textarea id="additional_databases" name="additional_databases" rows="4" class="large-text code" placeholder="<?php esc_attr_e( '每行一个数据库 ID，可选 “名称|ID” 格式', 'notion-to-wordpress' ); ?>"><?php echo esc_textarea( $additional_databases_text ); ?></textarea>
										<p class="description"><?php esc_html_e( '除上方主数据库外，额外同步的 Notion 数据库。每行一个 ID；如需备注可用“名称|ID”。为空则仅同步主数据库。定时与手动同步都会遍历全部配置的数据库。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="sync_schedule"><?php esc_html_e( '自动同步频率', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<select id="sync_schedule" name="sync_schedule" class="regular-text">
											<?php
											$schedules = array(
												'manual'   => __( '手动同步', 'notion-to-wordpress' ),
												'twicedaily' => __( '每天两次', 'notion-to-wordpress' ),
												'daily'    => __( '每天一次', 'notion-to-wordpress' ),
												'weekly'   => __( '每周一次', 'notion-to-wordpress' ),
												'biweekly' => __( '每两周一次', 'notion-to-wordpress' ),
												'monthly'  => __( '每月一次', 'notion-to-wordpress' ),
											);
											foreach ( $schedules as $value => $label ) {
												echo '<option value="' . esc_attr( $value ) . '" ' . selected( $sync_schedule, $value, false ) . '>' . esc_html( $label ) . '</option>';
											}
											?>
										</select>
										<p class="description"><?php esc_html_e( '选择 "手动同步" 以禁用定时任务。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '定时同步选项', 'notion-to-wordpress' ); ?></th>
									<td>
										<?php
										$cron_incremental_sync = $options['cron_incremental_sync'] ?? 1;
										$cron_check_deletions  = $options['cron_check_deletions'] ?? 1;
										?>
										<fieldset>
											<label>
												<input type="checkbox" name="cron_incremental_sync" value="1" <?php checked( $cron_incremental_sync, 1 ); ?>>
												<?php esc_html_e( '启用增量同步', 'notion-to-wordpress' ); ?>
											</label>
											<p class="description"><?php esc_html_e( '仅同步有变化的页面，提高同步速度', 'notion-to-wordpress' ); ?></p>

											<label>
												<input type="checkbox" name="cron_check_deletions" value="1" <?php checked( $cron_check_deletions, 1 ); ?>>
												<?php esc_html_e( '检查删除的页面', 'notion-to-wordpress' ); ?>
											</label>
											<p class="description"><?php esc_html_e( '自动删除在Notion中已删除但WordPress中仍存在的文章', 'notion-to-wordpress' ); ?></p>
										</fieldset>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="webhook_enabled"><?php esc_html_e( 'Webhook 支持', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$webhook_enabled    = $options['webhook_enabled'] ?? 0;
										$verification_token = $options['webhook_verify_token'] ?? '';
										$webhook_token      = $options['webhook_token'] ?? \NTWP\Core\HTTP_Client::generate_token( 32 );
										$webhook_url        = site_url( 'wp-json/notion-to-wordpress/v1/webhook/' . $webhook_token );
										?>
										<label for="webhook_enabled" class="checkbox-with-label">
											<input type="checkbox" id="webhook_enabled" name="webhook_enabled" value="1" <?php checked( 1, $webhook_enabled ); ?>>
											<span><?php esc_html_e( '启用 Webhook 支持', 'notion-to-wordpress' ); ?></span>
										</label>
										<p class="description"><?php esc_html_e( '启用后，您可以设置 Notion 集成的 Webhook 以在内容变更时自动触发同步。', 'notion-to-wordpress' ); ?></p>

										<div id="webhook-settings" class="notion-wp-subsetting <?php echo $webhook_enabled ? '' : 'notion-wp-hidden'; ?>">
											<div class="notion-wp-field">
												<label for="verification_token"><?php esc_html_e( '验证令牌', 'notion-to-wordpress' ); ?></label>
												<div class="input-with-button">
													<input type="text" id="verification_token" value="<?php echo esc_attr( $verification_token ); ?>" class="regular-text" readonly placeholder="<?php esc_attr_e( '等待 Notion 返回…', 'notion-to-wordpress' ); ?>">
													<button type="button" class="button button-secondary" id="refresh-verification-token"
														title="<?php esc_attr_e( '刷新验证令牌', 'notion-to-wordpress' ); ?>">
														<span class="dashicons dashicons-update"></span>
													</button>
													<button type="button" class="button button-secondary copy-to-clipboard"
														data-clipboard-target="#verification_token"
														onclick="window.copyTextToClipboard(document.getElementById('verification_token').value, function(success) { if(success) window.showModal(notionToWp.i18n.copied, 'success'); });"
														title="<?php esc_attr_e( '复制令牌', 'notion-to-wordpress' ); ?>">
														<span class="dashicons dashicons-clipboard"></span>
													</button>
												</div>
												<p class="description"><?php esc_html_e( '首次发送 Webhook 时，Notion 将返回 verification_token，此处会自动展示。点击刷新按钮可获取最新的令牌。', 'notion-to-wordpress' ); ?></p>
											</div>
											<div class="notion-wp-field">
												<label for="webhook_url"><?php esc_html_e( 'Webhook 地址', 'notion-to-wordpress' ); ?></label>
												<div class="input-with-button">
													<input type="text" id="webhook_url" value="<?php echo esc_url( $webhook_url ); ?>" class="regular-text" readonly>
													<button type="button" class="button button-secondary copy-to-clipboard"
														data-clipboard-target="#webhook_url"
														onclick="window.copyTextToClipboard(document.getElementById('webhook_url').value, function(success) { if(success) window.showModal(notionToWp.i18n.copied, 'success'); });"
														title="<?php esc_attr_e( '复制 URL', 'notion-to-wordpress' ); ?>">
														<span class="dashicons dashicons-clipboard"></span>
													</button>
												</div>
												<p class="description"><?php esc_html_e( '在 Notion 开发者平台设置此 URL 作为您集成的 Webhook 终端点。', 'notion-to-wordpress' ); ?></p>
											</div>

											<div class="notion-wp-field">
												<label><?php esc_html_e( 'Webhook 同步选项', 'notion-to-wordpress' ); ?></label>
												<?php
												$webhook_incremental     = $options['webhook_incremental_sync'] ?? 1;
												$webhook_check_deletions = $options['webhook_check_deletions'] ?? 1;
												?>
												<fieldset>
													<label>
														<input type="checkbox" name="webhook_incremental_sync" value="1" <?php checked( $webhook_incremental, 1 ); ?>>
														<?php esc_html_e( '启用增量同步', 'notion-to-wordpress' ); ?>
													</label>
													<p class="description"><?php esc_html_e( 'Webhook触发时仅同步有变化的页面，提高响应速度', 'notion-to-wordpress' ); ?></p>

													<label>
														<input type="checkbox" name="webhook_check_deletions" value="1" <?php checked( $webhook_check_deletions, 1 ); ?>>
														<?php esc_html_e( '数据库事件检查删除', 'notion-to-wordpress' ); ?>
													</label>
													<p class="description"><?php esc_html_e( '数据库结构变化时检查删除的页面（单页面事件不受影响）', 'notion-to-wordpress' ); ?></p>
												</fieldset>
											</div>

											<div class="notion-wp-field">
												<label for="webhook_delete_action"><?php esc_html_e( 'Webhook 删除处理方式', 'notion-to-wordpress' ); ?></label>
												<?php $webhook_delete_action = $options['webhook_delete_action'] ?? 'delete'; ?>
												<select id="webhook_delete_action" name="webhook_delete_action">
													<option value="delete" <?php selected( $webhook_delete_action, 'delete' ); ?>><?php esc_html_e( '彻底删除（默认）', 'notion-to-wordpress' ); ?></option>
													<option value="trash" <?php selected( $webhook_delete_action, 'trash' ); ?>><?php esc_html_e( '移至回收站', 'notion-to-wordpress' ); ?></option>
													<option value="draft" <?php selected( $webhook_delete_action, 'draft' ); ?>><?php esc_html_e( '标记为草稿', 'notion-to-wordpress' ); ?></option>
												</select>
												<p class="description"><?php esc_html_e( '当 Notion 页面被删除时，对应 WordPress 文章的处理方式。对应 Wiki「智能删除检测」的可配置删除行为。', 'notion-to-wordpress' ); ?></p>
											</div>

											<div class="notion-wp-field">
												<label><?php esc_html_e( 'Webhook 安全加固', 'notion-to-wordpress' ); ?></label>
												<?php $webhook_signature_verification = $options['webhook_signature_verification'] ?? 0; ?>
												<fieldset>
													<label>
														<input type="checkbox" name="webhook_signature_verification" value="1" <?php checked( $webhook_signature_verification, 1 ); ?>>
														<?php esc_html_e( '启用 Notion 签名验证', 'notion-to-wordpress' ); ?>
													</label>
													<p class="description"><?php esc_html_e( '校验 Notion 的 X-Notion-Signature（HMAC-SHA256），并启用时间戳检查与重放攻击防护。需先完成 Notion 订阅握手以获取 verification_token。', 'notion-to-wordpress' ); ?></p>
												</fieldset>
												<label for="webhook_ip_whitelist"><?php esc_html_e( 'IP 白名单（可选）', 'notion-to-wordpress' ); ?></label>
												<input type="text" id="webhook_ip_whitelist" name="webhook_ip_whitelist" class="regular-text" value="<?php echo esc_attr( $options['webhook_ip_whitelist'] ?? '' ); ?>" placeholder="203.0.113.10, 198.51.100.0/24">
												<p class="description"><?php esc_html_e( '仅允许白名单内的来源 IP 访问 Webhook 终端点，多个用逗号分隔，留空表示不限制。', 'notion-to-wordpress' ); ?></p>
											</div>

											<div class="notion-wp-field">
												<label><?php esc_html_e( 'API 响应缓存', 'notion-to-wordpress' ); ?></label>
												<?php $api_cache_enabled = $options['api_cache_enabled'] ?? 0; ?>
												<fieldset>
													<label>
														<input type="checkbox" name="api_cache_enabled" value="1" <?php checked( $api_cache_enabled, 1 ); ?>>
														<?php esc_html_e( '启用 Notion API 响应缓存', 'notion-to-wordpress' ); ?>
													</label>
													<p class="description"><?php esc_html_e( '缓存数据库页面列表，减少 API 调用。仅在未启用 Webhook 时生效（Webhook 模式下由事件驱动，无需缓存）。', 'notion-to-wordpress' ); ?></p>
												</fieldset>
												<label for="api_cache_ttl_minutes"><?php esc_html_e( '缓存有效期（分钟）', 'notion-to-wordpress' ); ?></label>
												<input type="number" id="api_cache_ttl_minutes" name="api_cache_ttl_minutes" min="1" max="60" value="<?php echo esc_attr( $options['api_cache_ttl_minutes'] ?? 5 ); ?>" class="small-text">
												<p class="description"><?php esc_html_e( '1-60 分钟，默认 5 分钟。', 'notion-to-wordpress' ); ?></p>
											</div>
											<div class="notion-wp-field">
												<label for="output_format"><?php esc_html_e( '输出格式', 'notion-to-wordpress' ); ?></label>
												<?php $output_format = $options['output_format'] ?? 'classic'; ?>
												<select id="output_format" name="output_format">
													<option value="classic" <?php selected( $output_format, 'classic' ); ?>><?php esc_html_e( '经典（传统 HTML）', 'notion-to-wordpress' ); ?></option>
													<option value="gutenberg" <?php selected( $output_format, 'gutenberg' ); ?>><?php esc_html_e( 'Gutenberg 区块（原生 wp: 标记）', 'notion-to-wordpress' ); ?></option>
												</select>
												<p class="description"><?php esc_html_e( '选择 Gutenberg 区块可在 WordPress 块编辑器中以原生区块形式二次编辑内容；经典模式输出纯净 HTML。', 'notion-to-wordpress' ); ?></p>
											</div>
										</div>

									</td>
								</tr>
							</tbody>
						</table>
						<div class="notion-wp-button-row">
							<button type="button" id="notion-test-connection" class="button button-secondary">
								<span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( '测试连接', 'notion-to-wordpress' ); ?>
							</button>
						</div>

						<!-- 同步操作按钮 -->
						<div class="notion-wp-sync-actions">
							<h3><?php esc_html_e( '同步操作', 'notion-to-wordpress' ); ?></h3>
							<div class="sync-buttons">
								<button type="button" class="button button-primary notion-wp-sync-btn" id="notion-manual-import"
										data-sync-type="manual" data-original-text="<?php esc_attr_e( '智能同步', 'notion-to-wordpress' ); ?>">
									<span class="dashicons dashicons-lightbulb"></span>
									<span class="button-text"><?php esc_html_e( '智能同步', 'notion-to-wordpress' ); ?></span>
								</button>
								<button type="button" class="button button-secondary notion-wp-sync-btn" id="notion-full-import"
										data-sync-type="full" data-original-text="<?php esc_attr_e( '完全同步', 'notion-to-wordpress' ); ?>">
									<span class="dashicons dashicons-update"></span>
									<span class="button-text"><?php esc_html_e( '完全同步', 'notion-to-wordpress' ); ?></span>
								</button>
							</div>

							<!-- 同步进度指示器 -->
							<div class="sync-progress notion-wp-hidden" id="sync-progress">
								<div class="progress-bar">
									<div class="progress-fill"></div>
								</div>
								<div class="progress-text">
									<span class="current-step">准备同步...</span>
									<span class="progress-percentage">0%</span>
								</div>
							</div>

							<div class="sync-info">
								<p><strong><?php esc_html_e( '智能同步', 'notion-to-wordpress' ); ?></strong>: <?php esc_html_e( '只同步有变化的页面，速度更快', 'notion-to-wordpress' ); ?></p>
								<p><strong><?php esc_html_e( '完全同步', 'notion-to-wordpress' ); ?></strong>: <?php esc_html_e( '同步所有页面，确保数据一致性', 'notion-to-wordpress' ); ?></p>
							</div>
						</div>
					</div>

					<!-- 快速配置部分 -->
					<div class="notion-wp-settings-section">
						<h2><?php esc_html_e( '🚀 快速配置', 'notion-to-wordpress' ); ?></h2>
						<p class="description"><?php esc_html_e( '使用预设模板快速配置插件，适合大多数用户。高级用户可以在其他标签页进行详细配置。', 'notion-to-wordpress' ); ?></p>

						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row"><label for="performance_level"><?php esc_html_e( '性能级别', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$performance_levels = class_exists( 'NTWP\\Utils\\Config_Simplifier' )
											? \NTWP\Utils\Config_Simplifier::get_available_performance_levels()
											: array(
												'conservative' => '保守模式 - 适合配置较低的服务器',
												'balanced' => '平衡模式 - 推荐的默认配置',
												'aggressive' => '激进模式 - 适合高性能服务器',
											);
										?>
										<select id="performance_level" name="performance_level" class="regular-text">
											<?php foreach ( $performance_levels as $level => $description ) : ?>
												<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $performance_level, $level ); ?>>
													<?php echo esc_html( $description ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( '选择适合您服务器配置的性能级别。系统会自动设置最优的API分页大小、基准并发数等参数，并根据实时性能动态调整。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="field_template"><?php esc_html_e( '字段映射模板', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$field_templates = class_exists( 'NTWP\\Utils\\Config_Simplifier' )
											? \NTWP\Utils\Config_Simplifier::get_available_field_templates()
											: array(
												'english' => '英文模板 - 适合英文Notion数据库',
												'chinese' => '中文模板 - 适合中文Notion数据库',
												'mixed'   => '混合模板 - 中英文兼容',
												'custom'  => '自定义 - 手动配置所有字段',
											);
										?>
										<select id="field_template" name="field_template" class="regular-text">
											<?php foreach ( $field_templates as $template => $description ) : ?>
												<option value="<?php echo esc_attr( $template ); ?>" <?php selected( $field_template, $template ); ?>>
													<?php echo esc_html( $description ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( '选择与您的Notion数据库语言匹配的字段映射模板。选择"自定义"可在字段映射标签页进行详细配置。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
							</tbody>
						</table>

						<!-- 智能推荐 -->
						<div class="notion-wp-smart-recommendations">
							<h3><?php esc_html_e( '💡 智能推荐', 'notion-to-wordpress' ); ?></h3>
							<div id="config-recommendations">
								<button type="button" class="button button-secondary" id="get-smart-recommendations">
									<?php esc_html_e( '获取配置建议', 'notion-to-wordpress' ); ?>
								</button>
								<div id="recommendations-result" class="notion-wp-hidden"></div>
							</div>
						</div>
					</div>
				</div>

				<div class="notion-wp-tab-content" id="field-mapping">
					<div class="notion-wp-settings-section">
						<h2><?php esc_html_e( '字段映射', 'notion-to-wordpress' ); ?></h2>
						<p class="description"><?php esc_html_e( '设置您的Notion数据库属性名称与WordPress字段的对应关系。多个备选名称请用英文逗号隔开。', 'notion-to-wordpress' ); ?></p>
						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row"><label for="mapping_title"><?php esc_html_e( '文章标题', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<input name="field_mapping[title]" type="text" id="mapping_title" value="<?php echo esc_attr( $field_mapping['title'] ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( '用于设置WordPress文章标题的Notion属性名称', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mapping_status"><?php esc_html_e( '状态', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<input name="field_mapping[status]" type="text" id="mapping_status" value="<?php echo esc_attr( $field_mapping['status'] ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( '值为 "Published" 或 "已发布" 的页面会被设为 "已发布" 状态，其他则为 "草稿"。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mapping_post_type"><?php esc_html_e( '文章类型', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<input name="field_mapping[post_type]" type="text" id="mapping_post_type" value="<?php echo esc_attr( $field_mapping['post_type'] ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( '用于确定WordPress文章类型的Notion属性名称', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mapping_date"><?php esc_html_e( '日期', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<input name="field_mapping[date]" type="text" id="mapping_date" value="<?php echo esc_attr( $field_mapping['date'] ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( '用于设置WordPress文章发布日期的Notion属性名称', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mapping_excerpt"><?php esc_html_e( '摘要', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<input name="field_mapping[excerpt]" type="text" id="mapping_excerpt" value="<?php echo esc_attr( $field_mapping['excerpt'] ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( '用于设置WordPress文章摘要的Notion属性名称', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mapping_featured_image"><?php esc_html_e( '特色图片', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<input name="field_mapping[featured_image]" type="text" id="mapping_featured_image" value="<?php echo esc_attr( $field_mapping['featured_image'] ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( '用于设置WordPress特色图片的Notion属性名称（应为URL或文件类型）', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mapping_categories"><?php esc_html_e( '分类', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<input name="field_mapping[categories]" type="text" id="mapping_categories" value="<?php echo esc_attr( $field_mapping['categories'] ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( '用于设置WordPress文章分类的Notion属性名称', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mapping_tags"><?php esc_html_e( '标签', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<input name="field_mapping[tags]" type="text" id="mapping_tags" value="<?php echo esc_attr( $field_mapping['tags'] ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( '用于设置WordPress文章标签的Notion属性名称', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mapping_password"><?php esc_html_e( '文章密码', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<input name="field_mapping[password]" type="text" id="mapping_password" value="<?php echo esc_attr( $field_mapping['password'] ?? 'Password,密码' ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( '用于设置WordPress文章密码的Notion属性名称', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
							</tbody>
						</table>

						<div class="notion-wp-settings-section">
							<h3><?php esc_html_e( '自定义字段映射', 'notion-to-wordpress' ); ?></h3>
							<p class="description"><?php esc_html_e( '将Notion属性映射到WordPress自定义字段。您可以添加任意数量的自定义字段映射。', 'notion-to-wordpress' ); ?></p>

							<div id="custom-field-mappings">
								<?php
								// 获取已保存的自定义字段映射
								$custom_field_mappings = $options['custom_field_mappings'] ?? array();

								// 如果不存在映射，则添加一个空的默认映射
								if ( empty( $custom_field_mappings ) ) {
									$custom_field_mappings = array(
										array(
											'notion_property' => '',
											'wp_field'   => '',
											'field_type' => 'text',
										),
									);
								}

								// 字段类型选项
								$field_types = array(
									'text'         => __( '文本', 'notion-to-wordpress' ),
									'number'       => __( '数字', 'notion-to-wordpress' ),
									'date'         => __( '日期', 'notion-to-wordpress' ),
									'checkbox'     => __( '复选框', 'notion-to-wordpress' ),
									'select'       => __( '选择', 'notion-to-wordpress' ),
									'multi_select' => __( '多选', 'notion-to-wordpress' ),
									'url'          => __( 'URL', 'notion-to-wordpress' ),
									'email'        => __( '电子邮件', 'notion-to-wordpress' ),
									'phone'        => __( '电话', 'notion-to-wordpress' ),
									'rich_text'    => __( '富文本', 'notion-to-wordpress' ),
								);

								foreach ( $custom_field_mappings as $index => $mapping ) :
									?>
								<div class="custom-field-mapping">
									<div class="custom-field-row">
										<div class="custom-field-col">
											<label><?php esc_html_e( 'Notion属性名称', 'notion-to-wordpress' ); ?></label>
											<input type="text" name="custom_field_mappings[<?php echo esc_attr( (string) $index ); ?>][notion_property]"
												value="<?php echo esc_attr( $mapping['notion_property'] ?? '' ); ?>"
												class="regular-text" placeholder="<?php esc_attr_e( '例如：Author,作者', 'notion-to-wordpress' ); ?>">
											<p class="description"><?php esc_html_e( 'Notion中的属性名称，多个备选名称请用英文逗号分隔', 'notion-to-wordpress' ); ?></p>
										</div>
										<div class="custom-field-col">
											<label><?php esc_html_e( 'WordPress字段名称', 'notion-to-wordpress' ); ?></label>
											<input type="text" name="custom_field_mappings[<?php echo esc_attr( (string) $index ); ?>][wp_field]"
												value="<?php echo esc_attr( $mapping['wp_field'] ?? '' ); ?>"
												class="regular-text" placeholder="<?php esc_attr_e( '例如：author', 'notion-to-wordpress' ); ?>">
											<p class="description"><?php esc_html_e( 'WordPress中的自定义字段名称', 'notion-to-wordpress' ); ?></p>
										</div>
										<div class="custom-field-col">
											<label><?php esc_html_e( '字段类型', 'notion-to-wordpress' ); ?></label>
											<select name="custom_field_mappings[<?php echo esc_attr( (string) $index ); ?>][field_type]" class="regular-text">
												<?php foreach ( $field_types as $type => $label ) : ?>
													<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $mapping['field_type'] ?? 'text', $type ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
											<p class="description"><?php esc_html_e( 'Notion属性的数据类型', 'notion-to-wordpress' ); ?></p>
										</div>
										<div class="custom-field-actions">
											<button type="button" class="button remove-field <?php echo ( count( $custom_field_mappings ) <= 1 ) ? 'notion-wp-hidden' : ''; ?>">
												<span class="dashicons dashicons-trash"></span>
											</button>
										</div>
									</div>
								</div>
								<?php endforeach; ?>
							</div>

							<div class="notion-wp-button-row">
								<button type="button" id="add-custom-field" class="button button-secondary">
									<span class="dashicons dashicons-database-import"></span> <?php esc_html_e( '添加自定义字段', 'notion-to-wordpress' ); ?>
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
						<h2><?php esc_html_e( '其他设置', 'notion-to-wordpress' ); ?></h2>
						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( '卸载设置', 'notion-to-wordpress' ); ?></th>
									<td>
										<fieldset>
											<legend class="screen-reader-text"><span><?php esc_html_e( '卸载时删除所有同步内容', 'notion-to-wordpress' ); ?></span></legend>
											<label for="delete_on_uninstall" class="checkbox-with-label">
												<input type="checkbox" id="delete_on_uninstall" name="delete_on_uninstall" value="1" <?php checked( 1, $delete_on_uninstall ); ?>>
												<span><?php esc_html_e( '卸载插件时，删除所有从Notion同步的文章和页面', 'notion-to-wordpress' ); ?></span>
											</label>
											<p class="description notion-wp-warning"><?php esc_html_e( '警告：此操作不可逆！所有通过Notion同步的内容将被永久删除。', 'notion-to-wordpress' ); ?></p>
										</fieldset>
									</td>
								</tr>

								<tr>
									<th scope="row"><label for="iframe_whitelist"><?php esc_html_e( 'iframe 白名单域名', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$iframe_whitelist = $options['iframe_whitelist'] ?? 'www.youtube.com,youtu.be,player.bilibili.com,b23.tv,v.qq.com';
										?>
										<textarea id="iframe_whitelist" name="iframe_whitelist" class="large-text" rows="3"><?php echo esc_textarea( $iframe_whitelist ); ?></textarea>
										<p class="description"><?php esc_html_e( '允许在内容中嵌入的 iframe 域名白名单，多个域名请用英文逗号分隔。输入 * 表示允许所有域名（不推荐）。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="allowed_image_types"><?php esc_html_e( '允许的图片格式', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$allowed_image_types = $options['allowed_image_types'] ?? 'image/jpeg,image/png,image/gif,image/webp';
										?>
										<textarea id="allowed_image_types" name="allowed_image_types" class="large-text" rows="2"><?php echo esc_textarea( $allowed_image_types ); ?></textarea>
										<p class="description"><?php esc_html_e( '允许下载和导入的图片 MIME 类型，多个类型请用英文逗号分隔。输入 * 表示允许所有格式。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="plugin_language"><?php esc_html_e( '插件界面语言', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										// 处理向后兼容：将旧的 force_english_ui 转换为新的 plugin_language
										$plugin_language = $options['plugin_language'] ?? 'auto';
										if ( empty( $options['plugin_language'] ) && ! empty( $force_english_ui ) ) {
											$plugin_language = 'en_US';
										}
										?>
										<select id="plugin_language" name="plugin_language">
											<option value="auto" <?php selected( 'auto', $plugin_language ); ?>><?php esc_html_e( '自动检测（跟随站点语言）', 'notion-to-wordpress' ); ?></option>
											<option value="zh_CN" <?php selected( 'zh_CN', $plugin_language ); ?>><?php esc_html_e( '简体中文', 'notion-to-wordpress' ); ?></option>
											<option value="en_US" <?php selected( 'en_US', $plugin_language ); ?>><?php esc_html_e( 'English', 'notion-to-wordpress' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( '选择插件界面显示的语言。自动检测将跟随WordPress站点语言设置。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="max_image_size"><?php esc_html_e( '最大图片大小', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$max_image_size = $options['max_image_size'] ?? 5;
										?>
										<input type="number" id="max_image_size" name="max_image_size" value="<?php echo esc_attr( $max_image_size ); ?>" class="small-text" min="1" max="20" step="1">
										<span><?php esc_html_e( 'MB', 'notion-to-wordpress' ); ?></span>
										<p class="description"><?php esc_html_e( '允许下载的最大图片大小（以 MB 为单位）。建议不超过 10MB。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
							</tbody>
						</table>
					</div>


				</div>

				<div class="notion-wp-tab-content" id="performance-config">
					<div class="notion-wp-settings-section">
						<h2><?php esc_html_e( '性能配置', 'notion-to-wordpress' ); ?></h2>
						<p><?php esc_html_e( '这里是高级性能选项的配置页面。请根据您的服务器配置和网络环境进行设置，以获得最佳的同步性能。', 'notion-to-wordpress' ); ?></p>

						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row"><label for="api_page_size"><?php esc_html_e( 'API分页大小', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$api_page_size = $options['api_page_size'] ?? 100;
										?>
										<input type="number" id="api_page_size" name="api_page_size" value="<?php echo esc_attr( $api_page_size ); ?>" class="small-text" min="50" max="200" step="10">
										<p class="description"><?php esc_html_e( '每次API请求获取的页面数量。较大的值可以减少API调用次数，但会增加单次请求的处理时间。推荐值：100-200。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="concurrent_requests"><?php esc_html_e( '并发请求数', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$concurrent_requests = $options['concurrent_requests'] ?? 5;
										?>
										<input type="number" id="concurrent_requests" name="concurrent_requests" value="<?php echo esc_attr( $concurrent_requests ); ?>" class="small-text" min="3" max="15" step="1">
										<p class="description"><?php esc_html_e( '基准并发请求数。系统会根据服务器性能（CPU负载、内存使用、网络延迟）动态调整实际并发数，以获得最佳性能。推荐值：5-10。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="batch_size"><?php esc_html_e( '批量处理大小', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$batch_size = $options['batch_size'] ?? 20;
										?>
										<input type="number" id="batch_size" name="batch_size" value="<?php echo esc_attr( $batch_size ); ?>" class="small-text" min="10" max="100" step="5">
										<p class="description"><?php esc_html_e( '批量处理页面的数量。较大的值可以提升数据库操作效率，但会增加内存使用。推荐值：20-50。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="log_buffer_size"><?php esc_html_e( '日志缓冲大小', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$log_buffer_size = $options['log_buffer_size'] ?? 50;
										?>
										<input type="number" id="log_buffer_size" name="log_buffer_size" value="<?php echo esc_attr( $log_buffer_size ); ?>" class="small-text" min="10" max="200" step="10">
										<p class="description"><?php esc_html_e( '日志批量写入的缓冲区大小。较大的值可以减少磁盘I/O，但会增加内存使用。推荐值：50-100。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '性能优化模式', 'notion-to-wordpress' ); ?></th>
									<td>
										<fieldset>
											<legend class="screen-reader-text"><span><?php esc_html_e( '性能优化模式', 'notion-to-wordpress' ); ?></span></legend>
											<?php
											$enable_performance_mode = $options['enable_performance_mode'] ?? 1;
											?>
											<label for="enable_performance_mode" class="checkbox-with-label">
												<input type="checkbox" id="enable_performance_mode" name="enable_performance_mode" value="1" <?php checked( 1, $enable_performance_mode ); ?>>
												<span><?php esc_html_e( '启用性能优化模式', 'notion-to-wordpress' ); ?></span>
											</label>
											<p class="description"><?php esc_html_e( '启用后将使用批量操作、动态并发调优等功能来提升同步性能。系统会自动根据服务器负载调整并发数。日志记录由调试工具中的日志等级单独控制。', 'notion-to-wordpress' ); ?></p>
										</fieldset>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '前端资源优化', 'notion-to-wordpress' ); ?></th>
									<td>
										<fieldset>
											<legend class="screen-reader-text"><span><?php esc_html_e( '前端资源优化', 'notion-to-wordpress' ); ?></span></legend>
											<?php
											$enable_asset_compression = $options['enable_asset_compression'] ?? 1;
											$enhanced_lazy_loading    = $options['enhanced_lazy_loading'] ?? 1;
											$performance_monitoring   = $options['performance_monitoring'] ?? 1;
											?>
											<label for="enable_asset_compression" class="checkbox-with-label">
												<input type="checkbox" id="enable_asset_compression" name="enable_asset_compression" value="1" <?php checked( 1, $enable_asset_compression ); ?>>
												<span><?php esc_html_e( '启用资源压缩', 'notion-to-wordpress' ); ?></span>
											</label>
											<br>
											<label for="enhanced_lazy_loading" class="checkbox-with-label">
												<input type="checkbox" id="enhanced_lazy_loading" name="enhanced_lazy_loading" value="1" <?php checked( 1, $enhanced_lazy_loading ); ?>>
												<span><?php esc_html_e( '增强懒加载', 'notion-to-wordpress' ); ?></span>
											</label>
											<br>
											<label for="performance_monitoring" class="checkbox-with-label">
												<input type="checkbox" id="performance_monitoring" name="performance_monitoring" value="1" <?php checked( 1, $performance_monitoring ); ?>>
												<span><?php esc_html_e( '性能监控', 'notion-to-wordpress' ); ?></span>
											</label>
											<p class="description"><?php esc_html_e( '启用前端资源优化可以提升页面加载速度20-40%。包括JavaScript/CSS压缩、增强懒加载和性能监控。', 'notion-to-wordpress' ); ?></p>
										</fieldset>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="cdn_provider"><?php esc_html_e( 'CDN配置', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$enable_cdn     = $options['enable_cdn'] ?? 0;
										$cdn_provider   = $options['cdn_provider'] ?? 'jsdelivr';
										$custom_cdn_url = $options['custom_cdn_url'] ?? '';
										?>
										<label for="enable_cdn" class="checkbox-with-label">
											<input type="checkbox" id="enable_cdn" name="enable_cdn" value="1" <?php checked( 1, $enable_cdn ); ?>>
											<span><?php esc_html_e( '启用CDN加速', 'notion-to-wordpress' ); ?></span>
										</label>
										<br><br>
										<select id="cdn_provider" name="cdn_provider">
											<option value="jsdelivr" <?php selected( 'jsdelivr', $cdn_provider ); ?>>jsDelivr</option>
											<option value="unpkg" <?php selected( 'unpkg', $cdn_provider ); ?>>UNPKG</option>
											<option value="cdnjs" <?php selected( 'cdnjs', $cdn_provider ); ?>>CDNJS</option>
											<option value="custom" <?php selected( 'custom', $cdn_provider ); ?>><?php esc_html_e( '自定义', 'notion-to-wordpress' ); ?></option>
										</select>
										<br><br>
										<input type="url" id="custom_cdn_url" name="custom_cdn_url" value="<?php echo esc_attr( $custom_cdn_url ); ?>" class="regular-text <?php echo $cdn_provider !== 'custom' ? 'notion-wp-hidden' : ''; ?>" placeholder="https://your-cdn.com">
										<p class="description"><?php esc_html_e( 'CDN可以加速静态资源加载。选择合适的CDN提供商或配置自定义CDN地址。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<div class="notion-wp-tab-content" id="performance">
					<div class="notion-wp-settings-section">
						<h2><?php esc_html_e( '性能监控', 'notion-to-wordpress' ); ?></h2>
						<p><?php esc_html_e( '查看插件的性能统计和优化效果。这些数据可以帮助您了解同步速度和资源使用情况。', 'notion-to-wordpress' ); ?></p>

						<div class="notion-wp-performance-dashboard">
							<div class="notion-wp-performance-cards">
								<div class="notion-wp-performance-card">
									<h3><?php esc_html_e( '当前配置', 'notion-to-wordpress' ); ?></h3>
									<div class="notion-wp-performance-config">
										<?php
										// 获取性能监控配置（类已在主插件文件中加载）
										if ( class_exists( 'NTWP\\Core\\Performance_Monitor' ) ) {
											$config = \NTWP\Core\Performance_Monitor::get_performance_config();
										} else {
											// 获取插件选项作为备用配置
											$options = get_option( 'notion_to_wordpress_options', array() );
											$config  = array(
												'api_page_size' => $options['api_page_size'] ?? 100,
												'concurrent_requests' => $options['concurrent_requests'] ?? 5,
												'batch_size' => $options['batch_size'] ?? 20,
												'log_buffer_size' => $options['log_buffer_size'] ?? 50,
												'enable_performance_mode' => $options['enable_performance_mode'] ?? 1,
											);
										}
										?>
										<div class="config-item">
											<span class="config-label"><?php esc_html_e( 'API分页大小:', 'notion-to-wordpress' ); ?></span>
											<span class="config-value"><?php echo esc_html( $config['api_page_size'] ); ?></span>
										</div>
										<div class="config-item">
											<span class="config-label"><?php esc_html_e( '并发请求数:', 'notion-to-wordpress' ); ?></span>
											<span class="config-value"><?php echo esc_html( $config['concurrent_requests'] ); ?></span>
										</div>
										<div class="config-item">
											<span class="config-label"><?php esc_html_e( '批量处理大小:', 'notion-to-wordpress' ); ?></span>
											<span class="config-value"><?php echo esc_html( $config['batch_size'] ); ?></span>
										</div>
										<div class="config-item">
											<span class="config-label"><?php esc_html_e( '日志缓冲大小:', 'notion-to-wordpress' ); ?></span>
											<span class="config-value"><?php echo esc_html( $config['log_buffer_size'] ); ?></span>
										</div>
										<div class="config-item">
											<span class="config-label"><?php esc_html_e( '性能优化模式:', 'notion-to-wordpress' ); ?></span>
											<span class="config-value <?php echo $config['enable_performance_mode'] ? 'enabled' : 'disabled'; ?>">
												<?php echo $config['enable_performance_mode'] ? esc_html__( '启用', 'notion-to-wordpress' ) : esc_html__( '禁用', 'notion-to-wordpress' ); ?>
											</span>
										</div>
									</div>
								</div>

								<div class="notion-wp-performance-card">
									<h3><?php esc_html_e( '系统信息', 'notion-to-wordpress' ); ?></h3>
									<div class="notion-wp-system-info">
										<div class="config-item">
											<span class="config-label"><?php esc_html_e( 'PHP版本:', 'notion-to-wordpress' ); ?></span>
											<span class="config-value"><?php echo esc_html( PHP_VERSION ); ?></span>
										</div>
										<div class="config-item">
											<span class="config-label"><?php esc_html_e( '内存限制:', 'notion-to-wordpress' ); ?></span>
											<span class="config-value"><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></span>
										</div>
										<div class="config-item">
											<span class="config-label"><?php esc_html_e( '当前内存使用:', 'notion-to-wordpress' ); ?></span>
											<span class="config-value"><?php echo esc_html( size_format( memory_get_usage( true ) ) ); ?></span>
										</div>
										<div class="config-item">
											<span class="config-label"><?php esc_html_e( '峰值内存使用:', 'notion-to-wordpress' ); ?></span>
											<span class="config-value"><?php echo esc_html( size_format( memory_get_peak_usage( true ) ) ); ?></span>
										</div>
									</div>
								</div>
							</div>

							<div class="notion-wp-performance-actions">
								<button type="button" class="button button-secondary" id="refresh-performance-stats">
									<?php esc_html_e( '刷新统计', 'notion-to-wordpress' ); ?>
								</button>
								<button type="button" class="button button-secondary" id="reset-performance-stats">
									<?php esc_html_e( '重置统计', 'notion-to-wordpress' ); ?>
								</button>
							</div>

							<div class="notion-wp-performance-tips">
								<h3><?php esc_html_e( '性能优化建议', 'notion-to-wordpress' ); ?></h3>
								<ul>
									<li><?php esc_html_e( '系统现已支持动态并发调优，会根据服务器性能自动调整并发数', 'notion-to-wordpress' ); ?></li>
									<li><?php esc_html_e( '如果同步速度较慢，可以适当增加API分页大小和基准并发请求数', 'notion-to-wordpress' ); ?></li>
									<li><?php esc_html_e( '如果服务器内存不足，系统会自动降低并发数以避免内存不足', 'notion-to-wordpress' ); ?></li>
									<li><?php esc_html_e( '启用性能优化模式可以显著提升同步效率', 'notion-to-wordpress' ); ?></li>
									<li><?php esc_html_e( '定期清理日志文件可以节省磁盘空间', 'notion-to-wordpress' ); ?></li>
									<li><?php esc_html_e( '创建数据库索引可以显著提升查询性能（30-50%）', 'notion-to-wordpress' ); ?></li>
								</ul>
							</div>

							<!-- 异步处理架构管理部分 -->
							<div class="notion-wp-performance-card">
								<h3><?php esc_html_e( '⚡ 异步处理架构', 'notion-to-wordpress' ); ?></h3>
								<p><?php esc_html_e( '异步处理架构可以解决大批量操作超时问题，显著提升用户体验。支持队列管理、错误恢复和智能调度。', 'notion-to-wordpress' ); ?></p>

								<?php
								// 获取异步状态数据
								$async_status = array(
									'status'     => 'idle',
									'operation'  => '',
									'started_at' => '',
									'updated_at' => '',
									'data_count' => 0,
									'progress'   => 0,
									'details'    => array(),
								);

								if ( class_exists( 'NTWP\\Core\\Async_Processor' ) ) {
									try {
										$async_status = \NTWP\Core\Async_Processor::get_async_status();
									} catch ( Exception $e ) {
										// 使用默认状态
									}
								}

								// 获取队列状态数据
								$queue_status = array(
									'total_tasks'    => 0,
									'pending'        => 0,
									'processing'     => 0,
									'completed'      => 0,
									'failed'         => 0,
									'retrying'       => 0,
									'queue_size'     => 0,
									'is_processing'  => false,
									'last_processed' => '',
									'next_scheduled' => '',
								);

								if ( class_exists( 'NTWP\\Core\\Queue_Manager' ) ) {
									try {
										$queue_status = \NTWP\Core\Queue_Manager::get_queue_status();
									} catch ( Exception $e ) {
										// 使用默认状态
									}
								}
								?>

								<div class="notion-wp-async-status" id="async-status-container">
									<?php
									$status_class = 'status-idle';
									$status_text  = __( '空闲', 'notion-to-wordpress' );

									if ( $async_status['status'] === 'running' ) {
										$status_class = 'status-running';
										$status_text  = __( '运行中', 'notion-to-wordpress' );
									} elseif ( $async_status['status'] === 'paused' ) {
										$status_class = 'status-paused';
										$status_text  = __( '已暂停', 'notion-to-wordpress' );
									} elseif ( $async_status['status'] === 'error' ) {
										$status_class = 'status-error';
										$status_text  = __( '错误', 'notion-to-wordpress' );
									}
									?>
									<div class="async-status-display <?php echo esc_attr( $status_class ); ?>">
										<div class="status-header">
											<span class="status-indicator"></span>
											<strong><?php esc_html_e( '异步状态:', 'notion-to-wordpress' ); ?></strong>
											<span class="status-value"><?php echo esc_html( $status_text ); ?></span>
										</div>
										<?php if ( ! empty( $async_status['operation'] ) ) : ?>
										<div class="status-details">
											<div class="detail-item">
												<span class="detail-label"><?php esc_html_e( '当前操作:', 'notion-to-wordpress' ); ?></span>
												<span class="detail-value"><?php echo esc_html( $async_status['operation'] ); ?></span>
											</div>
											<?php if ( $async_status['data_count'] > 0 ) : ?>
											<div class="detail-item">
												<span class="detail-label"><?php esc_html_e( '数据量:', 'notion-to-wordpress' ); ?></span>
												<span class="detail-value"><?php echo esc_html( $async_status['data_count'] ); ?></span>
											</div>
											<?php endif; ?>
											<?php if ( $async_status['progress'] > 0 ) : ?>
											<div class="detail-item">
												<span class="detail-label"><?php esc_html_e( '进度:', 'notion-to-wordpress' ); ?></span>
												<span class="detail-value"><?php echo esc_html( $async_status['progress'] . '%' ); ?></span>
											</div>
											<?php endif; ?>
											<?php if ( ! empty( $async_status['updated_at'] ) ) : ?>
											<div class="detail-item">
												<span class="detail-label"><?php esc_html_e( '更新时间:', 'notion-to-wordpress' ); ?></span>
												<span class="detail-value"><?php echo esc_html( $async_status['updated_at'] ); ?></span>
											</div>
											<?php endif; ?>
										</div>
										<?php endif; ?>
									</div>
								</div>

								<div class="notion-wp-queue-status" id="queue-status-container">
									<div class="queue-status-display">
										<div class="status-header">
											<strong><?php esc_html_e( '队列状态:', 'notion-to-wordpress' ); ?></strong>
										</div>
										<div class="queue-stats">
											<div class="queue-stat-item">
												<span class="stat-label"><?php esc_html_e( '总任务:', 'notion-to-wordpress' ); ?></span>
												<span class="stat-value"><?php echo esc_html( $queue_status['total_tasks'] ?? 0 ); ?></span>
											</div>
											<div class="queue-stat-item">
												<span class="stat-label"><?php esc_html_e( '等待中:', 'notion-to-wordpress' ); ?></span>
												<span class="stat-value"><?php echo esc_html( $queue_status['pending'] ?? 0 ); ?></span>
											</div>
											<div class="queue-stat-item">
												<span class="stat-label"><?php esc_html_e( '处理中:', 'notion-to-wordpress' ); ?></span>
												<span class="stat-value"><?php echo esc_html( $queue_status['processing'] ?? 0 ); ?></span>
											</div>
											<div class="queue-stat-item">
												<span class="stat-label"><?php esc_html_e( '已完成:', 'notion-to-wordpress' ); ?></span>
												<span class="stat-value"><?php echo esc_html( $queue_status['completed'] ?? 0 ); ?></span>
											</div>
											<div class="queue-stat-item">
												<span class="stat-label"><?php esc_html_e( '失败:', 'notion-to-wordpress' ); ?></span>
												<span class="stat-value"><?php echo esc_html( $queue_status['failed'] ?? 0 ); ?></span>
											</div>
										</div>
									</div>
								</div>

								<div class="notion-wp-async-actions">
									<button type="button" class="button button-secondary" id="refresh-async-status">
										<span class="dashicons dashicons-update"></span>
										<?php esc_html_e( '刷新状态', 'notion-to-wordpress' ); ?>
									</button>
									<button type="button" class="button button-secondary notion-wp-hidden" id="pause-async-operation">
										<span class="dashicons dashicons-controls-pause"></span>
										<?php esc_html_e( '暂停操作', 'notion-to-wordpress' ); ?>
									</button>
									<button type="button" class="button button-secondary notion-wp-hidden" id="resume-async-operation">
										<span class="dashicons dashicons-controls-play"></span>
										<?php esc_html_e( '恢复操作', 'notion-to-wordpress' ); ?>
									</button>
									<button type="button" class="button button-link-delete notion-wp-hidden" id="stop-async-operation">
										<span class="dashicons dashicons-controls-stop"></span>
										<?php esc_html_e( '停止操作', 'notion-to-wordpress' ); ?>
									</button>
									<button type="button" class="button button-secondary" id="cleanup-queue">
										<span class="dashicons dashicons-trash"></span>
										<?php esc_html_e( '清理队列', 'notion-to-wordpress' ); ?>
									</button>
								</div>

								<div class="notion-wp-async-info">
									<h4><?php esc_html_e( '异步处理说明', 'notion-to-wordpress' ); ?></h4>
									<ul>
										<li><strong>队列系统</strong>: <?php esc_html_e( '大批量操作自动分解为小批次，避免超时', 'notion-to-wordpress' ); ?></li>
										<li><strong>错误恢复</strong>: <?php esc_html_e( '失败的操作自动重试，提高成功率', 'notion-to-wordpress' ); ?></li>
										<li><strong>智能调度</strong>: <?php esc_html_e( '根据系统负载智能调整处理速度', 'notion-to-wordpress' ); ?></li>
										<li><strong>状态监控</strong>: <?php esc_html_e( '实时监控处理进度和系统状态', 'notion-to-wordpress' ); ?></li>
										<li><strong>用户体验</strong>: <?php esc_html_e( '后台处理，不阻塞用户操作', 'notion-to-wordpress' ); ?></li>
									</ul>
								</div>
							</div>

							<!-- 数据库索引优化部分 -->
							<div class="notion-wp-performance-card">
								<h3><?php esc_html_e( '🗄️ 数据库索引优化', 'notion-to-wordpress' ); ?></h3>
								<p><?php esc_html_e( '数据库索引可以显著提升查询性能，特别是在处理大量数据时。这是零风险的优化，预期性能提升30-50%。', 'notion-to-wordpress' ); ?></p>

								<div class="notion-wp-index-status" id="index-status-container">
									<div class="loading-placeholder">
										<span class="spinner is-active"></span>
										<?php esc_html_e( '正在检查索引状态...', 'notion-to-wordpress' ); ?>
									</div>
								</div>

								<div class="notion-wp-index-actions">
									<button type="button" class="button button-primary notion-wp-optimize-all-btn" id="optimize-all-indexes">
										<span class="dashicons dashicons-performance"></span>
										<?php esc_html_e( '一键优化所有索引', 'notion-to-wordpress' ); ?>
									</button>
									<button type="button" class="button button-secondary" id="refresh-index-status" style="margin-left: 15px;">
										<span class="dashicons dashicons-update"></span>
										<?php esc_html_e( '刷新状态', 'notion-to-wordpress' ); ?>
									</button>
									<button type="button" class="button button-link-delete notion-wp-hidden" id="remove-database-indexes" style="margin-left: 10px;">
										<span class="dashicons dashicons-database-remove"></span>
										<?php esc_html_e( '删除索引', 'notion-to-wordpress' ); ?>
									</button>
								</div>

								<div class="notion-wp-index-info">
									<h4><?php esc_html_e( '🚀 一键优化说明', 'notion-to-wordpress' ); ?></h4>
									<ul>
										<li><strong>🔥 专用索引</strong>: <?php esc_html_e( '针对Notion查询模式优化的5个高性能索引', 'notion-to-wordpress' ); ?></li>
										<li><strong>⚡ 智能覆盖</strong>: <?php esc_html_e( '包含meta_key、复合索引、覆盖索引等全面优化', 'notion-to-wordpress' ); ?></li>
										<li><strong>🛡️ 零风险操作</strong>: <?php esc_html_e( '安全创建，不影响现有数据，可随时删除', 'notion-to-wordpress' ); ?></li>
										<li><strong>📈 性能提升</strong>: <?php esc_html_e( '预计查询速度提升20-30%，大数据集效果更明显', 'notion-to-wordpress' ); ?></li>
										<li><strong>🎯 实时反馈</strong>: <?php esc_html_e( '显示创建进度、性能提升和详细统计信息', 'notion-to-wordpress' ); ?></li>
									</ul>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="notion-wp-tab-content" id="debug">
					<div class="notion-wp-settings-section">
						<h2><?php esc_html_e( '调试工具', 'notion-to-wordpress' ); ?></h2>
						<p><?php esc_html_e( '在这里，您可以管理日志级别、查看和清除日志文件。', 'notion-to-wordpress' ); ?></p>
						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row"><label for="debug_level"><?php esc_html_e( '日志记录级别', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<select id="debug_level" name="debug_level">
											<option value="<?php echo esc_attr( (string) \NTWP\Core\Logger::DEBUG_LEVEL_NONE ); ?>" <?php selected( $debug_level, \NTWP\Core\Logger::DEBUG_LEVEL_NONE ); ?>><?php esc_html_e( '无日志', 'notion-to-wordpress' ); ?></option>
											<option value="<?php echo esc_attr( (string) \NTWP\Core\Logger::DEBUG_LEVEL_ERROR ); ?>" <?php selected( $debug_level, \NTWP\Core\Logger::DEBUG_LEVEL_ERROR ); ?>><?php esc_html_e( '仅错误', 'notion-to-wordpress' ); ?></option>
											<option value="<?php echo esc_attr( (string) \NTWP\Core\Logger::DEBUG_LEVEL_WARNING ); ?>" <?php selected( $debug_level, \NTWP\Core\Logger::DEBUG_LEVEL_WARNING ); ?>><?php esc_html_e( '警告和错误', 'notion-to-wordpress' ); ?></option>
											<option value="<?php echo esc_attr( (string) \NTWP\Core\Logger::DEBUG_LEVEL_INFO ); ?>" <?php selected( $debug_level, \NTWP\Core\Logger::DEBUG_LEVEL_INFO ); ?>><?php esc_html_e( '信息、警告和错误', 'notion-to-wordpress' ); ?></option>
											<option value="<?php echo esc_attr( (string) \NTWP\Core\Logger::DEBUG_LEVEL_DEBUG ); ?>" <?php selected( $debug_level, \NTWP\Core\Logger::DEBUG_LEVEL_DEBUG ); ?>><?php esc_html_e( '所有日志 (调试)', 'notion-to-wordpress' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( '设置日志记录的详细程度。建议在生产环境中设置为"仅错误"。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="log_retention_days"><?php esc_html_e( '日志保留期限', 'notion-to-wordpress' ); ?></label></th>
									<td>
										<?php
										$log_retention_days = $options['log_retention_days'] ?? 0;
										$retention_options  = array(
											'0'  => __( '从不自动清理', 'notion-to-wordpress' ),
											'7'  => __( '7 天', 'notion-to-wordpress' ),
											'14' => __( '14 天', 'notion-to-wordpress' ),
											'30' => __( '30 天', 'notion-to-wordpress' ),
											'60' => __( '60 天', 'notion-to-wordpress' ),
										);
										?>
										<select id="log_retention_days" name="log_retention_days">
											<?php foreach ( $retention_options as $days => $label ) : ?>
											<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $log_retention_days, $days ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( '自动删除超过指定天数的旧日志文件。设置为"从不"以禁用。', 'notion-to-wordpress' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '错误日志', 'notion-to-wordpress' ); ?></th>
									<td>
										<div id="log-viewer-container">
											<select id="log-file-selector">
												<?php foreach ( \NTWP\Core\Logger::get_log_files() as $file ) : ?>
													<option value="<?php echo esc_attr( $file ); ?>"><?php echo esc_html( $file ); ?></option>
												<?php endforeach; ?>
											</select>
											<button type="button" class="button button-secondary" id="view-log-button"><?php esc_html_e( '查看日志', 'notion-to-wordpress' ); ?></button>
											<button type="button" class="button button-danger" id="clear-logs-button"><?php esc_html_e( '清除所有日志', 'notion-to-wordpress' ); ?></button>
											<textarea id="log-viewer" class="large-text code notion-wp-log-viewer" rows="18" readonly></textarea>
										</div>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<div class="notion-wp-tab-content" id="help">
					<div class="notion-wp-settings-section">
						<h2><?php esc_html_e( '使用帮助', 'notion-to-wordpress' ); ?></h2>

						<div class="notion-wp-help-section">
							<h3><?php esc_html_e( '快速开始', 'notion-to-wordpress' ); ?></h3>
							<ol>
								<li><?php esc_html_e( '在Notion创建一个集成并获取API密钥', 'notion-to-wordpress' ); ?></li>
								<li><?php esc_html_e( '在Notion中创建一个数据库，并与您的集成共享', 'notion-to-wordpress' ); ?></li>
								<li><?php esc_html_e( '复制数据库ID（从URL中获取）', 'notion-to-wordpress' ); ?></li>
								<li><?php esc_html_e( '在此页面配置API密钥和数据库ID', 'notion-to-wordpress' ); ?></li>
								<li><?php esc_html_e( '配置字段映射，确保Notion属性名称与WordPress字段正确对应', 'notion-to-wordpress' ); ?></li>
								<li><?php esc_html_e( '点击"测试连接"确认设置正确', 'notion-to-wordpress' ); ?></li>
								<li><?php esc_html_e( '点击"保存所有设置"保存您的配置', 'notion-to-wordpress' ); ?></li>
								<li><?php esc_html_e( '点击"手动同步"或设置自动同步频率开始导入内容', 'notion-to-wordpress' ); ?></li>
							</ol>
						</div>

						<div class="notion-wp-help-section">
							<h3><?php esc_html_e( '常见问题', 'notion-to-wordpress' ); ?></h3>
							<p><strong><?php esc_html_e( '问：为什么我的Notion页面没有导入？', 'notion-to-wordpress' ); ?></strong></p>
							<p><?php esc_html_e( '答：请检查以下几点：', 'notion-to-wordpress' ); ?></p>
							<ul>
								<li><?php esc_html_e( '确认您的API密钥和数据库ID正确', 'notion-to-wordpress' ); ?></li>
								<li><?php esc_html_e( '确认您的Notion集成已与数据库共享', 'notion-to-wordpress' ); ?></li>
								<li><?php esc_html_e( '检查字段映射是否正确对应Notion中的属性名称', 'notion-to-wordpress' ); ?></li>
								<li><?php esc_html_e( '尝试使用"完全同步"按钮重新同步', 'notion-to-wordpress' ); ?></li>
							</ul>

							<p><strong><?php esc_html_e( '问：如何自定义导入的内容格式？', 'notion-to-wordpress' ); ?></strong></p>
							<p><?php esc_html_e( '答：本插件会尽可能保留Notion中的格式，包括标题、列表、表格、代码块等。对于特殊内容（如数学公式、图表），插件也提供了支持。', 'notion-to-wordpress' ); ?></p>

							<p><strong><?php esc_html_e( '问：导入后如何更新内容？', 'notion-to-wordpress' ); ?></strong></p>
							<p><?php esc_html_e( '答：当您在Notion中更新内容后，可以点击"完全同步"按钮手动更新，或等待自动同步（如果已设置）。', 'notion-to-wordpress' ); ?></p>
						</div>

						<div class="notion-wp-help-section">
							<h3><?php esc_html_e( '获取支持', 'notion-to-wordpress' ); ?></h3>
							<p><?php esc_html_e( '如果您遇到任何问题或需要帮助，请访问我们的GitHub仓库：', 'notion-to-wordpress' ); ?></p>
							<p><a href="https://github.com/Frank-Loong/Notion-to-WordPress" target="_blank">https://github.com/Frank-Loong/Notion-to-WordPress</a></p>
						</div>
					</div>
				</div>

				<div class="notion-wp-tab-content" id="about-author">
					<div class="notion-wp-settings-section">
						<h2><?php esc_html_e( '关于作者', 'notion-to-wordpress' ); ?></h2>

						<div class="author-info">
							<div class="author-avatar">
								<img src="<?php echo esc_url( plugin_dir_url( dirname( __DIR__ ) ) . 'assets/avatar.svg' ); ?>" alt="Frank-Loong" onerror="this.style.display='none'">
							</div>
							<div class="author-details">
								<h3>Frank-Loong</h3>
								<p class="author-title"><?php esc_html_e( '科技爱好者 & AI玩家', 'notion-to-wordpress' ); ?></p>
								<p class="author-description">
									<?php esc_html_e( '对互联网、计算机等科技行业充满热情，擅长 AI 工具的使用与调教。', 'notion-to-wordpress' ); ?>
									<?php esc_html_e( '此插件在强大的 AI 编程助手 Cursor 和 Augment 的协助下完成，现在将这个有趣的项目分享给大家。', 'notion-to-wordpress' ); ?>
								</p>
								<div class="author-links">
									<a href="https://frankloong.com" target="_blank" class="author-link">
										<span class="link-icon">🌐</span>
										<?php esc_html_e( '个人网站', 'notion-to-wordpress' ); ?>
									</a>
									<a href="mailto:frankloong@qq.com" class="author-link">
										<span class="link-icon">📧</span>
										<?php esc_html_e( '联系邮箱', 'notion-to-wordpress' ); ?>
									</a>
									<a href="https://github.com/Frank-Loong/Notion-to-WordPress" target="_blank" class="author-link">
										<span class="link-icon">💻</span>
										GitHub
									</a>
								</div>
							</div>
						</div>

						<div class="plugin-info">
							<h4><?php esc_html_e( '插件信息', 'notion-to-wordpress' ); ?></h4>
							<div class="info-grid">
								<div class="info-item">
									<span class="info-label"><?php esc_html_e( '版本：', 'notion-to-wordpress' ); ?></span>
									<span class="info-value"><?php echo esc_html( NOTION_TO_WORDPRESS_VERSION ); ?></span>
								</div>
								<div class="info-item">
									<span class="info-label"><?php esc_html_e( '许可证：', 'notion-to-wordpress' ); ?></span>
									<span class="info-value">GPL v3</span>
								</div>
								<div class="info-item">
									<span class="info-label"><?php esc_html_e( '兼容性：', 'notion-to-wordpress' ); ?></span>
									<span class="info-value">WordPress 5.0+</span>
								</div>
							</div>
						</div>

						<div class="acknowledgments">
							<h4><?php esc_html_e( '致谢与参考', 'notion-to-wordpress' ); ?></h4>
							<p><?php esc_html_e( '本项目的开发过程中参考了以下优秀的开源项目：', 'notion-to-wordpress' ); ?></p>
							<div class="reference-projects">
								<div class="reference-item">
									<a href="https://github.com/tangly1024/NotionNext" target="_blank">NotionNext</a>
									<p><?php esc_html_e( '基于 Notion 的强大静态博客系统', 'notion-to-wordpress' ); ?></p>
								</div>
								<div class="reference-item">
									<a href="https://github.com/LetTTGACO/elog" target="_blank">Elog</a>
									<p><?php esc_html_e( '支持多平台的开源博客写作客户端', 'notion-to-wordpress' ); ?></p>
								</div>
								<div class="reference-item">
									<a href="https://github.com/pchang78/notion-content" target="_blank">notion-content</a>
									<p><?php esc_html_e( 'Notion 内容管理解决方案', 'notion-to-wordpress' ); ?></p>
								</div>
							</div>
							<p class="acknowledgments-footer"><em><?php esc_html_e( '感谢这些项目及其维护者对开源社区的贡献！', 'notion-to-wordpress' ); ?></em></p>
						</div>
					</div>
				</div>

				<div class="notion-wp-actions-bar">

					<?php submit_button( __( '保存所有设置', 'notion-to-wordpress' ), 'primary', 'submit', false, array( 'id' => 'notion-save-settings' ) ); ?>
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

<div id="loading-overlay" class="notion-wp-hidden">
	<div class="loading-message">
		<span class="spinner is-active"></span>
		<?php esc_html_e( '处理中，请稍候...', 'notion-to-wordpress' ); ?>
	</div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {

	// Toast提示函数 - 定义为全局函数
	window.showToast = function(type, message) {
		var $ = jQuery;
		var toast = $('#notion-wp-toast');
		var icon = toast.find('.dashicons');

		// 设置图标
		icon.removeClass().addClass('dashicons');
		if (type === 'success') {
			icon.addClass('dashicons-yes-alt');
			toast.removeClass('error').addClass('success');
		} else {
			icon.addClass('dashicons-warning');
			toast.removeClass('success').addClass('error');
		}

		// 设置消息
		toast.find('.notion-wp-toast-content').text(message);

		// 显示toast
		toast.addClass('show');

		// 3秒后自动隐藏
		setTimeout(function() {
			toast.removeClass('show');
		}, 3000);
	};

	// Toast关闭按钮
	$('.notion-wp-toast-close').on('click', function() {
		$('#notion-wp-toast').removeClass('show');
	});

	// 智能推荐功能
	$('#get-smart-recommendations').on('click', function() {
		const button = $(this);
		const resultDiv = $('#recommendations-result');

		button.prop('disabled', true).text('<?php esc_html_e( '分析中...', 'notion-to-wordpress' ); ?>');

		$.ajax({
			url: notionToWp.ajax_url,
			type: 'POST',
			data: {
				action: 'notion_to_wordpress_get_smart_recommendations',
				nonce: notionToWp.nonce
			},
			success: function(response) {
				if (response.success) {
					const recommendations = response.data;
					let html = '<div class="notice notice-info"><h4><?php esc_html_e( '💡 智能推荐结果', 'notion-to-wordpress' ); ?></h4>';

					html += '<p><strong><?php esc_html_e( '推荐性能级别:', 'notion-to-wordpress' ); ?></strong> ' + recommendations.performance_level + '</p>';
					html += '<p><strong><?php esc_html_e( '推荐字段模板:', 'notion-to-wordpress' ); ?></strong> ' + recommendations.field_template + '</p>';

					if (recommendations.reason && recommendations.reason.length > 0) {
						html += '<p><strong><?php esc_html_e( '推荐理由:', 'notion-to-wordpress' ); ?></strong></p><ul>';
						recommendations.reason.forEach(function(reason) {
							html += '<li>' + reason + '</li>';
						});
						html += '</ul>';
					}

					html += '<button type="button" class="button button-primary notion-wp-apply-recommendations" id="apply-recommendations"><?php esc_html_e( '应用推荐配置', 'notion-to-wordpress' ); ?></button>';
					html += '</div>';

					resultDiv.html(html).removeClass('notion-wp-hidden').show();

					// 应用推荐配置
					$('#apply-recommendations').on('click', function() {
						$('#performance_level').val(recommendations.performance_level);
						$('#field_template').val(recommendations.field_template);
						showToast('success', '<?php esc_html_e( '推荐配置已应用', 'notion-to-wordpress' ); ?>');
					});
				} else {
					resultDiv.html('<div class="notice notice-error"><p>' + response.data + '</p></div>').removeClass('notion-wp-hidden').show();
				}
			},
			error: function(xhr, status, error) {
				resultDiv.html('<div class="notice notice-error"><p><?php esc_html_e( '获取推荐配置失败，请稍后重试', 'notion-to-wordpress' ); ?></p></div>').removeClass('notion-wp-hidden').show();
			},
			complete: function() {
				button.prop('disabled', false).text('<?php esc_html_e( '获取配置建议', 'notion-to-wordpress' ); ?>');
			}
		});
	});
});
</script>
