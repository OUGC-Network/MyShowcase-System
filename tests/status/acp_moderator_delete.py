#!/usr/bin/env python3
"""The ACP moderator delete link deletes the moderator (NEW5B item 22).

The moderator list of the showcase admin page links to "action=update&type=delete&moderator_id=N" with my_post_key. That link is
a GET. The delete is handled in the switch that only runs for a POST request, so a click on the link never reached
deleteModerator(). This check is static: it reads AdminManager::showcaseCreateUpdate() and requires that the call to
deleteModerator() is not inside the block that runs only for a POST request.

    python3 tests/status/acp_moderator_delete.py

Exit code 1 if the call is only reachable from a POST request.
"""
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
SOURCE = os.path.join(REPO, 'Upload', 'inc', 'plugins', 'myshowcase', 'src', 'Service', 'AdminManager.php')

POST_BLOCK = "if ($this->mybb->request_method === 'post') {"
DELETE_CALL = 'deleteModerator('


def block_bounds(text, start):
    """The index of the '{' that opens the block at start, and the index of its closing '}'."""
    open_index = text.index('{', start)
    depth = 0
    for index in range(open_index, len(text)):
        if text[index] == '{':
            depth += 1
        elif text[index] == '}':
            depth -= 1
            if depth == 0:
                return open_index, index
    raise ValueError('unbalanced block')


def main():
    with open(SOURCE, encoding='utf-8') as handle:
        text = handle.read()

    function_start = text.index('private function showcaseCreateUpdate(): void')
    post_start = text.index(POST_BLOCK, function_start)
    _, post_end = block_bounds(text, post_start)

    failures = []
    checks = 0

    for match in re.finditer(re.escape(DELETE_CALL), text):
        position = match.start()
        if position < function_start:
            continue
        checks += 1
        if post_start <= position <= post_end:
            line = text.count('\n', 0, position) + 1
            failures.append(f'deleteModerator() at AdminManager.php:{line} is only reached by a POST request')

    if checks == 0:
        failures.append('no deleteModerator() call found in showcaseCreateUpdate()')

    for message in failures:
        print(f'FAIL {message}')
    print(f'\n{checks - len(failures)} passed, {len(failures)} failed (acp_moderator_delete, {max(checks, 1)} checks)')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
