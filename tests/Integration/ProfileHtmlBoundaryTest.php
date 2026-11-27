<?php
/**
 * Persisted profile HTML, URL and hook-state acceptance tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UsersPack;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Proves the profile boundary against real users, meta and core REST. */
final class ProfileHtmlBoundaryTest extends WP_UnitTestCase {
	/** Registered writable meta keys. */
	private const META_KEYS = array( 'fixture_note', 'fixture_body', 'fixture_flag', 'fixture_count', 'fixture_tags' );

	/** Tools keyed by canonical name.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $tools = array();
	/** Settings.
	 *
	 * @var WpSiteSettings
	 */
	private WpSiteSettings $settings;
	/** Editor actor with unfiltered_html.
	 *
	 * @var int
	 */
	private int $actor;
	/** Prior REST server fixture.
	 *
	 * @var mixed
	 */
	private mixed $previous_server = null;

	/** Register the fixture meta, actor and tools. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$this->previous_server = $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$this->actor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$editor      = new \WP_User( $this->actor );
		$editor->add_cap( 'unfiltered_html' );
		wp_set_current_user( $this->actor );
		register_meta(
			'user',
			'fixture_note',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => array( 'schema' => array( 'type' => 'string' ) ),
			)
		);
		register_meta(
			'user',
			'fixture_body',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => array(
					'schema' => array(
						'type'   => 'string',
						'format' => 'textarea-field',
					),
				),
			)
		);
		register_meta(
			'user',
			'fixture_flag',
			array(
				'type'         => 'boolean',
				'single'       => true,
				'show_in_rest' => array( 'schema' => array( 'type' => 'boolean' ) ),
			)
		);
		register_meta(
			'user',
			'fixture_count',
			array(
				'type'         => 'integer',
				'single'       => true,
				'show_in_rest' => array( 'schema' => array( 'type' => 'integer' ) ),
			)
		);
		register_meta(
			'user',
			'fixture_tags',
			array(
				'type'         => 'string',
				'single'       => false,
				'show_in_rest' => array( 'schema' => array( 'type' => 'string' ) ),
			)
		);
		add_filter( 'xnetvn_agent_hub_wp_user_meta_writable', array( $this, 'writable_meta' ) );
		$this->settings = new WpSiteSettings();
		foreach ( ( new UsersPack( new DefinitionFactory(), null ) )->definitions() as $tool ) {
			$this->tools[ $tool->descriptor()->name ] = $tool;
		}
	}

	/** Remove the fixture opt-in. */
	public function tear_down(): void {
		remove_filter( 'xnetvn_agent_hub_wp_user_meta_writable', array( $this, 'writable_meta' ) );
		foreach ( self::META_KEYS as $key ) {
			unregister_meta_key( 'user', $key );
		}
		$GLOBALS['wp_rest_server'] = $this->previous_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restore the prior core fixture.
		parent::tear_down();
	}

	/**
	 * Opt the fixture keys into the existing allowlist.
	 *
	 * @param array<string> $keys Existing keys.
	 * @return array<string>
	 */
	public function writable_meta( array $keys ): array {
		return array_merge( $keys, self::META_KEYS );
	}

	/** Unsafe values write nothing; a valid URL is normalized and stored. */
	public function test_unsafe_values_are_refused_and_safe_values_persist(): void {
		$before_url = get_userdata( $this->actor )->user_url;
		$refused    = $this->call(
			'update-my-profile',
			array(
				'meta' => array( 'fixture_note' => '<img src=x onerror=alert(1)>hello' ),
				'url'  => 'javascript:alert(1)',
			)
		);
		self::assertWPError( $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $refused->get_error_code() );
		self::assertSame( '', get_user_meta( $this->actor, 'fixture_note', true ) );
		self::assertSame( $before_url, get_userdata( $this->actor )->user_url );
		foreach ( array( 'data:text/html,x', 'http://' ) as $unsafe ) {
			$refused = $this->call( 'update-my-profile', array( 'url' => $unsafe ) );
			self::assertWPError( $refused );
			self::assertSame( $before_url, get_userdata( $this->actor )->user_url );
		}
		$plain = $this->call( 'update-my-profile', array( 'url' => 'https://example.test/plain' ) );
		self::assertNotWPError( $plain );
		clean_user_cache( $this->actor );
		self::assertSame( 'https://example.test/plain', get_userdata( $this->actor )->user_url );

		$stored = $this->call(
			'update-my-profile',
			array(
				'meta' => array( 'fixture_note' => '<img src=x onerror=alert(1)>hello' ),
				'url'  => 'HTTPS://example.test/me',
			)
		);
		self::assertNotWPError( $stored );
		self::assertSame( 'hello', get_user_meta( $this->actor, 'fixture_note', true ) );
		clean_user_cache( $this->actor );
		self::assertSame( 'https://example.test/me', get_userdata( $this->actor )->user_url );
	}

	/** Textarea newlines and typed meta survive the policy. */
	public function test_textarea_and_typed_meta_persist(): void {
		$result = $this->call(
			'update-my-profile',
			array(
				'meta' => array(
					'fixture_body'  => "line one\nline two",
					'fixture_flag'  => true,
					'fixture_count' => 7,
				),
			)
		);
		self::assertNotWPError( $result );
		self::assertSame( "line one\nline two", get_user_meta( $this->actor, 'fixture_body', true ) );
		self::assertTrue( (bool) get_user_meta( $this->actor, 'fixture_flag', true ) );
		self::assertSame( 7, (int) get_user_meta( $this->actor, 'fixture_count', true ) );
	}

	/** Null meta deletes and an empty URL clears the column. */
	public function test_null_meta_and_empty_url_clear_values(): void {
		$this->call(
			'update-my-profile',
			array(
				'meta' => array( 'fixture_note' => 'first' ),
				'url'  => 'https://example.test/first',
			)
		);
		$result = $this->call(
			'update-my-profile',
			array(
				'meta' => array( 'fixture_note' => null ),
				'url'  => '',
			)
		);
		self::assertNotWPError( $result );
		self::assertSame( '', get_user_meta( $this->actor, 'fixture_note', true ) );
		clean_user_cache( $this->actor );
		self::assertSame( '', get_userdata( $this->actor )->user_url );
	}

	/** A non-single registered array key is stored as a list. */
	public function test_registered_array_meta_persists(): void {
		$result = $this->call( 'update-my-profile', array( 'meta' => array( 'fixture_tags' => array( 'a', 'b' ) ) ) );
		self::assertNotWPError( $result );
		self::assertSame( array( 'a', 'b' ), get_user_meta( $this->actor, 'fixture_tags', false ) );
	}

	/** The same core biography policy applies outside MCP. */
	public function test_outside_mcp_update_matches_the_control_user(): void {
		$control = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new \WP_User( $control );
		$user->add_cap( 'unfiltered_html' );
		$text   = '<script>alert(1)</script><a href="https://example.test">text</a>';
		$result = $this->call( 'update-my-profile', array( 'description' => $text ) );
		self::assertNotWPError( $result );
		wp_update_user(
			array(
				'ID'          => $control,
				'description' => $text,
			)
		);
		clean_user_cache( $this->actor );
		clean_user_cache( $control );
		self::assertSame( get_userdata( $control )->description, get_userdata( $this->actor )->description );
	}

	/** A site that removed core's biography filter is restored to that state. */
	public function test_missing_core_filter_is_restored(): void {
		remove_filter( 'pre_user_description', 'wp_filter_kses' );
		try {
			self::assertFalse( has_filter( 'pre_user_description', 'wp_filter_kses' ) );
			$result = $this->call( 'update-my-profile', array( 'description' => '<script>alert(1)</script>safe' ) );
			self::assertNotWPError( $result );
			self::assertFalse( has_filter( 'pre_user_description', 'wp_filter_kses' ) );
			self::assertStringNotContainsString( '<script', get_userdata( $this->actor )->description );
		} finally {
			add_filter( 'pre_user_description', 'wp_filter_kses' );
		}
	}

	/** Description stays core-owned and the biography hook state is restored. */
	public function test_description_and_hook_state_are_restored(): void {
		$before = $this->hook_state();
		$result = $this->call(
			'update-my-profile',
			array(
				'description' => '<script>alert(1)</script><a href="https://example.test" onmouseover="alert(2)">text</a>',
				'meta'        => array( 'fixture_note' => '<b>hello</b>' ),
			)
		);
		self::assertNotWPError( $result );
		$description = get_userdata( $this->actor )->description;
		self::assertStringNotContainsString( '<script', $description );
		self::assertStringNotContainsString( 'onmouseover', $description );
		self::assertSame( $before, $this->hook_state() );
	}

	/** A forced dispatch exception still restores the exact hook state and fails safely. */
	public function test_dispatch_exception_restores_hook_state(): void {
		$before = $this->hook_state();
		$throw  = static function (): void {
			throw new \RuntimeException( 'Fixture dispatch failure.' );
		};
		add_filter( 'rest_pre_dispatch', $throw, 10, 0 );
		try {
			$result = $this->call( 'update-my-profile', array( 'name' => 'Boundary Fixture' ) );
			self::assertWPError( $result );
		} finally {
			remove_filter( 'rest_pre_dispatch', $throw, 10 );
		}
		self::assertSame( $before, $this->hook_state() );
	}

	/** The site principal cannot call the human-only profile operation. */
	public function test_machine_principal_is_refused(): void {
		$envelope = new SafeMcpEnvelope();
		self::assertFalse( $envelope->evaluate( 'xnetvn/update-my-profile', SafeMcpEnvelope::PRINCIPAL_SITE )->allowed );
		self::assertFalse( $envelope->evaluate( 'xnetvn/create-user', SafeMcpEnvelope::PRINCIPAL_SITE )->allowed );
	}

	/**
	 * Execute one schema-valid request through final output checks.
	 *
	 * @param string              $name Tool.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function call( string $name, array $args = array() ): mixed {
		$tool = $this->tools[ 'xnetvn/' . $name ];
		if ( true !== rest_validate_value_from_schema( $args, $tool->input_schema() ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		return ( new OutputPipeline( $this->settings ) )->process( $tool->execute( $args ), $tool->output_schema() );
	}

	/**
	 * Describe the exact biography hook state.
	 *
	 * @return array<string>
	 */
	private function hook_state(): array {
		$hook = $GLOBALS['wp_filter']['pre_user_description'] ?? null;
		if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
			return array();
		}
		$state = array();
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( array_keys( (array) $callbacks ) as $id ) {
				$state[] = (string) $priority . ':' . (string) $id;
			}
		}
		sort( $state );
		return $state;
	}
}
