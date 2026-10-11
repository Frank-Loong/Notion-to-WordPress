<?php
declare(strict_types=1);

namespace NTWP\Utils;

use Exception;

/**
 * 并发网络请求管理器类
 *
 * 基于PHP cURL multi-handle技术，提供批量HTTP请求处理能力。
 * 支持并发请求、错误处理、超时控制、重试机制和进度监控。
 *
 * @since      1.9.0-beta.1
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

class Concurrent_Network_Manager {

	/**
	 * cURL multi handle
	 *
	 * @since    1.1.2
	 * @access   private
	 * @var \CurlMultiHandle|null cURL multi handle
	 */
	private ?\CurlMultiHandle $multi_handle = null;
	/**
	 * cURL handles数组
	 *
	 * @since    1.1.2
	 * @access   private
	 * @var array<int, \CurlHandle> cURL句柄数组
	 */
	private array $curl_handles = array();
	/**
	 * 请求配置数组
	 *
	 * @since    1.1.2
	 * @access   private
	 * @var      array    $requests    请求配置数组
	 */
	private $requests = array();

	/**
	 * 响应结果数组
	 *
	 * @since    1.1.2
	 * @access   private
	 * @var      array    $responses    响应结果数组
	 */
	private $responses = array();

	/**
	 * 执行统计信息
	 *
	 * @since    1.1.2
	 * @access   private
	 * @var      array    $execution_stats    执行统计信息
	 */
	private $execution_stats = array();

	/**
	 * 最大并发请求数量
	 *
	 * @since    1.1.2
	 * @access   private
	 * @var      int    $max_concurrent_requests    最大并发请求数量
	 */
	private $max_concurrent_requests = 5;

	/**
	 * 默认超时时间（秒）
	 *
	 * @since    1.1.2
	 * @access   private
	 * @var      int    $default_timeout    默认超时时间
	 */
	private $default_timeout = 30;

	/**
	 * 构造函数
	 *
	 * @since    1.1.2
	 * @param    int $max_concurrent    最大并发请求数量
	 */
	public function __construct( $max_concurrent = 5 ) {
		$this->max_concurrent_requests = max( 1, min( 10, $max_concurrent ) );

		// 提高PHP执行时间限制
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // 设置为2分钟
		}

		// 记录初始化信息
		if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
			\NTWP\Core\Logger::debug_log(
				"初始化并发网络管理器，最大并发数: {$this->max_concurrent_requests}",
				'Concurrent Network'
			);
		}
	}

	/**
	 * 检查是否为性能模式
	 *
	 * @since 2.0.0-beta.1
	 * @return bool
	 */
	private function is_performance_mode(): bool {
		// 检查是否设置了性能模式
		return defined( 'NOTION_PERFORMANCE_MODE' ) && NOTION_PERFORMANCE_MODE;
	}

	/**
	 * 添加请求到队列
	 *
	 * @since    1.1.2
	 * @param    string $url     请求URL
	 * @param    array  $args    请求参数
	 * @return   int                请求ID
	 */
	public function add_request( $url, $args = array() ) {
		$request_id = count( $this->requests );

		// 设置默认参数
		$default_args = array(
			'method'     => 'GET',
			'timeout'    => $this->default_timeout,
			'headers'    => array(),
			'body'       => '',
			'user-agent' => 'Notion-to-WordPress/' . ( defined( 'NOTION_TO_WORDPRESS_VERSION' ) ? constant( 'NOTION_TO_WORDPRESS_VERSION' ) : '2.0.0' ),
		);

		$args = wp_parse_args( $args, $default_args );

		// 存储请求配置
		$this->requests[ $request_id ] = array(
			'url'  => $url,
			'args' => $args,
		);

		\NTWP\Core\Logger::debug_log(
			"添加请求到队列: {$args['method']} {$url}",
			'Concurrent Network'
		);

		return $request_id;
	}

	/**
	 * 执行所有并发请求
	 *
	 * @since    1.1.2
	 * @return   array    响应结果数组
	 */
	public function execute() {
		return $this->execute_with_retry();
	}

	/**
	 * 执行所有并发请求（支持重试）
	 *
	 * @since    1.1.2
	 * @param    int $max_retries    最大重试次数
	 * @param    int $base_delay     基础延迟时间（毫秒）
	 * @return   array                  响应结果数组
	 */
	public function execute_with_retry( $max_retries = 2, $base_delay = 1000 ) {
		return \NTWP\Utils\Network_Retry::with_retry(
			array( $this, 'execute_internal' ),
			$max_retries,
			$base_delay
		);
	}

	/**
	 * 内部执行方法（实际的并发请求处理）
	 *
	 * @since    1.1.2
	 * @return   array    响应结果数组
	 * @throws   Exception
	 */
	public function execute_internal() {
		if ( empty( $this->requests ) ) {
			\NTWP\Core\Logger::debug_log(
				'没有待执行的请求',
				'Concurrent Network'
			);
			return array();
		}

		$start_time = microtime( true );

		\NTWP\Core\Logger::debug_log(
			'开始执行 ' . count( $this->requests ) . ' 个并发请求',
			'Concurrent Network'
		);

		try {
			$this->init_multi_handle();
			$this->create_curl_handles();
			$this->execute_requests();
			$this->process_responses();

			$execution_time = microtime( true ) - $start_time;

			// 保存执行统计信息
			$this->execution_stats = array(
				'total_requests'      => count( $this->requests ),
				'successful_requests' => $this->count_successful_responses(),
				'failed_requests'     => $this->count_failed_responses(),
				'execution_time'      => $execution_time,
				'max_concurrent'      => $this->max_concurrent_requests,
				'memory_usage'        => memory_get_usage( true ),
				'peak_memory'         => memory_get_peak_usage( true ),
			);

			\NTWP\Core\Logger::debug_log(
				sprintf(
					'并发请求执行完成，耗时: %.2f秒，成功: %d，失败: %d',
					$execution_time,
					$this->execution_stats['successful_requests'],
					$this->execution_stats['failed_requests']
				),
				'Concurrent Network'
			);

		} catch ( Exception $e ) {
			\NTWP\Core\Logger::error_log(
				'并发请求执行异常: ' . $e->getMessage(),
				'Concurrent Network'
			);

			// 清理资源
			$this->cleanup();
			throw $e;
		}

		// 保存响应结果
		$responses = $this->responses;

		// 清理资源
		$this->cleanup();

		return $responses;
	}

	/**
	 * 获取响应结果
	 *
	 * @since    1.1.2
	 * @return   array    响应结果数组
	 */
	public function get_responses() {
		return $this->responses;
	}

	/**
	 * 获取指定请求的响应
	 *
	 * @since    1.1.2
	 * @param    int $request_id    请求ID
	 * @return   mixed               响应结果或null
	 */
	public function get_response( $request_id ) {
		return isset( $this->responses[ $request_id ] ) ? $this->responses[ $request_id ] : null;
	}

	/**
	 * 初始化multi handle
	 *
	 * @since    1.1.2
	 * @access   private
	 */
	private function init_multi_handle() {

		$this->multi_handle = curl_multi_init();

		// 设置multi handle选项
		curl_multi_setopt( $this->multi_handle, CURLMOPT_MAXCONNECTS, $this->max_concurrent_requests );
	}

	/**
	 * 创建cURL句柄（使用连接池优化）
	 *
	 * @since    1.1.2
	 * @access   private
	 */
	private function create_curl_handles() {
		// 🚀 初始化连接池
		$this->init_connection_pool();

		foreach ( $this->requests as $request_id => $request ) {
			// 🚀 从连接池获取优化的连接
			$curl_handle = $this->get_connection_from_pool();

			$this->configure_curl_handle( $curl_handle, $request );
			$this->curl_handles[ $request_id ] = $curl_handle;

			// 添加到multi handle
			curl_multi_add_handle( $this->multi_handle, $curl_handle );
		}
	}

	/**
	 * 配置cURL句柄（增强版，支持Keep-Alive和HTTP/2）
	 *
	 * @since    1.1.2
	 * @access   private
	 * @param \CurlHandle $curl_handle cURL句柄
	 * @param    array       $request        请求配置
	 */
	private function configure_curl_handle( \CurlHandle $curl_handle, array $request ): void {

		$url  = $request['url'];
		$args = $request['args'];

		// 🚀 增强配置：基本设置 + Keep-Alive + HTTP/2
		curl_setopt_array(
			$curl_handle,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 3,
				CURLOPT_TIMEOUT        => $args['timeout'],
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_USERAGENT      => $args['user-agent'],
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,

				// 🚀 HTTP/2和Keep-Alive优化（如果句柄是新创建的，这些可能已设置）
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
				CURLOPT_TCP_KEEPALIVE  => 1,
				CURLOPT_FORBID_REUSE   => false,
				CURLOPT_FRESH_CONNECT  => false,

				// 🚀 性能优化设置
				CURLOPT_TCP_NODELAY    => true,
				CURLOPT_ENCODING       => '',
			)
		);

		// 设置HTTP方法
		switch ( strtoupper( $args['method'] ) ) {
			case 'POST':
				curl_setopt( $curl_handle, CURLOPT_POST, true );
				if ( ! empty( $args['body'] ) ) {
					curl_setopt( $curl_handle, CURLOPT_POSTFIELDS, $args['body'] );
				}
				break;

			case 'PUT':
				curl_setopt( $curl_handle, CURLOPT_CUSTOMREQUEST, 'PUT' );
				if ( ! empty( $args['body'] ) ) {
					curl_setopt( $curl_handle, CURLOPT_POSTFIELDS, $args['body'] );
				}
				break;

			case 'DELETE':
				curl_setopt( $curl_handle, CURLOPT_CUSTOMREQUEST, 'DELETE' );
				break;

			default: // GET
				curl_setopt( $curl_handle, CURLOPT_HTTPGET, true );
				break;
		}

		// 设置请求头
		if ( ! empty( $args['headers'] ) ) {
			$headers = array();
			foreach ( $args['headers'] as $key => $value ) {
				$headers[] = $key . ': ' . $value;
			}
			curl_setopt( $curl_handle, CURLOPT_HTTPHEADER, $headers );
		}
	}

	/**
	 * 执行并发请求（优化版）
	 *
	 * @since    1.1.2
	 * @access   private
	 */
	private function execute_requests() {
		$running            = null;
		$start_time         = microtime( true );
		$max_execution_time = 90; // 最大执行时间90秒

		// 开始执行
		do {
			$status = curl_multi_exec( $this->multi_handle, $running );

			if ( $status != CURLM_OK ) {
				throw new Exception( 'cURL multi exec错误: ' . esc_html( curl_multi_strerror( $status ) ) );
			}

			// 检查执行时间，避免超时
			$elapsed_time = microtime( true ) - $start_time;
			if ( $elapsed_time > $max_execution_time ) {
				if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
					\NTWP\Core\Logger::error_log(
						sprintf( '并发请求执行超时 (%.2f秒)，强制终止', $elapsed_time ),
						'Concurrent Network'
					);
				}
				break;
			}

			// 等待活动，减少CPU占用
			if ( $running > 0 ) {
				curl_multi_select( $this->multi_handle, 0.2 ); // 增加等待时间
			}
		} while ( $running > 0 );
	}

	/**
	 * 处理响应结果（增强版，包含连接池统计）
	 *
	 * @since    1.1.2
	 * @access   private
	 */
	private function process_responses() {
		$total_response_time = 0;
		$successful_requests = 0;

		foreach ( $this->curl_handles as $request_id => $curl_handle ) {
			$response_data = curl_multi_getcontent( $curl_handle );
			$http_code     = curl_getinfo( $curl_handle, CURLINFO_HTTP_CODE );
			$error_code    = curl_errno( $curl_handle );
			$error_message = curl_error( $curl_handle );

			// 🚀 收集性能统计信息
			$info                 = curl_getinfo( $curl_handle );
			$response_time        = is_array( $info ) ? $info['total_time'] : 0;
			$total_response_time += $response_time;

			// 检查是否使用了HTTP/2
			if ( is_array( $info ) && $info['http_version'] >= 3 ) {
				// HTTP/2或更高版本
				if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
					\NTWP\Core\Logger::debug_log( "请求 {$request_id} 使用HTTP/2", 'Connection Pool' );
				}
			}

			if ( $error_code !== 0 ) {
				// cURL错误
				++$this->pool_stats['connection_errors'];

				$this->responses[ $request_id ] = new \WP_Error(
					'curl_error',
					sprintf( 'cURL错误 %d: %s', $error_code, $error_message )
				);

				\NTWP\Core\Logger::error_log(
					"请求失败 (ID: {$request_id}): cURL错误 {$error_code} - {$error_message}",
					'Concurrent Network'
				);

			} elseif ( $http_code < 200 || $http_code >= 300 ) {
				// HTTP错误
				++$this->pool_stats['connection_errors'];

				$this->responses[ $request_id ] = new \WP_Error(
					'http_error',
					sprintf(
						'HTTP错误 %d%s',
						$http_code,
						0 === $http_code ? '（请求未获得有效响应）' : ''
					)
				);

				\NTWP\Core\Logger::error_log(
					"请求失败 (ID: {$request_id}): HTTP错误 {$http_code}",
					'Concurrent Network'
				);

			} else {
				// 成功响应
				++$successful_requests;

				$this->responses[ $request_id ] = array(
					'body'     => $response_data,
					'response' => array(
						'code'    => $http_code,
						'message' => $this->get_http_status_message( $http_code ),
					),
					'headers'  => $this->parse_response_headers( $curl_handle ),
					'stats'    => array(
						'response_time' => $response_time,
						'http_version'  => is_array( $info ) ? $info['http_version'] : 0,
						'connect_time'  => is_array( $info ) ? $info['connect_time'] : 0,
					),
				);

				// 减少日志记录，仅在非性能模式下记录
				if ( class_exists( '\\NTWP\\Core\\Logger' ) && ! $this->is_performance_mode() ) {
					\NTWP\Core\Logger::debug_log(
						"请求成功 (ID: {$request_id}): HTTP {$http_code}, 响应时间: {$response_time}s",
						'Concurrent Network'
					);
				}
			}

			// 🚀 将连接返回到连接池
			$this->return_connection_to_pool( $curl_handle );
		}

		// 🚀 更新平均响应时间统计
		if ( $successful_requests > 0 ) {
			$avg_response_time                         = $total_response_time / $successful_requests;
			$this->pool_stats['average_response_time'] = round( $avg_response_time, 4 );
		}

		// 记录连接池统计信息
		if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
			$stats = $this->get_connection_pool_stats();
			\NTWP\Core\Logger::debug_log(
				sprintf(
					'批次完成 - 复用率: %s%%, 平均响应时间: %ss',
					$stats['reuse_rate'],
					$stats['average_response_time']
				),
				'Connection Pool'
			);
		}
	}

	/**
	 * 解析响应头
	 *
	 * @since    1.1.2
	 * @access   private
	 * @param \CurlHandle $curl_handle cURL句柄
	 * @return   array                       响应头数组
	 */
	private function parse_response_headers( \CurlHandle $curl_handle ): array {

		$headers = array();

		// 获取响应头信息
		$content_type = curl_getinfo( $curl_handle, CURLINFO_CONTENT_TYPE );
		if ( $content_type ) {
			$headers['content-type'] = $content_type;
		}

		$content_length = curl_getinfo( $curl_handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD );
		if ( $content_length > 0 ) {
			$headers['content-length'] = $content_length;
		}

		return $headers;
	}

	/**
	 * 获取HTTP状态消息
	 *
	 * @since    1.1.2
	 * @access   private
	 * @param    int $code    HTTP状态码
	 * @return   string          状态消息
	 */
	private function get_http_status_message( $code ) {
		$messages = array(
			200 => 'OK',
			201 => 'Created',
			204 => 'No Content',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			429 => 'Too Many Requests',
			500 => 'Internal Server Error',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
		);

		return isset( $messages[ $code ] ) ? $messages[ $code ] : 'Unknown';
	}

	/**
	 * 统计成功响应数量
	 *
	 * @since    1.1.2
	 * @access   private
	 * @return   int    成功响应数量
	 */
	private function count_successful_responses() {
		$count = 0;
		foreach ( $this->responses as $response ) {
			if ( ! is_wp_error( $response ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * 统计失败响应数量
	 *
	 * @since    1.1.2
	 * @access   private
	 * @return   int    失败响应数量
	 */
	private function count_failed_responses() {
		$count = 0;
		foreach ( $this->responses as $response ) {
			if ( is_wp_error( $response ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * 清理资源
	 *
	 * @since    1.1.2
	 * @access   private
	 */
	private function cleanup() {
		// 清理cURL句柄
		foreach ( $this->curl_handles as $curl_handle ) {
			curl_multi_remove_handle( $this->multi_handle, $curl_handle );
			curl_close( $curl_handle );
		}

		// 清理multi handle
		if ( $this->multi_handle !== null ) {
			curl_multi_close( $this->multi_handle );
			$this->multi_handle = null;
		}

		// 重置数组，但保留执行统计信息
		$this->curl_handles = array();
		$this->requests     = array();
		$this->responses    = array();

		\NTWP\Core\Logger::debug_log(
			'并发网络管理器资源清理完成',
			'Concurrent Network'
		);
	}

	/**
	 * 析构函数
	 *
	 * @since    1.1.2
	 */
	public function __destruct() {
		$this->cleanup();
	}

	/**
	 * 获取性能统计信息
	 *
	 * @since    1.1.2
	 * @return   array    性能统计数组
	 */
	public function get_stats() {
		// 如果有保存的执行统计信息，使用它
		if ( ! empty( $this->execution_stats ) ) {
			return $this->execution_stats;
		}

		// 否则返回当前状态
		return array(
			'total_requests'      => count( $this->requests ),
			'successful_requests' => $this->count_successful_responses(),
			'failed_requests'     => $this->count_failed_responses(),
			'max_concurrent'      => $this->max_concurrent_requests,
			'memory_usage'        => memory_get_usage( true ),
			'peak_memory'         => memory_get_peak_usage( true ),
		);
	}

	/**
	 * 设置最大并发请求数量
	 *
	 * @since    1.1.2
	 * @param    int $max_concurrent    最大并发数量
	 */
	public function set_max_concurrent_requests( $max_concurrent ) {
		$this->max_concurrent_requests = max( 1, min( 10, $max_concurrent ) );

		\NTWP\Core\Logger::debug_log(
			"设置最大并发请求数: {$this->max_concurrent_requests}",
			'Concurrent Network'
		);
	}

	/**
	 * 设置默认超时时间
	 *
	 * @since    1.1.2
	 * @param    int $timeout    超时时间（秒）
	 */
	public function set_default_timeout( $timeout ) {
		$this->default_timeout = max( 5, min( 120, $timeout ) );

		\NTWP\Core\Logger::debug_log(
			"设置默认超时时间: {$this->default_timeout}秒",
			'Concurrent Network'
		);
	}

	/**
	 * 连接池管理
	 *
	 * @since 2.0.0-beta.1
	 * @access private
	 * @var array<int, \CurlHandle> $connection_pool 连接池
	 */
	private array $connection_pool = array();
	/**
	 * 连接池最大大小
	 *
	 * @since 2.0.0-beta.1
	 * @access private
	 * @var int $max_pool_size 连接池最大大小
	 */
	private $max_pool_size = 10;

	/**
	 * 连接池统计信息
	 *
	 * @since 2.0.0-beta.1
	 * @access private
	 * @var array $pool_stats 连接池统计
	 */
	private $pool_stats = array(
		'total_requests'        => 0,
		'pool_hits'             => 0,
		'pool_misses'           => 0,
		'connections_created'   => 0,
		'connections_reused'    => 0,
		'http2_connections'     => 0,
		'keepalive_connections' => 0,
		'average_response_time' => 0,
		'connection_errors'     => 0,
		'unhealthy_connections' => 0,
	);

	/**
	 * 数据量预估缓存
	 *
	 * @since 2.0.0-beta.1
	 * @access private
	 * @var array $size_estimation_cache 数据量预估缓存
	 */
	private $size_estimation_cache = array();

	/**
	 * 初始化优化连接池（支持Keep-Alive和HTTP/2）
	 *
	 * @since 2.0.0-beta.1
	 * @return void
	 */
	private function init_connection_pool(): void {
		if ( empty( $this->connection_pool ) ) {
			// 优化：使用统一并发管理器获取最优连接池大小
			$optimal_concurrency = class_exists( '\\NTWP\\Utils\\Unified_Concurrency_Manager' )
				? \NTWP\Utils\Unified_Concurrency_Manager::get_optimal_concurrency( 'request' )
				: min( 3, $this->max_concurrent_requests );

			$pool_size = min( $optimal_concurrency, $this->max_concurrent_requests );

			for ( $i = 0; $i < $pool_size; $i++ ) {
				// 🚀 使用优化的cURL句柄
				$this->connection_pool[] = $this->create_optimized_curl_handle();
			}

			// 记录连接池初始化信息
			if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
				\NTWP\Core\Logger::debug_log(
					sprintf( '初始化连接池: %d个连接', $pool_size ),
					'Connection Pool'
				);
			}
		}
	}

	/**
	 * 从连接池获取优化的连接
	 *
	 * @since 2.0.0-beta.1
	 * @return \CurlHandle cURL句柄
	 */
	private function get_connection_from_pool(): \CurlHandle {

		++$this->pool_stats['total_requests'];

		if ( ! empty( $this->connection_pool ) ) {
			$handle = array_pop( $this->connection_pool );

			// 🚀 性能优化：增强连接健康检查
			if ( $this->is_connection_healthy_enhanced( $handle ) ) {
				++$this->pool_stats['pool_hits'];
				++$this->pool_stats['connections_reused'];

				// 🔇 减少日志频率：每10次复用记录一次
				static $reuse_count = 0;
				++$reuse_count;
				if ( class_exists( '\\NTWP\\Core\\Logger' ) && $reuse_count % 10 === 0 ) {
					\NTWP\Core\Logger::debug_log(
						sprintf( '连接池复用统计: 已复用%d次连接', $reuse_count ),
						'Connection Pool'
					);
				}

				return $handle;
			} else {
				// 连接不健康，关闭并创建新连接
				curl_close( $handle );
				++$this->pool_stats['pool_misses'];
				++$this->pool_stats['unhealthy_connections'];
				return $this->create_optimized_curl_handle();
			}
		}

		// 如果连接池为空，创建新的优化连接
		++$this->pool_stats['pool_misses'];
		return $this->create_optimized_curl_handle();
	}

	/**
	 * 创建优化的cURL句柄（支持Keep-Alive和HTTP/2）
	 *
	 * @since 2.0.0-beta.1
	 * @return \CurlHandle cURL句柄
	 */
	private function create_optimized_curl_handle(): \CurlHandle {

		$handle = curl_init();
		if ( $handle === false ) {
			throw new Exception( '无法初始化 cURL 句柄' );
		}
		// 🚀 HTTP Keep-Alive和连接复用优化
		curl_setopt_array(
			$handle,
			array(
				// HTTP/2支持（如果服务器支持）
				CURLOPT_HTTP_VERSION        => CURL_HTTP_VERSION_2_0,

				// Keep-Alive连接复用
				CURLOPT_TCP_KEEPALIVE       => 1,
				CURLOPT_TCP_KEEPIDLE        => 120,      // 120秒空闲后开始发送keep-alive包
				CURLOPT_TCP_KEEPINTVL       => 60,      // keep-alive包间隔60秒

			// 连接复用设置
				CURLOPT_FORBID_REUSE        => false,    // 允许连接复用
				CURLOPT_FRESH_CONNECT       => false,   // 不强制新连接

			// DNS缓存优化
				CURLOPT_DNS_CACHE_TIMEOUT   => 300, // DNS缓存5分钟

			// SSL/TLS优化
				CURLOPT_SSL_SESSIONID_CACHE => true, // 启用SSL会话缓存

			// 压缩支持
				CURLOPT_ENCODING            => '',           // 支持所有编码格式

			// 连接超时优化
				CURLOPT_CONNECTTIMEOUT      => 10,     // 连接超时10秒
				CURLOPT_TCP_NODELAY         => 1,         // 禁用Nagle算法，减少延迟
			)
		);

		// 🚀 更新统计信息
		++$this->pool_stats['connections_created'];
		++$this->pool_stats['http2_connections'];
		++$this->pool_stats['keepalive_connections'];

		// 减少日志记录，仅在非性能模式下记录
		if ( class_exists( '\\NTWP\\Core\\Logger' ) && ! $this->is_performance_mode() ) {
			\NTWP\Core\Logger::debug_log( '创建优化cURL句柄（Keep-Alive + HTTP/2）', 'Connection Pool' );
		}

		return $handle;
	}

	/**
	 * 增强的连接健康检查
	 *
	 * @since 2.0.0-beta.1
	 * @param \CurlHandle $handle cURL句柄
	 * @return bool 连接是否健康
	 */
	private function is_connection_healthy_enhanced( \CurlHandle $handle ): bool {
		// 基础检查
		$info = curl_getinfo( $handle );

		// 检查连接是否仍然有效
		if ( is_array( $info ) && $info['connect_time'] > 30 ) {
			return false; // 连接时间过长，可能已断开
		}

		// 检查是否有错误
		if ( curl_errno( $handle ) !== 0 ) {
			return false;
		}

		// 检查连接年龄（避免长时间复用导致的问题）
		static $connection_ages = array();
		$handle_id              = spl_object_id( $handle );
		if ( ! isset( $connection_ages[ $handle_id ] ) ) {
			$connection_ages[ $handle_id ] = time();
		}

		$age = time() - $connection_ages[ $handle_id ];
		if ( $age > 300 ) { // 5分钟后认为连接过旧
			unset( $connection_ages[ $handle_id ] );
			return false;
		}

		return true;
	}

	/**
	 * 获取连接池复用率
	 *
	 * @since 2.0.0-beta.1
	 * @return float 复用率百分比
	 */
	public function get_connection_reuse_rate(): float {
		if ( $this->pool_stats['total_requests'] === 0 ) {
			return 0.0;
		}

		return round( ( $this->pool_stats['connections_reused'] / $this->pool_stats['total_requests'] ) * 100, 2 );
	}

	/**
	 * 优化连接池性能报告
	 *
	 * @since 2.0.0-beta.1
	 * @return array 性能报告
	 */
	public function get_connection_pool_report(): array {
		return array(
			'pool_size'             => count( $this->connection_pool ),
			'reuse_rate'            => $this->get_connection_reuse_rate(),
			'total_requests'        => $this->pool_stats['total_requests'],
			'pool_hits'             => $this->pool_stats['pool_hits'],
			'pool_misses'           => $this->pool_stats['pool_misses'],
			'connections_reused'    => $this->pool_stats['connections_reused'],
			'unhealthy_connections' => $this->pool_stats['unhealthy_connections'] ?? 0,
			'efficiency_score'      => $this->calculate_efficiency_score(),
		);
	}

	/**
	 * 计算连接池效率分数
	 *
	 * @since 2.0.0-beta.1
	 * @return float 效率分数 (0-100)
	 */
	private function calculate_efficiency_score(): float {
		if ( $this->pool_stats['total_requests'] === 0 ) {
			return 0.0;
		}

		$reuse_rate     = $this->get_connection_reuse_rate();
		$hit_rate       = ( $this->pool_stats['pool_hits'] / $this->pool_stats['total_requests'] ) * 100;
		$unhealthy_rate = ( ( $this->pool_stats['unhealthy_connections'] ?? 0 ) / $this->pool_stats['total_requests'] ) * 100;

		// 综合评分：复用率40% + 命中率40% - 不健康率20%
		$score = ( $reuse_rate * 0.4 ) + ( $hit_rate * 0.4 ) - ( $unhealthy_rate * 0.2 );

		return max( 0, min( 100, round( $score, 2 ) ) );
	}

	/**
	 * 检查连接健康状态
	 *
	 * @since 2.0.0-beta.1
	/**
	 * 将连接返回到连接池
	 *
	 * @since 2.0.0-beta.1
	 * @param \CurlHandle $handle cURL句柄
	 * @return void
	 */
	private function return_connection_to_pool( \CurlHandle $handle ): void {

		if ( count( $this->connection_pool ) < $this->max_pool_size ) {
			// 重置连接状态
			curl_reset( $handle );
			$this->connection_pool[] = $handle;
		} else {
			// 连接池已满，关闭连接
			curl_close( $handle );
		}
	}

	/**
	 * 清理连接池
	 *
	 * @since 2.0.0-beta.1
	 * @return void
	 */
	public function cleanup_connection_pool(): void {
		foreach ( $this->connection_pool as $handle ) {
			curl_close( $handle );
		}
		$this->connection_pool = array();

		if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
			\NTWP\Core\Logger::debug_log( '连接池已清理', 'Connection Pool' );
		}
	}

	/**
	 * 预估数据库大小
	 *
	 * @since 2.0.0-beta.1
	 * @param string $database_id 数据库ID
	 * @param array  $filter 过滤条件
	 * @return int 预估的页面数量
	 */
	public function estimate_database_size( string $database_id, array $filter = array() ): int {
		$cache_key = md5( $database_id . serialize( $filter ) );

		// 检查缓存
		if ( isset( $this->size_estimation_cache[ $cache_key ] ) ) {
			return $this->size_estimation_cache[ $cache_key ];
		}

		// 执行小样本查询来预估大小
		$sample_size = 10;
		$estimation  = $sample_size; // 默认预估值

		// 这里可以实现更复杂的预估逻辑
		// 比如查询数据库的元数据或执行小样本查询
		if ( empty( $filter ) ) {
			$estimation = 500;
			// 无过滤条件时的默认预估
		} else {
			$estimation = 100;
			// 有过滤条件时的预估
		}

		// 缓存预估结果
		$this->size_estimation_cache[ $cache_key ] = $estimation;
		return $estimation;
	}

	/**
	 * 动态计算最优并发数
	 *
	 * @since 2.0.0-beta.1
	 * @param int $estimated_size 预估的数据量
	 * @param int $page_size 每页大小
	 * @return int 最优并发数
	 */
	public function calculate_optimal_concurrency( int $estimated_size, int $page_size = 100 ): int {
		// 计算预估的页面数
		$estimated_pages = ceil( $estimated_size / $page_size );

		// 根据数据量动态调整并发数
		if ( $estimated_pages <= 2 ) {
			$optimal_concurrency = 1; // 小数据集使用单线程
		} elseif ( $estimated_pages <= 10 ) {
			$optimal_concurrency = min( 3, $estimated_pages ); // 中等数据集
		} else {
			$optimal_concurrency = min( $this->max_concurrent_requests, ceil( $estimated_pages / 5 ) ); // 大数据集
		}

		// 考虑系统负载调整
		if ( class_exists( '\\NTWP\\Core\\Memory_Manager' ) ) {
			$system_stats = \NTWP\Core\Memory_Manager::get_adaptive_stats();
			if ( $system_stats['memory_usage_percent'] > 80 ) {
				$optimal_concurrency = max( 1, floor( $optimal_concurrency * 0.7 ) ); // 内存紧张时减少并发
			}
		}

		if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
			\NTWP\Core\Logger::debug_log(
				sprintf(
					'动态并发计算: 预估大小=%d, 页面数=%d, 最优并发=%d',
					$estimated_size,
					$estimated_pages,
					$optimal_concurrency
				),
				'Concurrency Calculation'
			);
		}

		return $optimal_concurrency;
	}

	/**
	 * 获取连接池统计信息
	 *
	 * @since 2.0.0-beta.1
	 * @return array 连接池统计数据
	 */
	public function get_connection_pool_stats(): array {
		$stats = $this->pool_stats;

		// 计算连接复用率
		if ( $stats['total_requests'] > 0 ) {
			$stats['reuse_rate'] = round( ( $stats['pool_hits'] / $stats['total_requests'] ) * 100, 2 );
		} else {
			$stats['reuse_rate'] = 0;
		}

		// 添加当前连接池状态
		$stats['current_pool_size'] = count( $this->connection_pool );
		$stats['max_pool_size']     = $this->max_pool_size;
		$stats['pool_utilization']  = round( ( ( $this->max_pool_size - count( $this->connection_pool ) ) / $this->max_pool_size ) * 100, 2 );

		return $stats;
	}

	/**
	 * 重置连接池统计信息
	 *
	 * @since 2.0.0-beta.1
	 * @return bool 重置是否成功
	 */
	public function reset_connection_pool_stats(): bool {
		$this->pool_stats = array(
			'total_requests'        => 0,
			'pool_hits'             => 0,
			'pool_misses'           => 0,
			'connections_created'   => 0,
			'connections_reused'    => 0,
			'http2_connections'     => 0,
			'keepalive_connections' => 0,
			'average_response_time' => 0,
			'connection_errors'     => 0,
			'unhealthy_connections' => 0,
		);

		if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
			\NTWP\Core\Logger::debug_log( '连接池统计信息已重置', 'Connection Pool' );
		}

		return true;
	}

	/**
	 * 获取连接池健康状态
	 *
	 * @since 2.0.0-beta.1
	 * @return array 健康状态信息
	 */
	public function get_connection_pool_health(): array {
		$stats = $this->get_connection_pool_stats();

		$health = array(
			'status'          => 'healthy',
			'issues'          => array(),
			'recommendations' => array(),
		);

		// 检查连接复用率
		if ( $stats['reuse_rate'] < 50 && $stats['total_requests'] > 10 ) {
			$health['status']            = 'warning';
			$health['issues'][]          = '连接复用率过低 (' . $stats['reuse_rate'] . '%)';
			$health['recommendations'][] = '考虑增加连接池大小或检查Keep-Alive配置';
		}

		// 检查错误率
		if ( $stats['connection_errors'] > 0 ) {
			$error_rate = round( ( $stats['connection_errors'] / $stats['total_requests'] ) * 100, 2 );
			if ( $error_rate > 5 ) {
				$health['status']            = 'critical';
				$health['issues'][]          = '连接错误率过高 (' . $error_rate . '%)';
				$health['recommendations'][] = '检查网络连接和服务器配置';
			}
		}

		// 检查池利用率
		if ( $stats['pool_utilization'] > 90 ) {
			$health['status']            = 'warning';
			$health['issues'][]          = '连接池利用率过高 (' . $stats['pool_utilization'] . '%)';
			$health['recommendations'][] = '考虑增加连接池大小';
		}

		return $health;
	}

	/**
	 * 强制刷新连接池（关闭所有连接并重新创建）
	 *
	 * @since 2.0.0-beta.1
	 * @return bool 刷新是否成功
	 */
	public function refresh_connection_pool(): bool {
		try {
			// 清理现有连接池
			$this->cleanup_connection_pool();

			// 重新初始化
			$this->init_connection_pool();

			if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
				\NTWP\Core\Logger::debug_log( '连接池已强制刷新', 'Connection Pool' );
			}

			return true;
		} catch ( Exception $e ) {
			if ( class_exists( '\\NTWP\\Core\\Logger' ) ) {
				\NTWP\Core\Logger::error_log( '连接池刷新失败: ' . $e->getMessage(), 'Connection Pool' );
			}

			return false;
		}
	}
}
