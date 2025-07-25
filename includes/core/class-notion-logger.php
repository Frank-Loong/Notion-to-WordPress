<?php
declare(strict_types=1);

/**
 * Notion 日志记录器类
 * 
 * 专门处理插件的日志记录功能，包括日志级别管理、文件轮转、
 * 敏感内容过滤等。实现单一职责原则，从Helper类中分离出来。
 * 
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

class Notion_Logger {
    /**
     * 调试级别常量
     */
    const DEBUG_LEVEL_NONE = 0;    // 不记录任何日志
    const DEBUG_LEVEL_ERROR = 1;   // 只记录错误
    const DEBUG_LEVEL_WARNING = 2; // 记录警告
    const DEBUG_LEVEL_INFO = 3;    // 记录错误、警告和信息
    const DEBUG_LEVEL_DEBUG = 4;   // 记录所有内容，包括详细调试信息
    
    /**
     * 当前日志记录级别。
     *
     * @access private
     * @var int
     */
    private static int $debug_level = self::DEBUG_LEVEL_ERROR;

    /**
     * 最大日志文件大小（字节）
     *
     * @access private
     * @var int
     */
    private static int $max_log_size = 5242880; // 5MB

    /**
     * 最大日志文件数量
     *
     * @access private
     * @var int
     */
    private static int $max_log_files = 10;

    /**
     * 日志缓冲区
     *
     * @access private
     * @var array
     */
    private static array $log_buffer = [];

    /**
     * 日志缓冲区大小
     *
     * @access private
     * @var int
     */
    private static int $buffer_size = 50;

    /**
     * 根据WordPress设置初始化日志级别。
     *
     * @since 2.0.0-beta.1
     */
    public static function init() {
        // 从选项中获取调试级别
        $options = get_option('notion_to_wordpress_options', []);
        self::$debug_level = isset($options['debug_level']) ? (int)$options['debug_level'] : self::DEBUG_LEVEL_ERROR;

        // 从选项中获取日志缓冲区大小
        self::$buffer_size = isset($options['log_buffer_size']) ? (int)$options['log_buffer_size'] : 50;

        // 如果定义了WP_DEBUG并且为true，则至少启用错误级别日志
        if (defined('WP_DEBUG') && WP_DEBUG === true && self::$debug_level < self::DEBUG_LEVEL_ERROR) {
            self::$debug_level = self::DEBUG_LEVEL_ERROR;
        }

        // 初始化时执行日志清理
        self::cleanup_logs();

        // 注册关闭时的清理函数
        register_shutdown_function([self::class, 'flush_log_buffer']);
    }

    /**
     * 优化版的日志记录方法。
     *
     * @since    2.0.0-beta.1
     * @param    mixed     $data       要记录的数据（字符串、数组或对象）。
     * @param    string    $prefix     日志条目的前缀。
     * @param    int       $level      此日志条目的级别。
     */
    public static function debug_log($data, $prefix = 'Notion Debug', $level = self::DEBUG_LEVEL_DEBUG) {
        // 如果当前调试级别小于指定级别，则不记录
        if (self::$debug_level < $level) {
            return;
        }

        // 检查是否启用性能模式
        $options = get_option('notion_to_wordpress_options', []);
        $performance_mode = $options['enable_performance_mode'] ?? 1;

        if ($performance_mode && $level > self::DEBUG_LEVEL_ERROR) {
            // 性能模式下，只有错误级别的日志才立即写入，其他的加入缓冲区
            self::add_to_buffer($data, $prefix, $level);
            return;
        }

        // 格式化日志前缀
        $log_prefix = date('Y-m-d H:i:s') . ' ' . $prefix . ': ';

        // 准备日志内容 - 限制大小
        $log_content = is_array($data) || is_object($data) ? print_r($data, true) : $data;

        // 限制日志内容大小，避免超大日志
        if (strlen($log_content) > 10000) {
            $log_content = substr($log_content, 0, 10000) . '... [日志内容已截断]';
        }

        // 过滤敏感内容，保护用户隐私
        $filtered_content = self::filter_sensitive_content($log_content, $level);

        // 仅在ERROR级别或WP_DEBUG启用时才写入WordPress error_log
        if ($level === self::DEBUG_LEVEL_ERROR && defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log($log_prefix . $filtered_content);
        }

        // 写入专用日志文件
        self::log_to_file($log_prefix . $filtered_content);
    }

    /**
     * 记录错误级别的日志消息。
     *
     * @since    2.0.0-beta.1
     * @param    mixed     $data       要记录的数据。
     * @param    string    $prefix     日志前缀。
     */
    public static function error_log($data, $prefix = 'Notion Error') {
        self::debug_log($data, $prefix, self::DEBUG_LEVEL_ERROR);
    }
    
    /**
     * 记录信息级别的日志消息。
     *
     * @since    2.0.0-beta.1
     * @param    mixed     $data       要记录的数据。
     * @param    string    $prefix     日志前缀。
     */
    public static function info_log($data, $prefix = 'Notion Info') {
        self::debug_log($data, $prefix, self::DEBUG_LEVEL_INFO);
    }

    /**
     * 记录警告级别的日志消息。
     *
     * @since    2.0.0-beta.1
     * @param    mixed     $data       要记录的数据。
     * @param    string    $prefix     日志前缀。
     */
    public static function warning_log($data, $prefix = 'Notion Warning') {
        self::debug_log($data, $prefix, self::DEBUG_LEVEL_WARNING);
    }

    /**
     * 过滤敏感内容，保护用户隐私
     *
     * @since    2.0.0-beta.1
     * @access   private
     * @param    string    $content    要过滤的内容
     * @param    int       $level      日志级别
     * @return   string                过滤后的内容
     */
    private static function filter_sensitive_content($content, $level) {
        // 错误级别的日志保持完整，便于问题诊断
        if ($level <= self::DEBUG_LEVEL_ERROR) {
            return $content;
        }

        // 检测并过滤HTML内容（可能包含文章内容）
        if (preg_match('/<[^>]+>/', $content) && strlen($content) > 1000) {
            // 如果包含HTML标签且内容很长，可能是文章内容，进行脱敏处理
            $content = preg_replace('/<[^>]+>/', '[HTML标签已过滤]', $content);
            if (strlen($content) > 500) {
                $content = substr($content, 0, 500) . '... [HTML内容已过滤]';
            }
        }

        // 过滤包含大量文本的数组输出（可能是文章内容）
        if (strpos($content, 'Array') === 0 && strlen($content) > 2000) {
            $content = '[数组内容已过滤，长度: ' . strlen($content) . ' 字符]';
        }

        return $content;
    }

    /**
     * 将日志消息写入到专用文件，优化版本。
     *
     * @since    2.0.0-beta.1
     * @access   private
     * @param    string    $message    要写入文件的日志消息。
     */
    private static function log_to_file($message) {
        // 如果完全禁用日志，则直接返回
        if (self::$debug_level === self::DEBUG_LEVEL_NONE) {
            return;
        }

        static $log_dir = null;
        static $log_file = null;

        // 只在第一次调用时初始化目录和文件路径
        if ($log_dir === null) {
            $upload_dir = wp_upload_dir();
            $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';

            // 确保日志目录存在并受保护
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);

                // 创建.htaccess文件以保护日志
                $htaccess_content = "Options -Indexes\nRequire all denied";
                file_put_contents($log_dir . '/.htaccess', $htaccess_content);

                // 创建index.php文件以防止目录列表被直接访问，提升安全性
                file_put_contents($log_dir . '/index.php', '<?php // Silence is golden.');
            }
        }

        // 只在第一次调用或日期变更时更新日志文件路径
        if ($log_file === null || strpos($log_file, date('Y-m-d')) === false) {
            $log_file = $log_dir . '/debug-' . date('Y-m-d') . '.log';

            // 检查日志文件大小，如果超过限制则进行轮转
            if (file_exists($log_file) && filesize($log_file) > self::$max_log_size) {
                self::rotate_log_file($log_file);
            }
        }

        // 写入日志 - 使用锁定以防止并发写入问题
        $fp = fopen($log_file, 'a');
        if ($fp) {
            flock($fp, LOCK_EX);
            fwrite($fp, $message . PHP_EOL);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * 轮转日志文件
     *
     * @since    2.0.0-beta.1
     * @access   private
     * @param    string    $log_file    日志文件路径
     */
    private static function rotate_log_file($log_file) {
        // 如果文件不存在，直接返回
        if (!file_exists($log_file)) {
            return;
        }

        // 获取不带扩展名的文件名
        $path_info = pathinfo($log_file);
        $base_name = $path_info['dirname'] . '/' . $path_info['filename'];

        // 移动现有的轮转日志
        for ($i = self::$max_log_files - 1; $i > 0; $i--) {
            $old_file = $base_name . '.' . $i . '.log';
            $new_file = $base_name . '.' . ($i + 1) . '.log';

            if (file_exists($old_file)) {
                @rename($old_file, $new_file);
            }
        }

        // 移动当前日志文件
        @rename($log_file, $base_name . '.1.log');
    }

    /**
     * 获取所有日志文件列表。
     *
     * @since 2.0.0-beta.1
     * @return array 日志文件名数组。
     */
    public static function get_log_files(): array {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';

        if (!is_dir($log_dir)) {
            return [];
        }

        $files = scandir($log_dir, SCANDIR_SORT_DESCENDING);
        return array_filter($files, function($file) {
            return !in_array($file, ['.', '..', '.htaccess', 'index.php']);
        });
    }

    /**
     * 获取特定日志文件的内容。
     *
     * @since 2.0.0-beta.1
     * @param string $filename 日志文件名。
     * @return string|false 文件内容或在失败时返回 false。
     */
    public static function get_log_content(string $filename): string {
        // 安全性检查：确保文件名不包含路径遍历字符
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return __('无效的文件名。', 'notion-to-wordpress');
        }

        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/notion-to-wordpress-logs/' . $filename;

        if (file_exists($log_file)) {
            // 只读取最后 1MB 的内容以防止过大的文件拖慢后台
            $size = filesize($log_file);
            $offset = max(0, $size - 1024 * 1024);
            return file_get_contents($log_file, false, null, $offset);
        }

        return __('日志文件不存在。', 'notion-to-wordpress');
    }

    /**
     * 清理所有日志文件。
     *
     * @since 2.0.0-beta.1
     * @return bool 是否成功。
     */
    public static function clear_logs(): bool {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';

        if (!is_dir($log_dir)) {
            return true;
        }

        $files = self::get_log_files();
        foreach ($files as $file) {
            $file_path = $log_dir . '/' . $file;
            if (is_writable($file_path)) {
                unlink($file_path);
            }
        }
        return empty(self::get_log_files());
    }

    /**
     * 清理过期和过大的日志文件
     *
     * @since    2.0.0-beta.1
     * @access   private
     */
    private static function cleanup_logs() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';

        if (!is_dir($log_dir)) {
            return;
        }

        $files = glob($log_dir . '/*.log');
        if (empty($files)) {
            return;
        }

        // 按修改时间排序，最新的在前
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $current_time = time();
        $retention_days = 7; // 保留7天的日志
        $retention_seconds = $retention_days * DAY_IN_SECONDS;

        foreach ($files as $index => $file) {
            $file_mod_time = filemtime($file);
            $file_size = filesize($file);

            // 删除过期的日志文件
            if (($current_time - $file_mod_time) > $retention_seconds) {
                @unlink($file);
                continue;
            }

            // 删除超过最大数量的日志文件
            if ($index >= self::$max_log_files) {
                @unlink($file);
                continue;
            }

            // 如果文件过大，进行轮转
            if ($file_size > self::$max_log_size) {
                self::rotate_log_file($file);
            }
        }
    }

    /**
     * 执行日志清理任务。
     *
     * @since 2.0.0-beta.1
     */
    public static function run_log_cleanup() {
        $options = get_option('notion_to_wordpress_options', []);
        $retention_days = isset($options['log_retention_days']) ? (int)$options['log_retention_days'] : 0;

        if ($retention_days <= 0) {
            self::info_log(__('日志清理任务跳过：未设置保留期限。', 'notion-to-wordpress'), 'LogCleanup');
            return;
        }

        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/notion-to-wordpress-logs';

        if (!is_dir($log_dir)) {
            self::info_log(__('日志清理任务跳过：日志目录不存在。', 'notion-to-wordpress'), 'LogCleanup');
            return;
        }

        $files = glob($log_dir . '/*.log');
        if (empty($files)) {
            self::info_log(__('日志清理任务跳过：没有找到日志文件。', 'notion-to-wordpress'), 'LogCleanup');
            return;
        }

        $current_time = time();
        $retention_seconds = $retention_days * DAY_IN_SECONDS;
        $deleted_count = 0;

        foreach ($files as $file) {
            $file_mod_time = filemtime($file);
            if (($current_time - $file_mod_time) > $retention_seconds) {
                if (@unlink($file)) {
                    $deleted_count++;
                }
            }
        }

        self::info_log(sprintf(__('日志清理任务完成：删除了 %d 个过期日志文件。', 'notion-to-wordpress'), $deleted_count), 'LogCleanup');
    }

    /**
     * 获取当前日志级别
     *
     * @since 2.0.0-beta.1
     * @return int 当前日志级别
     */
    public static function get_debug_level(): int {
        return self::$debug_level;
    }

    /**
     * 设置日志级别
     *
     * @since 2.0.0-beta.1
     * @param int $level 新的日志级别
     */
    public static function set_debug_level(int $level) {
        self::$debug_level = $level;
    }

    /**
     * 添加日志到缓冲区
     *
     * @since 2.0.0-beta.1
     * @param mixed $data 要记录的数据
     * @param string $prefix 日志前缀
     * @param int $level 日志级别
     */
    private static function add_to_buffer($data, string $prefix, int $level) {
        self::$log_buffer[] = [
            'data' => $data,
            'prefix' => $prefix,
            'level' => $level,
            'timestamp' => microtime(true)
        ];

        // 当缓冲区满时批量写入
        if (count(self::$log_buffer) >= self::$buffer_size) {
            self::flush_log_buffer();
        }
    }

    /**
     * 刷新日志缓冲区
     *
     * @since 2.0.0-beta.1
     */
    public static function flush_log_buffer() {
        if (empty(self::$log_buffer)) {
            return;
        }

        $batch_content = '';
        foreach (self::$log_buffer as $log_entry) {
            $log_prefix = date('Y-m-d H:i:s', (int)$log_entry['timestamp']) . ' ' . $log_entry['prefix'] . ': ';
            $log_content = is_array($log_entry['data']) || is_object($log_entry['data'])
                ? print_r($log_entry['data'], true)
                : $log_entry['data'];

            // 限制日志内容大小
            if (strlen($log_content) > 10000) {
                $log_content = substr($log_content, 0, 10000) . '... [日志内容已截断]';
            }

            // 过滤敏感内容
            $filtered_content = self::filter_sensitive_content($log_content, $log_entry['level']);

            $batch_content .= $log_prefix . $filtered_content . "\n";
        }

        // 批量写入日志文件
        if (!empty($batch_content)) {
            self::log_to_file($batch_content);
        }

        // 清空缓冲区
        self::$log_buffer = [];
    }

    /**
     * 获取缓冲区状态
     *
     * @since 2.0.0-beta.1
     * @return array 缓冲区状态信息
     */
    public static function get_buffer_status(): array {
        return [
            'buffer_size' => self::$buffer_size,
            'current_count' => count(self::$log_buffer),
            'usage_percentage' => round((count(self::$log_buffer) / self::$buffer_size) * 100, 2)
        ];
    }
}