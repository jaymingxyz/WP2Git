<?php

declare(strict_types=1);

namespace WP2Git\GitHub;

defined( 'ABSPATH' ) || exit;

use WP2Git\Settings;
use WP_Error;

/**
 * Git Data + Compare + Blobs API wrapper. All push/pull primitives go through
 * here so the Client stays a plain transport.
 */
final class GitData {

	public function __construct(
		private readonly Client $client,
		private readonly Settings $settings,
	) {
	}

	private function repo( string $suffix ): string {
		return sprintf( '/repos/%s/%s%s', $this->settings->repoOwner(), $this->settings->repoName(), $suffix );
	}

	/**
	 * Current commit SHA of a branch, or null if the branch does not exist yet.
	 *
	 * @return string|null|WP_Error
	 */
	public function branchHead( string $branch ) {
		$result = $this->client->request( 'GET', $this->repo( '/git/ref/heads/' . rawurlencode( $branch ) ) );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && ( $data['status'] ?? null ) === 404 ) {
				return null; // Fresh/empty branch.
			}
			return $result;
		}
		return isset( $result['object']['sha'] ) ? (string) $result['object']['sha'] : null;
	}

	/** @return string|WP_Error tree SHA of a commit */
	public function commitTree( string $commitSha ) {
		$result = $this->client->request( 'GET', $this->repo( '/git/commits/' . $commitSha ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (string) ( $result['tree']['sha'] ?? '' );
	}

	/** @return string|WP_Error new blob SHA */
	public function createBlob( string $content ) {
		$result = $this->client->request(
			'POST',
			$this->repo( '/git/blobs' ),
			array(
				// GitHub's blob API expects base64-encoded file content.
				'content'  => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'encoding' => 'base64',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (string) ( $result['sha'] ?? '' );
	}

	/** Raw bytes of a blob (handles base64 up to GitHub's 100 MB limit). */
	public function blobContent( string $blobSha ): string|WP_Error {
		$result = $this->client->request( 'GET', $this->repo( '/git/blobs/' . $blobSha ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$encoding = $result['encoding'] ?? 'base64';
		$content  = (string) ( $result['content'] ?? '' );
		// Decode the file content GitHub returned base64-encoded.
		return $encoding === 'base64' ? (string) base64_decode( $content, false ) : $content; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * @param list<array<string,mixed>> $tree
	 * @return string|WP_Error new tree SHA
	 */
	public function createTree( array $tree, ?string $baseTree ) {
		$payload = array( 'tree' => array_values( $tree ) );
		if ( $baseTree !== null ) {
			$payload['base_tree'] = $baseTree;
		}
		$result = $this->client->request( 'POST', $this->repo( '/git/trees' ), $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (string) ( $result['sha'] ?? '' );
	}

	/**
	 * @param list<string> $parents
	 * @return string|WP_Error new commit SHA
	 */
	public function createCommit( string $message, string $treeSha, array $parents ) {
		$result = $this->client->request(
			'POST',
			$this->repo( '/git/commits' ),
			array(
				'message' => $message,
				'tree'    => $treeSha,
				'parents' => array_values( $parents ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (string) ( $result['sha'] ?? '' );
	}

	/** Move (or create) the branch ref to a commit. */
	public function setBranchHead( string $branch, string $commitSha, bool $create ): bool|WP_Error {
		if ( $create ) {
			$result = $this->client->request(
				'POST',
				$this->repo( '/git/refs' ),
				array(
					'ref' => 'refs/heads/' . $branch,
					'sha' => $commitSha,
				)
			);
		} else {
			$result = $this->client->request(
				'PATCH',
				$this->repo( '/git/refs/heads/' . rawurlencode( $branch ) ),
				array(
					'sha'   => $commitSha,
					'force' => false,
				)
			);
		}
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Diff two commits. Returns a normalized change list.
	 *
	 * @return list<array{path:string,status:string,sha:?string,previous:?string}>|WP_Error
	 */
	public function compare( string $base, string $head ) {
		$result = $this->client->request( 'GET', $this->repo( "/compare/{$base}...{$head}" ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$files = is_array( $result['files'] ?? null ) ? $result['files'] : array();
		$out   = array();
		foreach ( $files as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['filename'] ) ) {
				continue;
			}
			$out[] = array(
				'path'     => (string) $f['filename'],
				'status'   => (string) ( $f['status'] ?? 'modified' ),
				'sha'      => isset( $f['sha'] ) ? (string) $f['sha'] : null,
				'previous' => isset( $f['previous_filename'] ) ? (string) $f['previous_filename'] : null,
			);
		}
		return $out;
	}

	/**
	 * Full recursive tree at a commit — used for the first-ever import when
	 * there is no base to compare against.
	 *
	 * @return list<array{path:string,status:string,sha:?string,previous:?string}>|WP_Error
	 */
	public function fullTree( string $commitSha ) {
		$treeSha = $this->commitTree( $commitSha );
		if ( is_wp_error( $treeSha ) ) {
			return $treeSha;
		}
		$result = $this->client->request( 'GET', $this->repo( '/git/trees/' . $treeSha . '?recursive=1' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$out = array();
		foreach ( ( $result['tree'] ?? array() ) as $node ) {
			if ( is_array( $node ) && ( $node['type'] ?? '' ) === 'blob' ) {
				$out[] = array(
					'path'     => (string) $node['path'],
					'status'   => 'added',
					'sha'      => isset( $node['sha'] ) ? (string) $node['sha'] : null,
					'previous' => null,
				);
			}
		}
		return $out;
	}
}
