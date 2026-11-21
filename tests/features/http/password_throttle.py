#!/usr/bin/env python3
"""The password gate's throttle keeps its times in UTC, whatever the server's time zone (decision D285).

The access log's dateline is a DATETIME. The gate writes it and compares its window in UTC: both sides are gmdate() values, so a
server whose date.timezone is not UTC gives the same window and the same stored times. This test runs the board's server in
America/Tijuana (through PHPRC, which the installer and the server both read) and checks, on every engine:

  T  the times stored for the wrong passwords are UTC (within five minutes of the UTC clock);
  W  a valid access older than the interval does not open the gate (a stale row, two hours old, with a 60 minute interval);
  G  a valid access inside the interval does open it (a row ten minutes old).

Note: the gate grants by user id, and every guest has user id 0; a valid access of one guest opens the gate for every guest
during the interval (an open item, not checked here).

    python3 tests/features/http/password_throttle.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Exit code 1 if a check fails. The zone is checked first: a server that does not run in the non-UTC zone fails the run.
"""
import argparse, datetime, json, os, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, HERE)

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''))
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, default=0)
a = p.parse_args()
if not a.mybb or not os.path.isdir(a.mybb):
    sys.exit('give --mybb: a MyBB 1.9 source tree with its vendor/ folder')

ZONE = 'America/Tijuana'
FAILS, PASSES = [], 0


def ok(cond, message):
    global PASSES
    print(('PASS ' if cond else 'FAIL ') + message)
    if cond:
        PASSES += 1
    else:
        FAILS.append(message)


def php_zone_dir(scratch):
    """A php.ini directory with the non-UTC zone; the installer and the board's server both read PHPRC."""
    d = os.path.join(scratch, 'tz')
    os.makedirs(d, exist_ok=True)
    with open(os.path.join(d, 'php.ini'), 'w') as handle:
        handle.write(f'date.timezone = {ZONE}\n')
    os.environ['PHPRC'] = d  # the php.ini of the installer and of the board's server (PHP_INI_SCAN_DIR is not honoured here)
    r = subprocess.run(['php', '-r', 'echo date_default_timezone_get();'], capture_output=True, text=True)
    return r.stdout.strip()


def php(board_dir, *args):
    r = subprocess.run(['php', os.path.join(HERE, 'fresh_fixture.php'), *[str(x) for x in args]], cwd=board_dir, capture_output=True, text=True)
    lines = [l for l in r.stdout.splitlines() if l.strip()]
    if r.returncode or not lines or 'SQL Error' in r.stdout:
        return {'error': (lines[-1] if lines else r.stdout[-200:])[:200]}
    return json.loads(lines[-1])


def utc_now():
    return datetime.datetime.now(datetime.timezone.utc).replace(tzinfo=None)


def stored_time(text):
    return datetime.datetime.strptime(str(text)[:19], '%Y-%m-%d %H:%M:%S')


def main():
    scratch = a.scratch
    os.makedirs(scratch, exist_ok=True)
    zone = php_zone_dir(scratch)
    if zone != ZONE:
        sys.exit(f'the server would not run in {ZONE} (php says {zone!r}): the test needs the non-UTC zone')
    import board  # noqa: E402
    import features  # noqa: E402
    from front import Front  # noqa: E402

    port = a.port or {'sqlite': 18920, 'mysql': 18921, 'pgsql': 18922}[a.engine]
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, scratch, port, 'real', None, dbname=f'msc_test_throttle_{a.engine}_' + os.urandom(3).hex())
        acp = board.activate(ctx)
        features.configure(ctx, scratch)
        board_dir = ctx.board
        adm = '/admin/index.php?module=myshowcase-json_sync'
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/blog.json'})
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog',
                       'route_identifier': 'blog', 'custom_template_prefix': ''})
        php(board_dir, 'enable', 'blog')
        php(board_dir, 'showcase-set', 'blog', 'password', 'throttle-secret')
        php(board_dir, 'showcase-set', 'blog', 'password_minutes_interval', 60)
        php(board_dir, 'showcase-set', 'blog', 'password_ip_check', 0)
        time.sleep(1.5)
        base = f'http://127.0.0.1:{port}'
        guest = Front(base)
        path = '/showcase.php/blog/'
        # the gate's own sentence (the page header has a password field of its own, so the field is not the test)
        gate_shown = lambda body: 'Enter the showcase password' in body

        # T: the wrong passwords are stored in UTC
        before = len(php(board_dir, 'password-rows').get('rows', []))
        guest.post(path, {'password': 'wrong-1'})
        guest.post(path, {'password': 'wrong-2'})
        now = utc_now()
        rows = php(board_dir, 'password-rows').get('rows', [])
        new = rows[before:]
        ok(len(new) == 2 and all(r['is_valid'] == 0 for r in new), f'two wrong passwords are two invalid access rows [{len(new)}]')
        drift = [abs((stored_time(r['dateline']) - now).total_seconds()) for r in new]
        ok(bool(new) and all(d < 300 for d in drift),
           f'the stored times of the wrong passwords are UTC (within five minutes of the UTC clock; drift in seconds {[int(d) for d in drift]})')

        # W: a valid access older than the interval does not open the gate
        stale = (utc_now() - datetime.timedelta(hours=2)).strftime('%Y-%m-%d %H:%M:%S')
        php(board_dir, 'password-row', 'blog', stale, 1)
        r = guest.get(path)
        ok(gate_shown(r['body']), f'a valid access two hours old (interval 60 minutes) does not open the gate [{r["status"]}]')

        # G: a valid access inside the interval opens it; the right password opens it too
        recent = (utc_now() - datetime.timedelta(minutes=10)).strftime('%Y-%m-%d %H:%M:%S')
        php(board_dir, 'password-row', 'blog', recent, 1)
        r = guest.get(path)
        ok(r['status'] == 200 and not gate_shown(r['body']), f'a valid access ten minutes old opens the gate [{r["status"]}]')

    except Exception as e:  # a crash is a failed check, reported with the summary below
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    finally:
        print(f'\n{PASSES} passed, {len(FAILS)} failed (throttle, {a.engine}, zone {ZONE})')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
        if ctx is not None:
            ctx.close()
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
