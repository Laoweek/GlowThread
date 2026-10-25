<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Text;

use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Text\MentionResolver;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Text\MentionResolver
 * @covers \MediaWiki\Extension\GlowThread\Text\TextProcessor
 */
class MentionResolverTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	/** @var int Queries the resolver started */
	private int $queries = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	/**
	 * A resolver whose user lookup counts the queries it starts
	 */
	private function newResolver(): MentionResolver {
		$real = $this->getServiceContainer()->getUserIdentityLookup();
		$lookup = $this->createMock( UserIdentityLookup::class );
		$lookup->method( 'newSelectQueryBuilder' )->willReturnCallback( function ( ...$args ) use ( $real ) {
			$this->queries++;
			return $real->newSelectQueryBuilder( ...$args );
		} );
		return new MentionResolver( $lookup, new TextProcessor() );
	}

	private function hiddenUser(): UserIdentity {
		$user = $this->gtUser();
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$user, $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(), 'infinity', '',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		return $user;
	}

	private static function token( UserIdentity $user ): string {
		return TextProcessor::mentionToken( $user->getId() );
	}

	public function testTokensOfAllTextsAreResolvedInOneQuery() {
		$alice = $this->gtUser();
		$bob = $this->gtUser();
		$hidden = $this->hiddenUser();
		$missing = $bob->getId() + 1000;
		$resolver = $this->newResolver();
		$texts = [
			'hi ' . self::token( $alice ),
			self::token( $bob ) . ' ' . self::token( $hidden ) . " <@$missing> " . self::token( $alice ),
			'@' . $bob->getName() . ' typed, not picked',
		];
		$this->assertSame(
			[ $alice->getId() => $alice->getName(), $bob->getId() => $bob->getName() ],
			$resolver->existenceMapForTexts( $texts )
		);
		$this->assertSame( 1, $this->queries );
		$resolver->existenceMapForTexts( $texts );
		$this->assertSame( 1, $this->queries, 'IDs already looked up are not looked up again' );
	}

	public function testMentionedUsers() {
		$alice = $this->gtUser();
		$bob = $this->gtUser();
		$hidden = $this->hiddenUser();
		$text = self::token( $bob ) . self::token( $hidden ) . self::token( $alice ) . self::token( $bob ) . '<@0>';
		$users = $this->newResolver()->getMentionedUsers( $text );
		$this->assertSame( [ $bob->getId(), $alice->getId() ], array_map( static fn ( $u ) => $u->getId(), $users ) );
	}

	/**
	 * Regression (A13): text full of typed names that look like mentions is plain text, and
	 * reading it looks nothing up
	 */
	public function testTypedNamesAreNotLookedUp() {
		$this->gtUser();
		$text = '';
		for ( $i = 0; $i < 11; $i++ ) {
			$text .= '@' . mb_chr( 0x4E01 + $i ) . str_repeat( ',', 85 ) . ' ';
		}
		$resolver = $this->newResolver();
		$this->assertSame( [], $resolver->existenceMapForTexts( array_fill( 0, 260, $text ) ) );
		$this->assertSame( [], $resolver->getMentionedUsers( $text ) );
		$this->assertSame( 0, $this->queries );
	}

	/**
	 * Only the first TextProcessor::MAX_MENTIONS_SHOWN tokens of a text are looked up
	 */
	public function testMentionsAfterTheCapAreNotLookedUp() {
		$alice = $this->gtUser();
		$missing = $alice->getId() + 1000;
		$text = str_repeat( "<@$missing>", TextProcessor::MAX_MENTIONS_SHOWN ) . self::token( $alice );
		$resolver = $this->newResolver();
		$this->assertSame( [], $resolver->getMentionedUsers( $text ) );
		$this->assertSame( [], $resolver->existenceMapForTexts( [ $text ] ) );
	}

	public function testPlainTexts() {
		$alice = $this->gtUser();
		$hidden = $this->hiddenUser();
		$texts = [
			'a' => 'thanks ' . self::token( $alice ) . ' and ' . self::token( $hidden ),
			'b' => 'nothing to look up',
		];
		$this->assertSame( [
			'a' => 'thanks @' . $alice->getName() . ' and ' . TextProcessor::MENTION_PLACEHOLDER,
			'b' => 'nothing to look up',
		], $this->newResolver()->toPlainTexts( $texts ) );
		$this->assertSame( 1, $this->queries );
	}
}
