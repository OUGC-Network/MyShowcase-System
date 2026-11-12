"""Builds a board that shows every status at once and takes screenshots of the JSON sync pages with headless Chromium.

Needs Playwright (pip install playwright; playwright install chromium). Run through run.py --screenshots DIR.
"""
import json, os, re

import flows
from flows import jsync, page, board_file, read_board_file, submit, one, db


def prepare():
    """The blog, plus rows in every status."""
    base_c = read_board_file('collections/blog.json')
    base_s = read_board_file('showcases/blog.json')
    states = ['sync', 'update', 'unver', 'down', 'missing', 'invalid']
    for st in states:
        c = {'type': 'collection', 'version': '1.0.0', 'name': f'Col {st}', 'fields': [
            {'field_key': 'title_' + st, 'label': 'Title', 'html_type': 'text', 'field_type': 'varchar', 'maximum_length': 40, 'is_enabled': 1}]}
        board_file(f'collections/col_{st}.json', c)
        s = {**base_s, 'collection': 'blog', 'main_per_page': 6}
        board_file(f'showcases/sc_{st}.json', s)
    jsync({'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': 'showcases/blog.json', 'name': 'Blog', 'route_identifier': 'blog'})
    for n, st in enumerate(states, 1):
        jsync({'action': 'run', 'mode': 'create', 'type': 'collection', 'file': f'collections/col_{st}.json'})
        jsync({'action': 'run', 'mode': 'create', 'type': 'showcase', 'file': f'showcases/sc_{st}.json', 'name': f'Showcase {st}', 'route_identifier': f'sc{st}'})
    # a field of the board's own in the blog collection
    cid = int(one("select collection_id from mybb_myshowcase_collections where name='Blog'"))
    submit(f'myshowcase-fields&action=newField&collection_id={cid}', 'action=newField', {
        'field_key': 'internal_note', 'field_label': 'Internal note', 'html_type': 'text', 'field_type': 'varchar', 'maximum_length': '40',
        'minimum_length': '0', 'allowed_groups_fill_type': 'all', 'allowed_groups_view_type': 'all'})
    # now put every file in its state
    def edit(rel, fn):
        d = read_board_file(rel); fn(d); board_file(rel, d)
    edit('collections/col_update.json', lambda d: d.update(version='1.1.0'))
    edit('collections/col_unver.json', lambda d: d['fields'][0].update(label='Title changed'))
    edit('collections/col_down.json', lambda d: d.update(version='0.9.0'))
    board_file('collections/col_missing.json', delete=True)
    board_file('collections/col_invalid.json', '{"type": "collection", "version": "1.0.0", "name": "Col invalid", "fields": [{"field_key": "Bad Key"}]}')
    edit('showcases/sc_update.json', lambda d: d.update(version='1.1.0', main_per_page=8))
    edit('showcases/sc_unver.json', lambda d: d.update(main_per_page=9))
    edit('showcases/sc_down.json', lambda d: d.update(version='0.9.0'))
    board_file('showcases/sc_missing.json', delete=True)
    board_file('showcases/sc_invalid.json', '{"type": "showcase", "version": "1.0.0", "collection": "blog", "main_per_page": "ten"}')
    # blog collection: update with an added field, a removed field and a PHP step that fails
    def blog(d):
        d['version'] = '1.1.0'
        d['fields'].append({'field_key': 'author_note', 'label': 'Author note', 'html_type': 'text', 'field_type': 'varchar', 'maximum_length': 60, 'is_enabled': 1})
        d['fields'] = [f for f in d['fields'] if f['field_key'] != 'cover_image']
    edit('collections/blog.json', blog)
    os.makedirs(f'{flows.IMP}/PHP/collections/blog', exist_ok=True)
    open(f'{flows.IMP}/PHP/collections/blog/1.1.0.php', 'w').write('<?php return function (int $id, string $old, string $new, array $file) { throw new RuntimeException("Could not convert the old author notes"); };')
    return cid


def shoot(ctx, outdir):
    from playwright.sync_api import sync_playwright
    os.makedirs(outdir, exist_ok=True)
    cid = prepare()
    sid = int(one("select showcase_id from mybb_myshowcase_config where name='Blog'"))
    fid = int(one(f"select field_id from mybb_myshowcase_fields where collection_id={cid} and field_key='category'"))
    paths = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        pg = browser.new_context(viewport={'width': 1400, 'height': 900}).new_page()
        pg.goto(ctx.url + '/admin/index.php')
        pg.fill('input[name=username]', ctx.user); pg.fill('input[name=password]', ctx.password)
        pg.click('input[type=submit]'); pg.wait_for_load_state()

        def snap(name, url, scroll_to=None):
            # navigate from inside the page: MyBB refuses a post key sent with Sec-Fetch-Site: none (a typed URL)
            with pg.expect_navigation():
                pg.evaluate('u => { window.location.href = u }', ctx.url + '/admin/index.php?' + url)
            pg.wait_for_load_state()
            f = os.path.join(outdir, f'{ctx.engine}_{name}.png')
            pg.screenshot(path=f, full_page=True); paths.append(f)

        snap('01_showcase_list', 'module=myshowcase-summary')
        snap('02_collection_list', 'module=myshowcase-fields')
        snap('03_field_list', f'module=myshowcase-fields&action=viewFields&collection_id={cid}')
        snap('04_showcase_edit_main', f'module=myshowcase-summary&action=update&showcase_id={sid}')
        snap('05_field_edit', f'module=myshowcase-fields&action=edit_field&field_id={fid}&collection_id={cid}')
        snap('06_collection_edit', f'module=myshowcase-fields&action=edit&collection_id={cid}')
        key = re.search(r'name="my_post_key" value="([0-9a-f]{32})"', pg.content()).group(1)  # the post key of the browser's own session
        snap('07_preview', f'module=myshowcase-json_sync&action=preview&mode=apply&type=collection&id={cid}&my_post_key=' + key)
        # confirm in the browser: the result page with the failed step
        pg.click('input[type=submit][value*=onfirm]'); pg.wait_for_load_state()
        f = os.path.join(outdir, f'{ctx.engine}_08_result_failed.png'); pg.screenshot(path=f, full_page=True); paths.append(f)
        snap('09_errors', 'module=myshowcase-json_sync&action=errors&file=collections/col_invalid.json&my_post_key=' + key)
        browser.close()
    return paths
