#!/usr/bin/env python3
"""Coverage of the content seeders (decisions D40 and D43). Needs no board: it reads the shipped collection files and the six
seeds in this folder, and fails when:

* a field of a shipped collection is absent or blank in every seed of its showcase (the full seed holds every field);
* a seed names a key the collection does not define;
* a required field is blank in a seed;
* a value is outside its field's bounds (a number, a length) or names no option of its field;
* a value is populated for a field type that cannot hold it (a list for a single-valued field, options for a text field,
  a non-URL for a file or URL field, a file type the field does not take);
* a seed holds no entry, or an entry has no subject.

The negative control is part of the run: a copy of the gallery seed with one field removed must make the same checks fail,
and the run fails when they do not.

    python3 tests/fixtures/seeds/coverage_test.py

Exit code 0 only when every seed passes and the negative control is detected.
"""
import json
import os
import re
import sys
import xml.etree.ElementTree as ET

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
COLLECTIONS = os.path.join(REPO, 'Upload', 'inc', 'plugins', 'myshowcase', 'imports', 'JSON', 'collections')

SEEDS = {
    'blog': ['blog-full', 'blog-minimal'],
    'downloads': ['downloads-full', 'downloads-minimal'],
    'gallery': ['gallery-full', 'gallery-minimal'],
}
FULL = {'blog': 'blog-full', 'downloads': 'downloads-full', 'gallery': 'gallery-full'}
URL_TYPES = ('url',)
OPTION_TYPES = ('select', 'radio', 'checkbox')
TEXT_TYPES = ('text', 'textarea', 'url', 'email', 'search', 'tel', 'password')
NUMBER_TYPES = ('number', 'range')
DATE_TYPES = ('date', 'datetime-local', 'time', 'month', 'week', 'color')
MULTI_OK = ('checkbox', 'select', 'file')

PROBLEMS = []
PASSES = 0


def report(ok, message):
    global PASSES
    print(('PASS ' if ok else 'FAIL ') + message)
    if ok:
        PASSES += 1
    else:
        PROBLEMS.append(message)


def seed_value(element):
    """A value of a seed file as the import reads it: an array whose keys are all INT is a list, another array a dict."""
    array = element.find('array')
    if array is not None:
        children = [(c.find('key'), c.find('value')) for c in array.findall('element')]
        if children and all(k is not None and k.get('type') == 'INT' for k, _ in children):
            return [seed_value(v) for _, v in children]
        return {k.text: seed_value(v) for k, v in children}
    text = element.text or ''
    return int(text) if element.get('type') == 'INT' else text


def read_seed(name):
    """The entries of a seed file: the subject, the message and the fields by key."""
    root = ET.parse(os.path.join(HERE, name + '.xml')).getroot()
    entries_value = [e.find('value') for e in root.findall('element') if e.find('key').text == 'entries'][0]
    entries = []
    for item in entries_value.find('array').findall('element'):
        parts = {p.find('key').text: p.find('value') for p in item.find('value').find('array').findall('element')}
        entries.append({'subject': seed_value(parts['subject']) if 'subject' in parts else None,
                        'fields': seed_value(parts['fields']) if 'fields' in parts else {}})
    return entries


def read_collection(route):
    data = json.load(open(os.path.join(COLLECTIONS, route + '.json'), encoding='utf-8'))
    return {f['field_key']: f for f in data['fields']}


def blank(value):
    return value is None or value == '' or value == [] or (isinstance(value, list) and all(v == '' for v in value))


def check_entries(route, name, entries, fields):
    """The problems of the entries of one seed against the fields of its collection (empty when none)."""
    problems = []
    if not entries:
        return [f'{name}: no entry']
    for index, entry in enumerate(entries):
        label = f'{name} entry {index + 1}'
        if blank(entry['subject']):
            problems.append(f'{label}: no subject')
        given = entry['fields']
        for key in given:
            if key not in fields:
                problems.append(f'{label}: {key} is not a field of the {route} collection')
        for key, definition in fields.items():
            value = given.get(key, '')
            if definition.get('is_required') and blank(value):
                problems.append(f'{label}: required {key} is blank')
            if blank(value):
                continue
            problems += check_value(label, key, definition, value)
    return problems


def check_value(label, key, definition, value):
    html = definition['html_type']
    problems = []
    values = value if isinstance(value, list) else [value]
    if isinstance(value, list) and not (html in MULTI_OK and (html != 'select' or definition.get('allow_multiple_values'))):
        problems.append(f'{label}: {key} is a list, but a {html} field takes one value')
    if isinstance(value, list) and html == 'file' and not definition.get('allow_multiple_values'):
        problems.append(f'{label}: {key} is a list, but the file field takes one file')
    if html in OPTION_TYPES:
        option_ids = {str(o['value_id']) for o in definition.get('options', [])}
        for item in values:
            if str(item) not in option_ids:
                problems.append(f'{label}: {key} names the option {item!r}, which the field does not have')
        if html == 'checkbox' and not isinstance(value, list):
            problems.append(f'{label}: {key} is a checkbox field and takes a list of options')
    elif html in NUMBER_TYPES:
        for item in values:
            if not re.fullmatch(r'-?\d+', str(item)):
                problems.append(f'{label}: {key} is not a whole number: {item!r}')
                continue
            number = int(item)
            if definition.get('minimum_value') not in (None, '') and number < int(definition['minimum_value']):
                problems.append(f'{label}: {key} = {number} is below the minimum {definition["minimum_value"]}')
            if definition.get('maximum_value') not in (None, '') and number > int(definition['maximum_value']):
                problems.append(f'{label}: {key} = {number} is above the maximum {definition["maximum_value"]}')
    elif html == 'file':
        for item in values:
            url = str(item)
            if not re.match(r'^https?://', url, re.I):
                problems.append(f'{label}: {key} is not an http(s) URL: {url!r}')
            # the file's type is decided by its name and content on import: a name in the URL is checked when it has an extension
            segment = url.split('?', 1)[0].rsplit('/', 1)[-1]
            extension = segment.rsplit('.', 1)[-1].lower() if '.' in segment else ''
            allowed = [e.strip() for e in definition.get('file_types', '').split(',') if e.strip()]
            if extension and allowed and extension not in allowed:
                problems.append(f'{label}: {key} names a {extension!r} file, the field takes {allowed}')
    elif html in URL_TYPES or html in TEXT_TYPES:
        for item in values:
            text = str(item)
            if html == 'url' and not re.match(r'^https?://', text, re.I):
                problems.append(f'{label}: {key} is not an http(s) URL: {text!r}')
            if definition.get('maximum_length') not in (None, '') and len(text) > int(definition['maximum_length']):
                problems.append(f'{label}: {key} is {len(text)} characters, the maximum is {definition["maximum_length"]}')
            if definition.get('minimum_length') not in (None, '') and len(text) < int(definition['minimum_length']):
                problems.append(f'{label}: {key} is {len(text)} characters, the minimum is {definition["minimum_length"]}')
    elif html in DATE_TYPES:
        pass  # the date fields of the shipped collections are the release date (length 10, checked below)
        for item in values:
            if definition.get('minimum_length') not in (None, '') and len(str(item)) != int(definition['minimum_length']):
                problems.append(f'{label}: {key} is {len(str(item))} characters, the field takes {definition["minimum_length"]}')
            if html == 'date' and not re.fullmatch(r'\d{4}-\d{2}-\d{2}', str(item)):
                problems.append(f'{label}: {key} is not a date (YYYY-MM-DD): {item!r}')
    else:
        problems.append(f'{label}: {key} has an html type this check does not know: {html}')
    return problems


def coverage(route, entries_by_seed, fields):
    """Every field of the collection is populated in at least one seed of its showcase (the full seed holds every field)."""
    problems = []
    for key in fields:
        populated = any(not blank(e['fields'].get(key, '')) for entries in entries_by_seed.values() for e in entries)
        if not populated:
            problems.append(f'{route}: {key} is blank in every seed')
    return problems


def main():
    all_problems = []
    loaded = {}
    for route, names in SEEDS.items():
        fields = read_collection(route)
        loaded[route] = {name: read_seed(name) for name in names}
        report(len(fields) == {'blog': 9, 'downloads': 11, 'gallery': 4}[route],
               f'the {route} collection has {len(fields)} fields (the shipped count: 9, 11 and 4 by decision D43)')
        for name in names:
            problems = check_entries(route, name, loaded[route][name], fields)
            report(not problems, f'{name}: every entry is inside its fields, bounds and options' +
                   (f'; {problems[:3]}' if problems else ''))
            all_problems += problems
        cover = coverage(route, loaded[route], fields)
        report(not cover, f'{route}: every field is populated in at least one seed of the showcase' + (f'; {cover}' if cover else ''))
        all_problems += cover

    # the negative control: a copy of the gallery seed with one field removed must make the checks fail
    fields = read_collection('gallery')
    copy = [dict(e, fields={k: v for k, v in e['fields'].items() if k != 'caption'}) for e in loaded['gallery']['gallery-full']]
    detected = bool(coverage('gallery', {'copy': copy}, fields))
    report(detected, 'negative control: a gallery seed without its caption is refused by the coverage check')
    cross = coverage('gallery', {'copy': [dict(e, fields={}) for e in loaded['gallery']['gallery-full']]}, fields)
    report(bool(cross), 'negative control: a gallery seed with no fields is refused by the coverage check')
    unknown = check_entries('gallery', 'control', [dict(loaded['gallery']['gallery-full'][0], fields={'nosuchkey': 'x'})], fields)
    report(any('nosuchkey' in p for p in unknown), 'negative control: a key the collection does not define is refused')
    bound = check_entries('gallery', 'control', [dict(loaded['gallery']['gallery-full'][0],
                                                       fields={'title': 'x' * 101, 'image': 'https://example.org/a.png'})], fields)
    report(any('title' in p and 'maximum' in p for p in bound), 'negative control: a text above its maximum length is refused')
    option = check_value('control', 'category', fields['category'], '99')
    report(bool(option), 'negative control: an option that the field does not have is refused')

    print(f'\n{PASSES} passed, {len(PROBLEMS)} failed')
    return 0 if not PROBLEMS else 1


if __name__ == '__main__':
    sys.exit(main())
