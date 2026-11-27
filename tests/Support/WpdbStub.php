<?php
/**
 * Minimal shared-database substitute for standalone owner-lock tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound,PEAR.NamingConventions.ValidClassName.StartWithCapital -- Isolated substitute for the core database type; the real symbol is global and lowercase.
if ( ! class_exists( 'wpdb', false ) ) {
	/** Answers only the lock queries the owner-lock boundary issues. */
	class wpdb {

		/**
		 * Table prefix used to derive the lock name.
		 *
		 * @var string
		 */
		public string $prefix = 'wp_';

		/**
		 * Loaded option values.
		 *
		 * @var array<string,mixed>
		 */
		public array $options = array();

		/**
		 * Recorded queries for the error boundary.
		 *
		 * @var array<int,array<int,string>>
		 */
		public array $queries = array();

		/**
		 * Last recorded database error.
		 *
		 * @var string
		 */
		public string $last_error = '';

		/**
		 * Configured RELEASE_LOCK value; null makes the query throw.
		 *
		 * @var string|null
		 */
		public ?string $release_result = '1';

		/**
		 * Current error suppression state.
		 *
		 * @var bool
		 */
		private bool $errors_suppressed = false;

		/**
		 * Toggle error suppression and report the previous state.
		 *
		 * @param bool $suppress Requested state.
		 * @return bool
		 */
		public function suppress_errors( bool $suppress = true ): bool {
			$previous                = $this->errors_suppressed;
			$this->errors_suppressed = $suppress;
			return $previous;
		}

		/**
		 * Report the prefix update_user_option() applies to site-local options.
		 *
		 * @return string
		 */
		public function get_blog_prefix(): string {
			return $this->prefix;
		}

		/**
		 * Substitute bound values so the boundary receives a non-empty statement.
		 *
		 * @param string $query SQL template.
		 * @param mixed  ...$args Bound values, or one value array.
		 * @return string
		 */
		public function prepare( string $query, mixed ...$args ): string {
			$values = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
			$index  = 0;
			return (string) preg_replace_callback(
				'/%[sdf]/',
				static function () use ( &$index, $values ): string {
					$value = $values[ $index ] ?? '';
					++$index;
					return is_scalar( $value ) ? (string) $value : '';
				},
				$query
			);
		}

		/**
		 * Answer DATABASE(), GET_LOCK and RELEASE_LOCK only.
		 *
		 * @param string|null $query Query text.
		 * @return string|null
		 * @throws \RuntimeException When the test configures a lost release connection.
		 */
		public function get_var( ?string $query = null ): ?string {
			$sql = (string) $query;
			if ( str_contains( $sql, 'DATABASE()' ) ) {
				return 'testdb';
			}
			if ( str_contains( $sql, 'GET_LOCK' ) ) {
				return '1';
			}
			if ( str_contains( $sql, 'RELEASE_LOCK' ) ) {
				if ( null === $this->release_result ) {
					throw new \RuntimeException( 'Fixture connection loss.' );
				}
				return $this->release_result;
			}
			return null;
		}
	}
}
// phpcs:enable
