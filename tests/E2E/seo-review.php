<?php
/**
 * MCP publishing flow for the retained SEO review campaign.
 *
 * Runs outside WordPress in the lab toolbox and speaks only HTTPS MCP: terms,
 * media, article creation and every read-back go through the reviewed route.
 * The article, its three attachments, its terms and its author survive every
 * outcome; cleanup removes run-scoped credentials and bounded temporary files
 * only. A lost receipt, a partial run or an owner edit is reconciled from the
 * journal and the persisted object before any retry, and no branch deletes a
 * review resource.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\E2E\SeoReview;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone toolbox CLI: no WordPress filesystem or HTTP transport is bootstrapped in this process.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- Owned test artifacts use bounded binary encoding and hashing, never executable code.
// phpcs:disable WordPress.Security.EscapeOutput -- Fixed secret-free JSON is written to the lab CLI stdout, not to an HTML response.
// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionCommentThrowTag, Squiz.Commenting.VariableComment.MissingVar -- Typed signatures and prose docblocks document this five-file lab harness without repeating each scalar.
// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The approved issue allows exactly five review files, so the journal, flow and transport helpers share one test-owned source.
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- Domain words such as object and exit are stable contract field names in the journal.

use XNetVN\AgentHubWP\Tests\E2E\McpClient;

require_once __DIR__ . '/McpClient.php';

/** Raised when the flow must stop with a classified, secret-free reason. */
final class FlowFailure extends \RuntimeException {
	/**
	 * Bind one classified failure.
	 *
	 * @param string $classification guard, unknown, conflict or assertion.
	 * @param string $reason Stable reason token.
	 * @param string $reference Object or operation reference.
	 * @param int    $exit Exit code for the runner.
	 */
	public function __construct( public readonly string $classification, public readonly string $reason, public readonly string $reference = '', public readonly int $exit = 2 ) {
		parent::__construct( $classification . ':' . $reason );
	}
}

/** Signals a test-owned fault point without leaking request values. */
final class FaultSignal extends \RuntimeException {
}

/** Transport contract shared by the HTTPS client and the integration fake. */
interface Transport {
	/**
	 * Send one tool call.
	 *
	 * @param string              $actor Actor alias with its own credential.
	 * @param string              $tool Tool suffix without the vendor prefix.
	 * @param array<string,mixed> $args Tool arguments.
	 * @return array{status:int,body:array<string,mixed>|null}
	 */
	public function call( string $actor, string $tool, array $args = array() ): array;
}

/** Validated target and campaign paths under the owned review root. */
final class Campaign {
	/** Approved single-site targets. */
	public const TARGETS = array( 'wp69', 'wp71' );

	/** Campaign shape shared with the runner and the journal guard. */
	public const CAMPAIGN_PATTERN = '/\Areview-[0-9]{8}T[0-9]{6}Z\z/';

	/** Canonical root when the environment does not override it. */
	public const DEFAULT_ROOT = '/work/.local/seo-review';

	/** Absolute owned root. */
	private string $root;

	/**
	 * Validate the tuple.
	 *
	 * @param string $root Absolute owned root.
	 * @param string $target Lab target.
	 * @param string $campaign Review campaign.
	 * @throws \InvalidArgumentException For an unapproved tuple.
	 */
	public function __construct( string $root, private string $target, private string $campaign ) {
		if ( ! in_array( $target, self::TARGETS, true ) ) {
			throw new \InvalidArgumentException( 'Unapproved review target.' );
		}
		if ( 1 !== preg_match( self::CAMPAIGN_PATTERN, $campaign ) ) {
			throw new \InvalidArgumentException( 'Unapproved review campaign.' );
		}
		if ( '' === $root || ! str_starts_with( $root, '/' ) || str_contains( $root, '..' ) || str_contains( $root, "\0" ) ) {
			throw new \InvalidArgumentException( 'Unapproved review root.' );
		}
		$this->root = rtrim( $root, '/' );
	}

	/** Target name. */
	public function target(): string {
		return $this->target;
	}

	/** Campaign name. */
	public function campaign(): string {
		return $this->campaign;
	}

	/** Owned root. */
	public function root(): string {
		return $this->root;
	}

	/** Deterministic term and slug tag for this campaign. */
	public function campaign_tag(): string {
		return 'sr' . strtolower( substr( $this->campaign, 7 ) );
	}

	/** Single-site blog id for this milestone. */
	public function blog(): int {
		return 1;
	}

	/** Private campaign directory. */
	public function private_dir(): string {
		return $this->root . '/private/' . $this->target . '/' . $this->campaign;
	}

	/** Public handoff directory. */
	public function public_dir(): string {
		return $this->root . '/' . $this->target . '/' . $this->campaign;
	}

	/** Journal path. */
	public function journal_path(): string {
		return $this->private_dir() . '/journal.json';
	}

	/** Private credential store path. */
	public function credentials_path(): string {
		return $this->private_dir() . '/credentials.json';
	}

	/** Bounded image staging directory. */
	public function media_dir(): string {
		return $this->private_dir() . '/media';
	}

	/** Handoff path. */
	public function handoff_path(): string {
		return $this->public_dir() . '/handoff.json';
	}

	/** Per-campaign flow lock path. */
	public function lock_path(): string {
		return $this->private_dir() . '/journal.lock';
	}

	/**
	 * Create every owned directory with private permissions.
	 *
	 * @throws \RuntimeException For an unavailable or symlinked path.
	 */
	public function ensure(): void {
		foreach ( array( $this->root, $this->root . '/private', $this->root . '/private/' . $this->target, $this->private_dir(), $this->media_dir(), $this->root . '/' . $this->target, $this->public_dir() ) as $path ) {
			if ( is_link( $path ) ) {
				throw new \RuntimeException( 'Review path ownership mismatch.' );
			}
			if ( ! is_dir( $path ) && ! mkdir( $path, 0700, true ) && ! is_dir( $path ) ) {
				throw new \RuntimeException( 'Review path unavailable.' );
			}
			chmod( $path, 0700 );
		}
	}
}

/** Site, campaign and operation markers without secrets. */
final class Marker {
	/** Fixed prefix of every owned marker. */
	public const PREFIX = 'xnetvn-seo-review';

	/**
	 * Build one marker.
	 *
	 * @param string $target Target.
	 * @param int    $blog Blog id.
	 * @param string $campaign Campaign.
	 * @param string $kind post, media, term or user.
	 * @param string $reference Operation reference.
	 */
	public static function build( string $target, int $blog, string $campaign, string $kind, string $reference ): string {
		return implode( '|', array( self::PREFIX, $target, (string) $blog, $campaign, $kind, $reference ) );
	}

	/**
	 * Parse a marker, or return null when it is not one of ours.
	 *
	 * @param string $value Candidate text.
	 * @return array{target:string,blog:int,campaign:string,kind:string,ref:string}|null
	 */
	public static function parse( string $value ): ?array {
		$parts = explode( '|', trim( $value ) );
		if ( 6 !== count( $parts ) || self::PREFIX !== $parts[0] ) {
			return null;
		}
		if ( ! in_array( $parts[1], Campaign::TARGETS, true ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $parts[2] ) || 1 !== preg_match( Campaign::CAMPAIGN_PATTERN, $parts[3] ) ) {
			return null;
		}
		if ( ! in_array( $parts[4], array( 'post', 'media', 'term', 'user' ), true ) || 1 !== preg_match( '/\A[a-z0-9-]{1,32}\z/', $parts[5] ) ) {
			return null;
		}
		return array(
			'target'   => $parts[1],
			'blog'     => (int) $parts[2],
			'campaign' => $parts[3],
			'kind'     => $parts[4],
			'ref'      => $parts[5],
		);
	}

	/**
	 * Check whether a marker belongs to the expected tuple.
	 *
	 * @param string $value Candidate.
	 * @param string $target Expected target.
	 * @param int    $blog Expected blog.
	 * @param string $campaign Expected campaign.
	 */
	public static function is_owned_by( string $value, string $target, int $blog, string $campaign ): bool {
		$parsed = self::parse( $value );
		return null !== $parsed && $parsed['target'] === $target && $parsed['blog'] === $blog && $parsed['campaign'] === $campaign;
	}
}

/** Atomic, private and lock-guarded campaign journal. */
final class Journal {
	/** Allowed object row keys; nothing secret may enter here. */
	private const OBJECT_KEYS = array( 'kind', 'ref', 'id', 'owner', 'marker', 'state', 'origin', 'digest', 'modified_gmt', 'url', 'role', 'name', 'slug', 'taxonomy', 'source_url', 'created_utc', 'updated_utc' );

	/** Allowed operation row keys. */
	private const OPERATION_KEYS = array( 'phase', 'request_ended', 'tool', 'object', 'reason', 'slug', 'updated_utc', 'attempts' );

	/** Loaded state for this process. */
	private array $state = array();

	/**
	 * Bind one campaign journal.
	 *
	 * @param Campaign $campaign Validated campaign.
	 */
	public function __construct( private Campaign $campaign ) {
	}

	/**
	 * Canonical digest of a journal fragment.
	 *
	 * @param mixed $value Fragment with stable key order.
	 * @return string Lowercase SHA-256 hex digest.
	 */
	public static function digest( mixed $value ): string {
		return hash( 'sha256', (string) json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Load the journal, creating the owned empty state on first use.
	 *
	 * @return array<string,mixed>
	 * @throws \RuntimeException For a symlinked, foreign or malformed journal.
	 */
	public function open(): array {
		$file = $this->campaign->journal_path();
		if ( is_link( $file ) || is_link( $this->campaign->private_dir() ) ) {
			throw new \RuntimeException( 'Review journal ownership mismatch.' );
		}
		if ( ! is_file( $file ) ) {
			$this->state = array(
				'suite'       => 'seo-review-journal',
				'target'      => $this->campaign->target(),
				'campaign'    => $this->campaign->campaign(),
				'blog'        => $this->campaign->blog(),
				'site_url'    => '',
				'created_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'objects'     => array(),
				'operations'  => array(),
				'checkpoints' => array(),
			);
			$this->save( $this->state );
			return $this->state;
		}
		$raw = file_get_contents( $file );
		if ( ! is_string( $raw ) ) {
			throw new \RuntimeException( 'Review journal unreadable.' );
		}
		try {
			$state = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new \RuntimeException( 'Review journal malformed.' );
		}
		if ( ! is_array( $state ) || 'seo-review-journal' !== ( $state['suite'] ?? '' ) || ! is_array( $state['objects'] ?? null ) || ! is_array( $state['operations'] ?? null ) ) {
			throw new \RuntimeException( 'Review journal malformed.' );
		}
		$this->state = $state;
		return $this->state;
	}

	/**
	 * Current state.
	 *
	 * @return array<string,mixed> Stored journal document.
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * Persist the state atomically.
	 *
	 * @param array<string,mixed> $state Full state.
	 * @throws \RuntimeException For an unsafe destination.
	 */
	public function save( array $state ): void {
		$this->campaign->ensure();
		$file = $this->campaign->journal_path();
		if ( is_link( $file ) ) {
			throw new \RuntimeException( 'Review journal ownership mismatch.' );
		}
		$state['objects']    = $state['objects'] ?? array();
		$state['operations'] = $state['operations'] ?? array();
		foreach ( array_keys( $state['objects'] ) as $ref ) {
			$this->assert_reference( (string) $ref );
		}
		$temporary = $file . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		$handle    = fopen( $temporary, 'x' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Review journal is not writable.' );
		}
		try {
			if ( false === fwrite( $handle, (string) json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) ) {
				throw new \RuntimeException( 'Review journal write failed.' );
			}
			fflush( $handle );
		} finally {
			fclose( $handle );
		}
		chmod( $temporary, 0600 );
		if ( ! rename( $temporary, $file ) ) {
			unlink( $temporary );
			throw new \RuntimeException( 'Review journal commit failed.' );
		}
		chmod( $file, 0600 );
		$this->state = $state;
	}

	/**
	 * Merge one object row and persist.
	 *
	 * @param string              $reference Object reference.
	 * @param array<string,mixed> $row Partial row.
	 * @return array<string,mixed> Stored row.
	 */
	public function put_object( string $reference, array $row ): array {
		$this->assert_reference( $reference );
		$current = $this->state['objects'][ $reference ] ?? array();
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = array_merge( $current, array_intersect_key( $row, array_flip( self::OBJECT_KEYS ) ) );
		if ( ! isset( $merged['id'] ) || ! is_int( $merged['id'] ) || 1 > $merged['id'] ) {
			throw new \InvalidArgumentException( 'Object row is missing its identifier.' );
		}
		if ( ! isset( $merged['kind'] ) || ! in_array( $merged['kind'], array( 'post', 'media', 'term', 'user' ), true ) ) {
			throw new \InvalidArgumentException( 'Object row kind is invalid.' );
		}
		$merged['ref']                        = $reference;
		$merged['updated_utc']                = gmdate( 'Y-m-d\TH:i:s\Z' );
		$this->state['objects'][ $reference ] = $merged;
		$this->save( $this->state );
		return $merged;
	}

	/**
	 * Read one object row.
	 *
	 * @param string $reference Object reference.
	 * @return array<string,mixed>|null
	 */
	public function object( string $reference ): ?array {
		$row = $this->state['objects'][ $reference ] ?? null;
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Merge one operation row and persist.
	 *
	 * @param string              $operation Operation reference.
	 * @param array<string,mixed> $row Partial row.
	 * @return array<string,mixed>
	 */
	public function set_operation( string $operation, array $row ): array {
		$this->assert_reference( $operation );
		$current = $this->state['operations'][ $operation ] ?? array();
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged                                  = array_merge( $current, array_intersect_key( $row, array_flip( self::OPERATION_KEYS ) ) );
		$merged['updated_utc']                   = gmdate( 'Y-m-d\TH:i:s\Z' );
		$this->state['operations'][ $operation ] = $merged;
		$this->save( $this->state );
		return $merged;
	}

	/**
	 * Read one operation row.
	 *
	 * @param string $operation Operation reference.
	 * @return array<string,mixed>|null
	 */
	public function operation( string $operation ): ?array {
		$row = $this->state['operations'][ $operation ] ?? null;
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Store one checkpoint value.
	 *
	 * @param string $key Checkpoint key.
	 * @param mixed  $value Safe value.
	 */
	public function checkpoint( string $key, mixed $value ): void {
		if ( 1 !== preg_match( '/\A[a-z0-9-]{1,32}\z/', $key ) ) {
			throw new \InvalidArgumentException( 'Unsafe checkpoint key.' );
		}
		$this->state['checkpoints'][ $key ] = $value;
		$this->save( $this->state );
	}

	/**
	 * Run a callable under the campaign lock.
	 *
	 * @param callable $run Work to run.
	 * @return mixed
	 * @throws \RuntimeException For an unavailable lock.
	 */
	public function with_lock( callable $run ): mixed {
		$this->campaign->ensure();
		$file = $this->campaign->lock_path();
		if ( is_link( $file ) ) {
			throw new \RuntimeException( 'Review lock ownership mismatch.' );
		}
		$handle = fopen( $file, 'c' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Review lock unavailable.' );
		}
		chmod( $file, 0600 );
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				throw new \RuntimeException( 'Review lock unavailable.' );
			}
			return $run();
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	/**
	 * Canonicalize a value for stable digests.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( self::class, 'canonical' ), $value );
		}
		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonical( $item );
		}
		return $value;
	}

	/**
	 * Require the `kind:slug` reference shape.
	 *
	 * @param string $reference Ledger reference such as `post:bai-viet`.
	 */
	private function assert_reference( string $reference ): void {
		if ( 1 !== preg_match( '/\A(?:post|media|term|user):[a-z0-9-]{1,32}\z/', $reference ) ) {
			throw new \InvalidArgumentException( 'Unsafe review reference.' );
		}
	}
}

/** Classifies a journaled operation from persisted evidence. */
final class Classifier {
	/**
	 * Classify one operation.
	 *
	 * @param bool                $found Persisted object exists.
	 * @param bool                $request_ended The request completion is known.
	 * @param array<string,mixed> $operation Journaled operation row.
	 * @return string committed, not-committed, partial, unresolved, unknown or conflict.
	 */
	public function classify( bool $found, bool $request_ended, array $operation ): string {
		$phase = (string) ( $operation['phase'] ?? 'intent' );
		if ( 'conflict' === $phase ) {
			return 'conflict';
		}
		if ( $found ) {
			return 'partial' === $phase ? 'partial' : 'committed';
		}
		if ( 'partial' === $phase ) {
			return 'partial';
		}
		if ( $request_ended && 'intent' === $phase ) {
			return 'not-committed';
		}
		return 'unknown';
	}
}

/** Fixes the S3 sample article, its terms and its three owned images. */
final class ContentPlan {
	/** Approved sample title. */
	public const TITLE = 'Hướng dẫn xây dựng bài viết WordPress rõ ràng và dễ đọc';

	/** Approved sample excerpt, written for this article. */
	public const EXCERPT = 'Bài viết hướng dẫn cách chuẩn bị ý tưởng, dựng cấu trúc và kiểm tra một bài WordPress trước khi xuất bản để người đọc theo dõi dễ dàng hơn.';

	/** Approved base slug; the campaign tag keeps each run unique. */
	public const SLUG_BASE = 'huong-dan-xay-dung-bai-viet-wordpress-ro-rang-va-de-doc';

	/** Official HTTPS sources linked by the body. */
	public const SOURCES = array(
		'https://wordpress.org/documentation/article/roles-and-capabilities/',
		'https://developers.google.com/search/docs/fundamentals/seo-starter-guide',
	);

	/**
	 * Planned slug for one campaign.
	 *
	 * @param string $tag Campaign tag without the `review-` prefix.
	 * @return string Deterministic post slug.
	 */
	public static function slug( string $tag ): string {
		return self::SLUG_BASE . '-' . $tag;
	}

	/**
	 * Planned category term.
	 *
	 * @param string $tag Campaign tag without the `review-` prefix.
	 * @return array<string,string> Term payload: taxonomy, name and slug.
	 */
	public static function category( string $tag ): array {
		return array(
			'taxonomy' => 'category',
			'name'     => 'Hướng dẫn',
			'slug'     => 'huong-dan-' . $tag,
		);
	}

	/**
	 * Planned tag terms.
	 *
	 * @param string $tag Campaign tag without the `review-` prefix.
	 * @return array<int,array<string,string>> Term payloads: taxonomy, name and slug.
	 */
	public static function tags( string $tag ): array {
		return array(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'WordPress',
				'slug'     => 'wordpress-' . $tag,
			),
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Viết nội dung',
				'slug'     => 'viet-noi-dung-' . $tag,
			),
		);
	}

	/**
	 * The three owned images: file, MIME and reader-facing metadata.
	 *
	 * @return array<int,array<string,mixed>> Declared image slots.
	 */
	public static function images(): array {
		return array(
			array(
				'slot'    => 'featured',
				'file'    => 'featured-bai-viet-ro-rang.png',
				'mime'    => 'image/png',
				'title'   => 'Bố cục bài viết rõ ràng',
				'alt'     => 'Ảnh minh họa một bài viết WordPress có bố cục rõ ràng',
				'caption' => 'Ảnh đại diện cho bài hướng dẫn bố cục bài viết',
			),
			array(
				'slot'    => 'inline-1',
				'file'    => 'cau-truc-bai-viet.png',
				'mime'    => 'image/png',
				'title'   => 'Ba bước chuẩn bị nội dung',
				'alt'     => 'Sơ đồ ba bước chuẩn bị nội dung trước khi viết',
				'caption' => 'Ba câu hỏi chuẩn bị quyết định độ dài và cách chia mục',
			),
			array(
				'slot'    => 'inline-2',
				'file'    => 'kiem-tra-bai-viet.png',
				'mime'    => 'image/png',
				'title'   => 'Danh sách kiểm tra trước khi xuất bản',
				'alt'     => 'Danh sách kiểm tra tiêu đề, thứ tự mục và liên kết trên trang bài viết',
				'caption' => 'Đọc lại bài trên giao diện thật trước khi xuất bản',
			),
		);
	}

	/**
	 * Render the article body with each inline attachment at its section.
	 *
	 * @param array<string,array<string,string>> $inline Inline image rows keyed inline-1 and inline-2.
	 * @param string                             $category_slug Owned category slug.
	 */
	public static function body( array $inline, string $category_slug ): string {
		$figure = static function ( array $image ): string {
			return '<figure class="wp-block-image size-large"><img src="' . $image['url'] . '" alt="' . $image['alt'] . '" /><figcaption>' . $image['caption'] . '</figcaption></figure>';
		};
		return implode(
			"\n",
			array(
				'<p>Một bài viết WordPress dễ đọc bắt đầu từ việc sắp xếp ý tưởng trước khi gõ chữ. Khi người đọc hiểu bạn muốn nói gì ngay từ đoạn đầu, họ có lý do ở lại và dễ tìm lại thông tin khi cần.</p>',
				'<h2>Chuẩn bị trước khi viết</h2>',
				'<p>Trước khi mở trình soạn thảo, hãy trả lời ba câu hỏi ngắn. Ba câu trả lời này quyết định độ dài, giọng văn và cách chia mục của bài.</p>',
				$figure( $inline['inline-1'] ),
				'<ul>',
				'<li><strong>Mục tiêu:</strong> bài viết giúp người đọc làm được việc gì?</li>',
				'<li><strong>Người đọc:</strong> họ đã biết gì và cần giải thích thêm phần nào?</li>',
				'<li><strong>Hành động:</strong> đọc xong họ nên bấm vào đâu hoặc làm gì tiếp theo?</li>',
				'</ul>',
				'<p>Nếu một mục trong dàn ý không phục vụ câu trả lời nào ở trên, hãy cắt nó thay vì viết lan man.</p>',
				'<h2>Dựng cấu trúc bài viết</h2>',
				'<p>Cấu trúc tốt giúp người đọc quét nhanh nội dung và quay lại đúng phần mình cần. Mỗi thẻ H2 nên là một bước hoặc một câu hỏi lớn; chỉ dùng H3 khi mục đó có từ hai ý nhỏ trở lên.</p>',
				'<h3>Mở bài</h3>',
				'<p>Nêu vấn đề trong hai đến ba câu, rồi nói rõ bài viết sẽ giúp gì. Tránh mở đầu bằng một đoạn lịch sử dài khi người đọc đang cần cách làm.</p>',
				'<h3>Thân bài</h3>',
				'<p>Chia nội dung theo trình tự người đọc sẽ thực hiện. Mỗi đoạn giữ một ý chính, và dùng <strong>câu chốt ngắn</strong> ở cuối đoạn để nhấn điều quan trọng nhất. Khi liệt kê bước, danh sách có thứ tự dễ theo dõi hơn một đoạn văn dày.</p>',
				'<h3>Kết luận</h3>',
				'<p>Tóm lại điều quan trọng nhất và đề nghị một bước tiếp theo cụ thể. Một kết luận tốt không lặp lại toàn bộ bài viết.</p>',
				'<h2>Kiểm tra trước khi xuất bản</h2>',
				'<p>Đọc lại bài trên giao diện thật, không chỉ trong trình soạn thảo. Kiểm tra tiêu đề, thứ tự H2 và H3, liên kết, ảnh và phần mô tả ngắn. Nếu giao diện không hiển thị một trường, hãy ghi rõ giới hạn đó thay vì cho rằng dữ liệu bị mất.</p>',
				$figure( $inline['inline-2'] ),
				'<p>Hai liên kết nên có trong bài: một liên kết nội bộ tới <a href="/category/' . $category_slug . '/">danh mục Hướng dẫn</a> của chính trang, và một liên kết tới nguồn chính thức như <a href="' . self::SOURCES[0] . '">WordPress Roles and Capabilities</a> hoặc <a href="' . self::SOURCES[1] . '">Google SEO Starter Guide</a>.</p>',
				'<h2>Kết luận</h2>',
				'<p>Một bài viết rõ ràng cho phép người đọc đi theo từng bước mà không phải đoán. Hãy giữ cấu trúc này khi bổ sung nội dung mới, và kiểm tra lại trên trang thật sau mỗi lần sửa.</p>',
			)
		);
	}
}

/** Allowlisted public handoff writer. */
final class Handoff {
	/** Top-level allowlist of the public record. */
	private const ALLOWED = array( 'suite', 'target', 'campaign', 'action', 'recorded_at', 'site_url', 'blog', 'revision', 'fingerprint', 'journal', 'post', 'author', 'terms', 'media', 'checks', 'retained', 'incomplete', 'unknown', 'conflict', 'limits', 'cleanup', 'credential_cleanup', 'temp_cleanup' );

	/**
	 * Validate and write the handoff atomically.
	 *
	 * @param Campaign            $campaign Owned campaign.
	 * @param array<string,mixed> $record Public record.
	 * @return string Written path.
	 * @throws \InvalidArgumentException For an unlisted or secret-bearing field.
	 */
	public function write( Campaign $campaign, array $record ): string {
		$record['suite'] = 'seo-review';
		foreach ( array_keys( $record ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, self::ALLOWED, true ) ) {
				throw new \InvalidArgumentException( 'Handoff field is not allowlisted.' );
			}
			if ( 1 === preg_match( '/token|bearer|nonce|cookie|passw|secret|hash|body|content|payload/i', $key ) ) {
				throw new \InvalidArgumentException( 'Handoff field looks secret-bearing.' );
			}
		}
		$campaign->ensure();
		$file = $campaign->handoff_path();
		if ( is_link( $file ) ) {
			throw new \InvalidArgumentException( 'Handoff ownership mismatch.' );
		}
		$json      = (string) json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		$temporary = $file . '.tmp-' . getmypid();
		if ( false === file_put_contents( $temporary, $json, LOCK_EX ) ) {
			throw new \RuntimeException( 'Handoff is not writable.' );
		}
		chmod( $temporary, 0600 );
		if ( ! rename( $temporary, $file ) ) {
			unlink( $temporary );
			throw new \RuntimeException( 'Handoff commit failed.' );
		}
		chmod( $file, 0600 );
		return $file;
	}

	/**
	 * Read a handoff written by the runner flow.
	 *
	 * @param string $path Absolute path.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException For an unsafe or malformed record.
	 */
	public function read( string $path ): array {
		if ( is_link( $path ) || ! is_file( $path ) ) {
			throw new \InvalidArgumentException( 'Handoff unavailable.' );
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			throw new \InvalidArgumentException( 'Handoff unreadable.' );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || 'seo-review' !== ( $data['suite'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'Handoff malformed.' );
		}
		return $data;
	}
}

/** HTTPS MCP transport with bounded diagnostics. */
final class HttpTransport implements Transport {
	/** Reviewed MCP route. */
	private const MCP_PATH = '/wp-json/xnetvn-agent-hub-wp/mcp';

	/** Protocol version used by the reviewed adapter. */
	private const PROTOCOL = '2026-07-28';

	/** Bounded request client. */
	private McpClient $client;

	/** Next JSON-RPC id. */
	private int $next_id = 1;

	/**
	 * Bind the owned site and the run-scoped credentials.
	 *
	 * @param string                            $site_url Owned site root.
	 * @param array<string,array<string,mixed>> $actors Actor rows with token and user id.
	 * @param array<string>                     $allowed_hosts Reviewed hosts.
	 */
	public function __construct( private string $site_url, private array $actors, array $allowed_hosts ) {
		$this->client = new McpClient( $site_url, $allowed_hosts, true );
	}

	/**
	 * Send one envelope through the reviewed adapter.
	 *
	 * @param string              $actor Actor alias.
	 * @param string              $tool Tool suffix.
	 * @param array<string,mixed> $args Arguments.
	 * @return array{status:int,body:array<string,mixed>|null}
	 * @throws \RuntimeException For an unresolved actor or failed transport.
	 */
	public function call( string $actor, string $tool, array $args = array() ): array {
		$token = $this->actors[ $actor ]['token'] ?? null;
		if ( ! is_string( $token ) || '' === $token ) {
			throw new \RuntimeException( 'Review actor is unresolved.' );
		}
		$name   = str_starts_with( $tool, 'xnetvn-' ) ? $tool : 'xnetvn-' . $tool;
		$params = array(
			'name'      => $name,
			'arguments' => (object) $args,
			'_meta'     => array(
				'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL,
				'io.modelcontextprotocol/clientCapabilities' => (object) array(),
				'io.modelcontextprotocol/clientInfo'      => array(
					'name'    => 'seo-review',
					'version' => '1',
				),
			),
		);
		$body   = (string) json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => $this->next_id++,
				'method'  => 'tools/call',
				'params'  => $params,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		try {
			return $this->client->request(
				self::MCP_PATH,
				$body,
				$token,
				array(
					'MCP-Protocol-Version' => self::PROTOCOL,
					'Mcp-Method'           => 'tools/call',
					'Mcp-Name'             => $name,
				)
			);
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'Review MCP request did not complete.' );
		}
	}
}

/** Journaled publish, verify and reconcile flow that never deletes review data. */
final class Flow {
	/** Tools a read-only action may call. */
	private const READ_TOOLS = array( 'get-session-info', 'list-terms', 'get-term', 'list-media', 'get-media', 'get-post', 'list-posts', 'get-post-meta', 'get-media-upload-limits' );

	/** Mutating tools the publish action may call. */
	private const WRITE_TOOLS = array( 'create-term', 'upload-media-from-data', 'create-post', 'set-featured-media', 'update-post' );

	/** Site URL of this run. */
	private string $site_url;

	/** Blog id of this run. */
	private int $blog;

	/** Journal for the campaign. */
	private Journal $journal;

	/** Read-only action in progress. */
	private bool $read_only = false;

	/** Current action name. */
	private string $action = '';

	/**
	 * Bind one campaign run.
	 *
	 * @param Campaign            $campaign Owned campaign.
	 * @param Transport           $transport MCP transport.
	 * @param array<string,mixed> $config Site URL, blog, revision, fingerprint, failure and probe.
	 * @throws \InvalidArgumentException For an unapproved configuration.
	 */
	public function __construct( private Campaign $campaign, private Transport $transport, private array $config = array() ) {
		$site_url = $config['site_url'] ?? '';
		if ( ! is_string( $site_url ) || 1 !== preg_match( '#\Ahttps://[A-Za-z0-9.:-]+\z#', $site_url ) ) {
			throw new \InvalidArgumentException( 'Unapproved review site URL.' );
		}
		$blog = $config['blog'] ?? 0;
		if ( ! is_int( $blog ) || 1 > $blog ) {
			throw new \InvalidArgumentException( 'Unapproved review blog.' );
		}
		$this->site_url = rtrim( $site_url, '/' );
		$this->blog     = $blog;
		$this->journal  = new Journal( $campaign );
	}

	/**
	 * Build the flow from the lab environment and the private credential store.
	 *
	 * @param Campaign $campaign Owned campaign.
	 * @return self
	 * @throws \RuntimeException For missing or foreign credentials.
	 */
	public static function from_environment( Campaign $campaign ): self {
		$credentials = self::read_credentials( $campaign );
		$allowed     = array();
		foreach ( array( 'RTL_ALIAS_WP69', 'RTL_ALIAS_WP71', 'RTL_DOMAIN_WP69', 'RTL_DOMAIN_WP71', 'XNETVN_RTL_ALIAS', 'XNETVN_RTL_DOMAIN' ) as $name ) {
			$value = getenv( $name );
			if ( is_string( $value ) && '' !== $value ) {
				$allowed[] = $value;
			}
		}
		$host = parse_url( (string) ( $credentials['site_url'] ?? '' ), PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) {
			$allowed[] = $host;
		}
		return new self(
			$campaign,
			new HttpTransport( (string) $credentials['site_url'], (array) $credentials['actors'], array_values( array_unique( $allowed ) ) ),
			array(
				'site_url'    => (string) $credentials['site_url'],
				'blog'        => (int) ( $credentials['blog'] ?? 1 ),
				'revision'    => self::env_or( 'XNETVN_SEO_REVIEW_REVISION', 'unknown' ),
				'fingerprint' => self::env_or( 'XNETVN_SEO_REVIEW_FINGERPRINT', 'unknown' ),
				'fault'       => self::env_or( 'XNETVN_SEO_REVIEW_FAULT', '' ),
				'probe'       => null,
			)
		);
	}

	/**
	 * Run one action under the campaign lock.
	 *
	 * @param string $action publish, verify or reconcile.
	 * @return array<string,mixed> Secret-free result.
	 */
	public function run( string $action ): array {
		if ( ! in_array( $action, array( 'publish', 'verify', 'reconcile' ), true ) ) {
			throw new \InvalidArgumentException( 'Unapproved review action.' );
		}
		$this->action    = $action;
		$this->read_only = 'publish' !== $action;
		$result          = array(
			'exit'       => 2,
			'action'     => $action,
			'checks'     => array(),
			'retained'   => array(
				'post'   => false,
				'media'  => 0,
				'terms'  => 0,
				'author' => false,
			),
			'incomplete' => array(),
			'unknown'    => array(),
			'conflict'   => array(),
			'limits'     => array(),
		);
		try {
			$this->campaign->ensure();
			$result = $this->journal->with_lock(
				function () use ( $action, $result ): array {
					$state = $this->journal->open();
					$this->assert_ownership( $state );
					return match ( $action ) {
						'publish' => $this->publish( $result ),
						'verify' => $this->verify( $result ),
						'reconcile' => $this->reconcile( $result ),
					};
				}
			);
		} catch ( FaultSignal $signal ) {
			$result['exit']      = 2;
			$result['reason']    = 'fault';
			$result['unknown'][] = '' !== $signal->getMessage() ? $signal->getMessage() : 'fault';
		} catch ( FlowFailure $failure ) {
			$result['exit']   = $failure->exit;
			$result['reason'] = $failure->classification . ':' . $failure->reason;
			if ( 'guard' === $failure->classification ) {
				$result['unknown'][] = $failure->reason;
			} elseif ( 'conflict' === $failure->classification ) {
				$result['conflict'][] = '' !== $failure->reference ? $failure->reference : $failure->reason;
			} elseif ( 'assertion' === $failure->classification ) {
				$result['incomplete'][] = $failure->reason;
			} else {
				$result['unknown'][] = '' !== $failure->reference ? $failure->reference : $failure->reason;
			}
		} catch ( \Throwable $error ) {
			$result['exit']      = 2;
			$result['reason']    = 'throwable:' . ( new \ReflectionClass( $error ) )->getShortName() . ':' . substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $error->getMessage() ), 0, 40 );
			$result['unknown'][] = 'transport';
		}
		$result                    = $this->attach_retained( $result );
		$result['handoff_written'] = $this->write_handoff( $result );
		return $result;
	}

	/**
	 * Publish the article idempotently, reusing any committed object.
	 *
	 * @param array<string,mixed> $result Journaled flow result.
	 * @return array<string,mixed> Result with post, media and term checkpoints.
	 */
	private function publish( array $result ): array {
		$author   = $this->session( 'author' );
		$operator = $this->session( 'operator' );
		$this->journal->checkpoint(
			'author',
			array(
				'id'   => $author['id'],
				'role' => $author['role'],
			)
		);
		$this->journal->checkpoint(
			'operator',
			array(
				'id'   => $operator['id'],
				'role' => $operator['role'],
			)
		);

		$tag   = $this->campaign->campaign_tag();
		$terms = array( 'category' => ContentPlan::category( $tag ) );
		foreach ( ContentPlan::tags( $tag ) as $index => $tag_spec ) {
			$terms[ 'tag-' . ( $index + 1 ) ] = $tag_spec;
		}
		$term_ids = array();
		foreach ( $terms as $key => $spec ) {
			if ( 'media-partial' === $this->fault() && 'tag-1' === $key ) {
				throw new FaultSignal( 'media-partial' );
			}
			$term_ids[ $key ] = $this->ensure_term( $key, $spec );
		}

		$media = array();
		foreach ( ContentPlan::images() as $index => $image ) {
			if ( 'media-partial' === $this->fault() && 1 === $index ) {
				throw new FaultSignal( 'media-partial' );
			}
			$media[ $image['slot'] ] = $this->ensure_media( $image, $author['id'] );
		}

		$post               = $this->ensure_post( $author['id'], $media, $term_ids );
		$result['checks']   = $this->inspect( (int) $post['id'], $term_ids, $media, $author['id'] );
		$result['retained'] = array(
			'post'   => true,
			'media'  => count( $media ),
			'terms'  => count( $term_ids ),
			'author' => true,
		);
		$result['exit']     = $this->passes( $result['checks'] ) ? 0 : 1;
		if ( 1 === $result['exit'] ) {
			foreach ( $result['checks'] as $check => $ok ) {
				if ( true !== $ok ) {
					$result['incomplete'][] = $check;
				}
			}
		}
		return $result;
	}

	/**
	 * Read-only verification of every retained component.
	 *
	 * @param array<string,mixed> $result Journaled flow result.
	 * @return array<string,mixed> Result with per-component checks and exit code.
	 */
	private function verify( array $result ): array {
		$post = $this->journal->object( 'post:bai-viet' );
		if ( null === $post ) {
			throw new FlowFailure( 'guard', 'post-not-committed', 'post:bai-viet' );
		}
		$author    = $this->session( 'author' );
		$media     = array();
		$media_ids = array();
		foreach ( ContentPlan::images() as $image ) {
			$row = $this->journal->object( 'media:' . $image['slot'] );
			if ( null === $row ) {
				throw new FlowFailure( 'guard', 'media-not-committed', 'media:' . $image['slot'] );
			}
			$media_ids[ $image['slot'] ] = (int) $row['id'];
			$media[ $image['slot'] ]     = $row;
		}
		$term_ids = array();
		$tag      = $this->campaign->campaign_tag();
		$terms    = array( 'category' => ContentPlan::category( $tag ) );
		foreach ( ContentPlan::tags( $tag ) as $index => $spec ) {
			$terms[ 'tag-' . ( $index + 1 ) ] = $spec;
		}
		foreach ( array_keys( $terms ) as $key ) {
			$row = $this->journal->object( 'term:' . $key );
			if ( null === $row ) {
				throw new FlowFailure( 'guard', 'term-not-committed', 'term:' . $key );
			}
			$term_ids[ $key ] = (int) $row['id'];
		}

		$read   = $this->success(
			'get-post',
			array(
				'id'             => (int) $post['id'],
				'content_format' => 'raw',
			)
		);
		$digest = $this->post_digest( $read );
		if ( isset( $post['digest'] ) && $post['digest'] !== $digest ) {
			$this->journal->set_operation(
				'post:bai-viet',
				array(
					'phase'         => 'conflict',
					'tool'          => 'get-post',
					'object'        => 'post:bai-viet',
					'request_ended' => true,
				)
			);
			throw new FlowFailure( 'conflict', 'user-edit', 'post', 2 );
		}
		if ( isset( $post['modified_gmt'] ) && ( $read['modified_gmt'] ?? '' ) !== $post['modified_gmt'] ) {
			$result['limits'][] = 'modified-changed-same-content';
		}
		$result['checks']   = $this->inspect( (int) $post['id'], $term_ids, $media, $author['id'] );
		$result['retained'] = array(
			'post'   => true,
			'media'  => count( $media ),
			'terms'  => count( $term_ids ),
			'author' => true,
		);
		$result['exit']     = $this->passes( $result['checks'] ) ? 0 : 1;
		if ( 1 === $result['exit'] ) {
			foreach ( $result['checks'] as $check => $ok ) {
				if ( true !== $ok ) {
					$result['incomplete'][] = $check;
				}
			}
		}
		return $result;
	}

	/**
	 * Read-only reconciliation of every unresolved journal operation.
	 *
	 * @param array<string,mixed> $result Journaled flow result.
	 * @return array<string,mixed> Result with reconciled journal state.
	 */
	private function reconcile( array $result ): array {
		$checkpoint = $this->journal->state()['checkpoints']['author'] ?? null;
		$author_id  = is_array( $checkpoint ) ? (int) ( $checkpoint['id'] ?? 0 ) : 0;
		foreach ( $this->journal->state()['operations'] as $operation => $row ) {
			if ( ! is_string( $operation ) || ! is_array( $row ) ) {
				continue;
			}
			$phase = (string) ( $row['phase'] ?? 'intent' );
			if ( in_array( $phase, array( 'committed', 'conflict', 'not-committed' ), true ) ) {
				continue;
			}
			$this->resolve_operation( $operation, $row, $author_id );
		}
		$result['checks']['reconciled'] = true;
		foreach ( $this->journal->state()['operations'] as $operation => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$phase = (string) ( $row['phase'] ?? 'intent' );
			if ( in_array( $phase, array( 'unknown', 'conflict', 'partial', 'unresolved' ), true ) ) {
				$result['unknown'][]            = (string) ( $row['object'] ?? $operation );
				$result['checks']['reconciled'] = false;
			}
		}
		$result['retained'] = $this->retained_from_journal();
		$result['exit']     = $result['checks']['reconciled'] ? 0 : 2;
		return $result;
	}

	/**
	 * Resolve one journaled operation from persisted evidence.
	 *
	 * @param string              $operation Journal operation name.
	 * @param array<string,mixed> $row       Recorded journal row.
	 * @param int                 $author_id Owning author user id.
	 */
	private function resolve_operation( string $operation, array $row, int $author_id ): void {
		$object     = (string) ( $row['object'] ?? $operation );
		$found      = $this->find_persisted( $object, $row, $author_id );
		$classifier = new Classifier();
		$class      = $classifier->classify( null !== $found, ( $row['request_ended'] ?? false ) === true, $row );
		if ( 'committed' === $class && null !== $found ) {
			$this->journal->put_object( $object, $found );
			$this->journal->set_operation(
				$operation,
				array(
					'phase'         => 'committed',
					'object'        => $object,
					'request_ended' => true,
				)
			);
			return;
		}
		if ( 'not-committed' === $class ) {
			$this->journal->set_operation(
				$operation,
				array(
					'phase'  => 'not-committed',
					'object' => $object,
				)
			);
			return;
		}
		$this->journal->set_operation(
			$operation,
			array(
				'phase'  => $class,
				'object' => $object,
			)
		);
	}

	/**
	 * Find the unique persisted object for one operation, or null.
	 *
	 * @param string              $object    Ledger object reference.
	 * @param array<string,mixed> $row       Recorded journal row.
	 * @param int                 $author_id Owning author user id.
	 * @return array<string,mixed>|null Unique match, or null when none or several match.
	 */
	private function find_persisted( string $object, array $row, int $author_id ): ?array {
		if ( str_starts_with( $object, 'post:' ) ) {
			$marker  = Marker::build( $this->campaign->target(), $this->blog, $this->campaign->campaign(), 'post', 'bai-viet' );
			$slug    = ContentPlan::slug( $this->campaign->campaign_tag() );
			$items   = $this->success(
				'list-posts',
				array(
					'author'   => array( $author_id ),
					'status'   => array( 'draft', 'pending', 'private', 'publish' ),
					'per_page' => 100,
				)
			)['items'] ?? array();
			$matches = array();
			foreach ( (array) $items as $item ) {
				if ( is_array( $item ) && ( $item['slug'] ?? '' ) === $slug && (int) ( $item['author'] ?? 0 ) === $author_id ) {
					$matches[] = $item;
				}
			}
			if ( 1 === count( $matches ) ) {
				$id = (int) $matches[0]['id'];
				return array(
					'kind'   => 'post',
					'id'     => $id,
					'owner'  => (int) ( $matches[0]['author'] ?? 0 ),
					'marker' => $marker,
					'slug'   => $slug,
				);
			}
			if ( 2 <= count( $matches ) ) {
				$this->journal->set_operation(
					'post:bai-viet',
					array(
						'phase'  => 'conflict',
						'object' => 'post:bai-viet',
						'reason' => 'duplicate-marker',
					)
				);
			}
			return null;
		}
		if ( str_starts_with( $object, 'media:' ) ) {
			$slot    = substr( $object, 6 );
			$marker  = Marker::build( $this->campaign->target(), $this->blog, $this->campaign->campaign(), 'media', $slot );
			$items   = $this->success(
				'list-media',
				array(
					'author'         => array( $author_id ),
					'per_page'       => 100,
					'content_format' => 'raw',
				)
			)['items'] ?? array();
			$matches = array();
			foreach ( (array) $items as $item ) {
				if ( is_array( $item ) && trim( (string) ( $item['description'] ?? '' ) ) === $marker ) {
					$matches[] = $item;
				}
			}
			if ( 1 === count( $matches ) ) {
				return array(
					'kind'       => 'media',
					'id'         => (int) $matches[0]['id'],
					'owner'      => (int) ( $matches[0]['author'] ?? 0 ),
					'marker'     => $marker,
					'source_url' => (string) ( $matches[0]['source_url'] ?? '' ),
				);
			}
			return null;
		}
		if ( str_starts_with( $object, 'term:' ) ) {
			$slug     = (string) ( $row['slug'] ?? '' );
			$taxonomy = (string) ( $row['tool'] ?? '' );
			if ( '' === $slug || ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
				return null;
			}
			$items = $this->success(
				'list-terms',
				array(
					'taxonomy' => $taxonomy,
					'slug'     => array( $slug ),
				)
			)['items'] ?? array();
			if ( 1 === count( (array) $items ) && is_array( $items[0] ) ) {
				return array(
					'kind'     => 'term',
					'id'       => (int) $items[0]['id'],
					'owner'    => 0,
					'slug'     => $slug,
					'taxonomy' => $taxonomy,
				);
			}
			return null;
		}
		return null;
	}

	/**
	 * Ensure one term through the operator key, reusing a unique owned slug.
	 *
	 * @param string              $key  Journal key that names the term.
	 * @param array<string,mixed> $spec Declared term payload.
	 * @return int Persisted term id.
	 */
	private function ensure_term( string $key, array $spec ): int {
		$ref      = 'term:' . $key;
		$existing = $this->journal->object( $ref );
		if ( null !== $existing ) {
			$read = $this->success(
				'get-term',
				array(
					'taxonomy' => $spec['taxonomy'],
					'id'       => (int) $existing['id'],
				)
			);
			return (int) $read['id'];
		}
		$listed = $this->success(
			'list-terms',
			array(
				'taxonomy' => $spec['taxonomy'],
				'slug'     => array( $spec['slug'] ),
			)
		);
		$items  = (array) ( $listed['items'] ?? array() );
		if ( 1 === count( $items ) && is_array( $items[0] ) ) {
			$row = array(
				'kind'     => 'term',
				'id'       => (int) $items[0]['id'],
				'owner'    => 0,
				'slug'     => $spec['slug'],
				'taxonomy' => $spec['taxonomy'],
				'origin'   => 'existing',
			);
			$this->journal->put_object( $ref, $row );
			$this->journal->set_operation(
				$ref,
				array(
					'phase'         => 'committed',
					'tool'          => $spec['taxonomy'],
					'object'        => $ref,
					'request_ended' => true,
					'slug'          => $spec['slug'],
				)
			);
			return (int) $row['id'];
		}
		if ( 1 < count( $items ) ) {
			throw new FlowFailure( 'conflict', 'duplicate-term', $ref );
		}
		$marker = Marker::build( $this->campaign->target(), $this->blog, $this->campaign->campaign(), 'term', $key );
		$this->journal->set_operation(
			$ref,
			array(
				'phase'         => 'intent',
				'tool'          => $spec['taxonomy'],
				'object'        => $ref,
				'request_ended' => false,
				'slug'          => $spec['slug'],
			)
		);
		try {
			$created = $this->success(
				'create-term',
				array(
					'taxonomy'    => $spec['taxonomy'],
					'name'        => $spec['name'],
					'slug'        => $spec['slug'],
					'description' => $marker . ' Tạo cho chiến dịch đánh giá nội dung.',
				)
			);
		} catch ( \Throwable $failure ) {
			$this->journal->set_operation(
				$ref,
				array(
					'phase'         => 'sent',
					'tool'          => $spec['taxonomy'],
					'object'        => $ref,
					'request_ended' => false,
					'slug'          => $spec['slug'],
				)
			);
			$found = $this->find_persisted(
				$ref,
				array(
					'tool' => $spec['taxonomy'],
					'slug' => $spec['slug'],
				),
				0
			);
			if ( null === $found ) {
				throw $failure;
			}
			$this->journal->put_object( $ref, $found );
			$this->journal->set_operation(
				$ref,
				array(
					'phase'         => 'committed',
					'tool'          => $spec['taxonomy'],
					'object'        => $ref,
					'request_ended' => true,
					'slug'          => $spec['slug'],
				)
			);
			return (int) $found['id'];
		}
		$this->journal->set_operation(
			$ref,
			array(
				'phase'         => 'received',
				'tool'          => $spec['taxonomy'],
				'object'        => $ref,
				'request_ended' => true,
				'slug'          => $spec['slug'],
			)
		);
		$read = $this->success(
			'get-term',
			array(
				'taxonomy' => $spec['taxonomy'],
				'id'       => (int) $created['id'],
			)
		);
		if ( (string) ( $read['slug'] ?? '' ) !== $spec['slug'] ) {
			throw new FlowFailure( 'conflict', 'term-slug-mismatch', $ref );
		}
		$row = array(
			'kind'     => 'term',
			'id'       => (int) $read['id'],
			'owner'    => 0,
			'slug'     => $spec['slug'],
			'taxonomy' => $spec['taxonomy'],
			'origin'   => 'created',
			'marker'   => $marker,
		);
		$this->journal->put_object( $ref, $row );
		$this->journal->set_operation(
			$ref,
			array(
				'phase'         => 'committed',
				'tool'          => $spec['taxonomy'],
				'object'        => $ref,
				'request_ended' => true,
				'slug'          => $spec['slug'],
			)
		);
		return (int) $row['id'];
	}

	/**
	 * Ensure one attachment through the author key with a persisted read-back.
	 *
	 * @param array<string,mixed> $image     Declared image slot, file and metadata.
	 * @param int                 $author_id Owning author user id.
	 * @return array<string,mixed> Read-back attachment record.
	 */
	private function ensure_media( array $image, int $author_id ): array {
		$ref      = 'media:' . $image['slot'];
		$existing = $this->journal->object( $ref );
		if ( null !== $existing ) {
			return $this->read_media( (int) $existing['id'], $image, $author_id );
		}
		$marker = Marker::build( $this->campaign->target(), $this->blog, $this->campaign->campaign(), 'media', $image['slot'] );
		$found  = $this->find_persisted( $ref, array(), $author_id );
		if ( null !== $found ) {
			$this->journal->put_object( $ref, $found );
			$this->journal->set_operation(
				$ref,
				array(
					'phase'         => 'committed',
					'tool'          => 'list-media',
					'object'        => $ref,
					'request_ended' => true,
				)
			);
			return $this->read_media( (int) $found['id'], $image, $author_id );
		}
		$path = $this->campaign->media_dir() . '/' . $image['file'];
		if ( ! is_file( $path ) || is_link( $path ) ) {
			throw new FlowFailure( 'guard', 'image-unavailable', $ref );
		}
		$bytes = file_get_contents( $path );
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			throw new FlowFailure( 'guard', 'image-unavailable', $ref );
		}
		$this->journal->set_operation(
			$ref,
			array(
				'phase'         => 'intent',
				'tool'          => 'upload-media-from-data',
				'object'        => $ref,
				'request_ended' => false,
			)
		);
		try {
			$created = $this->success(
				'upload-media-from-data',
				array(
					'filename'    => $image['file'],
					'mime_type'   => $image['mime'],
					'data_base64' => base64_encode( $bytes ),
					'alt_text'    => $image['alt'],
					'caption'     => $image['caption'],
					'title'       => $image['title'],
					'description' => $marker,
				)
			);
		} catch ( \Throwable $failure ) {
			$this->journal->set_operation(
				$ref,
				array(
					'phase'         => 'sent',
					'tool'          => 'upload-media-from-data',
					'object'        => $ref,
					'request_ended' => false,
				)
			);
			$found = $this->find_persisted( $ref, array(), $author_id );
			if ( null === $found ) {
				throw $failure;
			}
			$this->journal->put_object( $ref, $found );
			$this->journal->set_operation(
				$ref,
				array(
					'phase'         => 'committed',
					'tool'          => 'list-media',
					'object'        => $ref,
					'request_ended' => true,
				)
			);
			return $this->read_media( (int) $found['id'], $image, $author_id );
		}
		if ( 'media-lost-receipt' === $this->fault() ) {
			$this->journal->set_operation(
				$ref,
				array(
					'phase'         => 'sent',
					'tool'          => 'upload-media-from-data',
					'object'        => $ref,
					'request_ended' => false,
				)
			);
			throw new FaultSignal( 'media-lost-receipt' );
		}
		$read = $this->read_media( (int) $created['id'], $image, $author_id );
		$row  = array(
			'kind'       => 'media',
			'id'         => (int) $read['id'],
			'owner'      => (int) $read['author'],
			'marker'     => $marker,
			'source_url' => (string) $read['source_url'],
			'origin'     => 'created',
		);
		$this->journal->put_object( $ref, $row );
		$this->journal->set_operation(
			$ref,
			array(
				'phase'         => 'committed',
				'tool'          => 'read-back',
				'object'        => $ref,
				'request_ended' => true,
			)
		);
		return $read;
	}

	/**
	 * Read one attachment and require its owned binding.
	 *
	 * @param int                 $id        Attachment id.
	 * @param array<string,mixed> $image     Declared image slot.
	 * @param int                 $author_id Owning author user id.
	 * @return array<string,mixed> Attachment read-back.
	 */
	private function read_media( int $id, array $image, int $author_id ): array {
		$read   = $this->success(
			'get-media',
			array(
				'id'             => $id,
				'content_format' => 'raw',
			)
		);
		$url    = (string) ( $read['source_url'] ?? '' );
		$marker = Marker::build( $this->campaign->target(), $this->blog, $this->campaign->campaign(), 'media', $image['slot'] );
		$ok     = 1 <= (int) ( $read['id'] ?? 0 )
			&& (int) ( $read['author'] ?? 0 ) === $author_id
			&& (string) ( $read['mime_type'] ?? '' ) === $image['mime']
			&& '' !== $url
			&& str_starts_with( $url, $this->site_url . '/' )
			&& (int) ( $read['media_details']['width'] ?? 0 ) >= 600
			&& (int) ( $read['media_details']['height'] ?? 0 ) >= 400
			&& (int) ( $read['media_details']['filesize'] ?? 0 ) > 1024
			&& trim( (string) ( $read['description'] ?? '' ) ) === $marker
			&& (string) ( $read['alt_text'] ?? '' ) === $image['alt'];
		if ( ! $ok ) {
			throw new FlowFailure( 'conflict', 'media-binding-mismatch', 'media:' . $image['slot'] );
		}
		return $read;
	}

	/**
	 * Ensure the article exists once and is published by its own author.
	 *
	 * @param int                 $author_id Owning author user id.
	 * @param array<string,mixed> $media     Persisted media ids keyed by slot.
	 * @param array<int,int>      $term_ids  Persisted category and tag ids.
	 * @return array<string,mixed> Post read-back.
	 */
	private function ensure_post( int $author_id, array $media, array $term_ids ): array {
		$existing = $this->journal->object( 'post:bai-viet' );
		if ( null === $existing ) {
			$found = $this->find_persisted( 'post:bai-viet', array(), $author_id );
			if ( null !== $found ) {
				$this->journal->put_object( 'post:bai-viet', $found );
				$this->journal->set_operation(
					'post:bai-viet',
					array(
						'phase'         => 'committed',
						'tool'          => 'list-posts',
						'object'        => 'post:bai-viet',
						'request_ended' => true,
					)
				);
				$existing = $this->journal->object( 'post:bai-viet' );
			}
		}
		if ( null !== $existing ) {
			return $this->resume_post( $existing, $media );
		}

		$tag    = $this->campaign->campaign_tag();
		$slug   = ContentPlan::slug( $tag );
		$inline = array(
			'inline-1' => array(
				'url'     => (string) $media['inline-1']['source_url'],
				'alt'     => (string) $media['inline-1']['alt_text'],
				'caption' => (string) $media['inline-1']['caption'],
			),
			'inline-2' => array(
				'url'     => (string) $media['inline-2']['source_url'],
				'alt'     => (string) $media['inline-2']['alt_text'],
				'caption' => (string) $media['inline-2']['caption'],
			),
		);
		// The reviewed Content schema exposes no campaign custom field, so the
		// post identity is the campaign-scoped slug plus the owned taxonomy
		// relations; both are readable through MCP and journaled below.
		$marker = Marker::build( $this->campaign->target(), $this->blog, $this->campaign->campaign(), 'post', 'bai-viet' );
		$this->journal->set_operation(
			'post:bai-viet',
			array(
				'phase'         => 'intent',
				'tool'          => 'create-post',
				'object'        => 'post:bai-viet',
				'request_ended' => false,
			)
		);
		$args = array(
			'title'      => ContentPlan::TITLE,
			'slug'       => $slug,
			'excerpt'    => ContentPlan::EXCERPT,
			'content'    => ContentPlan::body( $inline, ContentPlan::category( $tag )['slug'] ),
			'status'     => 'draft',
			'categories' => array( $term_ids['category'] ),
			'tags'       => array( $term_ids['tag-1'], $term_ids['tag-2'] ),
		);
		try {
			$created = $this->success( 'create-post', $args );
		} catch ( \Throwable $failure ) {
			$this->journal->set_operation(
				'post:bai-viet',
				array(
					'phase'         => 'sent',
					'tool'          => 'create-post',
					'object'        => 'post:bai-viet',
					'request_ended' => false,
				)
			);
			$found = $this->find_persisted( 'post:bai-viet', array(), $author_id );
			if ( null === $found ) {
				throw $failure;
			}
			$this->journal->put_object( 'post:bai-viet', $found );
			$created = $found;
		}
		if ( 'post-lost-receipt' === $this->fault() ) {
			$this->journal->set_operation(
				'post:bai-viet',
				array(
					'phase'         => 'sent',
					'tool'          => 'create-post',
					'object'        => 'post:bai-viet',
					'request_ended' => false,
				)
			);
			throw new FaultSignal( 'post-lost-receipt' );
		}
		$id = (int) ( $created['id'] ?? 0 );
		if ( 1 > $id ) {
			throw new FlowFailure( 'unknown', 'create-without-id', 'post:bai-viet' );
		}
		$this->journal->set_operation(
			'post:bai-viet',
			array(
				'phase'         => 'received',
				'tool'          => 'create-post',
				'object'        => 'post:bai-viet',
				'request_ended' => true,
			)
		);
		$read = $this->read_post_raw( $id );
		if ( (int) ( $read['author'] ?? 0 ) !== $author_id || (string) ( $read['slug'] ?? '' ) !== $slug ) {
			throw new FlowFailure( 'conflict', 'post-ownership-mismatch', 'post:bai-viet' );
		}
		$featured = $this->success(
			'set-featured-media',
			array(
				'id'       => $id,
				'media_id' => (int) $media['featured']['id'],
			)
		);
		if ( (int) ( $featured['featured_media'] ?? 0 ) !== (int) $media['featured']['id'] ) {
			throw new FlowFailure( 'conflict', 'featured-binding-mismatch', 'media:featured' );
		}
		$fresh     = $this->read_post_raw( $id );
		$published = $this->success(
			'update-post',
			array(
				'id'                => $id,
				'status'            => 'publish',
				'expected_modified' => (string) ( $fresh['modified_gmt'] ?? '' ),
			)
		);
		if ( 'publish' !== (string) ( $published['status'] ?? '' ) ) {
			throw new FlowFailure( 'assertion', 'publish-refused', 'post:bai-viet', 1 );
		}
		$final = $this->read_post_raw( $id );
		$row   = array(
			'kind'         => 'post',
			'id'           => $id,
			'owner'        => (int) ( $final['author'] ?? 0 ),
			'marker'       => $marker,
			'slug'         => $slug,
			'url'          => (string) ( $final['link'] ?? '' ),
			'digest'       => $this->post_digest( $final ),
			'modified_gmt' => (string) ( $final['modified_gmt'] ?? '' ),
			'origin'       => 'created',
		);
		$this->journal->put_object( 'post:bai-viet', $row );
		$this->journal->set_operation(
			'post:bai-viet',
			array(
				'phase'         => 'committed',
				'tool'          => 'update-post',
				'object'        => 'post:bai-viet',
				'request_ended' => true,
			)
		);
		return $final;
	}

	/**
	 * Finish a retained article after an interruption without recreating it.
	 *
	 * Only the featured pointer and the publish transition are added; the
	 * content fields are never rewritten, and a changed digest stops here.
	 *
	 * @param array<string,mixed>               $existing Journaled post row.
	 * @param array<string,array<string,mixed>> $media Journaled media rows keyed by slot.
	 * @return array<string,mixed> Persisted post after the transition.
	 */
	private function resume_post( array $existing, array $media ): array {
		$id   = (int) ( $existing['id'] ?? 0 );
		$read = $this->read_post_raw( $id );
		if ( isset( $existing['digest'] ) && (string) $existing['digest'] !== $this->post_digest( $read ) ) {
			$this->journal->set_operation(
				'post:bai-viet',
				array(
					'phase'         => 'conflict',
					'tool'          => 'get-post',
					'object'        => 'post:bai-viet',
					'request_ended' => true,
				)
			);
			throw new FlowFailure( 'conflict', 'user-edit', 'post', 2 );
		}
		$featured = (int) ( $media['featured']['id'] ?? 0 );
		if ( 0 < $featured && (int) ( $read['featured_media'] ?? 0 ) !== $featured ) {
			$this->success(
				'set-featured-media',
				array(
					'id'       => $id,
					'media_id' => $featured,
				)
			);
			$read = $this->read_post_raw( $id );
		}
		if ( 'publish' !== (string) ( $read['status'] ?? '' ) ) {
			$this->success(
				'update-post',
				array(
					'id'                => $id,
					'status'            => 'publish',
					'expected_modified' => (string) ( $read['modified_gmt'] ?? '' ),
				)
			);
			$read = $this->read_post_raw( $id );
		}
		if ( 'publish' !== (string) ( $read['status'] ?? '' ) ) {
			throw new FlowFailure( 'assertion', 'publish-refused', 'post:bai-viet', 1 );
		}
		$this->journal->put_object(
			'post:bai-viet',
			array(
				'kind'         => 'post',
				'id'           => $id,
				'owner'        => (int) ( $read['author'] ?? 0 ),
				'slug'         => (string) ( $read['slug'] ?? '' ),
				'url'          => (string) ( $read['link'] ?? '' ),
				'digest'       => $this->post_digest( $read ),
				'modified_gmt' => (string) ( $read['modified_gmt'] ?? '' ),
			)
		);
		$this->journal->set_operation(
			'post:bai-viet',
			array(
				'phase'         => 'committed',
				'tool'          => 'resume',
				'object'        => 'post:bai-viet',
				'request_ended' => true,
			)
		);
		return $read;
	}

	/**
	 * Read one post with raw content.
	 *
	 * @param int $id Post id.
	 * @return array<string,mixed> Post payload with raw content.
	 */
	private function read_post_raw( int $id ): array {
		return $this->success(
			'get-post',
			array(
				'id'             => $id,
				'content_format' => 'raw',
			)
		);
	}

	/**
	 * Digest the owner-visible article fields.
	 *
	 * @param array<string,mixed> $post Post payload.
	 * @return string SHA-256 digest of the owner-visible fields.
	 */
	private function post_digest( array $post ): string {
		return Journal::digest(
			array(
				'title'          => (string) ( $post['title'] ?? '' ),
				'slug'           => (string) ( $post['slug'] ?? '' ),
				'excerpt'        => (string) ( $post['excerpt'] ?? '' ),
				'content'        => (string) ( $post['content'] ?? '' ),
				'status'         => (string) ( $post['status'] ?? '' ),
				'categories'     => array_values( (array) ( $post['categories'] ?? array() ) ),
				'tags'           => array_values( (array) ( $post['tags'] ?? array() ) ),
				'featured_media' => (int) ( $post['featured_media'] ?? 0 ),
			)
		);
	}

	/**
	 * Read-only component inspection for one article.
	 *
	 * @param int                 $post_id   Post id.
	 * @param array<int,int>      $term_ids  Persisted term ids.
	 * @param array<string,mixed> $media     Persisted media ids keyed by slot.
	 * @param int                 $author_id Owning author user id.
	 * @return array<string,bool> Named component checks.
	 */
	private function inspect( int $post_id, array $term_ids, array $media, int $author_id ): array {
		$post                 = $this->read_post_raw( $post_id );
		$content              = (string) ( $post['content'] ?? '' );
		$checks               = array();
		$checks['title']      = ContentPlan::TITLE === (string) ( $post['title'] ?? '' );
		$checks['slug']       = ContentPlan::slug( $this->campaign->campaign_tag() ) === (string) ( $post['slug'] ?? '' );
		$checks['excerpt']    = ContentPlan::EXCERPT === (string) ( $post['excerpt'] ?? '' );
		$checks['status']     = 'publish' === (string) ( $post['status'] ?? '' );
		$checks['author']     = (int) ( $post['author'] ?? 0 ) === $author_id;
		$checks['structure']  = substr_count( $content, '<h2' ) >= 4 && substr_count( $content, '<h3' ) >= 3 && ! str_contains( $content, '<h1' );
		$checks['formatting'] = str_contains( $content, '<strong' ) && str_contains( $content, '<li>' );
		$checks['links']      = str_contains( $content, '/category/' ) && str_contains( $content, ContentPlan::SOURCES[0] ) && str_contains( $content, ContentPlan::SOURCES[1] );
		$checks['taxonomy']   = in_array( (int) $term_ids['category'], array_map( 'intval', (array) ( $post['categories'] ?? array() ) ), true )
			&& in_array( (int) $term_ids['tag-1'], array_map( 'intval', (array) ( $post['tags'] ?? array() ) ), true )
			&& in_array( (int) $term_ids['tag-2'], array_map( 'intval', (array) ( $post['tags'] ?? array() ) ), true );
		$checks['featured']   = (int) ( $post['featured_media'] ?? 0 ) === (int) ( $media['featured']['id'] ?? 0 );
		$checks['media']      = true;
		$checks['assets']     = true;
		$urls                 = array();
		foreach ( $media as $slot => $row ) {
			$id   = (int) ( $row['id'] ?? 0 );
			$read = $this->success(
				'get-media',
				array(
					'id'             => $id,
					'content_format' => 'raw',
				)
			);
			$url  = (string) ( $read['source_url'] ?? '' );
			if ( 1 > (int) ( $read['media_details']['width'] ?? 0 ) || '' === $url ) {
				$checks['assets'] = false;
			}
			$urls[ $slot ] = $url;
			if ( 'featured' !== $slot && ( '' === $url || ! str_contains( $content, $url ) ) ) {
				$checks['media'] = false;
			}
		}
		foreach ( array( 'inline-1', 'inline-2' ) as $slot ) {
			if ( ! isset( $urls[ $slot ] ) || '' === $urls[ $slot ] ) {
				$checks['media'] = false;
			}
		}
		$checks['http'] = $this->probe( (string) ( $post['link'] ?? '' ) );
		return $checks;
	}

	/**
	 * Probe the public permalink with the reviewed allowlist.
	 *
	 * @param string $url Public permalink.
	 * @return bool True when the allowlisted probe serves the expected title.
	 */
	private function probe( string $url ): bool {
		$probe = $this->config['probe'] ?? null;
		if ( is_callable( $probe ) ) {
			$result = $probe( $url );
			return is_array( $result ) && true === ( $result['ok'] ?? false );
		}
		$host = parse_url( $url, PHP_URL_HOST );
		$site = parse_url( $this->site_url, PHP_URL_HOST );
		if ( ! is_string( $host ) || $host !== $site || ! str_starts_with( $url, $this->site_url . '/' ) ) {
			return false;
		}
		$handle = curl_init( $url );
		if ( false === $handle ) {
			return false;
		}
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_USERAGENT      => 'xnetvn-seo-review/1',
			)
		);
		$body   = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );
		if ( 200 !== $status || ! is_string( $body ) ) {
			return false;
		}
		$title = html_entity_decode( ContentPlan::TITLE, ENT_QUOTES, 'UTF-8' );
		return str_contains( $body, $title ) || str_contains( html_entity_decode( $body, ENT_QUOTES, 'UTF-8' ), $title );
	}

	/**
	 * Resolve one actor session and its effective role.
	 *
	 * @param string $actor Configured actor name (`author` or `operator`).
	 * @return array<string,mixed> Actor user id and effective role.
	 */
	private function session( string $actor ): array {
		$data  = $this->success( 'get-session-info', array(), $actor );
		$user  = (array) ( $data['user'] ?? array() );
		$id    = (int) ( $user['id'] ?? 0 );
		$roles = array_values( (array) ( $user['roles'] ?? array() ) );
		if ( 1 > $id || array() === $roles ) {
			throw new FlowFailure( 'guard', 'session-unavailable', $actor );
		}
		$role = (string) $roles[0];
		if ( 'author' === $actor && 'author' !== $role ) {
			throw new FlowFailure( 'guard', 'session-role-mismatch', $actor );
		}
		if ( 'operator' === $actor && ! in_array( $role, array( 'editor', 'administrator' ), true ) ) {
			throw new FlowFailure( 'guard', 'session-role-mismatch', $actor );
		}
		return array(
			'id'   => $id,
			'role' => $role,
		);
	}

	/**
	 * Require a structured success and unwrap it.
	 *
	 * @param string              $tool  Tool name.
	 * @param array<string,mixed> $args  Tool arguments.
	 * @param string|null         $actor Optional actor name; defaults to the operator.
	 * @return array<string,mixed> Structured content of the successful response.
	 */
	private function success( string $tool, array $args, ?string $actor = null ): array {
		if ( $this->read_only && ! in_array( $tool, self::READ_TOOLS, true ) ) {
			throw new FlowFailure( 'guard', 'read-only-action-mutation', $tool );
		}
		if ( ! $this->read_only && ! in_array( $tool, self::READ_TOOLS, true ) && ! in_array( $tool, self::WRITE_TOOLS, true ) ) {
			throw new FlowFailure( 'guard', 'unapproved-tool', $tool );
		}
		if ( null === $actor ) {
			$actor = in_array( $tool, array( 'create-term', 'list-terms', 'get-term' ), true ) ? 'operator' : 'author';
		}
		$response = $this->transport->call( $actor, $tool, $args );
		$status   = (int) ( $response['status'] ?? 0 );
		$body     = is_array( $response['body'] ?? null ) ? $response['body'] : array();
		if ( 200 !== $status || true === ( $body['result']['isError'] ?? true ) ) {
			$reason = 'transport';
			$text   = (string) ( $body['result']['content'][0]['text'] ?? '' );
			if ( 1 === preg_match( '/"error"\s*:\s*"([a-z_]+)"/', $text, $match ) ) {
				$reason = $match[1];
			} elseif ( 1 === preg_match( '/\b([a-z_]{3,40})\b/', $text, $match ) ) {
				$reason = $match[1];
			}
			throw new FlowFailure( in_array( $reason, array( 'conflict', 'invalid_state' ), true ) ? 'conflict' : 'assertion', $reason, $tool, in_array( $reason, array( 'conflict', 'invalid_state' ), true ) ? 2 : 1 );
		}
		$data = $body['result']['structuredContent'] ?? null;
		if ( ! is_array( $data ) ) {
			throw new FlowFailure( 'assertion', 'missing-structured-content', $tool, 1 );
		}
		return $data;
	}

	/** Check the configured test-owned fault point. */
	private function fault(): string {
		return (string) ( $this->config['fault'] ?? '' );
	}

	/**
	 * Require every check to be true.
	 *
	 * @param array<string,bool> $checks Named checks.
	 * @return bool True when every check is exactly true.
	 */
	private function passes( array $checks ): bool {
		foreach ( $checks as $value ) {
			if ( true !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Require journal ownership of this exact tuple.
	 *
	 * @param array<string,mixed> $state Journal state to verify.
	 */
	private function assert_ownership( array $state ): void {
		if ( $this->campaign->target() !== ( $state['target'] ?? '' ) || $this->campaign->campaign() !== ( $state['campaign'] ?? '' ) || (int) ( $state['blog'] ?? 0 ) !== $this->blog ) {
			throw new FlowFailure( 'guard', 'foreign-journal' );
		}
		if ( '' !== (string) ( $state['site_url'] ?? '' ) && (string) $state['site_url'] !== $this->site_url ) {
			throw new FlowFailure( 'guard', 'foreign-site' );
		}
		if ( '' === (string) ( $state['site_url'] ?? '' ) ) {
			$state['site_url'] = $this->site_url;
			$this->journal->save( $state );
		}
	}

	/**
	 * Read one environment value with an explicit default.
	 *
	 * @param string $name Variable name.
	 * @param string $default Default when unset.
	 */
	private static function env_or( string $name, string $default ): string {
		$value = getenv( $name );
		return is_string( $value ) && '' !== $value ? $value : $default;
	}

	/**
	 * Read the private credential store without exposing it.
	 *
	 * @param Campaign $campaign Validated campaign.
	 * @return array<string,mixed> Credential document with site URL and actor tokens.
	 */
	private static function read_credentials( Campaign $campaign ): array {
		$file = $campaign->credentials_path();
		if ( is_link( $file ) || ! is_file( $file ) ) {
			throw new \RuntimeException( 'Review credentials unavailable.' );
		}
		$raw = file_get_contents( $file );
		if ( ! is_string( $raw ) ) {
			throw new \RuntimeException( 'Review credentials unreadable.' );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['site_url'], $data['actors'] ) || ! is_array( $data['actors'] ) || ! isset( $data['actors']['author']['token'], $data['actors']['operator']['token'] ) ) {
			throw new \RuntimeException( 'Review credentials malformed.' );
		}
		return $data;
	}

	/**
	 * Fill the retained counts from the journal.
	 *
	 * @param array<string,mixed> $result Flow result.
	 * @return array<string,mixed> Result with retained counts merged in.
	 */
	private function attach_retained( array $result ): array {
		$retained           = $this->retained_from_journal();
		$result['retained'] = array_merge( $result['retained'], $retained );
		return $result;
	}

	/**
	 * Read retained counts from journaled objects.
	 *
	 * @return array<string,int> Retained counts keyed by object type.
	 */
	private function retained_from_journal(): array {
		$objects = $this->journal->state()['objects'] ?? array();
		$counts  = array(
			'post'   => false,
			'media'  => 0,
			'terms'  => 0,
			'author' => false,
		);
		foreach ( (array) $objects as $ref => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( 'post:bai-viet' === $ref ) {
				$counts['post'] = true;
			}
			if ( 'media' === ( $row['kind'] ?? '' ) ) {
				++$counts['media'];
			}
			if ( 'term' === ( $row['kind'] ?? '' ) ) {
				++$counts['terms'];
			}
			if ( 'user' === ( $row['kind'] ?? '' ) ) {
				$counts['author'] = true;
			}
		}
		$checkpoint       = $this->journal->state()['checkpoints']['author'] ?? null;
		$counts['author'] = $counts['author'] || is_array( $checkpoint );
		return $counts;
	}

	/**
	 * Write the allowlisted handoff for this run.
	 *
	 * @param array<string,mixed> $result Safe result.
	 * @return bool Whether the public record was written.
	 */
	private function write_handoff( array $result ): bool {
		$state      = $this->journal->state();
		$post       = $this->journal->object( 'post:bai-viet' );
		$author     = $state['checkpoints']['author'] ?? array();
		$category   = $this->journal->object( 'term:category' );
		$tag_one    = $this->journal->object( 'term:tag-1' );
		$tag_two    = $this->journal->object( 'term:tag-2' );
		$featured   = $this->journal->object( 'media:featured' );
		$inline_one = $this->journal->object( 'media:inline-1' );
		$inline_two = $this->journal->object( 'media:inline-2' );
		$record     = array(
			'target'             => $this->campaign->target(),
			'campaign'           => $this->campaign->campaign(),
			'action'             => $this->action,
			'recorded_at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site_url'           => $this->site_url,
			'blog'               => $this->blog,
			'revision'           => (string) ( $this->config['revision'] ?? 'unknown' ),
			'fingerprint'        => (string) ( $this->config['fingerprint'] ?? 'unknown' ),
			'journal'            => $this->campaign->journal_path(),
			'post'               => array(
				'id'       => (int) ( $post['id'] ?? 0 ),
				'url'      => (string) ( $post['url'] ?? '' ),
				'title'    => ContentPlan::TITLE,
				'status'   => 'publish',
				'edit_url' => 1 > (int) ( $post['id'] ?? 0 ) ? '' : $this->site_url . '/wp-admin/post.php?post=' . (int) ( $post['id'] ?? 0 ) . '&action=edit',
			),
			'author'             => array(
				'id'   => (int) ( is_array( $author ) ? ( $author['id'] ?? 0 ) : 0 ),
				'role' => (string) ( is_array( $author ) ? ( $author['role'] ?? '' ) : '' ),
			),
			'terms'              => array(
				'category' => array( (int) ( $category['id'] ?? 0 ) ),
				'tags'     => array( (int) ( $tag_one['id'] ?? 0 ), (int) ( $tag_two['id'] ?? 0 ) ),
			),
			'media'              => array(
				'featured' => (int) ( $featured['id'] ?? 0 ),
				'inline'   => array( (int) ( $inline_one['id'] ?? 0 ), (int) ( $inline_two['id'] ?? 0 ) ),
			),
			'checks'             => $result['checks'],
			'retained'           => $result['retained'],
			'incomplete'         => array_values( $result['incomplete'] ),
			'unknown'            => array_values( $result['unknown'] ),
			'conflict'           => array_values( $result['conflict'] ),
			'limits'             => array_values( $result['limits'] ),
			'cleanup'            => array(
				'keys'    => 'pending-runner',
				'temp'    => 'pending-runner',
				'article' => 'retained',
			),
			'credential_cleanup' => false,
			'temp_cleanup'       => false,
		);
		try {
			( new Handoff() )->write( $this->campaign, $record );
			return true;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Remove run-scoped credentials and bounded temporary files only.
	 *
	 * @param Campaign $campaign Owned campaign.
	 * @return array{credentials_removed:bool,temp_removed:bool}
	 * @throws \RuntimeException For a symlinked credential store.
	 */
	public static function cleanup_run_files( Campaign $campaign ): array {
		$credentials = $campaign->credentials_path();
		$removed     = false;
		if ( is_link( $credentials ) ) {
			throw new \RuntimeException( 'Credential store ownership mismatch.' );
		}
		if ( is_file( $credentials ) ) {
			$removed = unlink( $credentials );
		}
		$temp = false;
		foreach ( (array) glob( $campaign->media_dir() . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) && ! is_link( $file ) ) {
				unlink( $file );
				$temp = true;
			}
		}
		foreach ( (array) glob( $campaign->private_dir() . '/*.tmp-*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) && ! is_link( $file ) ) {
				unlink( $file );
			}
		}
		return array(
			'credentials_removed' => $removed,
			'temp_removed'        => $temp,
		);
	}
}

/** Standalone toolbox entry point. */
final class Cli {
	/**
	 * Read one reviewed action from the environment and report a safe result.
	 *
	 * @return int Exit code for the runner.
	 */
	public static function run(): int {
		$root = getenv( 'XNETVN_SEO_REVIEW_HOME' );
		if ( ! is_string( $root ) || '' === $root ) {
			$root = Campaign::DEFAULT_ROOT;
		}
		$action = (string) getenv( 'XNETVN_SEO_REVIEW_ACTION' );
		try {
			$flow   = Flow::from_environment( new Campaign( $root, (string) getenv( 'XNETVN_SEO_REVIEW_TARGET' ), (string) getenv( 'XNETVN_SEO_REVIEW_CAMPAIGN' ) ) );
			$result = $flow->run( $action );
		} catch ( \Throwable $error ) {
			$safe = preg_replace( '/[^a-z0-9-]/', '', strtolower( $error->getMessage() ) );
			$text = (string) json_encode(
				array(
					'suite'  => 'seo-review',
					'action' => $action,
					'exit'   => 2,
					'error'  => is_string( $safe ) ? substr( $safe, 0, 80 ) : 'guard',
				)
			);
			fwrite( STDOUT, $text . "\n" );
			return 2;
		}
		fwrite( STDOUT, (string) json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		return (int) $result['exit'];
	}
}

if ( PHP_SAPI === 'cli' && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( Cli::run() );
}
