<?php
/**
 * Role/SEO lifecycle contracts at integration level.
 *
 * Pins the mechanism the remote lifecycle matrix relies on: harness LIFE
 * case ids, status transitions by publish capability, cross-owner guards,
 * future scheduling with explicit offsets, trash/restore/delete states,
 * per-object bulk results, duplicate ownership, revision parent binding,
 * optimistic comparison conflicts, partial-failure receipts and the
 * controlled-cron core mechanics. Real HTTPS behavior stays in
 * tests/E2E/role-seo-lifecycle.php; this file pins the reviewed pack
 * behavior without any network.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Tests\E2E\RoleSeoHarness;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Pins SEO lifecycle, conflict and hygiene contracts without network. */
final class RoleSeoLifecycleTest extends WP_UnitTestCase {
	/**
	 * Content tools.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $content = array();

	/**
	 * Editor identity.
	 *
	 * @var int
	 */
	private int $editor;

	/** Real core server plus editor actor and the content pack. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
		foreach ( ( new ContentPack( new DefinitionFactory() ) )->definitions() as $tool ) {
			$this->content[ $tool->descriptor()->name ] = $tool;
		}
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

	/** LIFE ids are accepted alongside the FX/AUTH/SEO/IMG vocabulary. */
	public function test_harness_accepts_life_case_ids(): void {
		$harness = new RoleSeoHarness(
			array(
				'target'        => 'wp69',
				'blog'          => 1,
				'base_url'      => 'https://example.test',
				'run_id'        => 'life-vocab',
				'private_state' => '/tmp/role-seo-life-vocab',
			),
			new \XNetVN\AgentHubWP\Tests\E2E\McpClient( 'https://example.test', array( 'example.test' ), true )
		);
		foreach ( array( 'LIFE-01', 'LIFE-04-cron-due-utc', 'LIFE-09' ) as $id ) {
			$harness->recordCase( $id, true );
		}
		self::assertSame( 3, $harness->caseCount() );
		foreach ( array( 'LIFE-01', 'LIFE-04-cron-due-utc', 'LIFE-09' ) as $id ) {
			self::assertTrue( $harness->cases()[ $id ]['ok'] );
		}
	}

	/** Status transitions follow the publish capability; contributor publish is refused. */
	public function test_status_transitions_follow_publish_capability(): void {
		$post = $this->callContent(
			'create-post',
			array(
				'title'   => 'Lifecycle sample',
				'excerpt' => 'Lifecycle excerpt.',
			)
		);
		self::assertIsArray( $post );
		$id = $post['id'];
		foreach ( array( 'pending', 'publish', 'private', 'draft' ) as $status ) {
			$updated = $this->callContent(
				'update-post',
				array(
					'id'     => $id,
					'status' => $status,
				)
			);
			self::assertIsArray( $updated );
			self::assertSame( $status, $updated['status'] );
			self::assertSame( $this->editor, $updated['author'] );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$own = $this->callContent( 'create-post', array( 'title' => 'Contributor lifecycle' ) );
		self::assertIsArray( $own );
		$pending = $this->callContent(
			'update-post',
			array(
				'id'     => $own['id'],
				'status' => 'pending',
			)
		);
		self::assertIsArray( $pending );
		self::assertSame( 'pending', $pending['status'] );
		foreach ( array( 'publish', 'private', 'future' ) as $denied ) {
			$args = array(
				'id'     => $own['id'],
				'status' => $denied,
			);
			if ( 'future' === $denied ) {
				$args['date'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + 86400 );
			}
			$refused = $this->callContent( 'update-post', $args );
			self::assertWPError( $refused );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $refused->get_error_code() );
		}
		self::assertSame( 'pending', get_post_status( $own['id'] ) );
		wp_set_current_user( $this->editor );
		wp_delete_post( $own['id'], true );
		wp_delete_post( $id, true );
	}

	/** Cross-owner writes need edit_others; published posts need edit_published. */
	public function test_cross_owner_edit_and_published_guard(): void {
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );
		$other    = self::factory()->post->create(
			array(
				'post_author' => $author_b,
				'post_status' => 'publish',
				'post_title'  => 'Author B published',
			)
		);
		$author   = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$denied = $this->callContent(
			'update-post',
			array(
				'id'    => $other,
				'title' => 'Denied',
			)
		);
		self::assertWPError( $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $denied->get_error_code() );
		self::assertSame( 'Author B published', get_post( $other )->post_title );
		self::assertSame( 'publish', get_post_status( $other ) );
		wp_set_current_user( $this->editor );
		$allowed = $this->callContent(
			'update-post',
			array(
				'id'    => $other,
				'title' => 'Editor revision',
			)
		);
		self::assertIsArray( $allowed );
		self::assertSame( 'Editor revision', $allowed['title'] );
		$reverted = $this->callContent(
			'update-post',
			array(
				'id'    => $other,
				'title' => 'Author B published',
			)
		);
		self::assertIsArray( $reverted );
		$contrib = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$draft   = self::factory()->post->create(
			array(
				'post_author' => $contrib,
				'post_status' => 'draft',
				'post_title'  => 'Contributor draft',
			)
		);
		$this->callContent(
			'update-post',
			array(
				'id'     => $draft,
				'status' => 'pending',
			)
		);
		wp_set_current_user( $this->editor );
		$published = $this->callContent(
			'update-post',
			array(
				'id'     => $draft,
				'status' => 'publish',
			)
		);
		self::assertIsArray( $published );
		wp_set_current_user( $contrib );
		$locked = $this->callContent(
			'update-post',
			array(
				'id'    => $draft,
				'title' => 'Denied edit',
			)
		);
		self::assertWPError( $locked );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $locked->get_error_code() );
		self::assertSame( 'publish', get_post_status( $draft ) );
		wp_set_current_user( $this->editor );
		wp_delete_post( $draft, true );
		wp_delete_post( $other, true );
	}

	/** Future posts need an explicit offset date; schema edges write nothing. */
	public function test_future_scheduling_and_schema_edges(): void {
		$before = count(
			get_posts(
				array(
					'post_type'   => 'post',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
		foreach ( array(
			array(
				'create-post',
				array(
					'title'  => 'Missing date future',
					'status' => 'future',
				),
			),
			array(
				'create-post',
				array(
					'title'  => 'Offsetless date future',
					'status' => 'future',
					'date'   => '2026-10-08T12:00:00',
				),
			),
			array(
				'create-post',
				array(
					'title'  => 'Past date future',
					'status' => 'future',
					'date'   => '2000-01-01T00:00:00Z',
				),
			),
		) as $case ) {
			$result = $this->callContent( $case[0], $case[1] );
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		}
		self::assertSame(
			$before,
			count(
				get_posts(
					array(
						'post_type'   => 'post',
						'post_status' => 'any',
						'numberposts' => -1,
						'fields'      => 'ids',
					)
				)
			)
		);
		$future = $this->callContent(
			'create-post',
			array(
				'title'  => 'Future lifecycle',
				'status' => 'future',
				'date'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 2592000 ),
			)
		);
		self::assertIsArray( $future );
		self::assertSame( 'future', $future['status'] );
		self::assertSame( strtotime( $future['date_gmt'] ), strtotime( $future['date'] ) );
		wp_delete_post( $future['id'], true );
	}

	/** Trash, restore and delete keep strict state guards. */
	public function test_trash_restore_delete_states(): void {
		$post = $this->callContent( 'create-post', array( 'title' => 'State lifecycle' ) );
		self::assertIsArray( $post );
		$id = $post['id'];
		self::assertSame( 'trash', $this->callContent( 'trash-post', array( 'id' => $id ) )['status'] );
		$double_trash = $this->callContent( 'trash-post', array( 'id' => $id ) );
		self::assertWPError( $double_trash );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $double_trash->get_error_code() );
		$restored = $this->callContent(
			'restore-post',
			array(
				'id'     => $id,
				'status' => 'publish',
			)
		);
		self::assertIsArray( $restored );
		self::assertSame( 'publish', $restored['status'] );
		$restore_live = $this->callContent( 'restore-post', array( 'id' => $id ) );
		self::assertWPError( $restore_live );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $restore_live->get_error_code() );
		self::assertSame(
			array(
				'id'      => $id,
				'deleted' => true,
			),
			$this->callContent( 'delete-post', array( 'id' => $id ) )
		);
		self::assertNull( get_post( $id ) );
		$missing = $this->callContent( 'delete-post', array( 'id' => 999999 ) );
		self::assertWPError( $missing );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $missing->get_error_code() );
	}

	/** Bulk results stay per object with safe reasons; nothing is transactional. */
	public function test_bulk_mixed_results_are_per_object(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$own    = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'draft',
			)
		);
		$other  = self::factory()->post->create(
			array(
				'post_author' => $this->editor,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( $author );
		$result = $this->callContent(
			'bulk-update-posts',
			array(
				'ids'     => array( $own, $other, 999999 ),
				'changes' => array( 'status' => 'pending' ),
			)
		);
		self::assertIsArray( $result );
		self::assertSame( array( $own ), $result['updated'] );
		self::assertSame(
			array(
				array(
					'id'   => $other,
					'code' => 'forbidden',
				),
				array(
					'id'   => 999999,
					'code' => 'not_found',
				),
			),
			$result['failed']
		);
		self::assertSame( 'pending', get_post_status( $own ) );
		self::assertSame( 'draft', get_post_status( $other ) );
		wp_set_current_user( $this->editor );
		wp_delete_post( $own, true );
		wp_delete_post( $other, true );
	}

	/** Duplicates keep the acting owner and never copy passwords. */
	public function test_duplicate_keeps_owner_and_allowlist(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$source = self::factory()->post->create(
			array(
				'post_author'   => $author,
				'post_status'   => 'publish',
				'post_title'    => 'Duplicate source',
				'post_content'  => '<p>Duplicate body</p>',
				'post_password' => 'SECRET',
			)
		);
		$copy   = $this->callContent(
			'duplicate-post',
			array(
				'id'     => $source,
				'status' => 'draft',
			)
		);
		self::assertIsArray( $copy );
		self::assertNotSame( $source, $copy['id'] );
		self::assertSame( $this->editor, $copy['author'] );
		self::assertSame( 'draft', $copy['status'] );
		self::assertSame( 'Duplicate source', $copy['title'] );
		self::assertSame( '', get_post( $copy['id'] )->post_password );
		wp_delete_post( $copy['id'], true );
		wp_delete_post( $source, true );
	}

	/** Revisions bind to their parent; cross-parent reads and restores are refused. */
	public function test_revision_parent_binding(): void {
		$first = $this->callContent(
			'create-post',
			array(
				'title'   => 'Revision parent',
				'content' => '<p>Lifecycle v1</p>',
			)
		);
		self::assertIsArray( $first );
		$second = $this->callContent(
			'create-post',
			array(
				'title'   => 'Revision other',
				'content' => '<p>Other v1</p>',
			)
		);
		self::assertIsArray( $second );
		$this->callContent(
			'update-post',
			array(
				'id'      => $first['id'],
				'content' => '<p>Lifecycle v2</p>',
			)
		);
		$this->callContent(
			'update-post',
			array(
				'id'      => $second['id'],
				'content' => '<p>Other v2</p>',
			)
		);
		$mine = $this->callContent( 'list-revisions', array( 'id' => $first['id'] ) );
		self::assertIsArray( $mine );
		self::assertNotEmpty( $mine['items'] );
		foreach ( $mine['items'] as $item ) {
			self::assertSame( $first['id'], $item['parent'] );
		}
		$theirs = $this->callContent( 'list-revisions', array( 'id' => $second['id'] ) );
		self::assertIsArray( $theirs );
		self::assertNotEmpty( $theirs['items'] );
		$foreign_id = $theirs['items'][0]['id'];
		$swapped    = $this->callContent(
			'get-revision',
			array(
				'id'          => $first['id'],
				'revision_id' => $foreign_id,
			)
		);
		self::assertWPError( $swapped );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $swapped->get_error_code() );
		$swapped_restore = $this->callContent(
			'restore-revision',
			array(
				'id'          => $first['id'],
				'revision_id' => $foreign_id,
			)
		);
		self::assertWPError( $swapped_restore );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $swapped_restore->get_error_code() );
		$own_id = $mine['items'][0]['id'];
		$read   = $this->callContent(
			'get-revision',
			array(
				'id'          => $first['id'],
				'revision_id' => $own_id,
			)
		);
		self::assertIsArray( $read );
		self::assertSame( $first['id'], $read['parent'] );
		$restored = $this->callContent(
			'restore-revision',
			array(
				'id'          => $first['id'],
				'revision_id' => $own_id,
			)
		);
		self::assertIsArray( $restored );
		self::assertSame( $first['id'], $restored['id'] );
		$check = $this->callContent( 'get-post', array( 'id' => $second['id'] ) );
		self::assertIsArray( $check );
		self::assertStringContainsString( 'Other v2', (string) $check['content'] );
		wp_delete_post( $first['id'], true );
		wp_delete_post( $second['id'], true );
	}

	/** A stale optimistic token conflicts without overwrite; a fresh token wins. */
	public function test_stale_token_conflicts_and_fresh_wins(): void {
		$post = $this->callContent( 'create-post', array( 'title' => 'Conflict sample' ) );
		self::assertIsArray( $post );
		$id     = $post['id'];
		$before = $this->callContent(
			'get-post',
			array(
				'id'             => $id,
				'content_format' => 'raw',
			)
		);
		self::assertIsArray( $before );
		$stale = $before['modified_gmt'];
		sleep( 2 );
		$bumped = $this->callContent(
			'update-post',
			array(
				'id'    => $id,
				'title' => 'Conflict bumped',
			)
		);
		self::assertIsArray( $bumped );
		$after = $this->callContent(
			'get-post',
			array(
				'id'             => $id,
				'content_format' => 'raw',
			)
		);
		self::assertIsArray( $after );
		self::assertNotSame( $stale, $after['modified_gmt'] );
		$conflict = $this->callContent(
			'update-post',
			array(
				'id'                => $id,
				'title'             => 'Stale write',
				'expected_modified' => $stale,
			)
		);
		self::assertWPError( $conflict );
		self::assertSame( 'xnetvn_agent_hub_wp_conflict', $conflict->get_error_code() );
		self::assertSame( 'Conflict bumped', get_post( $id )->post_title );
		$fresh = $this->callContent(
			'update-post',
			array(
				'id'                => $id,
				'title'             => 'Fresh write',
				'expected_modified' => $after['modified_gmt'],
			)
		);
		self::assertIsArray( $fresh );
		self::assertSame( 'Fresh write', $fresh['title'] );
		wp_delete_post( $id, true );
	}

	/** A write that lands but cannot be projected returns a reconciling receipt. */
	public function test_prepare_fault_yields_partial_failure_receipt(): void {
		$probe = static function ( $response ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter signature requires the prepared response parameter.
			return new \WP_Error( 'role_seo_lifecycle_probe', 'Probe prepare fault.' );
		};
		add_filter( 'rest_prepare_post', $probe );
		try {
			$title  = 'Partial receipt ' . wp_rand();
			$result = $this->callContent( 'create-post', array( 'title' => $title ) );
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $result->get_error_code() );
			$created_id = $result->get_error_data()['created_id'] ?? 0;
			self::assertGreaterThan( 0, (int) $created_id );
			$stored = get_post( (int) $created_id );
			self::assertNotNull( $stored );
			self::assertSame( $title, $stored->post_title );
			self::assertSame( $this->editor, (int) $stored->post_author );
			$found = get_posts(
				array(
					'post_type'   => 'post',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
					's'           => $title,
				)
			);
			self::assertSame( array( (int) $created_id ), array_map( 'intval', $found ) );
			wp_delete_post( (int) $created_id, true );
			self::assertNull( get_post( (int) $created_id ) );
		} finally {
			remove_filter( 'rest_prepare_post', $probe );
		}
	}

	/** Controlled cron fires only the owned future event through the core action. */
	public function test_controlled_cron_publishes_owned_future(): void {
		// Core auto-publishes a future post whose date is moved to the past
		// through wp_update_post, so the fixture reschedules the owned event
		// instead of touching post dates. Pin the avoided path first.
		$probe = $this->callContent(
			'create-post',
			array(
				'title'  => 'Cron date probe',
				'status' => 'future',
				'date'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 2592000 ),
			)
		);
		self::assertIsArray( $probe );
		wp_update_post(
			array(
				'ID'            => $probe['id'],
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
			)
		);
		self::assertSame( 'publish', get_post_status( $probe['id'] ) );
		wp_delete_post( $probe['id'], true );
		$future = $this->callContent(
			'create-post',
			array(
				'title'  => 'Cron lifecycle',
				'status' => 'future',
				'date'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 2592000 ),
			)
		);
		self::assertIsArray( $future );
		$id                                = $future['id'];
		$owned_at                          = static function () use ( $id ): array {
			$stamps = array();
			$total  = 0;
			foreach ( _get_cron_array() as $timestamp => $hooks ) {
				if ( ! is_array( $hooks ) || ! isset( $hooks['publish_future_post'] ) || ! is_array( $hooks['publish_future_post'] ) ) {
					continue;
				}
				foreach ( $hooks['publish_future_post'] as $event ) {
					++$total;
					if ( array( $id ) === array_values( (array) ( $event['args'] ?? array() ) ) ) {
						$stamps[] = (int) $timestamp;
					}
				}
			}
			return array( $stamps, $total );
		};
		list( $timestamps, $total_before ) = $owned_at();
		self::assertNotEmpty( $timestamps );
		self::assertSame( 'future', get_post_status( $id ) );
		global $wpdb;
		// The cron callback only publishes when the post date itself is due,
		// so the owned row is moved to a past date with a bounded write that
		// skips the save-time transition; the status stays future.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Owned lifecycle row moved to its due date without the save-time transition.
			$wpdb->posts,
			array(
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
			),
			array( 'ID' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $id );
		self::assertSame( 'future', get_post_status( $id ) );
		foreach ( $timestamps as $timestamp ) {
			wp_unschedule_event( (int) $timestamp, 'publish_future_post', array( $id ) );
		}
		list( $remaining ) = $owned_at();
		self::assertSame( array(), $remaining );
		$due = time() - 60;
		self::assertTrue( wp_schedule_single_event( $due, 'publish_future_post', array( $id ) ) );
		list( $rescheduled ) = $owned_at();
		self::assertSame( array( $due ), $rescheduled );
		foreach ( $rescheduled as $timestamp ) {
			wp_unschedule_event( (int) $timestamp, 'publish_future_post', array( $id ) );
		}
		do_action( 'publish_future_post', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fire the owned core cron event only.
		self::assertSame( 'publish', get_post_status( $id ) );
		wp_delete_post( $id, true );
	}
}
