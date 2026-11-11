<?php
/**
 * Retention, journal and idempotency contracts for the SEO review campaign.
 *
 * Pins the behavior the remote SEO review runner relies on: no success,
 * failure, timeout, interruption, rerun or cleanup branch may delete the
 * review article, its attachments, its terms or its author; an unresolved
 * request stays unknown until a marker lookup decides it; a rerun of the same
 * campaign adds nothing; and an owner edit is reported as a conflict instead
 * of being overwritten. Real HTTPS publishing stays in tests/E2E and runs on
 * the shared lab; this file pins the pure logic and the WordPress objects it
 * must keep.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Campaign;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Classifier;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\ContentPlan;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Flow;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Handoff;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Journal;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Marker;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Transport;

require_once __DIR__ . '/../E2E/seo-review.php';

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.VariableComment.MissingVar -- Typed fixtures and prose docblocks document the fake transport without repeating each scalar.
// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The fake MCP transport lives beside its contract test, inside the five-file review scope.
// phpcs:disable WordPress.WP.AlternativeFunctions -- The suite owns a private scratch tree and scans tracked sources; PHPUnit has no WordPress filesystem transport.
// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag -- Fake transport failures are asserted by the suite rather than documented per helper.

/** Pins SEO review retention, journal classification and rerun safety. */
final class SeoReviewRetentionTest extends WP_UnitTestCase {
	/** Campaign shape shared with the runner. */
	private const CAMPAIGN = 'review-20261026T101112Z';

	/** Scratch root owned by this test only; never a lab review root. */
	private string $root = '';

	/** Set up an isolated scratch root after the flow file is present. */
	public function set_up(): void {
		parent::set_up();
		$this->root = sys_get_temp_dir() . '/seo-review-' . bin2hex( random_bytes( 6 ) );
		if ( ! mkdir( $this->root, 0700, true ) && ! is_dir( $this->root ) ) {
			self::fail( 'The SEO review scratch root is unavailable.' );
		}
	}

	/** Remove only the scratch root this test created. */
	public function tear_down(): void {
		$this->removeTree( $this->root );
		parent::tear_down();
	}

	/** Campaign and journal paths reject foreign or traversing input. */
	public function test_campaign_guards_reject_foreign_inputs(): void {
		self::assertInstanceOf( Campaign::class, new Campaign( $this->root, 'wp69', self::CAMPAIGN ) );
		foreach ( array( 'wp70', 'network-main', '', '../wp69' ) as $target ) {
			try {
				new Campaign( $this->root, $target, self::CAMPAIGN );
				self::fail( 'An unapproved target was accepted.' );
			} catch ( \InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
		foreach ( array( 'review-2026-10-26', 'review-20261026T101112', '../review-20261026T101112Z', 'review-20261026T101112Z/../x' ) as $campaign ) {
			try {
				new Campaign( $this->root, 'wp69', $campaign );
				self::fail( 'An unapproved campaign was accepted.' );
			} catch ( \InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
		$campaign = new Campaign( $this->root, 'wp71', self::CAMPAIGN );
		$campaign->ensure();
		self::assertSame( 'sr20261026t101112z', $campaign->campaign_tag() );
		self::assertStringStartsWith( $this->root . '/private/wp71/' . self::CAMPAIGN, $campaign->journal_path() );
		self::assertSame( 0700, fileperms( $campaign->private_dir() ) & 0700 );
	}

	/** The journal is private, atomic and refuses a symlinked file. */
	public function test_journal_is_atomic_private_and_refuses_symlink(): void {
		$campaign = new Campaign( $this->root, 'wp69', self::CAMPAIGN );
		$campaign->ensure();
		$journal = new Journal( $campaign );
		$state   = $journal->open();
		self::assertSame( 'seo-review-journal', $state['suite'] );
		self::assertSame( self::CAMPAIGN, $state['campaign'] );
		self::assertSame( 1, $state['blog'] );
		$journal->put_object(
			'post:bai-viet',
			array(
				'kind'  => 'post',
				'id'    => 11,
				'owner' => 7,
			)
		);
		self::assertSame( 0600, fileperms( $campaign->journal_path() ) & 0600 );
		self::assertSame( 11, $journal->object( 'post:bai-viet' )['id'] );
		$temporary = glob( $campaign->private_dir() . '/journal.json.tmp-*' );
		self::assertIsArray( $temporary );
		self::assertSame( array(), $temporary );

		unlink( $campaign->journal_path() );
		$foreign = $this->root . '/foreign-journal.json';
		file_put_contents( $foreign, '{"suite":"foreign"}' );
		symlink( $foreign, $campaign->journal_path() );
		$this->expectException( \RuntimeException::class );
		$journal->open();
	}

	/** An unresolved request stays unknown; only a closed request may be absent. */
	public function test_classifier_keeps_unresolved_requests_unknown(): void {
		$classifier = new Classifier();
		self::assertSame( 'committed', $classifier->classify( true, false, array( 'phase' => 'sent' ) ) );
		self::assertSame( 'committed', $classifier->classify( true, true, array( 'phase' => 'received' ) ) );
		self::assertSame( 'unknown', $classifier->classify( false, false, array( 'phase' => 'sent' ) ) );
		self::assertSame( 'unknown', $classifier->classify( false, true, array( 'phase' => 'received' ) ) );
		self::assertSame( 'not-committed', $classifier->classify( false, true, array( 'phase' => 'intent' ) ) );
		self::assertSame( 'unknown', $classifier->classify( false, false, array( 'phase' => 'intent' ) ) );
		self::assertSame( 'partial', $classifier->classify( true, true, array( 'phase' => 'partial' ) ) );
		self::assertSame( 'conflict', $classifier->classify( true, true, array( 'phase' => 'conflict' ) ) );
	}

	/** Markers carry the campaign tuple and never accept a lookalike. */
	public function test_marker_is_strict_and_carries_site_tuple(): void {
		$marker = Marker::build( 'wp69', 1, self::CAMPAIGN, 'media', 'inline-1' );
		self::assertSame( 'xnetvn-seo-review|wp69|1|review-20261026T101112Z|media|inline-1', $marker );
		self::assertSame(
			array(
				'target'   => 'wp69',
				'blog'     => 1,
				'campaign' => self::CAMPAIGN,
				'kind'     => 'media',
				'ref'      => 'inline-1',
			),
			Marker::parse( $marker )
		);
		foreach ( array( 'xnetvn-seo-review|wp69|1|review-20261026T101112Z|media', 'xnetvn-seo-review|wp70|1|' . self::CAMPAIGN . '|media|inline-1', 'other|wp69|1|' . self::CAMPAIGN . '|media|inline-1' ) as $bad ) {
			self::assertNull( Marker::parse( $bad ) );
		}
		self::assertTrue( Marker::is_owned_by( $marker, 'wp69', 1, self::CAMPAIGN ) );
		self::assertFalse( Marker::is_owned_by( $marker, 'wp71', 1, self::CAMPAIGN ) );
		self::assertFalse( Marker::is_owned_by( $marker, 'wp69', 2, self::CAMPAIGN ) );
		self::assertFalse( Marker::is_owned_by( $marker, 'wp69', 1, 'review-20261026T101113Z' ) );
	}

	/** The handoff carries public identifiers only and rejects secret fields. */
	public function test_handoff_rejects_secret_fields_and_writes_allowlist(): void {
		$campaign = new Campaign( $this->root, 'wp69', self::CAMPAIGN );
		$campaign->ensure();
		$handoff = new Handoff();
		$record  = array(
			'target'     => 'wp69',
			'campaign'   => self::CAMPAIGN,
			'site_url'   => 'https://example.test',
			'blog'       => 1,
			'revision'   => 'deadbeef',
			'post'       => array(
				'id'       => 12,
				'url'      => 'https://example.test/bai/',
				'edit_url' => 'https://example.test/wp-admin/post.php?post=12&action=edit',
			),
			'author'     => array(
				'id'   => 3,
				'role' => 'author',
			),
			'retained'   => array(
				'post'   => true,
				'media'  => 3,
				'terms'  => 3,
				'author' => true,
			),
			'checks'     => array(
				'title' => true,
				'media' => true,
			),
			'incomplete' => array(),
			'unknown'    => array(),
			'cleanup'    => array(
				'keys'    => 'revoked',
				'temp'    => 'removed',
				'article' => 'retained',
			),
		);
		$path    = $handoff->write( $campaign, $record );
		self::assertSame( $campaign->handoff_path(), $path );
		self::assertSame( 0600, fileperms( $path ) & 0600 );
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		self::assertIsArray( $decoded );
		self::assertSame( 12, $decoded['post']['id'] );
		self::assertArrayNotHasKey( 'token', $decoded );

		foreach ( array( 'token', 'bearer', 'nonce', 'cookie', 'password', 'secret_hash', 'raw_body' ) as $forbidden ) {
			try {
				$handoff->write( $campaign, array_merge( $record, array( $forbidden => 'x' ) ) );
				self::fail( 'A secret-bearing handoff field was accepted.' );
			} catch ( \InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	/** Rerunning one campaign reuses the committed article and adds nothing. */
	public function test_rerun_same_campaign_reuses_committed_article(): void {
		$campaign = new Campaign( $this->root, 'wp69', self::CAMPAIGN );
		$campaign->ensure();
		$this->writeImages( $campaign );
		$server = new SeoReviewFakeServer();
		$flow   = $this->flow( $campaign, $server );
		$first  = $flow->run( 'publish' );
		self::assertSame( 0, $first['exit'], 'First publish failed: ' . implode( ',', $first['incomplete'] ) );
		$calls_after_first = $server->mutations();
		$second            = $this->flow( $campaign, $server )->run( 'publish' );
		self::assertSame( 0, $second['exit'], 'Rerun failed: ' . implode( ',', $second['incomplete'] ) );
		self::assertSame( $calls_after_first, $server->mutations(), 'A rerun attempted another mutation.' );
		self::assertSame( 1, count( $server->posts ), 'A rerun created a duplicate article.' );
		self::assertSame( 3, count( $server->media ), 'A rerun created a duplicate attachment.' );
		self::assertSame( 3, count( $server->terms ), 'A rerun created a duplicate term.' );
	}

	/** A lost receipt is recovered by marker without a blind second create. */
	public function test_lost_receipt_is_reconciled_without_blind_retry(): void {
		$campaign = new Campaign( $this->root, 'wp69', self::CAMPAIGN );
		$campaign->ensure();
		$this->writeImages( $campaign );
		$server                    = new SeoReviewFakeServer();
		$server->lose_post_receipt = true;
		$recovered                 = $this->flow( $campaign, $server )->run( 'publish' );
		self::assertSame( 0, $recovered['exit'], 'The recovered receipt was not verified.' );
		self::assertSame( 1, count( $server->posts ), 'The committed article was not retained.' );
		self::assertSame( 1, $server->create_post_calls, 'A lost receipt was resent.' );

		$campaign_interrupted = new Campaign( $this->root, 'wp71', 'review-20261026T111213Z' );
		$campaign_interrupted->ensure();
		$this->writeImages( $campaign_interrupted );
		$server_interrupted = new SeoReviewFakeServer();
		$interrupted        = $this->flow( $campaign_interrupted, $server_interrupted, 'post-lost-receipt' )->run( 'publish' );
		self::assertSame( 2, $interrupted['exit'], 'A killed process was reported as a pass.' );
		self::assertContains( 'post-lost-receipt', $interrupted['unknown'] );
		self::assertSame( 1, count( $server_interrupted->posts ), 'The interrupted article was not retained.' );

		$reconciled = $this->flow( $campaign_interrupted, $server_interrupted )->run( 'reconcile' );
		self::assertSame( 0, $reconciled['exit'], 'Reconcile did not resolve the interrupted create.' );
		self::assertTrue( $reconciled['checks']['reconciled'] );
		self::assertSame( 1, count( $server_interrupted->posts ), 'Reconcile created a duplicate article.' );
		self::assertSame( 1, $server_interrupted->create_post_calls, 'Reconcile resent the whole creation.' );

		$resumed = $this->flow( $campaign_interrupted, $server_interrupted )->run( 'publish' );
		self::assertSame( 0, $resumed['exit'], 'The retained draft was not finished: ' . implode( ',', $resumed['incomplete'] ) );
		self::assertSame( 1, $server_interrupted->create_post_calls, 'Resuming created a duplicate article.' );
		self::assertSame( 1, count( $server_interrupted->posts ), 'A duplicate article exists after resume.' );

		$verified = $this->flow( $campaign_interrupted, $server_interrupted )->run( 'verify' );
		self::assertSame( 0, $verified['exit'], 'Verify failed after reconcile: ' . implode( ',', $verified['incomplete'] ) );
		self::assertTrue( $verified['retained']['post'] );
		self::assertSame( 3, $verified['retained']['media'] );
	}

	/** A forced failure and run cleanup keep every review object. */
	public function test_retention_survives_forced_failure_and_cleanup(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post   = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
				'post_title'  => 'Retained review article',
			)
		);
		$term   = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Retained review term',
			)
		);
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$pixel  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fixed one-pixel fixture, never executable code.
		$upload = wp_upload_bits( 'seo-review-retention.png', null, $pixel );
		self::assertIsArray( $upload );
		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => $upload['file'],
				'post_parent'    => $post,
				'post_author'    => $author,
				'post_mime_type' => 'image/png',
			)
		);
		self::assertIsInt( $attachment );

		$campaign = new Campaign( $this->root, 'wp69', self::CAMPAIGN );
		$campaign->ensure();
		$journal = new Journal( $campaign );
		$journal->open();
		$journal->put_object(
			'post:bai-viet',
			array(
				'kind'  => 'post',
				'id'    => $post,
				'owner' => $author,
				'state' => 'committed',
			)
		);
		$journal->put_object(
			'media:featured',
			array(
				'kind'  => 'media',
				'id'    => $attachment,
				'owner' => $author,
				'state' => 'committed',
			)
		);
		$journal->put_object(
			'term:category',
			array(
				'kind'  => 'term',
				'id'    => $term,
				'owner' => $editor,
				'state' => 'committed',
			)
		);
		$journal->put_object(
			'user:author',
			array(
				'kind'  => 'user',
				'id'    => $author,
				'owner' => $author,
				'state' => 'committed',
			)
		);
		$this->writeImages( $campaign );
		$credentials = $campaign->credentials_path();
		file_put_contents( $credentials, '{"author":{"token":"test-only"}}' );
		chmod( $credentials, 0600 );

		$cleanup = Flow::cleanup_run_files( $campaign );
		self::assertTrue( $cleanup['credentials_removed'] );
		self::assertTrue( $cleanup['temp_removed'] );
		self::assertFileDoesNotExist( $credentials );
		self::assertFileExists( $campaign->journal_path() );

		self::assertInstanceOf( \WP_Post::class, get_post( $post ) );
		self::assertSame( 'publish', get_post_status( $post ) );
		self::assertSame( $author, (int) get_post_field( 'post_author', $post ) );
		self::assertSame( 'Retained review article', get_post_field( 'post_title', $post ) );
		self::assertInstanceOf( \WP_Post::class, get_post( $attachment ) );
		self::assertSame( $post, (int) get_post_field( 'post_parent', $attachment ) );
		self::assertInstanceOf( \WP_Term::class, get_term( $term ) );
		self::assertInstanceOf( \WP_User::class, get_userdata( $author ) );
		self::assertContains( 'author', (array) get_userdata( $author )->roles );

		$missing = new Campaign( $this->root, 'wp69', 'review-20261026T101113Z' );
		$missing->ensure();
		$journal_missing = new Journal( $missing );
		$journal_missing->open();
		$journal_missing->put_object(
			'post:bai-viet',
			array(
				'kind'  => 'post',
				'id'    => $post,
				'owner' => $author,
				'state' => 'committed',
			)
		);
		self::assertSame( $post, $journal_missing->object( 'post:bai-viet' )['id'] );
	}

	/** A changed article digest is a conflict, not an overwrite. */
	public function test_user_edit_conflict_blocks_overwrite(): void {
		$campaign = new Campaign( $this->root, 'wp69', self::CAMPAIGN );
		$campaign->ensure();
		$this->writeImages( $campaign );
		$server = new SeoReviewFakeServer();
		$flow   = $this->flow( $campaign, $server );
		self::assertSame( 0, $flow->run( 'publish' )['exit'] );
		$post_id                                   = array_key_first( $server->posts );
		$server->posts[ $post_id ]['title']        = 'Owner revision';
		$server->posts[ $post_id ]['modified_gmt'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + 60 );
		$updates_before                            = $server->update_post_calls;
		$conflict                                  = $this->flow( $campaign, $server )->run( 'verify' );
		self::assertSame( 2, $conflict['exit'], 'A user edit was not reported as a conflict.' );
		self::assertSame( $updates_before, $server->update_post_calls, 'Verify mutated the article.' );
		self::assertSame( 'Owner revision', $server->posts[ $post_id ]['title'] );
		self::assertContains( 'post', $conflict['conflict'] );
	}

	/** A journal owned by another site or blog is refused before any write. */
	public function test_foreign_journal_is_refused(): void {
		$campaign = new Campaign( $this->root, 'wp69', self::CAMPAIGN );
		$campaign->ensure();
		$other = new Campaign( $this->root, 'wp71', self::CAMPAIGN );
		$other->ensure();
		self::assertNotSame( $other->private_dir(), $campaign->private_dir() );
		$journal         = new Journal( $campaign );
		$state           = $journal->open();
		$state['target'] = 'wp71';
		$journal->save( $state );
		$this->writeImages( $campaign );
		$server = new SeoReviewFakeServer();
		$result = $this->flow( $campaign, $server )->run( 'publish' );
		self::assertSame( 2, $result['exit'] );
		self::assertSame( array(), $server->mutations(), 'A foreign journal reached a mutation.' );
		self::assertSame( array(), $server->posts );
	}

	/** The review sources contain no deletion path for retained resources. */
	public function test_review_code_never_deletes_retained_resources(): void {
		$sources   = array(
			__DIR__ . '/../E2E/seo-review-fixture.php',
			__DIR__ . '/../E2E/seo-review.php',
			__DIR__ . '/../../infra/remote/run-seo-review.sh',
			__DIR__ . '/../E2E/seo-review.spec.js',
		);
		$forbidden = array( 'wp_delete_post', 'wp_delete_attachment', 'wp_delete_user', 'wp_delete_term', 'wp_trash_post', 'wp_delete_object_term_relationships' );
		foreach ( $sources as $file ) {
			self::assertFileExists( $file );
			$body = (string) file_get_contents( $file );
			foreach ( $forbidden as $needle ) {
				self::assertStringNotContainsString( $needle, $body, $file . ' contains a retained-resource delete path.' );
			}
		}
		$flow = (string) file_get_contents( __DIR__ . '/../E2E/seo-review.php' );
		self::assertStringNotContainsString( 'delete-media', $flow );
		self::assertStringNotContainsString( 'delete-term', $flow );
		self::assertStringNotContainsString( 'delete-post', $flow );
		self::assertStringNotContainsString( 'trash-post', $flow );
		$runner = (string) file_get_contents( __DIR__ . '/../../infra/remote/run-seo-review.sh' );
		foreach ( array( '--volumes', 'down --purge', 'docker system prune', 'cleanup-posts' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $runner );
		}
	}

	/**
	 * Build a flow bound to the fake lab.
	 *
	 * @param Campaign  $campaign  Validated campaign.
	 * @param Transport $transport Fake transport.
	 * @param string    $fault     Optional configured fault point.
	 * @return Flow Flow bound to the fake lab.
	 */
	private function flow( Campaign $campaign, Transport $transport, string $fault = '' ): Flow {
		return new Flow(
			$campaign,
			$transport,
			array(
				'site_url'    => 'https://example.test',
				'blog'        => 1,
				'revision'    => 'deadbeef',
				'fingerprint' => 'test-fingerprint',
				'fault'       => $fault,
				'probe'       => static fn(): array => array(
					'ok'     => true,
					'status' => 200,
					'title'  => ContentPlan::TITLE,
				),
			)
		);
	}

	/**
	 * Write the three declared image files into the owned media directory.
	 *
	 * @param Campaign $campaign Validated campaign that owns the media directory.
	 */
	private function writeImages( Campaign $campaign ): void {
		$campaign->ensure();
		$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fixed test-owned image bytes, never executable code.
		foreach ( ContentPlan::images() as $image ) {
			file_put_contents( $campaign->media_dir() . '/' . $image['file'], $bytes );
		}
	}

	/**
	 * Remove a test-owned directory tree without touching lab review data.
	 *
	 * @param string $path Directory to remove.
	 */
	private function removeTree( string $path ): void {
		if ( '' === $path || ! is_dir( $path ) || is_link( $path ) ) {
			return;
		}
		$items = scandir( $path );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$full = $path . '/' . $item;
			if ( is_dir( $full ) && ! is_link( $full ) ) {
				$this->removeTree( $full );
				continue;
			}
			unlink( $full );
		}
		rmdir( $path );
	}
}

/**
 * In-memory MCP stand-in with the reviewed tool contract.
 *
 * It records each call, keeps created objects by marker and can lose a
 * receipt after the object was committed, which is the failure shape the
 * remote flow must reconcile instead of resending.
 */
final class SeoReviewFakeServer implements Transport {
	/** Articles by id, shaped like the reviewed content projection. */
	public array $posts = array();

	/** Attachments by id, shaped like the reviewed media projection. */
	public array $media = array();

	/** Terms by id. */
	public array $terms = array();

	/** Simulated users resolved by the fake session. */
	public array $users = array();

	/** Simulate a lost create-post receipt after the article exists. */
	public bool $lose_post_receipt = false;

	/** Counted mutating create-post calls. */
	public int $create_post_calls = 0;

	/** Counted update-post calls. */
	public int $update_post_calls = 0;

	/** Next object id. */
	private int $next = 100;

	/** Mutating calls recorded in order. */
	private array $mutations = array();

	/** Simulated timestamps. */
	private int $clock = 1761471072;

	/** Create the fake actors. */
	public function __construct() {
		$this->users = array(
			'author'   => 7,
			'operator' => 8,
		);
	}

	/**
	 * Return the recorded mutation names.
	 *
	 * @return array<int,string> Recorded mutation names.
	 */
	public function mutations(): array {
		return $this->mutations;
	}

	/**
	 * Dispatch one tool call with a realistic reviewed contract.
	 *
	 * @param string              $actor Actor name.
	 * @param string              $tool  Tool name.
	 * @param array<string,mixed> $args  Tool arguments.
	 * @return array<string,mixed> Recorded transport response.
	 */
	public function call( string $actor, string $tool, array $args = array() ): array {
		switch ( $tool ) {
			case 'get-session-info':
				$id = $this->users[ $actor ] ?? 0;
				return $this->ok(
					array(
						'user' => array(
							'id'           => $id,
							'display_name' => $actor,
							'roles'        => array( 'author' === $actor ? 'author' : 'editor' ),
						),
					)
				);
			case 'list-terms':
				$taxonomy = (string) ( $args['taxonomy'] ?? '' );
				$slug     = (string) ( ( (array) ( $args['slug'] ?? array( '' ) ) )[0] ?? '' );
				$items    = array();
				foreach ( $this->terms as $id => $term ) {
					if ( $term['taxonomy'] === $taxonomy && ( '' === $slug || $term['slug'] === $slug ) ) {
						$items[] = $term;
					}
				}
				return $this->ok(
					array(
						'items'       => $items,
						'total'       => count( $items ),
						'total_pages' => 1,
						'page'        => 1,
					)
				);
			case 'get-term':
				$id = (int) ( $args['id'] ?? 0 );
				return isset( $this->terms[ $id ] ) ? $this->ok( $this->terms[ $id ] ) : $this->error( 'not_found' );
			case 'create-term':
				$this->assertMutatingAllowed( $tool );
				$marker = Marker::parse( (string) ( $args['description'] ?? '' ) );
				foreach ( $this->terms as $term ) {
					if ( $term['slug'] === $args['slug'] && $term['taxonomy'] === $args['taxonomy'] ) {
						return $this->error( 'duplicate_term' );
					}
				}
				$id                 = $this->next++;
				$this->terms[ $id ] = array(
					'id'       => $id,
					'taxonomy' => (string) $args['taxonomy'],
					'name'     => (string) $args['name'],
					'slug'     => (string) $args['slug'],
					'count'    => 0,
					'marker'   => $marker,
				);
				$this->mutations[]  = $tool;
				return $this->ok( $this->terms[ $id ] );
			case 'list-media':
				$items = array();
				foreach ( $this->media as $media ) {
					if ( isset( $args['author'] ) && ! in_array( $media['author'], (array) $args['author'], true ) ) {
						continue;
					}
					$items[] = $media;
				}
				return $this->ok(
					array(
						'items'       => $items,
						'total'       => count( $items ),
						'total_pages' => 1,
						'page'        => 1,
					)
				);
			case 'get-media':
				$id = (int) ( $args['id'] ?? 0 );
				return isset( $this->media[ $id ] ) ? $this->ok( $this->media[ $id ] ) : $this->error( 'not_found' );
			case 'upload-media-from-data':
				$this->assertMutatingAllowed( $tool );
				$id                 = $this->next++;
				$marker             = Marker::parse( (string) ( $args['description'] ?? '' ) );
				$this->media[ $id ] = array(
					'id'            => $id,
					'author'        => $this->users[ $actor ] ?? 0,
					'post'          => 0,
					'status'        => 'inherit',
					'alt_text'      => (string) ( $args['alt_text'] ?? '' ),
					'caption'       => (string) ( $args['caption'] ?? '' ),
					'description'   => (string) ( $args['description'] ?? '' ),
					'title'         => (string) ( $args['title'] ?? '' ),
					'link'          => 'https://example.test/media-' . $id,
					'source_url'    => 'https://example.test/uploads/png-' . $id . '.png',
					'mime_type'     => (string) ( $args['mime_type'] ?? '' ),
					'media_details' => array(
						'width'    => 1200,
						'height'   => 800,
						'filesize' => 4096,
					),
					'marker'        => $marker,
				);
				$this->mutations[]  = $tool;
				$response           = $this->ok( $this->media[ $id ] );
				$this->clock       += 1;
				return $response;
			case 'list-posts':
				$items = array();
				foreach ( $this->posts as $post ) {
					if ( isset( $args['author'] ) && ! in_array( $post['author'], (array) $args['author'], true ) ) {
						continue;
					}
					$items[] = $post;
				}
				return $this->ok(
					array(
						'items'       => $items,
						'total'       => count( $items ),
						'total_pages' => 1,
						'page'        => 1,
					)
				);
			case 'get-post':
				$id = (int) ( $args['id'] ?? 0 );
				return isset( $this->posts[ $id ] ) ? $this->ok( $this->posts[ $id ] ) : $this->error( 'not_found' );
			case 'get-post-meta':
				$id = (int) ( $args['id'] ?? 0 );
				return isset( $this->posts[ $id ] ) ? $this->ok( array( 'meta' => $this->posts[ $id ]['meta'] ) ) : $this->error( 'not_found' );
			case 'create-post':
				$this->assertMutatingAllowed( $tool );
				++$this->create_post_calls;
				$id                 = $this->next++;
				$post               = array(
					'id'             => $id,
					'type'           => 'post',
					'status'         => 'draft',
					'title'          => (string) ( $args['title'] ?? '' ),
					'slug'           => (string) ( $args['slug'] ?? '' ),
					'link'           => '',
					'author'         => $this->users[ $actor ] ?? 0,
					'excerpt'        => (string) ( $args['excerpt'] ?? '' ),
					'content'        => (string) ( $args['content'] ?? '' ),
					'featured_media' => 0,
					'categories'     => array_values( (array) ( $args['categories'] ?? array() ) ),
					'tags'           => array_values( (array) ( $args['tags'] ?? array() ) ),
					'modified'       => gmdate( 'Y-m-d\TH:i:s\Z', $this->clock ),
					'modified_gmt'   => gmdate( 'Y-m-d\TH:i:s\Z', $this->clock ),
					'meta'           => (array) ( $args['meta'] ?? array() ),
				);
				$this->posts[ $id ] = $post;
				$this->mutations[]  = $tool;
				if ( $this->lose_post_receipt ) {
					$this->lose_post_receipt = false;
					throw new \RuntimeException( 'Simulated lost receipt after commit.' );
				}
				$this->clock += 1;
				return $this->ok( $post );
			case 'set-featured-media':
				$this->assertMutatingAllowed( $tool );
				$id = (int) ( $args['id'] ?? 0 );
				if ( ! isset( $this->posts[ $id ] ) ) {
					return $this->error( 'not_found' );
				}
				$this->posts[ $id ]['featured_media'] = (int) ( $args['media_id'] ?? 0 );
				$this->posts[ $id ]['modified_gmt']   = gmdate( 'Y-m-d\TH:i:s\Z', $this->clock );
				$this->mutations[]                    = $tool;
				return $this->ok( $this->posts[ $id ] );
			case 'update-post':
				$this->assertMutatingAllowed( $tool );
				++$this->update_post_calls;
				$id = (int) ( $args['id'] ?? 0 );
				if ( ! isset( $this->posts[ $id ] ) ) {
					return $this->error( 'not_found' );
				}
				if ( isset( $args['expected_modified'] ) && strtotime( (string) $args['expected_modified'] ) !== strtotime( (string) $this->posts[ $id ]['modified_gmt'] ) ) {
					return $this->error( 'conflict' );
				}
				foreach ( array( 'status', 'content' ) as $field ) {
					if ( isset( $args[ $field ] ) ) {
						$this->posts[ $id ][ $field ] = $args[ $field ];
					}
				}
				if ( 'publish' === ( $this->posts[ $id ]['status'] ?? '' ) ) {
					$this->posts[ $id ]['link'] = 'https://example.test/?p=' . $id;
				}
				$this->posts[ $id ]['modified_gmt'] = gmdate( 'Y-m-d\TH:i:s\Z', $this->clock + 1 );
				$this->clock                       += 2;
				$this->mutations[]                  = $tool;
				return $this->ok( $this->posts[ $id ] );
			default:
				return $this->error( 'unsupported_operation' );
		}
	}

	/**
	 * Refuse an unapproved mutating tool by name.
	 *
	 * @param string $tool Tool name.
	 */
	private function assertMutatingAllowed( string $tool ): void {
		if ( ! in_array( $tool, array( 'create-term', 'upload-media-from-data', 'create-post', 'set-featured-media', 'update-post' ), true ) ) {
			throw new \RuntimeException( 'Unapproved mutating tool.' );
		}
	}

	/**
	 * Build a structured success envelope.
	 *
	 * @param array<string,mixed> $data Structured content.
	 * @return array<string,mixed> Success envelope.
	 */
	private function ok( array $data ): array {
		return array(
			'status' => 200,
			'body'   => array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array(
					'isError'           => false,
					'structuredContent' => $data,
				),
			),
		);
	}

	/**
	 * Build a tool error envelope with an allowlisted reason.
	 *
	 * @param string $reason Allowlisted error reason.
	 * @return array<string,mixed> Error envelope.
	 */
	private function error( string $reason ): array {
		return array(
			'status' => 200,
			'body'   => array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array(
					'isError' => true,
					'content' => array(
						array(
							'type' => 'text',
							'text' => '{"error":"' . $reason . '"}',
						),
					),
				),
			),
		);
	}
}
