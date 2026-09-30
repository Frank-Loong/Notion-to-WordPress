<?php

use NTWP\Framework\Autoloader;

class AutoloaderTest extends \PHPUnit\Framework\TestCase {

	public function test_loads_plugin_classes_without_composer_vendor(): void {
		Autoloader::register( dirname( __DIR__, 2 ) );

		$this->assertTrue( class_exists( \NTWP\Framework\Loader::class ) );
		$this->assertTrue( class_exists( \NTWP\Services\Database_Config::class ) );
	}

	public function test_ignores_unknown_or_unsafe_namespaces(): void {
		Autoloader::load( 'External\\Example' );
		Autoloader::load( 'NTWP\\Core\\..\\outside' );

		$this->assertFalse( class_exists( 'External\\Example', false ) );
	}
}
