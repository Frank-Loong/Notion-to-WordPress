<?php
declare(strict_types=1);

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
if (!defined('ABSPATH')) {
    exit;
}

class Notion_Image_Processor {

    /**
     * 待下载图片队列
     * 
     * @since 2.0.0-beta.1
     * @var array
     */
    private static array $pending_images = [];

    /**
     * 图片占位符映射
     * 
     * @since 2.0.0-beta.1
     * @var array
     */
    private static array $image_placeholders = [];

    /**
     * 异步图片模式状态
     * 
     * @since 2.0.0-beta.1
     * @var bool
     */
    private static bool $async_image_mode = false;

    /**
     * 性能统计数据
     * 
     * @since 2.0.0-beta.1
     * @var array
     */
    private static array $performance_stats = [
        'total_images' => 0,
        'concurrent_downloads' => 0,
        'download_time' => 0,
        'processing_time' => 0,
        'success_count' => 0,
        'error_count' => 0
    ];

    // ==================== 核心异步图片处理方法 ====================

    /**
     * 启用异步图片模式
     *
     * @since 2.0.0-beta.1
     */
    public static function enable_async_image_mode(): void {
        self::$async_image_mode = true;
        self::clear_image_queue();
        
        Notion_To_WordPress_Helper::debug_log(
            '异步图片模式已启用',
            'Async Image'
        );
    }

    /**
     * 禁用异步图片模式
     *
     * @since 2.0.0-beta.1
     */
    public static function disable_async_image_mode(): void {
        self::$async_image_mode = false;
        
        Notion_To_WordPress_Helper::debug_log(
            '异步图片模式已禁用',
            'Async Image'
        );
    }

    /**
     * 检查是否启用了异步图片模式
     *
     * @since 2.0.0-beta.1
     * @return bool 是否启用异步模式
     */
    public static function is_async_image_mode_enabled(): bool {
        return self::$async_image_mode;
    }

    /**
     * 收集图片用于异步下载
     *
     * @since 2.0.0-beta.1
     * @param string $url 图片URL
     * @param string $caption 图片说明
     * @return string 占位符字符串或直接HTML
     */
    public static function collect_image_for_download(string $url, string $caption = ''): string {
        if (!self::$async_image_mode) {
            // 非异步模式，直接返回图片HTML
            return self::generate_direct_image_html($url, $caption);
        }

        // 检查是否为Notion临时URL，只有临时URL才需要下载
        if (!self::is_notion_temp_url($url)) {
            // 外部永久链接，直接生成HTML，不下载
            Notion_To_WordPress_Helper::debug_log(
                "外部永久链接，直接引用: {$url}",
                'Image Processing'
            );
            return self::generate_direct_image_html($url, $caption);
        }

        // 生成唯一占位符
        $placeholder = 'pending_image_' . md5($url . time() . rand());

        // 提取基础URL（去除查询参数）
        $base_url = strtok($url, '?');

        // 添加到待下载队列
        self::$pending_images[] = [
            'url' => $url,
            'base_url' => $base_url,
            'caption' => $caption,
            'placeholder' => $placeholder,
            'timestamp' => time()
        ];

        self::$performance_stats['total_images']++;

        Notion_To_WordPress_Helper::debug_log(
            "Notion临时链接图片已添加到下载队列: {$url} -> {$placeholder}",
            'Async Image'
        );

        return $placeholder;
    }

    /**
     * 批量下载所有待处理的图片
     *
     * @since 2.0.0-beta.1
     * @return void
     */
    public static function batch_download_images(): void {
        if (empty(self::$pending_images)) {
            Notion_To_WordPress_Helper::debug_log(
                '没有待下载的图片',
                'Async Image'
            );
            return;
        }

        $start_time = microtime(true);
        $total_images = count(self::$pending_images);

        Notion_To_WordPress_Helper::info_log(
            "开始批量下载 {$total_images} 张图片",
            'Async Image'
        );

        // 检查是否有重复的图片URL，避免重复下载
        $unique_images = [];
        $url_to_placeholder = [];

        foreach (self::$pending_images as $image_info) {
            $base_url = $image_info['base_url'];
            
            if (!isset($unique_images[$base_url])) {
                $unique_images[$base_url] = $image_info;
                $url_to_placeholder[$base_url] = [$image_info['placeholder']];
            } else {
                // 记录重复的占位符，稍后一起替换
                $url_to_placeholder[$base_url][] = $image_info['placeholder'];
            }
        }

        $unique_count = count($unique_images);
        if ($unique_count < $total_images) {
            Notion_To_WordPress_Helper::debug_log(
                "去重后需要下载 {$unique_count} 张图片（原始: {$total_images}）",
                'Async Image'
            );
        }

        // 准备并发下载请求
        $requests = [];
        foreach ($unique_images as $base_url => $image_info) {
            $requests[] = [
                'url' => $image_info['url'],
                'args' => [
                    'timeout' => 30,
                    'user-agent' => 'Notion-to-WordPress/2.0.0'
                ],
                'image_info' => $image_info
            ];
        }

        // 执行并发下载
        $responses = self::concurrent_download_images($requests);
        self::$performance_stats['concurrent_downloads'] = count($responses);

        // 处理下载结果
        $success_count = 0;
        $error_count = 0;

        foreach ($responses as $index => $response) {
            $image_info = $requests[$index]['image_info'];
            $base_url = $image_info['base_url'];
            
            if (is_wp_error($response)) {
                Notion_To_WordPress_Helper::error_log(
                    "图片下载失败: {$image_info['url']} - " . $response->get_error_message(),
                    'Async Image'
                );
                
                // 标记所有相同URL的占位符为失败
                foreach ($url_to_placeholder[$base_url] as $placeholder) {
                    self::$image_placeholders[$placeholder] = null;
                }
                $error_count++;
            } else {
                // 处理成功下载的图片
                $attachment_id = self::process_downloaded_image_response($image_info, $response);
                
                if ($attachment_id) {
                    // 为所有相同URL的占位符设置相同的附件ID
                    foreach ($url_to_placeholder[$base_url] as $placeholder) {
                        self::$image_placeholders[$placeholder] = $attachment_id;
                    }
                    $success_count++;
                } else {
                    // 处理失败
                    foreach ($url_to_placeholder[$base_url] as $placeholder) {
                        self::$image_placeholders[$placeholder] = null;
                    }
                    $error_count++;
                }
            }
        }

        $end_time = microtime(true);
        $download_time = $end_time - $start_time;

        // 更新性能统计
        self::$performance_stats['download_time'] = $download_time;
        self::$performance_stats['success_count'] = $success_count;
        self::$performance_stats['error_count'] = $error_count;

        Notion_To_WordPress_Helper::info_log(
            sprintf(
                '批量下载完成: 成功 %d, 失败 %d, 耗时 %.2f 秒',
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
    private static function concurrent_download_images(array $requests): array {
        if (empty($requests)) {
            return [];
        }

        // 使用WordPress的HTTP API进行并发请求
        $multi_requests = [];

        // 准备并发请求
        foreach ($requests as $index => $request) {
            $multi_requests[$index] = [
                'url' => $request['url'],
                'args' => array_merge($request['args'], [
                    'blocking' => false, // 非阻塞模式
                    'timeout' => 30,
                    'user-agent' => 'Notion-to-WordPress/2.0.0'
                ])
            ];
        }

        Notion_To_WordPress_Helper::debug_log(
            sprintf('Starting concurrent download of %d images', count($multi_requests)),
            'Concurrent Download'
        );

        // 使用cURL多句柄进行并发下载
        return self::execute_concurrent_requests($multi_requests);
    }

    /**
     * 执行并发HTTP请求
     *
     * @since 2.0.0-beta.1
     * @param array $multi_requests 多个请求
     * @return array 响应数组
     */
    private static function execute_concurrent_requests(array $multi_requests): array {
        $responses = [];

        // 检查是否支持cURL
        if (!function_exists('curl_multi_init')) {
            Notion_To_WordPress_Helper::debug_log(
                'cURL multi not available, falling back to sequential requests',
                'Concurrent Download'
            );

            // 降级到顺序请求
            foreach ($multi_requests as $index => $request) {
                $response = wp_remote_get($request['url'], $request['args']);
                $responses[$index] = $response;
            }
            return $responses;
        }

        // 创建cURL多句柄
        $multi_handle = curl_multi_init();
        $curl_handles = [];

        // 添加所有请求到多句柄
        foreach ($multi_requests as $index => $request) {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $request['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'Notion-to-WordPress/2.0.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            curl_multi_add_handle($multi_handle, $ch);
            $curl_handles[$index] = $ch;
        }

        // 执行并发请求
        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle);
        } while ($running > 0);

        // 收集响应
        foreach ($curl_handles as $index => $ch) {
            $content = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if (!empty($error)) {
                $responses[$index] = new WP_Error('curl_error', $error);
            } else {
                // 模拟WordPress HTTP API响应格式
                $responses[$index] = [
                    'body' => $content,
                    'response' => [
                        'code' => $http_code,
                        'message' => ''
                    ],
                    'headers' => []
                ];
            }

            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi_handle);

        Notion_To_WordPress_Helper::debug_log(
            sprintf('Concurrent download completed: %d responses', count($responses)),
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
    private static function process_downloaded_image_response(array $image_info, array $response): ?int {
        if (is_wp_error($response)) {
            return null;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            Notion_To_WordPress_Helper::error_log(
                "图片下载HTTP错误: {$image_info['url']} - HTTP {$http_code}",
                'Async Image'
            );
            return null;
        }

        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            Notion_To_WordPress_Helper::error_log(
                "图片数据为空: {$image_info['url']}",
                'Async Image'
            );
            return null;
        }

        // 获取文件扩展名
        $url_path = parse_url($image_info['url'], PHP_URL_PATH);
        $extension = pathinfo($url_path, PATHINFO_EXTENSION);

        if (empty($extension)) {
            // 尝试从Content-Type头部获取扩展名
            $content_type = wp_remote_retrieve_header($response, 'content-type');
            $extension = self::get_extension_from_content_type($content_type);
        }

        if (empty($extension)) {
            $extension = 'jpg'; // 默认扩展名
        }

        // 生成文件名
        $filename = 'notion-image-' . md5($image_info['url']) . '.' . $extension;

        // 上传到WordPress媒体库
        $upload = wp_upload_bits($filename, null, $image_data);

        if ($upload['error']) {
            Notion_To_WordPress_Helper::error_log(
                "图片上传失败: {$image_info['url']} - " . $upload['error'],
                'Async Image'
            );
            return null;
        }

        // 创建附件
        $attachment = [
            'post_mime_type' => wp_check_filetype($filename)['type'],
            'post_title' => !empty($image_info['caption']) ? $image_info['caption'] : 'Notion Image',
            'post_content' => '',
            'post_status' => 'inherit'
        ];

        $attachment_id = wp_insert_attachment($attachment, $upload['file']);

        if (is_wp_error($attachment_id)) {
            Notion_To_WordPress_Helper::error_log(
                "附件创建失败: {$image_info['url']} - " . $attachment_id->get_error_message(),
                'Async Image'
            );
            return null;
        }

        // 生成附件元数据
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        Notion_To_WordPress_Helper::debug_log(
            "图片处理成功: {$image_info['url']} -> 附件ID {$attachment_id}",
            'Async Image'
        );

        return $attachment_id;
    }

    /**
     * 处理异步图片（替换占位符）
     *
     * @since 2.0.0-beta.1
     * @param string $html HTML内容
     * @return string 处理后的HTML
     */
    public static function process_async_images(string $html): string {
        if (!self::$async_image_mode || empty(self::$pending_images)) {
            return $html;
        }

        $start_time = microtime(true);

        // 先下载所有图片
        self::batch_download_images();

        // 然后替换占位符
        $processed_html = self::replace_image_placeholders($html);

        // 清理状态
        self::clear_image_queue();

        $processing_time = microtime(true) - $start_time;
        self::$performance_stats['processing_time'] = $processing_time;

        Notion_To_WordPress_Helper::info_log(
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
     * @return string 替换后的HTML
     */
    public static function replace_image_placeholders(string $html): string {
        if (empty(self::$image_placeholders)) {
            return $html;
        }

        $replaced_count = 0;

        foreach (self::$image_placeholders as $placeholder => $attachment_id) {
            if ($attachment_id) {
                // 成功下载的图片，生成WordPress图片HTML
                $image_html = self::generate_wordpress_image_html($attachment_id, $placeholder);
                $html = str_replace($placeholder, $image_html, $html);
                $replaced_count++;
            } else {
                // 下载失败的图片，显示错误信息
                $error_html = '<!-- 图片下载失败: ' . esc_html($placeholder) . ' -->';
                $html = str_replace($placeholder, $error_html, $html);
            }
        }

        Notion_To_WordPress_Helper::debug_log(
            "替换了 {$replaced_count} 个图片占位符",
            'Async Image'
        );

        return $html;
    }

    // ==================== 辅助方法 ====================

    /**
     * 清理图片队列
     *
     * @since 2.0.0-beta.1
     */
    private static function clear_image_queue(): void {
        self::$pending_images = [];
        self::$image_placeholders = [];

        // 重置性能统计
        self::$performance_stats = [
            'total_images' => 0,
            'concurrent_downloads' => 0,
            'download_time' => 0,
            'processing_time' => 0,
            'success_count' => 0,
            'error_count' => 0
        ];
    }

    /**
     * 生成直接图片HTML（非异步模式）
     *
     * @since 2.0.0-beta.1
     * @param string $url 图片URL
     * @param string $caption 图片说明
     * @return string HTML内容
     */
    private static function generate_direct_image_html(string $url, string $caption = ''): string {
        $alt_text = !empty($caption) ? esc_attr($caption) : '';
        $html = '<figure class="notion-image">';
        $html .= '<img src="' . esc_url($url) . '" alt="' . $alt_text . '" loading="lazy">';
        if (!empty($caption)) {
            $html .= '<figcaption>' . esc_html($caption) . '</figcaption>';
        }
        $html .= '</figure>';
        return $html;
    }

    /**
     * 生成WordPress图片HTML
     *
     * @since 2.0.0-beta.1
     * @param int $attachment_id 附件ID
     * @param string $placeholder 原占位符
     * @return string HTML内容
     */
    private static function generate_wordpress_image_html(int $attachment_id, string $placeholder): string {
        // 获取图片信息
        $image_src = wp_get_attachment_image_src($attachment_id, 'large');
        $image_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $image_caption = wp_get_attachment_caption($attachment_id);

        if (!$image_src) {
            return '<!-- 无法获取图片信息: 附件ID ' . $attachment_id . ' -->';
        }

        $html = '<figure class="wp-block-image size-large">';
        $html .= '<img src="' . esc_url($image_src[0]) . '" alt="' . esc_attr($image_alt) . '" class="wp-image-' . $attachment_id . '"';

        if (isset($image_src[1]) && isset($image_src[2])) {
            $html .= ' width="' . $image_src[1] . '" height="' . $image_src[2] . '"';
        }

        $html .= ' loading="lazy">';

        if (!empty($image_caption)) {
            $html .= '<figcaption>' . esc_html($image_caption) . '</figcaption>';
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
    private static function is_notion_temp_url(string $url): bool {
        // Notion临时链接通常包含特定的域名和参数
        return strpos($url, 'secure.notion-static.com') !== false ||
               strpos($url, 'prod-files-secure') !== false ||
               strpos($url, 'X-Amz-Algorithm') !== false ||
               strpos($url, 'notion.so') !== false ||
               strpos($url, 'amazonaws.com') !== false;
    }

    /**
     * 根据Content-Type获取文件扩展名
     *
     * @since 2.0.0-beta.1
     * @param string $content_type Content-Type头部
     * @return string 文件扩展名
     */
    private static function get_extension_from_content_type(string $content_type): string {
        $mime_to_ext = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff'
        ];

        $content_type = strtolower(trim($content_type));

        // 移除可能的字符集信息
        if (strpos($content_type, ';') !== false) {
            $content_type = trim(explode(';', $content_type)[0]);
        }

        return $mime_to_ext[$content_type] ?? '';
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
    public static function get_queue_status(): array {
        return [
            'async_mode' => self::$async_image_mode,
            'pending_images' => count(self::$pending_images),
            'placeholders' => count(self::$image_placeholders),
            'queue_details' => self::$pending_images
        ];
    }

    /**
     * 重置图片处理器状态
     *
     * @since 2.0.0-beta.1
     */
    public static function reset(): void {
        self::$async_image_mode = false;
        self::clear_image_queue();

        Notion_To_WordPress_Helper::debug_log(
            '图片处理器状态已重置',
            'Image Processor'
        );
    }

    /**
     * 向后兼容：检查是否启用异步模式（静态访问）
     *
     * @since 2.0.0-beta.1
     * @return bool 是否启用异步模式
     */
    public static function is_async_mode(): bool {
        return self::$async_image_mode;
    }
}
