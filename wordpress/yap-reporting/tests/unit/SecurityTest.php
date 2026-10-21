<?php
use YAPR\Cache;
use YAPR\Crypto;
use YAPR\Export;
use YAPR\UrlGuard;

return array(
	'csv formula injection is neutralised' => function () {
		Assert::same( "'=HYPERLINK(\"x\")", Export::csv_cell( '=HYPERLINK("x")' ) );
		Assert::same( '+1', Export::csv_cell( '+1' ), 'plain signed numbers are data, not formulas' );
		Assert::same( "'+cmd|' /C calc'!A0", Export::csv_cell( "+cmd|' /C calc'!A0" ) );
		Assert::same( "'@SUM(A1)", Export::csv_cell( '@SUM(A1)' ) );
		Assert::same( "'-2+3", Export::csv_cell( '-2+3' ) );
		Assert::same( '-5', Export::csv_cell( -5 ), 'real numbers stay numeric' );
		Assert::same( '12.5', Export::csv_cell( '12.5' ) );
		Assert::same( 'yes', Export::csv_cell( true ) );
		Assert::same( 'Area One', Export::csv_cell( 'Area One' ) );
	},
	'private and metadata addresses are rejected' => function () {
		foreach ( array( '127.0.0.1', '10.1.2.3', '192.168.0.5', '172.16.9.9', '169.254.169.254', '100.64.1.1', '0.0.0.0', '::1', 'fd00::1', 'fe80::1' ) as $ip ) {
			Assert::true( ! UrlGuard::is_public_ip( $ip ), $ip . ' should be blocked' );
		}
		Assert::true( UrlGuard::is_public_ip( '8.8.8.8' ) );
	},
	'url policy' => function () {
		UrlGuard::$resolver = static fn( $h ) => 'internal.example' === $h ? array( '10.0.0.5' ) : array( '93.184.216.34' );
		Assert::true( true === UrlGuard::validate( 'https://yap.example.org/reporting-api' ) );
		Assert::same( 'yapr_https_required', UrlGuard::validate( 'http://yap.example.org' )->get_error_code() );
		Assert::same( 'yapr_bad_url', UrlGuard::validate( 'https://user:pw@yap.example.org' )->get_error_code() );
		Assert::same( 'yapr_private_host', UrlGuard::validate( 'https://internal.example/' )->get_error_code() );
		Assert::same( 'yapr_private_host', UrlGuard::validate( 'https://169.254.169.254/latest' )->get_error_code() );
		Assert::same( 'yapr_bad_url', UrlGuard::validate( 'https://yap.example.org/?redirect=x' )->get_error_code() );
		UrlGuard::$resolver = null;
	},
	'credential encryption round trip' => function () {
		if ( ! Crypto::available() ) { return; }
		$enc = Crypto::encrypt( 'yapr_0123456789ab_secret' );
		Assert::true( false === strpos( $enc, 'secret' ), 'ciphertext must not contain plaintext' );
		Assert::same( 'yapr_0123456789ab_secret', Crypto::decrypt( $enc ) );
		Assert::same( null, Crypto::decrypt( 'yapr1:' . base64_encode( str_repeat( 'x', 60 ) ) ), 'tampered data is rejected' );
	},
	'public and private cache keys never collide' => function () {
		$a = Cache::key( 'private', 'area-dashboard', array( 10 ), array( 'period' => '30d' ) );
		$b = Cache::key( 'public', 'area-dashboard', array( 10 ), array( 'period' => '30d' ) );
		$c = Cache::key( 'private', 'area-dashboard', array( 11 ), array( 'period' => '30d' ) );
		$d = Cache::key( 'private', 'area-dashboard', array( 11, 10 ), array( 'period' => '30d' ) );
		$e = Cache::key( 'private', 'area-dashboard', array( 10, 11 ), array( 'period' => '30d' ) );
		Assert::true( $a !== $b && $a !== $c );
		Assert::same( $d, $e, 'area order does not matter' );
	},
);
