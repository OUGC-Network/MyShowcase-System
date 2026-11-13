#!/usr/bin/env python3
"""HTTP tests of the entries import (design doc, sections 6 to 11): installs a scratch board for one engine, creates
the blog showcase through the ACP's JSON sync, creates entries through the forum form, exports them and imports them
back, and checks the refusals, the security measures and the permissions.

    python3 tests/import/http/run.py --engine sqlite|mysql --mybb /path/to/mybb-1.9 --scratch DIR
                                     [--port N] [--pluginlibrary-file FILE] [--keep]

The MyBB tree must have its Composer dependencies (vendor/); the plugin's own dependencies are installed by the board
builder of tests/json-sync/http/board.py (in the plugin's ignored vendor/ folder). MySQL: the database msc_test_<random>
is created for the run and dropped at the end; MYSQL_* variables as described in tests/json-sync/README.md. The
PluginLibrary file is taken from --pluginlibrary-file, else the PLUGINLIBRARY variable, else ../_deps next to the
worktree. PostgreSQL is not part of this suite yet (see the report of the batch).

Exit code 1 if any check fails.
"""
import argparse, os, random, shutil, string, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'features', 'http'))
sys.path.insert(0, HERE)

import board  # noqa: E402  (tests/json-sync/http/board.py)
import flows  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''), help='a MyBB 1.9 source tree (copied, never modified)')
p.add_argument('--scratch', required=True, help='the scratch directory of the run (never removed by this script)')
p.add_argument('--port', type=int, default=0)
p.add_argument('--pluginlibrary-file', default=os.environ.get('PLUGINLIBRARY', ''))
p.add_argument('--keep', action='store_true', help='leave the PHP server running')
a = p.parse_args()

if not a.mybb or not os.path.isdir(a.mybb):
    sys.exit('give --mybb (or MYBB_SOURCE): a MyBB 1.9 source tree with its vendor/ folder')

pl_file = a.pluginlibrary_file or os.path.join(REPO, '..', '_deps', 'MyBB-PluginLibrary', 'inc', 'plugins', 'pluginlibrary.php')

if not os.path.isfile(pl_file):
    sys.exit('PluginLibrary not found: ' + pl_file)

os.makedirs(a.scratch, exist_ok=True)

port = a.port or {'sqlite': 18092, 'mysql': 18091}[a.engine]

dbname = 'msc_test_' + ''.join(random.choice(string.ascii_lowercase + string.digits) for _ in range(10))

ctx = board.make_board(a.engine, a.mybb, a.scratch, port, 'real', pl_file, dbname=dbname)

try:
    # The forum front controller and its helpers are not part of the plugin folder: copy them as the feature suite does.
    upload = os.path.join(REPO, 'Upload')
    for rel in ('showcase.php', 'inc/tasks/myshowcase.php', 'jscripts', 'images'):
        src, dst = os.path.join(upload, rel), os.path.join(ctx.board, rel)
        if os.path.isdir(src):
            shutil.copytree(src, dst, dirs_exist_ok=True)
        else:
            shutil.copy(src, dst)

    # The server is restarted with the error settings of the batch: every PHP notice goes to the log.
    ctx.stop()
    log = os.path.join(a.scratch, f'php_{a.engine}_errors.log')
    ctx.server = subprocess.Popen(
        ['php', '-d', 'error_reporting=32767', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', f'error_log={log}',
         '-S', f'127.0.0.1:{port}', '-t', ctx.board],
        stdout=open(os.path.join(a.scratch, f'php_{a.engine}.log'), 'w'), stderr=subprocess.STDOUT,
    )
    time.sleep(1.5)

    acp = board.activate(ctx)

    flows.configure(ctx, a.scratch, log)
    flows.create_blog(acp)
    flows.create_showcase(acp, 'gallery', 'Gallery')
    flows.create_showcase(acp, 'downloads', 'Downloads')
    flows.run()

    print(f'\n{flows.PASSES} passed, {len(flows.FAILS)} failed ({a.engine}, PluginLibrary: {pl_file})')

    for f in flows.FAILS:
        print('  FAILED:', f)

    for line in flows.NOT_RUN:
        print('  NOT RUN:', line)
finally:
    ctx.stop()

    if a.engine == 'mysql':
        env = board.db_env()
        subprocess.run(
            [env['MYSQL_BIN'], '--no-defaults', f"-h{env['MYSQL_HOST']}", f"-P{env['MYSQL_PORT']}", f"-u{env['MYSQL_USER']}"] +
            ([f"-p{env['MYSQL_PASSWORD']}"] if env['MYSQL_PASSWORD'] else []) + ['-e', f'DROP DATABASE IF EXISTS {dbname}'],
            check=False,
        )
        print(f'dropped database {dbname}')

sys.exit(1 if flows.FAILS else 0)
