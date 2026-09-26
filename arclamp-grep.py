#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
  Copyright 2015 Ori Livneh <ori@wikimedia.org>

  Licensed under the Apache License, Version 2.0 (the "License");
  you may not use this file except in compliance with the License.
  You may obtain a copy of the License at

      http://www.apache.org/licenses/LICENSE-2.0

  Unless required by applicable law or agreed to in writing, software
  distributed under the License is distributed on an "AS IS" BASIS,
  WITHOUT WARRANTIES OR CONDITIONS OF ANY CODE, either express or implied.
  See the License for the specific language governing permissions and
  limitations under the License.

"""
import argparse
import collections
import fnmatch
import glob
import gzip
import operator
import os.path
import re


# Stack frames which match any of these shell-style wildcard patterns
# are excluded from the leaderboard.
SKIP_PATTERNS = ('*BagOStuff*', '*Http::exec*', '*ObjectCache*', '/srv*',
                 'AutoLoader*', 'Curl*', 'Database*', 'Hooks*', 'Http::*',
                 'LoadBalancer*', 'Memcached*', 'wfGetDB*')
RESET = '\033[0m'
YELLOW = '\033[93m'


def slicer(spec):
    args = re.match('(-?[0-9]+)?(?::(-?[0-9]+))?', spec).groups()
    args = [int(arg) if arg is not None else arg for arg in args]
    return lambda seq: operator.getitem(seq, slice(*args))


def should_skip(f):
    return f.lower() == f or any(fnmatch.fnmatch(f, p) for p in SKIP_PATTERNS)


def parse_line(line):
    line = re.sub(r'\d\.\d\dwmf\d+', 'X.XXwmfXX', line.rstrip())
    funcs, count = line.split(' ', 1)
    return funcs.split(';'), int(count)


def grep(fname, search_string):
    if fname.endswith(".gz"):
        opener = gzip.open
    else:
        opener = open

    with opener(fname) as f:
        for line in f:
            if search_string in line:
                yield line


def iter_funcs(files, search_string):
    for fname in files:
        for line in grep(fname, search_string):
            funcs, count = parse_line(line)
            while funcs and should_skip(funcs[-1]):
                funcs.pop()
            if funcs:
                func = funcs.pop()
                for _ in range(count):
                    yield func


arg_parser = argparse.ArgumentParser(
    description='analyze Arc Lamp logs. '
                'This is a CLI tool for parsing trace logs '
                'and printing a leaderboard of the functions '
                'which are most frequently on-CPU.',
    formatter_class=argparse.ArgumentDefaultsHelpFormatter
)
arg_parser.add_argument(
    '--resolution',
    help='Which log files to analyze.',
    default='daily',
    choices=('hourly', 'daily', 'weekly'),
)
arg_parser.add_argument(
    '--entrypoint',
    help='Analyze logs for this entry point.',
    default='all',
    choices=('all', 'index', 'api', 'load'),
)
arg_parser.add_argument(
    '--channel',
    default='excimer',
    help='What channel to scan (i.e. log file suffix), '
         'typically the Redis channel.',
    choices=('xenon', 'excimer'),
)
arg_parser.add_argument(
    '--grep',
    help='Only include stacks which include this string',
    default='',
)
arg_parser.add_argument(
    '--count',
    help='Show this many entries when listing the most sampled functions',
    default=20,
    type=int,
)
arg_parser.add_argument(
    '--slice',
    default='-2:',
    help='Slice of files to analyze, in Python slice notation. '
         'Files are ordered from oldest to newest, '
         'so --slice="-2:" means the two most recent files.',
    type=slicer,
)
args = arg_parser.parse_args()

# Legacy: the 'xenon' channel has a generic filename for now.
if args.channel == 'xenon':
    glob_pattern = '/srv/arclamp/logs/%(resolution)s/*.%(entrypoint)s.log*'
else:
    glob_pattern = '/srv/arclamp/logs/%(resolution)s/*.%(channel)s.%(entrypoint)s.log*'
file_names = glob.glob(glob_pattern % vars(args))
file_names.sort(key=os.path.getmtime)
file_names = args.slice(file_names)
file_names = [fn for fn in file_names if fn.endswith(".log.gz") or fn.endswith(".log")]
counter = collections.Counter(iter_funcs(file_names, args.grep))
total = sum(1 for _ in counter.elements())

max_len = max(len(f) for f, _ in counter.most_common(args.count))

desc = 'Top %d functions' % args.count
if args.grep:
    desc += ' in traces matching "%s"' % args.grep
if args.entrypoint == 'all':
    desc += ', all entry-points:'
else:
    desc += ', %s.php:' % args.entrypoint

print(desc)
print('-' * len(desc))

for idx, (func, count) in enumerate(counter.most_common(args.count)):
    ordinal = idx + 1
    percent = 100.0 * count / total
    func = YELLOW + (('%% -%ds' % max_len) % func) + RESET
    print('% 4d | %s |% 5.2f%%' % (ordinal, func, percent))

print('-' * len(desc))
print('Log files:')
for f in file_names:
    print(' - %s' % f)
print('')
