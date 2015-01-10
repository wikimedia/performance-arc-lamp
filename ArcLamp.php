<?php
/**
 * ArcLamp -- a log processor for HHVM's Xenon extension.
 *
 * Copyright 2015 Ori Livneh <ori@wikimedia.org>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace ArcLamp;

/**
 * Collate Xenon traces and publish to Redis.
 *
 * To use ArcLamp, pass this function as the callback parameter to
 * register_shutdown_function:
 *
 * <code>
 * require_once('ArcLamp.php');
 * register_shutdown_function('ArcLamp\logXenonData', ['localhost', 6379]);
 * </code>
 *
 * @param Redis|array|string $redis A Redis instance, a Redis server name,
 *   or an array of arguments for Redis::connect.
 * @param array|null A list of function names to omit from traces, if any.
 *   By default, ArcLamp omits 'include', 'require' and anonymous functions.
 */
function logXenonData($redis = 'localhost', $exclude = null) {
	if (!extension_loaded('xenon')) {
		return;
	}
	$data = \HH\xenon_get_data();
	if (!is_array($data) || !count($data)) {
		return;
	}
	if ($redis instanceof \Redis) {
		$conn = $redis;
	} else {
		$conn = new \Redis;
		call_user_func_array(array($conn, 'connect'), (array)$redis);
	}
	if (!$conn) {
		return;
	}
	foreach (combineSamples($data, $exclude) as $stack => $count) {
		$conn->publish('xenon', "$stack $count");
	}
}

/**
 * Collate Xenon traces.
 *
 * @param array $xenonSamples Xenon samples, as returned by xenon_get_data().
 * @param array|null $exclude A list of function names to omit from traces.
 *   By default, ArcLamp omits 'include', 'require' and anonymous functions.
 * @return array A hash of (collapsed call stack => times seen).
 */
function combineSamples($xenonSamples, $exclude = null) {
	if ($exclude === null) {
		$exclude = array('include', 'require', '{closure}');
	}
	$stacks = array();
	foreach ($xenonSamples as $sample) {
		if (empty($sample['phpStack'])) {
			continue;
		}
		$stack = array();
		foreach ($sample['phpStack'] as $frame) {
			$func = $frame['function'];
			if ($func !== end($stack) && !in_array($func, $exclude)) {
				$stack[] = $func;
			}
		}
		if (count($stack)) {
			$strStack = implode(';', array_reverse($stack));
			if (!isset($stacks[$strStack])) {
				$stacks[$strStack] = 0;
			}
			$stacks[$strStack] += 1;
		}
	}
	return $stacks;
}
