<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/**
 * Report data service. Dashboards read aggregates from the cache; the API is contacted only when a
 * cache entry is missing or expired, and then only once at a time per entry (stampede lock).
 * If the API fails, the last good copy is served with a stale flag instead of an error.
 */
final class Reports {

	/** Endpoints whose payloads are aggregate (cacheable). */
	public const AGGREGATE = array( 'summary', 'trends', 'distributions', 'area-dashboard', 'meeting-lookups', 'meditations', 'sms-summary', 'areas' );

	/**
	 * @param int[] $areas already authorized
	 * @param array<string, scalar> $params validated report parameters
	 * @return array{data: array, meta: array, fetched_at: int, stale: bool, source: string, error?: string}|\WP_Error
	 */
	public static function aggregate( string $endpoint, array $areas, array $params, string $visibility = 'private', bool $force = false ) {
		if ( ! in_array( $endpoint, self::AGGREGATE, true ) ) {
			return new \WP_Error( 'yapr_endpoint', 'Not an aggregate endpoint.' );
		}
		$params = self::normalise_params( $params );
		$key    = Cache::key( $visibility, $endpoint, $areas, $params );
		$cached = Cache::get( $key );
		$now    = time();

		if ( ! $force && $cached && $cached['expires_at'] > $now ) {
			return self::wrap( $cached['payload'], $cached['fetched_at'], 'cache', false );
		}

		$lock = 'yapr_lock_' . substr( $key, 0, 32 );
		if ( ! $force && $cached && get_transient( $lock ) ) {
			// Someone else is refreshing; serve what we have.
			return self::wrap( $cached['payload'], $cached['fetched_at'], 'cache', self::is_stale( $cached['fetched_at'], $params ) );
		}
		set_transient( $lock, 1, 60 );

		$query = $params;
		if ( $areas ) {
			$query['areas'] = implode( ',', $areas );
		}
		$response = ApiClient::get( $endpoint, $query );
		delete_transient( $lock );

		if ( is_wp_error( $response ) ) {
			if ( $cached ) {
				$out          = self::wrap( $cached['payload'], $cached['fetched_at'], 'cache', true );
				$out['error'] = $response->get_error_message();
				return $out;
			}
			return $response;
		}

		$payload = array( 'data' => $response['data'] ?? array(), 'meta' => $response['meta'] ?? array() );
		if ( 'public' === $visibility ) {
			$payload = self::public_filter( $endpoint, $payload );
		}
		Cache::put( $key, $visibility, $endpoint, $areas, (string) ( $params['period'] ?? 'custom' ), $payload, self::ttl( $params ) );
		return self::wrap( $payload, $now, 'api', false );
	}

	/**
	 * Person-level data (calls, volunteers) is always fetched live and never stored.
	 *
	 * @param int[] $areas already authorized
	 * @return array|\WP_Error
	 */
	public static function live( string $endpoint, array $areas, array $params ) {
		$query = self::normalise_params( $params );
		if ( $areas ) {
			$query['areas'] = implode( ',', $areas );
		}
		return ApiClient::get( $endpoint, $query );
	}

	/** Fixed recent windows refresh often; closed historical windows rarely. */
	public static function ttl( array $params ): int {
		$recent  = max( 5, (int) Settings::get( 'interval_recent' ) ) * MINUTE_IN_SECONDS;
		$history = max( 30, (int) Settings::get( 'interval_history' ) ) * MINUTE_IN_SECONDS;
		$period  = (string) ( $params['period'] ?? '' );
		if ( in_array( $period, array( 'yesterday', 'last_week', 'last_month', 'last_quarter', 'last_year' ), true ) ) {
			return $history;
		}
		if ( ! empty( $params['end_date'] ) ) {
			$today = wp_date( 'Y-m-d', null, new \DateTimeZone( (string) ( $params['timezone'] ?? Settings::timezone() ) ) );
			if ( (string) $params['end_date'] < $today ) {
				return $history;
			}
		}
		return $recent;
	}

	public static function is_stale( int $fetched_at, array $params ): bool {
		$factor = max( 2, (int) Settings::get( 'stale_factor' ) );
		return ( time() - $fetched_at ) > self::ttl( $params ) * $factor;
	}

	/** Keep only API-understood keys, add the reporting timezone. */
	public static function normalise_params( array $params ): array {
		$out = array();
		foreach ( $params as $k => $v ) {
			if ( in_array( $k, ApiClient::QUERY_KEYS, true ) && 'areas' !== $k && null !== $v && '' !== $v ) {
				$out[ $k ] = is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v;
			}
		}
		if ( empty( $out['timezone'] ) ) {
			$out['timezone'] = Settings::timezone();
		}
		return $out;
	}

	private static function wrap( array $payload, int $fetched_at, string $source, bool $stale ): array {
		return array(
			'data'       => $payload['data'] ?? array(),
			'meta'       => $payload['meta'] ?? array(),
			'fetched_at' => $fetched_at,
			'stale'      => $stale,
			'source'     => $source,
		);
	}

	/** Public payloads keep only the dashboard metrics; labels and meta that describe scope stay. */
	private static function public_filter( string $endpoint, array $payload ): array {
		if ( 'area-dashboard' !== $endpoint ) {
			return array( 'data' => array(), 'meta' => array() );
		}
		$keep = array_merge( Renderer::METRIC_KEYS, array_map( static fn( $k ) => $k . '_installation_wide', Renderer::FALLBACK_KEYS ), array( 'labels' ) );
		$out  = array();
		foreach ( array( 'current', 'previous' ) as $block ) {
			if ( isset( $payload['data'][ $block ] ) && is_array( $payload['data'][ $block ] ) ) {
				$out[ $block ] = array_intersect_key( $payload['data'][ $block ], array_flip( $keep ) );
			}
		}
		$meta = array_intersect_key( (array) $payload['meta'], array_flip( array( 'generated_at', 'range', 'answered_definition' ) ) );
		return array( 'data' => $out, 'meta' => $meta );
	}
}
