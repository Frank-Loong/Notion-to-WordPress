<?php
declare(strict_types=1);

/**
 * 定义国际化功能
 *
 * @since      1.0.8
 * @package    Notion_To_WordPress
 * @license    GPL-3.0-or-later
 */
// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}

class Notion_To_WordPress_i18n {

    /**
     * 加载插件的文本域
     *
     * @since    1.0.5
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'notion-to-wordpress',
            false,
            dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
        );
    }
} 