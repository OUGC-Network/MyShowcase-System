#!/usr/bin/env python3
"""Runs the regression suite of tests/ in the order of tests/FINAL-REGRESSION.md, engine by engine (decision D352).

    python3 tests/run_final_regression.py --php /path/to/php8.2 --logs DIR
        [--engines sqlite,mysql,pgsql] [--mybb DIR] [--mysql-port N] [--pg-port N] [--pg-user NAME]
        [--only NAME[,NAME...]] [--from NAME]

  --php         the PHP 8.2 binary. It is also put first on PATH for the runners, which call 'php'. Required.
  --logs        the folder for the logs (one file per run) and the scratch folders. Required.
  --engines     comma list of sqlite, mysql, pgsql. Default: sqlite.
  --mybb        a MyBB 1.9 source tree (its vendor/ folder included), passed to every runner that needs a board. Required when a
                board runner is selected. Default: the MYBB_SOURCE variable.
  --mysql-port  the port of the MySQL or MariaDB test server the owner started. Required when mysql is selected. No default:
                the run never connects to a server the owner did not name. MYSQL_HOST is 127.0.0.1; MYSQL_USER and
                MYSQL_PASSWORD are read from the environment as the runners do.
  --pg-port     the port of the PostgreSQL test server the owner started. Required when pgsql is selected. No default.
  --pg-user     the PostgreSQL role. Default: the runners' own default ($USER).
  --only        run only these runners (their names, as printed in the table, comma separated).
  --from        resume from this runner (its name); the runs before it in the order are skipped.

Each run's full output goes to its own log file in --logs. The console shows one line per run and, at the end, a table
(runner, engine, passed, failed, exit code) and the FAIL lines of each failed run (at most 40 lines per run; a row of tildes
is shown with the 3 lines above it). A run that reports no checks counts as failed (D372), unless it is listed in NO_COUNT.
The runners' counts: "N passed, M failed"; "N checks, M failed" (url-mode/run.php: passed = N - M); "cells: N; failing: M"
(access_matrix.py: passed = N - M, the matrix's cells); "cells {'pass': N, ...}" (fields/http/run.py: its pass cells).
Exit code 0 only when every run exits 0 and reports checks.

Engine-free runners run once (engine "static"). Board runners run once per selected engine, in the order sqlite, mysql,
pgsql. Runners are never run in parallel: each one starts and stops its own PHP server on its own port.
"""
import argparse, os, re, subprocess, sys

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
TESTS = os.path.join(REPO, 'tests')
ENGINE_ORDER = ['sqlite', 'mysql', 'pgsql']

# engine-free runners, in order: (path, kind). kind php: run with --php; py: run with this Python.
FREE = [
    ('tests/util/run.php', 'php'),
    ('tests/util/password_grant.php', 'php'),
    ('tests/post-hash/run.php', 'php'),
    ('tests/url-mode/run.php', 'php'),
    ('tests/xml-file/run.php', 'php'),
    ('tests/exports-storage/run.php', 'php'),
    ('tests/import/run.php', 'php'),
    ('tests/import/fetcher/run.php', 'php'),
    ('tests/json-sync/run.php', 'php'),
    ('tests/fields/unit/run.php', 'php'),
    ('tests/status/field_factory_create.php', 'php'),
    ('tests/status/access_log_delete.php', 'php'),
    ('tests/fixtures/seeds/coverage_test.py', 'py'),
    ('tests/url-builder/guard_test.py', 'py'),
    ('tests/status/status_codes.py', 'py'),
    ('tests/status/strings.py', 'py'),
    ('tests/status/legacy.py', 'py'),
    ('tests/status/acp_moderator_delete.py', 'py'),
]

ALL = ['sqlite', 'mysql', 'pgsql']
# board runners, in order: (path, engines, extra arguments). Each takes --engine, --mybb and --scratch; the port is its own default.
BOARD = [
    ('tests/fields/http/run.py', ALL, []),
    ('tests/fields/http/acp_bounds_test.py', ALL, []),
    ('tests/features/http/run.py', ALL, []),
    ('tests/features/http/url_mode.py', ALL, []),
    ('tests/features/http/url_absolute.py', ALL, []),
    ('tests/features/http/quote_dates.py', ALL, []),
    ('tests/features/http/fresh_install.py', ALL, []),
    ('tests/features/http/pwa.py', ALL, []),
    ('tests/features/http/showcase_password.py', ALL, []),
    ('tests/features/http/guest_grant.py', ALL, []),
    ('tests/features/http/password_throttle.py', ALL, []),
    ('tests/features/http/moderator_areas.py', ALL, []),
    ('tests/features/http/moderation_restore.py', ALL, []),
    ('tests/features/http/new5_board.py', ALL, []),
    ('tests/features/http/unapproved_attachments.py', ALL, []),
    ('tests/features/http/attachment_rules.py', ALL, []),
    ('tests/features/http/forum_embed.py', ALL, []),
    ('tests/json-sync/http/run.py', ALL, []),
    ('tests/import/http/run.py', ALL, []),
    ('tests/import/http/second_board.py', ALL, []),
    ('tests/import/http/abort_test.py', ALL, []),
    ('tests/import/http/access_matrix.py', ALL, []),
    ('tests/harness/cleanup_test.py', ALL, []),
    # the interrupt harness: a SIGINT during each of four feature runs, on SQLite only (decision D256)
    ('tests/harness/interrupt_test.py', ['sqlite'], ['--script', 'tests/features/http/run.py', '--port', '18997']),
    ('tests/harness/interrupt_test.py', ['sqlite'], ['--script', 'tests/features/http/quote_dates.py', '--port', '18997']),
    ('tests/harness/interrupt_test.py', ['sqlite'], ['--script', 'tests/features/http/moderation_restore.py', '--port', '18997']),
    ('tests/harness/interrupt_test.py', ['sqlite'], ['--script', 'tests/features/http/new5_board.py', '--port', '18997']),
]

PASS_PATTERNS = [re.compile(r'(\d+) passed'), re.compile(r'checks passed: (\d+)')]
FAIL_PATTERNS = [re.compile(r'(\d+) failed')]
FAIL_LINE = re.compile(r'^\s*(FAIL\b|FAILED:|  FAILED)')
RULE_ROW = re.compile(r'^\s*~{5,}\s*$')  # MyBB's CLI error block opens and closes with a row of tildes
RULE_IN = re.compile(r'~{5,}')

# The summary line each runner prints, in the formats the runners really use (D372). The last matching line of a log counts;
# each entry gives (passed, failed) from its match. A log without one of these lines is read with PASS_PATTERNS and FAIL_PATTERNS.
SUMMARY = [
    (re.compile(r'(\d+) passed, (\d+) failed'), lambda m: (int(m[1]), int(m[2]))),
    (re.compile(r'(\d+) checks, (\d+) failed'), lambda m: (int(m[1]) - int(m[2]), int(m[2]))),  # url-mode/run.php
    (re.compile(r'cells: (\d+); failing: (\d+)'), lambda m: (int(m[1]) - int(m[2]), int(m[2]))),  # access_matrix.py
    (re.compile(r"cells \{'pass': (\d+)(?:, 'fail': (\d+))?"), lambda m: (int(m[1]), int(m[2] or 0))),  # fields/http/run.py
]

# Runners that truly have no countable checks may report none; the exit code is their evidence. Each entry gives the reason.
# Empty: no run of the owner's 91 logs reported zero checks once SUMMARY is read, so a run that reports 0 checks counts as failed.
NO_COUNT = {}


def runner_names():
    """A name is the file's base name without extension, or its path under tests/ when the base name is not unique."""
    paths = list(dict.fromkeys([p for p, _ in FREE] + [p for p, _, _ in BOARD]))
    base = [os.path.splitext(os.path.basename(p))[0] for p in paths]
    names = {}
    for p, b in zip(paths, base):
        names[p] = b if base.count(b) == 1 else os.path.splitext(os.path.relpath(os.path.join(REPO, p), TESTS))[0]
    return names


def plan(engines):
    """The runs in order: (path, kind, engine, extra)."""
    runs = [(p, kind, 'static', []) for p, kind in FREE]
    for engine in ENGINE_ORDER:
        if engine not in engines:
            continue
        for p, allowed, extra in BOARD:
            if engine in allowed:
                runs.append((p, 'board', engine, extra))
    return runs


def check_php(php):
    if not os.path.isfile(php) or not os.access(php, os.X_OK):
        sys.exit(f'--php: not an executable file: {php}')
    version = subprocess.run([php, '-r', 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;'], capture_output=True, text=True).stdout.strip()
    if version != '8.2':
        sys.exit(f'--php must be a PHP 8.2 binary; {php} is {version or "unknown"}')


def safe_name(name):
    """A runner name as one file or folder name: every character outside [A-Za-z0-9._-] becomes '-'."""
    return re.sub(r'[^A-Za-z0-9._-]', '-', name)


def report_lines(lines):
    """The FAIL lines of a run, with the 3 lines before each row of tildes. A row of tildes is not the error: MyBB's CLI block
    puts the title and the message above it, so the lines above are the ones that name the error (D372)."""
    out = []
    rows = 0
    for i, line in enumerate(lines):
        if FAIL_LINE.match(line):
            if RULE_IN.search(line):
                out.append(RULE_IN.sub('', line).rstrip() + '   [its rule row: the 3 lines before it follow]')
                out.extend('    | ' + x.strip() for x in lines[max(0, i - 3):i] if x.strip())
            else:
                out.append(line)
        elif RULE_ROW.match(line) and rows < 2:
            rows += 1
            out.append('  a row of tildes; the 3 lines before it:')
            out.extend('    | ' + x.strip() for x in lines[max(0, i - 3):i] if x.strip())
    return out


def summarize(text, code):
    lines = text.splitlines()
    fail_lines = report_lines(lines)
    counted = None
    for line in reversed(lines):
        for pattern, how in SUMMARY:
            m = pattern.search(line)
            if m:
                counted = how(m)
                break
        if counted:
            break
    if counted:
        n_pass, n_fail = counted
    else:
        passed = [int(x) for pat in PASS_PATTERNS for x in pat.findall(text)]
        failed = [int(x) for pat in FAIL_PATTERNS for x in pat.findall(text)]
        n_pass = passed[-1] if passed else sum(1 for l in lines if l.startswith('PASS '))
        n_fail = failed[-1] if failed else sum(1 for l in lines if FAIL_LINE.match(l))
    if code != 0 and n_fail == 0:
        n_fail = 1  # the run failed without a count of its own: its exit code is the evidence
    return n_pass, n_fail, fail_lines


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument('--php', required=True)
    p.add_argument('--logs', required=True)
    p.add_argument('--engines', default='sqlite')
    p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''))
    p.add_argument('--mysql-port', type=int, default=None)
    p.add_argument('--pg-port', type=int, default=None)
    p.add_argument('--pg-user', default='')
    p.add_argument('--only', default='')
    p.add_argument('--from', dest='start_from', default='')
    a = p.parse_args()

    engines = [e.strip() for e in a.engines.split(',') if e.strip()]
    bad = [e for e in engines if e not in ALL]
    if bad:
        sys.exit(f'--engines: unknown engine {bad}; use sqlite, mysql, pgsql')
    if 'mysql' in engines and a.mysql_port is None:
        sys.exit('--mysql-port is required when mysql is selected (the port of the MySQL server you started)')
    if 'pgsql' in engines and a.pg_port is None:
        sys.exit('--pg-port is required when pgsql is selected (the port of the PostgreSQL server you started)')
    if engines and not a.mybb:
        sys.exit('--mybb is required: a MyBB 1.9 source tree with its vendor/ folder')
    if a.mybb and not os.path.isdir(a.mybb):
        sys.exit(f'--mybb: not a folder: {a.mybb}')
    check_php(a.php)
    os.makedirs(a.logs, exist_ok=True)

    names = runner_names()
    runs = plan(engines)
    if a.only:
        wanted = {n.strip() for n in a.only.split(',') if n.strip()}
        known = set(names.values())
        unknown = sorted(wanted - known)
        if unknown:
            sys.exit(f'--only: unknown runner(s) {unknown}; the names are printed in the table of a run and listed in tests/FINAL-REGRESSION.md')
        runs = [r for r in runs if names[r[0]] in wanted]
    if a.start_from:
        idx = next((i for i, r in enumerate(runs) if names[r[0]] == a.start_from), None)
        if idx is None:
            sys.exit(f'--from: {a.start_from} is not among the selected runs')
        runs = runs[idx:]

    env = dict(os.environ)
    env['PATH'] = os.path.dirname(os.path.abspath(a.php)) + os.pathsep + env.get('PATH', '')
    env['MYBB_SOURCE'] = a.mybb
    env['MYSQL_HOST'] = '127.0.0.1'
    if a.mysql_port is not None:
        env['MYSQL_PORT'] = str(a.mysql_port)
    env['PGHOST'] = '127.0.0.1'
    if a.pg_port is not None:
        env['PGPORT'] = str(a.pg_port)
    if a.pg_user:
        env['PGUSER'] = a.pg_user

    results = []
    for number, (path, kind, engine, extra) in enumerate(runs, 1):
        name = names[path]
        label = f'{name} ({engine})' if engine != 'static' else name
        if kind == 'php':
            cmd = [a.php, os.path.join(REPO, path)]
        elif kind == 'py':
            cmd = [sys.executable, os.path.join(REPO, path)]
        else:
            scratch = os.path.join(a.logs, 'scratch', f'{number:02d}-{safe_name(name)}-{engine}')
            os.makedirs(scratch, exist_ok=True)
            cmd = [sys.executable, os.path.join(REPO, path), '--engine', engine, '--mybb', a.mybb, '--scratch', scratch] + extra
        # a runner's name can contain '/' (tests/util/run.php): the log file name must not make a folder of it
        log_path = os.path.join(a.logs, f'{number:02d}-{safe_name(name)}-{engine}.log')
        print(f'[{number:02d}/{len(runs)}] running {label} ...', flush=True)
        with open(log_path, 'w', encoding='utf-8') as log:
            log.write('$ ' + ' '.join(cmd) + '\n')
            log.flush()
            code = subprocess.run(cmd, cwd=REPO, env=env, stdout=log, stderr=subprocess.STDOUT).returncode
        text = open(log_path, encoding='utf-8', errors='replace').read()
        n_pass, n_fail, fail_lines = summarize(text, code)
        # a run that reports no checks counts as failed, unless the runner is listed in NO_COUNT with its reason (D372)
        zero = n_pass + n_fail == 0 and name not in NO_COUNT
        if zero:
            n_fail = 1
            fail_lines = ['no checks reported: the run counts as failed (D372); see the log'] + fail_lines
        results.append((name, engine, n_pass, n_fail, code, fail_lines, log_path, zero))
        print(f'      {label}: passed {n_pass}, failed {n_fail}, exit {code}, log {log_path}', flush=True)

    print('\n| runner | engine | passed | failed | exit code |')
    print('|---|---|---:|---:|---:|')
    for name, engine, n_pass, n_fail, code, _, _, _ in results:
        print(f'| {name} | {engine} | {n_pass} | {n_fail} | {code} |')
    for name, engine, _, n_fail, code, fail_lines, log_path, _ in results:
        if code != 0 or n_fail:
            print(f'\nFAIL lines of {name} ({engine}), log {log_path} (at most 40 lines):')
            for line in fail_lines[:40] or ['  (no FAIL line: the exit code is the only evidence; see the log)']:
                print('  ' + line.strip())
    overall = all(r[4] == 0 and not r[7] for r in results)
    print(f'\n{"ALL GREEN" if overall else "NOT GREEN"}: {sum(1 for r in results if r[4] == 0 and not r[7])} of {len(results)} runs exit 0 and report checks.')
    return 0 if overall else 1


if __name__ == '__main__':
    sys.exit(main())
