<?php
// Dev only: create the dev accounts, then set their groups, registration dates and blocks.
// Safe to run again: existing accounts, groups and blocks are kept.
//
// Reads one account per line from stdin, tab separated, so passwords stay off the command line:
//   name <TAB> password <TAB> groups <TAB> flags
// password: "-" for an account that never logs in (it gets no password).
// groups: comma separated, or "-". flags: comma separated, or "-":
//   age:<age>            registered at least <age> ago ("400d"). Only ever moves the date back.
//   renew:<age>          registered exactly <age> ago, set again on every run (a new account)
//   block                sitewide block, no expiry
//   partialblock:<page>  blocked from <page> only, no expiry
//
//   php maintenance/run.php /dev-maintenance/SeedAccounts.php < accounts.tsv
//
// A password is only set when it differs from the stored one, so logged-in sessions survive.
// Blocks have autoblock off: every dev request comes from the same few Docker addresses, so an
// autoblock would block the other accounts too.

use MediaWiki\Auth\AuthManager;
use MediaWiki\Block\Restriction\PageRestriction;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

require_once __DIR__ . '/SeedSupport.php';

class GlowThreadDevSeedAccounts extends Maintenance {
	use GlowThreadDevSeedSupport;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Dev only: create, date and block the GlowThread dev accounts' );
	}

	public function execute() {
		$accounts = [];
		while ( ( $line = fgets( STDIN ) ) !== false ) {
			$line = rtrim( $line, "\r\n" );
			if ( $line !== '' ) {
				$accounts[] = array_pad( explode( "\t", $line ), 4, '-' );
			}
		}
		// Accounts first: the blocks below are placed by Admin.
		foreach ( $accounts as [ $name, $password, $groups ] ) {
			$user = $this->ensureAccount( $name, $password );
			if ( $groups !== '-' ) {
				$this->ensureGroups( $user, explode( ',', $groups ) );
			}
		}
		foreach ( $accounts as [ $name, , , $flags ] ) {
			if ( $flags === '-' ) {
				continue;
			}
			$user = $this->getServiceContainer()->getUserFactory()->newFromName( $name );
			foreach ( explode( ',', $flags ) as $flag ) {
				[ $kind, $value ] = array_pad( explode( ':', $flag, 2 ), 2, '' );
				if ( $kind === 'age' ) {
					$this->setRegistration( $user, time() - $this->parseAgo( $value ), true );
				} elseif ( $kind === 'renew' ) {
					$this->setRegistration( $user, time() - $this->parseAgo( $value ), false );
				} elseif ( $kind === 'block' ) {
					$this->ensureBlock( $user, null );
				} elseif ( $kind === 'partialblock' ) {
					$this->ensureBlock( $user, $value );
				} else {
					$this->fatalError( "Unknown flag $flag for $name" );
				}
			}
		}
	}

	private function ensureAccount( string $name, string $password ): User {
		$services = $this->getServiceContainer();
		$user = $services->getUserFactory()->newFromName( $name );
		if ( !$user ) {
			$this->fatalError( "Invalid user name $name" );
		}
		if ( !$user->isRegistered() ) {
			// This also counts the account in the site statistics.
			$status = $services->getAuthManager()->autoCreateUser(
				$user, AuthManager::AUTOCREATE_SOURCE_MAINT, false
			);
			if ( !$status->isGood() ) {
				$this->fatalError( "Could not create $name: " . $status->getWikiText( false, false, 'en' ) );
			}
			$this->output( "created $name\n" );
		}
		if ( $password === '-' ) {
			return $user;
		}
		$stored = $this->getPrimaryDB()->newSelectQueryBuilder()
			->select( 'user_password' )
			->from( 'user' )
			->where( [ 'user_id' => $user->getId() ] )
			->caller( __METHOD__ )
			->fetchField();
		if ( !$services->getPasswordFactory()->newFromCiphertext( $stored ?: null )->verify( $password ) ) {
			$status = $user->changeAuthenticationData( [
				'username' => $user->getName(),
				'password' => $password,
				'retype' => $password,
			] );
			if ( !$status->isGood() ) {
				$this->fatalError( "Could not set the password of $name: " . $status->getWikiText( false, false, 'en' ) );
			}
			$user->saveSettings();
			$this->output( "set the password of $name\n" );
		}
		return $user;
	}

	private function ensureGroups( User $user, array $groups ): void {
		$userGroupManager = $this->getServiceContainer()->getUserGroupManager();
		$unknown = array_diff( $groups, $userGroupManager->listAllGroups() );
		if ( $unknown ) {
			$this->fatalError( 'Unknown groups: ' . implode( ', ', $unknown ) );
		}
		$missing = array_diff( $groups, $userGroupManager->getUserGroups( $user ) );
		if ( $missing ) {
			$userGroupManager->addUserToMultipleGroups( $user, $missing );
			$this->output( "added {$user->getName()} to " . implode( ', ', $missing ) . "\n" );
		}
	}

	/**
	 * @param User $user
	 * @param int $unixTime
	 * @param bool $onlyEarlier Leave a registration date that is already earlier alone
	 */
	private function setRegistration( User $user, int $unixTime, bool $onlyEarlier ): void {
		$dbw = $this->getPrimaryDB();
		$timestamp = $dbw->timestamp( $unixTime );
		$conds = [ 'user_id' => $user->getId() ];
		if ( $onlyEarlier ) {
			$conds[] = $dbw->expr( 'user_registration', '>', $timestamp );
		}
		$dbw->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_registration' => $timestamp ] )
			->where( $conds )
			->caller( __METHOD__ )
			->execute();
		if ( $dbw->affectedRows() ) {
			$user->invalidateCache();
			if ( $onlyEarlier ) {
				$this->output( "dated {$user->getName()} to " . gmdate( 'Y-m-d', $unixTime ) . "\n" );
			}
		}
	}

	private function ensureBlock( User $user, ?string $page ): void {
		$services = $this->getServiceContainer();
		if ( $services->getDatabaseBlockStore()->newFromTarget( $user, null, true ) ) {
			return;
		}
		$options = [ 'isAutoblocking' => false ];
		$restrictions = [];
		if ( $page !== null ) {
			$title = Title::newFromText( $page );
			if ( !$title || !$title->exists() ) {
				$this->fatalError( "Cannot block {$user->getName()} from $page: the page does not exist" );
			}
			$options['isPartial'] = true;
			$restrictions[] = new PageRestriction( 0, $title->getArticleID() );
		}
		$status = $services->getBlockUserFactory()->newBlockUser(
			$user, $this->seedUser( 'Admin' ), 'infinity', 'GlowThread dev test account', $options, $restrictions
		)->placeBlock();
		if ( !$status->isOK() ) {
			$this->fatalError( "Could not block {$user->getName()}: " . $status->getWikiText( false, false, 'en' ) );
		}
		$this->output( 'blocked ' . $user->getName() . ( $page === null ? ' sitewide' : " from $page" ) . "\n" );
	}
}

return GlowThreadDevSeedAccounts::class;
