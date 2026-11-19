#!/usr/bin/env python3
"""The URL option of a showcase (decisions D4 to D9, D224): url_mode 0 = No (plain links), 1 = Automatic, 2 = Yes (friendly links).

The plugin generates the links of a showcase; both forms are always routed, so a friendly request answers the same page as the
plain one. Automatic follows the board's seourls setting exactly as MyBB core does for its own links (init.php): yes makes the
links friendly, no keeps them plain, auto makes them friendly when the request carries SEO_SUPPORT == 1.

Two servers serve the same board: a plain one, and one whose requests carry SEO_SUPPORT (a server with mod_rewrite). Both use a
router that stands for the rewrite (url_mode_router.php in the board root). The checks:

  L  the links on the list page of every (seourls, url_mode, server) combination are plain or friendly as the rule says;
  R  both forms of an entry's address answer 200 with the same entry, on both servers;
  F  the ACP showcase form has the three choices, saves one of them, and refuses a value that is not one of them;
  D  a showcase created from a file without url_mode is Automatic (1); a file with url_mode 2 gives 2.

    python3 tests/features/http/url_mode.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Exit code 1 if a check fails.
"""
import argparse, json, os, re, shutil, subprocess, sys, time
from html.parser import HTMLParser

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, HERE)

import board  # noqa: E402
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
SCRATCH_DIR = ''
SERVERS = []


def ok(cond, message):
    global PASSES
    print(('PASS ' if cond else 'FAIL ') + message)
    if cond:
        PASSES += 1
    else:
        FAILS.append(message)


def php(board_dir, *args):
    """Runs one fixture command. A failing command returns {'error': ...} so that the other checks still run and report."""
    r = subprocess.run(['php', os.path.join(HERE, 'fresh_fixture.php'), *[str(x) for x in args]], cwd=board_dir, capture_output=True, text=True)
    lines = [l for l in r.stdout.splitlines() if l.strip()]
    if r.returncode or not lines or 'SQL Error' in r.stdout:
        first = next((l for l in r.stdout.splitlines() if 'SQLSTATE' in l), next((l for l in r.stdout.splitlines() if 'Error' in l and 'MyBB' not in l), r.stdout[-200:]))
        print(f'     fixture {args[0]} failed: {first.strip()[:200]}')
        if SCRATCH_DIR:
            open(os.path.join(SCRATCH_DIR, f'fixture-fail-{args[0]}.txt'), 'w').write(r.stdout + r.stderr)
        return {'error': first.strip()[:200]}
    return json.loads(lines[-1])


def start_server(board_dir, port, seo, log):
    env = dict(os.environ)
    env['URL_MODE_SEO'] = '1' if seo else '0'
    cmd = ['php', '-d', 'auto_prepend_file=' + os.path.join(HERE, 'url_mode_seo_prepend.php'), '-S', f'127.0.0.1:{port}',
           '-t', board_dir, os.path.join(board_dir, 'url_mode_router.php')]
    return subprocess.Popen(cmd, env=env, stdout=open(log, 'w'), stderr=subprocess.STDOUT)


def links_of(body, pattern):
    return re.findall(r'href="([^"]*' + pattern + r'[^"]*)"', body)


class FormScraper(HTMLParser):
    """The forms of a page with their fields (the JSON-sync flows' scraper, copied: this suite does not import them)."""

    def __init__(self):
        super().__init__()
        self.forms, self.cur, self.sel, self.ta = [], None, None, None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.cur = {'action': a.get('action', ''), 'fields': {}, 'disabled': set()}
            self.forms.append(self.cur)
        elif self.cur is None:
            return
        elif tag == 'input' and a.get('name'):
            if 'disabled' in a:
                self.cur['disabled'].add(a['name'])
            ty = (a.get('type') or 'text').lower()
            if ty in ('submit', 'button', 'reset', 'image'):
                return
            if ty in ('checkbox', 'radio'):
                if 'checked' in a:
                    self.cur['fields'].setdefault(a['name'], []).append(a.get('value', 'on'))
            else:
                self.cur['fields'].setdefault(a['name'], []).append(a.get('value', ''))
        elif tag == 'select':
            self.sel = {'name': a.get('name'), 'selected': [], 'first': None}
            if 'disabled' in a and self.sel['name']:
                self.cur['disabled'].add(self.sel['name'])
        elif tag == 'option' and self.sel is not None:
            if self.sel['first'] is None:
                self.sel['first'] = a.get('value', '')
            if 'selected' in a:
                self.sel['selected'].append(a.get('value', ''))
        elif tag == 'textarea':
            self.ta = [a.get('name'), '']

    def handle_data(self, d):
        if self.ta is not None:
            self.ta[1] += d

    def handle_endtag(self, tag):
        if tag == 'select' and self.sel is not None and self.cur is not None:
            if self.sel['name']:
                vals = self.sel['selected'] or ([self.sel['first']] if self.sel['first'] is not None else [])
                if vals:
                    self.cur['fields'][self.sel['name']] = vals
            self.sel = None
        elif tag == 'textarea' and self.ta is not None:
            if self.ta[0]:
                self.cur['fields'][self.ta[0]] = [self.ta[1]]
            self.ta = None


def form_of(html, contains):
    """The form whose action contains the text."""
    scraper = FormScraper()
    scraper.feed(html)
    for f in scraper.forms:
        if contains in f['action']:
            return f
    raise RuntimeError(f'no form with {contains}')


def php_env(board_dir, env, *args):
    """A fixture command with extra environment (the SEO_SUPPORT of the request the who's-online builder is told about)."""
    r = subprocess.run(['php', os.path.join(HERE, 'fresh_fixture.php'), *[str(x) for x in args]], cwd=board_dir, capture_output=True, text=True,
                       env={**os.environ, **env})
    lines = [l for l in r.stdout.splitlines() if l.strip()]
    if r.returncode or not lines or 'SQL Error' in r.stdout:
        return {'error': (lines[-1] if lines else r.stdout[-200:])[:200]}
    return json.loads(lines[-1])


def plugin_links(body):
    """Every link or form action of the blog: with the showcase.php segment (plain) or without it (friendly)."""
    return re.findall(r'(?:href|action|src)="([^"]*?/blog(?:/|\?|"|$)[^"]*)"', body)


def is_friendly(link):
    return '/showcase.php/' not in link


def expected_friendly(mode, seourls, seo):
    """Automatic is MyBB core's rule (init.php): yes, or auto with SEO_SUPPORT == 1."""
    if mode == 0:
        return False
    if mode == 2:
        return True
    return seourls == 'yes' or (seourls == 'auto' and seo)


def main():
    global SCRATCH_DIR
    scratch = a.scratch
    os.makedirs(scratch, exist_ok=True)
    SCRATCH_DIR = scratch
    port = a.port or {'sqlite': 18900, 'mysql': 18901, 'pgsql': 18902}[a.engine]
    ctx = board.make_board(a.engine, a.mybb, scratch, port, 'real', None, dbname=f'msc_test_urlmode_{a.engine}_' + os.urandom(3).hex())
    try:
        run(ctx, scratch, port)
    except Exception as e:  # a crash is a failed check, reported with the summary below
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    print(f'\n{PASSES} passed, {len(FAILS)} failed ({a.engine})')
    for f in FAILS:
        print('  FAILED:', f)
    sys.stdout.flush()
    try:
        for s in SERVERS:
            s.terminate()
            s.wait()
        ctx.close()  # stops the servers, drops the database and checks that nothing is left (D225)
    except Exception as e:
        print('CLEANUP FAILED:', e)
        return 1
    return 1 if FAILS else 0


def run(ctx, scratch, port):
    import features
    board_dir = ctx.board
    acp = board.activate(ctx)
    features.configure(ctx, scratch)
    shutil.copy(os.path.join(HERE, 'url_mode_router.php'), os.path.join(board_dir, 'url_mode_router.php'))
    adm = '/admin/index.php?module=myshowcase-json_sync'
    acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/blog.json'})
    acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog',
                   'route_identifier': 'blog', 'custom_template_prefix': ''})
    php(board_dir, 'enable', 'blog')
    entry = php(board_dir, 'entry', 'blog', 'Url mode entry')  # the list and the entry page need one approved entry
    plain_port, seo_port = port + 10, port + 11
    plain = start_server(board_dir, plain_port, False, os.path.join(scratch, 'php-plain.log'))
    seo = start_server(board_dir, seo_port, True, os.path.join(scratch, 'php-seo.log'))
    SERVERS.extend([plain, seo])
    time.sleep(1.5)
    # the server the links point at: the plain server (the friendly form is the same host without the script name)
    php(board_dir, 'showcase-set', 'blog', 'showcase_url', f'http://127.0.0.1:{plain_port}/showcase.php')
    servers_list = [plain, seo]

    # ---- D: the default of a showcase created from a file without url_mode
    print('--- D default: a showcase created from its file without url_mode is Automatic (1)')
    default = php(board_dir, 'showcase-value', 'blog', 'url_mode').get('value')
    ok(default == '1', f'the blog showcase created from its shipped file has url_mode 1 (Automatic) [{default!r}]')

    # ---- L: the links of the list page, by seourls, url_mode and server
    print('--- L links: plain or friendly as the rule says, for every seourls, url_mode and server')
    for seourls in ('no', 'auto', 'yes'):
        php(board_dir, 'setting', 'seourls', seourls)
        time.sleep(3)  # the opcache revalidates the settings file on its own clock
        for mode in (0, 1, 2):
            php(board_dir, 'showcase-set', 'blog', 'url_mode', mode)
            for name, base_port, is_seo in (('plain server', plain_port, False), ('SEO server', seo_port, True)):
                c = Front(f'http://127.0.0.1:{base_port}')
                r = c.get('/showcase.php/blog/')
                body = r['body']
                hrefs = links_of(body, '/view/')
                friendly_links = [h for h in hrefs if '/showcase.php/' not in h]
                plain_links = [h for h in hrefs if '/showcase.php/' in h]
                want = expected_friendly(mode, seourls, is_seo)
                good = bool(hrefs) and ((want and not plain_links and friendly_links) or (not want and not friendly_links and plain_links))
                ok(r['status'] == 200 and good,
                   f'seourls {seourls}, url_mode {mode}, {name}: links {"friendly" if want else "plain"} '
                   f'({len(hrefs)} links, first {hrefs[:1]})')

    # ---- R: both forms answer the same page
    print('--- R both forms: the plain and the friendly address of an entry answer the same page, on both servers')
    php(board_dir, 'setting', 'seourls', 'no')
    time.sleep(3)
    php(board_dir, 'showcase-set', 'blog', 'url_mode', 2)
    if 'public_id' not in entry:
        ok(False, 'an approved entry can be created for the address checks')
    for name, base_port in (('plain server', plain_port), ('SEO server', seo_port)) if 'public_id' in entry else ():
        c = Front(f'http://127.0.0.1:{base_port}')
        forms = {'plain': f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}",
                 'friendly': f"/blog/view/{entry['public_id']}/{entry['slug']}"}
        answers = {k: c.get(v) for k, v in forms.items()}
        ok(all(r['status'] == 200 and 'Url mode entry' in r['body'] for r in answers.values()),
           f'{name}: the plain and the friendly address answer 200 with the same entry '
           f'({ {k: r["status"] for k, r in answers.items()} })')

    # ---- E: the list page and the entry page: every link of the blog, in the form the rule says
    print('--- E every link of the list and the entry page follows the rule (incl. sort, last_comment, search, new entry)')
    for seourls in ('no', 'auto', 'yes'):
        php(board_dir, 'setting', 'seourls', seourls)
        time.sleep(3)
        for mode in (0, 1, 2):
            php(board_dir, 'showcase-set', 'blog', 'url_mode', mode)
            for name, base_port, is_seo in (('plain server', plain_port, False), ('SEO server', seo_port, True)):
                c = Front(f'http://127.0.0.1:{base_port}')
                want = expected_friendly(mode, seourls, is_seo)
                for page_name, path in (('list', '/showcase.php/blog/'),
                                        ('entry', f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}")):
                    r = c.get(path)
                    links = plugin_links(r['body'])
                    wrong = [l for l in links if is_friendly(l) != want]
                    kinds = {'sort' if 'sort' in l else 'last_comment' if 'last_comment' in l else 'view' if '/view/' in l else 'other' for l in links}
                    ok(r['status'] == 200 and links and not wrong,
                       f'seourls {seourls}, url_mode {mode}, {name}, {page_name} page: {len(links)} links all {"friendly" if want else "plain"}'
                       f'{"" if not wrong else " (wrong: " + wrong[0] + ")"} [{", ".join(sorted(kinds))}]')

    # ---- W: who's online shows the showcase page with the same rule
    print('--- W who\'s online: the location link of a showcase page follows the rule')
    for seourls in ('no', 'auto', 'yes'):
        php(board_dir, 'setting', 'seourls', seourls)
        for mode in (0, 1, 2):
            php(board_dir, 'showcase-set', 'blog', 'url_mode', mode)
            for is_seo in (False, True):
                env = {'URL_MODE_SEO': '1' if is_seo else '0'}
                loc = php_env(board_dir, env, 'wol', 'blog', 'view', entry['entry_id'])
                # the showcase's own links only: the board's profile link of the viewer is not a showcase link
                hrefs = [h for h in re.findall(r'href="([^"]*)"', loc.get('location', '')) if '/blog' in h]
                want = expected_friendly(mode, seourls, is_seo)
                good = bool(hrefs) and all(is_friendly(h) == want for h in hrefs)
                ok(good, f'who\'s online, seourls {seourls}, url_mode {mode}, {"SEO" if is_seo else "plain"} request: '
                         f'{"friendly" if want else "plain"} ({hrefs[:1] or loc})')

    # ---- P: a custom showcase_url (not ending in showcase.php) gives identical forms; an empty one falls back to bburl
    print('--- P prefix: a custom prefix gives identical forms in every mode; an empty one falls back to bburl/showcase.php')
    php(board_dir, 'setting', 'seourls', 'no')
    time.sleep(3)
    custom = f'http://127.0.0.1:{plain_port}/custom'
    php(board_dir, 'showcase-set', 'blog', 'showcase_url', custom)
    per_mode = {}
    for mode in (0, 2):
        php(board_dir, 'showcase-set', 'blog', 'url_mode', mode)
        per_mode[mode] = sorted(set(plugin_links(Front(f'http://127.0.0.1:{plain_port}').get('/showcase.php/blog/')['body'])))
    ok(per_mode[0] and per_mode[0] == per_mode[2] and all(l.startswith(custom + '/blog') for l in per_mode[0]),
       f'a custom prefix gives the same links in mode 0 and mode 2 under it ({per_mode[0][:1]})')
    php(board_dir, 'showcase-set', 'blog', 'showcase_url', '')
    php(board_dir, 'showcase-set', 'blog', 'url_mode', 0)
    links0 = plugin_links(Front(f'http://127.0.0.1:{plain_port}').get('/showcase.php/blog/')['body'])
    ok(links0 and all(l.startswith(f'http://127.0.0.1:{port}/showcase.php/blog') for l in links0),
       f'an empty showcase_url falls back to bburl/showcase.php in mode 0 ({links0[:1]})')
    php(board_dir, 'showcase-set', 'blog', 'url_mode', 2)
    links2 = plugin_links(Front(f'http://127.0.0.1:{plain_port}').get('/showcase.php/blog/')['body'])
    ok(links2 and all(l.startswith(f'http://127.0.0.1:{port}/blog') for l in links2),
       f'an empty showcase_url in mode 2 gives bburl/blog ({links2[:1]})')
    php(board_dir, 'showcase-set', 'blog', 'showcase_url', f'http://127.0.0.1:{plain_port}/showcase.php')
    php(board_dir, 'showcase-set', 'blog', 'url_mode', 1)

    # ---- J: JSON-sync export of a showcase carries url_mode; an apply with url_mode 0 stores 0
    print('--- J JSON sync: the export carries url_mode; an apply stores it')
    sid = php(board_dir, 'showcase-value', 'blog', 'showcase_id').get('value')
    # the export writes the columns that differ from their defaults (as the other columns do): url_mode 2 is written, 1 is not
    php(board_dir, 'showcase-set', 'blog', 'url_mode', 2)
    exp = acp.get(f'/admin/index.php?module=myshowcase-json_sync&action=export&type=showcase&id={sid}&my_post_key={acp.post_key}')
    ok(re.search(r'"url_mode":\s*2', exp['body']) is not None, f'the JSON export of a showcase with url_mode 2 carries "url_mode": 2 [{exp["status"]}]')
    php(board_dir, 'showcase-set', 'blog', 'url_mode', 1)
    exp = acp.get(f'/admin/index.php?module=myshowcase-json_sync&action=export&type=showcase&id={sid}&my_post_key={acp.post_key}')
    ok('"url_mode"' not in exp['body'], 'the JSON export of a showcase with the default (1) does not write it')
    # the apply reads the showcase's own file (showcases/blog.json of the board's copy): it is restored after the check
    shipped_path = os.path.join(board_dir, 'inc', 'plugins', 'myshowcase', 'imports', 'JSON', 'showcases', 'blog.json')
    original = open(shipped_path, 'rb').read()
    shipped = json.loads(original)
    shipped['url_mode'] = 0
    try:
        json.dump(shipped, open(shipped_path, 'w'))
        acp.post(adm, {'action': 'run', 'mode': 'apply', 'type': 'showcase', 'id': str(sid), 'file': 'showcases/blog.json'})
        ok(php(board_dir, 'showcase-value', 'blog', 'url_mode').get('value') == '0', 'a JSON apply of a file with url_mode 0 stores 0')
        # a file with a value that is not a choice is refused: the stored value does not change
        shipped['url_mode'] = 7
        json.dump(shipped, open(shipped_path, 'w'))
        acp.post(adm, {'action': 'run', 'mode': 'apply', 'type': 'showcase', 'id': str(sid), 'file': 'showcases/blog.json'})
        ok(php(board_dir, 'showcase-value', 'blog', 'url_mode').get('value') == '0', 'a JSON apply of a file with url_mode 7 is refused and stores nothing')
    finally:
        open(shipped_path, 'wb').write(original)

    # ---- F: the ACP showcase form (the General tab): three choices, each saved, and a value that is not a choice refused
    print('--- F ACP form: three choices, each saved, a value that is not a choice refused')
    sid = str(php(board_dir, 'showcase-value', 'blog', 'showcase_id').get('value'))
    page = acp.get(f'/admin/index.php?module=myshowcase-summary&action=update&showcase_id={sid}')
    general = form_of(page['body'], 'type=general')
    select = re.search(r'<select[^>]*name="showcase\[url_mode\]"[^>]*>(.*?)</select>', page['body'], re.S)
    options = re.findall(r'<option[^>]*value="(\d+)"[^>]*>([^<]*)</option>', select.group(1)) if select else []
    ok([v for v, _ in options] == ['0', '1', '2'], f'the form offers three choices 0, 1, 2 [{options}]')

    def save(value):
        data = {k: (v[0] if len(v) == 1 else v) for k, v in general['fields'].items() if k not in general['disabled']}
        data['showcase[url_mode]'] = value
        path = general['action'].replace('&amp;', '&').split('module=')[1]
        return acp.post('/admin/index.php?module=' + path, data)

    for value in ('2', '0', '1'):
        save(value)
        ok(php(board_dir, 'showcase-value', 'blog', 'url_mode').get('value') == value, f'saving the form with {value} stores {value}')
    save('2')
    r = save('7')
    ok(php(board_dir, 'showcase-value', 'blog', 'url_mode').get('value') == '2', f'a value that is not a choice (7) is refused and nothing changes [{r["status"]}]')

    # ---- Q: the other pages: the entry page with its comment and attachments, the export and import pages, the
    # canonical link (D240). As a guest, and as a member (who can export and import).
    print('--- Q comments, attachments, export and import pages, canonical link: as a guest and as a member')
    users_file = os.path.join(scratch, 'users.json')
    php(board_dir, 'users', users_file)
    member_password = json.load(open(users_file))['member1']['password']
    php(board_dir, 'save-row', 2, 'entries_can_export', 1)
    php(board_dir, 'save-row', 2, 'entries_can_import', 1)
    php(board_dir, 'save-row', 2, 'entries_can_post', 1)  # the import page also needs the right to post entries
    php(board_dir, 'comment', 'blog', entry['entry_id'])
    png = os.path.join(scratch, 'fresh.png')
    open(png, 'wb').write(bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cfc0f01f0005000201a6a1c0340000000049454e44ae426082'))
    php(board_dir, 'types', 'blog')  # an uploads folder and PNG attachments for the blog (as the features harness sets them)
    attached = php(board_dir, 'attachment', 'blog', entry['entry_id'], png)
    # the same pages with the prefix as stored (plain URL) and with an empty prefix (the bburl fallback, decision D229)
    for empty_prefix in (False, True):
        php(board_dir, 'showcase-set', 'blog', 'showcase_url', '' if empty_prefix else f'http://127.0.0.1:{plain_port}/showcase.php')
        print('    prefix: ' + ('empty (bburl fallback)' if empty_prefix else 'plain URL as stored'))
        for seourls in ('no', 'auto', 'yes'):
            php(board_dir, 'setting', 'seourls', seourls)
            time.sleep(3)
            for mode in (0, 1, 2):
                php(board_dir, 'showcase-set', 'blog', 'url_mode', mode)
                for name, base_port, is_seo in (('plain server', plain_port, False), ('SEO server', seo_port, True)):
                    want = expected_friendly(mode, seourls, is_seo)
                    guest = Front(f'http://127.0.0.1:{base_port}')
                    member = Front(f'http://127.0.0.1:{base_port}')
                    member.login('member1', member_password)
                    for who, client in (('guest', guest), ('member', member)):
                        entry_path = f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}"
                        r = client.get(entry_path)
                        links = plugin_links(r['body'])
                        wrong = [l for l in links if is_friendly(l) != want]
                        kinds = sorted({'comment' if '/comment' in l else 'attachment' if ('/attachment/' in l or '/thumbnail/' in l) else 'view' if '/view/' in l else 'other' for l in links})
                        need = ['attachment', 'comment'] if who == 'member' else []
                        ok(r['status'] == 200 and links and not wrong and all(k in kinds for k in need),
                           f'seourls {seourls}, url_mode {mode}, {name}, {who}, entry page with comments and attachments: '
                           f'{len(links)} links all {"friendly" if want else "plain"}{"" if not wrong else " (wrong: " + wrong[0] + ")"} [{", ".join(kinds)}]')
                        canon = re.search(r'<link rel="canonical" href="([^"]*)"', r['body'])
                        canon_ok = bool(canon) and (is_friendly(canon.group(1)) == want) and '/blog/view/' in canon.group(1)
                        ok(canon_ok, f'seourls {seourls}, url_mode {mode}, {name}, {who}: the canonical link is '
                                     f'{"friendly" if want else "plain"} ({canon.group(1) if canon else "none"})')
                    if True:
                        for page_name, path in (('export', '/showcase.php/blog/export'), ('import', '/showcase.php/blog/import')):
                            r = member.get(path)
                            links = plugin_links(r['body'])
                            wrong = [l for l in links if is_friendly(l) != want]
                            ok(r['status'] == 200 and links and not wrong,
                               f'seourls {seourls}, url_mode {mode}, {name}, member, {page_name} page: {len(links)} links all '
                               f'{"friendly" if want else "plain"}{"" if not wrong else " (wrong: " + wrong[0] + ")"}')

    php(board_dir, 'showcase-set', 'blog', 'showcase_url', f'http://127.0.0.1:{plain_port}/showcase.php')

    # ---- X: export file URLs are plain whatever the mode (D238); the own-board check of an import takes both forms (D52, D58)
    print('--- X export and import of file URLs: plain in every mode; both forms of an own-board URL are recognised')
    attached = php(board_dir, 'attachment', 'blog', entry['entry_id'], png)
    for empty_prefix in (False, True):
        prefix = '' if empty_prefix else f'http://127.0.0.1:{plain_port}/showcase.php'
        php(board_dir, 'showcase-set', 'blog', 'showcase_url', prefix)
        host = f'http://127.0.0.1:{port}' if empty_prefix else f'http://127.0.0.1:{plain_port}'
        for mode in (0, 1, 2):
            php(board_dir, 'setting', 'seourls', 'yes')
            time.sleep(3)
            php(board_dir, 'showcase-set', 'blog', 'url_mode', mode)
            label = f"prefix {'empty' if empty_prefix else 'plain'}, url_mode {mode}, seourls yes"
            file_url = php(board_dir, 'file-url', 'blog', attached['public_id'], attached['name']).get('url', '')
            ok(file_url.startswith(f"{host}/showcase.php/blog/attachment/") and '/showcase.php/' in file_url,
               f'{label}: the export writes the plain file URL [{file_url[:90]}]')
            route = f"/attachment/{attached['public_id']}/{attached['name']}"
            plain_form, friendly_form = f"{host}/showcase.php/blog{route}", f"{host}/blog{route}"
            if empty_prefix:
                plain_form = f"{host}/showcase.php/blog{route}"
            got_plain = php(board_dir, 'own-url', 'blog', plain_form).get('attachment_id')
            got_friendly = php(board_dir, 'own-url', 'blog', friendly_form).get('attachment_id')
            ok(str(got_plain) == str(attached['attachment_id']) and str(got_friendly) == str(attached['attachment_id']),
               f'{label}: the own-board URL is recognised in both forms (plain {got_plain}, friendly {got_friendly})')
    php(board_dir, 'showcase-set', 'blog', 'showcase_url', f'http://127.0.0.1:{plain_port}/showcase.php')
    php(board_dir, 'showcase-set', 'blog', 'url_mode', 1)

    # ---- D2: a file with url_mode 2 gives 2
    print('--- D file: a showcase file with url_mode 2 creates a showcase with 2')
    file_path = os.path.join(board_dir, 'inc', 'plugins', 'myshowcase', 'imports', 'JSON', 'showcases', 'urlmode-test.json')
    shipped = json.load(open(os.path.join(board_dir, 'inc', 'plugins', 'myshowcase', 'imports', 'JSON', 'showcases', 'blog.json')))
    shipped['url_mode'] = 2
    json.dump(shipped, open(file_path, 'w'))
    try:
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/urlmode-test.json', 'name': 'Url test', 'route_identifier': 'urltest'})
        php(board_dir, 'enable', 'urltest')
        ok(php(board_dir, 'showcase-value', 'urltest', 'url_mode').get('value') == '2', 'the showcase created from the file has url_mode 2')
    finally:
        os.remove(file_path)  # the test file lives in the board's copy only and is removed (D234)



if __name__ == '__main__':
    sys.exit(main())
