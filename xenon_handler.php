<?php

$xenonRedisServer = 'localhost';
$xenonRedisPort = 6379;

if ( extension_loaded( 'xenon' ) && ini_get( 'hhvm.xenon.period' ) ) {
	register_shutdown_function( function () {
		// Function names that should be excluded from the trace.
		$omit = array( 'include', '{closure}' );

		$data = HH\xenon_get_data();

		if ( empty( $data ) ) {
			return;
		}

		// Collate stack samples and fold into single lines.
		// This is the format expected by FlameGraph.
		$stacks = array();

		foreach ( $data as $sample ) {
			$stack = array();

			foreach( $sample['phpStack'] as $frame ) {
				$func = $frame['function'];
				if ( $func !== end( $stack ) && !in_array( $func, $omit ) ) {
					$stack[] = $func;
				}
			}

			if ( count( $stack ) ) {
				$strStack = implode( ';', array_reverse( $stack ) );
				if ( !isset( $stacks[$strStack] ) ) {
					$stacks[$strStack] = 0;
				}
				$stacks[$strStack] += 1;
			}
		}

		$redis = new Redis();
		if ( $redis->connect( $xenonRedisServer, $xenonRedisPort, 0.1 ) ) {
			foreach ( $stacks as $stack => $count ) {
				$redis->publish( 'xenon', "$stack $count" );
			}
		}
	} );
}
