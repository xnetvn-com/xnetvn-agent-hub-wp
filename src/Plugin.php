<?php
/**
 * Plugin kernel.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP;

use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Auth\Authenticator;
use XNetVN\AgentHubWP\Auth\HeaderExtractor;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Persistence\MigrationProvider;
use XNetVN\AgentHubWP\Persistence\DeactivationProvider;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Support\SystemClock;
use XNetVN\AgentHubWP\Http\RouteGate;
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\RateLimit\SlidingWindowLimiter;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\SchemaBuilder;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\System\SystemPack;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Mcp\ServerGateway;
use XNetVN\AgentHubWP\Audit\AuditLogger;
use XNetVN\AgentHubWP\Audit\Retention;
use XNetVN\AgentHubWP\Audit\PrivacyExporter;
use XNetVN\AgentHubWP\Audit\PrivacyEraser;
use XNetVN\AgentHubWP\Persistence\WpdbAuditRepository;
use XNetVN\AgentHubWP\Persistence\WpPrivacyStore;
use XNetVN\AgentHubWP\Auth\KeyService;
use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\Notifications;
use XNetVN\AgentHubWP\Persistence\WpOwnerLock;
use XNetVN\AgentHubWP\Rest\ManagementGate;
use XNetVN\AgentHubWP\Rest\KeysController;
use XNetVN\AgentHubWP\Rest\AuditController;
use XNetVN\AgentHubWP\Rest\SettingsController;
use XNetVN\AgentHubWP\Admin\Pages;

/** Registers providers once in a deterministic order. */
final class Plugin {

	/** Main plugin file.
	 *
	 * @var string
	 */
	public readonly string $plugin_file;

	/** Ordered service providers.
	 *
	 * @var iterable<ServiceProvider>
	 */
	private iterable $providers;

	/** Whether registration has completed.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Construct a kernel with optional injected providers.
	 *
	 * @param string   $plugin_file Main plugin file.
	 * @param iterable $providers Ordered providers.
	 * @phpstan-param iterable<ServiceProvider> $providers
	 */
	public function __construct( string $plugin_file, iterable $providers = array() ) {
		$this->plugin_file = $plugin_file;
		$this->providers   = $providers;
	}

	/**
	 * Boot the plugin kernel.
	 *
	 * @param string $plugin_file Main plugin file.
	 * @return self
	 */
	public static function boot( string $plugin_file ): self {
		$plugin = new self( $plugin_file, self::providers( $plugin_file ) );
		$plugin->register();
		return $plugin;
	}

	/** Register providers and dispatch the loaded hook once. */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		foreach ( $this->providers as $provider ) {
			$provider->register();
		}
		do_action( 'xnetvn_agent_hub_wp_loaded', $this );
	}

	/**
	 * Return the ordered infrastructure providers.
	 *
	 * @param string $plugin_file Main plugin file.
	 * @return array<ServiceProvider>
	 */
	private static function providers( string $plugin_file ): array {
		global $wpdb;
		$clock     = new SystemClock();
		$settings  = new WpSiteSettings();
		$request   = new RequestScope();
		$transport = new TransportGuard( $settings );
		$audit     = new WpdbAuditRepository( $wpdb );
		$privacy   = new WpPrivacyStore( $wpdb );
		$limits    = new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $clock );
		$registry  = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ), new \XNetVN\AgentHubWP\RateLimit\WpExpensiveBudget( $wpdb ) );
		$registry->add_pack( new \XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack( new DefinitionFactory() ) );
		$registry->add_pack( new \XNetVN\AgentHubWP\Abilities\Packs\Media\MediaPack( new DefinitionFactory(), $settings ) );
		$registry->add_pack( new \XNetVN\AgentHubWP\Abilities\Packs\Comments\CommentsPack( new DefinitionFactory() ) );
		$registry->add_pack( new \XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\TaxonomyPack( new DefinitionFactory() ) );
		$registry->add_pack( new \XNetVN\AgentHubWP\Abilities\Packs\Users\UsersPack( new DefinitionFactory(), $limits ) );
		$registry->add_pack( new SystemPack( new DefinitionFactory(), $registry, $request, new SchemaBuilder() ) );
		$keys    = new WpdbKeyRepository( $wpdb, $clock );
		$peppers = new PepperStore();
		$service = new KeyService( $keys, $peppers, new KeyGenerator(), $settings, $registry, $limits, new WpOwnerLock( $wpdb ), $clock );
		$gate    = new ManagementGate();
		$gateway = new ServerGateway( $registry, $request, $settings, null, $keys, new HeaderExtractor( $settings ) );
		return array(
			new MigrationProvider( $plugin_file, new Migrator( $wpdb, $clock ) ),
			new DeactivationProvider( $plugin_file ),
			new Capabilities( $settings ),
			new RouteGate( $settings, $transport ),
			new AuditLogger( $audit, $request, $settings, $clock, $registry ),
			new Retention( $audit, $settings, $clock, new WpBucketStore( $wpdb ) ),
			new PrivacyExporter( $privacy ),
			new PrivacyEraser( $privacy ),
			new Notifications( $keys, $settings, $clock ),
			new Authenticator( $keys, $peppers, $settings, new HeaderExtractor( $settings ), $limits, $transport, $request, $clock ),
			$registry,
			$gateway,
			$gate,
			new KeysController( $gate, $service, $keys ),
			new AuditController( $gate, $audit ),
			new SettingsController( $gate, $settings, $registry, $keys, $gateway, $transport ),
			new Pages( $plugin_file, $settings, $registry ),
		);
	}
}
