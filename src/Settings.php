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
	 * Database post types to back up to GitHub (e.g. ['post','page']). Empty by
	 * default — the plugin stays files-only unless the user opts in.
	 *
	 * @return list<string>
	 */
	public function contentTypes(): array {
		$stored = $this->get( 'content_types', array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_filter( array( 'post', 'page' ), static fn ( $t ) => ! empty( $stored[ $t ] ) ) );
	}

	/**
	 * Structured database snapshot groups to back up. These are deliberately
	 * opt-in because templates, widgets and snippets can contain private data or
	 * executable code.
	 *
	 * @return list<string>
	 */
	public function databaseGroups(): array {
		$stored = $this->get( 'database_groups', array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values(
			array_filter(
				\WP2Git\Backup\DatabaseExporter::GROUPS,
				static fn ( $group ) => ! empty( $stored[ $group ] ) || in_array( $group, $stored, true )
			)
		);
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
