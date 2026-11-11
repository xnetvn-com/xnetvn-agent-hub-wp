<?php
/**
 * Strict non-autoloaded site configuration.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Persistence;

use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Support\Cidr;
use XNetVN\AgentHubWP\Http\OriginGuard;

/** Rejects malformed settings instead of converting them into permissions. */
final class WpSiteSettings implements SiteSettings {
	/** Last exact option value; never substitutes for a fresh get_option() read.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $snapshot = null;
	/** Validated pure-value projection of that exact snapshot.
	 *
	 * @var array<string,mixed>
	 */
	private array $validated_snapshot = array();
	/** Per-site option name. */
	public const OPTION = 'xnetvn_agent_hub_wp_settings';
	/** Documented security defaults. */
	public const DEFAULTS = array(
		'enabled'                         => true,
		'allowed_roles_override'          => null,
		'tier_ceiling'                    => 'destructive',
		'allow_admin_tier'                => false,
		'max_keys_per_user'               => 10,
		'max_key_lifetime_days'           => 365,
		'allowed_origins'                 => array(),
		'site_ip_allowlist'               => array(),
		'trusted_proxy_headers'           => array(),
		'trusted_proxy_cidrs'             => array(),
		'fallback_header_enabled'         => true,
		'rate_limit_key_per_min'          => 120,
		'rate_limit_auth_fail_per_min_ip' => 10,
		'output_max_bytes'                => 262144,
		'audit_retention_days'            => 90,
		'anonymize_ip'                    => false,
		'exposed_third_party_abilities'   => array(),
		'disable_adapter_default_server'  => false,
		'notify_key_created'              => true,
		'notify_key_revoked'              => true,
		'notify_key_expiring'             => true,
		'notify_unusual_ip'               => true,
		'delete_data_on_uninstall'        => true,
		'media_remote_fetch_enabled'      => false,
		'media_remote_max_bytes'          => 10485760,
		'media_remote_timeout_seconds'    => 15,
		'media_remote_max_redirects'      => 3,
		'media_data_max_bytes'            => 1048576,
		'media_pixels_max'                => 40000000,
		'media_mime_denylist'             => array(),
	);
	/**
	 * Shared numeric ranges for persistence, REST and the settings form.
	 *
	 * @param string $name Setting name.
	 * @return array{int,int}
	 */
	public static function numeric_range( string $name ): array {
		return match ( $name ) {
			'media_remote_max_bytes' => array( 1024, 67108864 ),
			'media_data_max_bytes' => array( 1024, 8388608 ),
			'media_remote_timeout_seconds' => array( 3, 30 ),
			'media_remote_max_redirects' => array( 0, 5 ),
			'media_pixels_max' => array( 1000000, 100000000 ),
			default => array( 1, 4194304 ),
		};
	}

	/**
	 * Read a known field from validated current options.
	 *
	 * @param string $name Field name.
	 * @return mixed
	 * @throws \InvalidArgumentException For an unknown field.
	 */
	public function get( string $name ): mixed {
		if ( ! array_key_exists( $name, self::DEFAULTS ) ) {
			throw new \InvalidArgumentException( 'Unknown site setting.' );
		}
		return $this->all()[ $name ];
	}
	/**
	 * Read the active site's validated snapshot.
	 *
	 * @return array<string,mixed>
	 * @throws StorageException For invalid persisted settings.
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			throw new StorageException( 'Site settings are unavailable.' );
		}
		// Validation is pure for reads. Identical values across sites have identical decisions;
		// every read still resolves the current site's option and detects in-request changes.
		if ( $this->snapshot === $stored ) {
			return $this->validated_snapshot;
		}
		try {
			$result                   = array_merge( self::DEFAULTS, $this->validated( $stored ) );
			$this->snapshot           = $stored;
			$this->validated_snapshot = $result;
			return $result;
		} catch ( \InvalidArgumentException ) {
			throw new StorageException( 'Site settings are unavailable.' );
		}
	}
	/**
	 * Apply validated changes and prove option readback.
	 *
	 * @param array<string,mixed> $changes Changes.
	 * @return array<string,mixed>
	 * Invalid changes raise InvalidArgumentException through prepare_update().
	 *
	 * @throws StorageException For unavailable persistence.
	 * @see prepare_update()
	 */
	public function update( array $changes ): array {
		$data = $this->prepare_update( $changes );
		update_option( self::OPTION, $data, false );
		if ( get_option( self::OPTION ) !== $data ) {
			throw new StorageException( 'Site settings could not be recorded.' );
		}
		return $data;
	}

	/**
	 * Validate and merge an explicit update without writing, for the Settings API.
	 *
	 * @param array<string,mixed> $changes Explicit fields.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException For unknown/invalid policy fields.
	 */
	public function prepare_update( array $changes ): array {
		$valid = $this->validated( $changes, true );
		try {
			$current = $this->all();
		} catch ( StorageException ) {
			$current = self::DEFAULTS;
			$stored  = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) && array_diff( array_keys( self::DEFAULTS ), array_keys( $valid ) ) ) {
				throw new \InvalidArgumentException( 'An unavailable policy snapshot requires a complete explicit replacement.' );
			}
			if ( is_array( $stored ) ) {
				foreach ( $stored as $name => $value ) {
					try {
						$current = array_merge( $current, $this->validated( array( $name => $value ) ) );
					} catch ( \InvalidArgumentException ) {
						if ( is_string( $name ) && array_key_exists( $name, self::DEFAULTS ) && ! array_key_exists( $name, $valid ) ) {
							throw new \InvalidArgumentException( 'Invalid stored settings require explicit replacements.' );
						}
						continue;
					}
				}
			}
		}
		return array_merge( $current, $valid );
	}
	/**
	 * Validate exact field types and bounded values.
	 *
	 * @param array<mixed> $data Candidate fields.
	 * @param bool         $check_roles Require current role availability on an explicit update.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException For invalid configuration.
	 */
	private function validated( array $data, bool $check_roles = false ): array {
		$result = array();
		foreach ( $data as $name => $value ) {
			if ( ! is_string( $name ) || ! array_key_exists( $name, self::DEFAULTS ) ) {
				throw new \InvalidArgumentException( 'Unknown site setting.' );
			}
			$default = self::DEFAULTS[ $name ];
			if ( is_bool( $default ) && ! is_bool( $value ) ) {
				throw new \InvalidArgumentException( 'Expected a boolean setting.' );
			}
			if ( is_int( $default ) && ( ! is_int( $value ) || $value < self::numeric_range( $name )[0] || $value > self::numeric_range( $name )[1] ) ) {
				throw new \InvalidArgumentException( 'Invalid numeric setting.' );
			}
			if ( 'tier_ceiling' === $name && ( ! is_string( $value ) || null === RiskTier::tryFrom( $value ) ) ) {
				throw new \InvalidArgumentException( 'Invalid tier ceiling.' );
			}
			if ( is_array( $default ) || 'allowed_roles_override' === $name ) {
				if ( 'allowed_roles_override' === $name && null === $value ) {
					$result[ $name ] = null;
					continue;
				}
				if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 ) {
					throw new \InvalidArgumentException( 'Invalid list setting.' );
				}
				foreach ( $value as $item ) {
					if ( ! is_string( $item ) || '' === $item || strlen( $item ) > 512 ) {
						throw new \InvalidArgumentException( 'Invalid list item.' );
					}
					if ( in_array( $name, array( 'site_ip_allowlist', 'trusted_proxy_cidrs' ), true ) && ! Cidr::valid( $item ) ) {
						throw new \InvalidArgumentException( 'Invalid IP constraint.' );
					}
					if ( 'trusted_proxy_headers' === $name && ! in_array( $item, array( 'X-Forwarded-Proto' ), true ) ) {
						throw new \InvalidArgumentException( 'Unsupported trusted proxy header.' );
					}
					if ( 'allowed_roles_override' === $name && ( 1 !== preg_match( '/\A[a-z0-9_-]+\z/', $item ) || ( $check_roles && ! wp_roles()->is_role( $item ) ) ) ) {
						throw new \InvalidArgumentException( 'Unknown WordPress role.' );
					}
					if ( 'media_mime_denylist' === $name && 1 !== preg_match( '~\A[a-z0-9.+-]+/[a-z0-9.+-]+\z~', $item ) ) {
						throw new \InvalidArgumentException( 'Invalid MIME restriction.' ); }
					if ( 'allowed_origins' === $name && null === OriginGuard::normalize( $item ) ) {
						throw new \InvalidArgumentException( 'Invalid allowed origin.' );
					}
					if ( 'exposed_third_party_abilities' === $name && 1 !== preg_match( '/\A[a-z0-9-]+\/[a-z0-9-]+\z/', $item ) ) {
						throw new \InvalidArgumentException( 'Invalid approved ability name.' );
					}
				}
				$value = array_values( array_unique( $value ) );
			}
			$result[ $name ] = $value;
		}
		return $result;
	}
}
