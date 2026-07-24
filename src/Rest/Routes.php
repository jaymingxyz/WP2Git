<?php

declare(strict_types=1);

namespace WP2Git\Rest;

defined( 'ABSPATH' ) || exit;

use WP2Git\Logger;
use WP2Git\Plugin;
use WP2Git\Update\PusherPolicy;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoints: the GitHub webhook receiver and a status probe.
 *
 * The receiver verifies the HMAC signature, confirms the event/branch, drops
 * commits we authored (loop guard) and non-allowlisted pushers, then enqueues a
 * background apply and responds 202 fast so GitHub doesn't time the webhook out.
 */
final class Routes {

	private const NAMESPACE = 'wp2git/v1';

	public function __construct( private readonly Plugin $plugin ) {
	}

	public function register(): void {
		add_action(
			'rest_api_init',
			function (): void {
				register_rest_route(
					self::NAMESPACE,
					'/webhook',
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'handleWebhook' ),
						'permission_callback' => '__return_true', // Auth is the HMAC signature, not a WP cap.
					)
				);

				register_rest_route(
					self::NAMESPACE,
					'/status',
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'handleStatus' ),
						'permission_callback' => static fn () => current_user_can( 'manage_options' ),
					)
				);
			}
		);
	}

	public function handleWebhook( WP_REST_Request $request ): WP_REST_Response {
		$logger = $this->plugin->logger;

		// 1) Kill switch.
		if ( WP2GIT_DISABLE ) {
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => 'disabled',
				),
				503
			);
		}

		// 1b) Backup-only mode: acknowledge but never apply incoming commits.
		if ( ! $this->plugin->settings->autoApply() ) {
			return new WP_REST_Response(
				array(
					'ok'     => true,
					'reason' => 'backup_only',
				),
				202
			);
		}

		// 2) Verify the HMAC signature (constant-time).
		$secret    = $this->plugin->settings->webhookSecret();
		$signature = $request->get_header( 'x-hub-signature-256' );
		$payload   = $request->get_body();

		if ( ! is_string( $secret ) || $secret === '' || ! is_string( $signature ) || $signature === '' ) {
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => 'unsigned',
				),
				401
			);
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			$logger->error( 'Webhook signature mismatch' );
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => 'bad_signature',
				),
				401
			);
		}

		// 3) Only act on push events to the pinned branch.
		$event = $request->get_header( 'x-github-event' );
		if ( $event !== 'push' ) {
			return new WP_REST_Response(
				array(
					'ok'     => true,
					'reason' => 'ignored_event',
				),
				202
			);
		}

		$body        = json_decode( $payload, true );
		$ref         = is_array( $body ) ? ( $body['ref'] ?? '' ) : '';
		$expectedRef = 'refs/heads/' . $this->plugin->settings->branch();
		if ( $ref !== $expectedRef ) {
			return new WP_REST_Response(
				array(
					'ok'     => true,
					'reason' => 'other_branch',
				),
				202
			);
		}

		// 4) Loop guard: ignore commits we authored ourselves.
		$headSha = is_array( $body ) ? ( $body['after'] ?? null ) : null;
		if ( $this->isOwnCommit( $body ) ) {
			return new WP_REST_Response(
				array(
					'ok'     => true,
					'reason' => 'self_authored',
				),
				202
			);
		}

		if ( ! is_string( $headSha ) || $headSha === '' ) {
			return new WP_REST_Response(
				array(
					'ok'     => true,
					'reason' => 'no_head',
				),
				202
			);
		}

		// 5) Pusher allowlist: a compensating control for auto-apply.
		$allowlist = (array) $this->plugin->settings->get( 'pusher_allowlist', array() );
		$sender    = is_array( $body ) ? ( $body['sender']['login'] ?? $body['pusher']['name'] ?? null ) : null;
		if ( ! PusherPolicy::allows( $allowlist, is_string( $sender ) ? $sender : null, $this->commitAuthors( $body ) ) ) {
			$logger->error( 'Push from non-allowlisted user skipped', array( 'sender' => $sender ) );
			return new WP_REST_Response(
				array(
					'ok'     => true,
					'reason' => 'pusher_not_allowed',
				),
				202
			);
		}

		$logger->log(
			Logger::PULL,
			array(
				'phase' => 'received',
				'ref'   => $ref,
			),
			$headSha
		);

		// Enqueue the apply and respond fast so GitHub doesn't time the webhook out.
		$this->plugin->scheduler->enqueuePull( $headSha );

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'queued' => true,
			),
			202
		);
	}

	/**
	 * @param mixed $body Decoded webhook payload.
	 */
	private function isOwnCommit( $body ): bool {
		if ( ! is_array( $body ) || ! isset( $body['head_commit']['message'] ) ) {
			return false;
		}
		// Push commits carry an origin trailer set by the Pusher.
		return str_contains( (string) $body['head_commit']['message'], 'X-WP2Git-Origin: push' );
	}

	/**
	 * Commit author GitHub logins from a push payload (null where unresolved).
	 *
	 * @param mixed $body Decoded webhook payload.
	 * @return list<?string>
	 */
	private function commitAuthors( $body ): array {
		if ( ! is_array( $body ) || ! is_array( $body['commits'] ?? null ) ) {
			return array();
		}
		$out = array();
		foreach ( $body['commits'] as $commit ) {
			$out[] = is_array( $commit ) ? ( $commit['author']['username'] ?? null ) : null;
		}
		return $out;
	}

	public function handleStatus(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'connected'       => $this->plugin->settings->isConnected(),
				'enabled'         => $this->plugin->isEnabled(),
				'auto_apply'      => $this->plugin->settings->autoApply(),
				'database_groups' => $this->plugin->settings->databaseGroups(),
				'state'           => $this->plugin->state->current(),
				'last_synced_sha' => $this->plugin->state->lastSyncedSha(),
				'rate_remaining'  => $this->plugin->github->rateRemaining(),
				'push_progress'   => $this->plugin->pusher->progress(),
			),
			200
		);
	}
}
