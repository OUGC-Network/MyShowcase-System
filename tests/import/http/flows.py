"""The HTTP checks of the entries import. run.py installs the board, creates the blog showcase (create_blog) and calls
run(). Every check prints PASS or FAIL; FAILS and NOT_RUN collect what did not pass or could not run."""
import glob, hashlib, html, http.server, json, os, re, secrets, subprocess, sys, tempfile, threading, time, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'features', 'http'))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))

from front import Front, post_key  # noqa: E402  (tests/features/http/front.py)
from acp import text  # noqa: E402  (tests/json-sync/http/acp.py)

FAILS = []
NOT_RUN = []
PASSES = 0
CTX = None
SCRATCH = BOARD = ENGINE = ERROR_LOG = None
USERS = {}
SHOWCASE = '/showcase.php/blog'


def configure(ctx, scratch, error_log):
    global CTX, SCRATCH, BOARD, ENGINE, ERROR_LOG
    CTX, SCRATCH, BOARD, ENGINE, ERROR_LOG = ctx, scratch, ctx.board, ctx.engine, error_log


def ok(cond, message):
    global PASSES
    print(('PASS ' if cond else 'FAIL ') + message)
    if cond:
        PASSES += 1
    else:
        FAILS.append(message)


def not_run(message):
    print('NOT RUN ' + message)
    NOT_RUN.append(message)


def db(sql):
    return CTX.query(sql)


def one(sql):
    rows = db(sql)
    return rows[0][0] if rows else ''


def fixture(*args):
    r = subprocess.run(['php', os.path.join(HERE, 'fixture.php')] + [str(x) for x in args], cwd=BOARD, capture_output=True, text=True)
    lines = [l for l in r.stdout.splitlines() if l.strip()]
    if r.returncode or not lines:
        raise RuntimeError(f'fixture {args[0]} failed: {r.stdout[-800:]} {r.stderr[-800:]}')
    return json.loads(lines[-1])


def sid():
    return int(one("select showcase_id from mybb_myshowcase_config where route_identifier='blog'") or 0)


def cid():
    return int(one("select collection_id from mybb_myshowcase_config where route_identifier='blog'") or 0)


def data_table():
    return f'mybb_myshowcase_data{sid()}'


def entry_count(user=None):
    where = f' where user_id={int(user)}' if user is not None else ''
    return int(one(f'select count(*) from {data_table()}{where}') or 0)


def import_log_count():
    return int(one(f"select count(*) from mybb_myshowcase_logs where showcase_id={sid()} and type_id=3") or 0)


def option_id(label):
    return one(
        'select d.value_id from mybb_myshowcase_field_data d, mybb_myshowcase_fields f '
        f"where d.field_id=f.field_id and f.field_key='category' and d.value='{label}' and f.collection_id={cid()}"
    )


def logged_in(username):
    client = Front(CTX.url)
    client.login(username, USERS[username]['password'])
    return client


def count_php_tmp_files():
    tmp = subprocess.run(['php', '-r', 'echo sys_get_temp_dir();'], capture_output=True, text=True).stdout.strip()
    return len([n for n in os.listdir(tmp) if re.fullmatch(r'php[A-Za-z0-9]{6}', n)])


def error_log_lines():
    if not os.path.exists(ERROR_LOG):
        return []
    return open(ERROR_LOG, encoding='utf-8', errors='replace').read().splitlines()


def board_error_log_lines():
    """The lines of the board's own error log (MyBB's error handler writes the errors it logs there, not to PHP's log)."""
    path = os.path.join(BOARD, 'error.log')
    if not os.path.exists(path):
        return []
    return open(path, encoding='utf-8', errors='replace').read().splitlines()


# ---- the blog showcase, through the ACP's JSON sync -------------------------------------------------------------------

def create_blog(acp):
    acp.get('/admin/index.php?module=myshowcase-json_sync&action=create&type=showcase')
    for action in ('preview', 'run'):
        acp.post('/admin/index.php?module=myshowcase-json_sync', {
            'action': action, 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json',
            'name': 'Blog', 'route_identifier': 'blog', 'custom_template_prefix': '',
        })
    ok(one("select count(*) from mybb_myshowcase_config where route_identifier='blog'") == '1', 'the blog showcase is created from JSON')
    ok(one(f"select count(*) from mybb_myshowcase_fields f, mybb_myshowcase_collections c where f.collection_id=c.collection_id and c.name='Blog'") == '9',
       'the blog collection has its nine fields')


# ---- the XML files the checks write ----------------------------------------------------------------------------------

def xml_escape(value):
    return html.escape(str(value), quote=False)


def xml_value(value):
    if isinstance(value, bool):
        return f'<value type="BOOL">{1 if value else 0}</value>'
    if isinstance(value, int):
        return f'<value type="INT">{value}</value>'
    return f'<value>{xml_escape(value)}</value>'


def xml_node(key, value):
    """One element of a PluginLibrary export: a scalar, or a nested array for a dict."""
    if isinstance(value, dict):
        inner = ''.join(xml_node(k, v) for k, v in value.items())
        return f'<element><key>{xml_escape(key)}</key><value><array>{inner}</array></value></element>'
    return f'<element><key>{xml_escape(key)}</key>{xml_value(value)}</element>'


def xml_file(entries, route='blog', collection='Blog', format_version=1, entry_count=None, header_extra=None, raw_entries=None):
    """An entries export written by hand, in the layout the export writes (design doc, section 1)."""
    count = len(entries) if entry_count is None else entry_count
    header = {
        'format': 'myshowcase-entries', 'format_version': format_version, 'myshowcase_version': '3.0.0',
        'generated': '2026-11-12T09:30:00+00:00', 'showcase': {'route_identifier': route, 'name': 'Blog'},
        'collection': {'name': collection}, 'entry_count': count,
    }
    header.update(header_extra or {})
    head = ''.join(xml_node(k, v) for k, v in header.items())
    items = []
    for index, entry in enumerate(entries):
        parts = []
        if 'subject' in entry:
            parts.append(xml_node('subject', entry['subject']))
        if 'message' in entry:
            parts.append(xml_node('message', entry['message']))
        parts.append(xml_node('fields', entry.get('fields', {})))
        items.append(f'<element><key type="INT">{index}</key><value><array>{"".join(parts)}</array></value></element>')
    body = ''.join(items) if raw_entries is None else raw_entries
    return ('<?xml version="1.0" encoding="UTF-8"?>\n<!-- MyShowcase entries export :: 2026-11-12T09:30:00+00:00 -->\n'
            f'<array><element><key>header</key><value><array>{head}</array></value></element>'
            f'<element><key>entries</key><value><array>{body}</array></value></element></array>\n'
            '<!-- End of MyShowcase export. -->\n')


def good_entry(i, category=None):
    return {
        'subject': f'Import entry {i} & more <b>x</b>',
        'message': f'Message {i}\nSecond line',
        'fields': {
            'category': category if category is not None else 1 + (i % 4),
            'summary': f'Summary {i}',
            'tags': f'tag{i}',
            'cover_image': f'https://example.com/{i}.png',
            'reading_time': 5,
            'series': 'Series',
            'series_part': 2,
            'meta_description': f'Meta {i}',
            'source_url': f'https://example.com/source/{i}',
        },
    }


def write_file(name, content):
    path = os.path.join(SCRATCH, name)
    if isinstance(content, bytes):
        with open(path, 'wb') as handle:
            handle.write(content)
    else:
        with open(path, 'w', encoding='utf-8') as handle:
            handle.write(content)
    return path


def import_file(client, path, action='import'):
    form = client.get(f'{SHOWCASE}/import')
    return client.upload(f'{SHOWCASE}/import', {'my_post_key': post_key(form['body']), 'action': action}, 'entries_file', path)


def import_named(client, file_name, content):
    """Posts a file whose upload name is file_name (it may contain characters a disk path cannot hold)."""
    form = client.get(f'{SHOWCASE}/import')
    boundary = '----msc' + secrets.token_hex(8)
    fields = {'my_post_key': post_key(form['body']), 'action': 'import'}
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in fields.items()]
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="entries_file"; filename="{file_name}"\r\n'
                 f'Content-Type: text/xml\r\n\r\n'.encode() + content.encode('utf-8') + b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    req = urllib.request.Request(client.url(f'{SHOWCASE}/import'), data=b''.join(parts), method='POST')
    req.add_header('Content-Type', f'multipart/form-data; boundary={boundary}')
    return client._do(req)


def dump(name, body):
    """Keeps the response of a check in the scratch directory, for reading when the check fails."""
    with open(os.path.join(SCRATCH, f'response-{name}.html'), 'w', encoding='utf-8', errors='replace') as handle:
        handle.write(body)


def report_of(body):
    if 'id="import-report"' not in body:
        return None
    segment = body[body.index('id="import-report"'):]
    if 'Completed:' in segment:
        status = 'completed'
    elif 'Validated:' in segment:
        status = 'validated'
    elif 'Failed:' in segment:
        status = 'failed'
    else:
        status = '?'
    return {'status': status, 'text': text(segment)}


def entry_rows(user_id):
    return db(
        'select subject, message, category, summary, tags, cover_image, reading_time, series, series_part, meta_description, source_url '
        f'from {data_table()} where user_id={int(user_id)} order by entry_id'
    )


# ---- the sections ----------------------------------------------------------------------------------------------------

def run():
    global USERS
    created = fixture('setup', os.path.join(SCRATCH, 'users.json'))
    USERS = json.load(open(os.path.join(SCRATCH, 'users.json')))
    ok('member1' in created.get('users', []), 'the test users and groups are created')

    section_defaults()
    section_round_trip()
    section_all_or_nothing()
    section_validator()
    section_scope()
    section_security()
    section_permissions()
    section_flood_and_limit()
    section_files()
    section_logs()


def section_defaults():
    print('--- defaults')
    if ENGINE == 'sqlite':
        sql = one("select sql from sqlite_master where name='mybb_myshowcase_permissions'")
        ok(bool(re.search(r'entries_can_import\s+\w+[^,]*?DEFAULT\s+0', sql or '', re.I | re.S)),
           'entries_can_import defaults to 0 in the permissions table (sqlite)')
    else:
        column_default = one("select column_default from information_schema.columns where table_schema=database() "
                             "and table_name='mybb_myshowcase_permissions' and column_name='entries_can_import'")
        ok(column_default == '0', f'entries_can_import defaults to 0 in the permissions table (mysql: {column_default!r})')
    ok(one("select import_export_flood_seconds from mybb_myshowcase_config where route_identifier='blog'") == '60',
       'the import flood check defaults to 60 seconds')


def section_round_trip():
    print('--- I2 round trip')
    member = logged_in('member1')
    uid = USERS['member1']['uid']

    for i in range(1, 4):
        form = member.get(f'{SHOWCASE}/create')
        m = re.search(r'name="post_hash"\s+value="([^"]+)"', form['body'])
        member.post(f'{SHOWCASE}/create', {
            'subject': f'Round {i} & <i>x</i>', 'message': f'Round body {i}',
            'category': option_id('News' if i == 1 else 'Tutorials') or '1',
            'summary': f'Round summary {i}', 'tags': 'alpha,beta', 'cover_image': f'https://example.com/r{i}.png',
            'reading_time': str(4 + i), 'series': 'Round', 'series_part': str(i), 'meta_description': f'Round meta {i}',
            'source_url': f'https://example.com/r/{i}', 'my_post_key': post_key(form['body']),
            'post_hash': m.group(1) if m else '', 'submit': 'Post',
        })
    ok(entry_count(uid) == 3, f'three entries are created through the form (found {entry_count(uid)})')

    export_form = member.get(f'{SHOWCASE}/export')
    member.post(f'{SHOWCASE}/export', {'my_post_key': post_key(export_form['body']), 'limit': '0', 'order_by': 'dateline',
                                      'order_dir': 'asc', 'submit': 'Export'})
    export_id = one(f'select export_id from mybb_myshowcase_exports where user_id={uid} order by export_id desc limit 1')
    ok(export_id != '', 'the export is stored')
    if export_id == '':
        return

    download = member.get(f'{SHOWCASE}/export/{export_id}/download')
    ok(download['status'] == 200 and b'myshowcase-entries' in download['raw'], 'the export downloads as an entries file')
    path = write_file('round-trip.xml', download['raw'])

    before = entry_rows(uid)
    tmp_before = count_php_tmp_files()
    report = report_of(import_file(member, path)['body'])
    tmp_after = count_php_tmp_files()

    ok(report is not None and report['status'] == 'completed', 'the import of the export completes')
    ok(entry_count(uid) == 6, f'the import adds the three entries again (found {entry_count(uid)})')
    after = entry_rows(uid)
    ok(len(after) == 6 and after[3:] == before, 'the imported entries carry the field values of the exported ones')
    ok(tmp_after <= tmp_before, 'the temporary upload file is gone after the import')
    ok(import_log_count() == 1, 'one import log row is written')

    # The flood check is off for the rest of the suite (its own check comes later); validate only writes nothing.
    fixture('showcase-setting', 'blog', 'import_export_flood_seconds', '0')
    report = report_of(import_file(member, path, action='validate')['body'])
    ok(report is not None and report['status'] == 'validated', 'validate only reports the file as valid')
    ok(entry_count(uid) == 6 and import_log_count() == 1, 'validate only writes nothing and logs nothing')


def section_all_or_nothing():
    print('--- I3 all or nothing')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    before, logs = entry_count(uid), import_log_count()

    valid = [good_entry(i) for i in range(1, 6)]
    bad = good_entry(6)
    bad['fields']['cover_image'] = 'javascript:alert(1)'

    for label, entries in (('last', valid + [bad]), ('first', [bad] + valid)):
        report = report_of(import_file(member, write_file(f'aon-{label}.xml', xml_file(entries)))['body'])
        ok(report is not None and report['status'] == 'failed', f'a file with the invalid entry {label} is refused')
        ok(entry_count(uid) == before, f'nothing is imported when the invalid entry is {label}')
        ok(import_log_count() == logs, f'no import log row when the invalid entry is {label}')

    report = report_of(import_file(member, write_file('aon-message.xml', xml_file([bad])))['body'])
    ok(report is not None and 'Entry 1' in report['text'] and 'Cover image' in report['text'],
       'the report names the entry and the field')

    # A row the database refuses and the validator lets through: a CHECK constraint (MySQL 8.0.16 or later) or a trigger
    # (SQLite) refuses a subject that starts with BOOM, and the import must roll back the entries written before it, leave
    # no import log row, and log the cause without showing it on the page (decision T6, section 5.3 and 5.4).
    before, logs = entry_count(uid), import_log_count()
    log_before = len(board_error_log_lines())
    fixture('db-check', 'blog', 'add')
    try:
        entries = [good_entry(13), dict(good_entry(15), subject='BOOM entry'), good_entry(14)]
        body = import_file(member, write_file('aon-write.xml', xml_file(entries)))['body']
        dump('aon-write', body)
        report = report_of(body)
        ok(report is not None and report['status'] == 'failed' and 'could not be written' in report['text'],
           f'the database refuses an entry the validator accepted ({ENGINE}): the report is the generic write failure')
        ok(entry_count(uid) == before, 'the entries written before the refused one are rolled back')
        ok(import_log_count() == logs, 'the import log row is rolled back with the entries')
        new_lines = board_error_log_lines()[log_before:]
        ok(any('MyShowcase import: the entries could not be written.' in line for line in new_lines),
           'the cause of the failed write is written to the board error log')
        ok(not any(marker in body for marker in ('SQLSTATE', 'INSERT INTO', 'import test refusal', BOARD or '\0')),
           'the failed write shows no SQL, no database message and no path on the page')
    finally:
        fixture('db-check', 'blog', 'drop')


def section_validator():
    print('--- I4 same validator as the form')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    cases = [
        ('cover image not http(s)', {'cover_image': 'ftp://example.com/a.png'}),
        ('reading time 0', {'reading_time': '0'}),
        ('reading time 1000', {'reading_time': '1000'}),
        ('series part not a number', {'series_part': 'x'}),
        ('summary above 300', {'summary': 'a' * 301}),
        ('meta description above 160', {'meta_description': 'b' * 161}),
        ('source not http(s)', {'source_url': 'javascript:void(0)'}),
    ]
    for label, change in cases:
        entry = good_entry(7)
        entry['fields'].update(change)
        before = entry_count(uid)
        report = report_of(import_file(member, write_file('validator.xml', xml_file([entry])))['body'])
        ok(report is not None and report['status'] == 'failed' and entry_count(uid) == before,
           f'the import refuses {label} and writes nothing')

    entry = good_entry(8)
    del entry['fields']['category']
    report = report_of(import_file(member, write_file('validator-category.xml', xml_file([entry])))['body'])
    ok(report is not None and report['status'] == 'failed' and 'Category' in report['text'], 'a missing required category is refused')

    entry = good_entry(9)
    entry['fields'] = {'category': 1}
    report = report_of(import_file(member, write_file('validator-blank.xml', xml_file([entry])))['body'])
    ok(report is not None and report['status'] == 'completed', 'optional fields left blank are accepted (reading time, series part)')

    entry = good_entry(10)
    entry['fields']['summary'] = '<script>alert(1)</script>'
    report = report_of(import_file(member, write_file('validator-script.xml', xml_file([entry])))['body'])
    ok(report is not None and report['status'] == 'completed', 'a script in a summary is imported as text')
    # The example file of the documentation imports as it is written there.
    doc = open(os.path.join(HERE, '..', '..', '..', 'Documentation', 'import.md'), encoding='utf-8').read()
    example = re.search(r'```xml\n(.*?)```', doc, re.S).group(1)
    report = report_of(import_file(member, write_file('doc-example.xml', example))['body'])
    ok(report is not None and report['status'] == 'completed', 'the example file of the documentation imports')

    public_id = one(f"select public_id from {data_table()} where subject='Import entry 10 & more <b>x</b>' order by entry_id desc limit 1")
    view = member.get(f'{SHOWCASE}/view/{public_id}')['body']
    ok('<script>alert(1)</script>' not in view, 'the stored script is not rendered as markup on the entry page')


def section_scope():
    print('--- I5 showcase and file checks')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    before = entry_count(uid)

    def refused(name, content, needle):
        body = import_file(member, write_file(name, content))['body']
        dump(name, body)
        ok(needle in text(body) and entry_count(uid) == before, f'{name}: refused with a readable message')

    refused('foreign.xml', xml_file([good_entry(1)], route='gallery'), 'exported from the showcase')
    # Decision T3: a collection name mismatch is a warning. The file imports, its fields are still checked, and the
    # report says so; the count the later refusals compare with is taken again after it.
    body = import_file(member, write_file('collection.xml', xml_file([good_entry(1)], collection='Gallery')))['body']
    dump('collection.xml', body)
    report = report_of(body)
    ok(report is not None and report['status'] == 'completed' and 'exported from the collection' in report['text']
       and entry_count(uid) == before + 1, 'a file of another collection imports, with its warning in the report (T3)')
    before = entry_count(uid)
    refused('unknown.xml', xml_file([{'fields': {'legacy_tag': 'x'}}]), 'is not a field of this showcase')
    refused('version.xml', xml_file([good_entry(1)], format_version=3), 'format version 3')
    refused('count.xml', xml_file([good_entry(1)], entry_count=3), 'says it has 3 entries')
    refused('zero.xml', xml_file([]), 'The file has no entries')
    refused('notxml.xml', 'hello, not XML', 'does not start with an XML document')
    refused('plain-root.xml', '<?xml version="1.0"?><root><entry/></root>', 'not an export of MyShowcase')
    refused('too-many.xml', xml_file([{'fields': {'category': 1}} for _ in range(5001)], entry_count=5001), 'At most 5000')

    body = import_file(member, write_file('wrong-extension.txt', xml_file([good_entry(1)])))['body']
    ok('.xml' in text(body) and entry_count(uid) == before, 'a file that is not .xml is refused')

    # The report escapes the file name and the subject of a refused entry. The subject has no category, so the validator
    # refuses it and the report prints it. (The payloads are the two of the design review.)
    # PHP strips everything up to the last slash of an upload name, so the file name is a slash-free payload: the
    # slash of </script> never reaches the report.
    body = import_named(member, '<script>alert(1)<script>.xml', xml_file([{'subject': '"><img src=x onerror=alert(1)>', 'message': 'm', 'fields': {}}]))['body']
    dump('escaping', body)
    report = report_of(body)
    ok(report is not None and report['status'] == 'failed' and '<script>alert(1)<script>' not in body
       and '<img src=x onerror=alert(1)>' not in body and '&lt;script&gt;alert(1)&lt;script&gt;' in body
       and '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;' in body and entry_count(uid) == before,
       'the report escapes the file name and the subject of a refused entry, and imports nothing')



def section_security():
    print('--- I6 security')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    before = entry_count(uid)

    xxe = ('<?xml version="1.0"?><!DOCTYPE array [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
           '<array><element><key>header</key><value>&xxe;</value></element></array>')
    body = import_file(member, write_file('xxe-file.xml', xxe))['body']
    ok('root:' not in body and 'DOCTYPE' in text(body) and entry_count(uid) == before,
       'an XXE payload to a local file is refused, with no file content in the response')

    xxe_http = xxe.replace('file:///etc/passwd', 'http://127.0.0.1:9/never')
    body = import_file(member, write_file('xxe-http.xml', xxe_http))['body']
    ok('DOCTYPE' in text(body) and entry_count(uid) == before, 'an XXE payload to a network address is refused')

    levels = ''.join(f'<!ENTITY lol{i} "&lol{i - 1};&lol{i - 1};&lol{i - 1};&lol{i - 1};&lol{i - 1};&lol{i - 1};&lol{i - 1};&lol{i - 1};&lol{i - 1};&lol{i - 1};">'
                     for i in range(1, 10))
    bomb = ('<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol">' + levels + ']>'
            '<array><element><key>header</key><value>&lol9;</value></element></array>')
    started = time.time()
    body = import_file(member, write_file('bomb.xml', bomb))['body']
    elapsed = time.time() - started
    ok('DOCTYPE' in text(body) and entry_count(uid) == before, 'an entity expansion bomb is refused')
    ok(elapsed < 10, f'the expansion bomb is refused in {elapsed:.2f} s (limit 10 s)')

    padding = '<!-- ' + 'x' * (4 * 1024 * 1024) + ' -->'
    body = import_file(member, write_file('oversized.xml', xml_file([good_entry(1)], raw_entries=padding)))['body']
    dump('oversized', body)
    ok('too large' in text(body).lower() and entry_count(uid) == before, 'a file above the cap is refused as too large, before parsing')

    guest = Front(CTX.url)
    body = guest.get(f'{SHOWCASE}/import')['body']
    ok('entries_file' not in body, 'a guest does not get the import form')
    guest.post(f'{SHOWCASE}/import', {'my_post_key': '0' * 32, 'action': 'import'})
    ok(entry_count(uid) == before, 'a guest cannot import')

    body = member.upload(f'{SHOWCASE}/import', {'my_post_key': '0' * 32, 'action': 'import'}, 'entries_file',
                         write_file('badkey.xml', xml_file([good_entry(1)])))['body']
    ok('Authorization code mismatch' in body or 'mismatch' in body.lower(), 'a request without a valid post key is refused')
    ok(entry_count(uid) == before, 'a refused post key writes nothing')

    leaks = [marker for marker in (BOARD, '/var/folders', 'msc-') if marker in body]
    ok(not leaks, 'error pages contain no path')

    exporter = logged_in('exporter')
    ok('entries_file' not in exporter.get(f'{SHOWCASE}/import')['body'], 'a member without EntriesCanImport gets no import form')


def section_permissions():
    print('--- I7 permissions and links')
    member = logged_in('member1')
    exporter = logged_in('exporter')
    main_member = member.get(f'{SHOWCASE}/')['body']
    main_exporter = exporter.get(f'{SHOWCASE}/')['body']
    ok('href="/blog/import"' in main_member, 'the import link is shown to a member with EntriesCanImport')
    dump('main-exporter', main_exporter)
    ok('href="/blog/import"' not in main_exporter, 'the import link is hidden from a member without it')
    ok('href="/blog/export"' in main_exporter, 'the export link is shown to a member who may export')
    ok(exporter.get(f'{SHOWCASE}/export')['status'] == 200, 'export still works for a member who may only export')


def section_flood_and_limit():
    print('--- flood and entry limit')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    path = write_file('flood.xml', xml_file([good_entry(21)]))

    first = report_of(import_file(member, path)['body'])
    ok(first is not None and first['status'] == 'completed', 'a first import completes')

    fixture('showcase-setting', 'blog', 'import_export_flood_seconds', '60')
    second = import_file(member, path)['body']
    ok('Please wait' in second, 'a second import within the flood time is refused')
    fixture('showcase-setting', 'blog', 'import_export_flood_seconds', '0')

    fixture('permission', 'Registered', 'entries_limit_post_amount', '1')
    member = logged_in('member1')
    before = entry_count(uid)
    report = report_of(import_file(member, write_file('limit.xml', xml_file([good_entry(31), good_entry(32)])))['body'])
    ok(report is not None and report['status'] == 'failed' and entry_count(uid) == before,
       'an import that would pass the entry limit is refused as a whole')
    fixture('permission', 'Registered', 'entries_limit_post_amount', '0')


def create_showcase(acp, route, name):
    acp.get('/admin/index.php?module=myshowcase-json_sync&action=create&type=showcase')
    for action in ('preview', 'run'):
        acp.post('/admin/index.php?module=myshowcase-json_sync', {
            'action': action, 'mode': 'create', 'type': 'showcase', 'file': f'showcases/{route}.json',
            'name': name, 'route_identifier': route, 'custom_template_prefix': '',
        })
    ok(one(f"select count(*) from mybb_myshowcase_config where route_identifier='{route}'") == '1', f'the {route} showcase is created from JSON')


def import_into(client, route, path):
    base = f'/showcase.php/{route}'
    form = client.get(f'{base}/import')
    return client.upload(f'{base}/import', {'my_post_key': post_key(form['body']), 'action': 'import'}, 'entries_file', path)


def gallery_entry(i, image):
    # The gallery showcase does not use a subject: an entry carries its title as a field.
    return {'fields': {'title': f'Picture {i}', 'image': image, 'caption': f'Caption {i}'}}


def downloads_entry(i, archive):
    # Select values are the option ids of the shipped downloads collection (Tools, Any, Freeware).
    return {'fields': {'archive': archive, 'category': 1, 'version': f'1.9.{i}', 'release_date': '2026-11-01',
                       'platform': 1, 'license': 1, 'sha256': '0' * 64}}


def sha256_of(path):
    with open(path, 'rb') as handle:
        return hashlib.sha256(handle.read()).hexdigest()


def fetch_bytes(url):
    with urllib.request.urlopen(url, timeout=60) as response:
        return response.read()


class Listener:
    """A server on 127.0.0.1 of this run, that records every request it gets (the SSRF checks must leave it empty)."""

    def __init__(self, path):
        self.path = path
        self.hits = []
        hits = self.hits

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                hits.append(self.path)
                self.send_response(200)
                self.send_header('Content-Type', 'image/png')
                self.send_header('Content-Length', '0')
                self.end_headers()

            def log_message(self, *args):
                pass

        self.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.port = self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def close(self):
        self.server.shutdown()
        self.server.server_close()


def section_files():
    print('--- F file fields by URL')
    fixture('files-setup', 'import-test-uploads')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    gid = int(one("select showcase_id from mybb_myshowcase_config where route_identifier='gallery'") or 0)
    uploads = one(f"select attachments_uploads_path from mybb_myshowcase_config where showcase_id={gid}")
    if not gid or not uploads:
        ok(False, 'the gallery showcase exists and has an uploads folder')
        return

    def gallery_rows():
        return int(one(f'select count(*) from mybb_myshowcase_data{gid} where user_id={uid}') or 0)

    def attachment_rows():
        return int(one(f"select count(*) from mybb_myshowcase_attachments where showcase_id={gid} and field_id is not null") or 0)

    # F1: entries whose image is a placehold.co URL are imported, and their files are attached through the form's pipeline.
    urls = [f'https://placehold.co/600x450.png?text=Gallery%20Entry%20{i}' for i in (1, 2)]
    before_rows, before_files = gallery_rows(), attachment_rows()
    path = write_file('gallery-urls.xml', xml_file([gallery_entry(i + 1, url) for i, url in enumerate(urls)],
                                                   route='gallery', collection='Gallery', format_version=2))
    body = import_into(member, 'gallery', path)['body']
    dump('gallery-urls', body)
    report = report_of(body)
    ok(report is not None and report['status'] == 'completed', 'a gallery file with two placehold.co images imports')
    ok(gallery_rows() == before_rows + 2 and attachment_rows() == before_files + 2,
       f'two gallery entries and two attached images are stored (entries {gallery_rows() - before_rows}, files {attachment_rows() - before_files})')
    stored = one(f"select group_concat(file_name, '|') from mybb_myshowcase_attachments where showcase_id={gid} and user_id={uid} and field_id is not null order by attachment_id desc limit 2") or ''
    stored_names = [name for name in stored.split('|') if name]
    bytes_by_url = [fetch_bytes(url) for url in urls]
    stored_hashes = [hashlib.sha256(open(os.path.join(uploads, name), 'rb').read()).hexdigest() for name in stored_names]
    ok(sorted(stored_hashes) == sorted(hashlib.sha256(b).hexdigest() for b in bytes_by_url),
       'the stored files are the bytes of the two URLs (SHA-256 of each)')
    thumbs = one(f"select count(*) from mybb_myshowcase_attachments where showcase_id={gid} and thumbnail_name is not null and thumbnail_name!=''")
    ok(thumbs == '2' or int(thumbs or 0) >= 2, f'the two images have thumbnails ({thumbs})')
    logged = one(f"select log_data from mybb_myshowcase_logs where showcase_id={gid} and type_id=3 order by log_id desc limit 1") or ''
    ok('"external_files":2' in logged, 'the import log row records the two files taken from URLs')

    # F1b (V3): the setting is stored after activation, and with it off a file with URLs is refused as a whole, with nothing
    # stored; with it on again the same file imports.
    ok(one("select value from mybb_settings where name='myshowcase_import_external_files'") == '1', 'the setting exists after activation, with value yes')
    fixture('external-setting', '0')
    time.sleep(3)  # the server's opcache revalidates inc/settings.php on its own clock
    before_rows, before_files = gallery_rows(), attachment_rows()
    body = import_into(member, 'gallery', write_file('gallery-urls-off.xml', xml_file([gallery_entry(7, urls[0])], route='gallery', collection='Gallery', format_version=2)))['body']
    dump('gallery-urls-off', body)
    report = report_of(body)
    ok(report is not None and report['status'] == 'failed' and 'switched off on this board' in report['text'] and gallery_rows() == before_rows and attachment_rows() == before_files,
       'with the setting off, a file with URLs is refused as a whole, and nothing is stored')
    fixture('external-setting', '1')
    time.sleep(3)

    # F2: the export writes each file as its public URL (format version 2); the URL opens with the same bytes; the export
    # imported back into the same board resolves its own files without a request to the attachment route (decision D52).
    export_form = member.get('/showcase.php/gallery/export')
    member.post('/showcase.php/gallery/export', {'my_post_key': post_key(export_form['body']), 'limit': '0', 'order_by': 'dateline',
                                                'order_dir': 'asc', 'submit': 'Export'})
    export_id = one(f'select export_id from mybb_myshowcase_exports where user_id={uid} and showcase_id={gid} order by export_id desc limit 1')
    ok(export_id != '', 'the gallery export is stored')
    if export_id == '':
        return
    download = member.get(f'/showcase.php/gallery/export/{export_id}/download')
    exported = download['raw'].decode('utf-8', errors='replace')
    ok('<value type="INT">2</value>' in exported and 'format_version' in exported, 'the export is format version 2')
    attach_urls = re.findall(r'https?://127\.0\.0\.1:\d+/(?:showcase\.php/)?gallery/attachment/[^<\s]+', exported)
    ok(len(attach_urls) >= 2 and all(CTX.url in u for u in attach_urls), f'the export holds the public URL of each image ({len(attach_urls)})')
    if attach_urls:
        # The board's routed URL is /gallery/attachment/...; this test server has no URL rewriting, so the same route is
        # requested through the front controller. A real server's rewrite rules map one to the other.
        first = attach_urls[0].replace(CTX.url, '').replace('/gallery/attachment/', '/showcase.php/gallery/attachment/', 1)
        fetched = member.get(first)['raw']
        ok(len(fetched) > 0 and hashlib.sha256(fetched).hexdigest() in set(
            hashlib.sha256(open(os.path.join(uploads, name), 'rb').read()).hexdigest() for name in stored_names),
           "the URL opens in the exporting user's session and gives the stored bytes")
    path = write_file('gallery-export.xml', download['raw'])
    before_rows, before_files = gallery_rows(), attachment_rows()
    server_log = os.path.join(SCRATCH, f'php_{ENGINE}.log')
    lines_before = len(open(server_log, encoding='utf-8', errors='replace').read().splitlines()) if os.path.exists(server_log) else 0
    body = import_into(member, 'gallery', path)['body']
    dump('gallery-roundtrip', body)
    report = report_of(body)
    lines_after = open(server_log, encoding='utf-8', errors='replace').read().splitlines()[lines_before:] if os.path.exists(server_log) else []
    ok(report is not None and report['status'] == 'completed', 'the export of the gallery imports back into the gallery')
    ok(gallery_rows() == before_rows + 2 and attachment_rows() == before_files + 2, 'the round trip adds two entries with two new files')
    ok(not any('/attachment/' in line for line in lines_after), 'the round trip makes no request to the attachment route (own files are copied)')

    # With the setting on again, the same file imports (after the round trip, so that the round trip counts its own file).
    body = import_into(member, 'gallery', write_file('gallery-urls-on.xml', xml_file([gallery_entry(8, urls[0])], route='gallery', collection='Gallery', format_version=2)))['body']
    report = report_of(body)
    ok(report is not None and report['status'] == 'completed', 'with the setting on again, the same file imports')

    # F3 (V7): addresses that must be refused, each without a single request to the local listener of this run.
    listener = Listener(os.path.join(SCRATCH, 'listener.log'))
    try:
        refused_urls = [
            f'http://127.0.0.1:{listener.port}/x.png', f'http://localhost:{listener.port}/x.png', f'http://[::1]:{listener.port}/x.png',
            'http://169.254.169.254/latest/meta-data/', 'http://2130706433/x.png', 'http://0x7f000001/x.png', 'http://0177.0.0.1/x.png',
            f'http://placehold.co@127.0.0.1:{listener.port}/x.png', 'file:///etc/passwd', 'ftp://placehold.co/x.png',
            'gopher://placehold.co/', 'data:image/png;base64,AAAA', 'javascript:alert(1)', 'https://placehold.co:8443/x.png',
            'http://placehold.co:22/', 'https://placehold.co/' + 'a' * 3000, 'https://placehold.co/a b.png',
            'https://placehold.co/a\r\nX-Injected: 1.png', 'https://placehold.co/a\x00.png',
        ]
        listener_hits = len(listener.hits)
        for index, url in enumerate(refused_urls):
            before_rows, before_files = gallery_rows(), attachment_rows()
            path = write_file(f'ssrf-{index}.xml', xml_file([gallery_entry(90 + index, url)], route='gallery', collection='Gallery', format_version=2))
            body = import_into(member, 'gallery', path)['body']
            dump(f'ssrf-{index}', body)
            report = report_of(body)
            after_rows, after_files = gallery_rows(), attachment_rows()
            # A NUL byte makes the file binary: the upload's text check refuses it before any entry is read (no report).
            refused_ok = (report is not None and report['status'] == 'failed') or (report is None and 'not an XML text file' in text(body))
            ok(refused_ok and after_rows == before_rows and after_files == before_files,
               f'refused, nothing stored: index {index} (rows {before_rows}->{after_rows}, files {before_files}->{after_files}, status {report and report["status"]})')
        ok(len(listener.hits) == listener_hits, f'the local listener saw no request ({len(listener.hits) - listener_hits} requests)')
    finally:
        listener.close()

    # F5: five good entries and one URL that the server does not serve: nothing is imported, nothing is left on disk.
    before_rows, before_files, before_logs = gallery_rows(), attachment_rows(), import_log_count()
    files_before = set(os.listdir(uploads))
    temp_before = set(glob.glob(os.path.join(tempfile.gettempdir(), 'myshowcase-import-*')))
    entries = [gallery_entry(200 + i, f'https://placehold.co/600x450.png?text=Good%20{i}') for i in range(5)]
    entries.append(gallery_entry(299, 'https://github.com/mybb/mybb/archive/refs/heads/this-branch-does-not-exist.zip'))
    path = write_file('gallery-bad-last.xml', xml_file(entries, route='gallery', collection='Gallery', format_version=2))
    body = import_into(member, 'gallery', path)['body']
    dump('gallery-bad-last', body)
    report = report_of(body)
    ok(report is not None and report['status'] == 'failed' and 'could not be imported' in report['text'], 'a file with one 404 URL fails as a whole')
    ok(gallery_rows() == before_rows and attachment_rows() == before_files and import_log_count() == before_logs, 'nothing is stored, no attachment row, no log row')
    ok(set(os.listdir(uploads)) == files_before, 'no file is left in the uploads folder')
    ok(set(glob.glob(os.path.join(tempfile.gettempdir(), 'myshowcase-import-*'))) == temp_before, 'no temporary folder of the import is left')

    # F6 (V6): the downloads showcase takes a ZIP of GitHub (a redirect to codeload.github.com, 50 MB field limit, the ZIP
    # type). The size and the time are recorded; the stored file is the bytes of the archive.
    did = int(one("select showcase_id from mybb_myshowcase_config where route_identifier='downloads'") or 0)
    if did:
        duploads = one(f"select attachments_uploads_path from mybb_myshowcase_config where showcase_id={did}")
        zip_url = 'https://github.com/mybb/mybb/archive/refs/heads/dev-1.9.zip'
        dbefore = int(one(f'select count(*) from mybb_myshowcase_data{did} where user_id={uid}') or 0)
        started = time.time()
        path = write_file('downloads-zip.xml', xml_file([downloads_entry(1, zip_url)], route='downloads', collection='Downloads', format_version=2))
        body = import_into(member, 'downloads', path)['body']
        dump('downloads-zip', body)
        elapsed = time.time() - started
        report = report_of(body)
        ok(report is not None and report['status'] == 'completed', f'a downloads file with the GitHub ZIP imports ({elapsed:.1f} s)')
        ok(int(one(f'select count(*) from mybb_myshowcase_data{did} where user_id={uid}') or 0) == dbefore + 1, 'the GitHub ZIP entry is stored')
        zipped = one(f"select file_name from mybb_myshowcase_attachments where showcase_id={did} and user_id={uid} and field_id is not null order by attachment_id desc limit 1") or ''
        if zipped:
            fetched_zip = fetch_bytes(zip_url)
            ok(sha256_of(os.path.join(duploads, zipped)) == hashlib.sha256(fetched_zip).hexdigest(),
               f'the stored ZIP is the archive of GitHub ({len(fetched_zip)} bytes)')
        # A PNG in the downloads field: the board allows only ZIP there, so the file is refused by its type.
        dbefore = int(one(f'select count(*) from mybb_myshowcase_data{did} where user_id={uid}') or 0)
        path = write_file('downloads-png.xml', xml_file([downloads_entry(2, 'https://placehold.co/600x450.png?text=Not%20a%20zip')], route='downloads', collection='Downloads', format_version=2))
        body = import_into(member, 'downloads', path)['body']
        report = report_of(body)
        ok(report is not None and report['status'] == 'failed' and 'not an allowed type' in report['text'] and
           int(one(f'select count(*) from mybb_myshowcase_data{did} where user_id={uid}') or 0) == dbefore,
           'a PNG in the downloads field is refused by its type, and nothing is stored')


def section_logs():
    print('--- I8 PHP log')
    relevant = [l for l in error_log_lines() if re.search(r'Warning|Deprecated|Notice|Fatal', l) and re.search(r'myshowcase', l, re.I)]
    ok(not relevant, f'no PHP warning, notice, deprecation or fatal error from the plugin in the log ({len(relevant)} found)')
    for line in relevant[:20]:
        print('   log:', line[:240])
