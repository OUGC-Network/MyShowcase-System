#!/usr/bin/env python3
"""Decision D142 (c): a real second board. Board A exports its blog entries; board B, a second install whose ids differ (its
gallery collection is created first and extra options are added before the blog's options, so that collection, field and
option ids differ from A's), imports the file as another user. Per entry the checks are: each option field holds B's field_data_id
of the option with the value id of the file; the label of that option is shown on B's entry page; the text fields are equal.
Users, groups and file URLs of the file are not compared (decision D137).

    python3 tests/import/http/second_board.py --engine sqlite|mysql|pgsql --mybb /path/to/mybb-1.9 --scratch DIR
                                              [--port-a N] [--port-b N] [--pluginlibrary-file FILE]

Both boards are installed in DIR (board_a and board_b for SQLite; one msc_test_ database each for MySQL, both dropped at the end).
Exit code 1 if a check fails.
"""
import argparse, json, os, random, re, shutil, string, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'features', 'http'))
sys.path.insert(0, HERE)

import board  # noqa: E402
import flows  # noqa: E402

TEXT_COLUMNS = ['subject', 'summary', 'tags', 'cover_image', 'reading_time', 'series', 'series_part', 'meta_description', 'source_url']

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''))
p.add_argument('--scratch', required=True)
p.add_argument('--port-a', type=int, default=0)
p.add_argument('--port-b', type=int, default=0)
p.add_argument('--pluginlibrary-file', default=os.environ.get('PLUGINLIBRARY', ''))
a = p.parse_args()

if not a.mybb or not os.path.isdir(a.mybb):
    sys.exit('give --mybb (or MYBB_SOURCE): a MyBB 1.9 source tree with its vendor/ folder')

pl_file = a.pluginlibrary_file or os.path.join(REPO, '..', '_deps', 'MyBB-PluginLibrary', 'inc', 'plugins', 'pluginlibrary.php')
if not os.path.isfile(pl_file):
    sys.exit('PluginLibrary not found: ' + pl_file)

os.makedirs(a.scratch, exist_ok=True)
port_a = a.port_a or 18094
port_b = a.port_b or 18095


def random_db():
    return 'msc_test_' + ''.join(random.choice(string.ascii_lowercase + string.digits) for _ in range(10))


def start(ctx, scratch, port, label):
    """Copies the forum files into the board and starts its server with the error settings of the run (every PHP notice goes to the log)."""
    upload = os.path.join(REPO, 'Upload')
    for rel in ('showcase.php', 'inc/tasks/myshowcase.php', 'jscripts', 'images'):
        src, dst = os.path.join(upload, rel), os.path.join(ctx.board, rel)
        if os.path.isdir(src):
            shutil.copytree(src, dst, dirs_exist_ok=True)
        else:
            shutil.copy(src, dst)
    ctx.stop()
    log = os.path.join(scratch, f'php_{label}_errors.log')
    ctx.server = subprocess.Popen(
        ['php', '-d', 'error_reporting=32767', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', f'error_log={log}', '-d', 'max_execution_time=120',
         '-S', f'127.0.0.1:{port}', '-t', ctx.board],
        stdout=open(os.path.join(scratch, f'php_{label}.log'), 'w'), stderr=subprocess.STDOUT)
    time.sleep(1.5)
    return log


def finish(ctx):
    if ctx is None:
        return
    ctx.close()  # stops the server and drops the database, and checks that it is gone (D225)
    if a.engine in ('mysql', 'pgsql'):
        print(f'dropped database {ctx.dbname}')


scratch_a = os.path.join(a.scratch, 'board_a_dir')
scratch_b = os.path.join(a.scratch, 'board_b_dir')
os.makedirs(scratch_a, exist_ok=True)
os.makedirs(scratch_b, exist_ok=True)

# ---- board A: the author's blog entries and their export ----
db_a = random_db()
ctx_a = None
try:
    ctx_a = board.make_board(a.engine, a.mybb, scratch_a, port_a, 'real', pl_file, dbname=db_a)
    log_a = start(ctx_a, scratch_a, port_a, 'a')
    acp_a = board.activate(ctx_a)
    flows.configure(ctx_a, scratch_a, log_a)
    flows.create_blog(acp_a)
    # the downloads showcase before the setup fixture: its import permission is granted with the others (as in run.py)
    flows.create_showcase(acp_a, 'downloads', 'Downloads')
    flows.fixture('setup', os.path.join(scratch_a, 'users.json'))
    flows.USERS = json.load(open(os.path.join(scratch_a, 'users.json')))
    flows.fixture('showcase-setting', 'blog', 'import_export_flood_seconds', '0')
    author = flows.logged_in('member1')
    entries = [flows.good_entry(i, category=1 + (i % 4)) for i in range(1, 5)]
    path = flows.write_file('second-board-a.xml', flows.xml_file(entries))
    body = flows.import_into(author, 'blog', path)['body']
    report = flows.report_of(body)
    flows.ok(report is not None and report['status'] == 'completed', 'board A: the four blog entries are created by import')
    sid_a = int(flows.one("select showcase_id from mybb_myshowcase_config where route_identifier='blog'"))
    coll_a = int(flows.one("select collection_id from mybb_myshowcase_collections where name='Blog'"))
    field_a = {row[0]: row[1] for row in flows.db(f"select field_key, field_id from mybb_myshowcase_fields where collection_id={coll_a}")}
    options_a = {int(row[0]): (str(row[1]), str(row[2])) for row in flows.db(
        f"select field_data_id, value_id, value from mybb_myshowcase_field_data where field_id={field_a['category']}")}
    export_form = author.get(f'/showcase.php/blog/export')
    author.post('/showcase.php/blog/export', {'my_post_key': flows.post_key(export_form['body']), 'limit': '0',
                                              'order_by': 'dateline', 'order_dir': 'asc', 'submit': 'Export'})
    export_id = flows.one(f"select export_id from mybb_myshowcase_exports where user_id={flows.USERS['member1']['uid']} order by export_id desc limit 1")
    download = author.get(f'/showcase.php/blog/export/{export_id}/download')
    export_path = os.path.join(scratch_a, 'blog-export.xml')
    open(export_path, 'wb').write(download['raw'])
    flows.ok(download['status'] == 200 and b'myshowcase-entries' in download['raw'], 'board A: the blog entries export as an entries file')
    snapshot_a = {}
    for row in flows.db(f"select subject, category, public_id, entry_slug_custom, {', '.join(c for c in TEXT_COLUMNS if c != 'subject')} "
                        f"from mybb_myshowcase_data{sid_a} where user_id={flows.USERS['member1']['uid']} order by entry_id"):
        subject, category, pid, slug = row[0], str(row[1]), row[2], row[3]
        texts = dict(zip([c for c in TEXT_COLUMNS if c != 'subject'], row[4:]))
        value_id = options_a[int(category)][0]
        page = author.get(f'/showcase.php/blog/view/{pid}/{slug}')['body']
        flows.ok(flows.page_has_label(page, options_a[int(category)][1]), f'board A: the entry page shows the label {options_a[int(category)][1]!r} of {subject!r}')
        snapshot_a[subject] = {'value_id': value_id, 'label': options_a[int(category)][1], 'texts': texts}
    # several platforms (decision D182): a downloads entry with two platforms, exported and imported into board B. Board A's
    # entry holds the value ids of its platforms in option order; B must hold the same value ids with the same labels, in order.
    flows.fixture('files-setup', 'import-test-uploads')
    flows.fixture('external-setting', '1')
    time.sleep(3)  # the server's opcache revalidates inc/settings.php on its own clock
    zip_url = 'https://github.com/mybb/mybb/archive/refs/heads/dev-1.9.zip'
    dl_entry = flows.downloads_entry(95, zip_url)
    dl_entry['fields'].update({'category': 2, 'platform': ['1', '3'], 'license': 2,
                               'previews': ['https://placehold.co/600x450.png?text=Preview%20one', 'https://placehold.co/600x450.png?text=Preview%20two']})
    body = flows.import_into(author, 'downloads', flows.write_file('second-board-downloads-a.xml', flows.xml_file([dl_entry], route='downloads', collection='Downloads', format_version=3)))['body']
    report = flows.report_of(body)
    flows.ok(report is not None and report['status'] == 'completed', 'board A: the downloads entry with two platforms is created by import')
    dl_sid_a = int(flows.one("select showcase_id from mybb_myshowcase_config where route_identifier='downloads'"))
    dl_field_a = flows.one("select field_id from mybb_myshowcase_fields where field_key='platform' and collection_id=(select collection_id from mybb_myshowcase_collections where name='Downloads')")
    labels_a = {str(row[0]): str(row[1]) for row in flows.db(f"select value_id, value from mybb_myshowcase_field_data where field_id={dl_field_a}")}
    dl_export_form = author.get('/showcase.php/downloads/export')
    author.post('/showcase.php/downloads/export', {'my_post_key': flows.post_key(dl_export_form['body']), 'limit': '0',
                                                   'order_by': 'dateline', 'order_dir': 'asc', 'submit': 'Export'})
    dl_export_id = flows.one(f"select export_id from mybb_myshowcase_exports where user_id={flows.USERS['member1']['uid']} order by export_id desc limit 1")
    dl_download = author.get(f'/showcase.php/downloads/export/{dl_export_id}/download')
    dl_export_path = os.path.join(scratch_a, 'downloads-export.xml')
    open(dl_export_path, 'wb').write(dl_download['raw'])
    # board A's file URLs point at its own address (a port other than 80 and 443, which board B may not fetch from, D53). The file
    # is the GitHub archive, so the export names that archive: board B fetches the same bytes from an allowed host.
    exported_text = open(dl_export_path, encoding='utf-8', errors='replace').read()
    # the export names board A's file addresses in order: the archive, then the two previews. Board B may fetch only from the
    # ports 80 and 443 (D53), so each address is replaced by the archive on GitHub or by a placehold.co image.
    replacements = iter([zip_url, 'https://placehold.co/600x450.png?text=Preview%20one', 'https://placehold.co/600x450.png?text=Preview%20two'])
    exported_text = re.sub(r'https?://127\.0\.0\.1:\d+/[^<\s"]*', lambda m: next(replacements, zip_url), exported_text)
    open(dl_export_path, 'w', encoding='utf-8').write(exported_text)
    snapshot_dl = {}
    for entry_id, subject in flows.db(f"select entry_id, subject from mybb_myshowcase_data{dl_sid_a} where user_id={flows.USERS['member1']['uid']} order by entry_id"):
        platforms = flows.db(f"select value_id from mybb_myshowcase_field_values where showcase_id={dl_sid_a} and entry_id={entry_id} "
                             f"and field_id={dl_field_a} order by field_value_id")
        snapshot_dl[subject] = [(str(vid), labels_a.get(str(vid), '')) for (vid,) in platforms]
    flows.ok(bool(snapshot_dl) and all(len(v) == 2 for v in snapshot_dl.values()), f'board A: the entry holds two platforms ({snapshot_dl})')
finally:
    finish(ctx_a)

# ---- board B: a second install with other ids, the importer is another user ----
db_b = random_db()
ctx_b = None
try:
    ctx_b = board.make_board(a.engine, a.mybb, scratch_b, port_b, 'real', pl_file, dbname=db_b)
    log_b = start(ctx_b, scratch_b, port_b, 'b')
    acp_b = board.activate(ctx_b)
    flows.configure(ctx_b, scratch_b, log_b)
    # the gallery collection first, and extra options before the blog's options: ids that differ from board A's
    acp_b.get('/admin/index.php?module=myshowcase-json_sync&action=create&type=collection')
    for action in ('preview', 'run'):
        acp_b.post('/admin/index.php?module=myshowcase-json_sync', {'action': action, 'mode': 'create', 'type': 'collection',
                                                                    'file': 'collections/gallery.json', 'name': 'Gallery'})
    gallery_field = flows.one("select field_id from mybb_myshowcase_fields where collection_id=(select collection_id from mybb_myshowcase_collections where name='Gallery') order by field_id limit 1")
    gallery_collection = flows.one("select collection_id from mybb_myshowcase_collections where name='Gallery'")
    for extra in range(5):
        ctx_b.execute(f"insert into mybb_myshowcase_field_data (collection_id, field_id, field_key, value_id, value, allowed_groups_fill) "
                      f"select collection_id, field_id, field_key, '{900 + extra}', 'Extra {extra}', '-1' from mybb_myshowcase_fields "
                      f"where field_id={gallery_field}")
    flows.create_blog(acp_b)
    flows.create_showcase(acp_b, 'downloads', 'Downloads')
    flows.fixture('setup', os.path.join(scratch_b, 'users.json'))
    flows.USERS = json.load(open(os.path.join(scratch_b, 'users.json')))
    flows.fixture('showcase-setting', 'blog', 'import_export_flood_seconds', '0')
    coll_b = int(flows.one("select collection_id from mybb_myshowcase_collections where name='Blog'"))
    field_b = {row[0]: row[1] for row in flows.db(f"select field_key, field_id from mybb_myshowcase_fields where collection_id={coll_b}")}
    options_b = {str(row[1]): (str(row[0]), str(row[2])) for row in flows.db(
        f"select field_data_id, value_id, value from mybb_myshowcase_field_data where field_id={field_b['category']}")}
    flows.ok(str(coll_b) != str(coll_a) and str(field_b['category']) != str(field_a['category']),
             f'the ids differ: the blog collection {coll_a} on A and {coll_b} on B, the category field {field_a["category"]} and {field_b["category"]}')
    flows.ok(all(options_b[v][0] != str(fid) for fid, (v, _) in options_a.items() if v in options_b),
             'every category option has another field_data_id on B than on A')
    member_b = flows.logged_in('member2')
    body = flows.import_into(member_b, 'blog', export_path)['body']
    report = flows.report_of(body)
    flows.ok(report is not None and report['status'] == 'completed', f"board B: the file of board A is imported by member2 ({report['text'][:140] if report else 'no report'})")
    sid_b = int(flows.one("select showcase_id from mybb_myshowcase_config where route_identifier='blog'"))
    uid2 = flows.USERS['member2']['uid']
    for subject, expected in snapshot_a.items():
        subject_sql = subject.replace("'", "''")
        rows = flows.db(f"select entry_id, category, public_id, entry_slug_custom, {', '.join(c for c in TEXT_COLUMNS if c != 'subject')} "
                        f"from mybb_myshowcase_data{sid_b} where subject='{subject_sql}' and user_id={uid2} order by entry_id")
        if len(rows) != 1:
            flows.ok(False, f'board B: one entry {subject!r} after the import (found {len(rows)})')
            continue
        row = rows[0]
        stored = str(row[1])
        option_b = options_b.get(expected['value_id'], ('', ''))
        flows.ok(stored == option_b[0] and stored != '',
                 f"board B: {subject!r} holds the field_data_id {stored} of value id {expected['value_id']} (B's is {option_b[0]})")
        page = member_b.get(f'/showcase.php/blog/view/{row[2]}/{row[3]}')['body']
        flows.ok(flows.page_has_label(page, expected['label']), f"board B: the entry page shows the label {expected['label']!r} as board A does")
        texts_b = dict(zip([c for c in TEXT_COLUMNS if c != 'subject'], row[4:]))
        differences = {c: (expected['texts'][c], texts_b[c]) for c in expected['texts'] if str(expected['texts'][c]) != str(texts_b[c])}
        flows.ok(not differences, f'board B: the text fields of {subject!r} are equal to A\'s ({differences or "all equal"})')
    flows.ok(len(snapshot_a) == 4, f'board B: the four entries of board A are on B ({len(snapshot_a)} in A)')
    # the downloads entries of board A, on B: the same value ids, with the same labels, in the same order
    flows.fixture('files-setup', 'import-test-uploads')
    flows.fixture('external-setting', '1')
    time.sleep(3)  # the server's opcache revalidates inc/settings.php on its own clock
    dl_b_import = flows.import_into(member_b, 'downloads', dl_export_path)['body']
    dl_report = flows.report_of(dl_b_import)
    flows.ok(dl_report is not None and dl_report['status'] == 'completed', f"board B: the downloads file of board A is imported ({dl_report['text'][:600] if dl_report else 'no report'})")
    dl_sid_b = int(flows.one("select showcase_id from mybb_myshowcase_config where route_identifier='downloads'"))
    dl_field_b = flows.one("select field_id from mybb_myshowcase_fields where field_key='platform' and collection_id=(select collection_id from mybb_myshowcase_collections where name='Downloads')")
    labels_b = {str(row[0]): str(row[1]) for row in flows.db(f"select value_id, value from mybb_myshowcase_field_data where field_id={dl_field_b}")}
    for subject, expected in snapshot_dl.items():
        rows_b = flows.db(f"select entry_id from mybb_myshowcase_data{dl_sid_b} where subject='{subject.replace(chr(39), chr(39) * 2)}' and user_id={uid2} order by entry_id")
        if len(rows_b) != 1:
            flows.ok(False, f'board B: one downloads entry {subject!r} after the import (found {len(rows_b)})')
            continue
        platforms_b = [(str(vid), labels_b.get(str(vid), '')) for (vid,) in flows.db(
            f"select value_id from mybb_myshowcase_field_values where showcase_id={dl_sid_b} and entry_id={rows_b[0][0]} "
            f"and field_id={dl_field_b} order by field_value_id")]
        flows.ok(platforms_b == expected,
                 f'board B: {subject!r} holds the platforms {platforms_b}, as A holds {expected} (value ids and labels, in order)')
        # the previews: two files of the entry on B, in display order, as A holds them (decision D197)
        preview_field_b = flows.one("select field_id from mybb_myshowcase_fields where field_key='previews' and collection_id="
                                    "(select collection_id from mybb_myshowcase_collections where name='Downloads')")
        orders_b = [r[0] for r in flows.db(f"select display_order from mybb_myshowcase_attachments where entry_id={rows_b[0][0]} "
                                           f"and field_id={preview_field_b} order by display_order")]
        flows.ok(orders_b == ['1', '2'], f'board B: {subject!r} holds its two previews in display order {orders_b}')
    # the content seeders (decisions D40 and D43): each full seed is imported into board B, whose option ids differ from A's
    # and the seed's; every option is compared by its label (the value id of the seed, read through B's own ids), every list in
    # order, every text and number as sent, every file by name, type and size (the same checks as the import suite)
    flows.create_showcase(acp_b, 'gallery', 'Gallery')
    flows.fixture('showcase-setting', 'gallery', 'is_enabled', '1')  # the JSON sync creates a showcase switched off
    flows.fixture('files-setup', 'import-test-uploads')
    flows.fixture('external-setting', '1')
    time.sleep(3)  # the server's opcache revalidates inc/settings.php on its own clock
    for name, route in (('blog-full', 'blog'), ('downloads-full', 'downloads'), ('gallery-full', 'gallery')):
        first_attachment = int(flows.one('select max(attachment_id) from mybb_myshowcase_attachments') or 0) if flows.one('select max(attachment_id) from mybb_myshowcase_attachments') not in ('', 'NULL') else 0
        body = flows.import_into(member_b, route, os.path.join(flows.SEEDS_DIR, name + '.xml'))['body']
        report = flows.report_of(body)
        count = len(flows.seed_entries(name))
        flows.ok(report is not None and report['status'] == 'completed' and f'{count} entries were imported' in report['text'],
                 f"board B: the seed {name} is imported by member2 ({report['text'][:140] if report else 'no report'})")
        if report is None or report['status'] != 'completed':
            continue
        problems = []
        for index, entry in enumerate(flows.seed_entries(name)):
            problems += flows.check_seed_entry(member_b, route, entry, uid2, f'board B {name} entry {index + 1}', first_attachment)
        flows.ok(not problems, f'board B: every field of the seed {name} holds its seed value, by label and in order'
                               + (f': {problems[:3]}' if problems else ''))
finally:
    finish(ctx_b)

print(f'\n{flows.PASSES} passed, {len(flows.FAILS)} failed (second board, {a.engine}); users and groups and file URLs are not compared (D137)')
for f in flows.FAILS:
    print('  FAILED:', f)
sys.exit(1 if flows.FAILS else 0)
