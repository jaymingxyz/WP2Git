<?php

declare(strict_types=1);

namespace WP2Git\GitHub;

defined( 'ABSPATH' ) || exit;

use WP2Git\Crypto;
use WP2Git\Settings;
use WP_Error;

/**
 * Fine-grained Personal Access Token, encrypted at rest in settings.
 */
final class PatCredential implements Credential {

	public function __construct(
		private readonly Settings $settings,
		private readonly Crypto $crypto,
	) {
	}

	public function method(): string {
		return 'pat';
	}

	public function isConfigured(): bool {
		return $this->settings->encryptedToken() !== null;
	}

	public function bearerToken(): string|WP_Error {
		$enc = $this->settings->encryptedToken();
		if ( $enc === null ) {
			return new WP_Error( 'wp2git_no_token', __( 'No GitHub token configured.', 'wp2git' ) );
		}
		$token = $this->crypto->decrypt( $enc );
		if ( $token === null ) {
			return new WP_Error( 'wp2git_token_decrypt', __( 'Stored token could not be decrypted (was WP2GIT_KEY or the WP salts changed?).', 'wp2git' ) );
		}
		return $token;
	}
}
