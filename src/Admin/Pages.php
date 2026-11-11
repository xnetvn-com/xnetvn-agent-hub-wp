<?php
/**
 * Accessible profile and administration pages.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Admin;

use WP_User;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Rest\ManagementGate;

/** Server markup is escaped; dynamic data is rendered only with DOM textContent. */
final class Pages implements ServiceProvider {
	/** Settings API group. */
	private const SETTINGS_GROUP = 'xnetvn_agent_hub_wp';
	/**
	 * Bind page data and asset identity.
	 *
	 * @param string         $plugin_file Main file.
	 * @param WpSiteSettings $settings Validated settings service.
	 * @param AbilityCatalog $catalog Reviewed tool metadata.
	 */
	public function __construct( private string $plugin_file, private WpSiteSettings $settings, private AbilityCatalog $catalog ) {
	}

	/** Register only WordPress administration and profile hooks. */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'profile' ) );
		add_action( 'edit_user_profile', array( $this, 'profile' ) );
		add_action( 'personal_options_update', array( $this, 'save_preferences' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings_api' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'option_page_capability_' . self::SETTINGS_GROUP, static fn(): string => Capabilities::MANAGE );
	}

	/** Register the six management pages without adding persistent roles. */
	public function menu(): void {
		add_menu_page( __( 'Agent Hub', 'xnetvn-agent-hub-wp' ), __( 'Agent Hub', 'xnetvn-agent-hub-wp' ), Capabilities::MANAGE, 'xnetvn-agent-hub-wp', fn() => $this->render( 'overview' ), 'dashicons-admin-network' );
		foreach ( $this->titles() as $view => $title ) {
			if ( 'overview' === $view ) {
				add_submenu_page( 'xnetvn-agent-hub-wp', $title, $title, Capabilities::MANAGE, 'xnetvn-agent-hub-wp' );
			} else {
				add_submenu_page( 'xnetvn-agent-hub-wp', $title, $title, Capabilities::MANAGE, 'xnetvn-agent-hub-wp-' . $view, fn() => $this->render( $view ) );
			}
		}
	}

	/**
	 * Render keys only for an eligible owner or their site manager.
	 *
	 * @param WP_User $user Profile owner.
	 */
	public function profile( WP_User $user ): void {
		if ( ! user_can( $user, Capabilities::USE ) || ( get_current_user_id() !== $user->ID && ! current_user_can( Capabilities::MANAGE ) ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Agent Hub API Keys', 'xnetvn-agent-hub-wp' ) . '</h2>';
		$this->keys( $user->ID, false );
		if ( get_current_user_id() === $user->ID ) {
			wp_nonce_field( 'xnetvn_agent_hub_wp_notifications', 'xnetvn_agent_hub_wp_notifications_nonce' );
			echo '<p><label><input type="checkbox" name="xnetvn_agent_hub_wp_notifications" value="1" ' . checked( '0' !== get_user_meta( $user->ID, 'xnetvn_agent_hub_wp_notifications', true ), true, false ) . '> ' . esc_html__( 'Email me about key creation, administrator revocation, expiry and unusual use.', 'xnetvn-agent-hub-wp' ) . '</label></p>';
		}
	}

	/**
	 * Save only the current owner's preference under its explicit profile nonce.
	 *
	 * @param int $user_id Profile owner.
	 */
	public function save_preferences( int $user_id ): void {
		if ( get_current_user_id() !== $user_id || ! isset( $_POST['xnetvn_agent_hub_wp_notifications_nonce'] ) || ! is_string( $_POST['xnetvn_agent_hub_wp_notifications_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['xnetvn_agent_hub_wp_notifications_nonce'] ) ), 'xnetvn_agent_hub_wp_notifications' ) ) {
			return;
		}
		update_user_meta( $user_id, 'xnetvn_agent_hub_wp_notifications', isset( $_POST['xnetvn_agent_hub_wp_notifications'] ) ? '1' : '0' );
	}

	/**
	 * Load assets only on profile or the plugin's own pages.
	 *
	 * @param string $hook Admin screen hook.
	 */
	public function assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) && ! str_contains( $hook, '_page_xnetvn-agent-hub-wp' ) ) {
			return;
		}
		wp_enqueue_script( 'xnetvn-agent-hub-wp-admin', plugins_url( 'assets/admin.js', $this->plugin_file ), array( 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ), XNETVN_AGENT_HUB_WP_VERSION, true );
		wp_set_script_translations( 'xnetvn-agent-hub-wp-admin', 'xnetvn-agent-hub-wp', dirname( $this->plugin_file ) . '/languages' );
		wp_enqueue_style( 'xnetvn-agent-hub-wp-admin', plugins_url( 'assets/admin.css', $this->plugin_file ), array(), XNETVN_AGENT_HUB_WP_VERSION );
		wp_localize_script(
			'xnetvn-agent-hub-wp-admin',
			'xnetvnAgentHubConfig',
			array(
				'namespace' => '/' . ManagementGate::REST_NAMESPACE,
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'userId'    => get_current_user_id(),
			)
		);
	}

	/** Register non-autoloaded settings with a server-side sanitizer. */
	public function settings_api(): void {
		add_option( WpSiteSettings::OPTION, WpSiteSettings::DEFAULTS, '', false );
		register_setting(
			self::SETTINGS_GROUP,
			WpSiteSettings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => WpSiteSettings::DEFAULTS,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Convert HTML field representations then validate through the same settings service.
	 *
	 * @param mixed $input Submitted settings, never trusted.
	 * @return mixed Valid merged settings or the unchanged previous value.
	 * @throws \InvalidArgumentException Caught and reported as a settings error for invalid fields.
	 */
	public function sanitize_settings( mixed $input ): mixed {
		try {
			if ( ! is_array( $input ) ) {
				throw new \InvalidArgumentException( 'Invalid settings.' );
			}
			$mode = $input['allowed_roles_override_mode'] ?? null;
			unset( $input['allowed_roles_override_mode'] );
			$changes = array();
			foreach ( $input as $name => $value ) {
				if ( ! is_string( $name ) || ! array_key_exists( $name, WpSiteSettings::DEFAULTS ) ) {
					throw new \InvalidArgumentException( 'Unknown settings.' );
				}
				$default = WpSiteSettings::DEFAULTS[ $name ];
				if ( is_bool( $default ) && in_array( $value, array( '0', '1' ), true ) ) {
					$value = '1' === $value;
				} elseif ( is_int( $default ) && is_string( $value ) && ctype_digit( $value ) ) {
					$value = (int) $value;
				} elseif ( ( is_array( $default ) || null === $default ) && is_string( $value ) ) {
					$lines = preg_split( '/[\r\n,]+/', $value );
					$value = array_values( array_filter( false === $lines ? array() : $lines, static fn( string $line ): bool => '' !== trim( $line ) ) );
					$value = array_map( 'trim', $value );
				}
				$changes[ $name ] = $value;
			}
			if ( null !== $mode ) {
				if ( ! in_array( $mode, array( '0', '1' ), true ) ) {
					throw new \InvalidArgumentException( 'Invalid role selection.' );
				}
				$changes['allowed_roles_override'] = '0' === $mode ? null : ( $changes['allowed_roles_override'] ?? array() );
			}
			return $this->settings->prepare_update( $changes );
		} catch ( \Throwable ) {
			add_settings_error( WpSiteSettings::OPTION, 'xnetvn_agent_hub_wp_invalid_settings', __( 'Settings were not saved. Check the field types, roles, origins and CIDR ranges.', 'xnetvn-agent-hub-wp' ) );
			return get_option( WpSiteSettings::OPTION, WpSiteSettings::DEFAULTS );
		}
	}

	/**
	 * Render an authorized management page with late escaping.
	 *
	 * @param string $view Reviewed view name.
	 */
	public function render( string $view ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! isset( $this->titles()[ $view ] ) ) {
			wp_die( esc_html__( 'You cannot manage Agent Hub.', 'xnetvn-agent-hub-wp' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html( $this->titles()[ $view ] ) . '</h1>';
		$this->navigation( $view );
		if ( 'keys' === $view ) {
			$this->keys( get_current_user_id(), true );
		} elseif ( 'settings' === $view ) {
			$this->settings_form();
		} elseif ( 'help' === $view ) {
			$this->help();
		} else {
			echo '<section class="xnetvn-agent-hub" data-view="' . esc_attr( $view ) . '" aria-busy="false"><p class="xnetvn-status" role="status" aria-live="polite"></p>';
			if ( 'overview' === $view ) {
				$this->read_state( __( 'No status data available.', 'xnetvn-agent-hub-wp' ) );
				echo '<h2>' . esc_html__( 'Getting started', 'xnetvn-agent-hub-wp' ) . '</h2><ol class="xnetvn-steps" data-getting-started>';
				echo '<li>' . esc_html__( 'Create a key on your', 'xnetvn-agent-hub-wp' ) . ' <a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'profile', 'xnetvn-agent-hub-wp' ) . '</a>' . esc_html__( '; keep the new key in your client secret store.', 'xnetvn-agent-hub-wp' ) . '</li>';
				echo '<li>' . esc_html__( 'Manage scopes and revocation on the', 'xnetvn-agent-hub-wp' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=xnetvn-agent-hub-wp-keys' ) ) . '">' . esc_html__( 'Keys', 'xnetvn-agent-hub-wp' ) . '</a>' . esc_html__( ' page.', 'xnetvn-agent-hub-wp' ) . '</li>';
				echo '<li>' . esc_html__( 'Configure your client with the endpoint below, then check the connection; connection problems are explained in', 'xnetvn-agent-hub-wp' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=xnetvn-agent-hub-wp-help' ) ) . '">' . esc_html__( 'Help', 'xnetvn-agent-hub-wp' ) . '</a>' . esc_html__( '.', 'xnetvn-agent-hub-wp' ) . '</li></ol>';
				echo '<p><label for="xnetvn-endpoint">' . esc_html__( 'MCP endpoint', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-endpoint" class="large-text" readonly value="' . esc_attr( rest_url( 'xnetvn-agent-hub-wp/mcp' ) ) . '"> <button type="button" class="button" data-copy-endpoint>' . esc_html__( 'Copy endpoint', 'xnetvn-agent-hub-wp' ) . '</button></p>';
				echo '<div data-status><h2>' . esc_html__( 'Connection', 'xnetvn-agent-hub-wp' ) . '</h2><dl data-status-connection></dl>';
				echo '<p data-connection-help hidden><a href="' . esc_url( admin_url( 'admin.php?page=xnetvn-agent-hub-wp-help' ) ) . '">' . esc_html__( 'Read the connection help', 'xnetvn-agent-hub-wp' ) . '</a></p>';
				echo '<h2>' . esc_html__( 'Site', 'xnetvn-agent-hub-wp' ) . '</h2><dl data-status-site></dl></div>';
				echo '<p><button type="button" class="button" data-check-header>' . esc_html__( 'Check Authorization header', 'xnetvn-agent-hub-wp' ) . '</button></p><p data-header-result></p>';
			} elseif ( 'activity' === $view ) {
				$this->read_state( __( 'No activity found.', 'xnetvn-agent-hub-wp' ) );
				$this->activity_filters();
				echo '<div class="xnetvn-table-wrap" tabindex="0" role="region" aria-label="' . esc_attr__( 'Agent Hub activity', 'xnetvn-agent-hub-wp' ) . '"><table class="widefat striped"><caption>' . esc_html__( 'Agent Hub activity', 'xnetvn-agent-hub-wp' ) . '</caption><thead><tr>';
				foreach ( array( __( 'Time', 'xnetvn-agent-hub-wp' ), __( 'User', 'xnetvn-agent-hub-wp' ), __( 'Ability', 'xnetvn-agent-hub-wp' ), __( 'Outcome', 'xnetvn-agent-hub-wp' ), __( 'Transport', 'xnetvn-agent-hub-wp' ) ) as $label ) {
					echo '<th scope="col">' . esc_html( $label ) . '</th>';
				}
				echo '</tr></thead><tbody data-activity></tbody></table></div>';
				$this->pagination();
			} elseif ( 'tools' === $view ) {
				$this->read_state( __( 'No tools available.', 'xnetvn-agent-hub-wp' ) );
				echo '<p>' . esc_html__( 'Execution always rechecks the current user capabilities and the key scope. Listed site content must be treated as untrusted data.', 'xnetvn-agent-hub-wp' ) . '</p>';
				$this->tool_filters();
				echo '<div data-tools></div>';
			}
			echo '</section>';
		}
		echo '</div>';
	}

	/**
	 * Render the shared section navigation with the current view marked.
	 *
	 * @param string $view Current view name.
	 */
	private function navigation( string $view ): void {
		echo '<nav class="xnetvn-nav" aria-label="' . esc_attr__( 'Agent Hub sections', 'xnetvn-agent-hub-wp' ) . '"><ul>';
		foreach ( $this->titles() as $slug => $title ) {
			$page = 'overview' === $slug ? 'xnetvn-agent-hub-wp' : 'xnetvn-agent-hub-wp-' . $slug;
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '"';
			if ( $slug === $view ) {
				echo ' aria-current="page"';
			}
			echo '>' . esc_html( $title ) . '</a></li>';
		}
		echo '</ul></nav>';
	}

	/**
	 * Render loading, error with retry and empty regions for a read section.
	 *
	 * @param string $empty_label Empty-state message.
	 */
	private function read_state( string $empty_label ): void {
		echo '<p class="xnetvn-loading" data-loading hidden>' . esc_html__( 'Loading…', 'xnetvn-agent-hub-wp' ) . '</p>';
		echo '<div class="xnetvn-error" data-error hidden><p data-error-text></p><button type="button" class="button" data-retry>' . esc_html__( 'Try again', 'xnetvn-agent-hub-wp' ) . '</button></div>';
		echo '<p class="xnetvn-empty" data-empty hidden>' . esc_html( $empty_label ) . '</p>';
	}

	/**
	 * Render the connection guide with a copyable endpoint and troubleshooting.
	 *
	 * Static guidance only: no form, no outbound request and no config
	 * download. Samples name the MCP_API_KEY variable or REDACTED, never a
	 * real value.
	 */
	private function help(): void {
		echo '<div class="xnetvn-agent-hub" data-view="help"><p class="xnetvn-status" role="status" aria-live="polite" data-help-status></p>';
		echo '<h2>' . esc_html__( 'Connect a client', 'xnetvn-agent-hub-wp' ) . '</h2><ol class="xnetvn-steps" data-help-steps>';
		echo '<li>' . esc_html__( 'On your', 'xnetvn-agent-hub-wp' ) . ' <a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'profile', 'xnetvn-agent-hub-wp' ) . '</a>' . esc_html__( ', create a key with the lowest tier and toolsets you need. Scopes only narrow what your WordPress permissions already allow.', 'xnetvn-agent-hub-wp' ) . '</li>';
		echo '<li>' . esc_html__( 'Copy the new key once and keep it in your client secret store. Never paste keys into this help page, logs or support reports.', 'xnetvn-agent-hub-wp' ) . '</li>';
		echo '<li>' . esc_html__( 'Configure your client with the MCP endpoint below and send the key in the Authorization header as a Bearer token, for example from the MCP_API_KEY environment variable. Manage scopes and revocation on the', 'xnetvn-agent-hub-wp' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=xnetvn-agent-hub-wp-keys' ) ) . '">' . esc_html__( 'Keys', 'xnetvn-agent-hub-wp' ) . '</a>' . esc_html__( ' page.', 'xnetvn-agent-hub-wp' ) . '</li>';
		echo '<li>' . esc_html__( 'Check the connection from your client; when it is not ready, read the Connection on', 'xnetvn-agent-hub-wp' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=xnetvn-agent-hub-wp' ) ) . '">' . esc_html__( 'Overview', 'xnetvn-agent-hub-wp' ) . '</a>' . esc_html__( ' and match the failure below in Troubleshooting.', 'xnetvn-agent-hub-wp' ) . '</li></ol>';
		echo '<p><label for="xnetvn-help-endpoint">' . esc_html__( 'MCP endpoint', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-help-endpoint" class="large-text" readonly value="' . esc_attr( rest_url( 'xnetvn-agent-hub-wp/mcp' ) ) . '"> <button type="button" class="button" data-copy-endpoint>' . esc_html__( 'Copy endpoint', 'xnetvn-agent-hub-wp' ) . '</button></p>';
		echo '<p>' . esc_html__( 'If copying is unavailable, the endpoint above is selected for you: use your keyboard copy command on the selected value.', 'xnetvn-agent-hub-wp' ) . '</p>';
		echo '<h2>' . esc_html__( 'Configuration sample', 'xnetvn-agent-hub-wp' ) . '</h2><pre class="xnetvn-help-sample" data-help-sample><code>' . esc_html__( 'MCP_ENDPOINT=https://example.invalid/wp-json/xnetvn-agent-hub-wp/mcp', 'xnetvn-agent-hub-wp' ) . "\n" . esc_html__( 'Authorization: Bearer $MCP_API_KEY', 'xnetvn-agent-hub-wp' ) . "\n" . esc_html__( 'Revoked or unknown values read as REDACTED and are never pasted here.', 'xnetvn-agent-hub-wp' ) . '</code></pre>';
		echo '<h2>' . esc_html__( 'Troubleshooting', 'xnetvn-agent-hub-wp' ) . '</h2>';
		echo '<details class="xnetvn-help-issue" data-help-issue="401"><summary>' . esc_html__( '401 Unauthorized: the request has no valid identity', 'xnetvn-agent-hub-wp' ) . '</summary><p>' . esc_html__( 'In this admin area, reload the page and log in again: management uses your cookie session with its nonce.', 'xnetvn-agent-hub-wp' ) . '</p><p>' . esc_html__( 'For MCP calls, the key is missing, expired or revoked. Send it as Authorization: Bearer $MCP_API_KEY and check revocation on the Keys page. A management login never substitutes for a valid MCP key.', 'xnetvn-agent-hub-wp' ) . '</p></details>';
		echo '<details class="xnetvn-help-issue" data-help-issue="403"><summary>' . esc_html__( '403 Forbidden: a capability or nonce check refused the request', 'xnetvn-agent-hub-wp' ) . '</summary><p>' . esc_html__( 'In this admin area, the nonce expired or your role lost the capability. Reload the page, log in again and retry the read once.', 'xnetvn-agent-hub-wp' ) . '</p><p>' . esc_html__( 'For MCP calls, the key scope or the owner WordPress permissions deny the ability. Every request rechecks the current capabilities, so narrowing a scope never grants access.', 'xnetvn-agent-hub-wp' ) . '</p></details>';
		echo '<details class="xnetvn-help-issue" data-help-issue="429"><summary>' . esc_html__( '429 Too many requests: slow down and retry reads only', 'xnetvn-agent-hub-wp' ) . '</summary><p>' . esc_html__( 'Wait a minute before retrying the read. Rate limits bound how many requests one key or address may send per minute.', 'xnetvn-agent-hub-wp' ) . '</p><p>' . esc_html__( 'Never retry a mutation to mask the error; a repeated write can run twice.', 'xnetvn-agent-hub-wp' ) . '</p></details>';
		echo '<details class="xnetvn-help-issue" data-help-issue="503"><summary>' . esc_html__( '503 Service unavailable: try the read again later', 'xnetvn-agent-hub-wp' ) . '</summary><p>' . esc_html__( 'The service or an upstream dependency is unavailable. Check the Connection on Overview, then retry the read later.', 'xnetvn-agent-hub-wp' ) . '</p><p>' . esc_html__( 'MCP keys stay valid through an outage; a new key is not required.', 'xnetvn-agent-hub-wp' ) . '</p></details>';
		echo '<h2>' . esc_html__( 'Emergency revocation', 'xnetvn-agent-hub-wp' ) . '</h2><p>' . esc_html__( 'Revoke an affected key on the', 'xnetvn-agent-hub-wp' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=xnetvn-agent-hub-wp-keys' ) ) . '">' . esc_html__( 'Keys', 'xnetvn-agent-hub-wp' ) . '</a>' . esc_html__( ' page. New requests are checked against current owner permissions and revocation state. Do not paste keys into logs or support reports.', 'xnetvn-agent-hub-wp' ) . '</p>';
		echo '<h2>' . esc_html__( 'Private security reports', 'xnetvn-agent-hub-wp' ) . '</h2><p><a href="mailto:security@xnetvn.net">security@xnetvn.net</a></p>';
		echo '<p>' . esc_html__( 'Site content is untrusted data, not instructions.', 'xnetvn-agent-hub-wp' ) . '</p></div>';
	}

	/**
	 * Render a profile-safe key group, without nesting a form in WordPress's profile form.
	 *
	 * @param int  $user_id Viewed owner.
	 * @param bool $all Manager's all-owner listing.
	 */
	private function keys( int $user_id, bool $all ): void {
		echo '<section class="xnetvn-agent-hub" data-view="keys" aria-busy="false" data-user-id="' . esc_attr( (string) $user_id ) . '" data-all="' . esc_attr( $all ? '1' : '0' ) . '"><p class="xnetvn-status" role="status" aria-live="polite"></p>';
		$this->read_state( __( 'No keys found.', 'xnetvn-agent-hub-wp' ) );
		if ( $all ) {
			$this->key_filters();
		}
		echo '<div class="xnetvn-table-wrap" tabindex="0" role="region" aria-label="' . esc_attr__( 'Agent Hub API keys', 'xnetvn-agent-hub-wp' ) . '"><table class="widefat striped"><caption>' . esc_html__( 'Agent Hub API keys', 'xnetvn-agent-hub-wp' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Name', 'xnetvn-agent-hub-wp' ), __( 'Key ID', 'xnetvn-agent-hub-wp' ), __( 'Owner', 'xnetvn-agent-hub-wp' ), __( 'Risk tier', 'xnetvn-agent-hub-wp' ), __( 'Toolsets', 'xnetvn-agent-hub-wp' ), __( 'Created', 'xnetvn-agent-hub-wp' ), __( 'Last used', 'xnetvn-agent-hub-wp' ), __( 'Expires', 'xnetvn-agent-hub-wp' ), __( 'Status', 'xnetvn-agent-hub-wp' ), __( 'Actions', 'xnetvn-agent-hub-wp' ) ) as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody data-keys></tbody></table></div>';
		$this->pagination();
		if ( get_current_user_id() === $user_id && current_user_can( Capabilities::USE ) ) {
			$this->create_fields( $user_id );
		}
		$this->dialogs();
		echo '<noscript><p>' . esc_html__( 'Enable JavaScript to create, rotate or revoke keys.', 'xnetvn-agent-hub-wp' ) . '</p></noscript></section>';
	}

	/**
	 * Render manager-only listing filters that reuse the published listing parameters.
	 *
	 * Filters never widen access: the same owner check and capability run per request.
	 */
	private function key_filters(): void {
		echo '<div class="xnetvn-filters" role="group" aria-label="' . esc_attr__( 'Agent Hub key filters', 'xnetvn-agent-hub-wp' ) . '"><p><label for="xnetvn-key-state">' . esc_html__( 'Key state', 'xnetvn-agent-hub-wp' ) . '</label><select id="xnetvn-key-state" data-filter="state">';
		$states = array(
			'all'     => __( 'All states', 'xnetvn-agent-hub-wp' ),
			'active'  => __( 'Active', 'xnetvn-agent-hub-wp' ),
			'expired' => __( 'Expired', 'xnetvn-agent-hub-wp' ),
			'revoked' => __( 'Revoked', 'xnetvn-agent-hub-wp' ),
		);
		foreach ( $states as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( 'all', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p><label for="xnetvn-key-owner">' . esc_html__( 'Owner ID (optional)', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-key-owner" data-filter="owner" type="number" min="1" step="1" inputmode="numeric" autocomplete="off" placeholder="' . esc_attr__( 'All owners', 'xnetvn-agent-hub-wp' ) . '"></p><p><button type="button" class="button" data-apply>' . esc_html__( 'Apply filters', 'xnetvn-agent-hub-wp' ) . '</button> <button type="button" class="button" data-clear>' . esc_html__( 'Clear filters', 'xnetvn-agent-hub-wp' ) . '</button></p><p>' . esc_html__( 'Filters only narrow this listing. Choosing an owner shows that owner&rsquo;s keys; clearing shows every owner.', 'xnetvn-agent-hub-wp' ) . '</p></div>';
	}

	/**
	 * Render read-only audit filters that reuse the published audit parameters.
	 *
	 * This screen already requires management, so the owner control is only
	 * rendered here for managers. Filters never widen access: the same owner
	 * check and capability run per request.
	 */
	private function activity_filters(): void {
		echo '<div class="xnetvn-filters" role="group" aria-label="' . esc_attr__( 'Agent Hub activity filters', 'xnetvn-agent-hub-wp' ) . '">';
		echo '<p><label for="xnetvn-activity-owner">' . esc_html__( 'Owner ID (optional)', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-activity-owner" data-filter="owner" type="number" min="1" step="1" inputmode="numeric" autocomplete="off" placeholder="' . esc_attr__( 'All owners', 'xnetvn-agent-hub-wp' ) . '"></p>';
		echo '<p><label for="xnetvn-activity-key">' . esc_html__( 'Key ID', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-activity-key" data-filter="key_id" type="text" pattern="[a-z2-7]{12}" maxlength="12" autocomplete="off" spellcheck="false" placeholder="' . esc_attr__( 'All keys', 'xnetvn-agent-hub-wp' ) . '"></p>';
		echo '<p><label for="xnetvn-activity-ability">' . esc_html__( 'Ability', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-activity-ability" data-filter="ability" type="text" maxlength="128" autocomplete="off" spellcheck="false" placeholder="' . esc_attr__( 'All abilities', 'xnetvn-agent-hub-wp' ) . '"></p>';
		echo '<p><label for="xnetvn-activity-outcome">' . esc_html__( 'Outcome', 'xnetvn-agent-hub-wp' ) . '</label><select id="xnetvn-activity-outcome" data-filter="outcome">';
		$outcomes = array(
			'all'           => __( 'All outcomes', 'xnetvn-agent-hub-wp' ),
			'success'       => __( 'success', 'xnetvn-agent-hub-wp' ),
			'denied'        => __( 'denied', 'xnetvn-agent-hub-wp' ),
			'error'         => __( 'error', 'xnetvn-agent-hub-wp' ),
			'invalid_input' => __( 'invalid_input', 'xnetvn-agent-hub-wp' ),
			'rate_limited'  => __( 'rate_limited', 'xnetvn-agent-hub-wp' ),
			'auth_failed'   => __( 'auth_failed', 'xnetvn-agent-hub-wp' ),
		);
		foreach ( $outcomes as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( 'all', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="xnetvn-activity-after">' . esc_html__( 'From', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-activity-after" data-filter="after" type="datetime-local" autocomplete="off"></p>';
		echo '<p><label for="xnetvn-activity-before">' . esc_html__( 'To', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-activity-before" data-filter="before" type="datetime-local" autocomplete="off"></p>';
		echo '<p class="xnetvn-help" data-timezone></p>';
		echo '<p><button type="button" class="button" data-apply>' . esc_html__( 'Apply filters', 'xnetvn-agent-hub-wp' ) . '</button> <button type="button" class="button" data-clear>' . esc_html__( 'Clear filters', 'xnetvn-agent-hub-wp' ) . '</button></p>';
		echo '<p>' . esc_html__( 'Filters only narrow this listing. Choosing an owner shows that owner&rsquo;s activity; clearing shows every owner. Times are converted to UTC before the request.', 'xnetvn-agent-hub-wp' ) . '</p></div>';
	}

	/**
	 * Render catalog-only lookup controls that reuse the published listing filters.
	 *
	 * Filters never widen access: a single management GET feeds the in-memory
	 * catalog and every keystroke filters that copy without another request.
	 */
	private function tool_filters(): void {
		echo '<div class="xnetvn-filters" role="group" aria-label="' . esc_attr__( 'Agent Hub tool filters', 'xnetvn-agent-hub-wp' ) . '">';
		echo '<p><label for="xnetvn-tool-search">' . esc_html__( 'Search tools', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-tool-search" data-filter="search" type="search" autocomplete="off" placeholder="' . esc_attr__( 'Name, label or description', 'xnetvn-agent-hub-wp' ) . '"></p>';
		echo '<p><label for="xnetvn-tool-toolset">' . esc_html__( 'Toolset', 'xnetvn-agent-hub-wp' ) . '</label><select id="xnetvn-tool-toolset" data-filter="toolset"><option value="all" selected>' . esc_html__( 'All toolsets', 'xnetvn-agent-hub-wp' ) . '</option></select></p>';
		echo '<p><label for="xnetvn-tool-tier">' . esc_html__( 'Risk tier', 'xnetvn-agent-hub-wp' ) . '</label><select id="xnetvn-tool-tier" data-filter="tier">';
		$tiers = array(
			'all'         => __( 'All tiers', 'xnetvn-agent-hub-wp' ),
			'read'        => __( 'Read', 'xnetvn-agent-hub-wp' ),
			'write'       => __( 'Write', 'xnetvn-agent-hub-wp' ),
			'destructive' => __( 'Destructive', 'xnetvn-agent-hub-wp' ),
			'admin'       => __( 'Admin', 'xnetvn-agent-hub-wp' ),
		);
		foreach ( $tiers as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( 'all', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><button type="button" class="button" data-clear>' . esc_html__( 'Clear filters', 'xnetvn-agent-hub-wp' ) . '</button></p>';
		echo '<p data-tools-count role="status"></p>';
		echo '<p class="xnetvn-empty" data-tools-no-results hidden>' . esc_html__( 'No matching tools. Clear the filters to see the full catalog.', 'xnetvn-agent-hub-wp' ) . '</p></div>';
	}

	/**
	 * Render labeled creation fields and plain risk descriptions.
	 *
	 * @param int $user_id Owner.
	 */
	private function create_fields( int $user_id ): void {
		echo '<div data-create-group role="group" aria-labelledby="xnetvn-create-title"><h3 id="xnetvn-create-title">' . esc_html__( 'Create a key', 'xnetvn-agent-hub-wp' ) . '</h3><fieldset data-group-basic><legend>' . esc_html__( 'Basic settings', 'xnetvn-agent-hub-wp' ) . '</legend><p><label for="xnetvn-key-name">' . esc_html__( 'Key name', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-key-name" data-field="name" type="text" maxlength="100" required></p><p><label for="xnetvn-key-days">' . esc_html__( 'Expires in days', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-key-days" data-field="expires_in_days" type="number" min="1" max="' . esc_attr( (string) $this->settings->get( 'max_key_lifetime_days' ) ) . '" value="' . esc_attr( (string) min( 90, (int) $this->settings->get( 'max_key_lifetime_days' ) ) ) . '" required></p><p><label for="xnetvn-key-tier">' . esc_html__( 'Risk tier', 'xnetvn-agent-hub-wp' ) . '</label><select id="xnetvn-key-tier" data-field="tier_max" aria-describedby="xnetvn-tier-help">';
		$labels = array(
			'read'        => __( 'Read: view permitted data', 'xnetvn-agent-hub-wp' ),
			'write'       => __( 'Write: create or change content', 'xnetvn-agent-hub-wp' ),
			'destructive' => __( 'Destructive: permanently delete permitted data', 'xnetvn-agent-hub-wp' ),
			'admin'       => __( 'Admin: install software or manage site operations', 'xnetvn-agent-hub-wp' ),
		);
		foreach ( RiskTier::cases() as $tier ) {
			$cap = match ( $tier ) {
				RiskTier::Read => Capabilities::USE, RiskTier::Write => 'edit_posts', RiskTier::Destructive => 'delete_posts', RiskTier::Admin => Capabilities::MANAGE };
			if ( $tier->atMost( RiskTier::from( (string) $this->settings->get( 'tier_ceiling' ) ) ) && user_can( $user_id, $cap ) && ( RiskTier::Admin !== $tier || true === $this->settings->get( 'allow_admin_tier' ) ) ) {
				echo '<option value="' . esc_attr( $tier->value ) . '">' . esc_html( $labels[ $tier->value ] ) . '</option>';
			}
		}
		echo '</select></p><p id="xnetvn-tier-help">' . esc_html__( 'Choose the lowest tier needed. Your current WordPress permissions still apply. Destructive and Admin keys require your current password.', 'xnetvn-agent-hub-wp' ) . '</p><p data-password-group hidden><label for="xnetvn-key-password">' . esc_html__( 'Current password', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-key-password" data-field="current_password" type="password" autocomplete="current-password"></p><fieldset><legend>' . esc_html__( 'Enabled toolsets', 'xnetvn-agent-hub-wp' ) . '</legend>';
		$sets = array( 'system' => true );
		foreach ( $this->catalog->all() as $definition ) {
			if ( user_can( $user_id, $definition->descriptor()->visibility_cap ) ) {
				$sets[ $definition->descriptor()->toolset ] = true;
			}
		}
		foreach ( array_keys( $sets ) as $set ) {
			echo '<label class="xnetvn-choice"><input type="checkbox" data-toolset value="' . esc_attr( $set ) . '" ' . checked( 'system', $set, false ) . ' ' . disabled( 'system', $set, false ) . '> ' . esc_html( $set ) . '</label>';
		}
		echo '<p>' . esc_html__( 'System is always enabled. Other toolsets limit which groups this key can use.', 'xnetvn-agent-hub-wp' ) . '</p></fieldset></fieldset><details data-group-advanced><summary>' . esc_html__( 'Advanced settings', 'xnetvn-agent-hub-wp' ) . '</summary><p><label for="xnetvn-key-ip">' . esc_html__( 'Allowed IP networks (optional)', 'xnetvn-agent-hub-wp' ) . '</label><textarea id="xnetvn-key-ip" data-field="ip_allowlist" rows="3" aria-describedby="xnetvn-ip-help"></textarea></p><p id="xnetvn-ip-help">' . esc_html__( 'One CIDR range per line, for example 203.0.113.0/24. Leave empty for no key-specific IP restriction.', 'xnetvn-agent-hub-wp' ) . '</p><p><label for="xnetvn-key-deny">' . esc_html__( 'Blocked abilities', 'xnetvn-agent-hub-wp' ) . '</label><select id="xnetvn-key-deny" data-field="ability_deny" multiple size="5">';
		foreach ( $this->catalog->all() as $definition ) {
			echo '<option value="' . esc_attr( $definition->descriptor()->name ) . '">' . esc_html( $definition->descriptor()->name ) . '</option>';
		}
		echo '</select></p></details><button type="button" class="button button-primary" data-create disabled>' . esc_html__( 'Create key', 'xnetvn-agent-hub-wp' ) . '</button></div>';
	}

	/** Render native focus-contained dialogs with explicit clearing and close controls. */
	private function dialogs(): void {
		echo '<dialog data-secret-dialog aria-labelledby="xnetvn-secret-title" aria-describedby="xnetvn-secret-help"><h3 id="xnetvn-secret-title">' . esc_html__( 'Save your new key now', 'xnetvn-agent-hub-wp' ) . '</h3><p id="xnetvn-secret-help">' . esc_html__( 'This key is shown once. Closing this window clears it. Copy it now and store it securely.', 'xnetvn-agent-hub-wp' ) . '</p><label for="xnetvn-secret">' . esc_html__( 'API key', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-secret" data-secret readonly autocomplete="off" spellcheck="false"><p><button type="button" class="button" data-copy>' . esc_html__( 'Copy key', 'xnetvn-agent-hub-wp' ) . '</button> <button type="button" class="button button-primary" data-saved>' . esc_html__( 'I have saved the key', 'xnetvn-agent-hub-wp' ) . '</button></p></dialog><dialog data-action-dialog aria-labelledby="xnetvn-action-title"><h3 id="xnetvn-action-title">' . esc_html__( 'Confirm key operation', 'xnetvn-agent-hub-wp' ) . '</h3><p data-action-message></p><p data-action-password-group hidden><label for="xnetvn-action-password">' . esc_html__( 'Current password', 'xnetvn-agent-hub-wp' ) . '</label><input id="xnetvn-action-password" type="password" autocomplete="current-password"></p><p><button type="button" class="button button-primary" data-confirm>' . esc_html__( 'Confirm', 'xnetvn-agent-hub-wp' ) . '</button> <button type="button" class="button" data-cancel>' . esc_html__( 'Cancel', 'xnetvn-agent-hub-wp' ) . '</button></p></dialog>';
	}

	/** Render shared pagination controls with meaningful button labels. */
	private function pagination(): void {
		echo '<nav class="xnetvn-pagination" aria-label="' . esc_attr__( 'Result pages', 'xnetvn-agent-hub-wp' ) . '"><button type="button" class="button" data-prev disabled>' . esc_html__( 'Previous page', 'xnetvn-agent-hub-wp' ) . '</button> <span data-page></span> <button type="button" class="button" data-next disabled>' . esc_html__( 'Next page', 'xnetvn-agent-hub-wp' ) . '</button></nav>';
	}

	/** Render Settings API fields, preserving the standard WordPress nonce and capability checks. */
	private function settings_form(): void {
		$errors = $this->settings_errors();
		if ( '' !== $errors ) {
			echo '<div class="xnetvn-settings-errors" role="alert" tabindex="0">' . $errors . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-rendered, already escaped settings errors for this option.
		}
		try {
			$data = $this->settings->all();
		} catch ( \Throwable ) {
			echo '<div class="xnetvn-settings-errors" role="alert" tabindex="0"><p>' . esc_html__( 'Stored settings are unavailable, so this screen is read-only. Repair requires an explicit replacement of the invalid fields through the management API. No default value is written and no policy is changed for you.', 'xnetvn-agent-hub-wp' ) . '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="xnetvn-agent-hub">';
		settings_fields( self::SETTINGS_GROUP );
		foreach ( $this->setting_groups() as $group => $spec ) {
			echo '<fieldset class="xnetvn-settings-group" data-group="' . esc_attr( $group ) . '"><legend>' . esc_html( $spec['title'] ) . '</legend><p class="xnetvn-group-help">' . esc_html( $spec['help'] ) . '</p>';
			foreach ( $spec['fields'] as $name ) {
				$this->setting_field( $name, $data[ $name ] );
			}
			echo '</fieldset>';
		}
		echo '<p>' . esc_html__( 'List fields accept one value per line. Trusted proxy headers only work with an explicitly trusted proxy CIDR. Enabling Admin increases permitted risk but never bypasses WordPress permissions.', 'xnetvn-agent-hub-wp' ) . '</p>';
		submit_button( __( 'Save settings', 'xnetvn-agent-hub-wp' ) );
		echo '</form>';
	}

	/**
	 * Collect the settings errors WordPress recorded for this option.
	 *
	 * The markup is built here instead of reusing settings_errors(): WordPress
	 * hoists every div.notice to the top of the screen, which would leave this
	 * container empty and drop the role and focusable attributes the keyboard
	 * needs. get_settings_errors() keeps the core read-once semantics.
	 *
	 * @return string Rendered error text, or an empty string when nothing failed.
	 */
	private function settings_errors(): string {
		$errors = get_settings_errors( WpSiteSettings::OPTION );
		if ( ! is_array( $errors ) || array() === $errors ) {
			return '';
		}
		$rendered = '';
		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) || ! isset( $error['message'] ) || ! is_scalar( $error['message'] ) ) {
				continue;
			}
			$rendered .= '<p>' . esc_html( (string) $error['message'] ) . '</p>';
		}
		return $rendered;
	}

	/**
	 * Render one labeled setting with its help text and inline risk note.
	 *
	 * Field names, types and numeric bounds stay exactly as the sanitizer and
	 * WpSiteSettings define them; grouping only adds structure and guidance.
	 *
	 * @param string $name  Stored setting name.
	 * @param mixed  $value Current validated value.
	 */
	private function setting_field( string $name, mixed $value ): void {
		$id         = 'xnetvn-setting-' . $name;
		$field      = WpSiteSettings::OPTION . '[' . $name . ']';
		$help       = $this->setting_help()[ $name ];
		$warning    = $this->setting_warnings()[ $name ] ?? '';
		$references = array( 'xnetvn-help-' . $name );
		if ( '' !== $warning ) {
			$references[] = 'xnetvn-warning-' . $name;
		}
		$described = esc_attr( implode( ' ', $references ) );
		echo '<div class="xnetvn-field" data-field="' . esc_attr( $name ) . '">';
		if ( 'allowed_roles_override' === $name ) {
			$mode_references = 'xnetvn-help-' . $name . '-mode';
			if ( '' !== $warning ) {
				$mode_references .= ' xnetvn-warning-' . $name;
			}
			echo '<div class="xnetvn-field" data-field="' . esc_attr( $name ) . '_mode"><label class="xnetvn-choice"><input type="hidden" name="' . esc_attr( WpSiteSettings::OPTION . '[allowed_roles_override_mode]' ) . '" value="0"><input type="checkbox" id="' . esc_attr( $id ) . '_mode" name="' . esc_attr( WpSiteSettings::OPTION . '[allowed_roles_override_mode]' ) . '" value="1" ' . checked( null !== $value, true, false ) . ' aria-describedby="' . esc_attr( $mode_references ) . '"> ' . esc_html__( 'Use this custom role list (empty denies all roles)', 'xnetvn-agent-hub-wp' ) . '</label></div>';
		}
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $this->setting_labels()[ $name ] ) . '</label>';
		if ( is_bool( $value ) ) {
			echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="0"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '" value="1" ' . checked( true, $value, false ) . ' aria-describedby="' . $described . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() above.
		} elseif ( is_int( $value ) ) {
			$range = WpSiteSettings::numeric_range( $name );
			echo '<input type="number" min="' . esc_attr( (string) $range[0] ) . '" max="' . esc_attr( (string) $range[1] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $value ) . '" required aria-describedby="' . $described . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() above.
		} elseif ( 'tier_ceiling' === $name ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '" aria-describedby="' . $described . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() above.
			foreach ( array( 'read', 'write', 'destructive', 'admin' ) as $tier ) {
				echo '<option value="' . esc_attr( $tier ) . '" ' . selected( $value, $tier, false ) . '>' . esc_html( $tier ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '" rows="3" aria-describedby="' . $described . '">' . esc_textarea( is_array( $value ) ? implode( "\n", $value ) : '' ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() and esc_textarea() above.
		}
		if ( 'allowed_roles_override' === $name ) {
			echo '<p class="xnetvn-help" id="xnetvn-help-' . esc_attr( $name ) . '-mode">' . esc_html__( 'Off keeps the default WordPress roles. On with an empty list denies every role. On with a list allows only those roles.', 'xnetvn-agent-hub-wp' ) . '</p>';
		}
		echo '<p class="xnetvn-help" id="xnetvn-help-' . esc_attr( $name ) . '">' . esc_html( $help ) . '</p>';
		if ( '' !== $warning ) {
			echo '<p class="xnetvn-warning" id="xnetvn-warning-' . esc_attr( $name ) . '">' . esc_html( $warning ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Canonical translated menu titles.
	 *
	 * @return array<string,string>
	 */
	private function titles(): array {
		return array(
			'overview' => __( 'Overview', 'xnetvn-agent-hub-wp' ),
			'keys'     => __( 'Keys', 'xnetvn-agent-hub-wp' ),
			'activity' => __( 'Activity', 'xnetvn-agent-hub-wp' ),
			'tools'    => __( 'Tools', 'xnetvn-agent-hub-wp' ),
			'settings' => __( 'Settings', 'xnetvn-agent-hub-wp' ),
			'help'     => __( 'Help', 'xnetvn-agent-hub-wp' ),
		);
	}

	/**
	 * Ordered settings groups for the management screen.
	 *
	 * Every stored setting appears exactly once, in the same wording the
	 * sanitizer and WpSiteSettings define. The map only adds structure and
	 * guidance: it never adds, renames or drops a field.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function setting_groups(): array {
		return array(
			'access'        => array(
				'title'  => __( 'Access', 'xnetvn-agent-hub-wp' ),
				'help'   => __( 'Decide who may call the MCP endpoint and the highest risk a key can request.', 'xnetvn-agent-hub-wp' ),
				'fields' => array( 'enabled', 'allowed_roles_override', 'tier_ceiling', 'allow_admin_tier', 'exposed_third_party_abilities', 'disable_adapter_default_server' ),
			),
			'network'       => array(
				'title'  => __( 'Network', 'xnetvn-agent-hub-wp' ),
				'help'   => __( 'Restrict where requests may come from and which proxy headers are believed.', 'xnetvn-agent-hub-wp' ),
				'fields' => array( 'allowed_origins', 'site_ip_allowlist', 'trusted_proxy_headers', 'trusted_proxy_cidrs', 'fallback_header_enabled' ),
			),
			'limits'        => array(
				'title'  => __( 'Limits', 'xnetvn-agent-hub-wp' ),
				'help'   => __( 'Bound key use and response size so one key cannot exhaust the site.', 'xnetvn-agent-hub-wp' ),
				'fields' => array( 'max_keys_per_user', 'max_key_lifetime_days', 'rate_limit_key_per_min', 'rate_limit_auth_fail_per_min_ip', 'output_max_bytes' ),
			),
			'audit'         => array(
				'title'  => __( 'Audit and data', 'xnetvn-agent-hub-wp' ),
				'help'   => __( 'Choose how long activity is kept and what happens to stored plugin data.', 'xnetvn-agent-hub-wp' ),
				'fields' => array( 'audit_retention_days', 'anonymize_ip', 'delete_data_on_uninstall' ),
			),
			'notifications' => array(
				'title'  => __( 'Notifications', 'xnetvn-agent-hub-wp' ),
				'help'   => __( 'Choose the key events that email owners.', 'xnetvn-agent-hub-wp' ),
				'fields' => array( 'notify_key_created', 'notify_key_revoked', 'notify_key_expiring', 'notify_unusual_ip' ),
			),
			'media'         => array(
				'title'  => __( 'Media', 'xnetvn-agent-hub-wp' ),
				'help'   => __( 'Bound remote downloads, uploads and image size for media tools.', 'xnetvn-agent-hub-wp' ),
				'fields' => array( 'media_remote_fetch_enabled', 'media_remote_max_bytes', 'media_remote_timeout_seconds', 'media_remote_max_redirects', 'media_data_max_bytes', 'media_pixels_max', 'media_mime_denylist' ),
			),
		);
	}

	/**
	 * Plain-language help for every stored setting, keyed by field name.
	 *
	 * @return array<string,string>
	 */
	private function setting_help(): array {
		return array(
			'enabled'                         => __( 'Turn the MCP endpoint off to stop every key without deleting keys or settings.', 'xnetvn-agent-hub-wp' ),
			'allowed_roles_override'          => __( 'One WordPress role per line. Used only when the custom role list control above is switched on.', 'xnetvn-agent-hub-wp' ),
			'tier_ceiling'                    => __( 'The highest risk tier any key on this site may request. Existing higher-tier keys stop working.', 'xnetvn-agent-hub-wp' ),
			'allow_admin_tier'                => __( 'Allow the Admin tier to be selected when a key is created. WordPress permissions still apply.', 'xnetvn-agent-hub-wp' ),
			'exposed_third_party_abilities'   => __( 'One pack and ability name per line, for example content/read-post. Only names a registered pack declares are accepted.', 'xnetvn-agent-hub-wp' ),
			'disable_adapter_default_server'  => __( 'Stop the MCP adapter from registering its own default server. This plugin endpoint keeps working.', 'xnetvn-agent-hub-wp' ),
			'allowed_origins'                 => __( 'One browser origin per line, for example https://app.example.com. Empty allows only this site.', 'xnetvn-agent-hub-wp' ),
			'site_ip_allowlist'               => __( 'One CIDR range per line, for example 203.0.113.0/24. Empty allows every IP address.', 'xnetvn-agent-hub-wp' ),
			'trusted_proxy_headers'           => __( 'Only X-Forwarded-Proto is accepted, and only from a trusted proxy network.', 'xnetvn-agent-hub-wp' ),
			'trusted_proxy_cidrs'             => __( 'One CIDR range per line. Proxy headers are read only for these networks.', 'xnetvn-agent-hub-wp' ),
			'fallback_header_enabled'         => __( 'Accept the X-XNETVN-API-KEY header when the Authorization header is missing.', 'xnetvn-agent-hub-wp' ),
			'max_keys_per_user'               => __( 'Active keys one user may hold. Creating one more is refused.', 'xnetvn-agent-hub-wp' ),
			'max_key_lifetime_days'           => __( 'The longest expiry a key can request.', 'xnetvn-agent-hub-wp' ),
			'rate_limit_key_per_min'          => __( 'Requests one key may send per minute.', 'xnetvn-agent-hub-wp' ),
			'rate_limit_auth_fail_per_min_ip' => __( 'Failed authentication attempts one IP address may make per minute before it is throttled.', 'xnetvn-agent-hub-wp' ),
			'output_max_bytes'                => __( 'The largest tool response returned before it is truncated.', 'xnetvn-agent-hub-wp' ),
			'audit_retention_days'            => __( 'Days audit records are kept before the scheduled cleanup removes them.', 'xnetvn-agent-hub-wp' ),
			'anonymize_ip'                    => __( 'Store masked IP addresses in audit records and key metadata.', 'xnetvn-agent-hub-wp' ),
			'delete_data_on_uninstall'        => __( 'Remove keys, audit records and settings when the plugin is deleted.', 'xnetvn-agent-hub-wp' ),
			'notify_key_created'              => __( 'Email the owner when a key is created.', 'xnetvn-agent-hub-wp' ),
			'notify_key_revoked'              => __( 'Email the owner when an administrator revokes a key.', 'xnetvn-agent-hub-wp' ),
			'notify_key_expiring'             => __( 'Email the owner before a key expires.', 'xnetvn-agent-hub-wp' ),
			'notify_unusual_ip'               => __( 'Email the owner when a key is used from an unusual IP address.', 'xnetvn-agent-hub-wp' ),
			'media_remote_fetch_enabled'      => __( 'Allow tools to download public HTTPS media URLs.', 'xnetvn-agent-hub-wp' ),
			'media_remote_max_bytes'          => __( 'The largest remote media file accepted.', 'xnetvn-agent-hub-wp' ),
			'media_remote_timeout_seconds'    => __( 'Seconds before a remote media request is abandoned.', 'xnetvn-agent-hub-wp' ),
			'media_remote_max_redirects'      => __( 'Redirects followed during one remote media request.', 'xnetvn-agent-hub-wp' ),
			'media_data_max_bytes'            => __( 'The largest uploaded media payload accepted.', 'xnetvn-agent-hub-wp' ),
			'media_pixels_max'                => __( 'The largest image accepted, measured in pixels.', 'xnetvn-agent-hub-wp' ),
			'media_mime_denylist'             => __( 'One MIME type per line, for example image/svg+xml, refused in addition to the built-in list.', 'xnetvn-agent-hub-wp' ),
		);
	}

	/**
	 * Inline risk notes for the choices that widen access or destroy data.
	 *
	 * Notes never change a default; they explain the consequence before saving.
	 *
	 * @return array<string,string>
	 */
	private function setting_warnings(): array {
		return array(
			'allow_admin_tier'              => __( 'Admin keys can run operations that change the whole site. Keep this off unless a key needs it.', 'xnetvn-agent-hub-wp' ),
			'trusted_proxy_headers'         => __( 'Trusting this header without a matching proxy network lets any client forge the client IP address.', 'xnetvn-agent-hub-wp' ),
			'trusted_proxy_cidrs'           => __( 'Add only networks you control. A wrong entry hides the real client IP address from rate limits and audit records.', 'xnetvn-agent-hub-wp' ),
			'fallback_header_enabled'       => __( 'The fallback header is a second way to send a key. Some hosts forward or log it differently from Authorization.', 'xnetvn-agent-hub-wp' ),
			'exposed_third_party_abilities' => __( 'Each name must belong to a registered pack. An unknown name is rejected on save and nothing is exposed.', 'xnetvn-agent-hub-wp' ),
			'delete_data_on_uninstall'      => __( 'Deleting the plugin removes every key and audit record. Export what you need first.', 'xnetvn-agent-hub-wp' ),
		);
	}

	/**
	 * User-facing settings labels avoid displaying raw internal option names.
	 *
	 * @return array<string,string>
	 */
	private function setting_labels(): array {
		return array(
			'enabled'                         => __( 'Enable the MCP server', 'xnetvn-agent-hub-wp' ),
			'allowed_roles_override'          => __( 'Custom allowed roles', 'xnetvn-agent-hub-wp' ),
			'tier_ceiling'                    => __( 'Maximum risk tier for this site', 'xnetvn-agent-hub-wp' ),
			'allow_admin_tier'                => __( 'Allow Admin tier', 'xnetvn-agent-hub-wp' ),
			'max_keys_per_user'               => __( 'Maximum active keys per user', 'xnetvn-agent-hub-wp' ),
			'max_key_lifetime_days'           => __( 'Maximum key lifetime in days', 'xnetvn-agent-hub-wp' ),
			'allowed_origins'                 => __( 'Additional allowed browser origins', 'xnetvn-agent-hub-wp' ),
			'site_ip_allowlist'               => __( 'Allowed IP networks for this site', 'xnetvn-agent-hub-wp' ),
			'trusted_proxy_headers'           => __( 'Trusted TLS proxy header names', 'xnetvn-agent-hub-wp' ),
			'trusted_proxy_cidrs'             => __( 'Trusted proxy IP networks', 'xnetvn-agent-hub-wp' ),
			'fallback_header_enabled'         => __( 'Accept the fallback API key header', 'xnetvn-agent-hub-wp' ),
			'rate_limit_key_per_min'          => __( 'Requests per key per minute', 'xnetvn-agent-hub-wp' ),
			'rate_limit_auth_fail_per_min_ip' => __( 'Failed authentication attempts per IP per minute', 'xnetvn-agent-hub-wp' ),
			'output_max_bytes'                => __( 'Maximum tool output in bytes', 'xnetvn-agent-hub-wp' ),
			'audit_retention_days'            => __( 'Audit retention in days', 'xnetvn-agent-hub-wp' ),
			'anonymize_ip'                    => __( 'Mask IP addresses in audit and key metadata', 'xnetvn-agent-hub-wp' ),
			'exposed_third_party_abilities'   => __( 'Approved third-party ability names', 'xnetvn-agent-hub-wp' ),
			'disable_adapter_default_server'  => __( 'Disable the adapter default server', 'xnetvn-agent-hub-wp' ),
			'notify_key_created'              => __( 'Notify owners when keys are created', 'xnetvn-agent-hub-wp' ),
			'notify_key_revoked'              => __( 'Notify owners when an administrator revokes a key', 'xnetvn-agent-hub-wp' ),
			'notify_key_expiring'             => __( 'Notify owners before keys expire', 'xnetvn-agent-hub-wp' ),
			'notify_unusual_ip'               => __( 'Notify owners about unusual IP use', 'xnetvn-agent-hub-wp' ),
			'media_remote_fetch_enabled'      => __( 'Enable public HTTPS media downloads', 'xnetvn-agent-hub-wp' ),
			'media_remote_max_bytes'          => __( 'Remote media maximum bytes', 'xnetvn-agent-hub-wp' ),
			'media_remote_timeout_seconds'    => __( 'Remote media timeout seconds', 'xnetvn-agent-hub-wp' ),
			'media_remote_max_redirects'      => __( 'Remote media maximum redirects', 'xnetvn-agent-hub-wp' ),
			'media_data_max_bytes'            => __( 'Data upload maximum bytes', 'xnetvn-agent-hub-wp' ),
			'media_pixels_max'                => __( 'Maximum image pixels', 'xnetvn-agent-hub-wp' ),
			'media_mime_denylist'             => __( 'Additional denied media MIME types', 'xnetvn-agent-hub-wp' ),
			'delete_data_on_uninstall'        => __( 'Delete plugin data when uninstalled', 'xnetvn-agent-hub-wp' ),
		);
	}
}
