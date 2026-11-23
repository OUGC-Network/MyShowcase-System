# Header notices: a template edit from the root README (moved)

This snippet was in the root `README` of the repository. It adds the two global moderation notices (unapproved entries and
reported entries) to the forum header template, after the private message notice. The plugin does not make this edit itself.

```php
include MYBB_ROOT . '/inc/adminfunctions_templates.php';

find_replace_templatesets('header', '#' . preg_quote('{$myShowcaseGlobalMessagesUnapprovedEntries}') . '#', '');

find_replace_templatesets('header', '#' . preg_quote('{$myShowcaseGlobalMessagesReportedEntries}') . '#', '');
require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';

find_replace_templatesets(
    'header',
    '#' . preg_quote('{$pm_notice}') . '#',
    '{$pm_notice}{$myShowcaseGlobalMessagesUnapprovedEntries}{$myShowcaseGlobalMessagesReportedEntries}'
);
```

**Status: not useful as it is.** The first two `find_replace_templatesets()` calls remove the placeholders, and the third
puts them back after `{$pm_notice}`. The plugin's header hook (`Upload/inc/plugins/myshowcase/forum_hooks.php`, lines 58 to 60)
sets both variables to an empty string, so the notices are always empty. The snippet changes the header template and has no
visible effect. Keep it only if the notices come back; in that case the hook has to fill the variables first.
