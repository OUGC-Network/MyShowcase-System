#!/usr/bin/env python3
"""Static guard of the one URL builder (decisions D224, D237, D241).

Every link the plugin generates goes through Routing::url(). This test reads the plugin's PHP and Twig files, without a
board, and fails when a call that builds a link is written outside the builder and the allow-list:

  generateUrl(        a route path (only the builder may call it)
  showcase_url        the showcase's stored base (only the builder reads it, plus the entries of the allow-list)
  url.showcase        the Twig prefix of plugin links (a link is already full, so no template adds a prefix)

The allow-list (allow-list.json) gives, per file and pattern, the exact count and a reason. A new occurrence, or a removed
one, changes the count and fails. A negative control runs the same scan over a copy of a file with one call added, and the
run fails if that copy is not reported.

    python3 tests/url-builder/guard_test.py            (exit 0 when clean)
"""
import json, os, re, sys, tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
PLUGIN = os.path.join(REPO, 'Upload', 'inc', 'plugins', 'myshowcase')

PATTERNS = {
    'generateUrl(': re.compile(r'generateUrl\('),
    'showcase_url': re.compile(r'(?<![A-Za-z_])showcase_url\b'),
    'url.showcase': re.compile(r'\burl\.showcase\b'),
}
PATTERNS_BY_KIND = {'php': ['generateUrl(', 'showcase_url'], 'twig': ['showcase_url', 'url.showcase']}


def kind_of(path):
    if path.endswith('.php'):
        return 'php'
    if path.endswith('.twig'):
        return 'twig'
    return None


def scan_text(text, kind):
    """{pattern: count} of the forbidden patterns of one file's text."""
    return {p: len(PATTERNS[p].findall(text)) for p in PATTERNS_BY_KIND[kind]}


def plugin_files():
    for root, dirs, files in os.walk(PLUGIN):
        dirs[:] = [d for d in dirs if d not in ('vendor', 'imports', 'node_modules')]
        for name in files:
            full = os.path.join(root, name)
            if kind_of(name):
                yield os.path.relpath(full, REPO), kind_of(name)


def violations(allow, overrides=None):
    """The files whose count of a pattern differs from the allow-list (overrides: {relpath: text} read instead of disk)."""
    expected = {(e['file'], e['pattern']): e['count'] for e in allow['entries']}
    problems = []
    seen = set()
    for rel, kind in plugin_files():
        text = overrides[rel] if overrides and rel in overrides else open(os.path.join(REPO, rel), encoding='utf-8').read()
        counts = scan_text(text, kind)
        for pattern, count in counts.items():
            seen.add((rel, pattern))
            want = expected.get((rel, pattern), 0)
            if count != want:
                problems.append(f'{rel}: {pattern} x{count} (allow-list {want})')
    for (rel, pattern), want in expected.items():
        if (rel, pattern) not in seen and want:
            problems.append(f'{rel}: {pattern} is listed {want} time(s) but the file is not scanned or does not exist')
    return problems


def main():
    allow = json.load(open(os.path.join(HERE, 'allow-list.json'), encoding='utf-8'))
    problems = violations(allow)
    print(f'{len(problems)} site(s) outside the allow-list:')
    for p in problems:
        print('  ', p)

    # negative control: one generateUrl( added to a copy of an allowed file must be reported
    routing = os.path.join('Upload', 'inc', 'plugins', 'myshowcase', 'src', 'Routing', 'Routing.php')
    control_text = open(os.path.join(REPO, routing), encoding='utf-8').read() + "\n// $x = $this->generateUrl('control');\n"
    control = violations(allow, overrides={routing: control_text})
    control_ok = any(routing in p and 'generateUrl(' in p for p in control)
    print('negative control (one added generateUrl( in a copy of Routing.php is reported):', 'PASS' if control_ok else 'FAIL')

    ok = control_ok and not problems
    print('PASS' if ok else 'FAIL', f'({len(problems)} site(s) outside the allow-list)')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
