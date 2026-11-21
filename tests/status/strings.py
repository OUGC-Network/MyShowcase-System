#!/usr/bin/env python3
"""Every sentence the visitor reads is in the language files, and every key a page reads is defined (B8b).

  K  each language key the PHP code (->language->KEY, $lang->KEY, lang->KEY) and the Twig templates (lang.KEY) read is defined in
     the English language file of the plugin (dynamic keys built from a variable are not checked);
  P  no key still carries the old spelling "purse" (the purge button's keys: entry and comment);

The scan is static and does not depend on a database, so it runs once, not per engine.

    python3 tests/status/strings.py

Exit code 1 if a check fails.
"""
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
PLUGIN = os.path.join(REPO, 'Upload', 'inc', 'plugins', 'myshowcase')
LANG = os.path.join(REPO, 'Upload', 'inc', 'languages', 'english', 'myshowcase.lang.php')

DEFINED = re.compile(r"^\s*'([A-Za-z0-9_]+)'\s*=>", re.M)
DEFINED_L = re.compile(r"\$l\['([A-Za-z0-9_]+)'\]\s*=")
PHP_REF = re.compile(r"(?:->language|\$lang|\blang|->lang)->([A-Za-z_][A-Za-z0-9_]*)\b")
TWIG_REF = re.compile(r"\blang\.([A-Za-z_][A-Za-z0-9_]*)\b")
# Keys that MyBB's own language files define and the plugin reads too (not myshowcase's to define)
MYBB_KEYS = re.compile(r'^(error_|nopermission|board|invalid|bad|no|yes|go|cancel|submit|delete|edit|view|reply|forum|thread|post|user|username|password)')


def files(folder, suffixes):
    for root, _dirs, names in os.walk(folder):
        if '/vendor' in root.replace(os.sep, '/'):
            continue
        for n in names:
            if n.endswith(suffixes):
                yield os.path.join(root, n)


def main():
    failures, checks = [], 0
    defined = set()
    for lang_file in files(os.path.join(REPO, 'Upload', 'inc', 'languages', 'english'), ('.php',)):
        text = open(lang_file, encoding='utf-8').read()
        defined |= set(DEFINED.findall(text)) | set(DEFINED_L.findall(text))

    refs = {}
    for path in files(PLUGIN, ('.php', '.twig')):
        if '/vendor/' in path.replace(os.sep, '/') or path.endswith('.lang.php'):
            continue
        text = open(path, encoding='utf-8').read()
        for pat in (PHP_REF, TWIG_REF):
            for key in pat.findall(text):
                if key.startswith('myshowcase') and not MYBB_KEYS.match(key):
                    refs.setdefault(key, path)

    for key in sorted(refs):
        checks += 1
        if key not in defined:
            failures.append(f'K {key} is read in {os.path.relpath(refs[key], REPO)} but not defined in the English language file')

    for key in sorted(defined):
        checks += 1
        if 'purse' in key:
            failures.append(f'P {key} still uses the old spelling "purse"')

    for f in failures:
        print('FAIL', f)
    print(f'\n{checks - len(failures)} passed, {len(failures)} failed (strings, {checks} checks)')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
