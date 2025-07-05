<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 依赖管理器
 *
 * 负责加载和管理插件的所有依赖文件
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */

class Notion_To_WordPress_Dependency_Manager {

    /**
     * 核心依赖文件列表
     *
     * @since 1.1.0
     * @var array<string>
     */
    private static array $core_dependencies = [
        'includes/class-notion-to-wordpress-loader.php',
        'includes/class-notion-to-wordpress-i18n.php',
        'includes/class-notion-to-wordpress-error-handler.php',
        'includes/class-notion-rich-text-processor.php',
        'includes/class-notion-media-handler.php',
        'includes/class-notion-to-wordpress-memory-manager.php',
    ];

    /**
     * API相关依赖文件列表
     *
     * @since 1.1.0
     * @var array<string>
     */
    private static array $api_dependencies = [
        'includes/class-notion-api.php',
        'includes/class-notion-pages-refactored.php',
        'includes/class-notion-page-importer.php',
        'includes/class-notion-wordpress-integrator.php',
        'includes/class-notion-pages-compatibility.php',
        'includes/class-notion-block-converter.php',
    ];

    /**
     * 管理界面依赖文件列表
     *
     * @since 1.1.0
     * @var array<string>
     */
    private static array $admin_dependencies = [
        'admin/class-notion-to-wordpress-admin.php',
    ];

    /**
     * 功能扩展依赖文件列表
     *
     * @since 1.1.0
     * @var array<string>
     */
    private static array $feature_dependencies = [
        'includes/class-notion-to-wordpress-lock.php',
        'includes/class-notion-to-wordpress-webhook.php',
        'includes/class-notion-download-queue.php',
        'includes/class-notion-import-coordinator.php',
    ];

    /**
     * 已加载的依赖文件
     *
     * @since 1.1.0
     * @var array<string>
     */
    private static array $loaded_dependencies = [];

    /**
     * 加载所有依赖文件
     *
     * @since 1.1.0
     * @return bool 是否成功加载所有依赖
     */
    public static function load_all_dependencies(): bool {
        try {
            self::load_core_dependencies();
            self::load_api_dependencies();
            self::load_admin_dependencies();
            self::load_feature_dependencies();

            // 确保错误处理器已加载后再使用
            if (class_exists('Notion_To_WordPress_Error_Handler')) {
                Notion_To_WordPress_Error_Handler::log_info(
                    '所有依赖文件加载完成',
                    Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
                    ['loaded_count' => count(self::$loaded_dependencies)]
                );
            } else {
                // 使用Helper类作为备选日志记录
                if (class_exists('Notion_To_WordPress_Helper')) {
                    Notion_To_WordPress_Helper::info_log(
                        '所有依赖文件加载完成，共加载 ' . count(self::$loaded_dependencies) . ' 个文件',
                        'Dependency Manager'
                    );
                }
            }

            return true;
        } catch (Exception $e) {
            // 优先使用错误处理器，如果不可用则使用Helper类
            if (class_exists('Notion_To_WordPress_Error_Handler')) {
                Notion_To_WordPress_Error_Handler::exception_to_wp_error(
                    $e,
                    Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR
                );
            } elseif (class_exists('Notion_To_WordPress_Helper')) {
                Notion_To_WordPress_Helper::error_log(
                    '依赖加载失败: ' . $e->getMessage(),
                    'Dependency Manager'
                );
            } else {
                // 最后的备选方案
                error_log('[Notion-to-WordPress] 依赖加载失败: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * 加载核心依赖
     *
     * @since 1.1.0
     * @throws Exception 当文件加载失败时
     */
    public static function load_core_dependencies(): void {
        self::load_dependency_group(self::$core_dependencies, 'core');
    }

    /**
     * 加载API依赖
     *
     * @since 1.1.0
     * @throws Exception 当文件加载失败时
     */
    public static function load_api_dependencies(): void {
        self::load_dependency_group(self::$api_dependencies, 'api');
    }

    /**
     * 加载管理界面依赖
     *
     * @since 1.1.0
     * @throws Exception 当文件加载失败时
     */
    public static function load_admin_dependencies(): void {
        if (is_admin()) {
            self::load_dependency_group(self::$admin_dependencies, 'admin');
        }
    }

    /**
     * 加载功能扩展依赖
     *
     * @since 1.1.0
     * @throws Exception 当文件加载失败时
     */
    public static function load_feature_dependencies(): void {
        self::load_dependency_group(self::$feature_dependencies, 'feature');
    }

    /**
     * 检查依赖是否已加载
     *
     * @since 1.1.0
     * @param string $dependency_path 依赖文件路径
     * @return bool 是否已加载
     */
    public static function is_dependency_loaded(string $dependency_path): bool {
        return in_array($dependency_path, self::$loaded_dependencies);
    }

    /**
     * 获取已加载的依赖列表
     *
     * @since 1.1.0
     * @return array<string> 已加载的依赖文件列表
     */
    public static function get_loaded_dependencies(): array {
        return self::$loaded_dependencies;
    }

    /**
     * 验证所有必需的类是否已加载
     *
     * @since 1.1.0
     * @return bool 是否所有必需类都已加载
     */
    public static function validate_required_classes(): bool {
        $required_classes = [
            'Notion_To_WordPress_Loader',
            'Notion_To_WordPress_i18n',
            'Notion_To_WordPress_Error_Handler',
            'Notion_Rich_Text_Processor',
            'Notion_Media_Handler',
            'Notion_API',
            'Notion_Pages',
            'Notion_Pages_Refactored',
            'Notion_Page_Importer',
            'Notion_WordPress_Integrator',
            'Notion_Block_Converter',
            'Notion_To_WordPress_Lock',
            'Notion_To_WordPress_Webhook',
            'Notion_Download_Queue',
            'Notion_Import_Coordinator',
        ];

        $missing_classes = [];
        foreach ($required_classes as $class_name) {
            if (!class_exists($class_name)) {
                $missing_classes[] = $class_name;
            }
        }

        if (!empty($missing_classes)) {
            $error_message = '缺少必需的类: ' . implode(', ', $missing_classes);

            // 优先使用错误处理器，如果不可用则使用Helper类
            if (class_exists('Notion_To_WordPress_Error_Handler')) {
                Notion_To_WordPress_Error_Handler::log_error(
                    $error_message,
                    Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
                    ['missing_classes' => $missing_classes]
                );
            } elseif (class_exists('Notion_To_WordPress_Helper')) {
                Notion_To_WordPress_Helper::error_log($error_message, 'Dependency Manager');
            } else {
                error_log('[Notion-to-WordPress] ' . $error_message);
            }
            return false;
        }

        return true;
    }

    /**
     * 加载依赖组
     *
     * @since 1.1.0
     * @param array<string> $dependencies 依赖文件列表
     * @param string        $group_name   组名
     * @throws Exception 当文件加载失败时
     */
    private static function load_dependency_group(array $dependencies, string $group_name): void {
        foreach ($dependencies as $dependency) {
            if (self::is_dependency_loaded($dependency)) {
                continue;
            }

            $file_path = Notion_To_WordPress_Helper::plugin_path($dependency);
            
            if (!file_exists($file_path)) {
                throw new Exception("依赖文件不存在: {$dependency}");
            }

            if (!is_readable($file_path)) {
                throw new Exception("依赖文件不可读: {$dependency}");
            }

            require_once $file_path;
            self::$loaded_dependencies[] = $dependency;

            // 安全地记录调试信息
            if (class_exists('Notion_To_WordPress_Error_Handler')) {
                Notion_To_WordPress_Error_Handler::log_debug(
                    "加载依赖文件: {$dependency}",
                    Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
                    ['group' => $group_name]
                );
            } elseif (class_exists('Notion_To_WordPress_Helper')) {
                Notion_To_WordPress_Helper::debug_log(
                    "加载依赖文件: {$dependency} (组: {$group_name})",
                    'Dependency Manager',
                    Notion_To_WordPress_Helper::DEBUG_LEVEL_DEBUG
                );
            }
        }
    }

    /**
     * 动态加载单个依赖
     *
     * @since 1.1.0
     * @param string $dependency_path 依赖文件路径
     * @return bool 是否成功加载
     */
    public static function load_single_dependency(string $dependency_path): bool {
        try {
            // 获取完整路径
            $full_path = Notion_To_WordPress_Helper::plugin_path($dependency_path);
            
            // 检查文件是否存在
            if (!file_exists($full_path)) {
                throw new Exception("依赖文件不存在: {$dependency_path}");
            }
            
            // 避免重复加载
            if (in_array($dependency_path, self::$loaded_dependencies)) {
                return true;
            }
            
            // 加载文件
            require_once $full_path;
            
            // 记录已加载
            self::$loaded_dependencies[] = $dependency_path;
            
            return true;
        } catch (Exception $e) {
            // 优先使用错误处理器，如果不可用则使用Helper类
            if (class_exists('Notion_To_WordPress_Error_Handler')) {
                Notion_To_WordPress_Error_Handler::log_error(
                    "加载依赖失败: {$e->getMessage()}",
                    Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR,
                    ['dependency' => $dependency_path]
                );
            } elseif (class_exists('Notion_To_WordPress_Helper')) {
                Notion_To_WordPress_Helper::error_log(
                    "加载依赖失败: {$e->getMessage()}",
                    'Dependency Manager'
                );
            } else {
                // 最后的备选方案
                error_log("[Notion-to-WordPress] 加载依赖失败: {$dependency_path} - {$e->getMessage()}");
            }
            return false;
        }
    }

    /**
     * 重置依赖加载状态（主要用于测试）
     *
     * @since 1.1.0
     */
    public static function reset_loaded_dependencies(): void {
        self::$loaded_dependencies = [];
    }
}
