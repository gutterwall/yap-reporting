<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/**
 * Background synchronization (WP-Cron, or real cron — see docs/CACHING.md).
 *
 *   recent   every interval_recent minutes   dashboards for sync_periods, per managed Area and combined
 *   history  every interval_history minutes  closed periods (last month/quarter/year) and monthly trends
 *   bmlt     every interval_bmlt minutes     service body list
 *
 * Because the webservice recomputes aggregates from YAP's raw records on every refresh,
 * late-arriving or updated call events are reconciled automatically on the next run.
 * Person-level data is never synchronized.
 */
final class Sync {

	public const JOBS          = array( 'recent', 'history', 'bmlt' );
	private const MAX_REQUESTS = 250;
	private const LOCK_TTL     = 900;

	public static function register(): void {
		foreach ( self::JOBS as $job ) {
			add_action( 'yapr_sync_' . $job, static function () use ( $job ): void {
				self::run( $job, false );
			} );
		}
		add_action( 'yapr_cache_prune', array( Cache::class, 'prune' ) );
	}

	public static function cron_schedules( array $schedules ): array {
		foreach ( self::JOBS as $job ) {
			$minutes = self::interval_minutes( $job );
			$schedules[ 'yapr_' . $job ] = array(
				'interval' => $minutes * MINUTE_IN_SECONDS,
				/* translators: %d: minutes */
				'display'  => sprintf( __( 'YAP Reporting every %d minutes', 'yap-reporting' ), $minutes ),
			);
		}
		return $schedules;
	}

	public static function interval_minutes( string $job ): int {
		switch ( $job ) {
			case 'recent':
				return max( 5, min( 1440, (int) Settings::get( 'interval_recent' ) ) );
			case 'history':
				return max( 30, min( 10080, (int) Settings::get( 'interval_history' ) ) );
			default:
				return max( 60, min( 10080, (int) Settings::get( 'interval_bmlt' ) ) );
		}
	}

	public static function schedule(): void {
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		foreach ( self::JOBS as $i => $job ) {
			if ( ! wp_next_scheduled( 'yapr_sync_' . $job ) ) {
				wp_schedule_event( time() + 60 * ( $i + 1 ), 'yapr_' . $job, 'yapr_sync_' . $job );
			}
		}
		if ( ! wp_next_scheduled( 'yapr_cache_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'yapr_cache_prune' );
		}
	}

	public static function unschedule(): void {
		foreach ( array_merge( array_map( static fn( $j ) => 'yapr_sync_' . $j, self::JOBS ), array( 'yapr_cache_prune' ) ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/** Call after interval settings change. */
	public static function reschedule(): void {
		self::unschedule();
		self::schedule();
	}

	/** @return array{ok: bool, items: int, message: string} */
	public static function run( string $job, bool $manual ): array {
		if ( ! in_array( $job, self::JOBS, true ) ) {
			return array( 'ok' => false, 'items' => 0, 'message' => 'Unknown job.' );
		}
		$state = self::state( $job );
		if ( ! $manual && ! empty( $state['next_attempt'] ) && strtotime( $state['next_attempt'] . ' UTC' ) > time() ) {
			return array( 'ok' => false, 'items' => 0, 'message' => __( 'Waiting to retry after earlier failures.', 'yap-reporting' ) );
		}
		if ( ! self::lock( $job ) ) {
			return array( 'ok' => false, 'items' => 0, 'message' => __( 'A synchronization is already running.', 'yap-reporting' ) );
		}

		$started = microtime( true );
		self::save_state( $job, array( 'last_attempt' => gmdate( 'Y-m-d H:i:s' ) ) );
		try {
			if ( 'bmlt' === $job ) {
				$result = BmltClient::refresh();
				$items  = is_wp_error( $result ) ? 0 : (int) $result;
				$errors = is_wp_error( $result ) ? array( $result->get_error_message() ) : array();
				$failed = array();
			} else {
				[ $items, $errors, $failed ] = self::refresh_reports( $job );
			}
		} finally {
			self::unlock( $job );
		}

		$duration = (int) ( ( microtime( true ) - $started ) * 1000 );
		if ( $errors && 0 === $items ) {
			$failures = (int) $state['failures'] + 1;
			$backoff  = min( 6 * HOUR_IN_SECONDS, (int) ( 60 * ( 2 ** min( $failures, 8 ) ) ) );
			self::save_state( $job, array(
				'last_error'   => substr( implode( ' | ', array_unique( $errors ) ), 0, 2000 ),
				'failures'     => $failures,
				'next_attempt' => gmdate( 'Y-m-d H:i:s', time() + $backoff ),
				'duration_ms'  => $duration,
				'items'        => 0,
				'failed_areas' => implode( ',', $failed ),
			) );
			return array( 'ok' => false, 'items' => 0, 'message' => implode( ' | ', array_unique( $errors ) ) );
		}
		self::save_state( $job, array(
			'last_success' => gmdate( 'Y-m-d H:i:s' ),
			'last_error'   => $errors ? substr( implode( ' | ', array_unique( $errors ) ), 0, 2000 ) : null,
			'failures'     => 0,
			'next_attempt' => null,
			'duration_ms'  => $duration,
			'items'        => $items,
			'failed_areas' => implode( ',', $failed ),
		) );
		/* translators: %d: number of reports */
		return array( 'ok' => true, 'items' => $items, 'message' => sprintf( __( '%d reports refreshed.', 'yap-reporting' ), $items ) );
	}

	/** @return array{0: int, 1: string[], 2: int[]} */
	private static function refresh_reports( string $job ): array {
		$managed = Areas::managed_ids();
		if ( array() === $managed ) {
			return array( 0, array( __( 'No Areas are selected on the BMLT Configuration page.', 'yap-reporting' ) ), array() );
		}
		$tz       = Settings::timezone();
		$requests = array();
		$all      = Settings::get( 'include_unassigned' ) && Access::credential_allows_unassigned() ? array( 'include_unassigned' => 'true' ) : array();

		if ( 'recent' === $job ) {
			$periods = array_values( array_intersect( (array) Settings::get( 'sync_periods' ), Settings::PERIODS ) );
			$default = (string) Settings::get( 'default_period' );
			foreach ( $managed as $area ) {
				foreach ( $periods as $p ) {
					$requests[] = array( 'area-dashboard', array( $area ), array( 'period' => $p, 'compare' => 'true' ), $area );
				}
				$requests[] = array( 'summary', array( $area ), array( 'period' => $default ), $area );
				$requests[] = array( 'trends', array( $area ), array( 'period' => $default, 'granularity' => 'day' ), $area );
			}
			$requests[] = array( 'summary', $managed, array( 'period' => $default ) + $all, 0 );
			$requests[] = array( 'trends', $managed, array( 'period' => $default, 'granularity' => 'day' ) + $all, 0 );
		} else {
			foreach ( $managed as $area ) {
				foreach ( array( 'last_month', 'last_quarter', 'this_year' ) as $p ) {
					$requests[] = array( 'summary', array( $area ), array( 'period' => $p ), $area );
				}
				$requests[] = array( 'trends', array( $area ), array( 'period' => 'this_year', 'granularity' => 'month' ), $area );
			}
			$requests[] = array( 'summary', $managed, array( 'period' => 'last_month' ) + $all, 0 );
			$requests[] = array( 'trends', $managed, array( 'period' => 'this_year', 'granularity' => 'month' ) + $all, 0 );
		}

		$items  = 0;
		$errors = array();
		$failed = array();
		foreach ( array_slice( $requests, 0, self::MAX_REQUESTS ) as [ $endpoint, $areas, $params, $label ] ) {
			$params['timezone'] = $tz;
			$result             = Reports::aggregate( $endpoint, $areas, $params, 'private', true );
			if ( is_wp_error( $result ) || isset( $result['error'] ) ) {
				$errors[] = is_wp_error( $result ) ? $result->get_error_message() : (string) $result['error'];
				if ( $label ) {
					$failed[] = $label;
				}
				// Authentication / configuration errors will not fix themselves mid-run.
				if ( is_wp_error( $result ) && preg_match( '/unauthorized|not_configured|no_token|https/', $result->get_error_code() ) ) {
					break;
				}
				continue;
			}
			$items++;
		}

		// Public dashboards are refreshed from the same API data, filtered to published metrics.
		if ( 'recent' === $job ) {
			foreach ( array_map( 'intval', (array) Settings::get( 'public_areas' ) ) as $area ) {
				if ( ! in_array( $area, $managed, true ) ) {
					continue;
				}
				foreach ( array_values( array_intersect( (array) Settings::get( 'sync_periods' ), Settings::PUBLIC_PERIODS ) ) as $p ) {
					$r = Reports::aggregate( 'area-dashboard', array( $area ), array( 'period' => $p, 'compare' => 'true', 'timezone' => $tz ), 'public', true );
					if ( ! is_wp_error( $r ) && ! isset( $r['error'] ) ) {
						$items++;
					}
				}
			}
		}
		return array( $items, $errors, array_values( array_unique( $failed ) ) );
	}

	private static function lock( string $job ): bool {
		$name = 'yapr_lock_job_' . $job;
		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( $name );
		if ( $since > 0 && time() - $since > self::LOCK_TTL ) {
			// Stale lock from a crashed run.
			delete_option( $name );
			return add_option( $name, time(), '', false );
		}
		return false;
	}

	private static function unlock( string $job ): void {
		delete_option( 'yapr_lock_job_' . $job );
	}

	public static function state( string $job ): array {
		global $wpdb;
		$table = Installer::table( 'sync_state' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job = %s", $job ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array( $row ) ? $row : array( 'job' => $job, 'last_attempt' => null, 'last_success' => null, 'last_error' => null, 'failures' => 0, 'next_attempt' => null, 'duration_ms' => 0, 'items' => 0, 'failed_areas' => '' );
	}

	private static function save_state( string $job, array $changes ): void {
		global $wpdb;
		$row = array_merge( self::state( $job ), $changes, array( 'job' => $job ) );
		$wpdb->replace( Installer::table( 'sync_state' ), $row );
	}
}
