<?php
declare(strict_types=1);

namespace NTWP\Utils;

/**
 * API结果对象类
 *
 * 标准化API调用结果，提供明确的成功/失败状态和详细信息
 *
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API结果对象类
 *
 * 提供统一的API调用结果格式，包含成功状态、数据、错误信息和降级信息
 *
 * @since 2.0.0-beta.1
 */
class ApiResult {
    
    /**
     * 成功状态
     * @var bool
     */
    public bool $success;
    
    /**
     * 结果数据
     * @var mixed
     */
    public $data;
    
    /**
     * 错误类型
     * @var string|null
     */
    public ?string $error_type;
    
    /**
     * 错误消息
     * @var string|null
     */
    public ?string $error_message;
    
    /**
     * 是否使用了降级策略
     * @var bool
     */
    public bool $fallback_used;
    
    /**
     * 降级策略名称
     * @var string|null
     */
    public ?string $fallback_strategy;
    
    /**
     * 重试次数
     * @var int
     */
    public int $retry_count;
    
    /**
     * 执行时间（毫秒）
     * @var int
     */
    public int $execution_time_ms;
    
    /**
     * 额外的上下文信息
     * @var array
     */
    public array $context;

    /**
     * 构造函数
     *
     * @since 2.0.0-beta.1
     * @param bool $success 成功状态
     * @param mixed $data 结果数据
     * @param string|null $error_type 错误类型
     * @param string|null $error_message 错误消息
     * @param bool $fallback_used 是否使用降级策略
     * @param string|null $fallback_strategy 降级策略名称
     * @param int $retry_count 重试次数
     * @param int $execution_time_ms 执行时间
     * @param array $context 上下文信息
     */
    public function __construct(
        bool $success,
        $data = null,
        ?string $error_type = null,
        ?string $error_message = null,
        bool $fallback_used = false,
        ?string $fallback_strategy = null,
        int $retry_count = 0,
        int $execution_time_ms = 0,
        array $context = []
    ) {
        $this->success = $success;
        $this->data = $data;
        $this->error_type = $error_type;
        $this->error_message = $error_message;
        $this->fallback_used = $fallback_used;
        $this->fallback_strategy = $fallback_strategy;
        $this->retry_count = $retry_count;
        $this->execution_time_ms = $execution_time_ms;
        $this->context = $context;
    }

    /**
     * 创建成功结果
     *
     * @since 2.0.0-beta.1
     * @param mixed $data 结果数据
     * @param bool $fallback_used 是否使用了降级策略
     * @param string|null $fallback_strategy 降级策略名称
     * @param int $retry_count 重试次数
     * @param int $execution_time_ms 执行时间
     * @param array $context 上下文信息
     * @return self 成功结果对象
     */
    public static function success(
        $data = null, 
        bool $fallback_used = false,
        ?string $fallback_strategy = null,
        int $retry_count = 0,
        int $execution_time_ms = 0,
        array $context = []
    ): self {
        return new self(
            true, 
            $data, 
            null, 
            null, 
            $fallback_used, 
            $fallback_strategy,
            $retry_count,
            $execution_time_ms,
            $context
        );
    }

    /**
     * 创建失败结果
     *
     * @since 2.0.0-beta.1
     * @param string $error_type 错误类型
     * @param string $error_message 错误消息
     * @param int $retry_count 重试次数
     * @param int $execution_time_ms 执行时间
     * @param array $context 上下文信息
     * @return self 失败结果对象
     */
    public static function failure(
        string $error_type,
        string $error_message,
        int $retry_count = 0,
        int $execution_time_ms = 0,
        array $context = []
    ): self {
        return new self(
            false, 
            null, 
            $error_type, 
            $error_message, 
            false, 
            null,
            $retry_count,
            $execution_time_ms,
            $context
        );
    }

    /**
     * 创建降级成功结果
     *
     * @since 2.0.0-beta.1
     * @param mixed $data 结果数据
     * @param string $fallback_strategy 降级策略名称
     * @param int $retry_count 重试次数
     * @param int $execution_time_ms 执行时间
     * @param array $context 上下文信息
     * @return self 降级成功结果对象
     */
    public static function fallback_success(
        $data,
        string $fallback_strategy,
        int $retry_count = 0,
        int $execution_time_ms = 0,
        array $context = []
    ): self {
        return new self(
            true, 
            $data, 
            null, 
            null, 
            true, 
            $fallback_strategy,
            $retry_count,
            $execution_time_ms,
            $context
        );
    }

    /**
     * 转换为数组格式
     *
     * @since 2.0.0-beta.1
     * @return array 数组格式的结果
     */
    public function to_array(): array {
        return [
            'success' => $this->success,
            'data' => $this->data,
            'error_type' => $this->error_type,
            'error_message' => $this->error_message,
            'fallback_used' => $this->fallback_used,
            'fallback_strategy' => $this->fallback_strategy,
            'retry_count' => $this->retry_count,
            'execution_time_ms' => $this->execution_time_ms,
            'context' => $this->context
        ];
    }

    /**
     * 转换为JSON格式
     *
     * @since 2.0.0-beta.1
     * @return string JSON格式的结果
     */
    public function to_json(): string {
        return json_encode($this->to_array(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 检查是否为成功结果
     *
     * @since 2.0.0-beta.1
     * @return bool 是否成功
     */
    public function is_success(): bool {
        return $this->success;
    }

    /**
     * 检查是否为失败结果
     *
     * @since 2.0.0-beta.1
     * @return bool 是否失败
     */
    public function is_failure(): bool {
        return !$this->success;
    }

    /**
     * 检查是否使用了降级策略
     *
     * @since 2.0.0-beta.1
     * @return bool 是否使用降级策略
     */
    public function is_fallback(): bool {
        return $this->fallback_used;
    }

    /**
     * 获取数据，如果失败则返回默认值
     *
     * @since 2.0.0-beta.1
     * @param mixed $default 默认值
     * @return mixed 数据或默认值
     */
    public function get_data($default = null) {
        return $this->success ? $this->data : $default;
    }

    /**
     * 获取结果摘要信息
     *
     * @since 2.0.0-beta.1
     * @return string 结果摘要
     */
    public function get_summary(): string {
        if ($this->success) {
            $summary = '成功';
            if ($this->fallback_used) {
                $summary .= " (降级策略: {$this->fallback_strategy})";
            }
            if ($this->retry_count > 0) {
                $summary .= " (重试: {$this->retry_count}次)";
            }
            if ($this->execution_time_ms > 0) {
                $summary .= " (耗时: {$this->execution_time_ms}ms)";
            }
        } else {
            $summary = "失败: {$this->error_type} - {$this->error_message}";
            if ($this->retry_count > 0) {
                $summary .= " (重试: {$this->retry_count}次)";
            }
        }

        return $summary;
    }

    /**
     * 记录结果到日志
     *
     * @since 2.0.0-beta.1
     * @param string $operation_name 操作名称
     * @param string $log_category 日志分类
     */
    public function log_result(string $operation_name, string $log_category = 'API Result'): void {
        if (class_exists('\\NTWP\\Core\\Logger')) {
            $log_message = "{$operation_name}: {$this->get_summary()}";
            
            if ($this->success) {
                if ($this->fallback_used) {
                    \NTWP\Core\Logger::warning_log($log_message, $log_category);
                } else {
                    \NTWP\Core\Logger::info_log($log_message, $log_category);
                }
            } else {
                \NTWP\Core\Logger::error_log($log_message, $log_category);
            }
        }
    }
}