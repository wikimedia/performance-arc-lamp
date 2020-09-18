<?php
/**
 * Arc Lamp collector <https://github.com/wikimedia/arc-lamp>
 *
 * Copyright 2019 Timo Tijhof <krinklemail@gmail.com>
 * Copyright 2014 Ori Livneh <ori@wikimedia.org>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Wikimedia;

use Exception;
use ExcimerProfiler;
use Redis;

class ArcLamp {

	/**
	 *
	 * @param array $options
	 *   - excimer-period: The sampling interval (in seconds)
	 *   - redis-host: The Redis host to flush samples to
	 *   - redis-port: The Redis port
	 *   - redis-timeout: The Redis socket timeout (in seconds)
	 *   - redis-channel: The Redis pubsub channel name
	 */
	final public static function collect( array $options = [] ) {
		$options += [
			// Sample only once per minute in production.
			// This generally means we collect at most one sample from any given web request.
			// The start time is staggered by Excimer.
			'excimer-period' => 60,
			'redis-host' => '127.0.0.1',
			'redis-port' => 6379,
			'redis-timeout' => 0.1,
			'redis-channel' => 'excimer',
		];
		if ( !extension_loaded( 'excimer' ) ) {
			return;
		}

		// Keep the object in scope until the end of the request
		static $prof;
		$prof = new ExcimerProfiler;
		$prof->setEventType( EXCIMER_CPU );
		$prof->setPeriod( $options['excimer-period'] );
		$prof->setMaxDepth( 250 );
		// Flush for every sample (no buffering). There's no point in waiting for more than
		// one sample to arrive, because in production our sample period is 1 minute which is
		// generally more than a typical web request lives for.
		$prof->setFlushCallback(
			function ( $log ) use ( $options ) {
				self::flush( $log, $options );
			},
			1
		);
		$prof->start();
	}

	/**
	 * The callback for profiling. This is called every time Excimer collects a stack trace.
	 *
	 * @param string $log
	 * @param array $options
	 */
	final public static function flush( $log, array $options ) {
		try {
			$redis = new Redis();
			$ok = $redis->connect(
				$options['redis-host'],
				$options['redis-port'],
				$options['redis-timeout']
			);
			if ( $ok ) {
				// arclamp-log expects the first frame to be a PHP file. This file name is used to
				// group traces by entry point. In most cases, the stack starts with the entry
				// point already. But, for destructor callbacks in PHP 7.2+, the stack starts
				// without the original entry frame. For example, a line may look like this:
				// "LBFactory::__destruct;LBFactory::LBFactory::shutdown;… 1".
				$firstFrame = realpath( $_SERVER['SCRIPT_FILENAME'] ) . ';';
				$collapsed = $log->formatCollapsed();
				foreach ( explode( "\n", $collapsed ) as $line ) {
					if ( ( substr_count( $line, ';' ) + 1 ) >= 249 ) {
						// Discard lines with 249 or more stack depth. These are likely incomplete,
						// per ExcimerProfiler::setMaxDepth. We discard these because depth limit
						// is enforced by trimming from the root of the stack (from entry point down).
						// This makes them unusable for flame graphs. <https://phabricator.wikimedia.org/T176916>
						continue;
					}
					if ( $line === '' ) {
						// Discard the empty line at the end of $collapsed.
						continue;
					}

					// For stacks from destructor callbacke etc, prepend the entry point
					// as the real parent frame. This substring check includes a semicolon
					// to avoid false positives.
					if ( substr( $line, 0, strlen( $firstFrame ) ) !== $firstFrame ) {
						$line = $firstFrame . $line;
					}
					$redis->publish( $options['redis-channel'], $line );
				}
			}
		} catch ( Exception $e ) {
			// Ignore. Known failure scenarios:
			//
			// - "RedisException: read error on connection"
			//   Each publish() in the above loop writes data to Redis and
			//   subsequently reads from the socket for Redis' response.
			//   If a socket read takes longer than $timeout, it throws.
			//   <https://phabricator.wikimedia.org/T206092>
		}
	}
}
