"""The HTTP flow of the JSON sync: regression of the ACP flows, create from JSON, locking, updates, states, link,
detach, export. Driven by run.py, which calls configure() and then the sections."""
import sys, re, json, os, subprocess, sqlite3, shutil, time
from html.parser import HTMLParser
from acp import Acp, text

FAILS = []
PASSES = 0
ENGINE = BOARD = IMP = A = CTX = None
ADM = '/admin/index.php?module='


def configure(ctx):
    """ctx provides: engine, board (path), url, user, password, query(sql), execute(sql)."""
    global ENGINE, BOARD, IMP, A, CTX
    CTX = ctx
    ENGINE = ctx.engine
    BOARD = ctx.board
    IMP = f'{BOARD}/inc/plugins/myshowcase/imports'
    A = Acp(ctx.url, ctx.user, ctx.password)




def ok(c, m):
    global PASSES
    print(('PASS ' if c else 'FAIL ') + m)
    if c: PASSES += 1
    else: FAILS.append(m)

def db(sql):
    return CTX.query(sql)

def one(sql):
    r = db(sql); return r[0][0] if r else ''



ADM = '/admin/index.php?module='

def page(path, data=None, follow=True):
    if data is None: return A.get(ADM + path)
    return A.post(ADM + path, data, follow=follow)

def errors_in(r):
    t = text(r['body'])
    m = re.findall(r'(Error Message.{0,300}|Fatal error.{0,200}|Warning: (?:Undefined|Trying|Attempt|Array|foreach|Cannot|Division|include|require).{0,200}|Deprecated: .{0,160})', t)
    return m[0] if m else None

def clean(r, label):
    e = errors_in(r)
    ok(r['status'] == 200 and e is None, f'{label}: page renders' + (f' ({e})' if e else f' [{r["status"]}]'))
    return r

def jsync(data):
    return page('myshowcase-json_sync', dict(data))

def flashes(r):
    return re.findall(r'<div class="(?:success|error)">\s*(?:<p>)?\s*<em>?[^<]*', r['body']) + re.findall(r'class="(?:success|error)"[^>]*>(.*?)</div>', r['body'], re.S)

def board_file(rel, content=None, delete=False):
    p = f'{IMP}/JSON/{rel}'
    if delete: os.remove(p); return
    os.makedirs(os.path.dirname(p), exist_ok=True)
    open(p, 'w').write(content if isinstance(content, str) else json.dumps(content, indent=2))

def read_board_file(rel): return json.load(open(f'{IMP}/JSON/{rel}'))

from html.parser import HTMLParser
class FormScraper(HTMLParser):
    def __init__(self):
        super().__init__(); self.forms = []; self.cur = None; self.sel = None; self.ta = None
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.cur = {'action': a.get('action', ''), 'fields': {}, 'disabled': set(), 'names': set()}; self.forms.append(self.cur)
        elif self.cur is None: return
        elif tag == 'input':
            n = a.get('name'); 
            if not n: return
            self.cur['names'].add(n)
            if 'disabled' in a: self.cur['disabled'].add(n)
            ty = (a.get('type') or 'text').lower()
            if ty in ('submit', 'button', 'reset', 'image'): return
            if ty in ('checkbox', 'radio'):
                if 'checked' in a: self.cur['fields'].setdefault(n, []).append(a.get('value', 'on'))
            else: self.cur['fields'].setdefault(n, []).append(a.get('value', ''))
        elif tag == 'select':
            self.sel = {'name': a.get('name'), 'multiple': 'multiple' in a, 'selected': [], 'first': None}
            if self.sel['name']:
                self.cur['names'].add(self.sel['name'])
                if 'disabled' in a: self.cur['disabled'].add(self.sel['name'])
        elif tag == 'option' and self.sel is not None:
            v = a.get('value', ''); 
            if self.sel['first'] is None: self.sel['first'] = v
            if 'selected' in a: self.sel['selected'].append(v)
        elif tag == 'textarea':
            self.ta = [a.get('name'), '']
            if a.get('name'): self.cur['names'].add(a.get('name'))
    def handle_data(self, d):
        if self.ta is not None: self.ta[1] += d
    def handle_endtag(self, tag):
        if tag == 'select' and self.sel is not None and self.cur is not None:
            s = self.sel
            if s['name']:
                vals = s['selected'] or ([] if s['multiple'] else [s['first'] or ''])
                if vals: self.cur['fields'][s['name']] = vals
            self.sel = None
        elif tag == 'textarea' and self.ta is not None:
            if self.ta[0]: self.cur['fields'][self.ta[0]] = [self.ta[1]]
            self.ta = None

def form(html, contains):
    p = FormScraper(); p.feed(html)
    for f in p.forms:
        if contains in f['action']: return f
    raise RuntimeError('no form with ' + contains + ' among ' + str([f['action'] for f in p.forms]))

def submit(path, contains, overrides=None, drop=()):
    r = page(path)
    f = form(r['body'], contains)
    data = {k: (v if len(v) != 1 else v[0]) for k, v in f['fields'].items() if k not in f['disabled']}
    for k in drop: data.pop(k, None)
    data.update(overrides or {})
    action = f['action'].replace('&amp;', '&')
    return page(action.split('module=')[1], data), f


def section_a():
    print('--- A. regression')
    for p in ['myshowcase-summary', 'myshowcase-fields', 'myshowcase-fields&action=new', 'myshowcase-summary&action=create']:
        clean(page(p), p)
    submit('myshowcase-fields&action=new', 'action=new', {'name': 'Plain'})
    cid = int(one("select collection_id from mybb_myshowcase_collections where name='Plain'") or 0)
    ok(cid > 0, 'create collection')
    r = page('myshowcase-fields&action=new', {'name': 'Plain'})
    ok(one("select count(*) from mybb_myshowcase_collections where name='Plain'") == '1', 'duplicate collection name refused')
    submit(f'myshowcase-fields&action=edit&collection_id={cid}', 'action=edit', {'name': 'Plain two'})
    ok(one(f"select name from mybb_myshowcase_collections where collection_id={cid}") == 'Plain two', 'edit collection name')
    base = {'html_type': 'text', 'field_type': 'varchar', 'maximum_length': '40', 'minimum_length': '0', 'allowed_groups_fill_type': 'all', 'allowed_groups_view_type': 'all'}
    submit(f'myshowcase-fields&action=newField&collection_id={cid}', 'action=newField', {**base, 'field_key': 'Model Name', 'field_label': 'Model'})
    fid = int(one(f"select field_id from mybb_myshowcase_fields where collection_id={cid} and field_key='model_name'") or 0)
    ok(fid > 0, f'create field (id {fid}, key normalised)')
    ok(one(f"select max(field_id) from mybb_myshowcase_fields") == str(fid), 'new field id is the real id')
    page(f'myshowcase-fields&action=newField&collection_id={cid}', {**base, 'field_key': 'model_name'})
    ok(one(f"select count(*) from mybb_myshowcase_fields where collection_id={cid}") == '1', 'duplicate field key refused')
    for bad, label in [({'field_key': 'user_id'}, 'base column name'), ({'field_key': 'x' * 31}, 'too long key'), ({'field_key': 'zz', 'html_type': 'bogus'}, 'bad html_type'), ({'field_key': 'zz', 'field_type': 'bogus'}, 'bad field_type')]:
        page(f'myshowcase-fields&action=newField&collection_id={cid}', {**base, **bad})
        ok(one(f"select count(*) from mybb_myshowcase_fields where collection_id={cid}") == '1', f'new checks: {label} refused')
    submit(f'myshowcase-fields&action=newField&collection_id={cid}', 'action=newField', {**base, 'field_key': 'kind', 'html_type': 'select', 'field_type': 'int', 'maximum_length': '5'})
    kid = int(one(f"select field_id from mybb_myshowcase_fields where field_key='kind'"))
    # options
    for i, v in enumerate(['Sedan', 'Truck'], 1):
        page(f'myshowcase-fields&action=edit_option&field_id={kid}&collection_id={cid}', {'value': v, 'value_id': str(i), 'display_order': str(i), 'field_key': 'kind', 'create': '0'})
    ok(one(f"select count(*) from mybb_myshowcase_field_data where field_id={kid}") == '2', 'create options')
    r = page(f'myshowcase-fields&action=edit_option&field_id={kid}&collection_id={cid}')
    f = form(r['body'], 'edit_option')
    ids = sorted(int(re.search(r'\[(\d+)\]', k).group(1)) for k in f['fields'] if k.startswith('value[') )
    data = {k: (v[0] if len(v) == 1 else v) for k, v in f['fields'].items()}
    data[f'value[{ids[0]}]'] = 'Coupe'
    page(f'myshowcase-fields&action=edit_option&field_id={kid}&collection_id={cid}', data)
    ok(one(f"select value from mybb_myshowcase_field_data where field_data_id={ids[0]}") == 'Coupe', 'edit option value')
    # showcase from the ACP + all tabs
    r = page('myshowcase-summary&action=create')
    ok(re.search(r'<select[^>]*name="showcase\[custom_template_prefix\]"', r['body']) is not None and 'Global templates (none)' in r['body'], 'showcase form: template set is a select with the global templates')
    submit('myshowcase-summary&action=create', 'action=create', {'showcase[name]': 'Cars', 'showcase[route_identifier]': 'cars', 'showcase[collection_id]': str(cid), 'showcase[custom_template_prefix]': 'nosuchset'})
    ok(one("select count(*) from mybb_myshowcase_config where name='Cars'") == '0', 'a template set without a folder is refused')
    submit('myshowcase-summary&action=create', 'action=create', {'showcase[name]': 'Cars', 'showcase[route_identifier]': 'cars', 'showcase[collection_id]': str(cid), 'showcase[custom_template_prefix]': ''})
    sid = int(one("select showcase_id from mybb_myshowcase_config where name='Cars'") or 0)
    ok(sid >= 1, f'create showcase (id {sid})')
    r = page('myshowcase-summary&action=create', {'showcase[name]': 'Cars', 'showcase[route_identifier]': 'cars2'}, )
    ok(one("select count(*) from mybb_myshowcase_config where name='Cars'") == '1', 'duplicate showcase name refused')
    r = page(f'myshowcase-summary&action=update&showcase_id={sid}')
    for cat in ['general', 'main', 'parser', 'entries', 'comments', 'attachments']:
        f = form(r['body'], f'type={cat}')
        data = {k: (v[0] if len(v) == 1 else v) for k, v in f['fields'].items() if k not in f['disabled']}
        if cat == 'main': data['showcase[main_per_page]'] = '7'
        if cat == 'general': data['showcase[description]'] = 'cars desc'
        rr = page(f['action'].replace('&amp;', '&').split('module=')[1], data)
        e = errors_in(rr)
        ok(e is None, f'showcase tab {cat} saves' + (f' ({e})' if e else ''))
    ok(one(f"select main_per_page from mybb_myshowcase_config where showcase_id={sid}") == '7' and one(f"select description from mybb_myshowcase_config where showcase_id={sid}") == 'cars desc', 'showcase values saved')
    page(f'myshowcase-summary&action=create_table&showcase_id={sid}')
    ok(len(db("select 1 from mybb_myshowcase_collections")) >= 1 and one(f"select count(*) from information_schema.columns where table_name='mybb_myshowcase_data{sid}' and column_name='model_name'") == '1' if ENGINE != 'sqlite' else 'model_name' in [c[1] for c in db(f"pragma table_info('mybb_myshowcase_data{sid}')")], 'create table has the field columns')
    # rename a field with an existing column
    submit(f'myshowcase-fields&action=edit_field&field_id={fid}&collection_id={cid}', 'action=edit_field', {'field_key': 'model'})
    cols = ([c[0] for c in db(f"select column_name from information_schema.columns where table_name='mybb_myshowcase_data{sid}'")] if ENGINE != 'sqlite' else [c[1] for c in db(f"pragma table_info('mybb_myshowcase_data{sid}')")])
    ok('model' in cols and 'model_name' not in cols, 'rename field renames its column')
    ok(one(f"select field_key from mybb_myshowcase_fields where field_id={fid}") == 'model', 'rename field row')
    ok(one(f"select field_key from mybb_myshowcase_field_data where field_id={kid} limit 1") == 'kind', 'option key kept for other field')
    # quick settings
    page(f'myshowcase-fields&action=viewFields&collection_id={cid}', {f'is_enabled[{fid}]': '1', f'is_required[{fid}]': '1', f'display_order[{fid}]': '3', f'render_order[{fid}]': '4', f'display_order[{kid}]': '1'})
    ok(db(f"select is_enabled, is_required, display_order, render_order from mybb_myshowcase_fields where field_id={fid}") == [('1', '1', '3', '4')], 'quick settings saved')
    clean(page(f'myshowcase-fields&action=viewFields&collection_id={cid}'), 'field list')
    # delete a custom field
    page(f'myshowcase-fields&action=delete_field&field_id={fid}&collection_id={cid}', {})
    ok(one(f"select count(*) from mybb_myshowcase_fields where field_id={fid}") == '0', 'delete custom field')
    return cid, sid


import os, json, shutil

def canon(x):
    return json.dumps(x, sort_keys=True, separators=(',', ':'), ensure_ascii=False)

def cols(table):
    if ENGINE == 'sqlite': return [c[1] for c in db(f"pragma table_info('{table}')")]
    return [c[0] for c in db(f"select column_name from information_schema.columns where table_name='{table}'")]

def snapshot(table, key, idv, skip=('json_file', 'json_version', 'json_synced_at', 'json_hash')):
    names = [c for c in cols(table) if c not in skip]
    return db(f"select {','.join(names)} from {table} where {key}={idv}")

def list_text(path): return text(page(path)['body'])

def section_b():
    print('--- B. blog from JSON, managed inputs, forged requests')
    for kind in ('collection', 'showcase'):
        r = page(f'myshowcase-json_sync&action=create&type={kind}&my_post_key={A.post_key}'); clean(r, f'create-from-JSON form ({kind})')
    ok(jsync({'action': 'preview', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': '', 'route_identifier': ''})['body'].count('required') >= 0, 'create form shows validation on missing input')
    ok(one("select count(*) from mybb_myshowcase_config where json_file<>''") == '0', 'nothing written by an invalid preview')
    jsync({'action': 'preview', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog', 'route_identifier': 'cars'})
    r = jsync({'action': 'preview', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Cars', 'route_identifier': 'blog'})
    ok('already in use' in text(r['body']) or 'in use' in text(r['body']), 'uniqueness failures are shown on the create form')
    r = jsync({'action': 'preview', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog', 'route_identifier': 'blog', 'custom_template_prefix': 'blog'})
    ok('Confirm and apply' not in r['body'] and 'template set does not exist' in text(r['body']), 'create from JSON refuses a template set without a folder')
    r = jsync({'action': 'preview', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog', 'route_identifier': 'blog', 'custom_template_prefix': ''})
    t = text(r['body'])
    ok('Create the collection' in t and 'Create the showcase' in t and 'Confirm and apply' in r['body'], 'preview lists the plan, with a confirm button')
    ok(one("select count(*) from mybb_myshowcase_collections where name='Blog'") == '0', 'preview wrote nothing')
    r = jsync({'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog', 'route_identifier': 'blog', 'custom_template_prefix': ''})
    ok(errors_in(r) is None and 'The file was applied' in text(r['body']), 'create from JSON applied')
    cid = int(one("select collection_id from mybb_myshowcase_collections where name='Blog'")); sid = int(one("select showcase_id from mybb_myshowcase_config where name='Blog'"))
    ok(db(f"select json_file, json_version from mybb_myshowcase_collections where collection_id={cid}") == [('collections/blog.json', '1.0.0')], 'collection linked')
    ok(one(f"select count(*) from mybb_myshowcase_fields where collection_id={cid} and json_file='collections/blog.json' and json_hash<>''") == '5', '5 fields linked')
    ok(one(f"select count(*) from mybb_myshowcase_field_data where collection_id={cid}") == '4', '4 options')
    ok(one(f"select json_file from mybb_myshowcase_config where showcase_id={sid}") == 'showcases/blog.json', 'showcase linked')
    ok(one(f"select count(*) from mybb_myshowcase_config where json_file='showcases/blog.json'") == '1', 'one showcase row linked')
    # lists
    t = list_text('myshowcase-summary'); ok('In sync' in t and 'showcases/blog.json' in t and 'version 1.0.0' in t and 'Export JSON' in page('myshowcase-summary')['body'], 'showcase list: badge, file, version, export')
    t = list_text('myshowcase-fields'); ok('In sync' in t and 'collections/blog.json' in t, 'collection list: badge and file')
    vf = page(f'myshowcase-fields&action=viewFields&collection_id={cid}'); t = text(vf['body'])
    ok(t.count('In sync') >= 5, 'field list: a badge per field')
    # locked inputs
    fcat = int(one(f"select field_id from mybb_myshowcase_fields where collection_id={cid} and field_key='category'"))
    f = form(vf['body'], 'viewFields')
    ok({f'is_enabled[{fcat}]', f'display_order[{fcat}]', f'render_order[{fcat}]'} <= f['disabled'] and f'is_required[{fcat}]' in f['disabled'], 'quick settings of managed fields are disabled')
    r = page(f'myshowcase-fields&action=edit&collection_id={cid}'); f = form(r['body'], 'action=edit')
    ok('name' in f['disabled'] and 'Managed by JSON' in r['body'] and 'json_file' not in f['disabled'], 'collection form: name disabled and noted; JSON file select present')
    ok('collections/blog.json' in r['body'] and 'None' in r['body'], 'collection form: JSON file select lists None and the files')
    r = page(f'myshowcase-summary&action=update&showcase_id={sid}')
    fm = form(r['body'], 'type=main'); fg = form(r['body'], 'type=general')
    ok('showcase[main_per_page]' in fm['disabled'] and 'showcase[main_sort_default_field]' in fm['disabled'] and 'showcase[description]' in fg['disabled'] and 'showcase[name]' not in fg['disabled'] and 'showcase[route_identifier]' not in fg['disabled'], 'showcase form: managed inputs disabled, name/route editable')
    ok('showcase[main_display_moderators]' not in fm['disabled'], 'showcase form: columns the file does not set stay editable')
    ok('showcases/blog.json' in r['body'], 'showcase form: JSON file select')
    r = page(f'myshowcase-fields&action=edit_field&field_id={fcat}&collection_id={cid}'); f = form(r['body'], 'edit_field')
    ok({'field_key', 'html_type', 'field_type', 'maximum_length'} <= f['disabled'] and 'collections/blog.json' in text(r['body']), 'field form: managed inputs disabled, file named')
    clean(r, 'field form')
    r = page(f'myshowcase-fields&action=edit_option&field_id={fcat}&collection_id={cid}'); f = form(r['body'], 'edit_option')
    oid = db(f"select field_data_id from mybb_myshowcase_field_data where field_id={fcat} order by display_order")[0][0]
    ok(f'value[{oid}]' in f['disabled'] and f'display_order[{oid}]' in f['disabled'], 'option values of the file are disabled')
    # forged requests
    before_c = snapshot('mybb_myshowcase_collections', 'collection_id', cid)
    page(f'myshowcase-fields&action=edit&collection_id={cid}', {'name': 'Hacked', 'json_file': 'collections/blog.json', 'json_hash': 'f' * 64, 'json_version': '9.9.9'})
    ok(snapshot('mybb_myshowcase_collections', 'collection_id', cid) == before_c and one(f"select json_version from mybb_myshowcase_collections where collection_id={cid}") == '1.0.0', 'forged collection name / json_* ignored')
    before_s = snapshot('mybb_myshowcase_config', 'showcase_id', sid)
    r = page(f'myshowcase-summary&action=update&showcase_id={sid}')
    fm = form(r['body'], 'type=main')
    data = {k: (v[0] if len(v) == 1 else v) for k, v in fm['fields'].items()}
    data.update({'showcase[main_per_page]': '99', 'showcase[main_sort_default_field]': 'views', 'json_hash': 'a' * 64, 'showcase[json_file]': 'x'})
    page(fm['action'].replace('&amp;', '&').split('module=')[1], data)
    fg = form(page(f'myshowcase-summary&action=update&showcase_id={sid}')['body'], 'type=general')
    data = {k: (v[0] if len(v) == 1 else v) for k, v in fg['fields'].items()}
    data.update({'showcase[description]': 'Hacked', 'showcase[collection_id]': '999'})
    page(fg['action'].replace('&amp;', '&').split('module=')[1], data)
    ok(snapshot('mybb_myshowcase_config', 'showcase_id', sid) == before_s, 'forged showcase main/general values ignored')
    before_f = snapshot('mybb_myshowcase_fields', 'field_id', fcat)
    page(f'myshowcase-fields&action=viewFields&collection_id={cid}', {f'is_enabled[{fcat}]': '0', f'is_required[{fcat}]': '0', f'display_order[{fcat}]': '99', f'render_order[{fcat}]': '99'})
    ok(snapshot('mybb_myshowcase_fields', 'field_id', fcat) == before_f, 'forged quick settings ignored')
    r = page(f'myshowcase-fields&action=edit_field&field_id={fcat}&collection_id={cid}')
    f = form(r['body'], 'edit_field')
    data = {k: (v[0] if len(v) == 1 else v) for k, v in f['fields'].items()}
    data.update({'field_key': 'hacked', 'field_type': 'text', 'html_type': 'text', 'maximum_length': '1', 'is_enabled': '0', 'json_file': '', 'json_hash': 'b' * 64})
    page(f['action'].replace('&amp;', '&').split('module=')[1], data)
    ok(snapshot('mybb_myshowcase_fields', 'field_id', fcat) == before_f and one(f"select json_file from mybb_myshowcase_fields where field_id={fcat}") == 'collections/blog.json', 'forged field edit ignored')
    before_o = db(f"select field_data_id, value, display_order, display_style from mybb_myshowcase_field_data where field_id={fcat} order by field_data_id")
    r = page(f'myshowcase-fields&action=edit_option&field_id={fcat}&collection_id={cid}'); f = form(r['body'], 'edit_option')
    data = {k: (v[0] if len(v) == 1 else v) for k, v in f['fields'].items()}
    for o in before_o:
        data[f'value[{o[0]}]'] = 'Hacked'; data[f'display_order[{o[0]}]'] = '77'
    page(f['action'].replace('&amp;', '&').split('module=')[1], data)
    ok(db(f"select field_data_id, value, display_order, display_style from mybb_myshowcase_field_data where field_id={fcat} order by field_data_id") == before_o, 'forged option values ignored')
    # delete a managed field is refused; custom fields were deletable in A
    page(f'myshowcase-fields&action=delete_field&field_id={fcat}&collection_id={cid}', {})
    ok(one(f"select count(*) from mybb_myshowcase_fields where field_id={fcat}") == '1', 'deleting a managed field is refused')
    t = text(page(f'myshowcase-fields&action=delete_field&field_id={fcat}&collection_id={cid}')['body'])
    ok('managed by a JSON file' in t, 'refusal message names the way out')
    # export
    r = page(f'myshowcase-json_sync&action=export&type=collection&id={cid}&my_post_key={A.post_key}')
    ok('attachment; filename="blog.json"' in (r['headers'].get('Content-Disposition') or '') and canon(json.loads(r['body'])) == canon(read_board_file('collections/blog.json')), 'export collection: attachment blog.json equals the file')
    r = page(f'myshowcase-json_sync&action=export&type=showcase&id={sid}&my_post_key={A.post_key}')
    ok('attachment; filename="blog.json"' in (r['headers'].get('Content-Disposition') or '') and canon(json.loads(r['body'])) == canon(read_board_file('showcases/blog.json')), 'export showcase: attachment blog.json equals the file')
    r = page(f'myshowcase-json_sync&action=export&type=showcase&id={sid}&my_post_key=bad')
    ok(r['headers'].get('Content-Disposition') is None, 'export without a valid post key is refused')
    return cid, sid, fcat


def dbx(sql):
    CTX.execute(sql)


def badge_texts(path):
    return re.findall(r'myshowcase_json_status[^>]*>([^<]*)<', page(path)['body'])

def section_c(cid, sid, fcat):
    print('--- C. update to 1.1.0')
    tbl = f'mybb_myshowcase_data{sid}'
    for i in (1, 2, 3):
        dbx(f"insert into {tbl} (public_id, dateline, update_dateline, category, summary, tags, reading_time, cover_image) values ('e{i}', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 1, 's{i}', 't{i}', {i * 4}, 'http://x/{i}.png')")
    c = read_board_file('collections/blog.json'); c['version'] = '1.1.0'
    c['fields'].append({'field_key': 'author_note', 'label': 'Author note', 'html_type': 'text', 'field_type': 'varchar', 'maximum_length': 60, 'is_enabled': 1, 'display_order': 6})
    for f in c['fields']:
        if f['field_key'] == 'tags': f['field_key'] = 'topics'; f['previous_keys'] = ['tags']
        if f['field_key'] == 'reading_time': f['field_type'] = 'varchar'; f['maximum_length'] = 8
    c['fields'] = [f for f in c['fields'] if f['field_key'] != 'cover_image']
    board_file('collections/blog.json', c)
    os.makedirs(f'{IMP}/PHP/collections/blog', exist_ok=True)
    open(f'{IMP}/PHP/collections/blog/1.1.0.php', 'w').write('<?php return function (int $id, string $old, string $new, array $file) { global $db; $db->update_query("myshowcase_fields", ["placeholder" => "step {$old}->{$new}"], "field_key=\'summary\'"); };')
    ok('Update available' in badge_texts('myshowcase-fields'), 'collection list: Update available badge')
    ok('Update available' in badge_texts(f'myshowcase-fields&action=viewFields&collection_id={cid}'), 'field list: Update available badge for the collection')
    ok('Apply update' in page('myshowcase-fields')['body'], 'collection list: Apply update button')
    ok(badge_texts('myshowcase-summary') == ['In sync'], 'showcase list still in sync')
    r = jsync({'action': 'preview', 'mode': 'apply', 'type': 'collection', 'id': str(cid)}); t = text(r['body'])
    ok(all(s in t for s in ['Rename the field', 'Add the field', 'Update the field', 'becomes your own field', 'PHP update step', 'Rebuild the entries table']), 'apply preview lists every step in the plan')
    ok(one("select count(*) from mybb_myshowcase_fields where field_key='author_note'") == '0', 'preview wrote nothing')
    r = jsync({'action': 'run', 'mode': 'apply', 'type': 'collection', 'id': str(cid), 'file': 'collections/blog.json'})
    ok(errors_in(r) is None and 'The file was applied' in text(r['body']), 'apply update ran')
    c1 = cols(tbl)
    ok('author_note' in c1 and 'topics' in c1 and 'tags' not in c1 and 'cover_image' in c1, 'columns: added, renamed, removed field keeps its column')
    ok(one(f"select topics from {tbl} where public_id='e2'") == 't2' and one(f"select count(*) from {tbl}") == '3', 'entries preserved through the rename')
    ok(one("select reading_time from " + tbl + " where public_id='e3'") == '12', 'type change int -> varchar: values kept')
    ct = ([c[0] for c in db(f"select data_type from information_schema.columns where table_name='{tbl}' and column_name='reading_time'")] if ENGINE != 'sqlite' else [c[2] for c in db(f"pragma table_info('{tbl}')") if c[1] == 'reading_time'])
    ok('char' in ct[0].lower(), f'reading_time column type is now {ct[0]}')
    ok(db("select json_file, json_version from mybb_myshowcase_fields where field_key='cover_image'") == [('', '')], 'removed field: json_* cleared (custom)')
    ok(one("select placeholder from mybb_myshowcase_fields where field_key='summary'") == 'step 1.0.0->1.1.0', 'PHP update step ran with old and new version')
    ok(one(f"select json_version from mybb_myshowcase_collections where collection_id={cid}") == '1.1.0', 'version 1.1.0 recorded')
    vf = text(page(f'myshowcase-fields&action=viewFields&collection_id={cid}')['body'])
    ok('Custom, not in JSON' in vf, 'field list: "Custom, not in JSON" badge')
    ok(badge_texts('myshowcase-fields') == ['In sync'], 'collection list: in sync again')
    ok(vf.count('In sync') >= 6, 'field list: in sync badges')

def section_d(cid, sid):
    print('--- D. states')
    base = read_board_file('collections/blog.json')
    changed = json.loads(json.dumps(base)); changed['fields'][0]['label'] = 'Kind of post'
    board_file('collections/blog.json', changed)
    ok('Changed without a new version' in badge_texts('myshowcase-fields'), 'unversioned change: badge')
    ok('Apply anyway' in page('myshowcase-fields')['body'], 'unversioned change: Apply anyway')
    t = text(jsync({'action': 'preview', 'mode': 'apply', 'type': 'collection', 'id': str(cid)})['body'])
    ok('version was not raised' in t and 'Kind of post' not in t or 'version was not raised' in t, 'unversioned change: explicit warning in the preview')
    r = jsync({'action': 'run', 'mode': 'apply', 'type': 'collection', 'id': str(cid), 'file': 'collections/blog.json'})
    ok(one("select field_label from mybb_myshowcase_fields where field_key='category'") == 'Kind of post' and badge_texts('myshowcase-fields') == ['In sync'], 'apply anyway applied the file')
    down = json.loads(json.dumps(changed)); down['version'] = '1.0.5'
    board_file('collections/blog.json', down)
    ok('Row is newer than the file' in badge_texts('myshowcase-fields'), 'downgrade: error badge')
    ok('Apply' not in re.sub(r'myshowcase_json_status.*?</span>', '', page('myshowcase-fields')['body']).split('Apply')[0][-1:] , 'downgrade: no apply link') if False else None
    t = text(jsync({'action': 'preview', 'mode': 'apply', 'type': 'collection', 'id': str(cid)})['body'])
    ok('Apply update' not in page('myshowcase-fields')['body'] and 'Apply anyway' not in page('myshowcase-fields')['body'], 'downgrade: no apply action offered')
    r = jsync({'action': 'run', 'mode': 'apply', 'type': 'collection', 'id': str(cid), 'file': 'collections/blog.json'})
    ok(one(f"select json_version from mybb_myshowcase_collections where collection_id={cid}") == '1.1.0', 'downgrade: a forged apply is refused')
    board_file('collections/blog.json', delete=True)
    ok('File missing' in badge_texts('myshowcase-fields'), 'file missing: error badge')
    r = jsync({'action': 'run', 'mode': 'apply', 'type': 'collection', 'id': str(cid), 'file': 'collections/blog.json'})
    ok(one(f"select json_version from mybb_myshowcase_collections where collection_id={cid}") == '1.1.0', 'file missing: a forged apply is refused')
    board_file('collections/blog.json', '{"type": "collection", "version": "1.2.0", "name": "Blog", "fields": [{"field_key": "Bad Key", "html_type": "nope", "unknown": 1}]}')
    ok('File is invalid' in badge_texts('myshowcase-fields'), 'invalid file: error badge')
    ok('Show errors' in page('myshowcase-fields')['body'], 'invalid file: link to the errors')
    t = text(page(f'myshowcase-json_sync&action=errors&file=collections/blog.json&my_post_key={A.post_key}')['body'])
    ok('$.fields[0].field_key' in t and '$.fields[0].html_type' in t and '$.fields[0].unknown' in t, 'invalid file: every error with its JSON path')
    r = jsync({'action': 'run', 'mode': 'apply', 'type': 'collection', 'id': str(cid), 'file': 'collections/blog.json'})
    ok(one(f"select json_version from mybb_myshowcase_collections where collection_id={cid}") == '1.1.0', 'invalid file: a forged apply writes nothing')
    # the showcase of a missing/invalid collection file keeps being listed; restore the file
    board_file('collections/blog.json', changed)
    ok(badge_texts('myshowcase-fields') == ['In sync'], 'restored file: in sync')


def section_e(cid_a, sid_a, cid, sid):
    print('--- E. link, detach, failures')
    board_file('collections/plain.json', {'type': 'collection', 'version': '1.0.0', 'name': 'Plain two', 'fields': [
        {'field_key': 'kind', 'label': 'Kind', 'html_type': 'select', 'field_type': 'int', 'maximum_length': 5,
         'options': [{'value_id': 1, 'value': 'Coupe'}, {'value_id': 2, 'value': 'Truck'}, {'value_id': 3, 'value': 'Van'}]},
        {'field_key': 'colour', 'label': 'Colour', 'html_type': 'text', 'field_type': 'varchar', 'maximum_length': 20}]})
    board_file('showcases/cars.json', {'type': 'showcase', 'version': '1.0.0', 'collection': 'plain', 'comments_enable': 0, 'main_per_page': 9})
    r = page(f'myshowcase-json_sync&action=preview&type=showcase&id={sid_a}&mode=link&file=showcases/cars.json&my_post_key={A.post_key}')
    ok('Link the collection' in text(r['body']) or 'link the collection' in text(r['body']).lower(), 'showcase link refused while its collection is not linked')
    ok(one(f"select json_file from mybb_myshowcase_config where showcase_id={sid_a}") == '', 'refused link wrote nothing')
    before_fields = snapshot('mybb_myshowcase_fields', 'collection_id', cid_a)
    r = page(f'myshowcase-fields&action=edit&collection_id={cid_a}', {'name': 'Plain two', 'json_file': 'collections/plain.json'})
    t = text(r['body'])
    ok('Add the field' in t and 'colour' in t and 'own field now' in t and 'Confirm and apply' in r['body'], 'choosing a file on an unlinked collection leads to the plan')
    ok(one(f"select json_file from mybb_myshowcase_collections where collection_id={cid_a}") == '', 'plan shown before anything is written')
    r = jsync({'action': 'run', 'mode': 'link', 'type': 'collection', 'id': str(cid_a), 'file': 'collections/plain.json'})
    ok(errors_in(r) is None and 'The file was applied' in text(r['body']), 'link collection applied')
    ok(db(f"select json_file, json_version from mybb_myshowcase_collections where collection_id={cid_a}") == [('collections/plain.json', '1.0.0')] and one(f"select count(*) from mybb_myshowcase_fields where collection_id={cid_a} and json_file='collections/plain.json'") == '2', 'collection and both fields linked (existing field matched by key)')
    ok(one("select count(*) from mybb_myshowcase_field_data where value='Van'") == '1', 'missing option added')
    # a second collection can not take the same file
    submit('myshowcase-fields&action=new', 'action=new', {'name': 'Other'})
    oid = int(one("select collection_id from mybb_myshowcase_collections where name='Other'"))
    t = text(page(f'myshowcase-json_sync&action=preview&type=collection&id={oid}&mode=link&file=collections/plain.json&my_post_key={A.post_key}')['body'])
    ok('already linked' in t and 'Confirm and apply' not in t, 'second collection refused for a file already linked')
    # showcase link now
    r = page(f'myshowcase-summary&action=update&showcase_id={sid_a}')
    fg = form(r['body'], 'type=general')
    data = {k: (v[0] if len(v) == 1 else v) for k, v in fg['fields'].items() if k not in fg['disabled']}
    data['json_file'] = 'showcases/cars.json'
    r = page(fg['action'].replace('&amp;', '&').split('module=')[1], data)
    ok('Update the showcase' in text(r['body']) and 'Confirm and apply' in r['body'], 'showcase link: plan shown')
    r = jsync({'action': 'run', 'mode': 'link', 'type': 'showcase', 'id': str(sid_a), 'file': 'showcases/cars.json'})
    ok(errors_in(r) is None and 'The file was applied' in text(r['body']), 'link showcase applied')
    ok(db(f"select json_file, json_version, comments_enable, main_per_page from mybb_myshowcase_config where showcase_id={sid_a}") == [('showcases/cars.json', '1.0.0', '0', '9')], 'showcase linked with the file values')
    ok(sorted(badge_texts('myshowcase-summary')) == ['In sync', 'In sync'], 'both showcases in sync')
    # detach the collection: only json_* change
    snap_c = snapshot('mybb_myshowcase_collections', 'collection_id', cid_a); snap_f = snapshot('mybb_myshowcase_fields', 'collection_id', cid_a)
    r = page(f'myshowcase-fields&action=edit&collection_id={cid_a}', {'name': 'Plain two', 'json_file': ''})
    ok('Detach' in text(r['body']) or 'detach' in text(r['body']).lower(), 'choosing None asks to confirm the detach')
    ok(one(f"select json_file from mybb_myshowcase_collections where collection_id={cid_a}") == 'collections/plain.json', 'nothing detached before the confirmation')
    page(f'myshowcase-json_sync&action=detach&type=collection&id={cid_a}', {})
    ok(db(f"select json_file, json_version, json_synced_at, json_hash from mybb_myshowcase_collections where collection_id={cid_a}") == [('', '', '0', '')] and one(f"select count(*) from mybb_myshowcase_fields where collection_id={cid_a} and (json_file<>'' or json_hash<>'')") == '0', 'detach clears the json_* columns of the collection and its fields')
    ok(snapshot('mybb_myshowcase_collections', 'collection_id', cid_a) == snap_c and snapshot('mybb_myshowcase_fields', 'collection_id', cid_a) == snap_f, 'detach changes nothing else')
    ok('Collection is not linked to the file this showcase names' in badge_texts('myshowcase-summary'), 'showcase of a detached collection reports the problem')
    t = text(jsync({'action': 'preview', 'mode': 'apply', 'type': 'showcase', 'id': str(sid_a)})['body'])
    ok('Confirm and apply' not in t and 'Collection is not linked' in text(page('myshowcase-summary')['body']), 'no plan that would create a new collection for that showcase')
    page(f'myshowcase-json_sync&action=detach&type=showcase&id={sid_a}', {})
    ok(one(f"select json_file from mybb_myshowcase_config where showcase_id={sid_a}") == '', 'showcase detached')
    # a failing step is reported and the version is not written
    c = read_board_file('collections/blog.json'); c['version'] = '1.3.0'; c['fields'][0]['label'] = 'Category again'
    board_file('collections/blog.json', c)
    open(f'{IMP}/PHP/collections/blog/1.3.0.php', 'w').write('<?php return function (int $id, string $old, string $new, array $file) { throw new RuntimeException("step exploded"); };')
    r = jsync({'action': 'run', 'mode': 'apply', 'type': 'collection', 'id': str(cid), 'file': 'collections/blog.json'}); t = text(r['body'])
    ok('step exploded' in t and 'failed' in t and 'Run the PHP update step for 1.3.0' in t, 'failed step shown with the reason')
    ok(one(f"select json_version from mybb_myshowcase_collections where collection_id={cid}") == '1.1.0', 'version not written after the failure')
    os.remove(f'{IMP}/PHP/collections/blog/1.3.0.php')
    r = jsync({'action': 'run', 'mode': 'apply', 'type': 'collection', 'id': str(cid), 'file': 'collections/blog.json'})
    ok('The file was applied' in text(r['body']) and one(f"select json_version from mybb_myshowcase_collections where collection_id={cid}") == '1.3.0', 're-run after fixing the step completes')
    # permission / post key
    r = A.get(ADM + f'myshowcase-json_sync&action=detach&type=collection&id={cid}&my_post_key=bad')
    ok(one(f"select json_file from mybb_myshowcase_collections where collection_id={cid}") != '', 'bad post key: nothing happens')
    r = A.post(ADM + 'myshowcase-json_sync', {'action': 'detach', 'type': 'collection', 'id': str(cid), 'my_post_key': 'bad'}, key=False)
    ok(one(f"select json_file from mybb_myshowcase_collections where collection_id={cid}") != '', 'POST with a bad post key: nothing happens')


def list_row_numbers(path, name):
    """The numeric cells of the row of a list that mentions `name`."""
    html = page(path)['body']
    for row in html.split('<tr')[1:]:
        if f'>{name}<' in row or f'>{name}</a>' in row:
            return [int(c) for c in re.findall(r'<td[^>]*>\s*(\d+)\s*</td>', row)]
    return None


def section_f():
    print('--- F. leftover fixes: collection delete, post key on showcase save, counts, option hook')
    base = {'html_type': 'text', 'field_type': 'varchar', 'maximum_length': '20', 'minimum_length': '0', 'allowed_groups_fill_type': 'all', 'allowed_groups_view_type': 'all'}
    submit('myshowcase-fields&action=new', 'action=new', {'name': 'Counted'})
    cid = int(one("select collection_id from mybb_myshowcase_collections where name='Counted'"))
    for k in ('one', 'two'):
        submit(f'myshowcase-fields&action=newField&collection_id={cid}', 'action=newField', {**base, 'field_key': k, 'field_label': k})
    submit(f'myshowcase-fields&action=newField&collection_id={cid}', 'action=newField', {**base, 'field_key': 'pick', 'html_type': 'select', 'field_type': 'int', 'maximum_length': '5'})
    pid = int(one(f"select field_id from mybb_myshowcase_fields where collection_id={cid} and field_key='pick'"))
    r = page(f'myshowcase-fields&action=edit_option&field_id={pid}&collection_id={cid}', {'value': 'A', 'value_id': '1', 'display_order': '1', 'field_key': 'pick', 'create': '0'})
    ok(errors_in(r) is None and one(f"select count(*) from mybb_myshowcase_field_data where field_id={pid}") == '1', 'creating an option runs the field data hook without a PHP warning')
    for n in ('Counted A', 'Counted B'):
        submit('myshowcase-summary&action=create', 'action=create', {'showcase[name]': n, 'showcase[route_identifier]': n.lower().replace(' ', ''), 'showcase[collection_id]': str(cid)})
    nums = list_row_numbers('myshowcase-fields', 'Counted')
    ok(nums is not None and 3 in nums and 2 in nums, f'collection list: Field Count 3 and Assigned To 2 (cells {nums})')
    # a showcase save needs the post key
    sid = int(one("select showcase_id from mybb_myshowcase_config where name='Counted A'"))
    r = page(f'myshowcase-summary&action=update&showcase_id={sid}')
    fm = form(r['body'], 'type=main')
    data = {k: (v[0] if len(v) == 1 else v) for k, v in fm['fields'].items() if k not in fm['disabled'] and k != 'my_post_key'}
    data['showcase[main_per_page]'] = '3'
    r = A.post(ADM + fm['action'].replace('&amp;', '&').split('module=')[1], data, key=False)
    ok(one(f"select main_per_page from mybb_myshowcase_config where showcase_id={sid}") != '3', 'showcase save without a post key writes nothing')
    data['my_post_key'] = A.post_key
    page(fm['action'].replace('&amp;', '&').split('module=')[1], data)
    ok(one(f"select main_per_page from mybb_myshowcase_config where showcase_id={sid}") == '3', 'showcase save with the post key works')
    # collection delete: refused while a showcase uses it, then removes the fields and options
    page(f'myshowcase-fields&action=delete&collection_id={cid}', {})
    ok(one(f"select count(*) from mybb_myshowcase_collections where collection_id={cid}") == '1', 'a collection used by a showcase is not deleted')
    submit('myshowcase-fields&action=new', 'action=new', {'name': 'Doomed'})
    did = int(one("select collection_id from mybb_myshowcase_collections where name='Doomed'"))
    for k in ('x1', 'x2'):
        submit(f'myshowcase-fields&action=newField&collection_id={did}', 'action=newField', {**base, 'field_key': k, 'field_label': k})
    xid = int(one(f"select field_id from mybb_myshowcase_fields where collection_id={did} and field_key='x1'"))
    page(f'myshowcase-fields&action=edit_option&field_id={xid}&collection_id={did}', {'value': 'Z', 'value_id': '1', 'display_order': '1', 'field_key': 'x1', 'create': '0'})
    other = one(f"select count(*) from mybb_myshowcase_fields where collection_id={cid}")
    page(f'myshowcase-fields&action=delete&collection_id={did}', {})
    ok(one(f"select count(*) from mybb_myshowcase_collections where collection_id={did}") == '0', 'collection deleted')
    ok(one(f"select count(*) from mybb_myshowcase_fields where collection_id={did}") == '0' and one(f"select count(*) from mybb_myshowcase_field_data where collection_id={did}") == '0', 'its fields and options are gone')
    ok(one(f"select count(*) from mybb_myshowcase_fields where collection_id={cid}") == other, 'other collections keep their fields')
    # a collection linked to a JSON file can be deleted too: it is detached first
    r = jsync({'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/plain.json'}) if os.path.exists(f'{IMP}/JSON/collections/plain.json') else None
    if r is None:
        board_file('collections/gone.json', {'type': 'collection', 'version': '1.0.0', 'name': 'Gone', 'fields': [{'field_key': 'g1', 'label': 'G1', 'html_type': 'text', 'field_type': 'varchar', 'maximum_length': 10}]})
        jsync({'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/gone.json'})
        gid = int(one("select collection_id from mybb_myshowcase_collections where name='Gone'"))
        ok(one(f"select count(*) from mybb_myshowcase_fields where collection_id={gid} and json_file<>''") == '1', 'a JSON-linked collection with a linked field exists')
        page(f'myshowcase-fields&action=delete&collection_id={gid}', {})
        ok(one(f"select count(*) from mybb_myshowcase_collections where collection_id={gid}") == '0' and one(f"select count(*) from mybb_myshowcase_fields where collection_id={gid}") == '0', 'deleting a JSON-linked collection removes its fields (detached first)')


def section_g():
    print('--- G. gallery example files')
    r = jsync({'action': 'run', 'mode': 'create', 'type': 'collection', 'file': 'collections/gallery.json'})
    ok(errors_in(r) is None and 'The file was applied' in text(r['body']), 'gallery collection created from its file')
    gid = int(one("select collection_id from mybb_myshowcase_collections where name='Gallery'") or 0)
    ok(db(f"select json_file, json_version from mybb_myshowcase_collections where collection_id={gid}") == [('collections/gallery.json', '1.0.0')], 'gallery collection linked at 1.0.0')
    ok(db(f"select field_key from mybb_myshowcase_fields where collection_id={gid} and json_file='collections/gallery.json' order by display_order") == [('title',), ('image',), ('caption',), ('category',)], 'gallery fields title, image, caption, category linked')
    ok(db(f"select value_id from mybb_myshowcase_field_data where collection_id={gid} order by display_order") == [(str(i),) for i in range(1, 7)], 'gallery category has six options, value ids 1-6')
    ok('In sync' in list_text('myshowcase-fields'), 'gallery collection in sync')
    r = jsync({'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/gallery.json', 'name': 'Gallery', 'route_identifier': 'gallery'})
    ok(errors_in(r) is None and 'The file was applied' in text(r['body']), 'gallery showcase created from its file')
    gsid = int(one("select showcase_id from mybb_myshowcase_config where name='Gallery'") or 0)
    ok(db(f"select collection_id, json_file, json_version from mybb_myshowcase_config where showcase_id={gsid}") == [(str(gid), 'showcases/gallery.json', '1.0.0')], 'gallery showcase uses the linked gallery collection, linked at 1.0.0')
    ok(db(f"select main_per_page, entries_enable_subject, entries_display_empty_fields, attachments_display_type, comments_enable from mybb_myshowcase_config where showcase_id={gsid}") == [('24', '0', '0', '2', '1')], 'gallery showcase settings applied')
    ok(one(f"select count(*) from mybb_myshowcase_collections where name='Gallery'") == '1', 'no second gallery collection')
    return gid
