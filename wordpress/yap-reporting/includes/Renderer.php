<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the compact Area Service dashboard on the server, so it needs no JavaScript,
 * works for anonymous visitors in public mode, and never exposes an API endpoint to the browser.
 */
final class Renderer {

	/** Metrics the compact dashboard can show, in display order. */
	public const METRIC_KEYS = array(
		'volunteer_calls', 'calls_answered', 'answer_rate', 'calls_forwarded',
		'meeting_lookups', 'sms_meeting_lookups', 'sms_volunteer_lookups',
		'jft', 'sms_jft', 'spad', 'sms_spad',
	);

	public const MEDITATION_KEYS = array( 'jft', 'sms_jft', 'spad', 'sms_spad' );

	/** Figures YAP stores without a service body: shown as a labelled server-wide total when the Area has none. */
	public const FALLBACK_KEYS = array( 'jft', 'sms_jft', 'spad', 'sms_spad', 'meeting_lookups', 'sms_meeting_lookups', 'sms_volunteer_lookups' );

	/** Text-message figures (hidden with show_sms="no"). */
	public const SMS_KEYS = array( 'sms_meeting_lookups', 'sms_volunteer_lookups', 'sms_jft', 'sms_spad' );

	/** Shown only when non-zero, unless named in the shortcode's metrics attribute. */
	public const OPTIONAL_KEYS = array( 'calls_forwarded' );

	public static function metric_label( string $key ): string {
		$labels = array(
			'volunteer_calls'     => __( 'Volunteer calls', 'yap-reporting' ),
			'calls_answered'      => __( 'Calls answered', 'yap-reporting' ),
			'answer_rate'         => __( 'Answer rate', 'yap-reporting' ),
			'calls_forwarded'     => __( 'Forwarded to helpline number', 'yap-reporting' ),
			'meeting_lookups'       => __( 'Meeting lookups by phone', 'yap-reporting' ),
			'sms_meeting_lookups'   => __( 'Meeting lookups by text', 'yap-reporting' ),
			'sms_volunteer_lookups' => __( 'Volunteer requests by text', 'yap-reporting' ),
			'jft'                   => __( 'Just for Today by phone', 'yap-reporting' ),
			'sms_jft'               => __( 'Just for Today by text', 'yap-reporting' ),
			'spad'                  => __( 'Spiritual Principle a Day by phone', 'yap-reporting' ),
			'sms_spad'              => __( 'Spiritual Principle a Day by text', 'yap-reporting' ),
		);
		return $labels[ $key ] ?? $key;
	}

	public static function period_label( string $period ): string {
		$labels = array(
			'today'        => __( 'Today', 'yap-reporting' ),
			'yesterday'    => __( 'Yesterday', 'yap-reporting' ),
			'7d'           => __( 'Last 7 days', 'yap-reporting' ),
			'30d'          => __( 'Last 30 days', 'yap-reporting' ),
			'45d'          => __( 'Last 45 days', 'yap-reporting' ),
			'60d'          => __( 'Last 60 days', 'yap-reporting' ),
			'90d'          => __( 'Last 90 days', 'yap-reporting' ),
			'this_week'    => __( 'This week', 'yap-reporting' ),
			'last_week'    => __( 'Last week', 'yap-reporting' ),
			'this_month'   => __( 'This month', 'yap-reporting' ),
			'last_month'   => __( 'Last month', 'yap-reporting' ),
			'this_quarter' => __( 'This quarter', 'yap-reporting' ),
			'last_quarter' => __( 'Last quarter', 'yap-reporting' ),
			'this_year'    => __( 'This year', 'yap-reporting' ),
			'last_year'    => __( 'Last year', 'yap-reporting' ),
		);
		return $labels[ $period ] ?? $period;
	}

	/**
	 * @param array  $result   from Reports::aggregate (area-dashboard, compare=true)
	 * @param array  $opts     validated shortcode options
	 * @param string[] $metrics metric keys to show
	 */
	public static function area_dashboard( int $area, array $result, array $opts, array $metrics, bool $public ): string {
		$current  = (array) ( $result['data']['current'] ?? array() );
		$previous = (array) ( $result['data']['previous'] ?? array() );
		$style    = 'compact' === $opts['style'] ? 'compact' : 'cards';

		$id = 'yapr-ad-' . wp_unique_id();
		ob_start();
		?>
		<section class="yapr yapr-area-dashboard yapr-style-<?php echo esc_attr( $style ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>">
			<header class="yapr-ad-head">
				<?php if ( $opts['show_title'] ) : ?>
					<h3 class="yapr-ad-title" id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( '' !== $opts['title'] ? $opts['title'] : Areas::name( $area ) ); ?></h3>
				<?php else : ?>
					<h3 class="screen-reader-text" id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( Areas::name( $area ) ); ?></h3>
				<?php endif; ?>
				<?php echo self::period_control( $opts ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			</header>
			<dl class="yapr-ad-metrics">
				<?php foreach ( $metrics as $key ) : ?>
					<?php echo self::metric_row( $key, $current, $previous, $opts ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
				<?php endforeach; ?>
			</dl>
			<?php echo self::footnotes( $current, $metrics, $result, $opts ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function metric_row( string $key, array $current, array $previous, array $opts ): string {
		$m = $current[ $key ] ?? null;
		if ( in_array( $key, self::OPTIONAL_KEYS, true ) && empty( $opts['explicit_metrics'] ) && ( ! is_array( $m ) || empty( $m['available'] ) || 0 === (int) ( $m['value'] ?? 0 ) ) ) {
			return '';
		}
		if ( in_array( $key, self::FALLBACK_KEYS, true ) && is_array( $m ) && 0 === (int) ( $m['value'] ?? 0 ) && ! empty( $current[ $key . '_installation_wide' ]['available'] ) ) {
			// Area-attributed count is zero but YAP has server-wide figures: show those, clearly labelled.
			$m          = $current[ $key . '_installation_wide' ];
			$m['scope'] = 'installation';
			$previous[ $key ] = $previous[ $key . '_installation_wide' ] ?? null;
		}
		$available = is_array( $m ) && ! empty( $m['available'] );
		$value     = $available ? $m['value'] : null;
		$display   = self::format_value( $key, $value, $available );
		$icon      = $opts['icons'] ? self::icon( $key ) : '';
		$is_rate   = 'answer_rate' === $key;

		$delta = '';
		if ( $opts['compare'] && $available && null !== $value && isset( $previous[ $key ]['value'] ) ) {
			$diff = (float) $value - (float) $previous[ $key ]['value'];
			if ( abs( $diff ) > 0.0001 ) {
				$sign  = $diff > 0 ? '+' : '−';
				$text  = $is_rate ? number_format_i18n( abs( $diff ), 1 ) . ' ' . __( 'pts', 'yap-reporting' ) : number_format_i18n( abs( $diff ) );
				$delta = '<span class="yapr-delta">' . esc_html( $sign . $text ) . ' <span class="screen-reader-text">' . esc_html__( 'compared with the previous period', 'yap-reporting' ) . '</span><span aria-hidden="true">' . esc_html__( 'vs. prior', 'yap-reporting' ) . '</span></span>';
			} else {
				$delta = '<span class="yapr-delta">' . esc_html__( 'Same as prior period', 'yap-reporting' ) . '</span>';
			}
		}

		$scope = '';
		if ( isset( $m['scope'] ) && 'installation' === $m['scope'] ) {
			$scope = '<span class="yapr-scope">' . esc_html__( 'All lines on this phone system', 'yap-reporting' ) . '</span>';
		}

		$meter = '';
		if ( $is_rate && $available && null !== $value ) {
			$pct   = max( 0, min( 100, (float) $value ) );
			$meter = '<span class="yapr-meter" aria-hidden="true"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></span>';
		}

		return '<div class="yapr-ad-row yapr-metric-' . esc_attr( $key ) . ( $available ? '' : ' is-unavailable' ) . '">'
			. '<dt>' . $icon . esc_html( self::metric_label( $key ) ) . $scope . '</dt>'
			. '<dd><span class="yapr-figure">' . esc_html( $display ) . '</span>' . $meter . $delta . '</dd>'
			. '</div>';
	}

	public static function format_value( string $key, $value, bool $available ): string {
		if ( ! $available ) {
			return __( 'Not available', 'yap-reporting' );
		}
		if ( null === $value ) {
			return '—';
		}
		if ( 'answer_rate' === $key ) {
			return number_format_i18n( (float) $value, 1 ) . '%';
		}
		return number_format_i18n( (float) $value );
	}

	private static function period_control( array $opts ): string {
		$label = self::period_label( $opts['period'] );
		if ( ! $opts['selectable'] ) {
			return '<p class="yapr-ad-period">' . esc_html( $label ) . '</p>';
		}
		$options = '';
		foreach ( $opts['allowed_periods'] as $p ) {
			$options .= '<option value="' . esc_attr( $p ) . '"' . selected( $p, $opts['period'], false ) . '>' . esc_html( self::period_label( $p ) ) . '</option>';
		}
		$fid = 'yapr-period-' . wp_unique_id();
		return '<form class="yapr-ad-period" method="get" action="">'
			. self::preserve_query_args()
			. '<label for="' . esc_attr( $fid ) . '" class="screen-reader-text">' . esc_html__( 'Reporting period', 'yap-reporting' ) . '</label>'
			. '<select id="' . esc_attr( $fid ) . '" name="yapr_period" onchange="this.form.submit()">' . $options . '</select>'
			. '<noscript><button type="submit">' . esc_html__( 'Show', 'yap-reporting' ) . '</button></noscript>'
			. '</form>';
	}

	private static function preserve_query_args(): string {
		$out = '';
		foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only display.
			if ( 'yapr_period' === $k || is_array( $v ) ) {
				continue;
			}
			$out .= '<input type="hidden" name="' . esc_attr( sanitize_key( (string) $k ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( (string) $v ) ) ) . '">';
		}
		return $out;
	}

	private static function footnotes( array $current, array $metrics, array $result, array $opts ): string {
		$notes = array();
		if ( $opts['show_updated'] && ! empty( $result['fetched_at'] ) ) {
			$when    = (int) $result['fetched_at'];
			$notes[] = sprintf(
				/* translators: %s: date and time */
				esc_html__( 'Updated %s', 'yap-reporting' ),
				'<time datetime="' . esc_attr( gmdate( 'c', $when ) ) . '">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) ) . '</time>'
			);
		}
		if ( ! empty( $result['stale'] ) ) {
			$notes[] = '<span class="yapr-stale">' . esc_html__( 'These figures may be out of date; the phone system could not be reached recently.', 'yap-reporting' ) . '</span>';
		}
		if ( in_array( 'calls_answered', $metrics, true ) || in_array( 'answer_rate', $metrics, true ) ) {
			$definition = (string) ( $result['meta']['answered_definition'] ?? '' );
			$notes[]    = '' !== $definition
				/* translators: %s: definition from the phone system, e.g. "A volunteer answered, accepted the call and was connected to the caller (as in YAP)." */
				? esc_html( sprintf( __( 'Answered: %s', 'yap-reporting' ), $definition ) )
				: esc_html__( 'A call counts as answered when a volunteer answered and accepted it.', 'yap-reporting' );
		}
		if ( ! $notes ) {
			return '';
		}
		return '<footer class="yapr-ad-foot"><p>' . implode( '</p><p>', $notes ) . '</p></footer>';
	}

	private static function icon( string $key ): string {
		$paths = array(
			'volunteer_calls'     => 'M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z',
			'calls_answered'      => 'M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z',
			'answer_rate'         => 'M3 13h2v8H3zm6-4h2v12H9zm6-6h2v18h-2zm6 9h2v9h-2z',
			'meeting_lookups'     => 'M15.5 14h-.8l-.3-.3A6.5 6.5 0 1 0 14 15.5l.3.3v.8l5 5 1.5-1.5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14z',
			'sms_meeting_lookups' => 'M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z',
			'sms_volunteer_lookups' => 'M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm-8 3a3 3 0 1 1 0 6 3 3 0 0 1 0-6zm6 10H6v-1c0-2 4-3 6-3s6 1 6 3z',
			'jft'                 => 'M21 5c-1.1-.35-2.3-.5-3.5-.5-1.95 0-4.05.4-5.5 1.5-1.45-1.1-3.55-1.5-5.5-1.5S2.45 4.9 1 6v14.65c1.45-1.1 3.55-1.5 5.5-1.5s4.05.4 5.5 1.5c1.35-.85 3.8-1.5 5.5-1.5 1.65 0 3.35.3 4.75 1.05V6c-.6-.45-1.25-.75-2-1z',
			'spad'                => 'M12 2 9.2 8.6 2 9.2l5.5 4.7L5.8 21 12 17.3 18.2 21l-1.7-7.1L22 9.2l-7.2-.6z',
		);
		if ( 'sms_jft' === $key ) {
			$key = 'jft';
		} elseif ( 'sms_spad' === $key ) {
			$key = 'spad';
		}
		if ( ! isset( $paths[ $key ] ) ) {
			return '';
		}
		return '<svg class="yapr-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="' . esc_attr( $paths[ $key ] ) . '"/></svg>';
	}
}
