<?php
/**
 * Prepared whitelisted audit metadata storage.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Persistence;

use XNetVN\AgentHubWP\Contracts\AuditRepository;
use XNetVN\AgentHubWP\Support\Json;
/** Rejects unexpected event fields rather than storing arbitrary request data. */
final class WpdbAuditRepository implements AuditRepository {
	use RunsQueries;

	/** Fixed optional-filter template, prepared once with all values. */
	private const WHERE_SQL = "(%s = '' OR key_id = %s) AND (%d = 0 OR user_id = %d) AND (%s = '' OR ability = %s) AND (%s = '' OR outcome = %s) AND (%s = '' OR request_id = %s) AND (%s = '' OR occurred_at >= %s) AND (%s = '' OR occurred_at <= %s)";

	/** Fixed audit column order. */
	private const COLUMNS = array( 'occurred_at', 'request_id', 'key_id', 'user_id', 'transport', 'protocol_version', 'mcp_method', 'ability', 'outcome', 'error_code', 'duration_ms', 'ip', 'ua_short', 'arg_keys' );
	/** Fixed listing whitelist: only the five columns the activity UI renders. */
	private const LIST_COLUMNS = array( 'occurred_at', 'user_id', 'ability', 'outcome', 'transport' );
	/**
	 * Bind the database.
	 *
	 * @param \wpdb $db Connection.
	 */
	public function __construct( private \wpdb $db ) {
	}
	/**
	 * Insert a bounded safe batch in one statement.
	 *
	 * @param array<array<string,mixed>> $events Events.
	 * @return int
	 * @throws \InvalidArgumentException For invalid events.
	 * @throws StorageException For an unavailable statement.
	 */
	public function insert_batch( array $events ): int {
		if ( ! $events ) {
			return 0;
		}
		if ( count( $events ) > 1000 ) {
			throw new \InvalidArgumentException( 'Audit batch is too large.' );
		}
		$rows = array();
		$args = array( $this->table() );
		foreach ( $events as $event ) {
			if ( array_diff( array_keys( $event ), self::COLUMNS ) || ! isset( $event['occurred_at'], $event['request_id'], $event['transport'], $event['outcome'], $event['arg_keys'] ) || ! is_array( $event['arg_keys'] ) ) {
				throw new \InvalidArgumentException( 'Invalid audit fields.' );
			}
			if ( ! in_array( $event['transport'], array( 'http', 'stdio' ), true ) || ! in_array( $event['outcome'], array( 'success', 'denied', 'error', 'invalid_input', 'rate_limited', 'auth_failed' ), true ) || ! is_string( $event['request_id'] ) || 1 !== preg_match( '/\A[a-f0-9]{16}\z/', $event['request_id'] ) ) {
				throw new \InvalidArgumentException( 'Invalid audit identity.' );
			}
			foreach ( $event['arg_keys'] as $key ) {
				if ( ! is_string( $key ) || strlen( $key ) > 100 ) {
					throw new \InvalidArgumentException( 'Invalid audit argument names.' );
				}
			}
			$event['arg_keys'] = Json::encode( array_values( $event['arg_keys'] ) );
			$values            = array();
			foreach ( self::COLUMNS as $column ) {
				$value = $event[ $column ] ?? null;
				if ( null === $value ) {
					$values[] = 'NULL';
				} elseif ( is_int( $value ) || is_string( $value ) ) {
					$values[] = is_int( $value ) ? '%d' : '%s';
					$args[]   = $value;
				} else {
					throw new \InvalidArgumentException( 'Invalid audit value type.' );
				}
			}
			$rows[] = '(' . implode( ',', $values ) . ')';
		}
		$columns = implode( ',', self::COLUMNS );
		$values  = implode( ',', $rows );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Columns/placeholders are fixed whitelisted syntax; every event value is bound.
		$sql = $this->db->prepare( "INSERT INTO %i ($columns) VALUES $values", $args );
		if ( ! is_string( $sql ) ) {
			throw new StorageException( 'Audit statement is unavailable.' );
		}
		return $this->write_query( fn() => $this->db->query( $sql ) );
	}
	/**
	 * Query a bounded page; items carry only the fixed listing whitelist.
	 *
	 * @param array<string,mixed> $filter Filters.
	 * @param int                 $page Page.
	 * @return array{items:array<array<string,mixed>>,total:int,page:int,per_page:int}
	 * @throws StorageException For an unavailable page or count.
	 */
	public function query( array $filter = array(), int $page = 1 ): array {
		$values = array( $this->table() );
		foreach ( array( 'key_id', 'user_id', 'ability', 'outcome', 'request_id', 'after', 'before' ) as $field ) {
			$value    = 'user_id' === $field ? (int) ( $filter[ $field ] ?? 0 ) : ( is_scalar( $filter[ $field ] ?? null ) ? (string) $filter[ $field ] : '' );
			$values[] = $value;
			$values[] = $value;
		}
		$page    = max( 1, $page );
		$size    = max( 1, min( 100, (int) ( $filter['per_page'] ?? 20 ) ) );
		$columns = implode( ',', self::LIST_COLUMNS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The column list comes from the LIST_COLUMNS constant, never from request data; every value stays bound.
		$list_sql  = PreparedSql::make( $this->db, "SELECT $columns FROM %i WHERE " . self::WHERE_SQL . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $values, array( $size, ( $page - 1 ) * $size ) ) );
		$count_sql = PreparedSql::make( $this->db, 'SELECT COUNT(*) FROM %i WHERE ' . self::WHERE_SQL, $values );
		$rows      = $this->run_query( fn() => $this->db->get_results( $list_sql, ARRAY_A ) );
		$total     = $this->run_query( fn() => $this->db->get_var( $count_sql ) );
		if ( ! is_array( $rows ) ) {
			throw new StorageException( 'Audit listing is unavailable.' );
		}
		if ( ! is_numeric( $total ) ) {
			throw new StorageException( 'Audit count is unavailable.' );
		}
		return array(
			'items'    => $rows,
			'total'    => (int) $total,
			'page'     => $page,
			'per_page' => $size,
		);
	}
	/**
	 * Delete only the requested bounded older batch.
	 *
	 * @param string $cutoff UTC cutoff.
	 * @param int    $limit Batch size.
	 * @return int
	 * @throws \InvalidArgumentException For an invalid batch size.
	 */
	public function purge_older_than( string $cutoff, int $limit ): int {
		if ( $limit < 1 || $limit > 1000 ) {
			throw new \InvalidArgumentException( 'Invalid retention batch size.' );
		}
		$sql = PreparedSql::make( $this->db, 'DELETE FROM %i WHERE occurred_at < %s ORDER BY id LIMIT %d', array( $this->table(), $cutoff, $limit ) );
		return $this->write_query( fn() => $this->db->query( $sql ) );
	}
	/**
	 * Resolve the current site table at call time.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->db->prefix . 'xnetvn_agent_hub_wp_audit';
	}
}
