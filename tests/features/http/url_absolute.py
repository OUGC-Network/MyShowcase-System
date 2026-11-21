#!/usr/bin/env python3
"""Every URL the plugin's pages emit is absolute and based on the showcase base or on bburl (decision D244).

JSON endpoints (decision D248): the entry view API (api/view/<id>/<slug>) answers the entry's id and message, with no URL;
the attachment API (api/view/<id>/upload) answers URLs, but 403 to a member without the entry-edit right, so it is not in this
page set. Its URLs are built with url() (tests/url-builder keeps every link on the builder). The pagination pages (list and the
entry's comments, with a page size of one here) assert that URLs were read.

The board is installed with the plugin of this working tree (board.make_board). For each showcase_url form (absolute with a
host, absolute without showcase.php, a root-relative value, a relative value, empty) and each bburl form (the board's root,
a subfolder with a trailing slash), and each url_mode (0, 1, 2), the test fetches the pages a guest and a member see (list,
entry with comments and attachments, create and export and import forms for the member, search, a refused create page for
the guest, an unknown entry) and reads every URL of the HTML: href, src, action, srcset, poster, data-*url* attributes,
meta and canonical links, url() in CSS, and URL-like strings in inline scripts.

A URL fails when it is not absolute (a fragment, mailto:, tel:, data:, javascript: and other sites are not checked), or when an
absolute URL of the board starts with neither the expected showcase base (for a route of the showcase: the plain form for
url_mode 0 and 1 on this server, which has no rewrite and no marker, and the friendly form for url_mode 2) nor the bburl
(for the other board URLs). The first failures are printed; the summary line is printed before the database and the
server are closed (D234).

    python3 tests/features/http/url_absolute.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>
"""
import argparse, json, os, re, shutil, subprocess, sys, time
from html.parser import HTMLParser
from urllib.parse import urlsplit

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, HERE)

import board  # noqa: E402
from front import Front  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''))
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, default=0)
a = p.parse_args()
if not a.mybb or not os.path.isdir(a.mybb):
    sys.exit('give --mybb: a MyBB 1.9 source tree with its vendor/ folder')

FAILS, PASSES, PROBLEMS = [], 0, {}
SKIP_SCHEMES = ('#', 'mailto:', 'tel:', 'data:', 'javascript:', 'about:', 'blob:')


def ok(cond, message):
    global PASSES
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


def normalize(url):
    """No trailing slash, no doubled slashes after the scheme."""
    m = re.match(r'^(https?://)(.*)$', url)
    if m:
        return m.group(1) + re.sub(r'/{2,}', '/', m.group(2)).rstrip('/')
    return re.sub(r'/{2,}', '/', url).rstrip('/')


def origin(url):
    parts = urlsplit(url)
    return f'{parts.scheme}://{parts.netloc}'


def resolve_base(showcase_url, bburl):
    """D244: the showcase base the links are built on (the plain form; the friendly form drops the showcase.php segment)."""
    value = showcase_url.strip()
    if re.match(r'^https?://[^/]+', value):
        return normalize(value)
    if value.startswith('/'):
        return normalize(origin(bburl) + value)
    if value:
        return normalize(bburl + '/' + value)
    return normalize(bburl + '/showcase.php')


def expected_showcase_prefix(showcase_url, bburl, mode):
    base = resolve_base(showcase_url, bburl)
    if mode == 2 and base.endswith('/showcase.php'):
        return base[: -len('/showcase.php')]
    return base


class UrlCollector(HTMLParser):
    """Every URL-valued attribute of the page, and the URL-like strings of its scripts and styles."""

    def __init__(self):
        super().__init__()
        self.found = []  # (kind, value)
        self.in_script = self.in_style = False

    def handle_starttag(self, tag, attrs):
        d = dict(attrs)
        if tag == 'script':
            self.in_script = True
        if tag == 'style':
            self.in_style = True
        for name, value in attrs:
            if value is None:
                continue
            if name in ('href', 'src', 'action', 'poster', 'content') and (name != 'content' or self._is_url_meta(d)):
                self.found.append((f'{tag}[{name}]', value))
            elif name == 'srcset':
                for item in value.split(','):
                    if item.strip():
                        self.found.append((f'{tag}[srcset]', item.strip().split()[0]))
            elif name.startswith('data-') and 'url' in name:
                self.found.append((f'{tag}[{name}]', value))
            if 'url(' in value:
                for m in re.finditer(r"url\(\s*['\"]?([^'\")\s]+)", value):
                    self.found.append((f'{tag}[style url()]', m.group(1)))

    @staticmethod
    def _is_url_meta(d):
        key = (d.get('property') or d.get('name') or '').lower()
        return key in ('og:url', 'og:image', 'twitter:url', 'canonical') or 'url' in key or 'image' in key

    def handle_endtag(self, tag):
        if tag == 'script':
            self.in_script = False
        if tag == 'style':
            self.in_style = False

    def handle_data(self, data):
        if self.in_style:
            for m in re.finditer(r"url\(\s*['\"]?([^'\")\s]+)", data):
                self.found.append(('style url()', m.group(1)))
        if self.in_script:
            for m in re.finditer(r"['\"](/[^'\"\s]*|https?://[^'\"\s]*)['\"]", data):
                if '/showcase.php' in m.group(1) or re.search(r'/(blog|gallery|downloads)(/|\?|$)', m.group(1)):
                    self.found.append(('script string', m.group(1)))


# MyBB's own header, footer and default theme (core chrome, not emitted by the plugin): not checked here. The plugin's own
# images (attachment type icons) are checked. The list is explicit, so a new entry is a decision of the test.
# usercp.php is not core chrome here: the plugin links to it (the attachments of the entry form), so those links are checked.
CORE_CHROME = ('manifest.json', 'showteam.php', 'member.php', 'index.php', 'search.php', 'task.php', 'misc.php', 'private.php', 'memberlist.php', 'online.php', 'calendar.php', 'stats.php', 'modcp.php', 'portal.php', 'syndication.php', 'cache/', 'jscripts/', 'images/')
CORE_CHROME_EXCEPT = ('images/attachtypes/',)
# MyBB's own debug link, shown to administrators in the footer (not a plugin link): "?debug=1"
CORE_CHROME_QUERY = ('debug=1',)


def is_core_chrome(value):
    path = urlsplit(value).path if value.startswith(('http://', 'https://', '//')) else value
    query = urlsplit(value).query if value.startswith(('http://', 'https://')) else ''
    text = path.lstrip('/') + ('?' + query if query else '')
    if text.startswith(CORE_CHROME_EXCEPT):
        return False
    if value.lstrip('/?') in CORE_CHROME_QUERY or text in CORE_CHROME_QUERY:
        return True
    return any(text.startswith(item) for item in CORE_CHROME)


# No page of the set returns JSON yet: this walk is for JSON bodies, and the JSON endpoints are not in the set (D248).
def json_urls(value, out):
    """Every string of a decoded JSON value that is a URL of the board or a root-relative route."""
    if isinstance(value, dict):
        for item in value.values():
            json_urls(item, out)
    elif isinstance(value, list):
        for item in value:
            json_urls(item, out)
    elif isinstance(value, str) and (value.startswith(('http://', 'https://', '/')) or '/showcase.php' in value or '/blog/' in value):
        out.append(('json string', value))
    return out


DUMP_DIR = ''  # when set, the list page of a combination with a failing URL is kept there, for the reader (a diagnostic)


def check_page(page_name, body, showcase_url, bburl, mode, label):
    """Checks the URLs of one page; returns how many were read (a page with none proves nothing)."""
    before = sum(len(v) for v in PROBLEMS.values())
    result = check_page_urls(page_name, body, showcase_url, bburl, mode, label)
    if DUMP_DIR and page_name == 'list' and sum(len(v) for v in PROBLEMS.values()) > before:
        open(os.path.join(DUMP_DIR, re.sub(r'[^A-Za-z0-9]+', '-', label) + '.html'), 'w', encoding='utf-8').write(body)
    return result


def check_page_urls(page_name, body, showcase_url, bburl, mode, label):
    collector = UrlCollector()
    if body.lstrip().startswith(('{', '[')):
        try:
            collector.found = json_urls(json.loads(body), [])
        except ValueError:
            collector.found = []
    else:
        collector.feed(body)
    expected_showcase = expected_showcase_prefix(showcase_url, bburl, mode)
    board_host = urlsplit(bburl).netloc
    read = 0
    for kind, value in collector.found:
        value = value.strip()
        read += 1
        if value == '' or value.startswith(SKIP_SCHEMES):
            continue
        if is_core_chrome(value):
            continue
        if value.startswith('//'):
            value = 'https:' + value
        if value.startswith(('http://', 'https://')):
            parts = urlsplit(value)
            if parts.netloc != board_host:
                continue  # another site
            if page_name.startswith('password gate') and kind == 'form[action]':
                # the gate's form posts back to the request it answers (the origin of the board and the request's own path):
                # the test server serves a subfolder board from its root, so only the origin is checked here
                if not value.startswith(urlsplit(bburl).scheme + '://' + board_host + '/'):
                    PROBLEMS.setdefault(f'{page_name} {kind}', []).append(f'{label}: {value} (not on the board origin)')
                continue
            if re.search(r'/showcase\.php(/|$)|/(blog|gallery|downloads)(/|\?|$)|route_identifier=', value):
                # the query string form (decision D268) is the plain base with the route in its parameters
                plain = resolve_base(showcase_url, bburl)
                good = value.startswith(expected_showcase + '/') or value == expected_showcase \
                    or value.startswith(plain + '?route_identifier=')
                if kind == 'script string' and '&amp;' in value:
                    # a script's text is not decoded by the browser: an escaped "&" in a URL the script uses is a broken request
                    PROBLEMS.setdefault(f'{page_name} {kind} escaped', []).append(f'{label}: {value[:120]} (a script holds &amp;, not &)')
            else:
                good = value.startswith(normalize(bburl) + '/') or value == normalize(bburl)
            if not good:
                PROBLEMS.setdefault(f'{page_name} {kind}', []).append(f'{label}: {value} (expected under {expected_showcase if "showcase" in value or "blog" in value else bburl})')
            continue
        PROBLEMS.setdefault(f'{page_name} {kind}', []).append(f'{label}: not absolute: {value}')
    return read


def main():
    scratch = a.scratch
    os.makedirs(scratch, exist_ok=True)
    port = a.port or {'sqlite': 18950, 'mysql': 18951, 'pgsql': 18952}[a.engine]
    ctx = None
    cleanup_failed = False
    try:
        try:
            ctx = board.make_board(a.engine, a.mybb, scratch, port, 'real', None, dbname=f'msc_test_absolute_{a.engine}_' + os.urandom(3).hex())
            run(ctx, scratch, port)
        except Exception as e:
            FAILS.append(f'the run stopped: {e}')
            print('FAIL the run stopped:', e)
        # a URL that is not absolute, or is on the wrong base, is a failure of the run as well (it was only listed before)
        for kind, items in PROBLEMS.items():
            FAILS.append(f'{kind}: {len(items)} URL(s) not on the expected base or not absolute, e.g. {items[0][:160]}')
        print(f'\n{PASSES} passed, {len(FAILS)} failed ({a.engine}); {sum(len(v) for v in PROBLEMS.values())} URL problems in {len(PROBLEMS)} kinds')
        for kind, items in sorted(PROBLEMS.items(), key=lambda kv: -len(kv[1]))[:40]:
            for example in items[:3]:
                print(f'  {len(items):4d}  {kind}   e.g. {example[:200]}')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
    finally:
        # also on KeyboardInterrupt (a BaseException): the board's server and database are closed in every case (D256)
        if ctx is not None:
            try:
                ctx.close()
            except Exception as e:
                print('CLEANUP FAILED:', e)
                cleanup_failed = True
    return 1 if (FAILS or PROBLEMS or cleanup_failed) else 0


def run(ctx, scratch, port):
    global DUMP_DIR
    DUMP_DIR = scratch
    import features  # noqa: F401  (the same board setup as url_mode.py)
    board_dir = ctx.board
    acp = board.activate(ctx)
    features.configure(ctx, scratch)
    shutil.copy(os.path.join(HERE, 'url_mode_router.php'), os.path.join(board_dir, 'url_mode_router.php'))
    adm = '/admin/index.php?module=myshowcase-json_sync'
    acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/blog.json'})
    acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog',
                   'route_identifier': 'blog', 'custom_template_prefix': ''})
    php(board_dir, 'enable', 'blog')
    php(board_dir, 'setting', 'seourls', 'no')
    entry = php(board_dir, 'entry', 'blog', 'Absolute entry')
    php(board_dir, 'save-row', 2, 'entries_can_export', 1)
    php(board_dir, 'save-row', 2, 'entries_can_import', 1)
    php(board_dir, 'save-row', 2, 'entries_can_post', 1)
    php(board_dir, 'save-row', 2, 'comments_can_post', 1)  # the reply button of an entry needs the comment right (a member's)
    users_file = os.path.join(scratch, 'users.json')
    php(board_dir, 'users', users_file)
    member_password = json.load(open(users_file))['member1']['password']
    php(board_dir, 'comment', 'blog', entry['entry_id'])
    php(board_dir, 'entry', 'blog', 'Second absolute entry')
    php(board_dir, 'comment', 'blog', entry['entry_id'], 'Second absolute comment')
    php(board_dir, 'showcase-set', 'blog', 'main_per_page', 1)
    php(board_dir, 'showcase-set', 'blog', 'comments_per_page', 1)
    php(board_dir, 'types', 'blog')
    png = os.path.join(scratch, 'absolute.png')
    open(png, 'wb').write(bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cfc0f01f0005000201a6a1c0340000000049454e44ae426082'))
    php(board_dir, 'attachment', 'blog', entry['entry_id'], png)
    php(board_dir, 'set-ip', 'blog', entry['entry_id'], '127.0.0.1')  # a stored address: the moderator's IP search link (D281)
    php(board_dir, 'setting', 'logip', 'show')
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port + 10}', '-t', board_dir, os.path.join(board_dir, 'url_mode_router.php')],
                              stdout=open(os.path.join(scratch, 'php-absolute.log'), 'w'), stderr=subprocess.STDOUT)
    base = f'http://127.0.0.1:{port + 10}'
    try:  # the router is stopped on every exit, also when the sleep is interrupted (D256)
        time.sleep(1.5)
        showcase_forms = {
            'absolute with showcase.php': f'{base}/showcase.php',
            'absolute without showcase.php': base,
            'root-relative': '/showcase.php',
            'relative': 'showcase.php',
            'empty': '',
        }
        bburl_forms = {'root': base, 'subfolder with trailing slash': base + '/sub/'}
        admin = Front(base)
        admin.login('admin', ctx.password)  # a super moderator: the moderator's pages (the IP search link) are rendered
        for bburl_label, bburl in bburl_forms.items():
            php(board_dir, 'setting', 'bburl', bburl)
            time.sleep(3)  # the server's opcache revalidates the settings on its own clock (as url_mode.py does)
            for form_label, showcase_url in showcase_forms.items():
                php(board_dir, 'showcase-set', 'blog', 'showcase_url', showcase_url)
                for mode in (0, 1, 2):
                    php(board_dir, 'showcase-set', 'blog', 'url_mode', mode)
                    label = f'bburl {bburl_label}, showcase_url {form_label}, url_mode {mode}'
                    guest = Front(base)
                    member = Front(base)
                    member.login('member1', member_password)
                    pages = [
                        ('list', guest, '/showcase.php/blog/'),
                        ('entry', guest, f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}"),
                        ('entry (member)', member, f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}"),
                        ('create (member)', member, '/showcase.php/blog/create'),
                        ('create refused (guest)', guest, '/showcase.php/blog/create'),
                        ('export (member)', member, '/showcase.php/blog/export'),
                        ('import (member)', member, '/showcase.php/blog/import'),
                        ('search (guest)', guest, '/showcase.php/blog/search'),
                        ('unknown entry (guest)', guest, '/showcase.php/blog/view/nope/nope'),  # 400 today: a Batch 8 status code (D249), accepted here
                        ('list page 2 (guest)', guest, '/showcase.php/blog/?page=2'),
                        ('entry comments page 2 (guest)', guest, f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}?page=2"),
                        ('entry threaded view (guest)', guest, f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}?threaded=1"),
                        ('entry threaded view (member)', member, f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}?threaded=1"),
                    ]
                    for name, client, path in pages:
                        r = client.get(f'{base}{path}')
                        ok(r['status'] in ((200, 403, 404, 400) if name.startswith('unknown entry') else (200, 403, 404)), f'{label}: {name} answers ({r["status"]})')
                        read = check_page(name, r['body'], showcase_url, bburl, mode, label)
                        if name in ('list page 2 (guest)', 'entry comments page 2 (guest)'):
                            ok(read > 0, f'{label}: {name} has URLs to check ({read})')
                    # the password gate (decision D244): a guest of a showcase with a password is asked for it, on an absolute form
                    php(board_dir, 'showcase-set', 'blog', 'password', 'url-absolute-gate')
                    gate = guest.get(f'{base}/showcase.php/blog/')
                    check_page('password gate (guest)', gate['body'], showcase_url, bburl, mode, label)
                    ok(gate['status'] == 200 and 'type="password"' in gate['body'], f'{label}: the password gate answers the password form ({gate["status"]})')
                    php(board_dir, 'showcase-set', 'blog', 'password', '')
                    # the moderator's IP search link (decision D281): shown to a moderator for the stored address, never to a guest
                    moderator_page = admin.get(f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}")
                    ip_links = [h for h in re.findall(r'href="([^"]*)"', moderator_page['body']) if 'ipsearch' in h]
                    ok(moderator_page['status'] == 200 and bool(ip_links) and all(h.startswith('http') for h in ip_links),
                       f'{label}: the moderator sees the IP search link, absolute ({ip_links[:1]})')
                    check_page('entry (moderator, stored IP)', moderator_page['body'], showcase_url, bburl, mode, label)
                    guest_page = guest.get(f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}")
                    ok(not [h for h in re.findall(r'href="([^"]*)"', guest_page['body']) if 'ipsearch' in h], f'{label}: a guest does not see the IP search link')
    finally:
        server.terminate()
        server.wait()


if __name__ == '__main__':
    sys.exit(main())
