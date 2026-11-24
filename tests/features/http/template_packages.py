"""HTTP checks for template packages (TP): visitor toggle, cookies, package markup, and authorization order."""
import hashlib
import json
import os
import re
import zipfile
from html.parser import HTMLParser

from features import ok, G, B, user, entry, sid, fix, view_url, post_key, q, no_permission, bad_post_key, CTX, SCRATCH, MEDIA, upload, option_id
from front import Front

HERE = os.path.dirname(os.path.abspath(__file__))
D = '/showcase.php/downloads'

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


def _scoped_style_rule_count(body, selector):
    return len(re.findall(re.escape(selector) + r'\s*\{', body))


def _ensure_downloads_showcase(acp):
    if sid('downloads') > 0:
        return
    adm = '/admin/index.php?module=myshowcase-json_sync'
    acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/downloads.json'})
    acp.post(adm, {
        'action': 'run',
        'mode': 'create',
        'type': 'showcase',
        'file': 'showcases/downloads.json',
        'name': 'Downloads',
        'route_identifier': 'downloads',
    })
    ok(sid('downloads') > 0, 'downloads showcase created from JSON sync for template-package checks')


def _downloads_zip_in_media():
    name = 'tp-template-package.zip'
    path = os.path.join(MEDIA, name)
    if not os.path.isfile(path):
        with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as archive:
            archive.writestr('readme.txt', 'template package test archive')
    return name


def _downloads_view_url(e):
    return f"{D}/view/{e['public_id']}/{e['entry_slug_custom'] or 'x'}"


def _create_downloads_entry(client, subject):
    form = client.get(f'{D}/create')
    match = re.search(r'name="post_hash" value="([^"]+)"', form['body'])
    post_hash = match.group(1) if match else 'dlhashTP1'
    key = post_key(form['body'])
    zip_name = _downloads_zip_in_media()
    upload(client, D, 'archive', post_hash, key, zip_name)
    with open(os.path.join(MEDIA, zip_name), 'rb') as handle:
        digest = hashlib.sha256(handle.read()).hexdigest()
    response = client.post(f'{D}/create', {
        'subject': subject,
        'category': option_id('downloads', 'Tools'),
        'version': '1.0.0',
        'release_date': '2026-11-01',
        'platform': option_id('downloads', 'Any'),
        'license': option_id('downloads', 'Freeware'),
        'sha256': digest,
        'post_hash': post_hash,
        'my_post_key': key,
        'submit': 'Post',
    })
    return entry('downloads', 'subject', subject), response


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

    _ensure_downloads_showcase(_acp)
    fix('template-set', 'gallery', 'gallery')
    fix('template-set', 'blog', 'blog')
    fix('template-set', 'downloads', 'downloads')
    fix('language-set', 'blog', 'article')
    fix('language-set', 'gallery', 'previews')
    fix('language-set', 'downloads', 'resources')

    m = user('member1')
    gsid, bsid, dsid = sid('gallery'), sid('blog'), sid('downloads')
    prefix_before = q(f"select custom_template_prefix from mybb_myshowcase_config where showcase_id={gsid}")[0][0]
    blog_lang_before = q(f"select custom_language_prefix from mybb_myshowcase_config where showcase_id={bsid}")[0][0]

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

    b_configured = m.get(f'{B}/')
    ok('myshowcase-blog-entry' in b_configured['body'], 'configured blog list uses package row markup')
    ok(
        _scoped_style_rule_count(b_configured['body'], '.myshowcase-blog-entry') == 1,
        'configured blog list loads package list CSS once per page',
    )

    _toggle_main(m, f'{B}/', 'g')
    b_global = m.get(f'{B}/')
    ok(_package_markers_absent(b_global['body'], ['myshowcase-blog-entry', 'myshowcase-blog-detail']),
       'blog global mode omits blog-only presentation markup')
    ok(
        _scoped_style_rule_count(b_global['body'], '.myshowcase-blog-entry') == 0,
        'blog global mode omits blog package list stylesheet rules',
    )

    _toggle_main(m, f'{B}/', 'c')
    b_restored = m.get(f'{B}/')
    ok(
        'myshowcase-blog-entry' in b_restored['body']
        and _scoped_style_rule_count(b_restored['body'], '.myshowcase-blog-entry') == 1,
        'blog returns to package presentation when switched back to configured',
    )

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

    de, _ = _create_downloads_entry(m, 'TP downloads package A')
    ok(de is not None, 'seed downloads entry for template-package list checks')
    _create_downloads_entry(m, 'TP downloads package B')

    d_configured = m.get(f'{D}/')
    ok('myshowcase-download-entry' in d_configured['body'], 'configured downloads list uses package row markup')
    ok(
        _scoped_style_rule_count(d_configured['body'], '.myshowcase-download-entry') == 1,
        'configured downloads list loads package list CSS once per page',
    )
    ok(
        d_configured['body'].count('myshowcase-download-entry') >= 2,
        'configured downloads list marks each entry without duplicating list stylesheet rules',
    )

    _toggle_main(m, f'{D}/', 'g')
    d_global = m.get(f'{D}/')
    ok(_package_markers_absent(d_global['body'], ['myshowcase-download-entry', 'myshowcase-downloads-detail']),
       'downloads global mode omits downloads-only presentation markup')
    ok(
        _scoped_style_rule_count(d_global['body'], '.myshowcase-download-entry') == 0,
        'downloads global mode omits downloads package list stylesheet rules',
    )

    _toggle_main(m, f'{D}/', 'c')
    d_restored = m.get(f'{D}/')
    ok(
        'myshowcase-download-entry' in d_restored['body']
        and _scoped_style_rule_count(d_restored['body'], '.myshowcase-download-entry') == 1,
        'downloads returns to package presentation when switched back to configured',
    )

    prefs_before_dl = json.loads(_cookie_value(m, 'myshowcase_tpl_prefs'))
    _toggle_main(m, f'{D}/', 'g')
    prefs_after_dl = json.loads(_cookie_value(m, 'myshowcase_tpl_prefs'))
    ok(
        prefs_after_dl.get(str(dsid)) == 'g'
        and prefs_after_dl.get(str(bsid)) == prefs_before_dl.get(str(bsid))
        and prefs_after_dl.get(str(gsid)) == prefs_before_dl.get(str(gsid)),
        'switching downloads does not change blog or gallery visitor preferences',
    )
    _toggle_main(m, f'{D}/', 'c')

    if de is not None:
        detail = m.get(_downloads_view_url(de))
        ok('myshowcase-downloads-detail' in detail['body'], 'downloads entry detail uses the resource-oriented package view')
        ok('myshowcase-entry-view--downloads' in detail['body'], 'downloads entry view retains package page classes')
        ok(
            'myshowcase-downloads-detail__files' in detail['body'],
            'downloads entry detail uses authorized attachment rendering for archive files',
        )
        ok(
            'tp-template-package.zip' in detail['body'] or '/attachment/' in detail['body'],
            'downloads entry detail exposes the archive through the normal attachment URLs',
        )

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
    ok(q(f"select custom_language_prefix from mybb_myshowcase_config where showcase_id={bsid}")[0][0] == blog_lang_before == 'article',
       'visitor template toggle does not change the showcase custom language file selection')

    fix('language-set', 'blog', 'article')
    fix('language-set', 'downloads', 'article')
    ok(
        q(f"select custom_language_prefix from mybb_myshowcase_config where showcase_id={bsid}")[0][0] == 'article'
        and q(f"select custom_language_prefix from mybb_myshowcase_config where showcase_id={dsid}")[0][0] == 'article',
        'two showcases can share one reusable custom language file',
    )

    blog_entry = entry('blog', 'subject', 'Full blog post') or entry('blog', 'subject', 'Member one blog')
    if blog_entry is not None:
        blog_view = m.get(f"{B}/view/{blog_entry['public_id']}/{blog_entry['entry_slug_custom'] or 'x'}")
        ok('aria-label="Article"' in blog_view['body'], 'blog package entry detail uses the selected custom language file')

    if de is not None:
        dl_view = m.get(_downloads_view_url(de))
        ok('aria-label="Downloads"' in dl_view['body'], 'downloads package entry detail uses the selected custom language file')