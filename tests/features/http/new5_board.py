#!/usr/bin/env python3
"""Board checks of the IA-6 re-check items that were traced but not run on a board (NEW-5; D348: written, not run).

  2   entry limit: with the group's post limit at 1 the second entry is refused with 403 and not stored; with the limit at 0
      (no limit) three entries are stored;
  10  comment post hash: the comment form carries a hidden post_hash; a valid post_hash links the attachment drafted under
      it to the new comment; a malformed post_hash, or the post_hash of another user's draft, links no attachment;
  18  entry page moderation (soft delete through ?action=soft_delete): refused without a valid my_post_key, refused for a
      moderator without the permission, done for a moderator with it;
  20  front page moderators: a user moderator and a group moderator are listed by name; the line is absent when there are none;
  22  ACP moderator delete link: a valid my_post_key deletes the row and the moderator cache entry; an invalid or missing key
      deletes nothing and shows the error;
  11  keyword search: a search for % lists only the entry with a literal %, a search for _ lists neither entry (PD-IAF-2,
      D356). Fails before the fix in EntriesController::match (both entries are listed for % and for _).

  1   every field html type of the FieldKit showcase (one per type, first field of each type): the create form, the post
      response and, when an entry is stored, its entry page contain no "Warning:", "Notice:", "Deprecated:" or "Fatal error",
      and the board's error.log and the server's PHP log gain no message during that type (decision D355). Entries are posted
      with a generic value for the text-like types only; a type whose required fields the generic value does not fill is
      refused, and its pages are still scanned;

Expected results on the code of agent/integrity-audit-6 at the start of this run, by reading (not executed):
  1: unknown until run (the matrix does not scan for PHP warnings, so this is the first scan of those pages);
  2, 10, 18, 22: pass (the code paths are as the checks assume; see docs/import-export-design.md, "Re-check at IA-6");
  20: FAILS before the fix. The front page reads the moderator TYPE column as a usergroup id, so a group moderator row is
      listed under the usergroup whose id is 1 (MODERATOR_TYPE_GROUP), not under the group it names. Check 20b fails.

    python3 tests/features/http/new5_board.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Exit code 1 if a check fails.
"""
import argparse, json, os, re, shutil, sys

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
VISIBLE, SOFT = 1, 2
ADMIN_UID = 1


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


def grant(board_dir, column, value):
    php(board_dir, 'save-row', 2, column, value)


def main():
    import board  # noqa: E402
    import features  # noqa: E402
    from front import Front, post_key, no_permission, bad_post_key  # noqa: E402

    port = a.port or {'sqlite': 18970, 'mysql': 18971, 'pgsql': 18972}[a.engine]
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, a.scratch, port, 'real', None, dbname=f'msc_test_new5_{a.engine}_' + os.urandom(3).hex())
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
        sid = features.sid('blog')

        # The Registered group may view and post entries and comments and may update and restore. The entry page soft
        # delete is switched on and off by the checks below; the entry limit starts at 0 (no limit).
        grants = {'entries_can_view': 1, 'entries_can_view_unapproved': 1, 'entries_can_view_deleted': 1, 'entries_can_post': 1,
                  'entries_can_update': 1, 'entries_can_restore': 1, 'entries_can_soft_delete': 0, 'entries_can_approve': 0,
                  'entries_limit_post_amount': 0, 'comments_can_view': 1, 'comments_can_view_unapproved': 1,
                  'comments_can_post': 1, 'comments_can_soft_delete': 1}
        for column, value in grants.items():
            grant(board_dir, column, value)
        php(board_dir, 'moderator-row', 'blog', member_uid, 1, 1)
        member = Front(f'http://127.0.0.1:{port}')
        member.login('member1', member_password)

        # ---------------------------------------------------------------- item 2: entry limit
        print('--- item 2: entry limit (0 means unlimited)')
        grant(board_dir, 'entries_limit_post_amount', 1)
        first, r1 = features.create_blog(member, 'Limit first entry')
        ok(first is not None, f'2a: with the limit at 1 the first entry is stored [{r1["status"]}]')
        second, r2 = features.create_blog(member, 'Limit second entry')
        ok(second is None and r2['status'] == 403,
           f'2a: with the limit at 1 the second entry is refused with 403 and not stored [{r2["status"]}, stored: {second is not None}]')
        grant(board_dir, 'entries_limit_post_amount', 0)
        for n in (1, 2, 3):
            _, rn = features.create_blog(member, f'Unlimited entry {n}')
        stored = features.one(f"select count(*) from mybb_myshowcase_data{sid} where subject like 'Unlimited entry %'")
        ok(int(stored) == 3, f'2b: with the limit at 0 three entries are stored, no limit applies [stored {stored}]')

        # ---------------------------------------------------------------- item 10: comment post hash
        print('--- item 10: comment post hash and attachment links')
        target = php(board_dir, 'entry', 'blog', 'Comment target', VISIBLE)
        view = f"/showcase.php/blog/view/{target['public_id']}/fresh"
        form = member.get(view)
        m = re.search(r'name="post_hash"\s+value="([^"]*)"', form['body'])
        hash_own = m.group(1) if m else ''
        ok(bool(hash_own), f'10a: the comment form carries a hidden post_hash [{"found" if hash_own else "missing"}]')
        key = post_key(form['body'])
        note = os.path.join(a.scratch, 'note.png')
        with open(note, 'wb') as handle:
            handle.write(b'\x89PNG\r\n\x1a\n' + b'0' * 16)

        # the member's own draft, under the hash of the form: linked by a comment posted with that hash
        own = php(board_dir, 'attachment', 'blog', target['entry_id'], note, member_uid)
        ctx.execute(f"update mybb_myshowcase_attachments set entry_id=0, post_hash='{hash_own}' where attachment_id={own['attachment_id']}")
        member.post(f"/showcase.php/blog/view/{target['public_id']}/create/comment",
                    {'message': 'Comment with own draft', 'post_hash': hash_own, 'my_post_key': key, 'submit': 'Post'})
        comment_id = features.one("select comment_id from mybb_myshowcase_comments where message='Comment with own draft'")
        linked = features.one(f"select comment_id from mybb_myshowcase_attachments where attachment_id={own['attachment_id']}")
        ok(comment_id and linked == comment_id,
           f'10b: a valid post_hash links the member\'s draft to the new comment [comment {comment_id or "none"}, linked to {linked or "none"}]')

        # another user's draft (the administrator's, user 1), under a valid hash that a second form of the member gave out
        other_form = member.get(view)
        mo = re.search(r'name="post_hash"\s+value="([^"]*)"', other_form['body'])
        foreign_hash = mo.group(1) if mo else ''
        ok(bool(foreign_hash) and foreign_hash != hash_own, '10c setup: a second form gives a different valid post_hash')
        foreign = php(board_dir, 'attachment', 'blog', target['entry_id'], note, ADMIN_UID)
        ctx.execute(f"update mybb_myshowcase_attachments set entry_id=0, post_hash='{foreign_hash}' where attachment_id={foreign['attachment_id']}")
        member.post(f"/showcase.php/blog/view/{target['public_id']}/create/comment",
                    {'message': 'Comment with foreign hash', 'post_hash': foreign_hash, 'my_post_key': key, 'submit': 'Post'})
        linked = features.one(f"select comment_id from mybb_myshowcase_attachments where attachment_id={foreign['attachment_id']}")
        ok(linked in ('', '0'), f'10c: a foreign post_hash links no other user\'s draft [linked to {linked or "none"}]')

        # a malformed post_hash is replaced by a fresh one: the comment is stored and links nothing
        malformed = php(board_dir, 'attachment', 'blog', target['entry_id'], note, member_uid)
        ctx.execute(f"update mybb_myshowcase_attachments set entry_id=0, post_hash='{hash_own}x' where attachment_id={malformed['attachment_id']}")
        member.post(f"/showcase.php/blog/view/{target['public_id']}/create/comment",
                    {'message': 'Comment with malformed hash', 'post_hash': 'x<script>', 'my_post_key': key, 'submit': 'Post'})
        stored_c = features.one("select count(*) from mybb_myshowcase_comments where message='Comment with malformed hash'")
        linked = features.one(f"select comment_id from mybb_myshowcase_attachments where attachment_id={malformed['attachment_id']}")
        ok(int(stored_c) == 1 and linked in ('', '0'),
           f'10d: a malformed post_hash: the comment is stored and no draft is linked [stored {stored_c}, linked to {linked or "none"}]')

        # ---------------------------------------------------------------- item 18: entry page moderation
        print('--- item 18: entry page moderation')
        page_entry = php(board_dir, 'entry', 'blog', 'Moderated entry', VISIBLE)
        entry_url = f"/showcase.php/blog/update/{page_entry['public_id']}"

        def entry_status():
            return int(features.one(f"select status from mybb_myshowcase_data{sid} where entry_id='{page_entry['entry_id']}'"))

        r = member.get(f"{entry_url}?action=soft_delete")
        ok(bad_post_key(r) and entry_status() == VISIBLE,
           f'18a: soft delete without my_post_key is refused and the entry stays visible [{r["status"]}, status {entry_status()}]')
        r = member.get(f"{entry_url}?action=soft_delete&my_post_key=")
        ok(bad_post_key(r) and entry_status() == VISIBLE,
           f'18a: soft delete with an empty my_post_key is refused and the entry stays visible [{r["status"]}, status {entry_status()}]')

        key = post_key(member.get('/showcase.php/blog/')['body'])
        r = member.get(f"{entry_url}?action=soft_delete&my_post_key={key}")
        ok(no_permission(r) and entry_status() == VISIBLE,
           f'18b: a moderator without the soft delete permission is refused and the entry stays visible [{r["status"]}, status {entry_status()}]')

        grant(board_dir, 'entries_can_soft_delete', 1)
        r = member.get(f"{entry_url}?action=soft_delete&my_post_key={key}")
        ok(entry_status() == SOFT, f'18c: a moderator with the permission soft deletes the entry [{r["status"]}, status {entry_status()}]')
        grant(board_dir, 'entries_can_soft_delete', 0)

        # ---------------------------------------------------------------- item 20: front page moderators
        print('--- item 20: moderators on the front page')
        group_gid = php(board_dir, 'group', 'Blog Editors')['gid']
        ctx.execute(f"insert into mybb_myshowcase_moderators (showcase_id, user_id, group_id, entries_can_view, can_manage_comments) "
                    f"values ({sid}, {group_gid}, 1, 1, 0)")
        admin_group_title = features.one("select title from mybb_usergroups where gid='1'")
        page = member.get('/showcase.php/blog/')['body']
        line = re.search(r'Moderated by:\s*<strong>(.*?)</strong>', page, re.S)
        block = line.group(1) if line else ''
        ok('member1' in block, f'20a: the user moderator is listed by name on the front page [{"listed" if "member1" in block else "not listed"}]')
        ok('Blog Editors' in block and admin_group_title not in block,
           f'20b: the group moderator is listed by its own group name, not another group [group line: {block[-160:]!r}]')

        ctx.execute(f"delete from mybb_myshowcase_moderators where showcase_id={sid}")
        page = member.get('/showcase.php/blog/')['body']
        ok(not re.search(r'Moderated by:', page), '20c: with no moderators the front page shows no moderator line')

        # ---------------------------------------------------------------- item 22: ACP moderator delete link
        print('--- item 22: ACP moderator delete link')
        moderator_id = php(board_dir, 'moderator-row', 'blog', member_uid, 1, 1)['moderator_id']
        php(board_dir, 'moderator-cache', 'refresh')
        base = f'/admin/index.php?module=myshowcase-summary&action=update&type=delete&showcase_id={sid}&moderator_id={moderator_id}'

        def rows():
            return int(features.one(f"select count(*) from mybb_myshowcase_moderators where moderator_id='{moderator_id}'"))

        def cached():
            return moderator_id in php(board_dir, 'moderator-cache')['moderator_ids']

        ok(rows() == 1 and cached(), '22 setup: the moderator row exists and the cache holds it')
        r = acp.get(base + '&my_post_key=' + '0' * 32)
        ok(rows() == 1 and cached() and 'Invalid post key' in r['body'],
           f'22a: an invalid my_post_key deletes nothing, keeps the cache, and shows the error [rows {rows()}]')
        r = acp.get(base)
        ok(rows() == 1 and cached() and 'Invalid post key' in r['body'],
           f'22b: a missing my_post_key deletes nothing, keeps the cache, and shows the error [rows {rows()}]')
        r = acp.get(base + '&my_post_key=' + acp.post_key)
        ok(rows() == 0 and not cached(),
           f'22c: a valid my_post_key deletes the row and the cache entry [rows {rows()}, cached {cached()}]')

        # ---------------------------------------------------------------- item 1: every field html type
        print('--- item 1: one entry per field html type, no warning, notice, deprecation or fatal error')
        fields_dir = os.path.join(HERE, '..', '..', 'fields')
        imp = os.path.join(board_dir, 'inc', 'plugins', 'myshowcase', 'imports', 'JSON')
        shutil.copy(os.path.join(fields_dir, 'collections', 'fieldkit.json'), os.path.join(imp, 'collections', 'fieldkit.json'))
        shutil.copy(os.path.join(fields_dir, 'showcases', 'fieldkit.json'), os.path.join(imp, 'showcases', 'fieldkit.json'))
        for action in ('preview', 'run'):
            acp.post(adm, {'action': action, 'mode': 'create', 'type': 'showcase', 'file': 'showcases/fieldkit.json', 'name': 'FieldKit',
                           'route_identifier': 'fieldkit', 'custom_template_prefix': ''})
        php(board_dir, 'enable', 'fieldkit')
        fieldkit = json.load(open(os.path.join(fields_dir, 'collections', 'fieldkit.json')))
        first_of_type = {}
        for f in fieldkit['fields']:
            first_of_type.setdefault(f['html_type'], f)

        # the generic values of the text-like types; the choice, file and user/entry/thread types are scanned without a value
        generic = {'text': 'Hello', 'textarea': 'Hello', 'email': 'a@example.com', 'url': 'https://example.com', 'tel': '0123456789',
                   'password': 'Passw0rd!x', 'search': 'Hello', 'number': '5', 'range': '5', 'color': '#336699',
                   'date': '2026-01-02', 'datetime-local': '2026-01-02T10:00', 'time': '10:00', 'month': '2026-01', 'week': '2026-W01'}
        php_log = os.path.join(a.scratch, f'php_{a.engine}.log')
        board_log = os.path.join(board_dir, 'error.log')
        marks = {}

        def log_mark(path):
            marks[path] = os.path.getsize(path) if os.path.exists(path) else 0

        def log_since(path):
            """The bytes added to a log since its mark, and the mark moves to the end (so each type reads its own lines)."""
            if not os.path.exists(path):
                return ''
            with open(path, 'rb') as handle:
                handle.seek(marks.get(path, 0))
                text = handle.read().decode('utf-8', errors='replace')
            log_mark(path)
            return text

        for path in (php_log, board_log):
            log_mark(path)
        warning = re.compile(r'Warning:|Notice:|Deprecated:|Fatal error')
        covered = 0
        for html_type, f in first_of_type.items():
            form = member.get('/showcase.php/fieldkit/create')
            pages = [('create form', form)]
            hash_match = re.search(r'name="post_hash"\s+value="([^"]*)"', form['body'])
            token = f'Type {html_type} {os.urandom(2).hex()}'
            data = {'subject': token, 'message': f'Entry for {html_type}', 'post_hash': hash_match.group(1) if hash_match else '',
                    'my_post_key': post_key(form['body']), 'submit': 'Post'}
            if html_type in generic:
                data[f['field_key']] = generic[html_type]
            pages.append(('post response', member.post('/showcase.php/fieldkit/create', data)))
            made = entry('fieldkit', 'subject', token)
            if made:
                pages.append(('entry page', member.get(f"/showcase.php/fieldkit/view/{made['public_id']}/fresh")))
            found = {label: sorted(set(warning.findall(r['body']))) for label, r in pages}
            found = {label: w for label, w in found.items() if w}
            logged = log_since(php_log) + log_since(board_log)
            noise = [line for line in logged.splitlines() if warning.search(line) or '<message>' in line]
            ok(not found and not noise,
               f'1: {html_type} ({f["field_key"]}): no warning, notice, deprecation or fatal error in the pages or the logs '
               f'[entry {"stored" if made else "not stored"}; in pages {found or "none"}; in logs {len(noise)} lines]')
            covered += 1
        ok(covered == len(first_of_type) and covered >= 20,
           f'1 coverage: one entry attempt for each of the {covered} field html types of FieldKit')

        # ---------------------------------------------------------------- item 11: % and _ in the keyword search
        print('--- item 11: the keyword search takes % and _ as literal characters')
        from urllib.parse import quote  # noqa: E402
        php(board_dir, 'entry', 'blog', 'IA11 100% cotton', VISIBLE)
        php(board_dir, 'entry', 'blog', 'IA11 plain', VISIBLE)
        by_percent = member.get('/showcase.php/blog/search?keywords=' + quote('%'))['body']
        ok('IA11 100% cotton' in by_percent and 'IA11 plain' not in by_percent,
           f'11a: a search for % lists only the entry that contains a literal % '
           f'[cotton listed {"IA11 100% cotton" in by_percent}, plain listed {"IA11 plain" in by_percent}]')
        by_underscore = member.get('/showcase.php/blog/search?keywords=' + quote('_'))['body']
        ok('IA11 100% cotton' not in by_underscore and 'IA11 plain' not in by_underscore,
           f'11b: a search for _ lists neither entry, as neither contains a literal underscore '
           f'[cotton listed {"IA11 100% cotton" in by_underscore}, plain listed {"IA11 plain" in by_underscore}]')
    except Exception as e:  # a crash is a failed check, reported with the summary below
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    finally:
        print(f'\n{PASSES} passed, {len(FAILS)} failed (new5 board checks, {a.engine})')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
        if ctx is not None:
            ctx.close()
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
