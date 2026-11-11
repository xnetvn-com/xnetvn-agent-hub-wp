<?php
/**
 * Constructor guards of the immutable domain value objects.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\DownloadReply;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;

/** Malformed identity data never enters the domain model. */
final class ValueObjectGuardsTest extends TestCase {

	/** A descriptor name must be a two-segment lowercase identifier. */
	public function test_ability_descriptor_rejects_an_invalid_name(): void {
		$this->expectException( \InvalidArgumentException::class );
		new AbilityDescriptor( 'Bad Name', RiskTier::Read, 'system', 'read' );
	}

	/** Key metadata requires positive identities and a bounded name. */
	public function test_api_key_rejects_invalid_metadata(): void {
		$this->expectException( \InvalidArgumentException::class );
		new ApiKey(
			0,
			new KeyId( 'abcdefghijkl' ),
			1,
			'name',
			new Scope( RiskTier::Read ),
			new DateTimeImmutable( '2026-10-31T00:00:00Z' )
		);
	}

	/** Request identity is exactly sixteen lowercase hexadecimal characters. */
	public function test_auth_context_rejects_an_invalid_request_id(): void {
		$key = new ApiKey(
			1,
			new KeyId( 'abcdefghijkl' ),
			7,
			'name',
			new Scope( RiskTier::Read ),
			new DateTimeImmutable( '2026-10-31T00:00:00Z' )
		);
		$this->expectException( \InvalidArgumentException::class );
		new AuthContext( $key, 'NOT-HEX' );
	}

	/** Only real HTTP status codes describe a remote reply. */
	public function test_download_reply_rejects_an_out_of_range_status(): void {
		$this->expectException( \InvalidArgumentException::class );
		new DownloadReply( 99, null, false );
	}
}
