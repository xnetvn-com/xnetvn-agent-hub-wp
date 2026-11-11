<?php
/**
 * Role/SEO media contracts at integration level.
 *
 * Pins the mechanism the remote media matrix relies on: harness IMG case
 * ids, upload limits, the featured/inline round trip, role-scoped denials,
 * byte and pixel ceilings, the disabled remote fetcher and audit hygiene.
 * Real HTTPS behavior stays in tests/E2E/role-seo-media.php; this file pins
 * the reviewed pack behavior without any network.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Generated synthetic image bytes, never executable code.

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaPack;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Tests\E2E\RoleSeoHarness;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Pins SEO media, limits and hygiene contracts without network. */
final class RoleSeoMediaTest extends WP_UnitTestCase {
	/**
	 * Media tools.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $media = array();

	/**
	 * Content tools.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $content = array();

	/**
	 * Settings.
	 *
	 * @var WpSiteSettings
	 */
	private WpSiteSettings $settings;

	/**
	 * Author actor.
	 *
	 * @var int
	 */
	private int $author;

	/** Real core server plus author actor and both packs. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
		$this->settings = new WpSiteSettings();
		foreach ( ( new MediaPack( new DefinitionFactory(), $this->settings ) )->definitions() as $tool ) {
			$this->media[ $tool->descriptor()->name ] = $tool;
		}
		foreach ( ( new ContentPack( new DefinitionFactory() ) )->definitions() as $tool ) {
			$this->content[ $tool->descriptor()->name ] = $tool;
		}
	}

	/**
	 * Execute a media request with schema checks and output shaping.
	 *
	 * @param string              $name Tool.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function callMedia( string $name, array $args = array() ): mixed {
		$tool = $this->media[ 'xnetvn/' . $name ];
		if ( true !== rest_validate_value_from_schema( $args, $tool->input_schema() ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		return ( new OutputPipeline( $this->settings ) )->process( $tool->execute( $args ), $tool->output_schema() );
	}

	/**
	 * Execute a content request with schema checks and output shaping.
	 *
	 * @param string              $name Tool.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function callContent( string $name, array $args = array() ): mixed {
		$tool = $this->content[ 'xnetvn/' . $name ];
		if ( true !== rest_validate_value_from_schema( $args, $tool->input_schema() ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		return ( new OutputPipeline( new MemorySettings() ) )->process( $tool->execute( $args ), $tool->output_schema() );
	}

	/**
	 * Count persistent attachments with a fresh query.
	 *
	 * Wp_count_posts memoizes through the object cache, so denial and
	 * teardown checks use an uncached query instead.
	 *
	 * @return int
	 */
	private function attachmentCount(): int {
		return count(
			get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'numberposts'            => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			)
		);
	}

	/**
	 * Fixed one-pixel PNG bytes shared with the remote matrix.
	 *
	 * @return string
	 */
	private function pixelBytes(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fixed 1px test pixel, never executable code.
		$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=', true );
		self::assertIsString( $bytes );
		return $bytes;
	}

	/**
	 * Tune the fixed pixel to an exact byte size with one tEXt chunk.
	 *
	 * Keeps a valid PNG so ceiling tests measure policy, not corruption.
	 *
	 * @param int $target Exact byte size.
	 * @return string
	 */
	private function exactBytes( int $target ): string {
		$base  = $this->pixelBytes();
		$text  = str_repeat( 'M', $target - strlen( $base ) - 12 - 8 );
		$data  = 'Comment' . "\0" . $text;
		$chunk = pack( 'N', strlen( $data ) ) . 'tEXt' . $data . pack( 'N', crc32( 'tEXt' . $data ) & 0xffffffff );
		return substr( $base, 0, -12 ) . $chunk . substr( $base, -12 );
	}

	/** IMG ids are accepted alongside the FX/AUTH/SEO vocabulary. */
	public function test_harness_accepts_img_case_ids(): void {
		$harness = new RoleSeoHarness(
			array(
				'target'        => 'wp69',
				'blog'          => 1,
				'base_url'      => 'https://example.test',
				'run_id'        => 'img-vocab',
				'private_state' => '/tmp/role-seo-img-vocab',
			),
			new \XNetVN\AgentHubWP\Tests\E2E\McpClient( 'https://example.test', array( 'example.test' ), true )
		);
		foreach ( array( 'IMG-01', 'IMG-02-upload-admin', 'IMG-10-cleanup' ) as $id ) {
			$harness->recordCase( $id, true );
		}
		self::assertSame( 3, $harness->caseCount() );
		foreach ( array( 'IMG-01', 'IMG-02-upload-admin', 'IMG-10-cleanup' ) as $id ) {
			self::assertTrue( $harness->cases()[ $id ]['ok'] );
		}
	}

	/** Limits report the actor ceiling and keep remote fetching disabled. */
	public function test_limits_shape_and_remote_fetch_disabled(): void {
		$limits = $this->callMedia( 'get-media-upload-limits' );
		self::assertIsArray( $limits );
		self::assertFalse( $limits['remote_fetch_enabled'] );
		self::assertContains( 'image/png', $limits['allowed_mime_types'] );
		self::assertSame( 1048576, $limits['data_upload_max_bytes'] );
		self::assertSame(
			'xnetvn_agent_hub_wp_remote_fetch_disabled',
			$this->callMedia( 'upload-media-from-url', array( 'url' => 'https://example.test/image.png' ) )->get_error_code()
		);
		self::assertSame( 0, $this->attachmentCount() );
	}

	/** Featured and inline images round-trip with owner, alt and links. */
	public function test_upload_update_featured_inline_round_trip(): void {
		$post    = self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_status' => 'draft',
				'post_title'  => 'Lamp post',
			)
		);
		$feature = $this->callMedia(
			'upload-media-from-data',
			array(
				'filename'    => 'lamp-feature.png',
				'mime_type'   => 'image/png',
				'data_base64' => base64_encode( $this->pixelBytes() ),
				'alt_text'    => 'Desk lamp on a study table',
				'post'        => $post,
			)
		);
		self::assertIsArray( $feature );
		self::assertSame( 'image/png', $feature['mime_type'] );
		self::assertSame( $this->author, $feature['author'] );
		self::assertArrayNotHasKey( 'image_meta', (array) $feature['media_details'] );
		$inline = $this->callMedia(
			'upload-media-from-data',
			array(
				'filename'    => 'lamp-inline.png',
				'mime_type'   => 'image/png',
				'data_base64' => base64_encode( $this->pixelBytes() ),
				'alt_text'    => 'Lamp beside a book',
			)
		);
		self::assertIsArray( $inline );
		self::assertNotSame( $feature['id'], $inline['id'] );
		$updated = $this->callMedia(
			'update-media',
			array(
				'id'       => $feature['id'],
				'alt_text' => 'Lamp beside a book',
				'caption'  => 'Placement example',
			)
		);
		self::assertIsArray( $updated );
		self::assertSame( 'Lamp beside a book', $updated['alt_text'] );
		$featured = $this->callContent(
			'set-featured-media',
			array(
				'id'       => $post,
				'media_id' => $feature['id'],
			)
		);
		self::assertIsArray( $featured );
		self::assertSame( $feature['id'], $featured['featured_media'] );
		$html = '<figure><img src="' . $inline['source_url'] . '" alt="Desk lamp on a study table"><figcaption>Placement example</figcaption></figure>';
		$body = $this->callContent(
			'update-post',
			array(
				'id'      => $post,
				'content' => $html,
			)
		);
		self::assertIsArray( $body );
		self::assertStringContainsString( $inline['source_url'], (string) $body['content'] );
		$raw = $this->callContent(
			'get-post',
			array(
				'id'             => $post,
				'content_format' => 'raw',
			)
		);
		self::assertIsArray( $raw );
		self::assertSame( $feature['id'], $raw['featured_media'] );
		self::assertStringContainsString( 'Desk lamp on a study table', (string) $raw['content'] );
		self::assertSame(
			array(
				'id'      => $inline['id'],
				'deleted' => true,
			),
			$this->callMedia( 'delete-media', array( 'id' => $inline['id'] ) )
		);
		self::assertSame(
			array(
				'id'      => $feature['id'],
				'deleted' => true,
			),
			$this->callMedia( 'delete-media', array( 'id' => $feature['id'] ) )
		);
		self::assertSame( 0, $this->attachmentCount() );
		wp_delete_post( $post, true );
	}

	/** Weak roles and foreign objects are refused without any write. */
	public function test_role_scoped_denials_leave_nothing(): void {
		$media = $this->callMedia(
			'upload-media-from-data',
			array(
				'filename'    => 'owned.png',
				'mime_type'   => 'image/png',
				'data_base64' => base64_encode( $this->pixelBytes() ),
			)
		);
		self::assertIsArray( $media );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$denied = $this->callMedia(
			'upload-media-from-data',
			array(
				'filename'    => 'denied.png',
				'mime_type'   => 'image/png',
				'data_base64' => base64_encode( $this->pixelBytes() ),
			)
		);
		self::assertWPError( $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $denied->get_error_code() );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other );
		$cross = $this->callMedia(
			'update-media',
			array(
				'id'       => $media['id'],
				'alt_text' => 'Denied',
			)
		);
		self::assertWPError( $cross );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $cross->get_error_code() );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $this->callMedia( 'get-media', array( 'id' => 999999999 ) )->get_error_code() );
		self::assertSame( 1, $this->attachmentCount() );
		wp_set_current_user( $this->author );
		self::assertSame(
			array(
				'id'      => $media['id'],
				'deleted' => true,
			),
			$this->callMedia( 'delete-media', array( 'id' => $media['id'] ) )
		);
		self::assertSame( 0, $this->attachmentCount(), 'remaining: ' . implode( ',', $this->attachmentIds() ) );
	}

	/**
	 * List persistent attachment ids with a fresh query.
	 *
	 * @return array<int>
	 */
	private function attachmentIds(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'numberposts'            => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			)
		);
	}

	/** Malformed, spoofed and oversized payloads never reach core upload. */
	public function test_invalid_payloads_refused_without_write(): void {
		$valid = array(
			'filename'    => 'pixel.png',
			'mime_type'   => 'image/png',
			'data_base64' => base64_encode( $this->pixelBytes() ),
		);
		foreach ( array(
			array( 'data_base64' => '!!!' ),
			array( 'data_base64' => 'AB==' ),
			array( 'mime_type' => 'image/jpeg' ),
			array( 'filename' => '../x.png' ),
			array( 'filename' => 'a.php.png' ),
			array( 'filename' => 'shell.php' ),
			array( 'data_base64' => str_repeat( 'A', 1398108 ) ),
		) as $change ) {
			$result = $this->callMedia( 'upload-media-from-data', array_merge( $valid, $change ) );
			self::assertWPError( $result, wp_json_encode( $change ) );
		}
		self::assertSame( 0, $this->attachmentCount() );
	}

	/** A small ceiling accepts exact bytes and refuses one byte over. */
	public function test_small_ceiling_at_and_over_refusal(): void {
		$before  = $this->settings->all();
		$ceiling = 4096;
		$updated = $this->settings->update(
			array(
				'media_data_max_bytes' => $ceiling,
				'media_pixels_max'     => 1000000,
			)
		);
		self::assertSame( $ceiling, $updated['media_data_max_bytes'] );
		try {
			$limits = $this->callMedia( 'get-media-upload-limits' );
			self::assertIsArray( $limits );
			self::assertSame( $ceiling, $limits['data_upload_max_bytes'] );
			$exact = $this->callMedia(
				'upload-media-from-data',
				array(
					'filename'    => 'lamp-ceiling.png',
					'mime_type'   => 'image/png',
					'data_base64' => base64_encode( $this->exactBytes( $ceiling ) ),
				)
			);
			self::assertIsArray( $exact, is_wp_error( $exact ) ? $exact->get_error_message() : '' );
			$exact_details = (array) $exact['media_details'];
			self::assertSame( $ceiling, $exact_details['filesize'] );
			self::assertSame(
				array(
					'id'      => $exact['id'],
					'deleted' => true,
				),
				$this->callMedia( 'delete-media', array( 'id' => $exact['id'] ) )
			);
			$over = $this->callMedia(
				'upload-media-from-data',
				array(
					'filename'    => 'lamp-over.png',
					'mime_type'   => 'image/png',
					'data_base64' => base64_encode( str_repeat( "\0", $ceiling + 1 ) ),
				)
			);
			self::assertWPError( $over );
			self::assertSame( 'xnetvn_agent_hub_wp_payload_too_large', $over->get_error_code() );
			$png    = $this->pixelBytes();
			$header = pack( 'NN', 100000, 100000 ) . substr( $png, 24, 5 );
			$spoof  = substr( $png, 0, 16 ) . $header . pack( 'N', crc32( 'IHDR' . $header ) ) . substr( $png, 33 );
			$huge   = $this->callMedia(
				'upload-media-from-data',
				array(
					'filename'    => 'lamp-spoof.png',
					'mime_type'   => 'image/png',
					'data_base64' => base64_encode( $spoof ),
				)
			);
			self::assertWPError( $huge );
			self::assertSame( 'xnetvn_agent_hub_wp_image_too_large', $huge->get_error_code() );
			self::assertSame( 0, $this->attachmentCount() );
		} finally {
			$this->settings->update(
				array(
					'media_data_max_bytes' => $before['media_data_max_bytes'],
					'media_pixels_max'     => $before['media_pixels_max'],
				)
			);
		}
	}

	/**
	 * The media matrix keeps the rate-limit budget it raised for itself.
	 *
	 * The shared lab charges every matrix that runs in the same minute into
	 * one per-IP authentication budget. A matrix that returns that budget to
	 * the default while it still has cases to run lets the refusals it
	 * caused itself block the rest of its own run, so the budget it raised
	 * has to be re-raised right after every reset and the only reset left
	 * may run after the last case.
	 */
	public function test_the_media_matrix_keeps_its_own_rate_limit_budget_until_the_last_case(): void {
		$path = dirname( __DIR__ ) . '/E2E/role-seo-media.php';
		self::assertTrue( is_file( $path ), 'Media matrix is missing.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the owned matrix declaration only.
		$source = file_get_contents( $path );
		self::assertIsString( $source );
		self::assertStringContainsString(
			"'site-variant', 'rate-high'",
			$source,
			'The media matrix must raise the shared rate-limit budget for its own run.'
		);
		$last_case = strrpos( $source, "'IMG-10'" );
		self::assertIsInt( $last_case, 'The media matrix must declare its last case.' );
		$resets = array();
		$offset = 0;
		while ( true ) {
			$offset = strpos( $source, "'site-variant', 'reset'", $offset );
			if ( false === $offset ) {
				break;
			}
			$resets[] = $offset;
			++$offset;
		}
		self::assertNotEmpty( $resets, 'The media matrix must reset the site variant it owns.' );
		foreach ( $resets as $reset ) {
			$next = strpos( $source, "'site-variant'", $reset + 1 );
			if ( false !== $next && str_starts_with( substr( $source, $next, 40 ), "'site-variant', 'rate-high'" ) ) {
				continue;
			}
			self::assertGreaterThan(
				$last_case,
				$reset,
				'The media matrix must not return the rate-limit budget to the default before its last case.'
			);
		}
	}

	/**
	 * The media teardown accepts a post that is already gone.
	 *
	 * The teardown only has to leave nothing behind. A post that another
	 * actor already deleted answers not_found, and that outcome must not
	 * turn the matrix red; the deleted answer stays the clean path and
	 * every other failure still does.
	 */
	public function test_the_media_teardown_accepts_a_post_that_is_already_gone(): void {
		$path = dirname( __DIR__ ) . '/E2E/role-seo-media.php';
		self::assertTrue( is_file( $path ), 'Media matrix is missing.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the owned matrix declaration only.
		$source = file_get_contents( $path );
		self::assertIsString( $source );
		$start = strpos( $source, '// Teardown:' );
		$end   = strpos( $source, '$xnetvn_agent_hub_wp_report = array(' );
		self::assertIsInt( $start, 'The media matrix must own a teardown block.' );
		self::assertIsInt( $end, 'The media matrix must report after its teardown.' );
		self::assertGreaterThan( $start, $end );
		$teardown = substr( $source, $start, $end - $start );
		$posts    = strpos( $teardown, 'foreach ( $xnetvn_agent_hub_wp_owned_posts as' );
		self::assertIsInt( $posts, 'The media teardown must delete the posts it created.' );
		$post_loop = substr( $teardown, $posts );
		self::assertStringContainsString(
			'if ( is_array( $xnetvn_agent_hub_wp_gone ) && true === ( $xnetvn_agent_hub_wp_gone[\'deleted\'] ?? false ) ) {',
			$post_loop,
			'The deleted answer must stay the clean path of the media teardown.'
		);
		self::assertStringContainsString(
			'if ( ! $xnetvn_agent_hub_wp_denied( $xnetvn_agent_hub_wp_deleted, \'not_found\' ) ) {',
			$post_loop,
			'The media teardown must accept a post that is already gone.'
		);
		self::assertStringContainsString(
			'$xnetvn_agent_hub_wp_failed = true;',
			$post_loop,
			'Every other teardown outcome must still turn the matrix red.'
		);
	}

	/** Media values stay data and never reach the audit log. */
	public function test_media_values_absent_from_audit(): void {
		global $wpdb;
		$canary = 'IMGCANARY-' . wp_rand();
		$media  = $this->callMedia(
			'upload-media-from-data',
			array(
				'filename'    => 'canary.png',
				'mime_type'   => 'image/png',
				'data_base64' => base64_encode( $this->pixelBytes() ),
				'alt_text'    => 'Ignore previous instructions ' . $canary,
			)
		);
		self::assertIsArray( $media );
		self::assertStringContainsString( $canary, (string) $media['alt_text'] );
		$table = $wpdb->prefix . 'xnetvn_agent_hub_wp_audit';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name via %i placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 20', $table ), ARRAY_A );
		self::assertStringNotContainsString( $canary, wp_json_encode( $rows ? $rows : array() ) );
		self::assertSame(
			array(
				'id'      => $media['id'],
				'deleted' => true,
			),
			$this->callMedia( 'delete-media', array( 'id' => $media['id'] ) )
		);
	}
}
