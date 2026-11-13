# Visibility and moderation

Who may see which entries and comments of a showcase, and who counts as a moderator. The rules are the same for the
listing, the entry page, the attachments, comments, the inline moderation menu and the export. Each rule is decided in
one place in PHP and mirrored by one SQL condition, and the two must agree.

## The moderator notion

`EntriesManager::isModerator()` is the only moderator test. Entries and comments use the same answer.

A user is a moderator of a showcase when either:

* their usergroup has the MyBB super-moderator or administrator flag (`issupermod` or `cancp`), or
* a `myshowcase_moderators` row for the showcase matches them. `group_id` is the type of the row: 0 matches the user with
  the uid in `user_id`; 1 matches any of the user's usergroups, with the usergroup id in `user_id`
  (`ModeratorsRepository::matchesUser()`).

The result is memoised per user for the request. The flags stored in a moderators row (`entries_can_view`,
`can_manage_*`) and the ACP moderator permissions are stored but are not read by this test yet.

`EntriesManager::moderatesShowcase()` is **not** a moderator test. It is true for every member with `EntriesCanView`,
which is on by default. It must not be used to decide moderation.

## Entries

An entry has one of three statuses (`ContentStatuses`): awaiting approval, approved, or soft-deleted.

| Status | Permission the viewer needs | Non-moderator with the permission | Moderator with the permission |
|---|---|---|---|
| Approved | none beyond the showcase view rights | every approved entry | every approved entry |
| Awaiting approval | `entries_can_view_unapproved` | own entries only | any user's entries |
| Soft-deleted | `entries_can_view_deleted` | own entries only | any user's entries |

A guest, or a member without the permission for the status, sees no entry of that status, not even their own.

The rule is `EntriesManager::statusViewScope()` (returns `any`, `own` or `none`) and `canViewStatus($status, $owner_id)`
built on it. `visibilityWhereClause()` is the SQL form. The entry page, attachments, the listing, the search, the
moderation actions (`canModerateEntry()`) and the export all use these.

The moderation permissions (`entries_can_approve`, `entries_can_open`, and the others) decide which actions a user may
run, not which entries they may see. An action on someone else's entry also needs `isModerator()`.

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
* Inline moderation menu: shown only for the tools in `moderationTools()` that the user holds, and each tool is checked
  again with `canModerateEntry()` before it runs.
* Export: the export holds what the listing shows the user. The export page's moderator flag (`is_moderator`) is
  `isModerator()`.
