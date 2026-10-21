<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Narrow, purpose-specific REST routes for the dashboards (cookie auth + wp_rest nonce).
 * This is deliberately NOT a general proxy: each route maps to one webservice endpoint with a
 * fixed parameter allowlist, a capability check, and Area authorization against the CURRENT user —
 * never against the broader scope of the site's API credential.
 */
final class Rest {

	public const NS = 'yapr/v1';

	private const VIEWS = array( 'summary', 'trends', 'distributions', 'area-dashboard', 'meeting-lookups', 'meditations', 'sms-summary' );

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		$common = self::common_args();

		register_rest_route(
			self::NS,
			'/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'report' ),
				'permission_callback' => static fn() => self::can( Access::CAP_VIEW_REPORTS ),
				'args'                => array_merge(
					$common,
					array(
						'view'        => array( 'required' => true, 'type' => 'string', 'enum' => self::VIEWS ),
						'compare'     => array( 'type' => 'boolean', 'default' => false ),
						'granularity' => array( 'type' => 'string', 'enum' => array( 'day', 'week', 'month', 'quarter', 'year' ) ),
						'group_by'    => array( 'type' => 'string', 'enum' => array( 'service_body' ) ),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/calls',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'calls' ),
				'permission_callback' => static fn() => self::can( Access::CAP_VIEW_CALLS ),
				'args'                => array_merge(
					$common,
					array(
						'category'     => array( 'type' => 'string', 'enum' => array( 'volunteer', 'voicemail', 'meeting_lookup', 'meditation', 'dialback', 'menu_only' ) ),
						'outcome'      => array( 'type' => 'string', 'enum' => array( 'answered', 'voicemail', 'connected_short', 'connected_unknown_duration', 'not_answered', 'outcome_not_recorded', 'no_volunteer_response_logged', 'forwarded', 'not_routed', 'automated', 'dialback', 'ended_before_selection' ) ),
						'answered'     => array( 'type' => 'boolean' ),
						'voicemail'    => array( 'type' => 'boolean' ),
						'volunteer_id' => array( 'type' => 'string', 'pattern' => '^v_[0-9a-f]{16}$' ),
						'min_duration' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 86400 ),
						'max_duration' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 86400 ),
						'sort'         => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
						'page'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1 ),
						'per_page'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 25 ),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/calls/(?P<id>c_\d{8}_[0-9a-f]{20})',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'call_detail' ),
				'permission_callback' => static fn() => self::can( Access::CAP_VIEW_CALLS ),
				'args'                => array( 'id' => array( 'type' => 'string', 'required' => true ) ),
			)
		);

		register_rest_route(
			self::NS,
			'/volunteers',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'volunteers' ),
				'permission_callback' => static fn() => self::can( Access::CAP_VIEW_VOLUNTEERS ),
				'args'                => array_merge(
					$common,
					array( 'sort' => array( 'type' => 'string', 'enum' => array( 'answered_calls', 'answer_rate', 'total_conversation_seconds', 'routed_attempts' ), 'default' => 'answered_calls' ) )
				),
			)
		);
	}

	private static function common_args(): array {
		return array(
			'areas'      => array( 'type' => 'string', 'pattern' => '^\d{1,10}(,\d{1,10}){0,49}$' ),
			'region'     => array( 'type' => 'integer', 'minimum' => 1 ),
			'period'     => array( 'type' => 'string', 'enum' => Settings::PERIODS ),
			'start_date' => array( 'type' => 'string', 'format' => 'date', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
			'end_date'   => array( 'type' => 'string', 'format' => 'date', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
		);
	}

	private static function can( string $cap ): bool {
		return is_user_logged_in() && current_user_can( $cap );
	}

	/** @return array{0: int[], 1: array}|WP_Error authorized Areas and base report parameters */
	private static function context( WP_REST_Request $r ) {
		$areas = Access::resolve( get_current_user_id(), Access::parse_ids( $r->get_param( 'areas' ) ), $r->get_param( 'region' ) ? (int) $r->get_param( 'region' ) : null );
		if ( is_wp_error( $areas ) ) {
			return $areas;
		}
		$params = array( 'timezone' => Settings::timezone() );
		if ( ! $r->get_param( 'areas' ) && ! $r->get_param( 'region' ) && Access::can_see_unassigned( get_current_user_id() ) ) {
			$params['include_unassigned'] = 'true';
		}
		$start  = $r->get_param( 'start_date' );
		$end    = $r->get_param( 'end_date' );
		if ( $start || $end ) {
			if ( ! $start || ! $end ) {
				return new WP_Error( 'yapr_range', __( 'Choose both a start and an end date.', 'yap-reporting' ), array( 'status' => 400 ) );
			}
			$params['start_date'] = $start;
			$params['end_date']   = $end;
		} else {
			$params['period'] = $r->get_param( 'period' ) ?: (string) Settings::get( 'default_period' );
		}
		return array( $areas, $params );
	}

	public static function report( WP_REST_Request $r ) {
		$ctx = self::context( $r );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		[ $areas, $params ] = $ctx;
		$view = (string) $r->get_param( 'view' );
		if ( $r->get_param( 'compare' ) ) {
			$params['compare'] = 'true';
		}
		if ( 'area-dashboard' === $view ) {
			$params['compare'] = 'true'; // Matches the synchronized cache entry.
		}
		if ( 'trends' === $view ) {
			$params['granularity'] = $r->get_param( 'granularity' ) ?: 'day';
		}
		if ( 'summary' === $view && $r->get_param( 'group_by' ) ) {
			$params['group_by'] = 'service_body';
		}
		$result = Reports::aggregate( $view, $areas, $params, 'private' );
		if ( is_wp_error( $result ) ) {
			return self::error( $result );
		}
		$result['areas']    = Areas::names( $areas );
		$result['timezone'] = Settings::timezone();
		return self::no_store( $result );
	}

	public static function calls( WP_REST_Request $r ) {
		$ctx = self::context( $r );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		[ $areas, $params ] = $ctx;
		foreach ( array( 'category', 'outcome', 'min_duration', 'max_duration', 'sort', 'page', 'per_page' ) as $k ) {
			if ( null !== $r->get_param( $k ) ) {
				$params[ $k ] = $r->get_param( $k );
			}
		}
		foreach ( array( 'answered', 'voicemail' ) as $k ) {
			if ( null !== $r->get_param( $k ) ) {
				$params[ $k ] = (bool) $r->get_param( $k );
			}
		}
		if ( $r->get_param( 'volunteer_id' ) ) {
			if ( ! current_user_can( Access::CAP_VIEW_VOLUNTEERS ) ) {
				return new WP_Error( 'yapr_forbidden', __( 'You do not have permission to filter by volunteer.', 'yap-reporting' ), array( 'status' => 403 ) );
			}
			$params['volunteer_id'] = (string) $r->get_param( 'volunteer_id' );
		}
		$result = Reports::live( 'calls', $areas, $params );
		if ( is_wp_error( $result ) ) {
			return self::error( $result );
		}
		$calls = array();
		foreach ( (array) ( $result['data']['calls'] ?? array() ) as $call ) {
			$calls[] = self::filter_call( (array) $call );
		}
		return self::no_store(
			array(
				'calls'      => $calls,
				'pagination' => $result['data']['pagination'] ?? array(),
				'meta'       => $result['meta'] ?? array(),
				'areas'      => Areas::names( $areas ),
				'fetched_at' => time(),
			)
		);
	}

	public static function call_detail( WP_REST_Request $r ) {
		$allowed = Access::allowed_areas( get_current_user_id() );
		if ( array() === $allowed ) {
			return new WP_Error( 'yapr_no_areas', __( 'Your account has not been given access to any Area reports.', 'yap-reporting' ), array( 'status' => 403 ) );
		}
		$result = ApiClient::get( 'calls/' . $r->get_param( 'id' ), array( 'timezone' => Settings::timezone() ) );
		if ( is_wp_error( $result ) ) {
			return self::error( $result );
		}
		$call = (array) ( $result['data']['call'] ?? array() );
		$sb   = isset( $call['service_body_id'] ) ? (int) $call['service_body_id'] : 0;
		// The site's credential may cover more Areas than this user: enforce the user's own grant.
		if ( ! in_array( $sb, $allowed, true ) ) {
			return new WP_Error( 'yapr_not_found', __( 'Call not found.', 'yap-reporting' ), array( 'status' => 404 ) );
		}
		return self::no_store( array( 'call' => self::filter_call( $call ), 'area' => Areas::name( $sb ) ) );
	}

	public static function volunteers( WP_REST_Request $r ) {
		$ctx = self::context( $r );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		[ $areas, $params ] = $ctx;
		$params['sort']     = (string) $r->get_param( 'sort' );
		$result             = Reports::live( 'volunteer-summary', $areas, $params );
		if ( is_wp_error( $result ) ) {
			return self::error( $result );
		}
		$data = (array) ( $result['data'] ?? array() );
		// Explain missing names: the webservice only sends name / number_hint when the site's credential has
		// the volunteer_names permission; this site can also switch names off.
		$sent = false;
		foreach ( (array) ( $data['volunteers'] ?? array() ) as $v ) {
			if ( array_key_exists( 'name', (array) $v ) || array_key_exists( 'number_hint', (array) $v ) ) {
				$sent = true;
				break;
			}
		}
		if ( ! Settings::get( 'show_volunteer_names' ) ) {
			$data['names_status'] = 'setting_off';
		} elseif ( ! $sent && ! empty( $data['volunteers'] ) ) {
			$data['names_status'] = 'permission_missing';
		} else {
			$data['names_status'] = 'ok';
		}
		if ( ! Settings::get( 'show_volunteer_names' ) ) {
			foreach ( (array) ( $data['volunteers'] ?? array() ) as $i => $v ) {
				unset( $data['volunteers'][ $i ]['name'], $data['volunteers'][ $i ]['number_hint'] );
			}
		}
		return self::no_store( array( 'data' => $data, 'meta' => $result['meta'] ?? array(), 'fetched_at' => time() ) );
	}

	/** Remove volunteer identity unless this user may see it. */
	public static function filter_call( array $call ): array {
		$may_see = current_user_can( Access::CAP_VIEW_VOLUNTEERS );
		$names   = $may_see && (bool) Settings::get( 'show_volunteer_names' );
		if ( ! $may_see ) {
			unset( $call['answered_by'] );
		} elseif ( ! $names && isset( $call['answered_by'] ) && is_array( $call['answered_by'] ) ) {
			unset( $call['answered_by']['name'] );
		}
		if ( isset( $call['events'] ) && is_array( $call['events'] ) ) {
			foreach ( $call['events'] as $i => $e ) {
				if ( ! $may_see ) {
					unset( $call['events'][ $i ]['volunteer'] );
				} elseif ( ! $names && isset( $e['volunteer'] ) && is_array( $e['volunteer'] ) ) {
					unset( $call['events'][ $i ]['volunteer']['name'] );
				}
			}
		}
		$call['area_name'] = Areas::name( isset( $call['service_body_id'] ) ? (int) $call['service_body_id'] : null );
		return $call;
	}

	private static function error( WP_Error $e ): WP_Error {
		$data   = (array) $e->get_error_data();
		$status = (int) ( $data['status'] ?? 502 );
		// Upstream authentication problems are a site configuration issue, not the visitor's.
		if ( in_array( $status, array( 401, 403 ), true ) && 0 === strpos( $e->get_error_code(), 'yapr_api_' ) ) {
			$status = 502;
		}
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => $status ) );
	}

	private static function no_store( array $data ): WP_REST_Response {
		$res = new WP_REST_Response( $data, 200 );
		$res->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$res->header( 'X-Content-Type-Options', 'nosniff' );
		return $res;
	}
}
