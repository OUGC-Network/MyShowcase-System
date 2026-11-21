#!/usr/bin/env python3
"""The password grant of a guest is the guest's own signed cookie (decisions D296 and D297).

Guests share user id 0, so before the change one guest's access row opened the gate for every guest during the interval. Now:

  A  guest A enters the password and passes; guest B (another cookie jar) still sees the gate;
  C  a cookie the plugin makes for this showcase grants; a tampered signature, an expired cookie, another showcase's cookie, a
     cookie of the old password, and (with the IP check) a cookie bound to another address do not grant;
  F  a guest's failed attempts from one address do not throttle a guest from another address (the IP is the throttle key), and
     five failed attempts from the guest's own address stop the guest from entering with the right password;
  M  a logged-in member's grant still comes from the access log, by user id, and expires with the interval.

    python3 tests/features/http/guest_grant.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Exit code 1 if a check fails. The guest requests come from 127.0.0.1; the other addresses are written into the access log by the
fixture, and the cookies of the other addresses are made by the plugin's own code (fresh_fixture.php guest-cookie).
"""
import argparse, json, os, subprocess, sys, time, urllib.request, datetime

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

FAILS, PASSES = [], 0
GATE = 'Enter the showcase password'


def ok(cond, message):
    global PASSES
    print(('PASS ' if cond else 'FAIL ') + message)
    if cond:
        PASSES += 1
    else:
        FAILS.append(message)


def php(board_dir, *args):
    r = subprocess.run(['php', os.path.join(HERE, 'fresh_fixture.php'), *[str(x) for x in args]], cwd=board_dir, capture_output=True, text=True)
    lines = [l for l in r.stdout.splitlines() if l.strip()]
    if r.returncode or not lines or 'SQL Error' in r.stdout:
        return {'error': (lines[-1] if lines else r.stdout[-200:])[:200]}
    return json.loads(lines[-1])


def get_with_cookie(base, path, cookie_name, cookie_value):
    """A guest request that carries one grant cookie and nothing else."""
    req = urllib.request.Request(base + path, headers={'Cookie': f'{cookie_name}={cookie_value}'})
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            return {'status': resp.status, 'body': resp.read().decode('utf-8', 'replace')}
    except urllib.error.HTTPError as e:
        return {'status': e.code, 'body': e.read().decode('utf-8', 'replace')}


def main():
    import board  # noqa: E402
    import features  # noqa: E402
    from front import Front  # noqa: E402

    port = a.port or {'sqlite': 18990, 'mysql': 18991, 'pgsql': 18992}[a.engine]
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, a.scratch, port, 'real', None, dbname=f'msc_test_guestgrant_{a.engine}_' + os.urandom(3).hex())
        acp = board.activate(ctx)
        features.configure(ctx, a.scratch)
        board_dir = ctx.board
        adm = '/admin/index.php?module=myshowcase-json_sync'
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/blog.json'})
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog',
                       'route_identifier': 'blog', 'custom_template_prefix': ''})
        php(board_dir, 'enable', 'blog')
        php(board_dir, 'showcase-set', 'blog', 'password', 'gg-secret-1')
        php(board_dir, 'showcase-set', 'blog', 'password_minutes_interval', 60)
        php(board_dir, 'showcase-set', 'blog', 'password_ip_check', 0)
        users_file = os.path.join(a.scratch, 'users.json')
        php(board_dir, 'users', users_file)
        users = json.load(open(users_file))
        member_password, member_uid = users['member1']['password'], users['member1']['uid']
        time.sleep(1.5)
        base = f'http://127.0.0.1:{port}'
        path = '/showcase.php/blog/'
        # the board's default cookie prefix is empty, so the name is the plugin's (decision D296)
        cookie_name = 'myshowcase_pw_' + str(php(board_dir, 'showcase-value', 'blog', 'showcase_id')['value'])

        # A: one guest's grant is that guest's
        guest_a, guest_b = Front(base), Front(base)
        guest_a.post(path, {'password': 'gg-secret-1'})
        ra = guest_a.get(path)
        ok(ra['status'] == 200 and GATE not in ra['body'], 'guest A enters the right password and sees the list')
        rb = guest_b.get(path)
        ok(GATE in rb['body'], 'guest B, with its own cookie jar, still sees the gate after guest A entered')

        # C: the cookie itself
        now = int(time.time())
        good = php(board_dir, 'guest-cookie', 'blog', now + 3600, '')['cookie']
        ok(get_with_cookie(base, path, 'x', 'y')['status'] == 200 and GATE in get_with_cookie(base, path, 'x', 'y')['body'],
           'without a grant cookie the gate is shown')
        ok(GATE not in get_with_cookie(base, path, cookie_name, good)['body'], 'a cookie the plugin made for this showcase grants')
        expires, mac = good.split('.', 1)
        flipped = ('1' if mac[0] == '0' else '0') + mac[1:]
        ok(GATE in get_with_cookie(base, path, cookie_name, f'{expires}.{flipped}')['body'], 'a cookie with a changed signature does not grant')
        expired = php(board_dir, 'guest-cookie', 'blog', now - 10, '')['cookie']
        ok(GATE in get_with_cookie(base, path, cookie_name, expired)['body'], 'an expired cookie does not grant')
        other = php(board_dir, 'guest-cookie', 'blog', now + 3600, '', 999)['cookie']
        ok(GATE in get_with_cookie(base, path, cookie_name, other)['body'], 'a cookie made for another showcase does not grant here')

        old = good
        php(board_dir, 'showcase-set', 'blog', 'password', 'gg-secret-2')
        time.sleep(2)
        ok(GATE in get_with_cookie(base, path, cookie_name, old)['body'], 'after the showcase password changed, a cookie of the old password does not grant')
        php(board_dir, 'showcase-set', 'blog', 'password', 'gg-secret-1')
        time.sleep(2)

        # the IP check binds the cookie to the address it was made for
        php(board_dir, 'showcase-set', 'blog', 'password_ip_check', 1)
        time.sleep(2)
        bound_here = php(board_dir, 'guest-cookie', 'blog', now + 3600, '127.0.0.1')['cookie']
        bound_there = php(board_dir, 'guest-cookie', 'blog', now + 3600, '10.0.0.9')['cookie']
        ok(GATE not in get_with_cookie(base, path, cookie_name, bound_here)['body'], 'with the IP check, a cookie of this address grants')
        ok(GATE in get_with_cookie(base, path, cookie_name, bound_there)['body'], 'with the IP check, a cookie of another address does not grant')
        php(board_dir, 'showcase-set', 'blog', 'password_ip_check', 0)
        time.sleep(2)

        # F: the throttle of failed guest attempts is per address
        php(board_dir, 'password-rows-clear', 'blog')
        php(board_dir, 'failed-rows', 'blog', '10.0.0.9', 6)
        time.sleep(1)
        guest_c = Front(base)
        guest_c.post(path, {'password': 'gg-secret-1'})
        rc = guest_c.get(path)
        ok(GATE not in rc['body'], 'six failed attempts from another address do not throttle this guest (the right password enters)')
        php(board_dir, 'password-rows-clear', 'blog')
        php(board_dir, 'failed-rows', 'blog', '127.0.0.1', 5)
        time.sleep(1)
        guest_d = Front(base)
        guest_d.post(path, {'password': 'gg-secret-1'})
        rd = guest_d.get(path)
        ok(GATE in rd['body'], 'five failed attempts from this address stop it, even with the right password')
        php(board_dir, 'password-rows-clear', 'blog')
        time.sleep(1)

        # M: a logged-in member's grant still comes from the access log, by user id
        member = Front(base)
        member.login('member1', member_password)
        ok(GATE in member.get(path)['body'], 'a logged-in member sees the gate before entering the password')
        member.post(path, {'password': 'gg-secret-1'})
        ok(GATE not in member.get(path)['body'], 'a logged-in member enters the right password and sees the list')
        php(board_dir, 'password-rows-clear', 'blog')
        stale = (datetime.datetime.utcnow() - datetime.timedelta(hours=2)).strftime('%Y-%m-%d %H:%M:%S')
        php(board_dir, 'password-row', 'blog', stale, 1, member_uid)
        time.sleep(1)
        ok(GATE in member.get(path)['body'], 'a member grant two hours old (interval 60 minutes) does not open the gate')
        recent = (datetime.datetime.utcnow() - datetime.timedelta(minutes=10)).strftime('%Y-%m-%d %H:%M:%S')
        php(board_dir, 'password-row', 'blog', recent, 1, member_uid)
        time.sleep(1)
        ok(GATE not in member.get(path)['body'], 'a member grant ten minutes old opens the gate (the member\'s own row)')
    except Exception as e:  # a crash is a failed check, reported with the summary below
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    finally:
        print(f'\n{PASSES} passed, {len(FAILS)} failed (guest grant, {a.engine})')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
        if ctx is not None:
            ctx.close()
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
