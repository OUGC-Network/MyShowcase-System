"""Installs a scratch MyBB 1.9 board with MyShowcase from this working tree, on one database engine.

Nothing here touches the source board: it is copied (without .git and tests) into a scratch directory and installed
there with MyBB's own CLI installer, using an admin account and password generated for the run.
"""
import os, secrets, shutil, subprocess, sqlite3, sys, time, urllib.request

REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
PLUGIN = os.path.join(REPO, 'Upload')
PL_URL = 'https://raw.githubusercontent.com/frostschutz/PluginLibrary/master/inc/plugins/pluginlibrary.php'

STUB = r'''<?php
// STUB of PluginLibrary (only what MyShowcase calls). Used with --pluginlibrary stub.
class PluginLibrary
{
    public $version = 13;
    public function settings($name, $title, $description, $list, $replace_all = false)
    {
        global $db;
        $db->delete_query('settinggroups', "name='{$db->escape_string($name)}'");
        $gid = (int)$db->insert_query('settinggroups', ['name' => $name, 'title' => $title, 'description' => $description, 'disporder' => 99, 'isdefault' => 0]);
        $db->delete_query('settings', "name LIKE '{$db->escape_string($name)}_%'");
        $i = 0;
        foreach ($list as $key => $s) {
            $db->insert_query('settings', ['name' => $name . '_' . $key, 'title' => $s['title'], 'description' => $s['description'], 'optionscode' => $s['optionscode'], 'value' => $s['value'], 'disporder' => ++$i, 'gid' => $gid]);
        }
        rebuild_settings();
    }
    public function settings_delete($name, $greedy = false) { global $db; $db->delete_query('settinggroups', "name='{$db->escape_string($name)}'"); $db->delete_query('settings', "name LIKE '{$db->escape_string($name)}_%'"); rebuild_settings(); }
    public function templates_delete($name, $greedy = false) { }
}
$PL = new PluginLibrary();
'''


class Ctx:
    """Everything a flow needs about one installed board."""

    def __init__(self, engine, board, url, user, password, dbname, env):
        self.engine, self.board, self.url, self.user, self.password, self.dbname, self.env = engine, board, url, user, password, dbname, env
        self.server = None

    # ---- SQL helpers (out-of-band, to check what the ACP did) ----
    def _cli(self, sql, quiet_rows):
        e = self.env
        if self.engine == 'mysql':
            cmd = [e['MYSQL_BIN'], '--no-defaults', f"-h{e['MYSQL_HOST']}", f"-P{e['MYSQL_PORT']}", f"-u{e['MYSQL_USER']}"]
            if e['MYSQL_PASSWORD']: cmd.append(f"-p{e['MYSQL_PASSWORD']}")
            cmd += (['-N', '-B'] if quiet_rows else []) + [self.dbname, '-e', sql]
        else:
            cmd = [e['PSQL_BIN'], '-h', e['PGHOST'], '-p', e['PGPORT'], '-U', e['PGUSER'], '-d', self.dbname] + (['-At', '-F', '\t'] if quiet_rows else []) + ['-c', sql]
        out = subprocess.run(cmd, capture_output=True, text=True, env={**os.environ, 'LC_ALL': 'en_US.UTF-8'})
        if out.returncode:
            raise RuntimeError(out.stderr)
        return out.stdout

    def query(self, sql):
        if self.engine == 'sqlite':
            c = sqlite3.connect(os.path.join(self.board, 'inc', 'board.db'))
            rows = [tuple('' if v is None else str(v) for v in r) for r in c.execute(sql)]
            c.close()
            return rows
        return [tuple(l.split('\t')) for l in self._cli(sql, True).splitlines() if l != '']

    def execute(self, sql):
        if self.engine == 'sqlite':
            c = sqlite3.connect(os.path.join(self.board, 'inc', 'board.db')); c.execute(sql); c.commit(); c.close()
        else:
            self._cli(sql, False)

    def stop(self):
        if self.server:
            self.server.terminate()
            self.server.wait()


def db_env():
    e = os.environ
    return {
        'MYSQL_BIN': e.get('MYSQL_BIN', 'mysql'), 'MYSQL_HOST': e.get('MYSQL_HOST', '127.0.0.1'), 'MYSQL_PORT': e.get('MYSQL_PORT', '3306'),
        'MYSQL_USER': e.get('MYSQL_USER', 'root'), 'MYSQL_PASSWORD': e.get('MYSQL_PASSWORD', ''),
        'PSQL_BIN': e.get('PSQL_BIN', 'psql'), 'PGHOST': e.get('PGHOST', '127.0.0.1'), 'PGPORT': e.get('PGPORT', '5432'), 'PGUSER': e.get('PGUSER', e.get('USER', 'postgres')),
    }


def pluginlibrary(scratch, mode, given=None):
    """Contents of inc/plugins/pluginlibrary.php: the real library (downloaded once from its official repository), or the stub."""
    if mode == 'stub':
        return STUB
    path = given or os.path.join(scratch, 'pluginlibrary.php')
    if not os.path.exists(path):
        urllib.request.urlretrieve(PL_URL, path)
    code = open(path).read()
    if 'class PluginLibrary' not in code:
        raise RuntimeError('not the PluginLibrary file: ' + path)
    return code


def make_board(engine, mybb, scratch, port, pl_mode='real', pl_file=None, dbname=None, database_server='mysql'):
    env = db_env()
    dbname = dbname or f'myshowcase_verify_{engine}'
    board = os.path.join(scratch, f'board_{engine}')
    shutil.rmtree(board, ignore_errors=True)
    shutil.copytree(mybb, board, ignore=shutil.ignore_patterns('.git', 'tests', 'node_modules', '.claude'), symlinks=True)
    for f in ('inc/config.php', 'inc/settings.php', 'install/lock'):
        p = os.path.join(board, f)
        if os.path.exists(p): os.remove(p)
    for f in ('inc/settings.php', 'inc/config.php'):
        open(os.path.join(board, f), 'w').close(); os.chmod(os.path.join(board, f), 0o666)
    user, password = 'admin', 'T-' + secrets.token_urlsafe(18)
    url = f'http://127.0.0.1:{port}'
    ctx = Ctx(engine, board, url, user, password, dbname, env)
    common = ['-p', 'db_table_prefix=mybb_', '-p', 'bbname=Test', '-p', f'bburl={url}', '-p', 'adminemail=admin@example.com', '-p', f'account_username={user}',
              '-p', 'account_email=admin@example.com', '-p', f'account_password={password}', '-p', 'acp_pin=']
    if engine == 'sqlite':
        dbargs = ['-p', 'db_engine=sqlite', '-p', f'db_path={board}/inc/board.db']
    elif engine == 'mysql':
        subprocess.run([env['MYSQL_BIN'], '--no-defaults', f"-h{env['MYSQL_HOST']}", f"-P{env['MYSQL_PORT']}", f"-u{env['MYSQL_USER']}"] + ([f"-p{env['MYSQL_PASSWORD']}"] if env['MYSQL_PASSWORD'] else []) +
                       ['-e', f'DROP DATABASE IF EXISTS {dbname}; CREATE DATABASE {dbname}'], check=True)
        dbargs = ['-p', 'db_engine=mysql', '-p', f"db_host={env['MYSQL_HOST']}:{env['MYSQL_PORT']}", '-p', f"db_user={env['MYSQL_USER']}", '-p', f"db_password={env['MYSQL_PASSWORD']}", '-p', f'db_name={dbname}']
    else:
        base = [env['PSQL_BIN'], '-h', env['PGHOST'], '-p', env['PGPORT'], '-U', env['PGUSER'], '-d', 'postgres', '-q']
        subprocess.run(base + ['-c', f'DROP DATABASE IF EXISTS {dbname}', '-c', f'CREATE DATABASE {dbname}'], check=True, env={**os.environ, 'LC_ALL': 'en_US.UTF-8'})
        dbargs = ['-p', 'db_engine=pgsql', '-p', f"db_host={env['PGHOST']}:{env['PGPORT']}", '-p', f"db_user={env['PGUSER']}", '-p', 'db_password=', '-p', f'db_name={dbname}']
    r = subprocess.run(['php', 'bin/cli', 'install', '-n'] + dbargs + common, cwd=board, capture_output=True, text=True)
    if 'Installed' not in subprocess.run(['php', 'bin/cli', 'status'], cwd=board, capture_output=True, text=True).stdout:
        raise RuntimeError('install failed:\n' + r.stdout[-1500:] + r.stderr[-500:])
    # the plugin: symlinks into this working tree; imports/ is a private copy the tests may edit
    plugin_dir = os.path.join(board, 'inc', 'plugins', 'myshowcase')
    src = os.path.join(PLUGIN, 'inc', 'plugins', 'myshowcase')
    if not os.path.isdir(os.path.join(src, 'vendor')):
        subprocess.run(['composer', 'install', '--no-interaction', '--no-progress'], cwd=src, check=True)
    os.makedirs(plugin_dir)
    for n in os.listdir(src):
        if n != 'imports':
            os.symlink(os.path.join(src, n), os.path.join(plugin_dir, n))
    shutil.copytree(os.path.join(src, 'imports'), os.path.join(plugin_dir, 'imports'))
    os.symlink(os.path.join(PLUGIN, 'inc', 'plugins', 'myshowcase.php'), os.path.join(board, 'inc', 'plugins', 'myshowcase.php'))
    os.makedirs(os.path.join(board, 'admin', 'modules'), exist_ok=True)
    os.symlink(os.path.join(PLUGIN, 'admin', 'modules', 'myshowcase'), os.path.join(board, 'admin', 'modules', 'myshowcase'))
    lang = os.path.join(PLUGIN, 'inc', 'languages', 'english')
    for sub in ('', 'admin'):
        d = os.path.join(lang, sub)
        for n in os.listdir(d):
            if 'myshowcase' in n and n.endswith('.lang.php'):
                os.symlink(os.path.join(d, n), os.path.join(board, 'inc', 'languages', 'english', sub, n))
    open(os.path.join(board, 'inc', 'plugins', 'pluginlibrary.php'), 'w').write(pluginlibrary(scratch, pl_mode, pl_file))
    ctx.server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', board], stdout=open(os.path.join(scratch, f'php_{engine}.log'), 'w'), stderr=subprocess.STDOUT)
    time.sleep(1.5)
    return ctx


def activate(ctx):
    sys.path.insert(0, os.path.dirname(__file__))
    from acp import Acp, text
    a = Acp(ctx.url, ctx.user, ctx.password)
    a.get('/admin/index.php?module=config-plugins')
    r = a.get('/admin/index.php?module=config-plugins&action=activate&plugin=myshowcase&my_post_key=' + a.post_key)
    t = text(r['body'])
    if r['status'] != 200 or 'MyBB SQL Error' in t or 'Internal Error' in t:
        raise RuntimeError('activation failed: ' + t[:600])
    return a
