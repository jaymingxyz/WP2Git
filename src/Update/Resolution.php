<?php

declare(strict_types=1);

namespace WP2Git\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Pure three-way conflict decision for an incoming change. Kept free of any I/O
 * or WordPress dependency so the decision table can be exhaustively unit-tested.
 *
 * Inputs are git blob SHAs (or null when absent):
 *   - base:    what we recorded at the last sync (the merge base)
 *   - current: what is on disk right now
 *   - remote:  the incoming content from GitHub
 */
final class Resolution {

	// Write outcomes.
	public const NOOP     = 'noop';      // Disk already equals remote; just reconcile manifest.
	public const CLEAN    = 'clean';     // Safe to write; no local divergence to lose.
	public const CONFLICT = 'conflict';  // Local diverged from base AND from remote; remote wins, back up local.

	// Delete outcomes.
	public const DELETE          = 'delete';           // Safe to remove.
	public const DELETE_CONFLICT = 'delete_conflict';  // Local diverged since base; back up, then remove.
	public const DELETE_MISSING  = 'delete_missing';   // Nothing on disk; only drop the manifest row.

	public static function forWrite( ?string $base, ?string $current, string $remote ): string {
		if ( $current === $remote ) {
			return self::NOOP;
		}
		// A file present on disk whose content differs from what we last synced
		// is a local edit; applying remote would lose it.
		if ( $current !== null && $current !== $base ) {
			return self::CONFLICT;
		}
		return self::CLEAN;
	}

	public static function forDelete( ?string $base, ?string $current ): string {
		if ( $current === null ) {
			return self::DELETE_MISSING;
		}
		if ( $base !== null && $current !== $base ) {
			return self::DELETE_CONFLICT;
		}
		return self::DELETE;
	}
}
