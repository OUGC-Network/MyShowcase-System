#!/usr/bin/env python3
"""The access matrix of the attachment download route (decision D58): users x attachments x modes, on the route, on
AttachmentAccess::decide() and on the import's own-board URLs.

    python3 tests/import/http/access_matrix.py --engine sqlite|mysql --mybb /path/to/mybb-1.9 --scratch DIR
                                               [--port N] [--old-upload DIR]

The NEW board is this working tree's Upload/. With --old-upload DIR (the Upload/ folder of local main, exported with
git archive) an OLD board is installed from the same seed and the same steps, and every cell is compared with NEW.

For every cell (user x attachment x mode, mode = file or thumbnail) the script records the HTTP status and the SHA-256 of
the body (both boards), the answer of AttachmentAccess::decide() that the test-only probe (fixtures/msc_test_probe.php)
records for the same request (NEW only), and the answer derived from the rules by this script (expected). For every
attachment and user, the own-board file URL is imported with the setting on (NEW only): accepted or refused as a whole.

Exit code 1 if a cell fails. Prints a compact table; the full cell list goes to DIR/matrix-<engine>.json.
"""
import argparse, hashlib, json, os, random, re, shutil, string, struct, subprocess, sys, time, urllib.error, urllib.parse, urllib.request, zlib, zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'features', 'http'))
sys.path.insert(0, HERE)

import board  # noqa: E402
import flows  # noqa: E402
from front import Front, post_key  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', required=True)
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, default=0)
p.add_argument('--old-upload', default='', help='the Upload/ folder of local main (the OLD board), optional')
a = p.parse_args()

PL_FILE = os.path.join(REPO, '..', '_deps', 'MyBB-PluginLibrary', 'inc', 'plugins', 'pluginlibrary.php')
PORT_BASE = a.port or {'sqlite': 18200, 'pgsql': 18220, 'mysql': 18210}[a.engine]
MEDIA = os.path.join(a.scratch, 'media')
os.makedirs(MEDIA, exist_ok=True)

NEW_UPLOAD = os.path.join(REPO, 'Upload')

IMPORT_TEXT = {}
USERS_ORDER = ['owner', 'member2', 'nodl', 'excluded', 'modr', 'nomain', 'guest']
ATTACHMENTS = ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8']
MODES = ['file', 'thumbnail']

# ---- local bytes (no network) ---------------------------------------------------------------------------------------

def make_png(index):
    """A small valid PNG (solid colour, 240x180), different for every attachment, so that the bodies differ."""
    width, height = 240, 180
    color = bytes([(index * 53) % 256, (index * 97) % 256, (index * 31) % 256])
    raw = b''.join(b'\x00' + color * width for _ in range(height))

    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)

    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0)) +
            chunk(b'IDAT', zlib.compress(raw, 9)) + chunk(b'IEND', b''))


def make_zip(path):
    """One archive for both boards: the entries carry a fixed date, so the bytes do not depend on the time of the run."""
    if not os.path.exists(path):
        info = zipfile.ZipInfo('readme.txt', date_time=(2026, 11, 1, 0, 0, 0))
        with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
            z.writestr(info, 'matrix archive\n' * 20)
    return path


# ---- HTTP helpers --------------------------------------------------------------------------------------------------

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def raw_get(opener, url):
    try:
        resp = opener.open(urllib.request.Request(url), timeout=60)
        status, body, loc = resp.status, resp.read(), resp.headers.get('Location')
    except urllib.error.HTTPError as e:
        status, body, loc = e.code, e.read(), e.headers.get('Location')
    return {'status': status, 'sha256': hashlib.sha256(body).hexdigest() if status == 200 else '', 'location': loc or '',
            'body': body[:300].decode('utf-8', 'replace') if status not in (200, 302, 403, 404) else ''}


def opener_of(front):
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(front.jar), NoRedirect())


# ---- one board -----------------------------------------------------------------------------------------------------

class Build:
    """One installed board of the matrix (NEW or OLD) with its users, showcases and attachments."""

    def __init__(self, label, upload_dir, port, probe):
        self.label, self.upload_dir, self.port, self.probe = label, upload_dir, port, probe
        self.scratch = os.path.join(a.scratch, label)
        os.makedirs(self.scratch, exist_ok=True)
        self.dbname = 'msc_test_' + ''.join(random.choice(string.ascii_lowercase + string.digits) for _ in range(10))
        board.PLUGIN = upload_dir
        self.ctx = board.make_board(a.engine, a.mybb, self.scratch, port, 'real', PL_FILE, dbname=self.dbname)
        self.board = self.ctx.board
        self.log = os.path.join(self.scratch, f'php_{a.engine}_errors.log')
        self.attachments = {}

    def copy_front(self):
        for rel in ('showcase.php', 'inc/tasks/myshowcase.php', 'jscripts', 'images'):
            src, dst = os.path.join(self.upload_dir, rel), os.path.join(self.board, rel)
            if os.path.isdir(src):
                shutil.copytree(src, dst, dirs_exist_ok=True)
            else:
                shutil.copy(src, dst)

    def restart_with_error_log(self):
        self.ctx.stop()
        self.ctx.server = subprocess.Popen(
            ['php', '-d', 'error_reporting=32767', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', f'error_log={self.log}',
             '-S', f'127.0.0.1:{self.port}', '-t', self.board],
            stdout=open(os.path.join(self.scratch, 'php_server.log'), 'w'), stderr=subprocess.STDOUT)
        time.sleep(1.5)

    def php_fixture(self, *args):
        r = subprocess.run(['php', os.path.join(HERE, 'matrix_fixture.php')] + [str(x) for x in args], cwd=self.board, capture_output=True, text=True)
        lines = [l for l in r.stdout.splitlines() if l.strip()]
        if r.returncode or not lines:
            raise RuntimeError(f'matrix fixture {args[0]} failed: {r.stdout[-800:]} {r.stderr[-800:]}')
        return json.loads(lines[-1])

    def sql(self, q):
        return self.ctx.query(q)

    def one(self, q):
        rows = self.sql(q)
        return rows[0][0] if rows else ''

    def build(self):
        self.copy_front()
        self.restart_with_error_log()
        self.acp = board.activate(self.ctx)
        flows.configure(self.ctx, self.scratch, self.log)
        flows.create_blog(self.acp)
        flows.create_showcase(self.acp, 'gallery', 'Gallery')
        flows.create_showcase(self.acp, 'downloads', 'Downloads')
        self.users_file = os.path.join(self.scratch, 'users.json')
        setup = self.php_fixture('setup', self.users_file, 'matrix-uploads')
        self.php_fixture('type-groups', 'Matrix excluded')
        self.users = setup['users']
        self.gid, self.did = setup['gallery'], setup['downloads']
        self.uploads = setup['uploads']
        # The showcases' "Url to Showcase" is the front controller of the board: the public URL of an attachment is then
        # the URL the route answers to (showcase_url + the route the board generates), and an own-board URL can match it.
        for route in ('gallery', 'downloads'):
            flows.fixture('showcase-setting', route, 'showcase_url', self.ctx.url + '/showcase.php')
        self.groups = setup['groups']
        self.uid = {k: v['uid'] for k, v in self.users.items()}
        if self.probe:
            self.install_probe()
        # Every plugin change of the fixture is cached: the server restarted with the error log is the same process.
        self.fronts = {}
        for name in USERS_ORDER:
            if name == 'guest':
                self.fronts[name] = Front(self.ctx.url)
            else:
                f = Front(self.ctx.url)
                r = f.login(name, self.users[name]['password'])
                if 'do not have permission' in r['body']:
                    raise RuntimeError(f'login of {name} failed')
                self.fronts[name] = f
        time.sleep(1)
        return self

    def install_probe(self):
        shutil.copy(os.path.join(HERE, 'fixtures', 'msc_test_probe.php'), os.path.join(self.board, 'inc', 'plugins', 'msc_test_probe.php'))
        r = self.acp.get('/admin/index.php?module=config-plugins&action=activate&plugin=msc_test_probe&my_post_key=' + self.acp.post_key)
        if r['status'] != 200 or 'SQL Error' in r['body']:
            raise RuntimeError('probe activation failed')
        self.probe_log = os.path.join(self.board, 'msc_probe.jsonl')

    # ---- the content of the matrix (entries and attachments made through the forum) ----------------------------------

    def field_id(self, route, key):
        return int(self.one(f"select f.field_id from mybb_myshowcase_fields f, mybb_myshowcase_config c where c.route_identifier='{route}' "
                            f"and f.collection_id=c.collection_id and f.field_key='{key}'") or 0)

    def create_entry(self, who, route, fields, file_field, file_path, save=True):
        client = self.fronts[who]
        form = client.get(f'/showcase.php/{route}/create')
        m = re.search(r'name="post_hash" value="([^"]+)"', form['body'])
        post_hash = m.group(1)
        key = post_key(form['body'])
        fid = self.field_id(route, file_field)
        up = client.upload(f'/showcase.php/{route}/update/{post_hash}/upload?my_post_key={key}&field_id={fid}',
                           {'my_post_key': key, 'field_id': fid}, file_field, file_path)
        self.last_upload = (up['status'], ' '.join(up['body'].split())[:300])
        if not save:
            return {'post_hash': post_hash, 'upload': up}
        data = dict(fields, post_hash=post_hash, my_post_key=key, submit='Post')
        r = client.post(f'/showcase.php/{route}/create', data)
        return {'post_hash': post_hash, 'upload': up, 'post': r}

    def attachments_of(self, post_hash):
        rows = self.sql(f"select attachment_id, public_id, attachment_name, entry_id, user_id, status, mime_type, thumbnail_name "
                        f"from mybb_myshowcase_attachments where post_hash='{post_hash}'")
        assert len(rows) == 1, f'expected one attachment for {post_hash}, got {len(rows)}; last upload: {self.last_upload}'
        keys = ['attachment_id', 'public_id', 'attachment_name', 'entry_id', 'user_id', 'status', 'mime_type', 'thumbnail_name']
        return dict(zip(keys, rows[0]))

    def make_matrix(self):
        png = lambda i: os.path.join(MEDIA, f'matrix-{i}.png')
        for i in range(1, 8):
            with open(png(i), 'wb') as h:
                h.write(make_png(i))
        zip_path = make_zip(os.path.join(MEDIA, 'matrix-archive.zip'))
        gal_fields = lambda t: {'title': t, 'caption': 'Matrix caption', 'category': self.option('gallery', 'Nature')}
        dl_fields = {'version': '1.9.1', 'release_date': '2026-11-01', 'platform': 1, 'license': 1, 'sha256': '0' * 64, 'category': 1}

        def approved_gallery(name, index):
            r = self.create_entry('owner', 'gallery', gal_fields(name), 'image', png(index))
            att = self.attachments_of(r['post_hash'])
            return att, int(self.one(f"select entry_id from mybb_myshowcase_data{self.gid} where title='{name}' and user_id={self.uid['owner']}"))

        a1, _ = approved_gallery('Matrix A1', 1)
        a4, _ = approved_gallery('Matrix A4', 2)
        a5, _ = approved_gallery('Matrix A5', 3)
        a8, e4 = approved_gallery('Matrix A8', 4)
        # A4: the attachment itself is awaiting approval (its entry is approved).
        self.sql_exec(f"update mybb_myshowcase_attachments set status=0 where attachment_id={a4['attachment_id']}")
        # A5: no attachment type matches its extension and MIME type any more.
        self.sql_exec(f"update mybb_myshowcase_attachments set attachment_name='matrix-renamed.gif' where attachment_id={a5['attachment_id']}")

        def member_gallery(name, index, status):
            r = self.create_entry('member2', 'gallery', gal_fields(name), 'image', png(index))
            att = self.attachments_of(r['post_hash'])
            eid = int(self.one(f"select entry_id from mybb_myshowcase_data{self.gid} where title='{name}' and user_id={self.uid['member2']}"))
            self.sql_exec(f"update mybb_myshowcase_data{self.gid} set status={status} where entry_id={eid}")
            return att

        a2 = member_gallery('Matrix A2', 5, 0)  # the entry of member2 awaits approval
        a3 = member_gallery('Matrix A3', 6, 2)  # the entry of member2 is soft-deleted

        # A6: the form is not saved: the upload belongs to no entry yet.
        r6 = self.create_entry('owner', 'gallery', {}, 'image', png(7), save=False)
        a6 = self.attachments_of(r6['post_hash'])

        r7 = self.create_entry('owner', 'downloads', dl_fields, 'archive', zip_path)
        a7 = self.attachments_of(r7['post_hash'])

        # A8: the entry row is gone, the attachment row stays (deleted last: no later entry may reuse its id).
        self.sql_exec(f"delete from mybb_myshowcase_data{self.gid} where entry_id={e4}")
        # The stored address of an attachment: the packed address of the uploader (127.0.0.1), in binary, on every engine.
        hex_of = {'pgsql': "encode(ipaddress, 'hex')"}.get(a.engine, 'hex(ipaddress)')
        self.stored_ip = self.one(f"select {hex_of} from mybb_myshowcase_attachments where attachment_id={a1['attachment_id']}")
        if self.probe:  # the NEW board: the default address is empty; the previous route stored the text X' on MySQL
            assert self.stored_ip in ('', '7f000001'), f'the stored address of an attachment is {self.stored_ip!r}'
        self.attachments = {'A1': a1, 'A2': a2, 'A3': a3, 'A4': a4, 'A5': a5, 'A6': a6, 'A7': a7, 'A8': a8}
        self.attachment_showcase = {'A1': 'gallery', 'A2': 'gallery', 'A3': 'gallery', 'A4': 'gallery', 'A5': 'gallery', 'A6': 'gallery',
                                    'A7': 'downloads', 'A8': 'gallery'}
        self.entry_status = {'A1': 1, 'A2': 0, 'A3': 2, 'A4': 1, 'A5': 1, 'A6': None, 'A7': 1, 'A8': None}
        self.entry_owner = {'A1': 'owner', 'A2': 'member2', 'A3': 'member2', 'A4': 'owner', 'A5': 'owner', 'A6': 'owner', 'A7': 'owner', 'A8': 'owner'}
        self.entry_gone = {'A8'}
        self.renamed = {'A5'}
        self.attachment_status = {'A1': 1, 'A2': 1, 'A3': 1, 'A4': 0, 'A5': 1, 'A6': 1, 'A7': 1, 'A8': 1}
        return self

    def sql_exec(self, q):
        self.ctx.execute(q)

    def option(self, route, label):
        return self.one(f"select d.field_data_id from mybb_myshowcase_field_data d, mybb_myshowcase_config c where c.route_identifier='{route}' "
                        f"and d.collection_id=c.collection_id and d.value='{label}'")

    def read_probe(self):
        if not self.probe or not os.path.exists(self.probe_log):
            return []
        return [json.loads(l) for l in open(self.probe_log, encoding='utf-8') if l.strip()]

    def url_of(self, label, mode):
        att = self.attachments[label]
        name = self.renamed_name(label, att)
        route = 'attachment' if mode == 'file' else 'thumbnail'
        showcase = self.attachment_showcase[label]
        return f"{self.ctx.url}/showcase.php/{showcase}/{route}/{att['public_id']}/{urllib.parse.quote(name)}"

    def renamed_name(self, label, att):
        # The row was read before the rename of A5: the current name is the one the route answers to.
        if label == 'A5':
            return 'matrix-renamed.gif'
        return att['attachment_name']

# ---- the expected answer, derived from the rules (not from the code) -----------------------------------------------

USER_FLAGS = {
    'owner':    dict(main=True,  view=True,  dl=True,  unapproved_att=False, moderator=False, types=True,  import_=True,  group='2'),
    'member2':  dict(main=True,  view=True,  dl=True,  unapproved_att=False, moderator=False, types=True,  import_=True,  group='2'),
    'nodl':     dict(main=True,  view=True,  dl=False, unapproved_att=False, moderator=False, types=True,  import_=True,  group='nodl'),
    'excluded': dict(main=True,  view=True,  dl=True,  unapproved_att=False, moderator=False, types=False, import_=True,  group='excluded'),
    'modr':     dict(main=True,  view=True,  dl=True,  unapproved_att=True,  moderator=True,  types=True,  import_=True,  group='modr'),
    'nomain':   dict(main=False, view=True,  dl=True,  unapproved_att=False, moderator=False, types=True,  import_=True,  group='nomain'),
    'guest':    dict(main=True,  view=True,  dl=False, unapproved_att=False, moderator=False, types=True,  import_=False, group='1'),
}


def expected(user, label, att_status, entry_status, entry_owner, entry_gone, mode, renamed):
    f = USER_FLAGS[user]
    thumb = mode == 'thumbnail'
    is_owner = user != 'guest' and user == entry_owner
    if not f['main']:
        return 'FORBIDDEN'
    if label == 'A6':  # no entry yet: the owner only
        return 'ALLOWED' if is_owner else 'HIDDEN'
    if entry_gone:
        return 'HIDDEN'
    # The entry rule: an approved entry is visible to everyone; an awaiting or soft-deleted one to its owner (who holds
    # the unapproved and deleted permissions in the matrix groups) and to a moderator; never to a guest.
    if entry_status == 1:
        visible = True
    else:
        visible = user != 'guest' and (f['moderator'] or is_owner)
    if not visible:
        return 'HIDDEN'
    if not is_owner:
        if not f['view']:
            return 'FORBIDDEN'
        if att_status != 1 and not f['unapproved_att']:
            return 'HIDDEN'
        if not thumb and not f['dl']:
            return 'FORBIDDEN'
    if renamed:  # the extension and MIME type match no attachment type
        return 'HIDDEN'
    if not is_owner and not f['types']:
        return 'FORBIDDEN'
    return 'ALLOWED'


def http_of(decision):
    return {'ALLOWED': (200, 302), 'HIDDEN': (404,), 'FORBIDDEN': (403,)}[decision]


# ---- the matrix ----------------------------------------------------------------------------------------------------

def run_cells(build, label):
    cells = {}
    for user in USERS_ORDER:
        opener = opener_of(build.fronts[user])
        for att in ATTACHMENTS:
            for mode in MODES:
                url = build.url_of(att, mode)
                got = raw_get(opener, url)
                cells[(user, att, mode)] = {'status': got['status'], 'sha256': got['sha256'], 'location': got['location']}
    return cells


def import_cells(build):
    """The own-board file URL of each attachment, imported by each user: accepted or refused as a whole (NEW only)."""
    flows.configure(build.ctx, build.scratch, build.log)
    flows.fixture('external-setting', '1')
    time.sleep(3)
    result = {}
    for user in USERS_ORDER:
        if not USER_FLAGS[user]['import_']:
            for att in ATTACHMENTS:
                result[(user, att)] = 'NOT REACHED (no import permission)'
            continue
        client = build.fronts[user]
        for att in ATTACHMENTS:
            route = build.attachment_showcase[att]
            if route == 'gallery':
                entry = flows.gallery_entry(1, build.url_of(att, 'file'))
                content = flows.xml_file([entry], route='gallery', collection='Gallery', format_version=2)
            else:
                entry = flows.downloads_entry(1, build.url_of(att, 'file'))
                content = flows.xml_file([entry], route='downloads', collection='Downloads', format_version=2)
            path = flows.write_file(f'import-{build.label}-{user}-{att}.xml', content)
            body = flows.import_into(client, route, path)['body']
            report = flows.report_of(body)
            accepted = report is not None and report.get('status') == 'completed'
            result[(user, att)] = 'ACCEPTED' if accepted else 'REFUSED'
            IMPORT_TEXT[(user, att)] = (report or {}).get('text', body[:200])[:700]
    flows.fixture('external-setting', '0')
    time.sleep(3)
    return result


def drop_database(name):
    """Drops the throwaway database of this run (MySQL or PostgreSQL); nothing else is touched."""
    env = board.db_env()
    if a.engine == 'mysql':
        subprocess.run([env['MYSQL_BIN'], '--no-defaults', f"-h{env['MYSQL_HOST']}", f"-P{env['MYSQL_PORT']}", f"-u{env['MYSQL_USER']}"] +
                       ([f"-p{env['MYSQL_PASSWORD']}"] if env['MYSQL_PASSWORD'] else []) + ['-e', f'DROP DATABASE IF EXISTS {name}'], check=False)
    elif a.engine == 'pgsql':
        subprocess.run([env['PSQL_BIN'], '-h', env['PGHOST'], '-p', env['PGPORT'], '-U', env['PGUSER'], '-d', 'postgres', '-q',
                        '-c', f'DROP DATABASE IF EXISTS {name}'], check=False, env={**os.environ, 'LC_ALL': 'en_US.UTF-8'})
    print('dropped database', name)


def main():
    results = {}
    builds = []
    try:
        new = Build('new', NEW_UPLOAD, PORT_BASE, probe=True)
        builds.append(new)  # registered first: its server is stopped in the finally block even if the build fails
        new.build().make_matrix()
        old = None
        if a.old_upload:
            old = Build('old', a.old_upload, PORT_BASE + 1, probe=False)
            builds.append(old)
            old.build().make_matrix()

        # NEW: each cell is requested and the probe line of that request gives the answer of decide().
        new_cells = {}
        for user in USERS_ORDER:
            opener = opener_of(new.fronts[user])
            for att in ATTACHMENTS:
                for mode in MODES:
                    before = len(new.read_probe())
                    got = raw_get(opener, new.url_of(att, mode))
                    lines = new.read_probe()[before:]
                    decision = lines[-1]['decision'] if lines else 'not reached'
                    new_cells[(user, att, mode)] = dict(got, decide=decision)

        old_cells = run_cells(old, 'old') if old else {}

        imports = import_cells(new)

        rows = []
        failures = []
        for user in USERS_ORDER:
            for att in ATTACHMENTS:
                for mode in MODES:
                    n = new_cells[(user, att, mode)]
                    o = old_cells.get((user, att, mode))
                    exp = expected(user, att, new.attachment_status[att], new.entry_status[att], new.entry_owner[att],
                                   att in new.entry_gone, mode, att in new.renamed)
                    notes = []
                    if n['decide'] == 'not reached':
                        # Only A8 (the entry row is gone) is answered before decide() runs (404 by the route).
                        if not (att == 'A8' and n['status'] == 404) and user != 'nomain':
                            notes.append('decide not reached')
                    else:
                        if n['decide'] != exp.lower():
                            notes.append(f"decide {n['decide']} != expected {exp}")
                        if n['status'] not in http_of(n['decide'].upper()):
                            notes.append(f"route {n['status']} != decide {n['decide']}")
                    if n['status'] not in http_of(exp) and not (user == 'nomain' and n['status'] == 503):
                        notes.append(f"route {n['status']} != expected {exp}")
                    # The redirect of a missing thumbnail names the board's own address: each board has its own port.
                    if o is not None and (o['status'] != n['status'] or o['sha256'] != n['sha256'] or
                                          o['location'].replace(old.ctx.url, '') != n['location'].replace(new.ctx.url, '')):
                        notes.append(f"OLD {o['status']} != NEW {n['status']}")
                    if n.get('body') and user != 'nomain':
                        notes.append('body: ' + ' '.join(n['body'].split())[:140])
                    imp = imports.get((user, att), '-')
                    if mode == 'file' and imp.startswith(('ACCEPTED', 'REFUSED')):
                        if imp != ('ACCEPTED' if exp == 'ALLOWED' else 'REFUSED'):
                            notes.append(f'import {imp} != expected for {exp}')
                    rows.append({'user': user, 'att': att, 'mode': mode, 'old': o['status'] if o else '-', 'new': n['status'],
                                 'decide': n['decide'], 'expected': exp, 'import': imp if mode == 'file' else '', 'notes': notes,
                                 'sha256_new': n['sha256'][:12], 'sha256_old': o['sha256'][:12] if o else '-'})
                    if notes:
                        failures.append(rows[-1])
        results = {'cells': rows, 'failures': len(failures), 'imports': {f'{u}/{x}': v for (u, x), v in imports.items()},
                   'import_text': {f'{u}/{x}': v for (u, x), v in IMPORT_TEXT.items()},
                   'engine': a.engine, 'old_compared': bool(old)}

        for r in rows:
            print(f"{r['user']:9} {r['att']} {r['mode']:9} OLD={r['old']!s:4} NEW={r['new']!s:4} decide={r['decide']:12} "
                  f"expected={r['expected']:10} import={r['import']:9} {'ok' if not r['notes'] else 'FAIL ' + '; '.join(r['notes'])}"
                  + (' [explained: 503 from the front controller, same on OLD]' if r['user'] == 'nomain' and r['new'] == 503 else ''))
        print(f"\ncells: {len(rows)}; failing: {len(failures)}; OLD compared: {bool(old)}")
        print('stored ipaddress of A1 (NEW):', new.stored_ip, '| OLD:', old.stored_ip if old else '-')
        out = os.path.join(a.scratch, f'matrix-{a.engine}.json')
        with open(out, 'w') as h:
            json.dump(results, h, indent=1, default=str)
        print('written', out)
        return 1 if failures else 0
    finally:
        for b in builds:
            b.ctx.stop()
            if a.engine in ('mysql', 'pgsql'):
                drop_database(b.dbname)


if __name__ == '__main__':
    sys.exit(main())
