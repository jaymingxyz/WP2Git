<?php

declare(strict_types=1);

namespace WP2Git\GitHub;

defined( 'ABSPATH' ) || exit;

use WP2Git\Logger;
use WP_Error;

/**
 * Thin GitHub REST client built on the WP HTTP API (no Guzzle to bundle).
 * Rate-limit aware: surfaces remaining budget and backs off on secondary limits.
 * Authentication is delegated to a Credential (PAT or GitHub App).
 */
final class Client {

	private const API_BASE    = 'https://api.github.com';
	private const API_VERSION = '2022-11-28';

	private int $rateRemaining = -1;
	private int $rateReset     = 0;

	public function __construct(
		private readonly Credential $credential,
		private readonly Logger $logger,
	) {
	}

	public function rateRemaining(): int {
		return $this->rateRemaining;
	}

	public function rateReset(): int {
		return $this->rateReset;
	}

	public function authMethod(): string {
		return $this->credential->method();
	}

	/**
	 * Perform an authenticated request.
	 *
	 * @param array<string,mixed>|null $body
	 * @return array<string,mixed>|list<mixed>|WP_Error  Decoded JSON, or WP_Error.
	 */
	public function request( string $method, string $path, ?array $body = null, ?string $tokenOverride = null ) {
		$token = $tokenOverride ?? $this->credential->bearerToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Respect a known reset window before firing into a 403 wall.
		if ( $this->rateRemaining === 0 && $this->rateReset > time() ) {
			return new WP_Error(
				'wp2git_rate_limited',
				sprintf(
					/* translators: %d: seconds until reset. */
					__( 'GitHub rate limit reached; retry in %d seconds.', 'wp2git' ),
					$this->rateReset - time()
				)
			);
		}

		$url = str_starts_with( $path, 'http' ) ? $path : self::API_BASE . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'        => 'Bearer ' . $token,
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => self::API_VERSION,
				'User-Agent'           => 'WP2Git/' . WP2GIT_VERSION,
			),
		);
		if ( $body !== null ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'GitHub request transport error',
				array(
					'path'   => $path,
					'detail' => $response->get_error_message(),
				)
			);
			return $response;
		}

		$this->captureRateHeaders( $response );

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = $raw !== '' ? json_decode( $raw, true ) : array();

		if ( $code >= 400 ) {
			$message = is_array( $decoded ) && isset( $decoded['message'] )
				? (string) $decoded['message']
				: sprintf( 'HTTP %d', $code );
			$this->logger->error(
				'GitHub API error',
				array(
					'path'    => $path,
					'code'    => $code,
					'message' => $message,
				)
			);
			return new WP_Error( 'wp2git_github_' . $code, $message, array( 'status' => $code ) );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	private function captureRateHeaders( $response ): void {
		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$reset     = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
		if ( $remaining !== '' ) {
			$this->rateRemaining = (int) $remaining;
		}
		if ( $reset !== '' ) {
			$this->rateReset = (int) $reset;
		}
	}

	// --- Connection helpers used by the connect wizard --------------------

	/**
	 * Validate a token and return the authenticated user, or WP_Error.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function validateToken( string $token ) {
		return $this->request( 'GET', '/user', null, $token );
	}

	/**
	 * List repositories the token can access (most recently pushed first).
	 *
	 * @return list<array{full_name:string,private:bool,default_branch:string}>|WP_Error
	 */
	public function listRepositories( ?string $token = null ) {
		if ( $this->credential->method() === 'app' ) {
			$result = $this->request( 'GET', '/installation/repositories?per_page=100', null, $token );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = is_array( $result['repositories'] ?? null ) ? $result['repositories'] : array();
		} else {
			$result = $this->request( 'GET', '/user/repos?per_page=100&sort=pushed&affiliation=owner,collaborator,organization_member', null, $token );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$repos = array();
		foreach ( $result as $repo ) {
			if ( ! is_array( $repo ) || ! isset( $repo['full_name'] ) ) {
				continue;
			}
			$repos[] = array(
				'full_name'      => (string) $repo['full_name'],
				'private'        => (bool) ( $repo['private'] ?? false ),
				'default_branch' => (string) ( $repo['default_branch'] ?? 'main' ),
			);
		}
		return $repos;
	}

	/**
	 * @return list<string>|WP_Error  Branch names.
	 */
	public function listBranches( string $owner, string $repo, ?string $token = null ) {
		$result = $this->request( 'GET', "/repos/{$owner}/{$repo}/branches?per_page=100", null, $token );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$names = array();
		foreach ( $result as $branch ) {
			if ( is_array( $branch ) && isset( $branch['name'] ) ) {
				$names[] = (string) $branch['name'];
			}
		}
		return $names;
	}

	/**
	 * Fetch a single repo's metadata (used to enforce private-repo policy).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function getRepository( string $owner, string $repo, ?string $token = null ) {
		return $this->request( 'GET', "/repos/{$owner}/{$repo}", null, $token );
	}
}
