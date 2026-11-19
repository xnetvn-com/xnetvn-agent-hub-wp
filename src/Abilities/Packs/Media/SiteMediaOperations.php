<?php
/**
 * Accountless media operations on core domain APIs.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Media;

use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_User;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\PartialFailure;
use XNetVN\AgentHubWP\Abilities\Packs\Content\EditorialAuthors;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;
use XNetVN\AgentHubWP\Abilities\SafeEditorialHtml;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Support\Http\NativeDnsResolver;
use XNetVN\AgentHubWP\Support\Http\SafeRemoteFetcher;

/** Never evaluates a WordPress capability for a machine request or impersonates a user. */
final class SiteMediaOperations {

	/** The seven reviewed machine media names in pack order. */
	public const NAMES = array(
		'xnetvn/list-media',
		'xnetvn/get-media',
		'xnetvn/update-media',
		'xnetvn/upload-media-from-url',
		'xnetvn/upload-media-from-data',
		'xnetvn/delete-media',
		'xnetvn/get-media-upload-limits',
	);

	/** Reviewed base capability class for an allowed attachment parent. */
	private const PARENT_CAPABILITIES = array(
		'post' => array(
			'edit_post'              => 'edit_post',
			'read_post'              => 'read_post',
			'delete_post'            => 'delete_post',
			'edit_posts'             => 'edit_posts',
			'edit_others_posts'      => 'edit_others_posts',
			'edit_published_posts'   => 'edit_published_posts',
			'edit_private_posts'     => 'edit_private_posts',
			'read_private_posts'     => 'read_private_posts',
			'publish_posts'          => 'publish_posts',
			'delete_posts'           => 'delete_posts',
			'delete_others_posts'    => 'delete_others_posts',
			'delete_published_posts' => 'delete_published_posts',
			'delete_private_posts'   => 'delete_private_posts',
			'read'                   => 'read',
		),
		'page' => array(
			'edit_post'              => 'edit_page',
			'read_post'              => 'read_page',
			'delete_post'            => 'delete_page',
			'edit_posts'             => 'edit_pages',
			'edit_others_posts'      => 'edit_others_pages',
			'edit_published_posts'   => 'edit_published_pages',
			'edit_private_posts'     => 'edit_private_pages',
			'read_private_posts'     => 'read_private_pages',
			'publish_posts'          => 'publish_pages',
			'delete_posts'           => 'delete_pages',
			'delete_others_posts'    => 'delete_others_pages',
			'delete_published_posts' => 'delete_published_pages',
			'delete_private_posts'   => 'delete_private_pages',
			'read'                   => 'read',
		),
	);

	/** The single slot WordPress core derives from edit_posts. */
	private const CORE_CREATE_SLOT = 'create_posts';

	/** Reviewed readable parent statuses; trash, auto-draft and inherit are excluded. */
	private const READ_STATUSES = array( 'draft', 'pending', 'private', 'publish', 'future' );

	/** Reviewed attachment statuses. */
	private const ATTACHMENT_STATUSES = array( 'inherit', 'private' );

	/** Reviewed media prefixes. */
	private const MEDIA_PREFIXES = array( 'image', 'video', 'audio', 'text', 'application' );

	/** Reviewed read orderings. */
	private const ORDER_BY = array( 'date', 'modified', 'title', 'id', 'slug', 'author', 'relevance' );

	/** Reviewed MIME shape. */
	private const MIME_PATTERN = '~^[a-z0-9.+-]+/[a-z0-9.+-]+$~';

	/** Attachment alt-text meta key. */
	private const ALT_KEY = '_wp_attachment_image_alt';

	/** Bounded encoded data ceiling from the declared schema. */
	private const MAX_ENCODED_BYTES = 11184812;

	/** Bounded remote URL length. */
	private const MAX_URL_LENGTH = 2048;

	/** Site restrictions.
	 *
	 * @var SiteSettings
	 */
	private SiteSettings $settings;

	/** Attribution-only eligibility guard.
	 *
	 * @var EditorialAuthors
	 */
	private EditorialAuthors $authors;

	/** Scoped editorial HTML boundary.
	 *
	 * @var SafeEditorialHtml
	 */
	private SafeEditorialHtml $html;

	/** Reviewed upload policy.
	 *
	 * @var UploadPolicy
	 */
	private UploadPolicy $upload;

	/** Reviewed attachment projection.
	 *
	 * @var MediaProjection
	 */
	private MediaProjection $projection;

	/**
	 * Bind optional reviewed dependencies.
	 *
	 * @param SiteSettings           $settings Site limits.
	 * @param EditorialAuthors|null  $authors Attribution guard.
	 * @param SafeEditorialHtml|null $html Scoped HTML boundary.
	 */
	public function __construct( SiteSettings $settings, ?EditorialAuthors $authors = null, ?SafeEditorialHtml $html = null ) {
		$this->settings   = $settings;
		$this->authors    = $authors ?? new EditorialAuthors();
		$this->html       = $html ?? new SafeEditorialHtml();
		$this->upload     = new UploadPolicy( $settings );
		$this->projection = new MediaProjection();
	}

	/**
	 * Run one reviewed machine media operation without a human closure or a user.
	 *
	 * @param string              $canonical_name Canonical operation name.
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @param SiteAuthContext     $context Verified machine identity.
	 * @return mixed
	 */
	public function execute( string $canonical_name, array $arguments, SiteAuthContext $context ): mixed {
		if ( ! in_array( $canonical_name, self::NAMES, true ) ) {
			return R::error( 'invalid_params' );
		}
		if ( $context->blog_id() !== get_current_blog_id() || 0 !== get_current_user_id() ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_tool_unavailable', 'The tool is unavailable.' );
		}
		try {
			return match ( $canonical_name ) {
				'xnetvn/list-media'              => $this->list_media( $arguments ),
				'xnetvn/get-media'               => $this->get_media( $arguments ),
				'xnetvn/update-media'            => $this->update_media( $arguments ),
				'xnetvn/upload-media-from-url'   => $this->upload_media_from_url( $arguments ),
				'xnetvn/upload-media-from-data'  => $this->upload_media_from_data( $arguments ),
				'xnetvn/delete-media'            => $this->delete_media( $arguments ),
				'xnetvn/get-media-upload-limits' => $this->upload_limits(),
			};
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
	}

	/**
	 * List persisted attachments through the fixed object predicate.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function list_media( array $arguments ): mixed {
		$format = $this->content_format( $arguments );
		if ( is_wp_error( $format ) ) {
			return $format;
		}
		$fields = $this->projection_fields( $arguments );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$pagination = $this->pagination( $arguments );
		if ( is_wp_error( $pagination ) ) {
			return $pagination;
		}
		$order = $this->ordering( $arguments );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$status = $this->attachment_status( $arguments );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		$query = array(
			'post_type'           => 'attachment',
			'post_status'         => $status,
			'paged'               => $pagination['page'],
			'posts_per_page'      => $pagination['per_page'],
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
			'orderby'             => $order['orderby'],
			'order'               => $order['order'],
		);
		if ( array_key_exists( 'search', $arguments ) ) {
			$search = $arguments['search'];
			if ( ! is_string( $search ) || mb_strlen( $search ) > 200 ) {
				return R::error( 'invalid_params' );
			}
			$query['s'] = $search;
		}
		$mimes = $this->mime_filter( $arguments );
		if ( is_wp_error( $mimes ) ) {
			return $mimes;
		}
		if ( array() !== $mimes ) {
			$query['post_mime_type'] = $mimes;
		}
		$parent = $this->optional_parent( $arguments );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		if ( null !== $parent ) {
			$query['post_parent'] = $parent;
		}
		$authors = $this->optional_authors( $arguments );
		if ( is_wp_error( $authors ) ) {
			return $authors;
		}
		if ( array() !== $authors ) {
			$query['author__in'] = $authors;
		}
		$dates = $this->date_query( $arguments );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}
		if ( array() !== $dates ) {
			$query['date_query'] = $dates;
		}
		$database = $this->database();
		if ( null === $database ) {
			return R::error( 'internal_error' );
		}
		$database->last_error = '';
		$result               = new \WP_Query( $query );
		if ( '' !== $this->database_error() ) {
			return R::error( 'internal_error' );
		}

		$items   = array();
		$removed = 0;
		foreach ( (array) $result->posts as $post ) {
			if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || ! $this->readable_attachment( $post ) ) {
				++$removed;
				continue;
			}
			$projected = $this->project( $post, 'rendered' === $format ? 'rendered' : $format, $fields );
			if ( is_wp_error( $projected ) ) {
				return $projected;
			}
			$items[] = $projected;
		}
		$total = max( 0, (int) $result->found_posts - $removed );
		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 0,
			'page'        => $pagination['page'],
		);
	}

	/**
	 * Read one persisted attachment through the fixed predicate.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function get_media( array $arguments ): mixed {
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$format = $this->content_format( $arguments );
		if ( is_wp_error( $format ) ) {
			return $format;
		}
		$fields = $this->projection_fields( $arguments );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || ! $this->readable_attachment( $post ) ) {
			return R::error( 'not_found' );
		}
		return $this->project( $post, $format, $fields );
	}

	/**
	 * Update one attachment while preserving the stored author.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function update_media( array $arguments ): mixed {
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return R::error( 'not_found' );
		}
		$owned = $this->owned_attachment( $post );
		if ( is_wp_error( $owned ) ) {
			return $owned;
		}
		$allowed = array_merge( array( 'id' ), array_keys( MediaSchemas::changes() ) );
		if ( array() !== array_diff( array_keys( $arguments ), $allowed ) ) {
			return R::error( 'invalid_params' );
		}
		$fields = array();
		if ( array_key_exists( 'title', $arguments ) ) {
			if ( ! is_string( $arguments['title'] ) ) {
				return R::error( 'invalid_params' );
			}
			$fields['post_title'] = $this->html->filter_plain_text( $arguments['title'] );
		}
		if ( array_key_exists( 'caption', $arguments ) ) {
			if ( ! is_string( $arguments['caption'] ) ) {
				return R::error( 'invalid_params' );
			}
			$fields['post_excerpt'] = $this->html->filter_post_html( $arguments['caption'] );
		}
		if ( array_key_exists( 'description', $arguments ) ) {
			if ( ! is_string( $arguments['description'] ) ) {
				return R::error( 'invalid_params' );
			}
			$fields['post_content'] = $this->html->filter_post_html( $arguments['description'] );
		}
		if ( array_key_exists( 'post', $arguments ) ) {
			$parent = $arguments['post'];
			if ( ! is_int( $parent ) || $parent < 0 ) {
				return R::error( 'invalid_params' );
			}
			if ( $parent > 0 ) {
				$guard = $this->parent_guard( $parent, $id );
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}
			}
			$fields['post_parent'] = $parent;
		}
		if ( array_key_exists( 'alt_text', $arguments ) && ! is_string( $arguments['alt_text'] ) ) {
			return R::error( 'invalid_params' );
		}
		if ( array() === $fields && ! array_key_exists( 'alt_text', $arguments ) ) {
			return R::error( 'invalid_params' );
		}
		if ( array() !== $fields ) {
			$written = $this->html->run( fn(): mixed => wp_update_post( wp_slash( array_merge( array( 'ID' => $id ), $fields ) ), true ) );
			if ( is_wp_error( $written ) ) {
				return R::error( 'internal_error' );
			}
		}
		if ( array_key_exists( 'alt_text', $arguments ) ) {
			$alt = $this->html->filter_plain_text( $arguments['alt_text'] );
			update_metadata( 'post', $id, self::ALT_KEY, wp_slash( $alt ) );
			$stored_alt = get_post_meta( $id, self::ALT_KEY, true );
			if ( ! is_string( $stored_alt ) || $stored_alt !== $alt ) {
				return new PartialFailure( $id, false );
			}
		}
		$stored = get_post( $id );
		if ( ! $stored instanceof WP_Post || 'attachment' !== $stored->post_type ) {
			return new PartialFailure( $id, false );
		}
		foreach ( $fields as $key => $value ) {
			if ( (string) $stored->{$key} !== (string) $value ) {
				return new PartialFailure( $id, false );
			}
		}
		return MutationResult::project(
			$id,
			false,
			fn(): array|WP_Error => $this->project( $stored, 'rendered', array() )
		);
	}

	/**
	 * Permanently delete one attachment and verify every captured file.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function delete_media( array $arguments ): mixed {
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return R::error( 'not_found' );
		}
		$owned = $this->owned_attachment( $post );
		if ( is_wp_error( $owned ) ) {
			return $owned;
		}
		$captured = $this->captured_files( $id );
		$deleted  = wp_delete_attachment( $id, true );
		if ( false === $deleted || null === $deleted ) {
			return R::error( 'internal_error' );
		}
		if ( null !== get_post( $id ) ) {
			return R::error( 'internal_error' );
		}
		foreach ( $captured as $file ) {
			if ( file_exists( $file ) ) {
				return new PartialFailure( $id, false );
			}
		}
		return MutationResult::project(
			$id,
			false,
			static fn(): array => array(
				'id'      => $id,
				'deleted' => true,
			)
		);
	}

	/**
	 * Fetch and persist one bounded remote attachment.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function upload_media_from_url( array $arguments ): mixed {
		$author = $this->upload_author( $arguments );
		if ( is_wp_error( $author ) ) {
			return $author;
		}
		$url = $arguments['url'] ?? null;
		if ( ! is_string( $url ) || strlen( $url ) > self::MAX_URL_LENGTH || ! str_starts_with( $url, 'https://' ) ) {
			return R::error( 'invalid_params' );
		}
		$parent = $this->upload_parent( $arguments );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		$fetcher = new SafeRemoteFetcher( new NativeDnsResolver() );
		if ( true !== $this->settings->get( 'media_remote_fetch_enabled' ) || ! $fetcher->available() ) {
			return R::error( 'remote_fetch_disabled' );
		}
		if ( $this->quota_blocked() ) {
			return R::error( 'quota_exceeded' );
		}
		$maximum = min( wp_max_upload_size(), (int) $this->settings->get( 'media_remote_max_bytes' ) );
		$file    = $fetcher->fetch(
			$url,
			$maximum,
			(int) $this->settings->get( 'media_remote_timeout_seconds' ),
			(int) $this->settings->get( 'media_remote_max_redirects' )
		);
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		try {
			$bytes = $file->bytes();
		} finally {
			$file->close();
		}
		if ( $this->remaining_blocked( strlen( $bytes ) ) ) {
			return R::error( 'quota_exceeded' );
		}
		$filename = $arguments['filename'] ?? basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( ! is_string( $filename ) || '' === $filename || strlen( $filename ) > 255 ) {
			return R::error( 'invalid_params' );
		}
		return $this->persist_upload( $author, $parent, $bytes, $filename, null, $arguments );
	}

	/**
	 * Decode and persist one bounded inline attachment.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function upload_media_from_data( array $arguments ): mixed {
		$author = $this->upload_author( $arguments );
		if ( is_wp_error( $author ) ) {
			return $author;
		}
		$filename = $arguments['filename'] ?? null;
		$mime     = $arguments['mime_type'] ?? null;
		$encoded  = $arguments['data_base64'] ?? null;
		if ( ! is_string( $filename ) || '' === $filename || strlen( $filename ) > 255 ) {
			return R::error( 'invalid_params' );
		}
		if ( ! is_string( $mime ) || 1 !== preg_match( self::MIME_PATTERN, $mime ) ) {
			return R::error( 'invalid_params' );
		}
		if ( ! is_string( $encoded ) || '' === $encoded || strlen( $encoded ) > self::MAX_ENCODED_BYTES ) {
			return R::error( 'invalid_params' );
		}
		$parent = $this->upload_parent( $arguments );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		if ( $this->quota_blocked() ) {
			return R::error( 'quota_exceeded' );
		}
		$bytes = $this->upload->decode( $encoded );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		if ( $this->remaining_blocked( strlen( $bytes ) ) ) {
			return R::error( 'quota_exceeded' );
		}
		return $this->persist_upload( $author, $parent, $bytes, $filename, $mime, $arguments );
	}

	/**
	 * Report effective limits with no private paths.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function upload_limits(): array|WP_Error {
		try {
			$maximum = wp_max_upload_size();
			$fetcher = new SafeRemoteFetcher( new NativeDnsResolver() );
			return array(
				'max_upload_bytes'       => $maximum,
				'data_upload_max_bytes'  => min( $maximum, (int) $this->settings->get( 'media_data_max_bytes' ) ),
				'remote_fetch_enabled'   => true === $this->settings->get( 'media_remote_fetch_enabled' ) && $fetcher->available(),
				'remote_fetch_max_bytes' => min( $maximum, (int) $this->settings->get( 'media_remote_max_bytes' ) ),
				'pixels_max'             => (int) $this->settings->get( 'media_pixels_max' ),
				'allowed_mime_types'     => $this->upload->allowed(),
			);
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
	}

	/**
	 * Inspect, write and persist one attachment with exact read-back.
	 *
	 * @param int                 $author Eligible attribution target.
	 * @param int                 $parent_id Reviewed parent, zero for none.
	 * @param string              $bytes Checked bytes.
	 * @param string              $filename Requested filename.
	 * @param string|null         $mime Claimed MIME, if any.
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function persist_upload( int $author, int $parent_id, string $bytes, string $filename, ?string $mime, array $arguments ): mixed {
		$checked = $this->upload->inspect( $bytes, $filename, $mime );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$name = $checked['filename'];
		if ( '' === $name ) {
			return R::error( 'file_type_not_allowed' );
		}
		$upload = wp_upload_bits( $name, null, $bytes );
		$file   = isset( $upload['file'] ) && is_string( $upload['file'] ) ? $upload['file'] : '';
		$error  = isset( $upload['error'] ) ? (string) $upload['error'] : '';
		if ( '' !== $error ) {
			$this->delete_written_file( $file );
			return R::error( 'internal_error' );
		}
		clearstatcache( true, $file );
		$size = '' !== $file ? filesize( $file ) : false;
		if ( false === $size || strlen( $bytes ) !== $size ) {
			$this->delete_written_file( $file );
			return R::error( 'internal_error' );
		}

		$title   = is_string( $arguments['title'] ?? null ) ? $this->html->filter_plain_text( $arguments['title'] ) : $checked['filename'];
		$caption = is_string( $arguments['caption'] ?? null ) ? $this->html->filter_post_html( $arguments['caption'] ) : '';
		$content = is_string( $arguments['description'] ?? null ) ? $this->html->filter_post_html( $arguments['description'] ) : '';
		$alt     = '';
		if ( is_string( $arguments['alt_text'] ?? null ) ) {
			$alt = $this->html->filter_plain_text( $arguments['alt_text'] );
		}

		$eligible = $this->authors->require_eligible( $author );
		if ( is_wp_error( $eligible ) ) {
			$this->delete_written_file( $file );
			return $eligible;
		}

		$fields   = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => $checked['mime'],
			'post_title'     => $title,
			'post_excerpt'   => $caption,
			'post_content'   => $content,
			'post_author'    => $author,
			'post_parent'    => $parent_id,
		);
		$inserted = $this->html->run( static fn(): mixed => wp_insert_attachment( $fields, $file, 0, true ) );
		if ( is_wp_error( $inserted ) ) {
			$this->delete_written_file( $file );
			return $this->file_gone( $file ) ? R::sanitize( $inserted ) : R::error( 'internal_error' );
		}
		$id = (int) $inserted;
		if ( $id < 1 ) {
			$this->delete_written_file( $file );
			return R::error( 'internal_error' );
		}
		update_attached_file( $id, $file );

		try {
			$attached = get_attached_file( $id );
			if ( ! is_string( $attached ) || $attached !== $file ) {
				return new PartialFailure( $id, true );
			}
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$generated = wp_generate_attachment_metadata( $id, $file );
			if ( ! is_array( $generated ) ) {
				$generated = array();
			}
			wp_update_attachment_metadata( $id, $generated );
			$stored_metadata = wp_get_attachment_metadata( $id );
			if ( ! $this->same_metadata( $generated, $stored_metadata ) ) {
				return new PartialFailure( $id, true );
			}
			update_metadata( 'post', $id, self::ALT_KEY, wp_slash( $alt ) );
			$stored_alt = get_post_meta( $id, self::ALT_KEY, true );
			if ( ! is_string( $stored_alt ) || $stored_alt !== $alt ) {
				return new PartialFailure( $id, true );
			}
			$stored = get_post( $id );
			if ( ! $stored instanceof WP_Post || 'attachment' !== $stored->post_type || (int) $stored->post_author !== $author ) {
				return new PartialFailure( $id, true );
			}
			if ( (string) $stored->post_title !== $title || (string) $stored->post_excerpt !== $caption || (string) $stored->post_content !== $content ) {
				return new PartialFailure( $id, true );
			}
			if ( (int) $stored->post_parent !== $parent_id ) {
				return new PartialFailure( $id, true );
			}
			return MutationResult::project(
				$id,
				true,
				fn(): array|WP_Error => $this->project( $stored, 'rendered', array() )
			);
		} catch ( \Throwable ) {
			return new PartialFailure( $id, true );
		}
	}

	/**
	 * Validate the required positive upload author.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return int|WP_Error
	 */
	private function upload_author( array $arguments ): int|WP_Error {
		$author = $arguments['author'] ?? null;
		if ( ! is_int( $author ) || $author < 1 ) {
			return R::error( 'invalid_params' );
		}
		$eligible = $this->authors->require_eligible( $author );
		return $eligible instanceof WP_User ? $author : $eligible;
	}

	/**
	 * Validate the optional upload parent before any file work.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return int|WP_Error
	 */
	private function upload_parent( array $arguments ): int|WP_Error {
		$parent = $arguments['post'] ?? 0;
		if ( ! is_int( $parent ) || $parent < 0 ) {
			return R::error( 'invalid_params' );
		}
		if ( $parent > 0 ) {
			$guard = $this->parent_guard( $parent, 0 );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
		}
		return $parent;
	}

	/**
	 * Whether the multisite upload space pre-check refuses the request.
	 *
	 * @return bool
	 */
	private function quota_blocked(): bool {
		return is_multisite() && ! is_upload_space_available();
	}

	/**
	 * Whether the remaining space refuses the checked bytes.
	 *
	 * @param int $bytes Checked byte count.
	 * @return bool
	 */
	private function remaining_blocked( int $bytes ): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		$disabled = get_site_option( 'upload_space_check_disabled', false );
		if ( is_bool( $disabled ) ? $disabled : (bool) $disabled ) {
			return false;
		}
		$available = get_upload_space_available();
		return is_int( $available ) ? $bytes > $available : true;
	}

	/**
	 * Resolve one attachment into the reviewed projection.
	 *
	 * @param WP_Post       $post Stored attachment.
	 * @param string        $format Representation.
	 * @param array<string> $fields Selected fields.
	 * @return array<string,mixed>|WP_Error
	 */
	private function project( WP_Post $post, string $format, array $fields ): array|WP_Error {
		try {
			return $this->projection->project( $this->record( $post ), $format, $fields );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
	}

	/**
	 * Build one allowlisted attachment record.
	 *
	 * @param WP_Post $post Stored attachment.
	 * @return array<string,mixed>
	 */
	private function record( WP_Post $post ): array {
		$id       = (int) $post->ID;
		$metadata = wp_get_attachment_metadata( $id );
		$source   = wp_get_attachment_url( $id );
		$details  = array(
			'width'    => 0,
			'height'   => 0,
			'filesize' => 0,
			'file'     => '',
			'sizes'    => array(),
		);
		if ( is_array( $metadata ) ) {
			$details['width']    = (int) ( $metadata['width'] ?? 0 );
			$details['height']   = (int) ( $metadata['height'] ?? 0 );
			$details['filesize'] = (int) ( $metadata['filesize'] ?? 0 );
			$details['file']     = is_string( $metadata['file'] ?? null ) ? $metadata['file'] : '';
			$sizes               = array();
			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $size ) {
				if ( ! is_array( $size ) ) {
					continue;
				}
				$sizes[ (string) $name ] = array(
					'width'      => (int) ( $size['width'] ?? 0 ),
					'height'     => (int) ( $size['height'] ?? 0 ),
					'mime_type'  => is_string( $size['mime-type'] ?? null ) ? $size['mime-type'] : '',
					'source_url' => $this->size_source( $id, (string) $name ),
				);
			}
			$details['sizes'] = $sizes;
		}
		$mime = (string) $post->post_mime_type;
		$alt  = get_post_meta( $id, self::ALT_KEY, true );
		return array(
			'id'            => $id,
			'date'          => (string) $post->post_date,
			'date_gmt'      => (string) $post->post_date_gmt,
			'modified'      => (string) $post->post_modified,
			'modified_gmt'  => (string) $post->post_modified_gmt,
			'slug'          => (string) $post->post_name,
			'status'        => (string) $post->post_status,
			'link'          => (string) get_permalink( $id ),
			'title'         => array( 'rendered' => (string) $post->post_title ),
			'caption'       => array( 'rendered' => (string) $post->post_excerpt ),
			'description'   => array( 'rendered' => (string) $post->post_content ),
			'alt_text'      => is_string( $alt ) ? $alt : '',
			'media_type'    => $this->media_type( $mime ),
			'mime_type'     => $mime,
			'source_url'    => is_string( $source ) ? $source : '',
			'author'        => (int) $post->post_author,
			'post'          => (int) $post->post_parent,
			'media_details' => $details,
		);
	}

	/**
	 * Return the reviewed media class of one MIME type.
	 *
	 * @param string $mime Stored MIME type.
	 * @return string
	 */
	private function media_type( string $mime ): string {
		$prefix = strstr( $mime, '/', true );
		return is_string( $prefix ) && in_array( $prefix, array( 'image', 'video', 'audio' ), true ) ? $prefix : 'file';
	}

	/**
	 * Resolve one intermediate size source without exposing a path.
	 *
	 * @param int    $id Attachment ID.
	 * @param string $size Size name.
	 * @return string
	 */
	private function size_source( int $id, string $size ): string {
		$image = wp_get_attachment_image_src( $id, $size );
		return is_array( $image ) && isset( $image[0] ) && is_string( $image[0] ) ? $image[0] : '';
	}

	/**
	 * Run the fixed parent predicate before any parent mutation.
	 *
	 * @param int $parent_id Candidate parent.
	 * @param int $attachment_id Addressed attachment, zero for uploads.
	 * @return true|WP_Error
	 */
	private function parent_guard( int $parent_id, int $attachment_id ): true|WP_Error {
		if ( $parent_id > 0 && $parent_id === $attachment_id ) {
			return R::error( 'invalid_state' );
		}
		$parent = get_post( $parent_id );
		if ( ! $parent instanceof WP_Post ) {
			return R::error( 'not_found' );
		}
		if ( in_array( $parent->post_type, array( 'attachment', 'revision' ), true ) ) {
			return R::error( 'invalid_state' );
		}
		$type = $this->parent_type( $parent->post_type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		if ( ! in_array( (string) $parent->post_status, self::READ_STATUSES, true ) ) {
			return R::error( 'invalid_state' );
		}
		return true;
	}

	/**
	 * Resolve one parent type only when its capability class is exactly reviewed.
	 *
	 * @param string $slug Candidate post type.
	 * @return WP_Post_Type|WP_Error
	 */
	private function parent_type( string $slug ): WP_Post_Type|WP_Error {
		$type = get_post_type_object( $slug );
		if ( ! $type instanceof WP_Post_Type ) {
			return R::error( 'post_type_unavailable' );
		}
		$base = is_array( $type->capability_type ) ? (string) ( $type->capability_type[0] ?? '' ) : (string) $type->capability_type;
		if ( ! isset( self::PARENT_CAPABILITIES[ $base ] ) ) {
			return R::error( 'post_type_unavailable' );
		}
		if ( true !== $type->map_meta_cap || ! is_object( $type->cap ) ) {
			return R::error( 'post_type_unavailable' );
		}
		$review = self::PARENT_CAPABILITIES[ $base ];
		$actual = (array) $type->cap;
		foreach ( $review as $slot => $expected ) {
			if ( ! array_key_exists( $slot, $actual ) || $actual[ $slot ] !== $expected ) {
				return R::error( 'post_type_unavailable' );
			}
		}
		foreach ( array_keys( $actual ) as $slot ) {
			if ( self::CORE_CREATE_SLOT !== $slot && ! array_key_exists( $slot, $review ) ) {
				return R::error( 'post_type_unavailable' );
			}
		}
		if ( array_key_exists( self::CORE_CREATE_SLOT, $actual ) && $actual[ self::CORE_CREATE_SLOT ] !== $review['edit_posts'] ) {
			return R::error( 'post_type_unavailable' );
		}
		return $type;
	}

	/**
	 * Whether an attachment is readable under the fixed visibility rule.
	 *
	 * @param WP_Post $post Stored attachment.
	 * @return bool
	 */
	private function readable_attachment( WP_Post $post ): bool {
		$status = (string) $post->post_status;
		if ( 'private' === $status ) {
			return true;
		}
		if ( 'inherit' !== $status ) {
			return false;
		}
		$parent = (int) $post->post_parent;
		if ( 0 === $parent ) {
			return true;
		}
		return true === $this->parent_guard( $parent, (int) $post->ID );
	}

	/**
	 * Resolve the stored owner only when it is an eligible attribution target.
	 *
	 * @param WP_Post $post Stored attachment.
	 * @return true|WP_Error
	 */
	private function owned_attachment( WP_Post $post ): true|WP_Error {
		$author = (int) $post->post_author;
		if ( $author < 1 ) {
			return R::error( 'forbidden' );
		}
		$eligible = $this->authors->require_eligible( $author );
		return $eligible instanceof WP_User ? true : $eligible;
	}

	/**
	 * Capture the original and every reviewed derivative path.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string>
	 */
	private function captured_files( int $id ): array {
		$attached = get_attached_file( $id );
		if ( ! is_string( $attached ) || '' === $attached ) {
			return array();
		}
		$files    = array( $attached );
		$metadata = wp_get_attachment_metadata( $id );
		if ( ! is_array( $metadata ) ) {
			return $files;
		}
		$directory = dirname( $attached );
		$sizes     = (array) ( $metadata['sizes'] ?? array() );
		foreach ( $sizes as $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			$file = $this->derivative_name( $size['file'] ?? null );
			if ( null !== $file ) {
				$files[] = $directory . '/' . $file;
			}
		}
		$original = $this->derivative_name( $metadata['original_image'] ?? null );
		if ( null !== $original ) {
			$files[] = $directory . '/' . $original;
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Accept only a bare derivative basename.
	 *
	 * @param mixed $value Candidate metadata value.
	 * @return string|null
	 */
	private function derivative_name( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || str_contains( $value, '..' ) || 1 === preg_match( '~[/\\\\\x00-\x1f\x7f]~', $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Compare generated and stored attachment metadata fields.
	 *
	 * @param array<string,mixed>       $generated Generated metadata.
	 * @param array<string,mixed>|false $stored Stored metadata.
	 * @return bool
	 */
	private function same_metadata( array $generated, array|false $stored ): bool {
		if ( ! is_array( $stored ) ) {
			return false;
		}
		foreach ( array( 'width', 'height' ) as $field ) {
			if ( (int) ( $generated[ $field ] ?? 0 ) !== (int) ( $stored[ $field ] ?? 0 ) ) {
				return false;
			}
		}
		if ( (string) ( $generated['file'] ?? '' ) !== (string) ( $stored['file'] ?? '' ) ) {
			return false;
		}
		$generated_sizes = $this->size_names( $generated['sizes'] ?? array() );
		$stored_sizes    = $this->size_names( $stored['sizes'] ?? array() );
		return $generated_sizes === $stored_sizes;
	}

	/**
	 * Normalize one size map to comparable names and file names.
	 *
	 * @param mixed $sizes Candidate size map.
	 * @return array<string,string>
	 */
	private function size_names( mixed $sizes ): array {
		$result = array();
		foreach ( (array) $sizes as $name => $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			$result[ (string) $name ] = is_string( $size['file'] ?? null ) ? $size['file'] : '';
		}
		ksort( $result );
		return $result;
	}

	/**
	 * Delete exactly one owned uploads file.
	 *
	 * @param string $file Absolute path.
	 * @return void
	 */
	private function delete_written_file( string $file ): void {
		if ( '' !== $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Whether an owned uploads file is gone.
	 *
	 * @param string $file Absolute path.
	 * @return bool
	 */
	private function file_gone( string $file ): bool {
		return '' !== $file && ! file_exists( $file );
	}

	/**
	 * Validate and normalize the reviewed representation.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return string|WP_Error
	 */
	private function content_format( array $arguments ): string|WP_Error {
		$format = $arguments['content_format'] ?? 'rendered';
		if ( ! is_string( $format ) || ! in_array( $format, array( 'raw', 'rendered', 'excerpt' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		return 'raw' === $format ? R::error( 'forbidden' ) : $format;
	}

	/**
	 * Validate the reviewed field narrowing.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array<string>|WP_Error
	 */
	private function projection_fields( array $arguments ): array|WP_Error {
		if ( ! array_key_exists( 'fields', $arguments ) ) {
			return array();
		}
		$fields = $arguments['fields'];
		if ( ! is_array( $fields ) ) {
			return R::error( 'invalid_params' );
		}
		$selected = array();
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, MediaProjection::FIELDS, true ) ) {
				return R::error( 'invalid_params' );
			}
			$selected[] = $field;
		}
		return array_values( array_unique( $selected ) );
	}

	/**
	 * Validate the reviewed pagination bounds.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array{page:int,per_page:int}|WP_Error
	 */
	private function pagination( array $arguments ): array|WP_Error {
		$page     = $arguments['page'] ?? 1;
		$per_page = $arguments['per_page'] ?? 20;
		if ( ! is_int( $page ) || ! is_int( $per_page ) || $page < 1 || $per_page < 1 || $per_page > 100 || $page > intdiv( PHP_INT_MAX, $per_page ) ) {
			return R::error( 'invalid_params' );
		}
		return array(
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Validate the reviewed ordering.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array{orderby:string,order:string}|WP_Error
	 */
	private function ordering( array $arguments ): array|WP_Error {
		$orderby = $arguments['orderby'] ?? 'date';
		$order   = $arguments['order'] ?? 'desc';
		if ( ! is_string( $orderby ) || ! in_array( $orderby, self::ORDER_BY, true ) ) {
			return R::error( 'invalid_params' );
		}
		if ( ! is_string( $order ) || ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		if ( 'relevance' === $orderby && ( ! isset( $arguments['search'] ) || ! is_string( $arguments['search'] ) || '' === $arguments['search'] ) ) {
			return R::error( 'invalid_params' );
		}
		return array(
			'orderby' => match ( $orderby ) {
				'id'    => 'ID',
				'slug'  => 'name',
				default => $orderby,
			},
			'order'   => $order,
		);
	}

	/**
	 * Validate the reviewed attachment status selector.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return string|WP_Error
	 */
	private function attachment_status( array $arguments ): string|WP_Error {
		$status = $arguments['status'] ?? 'inherit';
		if ( ! is_string( $status ) || ! in_array( $status, self::ATTACHMENT_STATUSES, true ) ) {
			return R::error( 'invalid_params' );
		}
		return $status;
	}

	/**
	 * Translate the reviewed media classes to allowed MIME values.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array<string>|WP_Error
	 */
	private function mime_filter( array $arguments ): array|WP_Error {
		$allowed   = array_values( get_allowed_mime_types() );
		$requested = array();
		if ( array_key_exists( 'media_type', $arguments ) ) {
			$types = $arguments['media_type'];
			if ( ! is_array( $types ) || array() === $types || count( $types ) > 5 ) {
				return R::error( 'invalid_params' );
			}
			foreach ( $types as $type ) {
				if ( ! is_string( $type ) || ! in_array( $type, self::MEDIA_PREFIXES, true ) ) {
					return R::error( 'invalid_params' );
				}
				$matched = false;
				foreach ( $allowed as $mime ) {
					if ( str_starts_with( $mime, $type . '/' ) ) {
						$requested[] = $mime;
						$matched     = true;
					}
				}
				if ( ! $matched ) {
					return R::error( 'invalid_params' );
				}
			}
		}
		if ( ! array_key_exists( 'mime_type', $arguments ) ) {
			return $requested;
		}
		$explicit = $arguments['mime_type'];
		if ( ! is_array( $explicit ) || array() === $explicit || count( $explicit ) > 10 ) {
			return R::error( 'invalid_params' );
		}
		$valid = array();
		foreach ( $explicit as $mime ) {
			if ( ! is_string( $mime ) || 1 !== preg_match( self::MIME_PATTERN, $mime ) ) {
				return R::error( 'invalid_params' );
			}
			$valid[] = $mime;
		}
		$requested = array() === $requested ? $valid : array_values( array_intersect( $requested, $valid ) );
		return array() === $requested ? R::error( 'invalid_params' ) : $requested;
	}

	/**
	 * Validate the reviewed parent selector.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return int|null|WP_Error Null when omitted.
	 */
	private function optional_parent( array $arguments ): int|null|WP_Error {
		if ( ! array_key_exists( 'parent', $arguments ) ) {
			return null;
		}
		$parent = $arguments['parent'];
		return is_int( $parent ) && $parent >= 0 ? $parent : R::error( 'invalid_params' );
	}

	/**
	 * Validate the reviewed author list.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array<int>|WP_Error
	 */
	private function optional_authors( array $arguments ): array|WP_Error {
		if ( ! array_key_exists( 'author', $arguments ) ) {
			return array();
		}
		$ids = $arguments['author'];
		if ( ! is_array( $ids ) || array() === $ids || count( $ids ) > 20 ) {
			return R::error( 'invalid_params' );
		}
		$valid = array();
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return R::error( 'invalid_params' );
			}
			$valid[] = $id;
		}
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Validate the reviewed date bounds.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array<array<string,mixed>>|WP_Error
	 */
	private function date_query( array $arguments ): array|WP_Error {
		$query = array( 'column' => 'post_date_gmt' );
		foreach ( array( 'after', 'before' ) as $key ) {
			if ( ! array_key_exists( $key, $arguments ) ) {
				continue;
			}
			$value = $arguments[ $key ];
			if ( ! is_string( $value ) || strlen( $value ) > 40 || false === strtotime( $value ) ) {
				return R::error( 'invalid_params' );
			}
			$query[ $key ] = $value;
		}
		if ( ! isset( $query['after'] ) && ! isset( $query['before'] ) ) {
			return array();
		}
		$query['inclusive'] = true;
		return array( $query );
	}

	/**
	 * Validate one positive identifier.
	 *
	 * @param mixed $value Candidate.
	 * @return int|WP_Error
	 */
	private function positive_integer( mixed $value ): int|WP_Error {
		return is_int( $value ) && $value > 0 ? $value : R::error( 'invalid_params' );
	}

	/**
	 * Read the global database object without trusting request data.
	 *
	 * @return \wpdb|null Null when the global is missing or unreadable.
	 */
	private function database(): ?\wpdb {
		$database = $GLOBALS['wpdb'] ?? null;
		return $database instanceof \wpdb ? $database : null;
	}

	/**
	 * Read the observable database error from the global state.
	 *
	 * @return string
	 */
	private function database_error(): string {
		$database = $this->database();
		return null === $database ? '' : (string) $database->last_error;
	}
}
