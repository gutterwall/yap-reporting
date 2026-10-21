<?php
use YAPR\Access;

$setup = static function (): void {
	// Region 1 → Areas 10, 11, 12 (12 not managed); Area 20 elsewhere.
	yapr_seed_areas( array(
		array( 1, 0, 'Region', 'rs', true ),
		array( 10, 1, 'Area Ten', 'as', true ),
		array( 11, 1, 'Area Eleven', 'as', true ),
		array( 12, 1, 'Area Twelve', 'as', false ),
		array( 20, 0, 'Area Twenty', 'as', true ),
	) );
	$GLOBALS['yapr_test']['options']['yapr_settings'] = array( 'role_areas' => array( 'yapr_area_reporter' => array( 10 ) ) );
	$GLOBALS['yapr_test']['caps'] = array(
		1 => Access::ALL_CAPS,
		2 => array( Access::CAP_VIEW_REPORTS ),
		3 => array( Access::CAP_VIEW_REPORTS ),
		4 => array(),
	);
	$GLOBALS['yapr_test']['users'] = array( 1 => array( 'administrator' ), 2 => array( 'yapr_area_reporter' ), 3 => array( 'yapr_area_reporter' ), 4 => array( 'subscriber' ) );
	$GLOBALS['yapr_test']['meta']  = array( 3 => array( 'yapr_areas' => array( 20 ) ) );
};

return array(
	'administrator sees all managed areas' => function () use ( $setup ) {
		$setup();
		Assert::same( array( 1, 10, 11, 20 ), Access::allowed_areas( 1 ) );
	},
	'role grant applies' => function () use ( $setup ) {
		$setup();
		Assert::same( array( 10 ), Access::allowed_areas( 2 ) );
	},
	'user override replaces role grant' => function () use ( $setup ) {
		$setup();
		Assert::same( array( 20 ), Access::allowed_areas( 3 ) );
	},
	'no capability means no areas' => function () use ( $setup ) {
		$setup();
		Assert::same( array(), Access::allowed_areas( 4 ) );
		Assert::true( is_wp_error( Access::resolve( 4, null ) ) );
	},
	'requesting another area is refused, not widened' => function () use ( $setup ) {
		$setup();
		$r = Access::resolve( 2, array( 20 ) );
		Assert::true( is_wp_error( $r ) );
		Assert::same( 'yapr_area_forbidden', $r->get_error_code() );
		Assert::true( is_wp_error( Access::resolve( 2, array( 10, 20 ) ) ), 'mixed request refused' );
		Assert::same( array( 10 ), Access::resolve( 2, array( 10 ) ) );
	},
	'region expands only to managed descendants and is still checked' => function () use ( $setup ) {
		$setup();
		Assert::same( array( 1, 10, 11 ), Access::resolve( 1, null, 1 ) );
		Assert::true( is_wp_error( Access::resolve( 2, null, 1 ) ), 'reporter for Area 10 cannot open Region 1' );
	},
	'unknown and malformed ids are errors' => function () use ( $setup ) {
		$setup();
		Assert::same( 'yapr_area_unknown', Access::resolve( 1, array( 999 ) )->get_error_code() );
		Assert::same( array( -1 ), Access::parse_ids( '10 OR 1=1' ) );
		Assert::true( is_wp_error( Access::resolve( 1, Access::parse_ids( '10;DROP' ) ) ) );
	},
);
