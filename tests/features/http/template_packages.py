"""HTTP checks for template packages (TP): visitor toggle, cookies, package markup, and authorization order."""
import hashlib
import json
import os
import re
import zipfile
from html.parser import HTMLParser
from urllib.parse import unquote

from features import ok, G, B, user, entry, sid, fix, view_url, post_key, q, one, count, field_id, no_permission, bad_post_key, CTX, SCRATCH, MEDIA, upload, option_id, mybb_error_summary
from front import Front

HERE = os.path.dirname(os.path.abspath(__file__))
D = '/showcase.php/downloads'

_VOID_HTML_TAGS = frozenset({
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
    'link', 'meta', 'param', 'source', 'track', 'wbr',
})


def _cookie_names(client):
    return [c.name for c in client.jar]


def _cookie_value(client, name='myshowcase_tpl_prefs'):
    for c in client.jar:
        if c.name == name or c.name.endswith(name):
            # MyBB my_setcookie() urlencodes the Set-Cookie value (inc/functions.php).
            return unquote(c.value or '')
    return ''


def _template_prefs(client):
    raw = _cookie_value(client)
    return _decode_cookie_preferences(raw)


def _cookie_diag(client):
    raw = _cookie_value(client)
    if not raw:
        return 'cookie=missing'
    if raw[0] not in '{[':
        return f'cookie=unexpected_prefix len={len(raw)}'
    prefs = _decode_cookie_preferences(raw)
    return f'cookie=parsed keys={len(prefs)}'


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
    # Re-apply explicit global grants after the new showcase exists (fixture setup runs before downloads sync).
    fix('grants')


def _downloads_zip_in_media():
    name = 'tp-template-package.zip'
    path = os.path.join(MEDIA, name)
    if not os.path.isfile(path):
        with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as archive:
            archive.writestr('readme.txt', 'template package test archive')
    return name


def _downloads_view_url(e):
    return f"{D}/view/{e['public_id']}/{e['entry_slug_custom'] or 'x'}"


def _downloads_upload_hint(response):
    body = response.get('body') or ''
    status = response.get('status', 0)
    if status < 400:
        return ''
    plain = re.sub(r'\s+', ' ', body)
    for pattern in (
        r'inline_error[^<]{0,160}',
        r'(?:upload|attachment)[^<]{0,80}(?:failed|error|not allow|invalid)[^<]{0,80}',
        r'error_(?:attach|upload)[^<]{0,80}',
    ):
        match = re.search(pattern, plain, re.I)
        if match:
            return match.group(0).strip()[:140]
    summary = mybb_error_summary(body)
    return summary[:140] if summary else ''


def _downloads_seed_diag(form, upload_resp, post_resp, expected_showcase_id, subject, post_hash=''):
    parts = [
        f"create_form_http={form['status']}",
        f"showcase_id={expected_showcase_id}",
        f"archive_field={field_id('downloads', 'archive')}",
        f"archive_upload_http={upload_resp['status']}",
        f"entry_post_http={post_resp['status']}",
        f"entry_row={1 if entry('downloads', 'subject', subject) else 0}",
    ]
    uploads_path = one(f"select attachments_uploads_path from mybb_myshowcase_config where showcase_id='{expected_showcase_id}'")
    parts.append(f"uploads_path={'set' if uploads_path else 'MISSING'}")
    zip_rows = q("select enabled, myshowcase_ids, maxsize from mybb_attachtypes where extension='zip'")
    if zip_rows:
        enabled, showcase_ids, maxsize = zip_rows[0]
        parts.append(f"zip_attachtype_enabled={enabled}")
        parts.append(f"zip_showcase_ids={showcase_ids or 'none'}")
        parts.append(f"zip_maxsize_kb={maxsize}")
    if post_hash:
        safe_hash = post_hash.replace("'", "''")
        parts.append('attachment_rows=' + str(count('mybb_myshowcase_attachments', f"post_hash='{safe_hash}'")))
    upload_hint = _downloads_upload_hint(upload_resp)
    if upload_hint:
        parts.append(f"upload_error={upload_hint}")
    if not uploads_path and upload_resp.get('status', 0) >= 400:
        parts.append('storage_class=missing_showcase_uploads_path')
    elif upload_resp.get('status', 0) >= 400:
        parts.append('storage_class=upload_rejected_or_filesystem')
    summary = mybb_error_summary(post_resp.get('body') or form.get('body') or '')
    if summary and not upload_hint:
        parts.append(f"error={summary[:120]}")
    if no_permission(form):
        parts.append('permission=create_form_denied')
    elif no_permission(post_resp):
        parts.append('permission=entry_post_denied')
    elif bad_post_key(post_resp):
        parts.append('post_key=entry_post_rejected')
    return '; '.join(parts)


def _create_downloads_entry(client, subject):
    expected_sid = sid('downloads')
    form = client.get(f'{D}/create')
    if form['status'] != 200 or 'id="contentForm"' not in form['body']:
        return None, {'status': form['status'], 'body': form['body'], '_diag': _downloads_seed_diag(form, {'status': 0}, {'status': 0}, expected_sid, subject)}
    match = re.search(r'name="post_hash" value="([^"]+)"', form['body'])
    post_hash = match.group(1) if match else ''
    key = post_key(form['body'])
    if not key:
        return None, {'status': form['status'], 'body': form['body'], '_diag': 'create_form_http=' + str(form['status']) + '; post_key=missing'}
    zip_name = _downloads_zip_in_media()
    upload_resp = upload(client, D, 'archive', post_hash, key, zip_name)
    upload_ok = upload_resp['status'] == 200
    if not upload_ok:
        empty_post = {'status': 0, 'body': ''}
        return None, {
            'status': upload_resp['status'],
            'body': upload_resp['body'],
            '_diag': _downloads_seed_diag(form, upload_resp, empty_post, expected_sid, subject, post_hash),
        }
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
    response['_diag'] = _downloads_seed_diag(form, upload_resp, response, expected_sid, subject, post_hash)
    row = entry('downloads', 'subject', subject)
    if row is None and response['status'] == 200:
        response['_diag'] += '; entry_create=validation_or_missing_archive'
    return row, response


def _class_attr(attrs):
    for key, value in attrs:
        if key == 'class':
            return value or ''
    return ''


class _DownloadEntryCards(HTMLParser):
    """Count structural download list cards (class myshowcase-download-entry), not CSS selectors."""

    def __init__(self):
        super().__init__()
        self.cards = 0

    def handle_starttag(self, tag, attrs):
        if tag != 'div':
            return
        classes = _class_attr(attrs).split()
        if 'myshowcase-download-entry' in classes:
            self.cards += 1


def _download_entry_card_count(body):
    parser = _DownloadEntryCards()
    parser.feed(body)
    return parser.cards


def _attr_map(attrs):
    return {name: value for name, value in attrs}


class _DownloadsFilesSectionParser(HTMLParser):
    """Locate the real downloads files section element (not CSS rules)."""

    def __init__(self):
        super().__init__()
        self.sections = []

    def handle_starttag(self, tag, attrs):
        if tag != 'section':
            return
        mapped = _attr_map(attrs)
        classes = (mapped.get('class') or '').split()
        if 'myshowcase-downloads-detail__files' in classes:
            self.sections.append({
                'aria_label': mapped.get('aria-label', ''),
                'class': mapped.get('class', ''),
            })


def _downloads_files_section(body):
    parser = _DownloadsFilesSectionParser()
    parser.feed(body)
    return parser.sections[0] if parser.sections else None


def _downloads_archive_attachment_row(entry_id):
    fid = field_id('downloads', 'archive')
    if not fid:
        return None
    rows = q(
        f"select public_id, attachment_name, file_name from mybb_myshowcase_attachments "
        f"where entry_id='{entry_id}' and field_id='{fid}' order by attachment_id limit 1"
    )
    return rows[0] if rows else None


def _authorized_attachment_href(href, public_id, attachment_name=''):
    if not href or not public_id:
        return False
    if f'/attachment/{public_id}/' in href or f'attachment/{public_id}/' in href:
        return True
    if 'route=' in href and public_id in href and 'attachment' in href:
        return True
    return bool(attachment_name and public_id in href and attachment_name in href)


class _DownloadsFilesSectionLinkParser(HTMLParser):
    """Collect attachment anchors inside vs outside the files section."""

    def __init__(self):
        super().__init__()
        self._section_depth = 0
        self.section_hrefs = []
        self.outside_hrefs = []

    def handle_starttag(self, tag, attrs):
        if tag == 'section':
            mapped = _attr_map(attrs)
            if 'myshowcase-downloads-detail__files' in (mapped.get('class') or '').split():
                self._section_depth += 1
        if tag != 'a':
            return
        href = _attr_map(attrs).get('href', '')
        if 'attachment' not in href and 'thumbnail' not in href:
            return
        if self._section_depth > 0:
            self.section_hrefs.append(href)
        else:
            self.outside_hrefs.append(href)

    def handle_endtag(self, tag):
        if tag == 'section' and self._section_depth > 0:
            self._section_depth -= 1


def _downloads_attachment_link_report(body, entry_id):
    row = _downloads_archive_attachment_row(entry_id)
    public_id = row[0] if row else ''
    attachment_name = row[1] if row else ''
    parser = _DownloadsFilesSectionLinkParser()
    parser.feed(body)
    section_matches = [h for h in parser.section_hrefs if _authorized_attachment_href(h, public_id, attachment_name)]
    outside_matches = [h for h in parser.outside_hrefs if _authorized_attachment_href(h, public_id, attachment_name)]
    return {
        'public_id': public_id,
        'attachment_name': attachment_name,
        'section_anchor_count': len(parser.section_hrefs),
        'section_match_count': len(section_matches),
        'outside_match_count': len(outside_matches),
        'section_matches': section_matches,
        'outside_matches': outside_matches,
        'zip_in_link_text': 'tp-template-package.zip' in body,
    }


def _downloads_attachment_link_diag(entry_id, report):
    parts = [
        f"archive_public_id={report['public_id'] or 'missing'}",
        f"attachment_name={report['attachment_name'] or 'missing'}",
        f"section_anchors={report['section_anchor_count']}",
        f"section_authorized={report['section_match_count']}",
        f"outside_authorized={report['outside_match_count']}",
        f"zip_link_text={'yes' if report['zip_in_link_text'] else 'no'}",
    ]
    if report['section_matches']:
        shape = report['section_matches'][0].split('?')[0][-80:]
        parts.append(f"href_shape=...{shape}")
    elif report['section_anchor_count']:
        parts.append('href_shape=no_authorized_match_in_section')
    else:
        parts.append('href_shape=no_section_anchors')
    return '; '.join(parts)


def _downloads_detail_language_diag(response, showcase_id, expected_label):
    body = response.get('body') or ''
    parts = [f"http={response.get('status', '?')}"]
    prefix = one(f"select custom_language_prefix from mybb_myshowcase_config where showcase_id='{showcase_id}'")
    lang_file = f"myshowcase_showcase_{prefix}.lang.php" if prefix else 'global/neutral only'
    parts.append(f"language_prefix={prefix or 'empty'}")
    parts.append(f"language_file={lang_file}")
    section = _downloads_files_section(body)
    if section:
        parts.append(f"section_aria_label={section['aria_label']!r}")
        parts.append('section_element=yes')
    else:
        parts.append('section_element=missing')
    empty_state = 'myshowcase-downloads-detail__empty' in body
    parts.append(f"empty_state_p={'yes' if empty_state else 'no'}")
    archive_in_section = bool(section and 'tp-template-package.zip' in body)
    parts.append(f"authorized_archive_in_page={'yes' if archive_in_section or '/attachment/' in body else 'no'}")
    parts.append(f"expected_label={expected_label!r}")
    return '; '.join(parts)


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
    raw = unquote(raw)
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
    fix('language-set', 'downloads', '')

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

    prefs = _template_prefs(m)
    ok(prefs.get(str(gsid)) == 'c' and prefs.get(str(bsid)) == 'c',
       'two showcases sharing packages keep independent visitor preferences' + (f' [{_cookie_diag(m)}]' if not prefs else ''))

    _toggle_main(m, f'{G}/', 'g')
    prefs = _template_prefs(m)
    ok(prefs.get(str(gsid)) == 'g' and prefs.get(str(bsid)) == 'c',
       'switching gallery does not change blog visitor preference' + (f' [{_cookie_diag(m)}]' if prefs.get(str(gsid)) != 'g' else ''))

    _toggle_main(m, f'{B}/', 'g')
    prefs_blog_global = _template_prefs(m)
    ok(prefs_blog_global.get(str(bsid)) == 'g' and prefs_blog_global.get(str(gsid)) == 'g',
       'switching blog does not change gallery visitor preference' + (f' [{_cookie_diag(m)}]' if prefs_blog_global.get(str(bsid)) != 'g' else ''))

    users_file = os.path.join(SCRATCH, 'users.json')
    with open(users_file, encoding='utf-8') as users_handle:
        member_password = json.load(users_handle)['member1']['password']
    fresh = Front(CTX.url)
    fresh.login('member1', member_password)
    fresh_g = fresh.get(f'{G}/')
    ok(
        not any(n == 'myshowcase_tpl_prefs' or n.endswith('myshowcase_tpl_prefs') for n in _cookie_names(fresh))
        and 'myshowcase-gallery-entries' in fresh_g['body'],
        'new browser session uses configured package defaults without a preference cookie',
    )

    ok(
        _decode_cookie_preferences('{"not-a-showcase-id":"g","1":"g","2":"x","3":"g"}') == {'1': 'g', '3': 'g'},
        'invalid cookie keys and modes are discarded safely',
    )
    ok(
        _decode_cookie_preferences('%7B%221%22%3A%22c%22%2C%222%22%3A%22g%22%7D') == {'1': 'c', '2': 'g'},
        'urlencoded MyBB Set-Cookie JSON decodes to visitor preferences',
    )

    de, de_resp = _create_downloads_entry(m, 'TP downloads package A')
    ok(de is not None, 'seed downloads entry for template-package list checks'
       + ('' if de is not None else f" [{de_resp.get('_diag', '')}]"))
    de2, de2_resp = (None, {})
    if de is not None:
        de2, de2_resp = _create_downloads_entry(m, 'TP downloads package B')
    else:
        ok(False, 'seed second downloads entry for template-package list checks [blocked: archive upload failed]')
    downloads_seeded = de is not None and de2 is not None

    if de is not None:
        dsid_before = sid('downloads')
        dl_global_lang = m.get(_downloads_view_url(de))
        global_section = _downloads_files_section(dl_global_lang['body'])
        global_ok = (
            dl_global_lang['status'] == 200
            and global_section is not None
            and global_section.get('aria_label') == 'Attachments'
            and 'tp-template-package.zip' in dl_global_lang['body']
        )
        ok(
            global_ok,
            'downloads package without a custom language file uses global attachment heading'
            + ('' if global_ok else f" [{_downloads_detail_language_diag(dl_global_lang, dsid_before, 'Attachments')}]"),
        )

    fix('language-set', 'downloads', 'resources')

    if not downloads_seeded:
        ok(False, 'configured downloads list uses package row markup [blocked: downloads fixture missing entries]')
        ok(False, 'configured downloads list loads package list CSS once per page [blocked: downloads fixture missing entries]')
        ok(False, 'configured downloads list marks each entry without duplicating list stylesheet rules [blocked: downloads fixture missing entries]')
    else:
        d_configured = m.get(f'{D}/')
        ok('myshowcase-download-entry' in d_configured['body'], 'configured downloads list uses package row markup')
        ok(
            _scoped_style_rule_count(d_configured['body'], '.myshowcase-download-entry') == 1,
            'configured downloads list loads package list CSS once per page',
        )
        ok(
            _download_entry_card_count(d_configured['body']) >= 2,
            'configured downloads list renders two distinct entry cards',
        )

    if not downloads_seeded:
        ok(False, 'downloads global mode omits downloads-only presentation markup [blocked: downloads fixture missing entries]')
        ok(False, 'downloads global mode omits downloads package list stylesheet rules [blocked: downloads fixture missing entries]')
        ok(False, 'downloads returns to package presentation when switched back to configured [blocked: downloads fixture missing entries]')
        ok(False, 'switching downloads does not change blog or gallery visitor preferences [blocked: downloads fixture missing entries]')
    else:
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

        prefs_before_dl = _template_prefs(m)
        _toggle_main(m, f'{D}/', 'g')
        prefs_after_dl = _template_prefs(m)
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
        files_section = _downloads_files_section(detail['body'])
        link_report = _downloads_attachment_link_report(detail['body'], de['entry_id'])
        files_ok = (
            detail['status'] == 200
            and files_section is not None
            and link_report['section_match_count'] >= 1
        )
        ok(
            files_ok,
            'downloads entry detail uses authorized attachment rendering for archive files'
            + ('' if files_ok else f" [{_downloads_attachment_link_diag(de['entry_id'], link_report)}]"),
        )
        archive_once = (
            link_report['section_match_count'] == 1
            and link_report['outside_match_count'] == 0
        )
        ok(
            archive_once,
            'downloads entry detail exposes one authorized archive link without duplication'
            + ('' if archive_once else f" [{_downloads_attachment_link_diag(de['entry_id'], link_report)}]"),
        )
        resources_ok = (
            files_section is not None
            and files_section.get('aria_label') == 'Downloads'
        )
        ok(
            resources_ok,
            'downloads package uses selected custom language overrides for attachment heading'
            + ('' if resources_ok else f" [{_downloads_detail_language_diag(detail, dsid, 'Downloads')}]"),
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

        prefs_before = _template_prefs(m)
        mode_before = prefs_before.get(str(gsid), 'c')
        view_before = m.get(view_url(e))['body']
        had_package_detail = 'myshowcase-gallery-detail' in view_before
        bad = m.post(f"{G}/view/{e['public_id']}/{e['entry_slug_custom'] or 'x'}", {
            'my_post_key': '0' * 32,
            'myshowcase_action': 'template_view',
            'template_view_mode': 'g',
            'template_return_route': 'entry_view',
            'template_return_entry_public_id': e['public_id'],
            'template_return_entry_slug_custom': e['entry_slug_custom'] or '',
        })
        prefs_after = _template_prefs(m)
        toggle_refused = (
            bad_post_key(bad)
            or no_permission(bad)
            or 'Authorization code mismatch' in bad['body']
            or bad['status'] in (403, 400)
        )
        preference_unchanged = prefs_after.get(str(gsid), mode_before) == mode_before
        view_after = m.get(view_url(e))['body']
        mode_unchanged = ('myshowcase-gallery-detail' in view_after) == had_package_detail
        ok(
            toggle_refused and preference_unchanged and mode_unchanged,
            'invalid post key refuses template toggle without changing visitor preference'
            + ('' if toggle_refused and preference_unchanged and mode_unchanged
               else f" [http={bad['status']}; prefs={mode_before}->{prefs_after.get(str(gsid), mode_before)}; err={mybb_error_summary(bad['body'])[:80]}]"),
        )

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
