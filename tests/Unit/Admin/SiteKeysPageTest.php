<?php
/**
 * Accountless site key mode rendering on the Keys page.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only WordPress doubles must live beside the test.

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Admin\Pages;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Minimal user double for the standalone page render. */
final class PageUser {
	/** User identity.
	 *
	 * @var int
	 */
	public int $ID;
	/** Role slugs.
	 *
	 * @var array<string>
	 */
	public array $roles;
	/** Direct capabilities.
	 *
	 * @var array<string,bool>
	 */
	public array $caps;

	/**
	 * Bind the fixture identity.
	 *
	 * @param int                $id Identity.
	 * @param array<string>      $roles Roles.
	 * @param array<string,bool> $caps Direct capabilities.
	 */
	public function __construct( int $id, array $roles, array $caps = array() ) {
		$this->ID    = $id;
		$this->roles = $roles;
		$this->caps  = $caps;
	}
}

/** Minimal role registry double. */
final class PageRoles {
	/** Role definitions.
	 *
	 * @var array<string,mixed>
	 */
	public array $roles;

	/**
	 * Bind role definitions.
	 *
	 * @param array<string,mixed> $roles Roles.
	 */
	public function __construct( array $roles ) {
		$this->roles = $roles;
	}
}

/** Verifies the manager-only site mode without a live WordPress installation. */
final class SiteKeysPageTest extends TestCase {

	/** Current user identity.
	 *
	 * @var int
	 */
	private int $current = 1;
	/** User fixtures keyed by identity.
	 *
	 * @var array<int,PageUser>
	 */
	private array $users = array();
	/** Effective capabilities keyed by identity.
	 *
	 * @var array<int,array<string>>
	 */
	private array $effective = array();
	/** Role registry returned by wp_roles().
	 *
	 * @var PageRoles
	 */
	private PageRoles $roles;
	/** Stored option array returned by get_option().
	 *
	 * @var array<string,mixed>
	 */
	private array $settings = array();
	/** Localized script payloads keyed by object name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $localized = array();
	/** Nonce action recorded by wp_create_nonce().
	 *
	 * @var string
	 */
	private string $nonce_action = '';
	/** Nonce field names emitted on the profile.
	 *
	 * @var array<string>
	 */
	private array $nonce_fields = array();

	/** Prepare the WordPress function doubles and the local class aliases. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'XNETVN_AGENT_HUB_WP_VERSION' ) ) {
			define( 'XNETVN_AGENT_HUB_WP_VERSION', '1.0.0' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Plugin constant in a standalone render test.
		}
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core time constant in a standalone test.
			define( 'HOUR_IN_SECONDS', 3600 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core time constant in a standalone test.
		}
		if ( ! class_exists( 'WP_User', false ) ) {
			class_alias( PageUser::class, 'WP_User' );
		}
		if ( ! class_exists( 'WP_Roles', false ) ) {
			class_alias( PageRoles::class, 'WP_Roles' );
		}
		$this->localized    = array();
		$this->nonce_fields = array();
		$this->nonce_action = '';
		$this->settings     = WpSiteSettings::DEFAULTS;
		$this->roles        = new PageRoles(
			array(
				'administrator' => array(
					'capabilities' => array(
						'manage_options' => true,
						'edit_posts'     => true,
					),
				),
				'editor'        => array(
					'capabilities' => array(
						'edit_posts'      => true,
						'delete_posts'    => true,
						'unfiltered_html' => true,
					),
				),
				'subscriber'    => array( 'capabilities' => array( 'read' => true ) ),
			)
		);
		$this->users        = array(
			1 => new PageUser( 1, array( 'administrator' ), array( 'manage_options' => true ) ),
			9 => new PageUser( 9, array( 'editor' ), array() ),
			5 => new PageUser( 5, array( 'subscriber' ), array() ),
		);
		$this->effective    = array(
			1 => array( Capabilities::MANAGE, Capabilities::USE, 'delete_posts', 'edit_posts' ),
			9 => array( Capabilities::USE, 'delete_posts', 'edit_posts' ),
			5 => array(),
		);
		$this->stub_wordpress();
	}

	/** Remove every function substitute. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The Keys page keeps one heading and navigation and adds exactly two modes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_keys_page_renders_site_mode_without_account_controls(): void {
		$html = $this->render_keys();
		self::assertSame( 1, substr_count( $html, '<h1>' ), 'one page heading' );
		self::assertSame( 6, substr_count( $html, '<li><a ' ), 'six navigation links' );
		self::assertSame( 1, substr_count( $html, 'aria-current="page"' ), 'one current link' );
		self::assertSame( 1, substr_count( $html, 'role="tablist"' ), 'one tablist' );
		self::assertSame( 2, substr_count( $html, 'role="tab"' ), 'two tabs' );
		self::assertSame( 1, substr_count( $html, 'aria-selected="true"' ), 'one selected tab' );
		self::assertSame( 1, substr_count( $html, 'aria-selected="false"' ), 'one unselected tab' );
		self::assertSame( 2, substr_count( $html, 'role="tabpanel"' ), 'two panels' );
		self::assertMatchesRegularExpression( '/id="xnetvn-panel-site"[^>]*\shidden/', $html, 'the site panel starts hidden' );
		self::assertDoesNotMatchRegularExpression( '/id="xnetvn-panel-personal"[^>]*\shidden/', $html, 'the personal panel starts visible' );
		self::assertMatchesRegularExpression( '/id="xnetvn-tab-personal"[^>]*aria-selected="true"[^>]*tabindex="0"/', $html, 'the personal tab is selected first' );
		self::assertMatchesRegularExpression( '/id="xnetvn-tab-site"[^>]*aria-selected="false"[^>]*tabindex="-1"/', $html, 'the site tab uses a roving tabindex' );
		self::assertStringContainsString( 'data-protected-personal-notice', $html, 'the protected manager sees the fail-closed notice' );
		self::assertStringContainsString( 'data-view="site-keys"', $html, 'the site panel renders' );

		$site = $this->site_panel( $html );
		self::assertSame( 0, substr_count( $site, '<select' ), 'no select control in the site panel' );
		self::assertSame( 0, substr_count( $site, '<textarea' ), 'no textarea in the site panel' );
		self::assertSame( 0, substr_count( $site, 'data-filter' ), 'no listing filter in the site panel' );
		self::assertSame( 0, substr_count( $site, 'xnetvn-key-owner' ), 'no owner input in the site panel' );
		foreach ( array( 'tier_max', 'toolsets', 'ability_allow', 'ability_deny', 'ip_allowlist', 'grace_hours', 'user_id', 'owner_user_id', 'blog_id', 'principal_type' ) as $field ) {
			self::assertSame( 0, substr_count( $site, 'data-field="' . $field . '"' ), "no personal field {$field}" );
			self::assertSame( 0, substr_count( $site, 'data-site-field="' . $field . '"' ), "no site field {$field}" );
		}
		self::assertStringContainsString( 'id="xnetvn-site-heading" tabindex="-1"', $site, 'the focus fallback heading renders' );
		foreach ( array( 'scope', 'html', 'risk', 'expiry', 'authors', 'logout' ) as $item ) {
			self::assertStringContainsString( 'data-site-help="' . $item . '"', $site, "the help item {$item} renders" );
		}
		self::assertSame( 10, substr_count( $site, '<th scope="col">' ), 'ten site table columns' );
		self::assertStringContainsString( '<caption>', $site, 'the site table has a caption' );
		self::assertStringContainsString( 'data-site-keys', $site, 'the site table body renders' );
		self::assertStringContainsString( 'data-prev', $site, 'the site pager renders previous' );
		self::assertStringContainsString( 'data-next', $site, 'the site pager renders next' );

		$ban   = $this->input( $site, 'xnetvn-site-permission-ban' );
		$unban = $this->input( $site, 'xnetvn-site-permission-unban' );
		self::assertStringContainsString( 'value="ban-users"', $ban );
		self::assertStringContainsString( 'value="unban-users"', $unban );
		self::assertStringContainsString( 'disabled', $ban, 'ban is disabled under the default policy' );
		self::assertStringContainsString( 'disabled', $unban, 'unban is disabled under the default policy' );
		self::assertStringContainsString( 'aria-describedby="xnetvn-site-risk-help"', $ban );
		self::assertStringNotContainsString( 'checked', $ban, 'ban starts unchecked' );
		self::assertStringNotContainsString( 'checked', $unban, 'unban starts unchecked' );
		self::assertStringContainsString( 'id="xnetvn-site-risk-help"', $site, 'the disabled reason renders' );
		self::assertStringContainsString( 'Allow Admin tier', $site, 'the reason names the site setting' );

		$password = $this->input( $site, 'xnetvn-site-password' );
		self::assertStringContainsString( 'type="password"', $password );
		self::assertStringContainsString( 'data-site-field="current_password"', $password );
		self::assertStringContainsString( 'required', $password );
		self::assertStringContainsString( 'autocomplete="current-password"', $password );

		preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
		self::assertSame( count( $ids[1] ), count( array_unique( $ids[1] ) ), 'every element id is unique on the page' );
	}

	/**
	 * The moderation boxes enable only when the site policy permits the admin tier.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_moderation_boxes_enable_under_an_admin_ceiling(): void {
		$this->settings = array(
			'tier_ceiling'     => 'admin',
			'allow_admin_tier' => true,
		);
		$site           = $this->site_panel( $this->render_keys() );
		self::assertStringNotContainsString( 'disabled', $this->input( $site, 'xnetvn-site-permission-ban' ), 'ban enables under an admin ceiling' );
		self::assertStringNotContainsString( 'disabled', $this->input( $site, 'xnetvn-site-permission-unban' ), 'unban enables under an admin ceiling' );
		self::assertStringContainsString( 'Allow Admin tier is on', $site, 'the reason states why the boxes are available' );
	}

	/**
	 * A denied profile shows only the notice while an eligible owner keeps the keys.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_profiles_fail_closed_for_protected_accounts_only(): void {
		$this->current = 1;
		ob_start();
		$this->pages()->profile( $this->users[1] );
		$admin = (string) ob_get_clean();
		self::assertSame( 1, substr_count( $admin, '<h2>' ), 'one protected heading' );
		self::assertSame( 1, substr_count( $admin, 'data-protected-personal-notice' ), 'one protected notice' );
		self::assertSame( 0, substr_count( $admin, 'data-view="keys"' ), 'no personal key section' );
		self::assertSame( 0, substr_count( $admin, '<dialog' ), 'no personal dialogs' );
		self::assertSame( 0, substr_count( $admin, 'xnetvn_agent_hub_wp_notifications' ), 'no notification nonce or checkbox' );

		$this->current = 9;
		ob_start();
		$this->pages()->profile( $this->users[9] );
		$editor = (string) ob_get_clean();
		self::assertStringContainsString( 'data-view="keys"', $editor, 'an eligible editor keeps the personal keys' );
		self::assertStringContainsString( 'xnetvn_agent_hub_wp_notifications', $editor, 'the owner keeps the notification preference' );
		self::assertStringContainsString( '<dialog', $editor, 'the personal dialogs render' );
		self::assertSame( 0, substr_count( $editor, 'data-protected-personal-notice' ), 'no notice for an eligible owner' );

		$this->current = 5;
		ob_start();
		$this->pages()->profile( $this->users[5] );
		self::assertSame( '', (string) ob_get_clean(), 'a subscriber still renders nothing' );
	}

	/**
	 * The localized site flags stay typed and carry no credential.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_localized_config_keeps_typed_site_flags_without_secrets(): void {
		$this->pages()->assets( 'toplevel_page_xnetvn-agent-hub-wp' );
		self::assertArrayHasKey( 'xnetvnAgentHubConfig', $this->localized );
		$config = $this->localized['xnetvnAgentHubConfig'];
		self::assertSame( 'wp_rest', $this->nonce_action, 'the script keeps the wp_rest nonce' );
		self::assertSame( 'test-nonce', $config['nonce'] );
		self::assertSame( array( 'namespace', 'nonce', 'site', 'userId' ), $this->sorted_keys( $config ) );
		self::assertIsArray( $config['site'] );
		self::assertIsBool( $config['site']['moderationAllowed'], 'moderationAllowed stays a boolean' );
		self::assertFalse( $config['site']['moderationAllowed'] );
		self::assertIsInt( $config['site']['maxDays'], 'maxDays stays a number' );
		self::assertSame( 365, $config['site']['maxDays'] );
		self::assertSame( 90, $config['site']['defaultDays'] );
		self::assertSame( 'destructive', $config['site']['ceiling'] );
		self::assertIsBool( $config['site']['personalProtected'] );
		self::assertTrue( $config['site']['personalProtected'] );
		self::assertStringEndsWith( '/xnetvn-agent-hub-wp/v1/site-keys', (string) $config['site']['endpoint'] );

		$flat = strtolower( (string) json_encode( $config ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Localized script payload under test.
		foreach ( array( 'bearer', 'secret', 'password', 'xnv_', 'xns_' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $flat, "the localized config never carries {$needle}" );
		}
		self::assertArrayNotHasKey( 'secret', $config );
	}

	/**
	 * The admin script keeps the reviewed guardrails.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_script_keeps_single_copy_helper_and_no_mcp_route(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the owned plugin script.
		$js = file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin.js' );
		self::assertIsString( $js );
		self::assertSame( 1, substr_count( $js, 'const copyReadonlyValue' ), 'one copy helper definition' );
		self::assertStringNotContainsString( '/mcp', $js, 'the script never calls the MCP route' );
		self::assertSame( 1, substr_count( $js, "Authorization: 'Bearer redacted'" ), 'one diagnostics placeholder' );
		self::assertSame( 0, preg_match( '/localStorage|sessionStorage|indexedDB|document\.cookie/', $js ), 'no browser storage' );
	}

	/**
	 * Render the Keys page for the current actor.
	 *
	 * @return string Rendered markup.
	 */
	private function render_keys(): string {
		ob_start();
		$this->pages()->render( 'keys' );
		return (string) ob_get_clean();
	}

	/**
	 * Build the page provider with the published three-argument constructor.
	 *
	 * @return Pages
	 */
	private function pages(): Pages {
		$catalog = $this->createMock( AbilityCatalog::class );
		$catalog->method( 'all' )->willReturn( array() );
		return new Pages( dirname( __DIR__, 3 ) . '/xnetvn-agent-hub-wp.php', new WpSiteSettings(), $catalog );
	}

	/**
	 * Extract the site panel markup from a rendered page.
	 *
	 * @param string $html Full page.
	 * @return string Site panel.
	 */
	private function site_panel( string $html ): string {
		self::assertSame( 1, preg_match( '/<section class="xnetvn-agent-hub-site" data-view="site-keys".*?<\/section>/s', $html, $match ), 'one site panel' );
		return $match[0];
	}

	/**
	 * Read one input tag by its id.
	 *
	 * @param string $html Site markup.
	 * @param string $id Input id.
	 * @return string Input tag.
	 */
	private function input( string $html, string $id ): string {
		self::assertSame( 1, preg_match( '/<input id="' . preg_quote( $id, '/' ) . '"[^>]*>/', $html, $match ), "one input {$id}" );
		return $match[0];
	}

	/**
	 * Sort associative keys for stable comparison.
	 *
	 * @param array<string,mixed> $value Associative array.
	 * @return array<string>
	 */
	private function sorted_keys( array $value ): array {
		$keys = array_keys( $value );
		sort( $keys );
		return $keys;
	}

	/** Install every WordPress function double the render paths need. */
	private function stub_wordpress(): void {
		Functions\when( 'get_option' )->alias( fn(): array => $this->settings );
		Functions\when( 'get_userdata' )->alias( fn( int $id ): mixed => $this->users[ $id ] ?? false );
		Functions\when( 'wp_roles' )->alias( fn(): PageRoles => $this->roles );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_super_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn( string $tag, mixed $value ): mixed => $value );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current );
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => in_array( $capability, $this->effective[ $this->current ] ?? array(), true ) );
		Functions\when( 'user_can' )->alias(
			function ( mixed $subject, string $capability ): bool {
				$id = is_object( $subject ) ? (int) $subject->ID : (int) $subject;
				return in_array( $capability, $this->effective[ $id ] ?? array(), true );
			}
		);
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'wp_create_nonce' )->alias(
			function ( string $action ): string {
				$this->nonce_action = $action;
				return 'test-nonce';
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			function ( mixed $action = -1, string $name = '_wpnonce' ): void {
				$this->nonce_fields[] = $name;
				echo '<input type="hidden" name="' . htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' ) . '" value="test-nonce">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test double emits escaped markup.
			}
		);
		Functions\when( 'checked' )->alias(
			static function ( mixed $checked, mixed $current = true ): string {
				return $checked === $current ? " checked='checked'" : '';
			}
		);
		Functions\when( 'selected' )->alias(
			static function ( mixed $selected, mixed $current = true ): string {
				return $selected === $current ? " selected='selected'" : '';
			}
		);
		Functions\when( 'disabled' )->alias(
			static function ( mixed $disabled, mixed $current = true ): string {
				return $disabled === $current ? " disabled='disabled'" : '';
			}
		);
		Functions\when( 'esc_html' )->alias( static fn( mixed $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_attr' )->alias( static fn( mixed $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_textarea' )->alias( static fn( mixed $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'rest_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-json/' . $path );
		Functions\when( 'plugin_dir_path' )->justReturn( '/tmp/plugin/' );
		Functions\when( 'plugins_url' )->alias( static fn( string $path ): string => 'https://example.test/plugin/' . $path );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_set_script_translations' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->alias(
			function ( string $handle, string $object_name, array $data ): void {
				$this->localized[ $object_name ] = $data;
			}
		);
	}
}
