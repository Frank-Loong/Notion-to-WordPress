<?php
/**
 * Image processing reliability and fallback tests.
 */

declare(strict_types=1);

use NTWP\Services\Image_Processor;
use PHPUnit\Framework\TestCase;

final class ImageProcessorReliabilityTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['__wp_options']['notion_to_wordpress_options'] = array(
			'enable_performance_mode' => 1,
		);
		Image_Processor::reset();
	}

	protected function tearDown(): void {
		Image_Processor::reset();
	}

	public function test_external_image_is_rendered_without_entering_download_queue(): void {
		$state_id = Image_Processor::create_state( 'external-image' );
		Image_Processor::enable_async_image_mode( $state_id );

		$html   = Image_Processor::collect_image_for_download( 'https://example.com/image.png', 'Caption <safe>', $state_id );
		$status = Image_Processor::get_queue_status( $state_id );

		$this->assertSame( 0, $status['pending_images'] );
		$this->assertStringContainsString( '<figure class="notion-image">', $html );
		$this->assertStringContainsString( 'src="https://example.com/image.png"', $html );
		$this->assertStringContainsString( 'alt="Caption &lt;safe&gt;"', $html );
		$this->assertStringContainsString( '<figcaption>Caption &lt;safe&gt;</figcaption>', $html );
	}

	public function test_rotating_signatures_share_one_stable_hash(): void {
		$hash_method = new ReflectionMethod( Image_Processor::class, 'get_stable_image_hash' );
		$hash_method->setAccessible( true );
		$first       = $hash_method->invoke( null, 'https://prod-files-secure.example/image.png?X-Amz-Signature=one' );
		$second      = $hash_method->invoke( null, 'https://prod-files-secure.example/image.png?X-Amz-Signature=two' );

		$this->assertSame( $first, $second );
		$this->assertSame( md5( 'https://prod-files-secure.example/image.png' ), $first );
	}

	public function test_failed_temporary_image_download_degrades_to_safe_external_markup(): void {
		$state_id = Image_Processor::create_state( 'failed-image' );
		Image_Processor::enable_async_image_mode( $state_id );

		$placeholder = Image_Processor::collect_image_for_download(
			'https://prod-files-secure.s3.us-west-2.amazonaws.com/missing.png?X-Amz-Signature=expired',
			'Preserved caption',
			$state_id
		);
		$status      = Image_Processor::get_queue_status( $state_id );
		$html        = Image_Processor::replace_image_placeholders( '<div>' . $placeholder . '</div>', $state_id );

		$this->assertSame( 1, $status['pending_images'] );
		$this->assertStringNotContainsString( $placeholder, $html );
		$this->assertStringNotContainsString( '图片下载失败', $html );
		$this->assertStringContainsString( 'src="https://prod-files-secure.s3.us-west-2.amazonaws.com/missing.png?X-Amz-Signature=expired"', $html );
		$this->assertStringContainsString( '<figcaption>Preserved caption</figcaption>', $html );
	}

	public function test_duplicate_temporary_urls_are_queued_but_share_the_same_base_url(): void {
		$state_id = Image_Processor::create_state( 'duplicate-image' );
		Image_Processor::enable_async_image_mode( $state_id );

		Image_Processor::collect_image_for_download( 'https://prod-files-secure.s3.us-west-2.amazonaws.com/shared.png?X-Amz-Signature=one', '', $state_id );
		Image_Processor::collect_image_for_download( 'https://prod-files-secure.s3.us-west-2.amazonaws.com/shared.png?X-Amz-Signature=two', '', $state_id );
		$status = Image_Processor::get_queue_status( $state_id );

		$this->assertSame( 2, $status['pending_images'] );
		$this->assertSame( $status['queue_details'][0]['base_url'], $status['queue_details'][1]['base_url'] );
	}

	public function test_temp_url_detection_requires_a_trusted_host(): void {
		$method = new ReflectionMethod( Image_Processor::class, 'is_notion_temp_url' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, 'https://prod-files-secure.s3.us-west-2.amazonaws.com/image.png?X-Amz-Signature=one' ) );
		$this->assertTrue( $method->invoke( null, 'https://secure.notion-static.com/image.png' ) );
		$this->assertFalse( $method->invoke( null, 'https://notion.so.attacker.example/image.png' ) );
		$this->assertFalse( $method->invoke( null, 'https://attacker.example/image.png?X-Amz-Algorithm=AWS4-HMAC-SHA256' ) );
	}

	public function test_download_url_safety_rejects_local_and_insecure_targets(): void {
		$method = new ReflectionMethod( Image_Processor::class, 'is_safe_download_url' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( null, 'http://8.8.8.8/image.png' ) );
		$this->assertFalse( $method->invoke( null, 'https://127.0.0.1/image.png' ) );
		$this->assertFalse( $method->invoke( null, 'https://10.0.0.1/image.png' ) );
		$this->assertFalse( $method->invoke( null, 'https://169.254.169.254/latest/meta-data/' ) );
		$this->assertFalse( $method->invoke( null, 'https://localhost/image.png' ) );
		$this->assertTrue( $method->invoke( null, 'https://8.8.8.8/image.png' ) );
	}

	public function test_only_safe_raster_content_types_are_accepted(): void {
		$method = new ReflectionMethod( Image_Processor::class, 'get_extension_from_content_type' );
		$method->setAccessible( true );

		$this->assertSame( 'jpg', $method->invoke( null, 'image/jpeg; charset=binary' ) );
		$this->assertSame( 'png', $method->invoke( null, 'IMAGE/PNG' ) );
		$this->assertSame( 'avif', $method->invoke( null, 'image/avif' ) );
		$this->assertSame( '', $method->invoke( null, 'image/svg+xml' ) );
		$this->assertSame( '', $method->invoke( null, 'text/html' ) );
	}

	public function test_untrusted_signed_query_is_rendered_but_never_queued(): void {
		$state_id = Image_Processor::create_state( 'untrusted-signed-url' );
		Image_Processor::enable_async_image_mode( $state_id );

		$html   = Image_Processor::collect_image_for_download( 'https://attacker.example/image.png?X-Amz-Algorithm=AWS4-HMAC-SHA256', '', $state_id );
		$status = Image_Processor::get_queue_status( $state_id );

		$this->assertSame( 0, $status['pending_images'] );
		$this->assertStringContainsString( '<img ', $html );
	}
}
