<?php

declare(strict_types=1);

namespace WP2Git\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Accessor for the file manifest: one row per tracked file recording the last
 * known hashes. This is the merge base for diffs (push) and three-way conflict
 * detection (pull), and the mechanism that prevents sync loops.
 */
final class Manifest {
	/*
	 * Data-access layer for our own custom wp2git_manifest table. Direct $wpdb
	 * queries are required (no core API exists for it); the interpolated table
	 * name is internal, never user input; and all values are parameterised via
	 * $wpdb->prepare() or $wpdb->replace()/delete() format arrays. Per-row caching
	 * would fight the freshness this sync index needs.
	 */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	private function table(): string {
		return \WP2Git\Database::table( 'manifest' );
	}

	/**
	 * @return array<string,array{content_type:string,local_hash:?string,blob_sha:?string,last_sync_sha:?string}>
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT path, content_type, local_hash, blob_sha, last_sync_sha FROM {$this->table()}", ARRAY_A );
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['path'] ] = array(
				'content_type'  => (string) $row['content_type'],
				'local_hash'    => $row['local_hash'],
				'blob_sha'      => $row['blob_sha'],
				'last_sync_sha' => $row['last_sync_sha'],
			);
		}
		return $map;
	}

	/** @return array<string,mixed>|null */
	public function get( string $path ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE path = %s", $path ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function upsert( string $path, string $contentType, ?string $localHash, ?string $blobSha, ?string $lastSyncSha ): void {
		global $wpdb;
		$wpdb->replace(
			$this->table(),
			array(
				'path'          => $path,
				'content_type'  => $contentType,
				'local_hash'    => $localHash,
				'blob_sha'      => $blobSha,
				'last_sync_sha' => $lastSyncSha,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function delete( string $path ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'path' => $path ), array( '%s' ) );
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
