<?php
declare(strict_types=1);

namespace NTWP\Services;

/**
 * Safely persists expiring Notion file, PDF, video, and audio URLs.
 */
class Attachment_Processor {
	public const MAX_ATTACHMENT_SIZE = 50 * 1024 * 1024;
	private const REQUEST_TIMEOUT     = 30;

	/** @var array<string, array<string, string>> */
	private const ALLOWED_MIMES = array(
		'file'  => array(
			'application/pdf' => 'pdf',
			'text/plain'      => 'txt',
			'text/csv'        => 'csv',
			'application/zip' => 'zip',
			'application/msword' => 'doc',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
			'application/vnd.ms-excel' => 'xls',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
			'application/vnd.ms-powerpoint' => 'ppt',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
		),
		'pdf'   => array(
			'application/pdf' => 'pdf',
		),
		'video' => array(
			'video/mp4'  => 'mp4',
			'video/webm' => 'webm',
			'video/ogg'  => 'ogv',
		),
		'audio' => array(
			'audio/mpeg' => 'mp3',
			'audio/mp4'  => 'm4a',
			'audio/ogg'  => 'ogg',
			'audio/wav'  => 'wav',
			'audio/wave' => 'wav',
			'audio/x-wav' => 'wav',
		),
	);

	/**
	 * Return a durable local URL when a temporary Notion attachment can be
	 * downloaded safely; otherwise preserve the original URL.
	 */
	public static function resolve_url( string $url, string $name, string $caption, string $kind ): string {
		if ( '' === $url || ! isset( self::ALLOWED_MIMES[ $kind ] ) || ! self::is_notion_temp_url( $url ) ) {
			return $url;
		}

		$hash       = md5( $kind . ':' . self::get_stable_url( $url ) );
		$attachment = self::find_existing_attachment( $hash );
		if ( $attachment > 0 ) {
			$local_url = wp_get_attachment_url( $attachment );
			return is_string( $local_url ) && '' !== $local_url ? $local_url : $url;
		}

		if ( ! self::is_safe_download_url( $url ) ) {
			return $url;
		}

		$args = array(
			'timeout'              => self::REQUEST_TIMEOUT,
			'redirection'          => 3,
			'limit_response_size'  => self::MAX_ATTACHMENT_SIZE + 1,
			'reject_unsafe_urls'   => true,
			'user-agent'           => 'Notion-to-WordPress/' . ( defined( 'NOTION_TO_WORDPRESS_VERSION' ) ? NOTION_TO_WORDPRESS_VERSION : '2.0.0' ),
		);
		$is_integration = defined( 'NTWP_INTEGRATION_TEST' ) && NTWP_INTEGRATION_TEST;
		$response       = $is_integration ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $url;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_ATTACHMENT_SIZE ) {
			return $url;
		}

		$content_type = self::normalize_content_type( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$extension    = self::ALLOWED_MIMES[ $kind ][ $content_type ] ?? '';
		if ( '' === $extension ) {
			return $url;
		}

		$filename = self::build_filename( $name, $url, $hash, $extension );
		$upload   = wp_upload_bits( $filename, null, $body );
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['url'] ) ) {
			return $url;
		}

		$checked_type = wp_check_filetype_and_ext( $upload['file'], $filename );
		$checked_mime = self::normalize_content_type( (string) $checked_type['type'] );
		$checked_ext  = self::ALLOWED_MIMES[ $kind ][ $checked_mime ] ?? '';
		if ( '' === $checked_ext || $extension !== $checked_ext ) {
			wp_delete_file( $upload['file'] );
			return $url;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $content_type,
				'post_title'     => '' !== $name ? sanitize_text_field( $name ) : sanitize_file_name( $filename ),
				'post_content'   => '',
				'post_excerpt'   => wp_strip_all_tags( $caption ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			return $url;
		}

		update_post_meta( $attachment_id, '_notion_attachment_hash', $hash );
		update_post_meta( $attachment_id, '_notion_attachment_kind', $kind );
		update_post_meta( $attachment_id, '_notion_original_url', esc_url_raw( $url ) );
		update_post_meta( $attachment_id, '_notion_base_url', esc_url_raw( self::get_stable_url( $url ) ) );

		$local_url = wp_get_attachment_url( $attachment_id );
		return is_string( $local_url ) && '' !== $local_url ? $local_url : $upload['url'];
	}

	private static function find_existing_attachment( string $hash ): int {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				'_notion_attachment_hash',
				$hash
			)
		);
		return $id ? (int) $id : 0;
	}

	private static function build_filename( string $name, string $url, string $hash, string $extension ): string {
		$candidate = '' !== $name ? $name : basename( (string) parse_url( $url, PHP_URL_PATH ) );
		$candidate = sanitize_file_name( $candidate );
		$stem      = pathinfo( $candidate, PATHINFO_FILENAME );
		$stem      = '' !== $stem ? $stem : 'notion-attachment-' . $hash;
		return $stem . '.' . $extension;
	}

	private static function normalize_content_type( string $content_type ): string {
		$content_type = strtolower( trim( $content_type ) );
		return trim( explode( ';', $content_type )[0] );
	}

	private static function is_notion_temp_url( string $url ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( defined( 'NTWP_INTEGRATION_TEST' ) && NTWP_INTEGRATION_TEST && false !== strpos( $url, 'X-Amz-Algorithm' ) ) {
			return true;
		}
		return 'secure.notion-static.com' === $host ||
			str_ends_with( $host, '.notion-static.com' ) ||
			( str_ends_with( $host, '.amazonaws.com' ) && false !== strpos( $host, 'prod-files-secure' ) );
	}

	private static function is_safe_download_url( string $url ): bool {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $scheme || '' === $host ) {
			return false;
		}

		$is_integration = defined( 'NTWP_INTEGRATION_TEST' ) && NTWP_INTEGRATION_TEST;
		$is_loopback    = 'localhost' === $host || '::1' === $host || str_starts_with( $host, '127.' );
		if ( $is_integration && $is_loopback && in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return true;
		}
		if ( 'https' !== $scheme || $is_loopback || str_ends_with( $host, '.localhost' ) ) {
			return false;
		}
		if ( function_exists( 'wp_http_validate_url' ) && false === wp_http_validate_url( $url ) ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host );
		}
		$addresses = gethostbynamel( $host );
		if ( false === $addresses || array() === $addresses ) {
			return false;
		}
		foreach ( $addresses as $address ) {
			if ( ! self::is_public_ip( $address ) ) {
				return false;
			}
		}
		return true;
	}

	private static function is_public_ip( string $address ): bool {
		return false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	private static function get_stable_url( string $url ): string {
		$base_url = strtok( $url, '?' );
		return is_string( $base_url ) && '' !== $base_url ? $base_url : $url;
	}
}
