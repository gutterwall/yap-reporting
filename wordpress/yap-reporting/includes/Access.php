<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/**
 * Who may see what.
 *
 * Four effective levels:
 *  - Administrator          yapr_manage_settings (+ everything)       → every managed Area
 *  - Phoneline Administrator yapr_view_reports/call_details/volunteers → Areas granted to role or user
 *  - Area Reporter          yapr_view_reports                         → Areas granted to role or user
 *  - Public viewer          (no login)                                → only Areas + metrics published in public mode
 *
 * Area grants: a per-user override (user meta yapr_areas: 'all' | int[] | absent) replaces the
 * union of the user's role grants (setting role_areas: role => 'all' | int[]). The result is always
 * intersected with the managed Areas. Every REST route, export and shortcode calls resolve().
 */
final class Access {

	public const CAP_MANAGE_SETTINGS = 'yapr_manage_settings';
	public const CAP_MANAGE_ACCESS   = 'yapr_manage_access';
	public const CAP_VIEW_REPORTS    = 'yapr_view_reports';
	public const CAP_VIEW_CALLS      = 'yapr_view_call_details';
	public const CAP_VIEW_VOLUNTEERS = 'yapr_view_volunteers';
	public const CAP_EXPORT          = 'yapr_export_reports';

	public const ALL_CAPS = array(
		self::CAP_MANAGE_SETTINGS, self::CAP_MANAGE_ACCESS, self::CAP_VIEW_REPORTS,
		self::CAP_VIEW_CALLS, self::CAP_VIEW_VOLUNTEERS, self::CAP_EXPORT,
	);

	public const USER_META = 'yapr_areas';

	/** @return int[] Areas this user may see (subset of managed Areas). */
	public static function allowed_areas( int $user_id ): array {
		if ( $user_id <= 0 || ! user_can( $user_id, self::CAP_VIEW_REPORTS ) ) {
			return array();
		}
		$managed = Areas::managed_ids();
		if ( user_can( $user_id, self::CAP_MANAGE_SETTINGS ) ) {
			return $managed;
		}
		$override = get_user_meta( $user_id, self::USER_META, true );
		if ( 'all' === $override ) {
			return $managed;
		}
		if ( is_array( $override ) && array() !== $override ) {
			return array_values( array_intersect( $managed, array_map( 'intval', $override ) ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}
		$grants = (array) Settings::get( 'role_areas' );
		$ids    = array();
		foreach ( (array) $user->roles as $role ) {
			$g = $grants[ $role ] ?? null;
			if ( 'all' === $g ) {
				return $managed;
			}
			if ( is_array( $g ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $g ) );
			}
		}
		return array_values( array_intersect( $managed, array_unique( $ids ) ) );
	}

	/**
	 * Resolve the Areas for a request. Requested ids/regions are only ever NARROWED by the user's
	 * grant — never widened. Any requested id outside the grant is an error, not silently dropped,
	 * so a mis-typed shortcode is visible to its author.
	 *
	 * @param int[]|null $requested
	 * @return int[]|\WP_Error
	 */
	public static function resolve( int $user_id, ?array $requested, ?int $region = null ) {
		$allowed = self::allowed_areas( $user_id );
		if ( array() === $allowed ) {
			return new \WP_Error( 'yapr_no_areas', __( 'Your account has not been given access to any Area reports.', 'yap-reporting' ), array( 'status' => 403 ) );
		}
		$ids = array();
		if ( null !== $region && $region > 0 ) {
			if ( ! Areas::exists( $region ) ) {
				return self::unknown_area_error();
			}
			$ids = Areas::expand_region( $region );
			if ( array() === $ids ) {
				return new \WP_Error( 'yapr_region_empty', __( 'None of this region’s Areas are managed by this site.', 'yap-reporting' ), array( 'status' => 404 ) );
			}
		}
		if ( null !== $requested && array() !== $requested ) {
			$ids = array_merge( $ids, $requested );
		}
		if ( array() === $ids ) {
			return $allowed;
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		foreach ( $ids as $id ) {
			if ( ! Areas::exists( $id ) ) {
				return self::unknown_area_error();
			}
			if ( ! in_array( $id, $allowed, true ) ) {
				return new \WP_Error( 'yapr_area_forbidden', __( 'You do not have access to one or more of the requested Areas.', 'yap-reporting' ), array( 'status' => 403 ) );
			}
		}
		return $ids;
	}

	private static function unknown_area_error(): \WP_Error {
		return new \WP_Error( 'yapr_area_unknown', __( 'That Area is not one of this site’s service bodies. Check the area id in the shortcode.', 'yap-reporting' ), array( 'status' => 404 ) );
	}

	/**
	 * Activity YAP stores without a service body (meeting searches, JFT/SPAD, texts) belongs to the whole region.
	 * It is shown in "All my Areas" to users who may see every selected Area, when the site setting is on and the
	 * site's API credential allows unassigned activity.
	 */
	public static function can_see_unassigned( int $user_id ): bool {
		if ( ! Settings::get( 'include_unassigned' ) || ! self::credential_allows_unassigned() ) {
			return false;
		}
		$managed = Areas::managed_ids();
		$allowed = self::allowed_areas( $user_id );
		return array() !== $managed && array() === array_diff( $managed, $allowed );
	}

	public static function credential_allows_unassigned(): bool {
		$c = (array) ( ApiClient::capabilities()['client'] ?? array() );
		return ! empty( $c['all_service_bodies'] ) || ! empty( $c['allow_unassigned'] );
	}

	/** Public mode: is this Area published, and which metrics? @return string[] permitted metric keys (empty = not public) */
	public static function public_metrics_for( int $area ): array {
		$areas = array_map( 'intval', (array) Settings::get( 'public_areas' ) );
		if ( ! in_array( $area, $areas, true ) || ! Areas::is_managed( $area ) ) {
			return array();
		}
		return array_values( array_intersect( (array) Settings::get( 'public_metrics' ), Renderer::METRIC_KEYS ) );
	}

	/** Parse "1,2,3" safely. @return int[]|null */
	public static function parse_ids( $raw ): ?array {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		if ( is_array( $raw ) ) {
			$raw = implode( ',', $raw );
		}
		if ( ! preg_match( '/^\s*\d{1,10}(\s*,\s*\d{1,10})*\s*$/', (string) $raw ) ) {
			return array( -1 ); // Forces a validation failure downstream.
		}
		return array_values( array_unique( array_map( 'intval', explode( ',', (string) $raw ) ) ) );
	}
}
