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
        first = next((l for l in r.stdout.splitlines() if 'SQLSTATE' in l or 'Error' in l), r.stdout[-200:])
        print(f'     fixture {args[0]} failed: {first.strip()[:200]}')
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


def expected_friendly(mode, seourls, seo):
    """Automatic is MyBB core's rule (init.php): yes, or auto with SEO_SUPPORT == 1."""
    if mode == 0:
        return False
    if mode == 2:
        return True
    return seourls == 'yes' or (seourls == 'auto' and seo)


def main():
    scratch = a.scratch
    os.makedirs(scratch, exist_ok=True)
    port = a.port or {'sqlite': 18900, 'mysql': 18901, 'pgsql': 18902}[a.engine]
    ctx = board.make_board(a.engine, a.mybb, scratch, port, 'real', None, dbname=f'msc_test_urlmode_{a.engine}_' + os.urandom(3).hex())
    try:
        run(ctx, scratch, port)
    finally:
        for s in SERVERS:
            s.terminate()
            s.wait()
        ctx.stop()
        if a.engine == 'mysql':
            env = board.db_env()
            subprocess.run([env['MYSQL_BIN'], '--no-defaults', f"-h{env['MYSQL_HOST']}", f"-P{env['MYSQL_PORT']}", f"-u{env['MYSQL_USER']}",
                            '-e', f'DROP DATABASE IF EXISTS {ctx.dbname}'], check=False)
        if a.engine == 'pgsql':
            env = board.db_env()
            subprocess.run([env['PSQL_BIN'], '-h', env['PGHOST'], '-p', env['PGPORT'], '-U', env['PGUSER'], '-d', 'postgres',
                            '-c', f'DROP DATABASE IF EXISTS {ctx.dbname}'], check=False, capture_output=True)


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

    # ---- F: the ACP showcase form
    print('--- F ACP form: three choices, a saved choice, a refused value')
    showcase_id = str(php(board_dir, 'showcase-value', 'blog', 'showcase_id').get('value'))
    page = acp.get('/admin/index.php?module=myshowcase&action=edit&showcase_id=' + showcase_id)
    select = re.search(r'<select[^>]*name="url_mode"[^>]*>(.*?)</select>', page['body'], re.S)
    options = re.findall(r'<option[^>]*value="(\d+)"[^>]*>([^<]*)</option>', select.group(1)) if select else []
    ok(len(options) == 3 and [v for v, _ in options] == ['0', '1', '2'], f'the form offers three choices 0, 1, 2 [{options}]')
    data = {'my_post_key': acp.post_key, 'submit': 'Save'}
    data.update({'url_mode': '2'})
    r = acp.post('/admin/index.php?module=myshowcase&action=edit&showcase_id=' + showcase_id, data)
    ok(php(board_dir, 'showcase-value', 'blog', 'url_mode').get('value') == '2', f'saving the form with 2 stores 2 [{r["status"]}]')
    data['url_mode'] = '7'
    acp.post('/admin/index.php?module=myshowcase&action=edit&showcase_id=' + showcase_id, data)
    ok(php(board_dir, 'showcase-value', 'blog', 'url_mode').get('value') == '2', 'a value that is not a choice is refused and nothing changes')

    # ---- D2: a file with url_mode 2 gives 2
    print('--- D file: a showcase file with url_mode 2 creates a showcase with 2')
    file_path = os.path.join(board_dir, 'inc', 'plugins', 'myshowcase', 'imports', 'JSON', 'showcases', 'urlmode-test.json')
    shipped = json.load(open(os.path.join(board_dir, 'inc', 'plugins', 'myshowcase', 'imports', 'JSON', 'showcases', 'blog.json')))
    shipped['url_mode'] = 2
    json.dump(shipped, open(file_path, 'w'))
    acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/urlmode-test.json', 'name': 'Url test', 'route_identifier': 'urltest'})
    php(board_dir, 'enable', 'urltest')
    ok(php(board_dir, 'showcase-value', 'urltest', 'url_mode').get('value') == '2', 'the showcase created from the file has url_mode 2')

    for s in servers_list:
        s.terminate()
        s.wait()
    print(f'\n{PASSES} passed, {len(FAILS)} failed ({a.engine})')
    for f in FAILS:
        print('  FAILED:', f)


if __name__ == '__main__':
    main()
    sys.exit(1 if FAILS else 0)
