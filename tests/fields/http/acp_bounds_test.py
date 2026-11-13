#!/usr/bin/env python3
"""ACP test of the bounds of a field definition (see tests/fields/README.md, stage a; decision D124).

A board is installed with the plugin of this working tree and a collection is stored. Through the ACP's field screens
the test saves number, range, date and text fields with bounds, and checks: a value that is not in the format of its
type is refused, a minimum above the maximum is refused, a range without both bounds is refused, a bound of a type that
has none is saved as empty, the stored values agree with the edit screen, and an edit changes the stored value.

    python3 tests/fields/http/acp_bounds_test.py --engine sqlite|mysql|pgsql --mybb DIR --scratch DIR

Exit code 0 only when every check passes.
"""
import argparse, json, os, random, string, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
sys.path.insert(0, os.path.join(REPO, 'tests', 'json-sync', 'http'))

import board  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', required=True)
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, default=0)
p.add_argument('--pluginlibrary-file', default=os.environ.get('PLUGINLIBRARY', ''))
a = p.parse_args()

PORT = a.port or {'sqlite': 18400, 'mysql': 18401, 'pgsql': 18402}[a.engine]
PL_FILE = a.pluginlibrary_file or os.path.join(REPO, '..', '_deps', 'MyBB-PluginLibrary', 'inc', 'plugins', 'pluginlibrary.php')
DBNAME = 'msc_test_' + ''.join(random.choice(string.ascii_lowercase + string.digits) for _ in range(10))
results = []


def stop_server(ctx):
    """Stops the test server: the process that listens on the port (the php process, which may be a child of the one
    started), then the process started. Nothing else is signalled."""
    listeners = subprocess.run(['lsof', '-ti', f'tcp:{PORT}', '-sTCP:LISTEN'], capture_output=True, text=True).stdout.split()
    for pid in listeners:
        try:
            os.kill(int(pid), 9)
        except OSError:
            pass
    if ctx.server:
        try:
            ctx.server.kill()
            ctx.server.wait(timeout=10)
        except (OSError, subprocess.TimeoutExpired):
            pass
        ctx.server = None


def check(name, ok, detail=''):
    results.append({'check': name, 'result': 'pass' if ok else 'fail', 'detail': detail})


def main():
    ctx = board.make_board(a.engine, a.mybb, a.scratch, PORT, 'real', PL_FILE, dbname=DBNAME)
    try:
        ctx.server = subprocess.Popen(['php', '-d', 'error_reporting=32767', '-d', 'display_errors=0', '-d', 'log_errors=1',
                                       '-d', f'error_log={os.path.join(a.scratch, f"php_acp_{a.engine}_errors.log")}',
                                       '-S', f'127.0.0.1:{PORT}', '-t', ctx.board],
                                      stdout=open(os.path.join(a.scratch, f'php_acp_{a.engine}.log'), 'w'), stderr=subprocess.STDOUT,
                                      start_new_session=True)
        time.sleep(1.5)
        acp = board.activate(ctx)
        ctx.execute("insert into mybb_myshowcase_collections (name) values ('AcpBounds')")
        cid = ctx.query("select collection_id from mybb_myshowcase_collections where name='AcpBounds'")[0][0]

        def save(key, html_type, field_type, minimum, maximum, field_id=None):
            data = {'field_key': key, 'label': key, 'html_type': html_type, 'field_type': field_type, 'is_enabled': 1,
                    'display_in_create_update_page': 1, 'display_in_view_page': 1, 'display_in_main_page': 1,
                    'allowed_groups_fill_type': 'all', 'allowed_groups_view_type': 'all',
                    'minimum_value': minimum, 'maximum_value': maximum}
            if field_id:
                data['field_id'] = field_id
                return acp.post(f'/admin/index.php?module=myshowcase-fields&action=edit_field&collection_id={cid}&field_id={field_id}', data)
            return acp.post(f'/admin/index.php?module=myshowcase-fields&action=newField&collection_id={cid}', data)

        def stored(key):
            rows = ctx.query(f"select minimum_value, maximum_value from mybb_myshowcase_fields where field_key='{key}'")
            return rows[0] if rows else None

        def refused(response, text):
            return text in response['body']

        r = save('acp_num_order', 'number', 'int', '5', '3')
        check('a number minimum above its maximum is refused', refused(r, 'above the maximum value') and stored('acp_num_order') is None)

        r = save('acp_num_fmt', 'number', 'int', '1.5', '')
        check('a number bound that is not an integer is refused', refused(r, 'does not have the format') and stored('acp_num_fmt') is None)

        r = save('acp_num', 'number', 'int', '1', '10')
        check('a number with a valid pair of bounds is saved', stored('acp_num') == ('1', '10'), str(stored('acp_num')))

        r = save('acp_free', 'number', 'int', '', '')
        check('a number with no bounds is saved with empty bounds', stored('acp_free') == ('', ''), str(stored('acp_free')))

        r = save('acp_range_none', 'range', 'int', '0', '')
        check('a range without a maximum is refused', refused(r, 'needs both') and stored('acp_range_none') is None)

        r = save('acp_range', 'range', 'int', '0', '50')
        check('a range with both bounds is saved', stored('acp_range') == ('0', '50'), str(stored('acp_range')))

        r = save('acp_date_bad', 'date', 'date', '2026-13-01', '')
        check('a date bound that is not a calendar date is refused', refused(r, 'does not have the format') and stored('acp_date_bad') is None)

        r = save('acp_date', 'date', 'date', '2026-01-01', '2026-12-31')
        check('a date with valid bounds is saved', stored('acp_date') == ('2026-01-01', '2026-12-31'), str(stored('acp_date')))

        r = save('acp_time_order', 'time', 'time', '18:00', '09:00')
        check('a time minimum above its maximum is refused', refused(r, 'above the maximum value') and stored('acp_time_order') is None)

        r = save('acp_week', 'week', 'varchar', '2026-W01', '2026-W54')
        check('a week bound that does not exist in its year is refused', refused(r, 'does not have the format') and stored('acp_week') is None)

        r = save('acp_text', 'text', 'varchar', 'not used', 'not used either')
        check('a text field does not keep bound values (saved as empty)', stored('acp_text') == ('', ''), str(stored('acp_text')))

        field_id = ctx.query("select field_id from mybb_myshowcase_fields where field_key='acp_num'")[0][0]
        acp.post(f'/admin/index.php?module=myshowcase-fields&action=edit_field&collection_id={cid}&field_id={field_id}',
                 {'field_key': 'acp_num', 'label': 'acp_num', 'html_type': 'number', 'field_type': 'int', 'is_enabled': 1,
                  'display_in_create_update_page': 1, 'display_in_view_page': 1, 'display_in_main_page': 1,
                  'allowed_groups_fill_type': 'all', 'allowed_groups_view_type': 'all',
                  'minimum_value': '1', 'maximum_value': '20', 'field_id': field_id})
        check('an edit saves the changed maximum', stored('acp_num') == ('1', '20'), str(stored('acp_num')))
        page = acp.get(f'/admin/index.php?module=myshowcase-fields&action=edit_field&collection_id={cid}&field_id={field_id}')['body']
        check('the edit screen shows the stored bounds', 'value="20"' in page and 'value="1"' in page)
    finally:
        stop_server(ctx)
        if a.engine == 'mysql':
            env = ctx.env
            cmd = [env['MYSQL_BIN'], '--no-defaults', f"-h{env['MYSQL_HOST']}", f"-P{env['MYSQL_PORT']}", f"-u{env['MYSQL_USER']}"]
            if env['MYSQL_PASSWORD']:
                cmd.append(f"-p{env['MYSQL_PASSWORD']}")
            subprocess.run(cmd + ['-e', f'DROP DATABASE IF EXISTS {DBNAME}'], check=False)
        if a.engine == 'pgsql':
            env = ctx.env
            subprocess.run([env['PSQL_BIN'], '-h', env['PGHOST'], '-p', env['PGPORT'], '-U', env['PGUSER'], '-d', 'postgres',
                            '-c', f'DROP DATABASE IF EXISTS {DBNAME}'], check=False, capture_output=True)
    failed = [r for r in results if r['result'] != 'pass']
    print(json.dumps({'engine': a.engine, 'results': results}, indent=1))
    print(f"acp bounds {a.engine}: {len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
