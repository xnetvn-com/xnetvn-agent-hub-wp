<?php
/**
 * Accessible profile/admin rendering and asset scope tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Admin\Pages;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\SchemaBuilder;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\System\SystemPack;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Policy\Policy;

/** Checks output boundaries before browser flow tests are introduced. */
final class AdminPagesTest extends WP_UnitTestCase {
	/**
	 * Construct an independent page provider.
	 *
	 * @return Pages
	 */
	private function pages(): Pages {
		$settings = new WpSiteSettings();
		$registry = new Registry( new Policy( $settings ), new RequestScope(), new OutputPipeline( $settings ) );
		$registry->add_pack( new SystemPack( new DefinitionFactory(), $registry, new RequestScope(), new SchemaBuilder() ) );
		$registry->load_packs();
		return new Pages( dirname( __DIR__, 2 ) . '/xnetvn-agent-hub-wp.php', $settings, $registry );
	}

	/** Eligible users see labeled controls and a one-time dialog without a stored secret. */
	public function test_profile_render_and_subscriber_exclusion(): void {
		$owner = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $owner->ID );
		ob_start();
		$this->pages()->profile( $owner );
		$html = ob_get_clean();
		self::assertStringContainsString( 'Agent Hub API Keys', $html );
		self::assertStringContainsString( 'aria-labelledby=', $html );
		self::assertStringContainsString( '<dialog', $html );
		self::assertStringContainsString( 'type="password"', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'xnv_', $html );
		$subscriber = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber->ID );
		ob_start();
		$this->pages()->profile( $subscriber );
		self::assertSame( '', ob_get_clean() );
	}

	/** Scripts are absent from unrelated administration pages and present on profiles. */
	public function test_assets_are_scoped_and_use_wordpress_dependencies(): void {
		$pages = $this->pages();
		wp_dequeue_script( 'xnetvn-agent-hub-wp-admin' );
		$pages->assets( 'edit.php' );
		self::assertFalse( wp_script_is( 'xnetvn-agent-hub-wp-admin', 'enqueued' ) );
		$pages->assets( 'profile.php' );
		self::assertTrue( wp_script_is( 'xnetvn-agent-hub-wp-admin', 'enqueued' ) );
		self::assertSame( array( 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ), wp_scripts()->registered['xnetvn-agent-hub-wp-admin']->deps );
	}

	/** Settings API sanitization preserves unknown-policy boundaries and explicit types. */
	public function test_overview_menu_renders_only_once(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->pages()->menu();
		ob_start();
		do_action( 'toplevel_page_xnetvn-agent-hub-wp' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the core-generated page hook with the frozen plugin slug.
		$html = ob_get_clean();
		self::assertSame( 1, substr_count( $html, '<h1>Overview</h1>' ) );
	}

	/** Unified navigation marks the current view and never appears on profiles. */
	public function test_render_includes_unified_navigation_with_current(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$views = array( 'overview', 'keys', 'activity', 'tools', 'settings', 'help' );
		foreach ( $views as $view ) {
			ob_start();
			$this->pages()->render( $view );
			$html = ob_get_clean();
			self::assertSame( 1, substr_count( $html, '<h1>' ), "one heading on {$view}" );
			self::assertStringContainsString( '<nav class="xnetvn-nav"', $html );
			self::assertSame( 6, substr_count( $html, '<li><a ' ), "six navigation links on {$view}" );
			self::assertSame( 1, substr_count( $html, 'aria-current="page"' ), "one current link on {$view}" );
		}
		$owner = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $owner->ID );
		ob_start();
		$this->pages()->profile( $owner );
		$profile = ob_get_clean();
		self::assertStringNotContainsString( '<nav class="xnetvn-nav"', $profile );
		self::assertStringNotContainsString( '<form', $profile );
	}

	/** Async sections expose loading, error with retry, empty and live regions. */
	public function test_render_exposes_read_state_regions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( 'overview', 'keys', 'activity', 'tools' ) as $view ) {
			ob_start();
			$this->pages()->render( $view );
			$html = ob_get_clean();
			self::assertStringContainsString( 'data-loading', $html );
			self::assertStringContainsString( 'aria-busy=', $html );
			self::assertStringContainsString( 'role="status"', $html );
			self::assertStringContainsString( 'aria-live="polite"', $html );
			self::assertStringContainsString( 'data-error', $html );
			self::assertStringContainsString( 'data-retry', $html );
			self::assertStringContainsString( 'Try again', $html );
			self::assertStringContainsString( 'data-empty', $html );
		}
	}

	/** Result tables keep captions and named keyboard-focusable scroll regions. */
	public function test_tables_have_named_scroll_regions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( 'keys', 'activity' ) as $view ) {
			ob_start();
			$this->pages()->render( $view );
			$html = ob_get_clean();
			self::assertStringContainsString( '<caption>', $html );
			self::assertStringContainsString( 'scope="col"', $html );
			self::assertStringContainsString( 'tabindex="0"', $html );
			self::assertStringContainsString( 'aria-label="', $html );
		}
	}

	/** Plugin styles never target core markup or remote resources. */
	public function test_admin_css_is_scoped_to_plugin_selectors(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the owned plugin stylesheet.
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin.css' );
		self::assertIsString( $css );
		self::assertStringNotContainsString( 'http', $css );
		self::assertStringNotContainsString( '@import', $css );
		foreach ( preg_split( '/}/', $css ) as $block ) {
			$parts = preg_split( '/{/', $block, 2 );
			if ( ! isset( $parts[0] ) ) {
				continue;
			}
			$selectors = trim( $parts[0] );
			if ( '' === $selectors || str_starts_with( $selectors, '@' ) ) {
				continue;
			}
			foreach ( preg_split( '/,/', $selectors ) as $selector ) {
				$selector = trim( $selector );
				if ( '' === $selector ) {
					continue;
				}
				self::assertStringContainsString( '.xnetvn-', $selector, "scoped selector: {$selector}" );
			}
		}
	}

	/** Readonly copy helper exists and the secret dialog copy path is untouched. */
	public function test_admin_js_exposes_readonly_copy_helper(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the owned plugin script.
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin.js' );
		self::assertIsString( $js );
		self::assertStringContainsString( 'copyReadonlyValue', $js );
		self::assertStringContainsString( 'data-copy-endpoint', $js );
		self::assertStringContainsString( '[data-copy]', $js );
		self::assertStringContainsString( 'aria-busy', $js );
		self::assertStringContainsString( 'data-retry', $js );
	}

	/** Settings API sanitization preserves unknown-policy boundaries and explicit types. */
	public function test_settings_form_validation_and_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$pages = $this->pages();
		$pages->settings_api();
		$valid = $pages->sanitize_settings(
			array(
				'max_keys_per_user' => '4',
				'tier_ceiling'      => 'read',
				'enabled'           => '1',
			)
		);
		self::assertSame( 4, $valid['max_keys_per_user'] );
		self::assertSame( 'read', $valid['tier_ceiling'] );
		self::assertTrue( $valid['enabled'] );
		self::assertSame( 10, get_option( WpSiteSettings::OPTION, WpSiteSettings::DEFAULTS )['max_keys_per_user'] );
		$invalid = $pages->sanitize_settings( array( 'unknown_policy' => '1' ) );
		self::assertArrayNotHasKey( 'unknown_policy', $invalid );
		ob_start();
		$pages->render( 'settings' );
		$html = ob_get_clean();
		self::assertStringContainsString( 'name="_wpnonce"', $html );
		self::assertStringContainsString( 'options.php', $html );
	}

	/** Settings fields ship in open groups, once each, with labels, help text and published bounds. */
	public function test_settings_form_groups_every_stored_field_once_with_help_and_bounds(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$pages = $this->pages();
		$pages->settings_api();
		ob_start();
		$pages->render( 'settings' );
		$html = ob_get_clean();
		self::assertIsString( $html );

		preg_match_all( '/name="xnetvn_agent_hub_wp_settings\[([a-z0-9_]+)\]"/', $html, $matches );
		$rendered = array_values(
			array_filter( $matches[1], static fn( $rendered_name ): bool => 'allowed_roles_override_mode' !== $rendered_name )
		);
		$expected = array_keys( WpSiteSettings::DEFAULTS );
		sort( $expected );
		$found = array_values( array_unique( $rendered ) );
		sort( $found );
		self::assertSame( $expected, $found, 'every stored setting is grouped exactly once' );
		self::assertSame( 2, count( array_filter( $matches[1], static fn( $rendered_name ): bool => 'allowed_roles_override_mode' === $rendered_name ) ), 'the mode control renders once with its hidden zero' );

		$warned = array( 'allow_admin_tier', 'trusted_proxy_headers', 'trusted_proxy_cidrs', 'fallback_header_enabled', 'exposed_third_party_abilities', 'delete_data_on_uninstall' );
		foreach ( WpSiteSettings::DEFAULTS as $name => $default ) {
			$occurrences = count( array_filter( $rendered, static fn( $rendered_name ): bool => $name === $rendered_name ) );
			self::assertSame( is_bool( $default ) ? 2 : 1, $occurrences, "field {$name} renders once (a checkbox adds its hidden zero)" );
			self::assertStringContainsString( '<label for="xnetvn-setting-' . $name . '">', $html, "field {$name} keeps a label" );
			self::assertStringContainsString( 'id="xnetvn-help-' . $name . '"', $html, "field {$name} keeps help text" );
			$reference = in_array( $name, $warned, true )
				? 'aria-describedby="xnetvn-help-' . $name . ' xnetvn-warning-' . $name . '"'
				: 'aria-describedby="xnetvn-help-' . $name . '"';
			self::assertStringContainsString( $reference, $html, "field {$name} references its help text" );
			if ( is_int( $default ) ) {
				$range = WpSiteSettings::numeric_range( $name );
				self::assertMatchesRegularExpression( '/<input type="number"[^>]*id="xnetvn-setting-' . $name . '"[^>]*>/', $html, "field {$name} is numeric" );
				self::assertStringContainsString( 'min="' . $range[0] . '"', $html, "field {$name} keeps its documented minimum" );
				self::assertStringContainsString( 'max="' . $range[1] . '"', $html, "field {$name} keeps its documented maximum" );
			}
			if ( is_bool( $default ) ) {
				self::assertStringContainsString( '<input type="hidden" name="xnetvn_agent_hub_wp_settings[' . $name . ']" value="0">', $html, "checkbox {$name} keeps the hidden zero" );
			}
		}

		foreach ( array( 'Access', 'Network', 'Limits', 'Audit and data', 'Notifications', 'Media' ) as $group ) {
			self::assertSame( 1, substr_count( $html, '<legend>' . $group . '</legend>' ), "group {$group} renders once" );
		}
		self::assertSame( 6, substr_count( $html, '<fieldset class="xnetvn-settings-group"' ), 'six open groups replace the flat list' );
		self::assertStringNotContainsString( '<details', $html, 'no group hides behind a collapsed control' );
	}

	/** The role override control exposes three states without changing the stored policy. */
	public function test_settings_form_role_override_mode_exposes_three_states(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$pages = $this->pages();
		$pages->settings_api();

		$mode_pattern = '/<input type="checkbox" id="xnetvn-setting-allowed_roles_override_mode" name="xnetvn_agent_hub_wp_settings\[allowed_roles_override_mode\]" value="1"\s+aria-describedby="xnetvn-help-allowed_roles_override-mode">/';
		ob_start();
		$pages->render( 'settings' );
		$off = ob_get_clean();
		self::assertIsString( $off );
		self::assertStringContainsString( 'id="xnetvn-help-allowed_roles_override-mode"', $off, 'the mode control explains its three states' );
		self::assertMatchesRegularExpression( $mode_pattern, $off, 'the mode control is described' );
		self::assertStringNotContainsString( 'value="1" checked aria-describedby="xnetvn-help-allowed_roles_override-mode"', $off, 'the default keeps the custom role list disabled' );
		self::assertStringContainsString( '<input type="hidden" name="xnetvn_agent_hub_wp_settings[allowed_roles_override_mode]" value="0">', $off, 'the mode control keeps the hidden zero' );

		update_option( WpSiteSettings::OPTION, array( 'allowed_roles_override' => array( 'editor' ) ), false );
		ob_start();
		$pages->render( 'settings' );
		$list = ob_get_clean();
		self::assertIsString( $list );
		self::assertMatchesRegularExpression( '/value="1"\s+checked=\'checked\' aria-describedby="xnetvn-help-allowed_roles_override-mode">/', $list );
		self::assertStringContainsString( '>editor</textarea>', $list );

		self::assertNull(
			$pages->sanitize_settings(
				array(
					'allowed_roles_override_mode' => '0',
					'allowed_roles_override'      => "editor\nauthor",
				)
			)['allowed_roles_override'],
			'mode off stores null'
		);
		self::assertSame(
			array( 'editor', 'author' ),
			$pages->sanitize_settings(
				array(
					'allowed_roles_override_mode' => '1',
					'allowed_roles_override'      => "editor\nauthor",
				)
			)['allowed_roles_override'],
			'mode on with a list stores that list'
		);
		self::assertSame(
			array(),
			$pages->sanitize_settings(
				array(
					'allowed_roles_override_mode' => '1',
					'allowed_roles_override'      => '',
				)
			)['allowed_roles_override'],
			'mode on with an empty list denies every role'
		);
	}

	/** Unreadable stored settings ask for an explicit repair and never overwrite the policy. */
	public function test_settings_form_repairs_corrupt_settings_without_overwriting(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$pages = $this->pages();
		$pages->settings_api();
		// Simulate an out-of-band write (a migration or a direct database edit):
		// the registered sanitizer is detached so the stored value can be invalid.
		$filter = 'sanitize_option_' . WpSiteSettings::OPTION;
		remove_filter( $filter, array( $pages, 'sanitize_settings' ) );
		update_option( WpSiteSettings::OPTION, array( 'max_keys_per_user' => 'not-a-number' ), false );
		add_filter( $filter, array( $pages, 'sanitize_settings' ) );
		self::assertSame( array( 'max_keys_per_user' => 'not-a-number' ), get_option( WpSiteSettings::OPTION ), 'the stored value is corrupt' );

		ob_start();
		$pages->render( 'settings' );
		$html = ob_get_clean();
		self::assertIsString( $html );
		self::assertStringContainsString( 'Stored settings are unavailable', $html, 'the repair guidance names the cause' );
		self::assertStringContainsString( 'Repair', $html, 'the repair guidance names the action' );
		self::assertStringContainsString( 'management API', $html, 'the repair guidance names the existing API' );
		self::assertStringNotContainsString( 'not-a-number', $html, 'the invalid stored value is never echoed' );
		self::assertStringNotContainsString( '<form', $html, 'no editable form appears over unreadable settings' );
		self::assertStringContainsString( 'role="alert"', $html, 'the repair guidance is announced' );
		self::assertStringContainsString( 'tabindex="0"', $html, 'the repair guidance is keyboard reachable' );

		// A save that does not explicitly replace the invalid field is refused and
		// the corrupt value is returned unchanged, exactly as the sanitizer documents.
		$rejected = $pages->sanitize_settings( array( 'enabled' => '1' ) );
		self::assertSame( array( 'max_keys_per_user' => 'not-a-number' ), $rejected, 'a save that does not replace the invalid field is refused' );
		self::assertSame( array( 'max_keys_per_user' => 'not-a-number' ), get_option( WpSiteSettings::OPTION ), 'a refused save never replaces the stored value' );
		$reported = array_filter(
			get_settings_errors(),
			static fn( array $error ): bool => WpSiteSettings::OPTION === $error['setting']
		);
		self::assertNotEmpty( $reported, 'the refused save reports an error to the administrator' );
	}

	/** Sensitive settings explain their impact inline without changing any default. */
	public function test_settings_form_warns_about_sensitive_choices(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$pages = $this->pages();
		$pages->settings_api();
		ob_start();
		$pages->render( 'settings' );
		$html = ob_get_clean();
		self::assertIsString( $html );
		foreach ( array( 'allow_admin_tier', 'trusted_proxy_headers', 'trusted_proxy_cidrs', 'fallback_header_enabled', 'exposed_third_party_abilities', 'delete_data_on_uninstall' ) as $name ) {
			self::assertStringContainsString( 'id="xnetvn-warning-' . $name . '"', $html, "field {$name} explains its risk" );
			self::assertStringContainsString( 'aria-describedby="xnetvn-help-' . $name . ' xnetvn-warning-' . $name . '"', $html, "field {$name} references its risk" );
		}
	}

	/** Tools lookup controls filter the in-memory catalog without new requests. */
	public function test_tools_view_renders_labeled_lookup_controls(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$this->pages()->render( 'tools' );
		$html = ob_get_clean();
		self::assertIsString( $html );
		self::assertStringContainsString( '<label for="xnetvn-tool-search">', $html );
		self::assertStringContainsString( 'id="xnetvn-tool-search"', $html );
		self::assertStringContainsString( 'type="search"', $html );
		self::assertStringContainsString( '<label for="xnetvn-tool-toolset">', $html );
		self::assertStringContainsString( 'id="xnetvn-tool-toolset"', $html );
		self::assertStringContainsString( '<label for="xnetvn-tool-tier">', $html );
		foreach ( array( 'all', 'read', 'write', 'destructive', 'admin' ) as $tier ) {
			self::assertStringContainsString( 'value="' . $tier . '"', $html, "tier option {$tier}" );
		}
		self::assertStringContainsString( 'data-clear', $html );
		self::assertStringContainsString( 'type="button"', $html );
		self::assertStringContainsString( 'Clear filters', $html );
		self::assertStringContainsString( 'data-tools-count', $html );
		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'data-tools-no-results', $html );
		self::assertStringContainsString( 'No matching tools', $html );
		self::assertStringContainsString( 'data-tools', $html );
		self::assertStringContainsString( 'Execution always rechecks', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( '>Run<', $html );
		self::assertStringNotContainsString( '/mcp', $html );
		foreach ( array( 'overview', 'keys', 'activity' ) as $view ) {
			ob_start();
			$this->pages()->render( $view );
			$other = ob_get_clean();
			self::assertStringNotContainsString( 'xnetvn-tool-search', $other, "no tool search on {$view}" );
			self::assertStringNotContainsString( 'data-tools-count', $other, "no tool count on {$view}" );
		}
	}

	/** Tools filtering stays client-side with sorted text-only rendering. */
	public function test_admin_js_filters_tools_without_new_requests(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the owned plugin script.
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin.js' );
		self::assertIsString( $js );
		self::assertStringContainsString( '[data-filter="search"]', $js );
		self::assertStringContainsString( '[data-filter="toolset"]', $js );
		self::assertStringContainsString( '[data-filter="tier"]', $js );
		self::assertStringContainsString( 'toLowerCase', $js );
		self::assertStringContainsString( 'localeCompare', $js );
		self::assertStringContainsString( 'Technical details', $js );
		self::assertStringContainsString( 'data-tools-count', $js );
		self::assertStringContainsString( 'data-tools-no-results', $js );
		self::assertSame( 1, substr_count( $js, "'/tools'" ), 'one catalog GET feeds the in-memory copy' );
		self::assertStringNotContainsString( '/mcp', $js, 'filtering never calls MCP' );
	}

	/** Overview groups three getting-started steps with safe profile, keys and help links. */
	public function test_overview_getting_started_has_three_steps_and_safe_links(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$this->pages()->render( 'overview' );
		$html = ob_get_clean();
		self::assertIsString( $html );
		self::assertStringContainsString( 'Getting started', $html );
		self::assertSame( 1, preg_match( '/<ol[^>]*data-getting-started[^>]*>(.*?)<\/ol>/s', $html, $steps ), 'one getting-started list' );
		self::assertSame( 3, substr_count( $steps[1], '<li>' ), 'three getting-started steps' );
		self::assertStringContainsString( 'profile.php', $html, 'the first step links the profile' );
		self::assertStringContainsString( 'page=xnetvn-agent-hub-wp-keys', $html, 'the second step links the keys page' );
		self::assertStringContainsString( 'page=xnetvn-agent-hub-wp-help', $html, 'the third step links the help page' );
		self::assertStringNotContainsString( 'nonce', $html, 'no credential travels in the links' );
		self::assertStringNotContainsString( 'xnv_', $html, 'no raw token in the markup' );
		self::assertStringContainsString( 'id="xnetvn-endpoint"', $html );
		self::assertStringContainsString( 'readonly', $html );
		self::assertStringContainsString( 'data-copy-endpoint', $html );
		self::assertStringContainsString( 'Copy endpoint', $html );
	}

	/** Overview splits status into connection and site groups with a help fallback. */
	public function test_overview_status_groups_connection_and_site_with_help(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$this->pages()->render( 'overview' );
		$html = ob_get_clean();
		self::assertIsString( $html );
		self::assertStringContainsString( 'Connection', $html );
		self::assertStringContainsString( 'Site', $html );
		self::assertStringContainsString( 'data-status', $html );
		self::assertStringContainsString( 'data-status-connection', $html );
		self::assertStringContainsString( 'data-status-site', $html );
		self::assertStringContainsString( 'data-connection-help', $html );
		self::assertStringContainsString( 'data-check-header', $html );
		self::assertStringContainsString( 'Check Authorization header', $html );
		self::assertStringContainsString( 'data-header-result', $html );
	}

	/** Help guides four connection steps with safe profile, keys and overview links. */
	public function test_help_renders_four_guided_steps_with_safe_links(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$this->pages()->render( 'help' );
		$html = ob_get_clean();
		self::assertIsString( $html );
		self::assertStringContainsString( 'Connect a client', $html );
		self::assertSame( 1, preg_match( '/<ol[^>]*data-help-steps[^>]*>(.*?)<\/ol>/s', $html, $steps ), 'one guided steps list' );
		self::assertSame( 4, substr_count( $steps[1], '<li>' ), 'four guided steps' );
		self::assertStringContainsString( 'profile.php', $html, 'the steps link the profile' );
		self::assertStringContainsString( 'page=xnetvn-agent-hub-wp-keys', $html, 'the steps link the keys page' );
		self::assertStringContainsString( 'page=xnetvn-agent-hub-wp"', $html, 'the steps link the overview page' );
		self::assertStringNotContainsString( 'xnv_', $html, 'no raw token in the markup' );
		self::assertStringNotContainsString( '<form', $html, 'guidance never posts' );
		self::assertStringNotContainsString( 'type="password"', $html, 'no key field on help' );
	}

	/** Help exposes a readonly endpoint with copy and a credential-free sample. */
	public function test_help_renders_readonly_endpoint_with_copy_and_clean_sample(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$this->pages()->render( 'help' );
		$html = ob_get_clean();
		self::assertIsString( $html );
		self::assertStringContainsString( 'id="xnetvn-help-endpoint"', $html );
		self::assertStringContainsString( 'readonly', $html );
		self::assertStringContainsString( 'data-copy-endpoint', $html );
		self::assertStringContainsString( 'Copy endpoint', $html );
		self::assertStringContainsString( 'data-help-status', $html );
		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'data-help-sample', $html );
		self::assertStringContainsString( 'MCP_API_KEY', $html, 'the sample names the variable, never a value' );
		self::assertStringContainsString( 'REDACTED', $html, 'revoked values read as redacted' );
		self::assertStringContainsString( '/xnetvn-agent-hub-wp/mcp', $html, 'the sample targets the MCP route' );
		self::assertStringNotContainsString( 'xnv_', $html, 'no raw token in the sample' );
		self::assertStringNotContainsString( '<form', $html, 'copy never posts' );
		self::assertStringNotContainsString( 'download=', $html, 'no config download' );
	}

	/** Help troubleshooting names each failure with management and MCP actions. */
	public function test_help_troubleshooting_covers_common_failures(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$this->pages()->render( 'help' );
		$html = ob_get_clean();
		self::assertIsString( $html );
		self::assertStringContainsString( 'Troubleshooting', $html );
		foreach ( array( '401', '403', '429', '503' ) as $code ) {
			self::assertStringContainsString( $code, $html, "troubleshooting names {$code}" );
		}
		self::assertGreaterThanOrEqual( 4, substr_count( $html, '<details' ), 'one disclosure per failure' );
		self::assertStringContainsString( 'Reload', $html, 'management sessions reload and log in again' );
		self::assertStringContainsString( 'MCP', $html, 'failures separate management from MCP identity' );
		self::assertStringContainsString( 'security@xnetvn.net', $html, 'private reports stay off the page' );
		self::assertStringContainsString( 'page=xnetvn-agent-hub-wp-keys', $html, 'revocation links the keys page' );
		self::assertStringContainsString( 'untrusted', $html, 'site content stays untrusted data' );
		self::assertStringNotContainsString( '<form', $html, 'no support request form' );
		self::assertStringNotContainsString( 'xnv_', $html, 'no raw token in the guidance' );
	}

	/** Help copy reuses the readonly helper without a second clipboard path. */
	public function test_admin_js_wires_help_copy_through_readonly_helper(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the owned plugin script.
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin.js' );
		self::assertIsString( $js );
		self::assertStringContainsString( 'xnetvn-help-endpoint', $js );
		self::assertSame( 1, substr_count( $js, 'const copyReadonlyValue' ), 'one copy helper definition' );
		self::assertStringContainsString( "view === 'help'", $js );
	}
}
