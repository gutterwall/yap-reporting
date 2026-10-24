<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
/*
 * Minimal WordPress stand-ins so the plugin's pure logic can be tested without a WordPress install.
 * For full integration testing use the WordPress PHPUnit suite (wp scaffold plugin-tests).
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'YAPR_VERSION', 'test' );
define( 'YAPR_REQUIRED_API_VERSION', 'v1' );
define( 'YAPR_MIN_SERVICE_VERSION', '1.0.3' );
define( 'YAPR_DIR', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['yapr_test'] = array( 'options' => array(), 'caps' => array(), 'meta' => array(), 'users' => array(), 'current_user' => 0 );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_salt( $s ) { return 'test-salt-' . $s; }
function home_url( $p = '' ) { return 'https://wp.example' . $p; }
function wp_timezone_string() { return 'America/New_York'; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_date( $f, $ts = null, $tz = null ) { return ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz ?: new DateTimeZone( 'UTC' ) )->format( $f ); }
function get_option( $k, $d = false ) { return $GLOBALS['yapr_test']['options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['yapr_test']['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['yapr_test']['options'][ $k ] ); return true; }
function user_can( $u, $cap ) { return in_array( $cap, $GLOBALS['yapr_test']['caps'][ $u ] ?? array(), true ); }
function current_user_can( $cap ) { return user_can( $GLOBALS['yapr_test']['current_user'], $cap ); }
function get_current_user_id() { return $GLOBALS['yapr_test']['current_user']; }
function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['yapr_test']['meta'][ $u ][ $k ] ?? ''; }
function get_userdata( $u ) { return isset( $GLOBALS['yapr_test']['users'][ $u ] ) ? (object) array( 'roles' => $GLOBALS['yapr_test']['users'][ $u ] ) : false; }

spl_autoload_register( static function ( $class ) {
	if ( 0 === strpos( $class, 'YAPR\\' ) ) {
		require YAPR_DIR . 'includes/' . substr( $class, 5 ) . '.php';
	}
} );

/** Seed the Areas repository without a database. */
function yapr_seed_areas( array $rows ): void {
	$ref = new ReflectionProperty( YAPR\Areas::class, 'cache' );
	$out = array();
	foreach ( $rows as $r ) {
		$out[ $r[0] ] = array( 'id' => $r[0], 'parent_id' => $r[1], 'name' => $r[2], 'type' => $r[3], 'managed' => $r[4], 'present_in_bmlt' => true );
	}
	$ref->setValue( null, $out );
}

final class Assert {
	public static function same( $e, $a, $m = '' ) { if ( $e !== $a ) { throw new RuntimeException( $m . ' expected ' . var_export( $e, true ) . ' got ' . var_export( $a, true ) ); } }
	public static function true( $c, $m = 'false' ) { if ( ! $c ) { throw new RuntimeException( $m ); } }
}
