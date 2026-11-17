<?php
/**
 * Scoped KSES boundary for MCP editorial writes.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities;

/** Applies WordPress KSES policies for one MCP mutation and restores exact hook state. */
final class SafeEditorialHtml {

	/** Nested run depth.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Run work under the reviewed editorial HTML boundary.
	 *
	 * @param callable():mixed $work Work to execute.
	 * @return mixed
	 */
	public function run( callable $work ): mixed {
		++$this->depth;
		$added      = array();
		$cap_filter = null;
		if ( 1 === $this->depth ) {
			$cap_filter = static function ( array $caps, string $cap, int $user_id, array $args ): array {
				unset( $user_id, $args );
				return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
			};
			add_filter( 'map_meta_cap', $cap_filter, PHP_INT_MAX, 4 );
			foreach ( $this->kses_hooks() as $hook => $callbacks ) {
				foreach ( $callbacks as $entry ) {
					list( $callback, $priority ) = $entry;
					if ( ! has_filter( $hook, $callback ) ) {
						$callable = fn( string $value ): string => $this->apply_kses( $callback, $value );
						add_filter( $hook, $callable, $priority );
						$added[]  = array( $hook, $callable, $priority );
					}
				}
			}
		}
		try {
			return $work();
		} finally {
			--$this->depth;
			if ( 0 === $this->depth ) {
				foreach ( $added as $entry ) {
					list( $hook, $callback, $priority ) = $entry;
					remove_filter( $hook, $callback, $priority );
				}
				if ( null !== $cap_filter ) {
					remove_filter( 'map_meta_cap', $cap_filter, PHP_INT_MAX );
				}
			}
		}
	}

	/**
	 * Run work with plain-text titles for the duration of that work.
	 *
	 * @param callable():mixed $work Work to execute.
	 * @return mixed
	 */
	public function run_plain_title( callable $work ): mixed {
		$plain = fn( string $value ): string => $this->filter_plain_text( $value );
		add_filter( 'title_save_pre', $plain, 20 );
		try {
			return $this->run( $work );
		} finally {
			remove_filter( 'title_save_pre', $plain, 20 );
		}
	}

	/**
	 * Filter one registered metadata value by its REST schema.
	 *
	 * @param array<string,mixed> $schema Registered REST schema for one meta key.
	 * @param mixed               $value  Submitted value.
	 * @return mixed|\WP_Error
	 */
	public function filter_meta( array $schema, mixed $value ): mixed {
		if ( null === $value ) {
			return null;
		}
		$type = (string) ( $schema['type'] ?? '' );
		if ( in_array( $type, array( 'integer', 'number', 'boolean' ), true ) ) {
			return $value;
		}
		if ( 'array' === $type ) {
			$items = is_array( $schema['items'] ?? null ) ? $schema['items'] : array();
			if ( 'string' !== (string) ( $items['type'] ?? '' ) || ! is_array( $value ) ) {
				return new \WP_Error( 'xnetvn_agent_hub_wp_invalid_meta_shape', 'Invalid metadata shape.' );
			}
			foreach ( $value as $index => $item ) {
				$filtered = $this->filter_meta_string( $items, $item );
				if ( is_wp_error( $filtered ) ) {
					return $filtered;
				}
				$value[ $index ] = $filtered;
			}
			return $value;
		}
		return 'string' === $type ? $this->filter_meta_string( $schema, $value ) : new \WP_Error( 'xnetvn_agent_hub_wp_invalid_meta_shape', 'Invalid metadata shape.' );
	}

	/**
	 * Apply the HTML policy to one metadata string leaf.
	 *
	 * @param array<string,mixed> $schema String schema.
	 * @param mixed               $value  Submitted value.
	 * @return string|\WP_Error
	 */
	private function filter_meta_string( array $schema, mixed $value ): string|\WP_Error {
		if ( ! is_string( $value ) ) {
			return new \WP_Error( 'xnetvn_agent_hub_wp_invalid_meta_shape', 'Invalid metadata shape.' );
		}
		$formats = array( 'hex-color', 'date-time', 'email', 'uri', 'ip', 'uuid', 'text-field', 'textarea-field' );
		if ( isset( $schema['format'] ) && in_array( (string) $schema['format'], $formats, true ) ) {
			return $value;
		}
		return $this->filter_post_html( $value );
	}

	/**
	 * Strip all markup from a plain-text field.
	 *
	 * @param string $value Field value.
	 * @return string
	 */
	public function filter_plain_text( string $value ): string {
		return sanitize_text_field( wp_strip_all_tags( $value ) );
	}

	/**
	 * Apply the post HTML allowlist to a rich field.
	 *
	 * @param string $value Field value.
	 * @return string
	 */
	public function filter_post_html( string $value ): string {
		return (string) $this->run( static fn(): string => wp_kses_post( $value ) );
	}

	/**
	 * Apply one reviewed KSES callback.
	 *
	 * @param string $callback Callback name.
	 * @param string $value Field value.
	 * @return string
	 */
	private function apply_kses( string $callback, string $value ): string {
		return match ( $callback ) {
			'wp_filter_post_kses' => wp_filter_post_kses( $value ),
			'wp_filter_global_styles_post' => function_exists( 'wp_filter_global_styles_post' ) ? wp_filter_global_styles_post( $value ) : $value,
			default => wp_filter_kses( $value ),
		};
	}

	/**
	 * Return the reviewed KSES hook map.
	 *
	 * @return array<string,array<array{0:string,1:int}>>
	 */
	private function kses_hooks(): array {
		return array(
			'content_save_pre'          => array(
				array( 'wp_filter_global_styles_post', 9 ),
				array( 'wp_filter_post_kses', 10 ),
			),
			'content_filtered_save_pre' => array(
				array( 'wp_filter_global_styles_post', 9 ),
				array( 'wp_filter_post_kses', 10 ),
			),
			'excerpt_save_pre'          => array( array( 'wp_filter_post_kses', 10 ) ),
			'title_save_pre'            => array( array( 'wp_filter_kses', 10 ) ),
			'pre_comment_content'       => array( array( 'wp_filter_kses', 10 ) ),
		);
	}
}
