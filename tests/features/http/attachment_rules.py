#!/usr/bin/env python3
"""Who sees an attachment, and who may download it (B6, decisions P6 and D58, D211).

  C  an attachment of a comment that awaits approval is listed on the entry page only to the comment moderators: a member whose
     moderator row holds the entries right does not see it, one whose row holds the comments right does;
  F  an attachment of a field (the blog's cover image) that awaits approval is not listed to a guest, and is listed to a member
     whose moderator row holds the entries right;
  D  an owner who may not view entries cannot download the owner's own file; with the view right back, the owner downloads it.

    python3 tests/features/http/attachment_rules.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

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

    port = a.port or {'sqlite': 18900, 'mysql': 18901, 'pgsql': 18902}[a.engine]
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, a.scratch, port, 'real', None, dbname=f'msc_test_attrules_{a.engine}_' + os.urandom(3).hex())
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
        php(board_dir, 'save-row', 2, 'entries_can_view', 1)
        php(board_dir, 'save-row', 2, 'attachments_can_view', 1)
        php(board_dir, 'save-row', 2, 'attachments_can_download', 1)
        php(board_dir, 'save-row', 2, 'comments_can_view', 1)
        php(board_dir, 'types', 'blog')
        png = os.path.join(a.scratch, 'rules.png')
        open(png, 'wb').write(bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cfc0f01f0005000201a6a1c0340000000049454e44ae426082'))

        member = Front(f'http://127.0.0.1:{port}')
        member.login('member1', member_password)
        guest = Front(f'http://127.0.0.1:{port}')

        # C: a comment's attachment, awaiting approval, on the entry page of its comment
        entry_c = php(board_dir, 'entry', 'blog', 'Comment attachment entry')
        comment = php(board_dir, 'comment', 'blog', entry_c['entry_id'], 'Comment with a file')
        att_c = php(board_dir, 'attachment', 'blog', entry_c['entry_id'], png, 1, comment['comment_id'])
        php(board_dir, 'attachment-status', 'blog', att_c['attachment_id'], 0)
        page_c = f"/showcase.php/blog/view/{entry_c['public_id']}/{entry_c['slug']}"
        time.sleep(1.5)
        php(board_dir, 'moderator-row', 'blog', member_uid, 1, 0)
        time.sleep(1.5)
        ok(att_c['name'] not in member.get(page_c)['body'], 'C: an entries moderator does not see a comment\'s attachment awaiting approval')
        php(board_dir, 'moderator-row', 'blog', member_uid, 0, 1)
        time.sleep(1.5)
        ok(att_c['name'] in member.get(page_c)['body'], 'C: a comments moderator sees a comment\'s attachment awaiting approval')
        php(board_dir, 'moderator-row', 'blog', member_uid, 0, 0)
        time.sleep(1.5)
        ok(att_c['name'] not in guest.get(page_c)['body'], 'C: a guest does not see it')

        # F: a field's attachment (the cover image), awaiting approval
        entry_f = php(board_dir, 'entry', 'blog', 'Field attachment entry')
        att_f = php(board_dir, 'attachment', 'blog', entry_f['entry_id'], png, 1, 0, 'cover_image')
        php(board_dir, 'attachment-status', 'blog', att_f['attachment_id'], 0)
        page_f = f"/showcase.php/blog/view/{entry_f['public_id']}/{entry_f['slug']}"
        time.sleep(1.5)
        ok(att_f['name'] not in guest.get(page_f)['body'], 'F: a guest does not see a field\'s attachment awaiting approval')
        php(board_dir, 'moderator-row', 'blog', member_uid, 1, 0)
        time.sleep(1.5)
        ok(att_f['name'] in member.get(page_f)['body'], 'F: an entries moderator sees a field\'s attachment awaiting approval')
        php(board_dir, 'moderator-row', 'blog', member_uid, 0, 0)
        time.sleep(1.5)

        # D: the owner who may not view entries cannot download the owner's own file
        entry_d = php(board_dir, 'entry', 'blog', 'Owner download entry')
        att_d = php(board_dir, 'attachment', 'blog', entry_d['entry_id'], png, member_uid)
        download = f"/showcase.php/blog/attachment/{att_d['public_id']}/{att_d['name']}"
        php(board_dir, 'save-row', 2, 'entries_can_view', 0)
        time.sleep(1.5)
        refused = member.get(download)['status']
        ok(refused != 200, f'D: an owner who may not view entries cannot download their own file [{refused}]')
        php(board_dir, 'save-row', 2, 'entries_can_view', 1)
        time.sleep(1.5)
        ok(member.get(download)['status'] == 200, 'D: with the view right back, the owner downloads the file')
    except Exception as e:  # a crash is a failed check, reported with the summary below
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    finally:
        print(f'\n{PASSES} passed, {len(FAILS)} failed (attachment rules, {a.engine})')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
        if ctx is not None:
            ctx.close()
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
