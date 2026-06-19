<?php

declare(strict_types=1);

namespace WP2Git\GitHub;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Supplies the bearer token the HTTP client authenticates with. Implementations
 * isolate the auth method (fine-grained PAT vs. GitHub App installation token)
 * so the rest of the plugin is auth-agnostic.
 */
interface Credential {

	/** 'pat' or 'app'. */
	public function method(): string;

	/** Whether enough has been configured to attempt authentication. */
	public function isConfigured(): bool;

	/** A bearer token for the REST API, or WP_Error. May be short-lived. */
	public function bearerToken(): string|WP_Error;
}
