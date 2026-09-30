<?php
declare(strict_types=1);

namespace NTWP\Services;

use WP_Error;

/**

 * Notion 图片处理器类
 *
 * 专门处理所有图片处理功能，包括异步图片下载、并发处理、占位符管理、
 * 图片队列系统等功能。
 *
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 如果直接访问此文件，则退出
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Image_Processor {

	/**
	 * 并发处理常量
	 */
	const DEFAULT_MAX_CONCURRENT = 5;      // 默认最大并发数
	const MAX_CONCURRENT_LIMIT   = 10;       // 最大并发限制
	const REQUEST_TIMEOUT        = 30;            // 请求超时时间（秒）
	const MAX_IMAGE_SIZE         = 10 * 1024 * 1024; // 最大图片大小（10MB）
	const ALLOWED_IMAGE_MIMES    = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	/**
	 * 状态管理器实例存储
	 *
	 * @since 2.0.0-beta.1
	 * @var array
	 */
	private static array $state_managers = array();

	/**
	 * 默认状态管理器ID
	 *
	 * @since 2.0.0-beta.1
	 * @var string
	 */
	private static string $default_state_id = 'default';

	/**
	 * 全局性能统计数据
	 *
	 * @since 2.0.0-beta.1
	 * @var array
	 */
	private static array $performance_stats = array(
		'total_images'         => 0,
		'concurrent_downloads' => 0,
		'download_time'        => 0,
		'processing_time'      => 0,
		'success_count'        => 0,
		'error_count'          => 0,
	);

	/**
	 * 获取或创建状态管理器
	 *
	 * @since 2.0.0-beta.1
	 * @param string $state_id 状态管理器ID
	 * @return array 状态管理器数据
	 */
	private static function get_state_manager( ?string $state_id = null ): array {

		$id = $state_id ?? self::$default_state_id;

		if ( ! isset( self::$state_managers[ $id ] ) ) {
			self::$state_managers[ $id ] = array(
				'pending_images'     => array(),
				'image_placeholders' => array(),
				'async_image_mode'   => false,
				'performance_stats'  => array(
					'total_images'         => 0,
					'concurrent_downloads' => 0,
					'download_time'        => 0,
					'processing_time'      => 0,
					'success_count'        => 0,
					'error_count'          => 0,
				),
				'created_at'         => time(),
				'last_used'          => time(),
			);
		}

		// 更新最后使用时间
		self::$state_managers[ $id ]['last_used'] = time();

		return self::$state_managers[ $id ];
	}

	/**
	 * 更新状态管理器
	 *
	 * @since 2.0.0-beta.1
	 * @param array  $state 状态数据
	 * @param string $state_id 状态管理器ID
	 */
	private static function update_state_manager( array $state, ?string $state_id = null ): void {

		$id                          = $state_id ?? self::$default_state_id;
		$state['last_used']          = time();
		self::$state_managers[ $id ] = $state;
	}

	/**
	 * 清理过期的状态管理器
	 *
	 * @since 2.0.0-beta.1
	 * @param int $max_age 最大存活时间（秒），默认1小时
	 */
	private static function cleanup_expired_states( int $max_age = 3600 ): void {
		$current_time = time();
		foreach ( self::$state_managers as $id => $state ) {
			if ( $id !== self::$default_state_id &&
				( $current_time - $state['last_used'] ) > $max_age ) {
				unset( self::$state_managers[ $id ] );

				\NTWP\Core\Logger::debug_log(
					"清理过期状态管理器: {$id}",
					'Image Processor'
				);
			}
		}
	}

	// ==================== 核心异步图片处理方法 ====================

	/**
	 * 启用异步图片模式
	 *
	 * @since 2.0.0-beta.1
	 * @param string $state_id 状态管理器ID，用于状态隔离
	 */
	public static function enable_async_image_mode( ?string $state_id = null ): void {

		$state                     = self::get_state_manager( $state_id );
		$state['async_image_mode'] = true;

		// 清理当前状态的图片队列
		$state['pending_images']     = array();
		$state['image_placeholders'] = array();

		self::update_state_manager( $state, $state_id );

		\NTWP\Core\Logger::debug_log(
			'异步图片模式已启用' . ( $state_id ? " (状态ID: {$state_id})" : '' ),
			'Async Image'
		);
	}

	/**
	 * 禁用异步图片模式
	 *
	 * @since 2.0.0-beta.1
	 * @param string $state_id 状态管理器ID，用于状态隔离
	 */
	public static function disable_async_image_mode( ?string $state_id = null ): void {

		$state                     = self::get_state_manager( $state_id );
		$state['async_image_mode'] = false;

		self::update_state_manager( $state, $state_id );

		\NTWP\Core\Logger::debug_log(
			'异步图片模式已禁用' . ( $state_id ? " (状态ID: {$state_id})" : '' ),
			'Async Image'
		);
	}

	/**
	 * 检查是否启用了异步图片模式
	 *
	 * @since 2.0.0-beta.1
	 * @param string $state_id 状态管理器ID，用于状态隔离
	 * @return bool 是否启用异步模式
	 */
	public static function is_async_image_mode_enabled( ?string $state_id = null ): bool {

		$state = self::get_state_manager( $state_id );
		return $state['async_image_mode'];
	}

	/**
	 * 收集图片用于异步下载
	 *
	 * @since 2.0.0-beta.1
	 * @param string $url 图片URL
	 * @param string $caption 图片说明
	 * @param string $state_id 状态管理器ID，用于状态隔离
	 * @return string 占位符字符串或直接HTML
	 */
	public static function collect_image_for_download( string $url, string $caption = '', ?string $state_id = null ): string {

		$state            = self::get_state_manager( $state_id );
		$options          = get_option( 'notion_to_wordpress_options', array() );
		$performance_mode = (bool) ( $options['enable_performance_mode'] ?? true );
		if ( ! $state['async_image_mode'] ) {
			// 非异步模式，直接返回图片HTML
			return self::generate_direct_image_html( $url, $caption );
		}

		// 检查是否为Notion临时URL，只有临时URL才需要下载
		if ( ! self::is_notion_temp_url( $url ) ) {
			// 外部永久链接，直接生成HTML，不下载
			// 性能模式下减少日志调用
			if ( ! $performance_mode ) {
				\NTWP\Core\Logger::debug_log(
					'外部永久链接，直接引用: ' . self::get_stable_image_url( $url ),
					'Image Processing'
				);
			}
			return self::generate_direct_image_html( $url, $caption );
		}

		// 性能模式：检查是否已经处理过相同的图片
		if ( $performance_mode ) {
			$image_hash          = self::get_stable_image_hash( $url );
			$existing_attachment = self::get_existing_attachment_by_hash( $image_hash );
			if ( $existing_attachment ) {
				// Keep the same placeholder/replacement path as a fresh download so
				// anchor wrappers and generated markup remain stable across syncs.
				$placeholder = 'pending_image_' . md5( $url . time() . rand() );
				$state['image_placeholders'][ $placeholder ] = array(
					'url'           => $url,
					'caption'       => $caption,
					'status'        => 'completed',
					'attachment_id' => $existing_attachment,
				);
				++$state['performance_stats']['total_images'];
				++$state['performance_stats']['success_count'];
				self::update_state_manager( $state, $state_id );
				return $placeholder;
			}
		}

		// 生成唯一占位符
		$placeholder = 'pending_image_' . md5( $url . time() . rand() );

		// 提取基础URL（去除查询参数）
		$base_url = strtok( $url, '?' );

		// 添加到待下载队列
		$state['pending_images'][] = array(
			'url'         => $url,
			'base_url'    => $base_url,
			'caption'     => $caption,
			'placeholder' => $placeholder,
			'timestamp'   => time(),
		);

		++$state['performance_stats']['total_images'];

		// 保存状态到占位符映射
		$state['image_placeholders'][ $placeholder ] = array(
			'url'     => $url,
			'caption' => $caption,
			'status'  => 'pending',
		);

		// 更新状态管理器
		self::update_state_manager( $state, $state_id );

		\NTWP\Core\Logger::debug_log(
			'Notion临时链接图片已添加到下载队列: ' . self::get_stable_image_url( $url ) . " -> {$placeholder}" .
			( $state_id ? " (状态ID: {$state_id})" : '' ),
			'Async Image'
		);

		return $placeholder;
	}

	/**
	 * 批量下载所有待处理的图片
	 *
	 * @since 2.0.0-beta.1
	 * @param string $state_id 状态管理器ID，用于状态隔离
	 * @param int    $max_concurrent 最大并发数
	 * @return void
	 */
	public static function batch_download_images( ?string $state_id = null, int $max_concurrent = self::DEFAULT_MAX_CONCURRENT ): void {

		$state = self::get_state_manager( $state_id );

		if ( empty( $state['pending_images'] ) ) {
			\NTWP\Core\Logger::debug_log(
				'没有待下载的图片' . ( $state_id ? " (状态ID: {$state_id})" : '' ),
				'Async Image'
			);
			return;
		}

		$start_time   = microtime( true );
		$total_images = count( $state['pending_images'] );

		\NTWP\Core\Logger::info_log(
			"开始批量下载 {$total_images} 张图片" . ( $state_id ? " (状态ID: {$state_id})" : '' ),
			'Async Image'
		);

		// 检查是否有重复的图片URL，避免重复下载
		$unique_images      = array();
		$url_to_placeholder = array();

		foreach ( $state['pending_images'] as $image_info ) {
			$base_url = $image_info['base_url'];

			if ( ! isset( $unique_images[ $base_url ] ) ) {
				$unique_images[ $base_url ]      = $image_info;
				$url_to_placeholder[ $base_url ] = array( $image_info['placeholder'] );
			} else {
				// 记录重复的占位符，稍后一起替换
				$url_to_placeholder[ $base_url ][] = $image_info['placeholder'];
			}
		}

		$unique_count = count( $unique_images );
		if ( $unique_count < $total_images ) {
			\NTWP\Core\Logger::debug_log(
				"去重后需要下载 {$unique_count} 张图片（原始: {$total_images}）" .
				( $state_id ? " (状态ID: {$state_id})" : '' ),
				'Async Image'
			);
		}

		// 准备并发下载请求
		$requests = array();
		foreach ( $unique_images as $base_url => $image_info ) {
			$requests[] = array(
				'url'        => $image_info['url'],
				'args'       => array(
					'timeout'    => 30,
					'user-agent' => 'Notion-to-WordPress/2.0.0',
				),
				'image_info' => $image_info,
			);
		}

		// All batch sizes use the same validated cURL-multi transport. This keeps
		// captions and response validation identical while still downloading in
		// parallel when several images are queued.
		$responses      = array();
		$concurrent_cap = max( 1, min( $max_concurrent, self::MAX_CONCURRENT_LIMIT ) );
		foreach ( array_chunk( $requests, $concurrent_cap, true ) as $request_chunk ) {
			$responses += self::concurrent_download_images( $request_chunk );
		}
		ksort( $responses );
		$state['performance_stats']['concurrent_downloads'] = count( $responses );
		$state['performance_stats']['parallel_processing']  = count( $requests ) >= 5;

		// 处理下载结果
		$success_count = 0;
		$error_count   = 0;

		foreach ( $responses as $index => $response ) {
			$image_info = $requests[ $index ]['image_info'];
			$base_url   = $image_info['base_url'];

			if ( is_wp_error( $response ) ) {
				\NTWP\Core\Logger::error_log(
					'图片下载失败: ' . self::get_stable_image_url( $image_info['url'] ) . ' - ' . $response->get_error_message(),
					'Async Image'
				);

				// 标记所有相同URL的占位符为失败
				foreach ( $url_to_placeholder[ $base_url ] as $placeholder ) {
					$state['image_placeholders'][ $placeholder ]['status']        = 'failed';
					$state['image_placeholders'][ $placeholder ]['attachment_id'] = null;
				}
				++$error_count;
			} else {
				// 检查是否是并行处理的结果
				if ( isset( $response['attachment_id'] ) ) {
					// 并行处理器已经创建了附件
					$attachment_id = $response['attachment_id'];
					foreach ( $url_to_placeholder[ $base_url ] as $placeholder ) {
						$state['image_placeholders'][ $placeholder ]['status']        = 'completed';
						$state['image_placeholders'][ $placeholder ]['attachment_id'] = $attachment_id;
					}
					++$success_count;
				} else {
					// 处理成功下载的图片（传统方式）
					$attachment_id = self::process_downloaded_image_response( $image_info, $response );

					if ( $attachment_id ) {
						// 为所有相同URL的占位符设置相同的附件ID
						foreach ( $url_to_placeholder[ $base_url ] as $placeholder ) {
							$state['image_placeholders'][ $placeholder ]['status']        = 'completed';
							$state['image_placeholders'][ $placeholder ]['attachment_id'] = $attachment_id;
						}
						++$success_count;
					} else {
						// 处理失败
						foreach ( $url_to_placeholder[ $base_url ] as $placeholder ) {
							$state['image_placeholders'][ $placeholder ]['status']        = 'failed';
							$state['image_placeholders'][ $placeholder ]['attachment_id'] = null;
						}
						++$error_count;
					}
				}
			}
		}

		$end_time      = microtime( true );
		$download_time = $end_time - $start_time;

		// 更新性能统计
		$state['performance_stats']['download_time'] = $download_time;
		$state['performance_stats']['success_count'] = $success_count;
		$state['performance_stats']['error_count']   = $error_count;

		// 更新状态管理器
		self::update_state_manager( $state, $state_id );

		\NTWP\Core\Logger::info_log(
			sprintf(
				'批量下载完成: 成功 %d, 失败 %d, 耗时 %.2f 秒' . ( $state_id ? " (状态ID: {$state_id})" : '' ),
				$success_count,
				$error_count,
				$download_time
			),
			'Async Image'
		);
	}

	/**
	 * 并发下载图片
	 *
	 * @since 2.0.0-beta.1
	 * @param array $requests 请求数组
	 * @return array 响应数组
	 */
	private static function concurrent_download_images( array $requests ): array {
		if ( empty( $requests ) ) {
			return array();
		}

		// 使用WordPress的HTTP API进行并发请求
		$multi_requests = array();

		// 准备并发请求
		foreach ( $requests as $index => $request ) {
			$multi_requests[ $index ] = array(
				'url'  => $request['url'],
				'args' => array_merge(
					$request['args'],
					array(
						'blocking'   => false, // 非阻塞模式
						'timeout'    => 30,
						'user-agent' => 'Notion-to-WordPress/2.0.0',
					)
				),
			);
		}

		\NTWP\Core\Logger::debug_log(
			sprintf( 'Starting concurrent download of %d images', count( $multi_requests ) ),
			'Concurrent Download'
		);

		// 使用cURL多句柄进行并发下载
		return self::execute_concurrent_requests( $multi_requests );
	}

	/**
	 * 执行并发HTTP请求
	 *
	 * @since 2.0.0-beta.1
	 * @param array $multi_requests 多个请求
	 * @return array 响应数组
	 */
	private static function execute_concurrent_requests( array $multi_requests ): array {
		$responses = array();

		// 检查是否支持cURL
		if ( ! function_exists( 'curl_multi_init' ) ) {
			\NTWP\Core\Logger::debug_log(
				'cURL multi not available, falling back to sequential requests',
				'Concurrent Download'
			);

			// 降级到顺序请求
			foreach ( $multi_requests as $index => $request ) {
				if ( ! self::is_safe_download_url( $request['url'] ) ) {
					$responses[ $index ] = new WP_Error( 'unsafe_image_url', '图片下载地址未通过安全检查' );
					continue;
				}

				$args                  = array_merge( $request['args'], array( 'reject_unsafe_urls' => true ) );
				$response              = function_exists( 'wp_safe_remote_get' ) ? wp_safe_remote_get( $request['url'], $args ) : wp_remote_get( $request['url'], $args );
				$responses[ $index ] = $response;
			}
			return $responses;
		}

		// 创建cURL多句柄
		$multi_handle = curl_multi_init();
		$curl_handles = array();

		// 添加所有请求到多句柄
		foreach ( $multi_requests as $index => $request ) {
			if ( ! self::is_safe_download_url( $request['url'] ) ) {
				$responses[ $index ] = new WP_Error( 'unsafe_image_url', '图片下载地址未通过安全检查' );
				continue;
			}

			$ch = curl_init();
			$options = array(
				CURLOPT_URL            => $request['url'],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_USERAGENT      => 'Notion-to-WordPress/2.0.0',
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_MAXFILESIZE    => self::MAX_IMAGE_SIZE,
			);
			if ( defined( 'CURLOPT_PROTOCOLS' ) ) {
				$options[ CURLOPT_PROTOCOLS ] = ( defined( 'NTWP_INTEGRATION_TEST' ) && NTWP_INTEGRATION_TEST ) ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS;
			}

			curl_setopt_array(
				$ch,
				$options
			);

			curl_multi_add_handle( $multi_handle, $ch );
			$curl_handles[ $index ] = $ch;
		}

		// 执行并发请求
		$running = null;
		do {
			curl_multi_exec( $multi_handle, $running );
			curl_multi_select( $multi_handle );
		} while ( $running > 0 );

		// 收集响应
		foreach ( $curl_handles as $index => $ch ) {
			$content   = curl_multi_getcontent( $ch );
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$content_type = curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
			$error     = curl_error( $ch );

			if ( ! empty( $error ) ) {
				$responses[ $index ] = new WP_Error( 'curl_error', $error );
			} else {
				// 模拟WordPress HTTP API响应格式
				$responses[ $index ] = array(
					'body'     => $content,
					'response' => array(
						'code'    => $http_code,
						'message' => '',
					),
					'headers'  => array(
						'content-type' => is_string( $content_type ) ? $content_type : '',
					),
				);
			}

			curl_multi_remove_handle( $multi_handle, $ch );
			curl_close( $ch );
		}

		curl_multi_close( $multi_handle );
		ksort( $responses );

		\NTWP\Core\Logger::debug_log(
			sprintf( 'Concurrent download completed: %d responses', count( $responses ) ),
			'Concurrent Download'
		);

		return $responses;
	}

	/**
	 * 处理下载的图片响应
	 *
	 * @since 2.0.0-beta.1
	 * @param array $image_info 图片信息
	 * @param array $response HTTP响应
	 * @return int|null 附件ID或null
	 */
	private static function process_downloaded_image_response( array $image_info, array|WP_Error $response ): ?int {

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code !== 200 ) {
			\NTWP\Core\Logger::error_log(
				'图片下载HTTP错误: ' . self::get_stable_image_url( $image_info['url'] ) . " - HTTP {$http_code}",
				'Async Image'
			);
			return null;
		}

		$image_data = wp_remote_retrieve_body( $response );
		if ( empty( $image_data ) ) {
			\NTWP\Core\Logger::error_log(
				'图片数据为空: ' . self::get_stable_image_url( $image_info['url'] ),
				'Async Image'
			);
			return null;
		}
		if ( strlen( $image_data ) > self::MAX_IMAGE_SIZE ) {
			\NTWP\Core\Logger::warning_log(
				'图片超过允许的大小，已跳过: ' . self::get_stable_image_url( $image_info['url'] ),
				'Async Image'
			);
			return null;
		}

		$content_type = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
		$extension    = self::get_extension_from_content_type( $content_type );
		if ( '' === $extension && ( '' === $content_type || str_starts_with( $content_type, 'application/octet-stream' ) ) ) {
			$image_info_from_body = function_exists( 'getimagesizefromstring' ) ? getimagesizefromstring( $image_data ) : false;
			$detected_mime        = is_array( $image_info_from_body ) ? (string) $image_info_from_body['mime'] : '';
			$extension            = self::get_extension_from_content_type( $detected_mime );
		}

		if ( '' === $extension ) {
			\NTWP\Core\Logger::warning_log(
				'图片响应类型不受支持，已跳过: ' . self::get_stable_image_url( $image_info['url'] ),
				'Async Image'
			);
			return null;
		}

		// 生成文件名
		$filename = 'notion-image-' . self::get_stable_image_hash( $image_info['url'] ) . '.' . $extension;

		// 上传到WordPress媒体库
		$upload = wp_upload_bits( $filename, null, $image_data );

		if ( $upload['error'] ) {
			\NTWP\Core\Logger::error_log(
				'图片上传失败: ' . self::get_stable_image_url( $image_info['url'] ) . ' - ' . $upload['error'],
				'Async Image'
			);
			return null;
		}

		// 创建附件
		$attachment = array(
			'post_mime_type' => wp_check_filetype( $filename )['type'],
			'post_title'     => ! empty( $image_info['caption'] ) ? $image_info['caption'] : 'Notion Image',
			'post_content'   => '',
			'post_excerpt'   => $image_info['caption'] ?? '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'], 0, true );
		if ( is_wp_error( $attachment_id ) ) {
			\NTWP\Core\Logger::error_log(
				'附件创建失败: ' . self::get_stable_image_url( $image_info['url'] ) . ' - ' . $attachment_id->get_error_message(),
				'Async Image'
			);
			return null;
		}

		// 生成附件元数据
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );
		update_post_meta( $attachment_id, '_notion_image_hash', self::get_stable_image_hash( $image_info['url'] ) );
		update_post_meta( $attachment_id, '_notion_original_url', esc_url_raw( $image_info['url'] ) );
		update_post_meta( $attachment_id, '_notion_base_url', esc_url_raw( self::get_stable_image_url( $image_info['url'] ) ) );
		if ( ! empty( $image_info['caption'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $image_info['caption'] ) );
		}

		// 性能模式下减少成功日志
		$options          = get_option( 'notion_to_wordpress_options', array() );
		$performance_mode = $options['enable_performance_mode'] ?? 1;

		if ( ! $performance_mode ) {
			\NTWP\Core\Logger::debug_log(
				'图片处理成功: ' . self::get_stable_image_url( $image_info['url'] ) . " -> 附件ID {$attachment_id}",
				'Async Image'
			);
		}

		return $attachment_id;
	}

	/**
	 * 处理异步图片（替换占位符）
	 *
	 * @since 2.0.0-beta.1
	 * @param string $html HTML内容
	 * @param string $state_id 状态管理器ID，用于状态隔离
	 * @param int    $max_concurrent 最大并发数
	 * @return string 处理后的HTML
	 */
	public static function process_async_images( string $html, ?string $state_id = null, int $max_concurrent = self::DEFAULT_MAX_CONCURRENT ): string {

		$state = self::get_state_manager( $state_id );

		if ( ! $state['async_image_mode'] || ( empty( $state['pending_images'] ) && empty( $state['image_placeholders'] ) ) ) {
			return $html;
		}

		$start_time = microtime( true );

		// 先下载所有图片（使用动态并发数）。已命中媒体库的占位符
		// 没有 pending_images，可直接进入替换阶段。
		if ( ! empty( $state['pending_images'] ) ) {
			self::batch_download_images( $state_id, $max_concurrent );
		}

		// 然后替换占位符
		$processed_html = self::replace_image_placeholders( $html, $state_id );

		// 清理状态
		self::clear_image_queue( $state_id );

		$processing_time = microtime( true ) - $start_time;
		$state           = self::get_state_manager( $state_id );
		$state['performance_stats']['processing_time'] = $processing_time;
		self::update_state_manager( $state, $state_id );

		// 强制垃圾回收以释放内存
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		\NTWP\Core\Logger::info_log(
			sprintf(
				'异步图片处理完成，总耗时 %.2f 秒',
				$processing_time
			),
			'Async Image'
		);

		return $processed_html;
	}

	/**
	 * 替换图片占位符
	 *
	 * @since 2.0.0-beta.1
	 * @param string $html HTML内容
	 * @param string $state_id 状态管理器ID，用于状态隔离
	 * @return string 替换后的HTML
	 */
	public static function replace_image_placeholders( string $html, ?string $state_id = null ): string {

		$state = self::get_state_manager( $state_id );

		if ( empty( $state['image_placeholders'] ) ) {
			return $html;
		}

		$replaced_count = 0;

		foreach ( $state['image_placeholders'] as $placeholder => $placeholder_data ) {
			if ( isset( $placeholder_data['attachment_id'] ) && $placeholder_data['attachment_id'] ) {
				// 成功下载的图片，生成WordPress图片HTML
				$image_html = self::generate_wordpress_image_html( $placeholder_data['attachment_id'], $placeholder_data['caption'] ?? '' );
				$html       = str_replace( $placeholder, $image_html, $html );
				++$replaced_count;
			} else {
				// 下载失败时保留安全的外链展示，避免整张图片从正文中消失。
				$fallback_html = self::generate_direct_image_html(
					$placeholder_data['url'] ?? '',
					$placeholder_data['caption'] ?? ''
				);
				$html          = str_replace( $placeholder, $fallback_html, $html );
			}
		}

		\NTWP\Core\Logger::debug_log(
			"替换了 {$replaced_count} 个图片占位符" . ( $state_id ? " (状态ID: {$state_id})" : '' ),
			'Async Image'
		);

		return $html;
	}

	// ==================== 辅助方法 ====================

	/**
	 * 清理图片队列
	 *
	 * @since 2.0.0-beta.1
	 * @param string $state_id 状态管理器ID，用于状态隔离
	 */
	private static function clear_image_queue( ?string $state_id = null ): void {

		$state = self::get_state_manager( $state_id );

		$state['pending_images']     = array();
		$state['image_placeholders'] = array();

		// 重置性能统计
		$state['performance_stats'] = array(
			'total_images'         => 0,
			'concurrent_downloads' => 0,
			'download_time'        => 0,
			'processing_time'      => 0,
			'success_count'        => 0,
			'error_count'          => 0,
		);

		self::update_state_manager( $state, $state_id );

		\NTWP\Core\Logger::debug_log(
			'图片队列已清理' . ( $state_id ? " (状态ID: {$state_id})" : '' ),
			'Image Processor'
		);
	}

	/**
	 * 生成直接图片HTML（非异步模式）
	 *
	 * @since 2.0.0-beta.1
	 * @param string $url 图片URL
	 * @param string $caption 图片说明
	 * @return string HTML内容
	 */
	private static function generate_direct_image_html( string $url, string $caption = '' ): string {
		$alt_text = ! empty( $caption ) ? esc_attr( $caption ) : '';
		$html     = '<figure class="notion-image">';
		$html    .= '<img src="' . esc_url( $url ) . '" alt="' . $alt_text . '" loading="lazy">';
		if ( ! empty( $caption ) ) {
			$html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>';
		}
		$html .= '</figure>';
		return $html;
	}

	/**
	 * 生成WordPress图片HTML
	 *
	 * @since 2.0.0-beta.1
	 * @param int    $attachment_id 附件ID
	 * @param string $placeholder 原占位符
	 * @return string HTML内容
	 */
	private static function generate_wordpress_image_html( int $attachment_id, string $placeholder ): string {
		// 获取图片信息
		$image_src     = wp_get_attachment_image_src( $attachment_id, 'large' );
		$image_alt     = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$image_caption = wp_get_attachment_caption( $attachment_id );
		if ( empty( $image_caption ) && ! empty( $placeholder ) ) {
			$image_caption = $placeholder;
		}
		if ( empty( $image_alt ) && ! empty( $placeholder ) ) {
			$image_alt = $placeholder;
		}

		if ( ! $image_src ) {
			return '<!-- 无法获取图片信息: 附件ID ' . $attachment_id . ' -->';
		}

		$html  = '<figure class="wp-block-image size-large">';
		$html .= '<img src="' . esc_url( $image_src[0] ) . '" alt="' . esc_attr( $image_alt ) . '" class="wp-image-' . $attachment_id . '"';

		$html .= ' width="' . $image_src[1] . '" height="' . $image_src[2] . '"';
		$html .= ' loading="lazy">';

		if ( ! empty( $image_caption ) ) {
			$html .= '<figcaption>' . esc_html( $image_caption ) . '</figcaption>';
		}

		$html .= '</figure>';

		return $html;
	}

	/**
	 * 检查是否为Notion临时URL
	 *
	 * @since 2.0.0-beta.1
	 * @param string $url 图片URL
	 * @return bool 是否为临时URL
	 */
	private static function is_notion_temp_url( string $url ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		if ( defined( 'NTWP_INTEGRATION_TEST' ) && NTWP_INTEGRATION_TEST && false !== strpos( $url, 'X-Amz-Algorithm' ) ) {
			return true;
		}

		return 'secure.notion-static.com' === $host ||
			str_ends_with( $host, '.notion-static.com' ) ||
			'notion.so' === $host ||
			str_ends_with( $host, '.notion.so' ) ||
			( str_ends_with( $host, '.amazonaws.com' ) && false !== strpos( $host, 'prod-files-secure' ) );
	}

	/**
	 * Reject non-HTTPS and non-public download targets before opening a socket.
	 * Local HTTP is allowed only inside the explicitly marked integration site.
	 */
	private static function is_safe_download_url( string $url ): bool {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $scheme || '' === $host ) {
			return false;
		}

		$is_integration = defined( 'NTWP_INTEGRATION_TEST' ) && NTWP_INTEGRATION_TEST;
		$is_loopback    = 'localhost' === $host || '::1' === $host || str_starts_with( $host, '127.' );
		if ( $is_integration && $is_loopback && in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return true;
		}

		if ( 'https' !== $scheme || $is_loopback || str_ends_with( $host, '.localhost' ) ) {
			return false;
		}

		if ( function_exists( 'wp_http_validate_url' ) && false === wp_http_validate_url( $url ) ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host );
		}

		$addresses = gethostbynamel( $host );
		if ( false === $addresses || array() === $addresses ) {
			return false;
		}

		foreach ( $addresses as $address ) {
			if ( ! self::is_public_ip( $address ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether an IP address is globally routable.
	 */
	private static function is_public_ip( string $address ): bool {
		return false !== filter_var(
			$address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Remove rotating signature parameters from a Notion image URL.
	 */
	private static function get_stable_image_url( string $url ): string {
		$base_url = strtok( $url, '?' );
		return is_string( $base_url ) && '' !== $base_url ? $base_url : $url;
	}

	/**
	 * Build the cross-sync deduplication key for a Notion image.
	 */
	private static function get_stable_image_hash( string $url ): string {
		return md5( self::get_stable_image_url( $url ) );
	}

	/**
	 * 根据Content-Type获取文件扩展名
	 *
	 * @since 2.0.0-beta.1
	 * @param string $content_type Content-Type头部
	 * @return string 文件扩展名
	 */
	private static function get_extension_from_content_type( string $content_type ): string {
		$content_type = strtolower( trim( $content_type ) );

		// 移除可能的字符集信息
		if ( strpos( $content_type, ';' ) !== false ) {
			$content_type = trim( explode( ';', $content_type )[0] );
		}

		return self::ALLOWED_IMAGE_MIMES[ $content_type ] ?? '';
	}

	/**
	 * 获取性能统计数据
	 *
	 * @since 2.0.0-beta.1
	 * @return array 性能统计
	 */
	public static function get_performance_stats(): array {
		return self::$performance_stats;
	}

	/**
	 * 获取当前队列状态
	 *
	 * @since 2.0.0-beta.1
	 * @return array 队列状态
	 */
	public static function get_queue_status( ?string $state_id = null ): array {

		$state = self::get_state_manager( $state_id );
		return array(
			'async_mode'     => $state['async_image_mode'],
			'pending_images' => count( $state['pending_images'] ),
			'placeholders'   => count( $state['image_placeholders'] ),
			'queue_details'  => $state['pending_images'],
		);
	}

	/**
	 * 重置图片处理器状态
	 *
	 * @since 2.0.0-beta.1
	 * @param string $state_id 状态管理器ID，为null时重置所有状态
	 */
	public static function reset( ?string $state_id = null ): void {

		if ( $state_id === null ) {
			// 重置所有状态管理器
			self::$state_managers = array();

			\NTWP\Core\Logger::debug_log(
				'图片处理器所有状态已重置',
				'Image Processor'
			);
		} else {
			// 重置特定状态管理器
			if ( isset( self::$state_managers[ $state_id ] ) ) {
				unset( self::$state_managers[ $state_id ] );

				\NTWP\Core\Logger::debug_log(
					"图片处理器状态已重置 (状态ID: {$state_id})",
					'Image Processor'
				);
			}
		}

		// 清理过期状态
		self::cleanup_expired_states();
	}


	// ==================== 状态管理公共方法 ====================

	/**
	 * 创建新的状态管理器
	 *
	 * @since 2.0.0-beta.1
	 * @param string $state_id 状态管理器ID
	 * @return string 创建的状态管理器ID
	 */
	public static function create_state( ?string $state_id = null ): string {

		if ( $state_id === null ) {
			$state_id = 'state_' . uniqid();
		}

		// 强制创建新状态
		self::$state_managers[ $state_id ] = array(
			'pending_images'     => array(),
			'image_placeholders' => array(),
			'async_image_mode'   => false,
			'performance_stats'  => array(
				'total_images'         => 0,
				'concurrent_downloads' => 0,
				'download_time'        => 0,
				'processing_time'      => 0,
				'success_count'        => 0,
				'error_count'          => 0,
			),
			'created_at'         => time(),
			'last_used'          => time(),
		);

		\NTWP\Core\Logger::debug_log(
			"创建新的状态管理器: {$state_id}",
			'Image Processor'
		);

		return $state_id;
	}

	/**
	 * 获取状态管理器信息
	 *
	 * @since 2.0.0-beta.1
	 * @param string $state_id 状态管理器ID
	 * @return array|null 状态信息或null
	 */
	public static function get_state_info( ?string $state_id = null ): ?array {

		$id = $state_id ?? self::$default_state_id;
		return self::$state_managers[ $id ] ?? null;
	}

	/**
	 * 获取所有活跃的状态管理器
	 *
	 * @since 2.0.0-beta.1
	 * @return array 状态管理器列表
	 */
	public static function get_active_states(): array {
		$states = array();
		foreach ( self::$state_managers as $id => $state ) {
			$states[ $id ] = array(
				'id'                => $id,
				'async_mode'        => $state['async_image_mode'],
				'pending_count'     => count( $state['pending_images'] ),
				'placeholder_count' => count( $state['image_placeholders'] ),
				'created_at'        => $state['created_at'],
				'last_used'         => $state['last_used'],
			);
		}
		return $states;
	}

	/**
	 * 清理所有过期状态
	 *
	 * @since 2.0.0-beta.1
	 * @param int $max_age 最大存活时间（秒）
	 * @return int 清理的状态数量
	 */
	public static function cleanup_states( int $max_age = 3600 ): int {
		$cleaned      = 0;
		$current_time = time();

		foreach ( self::$state_managers as $id => $state ) {
			if ( $id !== self::$default_state_id &&
				( $current_time - $state['last_used'] ) > $max_age ) {
				unset( self::$state_managers[ $id ] );
				++$cleaned;
			}
		}

		if ( $cleaned > 0 ) {
			\NTWP\Core\Logger::debug_log(
				"清理了 {$cleaned} 个过期状态管理器",
				'Image Processor'
			);
		}

		return $cleaned;
	}

	/**
	 * 根据哈希值获取已存在的附件
	 *
	 * @since 2.0.0-beta.1
	 * @param string $hash 图片哈希值
	 * @return int|false 附件ID或false
	 */
	private static function get_existing_attachment_by_hash( string $hash ) {
		global $wpdb;

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_notion_image_hash'
            AND meta_value = %s
            LIMIT 1",
				$hash
			)
		);

		return $attachment_id ? intval( $attachment_id ) : false;
	}

	/**
	 * 并行处理图片（整合自Parallel_Image_Processor）
	 *
	 * 简化的并行图片处理，移除复杂的预估和分组逻辑
	 *
	 * @since 2.0.0-beta.1
	 * @param array $image_urls 图片URL数组
	 * @param int   $max_concurrent 最大并发数
	 * @return array [url => attachment_id] 映射，失败的返回false
	 */
	public static function process_images_parallel( array $image_urls, int $max_concurrent = self::DEFAULT_MAX_CONCURRENT ): array {
		if ( empty( $image_urls ) ) {
			return array();
		}

		// 记录开始时间
		$start_time = microtime( true );

		if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
			\NTWP\Core\Logger::info_log(
				sprintf( '开始并行处理 %d 个图片', count( $image_urls ) ),
				'Image Processor'
			);
		}

		// 限制并发数在合理范围内
		$max_concurrent = max( 1, min( $max_concurrent, self::MAX_CONCURRENT_LIMIT ) );

		$results = array();

		// 分块处理以控制并发数
		$chunks = array_chunk( $image_urls, $max_concurrent );

		foreach ( $chunks as $chunk_index => $chunk ) {
			// 监控内存使用
			if ( class_exists( '\\NTWP\\Core\\Memory_Manager' ) ) {
				\NTWP\Core\Memory_Manager::monitor_memory_usage( 'Parallel Image Processing' );
			}

			$chunk_results = self::process_image_chunk_parallel( $chunk );
			$results       = array_merge( $results, $chunk_results );

			// 短暂休息以避免过载
			if ( $chunk_index < count( $chunks ) - 1 ) {
				usleep( 100000 ); // 0.1秒
			}
		}

		// 记录性能统计
		$processing_time = microtime( true ) - $start_time;
		$success_count   = count( array_filter( $results ) );

		if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
			\NTWP\Core\Logger::info_log(
				sprintf(
					'并行处理完成: %d/%d 成功, 耗时 %.2f 秒',
					$success_count,
					count( $image_urls ),
					$processing_time
				),
				'Image Processor'
			);
		}

		return $results;
	}

	/**
	 * 处理图片块（并行）
	 *
	 * @since 2.0.0-beta.1
	 * @param array $image_urls 图片URL数组
	 * @return array [url => attachment_id] 映射
	 */
	private static function process_image_chunk_parallel( array $image_urls ): array {
		if ( empty( $image_urls ) ) {
			return array();
		}

		$requests = array();
		foreach ( $image_urls as $url ) {
			$requests[] = array(
				'url'  => $url,
				'args' => array(
					'timeout'    => self::REQUEST_TIMEOUT,
					'user-agent' => 'Notion-to-WordPress/2.0.0',
				),
			);
		}
		$responses = self::execute_concurrent_requests( $requests );

		$results = array();

		foreach ( $image_urls as $index => $url ) {
			$response = $responses[ $index ] ?? null;

			if ( ! is_array( $response ) && ! is_wp_error( $response ) ) {
				$results[ $url ] = false;
				continue;
			}

			$results[ $url ] = self::process_downloaded_image_response(
				array(
					'url'     => $url,
					'caption' => '',
				),
				$response
			);
		}

		return $results;
	}
}
