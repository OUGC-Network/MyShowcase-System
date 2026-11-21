#!/usr/bin/env python3
"""The showcase password is stored as a hash, and the ACP never shows it (PW, decision D306).

The General tab of the showcase form is the way a password is set. The checks:

  A  the form stores a password hash (password_hash), not the typed value, and the hash verifies the typed password;
  B  the form page shows neither the stored hash nor the typed password;
  C  saving the form with an empty password field keeps the stored password;
  D  the gate opens for the right password and not for a wrong one;
  E  changing the password cancels a guest's grant of the old password (the grant is bound to the stored value);
  F  the remove control clears the password, and a guest then sees the list without a gate.

    python3 tests/features/http/showcase_password.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Exit code 1 if a check fails.
"""
import argparse, json, os, re, subprocess, sys, time, urllib.request  # noqa: F401
from html.parser import HTMLParser  # noqa: F401

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'json-sync', 'http'))
sys.path.insert(0, HERE)

p = argparse.ArgumentParser()
p.add_argument('--engine', required=True, choices=['sqlite', 'mysql', 'pgsql'])
p.add_argument('--mybb', default=os.environ.get('MYBB_SOURCE', ''))
p.add_argument('--scratch', required=True)
p.add_argument('--port', type=int, default=0)
a = p.parse_args()
if not a.mybb or not os.path.isdir(a.mybb):
    sys.exit('give --mybb: a MyBB 1.9 source tree with its vendor/ folder')


class FormScraper(HTMLParser):
    """The forms of a page with their fields (the JSON-sync flows' scraper, copied: this suite does not import them)."""

    def __init__(self):
        super().__init__()
        self.forms, self.cur, self.sel, self.ta = [], None, None, None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.cur = {'action': a.get('action', ''), 'fields': {}, 'disabled': set()}
            self.forms.append(self.cur)
        elif self.cur is None:
            return
        elif tag == 'input' and a.get('name'):
            if 'disabled' in a:
                self.cur['disabled'].add(a['name'])
            ty = (a.get('type') or 'text').lower()
            if ty in ('submit', 'button', 'reset', 'image'):
                return
            if ty in ('checkbox', 'radio'):
                if 'checked' in a:
                    self.cur['fields'].setdefault(a['name'], []).append(a.get('value', 'on'))
            else:
                self.cur['fields'].setdefault(a['name'], []).append(a.get('value', ''))
        elif tag == 'select':
            self.sel = {'name': a.get('name'), 'selected': [], 'first': None}
            if 'disabled' in a and self.sel['name']:
                self.cur['disabled'].add(self.sel['name'])
        elif tag == 'option' and self.sel is not None:
            if self.sel['first'] is None:
                self.sel['first'] = a.get('value', '')
            if 'selected' in a:
                self.sel['selected'].append(a.get('value', ''))
        elif tag == 'textarea':
            self.ta = [a.get('name'), '']

    def handle_data(self, d):
        if self.ta is not None:
            self.ta[1] += d

    def handle_endtag(self, tag):
        if tag == 'select' and self.sel is not None and self.cur is not None:
            if self.sel['name']:
                vals = self.sel['selected'] or ([self.sel['first']] if self.sel['first'] is not None else [])
                if vals:
                    self.cur['fields'][self.sel['name']] = vals
            self.sel = None
        elif tag == 'textarea' and self.ta is not None:
            if self.ta[0]:
                self.cur['fields'][self.ta[0]] = [self.ta[1]]
            self.ta = None


def form_of(html, contains):
    """The form whose action contains the text."""
    scraper = FormScraper()
    scraper.feed(html)
    for f in scraper.forms:
        if contains in f['action']:
            return f
    raise RuntimeError(f'no form with {contains}')



FAILS, PASSES = [], 0
GATE = 'Enter the showcase password'


def ok(cond, message):
    global PASSES
    print(('PASS ' if cond else 'FAIL ') + message)
    if cond:
        PASSES += 1
    else:
        FAILS.append(message)


def php(board_dir, *args):
    r = subprocess.run(['php', os.path.join(HERE, 'fresh_fixture.php'), *[str(x) for x in args]], cwd=board_dir, capture_output=True, text=True)
    lines = [l for l in r.stdout.splitlines() if l.strip()]
    if r.returncode or not lines or 'SQL Error' in r.stdout:
        return {'error': (lines[-1] if lines else r.stdout[-200:])[:200]}
    return json.loads(lines[-1])


def main():
    import board  # noqa: E402
    import features  # noqa: E402
    from front import Front  # noqa: E402

    port = a.port or {'sqlite': 18940, 'mysql': 18941, 'pgsql': 18942}[a.engine]
    ctx = None
    try:
        ctx = board.make_board(a.engine, a.mybb, a.scratch, port, 'real', None, dbname=f'msc_test_pw_{a.engine}_' + os.urandom(3).hex())
        acp = board.activate(ctx)
        features.configure(ctx, a.scratch)
        board_dir = ctx.board
        adm = '/admin/index.php?module=myshowcase-json_sync'
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/blog.json'})
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog',
                       'route_identifier': 'blog', 'custom_template_prefix': ''})
        php(board_dir, 'enable', 'blog')
        php(board_dir, 'showcase-set', 'blog', 'password_minutes_interval', 60)
        php(board_dir, 'showcase-set', 'blog', 'password_ip_check', 0)
        sid = str(php(board_dir, 'showcase-value', 'blog', 'showcase_id')['value'])
        base = f'http://127.0.0.1:{port}'
        path = '/showcase.php/blog/'
        update = f'/admin/index.php?module=myshowcase-summary&action=update&showcase_id={sid}'

        def general_form():
            page = acp.get(update)
            return page, form_of(page['body'], 'type=general')

        def save(password=None, clear=False):
            page, general = general_form()
            data = {k: (v[0] if len(v) == 1 else v) for k, v in general['fields'].items() if k not in general['disabled']}
            if password is not None:
                data['showcase[password]'] = password
            if clear:
                data['showcase_password_clear'] = '1'
            return acp.post('/admin/index.php?module=' + general['action'].replace('&amp;', '&').split('module=')[1], data)

        stored = lambda: str(php(board_dir, 'showcase-value', 'blog', 'password').get('value', ''))  # noqa: E731

        # A: the form saves a hash, not the typed password
        save(password='pw-secret-1')
        hashed = stored()
        ok(hashed != '' and hashed != 'pw-secret-1' and hashed.startswith(('$2y$', '$argon2')),
           f'A: the form stores a password hash, not the typed value [prefix {hashed[:7]!r}]')
        ok(php(board_dir, 'password-verify', 'blog', 'pw-secret-1').get('match') is True,
           'A: the stored hash verifies the typed password')

        # B: the form never shows the stored value
        page, _ = general_form()
        ok(hashed not in page['body'] and 'pw-secret-1' not in page['body'], 'B: the ACP form page shows neither the hash nor the typed password')

        # C: an empty password field keeps the stored password
        save(password='')
        ok(stored() == hashed, 'C: saving the form with an empty password field keeps the stored password')

        # D: the gate opens for the right password only
        guest = Front(base)
        guest.post(path, {'password': 'wrong-password'})
        ok(GATE in guest.get(path)['body'], 'D: a wrong password does not open the gate')
        guest.post(path, {'password': 'pw-secret-1'})
        ok(GATE not in guest.get(path)['body'], 'D: the right password opens the gate')

        # E: changing the password cancels the guest's grant (the cookie is bound to the stored value)
        save(password='pw-secret-2')
        time.sleep(2)
        ok(GATE in guest.get(path)['body'], 'E: after the password changed, the guest grant of the old password is cancelled')

        # F: the remove control clears the password
        save(clear=True)
        time.sleep(2)
        ok(stored() == '', 'F: the remove control clears the stored password')
        ok(GATE not in Front(base).get(path)['body'], 'F: with no password, a guest sees the list without a gate')
    except Exception as e:  # a crash is a failed check, reported with the summary below
        FAILS.append(f'the run stopped: {e}')
        print('FAIL the run stopped:', e)
    finally:
        print(f'\n{PASSES} passed, {len(FAILS)} failed (showcase password, {a.engine})')
        for f in FAILS:
            print('  FAILED:', f)
        sys.stdout.flush()
        if ctx is not None:
            ctx.close()
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
