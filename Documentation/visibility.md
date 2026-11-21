# Visibility and moderation

Who may see which entries and comments of a showcase, and who counts as a moderator. The rules are the same for the
listing, the entry page, the attachments, comments, the inline moderation menu and the export. Each rule is decided in
one place in PHP and mirrored by one SQL condition, and the two must agree.

## The moderator notion

Moderation is per area (decision P1). `EntriesManager::isModerator(area)` is the one moderator test; it takes `'entries'`
(`EntriesManager::MODERATOR_AREA_ENTRIES`) or `'comments'` (`EntriesManager::MODERATOR_AREA_COMMENTS`). The entry and comment
edit, delete and moderation controls each ask the area they moderate, so a user may moderate the entries and not the comments,
or the reverse.

A user moderates an area of a showcase when either:

* one of their usergroups, primary or additional, has the MyBB super-moderator or administrator flag (`issupermod` or
  `cancp`): such a user moderates both areas. MyBB merges the additional groups into `$mybb->usergroup`, which the test reads, or
* a `myshowcase_moderators` row for the showcase matches them and has that area's flag on. `group_id` is the type of the row: 0
  matches the user with the uid in `user_id`; 1 matches any of the user's usergroups, with the usergroup id in `user_id`
  (`ModeratorsRepository::matchesUser()`). The flag of the entries area is `entries_can_view`; the flag of the comments area is
  `can_manage_comments`.

The answer is memoised per area and user for the request. A new moderator row starts with both flags on. The attachments,
reports and logs flags of the earlier design are not in the table definition any more (a board's old columns stay and are not
read), and the ACP moderators tab shows only the two flags.

## Entries

An entry has one of three statuses (`ContentStatuses`): awaiting approval, approved, or soft-deleted.

| Status | Permission the viewer needs | Non-moderator with the permission | Moderator with the permission |
|---|---|---|---|
| Approved | none beyond the showcase view rights | every approved entry | every approved entry |
| Awaiting approval | `entries_can_view_unapproved` | own entries only | any user's entries |
| Soft-deleted | `entries_can_view_deleted` | own entries only | any user's entries |

A guest, or a member without the permission for the status, sees no entry of that status, not even their own.

The rule is `EntriesManager::statusViewScope()` (returns `any`, `own` or `none`) and `canViewStatus($status, $owner_id)`
built on it. `visibilityWhereClause()` is the SQL form. The entry page, attachments, the listing, the search and the
export all use these.

The moderation permissions (`entries_can_approve`, `entries_can_open`, and the others) decide which actions a user may
run, not which entries they may see. `BaseController::mayModerateEntry()` checks an action: the permission, and either
the entry is the user's own or the user is a moderator (`isModerator()`).

The list's inline moderation runs the action named in its form (`EntriesController::INLINE_MODERATION_PERMISSIONS`). Its delete
needs `entries_can_delete` and the same moderator rule. A request that matches no route shows the page-not-found error; any other
failure shows its cause in the error sentence and in the error log. A page never shows an empty message (decisions D144 and D146).
The sentence comes from the plugin's language, then the global language, then the English text.

## Comments

Comments follow the entry rule with their own permissions. The rule is `EntriesManager::commentStatusViewScope()` and
`canViewCommentStatus($status, $owner_id)`; the SQL form is `EntriesManager::commentVisibilityWhereClause()`.

| Viewer | Approved | Awaiting approval | Soft-deleted |
|---|---|---|---|
| Guest, or a member without `comments_can_view` | hidden | hidden | hidden |
| Member with `comments_can_view`, not a moderator | visible | own only, and only with `comments_can_view_unapproved` | own only, and only with `comments_can_view_deleted` |
| Moderator with `comments_can_view` | visible | any user's, and only with `comments_can_view_unapproved` | any user's, and only with `comments_can_view_deleted` |

`comments_can_view` is also required before a comment is loaded at all (`BaseController::verifyPermission()`). The
deletion notices (`comments_can_view_deletion_notices`) are not changed by this rule.

`comments_can_view_unapproved` and `comments_can_view_deleted` do not reveal anything for a viewer who does not have
`comments_can_view`.

## Keeping the SQL and the PHP the same

Each status has one scope, computed once for the current user, and the PHP check and the SQL condition are both built
from it:

* `canViewStatus()` / `canViewCommentStatus()` answer for one entry or comment, given its status and owner;
* `visibilityWhereClause()` / `commentVisibilityWhereClause()` answer for a query, with one clause per status.

A status that the user may not see contributes nothing to the clause. When no status is visible, the comment clause is
`1=0`. A new reader or query must use one of these pairs, not its own condition.

## Where the rules are applied

* Listing, search, entry page and export: `visibilityWhereClause()` (entries), `commentVisibilityClause()` (comments on
  the entry page and the redirect to a comment's position).
* Attachments: a file of an entry the user may not see is refused with the same 404 as an unknown attachment, whoever
  uploaded it.
* Inline moderation menu: the moderators see it; a guest and a member without a moderation permission do not. Each batch
  action is checked with `mayModerateEntry()` for every selected entry before anything changes, so one refused entry
  refuses the request. `EntriesManager::moderationTools()` and `canModerateEntry()` have no callers yet and are not part
  of this rule.
* Export: the export holds what the listing shows the user. The export page's moderator flag (`is_moderator`) is
  `isModerator()`.
