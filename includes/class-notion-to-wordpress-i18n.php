<?php
declare(strict_types=1);

/**
 * 国际化 (i18n) 类。
 * 负责加载插件的文本域 (text domain)，并根据用户设置覆盖默认语言。
 * @since      1.0.8
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

class Notion_To_WordPress_i18n {

    /**
     * 标记textdomain是否已加载，避免重复日志
     *
     * @since    1.1.0
     * @access   private
     * @var      bool
     */
    private static $textdomain_loaded = false;

    /**
     * 标记是否已记录过locale信息，避免重复日志
     *
     * @since    1.8.1
     * @access   private
     * @var      bool
     */
    private static $locale_logged = false;

    /**
     * 加载插件的文本域
     *
     * @since    1.0.5
     */
    public function load_plugin_textdomain() {
        // 如果已经加载过，直接返回，避免重复处理
        if (self::$textdomain_loaded) {
            return;
        }

        load_plugin_textdomain(
            'notion-to-wordpress',
            false,
            dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
        );

        self::$textdomain_loaded = true;
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
                Notion_To_WordPress_Helper::debug_log('Overriding locale to: ' . $plugin_language . ' for domain: ' . $domain, 'Notion i18n');
                return $plugin_language;
            }
        }
        return $locale;
    }

    /**
     * 强制重新加载指定语言的翻译文件
     *
     * @since 1.1.0
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
                Notion_To_WordPress_Helper::debug_log('Force loaded ' . $plugin_language . ' translations from: ' . $mo_file, 'Notion i18n');
            } else {
                Notion_To_WordPress_Helper::error_log('Translation file not found: ' . $mo_file, 'Notion i18n');
            }
        }
    }

    /**
     * 拦截翻译函数调用，根据用户选择返回对应语言
     *
     * @since 1.1.0
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
                // 返回英文翻译；若翻译不存在则保持原文
                return $translation;
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