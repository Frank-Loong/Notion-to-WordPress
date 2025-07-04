<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Notion媒体处理器
 *
 * 统一处理媒体文件的下载、验证和处理
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notion_Media_Handler {

    /**
     * 支持的媒体类型
     *
     * @since 1.1.0
     */
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_FILE = 'file';

    /**
     * 允许的图片MIME类型
     *
     * @since 1.1.0
     * @var array<string>
     */
    private static array $allowed_image_types = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml'
    ];

    /**
     * 允许的视频MIME类型
     *
     * @since 1.1.0
     * @var array<string>
     */
    private static array $allowed_video_types = [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/avi',
        'video/mov'
    ];

    /**
     * 允许的音频MIME类型
     *
     * @since 1.1.0
     * @var array<string>
     */
    private static array $allowed_audio_types = [
        'audio/mp3',
        'audio/wav',
        'audio/ogg',
        'audio/m4a',
        'audio/aac'
    ];

    /**
     * 最大文件大小（字节）
     *
     * @since 1.1.0
     * @var int
     */
    private static int $max_file_size = 50 * 1024 * 1024; // 50MB

    /**
     * 下载并处理媒体文件
     *
     * @since 1.1.0
     * @param string $url        媒体文件URL
     * @param int    $post_id    关联的文章ID
     * @param bool   $is_featured 是否为特色图片
     * @param string $caption    图片说明
     * @param string $alt_text   替代文本
     * @return array{success: bool, attachment_id?: int, error?: string}
     */
    public static function download_and_process(
        string $url, 
        int $post_id = 0, 
        bool $is_featured = false, 
        string $caption = '', 
        string $alt_text = ''
    ): array {
        try {
            // 验证URL
            if (!self::validate_url($url)) {
                return [
                    'success' => false,
                    'error' => '无效的媒体URL'
                ];
            }

            // 新增：HEAD 预检，提前验证 MIME 类型及大小，减少不必要的下载
            $head_response = wp_remote_head($url, ['timeout' => 15, 'redirection' => 3]);
            if ( ! is_wp_error($head_response) ) {
                $pre_mime = wp_remote_retrieve_header($head_response, 'content-type');
                if ( $pre_mime ) {
                    $pre_mime = trim( explode(';', $pre_mime)[0] );
                    if ( ! self::is_allowed_mime_type( $pre_mime ) ) {
                        return [
                            'success' => false,
                            'error'   => '不允许的 MIME 类型: ' . esc_html( $pre_mime ),
                        ];
                    }
                }
                $content_length = (int) wp_remote_retrieve_header( $head_response, 'content-length' );
                if ( $content_length > 0 && $content_length > self::$max_file_size ) {
                    return [
                        'success' => false,
                        'error'   => '文件过大，最大允许 ' . size_format( self::$max_file_size ),
                    ];
                }
            }

            // 检查是否已存在
            $existing_id = self::find_existing_attachment($url);
            if ($existing_id) {
                self::maybe_set_featured_image($existing_id, $post_id, $is_featured);
                return [
                    'success' => true,
                    'attachment_id' => $existing_id
                ];
            }

            // 下载文件
            $download_result = self::download_file($url);
            if (!$download_result['success']) {
                return $download_result;
            }

            // 验证文件
            $validation_result = self::validate_file($download_result['file_path']);
            if (!$validation_result['success']) {
                @unlink($download_result['file_path']);
                return $validation_result;
            }

            // 创建附件
            $attachment_result = self::create_attachment(
                $download_result['file_path'],
                $download_result['filename'],
                $post_id,
                $caption,
                $alt_text
            );

            if (!$attachment_result['success']) {
                @unlink($download_result['file_path']);
                return $attachment_result;
            }

            // 设置特色图片
            self::maybe_set_featured_image($attachment_result['attachment_id'], $post_id, $is_featured);

            // 记录成功日志
            Notion_To_WordPress_Error_Handler::log_info(
                "成功处理媒体文件: {$url}",
                Notion_To_WordPress_Error_Handler::CODE_MEDIA_ERROR,
                [
                    'attachment_id' => $attachment_result['attachment_id'],
                    'post_id' => $post_id,
                    'is_featured' => $is_featured
                ]
            );

            return [
                'success' => true,
                'attachment_id' => $attachment_result['attachment_id']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => '媒体处理异常: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 批量处理媒体文件
     *
     * @since 1.1.0
     * @param array $media_items 媒体项数组
     * @param int   $post_id     关联的文章ID
     * @return array 处理结果
     */
    public static function batch_process(array $media_items, int $post_id = 0): array {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'attachment_ids' => []
        ];

        foreach ($media_items as $item) {
            $url = $item['url'] ?? '';
            $is_featured = $item['is_featured'] ?? false;
            $caption = $item['caption'] ?? '';
            $alt_text = $item['alt_text'] ?? '';

            if (empty($url)) {
                $results['failed']++;
                $results['errors'][] = '空的媒体URL';
                continue;
            }

            $result = self::download_and_process($url, $post_id, $is_featured, $caption, $alt_text);
            
            if ($result['success']) {
                $results['success']++;
                $results['attachment_ids'][] = $result['attachment_id'];
            } else {
                $results['failed']++;
                $results['errors'][] = $result['error'] ?? '未知错误';
            }
        }

        return $results;
    }

    /**
     * 获取媒体类型
     *
     * @since 1.1.0
     * @param string $mime_type MIME类型
     * @return string 媒体类型
     */
    public static function get_media_type(string $mime_type): string {
        if (in_array($mime_type, self::$allowed_image_types)) {
            return self::TYPE_IMAGE;
        }
        
        if (in_array($mime_type, self::$allowed_video_types)) {
            return self::TYPE_VIDEO;
        }
        
        if (in_array($mime_type, self::$allowed_audio_types)) {
            return self::TYPE_AUDIO;
        }
        
        return self::TYPE_FILE;
    }

    /**
     * 检查MIME类型是否被允许
     *
     * @since 1.1.0
     * @param string $mime_type MIME类型
     * @return bool 是否被允许
     */
    public static function is_allowed_mime_type(string $mime_type): bool {
        // 合并用户在设置中自定义的 MIME 白名单
        $options = get_option( 'notion_to_wordpress_options', [] );
        $custom  = isset( $options['allowed_image_types'] ) ? array_filter( array_map( 'trim', explode( ',', $options['allowed_image_types'] ) ) ) : [];

        // 若包含 * 则直接放行
        if ( in_array( '*', $custom, true ) ) {
            return true;
        }

        return in_array( $mime_type, array_merge(
            self::$allowed_image_types,
            self::$allowed_video_types,
            self::$allowed_audio_types,
            $custom
        ) );
    }

    /**
     * 验证URL
     *
     * @since 1.1.0
     * @param string $url URL
     * @return bool 是否有效
     */
    private static function validate_url(string $url): bool {
        return self::is_safe_url($url);
    }

    /**
     * 查找已存在的附件
     *
     * @since 1.1.0
     * @param string $url 媒体URL
     * @return int|null 附件ID，不存在时返回null
     */
    private static function find_existing_attachment(string $url): ?int {
        global $wpdb;

        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_notion_media_url' AND meta_value = %s LIMIT 1",
                $url
            )
        );

        return $attachment_id ? (int) $attachment_id : null;
    }

    /**
     * 下载文件
     *
     * @since 1.1.0
     * @param string $url 文件URL
     * @return array{success: bool, file_path?: string, filename?: string, error?: string}
     */
    private static function download_file(string $url): array {
        // 生成临时文件名
        $filename = self::generate_filename($url);
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['path'] . '/' . $filename;

        // 下载文件
        $response = wp_remote_get($url, [
            'timeout' => 60,
            'stream' => true,
            'filename' => $temp_file,
            'headers' => [
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
            ]
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => '下载失败: ' . $response->get_error_message()
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            @unlink($temp_file);
            return [
                'success' => false,
                'error' => "下载失败: HTTP {$response_code}"
            ];
        }

        if (!file_exists($temp_file) || filesize($temp_file) === 0) {
            @unlink($temp_file);
            return [
                'success' => false,
                'error' => '下载的文件为空'
            ];
        }

        return [
            'success' => true,
            'file_path' => $temp_file,
            'filename' => $filename
        ];
    }

    /**
     * 验证文件
     *
     * @since 1.1.0
     * @param string $file_path 文件路径
     * @return array{success: bool, error?: string}
     */
    private static function validate_file(string $file_path): array {
        // 检查文件大小
        $file_size = filesize($file_path);
        if ($file_size > self::$max_file_size) {
            return [
                'success' => false,
                'error' => '文件过大，最大允许 ' . size_format(self::$max_file_size)
            ];
        }

        // 检查MIME类型
        $mime_type = wp_check_filetype($file_path)['type'];
        if (!self::is_allowed_mime_type($mime_type)) {
            return [
                'success' => false,
                'error' => '不支持的文件类型: ' . $mime_type
            ];
        }

        // 额外的安全检查
        if (!self::is_file_safe($file_path, $mime_type)) {
            return [
                'success' => false,
                'error' => '文件安全检查失败'
            ];
        }

        return ['success' => true];
    }

    /**
     * 创建WordPress附件
     *
     * @since 1.1.0
     * @param string $file_path 文件路径
     * @param string $filename  文件名
     * @param int    $post_id   关联文章ID
     * @param string $caption   说明
     * @param string $alt_text  替代文本
     * @return array{success: bool, attachment_id?: int, error?: string}
     */
    private static function create_attachment(
        string $file_path,
        string $filename,
        int $post_id,
        string $caption,
        string $alt_text
    ): array {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // 移动文件到正确位置
        $upload_dir = wp_upload_dir();
        $final_path = $upload_dir['path'] . '/' . $filename;

        if ($file_path !== $final_path) {
            if (!rename($file_path, $final_path)) {
                return [
                    'success' => false,
                    'error' => '无法移动文件到上传目录'
                ];
            }
        }

        // 准备附件数据
        $attachment_data = [
            'guid' => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => wp_check_filetype($filename)['type'],
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_excerpt' => $caption,
            'post_status' => 'inherit'
        ];

        // 插入附件
        $attachment_id = wp_insert_attachment($attachment_data, $final_path, $post_id);

        if (is_wp_error($attachment_id)) {
            @unlink($final_path);
            return [
                'success' => false,
                'error' => '创建附件失败: ' . $attachment_id->get_error_message()
            ];
        }

        // 生成附件元数据
        $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $final_path);
        wp_update_attachment_metadata($attachment_id, $attachment_metadata);

        // 设置替代文本
        if ($alt_text) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }

        return [
            'success' => true,
            'attachment_id' => $attachment_id
        ];
    }

    /**
     * 设置特色图片（如果需要）
     *
     * @since 1.1.0
     * @param int  $attachment_id 附件ID
     * @param int  $post_id       文章ID
     * @param bool $is_featured   是否为特色图片
     */
    private static function maybe_set_featured_image(int $attachment_id, int $post_id, bool $is_featured): void {
        if ($is_featured && $post_id > 0) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }

    /**
     * 生成文件名
     *
     * @since 1.1.0
     * @param string $url 原始URL
     * @return string 生成的文件名
     */
    private static function generate_filename(string $url): string {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';

        // 提取原始文件名
        $original_filename = basename($path);

        // 如果没有扩展名，尝试从Content-Type推断
        if (!pathinfo($original_filename, PATHINFO_EXTENSION)) {
            $original_filename .= '.jpg'; // 默认扩展名
        }

        // 清理文件名
        $filename = sanitize_file_name($original_filename);

        // 确保文件名唯一
        $upload_dir = wp_upload_dir();
        $target_path = $upload_dir['path'] . '/' . $filename;

        if (file_exists($target_path)) {
            $info = pathinfo($filename);
            $name = $info['filename'];
            $ext = $info['extension'] ?? '';
            $counter = 1;

            do {
                $filename = $name . '-' . $counter . ($ext ? '.' . $ext : '');
                $target_path = $upload_dir['path'] . '/' . $filename;
                $counter++;
            } while (file_exists($target_path) && $counter < 100);
        }

        return $filename;
    }

    /**
     * 检查文件安全性
     *
     * @since 1.1.0
     * @param string $file_path 文件路径
     * @param string $mime_type MIME类型
     * @return bool 是否安全
     */
    private static function is_file_safe(string $file_path, string $mime_type): bool {
        // 检查文件是否存在且可读
        if (!is_file($file_path) || !is_readable($file_path)) {
            return false;
        }

        // 检查文件扩展名
        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!self::is_allowed_extension($file_extension)) {
            return false;
        }

        // 检查文件头部
        $file_handle = fopen($file_path, 'rb');
        if (!$file_handle) {
            return false;
        }

        $file_header = fread($file_handle, 2048); // 增加读取长度
        fclose($file_handle);

        // 检查是否包含可执行代码
        $dangerous_patterns = [
            '<?php',
            '<?=',
            '<%',
            '<script',
            'javascript:',
            'vbscript:',
            'onload=',
            'onerror=',
            'onclick=',
            'onmouseover=',
            'eval(',
            'base64_decode(',
            'exec(',
            'system(',
            'shell_exec(',
            'passthru(',
            'file_get_contents(',
            'file_put_contents(',
            'fopen(',
            'fwrite(',
            'include(',
            'require(',
            '\x00', // null字节
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (stripos($file_header, $pattern) !== false) {
                Notion_To_WordPress_Error_Handler::log_warning(
                    "文件包含危险模式: {$pattern}",
                    Notion_To_WordPress_Error_Handler::CODE_MEDIA_ERROR,
                    ['file_path' => basename($file_path), 'mime_type' => $mime_type]
                );
                return false;
            }
        }

        // 检查文件魔数（文件头）
        if (!self::validate_file_magic_number($file_header, $mime_type)) {
            return false;
        }

        // 针对图片文件的额外检查
        if (strpos($mime_type, 'image/') === 0) {
            return self::validate_image_file($file_path, $mime_type);
        }

        // 针对其他文件类型的检查
        return self::validate_other_file_types($file_path, $mime_type);
    }

    /**
     * 检查文件扩展名是否被允许
     *
     * @since 1.1.0
     * @param string $extension 文件扩展名
     * @return bool 是否被允许
     */
    private static function is_allowed_extension(string $extension): bool {
        $allowed_extensions = [
            // 图片
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
            // 视频
            'mp4', 'webm', 'ogg', 'avi', 'mov',
            // 音频
            'mp3', 'wav', 'ogg', 'm4a', 'aac',
            // 文档
            'pdf', 'doc', 'docx', 'txt', 'rtf'
        ];

        return in_array($extension, $allowed_extensions);
    }

    /**
     * 验证文件魔数
     *
     * @since 1.1.0
     * @param string $file_header 文件头部数据
     * @param string $mime_type   MIME类型
     * @return bool 是否匹配
     */
    private static function validate_file_magic_number(string $file_header, string $mime_type): bool {
        $magic_numbers = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
            'image/gif' => ["GIF87a", "GIF89a"],
            'image/webp' => ["RIFF"],
            'video/mp4' => ["\x00\x00\x00", "ftyp"],
            'audio/mp3' => ["ID3", "\xFF\xFB", "\xFF\xF3", "\xFF\xF2"],
            'application/pdf' => ["%PDF-"],
        ];

        if (!isset($magic_numbers[$mime_type])) {
            return true; // 未知类型，跳过魔数检查
        }

        foreach ($magic_numbers[$mime_type] as $magic) {
            if (strpos($file_header, $magic) === 0) {
                return true;
            }
        }

        Notion_To_WordPress_Error_Handler::log_warning(
            "文件魔数不匹配MIME类型",
            Notion_To_WordPress_Error_Handler::CODE_MEDIA_ERROR,
            ['mime_type' => $mime_type]
        );
        return false;
    }

    /**
     * 验证其他文件类型
     *
     * @since 1.1.0
     * @param string $file_path 文件路径
     * @param string $mime_type MIME类型
     * @return bool 是否有效
     */
    private static function validate_other_file_types(string $file_path, string $mime_type): bool {
        // PDF文件特殊检查
        if ($mime_type === 'application/pdf') {
            return self::validate_pdf_file($file_path);
        }

        // 视频文件检查
        if (strpos($mime_type, 'video/') === 0) {
            return self::validate_video_file($file_path);
        }

        // 音频文件检查
        if (strpos($mime_type, 'audio/') === 0) {
            return self::validate_audio_file($file_path);
        }

        return true;
    }

    /**
     * 验证图片文件
     *
     * @since 1.1.0
     * @param string $file_path 文件路径
     * @param string $mime_type MIME类型
     * @return bool 是否为有效图片
     */
    private static function validate_image_file(string $file_path, string $mime_type): bool {
        // 使用getimagesize验证图片
        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            return false;
        }

        // 检查MIME类型是否匹配
        $detected_mime = $image_info['mime'] ?? '';
        if ($detected_mime !== $mime_type) {
            return false;
        }

        // 检查图片尺寸是否合理
        $width = $image_info[0] ?? 0;
        $height = $image_info[1] ?? 0;

        if ($width > 10000 || $height > 10000) {
            return false; // 图片过大
        }

        if ($width < 1 || $height < 1) {
            return false; // 无效尺寸
        }

        return true;
    }

    /**
     * 验证PDF文件
     *
     * @since 1.1.0
     * @param string $file_path 文件路径
     * @return bool 是否为有效PDF
     */
    private static function validate_pdf_file(string $file_path): bool {
        $file_handle = fopen($file_path, 'rb');
        if (!$file_handle) {
            return false;
        }

        $header = fread($file_handle, 1024);
        fclose($file_handle);

        // 检查PDF头部
        if (strpos($header, '%PDF-') !== 0) {
            return false;
        }

        // 检查是否包含JavaScript（可能的安全风险）
        if (stripos($header, '/JavaScript') !== false || stripos($header, '/JS') !== false) {
            Notion_To_WordPress_Error_Handler::log_warning(
                "PDF文件包含JavaScript代码",
                Notion_To_WordPress_Error_Handler::CODE_MEDIA_ERROR
            );
            return false;
        }

        return true;
    }

    /**
     * 验证视频文件
     *
     * @since 1.1.0
     * @param string $file_path 文件路径
     * @return bool 是否为有效视频
     */
    private static function validate_video_file(string $file_path): bool {
        // 基本的文件大小检查
        $file_size = filesize($file_path);
        if ($file_size > 100 * 1024 * 1024) { // 100MB限制
            return false;
        }

        // 可以添加更多视频特定的验证
        return true;
    }

    /**
     * 验证音频文件
     *
     * @since 1.1.0
     * @param string $file_path 文件路径
     * @return bool 是否为有效音频
     */
    private static function validate_audio_file(string $file_path): bool {
        // 基本的文件大小检查
        $file_size = filesize($file_path);
        if ($file_size > 50 * 1024 * 1024) { // 50MB限制
            return false;
        }

        // 可以添加更多音频特定的验证
        return true;
    }

    /**
     * 生成安全的文件名
     *
     * @since 1.1.0
     * @param string $original_filename 原始文件名
     * @return string 安全的文件名
     */
    public static function sanitize_filename(string $original_filename): string {
        // 移除路径分隔符
        $filename = basename($original_filename);

        // 移除危险字符
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);

        // 限制长度
        if (strlen($filename) > 100) {
            $info = pathinfo($filename);
            $name = substr($info['filename'], 0, 90);
            $ext = $info['extension'] ?? '';
            $filename = $name . ($ext ? '.' . $ext : '');
        }

        // 确保不为空
        if (empty($filename)) {
            $filename = 'notion-file-' . time() . '.tmp';
        }

        return $filename;
    }

    /**
     * 检查URL是否安全
     *
     * @since 1.1.0
     * @param string $url URL
     * @return bool 是否安全
     */
    public static function is_safe_url(string $url): bool {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['host'])) {
            return false;
        }

        // 检查协议
        if (!in_array($parsed['scheme'] ?? '', ['http', 'https'])) {
            return false;
        }

        // 检查是否为私网地址
        $ip = gethostbyname($parsed['host']);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // 检查黑名单域名
        $blacklisted_domains = [
            'localhost',
            '127.0.0.1',
            '0.0.0.0',
            '::1'
        ];

        if (in_array(strtolower($parsed['host']), $blacklisted_domains)) {
            return false;
        }

        return true;
    }
}
