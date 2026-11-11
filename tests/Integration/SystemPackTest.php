<?php
/**
 * System-pack contracts and real scoped execution.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\Packs\System\SystemPack;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Ensures discovery/meta execution cannot escape registry policy. */
final class SystemPackTest extends WP_UnitTestCase {
	/** All five system definitions have valid strict schemas and safe examples. */
	public function test_system_contract_and_execution(): void {
		$settings = new MemorySettings();
		$request  = new RequestScope();
		$registry = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
		$pack     = new SystemPack( new DefinitionFactory(), $registry, $request, new \XNetVN\AgentHubWP\Abilities\SchemaBuilder() );
		\XNetVN\AgentHubWP\Tests\Contract\PackContractTestCase::assert_pack_contract( $pack );
		$definitions = iterator_to_array( $pack->definitions() );
		self::assertCount( 5, $definitions );
		$validator = new \Opis\JsonSchema\Validator();
		foreach ( $definitions as $definition ) {
			self::assertFalse( $definition->input_schema()['additionalProperties'] );
			self::assertFalse( $definition->output_schema()['additionalProperties'] );
			self::assertTrue( $validator->validate( json_decode( wp_json_encode( $definition->example() ) ), json_decode( wp_json_encode( $definition->output_schema() ) ) )->isValid(), $definition->descriptor()->name );
			$registry->add( $definition );
		}
		$owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $owner );
		$request->context = new AuthContext( new ApiKey( 1, new KeyId( 'abcdefghijkl' ), $owner, 'Fixture', new Scope( RiskTier::Read ), new \DateTimeImmutable() ), '0123456789abcdef' );
		$session          = $registry->get( 'xnetvn/get-session-info' )->execute( array() );
		self::assertSame( $owner, $session['user']['id'] );
		self::assertSame( 'abcdefghijkl', $session['key']['key_id'] );
		$discover = $registry->get( 'xnetvn/discover-abilities' )->execute( array() );
		$names    = array_column( $discover['items'], 'name' );
		$sorted   = $names;
		sort( $sorted );
		self::assertSame( $sorted, $names );
		self::assertWPError(
			$registry->get( 'xnetvn/execute-ability' )->execute(
				array(
					'name'      => 'core/get-site-info',
					'arguments' => array(),
				)
			)
		);
		self::assertWPError(
			$registry->get( 'xnetvn/execute-ability' )->execute(
				array(
					'name'      => 'xnetvn/execute-ability',
					'arguments' => array(),
				)
			)
		);
	}
}
