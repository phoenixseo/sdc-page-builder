<?php
// Diagnose: wie viele Variablen kommen wirklich an?
$flat = 0;
$walk = function ( $arr ) use ( &$walk, &$flat ) {
	foreach ( $arr as $v ) {
		if ( is_array( $v ) ) { $walk( $v ); } else { $flat++; }
	}
};
$walk( $_POST );

header( 'Content-Type: text/plain' );
echo 'max_input_vars=' . ini_get( 'max_input_vars' ) . "\n";
echo 'POST-Blätter=' . $flat . "\n";
echo 'POST-Top-Level=' . count( $_POST ) . "\n";
echo 'SAPI=' . PHP_SAPI . ' | Handler=' . ( function_exists( 'apache_get_modules' ) ? 'mod_php' : 'php-fpm/cgi' ) . "\n";
echo 'erste 3 Keys=' . implode( ',', array_slice( array_keys( $_POST ), 0, 3 ) ) . "\n";
echo 'letzte 3 Keys=' . implode( ',', array_slice( array_keys( $_POST ), -3 ) ) . "\n";
