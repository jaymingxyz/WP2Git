<?php

declare(strict_types=1);

namespace WP2Git;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessor over wp_options. All persistent config lives behind this class
 * so storage details (and future migration to a GitHub App) stay isolated.
 */
final class Settings {

	private const OPTION = 'wp2git_settings';

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	/** @return array<string,mixed> */
	private function all(): array {
		if ( $this->cache === null ) {
			$stored      = Options::get( self::OPTION, array() );
			$this->cache = is_array( $stored ) ? $stored : array();
		}
		return $this->cache;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->all()[ $key ] ?? $default;
	}

	public function set( string $key, mixed $value ): void {
		$all         = $this->all();
		$all[ $key ] = $value;
		$this->cache = $all;
		Options::set( self::OPTION, $all );
	}

	/** @param array<string,mixed> $values */
	public function merge( array $values ): void {
		$this->cache = array_merge( $this->all(), $values );
		Options::set( self::OPTION, $this->cache );
	}

	public function delete( string $key ): void {
		$all = $this->all();
		unset( $all[ $key ] );
		$this->cache = $all;
		Options::set( self::OPTION, $all );
	}

	// --- Convenience accessors -------------------------------------------

	/** Encrypted token blob (decrypt via Crypto). */
	public function encryptedToken(): ?string {
		$t = $this->get( 'token' );
		return is_string( $t ) && $t !== '' ? $t : null;
	}

	public function repoOwner(): ?string {
		return $this->get( 'owner' );
	}

	public function repoName(): ?string {
		return $this->get( 'repo' );
	}

	public function branch(): string {
		return (string) $this->get( 'branch', 'main' );
	}

	public function webhookSecret(): ?string {
		return $this->get( 'webhook_secret' );
	}

	public function authMethod(): string {
		return (string) $this->get( 'auth_method', 'pat' );
	}

	/**
	 * Whether incoming commits are applied to this site. When false the plugin
	 * is backup-only: it still pushes wp-content to GitHub but never pulls or
	 * writes anything from GitHub back to the live site. Defaults to true so
	 * existing two-way installs keep their behavior.
	 */
	public function autoApply(): bool {
		return (bool) $this->get( 'auto_apply', true );
	}

	/** Whether the chosen auth method has all its credentials stored. */
	public function hasCredentials(): bool {
		if ( $this->authMethod() === 'app' ) {
			return (bool) $this->get( 'app_id' )
				&& (bool) $this->get( 'app_installation_id' )
				&& (bool) $this->get( 'app_key' );
		}
		return $this->encryptedToken() !== null;
	}

	/** A connection is complete once we have credentials, a repo and a branch. */
	public function isConnected(): bool {
		return $this->hasCredentials()
			&& $this->repoOwner() !== null
			&& $this->repoName() !== null;
	}
}
