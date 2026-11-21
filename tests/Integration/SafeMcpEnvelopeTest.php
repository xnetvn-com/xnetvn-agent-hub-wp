<?php
/**
 * Whole-catalog SafeMcpEnvelope matrix and every refusal path it protects.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Contracts\RequestContext;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\Decision;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RateLimitResult;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Mcp\ServerGateway;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** The literal oracle below is independent of the static envelope it verifies. */
final class SafeMcpEnvelopeTest extends WP_UnitTestCase {

	/** Reviewed editorial and system operations permitted to both principals. */
	private const REVIEWED_EDITORIAL_SYSTEM = array(
		'xnetvn/assign-post-terms',
		'xnetvn/bulk-assign-post-terms',
		'xnetvn/bulk-create-terms',
		'xnetvn/bulk-moderate-comments',
		'xnetvn/bulk-update-posts',
		'xnetvn/create-comment',
		'xnetvn/create-post',
		'xnetvn/create-term',
		'xnetvn/delete-comment',
		'xnetvn/delete-media',
		'xnetvn/delete-post',
		'xnetvn/delete-term',
		'xnetvn/discover-abilities',
		'xnetvn/duplicate-post',
		'xnetvn/execute-ability',
		'xnetvn/get-ability-info',
		'xnetvn/get-comment',
		'xnetvn/get-comment-counts',
		'xnetvn/get-media',
		'xnetvn/get-media-upload-limits',
		'xnetvn/get-post',
		'xnetvn/get-post-meta',
		'xnetvn/get-revision',
		'xnetvn/get-session-info',
		'xnetvn/get-site-info',
		'xnetvn/get-term',
		'xnetvn/list-comments',
		'xnetvn/list-content-authors',
		'xnetvn/list-media',
		'xnetvn/list-posts',
		'xnetvn/list-post-statuses',
		'xnetvn/list-post-types',
		'xnetvn/list-revisions',
		'xnetvn/list-taxonomies',
		'xnetvn/list-terms',
		'xnetvn/moderate-comment',
		'xnetvn/purge-comments',
		'xnetvn/reply-comment',
		'xnetvn/restore-post',
		'xnetvn/restore-revision',
		'xnetvn/search-content',
		'xnetvn/set-featured-media',
		'xnetvn/stick-post',
		'xnetvn/trash-post',
		'xnetvn/update-comment',
		'xnetvn/update-media',
		'xnetvn/update-post',
		'xnetvn/update-post-meta',
		'xnetvn/update-term',
		'xnetvn/upload-media-from-data',
		'xnetvn/upload-media-from-url',
	);

	/** Human self-profile operations permitted only to the human principal. */
	private const HUMAN_ONLY = array(
		'xnetvn/get-my-profile',
		'xnetvn/update-my-profile',
	);

	/** Site moderation operation permitted only to the site principal. */
	private const SITE_ONLY = array(
		'xnetvn/set-user-status',
	);

	/** Registered names outside both principals. */
	private const OUTSIDE = array(
		'xnetvn/create-user',
		'xnetvn/get-user',
		'xnetvn/get-user-capabilities',
		'xnetvn/list-roles',
		'xnetvn/list-users',
		'xnetvn/send-password-reset',
		'xnetvn/set-user-role',
		'xnetvn/terminate-user-sessions',
		'xnetvn/update-user',
	);

	/** A canonical name that no pack registers. */
	private const UNKNOWN_OPERATION = 'xnetvn/unknown-operation';

	/** A principal kind the static envelope does not implement. */
	private const UNKNOWN_PRINCIPAL = 'robot';

	/** Restore the anonymous request identity after an assertion fails mid-test. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Every registered catalog name is classified and decided for both principals. */
	public function test_every_catalog_ability_is_classified_and_decided_for_both_principals(): void {
		$catalog  = $this->catalog_names();
		$reviewed = self::sorted( self::REVIEWED_EDITORIAL_SYSTEM );
		$human    = self::sorted( self::HUMAN_ONLY );
		$site     = self::sorted( self::SITE_ONLY );
		$outside  = self::sorted( self::OUTSIDE );

		self::assertSame( self::sorted( SafeMcpEnvelope::REVIEWED_EDITORIAL_SYSTEM_OPERATIONS ), $reviewed, 'The reviewed literal set must equal the static envelope reviewed operations.' );
		self::assertSame( self::sorted( SafeMcpEnvelope::HUMAN_ONLY_PROFILE_OPERATIONS ), $human, 'The human-only literal set must equal the static envelope human-only operations.' );
		self::assertSame( self::sorted( SafeMcpEnvelope::SITE_MODERATION_OPERATIONS ), $site, 'The site-only literal set must equal the static envelope site-moderation operations.' );

		self::assertCount( 63, $catalog, 'The plugin must register exactly 63 private catalog abilities.' );
		self::assertCount( 51, $reviewed, 'The reviewed literal set must keep its measured size.' );
		self::assertCount( 2, $human, 'The human-only literal set must keep its measured size.' );
		self::assertCount( 1, $site, 'The site-only literal set must keep its measured size.' );
		self::assertCount( 9, $outside, 'The outside literal set must keep its measured size.' );

		$pairs = array(
			array( $reviewed, $human ),
			array( $reviewed, $site ),
			array( $reviewed, $outside ),
			array( $human, $site ),
			array( $human, $outside ),
			array( $site, $outside ),
		);
		foreach ( $pairs as $pair ) {
			self::assertSame( array(), array_values( array_intersect( $pair[0], $pair[1] ) ), 'The four literal sets must be pairwise disjoint.' );
		}

		self::assertSame( $catalog, self::sorted( array_merge( $reviewed, $human, $site, $outside ) ), 'The literal union must equal the runtime catalog.' );
		self::assertSame(
			$outside,
			array_values( array_diff( $catalog, self::sorted( array_merge( $reviewed, $human, $site ) ) ) ),
			'Every runtime name outside the three envelope sets must be one of the nine literal outside names.'
		);

		$envelope      = new SafeMcpEnvelope();
		$human_allowed = 0;
		$site_allowed  = 0;
		foreach ( $catalog as $name ) {
			$expected_user = in_array( $name, $reviewed, true ) || in_array( $name, $human, true );
			$expected_site = in_array( $name, $reviewed, true ) || in_array( $name, $site, true );
			$user          = $envelope->evaluate( $name, SafeMcpEnvelope::PRINCIPAL_USER );
			$machine       = $envelope->evaluate( $name, SafeMcpEnvelope::PRINCIPAL_SITE );
			self::assertSame( $expected_user, $user->allowed, 'The human decision must follow the literal oracle for ' . $name . '.' );
			self::assertSame( $expected_site, $machine->allowed, 'The site decision must follow the literal oracle for ' . $name . '.' );
			if ( $expected_user ) {
				++$human_allowed;
			} else {
				self::assertSame( 'operation_outside_safe_envelope', $user->reason, 'A denied human name must carry the envelope reason for ' . $name . '.' );
			}
			if ( $expected_site ) {
				++$site_allowed;
			} else {
				self::assertSame( 'operation_outside_safe_envelope', $machine->reason, 'A denied site name must carry the envelope reason for ' . $name . '.' );
			}
		}
		self::assertSame( 53, $human_allowed, 'The human principal must be permitted exactly 53 of the 63 catalog names.' );
		self::assertSame( 52, $site_allowed, 'The site principal must be permitted exactly 52 of the 63 catalog names.' );

		$unknown = $envelope->evaluate( self::UNKNOWN_OPERATION, SafeMcpEnvelope::PRINCIPAL_USER );
		self::assertFalse( $unknown->allowed, 'An unregistered canonical name must be denied.' );
		self::assertSame( 'operation_outside_safe_envelope', $unknown->reason, 'An unregistered name must be denied by the envelope.' );

		$invalid = $envelope->evaluate( 'xnetvn/get-site-info', self::UNKNOWN_PRINCIPAL );
		self::assertFalse( $invalid->allowed, 'An invalid principal kind must be denied even for a reviewed name.' );
		self::assertSame( 'operation_outside_safe_envelope', $invalid->reason, 'An invalid principal kind must be denied by the envelope, not by a masking unknown-name branch.' );
	}

	/** The envelope is deny-only: no seam widens it and no extension hook can grant access. */
	public function test_envelope_is_deny_only_and_cannot_be_widened(): void {
		$reflection = new \ReflectionClass( SafeMcpEnvelope::class );
		self::assertTrue( $reflection->isFinal(), 'The static envelope must stay final so it cannot be subclassed.' );
		self::assertNull( $reflection->getConstructor(), 'The static envelope must not accept injected state.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local envelope source for the static seam scan.
		$source = file_get_contents( (string) $reflection->getFileName() );
		self::assertIsString( $source, 'The envelope source must be readable for the static seam scan.' );
		$seams = array( 'apply_filters', 'do_action', '$GLOBALS', 'get_option', 'getenv', '$_GET', '$_POST', '$_REQUEST', '$_SERVER' );
		foreach ( $seams as $seam ) {
			self::assertStringNotContainsString( $seam, $source, 'The static envelope must not contain ' . $seam . '.' );
		}

		$settings      = new MemorySettings(
			array(
				'allow_admin_tier' => true,
				'tier_ceiling'     => 'admin',
			)
		);
		$policy        = new Policy( $settings );
		$site_policy   = new SitePolicy( $settings );
		$outside       = self::sorted( self::OUTSIDE );
		$outside_scope = new SiteScope( RiskTier::Admin, array( 'users' ), $outside, array(), array(), array(), new DateTimeImmutable( '2027-01-01T00:00:00Z' ) );
		$machine       = $this->site_context( $outside_scope );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$permit_policy = static fn(): mixed => Decision::permit();
		$permit_site   = static fn(): mixed => Decision::permit();
		add_filter( 'xnetvn_agent_hub_wp_policy_decision', $permit_policy, 10, 4 );
		add_filter( 'xnetvn_agent_hub_wp_site_policy_decision', $permit_site, 10, 4 );
		try {
			foreach ( $outside as $name ) {
				$descriptor = $this->descriptor( $name );
				$human      = $policy->evaluate( $descriptor, null, 'stdio' );
				self::assertFalse( $human->allowed, 'An outside name must stay denied while a permit extension is attached for ' . $name . '.' );
				self::assertSame( 'operation_outside_safe_envelope', $human->reason, 'The human denial must be the envelope reason for ' . $name . '.' );
				$site = $site_policy->evaluate( $descriptor, $machine, 'http' );
				self::assertFalse( $site->allowed, 'An outside name must stay denied by the machine policy for ' . $name . '.' );
				self::assertSame( 'operation_outside_safe_envelope', $site->reason, 'The machine denial must be the envelope reason for ' . $name . '.' );
			}
		} finally {
			remove_filter( 'xnetvn_agent_hub_wp_policy_decision', $permit_policy, 10 );
			remove_filter( 'xnetvn_agent_hub_wp_site_policy_decision', $permit_site, 10 );
		}

		$reviewed = $this->descriptor( 'xnetvn/get-site-info' );
		self::assertTrue( $policy->evaluate( $reviewed, null, 'stdio' )->allowed, 'The base human policy must permit the reviewed control before any deny hook.' );
		$deny_policy = static fn(): mixed => Decision::deny( 'extension_denied' );
		add_filter( 'xnetvn_agent_hub_wp_policy_decision', $deny_policy, 10, 4 );
		try {
			$denied = $policy->evaluate( $reviewed, null, 'stdio' );
			self::assertFalse( $denied->allowed, 'A human deny hook must be able to refuse a reviewed name.' );
			self::assertSame( 'extension_denied', $denied->reason, 'The human denial must be attributable to the extension hook.' );
		} finally {
			remove_filter( 'xnetvn_agent_hub_wp_policy_decision', $deny_policy, 10 );
		}

		$system_scope = new SiteScope( RiskTier::Admin, array( 'system' ), array( 'xnetvn/get-site-info' ), array(), array(), array(), new DateTimeImmutable( '2027-01-01T00:00:00Z' ) );
		$system_site  = $this->site_context( $system_scope );
		self::assertTrue( $site_policy->evaluate( $reviewed, $system_site, 'http' )->allowed, 'The eligible machine scope must permit the reviewed control before any deny hook.' );
		$deny_site = static fn(): mixed => Decision::deny( 'extension_denied' );
		add_filter( 'xnetvn_agent_hub_wp_site_policy_decision', $deny_site, 10, 4 );
		try {
			$denied = $site_policy->evaluate( $reviewed, $system_site, 'http' );
			self::assertFalse( $denied->allowed, 'A machine deny hook must be able to refuse a reviewed name.' );
			self::assertSame( 'extension_denied', $denied->reason, 'The machine denial must be attributable to the extension hook, never to a scope or capability term.' );
		} finally {
			remove_filter( 'xnetvn_agent_hub_wp_site_policy_decision', $deny_site, 10 );
		}
	}

	/** Gateway advertisement and the direct call filter refuse every name outside the envelope. */
	public function test_every_ability_outside_the_envelope_is_refused_at_the_mcp_gateway(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$settings    = new MemorySettings(
			array(
				'allow_admin_tier' => true,
				'tier_ceiling'     => 'admin',
			)
		);
		$request     = $this->spy_request();
		$registry    = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
		$definitions = array();
		foreach ( $this->catalog_metadata() as $name => $meta ) {
			$definition           = ( new DefinitionFactory() )->create( $this->fixture_data( $name, $meta ) );
			$definitions[ $name ] = $definition;
			$registry->add( $definition );
		}

		$tools = array();
		foreach ( array_keys( $definitions ) as $name ) {
			$tools[] = $this->tool( str_replace( '/', '-', $name ) );
		}
		$gateway = new ServerGateway( $registry, $request, $settings, static fn(): ?string => '0.7.0' );
		$server  = $this->server();
		$listed  = $gateway->tools_list( $tools, $server, new \stdClass() );
		$wire    = array();
		foreach ( $listed as $tool ) {
			$wire[] = $tool->getName();
		}

		$expected = array();
		foreach ( $this->catalog_names() as $name ) {
			if ( in_array( $name, self::REVIEWED_EDITORIAL_SYSTEM, true ) || in_array( $name, self::HUMAN_ONLY, true ) ) {
				$expected[] = str_replace( '/', '-', $name );
			}
		}
		self::assertCount( 53, $wire, 'The gateway must advertise exactly the 53 human-envelope names for an administrator.' );
		self::assertSame( self::sorted( $expected ), $wire, 'The advertised list must be exactly the sorted human-envelope wire names.' );

		foreach ( self::sorted( self::OUTSIDE ) as $name ) {
			$tool  = $this->tool( str_replace( '/', '-', $name ) );
			$error = $gateway->pre_call( array(), $tool->getName(), $tool, $server );
			self::assertWPError( $error, 'Every outside name must be refused before the call: ' . $name . '.' );
			self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $error->get_error_code(), 'The outside refusal code must match the gateway contract for ' . $name . '.' );
			self::assertSame( 'The tool is unavailable.', $error->get_error_message(), 'The outside refusal message must stay uniform for ' . $name . '.' );
		}
		$unknown = $this->tool( 'xnetvn-unknown' );
		$error   = $gateway->pre_call( array(), 'xnetvn-unknown', $unknown, $server );
		self::assertWPError( $error, 'An unknown wire name must be refused before the call.' );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $error->get_error_code(), 'The unknown refusal code must match the gateway contract.' );
		self::assertSame( 'The tool is unavailable.', $error->get_error_message(), 'The unknown refusal message must stay uniform with the outside refusals.' );

		$arguments = array( 'id' => 7 );
		self::assertSame( $arguments, $gateway->pre_call( $arguments, 'xnetvn-get-post', $this->tool( 'xnetvn-get-post' ), $server ), 'An in-envelope representative must keep its arguments unchanged.' );

		$request->reset_counters();
		self::assertTrue( $registry->allowed( $definitions['xnetvn/get-site-info'] ), 'The reviewed control must be allowed.' );
		self::assertGreaterThanOrEqual( 1, $request->current_reads, 'The reviewed control must read the request identity.' );
		self::assertGreaterThanOrEqual( 1, $request->transport_reads, 'The reviewed control must read the transport.' );

		$request->reset_counters();
		self::assertFalse( $registry->allowed( $definitions['xnetvn/create-user'] ), 'An outside name must be denied.' );
		self::assertSame( 0, $request->current_reads, 'The envelope must reject an outside name before the identity term.' );
		self::assertSame( 0, $request->transport_reads, 'The envelope must reject an outside name before the transport term.' );
	}

	/** A registered but unreviewed callback is refused by core before its handler runs. */
	public function test_registered_but_unreviewed_ability_is_denied_at_the_core_callback(): void {
		$settings     = new MemorySettings();
		$request      = new RequestScope();
		$local        = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
		$marker       = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array( 'marker' => array( 'type' => 'string' ) ),
			'required'             => array( 'marker' ),
		);
		$reviewed_ran = false;
		$outside_ran  = false;
		$local->add(
			( new DefinitionFactory() )->create(
				array(
					'name'          => 'xnetvn/get-site-info',
					'label'         => 'Reviewed core fixture',
					'description'   => 'Returns a safe reviewed marker. Use this only to verify the reviewed core execution path.',
					'inputSchema'   => array(
						'type'                 => 'object',
						'additionalProperties' => false,
					),
					'outputSchema'  => $marker,
					'tier'          => RiskTier::Read,
					'toolset'       => 'system',
					'annotations'   => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'visibilityCap' => 'read',
					'handler'       => static function () use ( &$reviewed_ran ): array {
						$reviewed_ran = true;
						return array( 'marker' => 'reviewed' );
					},
					'example'       => array( 'marker' => 'reviewed' ),
				)
			)
		);
		$local->add(
			( new DefinitionFactory() )->create(
				array(
					'name'          => 'xnetvn/test-outside-envelope',
					'label'         => 'Unreviewed core fixture',
					'description'   => 'Returns a safe outside marker. Use this only to verify that the envelope denies the callback.',
					'inputSchema'   => array(
						'type'                 => 'object',
						'additionalProperties' => false,
					),
					'outputSchema'  => $marker,
					'tier'          => RiskTier::Read,
					'toolset'       => 'system',
					'annotations'   => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'visibilityCap' => 'read',
					'handler'       => static function () use ( &$outside_ran ): array {
						$outside_ran = true;
						return array( 'marker' => 'outside' );
					},
					'example'       => array( 'marker' => 'outside' ),
				)
			)
		);

		wp_get_ability_categories();
		wp_get_abilities();
		$saved_categories = $GLOBALS['wp_filter']['wp_abilities_api_categories_init'] ?? null;
		$saved_abilities  = $GLOBALS['wp_filter']['wp_abilities_api_init'] ?? null;
		$plugin_callbacks = array();
		if ( $saved_abilities instanceof \WP_Hook ) {
			foreach ( $saved_abilities->callbacks as $callbacks ) {
				foreach ( $callbacks as $entry ) {
					$callback = $entry['function'] ?? null;
					if ( is_array( $callback ) && $callback[0] instanceof Registry && 'register_abilities' === $callback[1] ) {
						$plugin_callbacks[] = $callback;
					}
				}
			}
		}
		self::assertCount( 1, $plugin_callbacks, 'Exactly one plugin registry registration callback must be present.' );
		$plugin_callback = $plugin_callbacks[0];
		$plugin_registry = $plugin_callback[0];
		$original        = wp_get_ability( 'xnetvn/get-site-info' );
		self::assertInstanceOf( \WP_Ability::class, $original, 'The plugin must register the reviewed ability before this test.' );
		$original_label = $original->get_label();
		self::assertFalse( wp_has_ability( 'xnetvn/test-outside-envelope' ), 'The outside fixture name must be free before the isolated registration.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		try {
			self::assertInstanceOf( \WP_Ability::class, wp_unregister_ability( 'xnetvn/get-site-info' ) );
			self::assertFalse( wp_has_ability( 'xnetvn/get-site-info' ), 'The reviewed name must be free before the fixture registers.' );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate and restore core registration hooks in this test.
			$GLOBALS['wp_filter']['wp_abilities_api_categories_init'] = new \WP_Hook();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate and restore core registration hooks in this test.
			$GLOBALS['wp_filter']['wp_abilities_api_init'] = new \WP_Hook();
			$local->register();
			do_action( 'wp_abilities_api_categories_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise core's registration lifecycle.
			do_action( 'wp_abilities_api_init', \WP_Abilities_Registry::get_instance() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise core's registration lifecycle.
			$reviewed_ability = wp_get_ability( 'xnetvn/get-site-info' );
			$outside_ability  = wp_get_ability( 'xnetvn/test-outside-envelope' );
			self::assertInstanceOf( \WP_Ability::class, $reviewed_ability, 'The isolated reviewed fixture must register.' );
			self::assertInstanceOf( \WP_Ability::class, $outside_ability, 'The isolated outside fixture must register.' );
			// The request scope reports HTTP under PHPUnit; bind the same actor shape RegistryTest uses.
			$request->context = $this->auth_context( get_current_user_id() );
			self::assertSame( array( 'marker' => 'reviewed' ), $reviewed_ability->execute( array() ), 'The reviewed callback must run and keep its schema-valid marker.' );
			self::assertTrue( $reviewed_ran, 'The reviewed handler must have run.' );
			$denied = $outside_ability->execute( array() );
			self::assertWPError( $denied, 'The unreviewed callback must be denied by core.' );
			self::assertSame( 'ability_invalid_permissions', $denied->get_error_code(), 'Core must deny the unreviewed callback on its permission check.' );
			self::assertFalse( $outside_ran, 'The unreviewed handler must never run.' );
		} finally {
			if ( wp_has_ability( 'xnetvn/test-outside-envelope' ) ) {
				wp_unregister_ability( 'xnetvn/test-outside-envelope' );
			}
			if ( wp_has_ability( 'xnetvn/get-site-info' ) ) {
				wp_unregister_ability( 'xnetvn/get-site-info' );
			}
			$local->unregister_hooks();
			try {
				$restore_hook = new \WP_Hook();
				$restore_hook->add_filter( 'wp_abilities_api_init', $plugin_callback, 10, 1 );
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate the plugin registration callback for the restore action.
				$GLOBALS['wp_filter']['wp_abilities_api_init'] = $restore_hook;
				do_action( 'wp_abilities_api_init', \WP_Abilities_Registry::get_instance() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise core's registration lifecycle.
			} finally {
				if ( null !== $saved_categories ) {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact saved hook object.
					$GLOBALS['wp_filter']['wp_abilities_api_categories_init'] = $saved_categories; }
				if ( null !== $saved_abilities ) {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact saved hook object.
					$GLOBALS['wp_filter']['wp_abilities_api_init'] = $saved_abilities; }
			}
		}
		$restored = wp_get_ability( 'xnetvn/get-site-info' );
		self::assertInstanceOf( \WP_Ability::class, $restored, 'The plugin ability must be restored after the test.' );
		self::assertSame( $original_label, $restored->get_label(), 'The restored ability must keep its reviewed label.' );
		self::assertFalse( $restored->get_meta()['show_in_rest'] ?? false, 'The restored ability must stay private.' );
		self::assertFalse( $restored->get_meta()['public'] ?? false, 'The restored ability must never be public.' );
		self::assertContains( 'xnetvn/get-site-info', $plugin_registry->names(), 'The restored ability must stay in the reviewed catalog.' );
		self::assertFalse( wp_has_ability( 'xnetvn/test-outside-envelope' ), 'The outside fixture must not survive the test.' );
	}

	/**
	 * Registered catalog names, sorted.
	 *
	 * @return array<string>
	 */
	private function catalog_names(): array {
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( str_starts_with( $name, 'xnetvn/' ) ) {
				$names[] = $name;
			}
		}
		return self::sorted( $names );
	}

	/**
	 * Registered authorization metadata of the catalog, sorted by name.
	 *
	 * @return array<string,array{tier:RiskTier,toolset:string,visibility_cap:string,annotations:array<string,mixed>}>
	 */
	private function catalog_metadata(): array {
		$metadata = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( ! str_starts_with( $name, 'xnetvn/' ) ) {
				continue;
			}
			$meta = $ability->get_meta();
			$data = isset( $meta['xnetvn'] ) && is_array( $meta['xnetvn'] ) ? $meta['xnetvn'] : array();
			$tier = isset( $data['tier'] ) && is_string( $data['tier'] ) ? RiskTier::tryFrom( $data['tier'] ) : null;
			if ( null === $tier || ! isset( $data['toolset'], $data['visibility_cap'] ) || ! is_string( $data['toolset'] ) || ! is_string( $data['visibility_cap'] ) ) {
				self::fail( 'The registered metadata of ' . $name . ' must carry a tier, toolset and visibility capability.' );
			}
			$metadata[ $name ] = array(
				'tier'           => $tier,
				'toolset'        => $data['toolset'],
				'visibility_cap' => $data['visibility_cap'],
				'annotations'    => isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array(),
			);
		}
		ksort( $metadata, SORT_STRING );
		return $metadata;
	}

	/**
	 * Build one strict private fixture from registered metadata.
	 *
	 * @param string              $name Catalog name.
	 * @param array<string,mixed> $meta Registered metadata.
	 * @return array<string,mixed>
	 */
	private function fixture_data( string $name, array $meta ): array {
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		return array(
			'name'          => $name,
			'label'         => 'Envelope fixture',
			'description'   => 'Returns a safe private fixture. Use this only to verify the reviewed envelope matrix.',
			'inputSchema'   => array(
				'type'                 => 'object',
				'additionalProperties' => false,
			),
			'outputSchema'  => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
				'required'             => array( 'id' ),
			),
			'tier'          => $meta['tier'],
			'toolset'       => $meta['toolset'],
			'annotations'   => array(
				'readonly'    => (bool) ( $annotations['readonly'] ?? false ),
				'destructive' => (bool) ( $annotations['destructive'] ?? false ),
				'idempotent'  => (bool) ( $annotations['idempotent'] ?? false ),
			),
			'visibilityCap' => $meta['visibility_cap'],
			'handler'       => static fn(): array => array( 'id' => 7 ),
			'example'       => array( 'id' => 7 ),
		);
	}

	/**
	 * Descriptor of one registered catalog name.
	 *
	 * @param string $name Canonical name.
	 * @return AbilityDescriptor
	 */
	private function descriptor( string $name ): AbilityDescriptor {
		$metadata = $this->catalog_metadata();
		self::assertArrayHasKey( $name, $metadata, 'The catalog must register ' . $name . '.' );
		$meta = $metadata[ $name ];
		return new AbilityDescriptor( $name, $meta['tier'], $meta['toolset'], $meta['visibility_cap'] );
	}

	/**
	 * Build a verified machine identity for one pinned scope.
	 *
	 * @param SiteScope $scope Machine restrictions.
	 * @return SiteAuthContext
	 */
	private function site_context( SiteScope $scope ): SiteAuthContext {
		$credential = new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), 3, 'Envelope fixture', $scope, new DateTimeImmutable( '2026-11-17T00:00:00Z' ) );
		return new SiteAuthContext( $credential, str_repeat( 'a', 16 ), '203.0.113.9' );
	}

	/**
	 * Build a delegated read identity for one actor.
	 *
	 * @param int $actor Actor identity.
	 * @return AuthContext
	 */
	private function auth_context( int $actor ): AuthContext {
		return new AuthContext(
			new ApiKey( 1, new KeyId( 'abcdefghijkl' ), $actor, 'Envelope fixture', new Scope( RiskTier::Read, array( 'system' ) ), new DateTimeImmutable( '@1791072000' ) ),
			str_repeat( 'a', 16 )
		);
	}

	/**
	 * A STDIO request fixture that counts identity and transport reads.
	 *
	 * @return object
	 */
	private function spy_request(): object {
		return new class() implements RequestContext {
			/** Counted current() reads.
			 *
			 * @var int
			 */
			public int $current_reads = 0;
			/** Counted transport() reads.
			 *
			 * @var int
			 */
			public int $transport_reads = 0;
			/** Reset both counters before one measured decision. */
			public function reset_counters(): void {
				$this->current_reads   = 0;
				$this->transport_reads = 0;
			}
			/**
			 * Count one identity read and stay anonymous.
			 *
			 * @return AuthContext|null
			 */
			public function current(): ?AuthContext {
				++$this->current_reads;
				return null;
			}
			/**
			 * Count one transport read and report STDIO.
			 *
			 * @return string
			 */
			public function transport(): string {
				++$this->transport_reads;
				return 'stdio';
			}
			/**
			 * No measured budget in this fixture.
			 *
			 * @return RateLimitResult|null
			 */
			public function rate_budget(): ?RateLimitResult {
				return null;
			}
		};
	}

	/**
	 * The facade server identity the gateway owns.
	 *
	 * @return object
	 */
	private function server(): object {
		return new class() {
			/**
			 * The reviewed server identity.
			 *
			 * @return string
			 */
			public function get_server_id(): string {
				return ServerGateway::SERVER_ID;
			}
		};
	}

	/**
	 * One advertised tool record of the adapter schema.
	 *
	 * @param string $name Wire name.
	 * @return object
	 */
	private function tool( string $name ): object {
		return new class( $name ) {
			/** Wire name.
			 *
			 * @var string
			 */
			private string $name;
			/**
			 * Bind the wire name.
			 *
			 * @param string $name Wire name.
			 */
			public function __construct( string $name ) {
				$this->name = $name;
			}
			/**
			 * The exact wire name.
			 *
			 * @return string
			 */
			public function getName(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The adapter tool record exposes this exact method name.
				return $this->name;
			}
		};
	}

	/**
	 * Return unique names in byte order.
	 *
	 * @param array<string> $names Names.
	 * @return array<string>
	 */
	private static function sorted( array $names ): array {
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );
		return $names;
	}
}
