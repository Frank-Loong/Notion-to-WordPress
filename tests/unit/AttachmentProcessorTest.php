<?php
/**
 * Non-image attachment safety tests.
 */

declare(strict_types=1);

use NTWP\Services\Attachment_Processor;
use PHPUnit\Framework\TestCase;

final class AttachmentProcessorTest extends TestCase {
	public function test_external_url_is_never_downloaded(): void {
		$url = 'https://downloads.example/manual.pdf';

		$this->assertSame( $url, Attachment_Processor::resolve_url( $url, 'Manual.pdf', '', 'pdf' ) );
	}

	public function test_untrusted_signed_url_is_never_downloaded(): void {
		$url = 'https://attacker.example/payload.pdf?X-Amz-Algorithm=AWS4-HMAC-SHA256';

		$this->assertSame( $url, Attachment_Processor::resolve_url( $url, 'Payload.pdf', '', 'pdf' ) );
	}

	public function test_attachment_url_safety_rejects_private_targets(): void {
		$method = new ReflectionMethod( Attachment_Processor::class, 'is_safe_download_url' );

		$this->assertFalse( $method->invoke( null, 'http://8.8.8.8/file.pdf' ) );
		$this->assertFalse( $method->invoke( null, 'https://127.0.0.1/file.pdf' ) );
		$this->assertFalse( $method->invoke( null, 'https://10.0.0.1/file.pdf' ) );
		$this->assertFalse( $method->invoke( null, 'https://169.254.169.254/latest/meta-data/' ) );
		$this->assertTrue( $method->invoke( null, 'https://8.8.8.8/file.pdf' ) );
	}

	public function test_filename_uses_validated_extension_instead_of_source_suffix(): void {
		$method   = new ReflectionMethod( Attachment_Processor::class, 'build_filename' );
		$filename = $method->invoke( null, 'Quarterly Report.php', 'https://example.test/payload.php', 'abc', 'pdf' );

		$this->assertSame( 'Quarterly-Report.pdf', $filename );
	}

	public function test_content_type_normalization_removes_parameters(): void {
		$method = new ReflectionMethod( Attachment_Processor::class, 'normalize_content_type' );

		$this->assertSame( 'application/pdf', $method->invoke( null, 'Application/PDF; charset=binary' ) );
	}
}
