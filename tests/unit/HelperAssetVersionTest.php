<?php
declare(strict_types=1);

use NTWP\Utils\Helper;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/utils/Helper.php';

final class HelperAssetVersionTest extends TestCase {
	public function test_existing_asset_includes_file_modified_time(): void {
		$path     = 'assets/dist/js/admin.js';
		$modified = filemtime( Helper::plugin_path( $path ) );

		self::assertNotFalse( $modified );
		self::assertSame(
			'2.0.0-beta.1.' . $modified,
			Helper::asset_version( $path, '2.0.0-beta.1' )
		);
	}

	public function test_missing_asset_falls_back_to_plugin_version(): void {
		self::assertSame(
			'2.0.0-beta.1',
			Helper::asset_version( 'assets/does-not-exist.js', '2.0.0-beta.1' )
		);
	}
}
