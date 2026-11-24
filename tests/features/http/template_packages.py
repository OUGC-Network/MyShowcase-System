"""HTTP checks for template packages (TP): visitor toggle, cookies, package markup, and authorization order."""
import json
import os
import re
from html.parser import HTMLParser

from features import ok, G, B, user, entry, sid, fix, view_url, post_key, q, no_permission, bad_post_key, CTX, SCRATCH
from front import Front

HERE = os.path.dirname(os.path.abspath(__file__))

_VOID_HTML_TAGS = frozenset({
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
    'link', 'meta', 'param', 'source', 'track', 'wbr',
})


def _cookie_names(client):
    return [c.name for c in client.jar]


def _cookie_value(client, name):
    for c in client.jar:
        if c.name == name:
            return c.value
    return ''


def _toggle_main(client, base_path, mode):
    page = client.get(base_path)
    return client.post(base_path, {
        'my_post_key': post_key(page['body']),
        'myshowcase_action': 'template_view',
        'template_view_mode': mode,
        'template_return_route': 'main',
    })


def _class_attr(attrs):
    for key, value in attrs:
        if key == 'class':
            return value or ''
    return ''


class _GalleryGridAncestry(HTMLParser):
    """True when a thread row card is a descendant of a gallery grid container."""

    def __init__(self):
        super().__init__()
        self._stack = []
        self.thread_inside_grid = False

    def _inside_grid(self):
        return any(frame['grid'] for frame in self._stack)

    def handle_starttag(self, tag, attrs):
        classes = _class_attr(attrs)
        is_grid = 'myshowcase-gallery-entries' in classes.split()
        if tag not in _VOID_HTML_TAGS:
            self._stack.append({'tag': tag, 'grid': is_grid})
        if self._inside_grid() and tag == 'div' and 'thread' in classes.split():
            self.thread_inside_grid = True

    def handle_endtag(self, tag):
        if tag in _VOID_HTML_TAGS:
            return
        while self._stack:
            frame = self._stack.pop()
            if frame['tag'] == tag:
                break


def _gallery_card_inside_grid(body):
    parser = _GalleryGridAncestry()
    parser.feed(body)
    return parser.thread_inside_grid


def _gallery_parser_self_check():
    ok(
        _gallery_card_inside_grid(
            '<div class="myshowcase-gallery-entries"><div class="thread"></div></div>'
        ),
        'parser self-check: thread card inside gallery grid',
    )
    ok(
        not _gallery_card_inside_grid(
            '<div class="myshowcase-gallery-entries"></div><div class="thread"></div>'
        ),
        'parser self-check: thread card after closed grid is not contained',
    )
    ok(
        not _gallery_card_inside_grid(
            '<div class="myshowcase-gallery-entries"><img class="thread"></div>'
        ),
        'parser self-check: void img with thread class is not a grid card',
    )


def _package_markers_absent(body, markers):
    return not any(marker in body for marker in markers)


def _decode_cookie_preferences(raw):
    if not raw or len(raw) > 2048:
        return {}
    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        return {}
    if not isinstance(decoded, dict):
        return {}
    out = {}
    for key, value in decoded.items():
        if isinstance(key, int):
            key = str(key)
        if not isinstance(key, str) or not re.fullmatch(r'\d{1,10}', key):
            continue
        if value not in ('c', 'g'):
            continue
        out[key] = value
        if len(out) >= 40:
            break
    return out


def run(_acp):
    print('--- TP. template packages and visitor preferences')
    _gallery_parser_self_check()

    fix('template-set', 'gallery', 'gallery')
    fix('template-set', 'blog', 'blog')

    m = user('member1')
    gsid, bsid = sid('gallery'), sid('blog')
    prefix_before = q(f"select custom_template_prefix from mybb_myshowcase_config where showcase_id={gsid}")[0][0]

    g_configured = m.get(f'{G}/')
    ok(_gallery_card_inside_grid(g_configured['body']), 'configured gallery places entry cards inside the grid container')
    ok('myshowcase-gallery-entries' in g_configured['body'], 'configured gallery list exposes the grid container')
    ok('myshowcase-template-view' in g_configured['body'], 'configured gallery package shows the visitor toggle')

    _toggle_main(m, f'{G}/', 'g')
    ok('myshowcase_tpl_prefs' in _cookie_names(m), 'gallery template toggle stores the session preference cookie')

    g_global = m.get(f'{G}/')
    ok(_package_markers_absent(g_global['body'], ['myshowcase-gallery-entries', 'myshowcase-gallery-detail']),
       'gallery global mode omits gallery-only presentation markup')

    _toggle_main(m, f'{G}/', 'c')
    g_back = m.get(f'{G}/')
    ok('myshowcase-gallery-entries' in g_back['body'] and _gallery_card_inside_grid(g_back['body']),
       'gallery returns to package presentation when switched back to configured')

    b_page = m.get(f'{B}/')
    m.post(f'{B}/', {
        'my_post_key': post_key(b_page['body']),
        'myshowcase_action': 'template_view',
        'template_view_mode': 'c',
        'template_return_route': 'main',
    })

    cookie_raw = _cookie_value(m, 'myshowcase_tpl_prefs')
    prefs = json.loads(cookie_raw)
    ok(prefs.get(str(gsid)) == 'c' and prefs.get(str(bsid)) == 'c',
       'two showcases sharing packages keep independent visitor preferences')

    _toggle_main(m, f'{G}/', 'g')
    prefs = json.loads(_cookie_value(m, 'myshowcase_tpl_prefs'))
    ok(prefs.get(str(gsid)) == 'g' and prefs.get(str(bsid)) == 'c',
       'switching gallery does not change blog visitor preference')

    _toggle_main(m, f'{B}/', 'g')
    prefs_blog_global = json.loads(_cookie_value(m, 'myshowcase_tpl_prefs'))
    ok(prefs_blog_global.get(str(bsid)) == 'g' and prefs_blog_global.get(str(gsid)) == 'g',
       'switching blog does not change gallery visitor preference')

    users_file = os.path.join(SCRATCH, 'users.json')
    with open(users_file, encoding='utf-8') as users_handle:
        member_password = json.load(users_handle)['member1']['password']
    fresh = Front(CTX.url)
    fresh.login('member1', member_password)
    fresh_g = fresh.get(f'{G}/')
    ok('myshowcase_tpl_prefs' not in _cookie_names(fresh) and 'myshowcase-gallery-entries' in fresh_g['body'],
       'new browser session uses configured package defaults without a preference cookie')

    ok(
        _decode_cookie_preferences('{"not-a-showcase-id":"g","1":"g","2":"x","3":"g"}') == {'1': 'g', '3': 'g'},
        'invalid cookie keys and modes are discarded safely',
    )

    b_list = m.get(f'{B}/')
    ok('myshowcase-blog-entry' in b_list['body'], 'configured blog list uses package row markup')
    ok('.myshowcase-blog-entry' in b_list['body'], 'configured blog list loads package list CSS once per page')

    _toggle_main(m, f'{B}/', 'g')
    b_global = m.get(f'{B}/')
    ok(_package_markers_absent(b_global['body'], ['myshowcase-blog-entry', 'myshowcase-blog-detail']),
       'blog global mode omits blog-only presentation markup')
    ok('.myshowcase-blog-entry {' not in b_global['body'],
       'blog global mode omits blog package list stylesheet rules')

    _toggle_main(m, f'{B}/', 'c')
    _toggle_main(m, f'{G}/', 'c')

    e = entry('gallery', 'title', 'Member one photo')
    if e is not None:
        view_page = m.get(f"{G}/view/{e['public_id']}/{e['entry_slug_custom'] or 'x'}")
        m.post(f"{G}/view/{e['public_id']}/{e['entry_slug_custom'] or 'x'}", {
            'my_post_key': post_key(view_page['body']),
            'myshowcase_action': 'template_view',
            'template_view_mode': 'g',
            'template_return_route': 'entry_view',
            'template_return_entry_public_id': e['public_id'],
            'template_return_entry_slug_custom': e['entry_slug_custom'] or '',
        })
        view_global = m.get(view_url(e))
        ok('myshowcase-gallery-detail' not in view_global['body'],
           'gallery entry global mode omits package-only detail markup')

        view_page = m.get(f"{G}/view/{e['public_id']}/{e['entry_slug_custom'] or 'x'}")
        m.post(f"{G}/view/{e['public_id']}/{e['entry_slug_custom'] or 'x'}", {
            'my_post_key': post_key(view_page['body']),
            'myshowcase_action': 'template_view',
            'template_view_mode': 'c',
            'template_return_route': 'entry_view',
            'template_return_entry_public_id': e['public_id'],
            'template_return_entry_slug_custom': e['entry_slug_custom'] or '',
        })
        view = m.get(view_url(e))
        ok('myshowcase-gallery-detail' in view['body'], 'gallery entry configured mode uses image-forward package markup')
        ok('myshowcase-entry-view--gallery' in view['body'], 'gallery entry view retains package page classes')
        ok(
            'myshowcase-gallery-detail__hero-image' in view['body']
            or 'myshowcase-gallery-detail__hero-missing' in view['body'],
            'gallery entry detail shows hero image or an explicit missing-image fallback',
        )

        bad = m.post(f"{G}/view/{e['public_id']}/{e['entry_slug_custom'] or 'x'}", {
            'my_post_key': '0' * 32,
            'myshowcase_action': 'template_view',
            'template_view_mode': 'g',
            'template_return_route': 'entry_view',
            'template_return_entry_public_id': e['public_id'],
            'template_return_entry_slug_custom': e['entry_slug_custom'] or '',
        })
        ok('Invalid Post Key' in bad['body'] or 'invalid post key' in bad['body'].lower(),
           'invalid post key refuses template toggle submission')

    guest = user('guest')
    create_page = guest.get(f'{B}/create')
    deny = guest.post(f'{B}/create', {
        'my_post_key': post_key(create_page['body']) if 'my_post_key' in create_page['body'] else '0' * 32,
        'myshowcase_action': 'template_view',
        'template_view_mode': 'g',
        'template_return_route': 'entry_create',
    })
    ok(no_permission(deny) or bad_post_key(deny), 'guest cannot change template preference on denied create page')

    bogus = m.post(f'{G}/view/notarealid/notaslug', {
        'my_post_key': post_key(m.get(f'{G}/')['body']),
        'myshowcase_action': 'template_view',
        'template_view_mode': 'g',
        'template_return_route': 'entry_view',
        'template_return_entry_public_id': 'notarealid',
        'template_return_entry_slug_custom': 'x',
    })
    ok(bogus['status'] == 404 or 'invalid' in bogus['body'].lower(),
       'template toggle on a missing entry is refused with the normal entry error')

    prefix_after = q(f"select custom_template_prefix from mybb_myshowcase_config where showcase_id={gsid}")[0][0]
    ok(prefix_before == prefix_after == 'gallery', 'visitor toggle does not change the administrator template prefix')
    ok(q(f"select custom_template_prefix from mybb_myshowcase_config where showcase_id={bsid}")[0][0] == 'blog',
       'blog showcase administrator prefix unchanged during gallery toggle checks')