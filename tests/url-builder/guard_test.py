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

  relative links in the templates (view/templates, every set folder) and in the scripts (jscripts): a link whose value starts
  with "//" (protocol-relative), with "/" (host-root), or with a file name ending in ".php" (relative), and an empty
  url_showcase. A link built from the board address ({{ mybb.settings.bburl }}) or from the builder ({{ url.* }}) is not one
  (decision D244). The allow-list's "relative" entries give the exact count per file and kind; Batch 5 (PWA) items are listed
  there with that reason and nothing else.

    python3 tests/url-builder/guard_test.py            (exit 0 when clean)
"""
import collections, json, os, re, sys, tempfile

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


ATTRIBUTE = re.compile(r'(?:href|action|src)="([^"]*)"')
EMPTY_SHOWCASE_URL = re.compile(r'url_showcase\s*=\s*""')


def relative_kinds(text):
    """{kind: count} of the links of one template or script that are not absolute and not built from the board address."""
    found = collections.Counter()
    for match in ATTRIBUTE.finditer(text):
        value = match.group(1).lstrip()
        if value.startswith('//'):
            found['protocol-relative'] += 1
        elif value.startswith('/'):
            found['host-root'] += 1
        elif re.match(r'[A-Za-z_][\w./-]*\.php', value):
            found['relative php'] += 1
    found['empty url_showcase'] += len(EMPTY_SHOWCASE_URL.findall(text))
    return {kind: count for kind, count in found.items() if count}


def relative_files():
    templates = os.path.join(PLUGIN, 'view', 'templates')
    for root, _dirs, files in os.walk(templates):
        for name in files:
            if name.endswith('.twig'):
                yield os.path.relpath(os.path.join(root, name), REPO)
    scripts = os.path.join(REPO, 'Upload', 'jscripts')
    for name in sorted(os.listdir(scripts)):
        if name.endswith('.js'):
            yield os.path.relpath(os.path.join(scripts, name), REPO)


def relative_violations(allow, overrides=None):
    """The files whose count of a relative kind differs from the allow-list's relative entries."""
    expected = {(e['file'], e['kind']): e['count'] for e in allow.get('relative', [])}
    problems, seen = [], set()
    for rel in relative_files():
        text = overrides[rel] if overrides and rel in overrides else open(os.path.join(REPO, rel), encoding='utf-8').read()
        counts = relative_kinds(text)
        for kind in set(counts) | {k for (f, k) in expected if f == rel}:
            seen.add((rel, kind))
            want = expected.get((rel, kind), 0)
            if counts.get(kind, 0) != want:
                problems.append(f'{rel}: relative link ({kind}) x{counts.get(kind, 0)} (allow-list {want})')
    for (rel, kind), want in expected.items():
        if (rel, kind) not in seen and want:
            problems.append(f'{rel}: relative link ({kind}) is listed {want} time(s) but the file is not scanned or does not exist')
    return problems


def main():
    allow = json.load(open(os.path.join(HERE, 'allow-list.json'), encoding='utf-8'))
    problems = violations(allow) + relative_violations(allow)
    print(f'{len(problems)} site(s) outside the allow-list:')
    for p in problems:
        print('  ', p)

    # negative control of the relative scan: one relative link added to a copy of a template is reported
    template = os.path.join('Upload', 'inc', 'plugins', 'myshowcase', 'view', 'templates', 'pageView.twig')
    control_text = open(os.path.join(REPO, template), encoding='utf-8').read() + '\n<a href="newreply.php?tid=1">x</a>\n'
    control_rel = relative_violations(allow, overrides={template: control_text})
    relative_control_ok = any(template in p and 'relative php' in p for p in control_rel)
    print('negative control (one added relative php link in a copy of pageView.twig is reported):', 'PASS' if relative_control_ok else 'FAIL')

    # negative control: one generateUrl( added to a copy of an allowed file must be reported
    routing = os.path.join('Upload', 'inc', 'plugins', 'myshowcase', 'src', 'Routing', 'Routing.php')
    control_text = open(os.path.join(REPO, routing), encoding='utf-8').read() + "\n// $x = $this->generateUrl('control');\n"
    control = violations(allow, overrides={routing: control_text})
    control_ok = any(routing in p and 'generateUrl(' in p for p in control)
    print('negative control (one added generateUrl( in a copy of Routing.php is reported):', 'PASS' if control_ok else 'FAIL')

    ok = control_ok and relative_control_ok and not problems
    print('PASS' if ok else 'FAIL', f'({len(problems)} site(s) outside the allow-list)')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
