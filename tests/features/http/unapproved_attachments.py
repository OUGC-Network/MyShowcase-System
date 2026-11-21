#!/usr/bin/env python3
"""An attachment awaiting approval is shown only to its author and to the moderators of the entries (B6, decision P6).

The entry page of a showcase lists its attachments. An attachment with status 0 (awaiting approval) must not be listed to a guest
or to a member who is neither its author nor a moderator of the entries; the listing must show it to a member whose moderator row
holds the entries right. The attachment's file name is the mark of the listing.

    python3 tests/features/http/unapproved_attachments.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Exit code 1 if a check fails.
"""
import argparse, json, os, subprocess, sys, time

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


def main():
    import board  # noqa: E402
    import features  # noqa: E402
    from front import Front  # noqa: E402

    port = a.port or {'sqlite': 18960, 'mysql': 18961, 'pgsql': 18962}[a.engine]
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, a.scratch, port, 'real', None, dbname=f'msc_test_unapproved_{a.engine}_' + os.urandom(3).hex())
        acp = board.activate(ctx)
        features.configure(ctx, a.scratch)
        board_dir = ctx.board
        adm = '/admin/index.php?module=myshowcase-json_sync'
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/blog.json'})
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog',
                       'route_identifier': 'blog', 'custom_template_prefix': ''})
        php(board_dir, 'enable', 'blog')
        php(board_dir, 'users', os.path.join(a.scratch, 'users.json'))
        users = json.load(open(os.path.join(a.scratch, 'users.json')))
        member_password, member_uid = users['member1']['password'], users['member1']['uid']
        php(board_dir, 'save-row', 2, 'entries_can_view', 1)
        php(board_dir, 'save-row', 2, 'attachments_can_view', 1)
        php(board_dir, 'save-row', 2, 'attachments_can_download', 1)
        php(board_dir, 'types', 'blog')
        entry = php(board_dir, 'entry', 'blog', 'Unapproved attachment entry')
        png = os.path.join(a.scratch, 'unapproved.png')
        open(png, 'wb').write(bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cfc0f01f0005000201a6a1c0340000000049454e44ae426082'))
        attached = php(board_dir, 'attachment', 'blog', entry['entry_id'], png)
        if 'error' in attached:
            raise RuntimeError(f"the attachment fixture failed: {attached['error']}")
        php(board_dir, 'attachment-status', 'blog', attached['attachment_id'], 0)
        time.sleep(1.5)
        page = f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}"
        name = attached['name']
        guest = Front(f'http://127.0.0.1:{port}')
        member = Front(f'http://127.0.0.1:{port}')
        member.login('member1', member_password)

        ok(name not in guest.get(page)['body'], 'a guest does not see an attachment awaiting approval')
        ok(name not in member.get(page)['body'], 'a member who is neither its author nor a moderator does not see it')
        php(board_dir, 'moderator-row', 'blog', member_uid, 1, 0)
        time.sleep(1.5)
        ok(name in member.get(page)['body'], 'a moderator of the entries (the entries right) sees it')
        php(board_dir, 'moderator-row', 'blog', member_uid, 0, 0)
        time.sleep(1.5)
        ok(name not in member.get(page)['body'], 'without the entries right the member does not see it again')
    except Exception as e:  # a crash is a failed check, reported with the summary below
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    finally:
        print(f'\n{PASSES} passed, {len(FAILS)} failed (unapproved attachments, {a.engine})')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
        if ctx is not None:
            ctx.close()
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
