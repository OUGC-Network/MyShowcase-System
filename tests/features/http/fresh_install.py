#!/usr/bin/env python3
"""Fresh-install stage (decision D3): a board is installed and the plugin activated, the shipped showcases are created, and the
global group permissions are NOT saved by anyone. The stage then checks what the plugin does with the missing rows:

  S1 reach      every group of the board reaches what the defaults allow (the list, an entry, an attachment; the new-entry form is
                refused, because the defaults say entries_can_post 0)
  S2 cycles     deactivate and activate: one row per group, each equal to the defaults
  S3 partial    a row saved for one group stays byte-identical; the missing rows are added; no duplicate row
  S4 late group a group created after the install reaches the defaults, and reading it writes no row
  S5 idempotent a second activation changes no row
  S6 ACP form   the group form of the ACP shows the seeded values; saving it unchanged changes nothing
  S7 uninstall  the seeded rows go with the table when the plugin is uninstalled with the table-drop setting

The defaults are read from the board (TABLES_DATA), not written here. The fixture is tests/features/http/fresh_fixture.php
(test data only; it seeds no permission row).

    python3 tests/features/http/fresh_install.py --engine sqlite|mysql|pgsql --mybb DIR --scratch DIR [--port N]

Exit code 1 if a check fails.
"""
import argparse, json, os, re, subprocess, sys, time
from html.parser import HTMLParser

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, HERE)

import board  # noqa: E402
import features  # noqa: E402  (its configure() copies the forum's entry point into the board)
from front import Front, post_key  # noqa: E402

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


class Forms(HTMLParser):
    """Every form of a page: its action, the values a browser would send, and the showcase controls with their shown values."""

    def __init__(self):
        super().__init__()
        self.forms = []
        self.current = None
        self.select = None

    def handle_starttag(self, tag, attrs):
        at = dict(attrs)
        if tag == 'form':
            self.current = {'action': at.get('action', ''), 'fields': {}, 'shown': {}}
            self.forms.append(self.current)
        elif self.current is None:
            return
        elif tag == 'select' and at.get('name'):
            self.select = at['name']
            self.current['fields'].setdefault(self.select, None)
            if self.select.startswith('showcase['):
                self.current['shown'].setdefault(self.select, None)
        elif tag == 'option' and self.select:
            # the selected option is sent; without one, the first is (as a browser does)
            if 'selected' in at or self.current['fields'][self.select] is None:
                self.current['fields'][self.select] = at.get('value', '')
            if self.select.startswith('showcase[') and ('selected' in at or self.current['shown'][self.select] is None):
                self.current['shown'][self.select] = at.get('value', '')
        elif tag == 'input' and at.get('name'):
            name, kind = at['name'], at.get('type', 'text')
            fields = self.current['fields']
            if kind in ('checkbox', 'radio'):
                if 'checked' in at:
                    fields[name] = at.get('value', 'on')
                    if name.startswith('showcase['):
                        self.current['shown'][name] = at.get('value', 'on')
                elif name.startswith('showcase['):
                    self.current['shown'].setdefault(name, None)
            else:
                fields[name] = at.get('value', '')
                if name.startswith('showcase['):
                    self.current['shown'][name] = at.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'select':
            self.select = None
        elif tag == 'form':
            self.current = None


def group_controls(html):
    """The form of the group's permissions (the one with showcase controls): its action, the values a save would post, and the
    shown value of each showcase control (keyed by column)."""
    parser = Forms()
    parser.feed(html)
    for form in parser.forms:
        if form['shown']:
            shown = {name[len('showcase['):-1]: value for name, value in form['shown'].items()}
            return form['action'], form['fields'], shown
    return '', {}, {}


def php(board_dir, *args):
    r = subprocess.run(['php', os.path.join(HERE, 'fresh_fixture.php'), *[str(x) for x in args]], cwd=board_dir, capture_output=True, text=True)
    lines = [l for l in r.stdout.splitlines() if l.strip()]
    if r.returncode or not lines:
        raise RuntimeError(f'fixture {args[0]} failed: {r.stdout[-500:]} {r.stderr[-500:]}')
    return json.loads(lines[-1])


def main():
    scratch = a.scratch
    os.makedirs(scratch, exist_ok=True)
    port = a.port or {'sqlite': 18710, 'mysql': 18711, 'pgsql': 18712}[a.engine]
    dbname = f'msc_test_fresh_{a.engine}_' + os.urandom(3).hex()
    ctx = board.make_board(a.engine, a.mybb, scratch, port, 'real', None, dbname=dbname)
    try:
        run(ctx, scratch)
    finally:
        ctx.stop()
        # the throwaway database of this run is dropped (MySQL and PostgreSQL; SQLite's file goes with the scratch folder)
        if a.engine == 'mysql':
            env = board.db_env()
            subprocess.run([env['MYSQL_BIN'], '--no-defaults', f"-h{env['MYSQL_HOST']}", f"-P{env['MYSQL_PORT']}", f"-u{env['MYSQL_USER']}",
                            '-e', f'DROP DATABASE IF EXISTS {dbname}'], check=False)
        if a.engine == 'pgsql':
            env = board.db_env()
            subprocess.run([env['PSQL_BIN'], '-h', env['PGHOST'], '-p', env['PGPORT'], '-U', env['PGUSER'], '-d', 'postgres',
                            '-c', f'DROP DATABASE IF EXISTS {dbname}'], check=False, capture_output=True)


def run(ctx, scratch):
    board_dir = ctx.board
    acp = board.activate(ctx)  # install and activation (no permission is saved)
    features.configure(ctx, scratch)
    adm = '/admin/index.php?module=myshowcase-json_sync'
    for route, name in (('blog', 'Blog'), ('gallery', 'Gallery')):
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': f'collections/{route}.json'})
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': f'showcases/{route}.json', 'name': name, 'route_identifier': route})
    php(board_dir, 'enable', 'blog', 'gallery')
    png = os.path.join(scratch, 'fresh.png')
    import base64
    open(png, 'wb').write(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))
    users_file = os.path.join(scratch, 'users.json')
    php(board_dir, 'users', users_file)
    users = json.load(open(users_file))
    php(board_dir, 'types', 'blog', 'gallery')
    defaults = php(board_dir, 'defaults')
    group_ids = [int(r[0]) for r in ctx.query('select gid from mybb_usergroups order by gid')]
    entries, attachments = {}, {}
    for route in ('blog', 'gallery'):
        entries[route] = php(board_dir, 'entry', route, f'Fresh {route} entry')
        attachments[route] = php(board_dir, 'attachment', route, entries[route]['entry_id'], png)

    def rows():
        found = php(board_dir, 'rows')
        return found if isinstance(found, dict) else {}  # an empty table is an empty JSON list

    def check_rows_are_defaults(label, snapshot):
        ok(bool(snapshot) and sorted(snapshot.keys(), key=int) == [str(g) for g in group_ids],
           f'{label}: one global row per group ({sorted(snapshot.keys(), key=int)} of {group_ids})')
        bad = []
        for gid, row in snapshot.items():
            for column, value in defaults.items():
                if str(row.get(column)) != str(value):
                    bad.append((gid, column, row.get(column), value))
        ok(not bad, f'{label}: every seeded value equals its default' + (f' (first differences: {bad[:3]})' if bad else ''))

    # ---- S1: reach, before any permission has been saved
    print('--- S1 reach: the defaults, for every group, with no saved permission')
    clients = {'guest': Front(ctx.url)}
    for name in ('member1', 'mod1'):
        client = Front(ctx.url)
        client.login(name, users[name]['password'])
        clients[name] = client
    admin = Front(ctx.url)
    admin.login(ctx.user, ctx.password)
    clients['admin'] = admin
    for role, client in clients.items():
        for route in ('blog', 'gallery'):
            r = client.get(f'/showcase.php/{route}/')
            ok(r['status'] == 200, f'{role}: the {route} list answers 200 (main_can_view and entries_can_view default to 1) [{r["status"]}]')
            e = entries[route]
            r = client.get(f'/showcase.php/{route}/view/{e["public_id"]}/{e["slug"]}')
            ok(r['status'] == 200, f'{role}: an approved {route} entry answers 200 [{r["status"]}]')
            att = attachments[route]
            r = client.get(f'/showcase.php/{route}/attachment/{att["public_id"]}/{att["name"]}')
            ok(r['status'] == 200, f'{role}: the {route} attachment downloads (attachments_can_view and _download default to 1) [{r["status"]}]')
            r = client.get(f'/showcase.php/{route}/create')
            ok(r['status'] == 403, f'{role}: the {route} new-entry form is refused (entries_can_post defaults to 0) [{r["status"]}]')

    # ---- S2: deactivate and activate
    print('--- S2 cycles: deactivate and activate')
    for step in ('deactivate', 'activate'):
        if step == 'deactivate':
            acp.get('/admin/index.php?module=config-plugins')
            r = acp.get(f'/admin/index.php?module=config-plugins&action=deactivate&plugin=myshowcase&my_post_key={acp.post_key}')
        else:
            acp.get('/admin/index.php?module=config-plugins')
            r = acp.get(f'/admin/index.php?module=config-plugins&action=activate&plugin=myshowcase&my_post_key={acp.post_key}')
        ok(r['status'] == 200 and 'MyBB SQL Error' not in r['body'], f'the plugin is {step}d through the ACP [{r["status"]}]')
    check_rows_are_defaults('after a deactivate and activate cycle', rows())

    # ---- S3: partly saved: one group saved, two rows missing
    print('--- S3 partial: one saved row stays, missing rows are added')
    php(board_dir, 'save-row', 2, 'entries_can_post', 1)
    saved_before = rows()['2']
    php(board_dir, 'delete-row', 3)
    php(board_dir, 'delete-row', 4)
    acp.get('/admin/index.php?module=config-plugins')
    acp.get(f'/admin/index.php?module=config-plugins&action=deactivate&plugin=myshowcase&my_post_key={acp.post_key}')
    acp.get('/admin/index.php?module=config-plugins')
    acp.get(f'/admin/index.php?module=config-plugins&action=activate&plugin=myshowcase&my_post_key={acp.post_key}')
    after = rows()
    ok(after.get('2') == saved_before, 'the saved row of group 2 is byte-identical after the activation (saved values stay)')
    ok(all(str(after.get(str(g), {}).get(c)) == str(v) for g in (3, 4) for c, v in defaults.items() if c == 'entries_can_post') and '3' in after and '4' in after,
       'the missing rows of groups 3 and 4 are added with the defaults')
    ok(len([1 for r in ctx.query("select group_id from mybb_myshowcase_permissions where showcase_id=0 and group_id=2")]) == 1,
       'no duplicate row for group 2')
    php(board_dir, 'save-row', 2, 'entries_can_post', 0)  # back to the default, so the next checks see the defaults

    # ---- S4: a group created after the install
    print('--- S4 late group: a group created after the install, with no row')
    late = php(board_dir, 'group', 'Late members')
    late_gid = late['gid']
    late_user = php(board_dir, 'user', 'late1', late_gid)
    late_client = Front(ctx.url)
    late_client.login('late1', late_user['password'])
    e = entries['blog']
    r = late_client.get('/showcase.php/blog/')
    ok(r['status'] == 200, f'a member of a group created after the install reaches the blog list (defaults) [{r["status"]}]')
    r = late_client.get(f'/showcase.php/blog/view/{e["public_id"]}/{e["slug"]}')
    ok(r['status'] == 200, f'and an approved entry [{r["status"]}]')
    ok(str(late_gid) not in rows(), 'reading as that group wrote no row (no write on read)')

    # ---- S5: a second activation
    print('--- S5 idempotent: a second activation changes no row')
    before = rows()
    acp.get('/admin/index.php?module=config-plugins')
    acp.get(f'/admin/index.php?module=config-plugins&action=deactivate&plugin=myshowcase&my_post_key={acp.post_key}')
    acp.get('/admin/index.php?module=config-plugins')
    acp.get(f'/admin/index.php?module=config-plugins&action=activate&plugin=myshowcase&my_post_key={acp.post_key}')
    after = rows()
    ok({k: v for k, v in after.items() if k != str(late_gid)} == {k: v for k, v in before.items() if k != str(late_gid)},
       'the second activation leaves every row byte-identical')

    # ---- S6: the ACP group form shows the seeded values; saving it unchanged changes nothing
    print('--- S6 ACP form: the group form (group 4, whose row S3 removed and the activation seeded) and an unchanged save')
    page = acp.get('/admin/index.php?module=user-groups&action=edit&gid=4')
    action, fields, shown = group_controls(page['body'])
    seeded = rows().get('4', {})
    differ = [(c, shown.get(c), v) for c, v in defaults.items() if str(shown.get(c)) != str(seeded.get(c))]
    ok(page['status'] == 200 and len(shown) >= len(defaults) and not differ,
       f'the group form shows the seeded values for every permission ({len(shown)} controls; differences: {differ[:3]})')
    before_save = rows()
    count_before = len(before_save)
    if not action or action.startswith('index.php'):
        action = '/admin/' + (action or 'index.php?module=user-groups&action=edit&gid=4')
    saved = acp.post(action, dict(fields, submit='Save'))
    after_save = rows()
    ok(len(after_save) == count_before and after_save.get('4') == before_save.get('4'),
       f'saving the group form unchanged leaves its row byte-identical and adds no row [{saved["status"]}]')

    # ---- S8: a refused main view answers the no-permission page on every showcase route (decision D221)
    print('--- S8 refused main view: 403 and the no-permission page on every showcase route, for the refused group only')
    php(board_dir, 'save-row', 1, 'main_can_view', 0)
    e, att = entries['blog'], attachments['blog']
    routes = [
        ('list', '/showcase.php/blog/'),
        ('entry page', f'/showcase.php/blog/view/{e["public_id"]}/{e["slug"]}'),
        ('new-entry form', '/showcase.php/blog/create'),
        ('attachment', f'/showcase.php/blog/attachment/{att["public_id"]}/{att["name"]}'),
        ('thumbnail', f'/showcase.php/blog/thumbnail/{att["public_id"]}/{att["name"]}'),
    ]
    guest_refused = Front(ctx.url)
    for label, path in routes:
        r = guest_refused.get(path)
        text = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', r['body']))
        ok(r['status'] == 403 and 'do not have permission' in text and 'Missing namespace' not in text,
           f'guest with main_can_view refused: the {label} answers 403 with the no-permission page [{r["status"]}]')
    member_ok = clients['member1']
    r = member_ok.get('/showcase.php/blog/')
    ok(r['status'] == 200, f'a member whose group is not refused still reaches the list [{r["status"]}]')
    php(board_dir, 'save-row', 1, 'main_can_view', 1)

    # ---- S7: uninstall with the table-drop setting: the seeded rows go with the table
    print('--- S7 uninstall: the rows go with the table')
    php(board_dir, 'setting', 'myshowcase_delete_tables_on_uninstall', 1)
    acp.get('/admin/index.php?module=config-plugins')
    acp.get(f'/admin/index.php?module=config-plugins&action=deactivate&plugin=myshowcase&my_post_key={acp.post_key}')
    acp.get('/admin/index.php?module=config-plugins')
    seeded_count = len(rows())
    r = acp.get(f'/admin/index.php?module=config-plugins&action=uninstall&plugin=myshowcase&my_post_key={acp.post_key}')
    ok(seeded_count >= len(group_ids), f'the rows exist before the uninstall ({seeded_count})')
    ok(not php(board_dir, 'table-exists', 'mybb_myshowcase_permissions')['exists'],
       'the uninstall drops the permission table, with its seeded and saved rows')

    print(f'\n{PASSES} passed, {len(FAILS)} failed ({a.engine})')
    for f in FAILS:
        print('  FAILED:', f)


if __name__ == '__main__':
    main()
    sys.exit(1 if FAILS else 0)
