<?php
declare(strict_types=1);

/**
 * 插件加载器类
 * 
 * 负责注册插件所有的动作（actions）和过滤器（filters），并将其挂载到 WordPress 的钩子系统中。
 * 
 * @since      1.0.9
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

class Notion_To_WordPress_Loader {

    /**
     * 存储所有需要注册的动作
     *
     * @since    1.0.5
     * @access   protected
     * @var      array    $actions    注册的动作
     */
    protected array $actions;

    /**
     * 存储所有需要注册的过滤器
     *
     * @since    1.0.5
     * @access   protected
     * @var      array    $filters    注册的过滤器
     */
    protected array $filters;

    /**
     * 初始化集合
     *
     * @since    1.0.5
     */
    public function __construct() {
        $this->actions = array();
        $this->filters = array();
    }

    /**
     * 添加新动作到集合
     *
     * @since    1.0.5
     * @param    string    $hook             钩子名称
     * @param    object    $component        钩子所在的组件
     * @param    string    $callback         要执行的回调函数
     * @param    int       $priority         动作执行的优先级
     * @param    int       $accepted_args    回调接受的参数数量
     */
    public function add_action($hook, $component, $callback, $priority = 10, $accepted_args = 1) {
        $this->actions = $this->add($this->actions, $hook, $component, $callback, $priority, $accepted_args);
    }

    /**
     * 添加新过滤器到集合
     *
     * @since    1.0.5
     * @param    string    $hook             钩子名称
     * @param    object    $component        钩子所在的组件
     * @param    string    $callback         要执行的回调函数
     * @param    int       $priority         过滤器执行的优先级
     * @param    int       $accepted_args    回调接受的参数数量
     */
    public function add_filter($hook, $component, $callback, $priority = 10, $accepted_args = 1) {
        $this->filters = $this->add($this->filters, $hook, $component, $callback, $priority, $accepted_args);
    }

    /**
     * 向集合添加钩子的辅助函数
     *
     * @since    1.0.5
     * @access   private
     * @param    array     $hooks            动作或过滤器的集合
     * @param    string    $hook             钩子名称
     * @param    object    $component        钩子所在的组件
     * @param    string    $callback         要执行的回调函数
     * @param    int       $priority         钩子执行的优先级
     * @param    int       $accepted_args    回调接受的参数数量
     * @return   array                       钩子集合
     */
    private function add($hooks, $hook, $component, $callback, $priority, $accepted_args) {
        $hooks[] = array(
            'hook'          => $hook,
            'component'     => $component,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args
        );

        return $hooks;
    }

    /**
     * 注册所有的钩子
     *
     * @since    1.0.5
     */
    public function run() {
        // 注册所有的动作
        foreach ($this->actions as $hook) {
            add_action(
                $hook['hook'],
                array($hook['component'], $hook['callback']),
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        // 注册所有的过滤器
        foreach ($this->filters as $hook) {
            add_filter(
                $hook['hook'],
                array($hook['component'], $hook['callback']),
                $hook['priority'],
                $hook['accepted_args']
            );
        }
    }
} 