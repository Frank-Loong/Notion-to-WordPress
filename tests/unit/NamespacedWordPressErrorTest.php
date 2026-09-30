<?php
declare(strict_types=1);

use NTWP\Framework\Autoloader;
use NTWP\Handlers\Integrator;

final class NamespacedWordPressErrorTest extends \PHPUnit\Framework\TestCase {
    protected function setUp(): void {
        Autoloader::register( dirname( __DIR__, 2 ) );
    }

    public function test_integrator_returns_the_global_wordpress_error_class(): void {
        $result = Integrator::validate_post_data( [ 'post_type' => 'not-a-wordpress-post-type' ] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_post_type', $result->get_error_code() );
    }
}
