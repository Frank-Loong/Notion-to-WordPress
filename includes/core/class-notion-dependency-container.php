<?php
declare(strict_types=1);

/**
 * Notion 依赖注入容器类
 * 
 * 实现简单的依赖注入容器，提升代码的可测试性和可维护性
 * 分离数据访问层和业务逻辑层
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

class Notion_Dependency_Container {
    
    /**
     * 服务实例存储
     * @var array
     */
    private static $instances = [];
    
    /**
     * 服务定义存储
     * @var array
     */
    private static $definitions = [];
    
    /**
     * 单例模式标记
     * @var array
     */
    private static $singletons = [];
    
    /**
     * 注册服务
     *
     * @since 2.0.0-beta.1
     * @param string $name 服务名称
     * @param callable|string $definition 服务定义
     * @param bool $singleton 是否为单例
     */
    public static function register(string $name, $definition, bool $singleton = true): void {
        self::$definitions[$name] = $definition;
        self::$singletons[$name] = $singleton;
    }
    
    /**
     * 获取服务实例
     *
     * @since 2.0.0-beta.1
     * @param string $name 服务名称
     * @return mixed 服务实例
     * @throws Exception 当服务未注册时
     */
    public static function get(string $name) {
        // 检查是否为单例且已实例化
        if (self::$singletons[$name] ?? false) {
            if (isset(self::$instances[$name])) {
                return self::$instances[$name];
            }
        }
        
        // 检查服务是否已注册
        if (!isset(self::$definitions[$name])) {
            throw new Exception("Service '{$name}' is not registered");
        }
        
        $definition = self::$definitions[$name];
        
        // 创建实例
        if (is_callable($definition)) {
            $instance = $definition();
        } elseif (is_string($definition) && class_exists($definition)) {
            $instance = new $definition();
        } else {
            throw new Exception("Invalid service definition for '{$name}'");
        }
        
        // 存储单例实例
        if (self::$singletons[$name] ?? false) {
            self::$instances[$name] = $instance;
        }
        
        return $instance;
    }
    
    /**
     * 检查服务是否已注册
     *
     * @since 2.0.0-beta.1
     * @param string $name 服务名称
     * @return bool 是否已注册
     */
    public static function has(string $name): bool {
        return isset(self::$definitions[$name]);
    }
    
    /**
     * 移除服务
     *
     * @since 2.0.0-beta.1
     * @param string $name 服务名称
     */
    public static function remove(string $name): void {
        unset(self::$definitions[$name], self::$instances[$name], self::$singletons[$name]);
    }
    
    /**
     * 清空所有服务
     *
     * @since 2.0.0-beta.1
     */
    public static function clear(): void {
        self::$definitions = [];
        self::$instances = [];
        self::$singletons = [];
    }
    
    /**
     * 初始化核心服务
     *
     * @since 2.0.0-beta.1
     */
    public static function init_core_services(): void {
        // 注册缓存服务
        self::register('cache', function() {
            return new Notion_Smart_Cache();
        });
        
        // 注册并发管理服务
        self::register('concurrency', function() {
            return new Notion_Unified_Concurrency_Manager();
        });
        
        // 注册数据库助手服务
        self::register('database', function() {
            return new Notion_Database_Helper();
        });
        
        // 注册内存管理服务
        self::register('memory', function() {
            return new Notion_Memory_Manager();
        });
        
        // 注册日志服务
        self::register('logger', function() {
            return new Notion_Logger();
        });
        
        // 注册网络管理服务
        self::register('network', function() {
            return new Notion_Concurrent_Network_Manager();
        });
        
        // 注册任务调度服务
        self::register('scheduler', function() {
            return new Notion_Async_Task_Scheduler();
        });
        
        // 注册API合并服务
        self::register('api_merger', function() {
            return new Notion_Smart_API_Merger();
        });
    }
    
    /**
     * 获取服务列表
     *
     * @since 2.0.0-beta.1
     * @return array 服务列表
     */
    public static function get_services(): array {
        return array_keys(self::$definitions);
    }
    
    /**
     * 获取服务状态
     *
     * @since 2.0.0-beta.1
     * @return array 服务状态信息
     */
    public static function get_status(): array {
        $status = [
            'total_services' => count(self::$definitions),
            'instantiated_services' => count(self::$instances),
            'singleton_services' => count(array_filter(self::$singletons)),
            'services' => []
        ];
        
        foreach (self::$definitions as $name => $definition) {
            $status['services'][$name] = [
                'registered' => true,
                'instantiated' => isset(self::$instances[$name]),
                'singleton' => self::$singletons[$name] ?? false,
                'type' => is_callable($definition) ? 'callable' : 'class'
            ];
        }
        
        return $status;
    }
}

/**
 * 服务接口基类
 * 
 * 为所有服务提供统一的接口规范
 */
interface Notion_Service_Interface {
    
    /**
     * 初始化服务
     */
    public function init(): void;
    
    /**
     * 获取服务状态
     */
    public function get_status(): array;
}

/**
 * 抽象服务基类
 * 
 * 为服务实现提供通用功能
 */
abstract class Notion_Abstract_Service implements Notion_Service_Interface {
    
    /**
     * 服务是否已初始化
     * @var bool
     */
    protected $initialized = false;
    
    /**
     * 服务配置
     * @var array
     */
    protected $config = [];
    
    /**
     * 构造函数
     *
     * @param array $config 服务配置
     */
    public function __construct(array $config = []) {
        $this->config = array_merge($this->get_default_config(), $config);
    }
    
    /**
     * 获取默认配置
     *
     * @return array 默认配置
     */
    protected function get_default_config(): array {
        return [];
    }
    
    /**
     * 检查服务是否已初始化
     *
     * @return bool 是否已初始化
     */
    public function is_initialized(): bool {
        return $this->initialized;
    }
    
    /**
     * 获取配置值
     *
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    protected function get_config(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * 设置配置值
     *
     * @param string $key 配置键
     * @param mixed $value 配置值
     */
    protected function set_config(string $key, $value): void {
        $this->config[$key] = $value;
    }
    
    /**
     * 获取服务状态（默认实现）
     *
     * @return array 服务状态
     */
    public function get_status(): array {
        return [
            'initialized' => $this->initialized,
            'config' => $this->config,
            'class' => get_class($this)
        ];
    }
}
