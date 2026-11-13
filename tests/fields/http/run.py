#!/usr/bin/env python3
"""End-to-end matrix of the custom field types (see tests/fields/README.md). Installs a scratch board for one engine,
creates the FieldKit showcase through the ACP's JSON sync, creates and edits entries through the forum form, and checks
every field of the collection at the automated stages of the matrix (create form, validation, storage, entry page with
escaping, edit round trip). Nothing is repaired: a failing cell must be listed in known_failures.json.

    python3 tests/fields/http/run.py --engine sqlite|mysql|pgsql --mybb DIR --scratch DIR
                                     [--port N] [--pluginlibrary-file FILE] [--matrix-out FILE] [--keep]

--mybb is a MyBB 1.9 source tree with its own vendor/ folder (tests/import/README.md). It is copied, never changed.
MySQL: MYSQL_* variables (tests/json-sync/README.md). PostgreSQL: a server the run may use for a throwaway database,
named by PGHOST, PGPORT and PGUSER, the client by PSQL_BIN. The database msc_test_<random> is dropped at the end.

Exit code 0 only when the failing cells equal known_failures.json. A new failing cell, or a listed cell that passes, exits 1.
Stages a, f, h and k are not automated yet: their cells are written as NOT RUN with the reason.
"""
import argparse, hashlib, html as htmllib, json, os, random, re, shutil, sqlite3, string, struct, subprocess, sys, time, zlib

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
sys.path.insert(0, os.path.join(REPO, 'tests', 'json-sync', 'http'))
sys.path.insert(0, os.path.join(REPO, 'tests', 'features', 'http'))

import board  # noqa: E402
from front import Front, post_key  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''))
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, default=0)
p.add_argument('--pluginlibrary-file', default=os.environ.get('PLUGINLIBRARY', ''))
p.add_argument('--matrix-out', default='')
p.add_argument('--keep', action='store_true', help='leave the PHP server running (debugging only)')
a = p.parse_args()

if not a.mybb or not os.path.isdir(a.mybb):
    sys.exit('give --mybb: a MyBB 1.9 source tree with its vendor/ folder')

PL_FILE = a.pluginlibrary_file or os.path.join(REPO, '..', '_deps', 'MyBB-PluginLibrary', 'inc', 'plugins', 'pluginlibrary.php')
if not os.path.isfile(PL_FILE):
    sys.exit('PluginLibrary not found: ' + PL_FILE)

os.makedirs(a.scratch, exist_ok=True)
PORT = a.port or {'sqlite': 18390, 'mysql': 18391, 'pgsql': 18392}[a.engine]
DBNAME = 'msc_test_' + ''.join(random.choice(string.ascii_lowercase + string.digits) for _ in range(10))
ROUTE = 'fieldkit'
FIELDKIT = json.load(open(os.path.join(REPO, 'tests', 'fields', 'collections', 'fieldkit.json')))
FIELDS = {f['field_key']: f for f in FIELDKIT['fields']}
NOT_RUN = {
    'a': 'ACP definition stage not automated yet (remaining step: read each field through the ACP edit screens and save one property)',
    'f': 'main list stage not automated yet (remaining step: sort and filter on each offered field)',
    'k': 'reserved and long key stage not automated yet (remaining step: JSON-sync preview and run for each key on each engine)',
}
LONG = 'x' * 21
PAYLOAD = '<script>alert(1)</script>'
CTX = None  # the board context, set by main()
PROPERTY_COLUMNS = ['field_label', 'description', 'placeholder', 'html_type', 'field_type', 'file_capture', 'allow_multiple_values',
                    'regular_expression', 'input_pattern', 'display_in_create_update_page', 'display_in_view_page',
                    'display_in_main_page', 'minimum_length', 'maximum_length', 'minimum_size', 'maximum_size', 'step_size',
                    'input_size', 'allowed_groups_fill', 'allowed_groups_view', 'default_value', 'default_type',
                    'display_order', 'render_order', 'format', 'filter_on_save', 'is_enabled', 'is_required', 'parser_enable',
                    'parser_allow_html', 'parser_allow_mycode', 'parser_allow_smiles', 'parser_allow_image_code',
                    'parser_allow_video_code', 'parser_affects_message_length', 'enable_search', 'enable_slug',
                    'enable_subject', 'enable_description', 'enable_editor', 'read_only']
DATA = ''   # the data table of the showcase, set by main()
PNG = None  # bytes of the upload used by the file fields
BOARD_DIR = ''   # the scratch board of the run
LOG_OFFSET = 0   # the size of the board's error.log when the matrix starts
COLUMN_TYPES = {}  # column name -> the engine's column type (written to the output)
FIELD_IDS = {}


def esc(value):
    """MyBB's escaping (htmlspecialchars_uni): what a correctly escaped value looks like in the page."""
    return value.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;').replace("'", '&#039;')


# ---- the cases ---------------------------------------------------------------------------------------------------------
# (name, value, expectation, extra)
#   accept    an entry is created and the field stores the value (when the value is not empty)
#   refuse    no entry is created
#   stored    an entry is created and the field keeps extra (the value the definition gives; the sent value is ignored)
#   either    an entry is created and the field stores the value or the value trimmed (refusal also passes)
#   nonempty  an entry is created and the field is not empty (a UUID default)
#   multi     several boxes checked: refused, or stored as the complete list "a,b" (anything else is a corruption)
#   upload    a file field: the value 'PNG' means one file is uploaded to the field, None means none
def cases():
    o = option_ids
    return {
        'fk_text_req': [('valid', 'Hello', 'accept', None), ('blank required', '', 'refuse', None),
                        ('required zero is a value, refused for its length, not as missing', '0', 'refuse_not_missing', None),
                        ('too short', 'a', 'refuse', None), ('too long', LONG, 'refuse', None),
                        ('multibyte UTF-8', 'héllo wörld', 'accept', None),
                        ('markup and quotes', '<b>a</b>"\'\\', 'accept', None),
                        ('emoji (4-byte UTF-8)', '😀😀😀😀', 'accept', None),
                        ('leading and trailing spaces', '  pad  ', 'either', None),
                        ('longer than the maximum, with markup', PAYLOAD, 'refuse', None)],
        'fk_text_opt': [('blank optional', '', 'accept', ''), ('valid', 'Hello', 'accept', None),
                        ('too long', 'y' * 31, 'refuse', None)],
        'fk_text_min1': [('blank optional, minimum 1', '', 'accept', ''), ('valid', 'Series', 'accept', None)],
        'fk_text_pattern': [('blank optional with pattern', '', 'accept', ''), ('matching', 'abc', 'accept', None),
                            ('not matching', 'ABC1', 'refuse', None)],
        'fk_text_default': [('valid', 'Changed', 'accept', None)],
        'fk_text_uuid': [('blank with UUID default', '', 'nonempty', None)],
        'fk_text_readonly': [('value sent for a read-only field', 'changed', 'stored', 'locked')],
        'fk_text_group': [('value sent by a user outside the group', 'hidden', 'stored', '')],
        'fk_textarea_parse': [('multiline with markup', 'line one\n' + PAYLOAD + '\n  line three  ', 'accept', None),
                              ('too long', 'z' * 2001, 'refuse', None)],
        'fk_textarea_plain': [('multiline with markup', 'one\n<img src=x onerror=alert(1)>', 'accept', None),
                              ('too long', 'z' * 2001, 'refuse', None)],
        'fk_email': [('valid', 'someone@example.com', 'accept', None), ('not an address', 'not-an-address', 'refuse', None)],
        'fk_url': [('valid', 'https://example.com/x', 'accept', None), ('script scheme', 'javascript:alert(1)', 'refuse', None)],
        'fk_tel': [('valid', '+1 555-0100', 'accept', None)],
        'fk_password': [('valid', 'secret123', 'accept', None)],
        'fk_search': [('valid', 'query text', 'accept', None)],
        'fk_number': [('zero', '0', 'accept', None), ('maximum', '100', 'accept', None), ('negative', '-1', 'refuse', None),
                      ('above maximum', '101', 'refuse', None), ('not a number', 'abc', 'refuse', None),
                      ('blank optional', '', 'accept', ''), ('very large integer', '99999999999999999999', 'refuse', None)],
        'fk_number_req': [('blank required', '', 'refuse', None), ('below minimum', '0', 'refuse', None),
                          ('zero is a value: refused for the minimum, not as missing', '0', 'refuse_not_missing', None),
                          ('above maximum', '11', 'refuse', None), ('valid', '5', 'accept', None)],
        'fk_range': [('valid', '50', 'accept', None), ('above maximum', '55', 'refuse', None), ('blank optional', '', 'accept', '')],
        'fk_rating': [('valid', '5', 'accept', None), ('not a number', 'abc', 'refuse', None)],
        'fk_poll': [('valid', '5', 'accept', None)],
        'fk_date': [('valid', '2026-11-13', 'accept', None), ('invalid date', '2026-13-45', 'refuse', None)],
        'fk_datetime': [('valid', '2026-11-13T10:00', 'accept', None), ('invalid', 'garbage', 'refuse', None)],
        'fk_time': [('valid', '10:30', 'accept', None), ('invalid', '25:00', 'refuse', None)],
        'fk_month': [('valid', '2026-11', 'accept', None), ('invalid', 'bad', 'refuse', None)],
        'fk_week': [('valid', '2026-W46', 'accept', None), ('invalid', 'bad', 'refuse', None)],
        'fk_color': [('valid', '#ff0000', 'accept', None), ('name instead of hex', 'red', 'refuse', None)],
        'fk_select': [('valid option', o['fk_select'][0], 'accept', None), ('option id that does not exist', '999', 'refuse', None),
                      ('option of another field', o['fk_radio'][0], 'refuse', None), ('not a number', 'abc', 'refuse', None),
                      ('blank optional', '', 'accept', ''),
                      ('restricted option, outside its group', str(RESTRICTED_OPTION.get('fk_select', 0)), 'refuse', None)],
        'fk_select_req': [('blank required', '', 'refuse', None), ('valid option', o['fk_select_req'][1], 'accept', None)],
        'fk_radio': [('valid option', o['fk_radio'][0], 'accept', None), ('option id that does not exist', '999', 'refuse', None),
                     ('blank optional', '', 'accept', '')],
        'fk_checkbox': [('one checked', o['fk_checkbox'][0], 'accept', None),
                        ('several checked', [o['fk_checkbox'][0], o['fk_checkbox'][1]], 'multi', None),
                        ('option id that does not exist', '999', 'refuse', None)],
        'fk_users': [('valid user id', '1', 'accept', None), ('not a number', 'abc', 'refuse', None)],
        'fk_entries': [('not a number', 'abc', 'refuse', None)],
        'fk_threads': [('not a number', 'abc', 'refuse', None)],
        'fk_file_req': [('no file', None, 'refuse', None), ('one PNG', 'PNG', 'accept', None)],
        'fk_file': [('no file', None, 'accept', None), ('one PNG', 'PNG', 'accept', None)],
    }


# the option ids of each select, radio and checkbox field, in the order of the form (set in main)
option_ids = {}

# the id of the restricted option added to the select for the run (set in main): a group outside it must be refused
RESTRICTED_OPTION = {}

# the values of a valid entry: every required field is filled, every other field is valid or blank
BASE = {'fk_text_req': 'Base', 'fk_number_req': '5', 'fk_text_pattern': 'abc', 'fk_text_min1': 'Series', 'fk_text_opt': 'Opt',
        'fk_date': '2026-11-13', 'fk_datetime': '2026-11-13T10:00', 'fk_time': '10:30', 'fk_month': '2026-11',
        'fk_week': '2026-W46', 'fk_color': '#ff0000', 'fk_email': 'a@example.com', 'fk_url': 'https://example.com/b',
        'fk_number': '7', 'fk_range': '10', 'fk_rating': '4', 'fk_poll': '4', 'fk_tel': '+1 555-0100'}

def check(cells, key, stage, result, detail=''):
    cells[f'{key}|{stage}'] = {'result': result, 'detail': str(detail)}


def accumulate(cells, key, stage, ok, detail):
    """One cell over several cases: it passes only when every case passes, and keeps the detail of each case."""
    previous = cells.get(f'{key}|{stage}')
    if previous is None or previous['result'] == 'na':
        check(cells, key, stage, 'pass' if ok else 'fail', detail)
        return
    check(cells, key, stage, 'pass' if (previous['result'] == 'pass' and ok) else 'fail', previous['detail'] + ' | ' + detail)


def png_bytes():
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 1, 1, 8, 2, 0, 0, 0)) +
            chunk(b'IDAT', zlib.compress(b'\x00\xff\x00\x00')) + chunk(b'IEND', b''))


def query(sql):
    return CTX.query(sql)



def column_types(table):
    """The column type of every column of the data table, as the engine reports it."""
    if a.engine == 'sqlite':
        connection = sqlite3.connect(os.path.join(BOARD_DIR, 'inc', 'board.db'))
        rows = [(r[1], r[2]) for r in connection.execute(f'pragma table_info({table})')]
        connection.close()
    elif a.engine == 'mysql':
        rows = query(f"select column_name, column_type from information_schema.columns where table_schema=database() and table_name='{table}'")
    else:
        rows = query(f"select column_name, data_type || coalesce('(' || character_maximum_length || ')', '') from information_schema.columns "
                     f"where table_schema='public' and table_name='{table}'")
    return {name: ctype for name, ctype in rows}


def hex_of(column):
    """The column as hexadecimal UTF-8 bytes, whatever the engine: the value read back is exact (newlines, backslashes and
    quotes survive the command-line clients, which escape them in their own output)."""
    if a.engine == 'pgsql':
        return f"encode(convert_to(coalesce({column}::text,''),'UTF8'),'hex')"
    return f"HEX(CAST({column} AS CHAR))" if a.engine == 'mysql' else f"hex({column})"


def stored_row(token):
    """The values of the entry whose subject is the token (every field column), or None when no entry has it."""
    columns = ['public_id', 'entry_slug_custom', 'subject'] + list(FIELDS)
    rows = query(f"select {', '.join(hex_of(c) for c in columns)} from {DATA} where subject='{token}'")
    if not rows:
        return None
    return {c: ('' if v in (None, '', 'NULL') else bytes.fromhex(v).decode('utf-8', errors='replace')) for c, v in zip(columns, rows[0])}


def form_fields(body):
    """The post_hash of a create form."""
    post_hash = re.search(r'name="post_hash" value="([^"]+)"', body)
    return post_hash.group(1) if post_hash else ''


def upload(client, post_hash, field_key):
    """The attachment route of the entry form: one PNG for the field, sent as the form's uploader sends it."""
    form = client.get(f'/showcase.php/{ROUTE}/create')
    token = post_key(form['body'])
    fid = FIELD_IDS[field_key]
    up = client.upload(f'/showcase.php/{ROUTE}/update/{post_hash}/upload?my_post_key={token}&field_id={fid}',
                       {'my_post_key': token, 'field_id': fid}, field_key, os.path.join(a.scratch, 'matrix.png'))
    return up['status'], up['body']


def create(client, overrides, token, uploads):
    """Posts the create form: BASE with the overrides, one subject token. uploads: field key -> True to upload one PNG."""
    form = client.get(f'/showcase.php/{ROUTE}/create')
    post_hash = form_fields(form['body'])
    key = post_key(form['body'])
    data = {'subject': token, 'message': f'Matrix entry {token}', 'post_hash': post_hash, 'my_post_key': key, 'submit': 'Post'}
    data.update(BASE)
    data['fk_checkbox[]'] = [option_ids['fk_checkbox'][0]]
    data['fk_radio'] = option_ids['fk_radio'][0]
    data['fk_select'] = option_ids['fk_select'][0]
    data['fk_select_req'] = option_ids['fk_select_req'][0]
    for field_key, wanted in uploads.items():
        if wanted:
            upload_status, upload_body = upload(client, post_hash, field_key)
            if upload_status != 200:
                raise RuntimeError(f'upload to {field_key} answered {upload_status}: {upload_body[:300]}')
    for name, value in overrides.items():
        if name == 'fk_checkbox':
            data['fk_checkbox[]'] = value if isinstance(value, list) else [value]
            data.pop('fk_checkbox', None)
        else:
            data[name] = value
    r = client.post(f'/showcase.php/{ROUTE}/create', data)
    text_out = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', r['body']))
    error = re.search(r'(errors before continuing|an error|errors were|SQL Error|internal error)[^|]{0,240}', text_out)
    return {'status': r['status'], 'error': error.group(0) if error else '', 'body': r['body'], 'url': r.get('url', '')}


def check_entry_page(client, cells, key, row, value, expectation, stored_text):
    """Stages e and l: the stored value is shown escaped, option fields show the label of the option, and no payload is
    printed raw. value is what the form sent (a list for checkboxes)."""
    f = FIELDS[key]
    page = client.get(f'/showcase.php/{ROUTE}/view/{row["public_id"]}/{row["entry_slug_custom"]}')
    body = page['body']
    if page['status'] != 200:
        accumulate(cells, key, 'e', False, f'entry page answered {page["status"]}: {page_problem(page)}')
        return
    problems = []
    if f['html_type'] in ('select', 'radio', 'checkbox'):
        sent = value if isinstance(value, list) else [value]
        for item in sent:
            if item in option_ids[key]:
                label = f['options'][option_ids[key].index(item)]['value']
                if esc(label) not in body:
                    problems.append(f'label of option {label!r} not shown')
    elif stored_text not in ('', None):
        shown = html_text(body)
        for segment in str(stored_text).split('\n'):
            if segment.strip() and esc(segment.strip()) not in body and segment.strip() not in shown:
                problems.append(f'value {segment.strip()!r} not shown')
    for segment in str(value).split('\n') if not isinstance(value, list) else []:
        if '<' in segment and segment.strip() and segment.strip() in body:
            problems.append(f'markup {segment.strip()!r} printed raw')
    accumulate(cells, key, 'e', not problems, f'{value!r}: ' + ('; '.join(problems) or 'shown as stored, escaped'))
    if '<' in str(value):
        accumulate(cells, key, 'l', not problems, f'{value!r}: ' + ('printed raw' if problems else 'escaped on the entry page'))

def page_problem(page):
    """The message MyBB printed on an error page (the text of the first error box), for the report."""
    text_out = html_text(page['body'])
    found = re.search(r'(SQL Error|internal error|Error Message|Board Message|do not have permission)[^|]{0,240}', re.sub(r'\s+', ' ', text_out))
    return found.group(0) if found else re.sub(r'\s+', ' ', text_out)[:200]


def html_text(body):
    """The page with its markup removed and its entities decoded: what a reader sees."""
    return htmllib.unescape(re.sub(r'<[^>]+>', ' ', re.sub(r'<script.*?</script>', ' ', body, flags=re.S)))


def same(stored, sent):
    """The stored value is the sent one, allowing the database's own form of a date or time."""
    if stored is None:
        return False
    s, v = stored.replace('T', ' '), sent.replace('T', ' ')
    return s == v or s.startswith(v) or v.startswith(s)


def record_case(key, name, expectation, extra, value, accepted, row, extra_error=''):
    """The verdict of one validation case and the text of its detail."""
    stored = row.get(key) if row else None
    option_type = FIELDS[key]['html_type'] in ('select', 'radio', 'checkbox')
    text_value = value if isinstance(value, str) else None
    if expectation == 'accept':
        ok = accepted and (text_value in (None, '') or option_type or same(stored, text_value))
    elif expectation == 'refuse':
        ok = not accepted
    elif expectation == 'stored':
        ok = accepted and stored == extra
    elif expectation == 'either':
        ok = (not accepted) or stored in (value, value.strip())
    elif expectation == 'nonempty':
        ok = accepted and stored not in (None, '')
    elif expectation == 'multi':
        ok = (not accepted) or stored == ','.join(value)
    elif expectation == 'refuse_not_missing':
        ok = not accepted and 'is missing' not in extra_error
    else:
        ok = False
    detail = f'{name}: sent {value!r}; ' + (f'accepted, stored {stored!r}' if accepted else 'refused')
    return ok, detail

def run_matrix(client, cells, users, restricted):
    # ---- stage b: the create form, as the member and as a user outside the restricted group ----
    form = client.get(f'/showcase.php/{ROUTE}/create')
    body = form['body']
    for key, f in FIELDS.items():
        if key == 'fk_text_group':
            continue
        m = re.search(rf'<(input|textarea|select)\b[^>]*name="{re.escape(key)}(\[\])?"[^>]*>', body)
        if not m:
            check(cells, key, 'b', 'fail', 'input not in the form')
            continue
        tag = m.group(0)
        problems = []
        if f['is_required'] and 'required' not in tag:
            problems.append('required marker missing')
        if f.get('read_only') and 'readonly' not in tag:
            problems.append('read only not shown')
        if f.get('regular_expression') and 'pattern=' not in tag:
            problems.append('pattern not shown')
        if f['html_type'] in ('number', 'range') and f.get('maximum_size') is not None and f'max="{f["maximum_size"]}"' not in tag:
            problems.append(f'max attribute does not match the maximum {f["maximum_size"]}')
        if f['html_type'] == 'number' and f.get('minimum_size') and 'min=' not in tag:
            problems.append('minimum not shown')
        if f.get('default_value') and f['html_type'] not in ('textarea',) and f'value="{f["default_value"]}"' not in tag:
            problems.append('default value not pre-filled')
        if f.get('default_type') in (2, 3) and not re.search(r'value="[^"]+"', tag):
            problems.append('generated default (UUID or current timestamp) not pre-filled')
        if f['html_type'] in ('select', 'radio', 'checkbox'):
            labels = [o['value'] for o in f['options']]
            for label in labels:
                if esc(label) not in body:
                    problems.append(f'option label {label!r} missing')
                    break
        check(cells, key, 'b', 'pass' if not problems else 'fail', '; '.join(problems) or 'rendered as defined')
    outsider = Front(client.base)
    outsider.login('fkrestricted', users['users']['fkrestricted']['password'])
    seen = 'name="fk_text_group"' in outsider.get(f'/showcase.php/{ROUTE}/create')['body']
    member_hidden = 'name="fk_text_group"' not in body
    check(cells, 'fk_text_group', 'b', 'pass' if (member_hidden and seen) else 'fail',
          f'hidden from the member: {member_hidden}; shown to the restricted user: {seen}')

    # ---- stages c, d, e, l: validation, storage, the entry page ----
    serial = 0
    for key, case_list in cases().items():
        for name, value, expectation, extra in case_list:
            serial += 1
            token = f'FK{serial:03d}'
            overrides = {}
            uploads = {'fk_file_req': True, 'fk_file': False}  # the required file is uploaded unless the case says otherwise
            if key.startswith('fk_file'):
                uploads[key] = value == 'PNG'
            elif value is not None:
                overrides[key] = value
            response = create(client, overrides, token, uploads)
            row = stored_row(token)
            accepted = row is not None
            ok, detail = record_case(key, name, expectation, extra, value, accepted, row or {}, response['error'] or '')
            detail += '' if response['status'] in (200, 302) else f'; HTTP {response["status"]}'
            if response['error'] and not accepted:
                detail += f'; message: {response["error"][:160]}'
            accumulate(cells, key, 'c', ok, detail)
            if accepted:
                accumulate(cells, key, 'd', True, f'{name}: stored {row.get(key)!r}')
                if not key.startswith('fk_file'):
                    check_entry_page(client, cells, key, row, value, expectation, row.get(key))
                else:
                    check(cells, key, 'e', 'na', 'file field: the entry page is not compared in this run')
            elif cells.get(f'{key}|d') is None:
                check(cells, key, 'd', 'na', 'no entry stored')
                check(cells, key, 'e', 'na', 'no entry stored')

    # ---- stage g: the edit round trip of a valid entry ----
    serial += 1
    token = f'FK{serial:03d}'
    create(client, {}, token, {'fk_file_req': True, 'fk_file': False})
    row = stored_row(token)
    if row is None:
        for key in FIELDS:
            check(cells, key, 'g', 'fail', 'no baseline entry to edit')
        return
    edit_round_trip(client, cells, row)


def edit_round_trip(client, cells, row):
    """Stage g: every field is pre-filled with its stored value, and a save without change keeps every value."""
    edit = client.get(f'/showcase.php/{ROUTE}/update/{row["public_id"]}')
    body = edit['body']
    if edit['status'] != 200:
        for key in FIELDS:
            check(cells, key, 'g', 'fail', f'edit form answered {edit["status"]}: {page_problem(edit)}')
        return
    prefilled = {}
    for key, f in FIELDS.items():
        if key.startswith('fk_file') or key == 'fk_text_group':
            continue
        stored = row.get(key) or ''
        ok = True
        if f['html_type'] in ('select', 'radio', 'checkbox'):
            index = None
            if stored:
                for position, item in enumerate(option_ids[key]):
                    if stored in (item, str(position + 1)):
                        index = item
                if index is None:
                    ok = False
                elif not re.search(rf'value="{index}"[^>]*(checked|selected)|(selected|checked)[^>]*value="{index}"', body):
                    ok = False
        elif stored and esc(stored) not in body and stored not in body:
            ok = False
        prefilled[key] = ok
    data = {'subject': row['subject'], 'message': 'edited', 'my_post_key': post_key(body), 'submit': 'Post'}
    data.update(BASE)
    data['fk_checkbox[]'] = [option_ids['fk_checkbox'][0]]
    data['fk_radio'] = option_ids['fk_radio'][0]
    data['fk_select'] = option_ids['fk_select'][0]
    data['fk_select_req'] = option_ids['fk_select_req'][0]
    data['fk_text_req'] = row['fk_text_req']
    post_hash = re.search(r'name="post_hash" value="([^"]+)"', body)
    if post_hash:
        data['post_hash'] = post_hash.group(1)
    client.post(f'/showcase.php/{ROUTE}/update/{row["public_id"]}', data)
    after = stored_row(row['subject'])
    for key, f in FIELDS.items():
        if key.startswith('fk_file') or key == 'fk_text_group':
            check(cells, key, 'g', 'na', 'file field or restricted field: not compared in this run')
            continue
        kept = after is not None and (after.get(key) or '') == (row.get(key) or '')
        ok = prefilled.get(key, False) and kept
        detail = ('pre-filled; ' if prefilled.get(key) else 'not pre-filled; ') + \
                 ('kept by an unchanged save' if kept else f'changed by an unchanged save: {row.get(key)!r} -> {after and after.get(key)!r}')
        check(cells, key, 'g', 'pass' if ok else 'fail', detail)

def stored_rows(token):
    """Every stored copy of the entries whose subject is the token (an import makes a second copy)."""
    columns = ['public_id', 'entry_slug_custom', 'subject'] + list(FIELDS)
    rows = query(f"select {', '.join(hex_of(c) for c in columns)} from {DATA} where subject='{token}' order by entry_id")
    return [{c: ('' if v in (None, '', 'NULL') else bytes.fromhex(v).decode('utf-8', errors='replace')) for c, v in zip(columns, r)}
            for r in rows]


def entry_ids(token):
    """The entry ids of every stored copy of the token, the original first."""
    return [int(r[0]) for r in query(f"select entry_id from {DATA} where subject='{token}' order by entry_id")]


def files_of(entry_id, field_key):
    """The stored attachments of one file field of one entry: public_id, attachment_name (the display name), file_name (the stored path), mime_type, file_size."""
    return query(f"select public_id, attachment_name, file_name, mime_type, file_size from mybb_myshowcase_attachments "
                 f"where entry_id={int(entry_id)} and field_id={FIELD_IDS[field_key]} and status=1 order by attachment_id")


def fetch_file(member, file_row):
    """The bytes of a stored file through the download route. The export writes the public form <route>/attachment/<id>/<name>,
    which the built-in server does not rewrite: the request goes through the front controller, the same route handler."""
    got = member.get(f'/showcase.php/{ROUTE}/attachment/{file_row[0]}/{file_row[1]}')
    return got['status'], got['raw']


def file_problems(member, key, tokens):
    """Stage i, file fields: each imported copy holds the same stored file as its original (bytes, name, MIME type and size,
    through the download route), the same field link, and its entry page shows the file's name."""
    problems = []
    for token in tokens:
        ids = entry_ids(token)
        if len(ids) != 2:
            problems.append(f'{token}: {len(ids)} entries after the import')
            continue
        left, right = files_of(ids[0], key), files_of(ids[1], key)
        if len(left) != len(right):
            problems.append(f'{token}: {len(left)} stored file(s) became {len(right)}')
            continue
        for original, copy in zip(left, right):
            status_a, body_a = fetch_file(member, original)
            status_b, body_b = fetch_file(member, copy)
            if status_a != 200 or status_b != 200:
                problems.append(f'{token}: download answered {status_a} and {status_b}')
            elif hashlib.sha256(body_a).digest() != hashlib.sha256(body_b).digest():
                problems.append(f'{token}: bytes differ')
            shown = lambda row: (row[1], row[3], row[4])  # the display name, MIME type and size (not the random stored path)
            if shown(original) != shown(copy):
                problems.append(f'{token}: file name, type or size {shown(original)} became {shown(copy)}')
        for entry_id, row in zip(ids, stored_rows(token)):
            if not files_of(entry_id, key):
                continue
            page = member.get(f'/showcase.php/{ROUTE}/view/{row["public_id"]}/{row["entry_slug_custom"]}')
            name = files_of(entry_id, key)[0][1]
            if page['status'] != 200 or name not in page['body']:
                problems.append(f'{token}: entry page answered {page["status"]} without the file {name!r}')
    return problems


def stage_i(member, cells, users, uid, fixture):
    """Stage i: the XML export of valid entries, imported into the same showcase. Every field of every copy must hold the
    value it held. Throwaway data: the entries of the matrix are removed first, the round trip uses valid entries only."""
    CTX.execute(f"delete from {DATA}")
    tokens = ['XML1', 'XML2', 'XML3']
    for token in tokens:
        create(member, {}, token, {'fk_file_req': True, 'fk_file': False})
    originals = {t: stored_rows(t) for t in tokens}
    if not all(len(originals[t]) == 1 for t in tokens):
        for key in FIELDS:
            check(cells, key, 'i', 'fail', f'the valid entries were not created: {originals}')
        return
    export_form = member.get(f'/showcase.php/{ROUTE}/export')
    member.post(f'/showcase.php/{ROUTE}/export', {'my_post_key': post_key(export_form['body']), 'limit': '0',
                                                 'order_by': 'dateline', 'order_dir': 'asc', 'submit': 'Export'})
    export_id = query(f"select export_id from mybb_myshowcase_exports where user_id={uid} order by export_id desc limit 1")
    if not export_id:
        for key in FIELDS:
            check(cells, key, 'i', 'fail', 'no export was stored')
        return
    download = member.get(f'/showcase.php/{ROUTE}/export/{export_id[0][0]}/download')
    path = os.path.join(a.scratch, 'export.xml')
    open(path, 'wb').write(download['raw'])
    import_form = member.get(f'/showcase.php/{ROUTE}/import')
    reply = member.upload(f'/showcase.php/{ROUTE}/import', {'my_post_key': post_key(import_form['body']), 'action': 'import'},
                          'entries_file', path)
    report = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', reply['body']))
    open(os.path.join(a.scratch, 'import-reply.html'), 'w').write(reply['body'])
    summary = re.search(r'Entries imported \d+|Failed: [^.]{0,120}|Problems with the file [^.]{0,200}', report)
    summary = summary.group(0) if summary else 'no import report found'
    for key, f in FIELDS.items():
        if key.startswith('fk_file'):
            problems = file_problems(member, key, tokens)
            check(cells, key, 'i', 'pass' if not problems else 'fail',
                  '; '.join(problems) or f'every copy holds the same stored file (bytes, name, type, size, entry page); {summary}')
            continue
        problems = []
        for token in tokens:
            copies = stored_rows(token)
            if len(copies) != 2:
                problems.append(f'{token}: {len(copies)} copies after the import')
                continue
            original, imported = copies[0], copies[1]
            if (original.get(key) or '') != (imported.get(key) or ''):
                problems.append(f'{token}: {original.get(key)!r} became {imported.get(key)!r}')
        check(cells, key, 'i', 'pass' if not problems else 'fail', '; '.join(problems) or f'every copy holds the same value; {summary}')
        if problems:
            cells[f'{key}|i']['detail'] += f' | import: {summary}'

    # the setting OFF (decision D57): the same file, which holds file URLs, is refused as a whole and adds no entry
    before = {t: len(stored_rows(t)) for t in tokens}
    fixture('external-setting', 0)
    time.sleep(3)
    reply_off = member.upload(f'/showcase.php/{ROUTE}/import', {'my_post_key': post_key(import_form['body']), 'action': 'import'},
                              'entries_file', path)
    report_off = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', reply_off['body']))
    summary_off = re.search(r'Entries imported \d+|Failed: [^.]{0,120}|Problems with the file [^.]{0,200}|[^.]{0,80}external[^.]{0,120}',
                            report_off)
    summary_off = summary_off.group(0) if summary_off else 'no import report found'
    after_off = {t: len(stored_rows(t)) for t in tokens}
    refused = after_off == before
    note = (f'setting OFF: the same file refused as a whole, no entry added; reply: {summary_off}' if refused else
            f'setting OFF: NOT refused as a whole (copies {before} -> {after_off}); reply: {summary_off}')
    for key in ('fk_file', 'fk_file_req'):
        cells[f'{key}|i']['detail'] += ' | ' + note
        if not refused:
            cells[f'{key}|i']['result'] = 'fail'


# a bare apostrophe is in every page's scripts, so only the two markup payloads are searched raw
PAYLOADS = ['<script>alert(1)</script>', '"><img src=x onerror=alert(1)>']


def stage_l(member, cells, data_table):
    """Stage l: the places where a value is printed, checked with the payloads. The entry carries each payload in a text
    field and an option label is not changed; a printed payload that is not escaped is a failure."""
    payload_text = ' / '.join(PAYLOADS) + " / '"  # the apostrophe is sent too: the page must show it escaped
    token = 'PAY1'
    create(member, {'fk_textarea_parse': payload_text, 'fk_text_opt': '<b>x</b>"', 'subject': token}, token, {'fk_file_req': True})
    row = stored_row(token)
    if row is None:
        for place in ('edit-form', 'main-list'):
            check(cells, f'place:{place}', 'l', 'fail', 'the payload entry was not created')
        return
    edit = member.get(f'/showcase.php/{ROUTE}/update/{row["public_id"]}')
    main = member.get(f'/showcase.php/{ROUTE}')
    for place, page in (('edit-form', edit), ('main-list', main)):
        body = page['body']
        raw = [p for p in PAYLOADS if p in body]
        check(cells, f'place:{place}', 'l', 'pass' if (page['status'] == 200 and not raw) else 'fail',
              f'HTTP {page["status"]}; raw payloads printed: {raw}' if raw or page['status'] != 200 else 'every payload is escaped')


def stage_j(acp, cells):
    """Stage j: the round trip of the collection file. The ACP exports the collection; the exported file replaces the
    collection's file in the board and is applied again. Every stored property of every field is compared before and after;
    the export format leaves out the properties that hold the column default (the importer takes them as the default)."""
    cid = query("select collection_id from mybb_myshowcase_collections where name='FieldKit'")[0][0]
    props = PROPERTY_COLUMNS

    def snapshot():
        rows = query(f"select field_key, {', '.join(props)} from mybb_myshowcase_fields where collection_id={cid} order by field_key")
        return {r[0]: dict(zip(props, r[1:])) for r in rows}

    before = snapshot()
    exported = acp.get(f'/admin/index.php?module=myshowcase-json_sync&action=export&type=collection&id={cid}&my_post_key={acp.post_key}')
    exported_ok = exported['status'] == 200 and exported['body'].lstrip().startswith('{')
    file_path = os.path.join(CTX.board, 'inc', 'plugins', 'myshowcase', 'imports', 'JSON', 'collections', 'fieldkit.json')
    open(os.path.join(a.scratch, 'collection-export.json'), 'w').write(exported['body'])
    if exported_ok:
        open(file_path, 'w').write(exported['body'])
    acp.post('/admin/index.php?module=myshowcase-json_sync', {'action': 'run', 'mode': 'apply', 'type': 'collection',
                                                              'id': str(cid), 'file': 'collections/fieldkit.json'})
    after = snapshot()
    for key in FIELDS:
        if not exported_ok:
            check(cells, key, 'j', 'fail', f'the export answered {exported["status"]}')
            continue
        diffs = [f'{p}: {before.get(key, {}).get(p)!r} -> {after.get(key, {}).get(p)!r}'
                 for p in props if before.get(key, {}).get(p) != after.get(key, {}).get(p)]
        # the export writes a group restriction only as "all": a restriction to other groups is not in the file
        exported_entry = next((f for f in json.loads(exported['body'])['fields'] if f['field_key'] == key), {})
        for column in ('allowed_groups_fill', 'allowed_groups_view'):
            stored = before.get(key, {}).get(column)
            if stored not in (None, '-1', 'all') and exported_entry.get(column) != 'all':
                diffs.append(f'{column} {stored!r} is not in the exported file (it would be lost on another board)')
        check(cells, key, 'j', 'pass' if not diffs else 'fail', '; '.join(diffs) or 'every stored property is unchanged')


def stage_h(member, acp, cells, ctx, cid, fixture):
    """Stage h: deletion and data type change, on throwaway data (the matrix's entries are removed first; this stage runs last).
    A  an entry is deleted through the entry page's moderation link (delete=2): its row and attachments go, the others stay.
    B  an option that entries use is deleted in the ACP: the option goes, the stored ids stay, the entry page still renders.
    D  types changed through the ACP's JSON sync, one change at a time: a shrink that loses values, a text-to-number change
       that loses values and a number-to-text change that loses nothing. A change with a loss must be refused as a whole.
    C  a field is deleted in the ACP. The ACP refuses a field a JSON file manages; that refusal is checked first, then the same
       field is unmanaged as data (the detach of a file, decision in the README) and deleted; only its column may go.
    Every operation is checked against every field it touches; a failure is recorded, never raised."""
    ops = {}

    def note(field, op, ok, detail):
        ops.setdefault(field, []).append((op, bool(ok), str(detail)))

    def columns_now():
        return column_types(DATA)

    CTX.execute(f"delete from {DATA}")
    for token in ('H1', 'H2'):
        create(member, {}, token, {'fk_file_req': True, 'fk_file': False})
    first, second = stored_row('H1'), stored_row('H2')
    if first is None or second is None:
        for key in FIELDS:
            note(key, 'setup', False, 'the two entries were not created')
    else:
        ids = {t: entry_ids(t)[0] for t in ('H1', 'H2') if entry_ids(t)}
        # ---- A: the owner deletes the first entry through the moderation link of its page; the test data gives the
        # member group the delete permission of the showcase (the admin account has no row of its own: a 403)
        sid = query(f"select showcase_id from mybb_myshowcase_config where route_identifier='{ROUTE}'")[0][0]
        ctx.execute(f"update mybb_myshowcase_permissions set entries_can_delete=1 where showcase_id={sid} and group_id=2")
        fixture('rebuild')
        listing = member.get(f'/showcase.php/{ROUTE}')
        deleted = member.post(f'/showcase.php/{ROUTE}?action=inline_moderation', {
            'my_post_key': post_key(listing['body']), 'moderate': 'delete', 'selected_all_entries': '0',
            'selected_entries': json.dumps([first['public_id']]), 'unselected_entries': '[]'})
        open(os.path.join(a.scratch, 'entry-delete.html'), 'w').write(f"list {listing['status']}; delete {deleted['status']}\n" + deleted['body'])
        gone = stored_row('H1') is None
        left = query(f"select count(*) from mybb_myshowcase_attachments where entry_id={ids['H1']}")[0][0]
        kept = stored_row('H2') == second
        answer = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', deleted['body']))
        message = re.search(r'Board Message (.{0,120})', answer)
        check(cells, 'place:entry-delete', 'h', 'pass' if (gone and kept and left == '0') else 'fail',
              f'delete answered {deleted["status"]}: {message.group(1) if message else "no board message"}; '
              f'first entry gone: {gone}; its attachments left: {left}; second entry unchanged: {kept}')

        # ---- B: delete an option the second entry uses ----
        opt = query(f"select field_data_id, value from mybb_myshowcase_field_data where field_id={FIELD_IDS['fk_select']} "
                    f"and value='Alpha'")
        if opt:
            fdid = opt[0][0]
            acp.post(f'/admin/index.php?module=myshowcase-fields&action=deleteOption&field_data_id={fdid}', {'yes': '1'})
            still = query(f"select count(*) from mybb_myshowcase_field_data where field_data_id={fdid}")[0][0]
            shown = member.get(f'/showcase.php/{ROUTE}/view/{second["public_id"]}/{second["entry_slug_custom"]}')
            stored_id = str(stored_row('H2').get('fk_select'))
            problems = []
            if still != '0':
                problems.append('the option row is still there')
            if shown['status'] != 200:
                problems.append(f'entry page answered {shown["status"]}: {page_problem(shown)}')
            if stored_id != str(fdid):
                problems.append(f'stored id {stored_id!r} changed from {fdid}')
            note('fk_select', 'B delete option in use', not problems,
                 '; '.join(problems) or f'option {fdid} removed; the entry keeps id {stored_id}; page answers {shown["status"]}; '
                                        f'label shown: {"Alpha" in html_text(shown["body"])}')
        else:
            note('fk_select', 'B delete option in use', False, 'the option Alpha was not found')

        # ---- D: type changes through the JSON sync, one at a time, the file restored after each ----
        collection_file = os.path.join(ctx.board, 'inc', 'plugins', 'myshowcase', 'imports', 'JSON', 'collections', 'fieldkit.json')
        original_text = open(collection_file).read()
        base_doc = json.loads(original_text)

        def change(field_key, new_props, label):
            doc = json.loads(original_text)
            for f in doc['fields']:
                if f['field_key'] == field_key:
                    f.update(new_props)
            open(collection_file, 'w').write(json.dumps(doc, indent=1))
            before_type = columns_now().get(field_key)
            before_values = {t: stored_row(t) for t in ('H1', 'H2') if stored_row(t)}
            reply = acp.post('/admin/index.php?module=myshowcase-json_sync', {'action': 'run', 'mode': 'apply', 'type': 'collection',
                                                                             'id': str(cid), 'file': 'collections/fieldkit.json'})
            open(os.path.join(a.scratch, f'apply-{field_key}.html'), 'w').write(reply['body'])
            message = re.sub(r'\s+', ' ', re.sub(r'<script.*?</script>', ' ', re.sub(r'<[^>]+>', ' ', reply['body']), flags=re.S))
            found = re.search(r'(loss|lost|cannot|not allowed|refus|problem|Problem|error[^a-z][^|]{0,160}|The field[^|]{0,160}|could not[^|]{0,160}|Saved|Updated|applied)', message)
            after_type = columns_now().get(field_key)
            after_values = {t: stored_row(t) for t in before_values}
            unchanged = before_values == after_values
            accepted = 'was applied' in message
            return {'label': label, 'before_type': before_type, 'after_type': after_type, 'unchanged': unchanged,
                    'accepted': accepted, 'message': found.group(0)[:220] if found else message[:160]}

        plans = [
            ('fk_text_req', {'maximum_length': 3}, 'D1 varchar(20) to varchar(3) with stored values of 4 characters (a loss)', True),
            ('fk_text_pattern', {'field_type': 'int', 'html_type': 'number'}, 'D2 text to number on stored text (a loss)', True),
            ('fk_number', {'field_type': 'varchar', 'html_type': 'text', 'maximum_length': 10}, 'D3 number to text (no loss)', False),
        ]
        original_types = columns_now()

        def apply_file(text_for_file):
            open(collection_file, 'w').write(text_for_file)
            reply = acp.post('/admin/index.php?module=myshowcase-json_sync', {'action': 'run', 'mode': 'apply', 'type': 'collection',
                                                                             'id': str(cid), 'file': 'collections/fieldkit.json'})
            return reply

        for field_key, props, label, expect_refusal in plans:
            start_type = columns_now().get(field_key)
            started_from_original = start_type == original_types.get(field_key)
            result = change(field_key, props, label)
            # restore the original definition, so that the next change starts from the original state (the file is restored
            # and applied again; the column type must return to its original type)
            restore_reply = apply_file(original_text)
            restore_text = re.sub(r'\s+', ' ', re.sub(r'<script.*?</script>', ' ', re.sub(r'<[^>]+>', ' ', restore_reply['body']), flags=re.S))
            restored_type = columns_now().get(field_key)
            restored = restored_type == original_types.get(field_key)
            restore_note = (f"restore: {'applied' if 'was applied' in restore_text else 'not applied'}; type back to "
                            f"{restored_type}: {restored}")
            if expect_refusal:
                # a change that loses values must be refused as a whole: no 'applied', the column and the entries unchanged
                ok = (not result['accepted']) and result['unchanged'] and result['after_type'] == result['before_type']
                detail = (f"{label}: the apply {'accepted it' if result['accepted'] else 'refused it'}; type {result['before_type']} -> "
                          f"{result['after_type']}; entries unchanged: {result['unchanged']}; start state original: {started_from_original}; "
                          f"{restore_note}; message: {result['message']}")
            else:
                ok = (result['accepted'] and result['after_type'] != result['before_type'] and
                      str(result['after_type']).lower().startswith(('varchar', 'character varying')) and result['unchanged'])
                detail = (f"{label}: the apply {'accepted it' if result['accepted'] else 'refused it'}; type {result['before_type']} -> "
                          f"{result['after_type']}; entries unchanged: {result['unchanged']}; start state original: {started_from_original}; "
                          f"{restore_note}; message: {result['message']}")
            ok = ok and started_from_original and restored
            note(field_key, 'D type change', ok, detail)
        open(collection_file, 'w').write(original_text)

        # ---- C: delete a field: refused while a JSON file manages it, then deleted once unmanaged ----
        fid = FIELD_IDS['fk_text_opt']
        blocked = acp.get(f'/admin/index.php?module=myshowcase-fields&action=delete_field&field_id={fid}&collection_id={cid}')
        still_there = 'fk_text_opt' in columns_now()
        note('fk_text_opt', 'C1 delete while managed', still_there and 'managed by' in blocked['body'],
             f'the column is kept: {still_there}; ACP answer {blocked["status"]}: '
             f'{"managed-by refusal shown" if "managed by" in blocked["body"] else "no managed-by text on the page"}')
        ctx.execute(f"update mybb_myshowcase_fields set json_file='' where field_key='fk_text_opt'")
        before_cols = set(columns_now())
        confirm = acp.get(f'/admin/index.php?module=myshowcase-fields&action=delete_field&field_id={fid}&collection_id={cid}')
        acp.post(f'/admin/index.php?module=myshowcase-fields&action=delete_field&field_id={fid}&collection_id={cid}', {'yes': '1'})
        after_cols = set(columns_now())
        removed = before_cols - after_cols
        rest_ok = query(f"select fk_text_req from {DATA} where subject='H2'") == [(second.get('fk_text_req', ''),)]
        note('fk_text_opt', 'C2 delete unmanaged field', removed == {'fk_text_opt'} and rest_ok,
             f'columns removed: {sorted(removed)}; other values unchanged: {rest_ok}; confirm page {confirm["status"]}')

    for key in FIELDS:
        steps = ops.get(key)
        if steps is None:
            check(cells, key, 'h', 'na', 'no delete or type change applies to this field')
            continue
        ok = all(s[1] for s in steps)
        check(cells, key, 'h', 'pass' if ok else 'fail', ' | '.join(f'{op}: {"ok" if good else "FAILED"}; {detail}' for op, good, detail in steps))


def cid_of_fieldkit():
    return query("select collection_id from mybb_myshowcase_collections where name='FieldKit'")[0][0]


def main():
    global CTX, DATA, PNG
    PNG = png_bytes()
    env = board.db_env()
    ctx = board.make_board(a.engine, a.mybb, a.scratch, PORT, 'real', PL_FILE, dbname=DBNAME)
    CTX = ctx
    global BOARD_DIR
    BOARD_DIR = ctx.board
    cells = {}
    try:
        for rel in ('showcase.php', 'inc/tasks/myshowcase.php', 'jscripts', 'images'):
            src, dst = os.path.join(REPO, 'Upload', rel), os.path.join(ctx.board, rel)
            if os.path.isdir(src):
                shutil.copytree(src, dst, dirs_exist_ok=True)
            else:
                shutil.copy(src, dst)
        shutil.copy(os.path.join(HERE, 'fixture.php'), os.path.join(ctx.board, 'msc_fields_fixture.php'))

        # the server restarts with the error settings of the run: every PHP error goes to the log the run reads afterwards
        ctx.stop()
        php_log = os.path.join(a.scratch, f'php_{a.engine}_errors.log')
        ctx.server = subprocess.Popen(['php', '-d', 'error_reporting=32767', '-d', 'display_errors=0', '-d', 'log_errors=1',
                                       '-d', f'error_log={php_log}', '-S', f'127.0.0.1:{PORT}', '-t', ctx.board],
                                      stdout=open(os.path.join(a.scratch, f'php_{a.engine}.log'), 'w'), stderr=subprocess.STDOUT)
        time.sleep(1.5)

        acp = board.activate(ctx)
        global LOG_OFFSET
        log = os.path.join(ctx.board, 'error.log')
        LOG_OFFSET = os.path.getsize(log) if os.path.exists(log) else 0

        def fixture(*args):
            r = subprocess.run(['php', 'msc_fields_fixture.php', *[str(x) for x in args]], cwd=ctx.board, capture_output=True, text=True)
            lines = [l for l in r.stdout.splitlines() if l.strip()]
            if r.returncode or not lines:
                raise RuntimeError(f'fixture {args[0]} failed: {r.stdout[-600:]} {r.stderr[-600:]}')
            return json.loads(lines[-1])

        users_file = os.path.join(a.scratch, 'users.json')
        fixture('setup', users_file)
        users = json.load(open(users_file))
        restricted = users['restricted']

        imp = os.path.join(ctx.board, 'inc', 'plugins', 'myshowcase', 'imports', 'JSON')
        shutil.copy(os.path.join(REPO, 'tests', 'fields', 'collections', 'fieldkit.json'), os.path.join(imp, 'collections', 'fieldkit.json'))
        shutil.copy(os.path.join(REPO, 'tests', 'fields', 'showcases', 'fieldkit.json'), os.path.join(imp, 'showcases', 'fieldkit.json'))
        acp.get('/admin/index.php?module=myshowcase-json_sync&action=create&type=showcase')
        for action in ('preview', 'run'):
            acp.post('/admin/index.php?module=myshowcase-json_sync', {'action': action, 'mode': 'create', 'type': 'showcase',
                                                                       'file': 'showcases/fieldkit.json', 'name': 'FieldKit',
                                                                       'route_identifier': ROUTE, 'custom_template_prefix': ''})
        count = query("select count(*) from mybb_myshowcase_fields f, mybb_myshowcase_collections c "
                      "where f.collection_id=c.collection_id and c.name='FieldKit'")
        if not count or count[0][0] != str(len(FIELDS)):
            raise RuntimeError(f'the FieldKit collection has {count}, expected {len(FIELDS)} fields')

        fixture('showcase-permissions', ROUTE)
        fixture('attach', ROUTE, 'fieldkit-uploads')
        sid = query(f"select showcase_id from mybb_myshowcase_config where route_identifier='{ROUTE}'")[0][0]
        DATA = f'mybb_myshowcase_data{sid}'

        # test data: the group restriction of one field (the JSON file says "all"; see the report)
        ctx.execute(f"update mybb_myshowcase_fields set allowed_groups_fill='{restricted}', allowed_groups_view='{restricted}' "
                    f"where field_key='fk_text_group'")
        fixture('rebuild')

        # test data: one option of the select that only the restricted group may fill (not in the JSON file)
        ctx.execute(f"insert into mybb_myshowcase_field_data (collection_id, field_id, field_key, value_id, value, "
                    f"allowed_groups_fill) select collection_id, field_id, field_key, 'restricted', 'Restricted option', "
                    f"'{restricted}' from mybb_myshowcase_fields where field_key='fk_select'")
        RESTRICTED_OPTION['fk_select'] = int(query("select field_data_id from mybb_myshowcase_field_data "
                                                   "where value='Restricted option'")[0][0])

        # the field ids and the option ids the form posts, read from the form itself
        for row in query("select field_key, field_id from mybb_myshowcase_fields"):
            FIELD_IDS[row[0]] = int(row[1])
        member = Front(ctx.url)
        member.login('fkmember', users['users']['fkmember']['password'])
        create_form = member.get(f'/showcase.php/{ROUTE}/create')['body']
        for key, f in FIELDS.items():
            if f['html_type'] == 'select':
                block = re.search(rf'<select[^>]*name="{re.escape(key)}"[^>]*>(.*?)</select>', create_form, re.S)
                ids = re.findall(r'value="(\d+)"', block.group(1)) if block else []
                ids = [i for i in ids if i != '']
            elif f['html_type'] in ('radio', 'checkbox'):
                ids = []
                for tag in re.findall(r'<input\b[^>]*>', create_form):
                    if re.search(rf'name="{re.escape(key)}(\[\])?"', tag):
                        ids.append(re.search(r'value="(\d+)"', tag).group(1))
            else:
                continue
            option_ids[key] = ids
            if f['html_type'] in ('select', 'radio', 'checkbox') and len(ids) < 2:
                raise RuntimeError(f'options of {key} not found in the form')

        open(os.path.join(a.scratch, 'matrix.png'), 'wb').write(PNG)
        COLUMN_TYPES.update(column_types(DATA))
        run_matrix(member, cells, users, restricted)
        ctx.execute("delete from mybb_myshowcase_field_data where value='Restricted option'")
        ctx.execute(f"update mybb_myshowcase_config set import_export_flood_seconds=0 where route_identifier='{ROUTE}'")
        fixture('rebuild')
        # the setting is read from inc/settings.php, which the server's opcache revalidates on its own clock: wait for it
        fixture('external-setting', 1)
        time.sleep(3)
        stage_i(member, cells, users, users['users']['fkmember']['uid'], fixture)
        fixture('external-setting', 0)
        time.sleep(3)
        stage_j(acp, cells)
        stage_l(member, cells, DATA)
        stage_h(member, acp, cells, ctx, cid_of_fieldkit(), fixture)
    finally:
        ctx.stop()
        if a.engine == 'mysql':
            subprocess.run([env['MYSQL_BIN'], '--no-defaults', f"-h{env['MYSQL_HOST']}", f"-P{env['MYSQL_PORT']}",
                            f"-u{env['MYSQL_USER']}"] + ([f"-p{env['MYSQL_PASSWORD']}"] if env['MYSQL_PASSWORD'] else []) +
                           ['-e', f'DROP DATABASE IF EXISTS {DBNAME}'], check=False)
        if a.engine == 'pgsql':
            subprocess.run([env['PSQL_BIN'], '-h', env['PGHOST'], '-p', env['PGPORT'], '-U', env['PGUSER'], '-d', 'postgres',
                            '-c', f'DROP DATABASE IF EXISTS {DBNAME}'], check=False, capture_output=True)
    for key in FIELDS:
        for stage, reason in NOT_RUN.items():
            check(cells, key, stage, 'notrun', reason)
    return cells


KNOWN = os.path.join(HERE, '..', 'known_failures.json')


def php_log_summary(path, board_log, board_offset):
    """The distinct messages of the run: PHP's own error log, and the entries MyBB wrote to the board's error.log after the
    matrix started (warnings, notices, deprecations, exceptions), with counts."""
    counts = {}
    if os.path.exists(path):
        for line in open(path, encoding='utf-8', errors='replace'):
            m = re.search(r'PHP (Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught [^:]*):? (.*)', line)
            if m:
                key = (m.group(1) + ': ' + re.sub(r' in /\S+/(\S+) on line \d+', r' in \1', m.group(2)).strip())[:300]
                counts[key] = counts.get(key, 0) + 1
    if os.path.exists(board_log):
        with open(board_log, 'rb') as handle:
            handle.seek(board_offset)
            for message in re.findall(r'<message>(.*?)</message>', handle.read().decode('utf-8', errors='replace'), re.S):
                key = ('board error.log: ' + re.sub(r'\s+', ' ', message))[:300]
                counts[key] = counts.get(key, 0) + 1
    return counts


def compare_known(cells, engine):
    """The run is green when the failing cells equal the cells listed for this engine in known_failures.json."""
    listed = json.load(open(KNOWN)).get(engine, {})
    failing = {k for k, v in cells.items() if v['result'] == 'fail'}
    new = sorted(failing - set(listed))
    fixed = sorted(set(listed) - failing)
    return new, fixed


if __name__ == '__main__':
    result = main()
    out = a.matrix_out or os.path.join(a.scratch, f'matrix-{a.engine}.json')
    php_version = subprocess.run(['php', '-r', 'echo PHP_VERSION;'], capture_output=True, text=True).stdout
    log = php_log_summary(os.path.join(a.scratch, f'php_{a.engine}_errors.log'), os.path.join(BOARD_DIR, 'error.log'), LOG_OFFSET)
    new, fixed = compare_known(result, a.engine)
    counts = {}
    for c in result.values():
        counts[c['result']] = counts.get(c['result'], 0) + 1
    json.dump({'engine': a.engine, 'php': php_version, 'counts': counts, 'cells': result, 'fields': list(FIELDS),
               'column_types': COLUMN_TYPES,
               'php_log': log, 'new_failures': new, 'fixed_listed': fixed}, open(out, 'w'), indent=1, ensure_ascii=False)
    print(f'{a.engine} PHP {php_version}: cells {counts}; written {out}')
    print('PHP log messages:', len(log), 'distinct')
    for key, n in sorted(log.items(), key=lambda kv: -kv[1])[:40]:
        print(f'  {n:5d}  {key}')
    print('new failures (not listed):', new)
    print('listed failures that pass:', fixed)
    sys.exit(1 if new or fixed else 0)
