<?php
/**
 * Profile field policy unit tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Users\ProfileFieldPolicy;

/** Pins plain-text profile fields, URL refusal and bounded registered meta. */
final class ProfileFieldPolicyTest extends TestCase {

	/** Whether the stub validator was called with null.
	 *
	 * @var bool
	 */
	private bool $validated_null = false;
	/** Policy under test.
	 *
	 * @var ProfileFieldPolicy
	 */
	private ProfileFieldPolicy $policy;

	/** Install WordPress substitutes and the policy. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text ): string {
				return self::strip( $text );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( string $text ): string {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test substitute for the core function.
				return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', self::strip( $text ) ) );
			}
		);
		Functions\when( 'sanitize_textarea_field' )->alias(
			static function ( string $text ): string {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test substitute for the core function.
				return trim( self::strip( $text ) );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url ): mixed {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test substitute for the core function.
				return parse_url( $url );
			}
		);
		Functions\when( 'esc_url_raw' )->alias(
			static function ( string $url, array $protocols = array() ): string {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test substitute for the core function.
				$parts  = parse_url( $url );
				$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
				if ( ! in_array( $scheme, $protocols, true ) ) {
					return '';
				}
				$normalized = preg_replace( '/\A[A-Za-z][A-Za-z0-9+.\-]*:/', $scheme . ':', $url, 1 );
				if ( ! is_string( $normalized ) ) {
					return '';
				}
				return str_contains( $url, ' ' ) ? str_replace( ' ', '%20', $normalized ) : $normalized;
			}
		);
		Functions\when( 'rest_validate_value_from_schema' )->alias(
			function ( mixed $value ): mixed {
				if ( null === $value ) {
					$this->validated_null = true;
					return new \WP_Error( 'fixture', 'fixture' );
				}
				return 'REVALIDATE_FAIL' === $value ? new \WP_Error( 'fixture', 'fixture' ) : true;
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		$this->policy = new ProfileFieldPolicy();
	}

	/** Remove substitutes. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Plain-text name fields are stripped and an empty name is refused. */
	public function test_plain_text_name_fields_and_empty_name(): void {
		$changes = $this->policy->changes(
			array(
				'name'       => '<b>Alice</b>',
				'first_name' => '<script>alert(1)</script>Bob',
				'nickname'   => '<img src=x onerror=alert(1)>Al',
			),
			array()
		);
		self::assertNotInstanceOf( \WP_Error::class, $changes );
		self::assertSame( 'Alice', $changes['name'] );
		self::assertSame( 'Bob', $changes['first_name'] );
		self::assertSame( 'Al', $changes['nickname'] );
		$this->assertError( 'invalid_params', $this->policy->changes( array( 'name' => '<b> </b>' ), array() ) );
	}

	/** Description, slug and locale are core-owned and pass through. */
	public function test_description_slug_and_locale_pass_through(): void {
		$changes = $this->policy->changes(
			array(
				'description' => '<a href="https://example.test">text</a>',
				'slug'        => 'fixture-slug',
				'locale'      => 'vi',
			),
			array()
		);
		self::assertNotInstanceOf( \WP_Error::class, $changes );
		self::assertSame( '<a href="https://example.test">text</a>', $changes['description'] );
		self::assertSame( 'fixture-slug', $changes['slug'] );
		self::assertSame( 'vi', $changes['locale'] );
	}

	/** URLs accept only absolute http/https values and normalize the scheme. */
	public function test_url_accepts_http_and_normalizes_the_scheme(): void {
		$changes = $this->policy->changes( array( 'url' => 'HTTPS://example.test/me' ), array() );
		self::assertNotInstanceOf( \WP_Error::class, $changes );
		self::assertSame( 'https://example.test/me', $changes['url'] );
		self::assertSame( '', $this->policy->changes( array( 'url' => '' ), array() )['url'] );
		foreach ( array( 'javascript:alert(1)', 'data:text/html,x', '//example.test', '/me', 'http://', 'https://user:pass@example.test', 'https://example.test/a b', 'https://' . str_repeat( 'a', 90 ) . '.test' ) as $url ) {
			$this->assertError( 'invalid_params', $this->policy->changes( array( 'url' => $url ), array() ) );
		}
	}

	/** Single string meta is plain text; textarea keeps newlines. */
	public function test_meta_string_paths(): void {
		$changes = $this->policy->changes(
			array(
				'meta' => array(
					'note' => '<img src=x onerror=alert(1)>hello',
					'body' => "line one\nline two",
				),
			),
			$this->registered()
		);
		self::assertNotInstanceOf( \WP_Error::class, $changes );
		self::assertSame( 'hello', $changes['meta']['note'] );
		self::assertSame( "line one\nline two", $changes['meta']['body'] );
	}

	/** Typed scalars and null keep their type; null is not revalidated. */
	public function test_meta_typed_scalars_and_null(): void {
		$changes = $this->policy->changes(
			array(
				'meta' => array(
					'flag'  => true,
					'count' => 7,
					'ratio' => 1.5,
					'note'  => null,
				),
			),
			$this->registered()
		);
		self::assertNotInstanceOf( \WP_Error::class, $changes );
		self::assertTrue( $changes['meta']['flag'] );
		self::assertSame( 7, $changes['meta']['count'] );
		self::assertSame( 1.5, $changes['meta']['ratio'] );
		self::assertNull( $changes['meta']['note'] );
		self::assertFalse( $this->validated_null );
	}

	/** The container bound is three; a fourth container and 101 leaves are refused. */
	public function test_meta_depth_and_leaf_bounds(): void {
		$three = array( 'nested' => array( array( array( 'ok' ) ) ) );
		self::assertNotInstanceOf( \WP_Error::class, $this->policy->changes( array( 'meta' => $three ), $this->registered() ) );
		$four = array( 'nested' => array( array( array( array( 'too-deep' ) ) ) ) );
		$this->assertError( 'invalid_params', $this->policy->changes( array( 'meta' => $four ), $this->registered() ) );
		$leaves = array( 'list' => array_fill( 0, 101, 'x' ) );
		$this->assertError( 'invalid_params', $this->policy->changes( array( 'meta' => $leaves ), $this->registered() ) );
	}

	/** The leaf bound covers the whole meta object, not each key. */
	public function test_meta_leaf_bound_spans_keys(): void {
		$fifty = array_fill( 0, 50, 'x' );
		$ok    = $this->policy->changes(
			array(
				'meta' => array(
					'list'  => $fifty,
					'list2' => $fifty,
				),
			),
			$this->registered()
		);
		self::assertNotInstanceOf( \WP_Error::class, $ok );
		$sixty = array_fill( 0, 60, 'x' );
		$this->assertError(
			'invalid_params',
			$this->policy->changes(
				array(
					'meta' => array(
						'list'  => $sixty,
						'list2' => $sixty,
					),
				),
				$this->registered()
			)
		);
	}

	/** The reviewed field list and every immutable key are pinned. */
	public function test_reviewed_field_list_and_immutable_keys(): void {
		self::assertSame(
			array( 'name', 'first_name', 'last_name', 'nickname', 'description', 'url', 'slug', 'locale', 'meta' ),
			ProfileFieldPolicy::FIELDS
		);
		foreach ( array( 'email', 'roles', 'user_pass', 'user_login', 'id', 'username' ) as $key ) {
			$this->assertError( 'invalid_params', $this->policy->changes( array( $key => 'fixture' ), array() ) );
		}
	}

	/** Object meta requires declared properties. */
	public function test_meta_object_requires_declared_properties(): void {
		$valid = $this->policy->changes( array( 'meta' => array( 'object' => array( 'a' => '<b>x</b>' ) ) ), $this->registered() );
		self::assertNotInstanceOf( \WP_Error::class, $valid );
		self::assertSame( 'x', $valid['meta']['object']['a'] );
		$this->assertError( 'invalid_params', $this->policy->changes( array( 'meta' => array( 'object' => array( 'b' => 'x' ) ) ), $this->registered() ) );
	}

	/** URI meta shares the URL rule and refuses unsafe schemes. */
	public function test_meta_uri_format_uses_the_url_rule(): void {
		self::assertNotInstanceOf( \WP_Error::class, $this->policy->changes( array( 'meta' => array( 'uri' => 'https://example.test/me' ) ), $this->registered() ) );
		$this->assertError( 'invalid_params', $this->policy->changes( array( 'meta' => array( 'uri' => 'javascript:alert(1)' ) ), $this->registered() ) );
	}

	/** Filtering that breaks the schema is refused, not silently stored. */
	public function test_meta_schema_revalidation_failure_is_refused(): void {
		$this->assertError( 'invalid_params', $this->policy->changes( array( 'meta' => array( 'note' => 'REVALIDATE_FAIL' ) ), $this->registered() ) );
	}

	/** Unknown profile fields and unregistered meta keys fail closed. */
	public function test_unknown_fields_and_keys_fail_closed(): void {
		$this->assertError( 'invalid_params', $this->policy->changes( array( 'email' => 'x@example.test' ), array() ) );
		$this->assertError( 'invalid_params', $this->policy->changes( array( 'meta' => array( 'unknown' => 'x' ) ), $this->registered() ) );
		$this->assertError( 'invalid_params', $this->policy->changes( array( 'meta' => array( 'note' => 'x' ) ), array( 'note' => 'not-a-schema' ) ) );
	}

	/**
	 * Emulate wp_strip_all_tags including script/style body removal.
	 *
	 * @param string $text Candidate.
	 * @return string
	 */
	private static function strip( string $text ): string {
		$text = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $text );
		$text = (string) preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $text );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test substitute for the core function.
		return strip_tags( $text );
	}

	/**
	 * Build the literal effective schema map used by the unit tests.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function registered(): array {
		return array(
			'note'   => array( 'type' => 'string' ),
			'body'   => array(
				'type'   => 'string',
				'format' => 'textarea-field',
			),
			'uri'    => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'flag'   => array( 'type' => 'boolean' ),
			'count'  => array( 'type' => 'integer' ),
			'ratio'  => array( 'type' => 'number' ),
			'object' => array(
				'type'       => 'object',
				'properties' => array( 'a' => array( 'type' => 'string' ) ),
			),
			'list'   => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'list2'  => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'nested' => array(
				'type'  => 'array',
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
		);
	}

	/**
	 * Assert one fixed safe error.
	 *
	 * @param string $reason Expected reason.
	 * @param mixed  $value Actual value.
	 * @return void
	 */
	private function assertError( string $reason, mixed $value ): void {
		self::assertInstanceOf( \WP_Error::class, $value );
		self::assertSame( 'xnetvn_agent_hub_wp_' . $reason, $value->get_error_code() );
	}
}
