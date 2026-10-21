<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/** Reads service bodies from a BMLT root server (Semantic API: switcher=GetServiceBodies). */
final class BmltClient {

	/** @return array<int, array{id: int, parent_id: int, name: string, type: string}>|\WP_Error */
	public static function service_bodies( ?string $root = null ) {
		$root = untrailingslashit( $root ?? (string) Settings::get( 'bmlt_root' ) );
		if ( '' === $root ) {
			return new \WP_Error( 'yapr_bmlt_missing', __( 'Enter the BMLT root server URL first.', 'yap-reporting' ) );
		}
		$valid = UrlGuard::validate( $root );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$url      = $root . '/client_interface/json/?switcher=GetServiceBodies';
		$response = UrlGuard::get( $url, UrlGuard::request_args( array( 'Accept' => 'application/json' ), 20 ) );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'yapr_bmlt_http', __( 'Could not reach the BMLT server: ', 'yap-reporting' ) . ApiClient::safe_message( $response->get_error_message() ) );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'yapr_bmlt_status', sprintf( __( 'The BMLT server returned HTTP %d. Check the root server URL (it usually ends in /main_server).', 'yap-reporting' ), (int) wp_remote_retrieve_response_code( $response ) ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'yapr_bmlt_json', __( 'The BMLT server did not return service bodies.', 'yap-reporting' ) );
		}
		$out = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_numeric( $row['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'        => (int) $row['id'],
				'parent_id' => isset( $row['parent_id'] ) && is_numeric( $row['parent_id'] ) ? (int) $row['parent_id'] : 0,
				'name'      => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'type'      => sanitize_key( (string) ( $row['type'] ?? '' ) ),
			);
		}
		if ( array() === $out ) {
			return new \WP_Error( 'yapr_bmlt_empty', __( 'The BMLT server returned no service bodies.', 'yap-reporting' ) );
		}
		return $out;
	}

	/** Fetch and store. @return int|\WP_Error number of service bodies */
	public static function refresh() {
		$bodies = self::service_bodies();
		if ( is_wp_error( $bodies ) ) {
			return $bodies;
		}
		Areas::replace_from_bmlt( $bodies );
		return count( $bodies );
	}
}
