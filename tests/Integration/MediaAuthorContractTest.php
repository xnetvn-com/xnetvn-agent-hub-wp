<?php
/**
 * Human media author attribution and stored HTML boundary contracts.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaOperations;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaPack;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaSchemas;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Proves attribution stays machine-only while human media writes stay filtered. */
final class MediaAuthorContractTest extends WP_UnitTestCase {

	/** Human editor identity.
	 *
	 * @var int
	 */
	private int $editor;

	/** Live site settings.
	 *
	 * @var WpSiteSettings
	 */
	private WpSiteSettings $settings;

	/**
	 * Media tools keyed by canonical name.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $tools = array();

	/** Direct human handler.
	 *
	 * @var MediaOperations
	 */
	private MediaOperations $operations;

	/**
	 * Attachments created by this test.
	 *
	 * @var array<int>
	 */
	private array $attachments = array();

	/** Build a real REST server and reproduce an unfiltered_html request. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$this->settings = new WpSiteSettings();
		$this->editor   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user           = get_userdata( $this->editor );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'unfiltered_html' );
		wp_set_current_user( $this->editor );
		// Reproduce the request state core prepares for an unfiltered_html owner.
		kses_remove_filters();
		foreach ( ( new MediaPack( new DefinitionFactory(), $this->settings ) )->definitions() as $tool ) {
			$this->tools[ $tool->descriptor()->name ] = $tool;
		}
		$this->operations = new MediaOperations( $this->settings );
	}

	/** Remove synthetic media and restore the shared core state. */
	public function tear_down(): void {
		foreach ( array_reverse( $this->attachments ) as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachments = array();
		wp_set_current_user( 0 );
		kses_init_filters();
		parent::tear_down();
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
	 * Execute a schema-checked request through final output checks.
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

	/**
	 * Count persisted attachments without the core counts cache.
	 *
	 * Core does not invalidate the counts cache for attachment inserts, so this
	 * fixture queries the rows it owns instead.
	 *
	 * @return int
	 */
	private function attachment_count(): int {
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
	 * Snapshot the content_save_pre callback identities.
	 *
	 * The editorial boundary installs closures, so a named-callback check would
	 * miss a leaked closure. Core stores one identity key per callback and
	 * priority, which this fixture compares exactly.
	 *
	 * @return array<int,string> Callback identities in registration order.
	 */
	private function content_save_pre_snapshot(): array {
		global $wp_filter;
		$hook = $wp_filter['content_save_pre'] ?? null;
		if ( ! $hook instanceof \WP_Hook ) {
			return array();
		}
		$snapshot = array();
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( array_keys( $callbacks ) as $identity ) {
				$snapshot[] = $priority . ':' . $identity;
			}
		}

		return $snapshot;
	}

	/**
	 * Assert the raw handler refusal contract.
	 *
	 * @param mixed $result Handler result.
	 */
	private function assert_raw_refusal( mixed $result ): void {
		self::assertWPError( $result );
		self::assertSame( 'rest_invalid_param', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 400, $data['status'] );
	}

	/**
	 * Assert a stored plain-text field lost its markup.
	 *
	 * @param string $value   Stored value.
	 * @param string $allowed Benign text that must survive.
	 */
	private function assert_plain_text( string $value, string $allowed ): void {
		self::assertStringNotContainsString( '<', $value );
		self::assertStringNotContainsString( '>', $value );
		self::assertStringContainsString( $allowed, $value );
	}

	/**
	 * Assert a stored rich field lost executable HTML.
	 *
	 * @param string $value   Stored value.
	 * @param string $allowed Benign markup that must survive.
	 */
	private function assert_safe_html( string $value, string $allowed ): void {
		self::assertStringNotContainsString( '<script', $value );
		self::assertStringNotContainsString( 'onerror', $value );
		self::assertStringNotContainsString( 'onload', $value );
		self::assertStringNotContainsString( 'javascript:', $value );
		self::assertStringContainsString( $allowed, $value );
	}

	/** Only the two upload schemas carry attribution; human writes never do. */
	public function test_media_definitions_expose_author_only_on_uploads(): void {
		foreach ( array( 'upload-media-from-url', 'upload-media-from-data' ) as $name ) {
			$schema = $this->tools[ 'xnetvn/' . $name ]->input_schema();
			self::assertSame( 'object', $schema['type'], $name );
			self::assertFalse( $schema['additionalProperties'], $name );
			self::assertArrayHasKey( 'author', $schema['properties'], $name );
			self::assertSame(
				array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				$schema['properties']['author'],
				$name
			);
			self::assertNotContains( 'author', $schema['required'], $name );
		}
		$update = $this->tools['xnetvn/update-media']->input_schema();
		self::assertSame( 'object', $update['type'] );
		self::assertFalse( $update['additionalProperties'] );
		self::assertArrayNotHasKey( 'author', $update['properties'] );
		self::assertArrayNotHasKey( 'author', MediaSchemas::changes() );
	}

	/** A human upload without author keeps actor-derived ownership. */
	public function test_human_upload_without_author_keeps_actor_ownership(): void {
		$media = $this->call( 'upload-media-from-data', $this->fixture() );
		self::assertIsArray( $media, is_wp_error( $media ) ? $media->get_error_message() : '' );
		$id                  = (int) $media['id'];
		$this->attachments[] = $id;
		$stored              = get_post( $id );
		self::assertInstanceOf( \WP_Post::class, $stored );
		self::assertSame( $this->editor, (int) $stored->post_author );
		self::assertSame( $this->editor, $media['author'] );
	}

	/** An explicit author is refused before any fetch, decode or write. */
	public function test_explicit_author_is_refused_before_any_side_effect(): void {
		$before = $this->attachment_count();
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$data   = array_merge( $this->fixture(), array( 'author' => $other ) );
		$this->assert_raw_refusal( $this->operations->execute( 'upload-media-from-data', $data ) );
		self::assertSame( $before, $this->attachment_count() );
		$pack_data = $this->call( 'upload-media-from-data', $data );
		self::assertWPError( $pack_data );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $pack_data->get_error_code() );
		self::assertSame( $before, $this->attachment_count() );
		$remote = array(
			'url'    => 'https://example.test/image.png',
			'author' => $other,
		);
		$this->assert_raw_refusal( $this->operations->execute( 'upload-media-from-url', $remote ) );
		$pack_remote = $this->call( 'upload-media-from-url', $remote );
		self::assertWPError( $pack_remote );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $pack_remote->get_error_code() );
		self::assertSame( $before, $this->attachment_count() );

		$media = $this->call( 'upload-media-from-data', $this->fixture() );
		self::assertIsArray( $media, is_wp_error( $media ) ? $media->get_error_message() : '' );
		$id                  = (int) $media['id'];
		$this->attachments[] = $id;
		$update              = array(
			'id'     => $id,
			'author' => $other,
		);
		$schema_error        = rest_validate_value_from_schema( $update, $this->tools['xnetvn/update-media']->input_schema(), 'input' );
		self::assertWPError( $schema_error );
		self::assertSame( 'rest_additional_properties_forbidden', $schema_error->get_error_code() );
		$ability = wp_get_ability( 'xnetvn/update-media' );
		self::assertInstanceOf( \WP_Ability::class, $ability );
		$ability_error = $ability->execute( $update );
		self::assertWPError( $ability_error );
		self::assertSame( 'ability_invalid_input', $ability_error->get_error_code() );
		$pack_update = $this->call( 'update-media', $update );
		self::assertWPError( $pack_update );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $pack_update->get_error_code() );
		$this->assert_raw_refusal( $this->operations->execute( 'update-media', $update ) );
		$stored = get_post( $id );
		self::assertInstanceOf( \WP_Post::class, $stored );
		self::assertSame( $this->editor, (int) $stored->post_author );
		self::assertSame( $before + 1, $this->attachment_count() );

		// The update dispatch forwards only the editable changes() keys.
		$unlisted = $this->operations->execute(
			'update-media',
			array(
				'id'        => $id,
				'mime_type' => 'image/jpeg',
			)
		);
		self::assertWPError( $unlisted );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $unlisted->get_error_code() );
		self::assertSame( 'image/png', get_post_mime_type( $id ) );
	}

	/** An unfiltered_html editor persists safe metadata and keeps the core state intact. */
	public function test_unfiltered_html_editor_persists_safe_media_metadata(): void {
		$hook_before = $this->content_save_pre_snapshot();
		// Core denies unfiltered_html to a non-Super-Admin on Multisite (wp-includes/capabilities.php:595-602).
		$cap_before = current_user_can( 'unfiltered_html' );
		self::assertSame( ! is_multisite(), $cap_before );
		$created = $this->operations->execute(
			'upload-media-from-data',
			array_merge(
				$this->fixture(),
				array(
					'title'       => '<b>Unsafe</b> title<script>alert(1)</script>',
					'alt_text'    => '<b>Unsafe</b> alt<script>alert(1)</script>',
					'caption'     => '<p>Safe caption</p><script>alert(1)</script><img src="x" onerror="alert(1)">',
					'description' => '<p>Safe description</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
				)
			)
		);
		self::assertInstanceOf( MutationResult::class, $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$id                  = $created->object_id;
		$this->attachments[] = $id;
		self::assertTrue( $created->created );
		$stored = get_post( $id );
		self::assertInstanceOf( \WP_Post::class, $stored );
		$this->assert_plain_text( $stored->post_title, 'title' );
		self::assertSame( 'Unsafe alt', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assert_safe_html( (string) $stored->post_excerpt, '<p>Safe caption</p>' );
		$this->assert_safe_html( (string) $stored->post_content, '<p>Safe description</p>' );
		self::assertSame( $this->editor, (int) $stored->post_author );
		self::assertSame( $cap_before, current_user_can( 'unfiltered_html' ) );
		self::assertSame( $hook_before, $this->content_save_pre_snapshot() );

		$updated = $this->operations->execute(
			'update-media',
			array(
				'id'          => $id,
				'title'       => '<b>Edited</b> title<script>alert(2)</script>',
				'alt_text'    => '<b>Edited</b> alt<script>alert(2)</script>',
				'caption'     => '<p>Edited caption</p><script>alert(2)</script><img src="x" onerror="alert(2)">',
				'description' => '<p>Edited description</p><svg onload="alert(2)"></svg><a href="javascript:alert(2)">x</a>',
			)
		);
		self::assertInstanceOf( MutationResult::class, $updated, is_wp_error( $updated ) ? $updated->get_error_message() : '' );
		self::assertSame( $id, $updated->object_id );
		self::assertFalse( $updated->created );
		$stored = get_post( $id );
		self::assertInstanceOf( \WP_Post::class, $stored );
		$this->assert_plain_text( $stored->post_title, 'Edited title' );
		self::assertSame( 'Edited alt', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assert_safe_html( (string) $stored->post_excerpt, '<p>Edited caption</p>' );
		$this->assert_safe_html( (string) $stored->post_content, '<p>Edited description</p>' );
		self::assertSame( $this->editor, (int) $stored->post_author );
		self::assertSame( $cap_before, current_user_can( 'unfiltered_html' ) );
		self::assertSame( $hook_before, $this->content_save_pre_snapshot() );
	}
}
