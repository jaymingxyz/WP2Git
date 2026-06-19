<?php

declare(strict_types=1);

namespace WP2Git\Update;

defined( 'ABSPATH' ) || exit;

use WP2Git\GitHub\GitData;
use WP_Error;

/**
 * Resolves the set of files to apply for an incoming push. Uses the Compare API
 * against the last synced commit, or a full recursive tree on the first import.
 */
final class ChangeFetcher {

	public function __construct( private readonly GitData $gitData ) {
	}

	/**
	 * @return list<array{path:string,status:string,sha:?string,previous:?string}>|WP_Error
	 */
	public function changes( ?string $base, string $head ) {
		if ( $base === null ) {
			return $this->gitData->fullTree( $head );
		}
		if ( $base === $head ) {
			return array();
		}
		return $this->gitData->compare( $base, $head );
	}
}
