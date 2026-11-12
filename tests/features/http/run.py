#!/usr/bin/env python3
"""Installs a scratch board for one engine (with the JSON sync suite's board builder) and runs the forum-side checks of
the features the gallery relies on: authorization, moderation, deletion, search, attachments, edit form, main list.

    python3 tests/features/http/run.py --engine sqlite|mysql|pgsql --mybb /path/to/mybb-1.9 [--scratch DIR] [--port N]
                                       [--pluginlibrary real|stub] [--pluginlibrary-file FILE] [--keep]

The database settings are the ones of tests/json-sync/README.md. The test users get passwords generated for the run.
"""
import argparse, os, sys, tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, HERE)
import board, features

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''))
p.add_argument('--scratch', default='')
p.add_argument('--port', type=int, default=0)
p.add_argument('--pluginlibrary', default='real', choices=['real', 'stub'])
p.add_argument('--pluginlibrary-file', default='')
p.add_argument('--keep', action='store_true', help='leave the PHP server running')
a = p.parse_args()

if not a.mybb or not os.path.isdir(a.mybb):
    sys.exit('give --mybb (or MYBB_SOURCE): a MyBB 1.9 source tree')

scratch = a.scratch or tempfile.mkdtemp(prefix='myshowcase-features-')
os.makedirs(scratch, exist_ok=True)
port = a.port or {'sqlite': 8085, 'mysql': 8084, 'pgsql': 8086}[a.engine]

ctx = board.make_board(a.engine, a.mybb, scratch, port, a.pluginlibrary, a.pluginlibrary_file or None, dbname=f'myshowcase_features_{a.engine}')
try:
    acp = board.activate(ctx)
    features.configure(ctx, scratch)
    features.run(acp)
    print(f'\n{features.PASSES} passed, {len(features.FAILS)} failed ({a.engine}, PluginLibrary: {a.pluginlibrary})')
    for f in features.FAILS:
        print('  FAILED:', f)
finally:
    if not a.keep:
        ctx.stop()
sys.exit(1 if features.FAILS else 0)
