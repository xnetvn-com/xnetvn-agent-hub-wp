<?php
/**
 * Disposable role/SEO lab mail sink with a control-bound fault gate.
 *
 * Active only in the local/development lab while the owned fixture marker is
 * present. Sinks every wp_mail call, records only recipient domains and
 * counts, and exposes no public endpoint. Never distributed in a release.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || ! get_option( 'xnetvn_agent_hub_wp_role_seo_fixture', false ) ) {
	return;
}

/**
 * Read the owned fixture marker.
 *
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_role_seo_marker(): array {
	$marker = get_option( 'xnetvn_agent_hub_wp_role_seo_fixture', array() );
	return is_array( $marker ) ? $marker : array();
}

/**
 * Count sunk deliveries without storing addresses or bodies.
 *
 * @return int
 */
function xnetvn_agent_hub_wp_role_seo_sink_count(): int {
	$sink = get_option( 'xnetvn_agent_hub_wp_role_seo_mail_sink', array() );
	return is_array( $sink ) ? count( $sink ) : 0;
}

/**
 * Read the recipient domain of the latest sunk delivery.
 *
 * @return string
 */
function xnetvn_agent_hub_wp_role_seo_sink_last_domain(): string {
	$sink = get_option( 'xnetvn_agent_hub_wp_role_seo_mail_sink', array() );
	if ( ! is_array( $sink ) || array() === $sink ) {
		return '';
	}
	$last = end( $sink );
	return is_array( $last ) && isset( $last['domain'] ) && is_string( $last['domain'] ) ? $last['domain'] : '';
}

/**
 * Arm or clear the suite fault gate. CLI-only and control-bound.
 *
 * @param string $control Run control from the private fixture state.
 * @param string $actor Owned actor alias the fault is attributed to.
 * @param string $mode One of mail-fail-once or none.
 * @return bool
 */
function xnetvn_agent_hub_wp_role_seo_fault( string $control, string $actor, string $mode ): bool {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return false;
	}
	$marker = xnetvn_agent_hub_wp_role_seo_marker();
	if ( ! isset( $marker['control'] ) || ! is_string( $marker['control'] ) || ! hash_equals( $marker['control'], $control ) ) {
		return false;
	}
	$users = $marker['users'] ?? array();
	if ( ! is_array( $users ) || ! array_key_exists( $actor, $users ) ) {
		return false;
	}
	if ( 'mail-fail-once' === $mode ) {
		update_option( 'xnetvn_agent_hub_wp_role_seo_fault', array( 'mail-fail-once' => true ), false );
		return true;
	}
	if ( 'none' === $mode ) {
		delete_option( 'xnetvn_agent_hub_wp_role_seo_fault' );
		return true;
	}
	return false;
}

/**
 * Count owned session tokens for one fixture actor. CLI-only and control-bound.
 *
 * Exposes no endpoint; the fixture calls it in-process to prove a ban ended
 * every session. Returns -1 when the control, actor or runtime gate refuses.
 *
 * @param string $control Run control from the private fixture state.
 * @param string $actor Owned actor alias the sessions belong to.
 * @return int
 */
function xnetvn_agent_hub_wp_role_seo_session_count( string $control, string $actor ): int {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return -1;
	}
	$marker = xnetvn_agent_hub_wp_role_seo_marker();
	if ( ! isset( $marker['control'] ) || ! is_string( $marker['control'] ) || ! hash_equals( $marker['control'], $control ) ) {
		return -1;
	}
	$users = $marker['users'] ?? array();
	if ( ! is_array( $users ) || ! isset( $users[ $actor ] ) || ! is_int( $users[ $actor ] ) ) {
		return -1;
	}
	if ( ! class_exists( 'WP_Session_Tokens' ) ) {
		return -1;
	}
	$sessions = WP_Session_Tokens::get_instance( $users[ $actor ] )->get_all();
	return is_array( $sessions ) ? count( $sessions ) : -1;
}

/**
 * Extract recipient domains without retaining addresses.
 *
 * @param mixed $to wp_mail recipients.
 * @return array<string>
 */
function xnetvn_agent_hub_wp_role_seo_domains( mixed $to ): array {
	$flat = array();
	if ( is_string( $to ) ) {
		$flat = explode( ',', $to );
	} elseif ( is_array( $to ) ) {
		foreach ( $to as $entry ) {
			$flat[] = is_string( $entry ) ? $entry : '';
		}
	}
	$domains = array();
	foreach ( $flat as $address ) {
		$address   = trim( (string) $address );
		$at        = strrpos( $address, '@' );
		$domains[] = ( false === $at ) ? 'invalid' : strtolower( substr( $address, $at + 1, 64 ) );
	}
	return array_values( array_unique( $domains ) );
}

/**
 * Arm or clear the suite lifecycle fault gate. CLI-only and control-bound.
 *
 * The gate is consumed by the MU filter below, not by this helper: the
 * fixture arms it through its own control check, so only the owned suite
 * run can trigger the single faulted projection.
 *
 * @param string $control Run control from the private fixture state.
 * @param string $actor Owned actor alias the fault is attributed to.
 * @param string $mode One of prepare-fail-once or none.
 * @return bool
 */
function xnetvn_agent_hub_wp_role_seo_lifecycle_fault( string $control, string $actor, string $mode ): bool {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return false;
	}
	$marker = xnetvn_agent_hub_wp_role_seo_marker();
	if ( ! isset( $marker['control'] ) || ! is_string( $marker['control'] ) || ! hash_equals( $marker['control'], $control ) ) {
		return false;
	}
	$users = $marker['users'] ?? array();
	if ( ! is_array( $users ) || ! array_key_exists( $actor, $users ) ) {
		return false;
	}
	if ( 'prepare-fail-once' === $mode ) {
		update_option( 'xnetvn_agent_hub_wp_role_seo_lifecycle_fault', array( 'prepare-fail-once' => true ), false );
		return true;
	}
	if ( 'none' === $mode ) {
		delete_option( 'xnetvn_agent_hub_wp_role_seo_lifecycle_fault' );
		return true;
	}
	return false;
}

add_filter(
	'rest_prepare_post',
	// The write has already landed when preparation runs: the core insert
	// hook fired first, so the ability keeps the receipt and reports a
	// partial failure instead of a silent success. Reads are never faulted:
	// duplicate-post reads its source through the same preparation hook,
	// and only the write projection may fail.
	static function ( $response, $post, $request ) {
		if ( $request instanceof WP_REST_Request && 'GET' === $request->get_method() ) {
			return $response;
		}
		$fault = get_option( 'xnetvn_agent_hub_wp_role_seo_lifecycle_fault', array() );
		if ( ! is_array( $fault ) || true !== ( $fault['prepare-fail-once'] ?? false ) ) {
			return $response;
		}
		delete_option( 'xnetvn_agent_hub_wp_role_seo_lifecycle_fault' );
		return new WP_Error( 'role_seo_lifecycle_prepare_fault', 'Owned lifecycle fault: projection unavailable once.' );
	},
	10,
	3
);

/**
 * Read the current stability barrier with stale-holder pruning.
 *
 * Only this suite arms the barrier and only suite-owned `stab-` uploads
 * wait on it; every other request passes through untouched. Stale entries
 * belong to holders whose connection died, which also freed their MySQL
 * budget slot, so they must never block a later status read.
 *
 * The crash record of a dead holder lives in its own option: the surviving
 * holder rewrites the whole barrier option every 200 milliseconds, and a
 * crash record stored there would be dropped by that rewrite. The dead
 * holder is also removed from the holder list, so only live holders count.
 *
 * @return array{waiting:int,released:bool,deadline:int,crashed:bool,crash_mechanism:string}
 */
function xnetvn_agent_hub_wp_role_seo_stability_status(): array {
	$option  = 'xnetvn_agent_hub_wp_role_seo_stability_barrier';
	$barrier = get_option( $option, array() );
	$crash   = get_option( 'xnetvn_agent_hub_wp_role_seo_stability_crash', array() );
	if ( ! is_array( $barrier ) ) {
		return array(
			'waiting'         => 0,
			'released'        => false,
			'deadline'        => 0,
			'crashed'         => false,
			'crash_mechanism' => '',
		);
	}
	$holders = ( isset( $barrier['holders'] ) && is_array( $barrier['holders'] ) ) ? $barrier['holders'] : array();
	$now     = time();
	foreach ( $holders as $holder => $seen ) {
		if ( ! is_int( $seen ) || $now - $seen > 30 ) {
			unset( $holders[ $holder ] );
		}
	}
	if ( is_array( $crash ) && isset( $crash['pid'] ) && is_string( $crash['pid'] ) ) {
		unset( $holders[ $crash['pid'] ] );
	}
	return array(
		'waiting'         => count( $holders ),
		'released'        => true === ( $barrier['release'] ?? false ),
		'deadline'        => (int) ( $barrier['deadline'] ?? 0 ),
		'crashed'         => is_array( $crash ) && true === ( $crash['crashed'] ?? false ),
		'crash_mechanism' => ( is_array( $crash ) && isset( $crash['mechanism'] ) && is_string( $crash['mechanism'] ) ) ? $crash['mechanism'] : '',
	);
}

/**
 * Measure a bounded owned read as numeric memory evidence.
 *
 * CLI-only and control-bound like the session counter: the fixture calls
 * this in-process and reports only the returned integers. The peak delta
 * is never zero-filled; when the control, actor or runtime gate refuses,
 * every field stays unavailable for the caller to detect.
 *
 * @param string $control Run control from the private fixture state.
 * @param string $actor Owned actor alias the read is attributed to.
 * @return array{baseline_bytes:int,peak_bytes:int,samples:int}
 */
function xnetvn_agent_hub_wp_role_seo_stability_probe( string $control, string $actor ): array {
	$refused = array(
		'baseline_bytes' => -1,
		'peak_bytes'     => -1,
		'samples'        => 0,
	);
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return $refused;
	}
	$marker = xnetvn_agent_hub_wp_role_seo_marker();
	if ( ! isset( $marker['control'] ) || ! is_string( $marker['control'] ) || ! hash_equals( $marker['control'], $control ) ) {
		return $refused;
	}
	$users = $marker['users'] ?? array();
	if ( ! is_array( $users ) || ! isset( $users[ $actor ] ) || ! is_int( $users[ $actor ] ) ) {
		return $refused;
	}
	$baseline = memory_get_usage();
	$profile  = get_userdata( $users[ $actor ] );
	$login    = ( $profile instanceof WP_User ) ? strlen( $profile->user_login ) : 0;
	if ( 0 === $login && 0 !== $users[ $actor ] ) {
		return $refused;
	}
	return array(
		'baseline_bytes' => $baseline,
		'peak_bytes'     => max( memory_get_peak_usage( true ), $baseline ),
		'samples'        => 1,
	);
}

add_filter(
	'wp_handle_sideload_prefilter',
	// Hold only suite-owned stab- uploads at the armed barrier while the
	// expensive-operation slot stays acquired: the Registry wraps the whole
	// ability execution in WpExpensiveBudget::run, so blocking here keeps
	// both MySQL slots held without creating any attachment row or file.
	// Unrelated uploads never match the stab- prefix and pass through.
	static function ( $file ) {
		if ( ! is_array( $file ) || ! isset( $file['name'] ) || ! is_string( $file['name'] ) || ! str_starts_with( $file['name'], 'stab-' ) ) {
			return $file;
		}
		$option  = 'xnetvn_agent_hub_wp_role_seo_stability_barrier';
		$barrier = get_option( $option, array() );
		if ( ! is_array( $barrier ) || ! in_array( $barrier['mode'] ?? '', array( 'hold', 'hold-short' ), true ) || true === ( $barrier['release'] ?? false ) ) {
			return $file;
		}
		$deadline = (int) ( $barrier['deadline'] ?? 0 );
		if ( $deadline > 0 && time() >= $deadline ) {
			return $file;
		}
		$me  = (string) getmypid();
		$end = min( $deadline > 0 ? $deadline : time() + 120, time() + 120 );
		do {
			wp_cache_delete( $option, 'options' );
			$barrier = get_option( $option, array() );
			if ( ! is_array( $barrier ) ) {
				$barrier = array();
			}
			$holders = ( isset( $barrier['holders'] ) && is_array( $barrier['holders'] ) ) ? $barrier['holders'] : array();
			$now     = time();
			foreach ( $holders as $holder => $seen ) {
				if ( ! is_int( $seen ) || $now - $seen > 30 ) {
					unset( $holders[ $holder ] );
				}
			}
			$holders[ $me ]     = $now;
			$barrier['holders'] = $holders;
			update_option( $option, $barrier, false );
			if ( str_starts_with( $file['name'], 'stab-crash-holder-' ) ) {
				// The crash holder ends its own request while the expensive
				// slot is still acquired: neither the release path nor the
				// finally block of the budget runs, so the MySQL lock must
				// die with the connection. The crash record goes into its
				// own option, because the surviving holder rewrites the
				// barrier option every 200 milliseconds and would drop it.
				$mechanism = function_exists( 'posix_kill' ) ? 'signal' : 'exit';
				update_option(
					'xnetvn_agent_hub_wp_role_seo_stability_crash',
					array(
						'crashed'   => true,
						'pid'       => $me,
						'mechanism' => $mechanism,
					),
					false
				);
				unset( $barrier['holders'][ $me ] );
				update_option( $option, $barrier, false );
				if ( 'signal' === $mechanism ) {
					posix_kill( (int) getmypid(), defined( 'SIGKILL' ) ? SIGKILL : 9 );
				}
				// The fallback aborts the request without the release path.
				exit( 1 );
			}
			if ( true === ( $barrier['release'] ?? false ) ) {
				break;
			}
			if ( $deadline > 0 && $now >= $deadline ) {
				break;
			}
			usleep( 200000 );
		} while ( time() < $end );
		wp_cache_delete( $option, 'options' );
		$barrier = get_option( $option, array() );
		if ( is_array( $barrier ) && isset( $barrier['holders'] ) && is_array( $barrier['holders'] ) ) {
			unset( $barrier['holders'][ $me ] );
			update_option( $option, $barrier, false );
		}
		return $file;
	}
);

add_filter(
	'pre_wp_mail',
	static function ( $pre, array $atts ) {
		$marker = xnetvn_agent_hub_wp_role_seo_marker();
		if ( ! isset( $marker['control'] ) ) {
			return $pre;
		}
		$fault = get_option( 'xnetvn_agent_hub_wp_role_seo_fault', array() );
		$fail  = is_array( $fault ) && true === ( $fault['mail-fail-once'] ?? false );
		if ( $fail ) {
			delete_option( 'xnetvn_agent_hub_wp_role_seo_fault' );
		}
		$sink = get_option( 'xnetvn_agent_hub_wp_role_seo_mail_sink', array() );
		if ( ! is_array( $sink ) ) {
			$sink = array();
		}
		$sink[] = array(
			'domain'  => xnetvn_agent_hub_wp_role_seo_domains( $atts['to'] ?? '' )[0] ?? 'invalid',
			'subject' => is_string( $atts['subject'] ?? null ) ? strlen( $atts['subject'] ) : 0,
			'failed'  => $fail,
		);
		update_option( 'xnetvn_agent_hub_wp_role_seo_mail_sink', array_slice( $sink, -200 ), false );
		return false;
	},
	1,
	2
);
