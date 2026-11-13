#!/usr/bin/env python3
"""The abnormal end of an entries import, over HTTP (decision D59): a request that ends after a file was stored, and
before the import's COMMIT, leaves no entry row, no attachment row, no import log row, no file in the showcase's upload
folder and no temporary folder of the import. A following normal import still works.

    python3 tests/import/http/abort_test.py --engine sqlite|mysql --mybb /path/to/mybb-1.9 --scratch DIR [--port N]

The request is ended by the test-only plugin fixtures/msc_test_abort.php (installed into the temporary board only):
  case 1  exit on the 2nd entry's insert: the first entry's file is stored and recorded, nothing is committed
  case 2  a PHP fatal error (memory exhaustion, which PHP cannot catch) on the same call
  case 3  exit right after the copy of a file, before it is recorded: the window of decision D70, reported only
Files come from placehold.co (the only host of the import tests besides GitHub, decision D54).

Exit code 1 if case 1 or 2 leaves anything behind, or the normal import after them fails.
"""
import argparse, json, os, random, re, shutil, string, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'features', 'http'))
sys.path.insert(0, HERE)

import board  # noqa: E402
import flows  # noqa: E402
from front import Front  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', required=True)
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, default=0)
a = p.parse_args()

PL_FILE = os.path.join(REPO, '..', '_deps', 'MyBB-PluginLibrary', 'inc', 'plugins', 'pluginlibrary.php')
PORT = a.port or {'sqlite': 18300, 'pgsql': 18320, 'mysql': 18310}[a.engine]
SCRATCH = os.path.join(a.scratch, 'abort-' + a.engine)
os.makedirs(SCRATCH, exist_ok=True)
DBNAME = 'msc_test_' + ''.join(random.choice(string.ascii_lowercase + string.digits) for _ in range(10))

RESULTS = []


def check(cond, message):
    print(('PASS ' if cond else 'FAIL ') + message)
    RESULTS.append((bool(cond), message))


def php_temp_dir():
    return subprocess.run(['php', '-r', 'echo sys_get_temp_dir();'], capture_output=True, text=True).stdout.strip()


def temp_folders():
    base = php_temp_dir()
    return sorted(n for n in os.listdir(base) if n.startswith('myshowcase-import-'))


def files_under(folder):
    """Every file below the upload folder, by its path relative to it (the attachments go to dated subfolders)."""
    found = []
    for root, _, names in os.walk(folder):
        for name in names:
            found.append(os.path.relpath(os.path.join(root, name), folder))
    return sorted(found)


def folders_under(folder):
    """Every subfolder below the upload folder (a dated folder the import made is left behind if it is not removed)."""
    found = []
    for root, names, _ in os.walk(folder):
        for name in names:
            found.append(os.path.relpath(os.path.join(root, name), folder))
    return sorted(found)


def snapshot(ctx, gid, uploads):
    table = f'mybb_myshowcase_data{gid}'
    return {
        'entries': int(ctx.query(f'select count(*) from {table}')[0][0]),
        'attachments': int(ctx.query(f'select count(*) from mybb_myshowcase_attachments where showcase_id={gid}')[0][0]),
        'import_logs': int(ctx.query(f"select count(*) from mybb_myshowcase_logs where showcase_id={gid} and type_id=3")[0][0]),
        'files': files_under(uploads),
        'folders': folders_under(uploads),
        'temp': temp_folders(),
    }


def diff(before, after):
    out = {}
    for key in before:
        if before[key] != after[key]:
            if isinstance(before[key], list):
                out[key] = {'added': sorted(set(after[key]) - set(before[key])), 'removed': sorted(set(before[key]) - set(after[key]))}
            else:
                out[key] = (before[key], after[key])
    return out


def write_marker(ctx, point, after, mode):
    path = os.path.join(ctx.board, 'msc_abort.json')
    with open(path, 'w') as h:
        json.dump({'point': point, 'after': after, 'mode': mode, 'count': 0}, h)


def remove_marker(ctx):
    path = os.path.join(ctx.board, 'msc_abort.json')
    if os.path.exists(path):
        os.remove(path)


def gallery_file(label, count):
    entries = [flows.gallery_entry(i + 1, f'https://placehold.co/600x450.png?text=Abort%20{label}%20{i + 1}') for i in range(count)]
    return flows.write_file(f'abort-{label}.xml', flows.xml_file(entries, route='gallery', collection='Gallery', format_version=2))


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
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, SCRATCH, PORT, 'real', PL_FILE, dbname=DBNAME)
        upload = os.path.join(REPO, 'Upload')
        for rel in ('showcase.php', 'inc/tasks/myshowcase.php', 'jscripts', 'images'):
            src, dst = os.path.join(upload, rel), os.path.join(ctx.board, rel)
            if os.path.isdir(src):
                shutil.copytree(src, dst, dirs_exist_ok=True)
            else:
                shutil.copy(src, dst)

        log = os.path.join(SCRATCH, f'php_{a.engine}_errors.log')
        ctx.stop()
        ctx.server = subprocess.Popen(
            ['php', '-d', 'error_reporting=32767', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', f'error_log={log}',
             '-S', f'127.0.0.1:{PORT}', '-t', ctx.board],
            stdout=open(os.path.join(SCRATCH, 'php_server.log'), 'w'), stderr=subprocess.STDOUT)
        time.sleep(1.5)

        acp = board.activate(ctx)
        flows.configure(ctx, SCRATCH, log)
        flows.create_blog(acp)
        flows.create_showcase(acp, 'gallery', 'Gallery')
        flows.create_showcase(acp, 'downloads', 'Downloads')

        users_file = os.path.join(SCRATCH, 'users.json')
        flows.fixture('setup', users_file)
        flows.USERS = json.load(open(users_file))
        flows.fixture('files-setup', 'abort-uploads')
        flows.fixture('external-setting', '1')
        time.sleep(3)

        # The abnormal-end plugin: a test-only file, installed and activated like any plugin, in the temporary board.
        shutil.copy(os.path.join(HERE, 'fixtures', 'msc_test_abort.php'), os.path.join(ctx.board, 'inc', 'plugins', 'msc_test_abort.php'))
        r = acp.get('/admin/index.php?module=config-plugins&action=activate&plugin=msc_test_abort&my_post_key=' + acp.post_key)
        check(r['status'] == 200 and 'SQL Error' not in r['body'], 'the abnormal-end test plugin is activated in the temporary board')

        gid = int(ctx.query("select showcase_id from mybb_myshowcase_config where route_identifier='gallery'")[0][0])
        uploads = ctx.query(f"select attachments_uploads_path from mybb_myshowcase_config where showcase_id={gid}")[0][0]
        member = Front(ctx.url)
        member.login('member1', flows.USERS['member1']['password'])

        results = {}
        cases = [
            ('exit on the 2nd entry (after its first file is recorded)', 'entry_validate_insert_update_start', 2, 'exit', 'case1', 2, True),
            ('fatal error on the 2nd entry (after its first file is recorded)', 'entry_validate_insert_update_start', 2, 'fatal', 'case2', 2, True),
            ('exit after the copy of a file, before it is recorded (D70, reported)', 'attachment_upload_file', 1, 'exit', 'case3', 2, False),
        ]
        for title, point, after, mode, label, count, must_be_clean in cases:
            before = snapshot(ctx, gid, uploads)
            write_marker(ctx, point, after, mode)
            path = gallery_file(label, count)
            try:
                body = flows.import_into(member, 'gallery', path)['body']
                answer = 'a response of %d bytes' % len(body)
            except Exception as error:  # the request ended without a response
                answer = 'no response (' + type(error).__name__ + ')'
            marker = json.load(open(os.path.join(ctx.board, 'msc_abort.json')))
            remove_marker(ctx)
            after_state = snapshot(ctx, gid, uploads)
            changes = diff(before, after_state)
            results[label] = {'title': title, 'answer': answer, 'hook_calls': marker.get('count'), 'changes': changes}
            print(f"--- {label}: {title}")
            print(f"    request: {answer}; hook calls: {marker.get('count')}; changes: {changes or 'none'}")
            if must_be_clean:
                check(marker.get('count') == after, f'{label}: the request reached the hook on call {after}')
                check(not changes, f'{label}: nothing is left after the request ended: no row, no log row, no file, no temporary folder')
            else:
                print(f"    D70 window: {'files left: ' + str(changes['files']['added']) if changes.get('files') else 'no file was left'}; "
                      f"rows left: entries {changes.get('entries', 'none')}, attachments {changes.get('attachments', 'none')}")

        # A following normal import still works (the marker is gone).
        before = snapshot(ctx, gid, uploads)
        body = flows.import_into(member, 'gallery', gallery_file('normal', 2))['body']
        report = flows.report_of(body)
        after_state = snapshot(ctx, gid, uploads)
        check(report is not None and report['status'] == 'completed', 'a normal import after the abnormal ends completes')
        check(after_state['entries'] == before['entries'] + 2 and len(set(after_state['files']) - set(before['files'])) >= 2,
              'the normal import stores its two entries and their files')

        log_text = open(log, encoding='utf-8', errors='replace').read() if os.path.exists(log) else ''
        plugin_fatals = [l for l in log_text.splitlines() if 'Allowed memory size' in l or 'msc_test_abort' in l]
        print('PHP log lines of the abnormal-end plugin (expected for case 2 only):')
        for line in plugin_fatals[:6]:
            print('   ', line[:220])

        print(f'\n{sum(1 for ok, _ in RESULTS if ok)} passed, {sum(1 for ok, _ in RESULTS if not ok)} failed ({a.engine})')
        out = os.path.join(a.scratch, f'abort-{a.engine}.json')
        with open(out, 'w') as h:
            json.dump({'results': results, 'checks': RESULTS}, h, indent=1, default=str)
        print('written', out)
        return 0 if all(ok for ok, _ in RESULTS) else 1
    finally:
        if ctx is not None:
            ctx.stop()
        if a.engine in ('mysql', 'pgsql'):
            drop_database(DBNAME)


if __name__ == '__main__':
    sys.exit(main())
