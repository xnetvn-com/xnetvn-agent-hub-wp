<?php
/**
 * Conservative annotation classification and extension risk tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Policy;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Policy\TierResolver;

/** Metadata cannot downgrade a declared risk or infer admin authority. */
final class TierResolverTest extends TestCase {
	/** Missing/malformed/conflicting annotations remain destructive. */
	public function test_annotation_classification(): void {
		self::assertSame( RiskTier::Destructive, TierResolver::from_annotations( null ) );
		self::assertSame( RiskTier::Destructive, TierResolver::from_annotations( array() ) );
		self::assertSame(
			RiskTier::Read,
			TierResolver::from_annotations(
				array(
					'readonly'    => true,
					'destructive' => false,
				)
			)
		);
		self::assertSame(
			RiskTier::Write,
			TierResolver::from_annotations(
				array(
					'readonly'    => false,
					'destructive' => false,
				)
			)
		);
		self::assertSame(
			RiskTier::Destructive,
			TierResolver::from_annotations(
				array(
					'readonly'    => true,
					'destructive' => true,
				)
			)
		);
		self::assertSame( RiskTier::Destructive, TierResolver::from_annotations( array( 'readonly' => 'true' ) ) );
	}
	/** The risk filter can only raise the descriptor's declared tier. */
	public function test_filter_cannot_lower_risk(): void {
		$descriptor = new AbilityDescriptor( 'xnetvn/delete-post', RiskTier::Destructive, 'content', 'delete_posts' );
		$resolver   = new TierResolver( static fn(): ?AbilityDescriptor => $descriptor, static fn(): RiskTier => RiskTier::Read );
		self::assertSame( RiskTier::Destructive, $resolver->tier_for( 'xnetvn/delete-post' ) );
		$raised = new TierResolver( static fn(): ?AbilityDescriptor => $descriptor, static fn(): RiskTier => RiskTier::Admin );
		self::assertSame( RiskTier::Admin, $raised->tier_for( 'xnetvn/delete-post' ) );
	}
}
