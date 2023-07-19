<?php
/**
 * Arc Lamp client <https://gerrit.wikimedia.org/g/performance/arc-lamp>
 *
 * Copyright Ori Livneh <ori@wikimedia.org>
 * Copyright Timo Tijhof <krinkle@fastmail.com>
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
	 * Collect stack samples in the background of this request.
	 *
	 * If a sample is taken during this web request, it will be flushed
	 * to the specified Redis server on the "excimer" pubsub channel.
	 *
	 * @param array $options
	 *   - excimer-period: The sampling interval (in seconds)
	 *     Default: 60.
	 *     This generally means you will collect at most one sample from
	 *     any given web request. The start time is staggered by Excimer
	 *     to ensure equal distribution and fair chance to all code,
	 *     including early on in the request.
	 *   - redis-host: Redis host to flush samples to.
	 *     Default: "127.0.0.1".
	 *   - redis-port: Redis port
	 *     Default: 6379.
	 *   - redis-timeout: The Redis socket timeout (in seconds)
	 *     Default: 0.1.
	 *   - statsd-host: [optional] StatsD host address (ip:port or hostname:port),
	 *     to report metrics about collection failures.
	 *   - statsd-prefix: For example `"MyApplication."`, prepended to
	 *     the `arclamp_client_error.<reason>` and `arclamp_client_discarded.<reason>`
	 *     counter metrics.
	 *     Default: "".
	 */
	final public static function collect( array $options = [] ) {
		$options += [
			'excimer-period' => 60,
			'redis-host' => '127.0.0.1',
			'redis-port' => 6379,
			'redis-timeout' => 0.1,
		];

		if ( PHP_SAPI !== 'cli' && extension_loaded( 'excimer' ) ) {
			// Used for unconditional sampling of production web requests.
			self::excimerSetup( $options );
		}
	}

	/**
	 * Start Excimer sampling profiler in production.
	 *
	 * @param array $options
	 */
	final public static function excimerSetup( $options ) {
		// Keep the object in scope until the end of the request
		static $realProf;

		$realProf = new ExcimerProfiler;
		$realProf->setEventType( EXCIMER_REAL );
		$realProf->setPeriod( 60 );
		// Limit the depth of stack traces to 250 (T176916)
		$realProf->setMaxDepth( 250 );
		$realProf->setFlushCallback(
			static function ( $log ) use ( $options ) {
				$logLines = explode( "\n", $log->formatCollapsed() );
				$redisChannel = 'excimer';
				self::excimerFlushToArclamp( $logLines, $options, $redisChannel );
			},
			/* $maxSamples = */ 1
		);
		$realProf->start();
	}

	/**
	 * Flush callback, called any time Excimer samples a stack trace in production.
	 *
	 * @param string[] $logLines Result of ExcimerLog::formatCollapsed()
	 * @param array $options
	 * @param string $redisChannel
	 */
	public static function excimerFlushToArclamp( $logLines, $options, $redisChannel ) {
		$error = null;
		try {
			$redis = new Redis();
			$ok = $redis->connect(
				$options['redis-host'],
				$options['redis-port'],
				$options['redis-timeout']
			);
			if ( !$ok ) {
				$error = 'connect_error';
			} else {
				$firstFrame = realpath( $_SERVER['SCRIPT_FILENAME'] ) . ';';
				foreach ( $logLines as $line ) {
					if ( $line === '' ) {
						// formatCollapsed() ends with a line break
						continue;
					}

					// There are two ways a stack trace may be missing the first few frames:
					//
					// 1. Destructor callbacks, as of PHP 7.2, may be formatted as
					//    "LBFactory::__destruct;LBFactory::LBFactory::shutdown;… 1"
					// 2. Stack traces that are longer than the configured maxDepth, will be
					//    missing their top-most frames in favour of excimer_truncated (T176916)
					//
					// Arc Lamp requires the top frame to be the PHP entry point file.
					// If the first frame isn't the expected entry point, prepend it.
					// This check includes the semicolon to avoid false positives.
					if ( substr( $line, 0, strlen( $firstFrame ) ) !== $firstFrame ) {
						$line = $firstFrame . $line;
					}
					$redis->publish( $redisChannel, $line );
				}
			}
		} catch ( \Exception $e ) {
			// Known failure scenarios:
			//
			// - "RedisException: read error on connection"
			//   Each publish() in the above loop writes data to Redis and
			//   subsequently reads from the socket for Redis' response.
			//   If any socket read takes longer than $timeout, it throws (T206092).
			//   As of writing, this is rare (a few times per day at most),
			//   which is considered an acceptable loss in profile samples.
			$error = 'exception';
		}

		if ( $error ) {
			$dest = $options['statsd-host'] ?? null;
			$prefix = $options['statsd-prefix'] ?? '';
			if ( $dest ) {
				$sock = socket_create( AF_INET, SOCK_DGRAM, SOL_UDP );
				if ( $error ) {
					$stat = $prefix . "arclamp_client_error.{$error}:1|c";
					@socket_sendto( $sock, $stat, strlen( $stat ), 0, $dest, 8125 );
				}
			}
		}
	}
}
