#!/usr/bin/env python3
"""An attachment awaiting approval, embedded in a forum post, is shown only to its author and to the moderators of its area (B6, P6).

A forum post carries [attachment=public_id]; the post bit (forum_hooks.php postbit()) embeds the attachment. The checks:

  E1  a guest does not see the embedded attachment awaiting approval;
  E2  a member who is neither its author nor a moderator (mod1, no moderator row) does not see it;
  E3  its author (member1) sees it;
  E4  a moderator of the entries (mod1 with the entries flag) sees it.

    python3 tests/features/http/forum_embed.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Exit code 1 if a check fails.
"""
import argparse, json, os, re, subprocess, sys, time

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
        detail = re.sub(r'\s+', ' ', r.stdout + r.stderr)
        found = re.search(r'SQL Error (.{0,260})', detail)
        return {'error': (found.group(1) if found else detail[-260:])[:260]}
    return json.loads(lines[-1])


def main():
    import board  # noqa: E402
    import features  # noqa: E402
    from front import Front  # noqa: E402

    port = a.port or {'sqlite': 18980, 'mysql': 18981, 'pgsql': 18982}[a.engine]
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, a.scratch, port, 'real', None, dbname=f'msc_test_forumembed_{a.engine}_' + os.urandom(3).hex())
        acp = board.activate(ctx)
        features.configure(ctx, a.scratch)
        board_dir = ctx.board
        adm = '/admin/index.php?module=myshowcase-json_sync'
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/blog.json'})
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog',
                       'route_identifier': 'blog', 'custom_template_prefix': ''})
        php(board_dir, 'enable', 'blog')
        php(board_dir, 'showcase-set', 'blog', 'attachments_enable_embeds', 1)
        php(board_dir, 'save-row', 2, 'entries_can_view', 1)
        php(board_dir, 'save-row', 2, 'attachments_can_view', 1)
        php(board_dir, 'save-row', 2, 'attachments_can_download', 1)
        php(board_dir, 'users', os.path.join(a.scratch, 'users.json'))
        users = json.load(open(os.path.join(a.scratch, 'users.json')))
        member_password, member_uid = users['member1']['password'], users['member1']['uid']
        mod_password, mod_uid = users['mod1']['password'], users['mod1']['uid']
        php(board_dir, 'types', 'blog')
        png = os.path.join(a.scratch, 'embed.png')
        open(png, 'wb').write(bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cfc0f01f0005000201a6a1c0340000000049454e44ae426082'))

        entry = php(board_dir, 'entry', 'blog', 'Embed entry')
        att = php(board_dir, 'attachment', 'blog', entry['entry_id'], png, member_uid)
        if 'error' in att:
            raise RuntimeError(f"the attachment fixture failed: {att['error']}")
        php(board_dir, 'attachment-status', 'blog', att['attachment_id'], 0)
        post = php(board_dir, 'forum-post', 1, 'admin', 'Embed thread', f"[attachment={att['public_id']}]")
        if 'error' in post:
            raise RuntimeError(f"the forum fixture failed: {post['error']}")
        page = f"/showthread.php?tid={post['tid']}"
        time.sleep(1.5)

        guest = Front(f'http://127.0.0.1:{port}')
        other = Front(f'http://127.0.0.1:{port}')
        other.login('mod1', mod_password)
        author = Front(f'http://127.0.0.1:{port}')
        author.login('member1', member_password)
        name = att['name']

        plain = php(board_dir, 'forum-post', 1, 'admin', 'Plain thread', 'A plain post without an embed')
        plain_status = guest.get(f"/showthread.php?tid={plain['tid']}")['status']
        ok(plain_status == 200, f'E0: a plain thread answers 200 to a guest, so the embed checks read real pages [{plain_status}]')
        guest_body = guest.get(page)['body']
        other_body = other.get(page)['body']
        for label, body in (('guest', guest_body), ('other', other_body)):
            open(os.path.join(a.scratch, f'{label}-page.html'), 'w', encoding='utf-8').write(body)
            for m in re.finditer(re.escape(name), body):
                print(f'  {label} context: {body[max(0, m.start() - 160):m.end() + 60]!r}')
        ok(name not in guest_body, 'E1: a guest does not see an embedded attachment awaiting approval')
        ok(name not in other_body, 'E2: a member who is neither its author nor a moderator does not see it')
        ok(name in author.get(page)['body'], 'E3: its author sees it')
        php(board_dir, 'moderator-row', 'blog', mod_uid, 1, 0)
        time.sleep(1.5)
        ok(name in other.get(page)['body'], 'E4: a moderator of the entries (the entries flag) sees it')
    except Exception as e:  # a crash is a failed check, reported with the summary below
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    finally:
        print(f'\n{PASSES} passed, {len(FAILS)} failed (forum embed, {a.engine})')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
        if ctx is not None:
            ctx.close()
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
