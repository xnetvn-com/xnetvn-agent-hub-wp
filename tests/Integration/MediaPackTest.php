<?php
/**
 * Attachment permissions, byte validation and safe projections.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaPack;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;

/** Uses core raw-body uploads and real persistent attachments. */
final class MediaPackTest extends WP_UnitTestCase {
	/** Tools.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $tools = array();
	/** Settings.
	 *
	 * @var WpSiteSettings
	 */
	private WpSiteSettings $settings;
	/** Real core server and uploader. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->settings = new WpSiteSettings();
		foreach ( ( new MediaPack( new DefinitionFactory(), $this->settings ) )->definitions() as $tool ) {
			$this->tools[ $tool->descriptor()->name ] = $tool; }
	}
	/**
	 * Valid one pixel PNG, generated fixture bytes.
	 *
	 * @return array<string,string> Base64 payload, file name and MIME type.
	 */
	private function fixture(): array {
		return array(
			'filename'    => 'pixel.png',
			'mime_type'   => 'image/png',
			'data_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=',
		);
	}
	/**
	 * Execute a schema-valid request through final output checks.
	 *
	 * @param string              $name Tool.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function call( string $name, array $args = array() ): mixed {
		$tool = $this->tools[ 'xnetvn/' . $name ];
		if ( true !== rest_validate_value_from_schema( $args, $tool->input_schema() ) ) {
			return ReasonMapper::error( 'invalid_params' ); }
		$result = $tool->execute( $args );

		return ( new OutputPipeline( $this->settings ) )->process( $result, $tool->output_schema() );
	}
	/** Seven strict contracts have schema-valid examples. */
	public function test_contract(): void {
		self::assertCount( 7, $this->tools );
		\XNetVN\AgentHubWP\Tests\Contract\PackContractTestCase::assert_pack_contract( new MediaPack( new DefinitionFactory(), $this->settings ) );
	}
	/** Exhausted network quota is reported before decoding or remote fetching. */
	public function test_multisite_exhausted_quota_reason(): void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Multisite upload quota only.' ); }
		$used    = static fn(): int => 2;
		$allowed = static fn(): int => 1;
		add_filter( 'site_option_upload_space_check_disabled', '__return_false' );
		add_filter( 'pre_get_space_used', $used );
		add_filter( 'get_space_allowed', $allowed );
		$this->settings->update( array( 'media_remote_fetch_enabled' => true ) );
		try {
			self::assertFalse( is_upload_space_available() );
			foreach ( array(
				'upload-media-from-data' => $this->fixture(),
				'upload-media-from-url'  => array( 'url' => 'https://127.0.0.1/never' ),
			) as $name => $args ) {
				$result = $this->call( $name, $args );
				self::assertWPError( $result );
				self::assertSame( 'xnetvn_agent_hub_wp_quota_exceeded', $result->get_error_code() );
			}
		} finally {
			remove_filter( 'site_option_upload_space_check_disabled', '__return_false' );
			remove_filter( 'pre_get_space_used', $used );
			remove_filter( 'get_space_allowed', $allowed );
		}
	}
	/** Valid raw-body bytes upload, update and delete via the attachment controller. */
	public function test_upload_lifecycle(): void {
		$seen  = false;
		$watch = static function ( $result, $server, $request ) use ( &$seen ) {
			if ( 'POST' === $request->get_method() && '/wp/v2/media' === $request->get_route() ) {
				self::assertSame( array(), $request->get_file_params() );
				self::assertNotSame( '', $request->get_body() );
				$seen = true;
			} return $result;
		};
		add_filter( 'rest_pre_dispatch', $watch, 10, 3 );
		try {
			$media = $this->call( 'upload-media-from-data', $this->fixture() );
		} finally {
			remove_filter( 'rest_pre_dispatch', $watch ); }
		self::assertIsArray( $media, is_wp_error( $media ) ? $media->get_error_message() : '' );
		self::assertTrue( $seen );
		self::assertSame( get_current_user_id(), $media['author'] );
		self::assertSame( 'image/png', $media['mime_type'] );
		self::assertArrayNotHasKey( 'image_meta', (array) $media['media_details'] );
		$id   = $media['id'];
		$file = get_attached_file( $id );
		self::assertFileExists( $file );
		$updated = $this->call(
			'update-media',
			array(
				'id'       => $id,
				'alt_text' => 'Small pixel',
			)
		);
		self::assertSame( 'Small pixel', $updated['alt_text'] );
		self::assertSame(
			array(
				'id'      => $id,
				'deleted' => true,
			),
			$this->call( 'delete-media', array( 'id' => $id ) )
		);
		self::assertFileDoesNotExist( $file );
	}
	/** Bad base64 and spoofed content never reach core upload. */
	public function test_bad_data_and_filename(): void {
		foreach ( array( array( 'data_base64' => '!!!' ), array( 'data_base64' => 'data:image/png;base64,AAAA' ), array( 'filename' => 'a.php.png' ), array( 'filename' => '../x.png' ), array( 'data_base64' => 'PGh0bWw+bWFsaWNpb3VzPC9odG1sPg==' ), array( 'mime_type' => 'image/jpeg' ) ) as $change ) {
			self::assertWPError( $this->call( 'upload-media-from-data', array_merge( $this->fixture(), $change ) ) ); }
		self::assertSame( 0, (int) wp_count_posts( 'attachment' )->inherit );
	}
	/**
	 * Reject each reviewed unsafe upload with a stable reason before insertion.
	 *
	 * @dataProvider rejected_uploads
	 * @param array<string,mixed> $changes Synthetic unsafe argument changes.
	 * @param string              $reason Expected safe reason.
	 */
	public function test_rejected_upload_reason_matrix( array $changes, string $reason ): void {
		$result = $this->call( 'upload-media-from-data', array_merge( $this->fixture(), $changes ) );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_' . $reason, $result->get_error_code() );
		self::assertSame( 0, (int) wp_count_posts( 'attachment' )->inherit );
	}
	/**
	 * Named MD-03..08 input and byte-spoofing cases.
	 *
	 * @return array<string,array{array<string,mixed>,string}>
	 */
	public function rejected_uploads(): array {
		$invalid = 'invalid_params';
		$type    = 'file_type_not_allowed';
		return array(
			'MD03-invalid-alphabet'  => array( array( 'data_base64' => '!!!' ), $invalid ),
			'MD03-padding'           => array( array( 'data_base64' => 'AAAA=' ), $invalid ),
			'MD03-whitespace'        => array( array( 'data_base64' => 'AA AA' ), $invalid ),
			'MD03-data-prefix'       => array( array( 'data_base64' => 'data:image/png;base64,AAAA' ), $invalid ),
			'MD04-dynamic-limit'     => array( array( 'data_base64' => str_repeat( 'A', 1398108 ) ), 'payload_too_large' ),
			'MD05-mime-mismatch'     => array( array( 'mime_type' => 'image/jpeg' ), $type ),
			'MD06-executable'        => array( array( 'filename' => 'shell.php' ), $type ),
			'MD06-middle-extension'  => array( array( 'filename' => 'a.php.jpg' ), $type ),
			'MD06-last-extension'    => array( array( 'filename' => 'a.jpg.php' ), $type ),
			'MD06-encoded-traversal' => array( array( 'filename' => '..%2f..%2fx.png' ), $type ),
			'MD06-nul'               => array( array( 'filename' => "x\0.png" ), $type ),
			'MD06-length'            => array( array( 'filename' => str_repeat( 'x', 300 ) . '.png' ), $invalid ),
			'MD07-svg'               => array(
				array(
					'filename'  => 'x.svg',
					'mime_type' => 'image/svg+xml',
				),
				$type,
			),
			'MD08-html-bytes'        => array(
				array(
					'filename'    => 'x.jpg',
					'mime_type'   => 'image/jpeg',
					'data_base64' => 'PGh0bWw+bWFsaWNpb3VzPC9odG1sPg==',
				),
				$type,
			),
			'MD08-php-bytes'         => array(
				array(
					'filename'    => 'x.jpg',
					'mime_type'   => 'image/jpeg',
					'data_base64' => 'PD9waHAgZWNobyAnZml4dHVyZSc7',
				),
				$type,
			),
		);
	}
	/** MD-09: a tiny PNG with oversized IHDR is refused before core processing. */
	public function test_pixel_bomb_is_rejected_before_upload(): void {
		$args = $this->fixture();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fixed image fixture, never executable code.
		$png    = base64_decode( $args['data_base64'], true );
		$header = pack( 'NN', 100000, 100000 ) . substr( $png, 24, 5 );
		$png    = substr( $png, 0, 16 ) . $header . pack( 'N', crc32( 'IHDR' . $header ) ) . substr( $png, 33 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fixed synthetic PNG header, never executable code.
		$args['data_base64'] = base64_encode( $png );
		$result              = $this->call( 'upload-media-from-data', $args );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_image_too_large', $result->get_error_code() );
		self::assertSame( 0, (int) wp_count_posts( 'attachment' )->inherit );
	}
	/** Actor-specific limits and default disabled remote fetching are truthful. */
	public function test_limits_and_remote_disabled(): void {
		$limits = $this->call( 'get-media-upload-limits' );
		self::assertIsArray( $limits );
		self::assertFalse( $limits['remote_fetch_enabled'] );
		self::assertSame( 1048576, $limits['data_upload_max_bytes'] );
		self::assertSame( 'xnetvn_agent_hub_wp_remote_fetch_disabled', $this->call( 'upload-media-from-url', array( 'url' => 'https://example.test/image.png' ) )->get_error_code() );
	}
	/** Changing parents and cross-owner writes must pass object permission checks. */
	public function test_object_and_parent_permissions(): void {
		$media = $this->call( 'upload-media-from-data', $this->fixture() );
		self::assertIsArray( $media );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$parent = self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'draft',
			)
		);
		self::assertSame(
			'xnetvn_agent_hub_wp_forbidden',
			$this->call(
				'update-media',
				array(
					'id'   => $media['id'],
					'post' => $parent,
				)
			)->get_error_code()
		);
		wp_set_current_user( $other );
		self::assertSame(
			'xnetvn_agent_hub_wp_forbidden',
			$this->call(
				'update-media',
				array(
					'id'    => $media['id'],
					'title' => 'Denied',
				)
			)->get_error_code()
		);
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $this->call( 'get-media', array( 'id' => $parent ) )->get_error_code() );
	}
	/** MD-11/12/19: raw requires edit rights and Editor writes retain core sanitization. */
	public function test_editor_update_raw_and_html_boundaries(): void {
		$media = $this->call( 'upload-media-from-data', $this->fixture() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$raw = $this->call(
			'get-media',
			array(
				'id'             => $media['id'],
				'content_format' => 'raw',
			)
		);
		self::assertWPError( $raw );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $raw->get_error_code() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$updated = $this->call(
			'update-media',
			array(
				'id'       => $media['id'],
				'alt_text' => '<b>Plain alternate text</b>',
				'caption'  => '<p>Safe caption</p>',
			)
		);
		self::assertIsArray( $updated );
		self::assertSame( 'Plain alternate text', $updated['alt_text'] );
		self::assertStringContainsString( 'Safe caption', $updated['caption'] );
		self::assertSame( 'Plain alternate text', get_post_meta( $media['id'], '_wp_attachment_image_alt', true ) );
		self::assertIsArray(
			$this->call(
				'get-media',
				array(
					'id'             => $media['id'],
					'content_format' => 'raw',
				)
			)
		);
	}
	/** MD-13: reviewed metadata updates cannot replace file properties or reassign ownership. */
	public function test_update_rejects_unreviewed_fields(): void {
		$media = $this->call( 'upload-media-from-data', $this->fixture() );
		foreach ( array(
			'source_url' => 'https://example.test/other.png',
			'guid'       => 'fixture',
			'mime_type'  => 'image/jpeg',
			'author'     => get_current_user_id(),
		) as $field => $value ) {
			$result = $this->call(
				'update-media',
				array(
					'id'   => $media['id'],
					$field => $value,
				)
			);
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		}
		self::assertSame( 'image/png', get_post_mime_type( $media['id'] ) );
	}
	/** MD-14/34: revisions and attachments are invalid parents on both upload and update. */
	public function test_parent_type_restrictions_on_upload_and_update(): void {
		$media    = $this->call( 'upload-media-from-data', $this->fixture() );
		$post     = self::factory()->post->create(
			array(
				'post_author' => get_current_user_id(),
				'post_status' => 'draft',
			)
		);
		$revision = _wp_put_post_revision( $post );
		self::assertIsInt( $revision );
		foreach ( array( $media['id'], $revision ) as $parent ) {
			foreach ( array(
				'upload-media-from-data' => array_merge( $this->fixture(), array( 'post' => $parent ) ),
				'update-media'           => array(
					'id'   => $media['id'],
					'post' => $parent,
				),
			) as $name => $args ) {
				$result = $this->call( $name, $args );
				self::assertWPError( $result );
				self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
			}
		}
		self::assertSame( 0, get_post( $media['id'] )->post_parent );
	}
	/** MD-15/17: permanent deletion removes original/sub-sizes and featured references. */
	public function test_delete_generated_sizes_and_featured_reference(): void {
		self::assertTrue( function_exists( 'imagecreatetruecolor' ), 'The lab image fixture requires GD.' );
		$image = imagecreatetruecolor( 1200, 1200 );
		ob_start();
		imagepng( $image );
		$bytes = ob_get_clean();
		imagedestroy( $image );
		self::assertIsString( $bytes );
		$args = $this->fixture();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Generated synthetic image, never executable code.
		$args['data_base64'] = base64_encode( $bytes );
		$media               = $this->call( 'upload-media-from-data', $args );
		self::assertIsArray( $media );
		$original = get_attached_file( $media['id'] );
		$metadata = wp_get_attachment_metadata( $media['id'] );
		self::assertNotEmpty( $metadata['sizes'] );
		$files = array( $original );
		foreach ( $metadata['sizes'] as $size ) {
			$files[] = dirname( $original ) . '/' . $size['file']; }
		foreach ( $files as $file ) {
			self::assertFileExists( $file ); }
		$post = self::factory()->post->create( array( 'post_author' => get_current_user_id() ) );
		self::assertNotFalse( set_post_thumbnail( $post, $media['id'] ) );
		$result = $this->call( 'delete-media', array( 'id' => $media['id'] ) );
		self::assertSame(
			array(
				'id'      => $media['id'],
				'deleted' => true,
			),
			$result
		);
		foreach ( $files as $file ) {
			self::assertFileDoesNotExist( $file ); }
		self::assertSame( 0, get_post_thumbnail_id( $post ) );
	}
	/** E-04 numeric ranges include zero redirects and large byte/pixel limits. */
	public function test_setting_ranges(): void {
		$values   = array(
			'media_remote_max_redirects' => 0,
			'media_remote_max_bytes'     => 67108864,
			'media_data_max_bytes'       => 8388608,
			'media_pixels_max'           => 100000000,
		);
		$settings = $this->settings->update( $values );
		foreach ( $values as $key => $value ) {
			self::assertSame( $value, $settings[ $key ] ); }
		$this->expectException( \InvalidArgumentException::class );
		$this->settings->update( array( 'media_remote_timeout_seconds' => 31 ) );
	}
	/** Unexpected projection exceptions after attachment insertion retain the real ID. */
	public function test_upload_projection_exception_retains_receipt(): void {
		$corrupt = static function ( $response, $handler, $request ) {
			if ( 'POST' === $request->get_method() && '/wp/v2/media' === $request->get_route() && $response instanceof \WP_REST_Response ) {
				$row                  = $response->get_data();
				$row['media_details'] = new \stdClass();
				$response->set_data( $row ); }
			return $response;
		};
		add_filter( 'rest_request_after_callbacks', $corrupt, 10, 3 );
		try {
			$result = $this->call( 'upload-media-from-data', $this->fixture() );
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $result->get_error_code() );
			self::assertSame( 'attachment', get_post_type( $result->get_error_data()['created_id'] ) );
		} finally {
			remove_filter( 'rest_request_after_callbacks', $corrupt, 10 ); }
	}
	/** Spaces in harmless filenames are sanitized by core rather than rejected. */
	public function test_safe_filename_spaces_are_sanitized(): void {
		$args             = $this->fixture();
		$args['filename'] = 'small pixel.png';
		$result           = $this->call( 'upload-media-from-data', $args );
		self::assertIsArray( $result );
		self::assertStringContainsString( 'small-pixel', $result['source_url'] );
	}
	/** The canonical approved WAV type accepts equivalent content-detector aliases. */
	public function test_wav_content_detection_alias(): void {
		$pcm    = str_repeat( "\0", 16 );
		$bytes  = 'RIFF' . pack( 'V', 36 + strlen( $pcm ) ) . 'WAVEfmt ' . pack( 'VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16 ) . 'data' . pack( 'V', strlen( $pcm ) ) . $pcm;
		$result = $this->call(
			'upload-media-from-data',
			array(
				'filename'    => 'tone.wav',
				'mime_type'   => 'audio/wav',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Generated PCM/WAV fixture bytes, never executable code.
				'data_base64' => base64_encode( $bytes ),
			)
		);
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertSame( 'audio/wav', $result['mime_type'] );
	}
}
