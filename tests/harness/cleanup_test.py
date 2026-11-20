#!/usr/bin/env python3
"""Cleanup after a failed board installation (decision D252).

board.make_board creates the database (MySQL, PostgreSQL) and the board folder before the MyBB installer runs. This test
makes the installer fail after the database exists: php is not on PATH, so the install step raises FileNotFoundError for
'php'. Afterwards nothing of the run may be left: no database with the run's name, no server listening on the run's port,
and no file in the run's folder. Before the repair the database (MySQL, PostgreSQL) and the board folder (every engine)
stay behind.

The check is only meaningful if the failure is the injected one, so the error must name 'php'.

    python3 tests/harness/cleanup_test.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>
"""
import argparse, os, shutil, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', 'json-sync', 'http'))

import board  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''))
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, default=18996)
a = p.parse_args()

# the system folders only: no php here, so the installer cannot start
NO_PHP_PATH = '/usr/bin:/bin:/usr/sbin:/sbin'


def listening(port):
    return bool(subprocess.run(['lsof', '-ti', f'tcp:{port}', '-sTCP:LISTEN'], capture_output=True, text=True).stdout.strip())


def main():
    if not a.mybb or not os.path.isdir(a.mybb):
        sys.exit('give --mybb: a MyBB 1.9 source tree with its vendor/ folder')
    os.makedirs(a.scratch, exist_ok=True)
    run_dir = os.path.join(a.scratch, f'cleanup-{a.engine}-' + os.urandom(3).hex())
    os.makedirs(run_dir)
    dbname = f'msc_test_cleanup_{a.engine}_' + os.urandom(3).hex()
    os.environ['PATH'] = NO_PHP_PATH

    raised = None
    try:
        board.make_board(a.engine, a.mybb, run_dir, a.port, 'stub', None, dbname=dbname)
    except Exception as e:  # the injected failure, expected
        raised = e

    checks = []  # (passed, message)
    checks.append((isinstance(raised, FileNotFoundError) and getattr(raised, 'filename', '') == 'php',
                   f'the installer fails on php after the database exists (raised {raised!r})'))
    if a.engine in ('mysql', 'pgsql'):
        left_db = board.database_exists(a.engine, dbname)
        checks.append((not left_db, f'no database {dbname} is left behind'))
    checks.append((not listening(a.port), f'no server listens on port {a.port} after the failure'))
    left = sorted(os.listdir(run_dir))
    checks.append((not left, 'no file of the run is left behind' + (f': {", ".join(left)}' if left else '')))

    failures = [message for passed, message in checks if not passed]
    print(f'\n{len(checks) - len(failures)} passed, {len(failures)} failed (cleanup, {a.engine})')
    for message in failures:
        print('  FAILED:', message)
    sys.stdout.flush()
    shutil.rmtree(run_dir, ignore_errors=True)
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
