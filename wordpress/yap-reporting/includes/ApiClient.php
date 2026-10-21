<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/**
 * Server-to-server client for the YAP Reporting Webservice.
 * The token is attached here and never reaches a browser. Only endpoints in ENDPOINTS may be called,
 * so the WordPress REST layer cannot be turned into a general-purpose proxy.
 */
final class ApiClient {

	public const ENDPOINTS = array(
		'health', 'capabilities', 'areas', 'summary', 'trends', 'distributions', 'area-dashboard',
		'meeting-lookups', 'meditations', 'sms-summary', 'calls', 'volunteers', 'volunteer-summary',
	);

	/** Query keys the webservice understands; anything else is dropped before sending. */
	public const QUERY_KEYS = array(
		'areas', 'area_id', 'include_unassigned', 'period', 'start_date', 'end_date', 'timezone', 'compare',
		'group_by', 'granularity', 'category', 'outcome', 'answered', 'voicemail', 'volunteer_id',
		'min_duration', 'max_duration', 'sort', 'page', 'per_page',
	);

	/**
	 * @param array<string, scalar> $query
	 * @return array|\WP_Error decoded JSON body
	 */
	public static function get( string $endpoint, array $query = array() ) {
		$isCallDetail = (bool) preg_match( '/^calls\/c_\d{8}_[0-9a-f]{20}$/', $endpoint );
		if ( ! $isCallDetail && ! in_array( $endpoint, self::ENDPOINTS, true ) ) {
			return new \WP_Error( 'yapr_endpoint', 'Unsupported endpoint.' );
		}
		$base = Settings::api_url();
		if ( '' === $base ) {
			return new \WP_Error( 'yapr_not_configured', __( 'The Reporting API URL has not been set.', 'yap-reporting' ) );
		}
		$valid = UrlGuard::validate( $base );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$token = Settings::api_token();
		if ( '' === $token && 'health' !== $endpoint ) {
			return new \WP_Error( 'yapr_no_token', __( 'The API credential has not been set.', 'yap-reporting' ) );
		}

		$clean = array();
		foreach ( $query as $k => $v ) {
			if ( in_array( $k, self::QUERY_KEYS, true ) && null !== $v && '' !== $v ) {
				$clean[ $k ] = is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v;
			}
		}
		$url = $base . '/api/v1/' . $endpoint;
		if ( $clean ) {
			$url .= '?' . http_build_query( $clean, '', '&', PHP_QUERY_RFC3986 );
		}

		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$started  = microtime( true );
		$response = UrlGuard::get( $url, UrlGuard::request_args( $headers, 25 ) );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'yapr_http', __( 'Could not reach the Reporting API: ', 'yap-reporting' ) . self::safe_message( $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status >= 300 && $status < 400 ) {
			return new \WP_Error( 'yapr_redirect', __( 'The Reporting API answered with a redirect. Check the API URL (redirects are not followed).', 'yap-reporting' ), array( 'status' => 502 ) );
		}
		if ( 200 !== $status || ! is_array( $body ) ) {
			$code = is_array( $body ) && isset( $body['error']['code'] ) ? sanitize_key( (string) $body['error']['code'] ) : 'http_' . $status;
			$msg  = is_array( $body ) && isset( $body['error']['message'] ) ? self::safe_message( (string) $body['error']['message'] ) : sprintf( __( 'The Reporting API returned HTTP %d.', 'yap-reporting' ), $status );
			return new \WP_Error( 'yapr_api_' . $code, $msg, array( 'status' => $status, 'elapsed_ms' => (int) ( ( microtime( true ) - $started ) * 1000 ) ) );
		}
		return $body;
	}

	/** Strip anything resembling a credential from messages before display or logging. */
	public static function safe_message( string $message ): string {
		$message = preg_replace( '/yapr_[A-Za-z0-9_\-]+/', 'yapr_[hidden]', $message );
		$message = preg_replace( '/Bearer\s+\S+/i', 'Bearer [hidden]', (string) $message );
		return wp_strip_all_tags( substr( (string) $message, 0, 300 ) );
	}

	/** @return array{ok: bool, message: string, capabilities?: array} */
	public static function test_connection(): array {
		$health = self::get( 'health' );
		if ( is_wp_error( $health ) ) {
			return array( 'ok' => false, 'message' => $health->get_error_message() );
		}
		$caps = self::get( 'capabilities' );
		if ( is_wp_error( $caps ) ) {
			return array( 'ok' => false, 'message' => __( 'The API is reachable, but the credential was not accepted: ', 'yap-reporting' ) . $caps->get_error_message() );
		}
		update_option( 'yapr_capabilities', array( 'fetched_at' => time(), 'data' => $caps['data'] ?? array() ), false );
		$compat = self::compatibility( (array) ( $caps['data'] ?? array() ) );
		if ( 'incompatible' === $compat['status'] ) {
			return array( 'ok' => false, 'message' => $compat['message'] );
		}
		$message = __( 'Connected. The credential was accepted.', 'yap-reporting' );
		if ( 'outdated' === $compat['status'] ) {
			$message .= ' ' . $compat['message'];
		}
		return array( 'ok' => true, 'message' => $message, 'capabilities' => $caps['data'] ?? array() );
	}

	/**
	 * Compare the webservice's reported versions with what this plugin needs.
	 *
	 * @return array{status: string, message: string} status: ok | outdated | incompatible | unknown
	 */
	public static function compatibility( ?array $caps = null ): array {
		$caps    = $caps ?? self::capabilities();
		$api     = (string) ( $caps['api_version'] ?? '' );
		$service = (string) ( $caps['service_version'] ?? '' );
		if ( '' === $api || '' === $service ) {
			return array( 'status' => 'unknown', 'message' => __( 'Run Test connection to check webservice compatibility.', 'yap-reporting' ) );
		}
		if ( YAPR_REQUIRED_API_VERSION !== $api ) {
			/* translators: 1: API version reported, 2: API version required */
			return array( 'status' => 'incompatible', 'message' => sprintf( __( 'The webservice speaks API %1$s but this plugin needs API %2$s. Install a matching webservice release (see COMPATIBILITY.md).', 'yap-reporting' ), $api, YAPR_REQUIRED_API_VERSION ) );
		}
		if ( version_compare( $service, YAPR_MIN_SERVICE_VERSION, '<' ) ) {
			/* translators: 1: installed webservice version, 2: minimum recommended version */
			return array( 'status' => 'outdated', 'message' => sprintf( __( 'The webservice is version %1$s; version %2$s or newer is recommended. Version 1.4.0 fixes which volunteer is credited with answering and corrects call times on servers not set to UTC.', 'yap-reporting' ), $service, YAPR_MIN_SERVICE_VERSION ) );
		}
		/* translators: %s: webservice version */
		return array( 'status' => 'ok', 'message' => sprintf( __( 'Webservice %s is compatible.', 'yap-reporting' ), $service ) );
	}

	/** Last-known capabilities (cached from Test Connection / sync). */
	public static function capabilities(): array {
		$c = get_option( 'yapr_capabilities' );
		return is_array( $c ) && isset( $c['data'] ) && is_array( $c['data'] ) ? $c['data'] : array();
	}
}
