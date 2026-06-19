<?php

declare(strict_types=1);

namespace WP2Git;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated symmetric encryption for the GitHub token at rest (AES-256-GCM).
 *
 * The key comes from the WP2GIT_KEY constant if defined (recommended: set it in
 * wp-config.php so the key lives outside the database). It falls back to WP's
 * AUTH/SECURE_AUTH salts, which keeps the token out of plaintext but offers
 * weaker separation since those salts are also in wp-config.
 */
final class Crypto {

	private const CIPHER = 'aes-256-gcm';

	private string $key;

	public function __construct( ?string $key = null ) {
		$this->key = $key ?? self::deriveKey();
	}

	private static function deriveKey(): string {
		if ( defined( 'WP2GIT_KEY' ) && is_string( WP2GIT_KEY ) && WP2GIT_KEY !== '' ) {
			$material = (string) WP2GIT_KEY;
		} else {
			$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
				. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
				. ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' );
		}
		// 32 raw bytes for AES-256.
		return hash( 'sha256', 'wp2git|' . $material, true );
	}

	/** Returns a base64 string: iv|tag|ciphertext. */
	public function encrypt( string $plaintext ): string {
		$ivLen      = openssl_cipher_iv_length( self::CIPHER ) ?: 12;
		$iv         = random_bytes( $ivLen );
		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		if ( $ciphertext === false ) {
			throw new \RuntimeException( 'WP2Git: encryption failed.' );
		}
		// Encode the binary ciphertext for storage; not code obfuscation.
		return base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** Returns the plaintext, or null if the blob is corrupt / key changed. */
	public function decrypt( string $blob ): ?string {
		$raw = base64_decode( $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own stored ciphertext.
		if ( $raw === false ) {
			return null;
		}
		$ivLen  = openssl_cipher_iv_length( self::CIPHER ) ?: 12;
		$tagLen = 16;
		if ( strlen( $raw ) < $ivLen + $tagLen ) {
			return null;
		}
		$iv         = substr( $raw, 0, $ivLen );
		$tag        = substr( $raw, $ivLen, $tagLen );
		$ciphertext = substr( $raw, $ivLen + $tagLen );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$this->key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		return $plaintext === false ? null : $plaintext;
	}
}
