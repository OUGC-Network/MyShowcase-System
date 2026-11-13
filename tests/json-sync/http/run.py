#!/usr/bin/env python3
"""Installs a scratch board for one engine and runs the whole HTTP flow of the JSON sync against it.

    python3 tests/json-sync/http/run.py --engine sqlite|mysql|pgsql [--mybb /path/to/mybb] [--scratch DIR]
                                         [--port N] [--pluginlibrary real|stub] [--screenshots DIR]

mysql also covers MariaDB (point MYSQL_BIN/MYSQL_HOST/MYSQL_PORT at it). See tests/json-sync/README.md.
"""
import argparse, os, sys, tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import board, flows

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''), help='a MyBB 1.9 source tree (copied, never modified)')
p.add_argument('--scratch', default='')
p.add_argument('--port', type=int, default=0)
p.add_argument('--pluginlibrary', default='real', choices=['real', 'stub'])
p.add_argument('--pluginlibrary-file', default='')
p.add_argument('--screenshots', default='', help='instead of the flow: build a board showing every status and take screenshots into this directory (needs Playwright)')
p.add_argument('--keep', action='store_true', help='leave the PHP server running')
a = p.parse_args()

if not a.mybb or not os.path.isdir(a.mybb):
    sys.exit('give --mybb (or MYBB_SOURCE): a MyBB 1.9 source tree')

scratch = a.scratch or tempfile.mkdtemp(prefix='myshowcase-http-')
os.makedirs(scratch, exist_ok=True)
port = a.port or {'sqlite': 8082, 'mysql': 8081, 'pgsql': 8083}[a.engine]

ctx = board.make_board(a.engine, a.mybb, scratch, port, a.pluginlibrary, a.pluginlibrary_file or None)
try:
    board.activate(ctx)
    flows.configure(ctx)
    if a.screenshots:
        import screenshots
        for f in screenshots.shoot(ctx, a.screenshots):
            print('screenshot:', f)
        sys.exit(0)
    cid_a, sid_a = flows.section_a()
    cid, sid, fcat = flows.section_b()
    flows.section_c(cid, sid, fcat)
    flows.section_d(cid, sid)
    flows.section_e(cid_a, sid_a, cid, sid)
    flows.section_f()
    flows.section_g()
    flows.section_h()
    print(f'\n{flows.PASSES} passed, {len(flows.FAILS)} failed ({a.engine}, PluginLibrary: {a.pluginlibrary})')
    for f in flows.FAILS:
        print('  FAILED:', f)
finally:
    if not a.keep:
        ctx.stop()
sys.exit(1 if flows.FAILS else 0)
