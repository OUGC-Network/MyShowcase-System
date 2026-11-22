#!/usr/bin/env python3
"""No code of the plugin converts an older board's data any more (LEG, decision D310, D1).

The activation and the uninstall of the plugin (admin.php) and the schema code (SchemaManager.php) carried a block that renamed
the columns and tables of old board versions, and the field-set to collection migration. None of these runs on a board that is
installed by this version, so they are removed. The static check fails when a removed marker, legacy column name or function is
still in one of the files. The fresh-install code that serves a new board (the table and column definitions in Plugin/Core.php)
stays and is not listed here.

    python3 tests/status/legacy.py

Exit code 1 if a check fails. No database: the check is static and runs once.
"""
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
PLUGIN = os.path.join(REPO, 'Upload', 'inc', 'plugins', 'myshowcase')

FILES = [
    os.path.join(PLUGIN, 'admin.php'),
    os.path.join(PLUGIN, 'Plugin', 'Core.php'),
    os.path.join(PLUGIN, 'src', 'Service', 'SchemaManager.php'),
]

# (marker, why it is removed)
REMOVED = [
    ('remove this legacy updating', 'the todo of the legacy update of the format column (Plugin/Core.php)'),
    ('drop this redundant field', 'the todo of the redundant value_id column (Plugin/Core.php)'),
    ('drop thumb_width', 'the todo of the thumb_width, thumb_height and maximum_text_field_length columns (admin.php)'),
    ('RUN UPDATES START', 'the activation block that renames the columns of old boards'),
    ("'othermaxlength'", 'a legacy column name of an old board (admin.php)'),
    ("'maximum_text_field_lenght'", 'a legacy column name of an old board (admin.php)'),
    ("'f2gpath'", 'a legacy column name of an old board (admin.php)'),
    ("'candelauthcomment'", 'a legacy column name of an old board (admin.php)'),
    ("'requiredField'", 'a legacy column name of an old board (admin.php)'),
    ('migrateCollections', 'the field-set to collection migration of old boards'),
    ('carryOverColumn', 'the helper of the field-set migration'),
    ('myshowcase_fieldsets', 'the table of the field-set era (migration and uninstall cleanup)'),
    ('renameTableSql', 'the table rename of the field-set migration'),
    ('collections_both_tables_have_rows', 'the warning of the field-set migration'),
    ('legacy_column_not_dropped', 'the warning of the legacy column drop'),
]


def main():
    failures, checks = [], 0
    for path in FILES:
        text = open(path, encoding='utf-8').read()
        rel = os.path.relpath(path, REPO)
        for marker, why in REMOVED:
            checks += 1
            if marker in text:
                failures.append(f'{rel} still has {marker!r} ({why})')
    for f in failures:
        print('FAIL', f)
    print(f'\n{checks - len(failures)} passed, {len(failures)} failed (legacy, {checks} checks)')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
