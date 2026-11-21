#!/usr/bin/env python3
"""Every error page of the plugin's controllers names its HTTP status (B8a, decision P2).

The status codes are: 404 for an unknown thing (an entry, a comment, a page, an attachment), 400 for refused input (a missing
file, an invalid upload, a bad import or export request), 401 for a guest who needs to log in, 403 for a member without the
permission, 500 for a fault of the board or the plugin. A call of ->error() without a second argument answers MyBB's default
(400 for a page, 500 for the plugin's own error()), so the status is then not a decision and the call is refused here.

The scan is static (it reads the PHP sources); it does not depend on a database, so it runs once, not per engine.

    python3 tests/status/status_codes.py

Exit code 1 if a call has no status.
"""
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
SOURCE = os.path.join(REPO, 'Upload', 'inc', 'plugins', 'myshowcase', 'src', 'Controller')

CALL = re.compile(r'->error\(')


def call_args(text, start):
    """The text between the parentheses of a call that starts at start (the index of its '(')."""
    depth, i = 0, start
    while i < len(text):
        if text[i] == '(':
            depth += 1
        elif text[i] == ')':
            depth -= 1
            if depth == 0:
                return text[start + 1:i]
        i += 1
    return ''


def has_status(args):
    """A second argument at the top level of the call (a comma outside any nested bracket or string)."""
    depth, quote = 0, None
    for i, ch in enumerate(args):
        if quote:
            if ch == quote and args[i - 1] != '\\':
                quote = None
        elif ch in '"\'':
            quote = ch
        elif ch in '([{':
            depth += 1
        elif ch in ')]}':
            depth -= 1
        elif ch == ',' and depth == 0 and args[i + 1:].strip():
            return True
    return False


def main():
    failures, checked = [], 0
    for name in sorted(os.listdir(SOURCE)):
        if not name.endswith('.php'):
            continue
        path = os.path.join(SOURCE, name)
        text = open(path, encoding='utf-8').read()
        for m in CALL.finditer(text):
            checked += 1
            line = text.count('\n', 0, m.start()) + 1
            args = call_args(text, m.end() - 1)
            if has_status(args):
                continue
            failures.append(f'{name}:{line} calls ->error() without a status')

    for f in failures:
        print('FAIL', f)
    print(f'\n{checked - len(failures)} passed, {len(failures)} failed (error statuses, {checked} calls)')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
