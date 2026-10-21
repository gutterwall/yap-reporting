<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap.php';
$pass = 0; $fail = 0;
foreach ( glob( __DIR__ . '/unit/*Test.php' ) as $file ) {
	foreach ( require $file as $name => $fn ) {
		try { $fn(); $pass++; echo "  ok    " . basename( $file, '.php' ) . " :: $name\n"; }
		catch ( Throwable $e ) { $fail++; echo "  FAIL  " . basename( $file, '.php' ) . " :: $name\n        " . $e->getMessage() . "\n"; }
	}
}
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
