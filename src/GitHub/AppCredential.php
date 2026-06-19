<?php

declare(strict_types=1);

namespace WP2Git\GitHub;

defined( 'ABSPATH' ) || exit;

use WP2Git\Context;
use WP2Git\Crypto;
use WP2Git\Settings;
use WP_Error;

/**
 * GitHub App authentication: signs a short-lived RS256 JWT with the app's
 * private key, exchanges it for an installation access token, and caches that
 * token until shortly before it expires. No third-party JWT library required.
 */
final class AppCredential implements Credential {

	private const CACHE = 'wp2git_app_token';

	public function __construct(
		private readonly string $appId,
		private readonly string $installationId,
		private readonly string $privateKeyPem,
	) {
	}

	public static function fromSettings( Settings $settings, Crypto $crypto ): self {
		$encKey = (string) $settings->get( 'app_key', '' );
		$pem    = $encKey !== '' ? ( $crypto->decrypt( $encKey ) ?? '' ) : '';
		return new self(
			(string) $settings->get( 'app_id', '' ),
			(string) $settings->get( 'app_installation_id', '' ),
			$pem
		);
	}

	public function method(): string {
		return 'app';
	}

	public function isConfigured(): bool {
		return $this->appId !== '' && $this->installationId !== '' && $this->privateKeyPem !== '';
	}

	public function bearerToken(): string|WP_Error {
		if ( ! $this->isConfigured() ) {
			return new WP_Error( 'wp2git_app_unconfigured', __( 'GitHub App is not fully configured.', 'wp2git' ) );
		}

		$cached = $this->cacheGet();
		if ( is_array( $cached ) && isset( $cached['token'], $cached['expires'] ) && (int) $cached['expires'] > time() + 60 ) {
			return (string) $cached['token'];
		}

		$jwt = $this->makeJwt();
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_post(
			"https://api.github.com/app/installations/{$this->installationId}/access_tokens",
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization'        => 'Bearer ' . $jwt,
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'WP2Git/' . WP2GIT_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $body ) || ! isset( $body['token'] ) ) {
			$message = is_array( $body ) && isset( $body['message'] ) ? (string) $body['message'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'wp2git_app_token', $message );
		}

		$token   = (string) $body['token'];
		$expires = isset( $body['expires_at'] ) ? (int) strtotime( (string) $body['expires_at'] ) : time() + 3000;
		$this->cacheSet(
			array(
				'token'   => $token,
				'expires' => $expires,
			),
			max( 60, $expires - time() - 60 )
		);

		return $token;
	}

	// --- JWT --------------------------------------------------------------

	private function makeJwt(): string|WP_Error {
		$key = openssl_pkey_get_private( $this->privateKeyPem );
		if ( $key === false ) {
			return new WP_Error( 'wp2git_app_key', __( 'Invalid GitHub App private key (expected a PEM).', 'wp2git' ) );
		}

		$now    = time();
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		// iat backdated for clock skew; exp within GitHub's 10-minute ceiling.
		$claims = array(
			'iat' => $now - 60,
			'exp' => $now + 540,
			'iss' => $this->appId,
		);

		$segments  = array(
			self::base64url( (string) wp_json_encode( $header ) ),
			self::base64url( (string) wp_json_encode( $claims ) ),
		);
		$signature = '';
		if ( ! openssl_sign( implode( '.', $segments ), $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'wp2git_app_sign', __( 'Could not sign the GitHub App JWT.', 'wp2git' ) );
		}
		$segments[] = self::base64url( $signature );

		return implode( '.', $segments );
	}

	private static function base64url( string $data ): string {
		// base64url encoding for JWT segments (RFC 7515); not obfuscation.
		return str_replace( array( '+', '/' ), array( '-', '_' ), rtrim( base64_encode( $data ), '=' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	// --- Token cache ------------------------------------------------------

	private function cacheGet(): mixed {
		return Context::networkScoped() ? get_site_transient( self::CACHE ) : get_transient( self::CACHE );
	}

	private function cacheSet( array $value, int $ttl ): void {
		if ( Context::networkScoped() ) {
			set_site_transient( self::CACHE, $value, $ttl );
		} else {
			set_transient( self::CACHE, $value, $ttl );
		}
	}

	public static function flushCache(): void {
		delete_transient( self::CACHE );
		delete_site_transient( self::CACHE );
	}
}
