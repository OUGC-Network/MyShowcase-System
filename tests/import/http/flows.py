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


def php_route(url):
    """The test server has no URL rewriting: a link of the board (plain or friendly) is requested through showcase.php."""
    path = url.replace(CTX.url, '', 1).replace('/showcase.php', '', 1)
    return '/showcase.php' + path


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
    """One element of a PluginLibrary export: a scalar, a nested array for a dict, or an indexed array for a list (the
    ordered value_ids of a multiple-choice field, decision D182)."""
    if isinstance(value, dict):
        inner = ''.join(xml_node(k, v) for k, v in value.items())
        return f'<element><key>{xml_escape(key)}</key><value><array>{inner}</array></value></element>'
    if isinstance(value, list):
        inner = ''.join(f'<element><key type="INT">{index}</key>{xml_value(item)}</element>' for index, item in enumerate(value))
        return f'<element><key>{xml_escape(key)}</key><value><array>{inner}</array></value></element>'
    return f'<element><key>{xml_escape(key)}</key>{xml_value(value)}</element>'


def xml_file(entries, route='blog', collection='Blog', format_version=3, entry_count=None, header_extra=None, raw_entries=None):
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


# The GitHub ZIP checks (section F6 and the round trip). A reason of the import report that comes from the network (the
# server could not be reached, the secure connection could not be verified, the file took too long to download) is
# named NETWORK. The last one is also the reason of the import's own time budget, so the elapsed time is printed too.
# Any other reason is a rejection by the plugin and is named PLUGIN. Both stay failures.
NETWORK_REASONS = (
    'the server could not be reached',
    'the secure connection could not be verified',
    'the file took too long to download',
)


def report_kind(body):
    # NETWORK when the report gives a network reason, PLUGIN for any other rejection, None when the import completed.
    report = report_of(body)
    if report is not None and report['status'] == 'completed':
        return None
    report_text = report['text'] if report is not None else 'no import report in the response'
    return 'NETWORK' if any(reason in report_text for reason in NETWORK_REASONS) else 'PLUGIN'


def import_check(label, body, elapsed, archive_url=None):
    # A completed import passes. A failure is printed with its label, the elapsed time and the full report text, and
    # stays a failure. The archive size is printed only for a GitHub ZIP.
    kind = report_kind(body)
    if kind is None:
        ok(True, f'{label} ({elapsed:.1f} s)')
        return
    report = report_of(body)
    report_text = report['text'] if report is not None else 'no import report in the response'
    size = ''
    if archive_url:
        try:
            size = f'; archive {len(fetch_bytes(archive_url))} bytes'
        except OSError as error:
            size = f'; archive unavailable ({type(error).__name__})'
    ok(False, f'{label} [{kind}] after {elapsed:.1f} s{size}; report: {report_text}')


def zip_check(label, body, elapsed, zip_url):
    import_check(label, body, elapsed, zip_url)


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
    section_option_tokens()
    section_files()
    section_option_numbering()
    section_option_portability()
    section_downloads_round_trip()
    section_unknown_option_value()
    section_previews()
    section_four_byte_subject()
    section_seeds()
    section_seed_round_trip(logged_in('member1'), USERS['member1']['uid'])
    section_logs()


def section_defaults():
    print('--- defaults')
    if ENGINE == 'sqlite':
        sql = one("select sql from sqlite_master where name='mybb_myshowcase_permissions'")
        ok(bool(re.search(r'entries_can_import\s+\w+[^,]*?DEFAULT\s+0', sql or '', re.I | re.S)),
           'entries_can_import defaults to 0 in the permissions table (sqlite)')
    else:
        # the schema of the database: database() on MySQL, current_schema() on PostgreSQL
        schema = 'current_schema()' if ENGINE == 'pgsql' else 'database()'
        column_default = one(f"select column_default from information_schema.columns where table_schema={schema} "
                             "and table_name='mybb_myshowcase_permissions' and column_name='entries_can_import'")
        ok(column_default == '0', f'entries_can_import defaults to 0 in the permissions table ({ENGINE}: {column_default!r})')
    ok(one("select import_export_flood_seconds from mybb_myshowcase_config where route_identifier='blog'") == '60',
       'the import flood check defaults to 60 seconds')


def section_round_trip():
    print('--- I2 round trip')
    member = logged_in('member1')
    uid = USERS['member1']['uid']

    for i in range(1, 4):
        form = member.get(f'{SHOWCASE}/create')
        m = re.search(r'name="post_hash"\s+value="([^"]+)"', form['body'])
        posted = member.post(f'{SHOWCASE}/create', {
            'subject': f'Round {i} & <i>x</i>', 'message': f'Round body {i}',
            'category': option_id('News' if i == 1 else 'Tutorials') or '1',
            'summary': f'Round summary {i}', 'tags': 'alpha,beta',
            'reading_time': str(4 + i), 'series': 'Round', 'series_part': str(i), 'meta_description': f'Round meta {i}',
            'source_url': f'https://example.com/r/{i}', 'my_post_key': post_key(form['body']),
            'post_hash': m.group(1) if m else '', 'submit': 'Post',
        })
    dump('blog-round-form', posted['body'])
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
    bad['fields']['category'] = 'Not a category'

    for label, entries in (('last', valid + [bad]), ('first', [bad] + valid)):
        report = report_of(import_file(member, write_file(f'aon-{label}.xml', xml_file(entries)))['body'])
        ok(report is not None and report['status'] == 'failed', f'a file with the invalid entry {label} is refused')
        ok(entry_count(uid) == before, f'nothing is imported when the invalid entry is {label}')
        ok(import_log_count() == logs, f'no import log row when the invalid entry is {label}')

    report = report_of(import_file(member, write_file('aon-message.xml', xml_file([bad])))['body'])
    ok(report is not None and 'Entry 1' in report['text'] and 'Category' in report['text'],
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
    example = re.search(r'### Example.*?```xml\n(.*?)```', doc, re.S).group(1)
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
    refused('version.xml', xml_file([good_entry(1)], format_version=4), 'format version 4')
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
    # the link of a route is in the form of the showcase's setting: the path or friendly form, or the query string form (D268)
    ok(re.search(r'href="[^"]*(?:/blog/import"|route_identifier=blog&(?:amp;)?route=import")', main_member) is not None, 'the import link is shown to a member with EntriesCanImport')
    dump('main-exporter', main_exporter)
    ok(re.search(r'href="[^"]*(?:/blog/import"|route_identifier=blog&(?:amp;)?route=import")', main_exporter) is None, 'the import link is hidden from a member without it')
    ok(re.search(r'href="[^"]*(?:/blog/export"|route_identifier=blog&(?:amp;)?route=export")', main_exporter) is not None, 'the export link is shown to a member who may export')
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
    # The gallery showcase takes its subject from its title (decision D204): the entry's subject is the title.
    return {'subject': f'Picture {i}', 'fields': {'title': f'Picture {i}', 'image': image, 'caption': f'Caption {i}'}}


def downloads_option(field_key, label):
    """The value id of an option of a downloads field, by its label, on this board (decision D262): option ids are not fixed,
    so a hard-coded id goes stale. An empty string when the board has no such option (the caller then fails, it does not guess)."""
    return one(
        'select d.value_id from mybb_myshowcase_field_data d, mybb_myshowcase_fields f '
        f"where d.field_id=f.field_id and f.field_key='{field_key}' and d.value='{label}' "
        "and f.collection_id=(select collection_id from mybb_myshowcase_config where route_identifier='downloads')"
    )


def downloads_entry(i, archive):
    # Select values are looked up by label: category Tools, platform Any, license Freeware of the shipped downloads collection.
    # The showcase requires a subject (entries_enable_subject), so every entry has one.
    ids = {key: downloads_option(key, label) for key, label in (('category', 'Tools'), ('platform', 'Any'), ('license', 'Freeware'))}
    missing = [key for key, value in ids.items() if value in (None, '')]
    if missing:
        raise RuntimeError(f'the downloads collection has no option for {", ".join(missing)} on this board')
    return {'subject': f'Download {i}', 'fields': {'archive': archive, 'category': ids['category'], 'version': f'1.9.{i}',
                                                  'release_date': '2026-11-01', 'platform': ids['platform'], 'license': ids['license'],
                                                  'sha256': '0' * 64}}


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


def section_option_tokens():
    """Decision D128: an option field is exported and imported as the option's value_id (format 3); a format 2 file with an
    option value is refused as a whole; an unknown token fails its entry. The stored id is the option's field_data_id."""
    print('--- option tokens (decision D128)')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    table = data_table()
    before = int(one(f'select count(*) from {table} where user_id={uid}') or 0)
    category_id = one(
        "select field_data_id from mybb_myshowcase_field_data where value_id='1' and field_id=("
        "select field_id from mybb_myshowcase_fields where field_key='category' and collection_id=("
        "select collection_id from mybb_myshowcase_collections where name='Blog'))"
    )
    unknown_entry = good_entry(40, category='9')

    body = import_into(member, 'blog', write_file('token-v2.xml', xml_file([good_entry(41, category='1')], format_version=2)))['body']
    ok(report_of(body) is not None and report_of(body)['status'] == 'failed' and 'exported before the option token changed' in text(body),
       'a format 2 file with an option value is refused with the message to export again')
    ok(int(one(f'select count(*) from {table} where user_id={uid}') or 0) == before, 'the refused format 2 file stores nothing')

    body = import_into(member, 'blog', write_file('token-unknown.xml', xml_file([unknown_entry])))['body']
    ok(report_of(body) is not None and report_of(body)['status'] == 'failed' and 'is not an option of this field' in text(body),
       'an unknown option token fails the import with the report line')
    ok(int(one(f'select count(*) from {table} where user_id={uid}') or 0) == before, 'the unknown token stores nothing')

    body = import_into(member, 'blog', write_file('token-valid.xml', xml_file([good_entry(42, category='1')])))['body']
    ok(report_of(body) is not None and report_of(body)['status'] == 'completed', 'a format 3 file with the value_id of an option imports')
    stored = one(f"select category from {table} where user_id={uid} order by entry_id desc limit 1")
    ok(category_id != '' and str(stored) == str(category_id),
       f'the stored option is the field_data_id of the option with value_id 1 (stored {stored}, field_data_id {category_id or "none"})')


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

    # F0 (decision D51): the setting is stored with the value no on a board that has not switched it. The checks below that
    # import file URLs switch it on first through the fixture (the server's opcache revalidates the settings on its own clock).
    ok(one("select value from mybb_settings where name='myshowcase_import_external_files'") == '0',
       'a fresh board stores the setting myshowcase_import_external_files with the value no')
    fixture('external-setting', '1')
    time.sleep(3)

    # F1: entries whose image is a placehold.co URL are imported, and their files are attached through the form's pipeline.
    urls = [f'https://placehold.co/600x450.png?text=Gallery%20Entry%20{i}' for i in (1, 2)]
    before_rows, before_files = gallery_rows(), attachment_rows()
    path = write_file('gallery-urls.xml', xml_file([gallery_entry(i + 1, url) for i, url in enumerate(urls)],
                                                   route='gallery', collection='Gallery', format_version=2))
    started = time.time()
    body = import_into(member, 'gallery', path)['body']
    dump('gallery-urls', body)
    report = report_of(body)
    import_check('a gallery file with two placehold.co images imports', body, time.time() - started)
    ok(gallery_rows() == before_rows + 2 and attachment_rows() == before_files + 2,
       f'two gallery entries and two attached images are stored (entries {gallery_rows() - before_rows}, files {attachment_rows() - before_files})')
    # The two newest files of the uploader, one row each (GROUP_CONCAT's separator syntax differs between the engines).
    stored_names = [row[0] for row in db(f"select file_name from mybb_myshowcase_attachments where showcase_id={gid} and user_id={uid} and field_id is not null order by attachment_id desc limit 2")]
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
        not_run('the rest of section F (the export checks, F4, F5 and F6 with the GitHub ZIP): the gallery export is missing')
        return
    download = member.get(f'/showcase.php/gallery/export/{export_id}/download')
    exported = download['raw'].decode('utf-8', errors='replace')
    ok('<value type="INT">3</value>' in exported and 'format_version' in exported, 'the export is format version 3')
    # the file URLs of the export: the path or friendly form, or the query string form of the showcase's setting (D268, D277)
    attach_urls = re.findall(r'https?://127\.0\.0\.1:\d+/(?:showcase\.php/)?gallery/attachment/[^<\s]+'
                             r'|https?://127\.0\.0\.1:\d+/showcase\.php\?route_identifier=gallery&amp;route=attachment/[^<\s]+', exported)
    attach_urls = [u.replace('&amp;', '&') for u in attach_urls]
    ok(len(attach_urls) >= 2 and all(CTX.url in u for u in attach_urls), f'the export holds the public URL of each image ({len(attach_urls)})')
    if attach_urls:
        # The board's routed URL is /gallery/attachment/...; this test server has no URL rewriting, so the same route is
        # requested through the front controller. A real server's rewrite rules map one to the other.
        first = php_route(attach_urls[0])
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

    # F2b (V4, V5). A user who may not download a file gets no URL for it in an export (the exporter is not the owner of the
    # files and has no download permission). A user who may not download an own-board file cannot import it (member2 is not
    # the owner). A look-alike address of this board is an external address: it is refused as a private address.
    exporter = logged_in('exporter')
    export_form = exporter.get('/showcase.php/gallery/export')
    exporter.post('/showcase.php/gallery/export', {'my_post_key': post_key(export_form['body']), 'limit': '0', 'order_by': 'dateline',
                                                  'order_dir': 'asc', 'submit': 'Export'})
    exporter_id = one(f"select export_id from mybb_myshowcase_exports where user_id={USERS['exporter']['uid']} and showcase_id={gid} order by export_id desc limit 1")
    if exporter_id:
        no_download = exporter.get(f'/showcase.php/gallery/export/{exporter_id}/download')['raw'].decode('utf-8', errors='replace')
        ok('/attachment/' not in no_download and 'gallery' in no_download,
           'a user without the download permission gets no URL for the file fields of an export')
    else:
        ok(False, 'the exporter can export the gallery')

    member2 = logged_in('member2')
    reg_title = one('select title from mybb_usergroups where gid=2')
    fixture('permission', reg_title, 'attachments_can_download', '0')
    before_rows, before_files = gallery_rows(), attachment_rows()
    body = import_into(member2, 'gallery', path)['body']
    report = report_of(body)
    ok(report is not None and report['status'] == 'failed' and 'you may not download' in report['text'] and gallery_rows() == before_rows and attachment_rows() == before_files,
       'a user who may not download an own-board file cannot import it, and nothing is stored')
    fixture('permission', reg_title, 'attachments_can_download', '1')
    time.sleep(3)

    listener_look = Listener(os.path.join(SCRATCH, 'listener-lookalike.log'))
    try:
        look = 'http://localhost/gallery/attachment/AAAA/600x450.png'
        before_rows, before_files = gallery_rows(), attachment_rows()
        body = import_into(member, 'gallery', write_file('gallery-lookalike.xml', xml_file([gallery_entry(9, look)], route='gallery', collection='Gallery', format_version=2)))['body']
        report = report_of(body)
        ok(report is not None and report['status'] == 'failed' and 'not a public one' in report['text'] and gallery_rows() == before_rows and not listener_look.hits,
           'a look-alike of the board address goes through the fetcher and is refused as a private address')
    finally:
        listener_look.close()

    # F2a (decisions D57 and D58, V2 and V3). One rule set for the route and the import. With the setting off, the board's own
    # file URL is refused as a whole too. With it on, member2 (a member who may import and download, not the owner) gets the
    # same answer from the route and from the import: the file is read while its entry is visible, and both refuse it when the
    # entry is awaiting approval or soft-deleted. The entry's status is changed in the database for this check and restored.
    if attach_urls:
        own_url = attach_urls[0]
        route_path = php_route(own_url)
        # The entry of the file the URL names (found by its public id, not assumed to be the newest one).
        own_public_id = own_url.split('/gallery/attachment/', 1)[1].split('/', 1)[0]
        own_entry = one(f"select entry_id from mybb_myshowcase_attachments where showcase_id={gid} and public_id='{own_public_id}'")
        ok(own_entry != '' and own_entry != '0', 'the URL of the export names a file with an entry of the gallery')

        fixture('external-setting', '0')
        time.sleep(3)
        before_rows, before_files = gallery_rows(), attachment_rows()
        body = import_into(member2, 'gallery', write_file('gallery-own-off.xml', xml_file([gallery_entry(19, own_url)], route='gallery', collection='Gallery', format_version=2)))['body']
        report = report_of(body)
        ok(report is not None and report['status'] == 'failed' and 'switched off on this board' in report['text'] and gallery_rows() == before_rows and attachment_rows() == before_files,
           'with the setting off, the board\'s own file URL is refused as a whole, and nothing is stored')
        fixture('external-setting', '1')
        time.sleep(3)

        for status, label, want in ((1, 'visible', True), (0, 'awaiting approval', False), (2, 'soft-deleted', False)):
            CTX.execute(f"update mybb_myshowcase_data{gid} set status={status} where entry_id={own_entry}")
            route_status = member2.get(route_path)['status']
            before_rows, before_files = gallery_rows(), attachment_rows()
            body = import_into(member2, 'gallery', write_file(f'gallery-parity-{status}.xml', xml_file([gallery_entry(20 + status, own_url)], route='gallery', collection='Gallery', format_version=2)))['body']
            report = report_of(body)
            imported = report is not None and report['status'] == 'completed'
            nothing_stored = gallery_rows() == before_rows and attachment_rows() == before_files
            ok((route_status == 200) == want and imported == want and (imported or nothing_stored),
               f'the route and the import agree for an entry that is {label} (route {route_status}, import {"completed" if imported else "refused"})')
        CTX.execute(f"update mybb_myshowcase_data{gid} set status=1 where entry_id={own_entry}")

    # F2c (V8): more than 25 files are refused before any download.
    many = [gallery_entry(300 + i, f'https://placehold.co/600x450.png?text=Many%20{i}') for i in range(26)]
    before_rows, before_files = gallery_rows(), attachment_rows()
    body = import_into(member, 'gallery', write_file('gallery-many.xml', xml_file(many, route='gallery', collection='Gallery', format_version=2)))['body']
    report = report_of(body)
    ok(report is not None and report['status'] == 'failed' and 'At most 25 files' in report['text'] and gallery_rows() == before_rows,
       'a file with 26 file URLs is refused before any download')

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

    # F4 (5.3, 5.9): a database refusal after a file was stored. The first entry's image is stored (a file on disk, an
    # attachment row); the second entry's title is refused by the database. The whole import is rolled back: the entries, the
    # attachment rows, the log row and the stored file are all gone.
    before_rows, before_files, before_logs = gallery_rows(), attachment_rows(), import_log_count()
    files_before = set(os.listdir(uploads))
    fixture('title-check', 'add')
    try:
        rows = [gallery_entry(400, 'https://placehold.co/600x450.png?text=Stored%20First'),
                {'fields': {'title': 'BOOM second', 'image': 'https://placehold.co/600x450.png?text=Refused%20Second', 'caption': 'x'}}]
        body = import_into(member, 'gallery', write_file('gallery-rollback.xml', xml_file(rows, route='gallery', collection='Gallery', format_version=2)))['body']
        dump('gallery-rollback', body)
        report = report_of(body)
        ok(report is not None and report['status'] == 'failed' and 'could not be written' in report['text'],
           'a database refusal after a stored file fails the import with the generic report'
           + ('' if report_kind(body) != 'NETWORK' else ' [NETWORK: a file of the import stalled, so the refusal did not happen]'))
        ok(gallery_rows() == before_rows and attachment_rows() == before_files and import_log_count() == before_logs,
           'the entries, the attachment rows and the log row of the failed import are rolled back')
        ok(set(os.listdir(uploads)) == files_before, 'the file stored before the refusal is removed from the uploads folder')
    finally:
        fixture('title-check', 'drop')

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
        path = write_file('downloads-zip.xml', xml_file([downloads_entry(1, zip_url)], route='downloads', collection='Downloads', format_version=3))
        body = import_into(member, 'downloads', path)['body']
        dump('downloads-zip', body)
        elapsed = time.time() - started
        report = report_of(body)
        zip_check('a downloads file with the GitHub ZIP imports', body, elapsed, zip_url)
        ok(int(one(f'select count(*) from mybb_myshowcase_data{did} where user_id={uid}') or 0) == dbefore + 1, 'the GitHub ZIP entry is stored')
        zipped = one(f"select file_name from mybb_myshowcase_attachments where showcase_id={did} and user_id={uid} and field_id is not null order by attachment_id desc limit 1") or ''
        if zipped:
            fetched_zip = fetch_bytes(zip_url)
            ok(sha256_of(os.path.join(duploads, zipped)) == hashlib.sha256(fetched_zip).hexdigest(),
               f'the stored ZIP is the archive of GitHub ({len(fetched_zip)} bytes)')
        # A PNG in the downloads field: the board allows only ZIP there, so the file is refused by its type.
        dbefore = int(one(f'select count(*) from mybb_myshowcase_data{did} where user_id={uid}') or 0)
        path = write_file('downloads-png.xml', xml_file([downloads_entry(2, 'https://placehold.co/600x450.png?text=Not%20a%20zip')], route='downloads', collection='Downloads', format_version=3))
        body = import_into(member, 'downloads', path)['body']
        dump('downloads-png', body)
        report = report_of(body)
        ok(report is not None and report['status'] == 'failed' and 'not an allowed type' in report['text'] and
           int(one(f'select count(*) from mybb_myshowcase_data{did} where user_id={uid}') or 0) == dbefore,
           'a PNG in the downloads field is refused by its type, and nothing is stored')

    # C (decision D204, closes D56): the shipped gallery showcase enables its subject, and an entry that carries one imports with
    # it and shows it on its page (the title of the gallery is its subject). Before D204 the showcase had no subject.
    ok(one(f"select entries_enable_subject from mybb_myshowcase_config where showcase_id={gid}") == '1',
       'the shipped gallery showcase enables its subject (decision D204)')
    subject_entry = gallery_entry(3, urls[0])
    subject_entry['subject'] = 'Picture subject three'
    body = import_into(member, 'gallery', write_file('gallery-subject.xml', xml_file([subject_entry], route='gallery', collection='Gallery', format_version=2)))['body']
    dump('gallery-subject', body)
    stored = db(f"select public_id, entry_slug_custom, subject from mybb_myshowcase_data{gid} where user_id={uid} and title='Picture 3' order by entry_id desc limit 1")
    page_ok = False
    if stored:
        page = member.get(f'/showcase.php/gallery/view/{stored[0][0]}/{stored[0][1]}')
        page_ok = page['status'] == 200 and 'Picture subject three' in page['body']
    ok(report_of(body) is not None and report_of(body)['status'] == 'completed' and bool(stored) and stored[0][2] == 'Picture subject three' and page_ok,
       f'a gallery entry with a subject imports with it, and its page shows the subject (stored subject {stored[0][2] if stored else None!r})')


def section_option_numbering():
    """Decision D128, the option token with differing numberings: the blog's category options are copied, so each
    field_data_id differs from its value_id; the export holds the value_id; the import stores the field_data_id of the option
    with that value_id and the entry page shows its label. An unknown token, the field_data_id of an option used as a token,
    a token of another field's option and a blank required value are refused. The shipped downloads entries hold the
    field_data_id of the value 1 option of each of their three fields."""
    print('--- option numbering: field_data_id against value_id (decision D128)')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    table = data_table()
    field = f"(select field_id from mybb_myshowcase_fields where field_key='category' and collection_id={cid()})"

    # copy the options: the copies get new field_data_ids, above their value ids (the same value ids and labels)
    originals = db(f"select value_id, value, allowed_groups_fill from mybb_myshowcase_field_data where field_id={field} "
                   "order by field_data_id")
    CTX.execute(f"delete from mybb_myshowcase_field_data where field_id={field}")
    for value_id, label, groups in originals:
        CTX.execute('insert into mybb_myshowcase_field_data (collection_id, field_id, field_key, value_id, value, '
                    f"allowed_groups_fill) select collection_id, field_id, field_key, '{value_id}', '{label}', '{groups}' "
                    f"from mybb_myshowcase_fields where field_id={field}")
    numbering = db(f"select value_id, field_data_id from mybb_myshowcase_field_data where field_id={field} order by value_id")
    ok(len(numbering) == len(originals) and all(str(v) != str(i) for v, i in numbering),
       f'the category options have numberings that differ: {numbering}')
    option = {str(v): str(i) for v, i in numbering}
    label_of = {str(v): str(l) for v, l, _ in originals}
    CTX.execute('update mybb_myshowcase_config set import_export_flood_seconds=0 where route_identifier=\'blog\'')

    body = import_into(member, 'blog', write_file('numbering-valid.xml', xml_file([good_entry(60, category='2')])))['body']
    ok(report_of(body) is not None and report_of(body)['status'] == 'completed', 'a format 3 file with value_id 2 imports')
    stored = one(f"select category from {table} where user_id={uid} order by entry_id desc limit 1")
    ok(str(stored) == option['2'], f'the stored option is the field_data_id of the option with value_id 2 (stored {stored}, '
                                   f"field_data_id {option['2']}, value_id 2)")
    pid, slug = db(f"select public_id, entry_slug_custom from {table} where user_id={uid} order by entry_id desc limit 1")[0]
    page = member.get(f'{SHOWCASE}/view/{pid}/{slug}')['body']
    ok(page_has_label(page, label_of['2']), f"the entry page shows the option's label {label_of['2']!r}")

    # the export of the member's entries holds value ids, never field_data_ids
    export_form = member.get(f'{SHOWCASE}/export')
    member.post(f'{SHOWCASE}/export', {'my_post_key': post_key(export_form['body']), 'limit': '0', 'order_by': 'dateline',
                                      'order_dir': 'asc', 'submit': 'Export'})
    export_id = one(f"select export_id from mybb_myshowcase_exports where user_id={uid} order by export_id desc limit 1")
    download = member.get(f'{SHOWCASE}/export/{export_id}/download')
    export_path = os.path.join(SCRATCH, 'numbering-export.xml')
    with open(export_path, 'wb') as handle:
        handle.write(download['raw'])
    tokens = set(re.findall(r'<key>category</key>\s*<value>([^<]*)</value>', download['raw'].decode('utf-8', 'replace')))
    ok('2' in tokens and tokens <= set(option.keys()) | {''},
       f'the export holds the value id 2 and no field_data_id (category values in the file: {sorted(tokens)})')

    # refusals: an unknown token, the field_data_id of an option as a token, a value of another field, a blank required value
    unknown = str(max(int(i) for i in option.values()) + 1000)
    body = import_into(member, 'blog', write_file('numbering-unknown.xml', xml_file([good_entry(61, category='9')])))['body']
    ok(report_of(body) is not None and report_of(body)['status'] == 'failed' and 'is not an option of this field' in text(body),
       'an unknown token fails the import with the report line')
    body = import_into(member, 'blog', write_file('numbering-fid.xml', xml_file([good_entry(62, category=option['2'])])))['body']
    ok(option['2'] not in option and report_of(body) is not None and report_of(body)['status'] == 'failed' and
       'is not an option of this field' in text(body),
       f"the field_data_id {option['2']} of an option is not a token: it is refused as unknown (unknown guard {unknown})")
    other = one("select d.value_id from mybb_myshowcase_field_data d, mybb_myshowcase_fields f where d.field_id=f.field_id "
                f"and f.field_key='category' and f.collection_id<>{cid()} and d.value_id not in "
                f"(select value_id from mybb_myshowcase_field_data where field_id={field}) limit 1")
    if other:
        body = import_into(member, 'blog', write_file('numbering-other.xml', xml_file([good_entry(63, category=str(other))])))['body']
        ok(report_of(body) is not None and report_of(body)['status'] == 'failed' and 'is not an option of this field' in text(body),
           f'the value id {other} of an option of another field is refused for this field')
    else:
        not_run('a token of another field: the board has no option of another field with a value id the blog does not use')
    body = import_into(member, 'blog', write_file('numbering-blank.xml', xml_file([good_entry(64, category='')])))['body']
    ok(report_of(body) is not None and report_of(body)['status'] == 'failed', 'a blank required option value fails the entry')

    # the shipped downloads entries (value id 1 in each of the three option fields)
    downloads_sid = one("select showcase_id from mybb_myshowcase_config where route_identifier='downloads'")
    if downloads_sid:
        for key in ('category', 'platform', 'license'):
            field_of = f"(select field_id from mybb_myshowcase_fields where field_key='{key}' and collection_id=" \
                       f"(select collection_id from mybb_myshowcase_collections where name='Downloads'))"
            expected = one(f"select field_data_id from mybb_myshowcase_field_data where field_id={field_of} and value_id='1'")
            stored_value = one(f"select {key} from mybb_myshowcase_data{downloads_sid} order by entry_id desc limit 1")
            if key == 'platform':
                # a multiple-choice field: its column is the cache of the value ids (decision D185); value 1 is among them
                ok(expected != '' and '1' in str(stored_value).split(','),
                   f'downloads {key}: the cache holds the value id 1 of the option ({stored_value})')
                continue
            ok(expected != '' and str(stored_value) == str(expected),
               f'downloads {key}: the stored id is the field_data_id of its value 1 option ({stored_value} / {expected})')
    else:
        not_run('downloads option assertions: the downloads showcase is not on this board')


def section_option_portability():
    """Step 4, decision D128 and D137: the option values travel between numberings. The entries are imported with value ids
    1 to 4, the options of the category are then copied (a second numbering of the same options, as another board built from
    the same collection would have), and a file in the export layout with the same value ids is imported. Each copy must hold
    the field_data_id of the option with its value id in the second numbering, and that option must have the label of the
    original. This is an option-only check on one board: it is not a second board and not a whole-file move (the users,
    groups and file URLs of a file are not compared)."""
    print('--- option portability: the same options under a second numbering (decisions D128, D137)')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    table = data_table()
    field = f"(select field_id from mybb_myshowcase_fields where field_key='category' and collection_id={cid()})"
    CTX.execute('update mybb_myshowcase_config set import_export_flood_seconds=0 where route_identifier=\'blog\'')

    body = import_into(member, 'blog', write_file('portability-first.xml', xml_file(
        [good_entry(70 + i, category=str(i + 1)) for i in range(4)])))['body']
    ok(report_of(body) is not None and report_of(body)['status'] == 'completed', 'the first numbering: four entries with value ids 1 to 4 import')
    first = db(f"select category from {table} where user_id={uid} and subject like 'Import entry 7%' order by entry_id")
    first_labels = {}
    for (stored_id,) in first:
        value_id, label = db(f"select value_id, value from mybb_myshowcase_field_data where field_data_id={stored_id}")[0]
        first_labels[str(value_id)] = (str(stored_id), str(label))
    ok(sorted(first_labels) == ['1', '2', '3', '4'], f'the first entries hold the four options: {sorted(first_labels)}')

    # the second numbering: the same value ids and labels, new field_data_ids. The copies are inserted before the old
    # rows are deleted: on SQLite a deleted newest id is handed out again, which would keep the numbering.
    originals = db(f"select value_id, value, allowed_groups_fill from mybb_myshowcase_field_data where field_id={field} "
                   "order by field_data_id")
    old_ids = [str(i) for (i,) in db(f"select field_data_id from mybb_myshowcase_field_data where field_id={field}")]
    for value_id, label, groups in originals:
        CTX.execute('insert into mybb_myshowcase_field_data (collection_id, field_id, field_key, value_id, value, '
                    f"allowed_groups_fill) select collection_id, field_id, field_key, '{value_id}', '{label}', '{groups}' "
                    f"from mybb_myshowcase_fields where field_id={field}")
    CTX.execute(f"delete from mybb_myshowcase_field_data where field_data_id in ({', '.join(old_ids)})")
    second = {str(v): str(i) for v, i in db(f"select value_id, field_data_id from mybb_myshowcase_field_data "
                                              f"where field_id={field}")}
    ok(all(second[v] != first_labels[v][0] for v in first_labels),
       f"the second numbering differs from the first: {[(v, first_labels[v][0], second[v]) for v in sorted(first_labels)]}")

    body = import_into(member, 'blog', write_file('portability-second.xml', xml_file(
        [good_entry(80 + i, category=str(i + 1)) for i in range(4)])))['body']
    ok(report_of(body) is not None and report_of(body)['status'] == 'completed', 'the file with value ids 1 to 4 imports into the second numbering')
    copies = db(f"select category from {table} where user_id={uid} and subject like 'Import entry 8%' order by entry_id")
    by_value = {}
    for (stored_id,) in copies:
        value_id, label = db(f"select value_id, value from mybb_myshowcase_field_data where field_data_id={stored_id}")[0]
        by_value[str(value_id)] = (str(stored_id), str(label))
    ok(sorted(by_value) == ['1', '2', '3', '4'] and all(by_value[v][0] == second[v] for v in by_value),
       f'each copy holds the field_data_id of its value id in the second numbering: {sorted(by_value.items())}')
    ok(all(by_value[v][1] == first_labels[v][1] for v in by_value),
       f"each copy holds the label of its original: {[(v, by_value[v][1], first_labels[v][1]) for v in sorted(by_value)]}")


def section_downloads_round_trip():
    """Decision D142 (b): the downloads entries of the board are exported and imported back. Two fresh entries are imported first,
    from the GitHub ZIP of the branch dev-1.9 (as section F6 does), each with a value other than 1 in category, platform and license.
    (1) The export is imported into the same board: each copy holds the field_data_id of the option with the value id the file
    holds, and its file has the same stored name and size as the original. (2) The entries are removed as data and the same file
    is imported: the own-board file of a removed entry is refused (decision D58: its entry must be visible), and nothing is stored."""
    print('--- downloads export and re-import (decision D142)')
    fixture('files-setup', 'import-test-uploads')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    did = int(one("select showcase_id from mybb_myshowcase_config where route_identifier='downloads'") or 0)
    if not did:
        not_run('downloads round trip: the downloads showcase is not on this board')
        return
    table = f'mybb_myshowcase_data{did}'
    zip_url = 'https://github.com/mybb/mybb/archive/refs/heads/dev-1.9.zip'
    # entry 91 holds one platform (a format-3 file with a single value); entry 92 holds two, as an ordered list of value ids
    picks = {91: {'category': 2, 'platform': 3, 'license': 2}, 92: {'category': 4, 'platform': ['1', '3'], 'license': 3}}
    for index, pick in picks.items():
        entry = downloads_entry(index, zip_url)
        entry['fields'].update(pick)
        started = time.time()
        body = import_into(member, 'downloads', write_file(f'downloads-fresh-{index}.xml', xml_file([entry], route='downloads', collection='Downloads', format_version=3)))['body']
        report = report_of(body)
        zip_check(f'a fresh downloads entry (1.9.{index}) imports from the GitHub ZIP', body, time.time() - started, zip_url)
    cols = ['version', 'category', 'platform', 'license']
    field_data = lambda key, value_id: one(
        f"select field_data_id from mybb_myshowcase_field_data where value_id='{value_id}' and field_id=("
        f"select field_id from mybb_myshowcase_fields where field_key='{key}' and collection_id=("
        "select collection_id from mybb_myshowcase_collections where name='Downloads'))")

    def snapshot():
        rows = {}
        for row in db(f"select entry_id, {', '.join(cols)} from {table} where user_id={uid} order by entry_id"):
            entry_id, values = row[0], dict(zip(cols, row[1:]))
            files = db(f"select attachment_name, file_size from mybb_myshowcase_attachments where showcase_id={did} and entry_id={entry_id} "
                       "and field_id is not null order by attachment_id")
            values['files'] = [tuple(f) for f in files]
            values['platform'] = platform_tokens(entry_id)
            rows.setdefault(values['version'], []).append(values)
        return rows

    # the platform of an entry is its child rows (decision D185): the value ids, in option order
    platform_field = one("select field_id from mybb_myshowcase_fields where field_key='platform' and collection_id="
                         "(select collection_id from mybb_myshowcase_collections where name='Downloads')")

    def platform_tokens(entry_id):
        return ','.join(row[0] for row in db(
            f"select value_id from mybb_myshowcase_field_values where showcase_id={did} and entry_id={entry_id} "
            f"and field_id={platform_field} order by field_value_id"))

    before = snapshot()
    ok(len(before.get('1.9.91', [])) == 1 and len(before.get('1.9.92', [])) == 1,
       f'each fresh entry is stored once before the export ({sorted(before)})')
    export_form = member.get('/showcase.php/downloads/export')
    member.post('/showcase.php/downloads/export', {'my_post_key': post_key(export_form['body']), 'limit': '0', 'order_by': 'dateline',
                                                   'order_dir': 'asc', 'submit': 'Export'})
    export_id = one(f"select export_id from mybb_myshowcase_exports where user_id={uid} order by export_id desc limit 1")
    download = member.get(f'/showcase.php/downloads/export/{export_id}/download')
    export_path = os.path.join(SCRATCH, 'downloads-export.xml')
    with open(export_path, 'wb') as handle:
        handle.write(download['raw'])
    ok(download['status'] == 200 and b'myshowcase-entries' in download['raw'], 'the downloads entries export as an entries file')

    # (1) the export imported into the same board: copies, each with the options of its value ids and the same file
    body = import_into(member, 'downloads', export_path)['body']
    report = report_of(body)
    ok(report is not None and report['status'] == 'completed', f"the export imports back into the same board ({report['text'][:160] if report else 'no report'})")
    after = snapshot()
    for version, pick_index in (('1.9.91', 91), ('1.9.92', 92)):
        if len(after.get(version, [])) != 2:
            ok(False, f'{version}: two entries after the import (found {len(after.get(version, []))})')
            continue
        original, copy = after[version][0], after[version][1]
        for key in ('category', 'license'):
            value_id = picks[pick_index][key]
            ok(str(copy[key]) == str(original[key]) == str(field_data(key, value_id)),
               f'{version} {key}: the copy holds the field_data_id {copy[key]} of value id {value_id}, as the original does')
        # several platforms: the child rows of the copy hold the same value ids, in the same order (decisions D182, D137)
        platforms = picks[pick_index]['platform']
        expected = ','.join(platforms if isinstance(platforms, list) else [str(platforms)])
        ok(copy['platform'] == original['platform'] == expected,
           f"{version} platform: the copy holds the value ids {copy['platform']!r}, the original {original['platform']!r}, expected {expected!r}")
        # the stored file name is generated again for each copy; the name the file was uploaded with and its size are compared
        ok(copy['files'] == original['files'] and len(copy['files']) == 1,
           f"{version} file: the copy has the same file name and size ({original['files']} -> {copy['files']})")

    # (2) the entries removed as data, the same file imported: refused as a whole (the own-board files have no visible entry)
    CTX.execute(f"delete from {table} where user_id={uid}")
    stored_before = int(one(f'select count(*) from {table}') or 0)
    body = import_into(member, 'downloads', export_path)['body']
    report = report_of(body)
    refused = report is not None and report['status'] == 'failed' and 'may not download' in text(body)
    ok(refused and int(one(f'select count(*) from {table}') or 0) == stored_before,
       'after the entries are removed, the file of an own-board entry is refused as a whole (decision D58) and nothing is stored')


def section_previews():
    """Decisions D197, D182 and D59 (import): a downloads entry with two preview images (a list of file URLs) is stored with
    the files in display order and the cache of the field; exported and imported back into the same board, its copy holds the
    same two previews; a second file that fails rolls the whole entry back, the first file included; with the setting off, a list
    of URLs is refused as a whole."""
    print('--- several files in a field: the previews of a downloads entry (decisions D197, D182, D59)')
    fixture('files-setup', 'import-test-uploads')
    fixture('external-setting', '1')
    time.sleep(3)
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    did = int(one("select showcase_id from mybb_myshowcase_config where route_identifier='downloads'") or 0)
    if not did:
        not_run('previews: the downloads showcase is not on this board')
        return
    table = f'mybb_myshowcase_data{did}'
    zip_url = 'https://github.com/mybb/mybb/archive/refs/heads/dev-1.9.zip'
    preview_field = one("select field_id from mybb_myshowcase_fields where field_key='previews' and collection_id="
                        "(select collection_id from mybb_myshowcase_collections where name='Downloads')")
    previews = ['https://placehold.co/600x450.png?text=Preview%20one', 'https://placehold.co/600x450.png?text=Preview%20two']

    def files_of(version):
        rows = db(f"select a.attachment_id, a.display_order from mybb_myshowcase_attachments a join {table} d on d.entry_id=a.entry_id "
                  f"where d.version='{version}' and d.user_id={uid} and a.field_id={preview_field} order by a.display_order")
        cache = one(f"select previews from {table} where version='{version}' and user_id={uid} order by entry_id desc limit 1")
        return [r[0] for r in rows], [r[1] for r in rows], cache

    entry = downloads_entry(96, zip_url)
    entry['fields']['previews'] = previews
    body = import_into(member, 'downloads', write_file('downloads-previews.xml', xml_file([entry], route='downloads', collection='Downloads', format_version=3)))['body']
    report = report_of(body)
    ok(report is not None and report['status'] == 'completed', 'a downloads entry with two preview URLs is imported')
    ids, orders, cache = files_of('1.9.96')
    ok(len(ids) == 2 and orders == ['1', '2'] and cache == ','.join(ids),
       f'the two previews are stored in display order {orders}, and the field holds their ids {cache!r}')

    # the export carries the previews as a list, and imports back into the same board as a second copy
    export_form = member.get('/showcase.php/downloads/export')
    member.post('/showcase.php/downloads/export', {'my_post_key': post_key(export_form['body']), 'limit': '0', 'order_by': 'dateline',
                                                   'order_dir': 'asc', 'submit': 'Export'})
    export_id = one(f"select export_id from mybb_myshowcase_exports where user_id={uid} order by export_id desc limit 1")
    download = member.get(f'/showcase.php/downloads/export/{export_id}/download')
    export_path = os.path.join(SCRATCH, 'downloads-previews-export.xml')
    open(export_path, 'wb').write(download['raw'])
    exported_list = re.search(r'<key>previews</key>\s*<value>\s*<array>(.*?)</array>', download['raw'].decode('utf-8', 'replace'), re.S)
    ok(exported_list is not None and len(re.findall(r'<value>', exported_list.group(1))) == 2,
       'the export writes the previews of the entry as a list of two URLs')
    body = import_into(member, 'downloads', export_path)['body']
    ok(report_of(body) is not None and report_of(body)['status'] == 'completed', 'the export of the previews imports back into the same board')
    copies = db(f"select entry_id from {table} where version='1.9.96' and user_id={uid} order by entry_id")
    copy_previews = [files_of('1.9.96')[0]]
    ok(len(copies) == 2 and len(copy_previews[0]) == 4, f'the copy holds its own two previews ({len(copies)} entries, {len(copy_previews[0])} files)')

    # a second file that fails rolls the first one back: nothing of the entry is stored
    stored_before = int(one(f'select count(*) from {table} where user_id={uid}') or 0)
    files_before = int(one(f'select count(*) from mybb_myshowcase_attachments where showcase_id={did}') or 0)
    bad = downloads_entry(97, zip_url)
    bad['fields']['previews'] = [previews[0], 'https://github.com/mybb/mybb/archive/refs/heads/this-branch-does-not-exist.zip']
    body = import_into(member, 'downloads', write_file('downloads-previews-bad.xml', xml_file([bad], route='downloads', collection='Downloads', format_version=3)))['body']
    report = report_of(body)
    ok(report is not None and report['status'] == 'failed' and int(one(f'select count(*) from {table} where user_id={uid}') or 0) == stored_before
       and int(one(f'select count(*) from mybb_myshowcase_attachments where showcase_id={did}') or 0) == files_before,
       'a second file that fails rolls the whole entry back, the first file too (decision D59)')

    # the setting off refuses a list of URLs as a whole
    # a file of the list over the field's maximum size (decision D197, closure A.2 of batch S2): the field takes 1 KB here, so
    # the preview is refused, the entry is not stored, and the message names the field and the file (its URL)
    fixture('field-maximum-size', 'Downloads', 'previews', '1')
    large = downloads_entry(99, zip_url)
    large['fields']['previews'] = [previews[0]]
    body = import_into(member, 'downloads', write_file('downloads-previews-large.xml', xml_file([large], route='downloads', collection='Downloads', format_version=3)))['body']
    report = report_of(body)
    named = report is not None and previews[0] in report['text'] and 'Previews' in report['text'] and 'larger' in report['text']
    stored_large = int(one(f"select count(*) from {table} where version='1.9.99' and user_id={uid}") or 0)
    ok(report is not None and report['status'] == 'failed' and named and stored_large == 0,
       f'a preview over the field size is refused as a whole, the message names the field and the file, nothing stored '
       f'({report_kind(body) if report is not None else "no report"}; report: {report["text"][:200] if report else ""!r})')
    fixture('field-maximum-size', 'Downloads', 'previews', '2048')
    time.sleep(3)

    fixture('external-setting', '0')
    time.sleep(3)
    off = downloads_entry(98, zip_url)
    off['fields']['previews'] = previews
    body = import_into(member, 'downloads', write_file('downloads-previews-off.xml', xml_file([off], route='downloads', collection='Downloads', format_version=3)))['body']
    report = report_of(body)
    ok(report is not None and report['status'] == 'failed' and int(one(f'select count(*) from {table} where user_id={uid}') or 0) == stored_before,
       'with the setting off a list of file URLs is refused as a whole, nothing stored')
    fixture('external-setting', '1')
    time.sleep(3)


def section_unknown_option_value():
    """Decision D182: a value_id of a multiple-choice field that is no option of the field is refused as a whole, with the reason
    (the entry, the value and the field); nothing is stored."""
    print('--- an unknown value of a multiple-choice field (decision D182)')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    did = int(one("select showcase_id from mybb_myshowcase_config where route_identifier='downloads'") or 0)
    if not did:
        not_run('unknown value: the downloads showcase is not on this board')
        return
    table = f'mybb_myshowcase_data{did}'
    before = int(one(f'select count(*) from {table} where user_id={uid}') or 0)
    entry = downloads_entry(93, 'https://placehold.co/600x450.png?text=Unknown%20value')
    entry['fields']['platform'] = ['1', '99']
    body = import_into(member, 'downloads', write_file('downloads-unknown-value.xml', xml_file([entry], route='downloads', collection='Downloads', format_version=3)))['body']
    report = report_of(body)
    reason = 'the value "99" of the field "Platform" is not an option of this field' in html.unescape(text(body))
    ok(report is not None and report['status'] == 'failed' and reason and int(one(f'select count(*) from {table} where user_id={uid}') or 0) == before,
       'a file with a value that is no option of the field is refused as a whole, with the reason, and nothing is stored')


def section_four_byte_subject():
    """Decision D150: the XML import goes through the same validator as the form. An emoji in an entry's subject is stored
    where the database stores it (SQLite); on MySQL utf8 the entry is refused and the report names the subject's input."""
    print('--- four-byte character in the subject (decision D150)')
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    table = data_table()
    before = int(one(f'select count(*) from {table} where user_id={uid}') or 0)
    entry = good_entry(95)
    entry['subject'] = 'Emoji \U0001F600 subject'
    body = import_into(member, 'blog', write_file('four-byte-subject.xml', xml_file([entry])))['body']
    report = report_of(body)
    after = int(one(f'select count(*) from {table} where user_id={uid}') or 0)
    if ENGINE == 'mysql':
        ok(report is not None and report['status'] == 'failed' and 'Post Subject' in text(body) and 'cannot store' in text(body),
           'MySQL utf8: the import refuses an emoji subject, and the report names the input')
        ok(after == before, 'MySQL utf8: the refused subject stores nothing')
    else:
        ok(report is not None and report['status'] == 'completed' and after == before + 1,
           f'{ENGINE}: an emoji subject imports as sent')


def page_has_label(page, label):
    return text(page).find(label) >= 0 if label else False


# ---- the content seeders (decisions D40 and D43): tests/fixtures/seeds, imported through the forum-side import ----------

SEEDS_DIR = os.path.join(HERE, '..', '..', 'fixtures', 'seeds')
COLLECTIONS_DIR = os.path.join(HERE, '..', '..', '..', 'Upload', 'inc', 'plugins', 'myshowcase', 'imports', 'JSON', 'collections')
COLLECTION_NAMES = {'blog': 'Blog', 'downloads': 'Downloads', 'gallery': 'Gallery'}
SEED_FIRST_ATTACHMENT = {}
SEED_EXPECTED = [
    ('blog-full', 'blog', 3), ('blog-minimal', 'blog', 1),
    ('downloads-full', 'downloads', 2), ('downloads-minimal', 'downloads', 1),
    ('gallery-full', 'gallery', 3), ('gallery-minimal', 'gallery', 1),
]


def seed_value(element):
    """A value of a seed file, read the way the import reads it: an array whose keys are all INT is a list, another array a
    dict, and a value typed INT is a number."""
    array = element.find('array')
    if array is not None:
        children = [(c.find('key'), c.find('value')) for c in array.findall('element')]
        if children and all(k is not None and k.get('type') == 'INT' for k, _ in children):
            return [seed_value(v) for _, v in children]
        return {k.text: seed_value(v) for k, v in children}
    text = element.text or ''
    return int(text) if element.get('type') == 'INT' else text


def seed_entries(name):
    """The entries of a seed file: subject, message (None when the entry carries none) and the fields by key."""
    import xml.etree.ElementTree as ET
    root = ET.parse(os.path.join(SEEDS_DIR, name + '.xml')).getroot()
    entries_value = [e.find('value') for e in root.findall('element') if e.find('key').text == 'entries'][0]
    entries = []
    for item in entries_value.find('array').findall('element'):
        parts = {p.find('key').text: p.find('value') for p in item.find('value').find('array').findall('element')}
        entries.append({'subject': seed_value(parts['subject']),
                        'message': seed_value(parts['message']) if 'message' in parts else None,
                        'fields': seed_value(parts['fields'])})
    return entries


def collection_fields(route):
    """The field definitions of the shipped collection of a showcase, by key (from its JSON file)."""
    data = json.load(open(os.path.join(COLLECTIONS_DIR, COLLECTION_NAMES[route].lower() + '.json'), encoding='utf-8'))
    return {f['field_key']: f for f in data['fields']}


def showcase_of(route):
    return int(one(f"select showcase_id from mybb_myshowcase_config where route_identifier='{route}'") or 0)


def field_id_of(route, key):
    return int(one(f"select f.field_id from mybb_myshowcase_fields f join mybb_myshowcase_config c on c.collection_id=f.collection_id "
                   f"where c.route_identifier='{route}' and f.field_key='{key}'") or 0)


def option_label(route, key, value_id):
    """The label of an option by its value id (decision D185: the value ids are the ones a seed names)."""
    return one(f"select value from mybb_myshowcase_field_data where field_id={field_id_of(route, key)} and value_id='{value_id}'")


def hex_of_column(column):
    """The column as hexadecimal UTF-8, whatever the engine: the command-line client of MySQL escapes newlines and backslashes
    in its output, so a text value is read back as bytes and decoded (the same reading as the field matrix's)."""
    if ENGINE == 'pgsql':
        return f"encode(convert_to(coalesce({column}::text,''),'UTF8'),'hex')"
    return f"HEX(CAST({column} AS CHAR))" if ENGINE == 'mysql' else f"hex({column})"


def text_row(sql_columns, table, where):
    """One row of the columns as text, read through hexadecimal (an empty value is '')."""
    rows = db(f"select {', '.join(hex_of_column(c) for c in sql_columns)} from {table} where {where}")
    if not rows:
        return None
    return [bytes.fromhex(v).decode('utf-8', errors='replace') if v not in (None, '', 'NULL') else '' for v in rows[0]]


def check_seed_entry(member, route, entry, uid, label, first_attachment):
    """Every field of one seed entry against its stored row: options by label, lists in order, text and numbers as sent, files
    by their attachments in display order, and the entry page answers with its subject. Returns the problems found."""
    sid = showcase_of(route)
    fields = collection_fields(route)
    found = db(f"select entry_id, public_id, entry_slug_custom from mybb_myshowcase_data{sid} where subject='{entry['subject']}' "
               f"and user_id={uid} order by entry_id desc limit 1")
    if not found:
        return [f'{label}: no stored entry for the subject {entry["subject"]!r}']
    entry_id, public_id, slug = found[0]
    keys = list(fields)
    values = dict(zip(keys, text_row(keys, f'mybb_myshowcase_data{sid}', f'entry_id={entry_id}')))
    problems = []
    for key in keys:
        html_type = fields[key]['html_type']
        wanted = entry['fields'].get(key, '')
        got = values[key] or ''
        if html_type == 'file':
            # only the files this import attached: an entry id can be reused after a delete, and older rows stay in the table
            files = db(f"select attachment_name, mime_type, file_size from mybb_myshowcase_attachments where entry_id={entry_id} "
                       f"and showcase_id={sid} and attachment_id>{first_attachment} "
                       f"and field_id={field_id_of(route, key)} and status=1 order by display_order, attachment_id")
            count = len(wanted) if isinstance(wanted, list) else (1 if wanted else 0)
            if len(files) != count:
                problems.append(f'{label}: {key} holds {len(files)} file(s), the seed has {count}')
            for name, mime, size in files:
                if not int(size or 0) > 0 or not str(mime).startswith(('image/', 'application/zip')):
                    problems.append(f'{label}: {key} file {name!r} has type {mime!r} and size {size}')
        elif html_type == 'select':
            if wanted == '':
                if got not in ('', '0'):
                    problems.append(f'{label}: blank {key} stored {got!r}')
            else:
                shown = one(f"select value from mybb_myshowcase_field_data where field_data_id='{got}'")
                if shown != option_label(route, key, wanted):
                    problems.append(f'{label}: {key} shows {shown!r}, the seed says {option_label(route, key, wanted)!r}')
        elif html_type == 'checkbox':
            wanted_ids = [str(v) for v in wanted] if isinstance(wanted, list) else ([str(wanted)] if wanted != '' else [])
            stored_ids = [v for v in str(got).split(',') if v]
            if [option_label(route, key, v) for v in stored_ids] != [option_label(route, key, v) for v in wanted_ids]:
                problems.append(f'{label}: {key} stored {stored_ids}, the seed says {wanted_ids}')
        elif html_type == 'number':
            if wanted == '':
                if got not in ('', '0'):
                    problems.append(f'{label}: blank {key} stored {got!r}')
            elif str(int(float(got or 0))) != str(wanted):
                problems.append(f'{label}: {key} stored {got!r}, the seed says {wanted!r}')
        else:
            if str(wanted) != str(got):
                problems.append(f'{label}: {key} stored {got!r}, the seed says {wanted!r}')
    if entry['message']:
        message = (text_row(['message'], f'mybb_myshowcase_data{sid}', f'entry_id={entry_id}') or [''])[0]
        if message != entry['message']:
            problems.append(f'{label}: the stored message {message!r} is not the seed message {entry["message"]!r}')
    page = member.get(f"/showcase.php/{route}/view/{public_id}/{slug or 'x'}")
    if page['status'] != 200 or entry['subject'] not in page['body']:
        problems.append(f'{label}: the entry page answered {page["status"]} without the subject')
    return problems


def section_seeds():
    print('--- S content seeders: every shipped collection field in a full seed, a minimal seed for each showcase (D40, D43)')
    fixture('files-setup', 'import-test-uploads')
    fixture('external-setting', '1')
    time.sleep(3)
    # the entries removed as data by the earlier checks leave their files in the table, and an entry id can come again: the
    # files of entries that no longer exist are removed first, so an export of the seeds holds only their own files
    for route in ('blog', 'downloads', 'gallery'):
        sid = showcase_of(route)
        CTX.execute(f"delete from mybb_myshowcase_attachments where showcase_id={sid} and entry_id>0 "
                    f"and entry_id not in (select entry_id from mybb_myshowcase_data{sid})")
    member = logged_in('member1')
    uid = USERS['member1']['uid']
    for name, route, count in SEED_EXPECTED:
        before = int(one(f"select count(*) from mybb_myshowcase_data{showcase_of(route)} where user_id={uid}") or 0)
        first_attachment = int(one('select max(attachment_id) from mybb_myshowcase_attachments') or 0) if one('select max(attachment_id) from mybb_myshowcase_attachments') not in ('', 'NULL') else 0
        SEED_FIRST_ATTACHMENT[name] = first_attachment
        body = import_into(member, route, os.path.join(SEEDS_DIR, name + '.xml'))['body']
        dump('seed-' + name, body)
        report = report_of(body)
        imported = re.search(r'Entries imported (\d+)', report['text']) if report else None
        ok(report is not None and report['status'] == 'completed' and imported is not None and int(imported.group(1)) == count,
           f'{name}: the seed imports completed with {count} entries ({report_kind(body) if report is not None else "no report"}; '
           f'report: {report["text"][:700] if report else ""!r})')
        if report is None or report['status'] != 'completed':
            continue
        stored = int(one(f"select count(*) from mybb_myshowcase_data{showcase_of(route)} where user_id={uid}") or 0) - before
        ok(stored == count, f'{name}: {stored} entries stored')
        problems = []
        for index, entry in enumerate(seed_entries(name)):
            problems += check_seed_entry(member, route, entry, uid, f'{name} entry {index + 1}', first_attachment)
        ok(not problems, f'{name}: every field of every entry holds its seed value' + (f'; {problems[:3]}' if problems else ' (checked)'))


def create_showcase_copy(route, copy_route):
    """A second showcase of the same collection, from the shipped file of the route (the ACP's JSON sync)."""
    acp = ACP
    acp.get('/admin/index.php?module=myshowcase-json_sync&action=create&type=showcase')
    for action in ('preview', 'run'):
        acp.post('/admin/index.php?module=myshowcase-json_sync', {
            'action': action, 'mode': 'create', 'type': 'showcase', 'file': f'showcases/{route}.json',
            'name': COLLECTION_NAMES[route] + ' copy', 'route_identifier': copy_route, 'custom_template_prefix': '',
        })
    created = one(f"select count(*) from mybb_myshowcase_config where route_identifier='{copy_route}'") == '1'
    if created:
        # the JSON sync creates a showcase switched off; the copy is switched on for the import
        fixture('showcase-setting', copy_route, 'is_enabled', '1')
    return created


def stored_values(route, subject, uid, newest=True, first_attachment=0):
    """The stored fields of an entry with the subject (the newest copy, or the oldest), by key: the column values, and for file
    fields the files of the entry (name, MIME type and size, in display order). None when the entry is not there."""
    sid = showcase_of(route)
    fields = collection_fields(route)
    order = 'desc' if newest else 'asc'
    found = db(f"select entry_id from mybb_myshowcase_data{sid} where subject='{subject}' and user_id={uid} order by entry_id {order} limit 1")
    if not found:
        return None
    entry_id = found[0][0]
    keys = list(fields)
    row = dict(zip(keys, db(f"select {', '.join(keys)} from mybb_myshowcase_data{sid} where entry_id={entry_id}")[0]))
    for key in keys:
        if fields[key]['html_type'] == 'file':
            row[key] = db(f"select attachment_name, mime_type, file_size from mybb_myshowcase_attachments where entry_id={entry_id} "
                          f"and showcase_id={sid} and attachment_id>{first_attachment} and field_id={field_id_of(route, key)} and status=1 "
                          f"order by display_order, attachment_id")
    return row


def seeded_export(raw, subjects):
    """The export of a showcase, kept to the entries whose subject is one of the seeded subjects (the showcase also holds the
    entries of other checks, which the import refuses as a whole): the entry count of the header is set to the kept entries."""
    import xml.etree.ElementTree as ET
    root = ET.fromstring(raw)
    top = {e.find('key').text: e.find('value') for e in root.findall('element')}
    array = top['entries'].find('array')
    kept = 0
    for item in list(array.findall('element')):
        parts = {p.find('key').text: p.find('value') for p in item.find('value').find('array').findall('element')}
        if seed_value(parts['subject']) in subjects:
            kept += 1
        else:
            array.remove(item)
    # the entries are a list: their index keys are renumbered from 0 after the removal
    for index, item in enumerate(array.findall('element')):
        item.find('key').text = str(index)
    for element in top['header'].find('array').findall('element'):
        if element.find('key').text == 'entry_count':
            element.find('value').text = str(kept)
    return ET.tostring(root, encoding='utf-8', xml_declaration=True)


def section_seed_round_trip(member, uid):
    """The export of each full seed's showcase imports back into the same showcase as a second copy, and the copy holds the same
    field values as the seed's entry (the stored values by label, lists in order, the files by name, type and size). The
    decision of the plugin (an export is imported only into the showcase it came from) is checked on a second showcase of the
    same collection, which is refused. Held back: the contract's import into a second showcase, which the plugin refuses by
    design (status file, step S decision P-S1)."""
    print('--- S2 round trip: the export of the seeded showcases imports back; a second showcase refuses the file')
    for route in ('blog', 'downloads', 'gallery'):
        copy_route = route + 'copy'
        created = create_showcase_copy(route, copy_route)
        ok(created, f'the second showcase {copy_route} of the {route} collection is created from the shipped file')
        export_form = member.get(f'/showcase.php/{route}/export')
        member.post(f'/showcase.php/{route}/export', {'my_post_key': post_key(export_form['body']), 'limit': '0', 'order_by': 'dateline',
                                                     'order_dir': 'asc', 'submit': 'Export'})
        export_id = one(f"select export_id from mybb_myshowcase_exports where user_id={uid} order by export_id desc limit 1")
        download = member.get(f'/showcase.php/{route}/export/{export_id}/download')
        seeded_by_name = {name: [e['subject'] for e in seed_entries(name)] for name, r, _ in SEED_EXPECTED
                          if r == route and name.endswith('full')}
        all_subjects = [subject for subjects in seeded_by_name.values() for subject in subjects]
        try:
            exported = seeded_export(download['raw'], all_subjects)
        except Exception as error:  # the export is not an entries file: a failed check, and the round trip of this route stops
            ok(False, f'the export of {route} is an entries file ({type(error).__name__}; status {download["status"]}: {download["raw"][:120]!r})')
            continue
        path = write_file(f'seed-roundtrip-{route}.xml', exported)
        first_round = int(one('select max(attachment_id) from mybb_myshowcase_attachments') or 0) if one('select max(attachment_id) from mybb_myshowcase_attachments') not in ('', 'NULL') else 0
        if created:
            body = import_into(member, copy_route, path)['body']
            dump(f'seed-other-showcase-{route}', body)
            ok(report_of(body) is not None and report_of(body)['status'] == 'failed' and
               'only imported into the showcase it came from' in report_of(body)['text'],
               f'an export of {route} is refused by {copy_route}: a file is imported only into the showcase it came from')
        body = import_into(member, route, path)['body']
        dump(f'seed-roundtrip-{route}', body)
        report = report_of(body)
        ok(report is not None and report['status'] == 'completed',
           f'the export of {route} imports back into {route} ({report_kind(body) if report is not None else "no report"})')
        if report is None or report['status'] != 'completed':
            continue
        differences = []
        seeded = all_subjects
        origin = {subject: name for name, subjects in seeded_by_name.items() for subject in subjects}
        for subject in seeded:
            original = stored_values(route, subject, uid, newest=False, first_attachment=SEED_FIRST_ATTACHMENT[origin[subject]])
            copy = stored_values(route, subject, uid, newest=True, first_attachment=first_round)
            if original is None or copy is None or original is copy:
                differences.append(f'{subject!r}: a copy is missing')
                continue
            for key in original:
                if original[key] != copy[key]:
                    differences.append(f'{subject!r} {key}: {original[key]!r} became {copy[key]!r}')
        ok(not differences and len(seeded) > 0,
           f'the copy of each seeded {route} entry holds the same field values ({len(seeded)} entries)' + (f': {differences[:3]}' if differences else ''))


def section_logs():
    print('--- I8 PHP log')
    relevant = [l for l in error_log_lines() if re.search(r'Warning|Deprecated|Notice|Fatal', l) and re.search(r'myshowcase', l, re.I)]
    ok(not relevant, f'no PHP warning, notice, deprecation or fatal error from the plugin in the log ({len(relevant)} found)')
    for line in relevant[:20]:
        print('   log:', line[:240])
