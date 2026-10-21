<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/**
 * [yap_reporting]        full statistics and charts (logged-in, yapr_view_reports)
 * [yap_phoneline_admin]  individual calls and volunteer activity (yapr_view_call_details)
 * [yap_area_dashboard]   compact Area Service figures (private by default; optional public aggregates)
 *
 * Shortcode attributes only choose presentation and defaults. Every Area they name is checked
 * against the viewer's grant here AND again on every REST request.
 */
final class Shortcodes {

	public static function register(): void {
		add_shortcode( 'yap_reporting', array( self::class, 'reporting' ) );
		add_shortcode( 'yap_phoneline_admin', array( self::class, 'phoneline_admin' ) );
		add_shortcode( 'yap_area_dashboard', array( self::class, 'area_dashboard' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'register_assets' ) );
	}

	public static function register_assets(): void {
		wp_register_style( 'yapr', YAPR_URL . 'assets/css/yapr.css', array(), YAPR_VERSION );
		wp_register_script( 'yapr-app', YAPR_URL . 'assets/js/yapr-app.js', array(), YAPR_VERSION, true );
	}

	/** Shared bootstrap data for the JS app (no secrets; REST nonce only). */
	public static function enqueue_app(): void {
		wp_enqueue_style( 'yapr' );
		wp_enqueue_script( 'yapr-app' );
		static $localized = false;
		if ( $localized ) {
			return;
		}
		$localized = true;
		$user      = get_current_user_id();
		$allowed   = Access::allowed_areas( $user );
		wp_localize_script(
			'yapr-app',
			'YAPR_BOOT',
			array(
				'rest'        => esc_url_raw( rest_url( 'yapr/v1/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'exportUrl'   => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'exportNonce' => wp_create_nonce( 'yapr_export' ),
				'areas'       => Areas::names( $allowed ),
				'regionWide'  => Access::can_see_unassigned( $user ),
				'timezone'    => Settings::timezone(),
				'chartStyle'  => (string) Settings::get( 'chart_style' ),
				'periods'     => array_combine( Settings::PERIODS, array_map( array( Renderer::class, 'period_label' ), Settings::PERIODS ) ),
				'can'         => array(
					'calls'      => current_user_can( Access::CAP_VIEW_CALLS ),
					'volunteers' => current_user_can( Access::CAP_VIEW_VOLUNTEERS ),
					'export'     => current_user_can( Access::CAP_EXPORT ) && (bool) Settings::get( 'exports_enabled' ),
				),
				'i18n'        => self::strings(),
			)
		);
	}

	public static function reporting( $atts ): string {
		$a = shortcode_atts(
			array(
				'area'       => '',
				'areas'      => '',
				'region'     => '',
				'period'     => '',
				'start_date' => '',
				'end_date'   => '',
				'view'       => 'overview',
				'charts'     => 'yes',
				'tables'     => 'yes',
				'comparison' => 'no',
				'export'     => 'yes',
			),
			$atts,
			'yap_reporting'
		);
		$gate = self::gate( Access::CAP_VIEW_REPORTS );
		if ( null !== $gate ) {
			return $gate;
		}
		$areas = self::resolve_areas( $a );
		if ( is_wp_error( $areas ) ) {
			return self::notice( $areas->get_error_message() );
		}
		$views = array( 'overview', 'calls', 'volunteers', 'voicemail', 'meetings', 'meditations', 'sms', 'trends', 'all' );
		$view  = in_array( $a['view'], $views, true ) ? $a['view'] : 'overview';
		return self::app_container(
			'reporting',
			array(
				'view'       => $view,
				'areas'      => $areas,
				'period'     => self::period( $a['period'] ),
				'start_date' => self::date( $a['start_date'] ),
				'end_date'   => self::date( $a['end_date'] ),
				'charts'     => self::yes( $a['charts'] ),
				'tables'     => self::yes( $a['tables'] ),
				'comparison' => self::yes( $a['comparison'] ),
				'export'     => self::yes( $a['export'] ),
			)
		);
	}

	public static function phoneline_admin( $atts ): string {
		$a    = shortcode_atts( array( 'area' => '', 'areas' => '', 'region' => '', 'period' => '', 'tab' => 'calls' ), $atts, 'yap_phoneline_admin' );
		$gate = self::gate( Access::CAP_VIEW_CALLS );
		if ( null !== $gate ) {
			return $gate;
		}
		$areas = self::resolve_areas( $a );
		if ( is_wp_error( $areas ) ) {
			return self::notice( $areas->get_error_message() );
		}
		return self::app_container(
			'admin',
			array(
				'areas'  => $areas,
				'period' => self::period( $a['period'] ),
				'tab'    => in_array( $a['tab'], array( 'calls', 'volunteers' ), true ) ? $a['tab'] : 'calls',
			)
		);
	}

	public static function area_dashboard( $atts ): string {
		$a = shortcode_atts(
			array(
				'area'             => '',
				'period'           => '30d',
				'style'            => 'cards',
				'title'            => '',
				'show_title'       => 'yes',
				'show_updated'     => 'yes',
				'show_meditations' => 'yes',
				'show_sms'         => 'yes',
				'comparison'       => 'no',
				'icons'            => 'yes',
				'selectable'       => 'no',
				'metrics'          => '',
			),
			$atts,
			'yap_area_dashboard'
		);

		$area = absint( $a['area'] ?: Settings::get( 'default_area' ) );
		if ( $area <= 0 || ! Areas::exists( $area ) ) {
			return current_user_can( 'edit_posts' ) ? self::notice( __( 'YAP Area dashboard: set area="…" to a BMLT service body id that is selected on the BMLT Configuration page.', 'yap-reporting' ) ) : '';
		}

		$user    = get_current_user_id();
		$private = $user > 0 && ! is_wp_error( Access::resolve( $user, array( $area ) ) );
		$public  = ! $private ? Access::public_metrics_for( $area ) : array();
		if ( ! $private && array() === $public ) {
			// Private mode (default): nothing is shown to visitors who are not authorized.
			if ( $user > 0 ) {
				return self::notice( __( 'You do not have access to this Area’s phoneline report.', 'yap-reporting' ) );
			}
			return '';
		}

		$allowed_periods = $private ? Settings::PERIODS : Settings::PUBLIC_PERIODS;
		$period          = in_array( $a['period'], $allowed_periods, true ) ? $a['period'] : '30d';
		$selectable      = self::yes( $a['selectable'] );
		if ( $selectable && isset( $_GET['yapr_period'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only, allowlisted.
			$req = sanitize_key( wp_unslash( (string) $_GET['yapr_period'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( in_array( $req, $allowed_periods, true ) ) {
				$period = $req;
			}
		}

		$metrics = Renderer::METRIC_KEYS;
		if ( '' !== trim( (string) $a['metrics'] ) ) {
			$metrics = array_values( array_intersect( Renderer::METRIC_KEYS, array_map( 'trim', explode( ',', (string) $a['metrics'] ) ) ) );
		}
		if ( ! self::yes( $a['show_meditations'] ) ) {
			$metrics = array_values( array_diff( $metrics, Renderer::MEDITATION_KEYS ) );
		}
		if ( ! self::yes( $a['show_sms'] ) ) {
			$metrics = array_values( array_diff( $metrics, Renderer::SMS_KEYS ) );
		}
		if ( ! $private ) {
			$metrics = array_values( array_intersect( $metrics, $public ) );
		}

		$params = array( 'period' => $period, 'compare' => 'true', 'timezone' => Settings::timezone() );
		$result = Reports::aggregate( 'area-dashboard', array( $area ), $params, $private ? 'private' : 'public' );
		if ( is_wp_error( $result ) ) {
			return $private ? self::notice( __( 'The phoneline report is temporarily unavailable.', 'yap-reporting' ) . ' ' . $result->get_error_message() ) : '';
		}

		wp_enqueue_style( 'yapr' );
		return Renderer::area_dashboard(
			$area,
			$result,
			array(
				'style'           => 'compact' === $a['style'] ? 'compact' : 'cards',
				'title'           => sanitize_text_field( (string) $a['title'] ),
				'show_title'      => self::yes( $a['show_title'] ),
				'show_updated'    => self::yes( $a['show_updated'] ),
				'compare'         => self::yes( $a['comparison'] ),
				'icons'           => self::yes( $a['icons'] ),
				'period'          => $period,
				'selectable'      => $selectable,
				'allowed_periods'  => $allowed_periods,
				'explicit_metrics' => '' !== trim( (string) $a['metrics'] ),
			),
			$metrics,
			! $private
		);
	}

	// ---------------------------------------------------------------------------------------

	/** Returns a rendered message when the viewer may not see the report, or null to continue. */
	private static function gate( string $cap ): ?string {
		if ( ! is_user_logged_in() ) {
			return self::notice(
				sprintf(
					/* translators: %s: login URL */
					__( 'Phoneline reports are private. <a href="%s">Log in</a> to view them.', 'yap-reporting' ),
					esc_url( wp_login_url( get_permalink() ?: home_url( '/' ) ) )
				),
				true
			);
		}
		if ( ! current_user_can( $cap ) ) {
			return self::notice( __( 'Your account does not have permission to view this report.', 'yap-reporting' ) );
		}
		return null;
	}

	/** @return int[]|\WP_Error */
	private static function resolve_areas( array $a ) {
		$ids    = Access::parse_ids( $a['areas'] ?: $a['area'] );
		$region = '' !== (string) $a['region'] ? absint( $a['region'] ) : null;
		return Access::resolve( get_current_user_id(), $ids, $region );
	}

	private static function app_container( string $mode, array $config ): string {
		self::enqueue_app();
		return '<div class="yapr yapr-app" data-yapr-mode="' . esc_attr( $mode ) . '" data-yapr-config="' . esc_attr( wp_json_encode( $config ) ) . '">'
			. '<p class="yapr-loading" role="status">' . esc_html__( 'Loading phoneline report…', 'yap-reporting' ) . '</p>'
			. '<noscript><p>' . esc_html__( 'This report needs JavaScript.', 'yap-reporting' ) . '</p></noscript>'
			. '</div>';
	}

	private static function notice( string $message, bool $allow_link = false ): string {
		wp_enqueue_style( 'yapr' );
		$body = $allow_link ? wp_kses( $message, array( 'a' => array( 'href' => array() ) ) ) : esc_html( $message );
		return '<div class="yapr yapr-notice" role="note"><p>' . $body . '</p></div>';
	}

	private static function yes( $v ): bool {
		return in_array( strtolower( (string) $v ), array( 'yes', 'true', '1', 'on' ), true );
	}

	private static function period( string $p ): string {
		return in_array( $p, Settings::PERIODS, true ) ? $p : (string) Settings::get( 'default_period' );
	}

	private static function date( string $d ): string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
	}

	public static function strings(): array {
		return array(
			'overview'        => __( 'Overview', 'yap-reporting' ),
			'calls'           => __( 'Calls', 'yap-reporting' ),
			'volunteers'      => __( 'Volunteers', 'yap-reporting' ),
			'voicemail'       => __( 'Voicemail', 'yap-reporting' ),
			'meetings'        => __( 'Meeting lookups', 'yap-reporting' ),
			'meditations'     => __( 'Meditations', 'yap-reporting' ),
			'sms'             => __( 'Text messages', 'yap-reporting' ),
			'trends'          => __( 'Trends', 'yap-reporting' ),
			'allAreas'        => __( 'All my Areas', 'yap-reporting' ),
			'area'            => __( 'Area', 'yap-reporting' ),
			'period'          => __( 'Period', 'yap-reporting' ),
			'custom'          => __( 'Custom dates', 'yap-reporting' ),
			'from'            => __( 'From', 'yap-reporting' ),
			'to'              => __( 'To', 'yap-reporting' ),
			'apply'           => __( 'Show', 'yap-reporting' ),
			'compare'         => __( 'Compare with previous period', 'yap-reporting' ),
			'loading'         => __( 'Loading…', 'yap-reporting' ),
			'empty'           => __( 'No calls in this period.', 'yap-reporting' ),
			'unavailable'     => __( 'Not available', 'yap-reporting' ),
			'stale'           => __( 'These figures are older than expected; the phone system could not be reached.', 'yap-reporting' ),
			'updated'         => __( 'Updated', 'yap-reporting' ),
			'tz'              => __( 'Times shown in', 'yap-reporting' ),
			'showTable'       => __( 'Show as table', 'yap-reporting' ),
			'hideTable'       => __( 'Hide table', 'yap-reporting' ),
			'export'          => __( 'Download CSV', 'yap-reporting' ),
			'print'           => __( 'Printable report', 'yap-reporting' ),
			'prev'            => __( 'Previous', 'yap-reporting' ),
			'next'            => __( 'Next', 'yap-reporting' ),
			'page'            => __( 'Page', 'yap-reporting' ),
			'of'              => __( 'of', 'yap-reporting' ),
			'details'         => __( 'Details', 'yap-reporting' ),
			'close'           => __( 'Close', 'yap-reporting' ),
			'answered'        => __( 'Answered', 'yap-reporting' ),
			'notAnswered'     => __( 'Not answered', 'yap-reporting' ),
			'any'             => __( 'Any', 'yap-reporting' ),
			'category'        => __( 'Type', 'yap-reporting' ),
			'volunteer'       => __( 'Volunteer', 'yap-reporting' ),
			'minDur'          => __( 'Min. seconds', 'yap-reporting' ),
			'maxDur'          => __( 'Max. seconds', 'yap-reporting' ),
			'errorPrefix'     => __( 'The report could not be loaded:', 'yap-reporting' ),
			'previousPeriod'  => __( 'Previous period', 'yap-reporting' ),
			'thisPeriod'      => __( 'This period', 'yap-reporting' ),
			'callLevel'       => __( 'Counts calls', 'yap-reporting' ),
			'attemptLevel'    => __( 'Counts each time a volunteer was dialed', 'yap-reporting' ),
			'interactionLevel'=> __( 'Counts each automated request', 'yap-reporting' ),
			'messageLevel'    => __( 'Counts text messages', 'yap-reporting' ),
		);
	}
}
