<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use captcha;
use DateTime;
use Exception;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Enum\FilterTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Fields\FieldFactory;
use MyShowcase\Fields\FieldsInterface;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Repository\ModeratorsRepository;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\StringUtil;
use MyShowcase\Util\UuidUtil;
use MyShowcase\Util\ValidationHelpers;
use MyShowcase\ValueObjects\EntryCustomField;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\stringKeysOnly;
use function MyShowcase\Plugin\Functions\castTableFieldValue;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\hooksRun;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

class EntriesManager
{
    public const VALIDATION_METHOD_INSERT = 'insert';
    public const VALIDATION_METHOD_UPDATE = 'update';
    private const POST_ICON_NONE = 0;
    public const STATUS_CLOSED = 0;
    public const STATUS_OPEN = 1;
    public const STATUS_NORMAL = 0;
    public const STATUS_STICKY = 1;
    public const SIGNATURE_DISABLED = 0;
    public const SIGNATURE_ALLOW = 1;
    public const SMILIES_DISABLED = 0;
    public const SMILIES_ALLOW = 1;
    public const FIRST_PAGE = 1;

    /**
     * The inline moderation tools and the permission each one needs (the existing permission definitions; none is new).
     * Every tool goes through canModerateEntry(), so none can skip it.
     */
    public const MODERATION_TOOLS = [
        'open' => UserPermissions::EntriesCanOpen,
        'close' => UserPermissions::EntriesCanClose,
        'stick' => UserPermissions::EntriesCanSticky,
        'unstick' => UserPermissions::EntriesCanUnstick,
        'approve' => UserPermissions::EntriesCanApprove,
        'unapprove' => UserPermissions::EntriesCanUnapprove,
        'copy' => UserPermissions::EntriesCanCopy,
        'soft_delete' => UserPermissions::EntriesCanSoftDelete,
        'restore' => UserPermissions::EntriesCanRestore,
        'delete' => UserPermissions::EntriesCanDelete,
    ];

    /**
     * The permission that lets a user see entries of other users in a status that is not public. Awaiting-approval
     * entries need EntriesCanViewUnapproved, soft-deleted entries need EntriesCanViewDeleted.
     */
    private const STATUS_VIEW_PERMISSIONS = [
        ContentStatuses::AwaitingApproval => UserPermissions::EntriesCanViewUnapproved,
        ContentStatuses::SoftDeleted => UserPermissions::EntriesCanViewDeleted,
    ];

    /** The comment counterpart of STATUS_VIEW_PERMISSIONS (see commentStatusViewScope()). */
    private const COMMENT_STATUS_VIEW_PERMISSIONS = [
        ContentStatuses::AwaitingApproval => UserPermissions::CommentsCanViewUnapproved,
        ContentStatuses::SoftDeleted => UserPermissions::CommentsCanViewDeleted,
    ];

    private Collection $entries;
    private readonly EntriesRepository $entries_repository;
    private readonly AttachmentsRepository $attachments_repository;
    public int $total_entries = 0;
    private array $where_clauses = [];
    private array $query_tables = [];
    private array $query_fields = [];
    private array $validation_errors = [];
    private EntryEntity $validation_entry;
    private bool $validation_data_is_valid = true;
    private string $validation_method = self::VALIDATION_METHOD_INSERT;
    private array $removed_attachment_ids = [];

    /**
     * The number of files per field that an entries import has checked and will attach to the entry once it is saved,
     * keyed by field id. Empty except during an import (section 5.9).
     *
     * @var array<int, int>
     */
    public array $pending_file_counts = [];
    private ?array $stored_entry_row = null;

    /** @var array<int, bool> Moderator answers per user id, see isModerator(). */
    private array $is_moderator_cache = [];

    public function __construct(
        public readonly ShowcaseContext $context,
    ) {
        $this->entries = new Collection();
    }

    /**
     * @return Collection<EntryEntity>
     */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    /**
     * Deletes an entry of this showcase and, through the entry-deleted event, everything that belongs to it. Every
     * path that deletes entries goes through here.
     */
    public function deleteEntry(int $entry_id): bool
    {
        $entry_data = $this->context->entries_repository->queryEntry(
            ["entry_id='{$entry_id}'"],
            ['entry_id', 'user_id']
        );

        if (empty($entry_data)) {
            return false;
        }

        $this->context->entries_repository->deleteEntry($entry_id);

        $this->context->event_dispatcher->dispatch(
            new EntryDeletedEvent($this->context->showcase->showcase_id, $entry_id, (int)$entry_data['user_id']),
            EntryDeletedEvent::NAME
        );

        // TODO: verify Collection has offsetUnset(); if not, use unset() on the underlying array.
        $this->entries->offsetUnset($entry_id);

        return true;
    }

    public function getEntryByID(int $entry_id): ?EntryEntity
    {
        if (!$this->entries->offsetExists($entry_id)) {
            return null;
        }

        return $this->entries->offsetGet($entry_id);
    }

    public function addEntry(array $entry_data): void
    {
        $entry = $this->generateEntry($entry_data);

        if ($this->entries->contains($entry)) {
            return;
        }

        $this->entries[$entry_data['entry_id']] = $entry;
    }

    /**
     * The value of every field of the collection, taken from raw input: a form post, or the data of an imported entry.
     * A value that was given is kept as it is. A field that is missing (or null) takes what $missing_value returns
     * for it; the form leaves it blank, a caller that wants configured defaults passes
     * fn($field) => FieldDefaultUtil::valueFor($field) ?? ''.
     *
     * @param array<string, mixed> $input
     * @param null|callable(\MyShowcase\Entity\CustomFieldEntity): mixed $missing_value
     * @param null|Collection<\MyShowcase\Entity\CustomFieldEntity> $fields The fields to read; all of them by default.
     * @return array<string, mixed> field_key => value
     */
    public function buildFieldValues(array $input, ?callable $missing_value = null, ?Collection $fields = null): array
    {
        $values = [];

        foreach ($fields ?? $this->context->custom_fields_collection as $field) {
            if (isset($input[$field->field_key])) {
                $values[$field->field_key] = $input[$field->field_key];
            } else {
                $values[$field->field_key] = $missing_value === null ? '' : $missing_value($field);
            }
        }

        return $values;
    }

    /**
     * Whether the current user is a moderator of this showcase: a super moderator or board administrator (issupermod
     * or cancp on the usergroup), or listed as a moderator of the showcase by user id or through one of their usergroups.
     *
     * This is the single moderator notion for entries and comments (decision D29). The ACP moderator flags are stored but
     * not read here.
     * TODO(verify): ModeratorsRepository::matchesUser() is static and exists; $this->context->moderators_repository
     *   is a property of ShowcaseContext. Neither has been confirmed in the files reviewed so far.
     */
    public function isModerator(): bool
    {
        $user = $this->context->current_user;

        if ($user->uid < 1) {
            return false;
        }

        if (isset($this->is_moderator_cache[$user->uid])) {
            return $this->is_moderator_cache[$user->uid];
        }

        $usergroup = $this->context->mybb->usergroup ?? [];

        $moderator = !empty($usergroup['issupermod']) || !empty($usergroup['cancp']);

        if (!$moderator) {
            $group_ids = array_filter(
                array_map(
                    'intval',
                    explode(
                        ',',
                        (int)($this->context->mybb->user['usergroup'] ?? 0) . ',' . ($this->context->mybb->user['additionalgroups'] ?? '')
                    )
                )
            );

            foreach (
                $this->context->moderators_repository->queryModerators([], ['user_id', 'group_id']) as $moderator_row
            ) {
                if (ModeratorsRepository::matchesUser($moderator_row, $group_ids, (int)$user->uid)) {
                    $moderator = true;

                    break;
                }
            }
        }

        return $this->is_moderator_cache[$user->uid] = $moderator;
    }

    /**
     * Condition that limits a query on the entries table to the entries the current user may see, built from
     * statusViewScope(): for each status, nothing, the user's own entries, or all of them. The listing, the search, the
     * entry page, the update page, comment access and the export all use it.
     */
    public function visibilityWhereClause(string $alias = 'entry_data'): string
    {
        $uid = (int)$this->context->current_user->uid;

        $conditions = [];

        foreach (
            [
                ContentStatuses::VisibleApproved,
                ContentStatuses::AwaitingApproval,
                ContentStatuses::SoftDeleted,
            ] as $status
        ) {
            $conditions[] = match ($this->statusViewScope($status)) {
                'any' => "{$alias}.status='{$status}'",
                'own' => "({$alias}.status='{$status}' AND {$alias}.user_id='{$uid}')",
                default => null,
            };
        }

        return '(' . implode(' OR ', array_filter($conditions)) . ')';
    }

    /**
     * How many entries of a status the current user may see: 'any' (entries of every user), 'own' (only theirs) or
     * 'none'. THE visibility rule, applied nowhere else:
     * - approved entries: everyone ('any');
     * - awaiting approval / soft-deleted: without entries_can_view_unapproved / entries_can_view_deleted, 'none', not even
     *   their own; with it, 'own' for a user who is not a moderator of the showcase and 'any' for a moderator.
     */
    public function statusViewScope(int $status): string
    {
        if ($status === ContentStatuses::VisibleApproved) {
            return 'any';
        }

        $permission = self::STATUS_VIEW_PERMISSIONS[$status] ?? null;

        if ($permission === null || $this->context->current_user->uid < 1 ||
            !$this->context->current_user->hasPermission($permission)) {
            return 'none';
        }

        return $this->isModerator() ? 'any' : 'own';
    }

    /**
     * Whether the current user may see an entry of this status that belongs to $owner_id. Without an owner: whether they
     * may see any entry of that status at all (what the export form asks to decide which options to offer).
     */
    public function canViewStatus(int $status, ?int $owner_id = null): bool
    {
        return match ($this->statusViewScope($status)) {
            'any' => true,
            'own' => $owner_id === null || $owner_id === (int)$this->context->current_user->uid,
            default => false,
        };
    }

    /**
     * How many comments of a status the current user may see: 'any', 'own' or 'none'. The comment side of the entry rule
     * in statusViewScope(), with the permissions of comments (decision D30):
     * - approved comments: everyone with comments_can_view;
     * - awaiting approval / soft-deleted: without comments_can_view_unapproved / comments_can_view_deleted, 'none';
     *   with it, 'own' for a user who is not a moderator and 'any' for a moderator.
     */
    private function commentStatusViewScope(int $status): string
    {
        $user = $this->context->current_user;

        if ($status === ContentStatuses::VisibleApproved) {
            return $user->hasPermission(UserPermissions::CommentsCanView) ? 'any' : 'none';
        }

        $permission = self::COMMENT_STATUS_VIEW_PERMISSIONS[$status] ?? null;

        if ($permission === null || $user->uid < 1 || !$user->hasPermission($permission)) {
            return 'none';
        }

        return $this->isModerator() ? 'any' : 'own';
    }

    /**
     * Whether the current user may see a comment of this status that belongs to $owner_id (the comment side of
     * canViewStatus()). Without an owner: whether they may see any comment of that status at all.
     */
    public function canViewCommentStatus(int $status, ?int $owner_id = null): bool
    {
        return match ($this->commentStatusViewScope($status)) {
            'any' => true,
            'own' => $owner_id === null || $owner_id === (int)$this->context->current_user->uid,
            default => false,
        };
    }

    /**
     * Condition on the comments table that limits a query to the comments the current user may see, built from
     * commentStatusViewScope(), so it means the same as canViewCommentStatus(). $alias is the table alias ('' for none).
     */
    public function commentVisibilityWhereClause(string $alias = 'comment_data'): string
    {
        $prefix = $alias === '' ? '' : "{$alias}.";

        $uid = (int)$this->context->current_user->uid;

        $conditions = [];

        foreach (
            [
                ContentStatuses::VisibleApproved,
                ContentStatuses::AwaitingApproval,
                ContentStatuses::SoftDeleted,
            ] as $status
        ) {
            $conditions[] = match ($this->commentStatusViewScope($status)) {
                'any' => "{$prefix}status='{$status}'",
                'own' => "({$prefix}status='{$status}' AND {$prefix}user_id='{$uid}')",
                default => null,
            };
        }

        $conditions = array_values(array_filter($conditions));

        return $conditions === [] ? '1=0' : '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * The inline moderation tools the current user holds the permission for (tool => true); empty for a guest. The
     * inline moderation menu shows these, and a tool that is not in the list is refused.
     *
     * @return array<string, true>
     */
    public function moderationTools(): array
    {
        if ($this->context->current_user->uid < 1) {
            return [];
        }

        $tools = [];

        foreach (self::MODERATION_TOOLS as $tool => $permission) {
            if ($this->context->current_user->hasPermission($permission)) {
                $tools[$tool] = true;
            }
        }

        return $tools;
    }

    /**
     * Whether the current user may use $tool on this entry: they hold the tool's permission, they may see the entry
     * (canViewStatus()), and it is their own entry or they moderate the showcase. The permissions are described as acting on
     * 'your own entries', so acting on someone else's also takes being a moderator (isModerator()).
     */
    public function canModerateEntry(string $tool, EntryEntity $entry): bool
    {
        $uid = (int)$this->context->current_user->uid;

        if ($uid < 1 || !isset(self::MODERATION_TOOLS[$tool]) ||
            !$this->context->current_user->hasPermission(self::MODERATION_TOOLS[$tool]) ||
            !$this->canViewStatus((int)$entry->status, (int)$entry->user_id)) {
            return false;
        }

        return (int)$entry->user_id === $uid || $this->isModerator();
    }

    /**
     * The most entries the current user may have in this showcase: the group permission "Limit Entry Post Amount"
     * (UserPermissions::EntriesLimitPostAmount, entries_limit_post_amount, set per group in the ACP; with several
     * groups the highest value counts). 0 means no limit.
     */
    public function entryPostLimit(): int
    {
        if ($this->context->current_user->uid < 1) {
            return 0;
        }

        return max(0, (int)($this->context->current_user->getPermissions()?->entries_limit_post_amount ?? 0));
    }

    /**
     * How many entries the current user has in this showcase against the limit: approved ones and the ones awaiting
     * approval (so the approval queue cannot be used to go past the limit). Soft-deleted entries do not count.
     */
    public function userEntriesCount(): int
    {
        return $this->context->entries_repository->queryEntryCount([
            "entry_data.user_id='" . (int)$this->context->current_user->uid . "'",
            "entry_data.status<>'" . ContentStatuses::SoftDeleted . "'",
        ]);
    }

    /**
     * Whether the current user may create $amount more entries without going past the limit, that is
     * existing entries + $amount <= limit. Always true when there is no limit.
     */
    public function canPostEntries(int $amount = 1): bool
    {
        $limit = $this->entryPostLimit();

        return $limit === 0 || $this->userEntriesCount() + $amount <= $limit;
    }

    /**
     * The name of a field in messages: its translated label when the language has one, else its label, else its key.
     */
    public function fieldLabel(\MyShowcase\Entity\CustomFieldEntity $field): string
    {
        return $this->context->language->{'myshowcase_collection_field_' . $field->field_key}
            ?? ($field->field_label !== '' ? $field->field_label : $field->field_key);
    }

    /**
     * How many attachments the entry being validated has for a file field, not counting the ones the submitted form
     * removes: stored against the entry, or, on a new entry, uploaded under its post hash.
     */
    private function countFieldAttachments(int $field_id): int
    {
        // The files an entries import has checked and will attach after the entry is saved (decision T20 and section 5.9).
        $pending = (int)($this->pending_file_counts[$field_id] ?? 0);

        $where_clauses = ["field_id='{$field_id}'"];

        $owners = [];

        if (!empty($this->validation_entry->entry_id)) {
            $owners[] = "entry_id='" . (int)$this->validation_entry->entry_id . "'";
        }

        if (!empty($this->validation_entry->post_hash)) {
            $owners[] = "post_hash='{$this->context->database->escape_string($this->validation_entry->post_hash)}'";
        }

        if (!$owners) {
            return $pending;
        }

        $where_clauses[] = '(' . implode(' OR ', $owners) . ')';

        if ($this->removed_attachment_ids) {
            $where_clauses[] = "attachment_id NOT IN ('" . implode("','", $this->removed_attachment_ids) . "')";
        }

        return $pending + count(
            iterator_to_array(
                $this->context->attachments_repository->queryAttachments($where_clauses, ['attachment_id']),
                false
            )
        );
    }

    /**
     * The fields of the collection the current user may see.
     *
     * @return Collection<\MyShowcase\Entity\CustomFieldEntity>
     */
    public function viewableFields(): Collection
    {
        return $this->context->custom_fields_collection->filter(
            fn(\MyShowcase\Entity\CustomFieldEntity $field): bool => $field->canView()
        );
    }

    /**
     * The fields of the collection the current user may give a value.
     *
     * @return Collection<\MyShowcase\Entity\CustomFieldEntity>
     */
    public function fillableFields(): Collection
    {
        return $this->context->custom_fields_collection->filter(
            fn(\MyShowcase\Entity\CustomFieldEntity $field): bool => $field->canFill()
        );
    }

    /**
     * What the create and update forms may take from posted input, and nothing else: the posted values are filtered
     * here, so a user cannot set server-controlled columns (user, status, views, dates, slug, counters, ip address)
     * by adding them to the post. Keys that are not allowed are dropped without an error.
     *
     * - subject, message: when the showcase has them enabled;
     * - icon_id: when the showcase allows post icons;
     * - allow_signature (canUseSignature()), disable_smilies (the showcase parses smilies in entries), is_closed
     *   (EntriesCanClose) and is_sticky (EntriesCanSticky) are checkboxes: a missing value means off, on an edit and on a
     *   new entry, for a user who may set one; a user who may not is never taken from the post (the stored value, or the
     *   column default on a new entry, stays);
     * - the fields of the collection the user may fill (CustomFieldEntity::canFill()), blank when not posted.
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    public function allowedEntryInput(array $posted): array
    {
        $user = $this->context->current_user;

        $showcase = $this->context->showcase;

        $allowed_keys = [];

        if ($showcase->entries_enable_subject) {
            $allowed_keys[] = 'subject';
        }

        if ($showcase->entries_enable_message) {
            $allowed_keys[] = 'message';
        }

        if ($showcase->entries_allow_post_icons) {
            $allowed_keys[] = 'icon_id';
        }

        // Checkboxes the user may set. An unchecked checkbox is not posted at all, so for these a missing value means
        // "off" (below). The ones the user may not set are not listed and keep their stored value.
        $allowed_checkboxes = [];

        if ($this->canUseSignature()) {
            $allowed_checkboxes[] = 'allow_signature';
        }

        if ($showcase->parser_allow_smiles_in_entries) {
            $allowed_checkboxes[] = 'disable_smilies';
        }

        if ($user->hasPermission(UserPermissions::EntriesCanClose)) {
            $allowed_checkboxes[] = 'is_closed';
        }

        if ($user->hasPermission(UserPermissions::EntriesCanSticky)) {
            $allowed_checkboxes[] = 'is_sticky';
        }

        $allowed = [];

        foreach ($allowed_keys as $key) {
            if (isset($posted[$key]) && is_scalar($posted[$key])) {
                $allowed[$key] = $posted[$key];
            }
        }

        foreach ($allowed_checkboxes as $key) {
            if (isset($posted[$key]) && is_scalar($posted[$key])) {
                $allowed[$key] = $posted[$key];
            } else {
                // The form was submitted without the box ticked, on an edit and on a new entry alike.
                $allowed[$key] = 0;
            }
        }

        return $allowed + $this->buildFieldValues($posted, fields: $this->fillableFields());
    }

    /**
     * True for every member with EntriesCanView (on by default); it must not be used as a moderator test. Use isModerator().
     */
    public function moderatesShowcase(): bool
    {
        $user = $this->context->current_user;

        if ($user->uid < 1) {
            return false;
        }

        if ($user->hasPermission(UserPermissions::EntriesCanView)) {
            return true;
        }

        $group_ids = array_filter(
            array_map(
                'intval',
                explode(
                    ',',
                    ($this->context->mybb->user['usergroup'] ?? 0) . ',' . ($this->context->mybb->user['additionalgroups'] ?? '')
                )
            )
        );

        $conditions = ["user_id='" . (int)$user->uid . "'"];

        if ($group_ids) {
            $conditions[] = "group_id IN ('" . implode("','", $group_ids) . "')";
        }

        return !empty($this->context->moderators_repository->queryModerators(
            ['(' . implode(' OR ', $conditions) . ')'],
            [],
            ['limit' => 1]
        )['moderator_id']);
    }

    /**
     * Whether the current user may choose to include their signature: the same test MyBB core applies before it shows
     * the signature option in newthread.php, newreply.php and editpost.php, namely that the group may use signatures
     * (canusesig == 1) and the user's signature is not suspended. The form shows the checkbox on this, and
     * allowedEntryInput() accepts allow_signature on this.
     */
    public function canUseSignature(): bool
    {
        return (int)($this->context->mybb->usergroup['canusesig'] ?? 0) === 1 &&
            empty($this->context->mybb->user['suspendsignature']);
    }

    /**
     * The status an entry has after its author edited it: awaiting approval for a user whose group holds "Moderate
     * Entry Updates" (UserPermissions::EntriesModerateUpdates) and may not bypass moderation, the current status for
     * everyone else. A deleted entry stays deleted.
     */
    public function updatedEntryStatus(int $current_status): int
    {
        if ($current_status !== ContentStatuses::SoftDeleted &&
            !$this->context->current_user->hasPermission(UserPermissions::CanBypassModeration) &&
            $this->context->current_user->hasPermission(UserPermissions::EntriesModerateUpdates)) {
            return ContentStatuses::AwaitingApproval;
        }

        return $current_status;
    }

    /**
     * The status a new entry is created with for the current user. The form and any other way of creating entries ask
     * here, so there is one decision.
     */
    public function newEntryStatus(): int
    {
        // The group permission "Moderate New Entries" (entries_moderate_new) sends the entries of its users to the
        // approval queue, unless they may bypass moderation (can_bypass_moderation).
        if (!$this->context->current_user->hasPermission(UserPermissions::CanBypassModeration) &&
            $this->context->current_user->hasPermission(UserPermissions::EntriesModerateNew)) {
            return ContentStatuses::AwaitingApproval;
        }

        return ContentStatuses::VisibleApproved;
    }

    public function generateEntry(array $entry_data): EntryEntity
    {
        $fields_data = $extra_data = [];

        foreach ($this->context->custom_fields_collection as $field_id => $field) {
            if (isset($entry_data[$field->field_key])) {
                $fields_data[$field->field_key] = $entry_data[$field->field_key];

                unset($entry_data[$field->field_key]);
            }
        }

        foreach ($entry_data as $field_key => $field_value) {
            if (!in_array($field_key, array_keys(DATA_TABLE_STRUCTURE['myshowcase_data']))) {
                $extra_data[$field_key] = $field_value;

                unset($entry_data[$field_key]);
            }
        }

        foreach (DATA_TABLE_STRUCTURE['myshowcase_data'] as $field_name => $field_definition) {
            if (isset($entry_data[$field_name])) {
                $entry_data[$field_name] = castTableFieldValue(
                    $entry_data[$field_name],
                    $field_definition['type']
                );
            }
        }

        //$entry_data['extra_data'] = $extra_data;

        $entry = new EntryEntity(...stringKeysOnly($entry_data));

        foreach ($fields_data as $field_key => $field_value) {
            $field_definition = current(
                array_filter(
                    $this->context->collection_fields,
                    function ($field_definition) use ($field_key): bool {
                        return $field_definition['field_key'] === $field_key;
                    }
                )
            ) ?: [];

            // Several submitted values stay in the extra data, where the validation rejects them.
            $custom_field = new EntryCustomField(
                $field_key,
                is_int($field_value) || is_string($field_value) ? $field_value : ''
            );

            $custom_field->setParserOptions([
                'allow_html' => !empty($field_definition['parser_allow_html']),
                'allow_mycode' => !empty($field_definition['parser_allow_mycode']),
                'allow_smilies' => !empty($field_definition['parser_allow_smiles']),
                'allow_imgcode' => !empty($field_definition['parser_allow_image_code']),
                'allow_videocode' => !empty($field_definition['parser_allow_video_code']),
                'filter_badwords' => true,
                'shorten_urls' => false,
                'highlight' => false,
            ]);

            $entry->addCustomField($custom_field);

            // insertEntry() reads the values to store from the extra data.
            $entry->setExtraData($field_key, $field_value);
        }

        $this->attachFields($entry, $fields_data);

        return $entry;
    }

    /**
     * Gives the entry one field object for every enabled field of the collection, in field order, holding the value
     * the entry has for it (an empty string when it has none).
     *
     * @param array<string, mixed> $values Field key => value
     */
    public function attachFields(EntryEntity $entry, array $values = []): void
    {
        $factory = new FieldFactory();

        foreach ($this->context->collection_fields as $field_definition) {
            if (!FieldFactory::supports((string)$field_definition['html_type'])) {
                continue;
            }

            $value = $values[$field_definition['field_key']] ?? '';

            $entry->addField(
                $factory->create($field_definition)->valueSet(is_scalar($value) ? (string)$value : '')
            );
        }
    }

    /**
     * Sets the data to be used for the data handler
     *
     * @param EntryEntity $entry
     * @param string $method
     * @return void
     */
    public function validateSet(EntryEntity $entry, string $method = self::VALIDATION_METHOD_INSERT): void
    {
        // Every entry is validated from a clean state: nothing found or cached for the previous one carries over.
        $this->validation_errors = [];

        $this->validation_data_is_valid = true;

        $this->stored_entry_row = null;

        $this->validation_entry = $entry;

        $this->validation_method = $method;
    }

    /**
     * Add an error to the error array.
     *
     * @param string $message The error name.
     * @param array $data
     */
    public function validateSetError(string $message, array $data = []): void
    {
        // An error is identified by its message and its values (for example the field it is about), so two fields
        // failing the same check are two errors instead of the second replacing the first.
        $this->validation_errors[$message . '|' . json_encode($data)] = $this->context->language->sprintf(
            $this->validationErrorText($message),
            ...$data
        );
    }

    /**
     * Some callers pass the finished text, others the short name of a data handler message ('required_field',
     * 'invalid_value1'). The short names live in datahandler_myshowcase.lang.php, so that is where they are read.
     */
    private function validationErrorText(string $message): string
    {
        if (!preg_match('/^[a-z_]+\d*$/', $message)) {
            return $message;
        }

        static $loaded = false;

        if (!$loaded) {
            $this->context->language->load('datahandler_myshowcase', false, true);

            $loaded = true;
        }

        $language_key = 'myshowcase_datahandler_' . preg_replace('/\d+$/', '', $message);

        return $this->context->language->{$language_key} ?? $message;
    }

    /** Files the submitted form removes: they do not count as uploaded while the entry is validated. */
    public function setRemovedAttachments(array $attachment_ids): void
    {
        $this->removed_attachment_ids = array_map('intval', $attachment_ids);
    }

    /**
     * @return array
     */
    public function validateGetError(): array
    {
        return $this->validation_errors;
    }

    /**
     * Validate a showcase.
     *
     * @return bool True when valid, false when invalid.
     * @throws RandomException
     * @throws Exception
     */
    public function validateEntry(): bool
    {
        hooksRun('entry_validate_start', $this);

        if ($this->context->current_user->hasPermission(UserPermissions::MainRequireCaptcha)) {
            require_once MYBB_ROOT . 'inc/class_captcha.php';

            $post_captcha = new captcha(true, 'post');

            if ($post_captcha->validate_captcha() === false) {
                foreach ($post_captcha->get_errors() as $error) {
                    $this->validateSetError($error);
                }
            }
        }

        if ($this->validation_method === self::VALIDATION_METHOD_INSERT && empty($this->validation_entry->user_id)) {
            $this->validation_entry->user_id = $this->context->current_user->uid;
        }

        if (isset($this->validation_entry->user_id) &&
            !$this->context->users_manager->getUserByID($this->validation_entry->user_id)) {
            $this->context->users_manager->addUser(get_user($this->validation_entry->user_id));
        }

        if (isset($this->validation_entry->user_id) && !get_user($this->validation_entry->user_id)) {
            $this->validateSetError($this->context->language->myshowcase_validation_errors_entry_invalid_user);
        }

        $user = $this->context->users_manager->getUserByID($this->validation_entry->user_id);

        if (!$user || !$user->hasPermission(UserPermissions::EntriesCanPost)) {
            if ($user->uid === $this->context->current_user->uid) {
                $this->validateSetError(
                    $this->context->language->myshowcase_validation_errors_entry_no_permission_current
                );
            } else {
                $this->validateSetError($this->context->language->myshowcase_validation_errors_entry_no_permission);
            }
        }

        $this->validation_entry->username = $user->username ?? $this->context->language->myshowcase_validation_guest;

        if (!$this->context->showcase->entries_enable_subject) {
            unset($this->validation_entry->subject);
        }

        if (isset($this->validation_entry->subject)) {
            $subject_length = my_strlen($this->validation_entry->subject);

            // A maximum of 0 means no limit other than the size of the column.
            $max_subject_length = $this->context->showcase->entries_subject_maximum_length > 0
                ? min(
                    DATA_TABLE_STRUCTURE['myshowcase_data']['subject']['size'],
                    $this->context->showcase->entries_subject_maximum_length
                )
                : DATA_TABLE_STRUCTURE['myshowcase_data']['subject']['size'];

            if ($subject_length < $this->context->showcase->entries_subject_minimum_length ||
                $subject_length > $max_subject_length) {
                $this->validateSetError(
                    $this->context->language->myshowcase_validation_errors_entry_invalid_subject_length,
                    [
                        my_number_format($this->context->showcase->entries_subject_minimum_length),
                        my_number_format($max_subject_length),
                        my_number_format($subject_length),
                    ]
                );
            }
        }

        if (!$this->context->showcase->entries_enable_message) {
            unset($this->validation_entry->message);
        }

        if (isset($this->validation_entry->message)) {
            if ($this->context->showcase->parser_affects_message_length_in_entries) {
                $message_length = my_strlen($this->validation_entry->message);
            } else {
                $message_length = my_strlen((new StringUtil())->stripCodes($this->validation_entry->message));
            }

            // A maximum of 0 means no limit.
            if ($message_length < $this->context->showcase->entries_message_minimum_length ||
                ($this->context->showcase->entries_message_maximum_length > 0 &&
                    $message_length > $this->context->showcase->entries_message_maximum_length)) {
                $this->validateSetError(
                    $this->context->language->myshowcase_validation_errors_entry_invalid_message_length,
                    [
                        my_number_format($this->context->showcase->entries_message_minimum_length),
                        my_number_format($this->context->showcase->entries_message_maximum_length),
                        my_number_format($message_length),
                    ]
                );
            }
        }

        if (!$this->context->showcase->entries_allow_post_icons) {
            unset($this->validation_entry->icon_id);
        }

        if (isset($this->validation_entry->icon_id) && $this->validation_entry->icon_id !== self::POST_ICON_NONE &&
            empty($this->context->mybb->cache->read('posticons')[$this->validation_entry->icon_id])) {
            $this->validateSetError($this->context->language->myshowcase_validation_errors_entry_invalid_icon);
        }

        $current_date_time = new DateTime('@' . TIME_NOW);

        if ($this->validation_method === self::VALIDATION_METHOD_INSERT && !isset($this->validation_entry->dateline)) {
            $this->validation_entry->dateline = $current_date_time;
        } elseif ($this->validation_method === self::VALIDATION_METHOD_UPDATE && isset($this->validation_entry->dateline) &&
            $this->changedOnUpdate('dateline') && $this->validation_entry->dateline > $current_date_time) {
            $this->validateSetError($this->context->language->myshowcase_validation_errors_entry_invalid_dateline);
        }

        if (isset($this->validation_entry->status) && !in_array($this->validation_entry->status, [
                ContentStatuses::AwaitingApproval,
                ContentStatuses::VisibleApproved,
                ContentStatuses::SoftDeleted,
            ])) {
            $this->validateSetError($this->context->language->myshowcase_validation_errors_entry_invalid_status);
        }

        if (isset($this->validation_entry->is_closed) && !in_array($this->validation_entry->is_closed, [
                self::STATUS_CLOSED,
                self::STATUS_OPEN,
            ])) {
            $this->validateSetError($this->context->language->myshowcase_validation_errors_entry_invalid_open_status);
        }

        if (isset($this->validation_entry->is_sticky) && !in_array($this->validation_entry->is_sticky, [
                self::STATUS_NORMAL,
                self::STATUS_STICKY,
            ])) {
            $this->validateSetError(
                $this->context->language->myshowcase_validation_errors_entry_invalid_priority_status
            );
        }

        if (isset($this->validation_entry->views) && $this->validation_entry->views < 0) {
            $this->validateSetError($this->context->language->myshowcase_validation_errors_entry_invalid_views_count);
        }

        if (isset($this->validation_entry->comments_awaiting) && $this->validation_entry->comments_awaiting < 0 ||
            isset($this->validation_entry->comments_approved) && $this->validation_entry->comments_approved < 0 ||
            isset($this->validation_entry->comments_deleted) && $this->validation_entry->comments_deleted < 0) {
            $this->validateSetError(
                $this->context->language->myshowcase_validation_errors_entry_invalid_comments_count
            );
        }

        if (isset($this->validation_entry->attachments_awaiting) && $this->validation_entry->attachments_awaiting < 0 ||
            isset($this->validation_entry->attachments_approved) && $this->validation_entry->attachments_approved < 0 ||
            isset($this->validation_entry->attachments_deleted) && $this->validation_entry->attachments_deleted < 0) {
            $this->validateSetError(
                $this->context->language->myshowcase_validation_errors_entry_invalid_attachments_count
            );
        }

        // On an update the time is stamped below, whatever the form sent.
        if (isset($this->validation_entry->update_dateline) && $this->validation_method !== self::VALIDATION_METHOD_UPDATE &&
            $this->validation_entry->update_dateline > $current_date_time) {
            $this->validateSetError(
                $this->context->language->myshowcase_validation_errors_entry_invalid_update_dateline
            );
        } elseif ($this->validation_method === self::VALIDATION_METHOD_UPDATE) {
            // An update always stamps the time it happens.
            $this->validation_entry->update_dateline = $current_date_time;
        }

        if ($this->validation_method === self::VALIDATION_METHOD_INSERT) {
            unset($this->validation_entry->edit_user_id);
        } elseif ($this->validation_method === self::VALIDATION_METHOD_UPDATE) {
            // The user who edits is the one who is recorded; the stored value (0 on a never-edited entry) is not checked.
            $this->validation_entry->edit_user_id = $this->context->current_user->uid;
        }

        if ($this->validation_method === self::VALIDATION_METHOD_INSERT || !$this->context->showcase->entries_enable_update_notes) {
            unset($this->validation_entry->update_note);
        } elseif ($this->validation_method === self::VALIDATION_METHOD_UPDATE && isset($this->validation_entry->update_note) &&
            my_strlen($this->validation_entry->update_note)
            > DATA_TABLE_STRUCTURE['myshowcase_data']['update_note']['size']) {
            $this->validateSetError(
                $this->context->language->myshowcase_validation_errors_entry_invalid_update_note,
                [
                    my_number_format(DATA_TABLE_STRUCTURE['myshowcase_data']['update_note']['size'])
                ]
            );
        } elseif ($this->validation_method === self::VALIDATION_METHOD_UPDATE && !isset($this->validation_entry->update_note)) {
            $this->validation_entry->update_note = '';
        }

        if ($this->validation_method === self::VALIDATION_METHOD_INSERT) {
            unset($this->validation_entry->last_comment_id);
        } elseif ($this->validation_method === self::VALIDATION_METHOD_UPDATE && isset($this->validation_entry->last_comment_user_id) &&
            $this->changedOnUpdate('last_comment_user_id') &&
            !($last_comment_user = get_user($this->validation_entry->last_comment_user_id))) {
            $this->validateSetError(
                $this->context->language->myshowcase_validation_errors_entry_invalid_last_comment_user
            );
        }

        if (isset($this->validation_entry->last_comment_id)) {
            $this->validation_entry->last_comment_username = $last_comment_user->username ?? $this->context->language->myshowcase_validation_guest;
        }

        if (isset($this->validation_entry->allow_signature) && !in_array($this->validation_entry->allow_signature, [
                self::SIGNATURE_DISABLED,
                self::SIGNATURE_ALLOW,
            ])) {
            $this->validateSetError(
                $this->context->language->myshowcase_validation_errors_entry_invalid_signature_status
            );
        }

        if (isset($this->validation_entry->disable_smilies) && !in_array($this->validation_entry->disable_smilies, [
                self::SMILIES_DISABLED,
                self::SMILIES_ALLOW,
            ])) {
            $this->validateSetError(
                $this->context->language->myshowcase_validation_errors_entry_invalid_smilies_status
            );
        }

        if ($this->validation_method === self::VALIDATION_METHOD_INSERT && !isset($this->validation_entry->ipaddress)) {
            $this->validation_entry->ipaddress = $this->context->mybb->session->ipaddress;
            unset($this->validation_entry->last_comment_id);
        } elseif (isset($this->validation_entry->ipaddress) && $this->changedOnUpdate('ipaddress') &&
            !((new ValidationHelpers())->validateIpAddress($this->validation_entry->ipaddress, false))) {
            $this->validateSetError($this->context->language->myshowcase_validation_errors_entry_invalid_ipaddress);
        }

        $entry_slug_custom = [];

        // The custom fields are checked from the collection's field entities. (The validator used to loop over
        // $entry->getFields(), which nothing ever fills, so none of these checks ran.)
        foreach ($this->context->custom_fields_collection as $field_id => $field) {
            $field_key = $field->field_key;

            if (!$field->isViewableInForm() ||
                $field->read_only ||
                !isset($this->validation_entry->extra_data[$field_key])
            ) {
                // todo, send user to data handler to check instead of current user
                continue;
            }

            $field_label = $this->fieldLabel($field);

            if ($field->regular_expression &&
                preg_match($field->regular_expression, '') !== false &&
                preg_match($field->regular_expression, $this->validation_entry->extra_data[$field_key]) !== 1) {
                $this->validateSetError('invalid_value1', [$field_label]);
            }

            if ($field->is_required && empty($this->validation_entry->extra_data[$field_key])) {
                if ($field->html_type === FieldHtmlTypes::File) {
                    if ($this->countFieldAttachments((int)$field_id) < 1) {
                        $this->validateSetError('required_field', [$field_label]);
                    }
                } else {
                    $this->validateSetError('required_field', [$field_label]);
                }
            }

            if (empty($this->validation_entry->extra_data[$field_key]) && $field->default_value !== '') {
                $this->validation_entry->extra_data[$field_key] = $field->default_value;
            }

            // A field left empty has nothing more to check: if it is required it was reported above.
            if ($this->validation_entry->extra_data[$field_key] === '' ||
                $this->validation_entry->extra_data[$field_key] === []) {
                continue;
            }

            if (fieldTypeMatchInt($field->field_type)) {
                if (is_array($this->validation_entry->extra_data[$field_key])) {
                    foreach ($this->validation_entry->extra_data[$field_key] as $fieldValue) {
                        if (!is_numeric($fieldValue)) {
                            $this->validateSetError('invalid_value2', [$field_label]);
                        }
                    }
                } elseif (!is_numeric($this->validation_entry->extra_data[$field_key])) {
                    $this->validateSetError('invalid_value3', [$field_label]);
                }
            } elseif (fieldTypeMatchChar($field->field_type) || fieldTypeMatchText($field->field_type)) {
                if (!is_scalar($this->validation_entry->extra_data[$field_key])) {
                    $this->validateSetError('invalid_value4', [$field_label]);

                    continue;
                }

                if ($field->html_type !== FieldHtmlTypes::Date) {
                    $length = my_strlen($this->validation_entry->extra_data[$field_key]);

                    if ($field->minimum_length && $length < $field->minimum_length) {
                        $this->validateSetError(
                            'invalid_length_minimum',
                            [$field_label, my_number_format($field->minimum_length)]
                        );
                    }

                    if ($field->maximum_length && $length > $field->maximum_length) {
                        $this->validateSetError(
                            'invalid_length_maximum',
                            [$field_label, my_number_format($field->maximum_length)]
                        );
                    }
                }
            }
        }

        foreach ($this->context->collection_fields as $field_id => $field_definition) {
            if (!$field_definition['is_enabled'] ||
                !isset($this->validation_entry->extra_data[$field_definition['field_key']]) ||
                !is_member($field_definition['allowed_groups_fill'])) {
                continue;
            }

            if ($field_definition['filter_on_save']) {
                $this->validation_entry->extra_data[$field_definition['field_key']] = match ($field_definition['filter_on_save']) {
                    FilterTypes::UUID => (new UuidUtil())->generateUuidV4(),
                    default => $this->validation_entry->extra_data[$field_definition['field_key']],
                };
            }
        }

        // It doesn't matter if this changes, only the public_id should remain the same always.
        if ($this->validation_method === self::VALIDATION_METHOD_INSERT) {
            $entry_slug_custom = [];

            foreach ($this->context->custom_fields_collection as $field_id => $field) {
                if (!$field->enable_slug || $this->validation_entry->getCustomField($field->field_key) === false) {
                    continue;
                }

                if (is_array($this->validation_entry->extra_data[$field->field_key])) {
                    foreach ($this->validation_entry->extra_data[$field->field_key] as $field_data_id) {
                        $field_data_id = (int)$field_data_id;

                        if (!isset($this->context->collection_fields[$field_data_id])) {
                            continue;
                        }

                        foreach (
                            fieldDataGet(
                                [
                                    "field_data_id='{$field_data_id}'"
                                ],
                                ['value', 'allowed_groups_fill'],
                                ['order_by' => 'display_order']
                            ) as $field_data_id => $field_data_data
                        ) {
                            if (is_member($field_data_data['allowed_groups_fill'])) {
                                $entry_slug_custom[] = $field_data_data['value'];
                            }
                        }
                    }
                } elseif (is_member($field->allowed_groups_fill)) {
                    $entry_slug_custom[] = $this->validation_entry->extra_data[$field->field_key];
                }
            }
        }

        if (isset($this->validation_entry->subject)) {
            $entry_slug_custom[] = $this->validation_entry->subject;
        }

        if ($entry_slug_custom) {
            $this->validation_entry->entry_slug_custom = (new StringUtil())
                ->generateEntrySlug(implode(' ', $entry_slug_custom));
        }

        hooksRun('entry_validate_end', $this);

        if ($this->validation_errors) {
            $this->validation_data_is_valid = false;
        }

        return $this->validation_data_is_valid;
    }

    /**
     * Insert a showcase into the database.
     *
     * @return EntryEntity Object of new showcase entry
     * @throws RandomException
     */
    public function validationEntryInsert(): EntryEntity
    {
        if (!$this->validation_data_is_valid) {
            die('The entry needs to be validated before inserting it into the DB.');
        }

        if ($this->validation_errors) {
            die('The entry is not valid.');
        }

        hooksRun('entry_validate_insert_update_start', $this);

        if ($this->validation_method === self::VALIDATION_METHOD_INSERT && !isset($this->validation_entry->public_id)) {
            $unique_found = false;

            while (!$unique_found) {
                $this->validation_entry->public_id = (new StringUtil())->generatePublicId();

                $unique_found = !$this->context->entries_repository->queryEntry(
                    ["public_id='{$this->context->database->escape_string($this->validation_entry->public_id)}'"],
                    ['entry_id']
                );
            }
        }

        if ($this->validation_method === self::VALIDATION_METHOD_UPDATE) {
            $this->validation_entry->entry_id = $this->context->entries_repository->updateEntry(
                $this->validation_entry,
                $this->context->collection_fields
            );
        } else {
            $this->validation_entry->entry_id = $this->context->entries_repository->insertEntry(
                $this->validation_entry,
                $this->context->collection_fields
            );
        }

        // Assign any uploaded attachments with the specific post_hash to the newly created entry.
        // TODO(verify): AttachmentsRepository::linkAttachmentsByPostHash() exists with this signature.
        if (!empty($this->validation_entry->post_hash)) {
            $this->context->attachments_repository->linkAttachmentsByPostHash(
                $this->validation_entry->post_hash,
                (int)$this->context->current_user->uid,
                ['entry_id' => $this->validation_entry->entry_id]
            );
        }

        if (!isset($this->validation_entry->public_id)) {
            $this->validation_entry->public_id = $this->context->entries_repository->queryEntry(
                ["entry_id='{$this->validation_entry->entry_id}'"],
                ['public_id'],
            )['public_id'];
        }

        if (!isset($this->validation_entry->entry_slug_custom)) {
            $this->validation_entry->entry_slug_custom = $this->context->entries_repository->queryEntry(
                ["entry_id='{$this->validation_entry->entry_id}'"],
                ['entry_slug_custom'],
            )['entry_slug_custom'];
        }

        hooksRun('entry_validate_insert_update_end', $this);

        return $this->validation_entry;
    }

    /**
     * Updates a showcase that is already in the database.
     *
     * @throws RandomException
     */
    public function validationEntryUpdate(): EntryEntity
    {
        return $this->validationEntryInsert();
    }

    /**
     * On an update only the values that differ from the stored ones are validated. Everything else was accepted when
     * it was stored (for example the 0 of an entry that was never edited).
     */
    private function changedOnUpdate(string $column): bool
    {
        if ($this->validation_method !== self::VALIDATION_METHOD_UPDATE || empty($this->validation_entry->entry_id)) {
            return true;
        }

        $this->stored_entry_row ??= $this->context->entries_repository->queryEntry(
            ["entry_data.entry_id='" . (int)$this->validation_entry->entry_id . "'"],
            [
                'entry_data.dateline',
                'entry_data.update_dateline',
                'entry_data.edit_user_id',
                'entry_data.last_comment_user_id',
                'entry_data.ipaddress',
            ],
        );

        $value = $this->validation_entry->$column ?? null;

        if ($value instanceof DateTime) {
            $value = $value->format('Y-m-d H:i:s');
        }

        return (string)$value !== (string)($this->stored_entry_row[$column] ?? '');
    }
}