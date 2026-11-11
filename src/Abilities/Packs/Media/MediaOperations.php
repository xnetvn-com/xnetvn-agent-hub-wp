<?php
/**
 * Attachment operations retaining core REST permission callbacks.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Media;

use WP_Error;
use WP_REST_Request;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;
use XNetVN\AgentHubWP\Abilities\RestBackedAbility;

/** No file replacement, user reassignment or caller-selected route exists. */
final class MediaOperations {
	/** Internal core dispatcher.
	 *
	 * @var RestBackedAbility
	 */
	private RestBackedAbility $rest;
	/** Safe attachment shaping.
	 *
	 * @var MediaProjection
	 */
	private MediaProjection $projection;
	/** Current-user upload restrictions.
	 *
	 * @var UploadPolicy
	 */
	private UploadPolicy $upload;
	/**
	 * Bind dynamic site policy.
	 *
	 * @param SiteSettings $settings Settings.
	 */
	public function __construct( private SiteSettings $settings ) {
		$this->rest       = new RestBackedAbility();
		$this->projection = new MediaProjection();
		$this->upload     = new UploadPolicy( $settings ); }
	/**
	 * Execute one registry-authorized media operation.
	 *
	 * @param string              $name Reviewed operation.
	 * @param array<string,mixed> $args Validated arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $args ): mixed {
		if ( ! current_user_can( 'upload_files' ) ) {
			return R::error( 'forbidden' ); }
		if ( 'get-media-upload-limits' === $name ) {
			return $this->limits(); }
		$route = rest_get_route_for_post_type_items( 'attachment' );
		if ( ! $route ) {
			return R::error( 'unsupported_operation' ); }
		$id     = (int) ( $args['id'] ?? 0 );
		$format = (string) ( $args['content_format'] ?? 'rendered' );
		if ( $id && 'attachment' !== get_post_type( $id ) ) {
			return R::error( 'not_found' ); }
		if ( 'raw' === $format && ( $id ? ! current_user_can( 'edit_post', $id ) : ! current_user_can( 'edit_posts' ) ) ) {
			return R::error( 'forbidden' ); }
		if ( isset( $args['post'] ) && 0 !== $args['post'] ) {
			$parent = get_post( $args['post'] );
			if ( null === $parent || in_array( $parent->post_type, array( 'attachment', 'revision' ), true ) ) {
				return R::error( 'invalid_params' ); }
			if ( ! current_user_can( 'edit_post', $parent->ID ) ) {
				return R::error( 'forbidden' ); }
		}
		if ( 'upload-media-from-url' === $name ) {
			$fetcher = new \XNetVN\AgentHubWP\Support\Http\SafeRemoteFetcher( new \XNetVN\AgentHubWP\Support\Http\NativeDnsResolver() );
			if ( true !== $this->settings->get( 'media_remote_fetch_enabled' ) || ! $fetcher->available() ) {
				return R::error( 'remote_fetch_disabled' ); }
			if ( is_multisite() && ! is_upload_space_available() ) {
				return R::error( 'quota_exceeded' ); }
			$file = $fetcher->fetch( $args['url'], min( wp_max_upload_size(), (int) $this->settings->get( 'media_remote_max_bytes' ) ), (int) $this->settings->get( 'media_remote_timeout_seconds' ), (int) $this->settings->get( 'media_remote_max_redirects' ) );
			if ( is_wp_error( $file ) ) {
				return $file; }
			try {
				return $this->upload_bytes( $route, $file->bytes(), $args['filename'] ?? basename( (string) wp_parse_url( $args['url'], PHP_URL_PATH ) ), null, $args );
			} finally {
				$file->close(); }
		}
		if ( 'upload-media-from-data' === $name ) {
			if ( is_multisite() && ! is_upload_space_available() ) {
				return R::error( 'quota_exceeded' ); }
			$bytes = $this->upload->decode( $args['data_base64'] );
			if ( is_wp_error( $bytes ) ) {
				return $bytes; }
			return $this->upload_bytes( $route, $bytes, $args['filename'], $args['mime_type'], $args );
		}
		if ( 'update-media' === $name ) {
			$params = array_diff_key( $args, array( 'id' => true ) );
			if ( ! $params ) {
				return R::error( 'invalid_params' ); }
			$response = $this->rest->dispatch( 'POST', $route . '/' . $id, $params, 'attachment' );
			if ( is_wp_error( $response ) ) {
				return $response; }
			return $response instanceof \XNetVN\AgentHubWP\Abilities\MutationResponse ? $response->project( fn( array $row ): mixed => $this->projection->project( $row ) ) : R::error( 'partial_failure' );
		}
		if ( 'delete-media' === $name ) {
			$response = $this->rest->dispatch( 'DELETE', $route . '/' . $id, array( 'force' => true ), 'attachment' );
			if ( is_wp_error( $response ) ) {
				return $response; }
			return $response instanceof \XNetVN\AgentHubWP\Abilities\MutationResponse ? \XNetVN\AgentHubWP\Abilities\MutationResult::project(
				$response->object_id,
				false,
				static fn(): array => array(
					'id'      => $id,
					'deleted' => true,
				)
			) : R::error( 'partial_failure' );
		}
		$params            = array_diff_key( $args, array_flip( array( 'id', 'content_format', 'fields' ) ) );
		$params['context'] = 'raw' === $format ? 'edit' : 'view';
		if ( ! $id ) {
			$params += array(
				'page'     => 1,
				'per_page' => 20,
				'status'   => 'inherit',
			); }
		if ( 'relevance' === ( $params['orderby'] ?? '' ) && empty( $params['search'] ) ) {
			return R::error( 'invalid_params' ); }
		$response = $this->rest->dispatch( 'GET', $route . ( $id ? '/' . $id : '' ), $params );
		if ( is_wp_error( $response ) ) {
			return $response; }
		if ( $id ) {
			return $this->projection->project( $response->get_data(), $format, $args['fields'] ?? array() ); }
		$items = array();
		foreach ( $response->get_data() as $row ) {
			$item = $this->projection->project( $row, $format, $args['fields'] ?? array() );
			if ( is_wp_error( $item ) ) {
				return $item;
			} $items[] = $item; }
		$result = $this->rest->collection( $response, (int) $params['page'], static fn( array $row ): array => $row );
		if ( ! is_wp_error( $result ) ) {
			$result['items'] = $items;
		} return $result;
	}
	/**
	 * Single raw-body persistence path shared by data and remote uploads.
	 *
	 * @param string              $route Attachment collection.
	 * @param string              $bytes Validated bounded bytes.
	 * @param string              $filename Requested filename.
	 * @param string|null         $mime Claimed MIME, if any.
	 * @param array<string,mixed> $args Editable attachment metadata.
	 * @return mixed
	 */
	private function upload_bytes( string $route, string $bytes, string $filename, ?string $mime, array $args ): mixed {
		$checked = $this->upload->inspect( $bytes, $filename, $mime );
		if ( is_wp_error( $checked ) ) {
			return $checked; }
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', $checked['mime'] );
		$request->set_header( 'Content-Disposition', 'attachment; filename="' . str_replace( '"', '', $checked['filename'] ) . '"' );
		$request->set_body( $bytes );
		$request->set_query_params( array_intersect_key( $args, MediaSchemas::changes() ) );
		try {
			$response = $this->rest->send( $request, 'attachment' );
		} finally {
			if ( ! headers_sent() ) {
				header_remove( 'X-WP-Upload-Attachment-ID' ); }
		}
		if ( is_wp_error( $response ) ) {
			return $response; }
		return $response instanceof \XNetVN\AgentHubWP\Abilities\MutationResponse ? $response->project( fn( array $row ): mixed => $this->projection->project( $row ) ) : R::error( 'partial_failure' );
	}
	/**
	 * Report effective user-specific limits with no private paths.
	 *
	 * @return array<string,mixed>
	 */
	private function limits(): array {
		$max = wp_max_upload_size();
		return array(
			'max_upload_bytes'       => $max,
			'data_upload_max_bytes'  => min( $max, (int) $this->settings->get( 'media_data_max_bytes' ) ),
			'remote_fetch_enabled'   => true === $this->settings->get( 'media_remote_fetch_enabled' ) && ( new \XNetVN\AgentHubWP\Support\Http\SafeRemoteFetcher( new \XNetVN\AgentHubWP\Support\Http\NativeDnsResolver() ) )->available(),
			'remote_fetch_max_bytes' => min( $max, (int) $this->settings->get( 'media_remote_max_bytes' ) ),
			'pixels_max'             => $this->settings->get( 'media_pixels_max' ),
			'allowed_mime_types'     => $this->upload->allowed(),
		);
	}
}
