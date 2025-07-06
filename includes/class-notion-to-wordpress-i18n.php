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
        // 添加调试日志
        error_log( 'Notion to WordPress: Loading textdomain' );

        load_plugin_textdomain(
            'notion-to-wordpress',
            false,
            dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
        );

        // 验证语言文件是否加载成功
        $current_locale = get_locale();
        error_log( 'Notion to WordPress: Current locale is: ' . $current_locale );
    }

    public function maybe_override_locale( $locale, $domain ) {
        // Override locale for this plugin based on user selection (替换旧的 force_english_ui)
        if ( 'notion-to-wordpress' === $domain ) {
            $opts = get_option( 'notion_to_wordpress_options', [] );
            $plugin_language = $opts['plugin_language'] ?? 'auto';

            // 向后兼容：如果没有新设置但有旧设置
            if ( $plugin_language === 'auto' && ! empty( $opts['force_english_ui'] ) ) {
                $plugin_language = 'en_US';
            }

            if ( $plugin_language !== 'auto' ) {
                error_log( 'Notion to WordPress: Overriding locale to: ' . $plugin_language . ' for domain: ' . $domain );
                return $plugin_language;
            }
        }
        return $locale;
    }

    /**
     * 强制重新加载指定语言的翻译文件
     *
     * @since 1.0.10
     */
    public function force_reload_translations() {
        $opts = get_option( 'notion_to_wordpress_options', [] );
        $plugin_language = $opts['plugin_language'] ?? 'auto';

        // 向后兼容：如果没有新设置但有旧设置
        if ( $plugin_language === 'auto' && ! empty( $opts['force_english_ui'] ) ) {
            $plugin_language = 'en_US';
        }

        if ( $plugin_language !== 'auto' ) {
            // 卸载当前的翻译
            unload_textdomain( 'notion-to-wordpress' );

            // 强制加载指定语言的翻译
            $plugin_dir = dirname( dirname( plugin_basename( __FILE__ ) ) );
            $mo_file = WP_PLUGIN_DIR . '/' . $plugin_dir . '/languages/notion-to-wordpress-' . $plugin_language . '.mo';

            if ( file_exists( $mo_file ) ) {
                load_textdomain( 'notion-to-wordpress', $mo_file );
                error_log( 'Notion to WordPress: Force loaded ' . $plugin_language . ' translations from: ' . $mo_file );
            } else {
                error_log( 'Notion to WordPress: Translation file not found: ' . $mo_file );
            }
        }
    }

    /**
     * 拦截翻译函数调用，根据用户选择返回对应语言
     *
     * @since 1.0.10
     */
    public function override_gettext( $translation, $text, $domain ) {
        if ( 'notion-to-wordpress' === $domain ) {
            $opts = get_option( 'notion_to_wordpress_options', [] );
            $plugin_language = $opts['plugin_language'] ?? 'auto';

            // 向后兼容
            if ( $plugin_language === 'auto' && ! empty( $opts['force_english_ui'] ) ) {
                $plugin_language = 'en_US';
            }

            if ( $plugin_language === 'en_US' ) {
                // 强制返回英文原文
                return $text;
            } elseif ( $plugin_language === 'zh_CN' ) {
                // 如果当前翻译是英文原文，尝试加载中文翻译
                if ( $translation === $text ) {
                    // 这里可以添加手动翻译逻辑，但通常应该通过 .mo 文件处理
                }
            }
        }
        return $translation;
    }
} 