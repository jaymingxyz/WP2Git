<?php

declare(strict_types=1);

namespace WP2Git\GitHub;

defined( 'ABSPATH' ) || exit;

use WP2Git\Settings;
use WP_Error;

/**
 * Provisions and manages the repository push webhook that drives pulls.
 */
final class Webhooks {

	public function __construct(
		private readonly Client $github,
		private readonly Settings $settings,
	) {
	}

	/** The REST URL GitHub will POST push events to. */
	public static function receiverUrl(): string {
		return rest_url( 'wp2git/v1/webhook' );
	}

	/**
	 * Create (or re-create) the push webhook on the connected repo. Stores the
	 * hook id and the shared secret used for HMAC verification.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function provision() {
		$owner = $this->settings->repoOwner();
		$repo  = $this->settings->repoName();
		if ( $owner === null || $repo === null ) {
			return new WP_Error( 'wp2git_not_connected', __( 'Connect a repository first.', 'wp2git' ) );
		}

		$secret = $this->settings->webhookSecret();
		if ( ! is_string( $secret ) || $secret === '' ) {
			$secret = wp_generate_password( 40, false );
			$this->settings->set( 'webhook_secret', $secret );
		}

		$payload = array(
			'name'   => 'web',
			'active' => true,
			'events' => array( 'push' ),
			'config' => array(
				'url'          => self::receiverUrl(),
				'content_type' => 'json',
				'secret'       => $secret,
				'insecure_ssl' => '0',
			),
		);

		$result = $this->github->request( 'POST', "/repos/{$owner}/{$repo}/hooks", $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result['id'] ) ) {
			$this->settings->set( 'webhook_id', (int) $result['id'] );
		}
		return $result;
	}

	/** Remove the webhook from GitHub (best effort). */
	public function deprovision(): void {
		$owner = $this->settings->repoOwner();
		$repo  = $this->settings->repoName();
		$id    = $this->settings->get( 'webhook_id' );
		if ( $owner === null || $repo === null || ! is_numeric( $id ) ) {
			return;
		}
		$this->github->request( 'DELETE', "/repos/{$owner}/{$repo}/hooks/{$id}" );
		$this->settings->delete( 'webhook_id' );
	}
}
