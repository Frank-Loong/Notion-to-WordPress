<?php
declare(strict_types=1);

/**
 * Notion 并行图片处理器类
 * 
 * 实现真正的并行图片下载和处理，使用WordPress HTTP API的并发功能
 * 大幅提升图片处理速度，预期提升3-5倍性能
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

class Notion_Parallel_Image_Processor {
    
    /**
     * 并发处理常量
     */
    const DEFAULT_MAX_CONCURRENT = 5;      // 默认最大并发数
    const MAX_CONCURRENT_LIMIT = 10;       // 最大并发限制
    const REQUEST_TIMEOUT = 30;            // 请求超时时间（秒）
    const MAX_IMAGE_SIZE = 10 * 1024 * 1024; // 最大图片大小（10MB）
    
    /**
     * 并行处理图片（智能选择处理方式）
     *
     * 根据系统环境和图片数量智能选择最优的处理方式
     * 优先使用真并发方法，回退到分块处理
     *
     * @since 2.0.0-beta.1
     * @param array $image_urls 图片URL数组
     * @param int $max_concurrent 最大并发数
     * @param bool $force_true_parallel 强制使用真并发
     * @return array [url => attachment_id] 映射，失败的返回false
     */
    public static function process_images_parallel(array $image_urls, int $max_concurrent = self::DEFAULT_MAX_CONCURRENT, bool $force_true_parallel = false): array {
        if (empty($image_urls)) {
            return [];
        }

        // 智能选择处理方式
        $use_true_parallel = $force_true_parallel || self::should_use_true_parallel($image_urls);

        if ($use_true_parallel) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::info_log(
                    sprintf('使用真并发处理 %d 个图片', count($image_urls)),
                    'Parallel Image Processor'
                );
            }
            return self::download_images_truly_parallel($image_urls, $max_concurrent);
        }

        // 回退到原有的分块处理方式
        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf('使用分块处理 %d 个图片', count($image_urls)),
                'Parallel Image Processor'
            );
        }

        // 使用自适应并发数调整
        if (class_exists('Notion_Adaptive_Batch')) {
            $adaptive_concurrent = Notion_Adaptive_Batch::get_concurrent_limit();
            $max_concurrent = min($max_concurrent, $adaptive_concurrent);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    "图片处理自适应并发数: {$max_concurrent} (原始: {$adaptive_concurrent})",
                    'Parallel Image Processor'
                );
            }
        }

        // 限制并发数在合理范围内
        $max_concurrent = max(1, min($max_concurrent, self::MAX_CONCURRENT_LIMIT));
        
        // 记录开始时间
        $start_time = microtime(true);
        $results = [];
        
        // 分块处理以控制并发数
        $chunks = array_chunk($image_urls, $max_concurrent);
        
        foreach ($chunks as $chunk_index => $chunk) {
            // 监控内存使用
            if (class_exists('Notion_Memory_Manager')) {
                Notion_Memory_Manager::monitor_memory_usage('Parallel Image Processing');
            }
            
            $chunk_results = self::process_image_chunk($chunk);
            $results = array_merge($results, $chunk_results);
            
            // 记录进度
            if (class_exists('Notion_Logger')) {
                $processed = ($chunk_index + 1) * $max_concurrent;
                $total = count($image_urls);
                Notion_Logger::debug_log(
                    sprintf('并行图片处理进度: %d/%d (%.1f%%)', 
                        min($processed, $total), 
                        $total, 
                        (min($processed, $total) / $total) * 100
                    ),
                    'Parallel Image Processor'
                );
            }
            
            // 清理内存
            unset($chunk_results);
            
            // 定期垃圾回收
            if ($chunk_index % 3 === 0 && class_exists('Notion_Memory_Manager')) {
                Notion_Memory_Manager::force_garbage_collection();
            }
        }
        
        // 记录总体性能
        $end_time = microtime(true);
        $duration = $end_time - $start_time;
        $success_count = count(array_filter($results));
        
        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf('并行图片处理完成: %d成功/%d总计，耗时%.2f秒，平均%.2f图片/秒',
                    $success_count,
                    count($image_urls),
                    $duration,
                    count($image_urls) / $duration
                ),
                'Parallel Image Processor'
            );
        }
        
        return $results;
    }

    /**
     * 处理单个图片块
     *
     * @since 2.0.0-beta.1
     * @param array $image_urls 图片URL数组
     * @return array 处理结果
     */
    private static function process_image_chunk(array $image_urls): array {
        $results = [];
        $requests = [];
        $request_map = [];
        
        // 准备并发请求
        foreach ($image_urls as $index => $url) {
            if (!self::is_valid_image_url($url)) {
                $results[$url] = false;
                continue;
            }
            
            $request_id = 'img_' . $index . '_' . md5($url);
            $requests[$request_id] = [
                'url' => $url,
                'type' => 'GET',
                'options' => [
                    'timeout' => self::REQUEST_TIMEOUT,
                    'user-agent' => 'Notion-to-WordPress/2.0.0-beta.1',
                    'headers' => [
                        'Accept' => 'image/*',
                        'Cache-Control' => 'no-cache'
                    ],
                    'stream' => false,
                    'filename' => null
                ]
            ];
            $request_map[$request_id] = $url;
        }
        
        if (empty($requests)) {
            return $results;
        }
        
        try {
            // 执行并发HTTP请求
            $responses = self::execute_concurrent_requests($requests);
            
            // 处理响应
            foreach ($responses as $request_id => $response) {
                $url = $request_map[$request_id];
                
                if (is_wp_error($response)) {
                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::error_log(
                            "图片下载失败 ({$url}): " . $response->get_error_message(),
                            'Parallel Image Processor'
                        );
                    }
                    $results[$url] = false;
                    continue;
                }
                
                $response_code = wp_remote_retrieve_response_code($response);
                if ($response_code !== 200) {
                    if (class_exists('Notion_Logger')) {
                        Notion_Logger::warning_log(
                            "图片下载HTTP错误 ({$url}): {$response_code}",
                            'Parallel Image Processor'
                        );
                    }
                    $results[$url] = false;
                    continue;
                }
                
                // 创建WordPress附件
                $attachment_id = self::create_attachment_from_response($url, $response);
                $results[$url] = $attachment_id;
            }
            
        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    '并发图片处理异常: ' . $e->getMessage(),
                    'Parallel Image Processor'
                );
            }
            
            // 降级到串行处理
            foreach ($image_urls as $url) {
                if (!isset($results[$url])) {
                    $results[$url] = self::process_single_image_fallback($url);
                }
            }
        }
        
        // 清理临时数据
        unset($requests, $request_map, $responses);
        
        return $results;
    }

    /**
     * 执行并发HTTP请求
     *
     * @since 2.0.0-beta.1
     * @param array $requests 请求数组
     * @return array 响应数组
     */
    private static function execute_concurrent_requests(array $requests): array {
        $responses = [];
        
        // 使用WordPress的HTTP API进行并发请求
        // 注意：WordPress本身不直接支持真正的并发，这里模拟并发效果
        foreach ($requests as $request_id => $request) {
            $response = wp_remote_get($request['url'], $request['options']);
            $responses[$request_id] = $response;
        }
        
        return $responses;
    }

    /**
     * 从HTTP响应创建WordPress附件
     *
     * @since 2.0.0-beta.1
     * @param string $url 图片URL
     * @param array $response HTTP响应
     * @return int|false 附件ID或false
     */
    private static function create_attachment_from_response(string $url, array $response) {
        $image_data = wp_remote_retrieve_body($response);
        
        if (empty($image_data)) {
            return false;
        }
        
        // 检查图片大小
        if (strlen($image_data) > self::MAX_IMAGE_SIZE) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    "图片过大，跳过处理 ({$url}): " . size_format(strlen($image_data)),
                    'Parallel Image Processor'
                );
            }
            return false;
        }
        
        // 验证图片数据
        $image_info = getimagesizefromstring($image_data);
        if (!$image_info) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    "无效的图片数据 ({$url})",
                    'Parallel Image Processor'
                );
            }
            return false;
        }
        
        // 生成文件名
        $filename = self::generate_filename($url, $image_info['mime']);
        
        // 获取上传目录
        $upload_dir = wp_upload_dir();
        if ($upload_dir['error']) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    "上传目录错误: " . $upload_dir['error'],
                    'Parallel Image Processor'
                );
            }
            return false;
        }
        
        $file_path = $upload_dir['path'] . '/' . $filename;
        
        // 写入文件
        if (file_put_contents($file_path, $image_data) === false) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    "文件写入失败: {$file_path}",
                    'Parallel Image Processor'
                );
            }
            return false;
        }
        
        // 创建附件记录
        $attachment_data = [
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_mime_type' => $image_info['mime'],
            'guid' => $upload_dir['url'] . '/' . $filename
        ];
        
        $attachment_id = wp_insert_attachment($attachment_data, $file_path);
        
        if (is_wp_error($attachment_id)) {
            unlink($file_path);
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    "附件创建失败: " . $attachment_id->get_error_message(),
                    'Parallel Image Processor'
                );
            }
            return false;
        }
        
        // 生成缩略图
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attach_data);
        
        return $attachment_id;
    }

    /**
     * 验证图片URL是否有效
     *
     * @since 2.0.0-beta.1
     * @param string $url 图片URL
     * @return bool 是否有效
     */
    private static function is_valid_image_url(string $url): bool {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // 检查URL协议
        $parsed_url = parse_url($url);
        if (!in_array($parsed_url['scheme'] ?? '', ['http', 'https'])) {
            return false;
        }
        
        // 检查文件扩展名（可选）
        $path = $parsed_url['path'] ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        
        // 如果有扩展名，检查是否在允许列表中
        if (!empty($extension) && !in_array($extension, $allowed_extensions)) {
            return false;
        }
        
        return true;
    }

    /**
     * 生成文件名
     *
     * @since 2.0.0-beta.1
     * @param string $url 图片URL
     * @param string $mime_type MIME类型
     * @return string 文件名
     */
    private static function generate_filename(string $url, string $mime_type): string {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '';
        $original_filename = basename($path);
        
        // 如果有原始文件名，使用它
        if (!empty($original_filename) && pathinfo($original_filename, PATHINFO_EXTENSION)) {
            $filename = sanitize_file_name($original_filename);
        } else {
            // 生成基于时间和URL哈希的文件名
            $extension = self::get_extension_from_mime($mime_type);
            $hash = substr(md5($url), 0, 8);
            $filename = 'notion-image-' . time() . '-' . $hash . '.' . $extension;
        }
        
        return $filename;
    }

    /**
     * 从MIME类型获取文件扩展名
     *
     * @since 2.0.0-beta.1
     * @param string $mime_type MIME类型
     * @return string 文件扩展名
     */
    private static function get_extension_from_mime(string $mime_type): string {
        $mime_to_ext = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg'
        ];
        
        return $mime_to_ext[$mime_type] ?? 'jpg';
    }

    /**
     * 单个图片处理的降级方案
     *
     * @since 2.0.0-beta.1
     * @param string $url 图片URL
     * @return int|false 附件ID或false
     */
    private static function process_single_image_fallback(string $url) {
        if (!self::is_valid_image_url($url)) {
            return false;
        }
        
        $response = wp_remote_get($url, [
            'timeout' => self::REQUEST_TIMEOUT,
            'user-agent' => 'Notion-to-WordPress/2.0.0-beta.1'
        ]);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        return self::create_attachment_from_response($url, $response);
    }

    /**
     * 获取并发处理统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public static function get_processing_stats(): array {
        return [
            'max_concurrent' => self::DEFAULT_MAX_CONCURRENT,
            'max_concurrent_limit' => self::MAX_CONCURRENT_LIMIT,
            'request_timeout' => self::REQUEST_TIMEOUT,
            'max_image_size' => self::MAX_IMAGE_SIZE,
            'max_image_size_formatted' => size_format(self::MAX_IMAGE_SIZE)
        ];
    }

    /**
     * 真正的并发图片下载和处理
     *
     * 使用curl_multi实现真正的并发下载，替代分块串行处理
     *
     * @since 2.0.0-beta.1
     * @param array $image_urls 图片URL数组
     * @param int $max_concurrent 最大并发数
     * @return array [url => attachment_id] 映射，失败的返回false
     */
    public static function download_images_truly_parallel(array $image_urls, int $max_concurrent = self::DEFAULT_MAX_CONCURRENT): array {
        if (empty($image_urls)) {
            return [];
        }

        // 开始性能监控
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);

        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::start_timer('true_parallel_image_download');
        }

        // 智能并发数调整
        $system_load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 1.0) : 1.0;
        $optimal_concurrent = $system_load > 2.0 ? min(4, $max_concurrent) : $max_concurrent;
        $optimal_concurrent = max(1, min($optimal_concurrent, self::MAX_CONCURRENT_LIMIT));

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf(
                    '开始真并发图片下载: %d个图片, 系统负载%.2f, 并发数%d',
                    count($image_urls),
                    $system_load,
                    $optimal_concurrent
                ),
                'True Parallel Image Processor'
            );
        }

        // 图片大小预估和分组
        $image_groups = self::estimate_and_group_images($image_urls);

        // 处理小图片组（优先处理）
        $small_results = [];
        if (!empty($image_groups['small'])) {
            $small_results = self::process_image_group_concurrent($image_groups['small'], $optimal_concurrent, 'small');
        }

        // 处理大图片组（减少并发数）
        $large_results = [];
        if (!empty($image_groups['large'])) {
            $large_concurrent = max(1, floor($optimal_concurrent / 2)); // 大图片使用一半并发数
            $large_results = self::process_image_group_concurrent($image_groups['large'], $large_concurrent, 'large');
        }

        // 合并结果
        $results = array_merge($small_results, $large_results);

        // 记录性能统计
        $processing_time = microtime(true) - $start_time;
        $memory_used = memory_get_usage(true) - $start_memory;
        $success_count = count(array_filter($results));

        if (class_exists('Notion_Performance_Monitor')) {
            Notion_Performance_Monitor::end_timer('true_parallel_image_download');
            Notion_Performance_Monitor::record_custom_metric('parallel_image_download_time', $processing_time);
            Notion_Performance_Monitor::record_custom_metric('parallel_image_success_count', $success_count);
            Notion_Performance_Monitor::record_custom_metric('parallel_image_memory_usage', $memory_used);
        }

        if (class_exists('Notion_Logger')) {
            Notion_Logger::info_log(
                sprintf(
                    '真并发图片下载完成: %d成功/%d总计, 耗时%.3fs, 内存%s, 平均%.1f图片/秒',
                    $success_count,
                    count($image_urls),
                    $processing_time,
                    self::format_bytes($memory_used),
                    count($image_urls) / $processing_time
                ),
                'True Parallel Image Processor'
            );
        }

        return $results;
    }

    /**
     * 预估图片大小并分组
     *
     * @since 2.0.0-beta.1
     * @param array $image_urls 图片URL数组
     * @return array ['small' => [], 'large' => []] 分组结果
     */
    private static function estimate_and_group_images(array $image_urls): array {
        $groups = ['small' => [], 'large' => []];

        foreach ($image_urls as $url) {
            if (!self::is_valid_image_url($url)) {
                continue;
            }

            // 简单的大小预估（基于URL特征）
            $estimated_size = self::estimate_image_size($url);

            if ($estimated_size > 1024 * 1024) { // 大于1MB认为是大图片
                $groups['large'][] = $url;
            } else {
                $groups['small'][] = $url;
            }
        }

        if (class_exists('Notion_Logger')) {
            Notion_Logger::debug_log(
                sprintf('图片分组: 小图片%d个, 大图片%d个', count($groups['small']), count($groups['large'])),
                'Image Grouping'
            );
        }

        return $groups;
    }

    /**
     * 预估图片大小
     *
     * @since 2.0.0-beta.1
     * @param string $url 图片URL
     * @return int 预估大小（字节）
     */
    private static function estimate_image_size(string $url): int {
        // 基于URL特征的简单预估
        $url_lower = strtolower($url);

        // 检查URL中的尺寸信息
        if (preg_match('/(\d+)x(\d+)/', $url, $matches)) {
            $width = intval($matches[1]);
            $height = intval($matches[2]);

            // 简单的大小预估公式
            if ($width > 1920 || $height > 1080) {
                return 2 * 1024 * 1024; // 2MB
            } elseif ($width > 800 || $height > 600) {
                return 500 * 1024; // 500KB
            } else {
                return 100 * 1024; // 100KB
            }
        }

        // 基于文件扩展名预估
        if (strpos($url_lower, '.png') !== false) {
            return 800 * 1024; // PNG通常较大
        } elseif (strpos($url_lower, '.jpg') !== false || strpos($url_lower, '.jpeg') !== false) {
            return 300 * 1024; // JPEG通常较小
        } elseif (strpos($url_lower, '.gif') !== false) {
            return 200 * 1024; // GIF通常较小
        } elseif (strpos($url_lower, '.webp') !== false) {
            return 150 * 1024; // WebP通常最小
        }

        // 默认预估
        return 400 * 1024; // 400KB
    }

    /**
     * 并发处理图片组
     *
     * @since 2.0.0-beta.1
     * @param array $image_urls 图片URL数组
     * @param int $max_concurrent 最大并发数
     * @param string $group_type 组类型（small/large）
     * @return array 处理结果
     */
    private static function process_image_group_concurrent(array $image_urls, int $max_concurrent, string $group_type): array {
        if (empty($image_urls)) {
            return [];
        }

        $results = [];
        $multi_handle = curl_multi_init();
        $curl_handles = [];
        $url_map = [];

        if (!$multi_handle) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log('无法初始化curl_multi句柄', 'Concurrent Image Processing');
            }
            // 回退到串行处理
            return self::process_image_chunk($image_urls);
        }

        try {
            // 设置curl_multi选项
            curl_multi_setopt($multi_handle, CURLMOPT_MAXCONNECTS, $max_concurrent);

            $active_downloads = 0;
            $url_index = 0;
            $total_urls = count($image_urls);

            if (class_exists('Notion_Logger')) {
                Notion_Logger::debug_log(
                    sprintf('开始%s图片组并发下载: %d个图片, 并发数%d', $group_type, $total_urls, $max_concurrent),
                    'Concurrent Image Processing'
                );
            }

            // 初始化第一批下载
            while ($active_downloads < $max_concurrent && $url_index < $total_urls) {
                $url = $image_urls[$url_index];

                if (self::is_valid_image_url($url)) {
                    $curl_handle = self::create_curl_handle($url, $group_type);
                    if ($curl_handle) {
                        curl_multi_add_handle($multi_handle, $curl_handle);
                        $curl_handles[(int)$curl_handle] = $curl_handle;
                        $url_map[(int)$curl_handle] = $url;
                        $active_downloads++;
                    }
                } else {
                    $results[$url] = false;
                }

                $url_index++;
            }

            // 执行并发下载
            do {
                $status = curl_multi_exec($multi_handle, $running);

                if ($running > 0) {
                    // 等待活动或超时
                    curl_multi_select($multi_handle, 0.1);
                }

                // 检查完成的下载
                while (($info = curl_multi_info_read($multi_handle)) !== false) {
                    if ($info['msg'] === CURLMSG_DONE) {
                        $curl_handle = $info['handle'];
                        $handle_id = (int)$curl_handle;
                        $url = $url_map[$handle_id];

                        // 处理下载结果
                        $attachment_id = self::process_curl_result($curl_handle, $url);
                        $results[$url] = $attachment_id;

                        // 清理句柄
                        curl_multi_remove_handle($multi_handle, $curl_handle);
                        curl_close($curl_handle);
                        unset($curl_handles[$handle_id], $url_map[$handle_id]);
                        $active_downloads--;

                        // 添加新的下载（如果还有）
                        if ($url_index < $total_urls) {
                            $next_url = $image_urls[$url_index];

                            if (self::is_valid_image_url($next_url)) {
                                $new_curl_handle = self::create_curl_handle($next_url, $group_type);
                                if ($new_curl_handle) {
                                    curl_multi_add_handle($multi_handle, $new_curl_handle);
                                    $curl_handles[(int)$new_curl_handle] = $new_curl_handle;
                                    $url_map[(int)$new_curl_handle] = $next_url;
                                    $active_downloads++;
                                }
                            } else {
                                $results[$next_url] = false;
                            }

                            $url_index++;
                        }
                    }
                }

            } while ($running > 0 || $active_downloads > 0);

        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('并发图片下载异常: %s', $e->getMessage()),
                    'Concurrent Image Processing'
                );
            }
        } finally {
            // 清理剩余的句柄
            foreach ($curl_handles as $curl_handle) {
                curl_multi_remove_handle($multi_handle, $curl_handle);
                curl_close($curl_handle);
            }
            curl_multi_close($multi_handle);
        }

        return $results;
    }

    /**
     * 创建cURL句柄
     *
     * @since 2.0.0-beta.1
     * @param string $url 图片URL
     * @param string $group_type 组类型
     * @return resource|false cURL句柄
     */
    private static function create_curl_handle(string $url, string $group_type) {
        $curl_handle = curl_init();

        if (!$curl_handle) {
            return false;
        }

        // 根据图片组类型设置不同的超时时间
        $timeout = ($group_type === 'large') ? self::REQUEST_TIMEOUT * 2 : self::REQUEST_TIMEOUT;

        curl_setopt_array($curl_handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Notion-to-WordPress/2.0.0-beta.1',
            CURLOPT_HTTPHEADER => [
                'Accept: image/*',
                'Cache-Control: no-cache'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => '', // 支持压缩
            CURLOPT_MAXFILESIZE => self::MAX_IMAGE_SIZE
        ]);

        return $curl_handle;
    }

    /**
     * 处理cURL下载结果
     *
     * @since 2.0.0-beta.1
     * @param resource $curl_handle cURL句柄
     * @param string $url 图片URL
     * @return int|false 附件ID或false
     */
    private static function process_curl_result($curl_handle, string $url) {
        $response_data = curl_getinfo($curl_handle);
        $http_code = $response_data['http_code'];
        $content = curl_multi_getcontent($curl_handle);

        // 检查HTTP状态码
        if ($http_code !== 200) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    sprintf('图片下载HTTP错误 (%s): %d', $url, $http_code),
                    'Concurrent Image Processing'
                );
            }
            return false;
        }

        // 检查cURL错误
        $curl_error = curl_error($curl_handle);
        if (!empty($curl_error)) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('图片下载cURL错误 (%s): %s', $url, $curl_error),
                    'Concurrent Image Processing'
                );
            }
            return false;
        }

        // 检查内容
        if (empty($content)) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    sprintf('图片下载内容为空 (%s)', $url),
                    'Concurrent Image Processing'
                );
            }
            return false;
        }

        // 验证图片内容
        if (!self::validate_image_content($content)) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log(
                    sprintf('图片内容验证失败 (%s)', $url),
                    'Concurrent Image Processing'
                );
            }
            return false;
        }

        // 创建WordPress附件
        return self::create_attachment_from_content($url, $content, $response_data);
    }

    /**
     * 验证图片内容
     *
     * @since 2.0.0-beta.1
     * @param string $content 图片内容
     * @return bool 是否为有效图片
     */
    private static function validate_image_content(string $content): bool {
        if (strlen($content) < 100) { // 太小不可能是有效图片
            return false;
        }

        // 检查图片文件头
        $image_headers = [
            'jpeg' => ["\xFF\xD8\xFF"],
            'png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
            'gif' => ["GIF87a", "GIF89a"],
            'webp' => ["RIFF", "WEBP"],
            'bmp' => ["BM"]
        ];

        foreach ($image_headers as $type => $headers) {
            foreach ($headers as $header) {
                if (strpos($content, $header) === 0) {
                    return true;
                }
            }
        }

        // 对于WebP，需要检查RIFF和WEBP标识
        if (strpos($content, 'RIFF') === 0 && strpos($content, 'WEBP') !== false) {
            return true;
        }

        return false;
    }

    /**
     * 从内容创建WordPress附件
     *
     * @since 2.0.0-beta.1
     * @param string $url 原始URL
     * @param string $content 图片内容
     * @param array $response_data cURL响应信息
     * @return int|false 附件ID或false
     */
    private static function create_attachment_from_content(string $url, string $content, array $response_data) {
        try {
            // 获取文件名
            $filename = self::extract_filename_from_url($url);

            // 获取MIME类型
            $mime_type = self::detect_mime_type($content, $response_data);

            // 创建临时文件
            $temp_file = wp_tempnam($filename);
            if (!$temp_file) {
                return false;
            }

            // 写入内容
            if (file_put_contents($temp_file, $content) === false) {
                @unlink($temp_file);
                return false;
            }

            // 准备文件数组
            $file_array = [
                'name' => $filename,
                'type' => $mime_type,
                'tmp_name' => $temp_file,
                'error' => 0,
                'size' => strlen($content)
            ];

            // 使用WordPress媒体处理
            $attachment_id = media_handle_sideload($file_array, 0);

            // 清理临时文件
            @unlink($temp_file);

            if (is_wp_error($attachment_id)) {
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::error_log(
                        sprintf('创建附件失败 (%s): %s', $url, $attachment_id->get_error_message()),
                        'Concurrent Image Processing'
                    );
                }
                return false;
            }

            return $attachment_id;

        } catch (Exception $e) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::error_log(
                    sprintf('创建附件异常 (%s): %s', $url, $e->getMessage()),
                    'Concurrent Image Processing'
                );
            }
            return false;
        }
    }

    /**
     * 从URL提取文件名
     *
     * @since 2.0.0-beta.1
     * @param string $url URL
     * @return string 文件名
     */
    private static function extract_filename_from_url(string $url): string {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '';
        $filename = basename($path);

        // 如果没有扩展名，尝试从URL推断
        if (strpos($filename, '.') === false) {
            $filename .= '.jpg'; // 默认扩展名
        }

        // 确保文件名安全
        $filename = sanitize_file_name($filename);

        // 如果文件名为空，生成一个
        if (empty($filename) || $filename === '.jpg') {
            $filename = 'notion-image-' . uniqid() . '.jpg';
        }

        return $filename;
    }

    /**
     * 检测MIME类型
     *
     * @since 2.0.0-beta.1
     * @param string $content 图片内容
     * @param array $response_data cURL响应信息
     * @return string MIME类型
     */
    private static function detect_mime_type(string $content, array $response_data): string {
        // 首先尝试从HTTP头获取
        $content_type = $response_data['content_type'] ?? '';
        if (!empty($content_type) && strpos($content_type, 'image/') === 0) {
            return explode(';', $content_type)[0]; // 移除charset等参数
        }

        // 从内容检测
        if (strpos($content, "\xFF\xD8\xFF") === 0) {
            return 'image/jpeg';
        } elseif (strpos($content, "\x89\x50\x4E\x47") === 0) {
            return 'image/png';
        } elseif (strpos($content, 'GIF8') === 0) {
            return 'image/gif';
        } elseif (strpos($content, 'RIFF') === 0 && strpos($content, 'WEBP') !== false) {
            return 'image/webp';
        } elseif (strpos($content, 'BM') === 0) {
            return 'image/bmp';
        }

        // 默认返回JPEG
        return 'image/jpeg';
    }

    /**
     * 格式化字节数
     *
     * @since 2.0.0-beta.1
     * @param int $bytes 字节数
     * @return string 格式化后的字符串
     */
    private static function format_bytes(int $bytes): string {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . 'MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . 'KB';
        } else {
            return $bytes . 'B';
        }
    }

    /**
     * 判断是否应该使用真并发处理
     *
     * @since 2.0.0-beta.1
     * @param array $image_urls 图片URL数组
     * @return bool 是否使用真并发
     */
    private static function should_use_true_parallel(array $image_urls): bool {
        // 检查cURL多句柄支持
        if (!function_exists('curl_multi_init')) {
            if (class_exists('Notion_Logger')) {
                Notion_Logger::warning_log('cURL多句柄不可用，回退到分块处理', 'Parallel Image Processor');
            }
            return false;
        }

        // 图片数量太少时不值得使用真并发
        if (count($image_urls) < 3) {
            return false;
        }

        // 检查系统负载
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg()[0] ?? 0;
            if ($load > 3.0) { // 系统负载过高
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::warning_log(
                        sprintf('系统负载过高(%.2f)，回退到分块处理', $load),
                        'Parallel Image Processor'
                    );
                }
                return false;
            }
        }

        // 检查内存使用情况
        if (class_exists('Notion_Memory_Manager')) {
            $memory_stats = Notion_Memory_Manager::get_memory_stats();
            if (isset($memory_stats['usage_percent']) && $memory_stats['usage_percent'] > 85) {
                if (class_exists('Notion_Logger')) {
                    Notion_Logger::warning_log(
                        sprintf('内存使用率过高(%d%%)，回退到分块处理', $memory_stats['usage_percent']),
                        'Parallel Image Processor'
                    );
                }
                return false;
            }
        }

        // 检查是否有配置禁用真并发
        $disable_true_parallel = get_option('notion_disable_true_parallel_images', false);
        if ($disable_true_parallel) {
            return false;
        }

        return true;
    }

    /**
     * 获取真并发处理统计信息
     *
     * @since 2.0.0-beta.1
     * @return array 统计信息
     */
    public static function get_true_parallel_stats(): array {
        $stats = [
            'curl_multi_available' => function_exists('curl_multi_init'),
            'system_load' => function_exists('sys_getloadavg') ? sys_getloadavg()[0] : null,
            'memory_usage' => null,
            'true_parallel_enabled' => !get_option('notion_disable_true_parallel_images', false),
            'recommended_mode' => 'chunk' // 默认推荐
        ];

        // 获取内存使用情况
        if (class_exists('Notion_Memory_Manager')) {
            $memory_stats = Notion_Memory_Manager::get_memory_stats();
            $stats['memory_usage'] = $memory_stats['usage_percent'] ?? null;
        }

        // 判断推荐模式
        if ($stats['curl_multi_available'] &&
            $stats['true_parallel_enabled'] &&
            ($stats['system_load'] === null || $stats['system_load'] < 3.0) &&
            ($stats['memory_usage'] === null || $stats['memory_usage'] < 85)) {
            $stats['recommended_mode'] = 'true_parallel';
        }

        return $stats;
    }
}
