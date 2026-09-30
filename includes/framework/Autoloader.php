<?php
/**
 * Runtime PSR-4 autoloader for the packaged plugin.
 *
 * @package Notion_To_WordPress
 */

declare(strict_types=1);

namespace NTWP\Framework;

/**
 * Minimal runtime PSR-4 loader for plugin classes packaged without vendor/.
 *
 * @since 2.0.0
 */
final class Autoloader {

	/**
	 * Absolute path to the plugin root.
	 *
	 * @var string
	 */
	private static $plugin_path = '';

	/**
	 * Maps the supported namespace roots to their plugin directories.
	 *
	 * @var array<string, string>
	 */
	private const ROOTS = array(
		'API'       => 'includes/api',
		'Contracts' => 'includes/contracts',
		'Core'      => 'includes/core',
		'Framework' => 'includes/framework',
		'Handlers'  => 'includes/handlers',
		'Services'  => 'includes/services',
		'Utils'     => 'includes/utils',
	);

	/**
	 * Registers this loader for classes in the plugin namespace.
	 *
	 * @param string $plugin_path Absolute path to the plugin root.
	 * @return void
	 */
	public static function register( string $plugin_path ): void {
		self::$plugin_path = rtrim( $plugin_path, '/\\' ) . DIRECTORY_SEPARATOR;
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Loads an NTWP class when it maps to a supported plugin directory.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		$prefix = 'NTWP\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		if ( ! isset( self::ROOTS[ $parts[0] ] ) ) {
			return;
		}

		$class_parts = array_slice( $parts, 1 );
		foreach ( $class_parts as $part ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $part ) ) {
				return;
			}
		}

		if ( empty( $class_parts ) ) {
			return;
		}

		$file = self::$plugin_path . self::ROOTS[ $parts[0] ] . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $class_parts ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
