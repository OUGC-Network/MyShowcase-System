"""Forum-side checks of the features the gallery relies on: authorization of every write handler, moderation of new
entries, deletion, search, attachment serving, the edit form and the main list, with the global templates.
Driven by run.py, which installs the board and calls configure() and then run()."""
import json, os, re, shutil, subprocess, sys

from front import Front, post_key, no_permission, bad_post_key, php_errors

FAILS = []
PASSES = 0
CTX = SCRATCH = BOARD = MEDIA = None
USERS = {}
G, B = '/showcase.php/gallery', '/showcase.php/blog'
HERE = os.path.dirname(os.path.abspath(__file__))


def ok(c, m):
    global PASSES
    print(('PASS ' if c else 'FAIL ') + m)
    if c:
        PASSES += 1
    else:
        FAILS.append(m)


def configure(ctx, scratch):
    """The board builder installs the plugin for the ACP; the forum side also needs the files outside inc/plugins."""
    global CTX, SCRATCH, BOARD, MEDIA
    CTX, SCRATCH, BOARD = ctx, scratch, ctx.board
    MEDIA = os.path.join(scratch, 'media')
    upload = os.path.join(HERE, '..', '..', '..', 'Upload')
    for rel in ('showcase.php', 'inc/tasks/myshowcase.php', 'jscripts', 'images'):
        src, dst = os.path.join(upload, rel), os.path.join(BOARD, rel)
        if os.path.isdir(src):
            shutil.copytree(src, dst, dirs_exist_ok=True)
        else:
            shutil.copy(src, dst)


def fix(*args):
    r = subprocess.run(['php', os.path.join(HERE, 'fixture.php')] + [str(a) for a in args], cwd=BOARD, capture_output=True, text=True)
    lines = [l for l in r.stdout.splitlines() if l.strip()]
    if r.returncode or not lines:
        raise RuntimeError(f'fixture {args[0]} failed: {r.stdout[-800:]} {r.stderr[-800:]}')
    return json.loads(lines[-1])


def q(sql):
    return CTX.query(sql)


def one(sql):
    r = q(sql)
    return r[0][0] if r else ''


def sid(route):
    return int(one(f"select showcase_id from mybb_myshowcase_config where route_identifier='{route}'") or 0)


def field_id(route, key):
    return int(one(f"select f.field_id from mybb_myshowcase_fields f, mybb_myshowcase_config c where c.route_identifier='{route}' "
                   f"and f.collection_id=c.collection_id and f.field_key='{key}'") or 0)


def option_id(route, label):
    return one(f"select d.field_data_id from mybb_myshowcase_field_data d, mybb_myshowcase_config c where c.route_identifier='{route}' "
               f"and d.collection_id=c.collection_id and d.value='{label}'")


def entry(route, column, value):
    rows = q(f"select entry_id, public_id, user_id, status, is_closed, is_sticky, views, entry_slug_custom from mybb_myshowcase_data{sid(route)} "
             f"where {column}='{value}'")
    if not rows:
        return None
    k = ['entry_id', 'public_id', 'user_id', 'status', 'is_closed', 'is_sticky', 'views', 'entry_slug_custom']
    return dict(zip(k, rows[0]))


def count(table, where='1=1'):
    return int(one(f'select count(*) from {table} where {where}') or 0)


def user(name):
    f = Front(CTX.url)
    if name != 'guest':
        f.login(name, USERS[name]['password'])
    return f


def view_url(e):
    return f"{G}/view/{e['public_id']}/{e['entry_slug_custom'] or 'x'}"


def upload(client, route, field, post_hash, key, file):
    fid = field_id(route.split('/')[-1], field)
    return client.upload(f'{route}/update/{post_hash}/upload?my_post_key={key}&field_id={fid}', {'my_post_key': key, 'field_id': fid}, field,
                         os.path.join(MEDIA, file))


def create_gallery(client, title, file='photo.jpg', extra=None, key=None, category='Nature'):
    form = client.get(f'{G}/create')
    m = re.search(r'name="post_hash" value="([^"]+)"', form['body'])
    post_hash = m.group(1) if m else 'g2' + title.lower().replace(' ', '')
    k = post_key(form['body']) if key is None else key
    up = upload(client, G, 'image', post_hash, post_key(form['body']), file) if file else None
    data = {'title': title, 'caption': f'Caption of [b]{title}[/b]', 'category': option_id('gallery', category), 'post_hash': post_hash,
            'my_post_key': k, 'submit': 'Post'}
    data.update(extra or {})
    r = client.post(f'{G}/create', data)
    return {'form': form, 'upload': up, 'post': r, 'entry': entry('gallery', 'title', title), 'post_hash': post_hash}


def create_blog(client, subject):
    form = client.get(f'{B}/create')
    m = re.search(r'name="post_hash" value="([^"]+)"', form['body'])
    r = client.post(f'{B}/create', {'subject': subject, 'message': f'Message of {subject}', 'category': option_id('blog', 'News') or
                                    one("select d.field_data_id from mybb_myshowcase_field_data d, mybb_myshowcase_config c where c.route_identifier='blog' and d.collection_id=c.collection_id"),
                                    'post_hash': m.group(1) if m else '', 'my_post_key': post_key(form['body']), 'submit': 'Post'})
    return entry('blog', 'subject', subject), r


def moderate(client, action, entries, key=True, route=G):
    page = client.get(f'{route}/')
    data = {'action': 'inline_moderation', 'moderate': action, 'selected_entries': json.dumps([e['public_id'] for e in entries]),
            'selected_all_entries': 0}
    if key:
        data['my_post_key'] = post_key(page['body'])
    return client.post(f'{route}/', data)


def comment(client, e, message, key=True):
    page = client.get(view_url(e))
    data = {'message': message, 'post_hash': '', 'submit': 'Post'}
    if key:
        data['my_post_key'] = post_key(page['body'])
    return client.post(f"{G}/view/{e['public_id']}/create/comment", data)


# ---------------------------------------------------------------------------------------------------------------------

def setup(acp):
    print('--- setup: gallery and blog from their files, users, explicit permissions')
    adm = '/admin/index.php?module=myshowcase-json_sync'
    for kind, f in (('collection', 'collections/gallery.json'), ('collection', 'collections/blog.json')):
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': kind, 'file': f})
    for name, route in (('Gallery', 'gallery'), ('Blog', 'blog')):
        acp.post(adm, {'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': f'showcases/{route}.json', 'name': name, 'route_identifier': route})
    ok(sid('gallery') > 0 and sid('blog') > 0, 'gallery and blog showcases created from their files')
    users_file = os.path.join(SCRATCH, 'users.json')
    fix('setup', users_file)
    USERS.update(json.load(open(users_file)))
    fix('media', MEDIA)
    ok(count('mybb_myshowcase_permissions', "showcase_id='0'") == 5, 'explicit permissions for guests, members, moderators, admins, moderated group')


WRITE_DEFAULTS_OFF = ['entries_can_post', 'entries_can_update', 'entries_can_copy', 'entries_can_soft_delete', 'comments_can_post',
                      'comments_can_update', 'comments_can_soft_delete', 'attachments_can_upload', 'attachments_can_update',
                      'attachments_can_soft_delete']


def defaults(acp):
    print('--- D2. permission defaults grant no write access')
    from flows import form
    fix('clear-permissions')
    path = '/admin/index.php?module=user-groups&action=edit&gid=2'
    page = acp.get(path)
    f = form(page['body'], 'action=edit')
    prefilled = {c: (f['fields'].get(f'showcase[{c}]') or [''])[0] for c in WRITE_DEFAULTS_OFF}
    ok(set(prefilled.values()) == {'0'}, f'usergroup MyShowcase tab prefills the write permissions with 0 {prefilled}')
    data = {k: (v[0] if len(v) == 1 else v) for k, v in f['fields'].items() if k not in f['disabled']}
    acp.post('/admin/index.php?module=' + f['action'].replace('&amp;', '&').split('module=')[1], data)
    stored = q("select " + ', '.join(WRITE_DEFAULTS_OFF) + " from mybb_myshowcase_permissions where group_id='2' and showcase_id='0'")
    ok(stored and set(stored[0]) == {'0'}, f'saving the tab unchanged stores 0 for every write permission {stored[:1]}')
    m1 = user('member1')
    ok(no_permission(m1.get(f'{G}/create')), 'a member cannot post until the write permission is granted')
    fix('grants')
    r = m1.get(f'{G}/create')
    ok('id="contentForm"' in r['body'], 'granted explicitly: the member gets the create form')


def security():
    print('--- 1. authorization of the write handlers')
    guest, m1, m2, mod = user('guest'), user('member1'), user('member2'), user('mod1')

    r = guest.get(f'{G}/create')
    ok(no_permission(r) and 'id="contentForm"' not in r['body'], 'guest create form: no-permission page, no form')
    before = count(f"mybb_myshowcase_data{sid('gallery')}")
    files_before = count('mybb_myshowcase_attachments')
    r = guest.post(f'{G}/create', {'title': 'Guest entry', 'category': option_id('gallery', 'Nature'), 'post_hash': 'guesthash1',
                                   'my_post_key': post_key(r['body']), 'submit': 'Post'})
    ok(no_permission(r) and count(f"mybb_myshowcase_data{sid('gallery')}") == before, 'guest POST create refused, nothing stored')
    r = upload(guest, G, 'image', 'guesthash1', post_key(guest.get(f'{G}/')['body']), 'photo.jpg')
    ok(count('mybb_myshowcase_attachments') == files_before and r['status'] >= 400, f'guest upload refused, no attachment [{r["status"]}]')

    e1 = create_gallery(m1, 'Member one photo')['entry']
    e2 = create_gallery(m2, 'Member two photo', 'graphic.png')['entry']
    ok(e1 is not None and e2 is not None and e1['status'] == '1', 'members create entries (no moderation for them)')

    for action in ('open', 'close', 'stick', 'unstick', 'approve', 'unapprove', 'copy', 'soft_delete', 'restore', 'delete'):
        r = moderate(m2, action, [e1])
        after = entry('gallery', 'entry_id', e1['entry_id'])
        ok(no_permission(r) and after is not None and (after['status'], after['is_closed'], after['is_sticky']) == (e1['status'], e1['is_closed'], e1['is_sticky']),
           f'member without moderation rights: inline {action} refused, entry unchanged')
    r = moderate(m2, 'soft_delete', [e2])
    ok(no_permission(r) and entry('gallery', 'entry_id', e2['entry_id'])['status'] == '1', 'member: inline soft delete of an own entry refused without the permission')

    r = m2.get(f"{G}/update/{e1['public_id']}")
    ok(no_permission(r) and 'id="contentForm"' not in r['body'], "member: another user's edit form refused")
    r = m2.post(f"{G}/update/{e1['public_id']}", {'title': 'Hijacked', 'category': option_id('gallery', 'Nature'),
                                                    'my_post_key': post_key(m2.get(f'{G}/')['body']), 'submit': 'Post'})
    ok(no_permission(r) and entry('gallery', 'title', 'Hijacked') is None, "member: another user's entry not changed by a forged POST")
    files_before = count('mybb_myshowcase_attachments', f"entry_id='{e1['entry_id']}'")
    k = post_key(m2.get(f'{G}/')['body'])
    r = m2.upload(f"{G}/api/view/{e1['public_id']}/upload?my_post_key={k}&field_id={field_id('gallery', 'image')}",
                  {'my_post_key': k, 'field_id': field_id('gallery', 'image')}, 'image', os.path.join(MEDIA, 'static.gif'))
    ok(r['status'] >= 400 and count('mybb_myshowcase_attachments', f"entry_id='{e1['entry_id']}'") == files_before,
       f"member: upload to another user's entry refused [{r['status']}]")

    # Missing post key on every write handler.
    r = create_gallery(m1, 'No key entry', key='')
    ok(bad_post_key(r['post']) and r['entry'] is None, 'create without my_post_key refused')
    r = m1.post(f"{G}/update/{e1['public_id']}", {'title': 'No key edit', 'category': option_id('gallery', 'Nature'), 'submit': 'Post'})
    ok(bad_post_key(r) and entry('gallery', 'title', 'No key edit') is None, 'edit without my_post_key refused')
    r = moderate(mod, 'close', [e1], key=False)
    ok(bad_post_key(r) and entry('gallery', 'entry_id', e1['entry_id'])['is_closed'] == '0', 'inline moderation without my_post_key refused')
    comments_before = count('mybb_myshowcase_comments')
    r = comment(m1, e1, 'No key comment', key=False)
    ok(bad_post_key(r) and count('mybb_myshowcase_comments') == comments_before, 'comment without my_post_key refused')
    r = upload(m1, G, 'image', 'nokeyhash', '', 'photo.jpg')
    ok(r['status'] >= 400 and count('mybb_myshowcase_attachments', "post_hash='nokeyhash'") == 0, f'upload without my_post_key refused [{r["status"]}]')
    r = m1.get(f"{view_url(e1)}?action=subscribe")
    ok(bad_post_key(r) and count('mybb_myshowcase_subscriptions', f"entry_id='{e1['entry_id']}'") == 0, 'subscribe without my_post_key refused')
    r = mod.get(f"{G}/update/{e1['public_id']}?delete=2")
    ok(bad_post_key(r) and entry('gallery', 'entry_id', e1['entry_id']) is not None, 'entry page delete without my_post_key refused')

    # The form cannot set the columns the code owns.
    r = create_gallery(m1, 'Forged columns', extra={'user_id': USERS['member2']['uid'], 'status': 2, 'views': 999, 'is_sticky': 1,
                                                     'public_id': 'forged'})
    e = r['entry']
    ok(e is not None and int(e['user_id']) == USERS['member1']['uid'] and (e['status'], e['views'], e['is_sticky']) == ('1', '0', '0')
       and e['public_id'] != 'forged', 'create: user_id, status, views, is_sticky and public_id from the form are ignored')

    # Comments.
    r = comment(m2, e1, 'A visible comment by member two')
    ok(count('mybb_myshowcase_comments', f"entry_id='{e1['entry_id']}' and status='1'") == 1, 'member comments on an entry')
    c = q(f"select public_id from mybb_myshowcase_comments where entry_id='{e1['entry_id']}'")[0][0]
    page = m1.get(view_url(e1))
    r = m1.post(f"{G}/view/{e1['public_id']}/update/comment/{c}", {'message': 'Edited by someone else', 'my_post_key': post_key(page['body']), 'submit': 'Post'})
    ok(no_permission(r) and count('mybb_myshowcase_comments', "message='Edited by someone else'") == 0, "member: another user's comment cannot be edited")
    r = m2.post(f"{G}/view/{e2['public_id']}/update/comment/{c}", {'message': 'Moved', 'my_post_key': post_key(page['body']), 'submit': 'Post'})
    ok(count('mybb_myshowcase_comments', f"public_id='{c}' and entry_id='{e1['entry_id']}'") == 1 and count('mybb_myshowcase_comments', "message='Moved'") == 0,
       "a comment cannot be edited (or moved) through another entry's address")
    moderate(mod, 'close', [e1])
    ok(entry('gallery', 'entry_id', e1['entry_id'])['is_closed'] == '1', 'moderator: inline close')
    r = comment(m2, e1, 'Comment on a closed entry')
    ok(count('mybb_myshowcase_comments', "message='Comment on a closed entry'") == 0, 'comment on a closed entry refused')
    moderate(mod, 'open', [e1])
    ok(entry('gallery', 'entry_id', e1['entry_id'])['is_closed'] == '0', 'moderator: inline open')
    moderate(mod, 'stick', [e1])
    ok(entry('gallery', 'entry_id', e1['entry_id'])['is_sticky'] == '1', 'moderator: inline stick')
    moderate(mod, 'unstick', [e1])
    ok(entry('gallery', 'entry_id', e1['entry_id'])['is_sticky'] == '0', 'moderator: inline unstick')

    page = guest.get(view_url(e1))
    r = guest.get(f"{view_url(e1)}?action=subscribe&my_post_key={post_key(page['body'])}")
    ok(no_permission(r) and count('mybb_myshowcase_subscriptions', f"entry_id='{e1['entry_id']}' and user_id='0'") == 0, 'guest subscription refused')
    return e1, e2


def moderation(e1):
    print('--- 2. moderation of new entries')
    guest, m2, q1, mod = user('guest'), user('member2'), user('modq1'), user('mod1')
    r = create_gallery(q1, 'Waiting photo', extra={'status': 1})
    e = r['entry']
    ok(e is not None and e['status'] == '0', 'entry of the moderated group waits for approval (status 0, forged status ignored)')
    ok('Waiting photo' not in guest.get(f'{G}/')['body'] and 'Waiting photo' not in m2.get(f'{G}/')['body'], 'hidden from guests and other members in the main list')
    ok('Waiting photo' not in m2.get(f'{G}/?keywords=Waiting')['body'], 'hidden from other members in the search')
    fix('permission', 2, 'entries_can_view_unapproved', 1)
    ok('Waiting photo' not in m2.get(f'{G}/')['body'], 'hidden from members with "view unapproved" (their own entries only), the column default')
    fix('permission', 2, 'entries_can_view_unapproved', 0)
    r = m2.get(view_url(e))
    ok('Waiting photo' not in r['body'], 'entry page refused to other members')
    ok('Waiting photo' in q1.get(f'{G}/')['body'] and 'Waiting photo' in q1.get(view_url(e))['body'], 'visible to its author (list and entry page)')
    r = mod.get(f'{G}/?unapproved=1')
    ok('Waiting photo' in r['body'] and 'Member one photo' not in r['body'], 'moderator: approval queue lists only the waiting entries')
    ok('unapproved entries' in mod.get(f'{G}/')['body'], 'moderator: main list links to the approval queue')
    ok('Waiting photo' in mod.get(view_url(e))['body'], 'moderator sees the waiting entry')
    moderate(mod, 'approve', [e])
    ok(entry('gallery', 'entry_id', e['entry_id'])['status'] == '1' and 'Waiting photo' in guest.get(f'{G}/')['body'], 'approved: visible to everyone')
    fix('permission', 3, 'entries_moderate_new', 1)
    b = create_gallery(mod, 'Bypassing photo')['entry']
    ok(b is not None and b['status'] == '1', 'moderated group with "bypass moderation": new entry visible at once')
    fix('permission', 3, 'entries_moderate_new', 0)
    r = comment(q1, e1, 'Waiting comment')
    ok(count('mybb_myshowcase_comments', "message='Waiting comment' and status='0'") == 1, 'comment of the moderated group waits for approval')
    ok('Waiting comment' not in m2.get(view_url(e1))['body'] and 'Waiting comment' in mod.get(view_url(e1))['body'], 'waiting comment: hidden from members, shown to moderators')
    return e


def search(e1):
    print('--- 4. search')
    m2, guest, mod = user('member2'), user('guest'), user('mod1')
    r = m2.get(f'{G}/?keywords=' + 'Member')
    ok(r['status'] == 200 and not php_errors(r) and 'Member one photo' in r['body'] and 'Member two photo' in r['body'], 'keyword search on the title')
    r = m2.get(f'{G}/?keywords=Nature')
    ok(r['status'] == 200 and not php_errors(r) and 'Member one photo' in r['body'], 'keyword search on a category label')
    r = m2.get(f'{G}/search?keywords=Member')
    ok(r['status'] == 200 and not php_errors(r) and 'Member one photo' in r['body'], f'/search answers 200 with results [{r["status"]}]')
    r = guest.get(f'{G}/search')
    ok(r['status'] == 200 and not php_errors(r), '/search without keywords answers 200')
    moderate(mod, 'soft_delete', [e1])
    ok('Member one photo' not in m2.get(f'{G}/search?keywords=Member')['body'], 'soft-deleted entry excluded from the search of other users')
    ok('Member one photo' in mod.get(f'{G}/search?keywords=Member')['body'], 'moderator with view-deleted finds it')
    moderate(mod, 'restore', [e1])
    ok(entry('gallery', 'entry_id', e1['entry_id'])['status'] == '1', 'restore brings it back')


def attachments():
    print('--- 5. attachment and thumbnail serving')
    m1, m2, guest = user('member1'), user('member2'), user('guest')
    e = create_gallery(m1, 'Webp photo', 'image.webp')['entry']
    a = q(f"select public_id, attachment_name, thumbnail_name from mybb_myshowcase_attachments where entry_id='{e['entry_id']}'")[0]
    ok(a[2] == '', 'the WebP image has no thumbnail')
    thumb, orig = f'{G}/thumbnail/{a[0]}/{a[1]}', f'{G}/attachment/{a[0]}/{a[1]}'
    for who, c in (('guest', guest), ('member2', m2), ('owner', m1)):
        t, o = c.get(thumb), c.get(orig)
        ok(t['status'] == 200 and t['type'].startswith('image/webp') and o['status'] == 200 and t['raw'] == o['raw'],
           f'{who}: thumbnail without a thumbnail falls back to the original [{t["status"]} {t["type"]}]')
    j = create_gallery(m1, 'Jpeg photo', 'photo.jpg')['entry']
    b = q(f"select public_id, attachment_name, thumbnail_name from mybb_myshowcase_attachments where entry_id='{j['entry_id']}'")[0]
    ok(b[2] != '' and m2.get(f'{G}/thumbnail/{b[0]}/{b[1]}')['status'] == 200, 'a real thumbnail is served')
    fix('permission', 1, 'attachments_can_download', 0)
    fix('permission', 2, 'attachments_can_download', 0)
    for who, c in (('guest', guest), ('member2', m2)):
        t, o = c.get(f'{G}/thumbnail/{b[0]}/{b[1]}'), c.get(f'{G}/attachment/{b[0]}/{b[1]}')
        ok(t['status'] == 403 and o['status'] == 403, f'{who} without the download permission: 403 on both routes [{t["status"]} {o["status"]}]')
        t = c.get(thumb)
        ok(t['status'] == 403, f'{who} without the download permission: fallback thumbnail 403 too [{t["status"]}]')
    t, o = m1.get(f'{G}/thumbnail/{b[0]}/{b[1]}'), m1.get(f'{G}/attachment/{b[0]}/{b[1]}')
    ok(t['status'] == 200 and o['status'] == 200, f'owner without the download permission still gets both [{t["status"]} {o["status"]}]')
    fix('permission', 1, 'attachments_can_download', 1)
    fix('permission', 2, 'attachments_can_download', 1)
    r = guest.get(f'{G}/thumbnail/{b[0]}/nope.jpg')
    ok(r['status'] == 404, f'unknown attachment: 404, not 503 [{r["status"]}]')
    page = guest.get(view_url(j))
    src = re.findall(r'<img\s[^>]*src="([^"]*/(?:attachment|thumbnail)/[^"]+)"', page['body'])
    loaded = guest.get(src[0]) if src else {'status': 0, 'type': ''}
    ok(page['status'] == 200 and src and loaded['status'] == 200 and loaded['type'].startswith('image/'),
       f'entry page: the image address loads [{src[:1]} {loaded["status"]}]')
    return j


def edit_form(j):
    print('--- 5c. edit form keeps or replaces the image')
    m1 = user('member1')
    old = q(f"select attachment_id, file_name, attachment_name from mybb_myshowcase_attachments where entry_id='{j['entry_id']}'")[0]
    form = m1.get(f"{G}/update/{j['public_id']}")
    ok(old[2] in form['body'] and f'name="remove_attachments[]" value="{old[0]}"' in form['body'], 'edit form lists the attached image with a remove control')
    data = {'title': 'Jpeg photo', 'caption': 'kept', 'category': option_id('gallery', 'Nature'), 'my_post_key': post_key(form['body']), 'submit': 'Post'}
    m1.post(f"{G}/update/{j['public_id']}", data)
    ok(count('mybb_myshowcase_attachments', f"attachment_id='{old[0]}'") == 1, 'saving without removing keeps the image')
    r = m1.post(f"{G}/update/{j['public_id']}", dict(data, **{'remove_attachments[]': old[0]}))
    ok('A required field is missing' in r['body'] and count('mybb_myshowcase_attachments', f"attachment_id='{old[0]}'") == 1,
       'removing the only image of a required field is refused, the image stays')
    k = post_key(form['body'])
    up = m1.upload(f"{G}/api/view/{j['public_id']}/upload?my_post_key={k}&field_id={field_id('gallery', 'image')}",
                   {'my_post_key': k, 'field_id': field_id('gallery', 'image')}, 'image', os.path.join(MEDIA, 'second.jpg'))
    ok(up['status'] == 200, f'owner uploads a new image to the entry [{up["status"]}]')
    m1.post(f"{G}/update/{j['public_id']}", dict(data, **{'remove_attachments[]': old[0]}))
    left = q(f"select attachment_name from mybb_myshowcase_attachments where entry_id='{j['entry_id']}'")
    ok(left == [('second.jpg',)] and not os.path.exists(os.path.join(BOARD, 'uploads', 'myshowcase', old[1])), 'replace: the old image row and file are gone, the new one stays')
    other = one(f"select attachment_id from mybb_myshowcase_attachments where entry_id='{entry('gallery', 'title', 'Member two photo')['entry_id']}'")
    m1.post(f"{G}/update/{j['public_id']}", dict(data, **{'remove_attachments[]': other}))
    ok(count('mybb_myshowcase_attachments', f"attachment_id='{other}'") == 1, "the id of another entry's file in the form removes nothing")


def main_list():
    print('--- 6. main list with the global templates')
    m2 = user('member2')
    r = m2.get(f'{G}/')
    e = entry('gallery', 'title', 'Member one photo')
    ok(r['status'] == 200 and not php_errors(r), 'gallery main list renders')
    ok(f"/view/{e['public_id']}/" in r['body'] and 'thread__subject' in r['body'], 'row: subject linked to the entry')
    ok('member1' in r['body'] and 'thread__author' in r['body'], 'row: author')
    ok('thread__date' in r['body'] and 'thread__replies' in r['body'], 'row: date and comment count')
    ok(re.search(r'<a [^>]*href="[^"]*/view/[^"]+"[^>]*class="thread__image"[^>]*>\s*<img [^>]*src="[^"]*/(thumbnail|attachment)/', r['body']) is not None,
       'row: thumbnail of the first image, linked to the entry')
    ok(re.search(r'---\d{9,}---', r['body']) is None, 'no leftover debug output')
    be, br = create_blog(user('member1'), 'A blog post')
    r = m2.get(f'{B}/')
    ok(be is not None and r['status'] == 200 and not php_errors(r) and 'A blog post' in r['body'] and 'thread__image' not in r['body'],
       'blog main list: rows without an image block, no errors')
    r = m2.get(f'{B}/?keywords=blog')
    ok(r['status'] == 200 and not php_errors(r) and f"/view/{be['public_id']}/" in r['body'] and 'highlight' in r['body'],
       'blog: keyword search on the entry subject, highlighted')
    return be


def template_set():
    print('--- custom template set without the changed templates')
    d = os.path.join(BOARD, 'inc', 'plugins', 'myshowcase', 'view', 'templates', 'g2set')
    os.makedirs(d, exist_ok=True)
    open(os.path.join(d, 'pageAccess.twig'), 'w').write('<p>g2set</p>')
    try:
        fix('template-set', 'gallery', 'g2set')
        m2 = user('member2')
        r = m2.get(f'{G}/')
        ok(r['status'] == 200 and not php_errors(r) and 'class="thread__image"' in r['body'], 'main list falls back to the global templates')
        e = entry('gallery', 'title', 'Member one photo')
        r = m2.get(view_url(e))
        ok(r['status'] == 200 and not php_errors(r), 'entry page falls back to the global templates')
    finally:
        fix('template-set', 'gallery', '')
        shutil.rmtree(d, ignore_errors=True)


def deletion(e2):
    print('--- 3. deleting entries on every path')
    m1, m2, mod, leaver = user('member1'), user('member2'), user('mod1'), user('leaver')
    files = lambda eid: [r[0] for r in q(f"select file_name from mybb_myshowcase_attachments where entry_id='{eid}'")] + \
        [r[0] for r in q(f"select thumbnail_name from mybb_myshowcase_attachments where entry_id='{eid}' and thumbnail_name<>''")]
    exists = lambda names: [n for n in names if os.path.exists(os.path.join(BOARD, 'uploads', 'myshowcase', n))]

    def dependents(eid):
        return (count('mybb_myshowcase_attachments', f"entry_id='{eid}'"), count('mybb_myshowcase_comments', f"entry_id='{eid}' and showcase_id='{sid('gallery')}'"),
                count('mybb_myshowcase_subscriptions', f"entry_id='{eid}' and showcase_id='{sid('gallery')}'"))

    # Inline hard delete.
    comment(m1, e2, 'Comment to be deleted')
    page = m2.get(view_url(e2))
    m2.get(f"{view_url(e2)}?action=subscribe&my_post_key={post_key(page['body'])}")
    names = files(e2['entry_id'])
    ok(dependents(e2['entry_id']) == (1, 1, 1) and len(exists(names)) == len(names) > 0, 'entry with an attachment, a comment and a subscription')
    moderate(mod, 'delete', [e2])
    ok(entry('gallery', 'entry_id', e2['entry_id']) is None, 'inline hard delete removes the row')
    ok(dependents(e2['entry_id']) == (0, 0, 0) and exists(names) == [], 'inline hard delete removes attachments, files, thumbnails, comments, subscriptions')

    # Entry page delete.
    e = create_gallery(m1, 'Entry page delete')['entry']
    page = m1.get(view_url(e))
    r = m1.get(f"{G}/update/{e['public_id']}?delete=2&my_post_key={post_key(page['body'])}")
    ok(no_permission(r) and entry('gallery', 'entry_id', e['entry_id']) is not None, 'entry page: owner without the delete permission is refused')
    page = mod.get(view_url(e))
    names = files(e['entry_id'])
    mod.get(f"{G}/update/{e['public_id']}?delete=1&my_post_key={post_key(page['body'])}")
    ok(entry('gallery', 'entry_id', e['entry_id'])['status'] == '2', 'entry page: moderator soft delete')
    r = m1.get(f"{G}/update/{e['entry_slug']}")
    ok('id="contentForm"' not in r['body'], "edit form: the author without \"view deleted\" cannot open the own soft-deleted entry")
    mod.get(f"{G}/update/{e['public_id']}?action=restore&my_post_key={post_key(page['body'])}")
    ok(entry('gallery', 'entry_id', e['entry_id'])['status'] == '1', 'entry page: moderator restore')
    mod.get(f"{G}/update/{e['public_id']}?delete=2&my_post_key={post_key(page['body'])}")
    ok(entry('gallery', 'entry_id', e['entry_id']) is None and dependents(e['entry_id'])[0] == 0 and exists(names) == [], 'entry page: moderator hard delete removes the row and files')

    # Pruning task: no showcase can have a pruning age (the prune_time column is gone), so the task deletes
    # nothing; the deletion it does for each old entry is checked outside a forum request.
    total = count(f"mybb_myshowcase_data{sid('gallery')}")
    fix('task')
    ok(count(f"mybb_myshowcase_data{sid('gallery')}") == total, 'pruning task runs and deletes nothing without a pruning age')
    e = create_gallery(m1, 'Old photo')['entry']
    comment(m2, e, 'Comment on an old photo')
    names = files(e['entry_id'])
    keep = entry('gallery', 'title', 'Member one photo')
    fix('delete-entry', 'gallery', e['entry_id'])
    ok(entry('gallery', 'entry_id', e['entry_id']) is None and dependents(e['entry_id']) == (0, 0, 0) and exists(names) == [],
       "the task's deletion (outside a forum request) removes the entry and its dependents")
    ok(entry('gallery', 'entry_id', keep['entry_id']) is not None, 'other entries stay')

    # User deletion.
    e = create_gallery(leaver, 'Leaver photo')['entry']
    comment(leaver, keep, 'Comment by the leaver')
    names = files(e['entry_id'])
    others = count(f"mybb_myshowcase_data{sid('gallery')}", f"user_id<>'{USERS['leaver']['uid']}'")
    fix('delete-user', 'leaver')
    ok(entry('gallery', 'entry_id', e['entry_id']) is None and dependents(e['entry_id']) == (0, 0, 0) and exists(names) == [],
       "user deletion removes the user's entries and their dependents")
    ok(count(f"mybb_myshowcase_data{sid('gallery')}", f"user_id<>'{USERS['leaver']['uid']}'") == others, "user deletion keeps other users' entries")
    r = m2.get(view_url(keep))
    ok(r['status'] == 200 and not php_errors(r), 'an entry with a comment of the deleted user still renders')

    # Showcase deletion.
    bsid = sid('blog')
    be = entry('blog', 'subject', 'A blog post')
    comment_count = count('mybb_myshowcase_comments', f"showcase_id='{bsid}'")
    fix('delete-showcase', 'blog')
    ok(sid('blog') == 0 and count('mybb_myshowcase_comments', f"showcase_id='{bsid}'") == 0 and count('mybb_myshowcase_attachments', f"showcase_id='{bsid}'") == 0,
       'showcase deletion removes the showcase, its comments and attachments')
    ok(entry('gallery', 'title', 'Member one photo') is not None, 'showcase deletion leaves the other showcase alone')


def logs():
    print('--- logs')
    log = os.path.join(SCRATCH, f'php_{CTX.engine}.log')
    lines = [l for l in open(log, errors='replace').read().splitlines()
             if re.search(r'PHP (Warning|Notice|Fatal error|Deprecated|Parse error)', l) and 'chdir()' not in l]
    ok(not lines, 'PHP log: no warnings besides the chdir() one' + (f' ({lines[0][:200]})' if lines else ''))
    err = os.path.join(BOARD, 'error.log')
    errors = re.findall(r'<error>(.*?)</error>', open(err, errors='replace').read(), re.S) if os.path.exists(err) else []
    # MyBB itself on the command line (installer, this suite's fixture) and under PHP's built-in server.
    environment = ('bin/cli', 'REMOTE_ADDR', 'QUERY_STRING', 'uploads/avatars')
    mine = [re.sub(r'\s+', ' ', e)[:300] for e in errors if not any(n in e for n in environment)]
    ok(not mine, f'MyBB error log: no MyShowcase errors or warnings ({len(errors)} environment entries)' + (f' ({mine[0]})' if mine else ''))


def run(acp):
    setup(acp)
    defaults(acp)
    e1, e2 = security()
    moderation(e1)
    search(e1)
    j = attachments()
    edit_form(j)
    main_list()
    template_set()
    deletion(e2)
    logs()
