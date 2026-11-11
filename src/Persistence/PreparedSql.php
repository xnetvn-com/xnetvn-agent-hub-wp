<?php
/**
 * Definite prepared statement boundary.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Persistence;

/** Rejects failed preparation instead of passing null as a query. */
final class PreparedSql {
	/**
	 * Bind values to a developer-owned SQL template.
	 *
	 * @param \wpdb        $db Database.
	 * @param string       $template SQL template.
	 * @param array<mixed> $values Bound values.
	 * @phpstan-param literal-string $template
	 * @return string
	 * @throws StorageException For failed preparation.
	 */
	public static function make( \wpdb $db, string $template, array $values ): string {
		$sql = $db->prepare( $template, $values );
		if ( ! is_string( $sql ) || '' === $sql ) {
			throw new StorageException( 'Storage statement is unavailable.' );
		}
		return $sql;
	}
}
