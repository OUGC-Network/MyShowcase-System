#!/usr/bin/env python3
"""Every sentence the visitor reads is in the language files, and every key a page reads is defined (B8b).

  K  each language key the PHP code (->language->KEY, $lang->KEY, lang->KEY) and the Twig templates (lang.KEY) read is defined in
     the English language file of the plugin (dynamic keys built from a variable are not checked);
  P  no key still carries the old spelling "purse" (the purge button's keys: entry and comment);
  C  no camelCase admin key (myShowcaseAdmin*) remains in the plugin's PHP, Twig or language files: the admin keys are
     myshowcase_admin_* (decision D303);

The scan is static and does not depend on a database, so it runs once, not per engine.

    python3 tests/status/strings.py

Exit code 1 if a check fails.
"""
import json
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

    camel = re.compile(r'\bmyShowcaseAdmin[A-Za-z0-9_]*')
    for path in list(files(PLUGIN, ('.php', '.twig'))) + list(files(os.path.join(REPO, 'Upload', 'inc', 'languages'), ('.php',))):
        if '/vendor/' in path.replace(os.sep, '/'):
            continue
        for number, line in enumerate(open(path, encoding='utf-8').read().splitlines(), 1):
            for key in camel.findall(line):
                checks += 1
                failures.append(f'C {key} at {os.path.relpath(path, REPO)}:{number}')

    # M (MSG, D146, D302): a user-facing English sentence is not written in the PHP of the services and controllers. A sentence
    # is a quoted text of three or more words with a lowercase word, not in a line that throws, dies, logs or sends a header;
    # the allow-list (strings-allow.json) names each justified exception with its reason.
    allow = json.load(open(os.path.join(HERE, 'strings-allow.json'), encoding='utf-8'))
    allowed = {(e['file'], e['text']) for e in allow}
    sentence = re.compile(r"'(?=[^'\n]*\b[a-z])([A-Z][A-Za-z]*(?:[ ,'\-][A-Za-z0-9,:;()'\-]+){2,}[.!?]?)'")
    skip = re.compile(r'throw new|die\(|error_log\(|header\(|LogicException|RuntimeException')
    for sub in ('Service', 'Controller'):
        for path in files(os.path.join(PLUGIN, 'src', sub), ('.php',)):
            rel = os.path.relpath(path, REPO).replace(os.sep, '/')
            for number, line in enumerate(open(path, encoding='utf-8').read().splitlines(), 1):
                stripped = line.strip()
                if skip.search(line) or stripped.startswith(('//', '*', '/*')):
                    continue
                for text in sentence.findall(line):
                    if (rel, text) in allowed:
                        continue
                    checks += 1
                    failures.append(f'M {text!r} at {rel}:{number} (a user-facing English sentence in a service or controller)')

    for f in failures:
        print('FAIL', f)
    print(f'\n{checks - len(failures)} passed, {len(failures)} failed (strings, {checks} checks)')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
