<?php

declare(strict_types=1);

namespace WP2Git\Backup;

defined( 'ABSPATH' ) || exit;

use WP2Git\Settings;
use WP2Git\Sync\Paths;
use WP_Post;

/**
 * Exports database content (posts, pages, and any other enabled post types) to
 * readable Markdown-with-frontmatter files inside the repo, so they back up to
 * GitHub alongside wp-content.
 *
 * This is a one-way backup: exports live under a reserved repo prefix that is
 * outside the in-scope wp-content dirs, so the pull safety gate never writes
 * them back to disk. (Restoring database content from GitHub is deliberately out
 * of scope — it would mean writing arbitrary content into wp_posts.)
 *
 * It presents the same repoPath => git-blob-sha shape as the file scanner, so
 * the incremental Pusher diffs, chunks, and commits it with no special casing.
 */
final class ContentExporter {

	/** Reserved repo path for exported content. Outside every in-scope dir. */
	public const PREFIX = 'wp2git-content/';

	/** Post types this feature can export (kept deliberately small for v1). */
	public const SUPPORTED = array( 'post', 'page' );

	public function __construct( private readonly Settings $settings ) {
	}

	/** @return list<string> enabled post types, e.g. ['post','page'] */
	public function enabledTypes(): array {
		$stored = $this->settings->get( 'content_types', array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_filter( self::SUPPORTED, static fn ( $t ) => ! empty( $stored[ $t ] ) ) );
	}

	public function isEnabled(): bool {
		return $this->enabledTypes() !== array();
	}

	/** Whether a repo path is an exported-content path managed by this class. */
	public function owns( string $repoPath ): bool {
		return str_starts_with( ltrim( $repoPath, '/' ), self::PREFIX );
	}

	/** Manifest content-type for an owned path, e.g. "post" or "page". */
	public function contentType( string $repoPath ): string {
		$rest  = substr( ltrim( $repoPath, '/' ), strlen( self::PREFIX ) );
		$slash = strpos( $rest, '/' );
		return $slash === false ? 'content' : substr( $rest, 0, $slash );
	}

	/**
	 * All exportable content as repoPath => git blob sha, or empty when the
	 * feature is off. Matches FileScanner::scan() so the Pusher can union them.
	 *
	 * @return array<string,string>
	 */
	public function scan(): array {
		$types = $this->enabledTypes();
		if ( $types === array() ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		$map = array();
		foreach ( $posts as $post ) {
			$map[ $this->pathFor( $post ) ] = Paths::gitBlobSha( $this->renderPost( $post ) );
		}
		return $map;
	}

	/**
	 * Regenerate the file content for a specific owned path, or null if the post
	 * no longer exists / is no longer eligible (so the Pusher drops it).
	 */
	public function render( string $repoPath ): ?string {
		if ( ! $this->owns( $repoPath ) ) {
			return null;
		}
		$rest  = substr( ltrim( $repoPath, '/' ), strlen( self::PREFIX ) );
		$parts = explode( '/', $rest, 2 );
		if ( count( $parts ) !== 2 || ! preg_match( '/^(\d+)/', $parts[1], $m ) ) {
			return null;
		}
		$type = $parts[0];
		if ( ! in_array( $type, $this->enabledTypes(), true ) ) {
			return null;
		}

		$post = get_post( (int) $m[1] );
		if ( ! $post instanceof WP_Post
			|| $post->post_type !== $type
			|| $post->post_status !== 'publish' ) {
			return null;
		}
		return $this->renderPost( $post );
	}

	private function pathFor( WP_Post $post ): string {
		$slug = $post->post_name !== '' ? $post->post_name : 'untitled';
		return self::PREFIX . $post->post_type . '/' . $post->ID . '-' . $slug . '.md';
	}

	/**
	 * Deterministic Markdown: stable YAML frontmatter then the raw post body, so
	 * the same post always hashes to the same blob (no spurious diffs).
	 */
	private function renderPost( WP_Post $post ): string {
		$front = array(
			'id'           => (string) $post->ID,
			'type'         => $post->post_type,
			'slug'         => $post->post_name,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'date_gmt'     => $post->post_date_gmt,
			'modified_gmt' => $post->post_modified_gmt,
			'author'       => (string) get_the_author_meta( 'user_login', (int) $post->post_author ),
			'excerpt'      => $post->post_excerpt,
		);

		$lines = array( '---' );
		foreach ( $front as $key => $value ) {
			$lines[] = $key . ': ' . $this->yamlScalar( (string) $value );
		}
		$lines[] = '---';
		$lines[] = '';

		return implode( "\n", $lines ) . (string) $post->post_content . "\n";
	}

	/** Always emit a double-quoted YAML scalar with escapes — deterministic and safe. */
	private function yamlScalar( string $value ): string {
		$value = str_replace(
			array( '\\', '"', "\r\n", "\n", "\r", "\t" ),
			array( '\\\\', '\\"', '\\n', '\\n', '\\n', '\\t' ),
			$value
		);
		return '"' . $value . '"';
	}
}
