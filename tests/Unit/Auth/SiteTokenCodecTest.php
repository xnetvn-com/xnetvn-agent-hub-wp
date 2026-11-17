<?php
/**
 * Accountless site token syntax, HMAC and disclosure contract tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\GeneratedSiteKey;
use XNetVN\AgentHubWP\Auth\IssuedSiteKey;
use XNetVN\AgentHubWP\Auth\KeyHasher;
use XNetVN\AgentHubWP\Auth\Pepper;
use XNetVN\AgentHubWP\Auth\SiteTokenCodec;
use XNetVN\AgentHubWP\Auth\StoredSiteKey;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;

/** Exercises the pure accountless-site credential boundary. */
final class SiteTokenCodecTest extends TestCase {

	/** Every generated site token has the exact shape and unique public id. */
	public function test_generated_site_tokens_have_exact_shape_and_unique_ids(): void {
		$codec = new SiteTokenCodec();
		$seen  = array();
		$ids   = array();
		for ( $i = 0; $i < 1000; ++$i ) {
			$generated = $codec->generate();
			$token     = $generated->reveal();
			self::assertMatchesRegularExpression( '/\Axns_[a-z2-7]{12}_[A-Za-z0-9_-]{43}\z/', $token );
			self::assertSame( 60, strlen( $token ) );
			self::assertArrayNotHasKey( $token, $seen );
			self::assertArrayNotHasKey( $generated->id->value, $ids );
			$seen[ $token ]               = true;
			$ids[ $generated->id->value ] = true;
			$parsed                       = SiteTokenCodec::parse( $token );
			self::assertNotNull( $parsed );
			self::assertSame( $generated->id->value, $parsed->id->value );
		}
	}

	/**
	 * Personal and malformed tokens are rejected before any key material exists.
	 *
	 * @dataProvider invalid_site_tokens
	 * @param string $token Malformed credential.
	 */
	public function test_parse_rejects_personal_and_malformed_tokens( string $token ): void {
		self::assertNull( SiteTokenCodec::parse( $token ) );
	}

	/**
	 * Invalid token cases.
	 *
	 * @return array<string,array{string}>
	 */
	public function invalid_site_tokens(): array {
		$valid = 'xns_abcdefghijkl_' . str_repeat( 'A', 43 );
		return array(
			'empty'         => array( '' ),
			'personal xnv_' => array( 'xnv_abcdefghijkl_' . str_repeat( 'A', 43 ) ),
			'no prefix'     => array( substr( $valid, 4 ) ),
			'uppercase id'  => array( str_replace( 'abcdefghijkl', 'ABCDEFGHIJKL', $valid ) ),
			'short id'      => array( 'xns_abc_' . str_repeat( 'A', 43 ) ),
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

	/** The static HMAC contract matches an independent RFC 4231 vector. */
	public function test_known_hmac_vector_and_stored_site_verification(): void {
		$pepper_bytes = str_repeat( chr( 0x0b ), 20 );
		$expected     = 'b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7';
		self::assertSame( $expected, KeyHasher::hash( 'Hi There', $pepper_bytes ) );
		self::assertTrue( KeyHasher::verify( 'Hi There', $pepper_bytes, $expected ) );
		self::assertFalse( KeyHasher::verify( 'Wrong', $pepper_bytes, $expected ) );

		$pepper = new Pepper( 1, str_repeat( 'P', 32 ) );
		$stored = new StoredSiteKey( $this->credential(), KeyHasher::hash( 'site-secret', $pepper->reveal() ), 1 );
		self::assertTrue( $stored->verify_secret( 'site-secret', $pepper ) );
		self::assertFalse( $stored->verify_secret( 'wrong', $pepper ) );
		self::assertFalse( $stored->verify_secret( 'site-secret', new Pepper( 2, str_repeat( 'Q', 32 ) ) ) );
	}

	/** Invalid stored verifier metadata is rejected without normalization. */
	public function test_stored_site_key_rejects_invalid_metadata(): void {
		foreach ( array( array( 'invalid', 1 ), array( str_repeat( 'g', 64 ), 1 ), array( str_repeat( 'a', 64 ), 0 ), array( str_repeat( 'a', 64 ), 256 ) ) as list( $hash, $version ) ) {
			try {
				new StoredSiteKey( $this->credential(), $hash, $version );
				self::fail( 'Corrupt site verification metadata was accepted.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertSame( 'Invalid site credential metadata.', $exception->getMessage() );
			}
		}
	}

	/** Generated site credentials disclose once and cannot be cloned. */
	public function test_generated_site_key_reveals_once_and_cannot_be_cloned(): void {
		$key   = ( new SiteTokenCodec() )->generate();
		$token = $key->reveal();
		self::assertStringStartsWith( 'xns_', $token );

		$this->expectException( \LogicException::class );
		$key->reveal();
	}

	/** Cloning a generated site credential is impossible. */
	public function test_generated_site_key_cannot_be_cloned(): void {
		$key = ( new SiteTokenCodec() )->generate();
		$this->expectException( \Error::class );
		clone $key;
	}

	/** Issued site credentials delegate a single disclosure and cannot be cloned. */
	public function test_issued_site_key_reveals_once_and_cannot_be_cloned(): void {
		$key   = new IssuedSiteKey( $this->credential(), ( new SiteTokenCodec() )->generate() );
		$token = $key->reveal();
		self::assertStringStartsWith( 'xns_', $token );

		try {
			$key->reveal();
			self::fail( 'Issued site key disclosed twice.' );
		} catch ( \LogicException ) {
			self::assertTrue( true );
		}

		$this->expectException( \Error::class );
		clone $key;
	}

	/** Debug, JSON and serialization surfaces never expose token material. */
	public function test_site_credentials_do_not_expose_secret_or_hash(): void {
		$generated = ( new SiteTokenCodec() )->generate();
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test actual PHP debug/JSON surfaces without WordPress.
		self::assertStringNotContainsString( 'xns_', print_r( $generated, true ) );
		self::assertStringNotContainsString( 'xns_', var_export( $generated, true ) );
		self::assertStringNotContainsString( 'xns_', (string) json_encode( $generated ) );
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$pepper = new Pepper( 1, str_repeat( 'P', 32 ) );
		$hash   = KeyHasher::hash( 'site-secret', $pepper->reveal() );
		$stored = new StoredSiteKey( $this->credential(), $hash, 1 );
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.PHP.AlternativeFunctions.json_encode_json_encode -- Test actual PHP debug/JSON surfaces without WordPress.
		self::assertStringNotContainsString( $hash, print_r( $stored, true ) );
		self::assertStringNotContainsString( $hash, var_export( $stored, true ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON is inspected only for absence of the stored hash.
		self::assertStringNotContainsString( $hash, (string) json_encode( $stored ) );
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Verify that the stored hash is absent from serialization.
		self::assertStringNotContainsString( $hash, serialize( $stored ) );
	}

	/** Wrapper debug and JSON surfaces do not expose the actual secret. */
	public function test_wrapper_debug_surfaces_do_not_expose_the_actual_secret(): void {
		$generated = ( new SiteTokenCodec() )->generate();
		$parsed    = SiteTokenCodec::parse( $generated->reveal() );
		self::assertNotNull( $parsed );
		$this->assert_secret_absent( $parsed->secret->reveal(), $generated );

		$issued = new IssuedSiteKey( $this->credential(), ( new SiteTokenCodec() )->generate() );
		$parsed = SiteTokenCodec::parse( $issued->reveal() );
		self::assertNotNull( $parsed );
		$this->assert_secret_absent( $parsed->secret->reveal(), $issued );

		$this->expectException( \LogicException::class );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Verify that issued site credential serialization is blocked.
		serialize( $issued );
	}

	/** The issuance chain hashes, reveals, parses and verifies one credential. */
	public function test_site_issuance_chain_round_trips(): void {
		$codec     = new SiteTokenCodec();
		$pepper    = new Pepper( 1, str_repeat( 'P', 32 ) );
		$generated = $codec->generate();
		$hash      = $generated->hash_with( $pepper );
		$parsed    = SiteTokenCodec::parse( $generated->reveal() );
		self::assertNotNull( $parsed );

		$stored = new StoredSiteKey( $this->credential(), $hash, 1 );
		self::assertTrue( $stored->verify_secret( $parsed->secret->reveal(), $pepper ) );
		self::assertFalse( $stored->verify_secret( 'wrong-secret', $pepper ) );
	}

	/**
	 * Assert one secret and every eight-character window is absent.
	 *
	 * @param string $secret Secret.
	 * @param object $value Wrapper.
	 * @return void
	 */
	private function assert_secret_absent( string $secret, object $value ): void {
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test actual PHP debug/JSON surfaces without WordPress.
		$outputs = array( print_r( $value, true ), var_export( $value, true ), (string) json_encode( $value ) );
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$length = strlen( $secret );
		foreach ( $outputs as $output ) {
			self::assertStringNotContainsString( $secret, $output );
			for ( $offset = 0; $offset + 8 <= $length; ++$offset ) {
				self::assertStringNotContainsString( substr( $secret, $offset, 8 ), $output );
			}
		}
	}

	/** Serializing a live site secret is forbidden. */
	public function test_generated_site_key_cannot_be_serialized(): void {
		$key = ( new SiteTokenCodec() )->generate();
		$this->expectException( \LogicException::class );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Verify that credential serialization is explicitly blocked.
		serialize( $key );
	}

	/**
	 * Build a safe website credential fixture.
	 *
	 * @return SiteCredential
	 */
	private function credential(): SiteCredential {
		$scope = new SiteScope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post' ), expires_at: new DateTimeImmutable( '2027-01-01T00:00:00Z' ) );
		return new SiteCredential(
			1,
			new KeyId( 'abcdefghijkl' ),
			3,
			'Site fixture',
			$scope,
			new DateTimeImmutable( '2026-11-17T00:00:00Z' )
		);
	}
}
