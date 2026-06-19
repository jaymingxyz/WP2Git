<?php

declare(strict_types=1);

namespace WP2Git\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Pure decision for the pusher allowlist — a compensating control for
 * auto-apply: when configured, only pushes from (and commits authored by)
 * allowlisted GitHub users are applied to the live site. Kept dependency-free
 * so it can be exhaustively unit-tested.
 */
final class PusherPolicy {

	/**
	 * @param list<string>  $allowlist Configured GitHub logins (any case; blanks ignored).
	 * @param string|null   $sender    Login that pushed (webhook sender).
	 * @param list<?string> $authors   Commit author logins (null when GitHub can't resolve one).
	 */
	public static function allows( array $allowlist, ?string $sender, array $authors ): bool {
		$set = array();
		foreach ( $allowlist as $login ) {
			$login = strtolower( trim( (string) $login ) );
			if ( $login !== '' ) {
				$set[ $login ] = true;
			}
		}

		// Empty allowlist = feature disabled: auto-apply from any pusher.
		if ( $set === array() ) {
			return true;
		}

		// The pusher must be known and allowlisted.
		if ( $sender === null || ! isset( $set[ strtolower( $sender ) ] ) ) {
			return false;
		}

		// Every resolvable commit author must also be allowlisted, so an
		// allowlisted user can't relay someone else's commits onto the site.
		foreach ( $authors as $author ) {
			if ( $author !== null && $author !== '' && ! isset( $set[ strtolower( $author ) ] ) ) {
				return false;
			}
		}

		return true;
	}
}
