<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/**
 * Persistent cache of NON-identifying aggregate payloads (custom table).
 * Individual calls and per-volunteer data are never stored here.
 *
 * Keys combine: visibility (public|private), endpoint, sorted Area ids, period/range parameters,
 * timezone and the API metric version — so a public payload can never be served for a private
 * request, nor one Area set for another. Authorization is checked by callers BEFORE get().
 */
final class Cache {

	public const VERSION = 'v1';

	/** @param int[] $areas */
	public static function key( string $visibility, string $endpoint, array $areas, array $params ): string {
		sort( $areas );
		ksort( $params );
		return hash( 'sha256', wp_json_encode( array( self::VERSION, $visibility, $endpoint, $areas, $params ) ) );
	}

	/** @return array{payload: array, fetched_at: int, expires_at: int}|null */
	public static function get( string $key ): ?array {
		global $wpdb;
		$table = Installer::table( 'aggregate_cache' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT payload, fetched_at, expires_at FROM {$table} WHERE cache_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return null;
		}
		$payload = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		return array(
			'payload'    => $payload,
			'fetched_at' => (int) strtotime( $row['fetched_at'] . ' UTC' ),
			'expires_at' => (int) strtotime( $row['expires_at'] . ' UTC' ),
		);
	}

	/** @param int[] $areas */
	public static function put( string $key, string $visibility, string $endpoint, array $areas, string $period, array $payload, int $ttl ): void {
		global $wpdb;
		sort( $areas );
		$wpdb->replace(
			Installer::table( 'aggregate_cache' ),
			array(
				'cache_key'  => $key,
				'visibility' => 'public' === $visibility ? 'public' : 'private',
				'endpoint'   => substr( $endpoint, 0, 40 ),
				'areas_key'  => substr( implode( ',', $areas ), 0, 255 ),
				'period'     => substr( $period, 0, 40 ),
				'payload'    => wp_json_encode( $payload ),
				'fetched_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + max( 60, $ttl ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function clear( ?string $visibility = null ): int {
		global $wpdb;
		$table = Installer::table( 'aggregate_cache' );
		if ( null === $visibility ) {
			return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE visibility = %s", $visibility ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Remove rows older than the retention window. */
	public static function prune(): int {
		global $wpdb;
		$table = Installer::table( 'aggregate_cache' );
		$days  = max( 1, (int) Settings::get( 'cache_retention_days' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE fetched_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function stats(): array {
		global $wpdb;
		$table = Installer::table( 'aggregate_cache' );
		$row   = $wpdb->get_row( "SELECT COUNT(*) AS n, SUM(visibility = 'public') AS pub, MIN(fetched_at) AS oldest, MAX(fetched_at) AS newest FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'records' => (int) ( $row['n'] ?? 0 ),
			'public'  => (int) ( $row['pub'] ?? 0 ),
			'oldest'  => ! empty( $row['oldest'] ) ? (int) strtotime( $row['oldest'] . ' UTC' ) : null,
			'newest'  => ! empty( $row['newest'] ) ? (int) strtotime( $row['newest'] . ' UTC' ) : null,
		);
	}
}
