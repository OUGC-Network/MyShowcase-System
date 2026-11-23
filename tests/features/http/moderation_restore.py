#!/usr/bin/env python3
"""Restore only acts on soft-deleted content (IA-6c item 19, decision D337).

A moderator (member1: a moderator row on the blog showcase, both areas) holds the restore permission but not the approve
permission (the Registered group's global row, set by the run). The checks:

  a  an awaiting entry, restored through its entry page: refused with status 400 (P2), the entry stays awaiting;
  b  an awaiting comment, restored through its comment page: refused with status 400, the comment stays awaiting;
  c  a batch restore of one soft-deleted and one awaiting entry: the soft-deleted entry becomes visible and approved, the
     awaiting one is skipped and stays awaiting;
  d  a soft-deleted entry and a soft-deleted comment restored through their pages: visible and approved, as before;
  e  approval still needs the approve permission: the member's approve of an awaiting entry is refused, the entry stays awaiting.

Before the fix, a and b restored the item to visible and approved, and c approved the awaiting entry.

    python3 tests/features/http/moderation_restore.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Exit code 1 if a check fails.
"""
import argparse, json, os, sys, time

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
AWAITING, VISIBLE, SOFT = 0, 1, 2


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
        raise RuntimeError('fixture failed: ' + (lines[-1] if lines else r.stdout[-200:]))
    return json.loads(lines[-1])


def main():
    import board  # noqa: E402
    import features  # noqa: E402
    from front import Front, post_key  # noqa: E402

    port = a.port or {'sqlite': 18960, 'mysql': 18961, 'pgsql': 18962}[a.engine]
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, a.scratch, port, 'real', None, dbname=f'msc_test_modrestore_{a.engine}_' + os.urandom(3).hex())
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

        # The Registered group may view awaiting and deleted content and may restore, but may not approve.
        grants = {'entries_can_view': 1, 'entries_can_view_unapproved': 1, 'entries_can_view_deleted': 1, 'entries_can_update': 1,
                  'entries_can_restore': 1, 'entries_can_soft_delete': 1, 'entries_can_approve': 0,
                  'comments_can_view': 1, 'comments_can_view_unapproved': 1, 'comments_can_view_deleted': 1,
                  'comments_can_restore': 1, 'comments_can_soft_delete': 1, 'comments_can_approve': 0}
        for column, value in grants.items():
            php(board_dir, 'save-row', 2, column, value)
        php(board_dir, 'moderator-row', 'blog', member_uid, 1, 1)
        time.sleep(1.5)

        visible = php(board_dir, 'entry', 'blog', 'Visible entry', 1)
        aw_page = php(board_dir, 'entry', 'blog', 'Awaiting page entry', 0)
        aw_batch = php(board_dir, 'entry', 'blog', 'Awaiting batch entry', 0)
        soft_batch = php(board_dir, 'entry', 'blog', 'Soft batch entry', 2)
        soft_page = php(board_dir, 'entry', 'blog', 'Soft page entry', 2)
        aw_approve = php(board_dir, 'entry', 'blog', 'Awaiting approve entry', 0)
        aw_comment = php(board_dir, 'comment', 'blog', visible['entry_id'], 'Awaiting comment', 0)
        soft_comment = php(board_dir, 'comment', 'blog', visible['entry_id'], 'Soft comment', 2)

        def entry_status(e):
            return int(features.one(f"select status from mybb_myshowcase_data{features.sid('blog')} where entry_id='{e['entry_id']}'"))

        def comment_status(c):
            return int(features.one(f"select status from mybb_myshowcase_comments where comment_id='{c['comment_id']}'"))

        member = Front(f'http://127.0.0.1:{port}')
        member.login('member1', member_password)
        key = post_key(member.get('/showcase.php/blog/')['body'])
        entry_base = '/showcase.php/blog/update/'
        comment_base = f"/showcase.php/blog/view/{visible['public_id']}/update/comment/"

        # a: awaiting entry, restore through the entry page
        r = member.get(f"{entry_base}{aw_page['public_id']}?action=restore&my_post_key={key}")
        ok(r['status'] == 400 and entry_status(aw_page) == AWAITING,
           f"a: restore of an awaiting entry by a moderator without approve is refused with 400 and the entry stays awaiting [{r['status']}, status {entry_status(aw_page)}]")

        # b: awaiting comment, restore through the comment page
        r = member.get(f"{comment_base}{aw_comment['public_id']}?action=restore&my_post_key={key}")
        ok(r['status'] == 400 and comment_status(aw_comment) == AWAITING,
           f"b: restore of an awaiting comment is refused with 400 and the comment stays awaiting [{r['status']}, status {comment_status(aw_comment)}]")

        # c: batch restore of one soft-deleted and one awaiting entry
        r = member.post('/showcase.php/blog/', {'action': 'inline_moderation', 'moderate': 'restore', 'selected_all_entries': 0,
                                                'selected_entries': json.dumps([soft_batch['public_id'], aw_batch['public_id']]),
                                                'my_post_key': key})
        ok(entry_status(soft_batch) == VISIBLE and entry_status(aw_batch) == AWAITING,
           f"c: batch restore makes the soft-deleted entry visible and leaves the awaiting one [{entry_status(soft_batch)}, {entry_status(aw_batch)}]")

        # d: soft-deleted entry and comment restored as before
        member.get(f"{entry_base}{soft_page['public_id']}?action=restore&my_post_key={key}")
        ok(entry_status(soft_page) == VISIBLE, f"d: a soft-deleted entry restored through its page is visible and approved [{entry_status(soft_page)}]")
        member.get(f"{comment_base}{soft_comment['public_id']}?action=restore&my_post_key={key}")
        ok(comment_status(soft_comment) == VISIBLE, f"d: a soft-deleted comment restored through its page is visible and approved [{comment_status(soft_comment)}]")

        # e: approve still needs the approve permission
        r = member.get(f"{entry_base}{aw_approve['public_id']}?action=approve&my_post_key={key}")
        ok(entry_status(aw_approve) == AWAITING, f"e: approve of an awaiting entry by a member without approve is refused, the entry stays awaiting [{r['status']}]")
    except Exception as e:  # a crash is a failed check, reported with the summary below
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    finally:
        print(f'\n{PASSES} passed, {len(FAILS)} failed (moderation restore, {a.engine})')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
        if ctx is not None:
            ctx.close()
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
