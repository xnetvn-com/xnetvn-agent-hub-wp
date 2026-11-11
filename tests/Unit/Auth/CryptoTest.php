<?php
/**
 * Key entropy, parsing and HMAC contract tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\KeyHasher;
use XNetVN\AgentHubWP\Auth\TokenParser;

/** Exercises the pure-PHP cryptographic boundary. */
final class CryptoTest extends TestCase {

	/** Every generated token is unique and has exactly the required entropy format. */
	public function test_ten_thousand_generated_keys(): void {
		$generator = new KeyGenerator();
		$seen      = array();
		for ( $i = 0; $i < 10000; ++$i ) {
			$generated = $generator->generate();
			$token     = $generated->reveal();
			self::assertMatchesRegularExpression( '/\Axnv_[a-z2-7]{12}_[A-Za-z0-9_-]{43}\z/', $token );
			self::assertSame( 60, strlen( $token ) );
			self::assertArrayNotHasKey( $token, $seen );
			$seen[ $token ] = true;
			$parsed         = TokenParser::parse( $token );
			self::assertNotNull( $parsed );
			self::assertSame( $generated->id->value, $parsed->id->value );
		}
	}

	/** An independently specified RFC 4231 HMAC-SHA256 vector matches. */
	public function test_hmac_known_vector_and_verification(): void {
		$pepper = str_repeat( chr( 0x0b ), 20 );
		$stored = 'b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7';
		self::assertSame( $stored, KeyHasher::hash( 'Hi There', $pepper ) );
		self::assertTrue( KeyHasher::verify( 'Hi There', $pepper, $stored ) );
		self::assertFalse( KeyHasher::verify( 'Wrong', $pepper, $stored ) );
		self::assertFalse( KeyHasher::verify( 'Hi There', 'Wrong pepper', $stored ) );
		self::assertFalse( KeyHasher::verify( 'Hi There', $pepper, 'invalid' ) );
	}

	/** A generated token is only disclosed once and never in debugging exports. */
	public function test_disclosure_is_once_only(): void {
		$key = ( new KeyGenerator() )->generate();
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test actual PHP debug/JSON surfaces without WordPress.
		self::assertStringNotContainsString( 'xnv_', print_r( $key, true ) );
		self::assertStringNotContainsString( 'xnv_', var_export( $key, true ) );
		self::assertStringNotContainsString( 'xnv_', (string) json_encode( $key ) );
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$key->reveal();
		$this->expectException( \LogicException::class );
		$key->reveal();
	}

	/**
	 * Invalid values must never enter the repository boundary.
	 *
	 * @dataProvider invalid_tokens
	 * @param string $token Malformed credential.
	 */
	public function test_invalid_tokens( string $token ): void {
		self::assertNull( TokenParser::parse( $token ) );
	}

	/**
	 * Malformed-token test cases.
	 *
	 * @return array<string,array{string}>
	 */
	public function invalid_tokens(): array {
		$valid = 'xnv_abcdefghijkl_' . str_repeat( 'A', 43 );
		return array(
			'empty'         => array( '' ),
			'no prefix'     => array( substr( $valid, 4 ) ),
			'uppercase id'  => array( str_replace( 'abcdefghijkl', 'ABCDEFGHIJKL', $valid ) ),
			'short id'      => array( 'xnv_abc_' . str_repeat( 'A', 43 ) ),
			'short secret'  => array( substr( $valid, 0, -1 ) ),
			'long secret'   => array( $valid . 'A' ),
			'leading space' => array( ' ' . $valid ),
			'trailing LF'   => array( $valid . "\n" ),
			'padding'       => array( substr( $valid, 0, -1 ) . '=' ),
			'bad character' => array( substr( $valid, 0, -1 ) . '/' ),
			'null byte'     => array( substr( $valid, 0, -1 ) . "\0" ),
			'oversized'     => array( str_repeat( 'x', 100000 ) ),
		);
	}
}
