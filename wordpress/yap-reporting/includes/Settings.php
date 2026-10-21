<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/** Plugin options. The API token is stored separately, encrypted, and is write-only in the UI. */
final class Settings {

	public const OPTION       = 'yapr_settings';
	public const TOKEN_OPTION = 'yapr_api_token';

	public const PERIODS = array( 'today', 'yesterday', '7d', '30d', '45d', '60d', '90d', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year' );

	/** Periods a public (anonymous) dashboard may request. */
	public const PUBLIC_PERIODS = array( '7d', '30d', '90d', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year' );

	public static function defaults(): array {
		return array(
			'api_url'                 => '',
			'timezone'                => wp_timezone_string() ?: 'UTC',
			'default_area'            => 0,
			'default_period'          => '30d',
			'chart_style'             => 'bars',
			'exports_enabled'         => 1,
			'show_volunteer_names'    => 1,
			'bmlt_root'               => '',
			'include_children'        => 1,
			'interval_recent'         => 15,
			'interval_history'        => 360,
			'interval_bmlt'           => 1440,
			'sync_periods'            => array( '7d', '30d', 'this_month', 'last_month' ),
			'stale_factor'            => 3,
			'cache_retention_days'    => 30,
			'keep_data_on_uninstall'  => 0,
			'public_areas'            => array(),
			'public_metrics'          => array( 'volunteer_calls', 'calls_answered', 'answer_rate', 'calls_forwarded', 'meeting_lookups', 'sms_meeting_lookups', 'sms_volunteer_lookups', 'jft', 'sms_jft', 'spad', 'sms_spad' ),
			'role_areas'              => array(),
			'area_labels'             => array(),
			'setup_complete'          => 0,
			'include_unassigned'      => 1,
		);
	}

	public static function ensure_defaults(): void {
		$current = get_option( self::OPTION );
		if ( ! is_array( $current ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
			return;
		}
		update_option( self::OPTION, array_merge( self::defaults(), $current ), false );
	}

	public static function all(): array {
		$v = get_option( self::OPTION );
		return array_merge( self::defaults(), is_array( $v ) ? $v : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $changes ): void {
		update_option( self::OPTION, array_merge( self::all(), $changes ), false );
	}

	/** API URL: YAPR_API_URL constant wins over the database. */
	public static function api_url(): string {
		if ( defined( 'YAPR_API_URL' ) && '' !== (string) YAPR_API_URL ) {
			return untrailingslashit( (string) YAPR_API_URL );
		}
		return untrailingslashit( (string) self::get( 'api_url' ) );
	}

	public static function api_url_locked(): bool {
		return defined( 'YAPR_API_URL' ) && '' !== (string) YAPR_API_URL;
	}

	/** API token: YAPR_API_TOKEN constant (or environment variable) wins over the encrypted option. */
	public static function api_token(): string {
		if ( defined( 'YAPR_API_TOKEN' ) && '' !== (string) YAPR_API_TOKEN ) {
			return (string) YAPR_API_TOKEN;
		}
		$env = getenv( 'YAPR_API_TOKEN' );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}
		$stored = get_option( self::TOKEN_OPTION );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}
		return (string) Crypto::decrypt( $stored );
	}

	public static function token_source(): string {
		if ( defined( 'YAPR_API_TOKEN' ) && '' !== (string) YAPR_API_TOKEN ) {
			return 'constant';
		}
		$env = getenv( 'YAPR_API_TOKEN' );
		if ( is_string( $env ) && '' !== $env ) {
			return 'environment';
		}
		return get_option( self::TOKEN_OPTION ) ? 'database' : 'none';
	}

	/** Store a new token (encrypted). Never echoed back to any page. */
	public static function set_token( string $token ): void {
		update_option( self::TOKEN_OPTION, Crypto::encrypt( $token ), false );
	}

	public static function clear_token(): void {
		delete_option( self::TOKEN_OPTION );
	}

	/** Display-safe hint, e.g. "yapr_3f9a…c2" — shows only the non-secret client id. */
	public static function token_hint(): string {
		$t = self::api_token();
		if ( preg_match( '/^yapr_([0-9a-f]{12})_/', $t, $m ) ) {
			return 'yapr_' . $m[1] . '_••••••••';
		}
		return '' === $t ? '' : '••••••••';
	}

	public static function timezone(): string {
		$tz = (string) self::get( 'timezone' );
		return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : 'UTC';
	}
}
