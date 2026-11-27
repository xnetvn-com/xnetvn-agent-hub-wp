<?php
/**
 * Request-local authenticated website principal.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

/** Derives machine identity from the validated site credential, never a cookie user. */
final readonly class SiteAuthContext {

	/** Fixed principal kind.
	 *
	 * @var string
	 */
	public string $principal_type;

	/**
	 * Bind a validated site credential to a fresh request.
	 *
	 * @param SiteCredential $credential Verified site credential.
	 * @param string         $request_id Request identifier.
	 * @param string         $client_ip Authenticated peer address.
	 * @throws \InvalidArgumentException For empty request identities.
	 */
	public function __construct( public SiteCredential $credential, public string $request_id, public string $client_ip ) {
		if ( '' === trim( $request_id ) || '' === trim( $client_ip ) ) {
			throw new \InvalidArgumentException( 'Invalid site request identity.' );
		}
		$this->principal_type = 'site';
	}

	/**
	 * Return the current website identity.
	 *
	 * @return int
	 */
	public function blog_id(): int {
		return $this->credential->blog_id;
	}

	/**
	 * Return the unambiguous machine credential reference.
	 *
	 * @return string
	 */
	public function credential_ref(): string {
		return 'xns:' . $this->credential->key_id->value;
	}

	/**
	 * Check an explicit moderation grant.
	 *
	 * @param string $permission Permission name.
	 * @return bool
	 */
	public function has_permission( string $permission ): bool {
		return in_array( $permission, $this->credential->scope->permissions, true );
	}

	/**
	 * Expose safe request identity only.
	 *
	 * @return array<string,int|string>
	 */
	public function __debugInfo(): array {
		return array(
			'principal_type' => $this->principal_type,
			'blog_id'        => $this->blog_id(),
			'credential_ref' => $this->credential_ref(),
			'request_id'     => $this->request_id,
			'client_ip'      => $this->client_ip,
		);
	}
}
