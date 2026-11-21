#!/usr/bin/env python3
"""The PWA files of the showcase (B5, decision D309): a web app manifest and a service worker, both served by showcase routes.

The worker is registered at its query-form address (showcase.php?route_identifier=<id>&route=service-worker.js) whatever the
showcase's link setting, and its scope is the board folder. The checks, for each link form (url_mode 0 Path, 1 Automatic,
2 Friendly, 3 Query string) and on the list page and the entry page:

  M  the manifest link of the page answers 200 with the manifest media type; its start_url, scope and icon URLs are absolute,
     and each icon answers 200 as an image;
  W  the registration names the worker in query form and the board folder as its scope; the worker answers 200 with the
     JavaScript media type, a no-cache header and Service-Worker-Allowed set to the board folder;
  C  every URL of the worker's cache list (PRECACHE_URLS) answers 200;
  L  the manifest link is not the old root address /manifest.json, and the static guard's allow-list has no Batch 5 entry.

    python3 tests/features/http/pwa.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Exit code 1 if a check fails.
"""
import argparse, codecs, html, json, os, re, shutil, subprocess, sys, time, urllib.error, urllib.parse, urllib.request

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

FAILS, PASSES, SERVERS = [], 0, []
SCRATCH_DIR = ''
LINK_FORMS = ((0, 'path'), (1, 'automatic'), (2, 'friendly'), (3, 'query'))


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
        return {'error': first.strip()[:200]}
    return json.loads(lines[-1])


def fetch(url):
    """A plain GET without cookies: the status, the Content-Type, the headers and the body of the answer."""
    try:
        resp = urllib.request.urlopen(urllib.request.Request(url), timeout=60)
        status, headers, body = resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as e:
        status, headers, body = e.code, e.headers, e.read()
    return {'status': status, 'type': headers.get('Content-Type', '') or '', 'headers': headers, 'body': body,
            'text': body.decode('utf-8', 'replace')}


def js_string(raw):
    """A string literal as the page writes it (JavaScript escapes: \\/ and \\xHH or \\uHHHH) read back to its value."""
    return codecs.decode(raw.replace('\\/', '/'), 'unicode_escape')


def check_page(page_url, origin, form, label):
    """The checks of one page (M, W, C, L) for one link form."""
    page = fetch(page_url)
    text = page['text']
    ok(page['status'] == 200, f'{form} form, {label} page answers 200 [{page["status"]}]')
    ok('myshowcase.js' in text, f'{form} form, {label} page loads myshowcase.js')

    # W: the registration names the worker in query form, scoped to the board folder
    reg = re.search(r'myshowcase_service_worker\s*=\s*\{\s*url:\s*"((?:\\.|[^"\\])*)",\s*scope:\s*"((?:\\.|[^"\\])*)"', text)
    ok(reg is not None, f'{form} form, {label} page carries the service worker registration')
    if reg is None:
        return
    sw_url, scope = js_string(reg.group(1)), js_string(reg.group(2))
    ok(sw_url == origin + '/showcase.php?route_identifier=blog&route=service-worker.js',
       f'{form} form, {label} page registers the worker in query form [{sw_url}]')
    ok(scope == '/', f'{form} form, {label} page registers with the board folder as scope [{scope}]')

    # L: the manifest link is the builder's, not the root address
    link = re.search(r'<link rel="manifest" href="([^"]*)"', text)
    href = html.unescape(link.group(1)) if link else ''
    ok(bool(href) and href != '/manifest.json', f'{form} form, {label} page links the manifest through the builder [{href}]')

    # M: the manifest
    manifest = fetch(href) if href else {'status': 0, 'type': '', 'text': ''}
    ok(manifest['status'] == 200, f'{form} form, {label} page: the manifest answers 200 [{manifest["status"]}]')
    ok(manifest['type'].startswith('application/manifest+json'), f'{form} form, {label} page: the manifest is application/manifest+json [{manifest["type"]}]')
    try:
        doc = json.loads(manifest['text'])
    except ValueError:
        doc = {}
        ok(False, f'{form} form, {label} page: the manifest is JSON')
    ok(str(doc.get('start_url', '')).startswith('http') and str(doc.get('scope', '')).startswith('http'),
       f'{form} form, {label} page: the manifest start_url and scope are absolute')
    # D316: the manifest scope is the board folder, the folder of the worker's scope, in every link form
    manifest_scope = urllib.parse.urlparse(str(doc.get('scope', ''))).path
    ok(manifest_scope == scope, f'{form} form, {label} page: the manifest scope is the worker\'s scope, the board folder [{manifest_scope!r} vs {scope!r}]')
    start_path = urllib.parse.urlparse(str(doc.get('start_url', ''))).path
    ok(start_path.startswith(manifest_scope), f'{form} form, {label} page: the manifest start_url lies inside its scope [{start_path!r}]')
    icons = doc.get('icons', [])
    ok(len(icons) > 0, f'{form} form, {label} page: the manifest lists icons')
    for icon in icons:
        src = str(icon.get('src', ''))
        got = fetch(src)
        ok(src.startswith('http') and got['status'] == 200 and got['type'].startswith('image/'),
           f'{form} form, {label} page: icon {src} answers 200 as an image [{got["status"]} {got["type"]}]')

    # W: the worker
    worker = fetch(sw_url)
    ok(worker['status'] == 200, f'{form} form, {label} page: the worker answers 200 [{worker["status"]}]')
    ok(worker['type'].startswith('application/javascript'), f'{form} form, {label} page: the worker is application/javascript [{worker["type"]}]')
    ok(worker['headers'].get('Service-Worker-Allowed') == '/', f'{form} form, {label} page: the worker allows the board folder [{worker["headers"].get("Service-Worker-Allowed")}]')
    ok('no-cache' in (worker['headers'].get('Cache-Control') or ''), f'{form} form, {label} page: the worker is sent no-cache')

    # C: the cache list names files that answer 200
    listing = re.search(r'PRECACHE_URLS\s*=\s*(\[[^;]*\]);', worker['text'])
    urls = json.loads(listing.group(1)) if listing else []
    ok(len(urls) > 0, f'{form} form, {label} page: the worker has a cache list')
    for url in urls:
        got = fetch(url)
        ok(url.startswith(origin) and got['status'] == 200, f'{form} form, {label} page: cache list entry {url} answers 200 [{got["status"]}]')


def run(ctx, scratch, port):
    import features  # noqa: F401  (its configure() prepares the forum's entry point and the users)
    board_dir = ctx.board
    acp = board.activate(ctx)
    features.configure(ctx, scratch)
    shutil.copy(os.path.join(HERE, 'url_mode_router.php'), os.path.join(board_dir, 'url_mode_router.php'))
    adm = '/admin/index.php?module=myshowcase-json_sync'
    acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/blog.json'})
    acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog',
                   'route_identifier': 'blog', 'custom_template_prefix': ''})
    php(board_dir, 'enable', 'blog')
    entry = php(board_dir, 'entry', 'blog', 'Pwa entry')
    port_router = port + 10
    origin = f'http://127.0.0.1:{port_router}'
    SERVERS.append(subprocess.Popen(['php', '-S', f'127.0.0.1:{port_router}', '-t', board_dir, os.path.join(board_dir, 'url_mode_router.php')],
                                    stdout=open(os.path.join(scratch, 'php-pwa.log'), 'w'), stderr=subprocess.STDOUT))
    time.sleep(1.5)
    php(board_dir, 'setting', 'seourls', 'no')
    php(board_dir, 'showcase-set', 'blog', 'showcase_url', origin + '/showcase.php')
    for mode, form in LINK_FORMS:
        print(f'--- link form {form} (url_mode {mode})')
        php(board_dir, 'showcase-set', 'blog', 'url_mode', mode)
        check_page(origin + '/showcase.php/blog/', origin, form, 'list')
        check_page(origin + f"/showcase.php/blog/view/{entry['public_id']}/{entry['slug']}", origin, form, 'entry')

    print('--- static guard: no Batch 5 entry in the URL allow-list')
    allow = json.load(open(os.path.join(HERE, '..', '..', 'url-builder', 'allow-list.json')))
    reasons = [str(e.get('reason', '')) for key in ('entries', 'relative') for e in allow.get(key, [])]
    ok(not any('Batch 5' in r for r in reasons), 'the URL allow-list has no Batch 5 (PWA) entry')


def main():
    global SCRATCH_DIR
    os.makedirs(a.scratch, exist_ok=True)
    SCRATCH_DIR = a.scratch
    port = a.port or {'sqlite': 18950, 'mysql': 18951, 'pgsql': 18952}[a.engine]
    ctx = None
    cleanup_failed = False
    try:
        try:
            ctx = board.make_board(a.engine, a.mybb, a.scratch, port, 'real', None, dbname=f'msc_test_pwa_{a.engine}_' + os.urandom(3).hex())
            run(ctx, a.scratch, port)
        except Exception as e:  # a crash is a failed check, reported with the summary below
            FAILS.append(f'the run stopped: {e}')
            print('FAIL the run stopped:', e)
        print(f'\n{PASSES} passed, {len(FAILS)} failed ({a.engine})')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
    finally:
        try:
            for s in SERVERS:
                s.terminate()
                s.wait()
            if ctx is not None:
                ctx.close()
        except Exception as e:
            print('CLEANUP FAILED:', e)
            cleanup_failed = True
    return 1 if (FAILS or cleanup_failed) else 0


if __name__ == '__main__':
    sys.exit(main())
