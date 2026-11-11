<?php
/**
 * Bounded byte, filename, MIME and image-processing policy.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Media;

use WP_Error;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;

/** Core uploader still enforces its own permissions and upload restrictions. */
final class UploadPolicy {
	/** Reviewed MIME allowlist; user settings may only remove entries. */
	private const ALLOWED = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/pdf', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'video/mp4', 'video/webm' );
	/** Prohibited extension components, including double extensions. */
	private const DENIED = array( 'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'js', 'mjs', 'css', 'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'sh', 'bash', 'pl', 'py', 'rb', 'cgi', 'asp', 'aspx', 'jsp', 'exe', 'dll', 'msi', 'bat', 'cmd', 'com', 'scr', 'jar', 'war', 'apk', 'swf', 'zip', '7z', 'rar', 'tar', 'gz', 'htaccess', 'htpasswd', 'ini', 'config' );
	/**
	 * Bind site restrictions.
	 *
	 * @param SiteSettings $settings Settings.
	 */
	public function __construct( private SiteSettings $settings ) {}
	/**
	 * Current-user MIME intersection, without trusting unfiltered_upload.
	 *
	 * @return array<string>
	 */
	public function allowed(): array {
		$core   = array_values( get_allowed_mime_types() );
		$result = array();
		foreach ( self::ALLOWED as $mime ) {
			if ( ! in_array( $mime, $core, true ) || in_array( $mime, $this->settings->get( 'media_mime_denylist' ), true ) ) {
				continue; }
			try {
				$allowed = apply_filters( 'xnetvn_agent_hub_wp_media_mime_allowed', true, $mime );
			} catch ( \Throwable ) {
				$allowed = false; }
			if ( true === $allowed ) {
				$result[] = $mime; }
		}
		sort( $result, SORT_STRING );
		return $result;
	}
	/**
	 * Decode canonical base64 only after checking its maximum encoded length.
	 *
	 * @param string $encoded Encoded bytes.
	 * @return string|WP_Error
	 */
	public function decode( string $encoded ): string|WP_Error {
		$max = min( (int) $this->settings->get( 'media_data_max_bytes' ), wp_max_upload_size(), 8388608 );
		if ( strlen( $encoded ) > 4 * (int) ceil( $max / 3 ) ) {
			return R::error( 'payload_too_large' ); }
		if ( '' === $encoded || 1 !== preg_match( '~\A(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?\z~D', $encoded ) ) {
			return R::error( 'invalid_params' ); }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Strict bounded media decoding, never executable code.
		$bytes = base64_decode( $encoded, true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Canonical encoding verification of validated media bytes.
		if ( false === $bytes || base64_encode( $bytes ) !== $encoded ) {
			return R::error( 'invalid_params' ); }
		return strlen( $bytes ) > $max ? R::error( 'payload_too_large' ) : $bytes;
	}
	/**
	 * Validate real bytes in a core temporary file, always removed afterwards.
	 *
	 * @param string      $bytes Actual data.
	 * @param string      $filename Requested name.
	 * @param string|null $claimed Optional MIME declaration.
	 * @return array{filename:string,mime:string}|WP_Error
	 */
	public function inspect( string $bytes, string $filename, ?string $claimed = null ): array|WP_Error {
		if ( '' === $bytes || strlen( $bytes ) > min( wp_max_upload_size(), 67108864 ) ) {
			return R::error( 'payload_too_large' ); }
		if ( '' === $filename || strlen( $filename ) > 255 || str_contains( $filename, '..' ) || 1 === preg_match( '~[/\\\\\x00-\x1f\x7f]~', $filename ) ) {
			return R::error( 'file_type_not_allowed' ); }
		foreach ( explode( '.', strtolower( $filename ) ) as $part ) {
			if ( in_array( $part, self::DENIED, true ) ) {
				return R::error( 'file_type_not_allowed' ); }
		}
		$filename = ltrim( sanitize_file_name( $filename ), '.' );
		if ( strlen( pathinfo( $filename, PATHINFO_FILENAME ) ) > 100 ) {
			return R::error( 'file_type_not_allowed' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = wp_tempnam( $filename );
		if ( ! $file ) {
			return R::error( 'internal_error' ); }
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Core-created temporary file, removed in finally.
			if ( strlen( $bytes ) !== file_put_contents( $file, $bytes ) ) {
				return R::error( 'internal_error' ); }
			if ( ! class_exists( \finfo::class ) ) {
				return R::error( 'unsupported_operation' ); }
			$detected = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $file );
			// Equivalent WAV detector label; extension, core policy and caller still use the canonical approved type.
			$actual    = 'audio/x-wav' === $detected ? 'audio/wav' : $detected;
			$extension = wp_check_filetype( $filename, get_allowed_mime_types() );
			$checked   = wp_check_filetype_and_ext( $file, $filename, get_allowed_mime_types() );
			if ( ! is_string( $actual ) || ! in_array( $actual, $this->allowed(), true ) || $actual !== $extension['type'] || $actual !== $checked['type'] || ( null !== $claimed && $actual !== $claimed ) ) {
				return R::error( 'file_type_not_allowed' ); }
			if ( str_starts_with( $actual, 'image/' ) ) {
				$size         = wp_getimagesize( $file );
				$memory_limit = defined( 'WP_MAX_MEMORY_LIMIT' ) ? constant( 'WP_MAX_MEMORY_LIMIT' ) : '0';
				$memory       = is_string( $memory_limit ) ? wp_convert_hr_to_bytes( $memory_limit ) : 0;
				if ( false === $size || $size[0] < 1 || $size[1] < 1 || $size[0] > (int) $this->settings->get( 'media_pixels_max' ) / $size[1] || ( $memory > 0 && $size[0] * $size[1] * 8 > $memory / 2 ) ) {
					return R::error( 'image_too_large' ); }
			}
			return array(
				'filename' => $checked['proper_filename'] ? $checked['proper_filename'] : $filename,
				'mime'     => $actual,
			);
		} finally {
			wp_delete_file( $file ); }
	}
}
