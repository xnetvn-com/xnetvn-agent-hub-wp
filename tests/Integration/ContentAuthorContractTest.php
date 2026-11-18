<?php
/**
 * Machine content-author contract on the human MCP path.
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
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Proves attribution, refusal and KSES persistence against real core REST. */
final class ContentAuthorContractTest extends WP_UnitTestCase {

	/** Definitions.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $tools = array();

	/** Human Editor identity.
	 *
	 * @var int
	 */
	private int $editor;

	/** Second Editor eligible as an attribution target.
	 *
	 * @var int
	 */
	private int $target;

	/** Establish a real REST server and current actor. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->target = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user         = get_userdata( $this->editor );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'unfiltered_html' );
		wp_set_current_user( $this->editor );
		kses_remove_filters();
		foreach ( ( new ContentPack( new DefinitionFactory() ) )->definitions() as $definition ) {
			$this->tools[ $definition->descriptor()->name ] = $definition;
		}
	}

	/** Restore the shared core state. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		kses_init_filters();
		parent::tear_down();
	}

	/**
	 * Execute with both schema checks, as the registry does after authorization.
	 *
	 * @param string              $name Tool.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function call( string $name, array $args = array() ): mixed {
		$tool = $this->tools[ 'xnetvn/' . $name ];
		if ( true !== rest_validate_value_from_schema( $args, $tool->input_schema() ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		return ( new OutputPipeline( new MemorySettings() ) )->process( $tool->execute( $args ), $tool->output_schema() );
	}

	/**
	 * Assert stored markup keeps benign formatting and drops executable HTML.
	 *
	 * @param string $value   Stored value.
	 * @param string $allowed Benign markup that must survive.
	 */
	private function assert_safe( string $value, string $allowed ): void {
		self::assertStringNotContainsString( '<script', $value );
		self::assertStringNotContainsString( 'onerror', $value );
		self::assertStringNotContainsString( 'onload', $value );
		self::assertStringNotContainsString( 'javascript:', $value );
		self::assertStringContainsString( $allowed, $value );
	}

	/**
	 * Count persisted posts in every core status.
	 *
	 * @return int
	 */
	private function count_posts(): int {
		return array_sum( array_map( 'intval', get_object_vars( wp_count_posts( 'post' ) ) ) );
	}

	/**
	 * Post IDs authored by an account.
	 *
	 * @param int $author Author.
	 * @return array<int>
	 */
	private function authored( int $author ): array {
		return get_posts(
			array(
				'author'         => $author,
				'fields'         => 'ids',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
				'post_type'      => 'post',
				'posts_per_page' => -1,
			)
		);
	}

	/** Create without author derives the actor and persists filtered HTML. */
	public function test_create_without_author_derives_actor_and_persists_safe_html(): void {
		$before  = $this->count_posts();
		$created = $this->call(
			'create-post',
			array(
				'title'   => '<b>Owned</b><script>alert(1)</script>',
				'content' => '<p>Safe</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">x</a>',
				'excerpt' => '<p>Safe excerpt</p><script>alert(1)</script>',
			)
		);
		self::assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		self::assertSame( $this->editor, $created['author'] );
		$stored = get_post( $created['id'] );
		self::assertInstanceOf( \WP_Post::class, $stored );
		self::assertSame( $this->editor, (int) $stored->post_author );
		$this->assert_safe( $stored->post_content, '<p>Safe</p>' );
		$this->assert_safe( $stored->post_excerpt, '<p>Safe excerpt</p>' );
		self::assertStringNotContainsString( '<b>', $stored->post_title );
		self::assertStringNotContainsString( '<script', $stored->post_title );
		self::assertSame( $before + 1, $this->count_posts() );
	}

	/** Duplicate without author credits the actor and filters copied raw content. */
	public function test_duplicate_without_author_credits_actor_and_filters_raw_source(): void {
		$source = self::factory()->post->create(
			array(
				'post_author'  => $this->target,
				'post_content' => '<p>Raw</p><script>alert(2)</script><svg onload="alert(2)"></svg><img src="x" onerror="alert(2)"><a href="javascript:alert(2)">x</a>',
				'post_excerpt' => '<p>Raw excerpt</p><script>alert(2)</script>',
				'post_status'  => 'draft',
				'post_title'   => '<b>Raw source</b><script>alert(2)</script>',
			)
		);
		$raw    = get_post( $source );
		self::assertInstanceOf( \WP_Post::class, $raw );
		self::assertStringContainsString( '<script', $raw->post_content );
		self::assertStringContainsString( 'onload', $raw->post_content );
		self::assertStringContainsString( 'onerror', $raw->post_content );
		self::assertStringContainsString( 'javascript:', $raw->post_content );
		$before = $this->count_posts();
		$copy   = $this->call( 'duplicate-post', array( 'id' => $source ) );
		self::assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		self::assertSame( 'draft', $copy['status'] );
		self::assertSame( $this->editor, $copy['author'] );
		$stored = get_post( $copy['id'] );
		self::assertInstanceOf( \WP_Post::class, $stored );
		self::assertSame( $this->editor, (int) $stored->post_author );
		$this->assert_safe( $stored->post_content, '<p>Raw</p>' );
		$this->assert_safe( $stored->post_excerpt, '<p>Raw excerpt</p>' );
		self::assertStringNotContainsString( '<b>', $stored->post_title );
		self::assertSame( $before + 1, $this->count_posts() );
		$unchanged = get_post( $source );
		self::assertInstanceOf( \WP_Post::class, $unchanged );
		self::assertStringContainsString( '<script', $unchanged->post_content );
		self::assertStringContainsString( 'javascript:', $unchanged->post_content );
		self::assertSame( $this->target, (int) $unchanged->post_author );
	}

	/** An explicit human author is refused before any post is written. */
	public function test_explicit_author_is_rejected_before_any_write(): void {
		$source = self::factory()->post->create( array( 'post_author' => $this->editor ) );
		$before = $this->count_posts();
		$writes = 0;
		$count  = static function () use ( &$writes ): void {
			++$writes;
		};
		add_action( 'wp_insert_post', $count, 10, 1 );
		try {
			$cases = array(
				array(
					'create-post',
					array(
						'author' => 99,
						'title'  => 'Unknown author',
					),
				),
				array(
					'create-post',
					array(
						'author' => $this->target,
						'title'  => 'Other author',
					),
				),
				array(
					'duplicate-post',
					array(
						'author' => $this->target,
						'id'     => $source,
					),
				),
				array(
					'duplicate-post',
					array(
						'author' => 99,
						'id'     => $source,
					),
				),
			);
			foreach ( $cases as $case ) {
				$result = $this->call( $case[0], $case[1] );
				self::assertWPError( $result, $case[0] );
				self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code(), $case[0] );
			}
		} finally {
			remove_action( 'wp_insert_post', $count );
		}
		self::assertSame( 0, $writes );
		self::assertSame( $before, $this->count_posts() );
		self::assertSame( array(), $this->authored( $this->target ) );
	}

	/** Update, restore and bulk keep their existing author contracts. */
	public function test_update_restore_and_bulk_author_contracts_are_unchanged(): void {
		$created = $this->call( 'create-post', array( 'title' => 'Author contract fixture' ) );
		self::assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$id     = $created['id'];
		$before = $this->count_posts();
		$update = $this->call(
			'update-post',
			array(
				'author' => $this->target,
				'id'     => $id,
				'title'  => 'Never applied',
			)
		);
		self::assertWPError( $update );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $update->get_error_code() );
		$restore = $this->call(
			'restore-post',
			array(
				'author' => $this->target,
				'id'     => $id,
			)
		);
		self::assertWPError( $restore );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $restore->get_error_code() );
		$stored = get_post( $id );
		self::assertInstanceOf( \WP_Post::class, $stored );
		self::assertSame( $this->editor, (int) $stored->post_author );
		self::assertSame( 'Author contract fixture', $stored->post_title );
		self::assertSame( $before, $this->count_posts() );
		$bulk = $this->call(
			'bulk-update-posts',
			array(
				'changes' => array( 'author' => $this->target ),
				'ids'     => array( $id ),
			)
		);
		self::assertIsArray( $bulk );
		self::assertSame( array( $id ), $bulk['updated'] );
		self::assertSame( array(), $bulk['failed'] );
		self::assertSame( $this->target, (int) get_post( $id )->post_author );
	}

	/** The shared machine schema accepts author and fails closed on bad values. */
	public function test_machine_payload_author_validates_and_invalid_values_are_refused(): void {
		$create    = $this->tools['xnetvn/create-post']->input_schema();
		$duplicate = $this->tools['xnetvn/duplicate-post']->input_schema();
		self::assertTrue(
			rest_validate_value_from_schema(
				array(
					'author'  => 5,
					'content' => '<p>Machine</p>',
					'title'   => 'Machine attribution',
				),
				$create
			)
		);
		self::assertTrue(
			rest_validate_value_from_schema(
				array(
					'author' => 5,
					'id'     => 7,
				),
				$duplicate
			)
		);
		foreach ( array( 0, -1, 1.5, 'not-an-integer', true ) as $invalid ) {
			self::assertWPError(
				rest_validate_value_from_schema(
					array(
						'author' => $invalid,
						'title'  => 'Machine attribution',
					),
					$create
				)
			);
		}
		self::assertWPError(
			rest_validate_value_from_schema(
				array(
					'author'  => 5,
					'title'   => 'Machine attribution',
					'unknown' => 'widened',
				),
				$create
			)
		);
		self::assertFalse( $create['additionalProperties'] );
		self::assertSame( 1, $create['properties']['author']['minimum'] );
		self::assertNotContains( 'author', $create['required'] ?? array() );
		self::assertNotContains( 'author', $duplicate['required'] ?? array() );
	}
}
