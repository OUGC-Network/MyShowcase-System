#!/usr/bin/env python3
"""A quote in a stored message with a date the plugin reads (decision D248, QuotesUtil).

A [quote datetime='…'] tag is text a member writes into a message, so its date is stored text. Three quotes are stored in
comments of a showcase: a numeric timestamp (the form a stored integer time takes), a valid date, and a malformed date. The
entry page must answer 200 and show the text of every quote. Before the repair the numeric and the malformed dates break
the page (PHP refuses them in the DateTime constructor), on every engine.

The board is installed with the plugin of this working tree (board.make_board); the summary is printed before Ctx.close()
(D234). It prints the first failures of each quote.

    python3 tests/features/http/quote_dates.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>
"""
import argparse, json, os, shutil, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, HERE)

import board  # noqa: E402
from front import Front  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''))
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, default=0)
a = p.parse_args()
if not a.mybb or not os.path.isdir(a.mybb):
    sys.exit('give --mybb: a MyBB 1.9 source tree with its vendor/ folder')

FAILS, PASSES = [], 0
QUOTES = [
    ('numeric timestamp', "[quote datetime='1700000000']numeric quote text[/quote] after it"),
    ('valid date', "[quote datetime='2024-01-02 10:00:00']dated quote text[/quote] after it"),
    ('malformed date', "[quote datetime='nonsense']malformed quote text[/quote] after it"),
]


def ok(cond, message):
    global PASSES
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


def main():
    scratch = a.scratch
    os.makedirs(scratch, exist_ok=True)
    port = a.port or {'sqlite': 18970, 'mysql': 18971, 'pgsql': 18972}[a.engine]
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, scratch, port, 'real', None, dbname=f'msc_test_quotes_{a.engine}_' + os.urandom(3).hex())
        run(ctx, scratch, port)
    except Exception as e:
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    print(f'\n{PASSES} passed, {len(FAILS)} failed ({a.engine})')
    for f in FAILS[:20]:
        print('  FAILED:', f)
    sys.stdout.flush()
    try:
        if ctx is not None:
            ctx.close()
    except Exception as e:
        print('CLEANUP FAILED:', e)
        return 1
    return 1 if FAILS else 0


def run(ctx, scratch, port):
    import features  # noqa: F401
    board_dir = ctx.board
    acp = board.activate(ctx)
    features.configure(ctx, scratch)
    shutil.copy(os.path.join(HERE, 'url_mode_router.php'), os.path.join(board_dir, 'url_mode_router.php'))
    adm = '/admin/index.php?module=myshowcase-json_sync'
    acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/blog.json'})
    acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog',
                   'route_identifier': 'blog', 'custom_template_prefix': ''})
    php(board_dir, 'enable', 'blog')
    php(board_dir, 'setting', 'seourls', 'no')
    entry = php(board_dir, 'entry', 'blog', 'Quote entry')
    for label, message in QUOTES:
        php(board_dir, 'comment', 'blog', entry['entry_id'], message)
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port + 10}', '-t', board_dir, os.path.join(board_dir, 'url_mode_router.php')],
                              stdout=open(os.path.join(scratch, 'php-quotes.log'), 'w'), stderr=subprocess.STDOUT)
    time.sleep(1.5)
    try:
        guest = Front(f'http://127.0.0.1:{port + 10}')
        r = guest.get(f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}")
        ok(r['status'] == 200, f'the entry page with the stored quotes answers 200 (answered {r["status"]})')
        for label, text in (('numeric timestamp', 'numeric quote text'), ('valid date', 'dated quote text'), ('malformed date', 'malformed quote text')):
            ok(r['status'] == 200 and text in r['body'], f'the text of the quote with a {label} is shown')
    finally:
        server.terminate()
        server.wait()


if __name__ == '__main__':
    sys.exit(main())
