"""HTTP checks for template packages (TP): visitor toggle, cookies, and package markup."""
from features import ok, G, B, user, entry, sid, fix, view_url, post_key, q


def _cookie_names(client):
    return [c.name for c in client.jar]


def run(_acp):
    print('--- TP. template packages and visitor preferences')
    fix('template-set', 'gallery', 'gallery')
    fix('template-set', 'blog', 'blog')

    m = user('member1')
    gsid, bsid = sid('gallery'), sid('blog')
    prefix_before = q(f"select custom_template_prefix from mybb_myshowcase_config where showcase_id={gsid}")[0][0]

    g_page = m.get(f'{G}/')
    m.post(f'{G}/', {
        'my_post_key': post_key(g_page['body']),
        'myshowcase_action': 'template_view',
        'template_view_mode': 'g',
        'template_return_route': 'main',
    })
    ok('myshowcase_tpl_prefs' in _cookie_names(m), 'gallery template toggle stores the session preference cookie')

    b_page = m.get(f'{B}/')
    m.post(f'{B}/', {
        'my_post_key': post_key(b_page['body']),
        'myshowcase_action': 'template_view',
        'template_view_mode': 'c',
        'template_return_route': 'main',
    })
    ok('myshowcase_tpl_prefs' in _cookie_names(m), 'blog showcase keeps an independent preference cookie entry')

    ok('myshowcase-gallery-entries' in m.get(f'{G}/')['body'], 'gallery list exposes the image grid container')
    ok('myshowcase-template-view' in m.get(f'{G}/')['body'], 'configured gallery package shows the visitor toggle')

    e = entry('gallery', 'title', 'Member one photo')
    if e is not None:
        view = m.get(view_url(e))
        ok('myshowcase-entry-view--gallery' in view['body'], 'gallery entry view uses package presentation markup')
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

    prefix_after = q(f"select custom_template_prefix from mybb_myshowcase_config where showcase_id={gsid}")[0][0]
    ok(prefix_before == prefix_after == 'gallery', 'visitor toggle does not change the administrator template prefix')
    ok(q(f"select custom_template_prefix from mybb_myshowcase_config where showcase_id={bsid}")[0][0] == 'blog',
       'blog showcase administrator prefix unchanged during gallery toggle checks')