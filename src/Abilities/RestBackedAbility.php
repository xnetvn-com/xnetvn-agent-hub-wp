<?php
/**
 * Internal core REST dispatch retaining validation and permissions.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities;

use Closure;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Routes are supplied by reviewed pack code, never directly by callers. */
final class RestBackedAbility {
	/**
	 * Dispatch an internal request with core validation and object permissions.
	 *
	 * @param string              $method Trusted method.
	 * @param string              $route Resolved core route.
	 * @param array<string,mixed> $params Reviewed parameters.
	 * @param string|null         $mutation_type Reviewed type for mutation receipts.
	 * @param string|null         $object_kind Object kind: post, comment, term or user. Null infers from mutation type.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dispatch( string $method, string $route, array $params = array(), ?string $mutation_type = null, ?string $object_kind = null ): WP_REST_Response|WP_Error {
		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return $this->send( $request, $mutation_type, $object_kind );
	}
	/**
	 * Send a pack-constructed request without bypassing the REST server.
	 *
	 * @param WP_REST_Request $request Reviewed request.
	 * @param string|null     $mutation_type Reviewed type for mutation receipts.
	 * @param string|null     $object_kind Object kind: post, comment, term or user. Null infers from mutation type.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send( WP_REST_Request $request, ?string $mutation_type = null, ?string $object_kind = null ): WP_REST_Response|WP_Error {
		$kind      = self::resolve_kind( $mutation_type, $object_kind );
		$receipt   = new MutationReceipt();
		$created   = ! isset( $request['id'] ) && ! preg_match( '~/[0-9]+$~', $request->get_route() );
		$delete_id = 0;
		if ( 'DELETE' === $request->get_method() && preg_match( '~/([0-9]+)$~', $request->get_route(), $match ) ) {
			$delete_id = (int) $match[1]; }
		$delete_type = false;
		if ( $delete_id && 'post' === $kind ) {
			$delete_type = get_post_type( $delete_id ); }
		$observe_delete         = static function ( $object_id ) use ( $delete_id, $delete_type, $mutation_type, $receipt ): void {
			if ( $delete_id > 0 && $delete_id === $object_id && $delete_type === $mutation_type ) {
				$receipt->id = $delete_id; }
		};
		$observe_comment_delete = static function ( $comment_id ) use ( $delete_id, $mutation_type, $receipt ): void {
			if ( 'comment' === $mutation_type && $delete_id > 0 && $delete_id === (int) $comment_id ) {
				$receipt->id = $delete_id; }
		};
		$observe_term_delete    = static function ( $term_id, $tt_id, $taxonomy, $deleted_term = null, $object_ids = array() ) use ( $delete_id, $mutation_type, $kind, $receipt ): void {
			$unused = array( $tt_id, $deleted_term, $object_ids );
			unset( $unused );
			if ( 'term' !== $kind ) {
				return; }
			if ( $delete_id > 0 && (int) $term_id === $delete_id && $taxonomy === $mutation_type ) {
				$receipt->id = $delete_id; }
		};
		if ( null !== $mutation_type && $delete_id ) {
			if ( 'term' === $kind ) {
				add_action( 'delete_term', $observe_term_delete, PHP_INT_MIN, 5 );
			} else {
				add_action( 'deleted_post', $observe_delete, PHP_INT_MIN );
				add_action( 'trashed_post', $observe_delete, PHP_INT_MIN );
				add_action( 'deleted_comment', $observe_comment_delete, PHP_INT_MIN );
				add_action( 'trashed_comment', $observe_comment_delete, PHP_INT_MIN );
				add_action( 'spammed_comment', $observe_comment_delete, PHP_INT_MIN );
			}
		}
		$observe      = static function ( $record, $core_request ) use ( $request, $receipt ): void {
			if ( $core_request === $request ) {
				if ( $record instanceof \WP_Post ) {
					$receipt->id = $record->ID;
				} elseif ( $record instanceof \WP_Comment ) {
					$receipt->id = (int) $record->comment_ID;
				} elseif ( $record instanceof \WP_User ) {
					$receipt->id = (int) $record->ID;
				}
			} };
		$observe_term = static function ( $term, $core_request ) use ( $request, $mutation_type, $receipt ): void {
			if ( $core_request === $request && $term instanceof \WP_Term && $term->taxonomy === $mutation_type ) {
				$receipt->id = (int) $term->term_id; }
		};
		$observe_user = static function ( $user, $core_request ) use ( $request, $receipt ): void {
			if ( $core_request === $request && $user instanceof \WP_User ) {
				$receipt->id = (int) $user->ID; }
		};
		if ( null !== $mutation_type ) {
			if ( 'term' === $kind ) {
				add_action( 'rest_insert_' . $mutation_type, $observe_term, PHP_INT_MIN, 2 );
			} elseif ( 'user' === $kind ) {
				add_action( 'rest_insert_user', $observe_user, PHP_INT_MIN, 2 );
			} else {
				add_action( 'rest_insert_' . $mutation_type, $observe, PHP_INT_MIN, 2 );
			}
		}
		try {
			$response = rest_do_request( $request );
			if ( $response->is_error() ) {
				if ( null !== $receipt->id ) {
					return new PartialFailure( $receipt->id, $created ); }
				$error = $response->as_error();
				return $error instanceof WP_Error ? ReasonMapper::sanitize( $error, $response->get_status() ) : ReasonMapper::error( 'internal_error' );
			}
			if ( null !== $mutation_type ) {
				return null !== $receipt->id ? new MutationResponse( $response, $receipt->id, $created ) : ReasonMapper::error( 'partial_failure' );
			}
			return $response;
		} catch ( \Throwable ) {
			return null !== $receipt->id ? new PartialFailure( $receipt->id, $created ) : ReasonMapper::error( 'internal_error' );
		} finally {
			if ( null !== $mutation_type && $delete_id ) {
				if ( 'term' === $kind ) {
					remove_action( 'delete_term', $observe_term_delete, PHP_INT_MIN );
				} else {
					remove_action( 'deleted_post', $observe_delete, PHP_INT_MIN );
					remove_action( 'trashed_post', $observe_delete, PHP_INT_MIN );
					remove_action( 'deleted_comment', $observe_comment_delete, PHP_INT_MIN );
					remove_action( 'trashed_comment', $observe_comment_delete, PHP_INT_MIN );
					remove_action( 'spammed_comment', $observe_comment_delete, PHP_INT_MIN );
				}
			}
			if ( null !== $mutation_type ) {
				if ( 'term' === $kind ) {
					remove_action( 'rest_insert_' . $mutation_type, $observe_term, PHP_INT_MIN );
				} elseif ( 'user' === $kind ) {
					remove_action( 'rest_insert_user', $observe_user, PHP_INT_MIN );
				} else {
					remove_action( 'rest_insert_' . $mutation_type, $observe, PHP_INT_MIN );
				}
			}
		}
	}
	/**
	 * Resolve the mutation object kind without trusting caller strings.
	 *
	 * @param string|null $mutation_type Reviewed type.
	 * @param string|null $object_kind Explicit kind or null to infer.
	 * @return string
	 */
	private static function resolve_kind( ?string $mutation_type, ?string $object_kind ): string {
		if ( in_array( $object_kind, array( 'post', 'comment', 'term', 'user' ), true ) ) {
			return $object_kind; }
		if ( 'comment' === $mutation_type ) {
			return 'comment'; }
		if ( 'user' === $mutation_type ) {
			return 'user'; }
		if ( null !== $mutation_type && function_exists( 'taxonomy_exists' ) ) {
			try {
				if ( taxonomy_exists( $mutation_type ) ) {
					return 'term'; }
			} catch ( \Throwable ) {
				return 'post'; }
		}
		return 'post';
	}
	/**
	 * Project a core collection using its real pagination headers.
	 *
	 * @param WP_REST_Response $response Core response.
	 * @param int              $page Selected page.
	 * @param Closure          $project Record projection.
	 * @phpstan-param Closure(array<string,mixed>):(array<string,mixed>|WP_Error) $project
	 * @return array<string,mixed>|WP_Error
	 */
	public function collection( WP_REST_Response $response, int $page, Closure $project ): array|WP_Error {
		$headers = array_change_key_case( $response->get_headers(), CASE_LOWER );
		$data    = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $headers['x-wp-total'], $headers['x-wp-totalpages'] ) || ! is_numeric( $headers['x-wp-total'] ) || ! is_numeric( $headers['x-wp-totalpages'] ) ) {
			return ReasonMapper::error( 'internal_error' );
		}
		$items = array();
		foreach ( array_values( $data ) as $row ) {
			$item = $project( $row );
			if ( $item instanceof WP_Error ) {
				return $item;
			} $items[] = $item; }
		return array(
			'items'       => $items,
			'total'       => (int) $headers['x-wp-total'],
			'total_pages' => (int) $headers['x-wp-totalpages'],
			'page'        => $page,
		);
	}
}
