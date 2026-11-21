#!/usr/bin/env python3
"""A moderator's rights are per area: the entries flag moderates entries, the comments flag moderates comments (B7, decision P1).

A member (member1, Registered) who may update entries and comments is given a moderator row on the blog showcase. The admin's
entry and the admin's comment are the ones the member may not touch, so only a moderator right lets the member open their edit
forms. The checks, for each row (A: entries only; B: comments only; C: both):

  E  the entry's edit form opens (it carries the form, name="input") when the row has the entries flag, and not otherwise;
  M  the comment's edit form opens when the row has the comments flag, and not otherwise.

Before the per-area rule a row of either kind moderated both areas, so A and B fail on the other area.

    python3 tests/features/http/moderator_areas.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Exit code 1 if a check fails.
"""
import argparse, json, os, re, sys, time

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

import subprocess  # noqa: E402

FAILS, PASSES = [], 0


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


def opens(page):
    """An edit form opens when the page answers 200 and carries the form (its controls name their input)."""
    return page['status'] == 200 and 'name="input"' in page['body']


def main():
    import board  # noqa: E402
    import features  # noqa: E402
    from front import Front  # noqa: E402

    port = a.port or {'sqlite': 18950, 'mysql': 18951, 'pgsql': 18952}[a.engine]
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, a.scratch, port, 'real', None, dbname=f'msc_test_modareas_{a.engine}_' + os.urandom(3).hex())
        acp = board.activate(ctx)
        features.configure(ctx, a.scratch)
        board_dir = ctx.board
        adm = '/admin/index.php?module=myshowcase-json_sync'
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/blog.json'})
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog',
                       'route_identifier': 'blog', 'custom_template_prefix': ''})
        php(board_dir, 'enable', 'blog')
        php(board_dir, 'setting', 'seourls', 'no')
        users_file = os.path.join(a.scratch, 'users.json')
        php(board_dir, 'users', users_file)
        users = json.load(open(users_file))
        member_password, member_uid = users['member1']['password'], users['member1']['uid']
        # the Registered group may update entries and comments; the admin's entry and comment are the ones tested
        php(board_dir, 'save-row', 2, 'entries_can_update', 1)
        php(board_dir, 'save-row', 2, 'comments_can_update', 1)
        entry = php(board_dir, 'entry', 'blog', 'Moderated entry')
        comment = php(board_dir, 'comment', 'blog', entry['entry_id'], 'Moderated comment')
        member = Front(f'http://127.0.0.1:{port}')
        member.login('member1', member_password)
        entry_url = f"/showcase.php/blog/update/{entry['public_id']}"
        comment_url = f"/showcase.php/blog/view/{entry['public_id']}/update/comment/{comment['public_id']}"

        for label, entries, comments in (('A entries only', 1, 0), ('B comments only', 0, 1), ('C both', 1, 1)):
            php(board_dir, 'moderator-row', 'blog', member_uid, entries, comments)
            time.sleep(1.5)
            ok(opens(member.get(entry_url)) == bool(entries), f'{label}: the entry edit form opens to the member: {bool(entries)} (E)')
            ok(opens(member.get(comment_url)) == bool(comments), f'{label}: the comment edit form opens to the member: {bool(comments)} (M)')
    except Exception as e:  # a crash is a failed check, reported with the summary below
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    finally:
        print(f'\n{PASSES} passed, {len(FAILS)} failed (moderator areas, {a.engine})')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
        if ctx is not None:
            ctx.close()
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
