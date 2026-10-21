<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets at rest with libsodium (XSalsa20-Poly1305).
 *
 * Key material, in order of preference:
 *   1. YAPR_ENCRYPTION_KEY constant in wp-config.php (recommended: 32+ random characters)
 *   2. WordPress's AUTH salt (wp_salt('auth'))
 * The key never lives in the database, so a database dump alone does not reveal the token.
 */
final class Crypto {

	private const PREFIX = 'yapr1:';

	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	public static function key_source(): string {
		return defined( 'YAPR_ENCRYPTION_KEY' ) && '' !== (string) YAPR_ENCRYPTION_KEY ? 'wp-config constant' : 'WordPress salts';
	}

	private static function key(): string {
		$material = defined( 'YAPR_ENCRYPTION_KEY' ) && '' !== (string) YAPR_ENCRYPTION_KEY
			? (string) YAPR_ENCRYPTION_KEY
			: wp_salt( 'auth' );
		return hash( 'sha256', 'yapr-secret-v1|' . $material, true );
	}

	public static function encrypt( string $plain ): string {
		if ( ! self::available() ) {
			throw new \RuntimeException( 'The sodium PHP extension is required to store credentials.' );
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding of ciphertext.
	}

	public static function decrypt( string $stored ): ?string {
		if ( ! self::available() || 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding of ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return false === $plain ? null : $plain;
	}
}
