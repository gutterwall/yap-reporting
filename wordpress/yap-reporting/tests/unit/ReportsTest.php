<?php
use YAPR\Reports;
use YAPR\Rest;

return array(
	'public payload keeps only dashboard figures' => function () {
		$m   = new ReflectionMethod( Reports::class, 'public_filter' );
		$m->setAccessible( true );
		$out = $m->invoke( null, 'area-dashboard', array(
			'data' => array( 'current' => array( 'volunteer_calls' => array( 'value' => 3 ), 'answered_by' => 'x', 'labels' => array() ) ),
			'meta' => array( 'range' => array(), 'service_bodies' => array( 'service_body_ids' => array( 10 ) ) ),
		) );
		Assert::true( isset( $out['data']['current']['volunteer_calls'] ) );
		Assert::true( ! isset( $out['data']['current']['answered_by'] ) );
		Assert::true( ! isset( $out['meta']['service_bodies'] ) );
		Assert::same( array( 'data' => array(), 'meta' => array() ), $m->invoke( null, 'summary', array( 'data' => array( 'x' => 1 ) ) ), 'only the dashboard can be public' );
	},
	'closed periods use the long refresh interval' => function () {
		$GLOBALS['yapr_test']['options']['yapr_settings'] = array( 'interval_recent' => 15, 'interval_history' => 360 );
		Assert::same( 900, Reports::ttl( array( 'period' => '30d' ) ) );
		Assert::same( 21600, Reports::ttl( array( 'period' => 'last_month' ) ) );
		Assert::same( 21600, Reports::ttl( array( 'start_date' => '2020-01-01', 'end_date' => '2020-01-31', 'timezone' => 'UTC' ) ) );
	},
	'volunteer identity is stripped without permission' => function () {
		yapr_seed_areas( array( array( 10, 0, 'Area Ten', 'as', true ) ) );
		$GLOBALS['yapr_test']['options']['yapr_settings'] = array( 'show_volunteer_names' => 1 );
		$call = array( 'service_body_id' => 10, 'answered_by' => array( 'volunteer_id' => 'v_1', 'name' => 'Alex' ), 'events' => array( array( 'volunteer' => array( 'volunteer_id' => 'v_1', 'name' => 'Alex' ) ) ) );
		$GLOBALS['yapr_test']['current_user'] = 2;
		$GLOBALS['yapr_test']['caps'][2]      = array( 'yapr_view_call_details' );
		$out = Rest::filter_call( $call );
		Assert::true( ! isset( $out['answered_by'] ) && ! isset( $out['events'][0]['volunteer'] ) );
		$GLOBALS['yapr_test']['caps'][2][] = 'yapr_view_volunteers';
		$GLOBALS['yapr_test']['options']['yapr_settings']['show_volunteer_names'] = 0;
		$out = Rest::filter_call( $call );
		Assert::same( array( 'volunteer_id' => 'v_1' ), $out['answered_by'], 'names hidden when the setting is off' );
		Assert::same( 'Area Ten', $out['area_name'] );
	},
);
