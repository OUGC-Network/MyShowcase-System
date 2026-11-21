#!/usr/bin/env python3
"""A SIGINT during a harness run closes its board (decision D256).

Starts one of the features harnesses on its own board. When the ready port (the board's port, or the router's port with
--ready-port, the one above the board's) listens, the harness is in its run; the test waits half a second and sends
SIGINT: the harness gets a KeyboardInterrupt. A harness that has already ended before the interrupt fails the test. The
test then checks that nothing of the run is left: no server listens on the board's port or on any of the 12 ports above
it (url_mode's extra servers), and, on MySQL and PostgreSQL, no database of the run appeared. The test needs 13 free ports
from its --port on: the verification uses a spacing of 14.

Before the repair the Ctx's server outlives the interrupted harness: the port stays in use. The test then kills that
server, so that it does not stay behind.

    python3 tests/harness/interrupt_test.py --script tests/features/http/quote_dates.py --engine sqlite|mysql|pgsql \\
        --mybb /path/to/mybb-1.9 --scratch DIR --port N
"""
import argparse, os, signal, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', 'json-sync', 'http'))

import board  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument('--script', required=True)
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''))
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, required=True)
p.add_argument('--ready-port', type=int, default=0, help='the port whose listener means the harness is in its run (default: --port)')
a = p.parse_args()


def listeners(port):
    return [int(x) for x in subprocess.run(['lsof', '-ti', f'tcp:{port}', '-sTCP:LISTEN'], capture_output=True, text=True).stdout.split()]


def run_ports():
    """The board's port and the ports the harnesses open above it: url_mode uses up to +12 (its SEO and marker-less servers)."""
    return range(a.port, a.port + 13)


def databases():
    """The names of the databases that start with msc_test_ (MySQL, PostgreSQL); nothing else is read."""
    env = board.db_env()
    if a.engine == 'mysql':
        out = subprocess.run([env['MYSQL_BIN'], '--no-defaults', f"-h{env['MYSQL_HOST']}", f"-P{env['MYSQL_PORT']}", f"-u{env['MYSQL_USER']}", '-N', '-e', "SHOW DATABASES LIKE 'msc_test_%'"],
                             capture_output=True, text=True).stdout
    else:
        out = subprocess.run([env['PSQL_BIN'], '-h', env['PGHOST'], '-p', env['PGPORT'], '-U', env['PGUSER'], '-d', 'postgres', '-At', '-c',
                              "select datname from pg_database where datname like 'msc_test_%'"], capture_output=True, text=True).stdout
    return {line.strip() for line in out.splitlines() if line.strip().startswith('msc_test_')}


def main():
    if not a.mybb or not os.path.isdir(a.mybb):
        sys.exit('give --mybb: a MyBB 1.9 source tree with its vendor/ folder')
    os.makedirs(a.scratch, exist_ok=True)
    busy = [port for port in run_ports() if listeners(port)]
    if busy:
        sys.exit(f'ports {busy} are in use before the run: choose another --port')
    before_dbs = databases() if a.engine in ('mysql', 'pgsql') else set()
    log = open(os.path.join(a.scratch, 'interrupt-run.log'), 'w')
    # A background job inherits SIGINT as ignored, and then Python in the child never raises KeyboardInterrupt: the child
    # gets the default disposition, so that the signal is the one the test means.
    proc = subprocess.Popen([sys.executable, a.script, '--engine', a.engine, '--mybb', a.mybb, '--scratch', a.scratch, '--port', str(a.port)],
                            stdout=log, stderr=subprocess.STDOUT, preexec_fn=lambda: signal.signal(signal.SIGINT, signal.SIG_DFL))
    ready = a.ready_port or a.port
    started = time.time()
    while not listeners(ready) and proc.poll() is None and time.time() - started < 600:
        time.sleep(0.2)
    time.sleep(0.5)  # the ready server is up: the harness is in its run (a fast run may end here, see below)
    interrupted = proc.poll() is None
    if interrupted:
        os.kill(proc.pid, signal.SIGINT)
        try:
            proc.wait(timeout=300)
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.wait()
    code = proc.returncode

    checks = []  # (passed, message)
    checks.append((interrupted, 'the harness was still running when the interrupt was sent'))
    checks.append((code != 0, f'the harness ended with an error code after the interrupt (exit {code})'))
    left = [pid for port in run_ports() for pid in listeners(port)]
    checks.append((not left, 'no server listens on the ports of the run (the board port and up to 12 above it) after the interrupt'
                   + (f' (pids {left})' if left else '')))
    for pid in left:  # the orphan server of a failed check is stopped, so that it does not stay behind
        os.kill(pid, signal.SIGTERM)
    if a.engine in ('mysql', 'pgsql'):
        new_dbs = sorted(databases() - before_dbs)
        checks.append((not new_dbs, 'no database of the run is left behind' + (f': {", ".join(new_dbs)}' if new_dbs else '')))

    failures = [message for passed, message in checks if not passed]
    print(f'\n{len(checks) - len(failures)} passed, {len(failures)} failed (interrupt, {os.path.basename(a.script)}, {a.engine})')
    for message in failures:
        print('  FAILED:', message)
    sys.stdout.flush()
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
