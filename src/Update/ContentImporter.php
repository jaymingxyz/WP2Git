<?php

declare(strict_types=1);

namespace WP2Git\Update;

defined( 'ABSPATH' ) || exit;

use WP2Git\Backup\ContentExporter;
use WP2Git\Settings;
use WP_Post;

/**
 * Applies incoming changes to exported content files (wp2git-content/*.md) back
 * onto the database: parses the Markdown + YAML frontmatter and updates — or
 * creates — the corresponding post.
 *
 * Counterpart to ContentExporter. Only runs for post types the user has enabled
 * for content backup, and only as part of the auto-apply pull flow (so the
 * webhook HMAC, branch pin, pusher allowlist, private-repo and backup-only-mode
 * gates all still apply before anything reaches here).
 *
 * Deliberately conservative: it never deletes a post. A removed export file
 * leaves the post intact (the caller re-exports it), so a bad git operation
 * can't wipe your content.
 */
final class ContentImporter {

	public function __construct(
		private readonly Settings $settings,
		private readonly ContentExporter $exporter,
	) {
	}

	/**
	 * Apply one export file to the database.
	 *
	 * @return string One of: 'updated', 'created', 'skipped'.
	 */
	public function apply( string $repoPath, string $content ): string {
		if ( ! $this->exporter->owns( $repoPath ) ) {
			return 'skipped';
		}

		$rest  = substr( ltrim( $repoPath, '/' ), strlen( ContentExporter::PREFIX ) );
		$parts = explode( '/', $rest, 2 );
		if ( count( $parts ) !== 2 || ! preg_match( '/^(\d+)/', $parts[1], $m ) ) {
			return 'skipped';
		}
		$type = $parts[0];
		$id   = (int) $m[1];
		if ( ! in_array( $type, $this->settings->contentTypes(), true ) ) {
			return 'skipped';
		}

		[ $front, $body ] = $this->parse( $content );

		$existing = get_post( $id );
		if ( $existing instanceof WP_Post ) {
			if ( $existing->post_type !== $type ) {
				return 'skipped'; // ID reused by a different type — don't clobber.
			}
			$data = array(
				'ID'           => $id,
				'post_content' => $body,
			);
			if ( isset( $front['title'] ) ) {
				$data['post_title'] = $front['title'];
			}
			if ( isset( $front['excerpt'] ) ) {
				$data['post_excerpt'] = $front['excerpt'];
			}
			$result = wp_update_post( wp_slash( $data ), true );
			return is_wp_error( $result ) ? 'skipped' : 'updated';
		}

		$data = array(
			'import_id'    => $id,
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_content' => $body,
			'post_title'   => (string) ( $front['title'] ?? '' ),
			'post_excerpt' => (string) ( $front['excerpt'] ?? '' ),
			'post_name'    => (string) ( $front['slug'] ?? '' ),
		);
		$author = isset( $front['author'] ) ? get_user_by( 'login', $front['author'] ) : false;
		if ( $author ) {
			$data['post_author'] = $author->ID;
		}
		$result = wp_insert_post( wp_slash( $data ), true );
		return ( is_wp_error( $result ) || $result === 0 ) ? 'skipped' : 'created';
	}

	/**
	 * Split an export file into [frontmatter map, body]. The body has the single
	 * trailing newline that the exporter appended stripped, so it round-trips.
	 *
	 * @return array{0:array<string,string>,1:string}
	 */
	private function parse( string $content ): array {
		if ( ! preg_match( '/^---\n(.*?)\n---\n(.*)$/s', $content, $m ) ) {
			return array( array(), $content );
		}
		$front = array();
		foreach ( explode( "\n", $m[1] ) as $line ) {
			if ( preg_match( '/^(\w+):\s*(.*)$/', $line, $kv ) ) {
				$front[ $kv[1] ] = $this->unscalar( $kv[2] );
			}
		}
		$body = $m[2];
		if ( str_ends_with( $body, "\n" ) ) {
			$body = substr( $body, 0, -1 );
		}
		return array( $front, $body );
	}

	/** Reverse ContentExporter::yamlScalar() — unwrap quotes and unescape. */
	private function unscalar( string $value ): string {
		$value = trim( $value );
		if ( strlen( $value ) >= 2 && str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) ) {
			$value = substr( $value, 1, -1 );
			$value = preg_replace_callback(
				'/\\\\(.)/',
				static fn ( $m ) => match ( $m[1] ) {
					'n'     => "\n",
					't'     => "\t",
					default => $m[1],
				},
				$value
			);
		}
		return $value;
	}
}
