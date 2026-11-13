#!/usr/bin/env python3
"""Upgrade test of the field definition columns (see tests/fields/README.md, stage u).

A board is installed and the plugin of the BASE commit is activated, a collection and a field are stored, and then the
plugin of this working tree replaces it and is activated again through the ACP. The test checks that the columns the
working tree adds to myshowcase_fields exist afterwards with the default '' (VARCHAR(32)), that the rows and the columns
the base has are unchanged, and that a second activation changes nothing.

    python3 tests/fields/http/upgrade_test.py --engine sqlite|mysql|pgsql --mybb DIR --scratch DIR [--base SHA]

The base plugin is read with git archive from this repository (the commit named by --base, default: the commit this part
starts from). Exit code 0 only when every check passes.
"""
import argparse, json, os, random, shutil, string, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
sys.path.insert(0, os.path.join(REPO, 'tests', 'json-sync', 'http'))

import board  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', required=True)
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, default=0)
p.add_argument('--base', default='16e928c9e3300a2343e6289466efab8aab673729')
p.add_argument('--pluginlibrary-file', default=os.environ.get('PLUGINLIBRARY', ''))
a = p.parse_args()

PORT = a.port or {'sqlite': 18395, 'mysql': 18396, 'pgsql': 18397}[a.engine]
PL_FILE = a.pluginlibrary_file or os.path.join(REPO, '..', '_deps', 'MyBB-PluginLibrary', 'inc', 'plugins', 'pluginlibrary.php')
DBNAME = 'msc_test_' + ''.join(random.choice(string.ascii_lowercase + string.digits) for _ in range(10))
TABLE = 'mybb_myshowcase_fields'
NEW_COLUMNS = ('minimum_value', 'maximum_value')
results = []


def check(name, ok, detail=''):
    results.append({'check': name, 'result': 'pass' if ok else 'fail', 'detail': detail})


def columns_of(ctx):
    if ctx.engine == 'sqlite':
        rows = ctx.query(f"select name, type, dflt_value from pragma_table_info('{TABLE}')")
    elif ctx.engine == 'mysql':
        rows = ctx.query("select column_name, column_type, column_default from information_schema.columns "
                         f"where table_schema=database() and table_name='{TABLE}' order by ordinal_position")
    else:
        rows = ctx.query("select column_name, data_type || coalesce('(' || character_maximum_length || ')', ''), "
                         f"column_default from information_schema.columns where table_name='{TABLE}' order by ordinal_position")
    return [tuple(r) for r in rows]


def rows_of(ctx, columns):
    names = [c[0] for c in columns if c[0] not in NEW_COLUMNS]
    return ctx.query(f"select {', '.join(names)} from {TABLE} order by field_id")


def swap_plugin(ctx, tree):
    """Replaces the plugin files of the board with those of the tree: 'base' (a copy) or 'work' (symlinks)."""
    plugin = os.path.join(ctx.board, 'inc', 'plugins', 'myshowcase')
    entry = os.path.join(ctx.board, 'inc', 'plugins', 'myshowcase.php')
    admin = os.path.join(ctx.board, 'admin', 'modules', 'myshowcase')
    for path in (plugin, entry, admin):
        if os.path.islink(path):
            os.unlink(path)
        elif os.path.isdir(path):
            shutil.rmtree(path)
        elif os.path.exists(path):
            os.remove(path)
    if tree == 'work':
        src = os.path.join(REPO, 'Upload', 'inc', 'plugins', 'myshowcase')
        os.makedirs(plugin)
        for n in os.listdir(src):
            if n != 'imports':
                os.symlink(os.path.join(src, n), os.path.join(plugin, n))
        shutil.copytree(os.path.join(src, 'imports'), os.path.join(plugin, 'imports'))
        os.symlink(os.path.join(REPO, 'Upload', 'inc', 'plugins', 'myshowcase.php'), entry)
        os.makedirs(os.path.join(ctx.board, 'admin', 'modules'), exist_ok=True)
        os.symlink(os.path.join(REPO, 'Upload', 'admin', 'modules', 'myshowcase'), admin)
        return
    staging = os.path.join(a.scratch, 'base_tree')
    shutil.rmtree(staging, ignore_errors=True)
    os.makedirs(staging)
    archive = subprocess.run(['git', '-C', REPO, 'archive', a.base, 'Upload'], capture_output=True, check=True)
    subprocess.run(['tar', '-x', '-C', staging], input=archive.stdout, check=True)
    upload = os.path.join(staging, 'Upload')
    shutil.copytree(os.path.join(upload, 'inc', 'plugins', 'myshowcase'), plugin)
    shutil.copy(os.path.join(upload, 'inc', 'plugins', 'myshowcase.php'), entry)
    os.makedirs(os.path.join(ctx.board, 'admin', 'modules'), exist_ok=True)
    shutil.copytree(os.path.join(upload, 'admin', 'modules', 'myshowcase'), admin)
    # the base code needs the vendor folder of the working tree (not versioned); it is the same library set
    vendor_src = os.path.join(REPO, 'Upload', 'inc', 'plugins', 'myshowcase', 'vendor')
    if os.path.isdir(vendor_src) and not os.path.exists(os.path.join(plugin, 'vendor')):
        os.symlink(vendor_src, os.path.join(plugin, 'vendor'))


def start_server(ctx):
    ctx.stop()
    php_log = os.path.join(a.scratch, f'php_upgrade_{a.engine}_errors.log')
    ctx.server = subprocess.Popen(['php', '-d', 'error_reporting=32767', '-d', 'display_errors=0', '-d', 'log_errors=1',
                                   '-d', f'error_log={php_log}', '-S', f'127.0.0.1:{PORT}', '-t', ctx.board],
                                  stdout=open(os.path.join(a.scratch, f'php_upgrade_{a.engine}.log'), 'w'),
                                  stderr=subprocess.STDOUT)
    time.sleep(1.5)


def main():
    ctx = board.make_board(a.engine, a.mybb, a.scratch, PORT, 'real', PL_FILE, dbname=DBNAME)
    try:
        swap_plugin(ctx, 'base')
        start_server(ctx)
        acp = board.activate(ctx)
        before_columns = columns_of(ctx)
        check('base schema has no new columns', not any(c[0] in NEW_COLUMNS for c in before_columns))

        ctx.execute("insert into mybb_myshowcase_collections (name) values ('UpgradeTest')")
        cid = ctx.query("select collection_id from mybb_myshowcase_collections where name='UpgradeTest'")[0][0]
        ctx.execute(f"insert into {TABLE} (collection_id, field_key, field_label, html_type, field_type, maximum_length, "
                    f"minimum_length, is_enabled, is_required) values ({cid}, 'up_number', 'Upgrade number', 'number', "
                    f"'int', 10, 0, 1, 0)")
        ctx.execute(f"insert into {TABLE} (collection_id, field_key, field_label, html_type, field_type, maximum_length, "
                    f"minimum_length, is_enabled, is_required) values ({cid}, 'up_text', 'Upgrade text', 'text', "
                    f"'varchar', 20, 2, 1, 1)")
        rows_before = rows_of(ctx, before_columns)
        check('the stored fields exist before the upgrade', len(rows_before) == 2, str(rows_before))

        # the working tree replaces the base plugin and is activated again through the ACP
        swap_plugin(ctx, 'work')
        # a new server process: the opcache of the old one still holds the base files under the same paths
        start_server(ctx)
        board.activate(ctx)
        after_columns = columns_of(ctx)
        added = {c[0]: c for c in after_columns if c[0] in NEW_COLUMNS}
        check('both new columns exist after the upgrade', set(added) == set(NEW_COLUMNS), str(sorted(added)))
        for name in NEW_COLUMNS:
            if name in added:
                default = added[name][2] or ''
                ok = "''" in default or default == '' or default.strip("'") == ''
                check(f'{name} has the default empty string', ok, f'type {added[name][1]}, default {default!r}')
                check(f'{name} is a varchar(32)', '32' in added[name][1], added[name][1])
        rows_after = rows_of(ctx, after_columns)
        check('the stored rows are unchanged by the upgrade', rows_after == rows_before,
              f'before {rows_before} after {rows_after}')
        new_values = ctx.query(f"select minimum_value, maximum_value from {TABLE} order by field_id")
        check('the new columns hold the empty value for the stored rows',
              all(v == ('', '') for v in new_values), str(new_values))

        # a second activation changes nothing
        board.activate(ctx)
        again_columns = columns_of(ctx)
        check('a second activation changes no column', again_columns == after_columns)
        check('a second activation changes no row', rows_of(ctx, again_columns) == rows_before)
    finally:
        ctx.stop()
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
    print(f"upgrade {a.engine}: {len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
