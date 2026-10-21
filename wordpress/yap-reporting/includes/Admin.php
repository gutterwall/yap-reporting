<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/** Administration screens. Every form posts with a nonce and is re-checked against a capability. */
final class Admin {

	private const SLUG = 'yapr';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'settings_api' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		foreach ( array( 'test_connection', 'bmlt_save', 'bmlt_areas', 'public_save', 'access_save', 'sync', 'clear_cache', 'rebuild', 'setup_done' ) as $action ) {
			add_action( 'admin_post_yapr_' . $action, array( self::class, 'handle_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( YAPR_FILE ), array( self::class, 'action_links' ) );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=yapr-settings' ) ) . '">' . esc_html__( 'Settings', 'yap-reporting' ) . '</a>' );
		return $links;
	}

	public static function menu(): void {
		add_menu_page( __( 'YAP Reporting', 'yap-reporting' ), __( 'YAP Reporting', 'yap-reporting' ), Access::CAP_VIEW_REPORTS, self::SLUG, array( self::class, 'page_dashboard' ), 'dashicons-phone', 58 );
		$pages = array(
			array( self::SLUG, __( 'Dashboard', 'yap-reporting' ), Access::CAP_VIEW_REPORTS, 'page_dashboard' ),
			array( 'yapr-reports', __( 'Reports', 'yap-reporting' ), Access::CAP_VIEW_REPORTS, 'page_reports' ),
			array( 'yapr-calls', __( 'Call Detail', 'yap-reporting' ), Access::CAP_VIEW_CALLS, 'page_calls' ),
			array( 'yapr-volunteers', __( 'Volunteers', 'yap-reporting' ), Access::CAP_VIEW_VOLUNTEERS, 'page_volunteers' ),
			array( 'yapr-area-dashboards', __( 'Area Dashboards', 'yap-reporting' ), Access::CAP_MANAGE_SETTINGS, 'page_area_dashboards' ),
			array( 'yapr-settings', __( 'Settings', 'yap-reporting' ), Access::CAP_MANAGE_SETTINGS, 'page_settings' ),
			array( 'yapr-bmlt', __( 'BMLT Configuration', 'yap-reporting' ), Access::CAP_MANAGE_SETTINGS, 'page_bmlt' ),
			array( 'yapr-cache', __( 'Cache & Synchronization', 'yap-reporting' ), Access::CAP_MANAGE_SETTINGS, 'page_cache' ),
			array( 'yapr-access', __( 'Access Control', 'yap-reporting' ), Access::CAP_MANAGE_ACCESS, 'page_access' ),
			array( 'yapr-diagnostics', __( 'Diagnostics', 'yap-reporting' ), Access::CAP_MANAGE_SETTINGS, 'page_diagnostics' ),
			array( 'yapr-setup', __( 'Setup', 'yap-reporting' ), Access::CAP_MANAGE_SETTINGS, 'page_setup' ),
		);
		foreach ( $pages as [ $slug, $title, $cap, $cb ] ) {
			add_submenu_page( self::SLUG, $title, $title, $cap, $slug, array( self::class, $cb ) );
		}
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'yapr' ) ) {
			return; // Load nothing on unrelated admin screens.
		}
		wp_enqueue_style( 'yapr', YAPR_URL . 'assets/css/yapr.css', array(), YAPR_VERSION );
		wp_enqueue_style( 'yapr-admin', YAPR_URL . 'assets/css/yapr-admin.css', array( 'yapr' ), YAPR_VERSION );
		wp_enqueue_script( 'yapr-admin', YAPR_URL . 'assets/js/yapr-admin.js', array(), YAPR_VERSION, true );
	}

	// ------------------------------------------------------------------------------------------
	// Report pages (reuse the front-end app)
	// ------------------------------------------------------------------------------------------

	public static function page_dashboard(): void {
		self::open( __( 'Phoneline dashboard', 'yap-reporting' ) );
		if ( current_user_can( Access::CAP_MANAGE_SETTINGS ) && ! Settings::get( 'setup_complete' ) ) {
			/* translators: %s: URL of the Setup page */
			echo '<div class="notice notice-info"><p>' . wp_kses_post( sprintf( __( 'Finish connecting this site to YAP on the <a href="%s">Setup</a> page.', 'yap-reporting' ), esc_url( admin_url( 'admin.php?page=yapr-setup' ) ) ) ) . '</p></div>';
		}
		echo Shortcodes::reporting( array( 'view' => 'overview' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderer.
		self::close();
	}

	public static function page_reports(): void {
		self::open( __( 'Reports', 'yap-reporting' ) );
		echo Shortcodes::reporting( array( 'view' => 'all', 'comparison' => 'yes' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		self::close();
	}

	public static function page_calls(): void {
		self::open( __( 'Call detail', 'yap-reporting' ) );
		echo Shortcodes::phoneline_admin( array( 'tab' => 'calls' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		self::close();
	}

	public static function page_volunteers(): void {
		self::open( __( 'Volunteers', 'yap-reporting' ) );
		echo Shortcodes::phoneline_admin( array( 'tab' => 'volunteers' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		self::close();
	}

	// ------------------------------------------------------------------------------------------
	// Settings (WordPress Settings API)
	// ------------------------------------------------------------------------------------------

	public static function settings_api(): void {
		add_filter( 'option_page_capability_yapr_general', static fn() => Access::CAP_MANAGE_SETTINGS );
		register_setting(
			'yapr_general',
			Settings::OPTION,
			array( 'type' => 'array', 'sanitize_callback' => array( self::class, 'sanitize_settings' ), 'show_in_rest' => false )
		);

		add_settings_section( 'yapr_api', __( 'Reporting API', 'yap-reporting' ), static function (): void {
			echo '<p>' . esc_html__( 'The YAP administrator installs the Reporting Webservice on the YAP server and gives you a credential that starts with yapr_.', 'yap-reporting' ) . '</p>';
		}, 'yapr-settings' );
		add_settings_section( 'yapr_defaults', __( 'Report defaults', 'yap-reporting' ), '__return_false', 'yapr-settings' );
		add_settings_section( 'yapr_sync', __( 'Caching', 'yap-reporting' ), '__return_false', 'yapr-settings' );
		add_settings_section( 'yapr_data', __( 'Data', 'yap-reporting' ), '__return_false', 'yapr-settings' );

		$fields = array(
			array( 'api_url', __( 'Reporting API URL', 'yap-reporting' ), 'yapr_api' ),
			array( 'api_token', __( 'API credential', 'yap-reporting' ), 'yapr_api' ),
			array( 'timezone', __( 'Reporting timezone', 'yap-reporting' ), 'yapr_defaults' ),
			array( 'default_area', __( 'Default Area', 'yap-reporting' ), 'yapr_defaults' ),
			array( 'default_period', __( 'Default period', 'yap-reporting' ), 'yapr_defaults' ),
			array( 'chart_style', __( 'Chart style', 'yap-reporting' ), 'yapr_defaults' ),
			array( 'exports_enabled', __( 'Exports', 'yap-reporting' ), 'yapr_defaults' ),
			array( 'show_volunteer_names', __( 'Volunteer names', 'yap-reporting' ), 'yapr_defaults' ),
			array( 'include_unassigned', __( 'Region-wide activity', 'yap-reporting' ), 'yapr_defaults' ),
			array( 'interval_recent', __( 'Refresh recent figures every', 'yap-reporting' ), 'yapr_sync' ),
			array( 'interval_history', __( 'Refresh past periods every', 'yap-reporting' ), 'yapr_sync' ),
			array( 'interval_bmlt', __( 'Refresh service bodies every', 'yap-reporting' ), 'yapr_sync' ),
			array( 'sync_periods', __( 'Periods kept ready', 'yap-reporting' ), 'yapr_sync' ),
			array( 'stale_factor', __( 'Warn when data is older than', 'yap-reporting' ), 'yapr_sync' ),
			array( 'cache_retention_days', __( 'Keep cached figures for', 'yap-reporting' ), 'yapr_data' ),
			array( 'keep_data_on_uninstall', __( 'On uninstall', 'yap-reporting' ), 'yapr_data' ),
		);
		foreach ( $fields as [ $key, $label, $section ] ) {
			add_settings_field( 'yapr_' . $key, $label, array( self::class, 'field' ), 'yapr-settings', $section, array( 'key' => $key, 'label_for' => 'yapr_' . $key ) );
		}
	}

	public static function field( array $args ): void {
		$key  = $args['key'];
		$s    = Settings::all();
		$name = Settings::OPTION . '[' . $key . ']';
		$id   = 'yapr_' . $key;
		switch ( $key ) {
			case 'api_url':
				if ( Settings::api_url_locked() ) {
					echo '<code>' . esc_html( Settings::api_url() ) . '</code><p class="description">' . esc_html__( 'Set by YAPR_API_URL in wp-config.php.', 'yap-reporting' ) . '</p>';
					break;
				}
				printf( '<input type="url" class="regular-text code" id="%s" name="%s" value="%s" placeholder="https://example.org/yap/reporting-api" autocomplete="off">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $s['api_url'] ) );
				echo '<p class="description">' . esc_html__( 'The address of the reporting-api folder on your YAP server. Must use https.', 'yap-reporting' ) . '</p>';
				break;
			case 'api_token':
				$source = Settings::token_source();
				if ( in_array( $source, array( 'constant', 'environment' ), true ) ) {
					echo '<p>' . esc_html( 'constant' === $source ? __( 'Set by YAPR_API_TOKEN in wp-config.php.', 'yap-reporting' ) : __( 'Set by the YAPR_API_TOKEN environment variable.', 'yap-reporting' ) ) . '</p>';
					break;
				}
				if ( 'database' === $source ) {
					echo '<p><code>' . esc_html( Settings::token_hint() ) . '</code> ' . esc_html__( 'saved (encrypted). Enter a new credential only to replace it.', 'yap-reporting' ) . '</p>';
				}
				printf( '<input type="password" class="regular-text code" id="%s" name="yapr_new_token" value="" autocomplete="new-password" spellcheck="false">', esc_attr( $id ) );
				if ( 'database' === $source ) {
					echo '<p><label><input type="checkbox" name="yapr_clear_token" value="1"> ' . esc_html__( 'Remove the saved credential', 'yap-reporting' ) . '</label></p>';
				}
				if ( ! Crypto::available() ) {
					echo '<p class="yapr-warning">' . esc_html__( 'The PHP sodium extension is missing, so a credential cannot be stored safely. Define YAPR_API_TOKEN in wp-config.php instead.', 'yap-reporting' ) . '</p>';
				}
				break;
			case 'timezone':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . wp_timezone_choice( (string) $s['timezone'], get_user_locale() ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput -- core-generated options.
				break;
			case 'default_area':
				self::area_select( $name, $id, (int) $s['default_area'], Areas::managed_ids(), true );
				break;
			case 'default_period':
				self::select( $name, $id, (string) $s['default_period'], array_combine( Settings::PERIODS, array_map( array( Renderer::class, 'period_label' ), Settings::PERIODS ) ) );
				break;
			case 'chart_style':
				self::select( $name, $id, (string) $s['chart_style'], array( 'bars' => __( 'Bars', 'yap-reporting' ), 'lines' => __( 'Lines', 'yap-reporting' ) ) );
				break;
			case 'exports_enabled':
				self::checkbox( $name, $id, (bool) $s['exports_enabled'], __( 'Allow users with the export permission to download CSV and printable reports', 'yap-reporting' ) );
				break;
			case 'show_volunteer_names':
				self::checkbox( $name, $id, (bool) $s['show_volunteer_names'], __( 'Show volunteer names to Phoneline Administrators (otherwise anonymous ids are shown)', 'yap-reporting' ) );
				break;
			case 'include_unassigned':
				self::checkbox( $name, $id, (bool) $s['include_unassigned'], __( 'Include meeting searches, Just for Today, SPAD and text messages in "All my Areas" (YAP does not tie them to an Area). Shown to users who can see every selected Area.', 'yap-reporting' ) );
				if ( ! Access::credential_allows_unassigned() ) {
					echo '<p class="yapr-warning">' . esc_html__( 'The API credential does not allow this yet: on the YAP server setup page, choose "Change permissions" and tick "Include unassigned activity" (or "All service bodies"), then Test connection.', 'yap-reporting' ) . '</p>';
				}
				break;
			case 'interval_recent':
			case 'interval_history':
			case 'interval_bmlt':
				$limits = array( 'interval_recent' => array( 5, 1440 ), 'interval_history' => array( 30, 10080 ), 'interval_bmlt' => array( 60, 10080 ) );
				printf( '<input type="number" class="small-text" id="%s" name="%s" value="%d" min="%d" max="%d"> %s', esc_attr( $id ), esc_attr( $name ), (int) $s[ $key ], (int) $limits[ $key ][0], (int) $limits[ $key ][1], esc_html__( 'minutes', 'yap-reporting' ) );
				break;
			case 'sync_periods':
				foreach ( Settings::PERIODS as $p ) {
					printf( '<label class="yapr-inline-check"><input type="checkbox" name="%s[]" value="%s"%s> %s</label>', esc_attr( $name ), esc_attr( $p ), checked( in_array( $p, (array) $s['sync_periods'], true ), true, false ), esc_html( Renderer::period_label( $p ) ) );
				}
				echo '<p class="description">' . esc_html__( 'Area dashboards for these periods are refreshed in the background so pages load instantly. Up to 6.', 'yap-reporting' ) . '</p>';
				break;
			case 'stale_factor':
				printf( '<input type="number" class="small-text" id="%s" name="%s" value="%d" min="2" max="20"> %s', esc_attr( $id ), esc_attr( $name ), (int) $s['stale_factor'], esc_html__( '× the refresh interval', 'yap-reporting' ) );
				break;
			case 'cache_retention_days':
				printf( '<input type="number" class="small-text" id="%s" name="%s" value="%d" min="1" max="365"> %s', esc_attr( $id ), esc_attr( $name ), (int) $s['cache_retention_days'], esc_html__( 'days', 'yap-reporting' ) );
				echo '<p class="description">' . esc_html__( 'Only aggregate figures are cached. Individual calls are never stored in WordPress.', 'yap-reporting' ) . '</p>';
				break;
			case 'keep_data_on_uninstall':
				self::checkbox( $name, $id, (bool) $s['keep_data_on_uninstall'], __( 'Keep settings, Area selections and access rules when the plugin is deleted (the credential and caches are always removed)', 'yap-reporting' ) );
				break;
		}
	}

	public static function sanitize_settings( $input ) {
		// Only process submissions from this settings page; programmatic updates pass through.
		if ( ! isset( $_POST['option_page'] ) || 'yapr_general' !== $_POST['option_page'] || ! current_user_can( Access::CAP_MANAGE_SETTINGS ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- options.php verifies the nonce.
			return $input;
		}
		$in  = is_array( $input ) ? $input : array();
		$old = Settings::all();
		$out = $old;

		if ( ! Settings::api_url_locked() && isset( $in['api_url'] ) ) {
			$url = esc_url_raw( trim( (string) $in['api_url'] ), array( 'https' ) );
			$url = untrailingslashit( preg_replace( '#/api/v1/?$#', '', (string) $url ) );
			if ( '' === $url ) {
				$out['api_url'] = '';
			} else {
				$valid = UrlGuard::validate( $url );
				if ( is_wp_error( $valid ) ) {
					add_settings_error( 'yapr', 'api_url', $valid->get_error_message() );
				} else {
					$out['api_url'] = $url;
				}
			}
		}

		$new_token = isset( $_POST['yapr_new_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['yapr_new_token'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $new_token ) {
			if ( ! preg_match( '/^yapr_[0-9a-f]{12}_[A-Za-z0-9_\-]{43}$/', $new_token ) ) {
				add_settings_error( 'yapr', 'api_token', __( 'That does not look like a YAP Reporting credential (it should start with yapr_). The saved credential was not changed.', 'yap-reporting' ) );
			} else {
				try {
					Settings::set_token( $new_token );
					add_settings_error( 'yapr', 'api_token', __( 'Credential saved. Use Test connection on the Diagnostics page to confirm it works.', 'yap-reporting' ), 'success' );
				} catch ( \RuntimeException $e ) {
					add_settings_error( 'yapr', 'api_token', $e->getMessage() );
				}
			}
		} elseif ( ! empty( $_POST['yapr_clear_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			Settings::clear_token();
		}

		if ( isset( $in['timezone'] ) && in_array( $in['timezone'], timezone_identifiers_list(), true ) ) {
			$out['timezone'] = $in['timezone'];
		}
		$out['default_area']   = isset( $in['default_area'] ) ? absint( $in['default_area'] ) : $old['default_area'];
		$out['default_period'] = isset( $in['default_period'] ) && in_array( $in['default_period'], Settings::PERIODS, true ) ? $in['default_period'] : $old['default_period'];
		$out['chart_style']    = isset( $in['chart_style'] ) && in_array( $in['chart_style'], array( 'bars', 'lines' ), true ) ? $in['chart_style'] : $old['chart_style'];
		foreach ( array( 'exports_enabled', 'show_volunteer_names', 'keep_data_on_uninstall', 'include_unassigned' ) as $box ) {
			$out[ $box ] = empty( $in[ $box ] ) ? 0 : 1;
		}
		$out['interval_recent']      = self::clamp( $in['interval_recent'] ?? $old['interval_recent'], 5, 1440 );
		$out['interval_history']     = self::clamp( $in['interval_history'] ?? $old['interval_history'], 30, 10080 );
		$out['interval_bmlt']        = self::clamp( $in['interval_bmlt'] ?? $old['interval_bmlt'], 60, 10080 );
		$out['stale_factor']         = self::clamp( $in['stale_factor'] ?? $old['stale_factor'], 2, 20 );
		$out['cache_retention_days'] = self::clamp( $in['cache_retention_days'] ?? $old['cache_retention_days'], 1, 365 );
		$periods                     = array_values( array_intersect( Settings::PERIODS, (array) ( $in['sync_periods'] ?? array() ) ) );
		$out['sync_periods']         = array_slice( $periods ?: array( '30d' ), 0, 6 );

		if ( $out['interval_recent'] !== $old['interval_recent'] || $out['interval_history'] !== $old['interval_history'] || $out['interval_bmlt'] !== $old['interval_bmlt'] ) {
			add_action( 'shutdown', array( Sync::class, 'reschedule' ) );
		}
		return $out;
	}

	public static function page_settings(): void {
		self::open( __( 'Settings', 'yap-reporting' ) );
		settings_errors( 'yapr' );
		echo '<form method="post" action="options.php" autocomplete="off">';
		settings_fields( 'yapr_general' );
		do_settings_sections( 'yapr-settings' );
		submit_button( __( 'Save settings', 'yap-reporting' ) );
		echo '</form>';
		self::action_button( 'test_connection', __( 'Test connection', 'yap-reporting' ), array(), 'secondary' );
		self::close();
	}

	// ------------------------------------------------------------------------------------------
	// BMLT configuration
	// ------------------------------------------------------------------------------------------

	public static function page_bmlt(): void {
		self::open( __( 'BMLT configuration', 'yap-reporting' ) );
		$s = Settings::all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yapr-card">
			<?php wp_nonce_field( 'yapr_bmlt_save' ); ?>
			<input type="hidden" name="action" value="yapr_bmlt_save">
			<h2><?php esc_html_e( 'Root server', 'yap-reporting' ); ?></h2>
			<p><label for="yapr_bmlt_root"><?php esc_html_e( 'BMLT root server URL', 'yap-reporting' ); ?></label><br>
			<input type="url" class="regular-text code" id="yapr_bmlt_root" name="bmlt_root" value="<?php echo esc_attr( (string) $s['bmlt_root'] ); ?>" placeholder="https://bmlt.example.org/main_server"></p>
			<p class="description"><?php esc_html_e( 'Use the same root server YAP uses, so service body ids match.', 'yap-reporting' ); ?></p>
			<p>
				<button class="button" name="do" value="save"><?php esc_html_e( 'Save', 'yap-reporting' ); ?></button>
				<button class="button button-primary" name="do" value="refresh"><?php esc_html_e( 'Save and refresh service bodies', 'yap-reporting' ); ?></button>
			</p>
			<?php
			$synced = (int) get_option( 'yapr_bmlt_synced_at' );
			if ( $synced ) {
				/* translators: %s: human time difference */
				echo '<p class="description">' . esc_html( sprintf( __( 'Service bodies last refreshed %s ago.', 'yap-reporting' ), human_time_diff( $synced ) ) ) . '</p>';
			}
			?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yapr-card">
			<?php wp_nonce_field( 'yapr_bmlt_areas' ); ?>
			<input type="hidden" name="action" value="yapr_bmlt_areas">
			<h2><?php esc_html_e( 'Areas this site reports on', 'yap-reporting' ); ?></h2>
			<?php
			$tree = Areas::tree();
			if ( ! $tree ) {
				echo '<p>' . esc_html__( 'Refresh service bodies first.', 'yap-reporting' ) . '</p>';
			} else {
				echo '<p><label><input type="checkbox" id="yapr-include-children" name="include_children" value="1"' . checked( (bool) $s['include_children'], true, false ) . '> ' . esc_html__( 'Selecting a Region also selects its Areas', 'yap-reporting' ) . '</label></p>';
				echo '<div class="yapr-tree" role="group" aria-label="' . esc_attr__( 'Service bodies', 'yap-reporting' ) . '">';
				self::tree_list( $tree );
				echo '</div>';
			}
			?>
			<p><?php submit_button( __( 'Save Area selection', 'yap-reporting' ), 'primary', 'submit', false ); ?></p>
		</form>
		<?php
		self::mapping_overview();
		self::close();
	}

	private static function tree_list( array $nodes ): void {
		echo '<ul>';
		foreach ( $nodes as $n ) {
			$type = '' !== $n['type'] ? strtoupper( $n['type'] ) : '';
			echo '<li><label><input type="checkbox" class="yapr-sb" name="managed[]" value="' . esc_attr( (string) $n['id'] ) . '"' . checked( $n['managed'], true, false ) . '> '
				. esc_html( $n['name'] ) . ' <span class="yapr-muted">#' . esc_html( (string) $n['id'] ) . ( $type ? ' ' . esc_html( $type ) : '' ) . ( $n['present_in_bmlt'] ? '' : ' — ' . esc_html__( 'no longer in BMLT', 'yap-reporting' ) ) . '</span></label>';
			if ( $n['children'] ) {
				self::tree_list( $n['children'] );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/** Compare service bodies seen in YAP with the BMLT list; let admins label unknown ids. */
	private static function mapping_overview(): void {
		echo '<div class="yapr-card"><h2>' . esc_html__( 'YAP to BMLT mapping', 'yap-reporting' ) . '</h2>';
		$result = ApiClient::get( 'areas', array( 'period' => '90d', 'timezone' => Settings::timezone() ) );
		if ( is_wp_error( $result ) ) {
			echo '<p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			return;
		}
		$rows = (array) ( $result['data']['service_bodies'] ?? array() );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'YAP reported no activity in the last 90 days for the service bodies this credential covers.', 'yap-reporting' ) . '</p></div>';
			return;
		}
		$labels = (array) Settings::get( 'area_labels' );
		echo '<p>' . esc_html__( 'Service bodies with phoneline activity in the last 90 days, as recorded by YAP.', 'yap-reporting' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'yapr_bmlt_areas' );
		echo '<input type="hidden" name="action" value="yapr_bmlt_areas"><input type="hidden" name="labels_only" value="1">';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'YAP service body id', 'yap-reporting' ) . '</th><th>' . esc_html__( 'BMLT name', 'yap-reporting' ) . '</th><th>' . esc_html__( 'Reported on this site', 'yap-reporting' ) . '</th><th>' . esc_html__( 'Calls', 'yap-reporting' ) . '</th><th>' . esc_html__( 'Display name override', 'yap-reporting' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$id = $r['service_body_id'];
			if ( null === $id ) {
				echo '<tr><td>—</td><td>' . esc_html__( 'Unassigned (no service body recorded)', 'yap-reporting' ) . '</td><td>—</td><td>' . esc_html( (string) $r['calls'] ) . '</td><td></td></tr>';
				continue;
			}
			$id      = (int) $id;
			$known   = Areas::exists( $id );
			$name    = $known ? Areas::all()[ $id ]['name'] : __( 'Not found in BMLT', 'yap-reporting' );
			$managed = Areas::is_managed( $id ) ? __( 'Yes', 'yap-reporting' ) : __( 'No', 'yap-reporting' );
			echo '<tr><td>' . esc_html( (string) $id ) . '</td><td>' . esc_html( $name ) . '</td><td>' . esc_html( $managed ) . '</td><td>' . esc_html( (string) $r['calls'] ) . '</td>'
				. '<td><input type="text" name="labels[' . esc_attr( (string) $id ) . ']" value="' . esc_attr( (string) ( $labels[ $id ] ?? '' ) ) . '" class="regular-text">'
				. ( $known ? '' : ' <label><input type="checkbox" name="adopt[]" value="' . esc_attr( (string) $id ) . '"> ' . esc_html__( 'Report on it', 'yap-reporting' ) . '</label>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save names', 'yap-reporting' ), 'secondary' );
		echo '</form></div>';
	}

	public static function handle_bmlt_save(): void {
		self::guard( 'yapr_bmlt_save', Access::CAP_MANAGE_SETTINGS );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by self::guard().
		$root = isset( $_POST['bmlt_root'] ) ? untrailingslashit( esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['bmlt_root'] ) ) ), array( 'https' ) ) ) : '';
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		// phpcs:enable
		if ( '' !== $root ) {
			$valid = UrlGuard::validate( $root );
			if ( is_wp_error( $valid ) ) {
				self::redirect( 'yapr-bmlt', $valid->get_error_message(), 'error' );
			}
		}
		Settings::update( array( 'bmlt_root' => $root ) );
		if ( 'refresh' === $do ) {
			$result = Sync::run( 'bmlt', true );
			/* translators: %d: number of service bodies */
			self::redirect( 'yapr-bmlt', $result['ok'] ? sprintf( __( 'Loaded %d service bodies.', 'yap-reporting' ), $result['items'] ) : $result['message'], $result['ok'] ? 'success' : 'error' );
		}
		self::redirect( 'yapr-bmlt', __( 'Saved.', 'yap-reporting' ) );
	}

	public static function handle_bmlt_areas(): void {
		self::guard( 'yapr_bmlt_areas', Access::CAP_MANAGE_SETTINGS );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by self::guard().
		if ( ! empty( $_POST['labels_only'] ) ) {
			$labels = array();
			$posted = isset( $_POST['labels'] ) && is_array( $_POST['labels'] ) ? map_deep( wp_unslash( $_POST['labels'] ), 'sanitize_text_field' ) : array();
			foreach ( $posted as $id => $label ) {
				$label = is_string( $label ) ? $label : '';
				if ( absint( $id ) > 0 && '' !== $label ) {
					$labels[ absint( $id ) ] = substr( $label, 0, 120 );
				}
			}
			Settings::update( array( 'area_labels' => $labels ) );
			foreach ( array_map( 'absint', isset( $_POST['adopt'] ) ? (array) wp_unslash( $_POST['adopt'] ) : array() ) as $id ) {
				if ( $id > 0 && ! Areas::exists( $id ) ) {
					global $wpdb;
					$wpdb->insert( Installer::table( 'areas' ), array( 'id' => $id, 'parent_id' => 0, 'name' => $labels[ $id ] ?? '', 'type' => '', 'managed' => 1, 'present_in_bmlt' => 0, 'updated_at' => current_time( 'mysql', true ) ) );
				}
			}
			Areas::flush();
			self::redirect( 'yapr-bmlt', __( 'Names saved.', 'yap-reporting' ) );
		}
		$ids = array_map( 'absint', isset( $_POST['managed'] ) ? (array) wp_unslash( $_POST['managed'] ) : array() );
		Areas::set_managed( $ids );
		Settings::update( array( 'include_children' => empty( $_POST['include_children'] ) ? 0 : 1 ) );
		// phpcs:enable
		Cache::clear();
		/* translators: %d: number of Areas */
		self::redirect( 'yapr-bmlt', sprintf( __( '%d service bodies selected. Cached figures were cleared and will refresh on the next sync.', 'yap-reporting' ), count( Areas::managed_ids() ) ) );
	}

	// ------------------------------------------------------------------------------------------
	// Area dashboards (shortcode builder + public mode)
	// ------------------------------------------------------------------------------------------

	public static function page_area_dashboards(): void {
		self::open( __( 'Area dashboards', 'yap-reporting' ) );
		$s       = Settings::all();
		$managed = Areas::managed_ids();
		?>
		<div class="yapr-card">
			<h2><?php esc_html_e( 'Shortcode', 'yap-reporting' ); ?></h2>
			<p><?php esc_html_e( 'Paste one of these into a page. Each Area’s dashboard is private unless you publish it below.', 'yap-reporting' ); ?></p>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Area', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Shortcode', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Visibility', 'yap-reporting' ); ?></th></tr></thead><tbody>
			<?php foreach ( $managed as $id ) : ?>
				<tr>
					<td><?php echo esc_html( Areas::name( $id ) ); ?></td>
					<td><code>[yap_area_dashboard area="<?php echo esc_html( (string) $id ); ?>" period="30d"]</code></td>
					<td><?php echo in_array( $id, array_map( 'intval', (array) $s['public_areas'] ), true ) ? esc_html__( 'Public aggregates', 'yap-reporting' ) : esc_html__( 'Private (logged-in, authorized)', 'yap-reporting' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="description"><?php esc_html_e( 'Options: period, style="cards|compact", title, show_title, show_updated, show_meditations, show_sms, comparison, icons, selectable="yes", metrics="volunteer_calls,calls_answered,…". See docs/SHORTCODES.md.', 'yap-reporting' ); ?></p>
		</div>

		<?php if ( $managed ) : ?>
		<div class="yapr-card">
			<h2><?php esc_html_e( 'Preview', 'yap-reporting' ); ?></h2>
			<?php echo Shortcodes::area_dashboard( array( 'area' => (string) ( $s['default_area'] ?: $managed[0] ), 'comparison' => 'yes' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yapr-card">
			<?php wp_nonce_field( 'yapr_public_save' ); ?>
			<input type="hidden" name="action" value="yapr_public_save">
			<h2><?php esc_html_e( 'Public aggregate mode', 'yap-reporting' ); ?></h2>
			<p><?php esc_html_e( 'Publishing lets anyone who visits the page see the chosen totals for that Area. Volunteer names, caller information and individual calls are never shown publicly.', 'yap-reporting' ); ?></p>
			<fieldset><legend class="yapr-legend"><?php esc_html_e( 'Publish these Areas', 'yap-reporting' ); ?></legend>
			<?php foreach ( $managed as $id ) : ?>
				<label class="yapr-inline-check"><input type="checkbox" name="public_areas[]" value="<?php echo esc_attr( (string) $id ); ?>" <?php checked( in_array( $id, array_map( 'intval', (array) $s['public_areas'] ), true ) ); ?>> <?php echo esc_html( Areas::name( $id ) ); ?></label>
			<?php endforeach; ?>
			</fieldset>
			<fieldset><legend class="yapr-legend"><?php esc_html_e( 'Figures that may be published', 'yap-reporting' ); ?></legend>
			<?php foreach ( Renderer::METRIC_KEYS as $k ) : ?>
				<label class="yapr-inline-check"><input type="checkbox" name="public_metrics[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $s['public_metrics'], true ) ); ?>> <?php echo esc_html( Renderer::metric_label( $k ) ); ?></label>
			<?php endforeach; ?>
			</fieldset>
			<p class="description"><?php esc_html_e( 'Small numbers are shown as they are. With few calls in a period, consider publishing only monthly or longer periods.', 'yap-reporting' ); ?></p>
			<?php submit_button( __( 'Save public settings', 'yap-reporting' ) ); ?>
		</form>
		<?php
		self::close();
	}

	public static function handle_public_save(): void {
		self::guard( 'yapr_public_save', Access::CAP_MANAGE_SETTINGS );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by self::guard().
		$areas   = array_values( array_intersect( Areas::managed_ids(), array_map( 'absint', isset( $_POST['public_areas'] ) ? (array) wp_unslash( $_POST['public_areas'] ) : array() ) ) );
		$metrics = array_values( array_intersect( Renderer::METRIC_KEYS, array_map( 'sanitize_key', isset( $_POST['public_metrics'] ) ? (array) wp_unslash( $_POST['public_metrics'] ) : array() ) ) );
		// phpcs:enable
		Settings::update( array( 'public_areas' => $areas, 'public_metrics' => $metrics ) );
		Cache::clear( 'public' ); // Previously published payloads may include metrics no longer permitted.
		self::redirect( 'yapr-area-dashboards', __( 'Public settings saved.', 'yap-reporting' ) );
	}

	// ------------------------------------------------------------------------------------------
	// Access control
	// ------------------------------------------------------------------------------------------

	public static function page_access(): void {
		self::open( __( 'Access control', 'yap-reporting' ) );
		$grants  = (array) Settings::get( 'role_areas' );
		$managed = Areas::managed_ids();
		$roles   = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			if ( ! empty( $role['capabilities'][ Access::CAP_VIEW_REPORTS ] ) && 'administrator' !== $slug ) {
				$roles[ $slug ] = translate_user_role( $role['name'] );
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'yapr_access_save' ); ?>
			<input type="hidden" name="action" value="yapr_access_save">
			<div class="yapr-card">
				<h2><?php esc_html_e( 'Areas by role', 'yap-reporting' ); ?></h2>
				<p><?php esc_html_e( 'Administrators always see every selected Area. Other roles see nothing until you grant Areas here or per user below.', 'yap-reporting' ); ?></p>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Role', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Permissions', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Areas', 'yap-reporting' ); ?></th></tr></thead><tbody>
				<?php foreach ( $roles as $slug => $label ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><?php echo esc_html( self::caps_summary( (array) wp_roles()->roles[ $slug ]['capabilities'] ) ); ?></td>
						<td><?php self::grant_picker( 'role[' . $slug . ']', $grants[ $slug ] ?? array(), $managed, false ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>

			<div class="yapr-card">
				<h2><?php esc_html_e( 'Individual users', 'yap-reporting' ); ?></h2>
				<p><?php esc_html_e( 'A user setting replaces the role’s Areas for that person.', 'yap-reporting' ); ?></p>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'User', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Role', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Areas', 'yap-reporting' ); ?></th></tr></thead><tbody>
				<?php
				$users = get_users(
					array(
						'role__in' => array_keys( $roles ),
						'number'   => 200,
						'orderby'  => 'display_name',
					)
				);
				foreach ( $users as $u ) :
					$override = get_user_meta( $u->ID, Access::USER_META, true );
					?>
					<tr>
						<td><?php echo esc_html( $u->display_name ); ?><br><span class="yapr-muted"><?php echo esc_html( $u->user_login ); ?></span></td>
						<td><?php echo esc_html( implode( ', ', array_map( static fn( $r ) => $roles[ $r ] ?? $r, (array) $u->roles ) ) ); ?></td>
						<td><?php self::grant_picker( 'user[' . $u->ID . ']', '' === $override ? 'inherit' : $override, $managed, true ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php /* translators: %s: URL of the Users screen */ ?>
				<p><?php echo wp_kses_post( sprintf( __( 'To add someone, give them the Phoneline Administrator or Area Reporter role on the <a href="%s">Users</a> screen.', 'yap-reporting' ), esc_url( admin_url( 'users.php' ) ) ) ); ?></p>
			</div>
			<?php submit_button( __( 'Save access', 'yap-reporting' ) ); ?>
		</form>
		<?php
		self::close();
	}

	private static function caps_summary( array $caps ): string {
		$names = array(
			Access::CAP_VIEW_REPORTS    => __( 'reports', 'yap-reporting' ),
			Access::CAP_VIEW_CALLS      => __( 'call detail', 'yap-reporting' ),
			Access::CAP_VIEW_VOLUNTEERS => __( 'volunteers', 'yap-reporting' ),
			Access::CAP_EXPORT          => __( 'export', 'yap-reporting' ),
		);
		$out = array();
		foreach ( $names as $cap => $label ) {
			if ( ! empty( $caps[ $cap ] ) ) {
				$out[] = $label;
			}
		}
		return implode( ', ', $out );
	}

	/** @param string|array $current 'all' | 'inherit' | int[] */
	private static function grant_picker( string $name, $current, array $managed, bool $allow_inherit ): void {
		$mode = is_array( $current ) ? ( $current ? 'some' : ( $allow_inherit ? 'inherit' : 'none' ) ) : (string) $current;
		echo '<select name="' . esc_attr( $name ) . '[mode]" class="yapr-grant-mode">';
		if ( $allow_inherit ) {
			echo '<option value="inherit"' . selected( $mode, 'inherit', false ) . '>' . esc_html__( 'Same as role', 'yap-reporting' ) . '</option>';
		} else {
			echo '<option value="none"' . selected( $mode, 'none', false ) . '>' . esc_html__( 'No Areas', 'yap-reporting' ) . '</option>';
		}
		echo '<option value="all"' . selected( $mode, 'all', false ) . '>' . esc_html__( 'All selected Areas', 'yap-reporting' ) . '</option>';
		echo '<option value="some"' . selected( $mode, 'some', false ) . '>' . esc_html__( 'Only these Areas…', 'yap-reporting' ) . '</option>';
		echo '</select><div class="yapr-grant-areas">';
		foreach ( $managed as $id ) {
			$on = is_array( $current ) && in_array( $id, array_map( 'intval', $current ), true );
			echo '<label class="yapr-inline-check"><input type="checkbox" name="' . esc_attr( $name ) . '[areas][]" value="' . esc_attr( (string) $id ) . '"' . checked( $on, true, false ) . '> ' . esc_html( Areas::name( $id ) ) . '</label>';
		}
		echo '</div>';
	}

	public static function handle_access_save(): void {
		self::guard( 'yapr_access_save', Access::CAP_MANAGE_ACCESS );
		$managed = Areas::managed_ids();
		$parse   = static function ( $row ) use ( $managed ) {
			$mode = is_array( $row ) ? sanitize_key( (string) ( $row['mode'] ?? '' ) ) : '';
			if ( 'all' === $mode ) {
				return 'all';
			}
			if ( 'some' === $mode ) {
				return array_values( array_intersect( $managed, array_map( 'absint', (array) ( $row['areas'] ?? array() ) ) ) );
			}
			return 'inherit' === $mode ? 'inherit' : array();
		};

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by self::guard(); keys and values are sanitised below and in $parse.
		$grants = array();
		foreach ( isset( $_POST['role'] ) ? (array) wp_unslash( $_POST['role'] ) : array() as $role => $row ) {
			$role = sanitize_key( (string) $role );
			if ( get_role( $role ) && 'administrator' !== $role ) {
				$g = $parse( $row );
				$grants[ $role ] = 'inherit' === $g ? array() : $g;
			}
		}
		Settings::update( array( 'role_areas' => $grants ) );

		foreach ( isset( $_POST['user'] ) ? (array) wp_unslash( $_POST['user'] ) : array() as $user_id => $row ) {
			$user_id = absint( $user_id );
			if ( ! $user_id || ! get_userdata( $user_id ) ) {
				continue;
			}
			$g = $parse( $row );
			if ( 'inherit' === $g || array() === $g ) {
				delete_user_meta( $user_id, Access::USER_META );
			} else {
				update_user_meta( $user_id, Access::USER_META, $g );
			}
		}
		// phpcs:enable
		self::redirect( 'yapr-access', __( 'Access saved.', 'yap-reporting' ) );
	}

	// ------------------------------------------------------------------------------------------
	// Cache & synchronization
	// ------------------------------------------------------------------------------------------

	public static function page_cache(): void {
		self::open( __( 'Cache and synchronization', 'yap-reporting' ) );
		$stats = Cache::stats();
		$fmt   = static fn( $v ) => $v ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), is_numeric( $v ) ? (int) $v : (int) strtotime( $v . ' UTC' ) ) : '—';
		?>
		<div class="yapr-card">
			<h2><?php esc_html_e( 'Background jobs', 'yap-reporting' ); ?></h2>
			<table class="widefat striped"><thead><tr>
				<th><?php esc_html_e( 'Job', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Last success', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Last attempt', 'yap-reporting' ); ?></th>
				<th><?php esc_html_e( 'Next run', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Reports', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Duration', 'yap-reporting' ); ?></th><th><?php esc_html_e( 'Problems', 'yap-reporting' ); ?></th>
			</tr></thead><tbody>
			<?php
			$labels = array( 'recent' => __( 'Recent figures', 'yap-reporting' ), 'history' => __( 'Past periods', 'yap-reporting' ), 'bmlt' => __( 'Service bodies', 'yap-reporting' ) );
			foreach ( Sync::JOBS as $job ) :
				$st     = Sync::state( $job );
				$failed = array_filter( array_map( 'intval', explode( ',', (string) $st['failed_areas'] ) ) );
				?>
				<tr>
					<td><?php echo esc_html( $labels[ $job ] ); ?></td>
					<td><?php echo esc_html( $fmt( $st['last_success'] ) ); ?></td>
					<td><?php echo esc_html( $fmt( $st['last_attempt'] ) ); ?></td>
					<td><?php echo esc_html( $fmt( wp_next_scheduled( 'yapr_sync_' . $job ) ) ); ?><?php echo $st['next_attempt'] ? '<br><span class="yapr-muted">' . esc_html__( 'retrying after failures', 'yap-reporting' ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( (string) $st['items'] ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $st['duration_ms'] / 1000, 1 ) . ' s' ); ?></td>
					<td><?php echo $st['last_error'] ? esc_html( ApiClient::safe_message( (string) $st['last_error'] ) ) : '—'; ?><?php echo $failed ? '<br>' . esc_html__( 'Areas:', 'yap-reporting' ) . ' ' . esc_html( implode( ', ', array_map( array( Areas::class, 'name' ), $failed ) ) ) : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<p class="description"><?php esc_html_e( 'WP-Cron is disabled on this site; make sure a server cron job runs wp-cron.php (see docs/CACHING.md).', 'yap-reporting' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="yapr-card">
			<h2><?php esc_html_e( 'Cached figures', 'yap-reporting' ); ?></h2>
			<p>
			<?php
			/* translators: 1: total records 2: public records */
			echo esc_html( sprintf( __( '%1$d cached reports (%2$d public).', 'yap-reporting' ), $stats['records'], $stats['public'] ) );
			if ( $stats['newest'] ) {
				/* translators: 1: time difference */
				echo ' ' . esc_html( sprintf( __( 'Newest is %s old.', 'yap-reporting' ), human_time_diff( $stats['newest'] ) ) );
			}
			?>
			</p>
			<div class="yapr-actions">
				<?php
				self::action_button( 'test_connection', __( 'Test connection', 'yap-reporting' ) );
				self::action_button( 'sync', __( 'Sync now', 'yap-reporting' ), array( 'job' => 'recent' ), 'primary' );
				self::action_button( 'sync', __( 'Refresh Areas', 'yap-reporting' ), array( 'job' => 'bmlt' ) );
				self::action_button( 'rebuild', __( 'Rebuild statistics', 'yap-reporting' ) );
				self::action_button( 'clear_cache', __( 'Clear cache', 'yap-reporting' ) );
				?>
			</div>
		</div>
		<?php
		self::close();
	}

	public static function handle_sync(): void {
		self::guard( 'yapr_sync', Access::CAP_MANAGE_SETTINGS );
		$job    = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : 'recent'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by self::guard().
		$result = Sync::run( in_array( $job, Sync::JOBS, true ) ? $job : 'recent', true );
		self::redirect( 'yapr-cache', $result['message'], $result['ok'] ? 'success' : 'error' );
	}

	public static function handle_rebuild(): void {
		self::guard( 'yapr_rebuild', Access::CAP_MANAGE_SETTINGS );
		Cache::clear();
		$a = Sync::run( 'recent', true );
		$b = Sync::run( 'history', true );
		/* translators: %d: count */
		self::redirect( 'yapr-cache', sprintf( __( 'Rebuilt %d reports.', 'yap-reporting' ), $a['items'] + $b['items'] ) . ( $a['ok'] && $b['ok'] ? '' : ' ' . $a['message'] . ' ' . $b['message'] ), $a['ok'] && $b['ok'] ? 'success' : 'error' );
	}

	public static function handle_clear_cache(): void {
		self::guard( 'yapr_clear_cache', Access::CAP_MANAGE_SETTINGS );
		/* translators: %d: count */
		self::redirect( 'yapr-cache', sprintf( __( 'Removed %d cached reports.', 'yap-reporting' ), Cache::clear() ) );
	}

	public static function handle_test_connection(): void {
		self::guard( 'yapr_test_connection', Access::CAP_MANAGE_SETTINGS );
		$r    = ApiClient::test_connection();
		$back = isset( $_POST['back'] ) ? sanitize_key( wp_unslash( $_POST['back'] ) ) : 'yapr-diagnostics'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by self::guard().
		self::redirect( 0 === strpos( $back, 'yapr' ) ? $back : 'yapr-diagnostics', $r['message'], $r['ok'] ? 'success' : 'error' );
	}

	// ------------------------------------------------------------------------------------------
	// Diagnostics
	// ------------------------------------------------------------------------------------------

	public static function page_diagnostics(): void {
		self::open( __( 'Diagnostics', 'yap-reporting' ) );
		$caps = ApiClient::capabilities();
		$url  = Settings::api_url();
		$host = $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
		$rows = array(
			__( 'Reporting API host', 'yap-reporting' )       => $host ?: __( 'Not set', 'yap-reporting' ),
			__( 'Credential', 'yap-reporting' )               => Settings::token_hint() ? Settings::token_hint() . ' (' . Settings::token_source() . ')' : __( 'Not set', 'yap-reporting' ),
			__( 'Encryption key source', 'yap-reporting' )    => Crypto::available() ? Crypto::key_source() : __( 'sodium extension missing', 'yap-reporting' ),
			__( 'Private network targets', 'yap-reporting' )  => UrlGuard::private_hosts_allowed() ? __( 'Allowed by YAPR_ALLOW_PRIVATE_HOSTS', 'yap-reporting' ) : __( 'Blocked', 'yap-reporting' ),
			__( 'API version', 'yap-reporting' )              => (string) ( $caps['api_version'] ?? '—' ) . ' / service ' . (string) ( $caps['service_version'] ?? '—' ),
			__( 'Compatibility', 'yap-reporting' )            => ApiClient::compatibility()['message'] . ' ' . sprintf( 'Plugin %s needs API %s, webservice %s+.', YAPR_VERSION, YAPR_REQUIRED_API_VERSION, YAPR_MIN_SERVICE_VERSION ),
			__( 'YAP event definitions', 'yap-reporting' )    => (string) ( $caps['yap']['event_catalog_source'] ?? '—' ),
			__( 'Volunteer names', 'yap-reporting' )          => ! Settings::get( 'show_volunteer_names' ) ? __( 'Switched off in Settings', 'yap-reporting' ) : ( isset( $caps['client']['scopes'] ) ? ( in_array( 'volunteer_names', (array) $caps['client']['scopes'], true ) ? __( 'Shown (credential has the Volunteer names permission)', 'yap-reporting' ) : __( 'HIDDEN: the credential lacks the Volunteer names permission. Add it on the YAP server setup page (Change permissions), then Test connection.', 'yap-reporting' ) ) : '—' ),
			__( 'Region-wide activity', 'yap-reporting' )     => Access::credential_allows_unassigned() ? ( Settings::get( 'include_unassigned' ) ? __( 'Included in "All my Areas"', 'yap-reporting' ) : __( 'Allowed but switched off in Settings', 'yap-reporting' ) ) : __( 'NOT ALLOWED by the credential: meeting searches, JFT/SPAD and texts cannot be shown. Tick "Include unassigned activity" on the YAP server setup page.', 'yap-reporting' ),
			__( 'Credential covers', 'yap-reporting' )        => isset( $caps['client'] ) ? ( ! empty( $caps['client']['all_service_bodies'] ) ? __( 'All service bodies', 'yap-reporting' ) : implode( ', ', array_map( array( Areas::class, 'name' ), (array) $caps['client']['service_body_ids'] ) ) ) . ' — ' . implode( ', ', (array) $caps['client']['scopes'] ) : '—',
			__( 'BMLT root server', 'yap-reporting' )         => (string) Settings::get( 'bmlt_root' ) ?: __( 'Not set', 'yap-reporting' ),
			__( 'Selected Areas', 'yap-reporting' )           => (string) count( Areas::managed_ids() ),
			__( 'Last successful sync', 'yap-reporting' )     => ( $s = Sync::state( 'recent' )['last_success'] ) ? human_time_diff( (int) strtotime( $s . ' UTC' ) ) . ' ' . __( 'ago', 'yap-reporting' ) : '—',
			__( 'Most recent sync error', 'yap-reporting' )   => (string) ( Sync::state( 'recent' )['last_error'] ?? '' ) ?: '—',
			__( 'PHP / WordPress', 'yap-reporting' )          => PHP_VERSION . ' / ' . get_bloginfo( 'version' ),
		);
		echo '<div class="yapr-card"><table class="widefat striped"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th scope="row">' . esc_html( $k ) . '</th><td>' . esc_html( ApiClient::safe_message( (string) $v ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::action_button( 'test_connection', __( 'Test connection', 'yap-reporting' ), array( 'back' => 'yapr-diagnostics' ), 'primary' );
		echo '</div>';

		if ( ! empty( $caps['metrics'] ) ) {
			echo '<div class="yapr-card"><h2>' . esc_html__( 'Statistics available from this YAP build', 'yap-reporting' ) . '</h2><table class="widefat striped"><tbody>';
			foreach ( (array) $caps['metrics'] as $metric => $state ) {
				echo '<tr><th scope="row">' . esc_html( str_replace( '_', ' ', (string) $metric ) ) . '</th><td>' . ( ! empty( $state['available'] ) ? esc_html__( 'Available', 'yap-reporting' ) : esc_html__( 'Unavailable', 'yap-reporting' ) . ' — ' . esc_html( (string) ( $state['reason'] ?? '' ) ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		self::close();
	}

	// ------------------------------------------------------------------------------------------
	// Setup wizard (checklist that reflects real state)
	// ------------------------------------------------------------------------------------------

	public static function page_setup(): void {
		self::open( __( 'Setup', 'yap-reporting' ) );
		$caps  = ApiClient::capabilities();
		$steps = array(
			array( __( 'Enter the Reporting API URL and credential', 'yap-reporting' ), '' !== Settings::api_url() && '' !== Settings::api_token(), 'yapr-settings' ),
			array( __( 'Test the connection', 'yap-reporting' ), ! empty( $caps ), 'yapr-diagnostics' ),
			array( __( 'Enter the BMLT root server and refresh service bodies', 'yap-reporting' ), array() !== Areas::all(), 'yapr-bmlt' ),
			array( __( 'Select the Areas this site reports on', 'yap-reporting' ), array() !== Areas::managed_ids(), 'yapr-bmlt' ),
			array( __( 'Check the YAP to BMLT mapping', 'yap-reporting' ), array() !== Areas::managed_ids(), 'yapr-bmlt' ),
			array( __( 'Choose report defaults and caching', 'yap-reporting' ), (int) Settings::get( 'default_area' ) > 0, 'yapr-settings' ),
			array( __( 'Grant Areas to roles or users', 'yap-reporting' ), array() !== (array) Settings::get( 'role_areas' ), 'yapr-access' ),
			array( __( 'Run the first synchronization', 'yap-reporting' ), ! empty( Sync::state( 'recent' )['last_success'] ), 'yapr-cache' ),
			array( __( 'Add shortcodes to your pages', 'yap-reporting' ), (bool) Settings::get( 'setup_complete' ), 'yapr-area-dashboards' ),
		);
		echo '<ol class="yapr-steps">';
		foreach ( $steps as [ $label, $done, $page ] ) {
			echo '<li class="' . ( $done ? 'is-done' : '' ) . '"><span class="yapr-step-state">' . ( $done ? esc_html__( 'Done', 'yap-reporting' ) : esc_html__( 'To do', 'yap-reporting' ) ) . '</span> '
				. '<a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '">' . esc_html( $label ) . '</a></li>';
		}
		echo '</ol>';
		echo '<div class="yapr-card"><h2>' . esc_html__( 'Shortcodes', 'yap-reporting' ) . '</h2><p><code>[yap_reporting]</code> ' . esc_html__( 'full report for authorized users', 'yap-reporting' ) . '<br><code>[yap_phoneline_admin]</code> ' . esc_html__( 'call detail and volunteer activity', 'yap-reporting' ) . '<br><code>[yap_area_dashboard area="123"]</code> ' . esc_html__( 'compact Area figures', 'yap-reporting' ) . '</p>';
		self::action_button( 'setup_done', __( 'Mark setup complete', 'yap-reporting' ), array(), 'primary' );
		echo '</div>';
		self::close();
	}

	public static function handle_setup_done(): void {
		self::guard( 'yapr_setup_done', Access::CAP_MANAGE_SETTINGS );
		Settings::update( array( 'setup_complete' => 1 ) );
		self::redirect( 'yapr-setup', __( 'Setup marked complete.', 'yap-reporting' ) );
	}

	// ------------------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------------------

	private static function open( string $title ): void {
		echo '<div class="wrap yapr-wrap"><h1>' . esc_html( $title ) . '</h1>';
		$notice = get_transient( 'yapr_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'yapr_notice_' . get_current_user_id() );
			$class = 'error' === $notice[0] ? 'notice-error' : ( 'success' === $notice[0] ? 'notice-success' : 'notice-info' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice[1] ) . '</p></div>';
		}
		if ( current_user_can( Access::CAP_MANAGE_SETTINGS ) ) {
			$compat = ApiClient::compatibility();
			if ( in_array( $compat['status'], array( 'outdated', 'incompatible' ), true ) ) {
				echo '<div class="notice ' . ( 'incompatible' === $compat['status'] ? 'notice-error' : 'notice-warning' ) . '"><p>' . esc_html( $compat['message'] ) . '</p></div>';
			}
		}
	}

	private static function close(): void {
		echo '</div>';
	}

	private static function guard( string $nonce, string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'yap-reporting' ), 403 );
		}
		check_admin_referer( $nonce );
	}

	private static function redirect( string $page, string $message, string $type = 'success' ): void {
		set_transient( 'yapr_notice_' . get_current_user_id(), array( $type, $message ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	private static function action_button( string $action, string $label, array $fields = array(), string $style = 'secondary' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="yapr-inline-form">';
		wp_nonce_field( 'yapr_' . $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( 'yapr_' . $action ) . '">';
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		submit_button( $label, $style, 'submit', false );
		echo '</form> ';
	}

	private static function select( string $name, string $id, string $current, array $options ): void {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $current, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private static function area_select( string $name, string $id, int $current, array $ids, bool $none ): void {
		$options = $none ? array( '0' => __( '— None —', 'yap-reporting' ) ) : array();
		foreach ( $ids as $a ) {
			$options[ (string) $a ] = Areas::name( $a );
		}
		self::select( $name, $id, (string) $current, $options );
		if ( ! $ids ) {
			echo '<p class="description">' . esc_html__( 'Select Areas on the BMLT Configuration page first.', 'yap-reporting' ) . '</p>';
		}
	}

	private static function checkbox( string $name, string $id, bool $on, string $label ): void {
		printf( '<label><input type="checkbox" id="%s" name="%s" value="1"%s> %s</label>', esc_attr( $id ), esc_attr( $name ), checked( $on, true, false ), esc_html( $label ) );
	}

	private static function clamp( $v, int $min, int $max ): int {
		return max( $min, min( $max, (int) $v ) );
	}
}
