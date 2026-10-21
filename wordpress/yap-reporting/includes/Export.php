<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/**
 * Exports via admin-post.php (logged-in users only), enforcing the same capability and Area
 * checks as the dashboards. CSV cells are neutralised against spreadsheet formula injection.
 * PDF: open the printable report and use the browser's "Save as PDF" (no PDF library is bundled).
 */
final class Export {

	public const TYPES = array(
		'summary'    => array( 'cap' => Access::CAP_VIEW_REPORTS, 'title' => 'Area summary' ),
		'trends'     => array( 'cap' => Access::CAP_VIEW_REPORTS, 'title' => 'Monthly comparison' ),
		'hours'      => array( 'cap' => Access::CAP_VIEW_REPORTS, 'title' => 'Call distribution' ),
		'meetings'   => array( 'cap' => Access::CAP_VIEW_REPORTS, 'title' => 'Meeting lookup activity' ),
		'volunteers' => array( 'cap' => Access::CAP_VIEW_VOLUNTEERS, 'title' => 'Volunteer statistics' ),
		'calls'      => array( 'cap' => Access::CAP_VIEW_CALLS, 'title' => 'Call detail' ),
	);

	private const MAX_CALL_ROWS = 5000;

	public static function register(): void {
		add_action( 'admin_post_yapr_export', array( self::class, 'handle' ) );
		// No admin_post_nopriv handler: anonymous exports are impossible.
	}

	public static function handle(): void {
		if ( ! is_user_logged_in() || ! check_admin_referer( 'yapr_export', '_yapr_nonce' ) ) {
			wp_die( esc_html__( 'Your session has expired. Reload the report and try again.', 'yap-reporting' ), 403 );
		}
		$type = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		if ( ! isset( self::TYPES[ $type ] ) || ! Settings::get( 'exports_enabled' ) || ! current_user_can( Access::CAP_EXPORT ) || ! current_user_can( self::TYPES[ $type ]['cap'] ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'yap-reporting' ), 403 );
		}
		$format = 'html' === ( $_GET['format'] ?? '' ) ? 'html' : 'csv'; // phpcs:ignore WordPress.Security.NonceVerification

		$areas = Access::resolve( get_current_user_id(), Access::parse_ids( isset( $_GET['areas'] ) ? sanitize_text_field( wp_unslash( $_GET['areas'] ) ) : null ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_wp_error( $areas ) ) {
			wp_die( esc_html( $areas->get_error_message() ), 403 );
		}
		$params = array( 'timezone' => Settings::timezone() );
		$start  = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$end    = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
			$params['start_date'] = $start;
			$params['end_date']   = $end;
		} else {
			$p                = sanitize_key( wp_unslash( $_GET['period'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$params['period'] = in_array( $p, Settings::PERIODS, true ) ? $p : (string) Settings::get( 'default_period' );
		}

		[ $headers, $rows, $meta, $fetched ] = self::build( $type, $areas, $params );
		$title = self::TYPES[ $type ]['title'];
		$info  = array(
			array( 'Report', $title ),
			array( 'Areas', implode( '; ', array_values( Areas::names( $areas ) ) ) ),
			array( 'Period', isset( $meta['range'] ) ? ( $meta['range']['start_date'] ?? '' ) . ' to ' . ( $meta['range']['end_date'] ?? '' ) : '' ),
			array( 'Timezone', Settings::timezone() ),
			array( 'Data as of', $fetched ? wp_date( 'Y-m-d H:i T', $fetched ) : '' ),
			array( 'Answered means', (string) ( $meta['answered_definition'] ?? '' ) ),
		);

		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		$filename = 'yap-' . $type . '-' . gmdate( 'Ymd-His' );

		if ( 'csv' === $format ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );
			$out = fopen( 'php://output', 'w' );
			fwrite( $out, "\xEF\xBB\xBF" );
			foreach ( $info as $line ) {
				fputcsv( $out, array_map( array( self::class, 'csv_cell' ), $line ) );
			}
			fputcsv( $out, array() );
			fputcsv( $out, array_map( array( self::class, 'csv_cell' ), $headers ) );
			foreach ( $rows as $row ) {
				fputcsv( $out, array_map( array( self::class, 'csv_cell' ), $row ) );
			}
			fclose( $out );
			exit;
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; frame-ancestors 'none'" );
		self::printable( $title, $info, $headers, $rows );
		exit;
	}

	/**
	 * Neutralise values a spreadsheet would execute (= + - @ tab CR) by prefixing an apostrophe.
	 * Real numbers are left intact so totals still work.
	 */
	public static function csv_cell( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$s = (string) $value;
		if ( is_numeric( $s ) && ! preg_match( '/^\s/', $s ) ) {
			return $s;
		}
		if ( '' !== $s && in_array( $s[0], array( '=', '+', '-', '@', "\t", "\r", "\n" ), true ) ) {
			return "'" . $s;
		}
		return $s;
	}

	/** @return array{0: string[], 1: array<int, array>, 2: array, 3: int} */
	private static function build( string $type, array $areas, array $params ): array {
		switch ( $type ) {
			case 'summary':
				$r = self::ok( Reports::aggregate( 'summary', $areas, $params ) );
				$rows = array();
				foreach ( (array) ( $r['data']['metrics'] ?? array() ) as $key => $m ) {
					$rows[] = array( $key, ! empty( $m['available'] ) ? $m['value'] : 'not available', $m['unit'] ?? '', $m['level'] ?? '', $m['reason'] ?? '' );
				}
				return array( array( 'Metric', 'Value', 'Unit', 'Counts', 'Note' ), $rows, $r['meta'], $r['fetched_at'] );

			case 'trends':
				$r    = self::ok( Reports::aggregate( 'trends', $areas, $params + array( 'granularity' => 'month', 'compare' => 'true' ) ) );
				$cols = array( 'bucket', 'total_calls', 'volunteer_routed_calls', 'answered_calls', 'answer_rate', 'voicemail_calls', 'meeting_lookup_actions', 'sms_meeting_lookups', 'jft', 'spad' );
				$rows = array();
				foreach ( (array) ( $r['data']['series'] ?? array() ) as $s ) {
					$rows[] = array_map( static fn( $c ) => $s[ $c ] ?? '', $cols );
				}
				foreach ( (array) ( $r['data']['previous_period']['series'] ?? array() ) as $s ) {
					$rows[] = array_map( static fn( $c ) => 'bucket' === $c ? 'previous ' . ( $s[ $c ] ?? '' ) : ( $s[ $c ] ?? '' ), $cols );
				}
				return array( $cols, $rows, $r['meta'], $r['fetched_at'] );

			case 'hours':
				$r    = self::ok( Reports::aggregate( 'distributions', $areas, $params ) );
				$rows = array();
				foreach ( (array) ( $r['data']['by_hour'] ?? array() ) as $h ) {
					$rows[] = array( 'hour ' . $h['hour'], $h['calls'], $h['volunteer_routed_calls'], $h['answered_calls'], $h['answer_rate'] );
				}
				foreach ( (array) ( $r['data']['by_weekday'] ?? array() ) as $d ) {
					$rows[] = array( $d['name'], $d['calls'], $d['volunteer_routed_calls'], $d['answered_calls'], $d['answer_rate'] );
				}
				return array( array( 'Hour / weekday', 'Calls', 'Routed to volunteers', 'Answered', 'Answer rate %' ), $rows, $r['meta'], $r['fetched_at'] );

			case 'meetings':
				$r    = self::ok( Reports::aggregate( 'meeting-lookups', $areas, $params ) );
				$rows = array();
				foreach ( (array) ( $r['data']['by_service_body'] ?? array() ) as $a ) {
					$rows[] = array( Areas::name( $a['service_body_id'] ), $a['voice_actions'], $a['sms_lookups'] );
				}
				return array( array( 'Area', 'Phone meeting searches', 'Text meeting searches' ), $rows, $r['meta'], $r['fetched_at'] );

			case 'volunteers':
				$r     = self::ok( Reports::live( 'volunteer-summary', $areas, $params ), true );
				$names = (bool) Settings::get( 'show_volunteer_names' );
				$rows  = array();
				foreach ( (array) ( $r['data']['volunteers'] ?? array() ) as $v ) {
					$rows[] = array(
						$names && ! empty( $v['name'] ) ? $v['name'] : $v['volunteer_id'],
						$names ? ( $v['number_hint'] ?? '' ) : '',
						$v['routed_attempts'], $v['picked_up'] ?? '', $v['answered_calls'], $v['pickups_taken_by_other'] ?? '', $v['pickups_on_unanswered_calls'] ?? '', $v['attempts_outcome_not_recorded'] ?? '', $v['declined_without_key'] ?? '', $v['calls_lost_after_first_pickup'] ?? '', ! empty( $v['possible_voicemail_pickup'] ),
						$v['not_answered_attempts'], $v['rejected_attempts'], $v['busy_attempts'] ?? '', $v['bad_number_attempts'] ?? '',
						$v['answer_rate'], $v['total_conversation_seconds'], $v['average_conversation_seconds'], $v['last_answered_at'],
					);
				}
				return array( array( 'Volunteer', 'Number ends', 'Times dialed', 'Picked up', 'Accepted (answered)', 'Taken by another volunteer', 'Picked up, nobody answered', 'Result not recorded', 'No key pressed', 'Calls lost after their pick-up', 'Possible voicemail pick-up', 'Not answered', 'Declined', 'Busy', 'Number failed', 'Answer rate %', 'Conversation seconds', 'Average seconds', 'Last answered' ), $rows, $r['meta'], time() );

			case 'calls':
			default:
				$rows  = array();
				$page  = 1;
				$meta  = array();
				$vols  = current_user_can( Access::CAP_VIEW_VOLUNTEERS );
				$names = $vols && (bool) Settings::get( 'show_volunteer_names' );
				do {
					$r     = self::ok( Reports::live( 'calls', $areas, $params + array( 'page' => $page, 'per_page' => 200, 'sort' => 'asc' ) ), true );
					$meta  = $r['meta'];
					$pages = (int) ( $r['data']['pagination']['total_pages'] ?? 1 );
					foreach ( (array) ( $r['data']['calls'] ?? array() ) as $c ) {
						$vol = '';
						if ( $vols && ! empty( $c['answered_by'] ) ) {
							$vol = $names && ! empty( $c['answered_by']['name'] ) ? $c['answered_by']['name'] : $c['answered_by']['volunteer_id'];
						}
						$rows[] = array( $c['date'], $c['time'], Areas::name( $c['service_body_id'] ), $c['category'], $c['outcome'], $c['answered'], $vol, $c['duration_seconds'], $c['conversation_seconds'], $c['voicemail'], $c['meeting_lookups'], $c['volunteer_attempts'] );
					}
					$page++;
				} while ( $page <= $pages && count( $rows ) < self::MAX_CALL_ROWS );
				return array( array( 'Date', 'Time', 'Area', 'Type', 'Outcome', 'Answered', 'Volunteer', 'Call seconds', 'Conversation seconds', 'Voicemail', 'Meeting searches', 'Volunteers dialed' ), $rows, $meta, time() );
		}
	}

	/** @param array|\WP_Error $r */
	private static function ok( $r, bool $raw = false ): array {
		if ( is_wp_error( $r ) ) {
			wp_die( esc_html( $r->get_error_message() ), 502 );
		}
		if ( $raw ) {
			return array( 'data' => $r['data'] ?? array(), 'meta' => $r['meta'] ?? array() );
		}
		return $r;
	}

	private static function printable( string $title, array $info, array $headers, array $rows ): void {
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title>';
		echo '<style>body{font:14px/1.45 system-ui,sans-serif;color:#1f2933;margin:2rem}h1{font-size:1.4rem;margin:0 0 1rem}table{border-collapse:collapse;width:100%;margin-top:1rem}th,td{border-bottom:1px solid #d5dbe1;padding:.35rem .5rem;text-align:left;font-variant-numeric:tabular-nums}th{background:#eef1f4}dl{display:grid;grid-template-columns:max-content 1fr;gap:.2rem 1rem;margin:0}dt{font-weight:600}@media print{body{margin:0}button{display:none}}</style></head><body>';
		echo '<button onclick="window.print()">' . esc_html__( 'Print or save as PDF', 'yap-reporting' ) . '</button>';
		echo '<h1>' . esc_html( $title ) . '</h1><dl>';
		foreach ( $info as $line ) {
			echo '<dt>' . esc_html( (string) $line[0] ) . '</dt><dd>' . esc_html( (string) $line[1] ) . '</dd>';
		}
		echo '</dl><table><thead><tr>';
		foreach ( $headers as $h ) {
			echo '<th scope="col">' . esc_html( (string) $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $cell ) {
				echo '<td>' . esc_html( is_bool( $cell ) ? ( $cell ? 'yes' : 'no' ) : (string) $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></body></html>';
	}
}
