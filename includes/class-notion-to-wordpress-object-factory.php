<?php
/**
 * 对象工厂
 *
 * 负责创建和管理插件的所有对象实例
 *
 * @since      1.1.0
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */
declare(strict_types=1);

class Notion_To_WordPress_Object_Factory {

    /**
     * 插件名称
     *
     * @since 1.1.0
     * @var string
     */
    private string $plugin_name;

    /**
     * 插件版本
     *
     * @since 1.1.0
     * @var string
     */
    private string $version;

    /**
     * 对象实例缓存
     *
     * @since 1.1.0
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * 构造函数
     *
     * @since 1.1.0
     * @param string $plugin_name 插件名称
     * @param string $version     插件版本
     */
    public function __construct(string $plugin_name, string $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * 创建所有核心对象
     *
     * @since 1.1.0
     * @return array<string, object> 创建的对象实例
     */
    public function create_all_objects(): array {
        $this->create_loader();
        $this->create_i18n();
        $this->create_api_objects();
        $this->create_admin_objects();
        $this->create_feature_objects();

        return $this->instances;
    }

    /**
     * 创建加载器
     *
     * @since 1.1.0
     * @return Notion_To_WordPress_Loader
     */
    public function create_loader(): Notion_To_WordPress_Loader {
        if (!isset($this->instances['loader'])) {
            $this->instances['loader'] = new Notion_To_WordPress_Loader();
        }
        return $this->instances['loader'];
    }

    /**
     * 创建国际化处理器
     *
     * @since 1.1.0
     * @return Notion_To_WordPress_i18n
     */
    public function create_i18n(): Notion_To_WordPress_i18n {
        if (!isset($this->instances['i18n'])) {
            $this->instances['i18n'] = new Notion_To_WordPress_i18n();
        }
        return $this->instances['i18n'];
    }

    /**
     * 创建API相关对象
     *
     * @since 1.1.0
     */
    public function create_api_objects(): void {
        $options = get_option('notion_to_wordpress_options', []);
        $api_key = $options['notion_api_key'] ?? '';
        $database_id = $options['notion_database_id'] ?? '';
        $field_mapping = $options['field_mapping'] ?? [];
        $lock_timeout = $options['lock_timeout'] ?? 120;

        // 创建API实例
        if (!isset($this->instances['notion_api'])) {
            $this->instances['notion_api'] = Notion_API::instance($api_key);
        }

        // 创建页面处理器
        if (!isset($this->instances['notion_pages'])) {
            $this->instances['notion_pages'] = new Notion_Pages(
                $this->instances['notion_api'],
                $database_id,
                $field_mapping,
                $lock_timeout
            );
        }

        // 创建块转换器
        if (!isset($this->instances['block_converter'])) {
            $this->instances['block_converter'] = new Notion_Block_Converter(
                $this->instances['notion_pages'],
                $this->instances['notion_api']
            );
        }
    }

    /**
     * 创建管理界面对象
     *
     * @since 1.1.0
     */
    public function create_admin_objects(): void {
        if (!is_admin()) {
            return;
        }

        if (!isset($this->instances['admin'])) {
            $this->instances['admin'] = new Notion_To_WordPress_Admin(
                $this->plugin_name,
                $this->version,
                $this->get_notion_api(),
                $this->get_notion_pages()
            );
        }
    }

    /**
     * 创建功能扩展对象
     *
     * @since 1.1.0
     */
    public function create_feature_objects(): void {
        // 创建Webhook处理器
        if (!isset($this->instances['webhook'])) {
            $this->instances['webhook'] = new Notion_To_WordPress_Webhook(
                $this->get_notion_pages()
            );
        }

        // 创建导入协调器（按需创建）
        // Import Coordinator 通常在需要时动态创建
    }

    /**
     * 获取Notion API实例
     *
     * @since 1.1.0
     * @return Notion_API
     */
    public function get_notion_api(): Notion_API {
        if (!isset($this->instances['notion_api'])) {
            $this->create_api_objects();
        }
        return $this->instances['notion_api'];
    }

    /**
     * 获取Notion页面处理器实例
     *
     * @since 1.1.0
     * @return Notion_Pages
     */
    public function get_notion_pages(): Notion_Pages {
        if (!isset($this->instances['notion_pages'])) {
            $this->create_api_objects();
        }
        return $this->instances['notion_pages'];
    }

    /**
     * 获取管理界面实例
     *
     * @since 1.1.0
     * @return Notion_To_WordPress_Admin|null
     */
    public function get_admin(): ?Notion_To_WordPress_Admin {
        return $this->instances['admin'] ?? null;
    }

    /**
     * 获取Webhook处理器实例
     *
     * @since 1.1.0
     * @return Notion_To_WordPress_Webhook|null
     */
    public function get_webhook(): ?Notion_To_WordPress_Webhook {
        return $this->instances['webhook'] ?? null;
    }

    /**
     * 获取加载器实例
     *
     * @since 1.1.0
     * @return Notion_To_WordPress_Loader
     */
    public function get_loader(): Notion_To_WordPress_Loader {
        if (!isset($this->instances['loader'])) {
            $this->create_loader();
        }
        return $this->instances['loader'];
    }

    /**
     * 获取国际化处理器实例
     *
     * @since 1.1.0
     * @return Notion_To_WordPress_i18n
     */
    public function get_i18n(): Notion_To_WordPress_i18n {
        if (!isset($this->instances['i18n'])) {
            $this->create_i18n();
        }
        return $this->instances['i18n'];
    }

    /**
     * 创建导入协调器
     *
     * @since 1.1.0
     * @param string|null $strategy_class 策略类名
     * @return Notion_Import_Coordinator
     */
    public function create_import_coordinator(?string $strategy_class = null): Notion_Import_Coordinator {
        $strategy = null;
        if ($strategy_class && class_exists($strategy_class)) {
            $strategy = new $strategy_class();
        }

        $options = get_option('notion_to_wordpress_options', []);
        $database_id = $options['notion_database_id'] ?? '';
        $lock_timeout = $options['lock_timeout'] ?? 120;

        return new Notion_Import_Coordinator(
            $this->get_notion_pages(),
            $this->get_notion_api(),
            $database_id,
            $lock_timeout,
            $strategy
        );
    }

    /**
     * 创建锁实例
     *
     * @since 1.1.0
     * @param string $database_id 数据库ID
     * @param int    $expiration  过期时间
     * @return Notion_To_WordPress_Lock
     */
    public function create_lock(string $database_id, int $expiration = 300): Notion_To_WordPress_Lock {
        return new Notion_To_WordPress_Lock($database_id, $expiration);
    }

    /**
     * 检查对象是否已创建
     *
     * @since 1.1.0
     * @param string $object_name 对象名称
     * @return bool 是否已创建
     */
    public function has_instance(string $object_name): bool {
        return isset($this->instances[$object_name]);
    }

    /**
     * 获取所有实例
     *
     * @since 1.1.0
     * @return array<string, object> 所有对象实例
     */
    public function get_all_instances(): array {
        return $this->instances;
    }

    /**
     * 清理所有实例（主要用于测试）
     *
     * @since 1.1.0
     */
    public function clear_all_instances(): void {
        $this->instances = [];
    }

    /**
     * 设置自定义字段映射
     *
     * @since 1.1.0
     * @param array $custom_field_mappings 自定义字段映射
     */
    public function set_custom_field_mappings(array $custom_field_mappings): void {
        if (isset($this->instances['notion_pages'])) {
            $this->instances['notion_pages']->set_custom_field_mappings($custom_field_mappings);
        }
    }

    /**
     * 更新配置选项
     *
     * @since 1.1.0
     * @param array $options 新的配置选项
     */
    public function update_options(array $options): void {
        // 如果API密钥或数据库ID发生变化，需要重新创建相关对象
        $current_options = get_option('notion_to_wordpress_options', []);
        
        $api_key_changed = ($options['notion_api_key'] ?? '') !== ($current_options['notion_api_key'] ?? '');
        $database_id_changed = ($options['notion_database_id'] ?? '') !== ($current_options['notion_database_id'] ?? '');

        if ($api_key_changed || $database_id_changed) {
            // 清理相关实例，强制重新创建
            unset($this->instances['notion_api']);
            unset($this->instances['notion_pages']);
            unset($this->instances['block_converter']);
            unset($this->instances['webhook']);

            // 重新创建API对象
            $this->create_api_objects();
            $this->create_feature_objects();
        }

        // 更新自定义字段映射
        if (isset($options['custom_field_mappings'])) {
            $this->set_custom_field_mappings($options['custom_field_mappings']);
        }
    }

    /**
     * 验证所有必需对象是否已创建
     *
     * @since 1.1.0
     * @return bool 是否所有必需对象都已创建
     */
    public function validate_required_objects(): bool {
        $required_objects = ['loader', 'i18n'];
        
        if (is_admin()) {
            $required_objects[] = 'admin';
        }

        $options = get_option('notion_to_wordpress_options', []);
        if (!empty($options['notion_api_key']) && !empty($options['notion_database_id'])) {
            $required_objects = array_merge($required_objects, ['notion_api', 'notion_pages']);
        }

        foreach ($required_objects as $object_name) {
            if (!$this->has_instance($object_name)) {
                Notion_To_WordPress_Error_Handler::log_error(
                    "缺少必需的对象实例: {$object_name}",
                    Notion_To_WordPress_Error_Handler::CODE_CONFIG_ERROR
                );
                return false;
            }
        }

        return true;
    }
}
