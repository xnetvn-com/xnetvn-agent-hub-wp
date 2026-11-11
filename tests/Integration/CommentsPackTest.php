<?php
/**
 * Comment moderation permissions, state machine and safe projections.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Comments\CommentsPack;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Uses real core comments, moderation functions and the comments controller. */
final class CommentsPackTest extends WP_UnitTestCase {
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
	/** Real core server and an editor actor. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->settings = new WpSiteSettings();
		foreach ( ( new CommentsPack( new DefinitionFactory() ) )->definitions() as $tool ) {
			$this->tools[ $tool->descriptor()->name ] = $tool; }
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
	/**
	 * Create a post with open comments.
	 *
	 * @return int
	 */
	private function post(): int {
		return self::factory()->post->create( array( 'comment_status' => 'open' ) );
	}
	/**
	 * Insert a comment with a known status.
	 *
	 * @param int    $post_id Post.
	 * @param string $status Approved, hold, spam or trash.
	 * @return int
	 */
	private function comment( int $post_id, string $status = 'hold' ): int {
		$approved = 'approved' === $status ? 1 : ( 'hold' === $status ? 0 : $status );
		$id       = wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Fixture comment ' . $status,
				'comment_approved' => $approved,
			)
		);
		self::assertIsInt( $id );
		return $id;
	}
	/**
	 * Backdate comments so cutoff-exclusive purge selects them.
	 *
	 * @param array<int> $ids Comments.
	 */
	private function backdate( array $ids ): void {
		$past = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		foreach ( $ids as $id ) {
			wp_update_comment(
				array(
					'comment_ID'       => $id,
					'comment_date'     => get_date_from_gmt( $past ),
					'comment_date_gmt' => $past,
				)
			);
		}
	}
	/** Ten strict contracts have schema-valid examples. */
	public function test_contract(): void {
		self::assertCount( 10, $this->tools );
		\XNetVN\AgentHubWP\Tests\Contract\PackContractTestCase::assert_pack_contract( new CommentsPack( new DefinitionFactory() ) );
	}
	/** Authors never see comment tools; direct calls are refused. */
	public function test_author_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$result = $this->call( 'list-comments', array() );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $result->get_error_code() );
	}
	/** List, read and counts round-trip with private fields removed. */
	public function test_read_lifecycle(): void {
		$post = $this->post();
		$held = $this->comment( $post, 'hold' );
		$this->comment( $post, 'approved' );
		$listed = $this->call( 'list-comments', array( 'post' => array( $post ) ) );
		self::assertSame( 1, $listed['total'] );
		self::assertSame( 'approved', $listed['items'][0]['status'] );
		$held_list = $this->call(
			'list-comments',
			array(
				'post'   => array( $post ),
				'status' => 'hold',
			)
		);
		self::assertSame( 1, $held_list['total'] );
		$one = $this->call( 'get-comment', array( 'id' => $held ) );
		self::assertSame( $held, $one['id'] );
		self::assertSame( 'hold', $one['status'] );
		self::assertArrayNotHasKey( 'author_email', $one );
		self::assertArrayNotHasKey( 'author_ip', $one );
		$counts = $this->call( 'get-comment-counts', array( 'post' => $post ) );
		self::assertSame( 1, $counts['approved'] );
		self::assertSame( 1, $counts['hold'] );
		self::assertSame( 2, $counts['all'] );
	}
	/** Editor notes are never exposed through managed tools. */
	public function test_note_type_is_hidden(): void {
		$post = $this->post();
		$note = wp_insert_comment(
			array(
				'comment_post_ID'  => $post,
				'comment_content'  => 'Editor note',
				'comment_type'     => 'note',
				'comment_approved' => 1,
			)
		);
		self::assertIsInt( $note );
		$result = $this->call( 'get-comment', array( 'id' => $note ) );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $result->get_error_code() );
		$listed = $this->call( 'list-comments', array( 'post' => array( $post ) ) );
		self::assertSame( 0, $listed['total'] );
	}
	/** New comments wait for review unless publishing is explicit. */
	public function test_create_defaults_to_hold(): void {
		$post = $this->post();
		$held = $this->call(
			'create-comment',
			array(
				'post'    => $post,
				'content' => 'Please review me',
			)
		);
		self::assertSame( 'hold', $held['status'] );
		self::assertSame( get_current_user_id(), $held['author'] );
		$open = $this->call(
			'create-comment',
			array(
				'post'    => $post,
				'content' => 'Publish me now',
				'status'  => 'approved',
			)
		);
		self::assertSame( 'approved', $open['status'] );
	}
	/** Closed comments and draft posts are refused with reviewed reasons. */
	public function test_create_target_gates(): void {
		$closed = self::factory()->post->create( array( 'comment_status' => 'closed' ) );
		$result = $this->call(
			'create-comment',
			array(
				'post'    => $closed,
				'content' => 'Knock knock',
			)
		);
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_comments_closed', $result->get_error_code() );
		$draft  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$result = $this->call(
			'create-comment',
			array(
				'post'    => $draft,
				'content' => 'Too early',
			)
		);
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $result->get_error_code() );
	}
	/** Replies bind to the parent post and can approve a held parent. */
	public function test_reply_flow(): void {
		$post   = $this->post();
		$parent = $this->comment( $post, 'hold' );
		$denied = $this->call(
			'reply-comment',
			array(
				'parent_id' => $parent,
				'content'   => 'Answer',
			)
		);
		self::assertWPError( $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $denied->get_error_code() );
		$reply = $this->call(
			'reply-comment',
			array(
				'parent_id'      => $parent,
				'content'        => 'Answer',
				'approve_parent' => true,
			)
		);
		self::assertSame( 'hold', $reply['status'] );
		self::assertSame( $parent, $reply['parent'] );
		self::assertTrue( $reply['parent_approved'] );
		self::assertSame( 'approved', wp_get_comment_status( $parent ) );
	}
	/** Updates keep the original network identity. */
	public function test_update_keeps_ip(): void {
		$post    = $this->post();
		$id      = wp_insert_comment(
			array(
				'comment_post_ID'   => $post,
				'comment_content'   => 'Original',
				'comment_author_IP' => '203.0.113.7',
				'comment_agent'     => 'Fixture/1.0',
				'comment_approved'  => 1,
			)
		);
		$updated = $this->call(
			'update-comment',
			array(
				'id'      => $id,
				'content' => 'Edited without spam link',
			)
		);
		self::assertStringContainsString( 'Edited without spam link', $updated['content'] );
		$fresh = get_comment( $id );
		self::assertSame( '203.0.113.7', $fresh->comment_author_IP );
		self::assertSame( 'Fixture/1.0', $fresh->comment_agent );
	}
	/** Forced KSES strips scripts even for privileged key owners. */
	public function test_kses_strips_script_for_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post    = $this->post();
		$evil    = 'Hello <script>alert(1)</script> <img src="x" onerror="alert(1)">';
		$created = $this->call(
			'create-comment',
			array(
				'post'    => $post,
				'content' => $evil,
			)
		);
		self::assertStringNotContainsString( '<script>', $created['content'] );
		self::assertStringNotContainsString( 'onerror', $created['content'] );
	}
	/** Moderation follows the transition table and stays idempotent. */
	public function test_moderate_transitions(): void {
		$post  = $this->post();
		$id    = $this->comment( $post, 'hold' );
		$first = $this->call(
			'moderate-comment',
			array(
				'id'     => $id,
				'action' => 'approve',
			)
		);
		self::assertSame( 'approved', $first['status'] );
		self::assertSame( 'hold', $first['previous_status'] );
		self::assertTrue( $first['changed'] );
		$repeat = $this->call(
			'moderate-comment',
			array(
				'id'     => $id,
				'action' => 'approve',
			)
		);
		self::assertFalse( $repeat['changed'] );
		$spam = $this->comment( $post, 'spam' );
		$bad  = $this->call(
			'moderate-comment',
			array(
				'id'     => $spam,
				'action' => 'approve',
			)
		);
		self::assertWPError( $bad );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $bad->get_error_code() );
		$fresh = get_comment( $id );
		self::assertSame( get_comment( $id )->comment_author_IP, $fresh->comment_author_IP );
	}
	/** Trash is refused when the site disables the trash. */
	public function test_trash_refused_without_trash(): void {
		$filter = static fn(): bool => false;
		add_filter( 'rest_comment_trashable', $filter );
		try {
			$post   = $this->post();
			$id     = $this->comment( $post, 'approved' );
			$result = $this->call(
				'moderate-comment',
				array(
					'id'     => $id,
					'action' => 'trash',
				)
			);
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_unsupported_operation', $result->get_error_code() );
			self::assertSame( '1', get_comment( $id )->comment_approved );
		} finally {
			remove_filter( 'rest_comment_trashable', $filter );
		}
	}
	/** Deletion needs spam or trash status first. */
	public function test_delete_two_step(): void {
		$post  = $this->post();
		$live  = $this->comment( $post, 'approved' );
		$early = $this->call( 'delete-comment', array( 'id' => $live ) );
		self::assertWPError( $early );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $early->get_error_code() );
		$trashed = $this->call(
			'moderate-comment',
			array(
				'id'     => $live,
				'action' => 'trash',
			)
		);
		self::assertSame( 'trash', $trashed['status'] );
		self::assertSame(
			array(
				'id'      => $live,
				'deleted' => true,
			),
			$this->call( 'delete-comment', array( 'id' => $live ) )
		);
		self::assertNull( get_comment( $live ) );
	}
	/** Bulk reports per-item outcomes without stopping on errors. */
	public function test_bulk_mixed_outcomes(): void {
		$post   = $this->post();
		$one    = $this->comment( $post, 'hold' );
		$two    = $this->comment( $post, 'hold' );
		$result = $this->call(
			'bulk-moderate-comments',
			array(
				'ids'    => array( $one, $two, 999999 ),
				'action' => 'approve',
			)
		);
		self::assertSame( array( $one, $two ), $result['updated'] );
		self::assertSame( array(), $result['unchanged'] );
		self::assertSame( 999999, $result['failed'][0]['id'] );
		self::assertSame( 'not_found', $result['failed'][0]['code'] );
		$toomany = $this->call(
			'bulk-moderate-comments',
			array(
				'ids'    => range( 1, 51 ),
				'action' => 'approve',
			)
		);
		self::assertWPError( $toomany );
	}
	/** Purge honors the cutoff so newer spam survives. */
	public function test_purge_cutoff(): void {
		$post = $this->post();
		$old  = array( $this->comment( $post, 'spam' ), $this->comment( $post, 'spam' ) );
		$this->backdate( $old );
		$first = $this->call(
			'purge-comments',
			array(
				'status' => 'spam',
				'limit'  => 100,
			)
		);
		self::assertSame( 2, $first['deleted'] );
		self::assertArrayHasKey( 'cutoff', $first );
		$new    = $this->comment( $post, 'spam' );
		$second = $this->call(
			'purge-comments',
			array(
				'status' => 'spam',
				'limit'  => 100,
				'before' => $first['cutoff'],
			)
		);
		self::assertSame( 0, $second['deleted'] );
		self::assertNotNull( get_comment( $new ) );
	}
}
