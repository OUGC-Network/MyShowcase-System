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
use MyShowcase\Fields\FieldFactory;
use MyShowcase\Fields\FieldsInterface;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\EntriesRepository;
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
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\optionGroupsAllow;
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
     * @return array<string, mixed> field_key => value
     */
    public function buildFieldValues(array $input, ?callable $missing_value = null): array
    {
        $values = [];

        foreach ($this->context->custom_fields_collection as $field) {
            if (isset($input[$field->field_key])) {
                $values[$field->field_key] = $input[$field->field_key];
            } else {
                $values[$field->field_key] = $missing_value === null ? '' : $missing_value($field);
            }
        }

        return $values;
    }

    /**
     * Condition that limits a query to the entries the current user may see: approved ones, plus their own that
     * await approval. Empty when the user holds $permission (EntriesCanView by default), which lets them see every
     * status. The listing, the entry page, the update page and comment access all use this one rule.
     */
    public function visibilityWhereClause(
        string $alias = 'entry_data',
        string $permission = UserPermissions::EntriesCanView
    ): string {
        if ($this->context->current_user->hasPermission($permission)) {
            return '';
        }

        return $this->statusVisibilityClause($alias);
    }

    /**
     * The same condition without the permission test: approved entries and the user's own awaiting approval.
     */
    public function statusVisibilityClause(string $alias = 'entry_data'): string
    {
        return "({$alias}.status='" . ContentStatuses::VisibleApproved . "' OR ("
            . "{$alias}.user_id='{$this->context->current_user->uid}' AND "
            . "{$alias}.status='" . ContentStatuses::AwaitingApproval . "'))";
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
     * The status a new entry is created with. The form and any other way of creating entries ask here, so there is one
     * decision.
     */
    public function newEntryStatus(): int
    {
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
            $field_definition = current(array_filter(
                $this->context->collection_fields,
                function ($field_definition) use ($field_key): bool {
                    return $field_definition['field_key'] === $field_key;
                }
            )) ?: [];

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

    /**
     * Whether a file field has an uploaded file: one already attached to the entry, or uploaded with the form (the
     * post hash) before the entry is saved. The entry being validated is built from the submitted form and carries
     * no attachments itself.
     */
    private function fileFieldHasAttachments(FieldsInterface $field): bool
    {
        $owners = [];

        if (!empty($this->validation_entry->entry_id)) {
            $owners[] = "entry_id='" . (int)$this->validation_entry->entry_id . "'";
        }

        if (!empty($this->validation_entry->post_hash)) {
            $owners[] = "post_hash='" . $this->context->database->escape_string($this->validation_entry->post_hash) . "'";
        }

        if (!$owners) {
            return false;
        }

        return $this->context->attachments_repository->queryAttachmentCount([
            "field_id='" . (int)$field->field_definition['field_id'] . "'",
            '(' . implode(' OR ', $owners) . ')',
        ]) > 0;
    }

    /**
     * Adds the error of a custom field, one per field and error, with the field label as the first argument.
     *
     * @param string $language_key A datahandler_myshowcase language key
     */
    private function validateSetFieldError(FieldsInterface $field, string $language_key, array $data = []): void
    {
        $this->validation_errors[$language_key . '_' . $field->field_definition['field_key']] = $this->context->language->sprintf(
            $this->context->language->$language_key ?? $language_key,
            htmlspecialchars_uni($field->getLabel()),
            ...$data
        );
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

        $this->context->language->load('datahandler_myshowcase', true, true);

        /**
         * @var FieldsInterface $field
         */
        foreach ($this->validation_entry->getFields() as $field) {
            $field_key = $field->field_definition['field_key'];

            if (!$field->isViewableInForm() ||
                $field->field_definition['read_only'] ||
                !array_key_exists($field_key, $this->validation_entry->extra_data)
            ) {
                // todo, send user to data handler to check instead of current user
                continue;
            }

            $value = $this->validation_entry->extra_data[$field_key];

            if (is_array($value)) {
                // Fields hold one value; several values (multiple select, several checkboxes) are not supported yet.
                $this->validateSetFieldError($field, 'myshowcase_datahandler_invalid_value');

                continue;
            }

            $value = (string)$value;

            if ($value === '' && $field->field_definition['default_value'] !== '') {
                $value = $this->validation_entry->extra_data[$field_key] = (string)$field->field_definition['default_value'];
            }

            if ($value === '') {
                if ($field->field_definition['is_required'] &&
                    ($field->field_definition['html_type'] !== FieldHtmlTypes::File || !$this->fileFieldHasAttachments($field))) {
                    $this->validateSetFieldError($field, 'myshowcase_datahandler_required_field');
                }

                continue;
            }

            if ($field->hasOptions()) {
                $field->fetchSelectableValues((int)$field->field_definition['collection_id'], true);

                if (!ctype_digit($value) || !$field->getSelectableValues()->offsetExists((int)$value)) {
                    $this->validateSetFieldError($field, 'myshowcase_datahandler_invalid_value');
                }

                continue;
            }

            if ($field->field_definition['regular_expression'] &&
                @preg_match($field->field_definition['regular_expression'], '') !== false &&
                preg_match($field->field_definition['regular_expression'], $value) !== 1) {
                $this->validateSetFieldError($field, 'myshowcase_datahandler_invalid_value');
            }

            if (fieldTypeMatchInt($field->field_definition['field_type'])) {
                if (!is_numeric($value)) {
                    $this->validateSetFieldError($field, 'myshowcase_datahandler_invalid_value');
                }
            } elseif ((fieldTypeMatchChar($field->field_definition['field_type']) ||
                    fieldTypeMatchText($field->field_definition['field_type'])) &&
                $field->field_definition['html_type'] !== FieldHtmlTypes::Date) {
                $length = my_strlen($value);

                if ($field->field_definition['minimum_length'] && $length < $field->field_definition['minimum_length']) {
                    $this->validateSetFieldError(
                        $field,
                        'myshowcase_datahandler_invalid_length_minimum',
                        [my_number_format($field->field_definition['minimum_length'])]
                    );
                }

                if ($field->field_definition['maximum_length'] && $length > $field->field_definition['maximum_length']) {
                    $this->validateSetFieldError(
                        $field,
                        'myshowcase_datahandler_invalid_length_maximum',
                        [my_number_format($field->field_definition['maximum_length'])]
                    );
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

        // it doesn't matter if this changes, only the entry_slug should remain the same always
        if ($this->validation_method === self::VALIDATION_METHOD_INSERT) {
            $entry_slug_custom = [];

            //$this->context->custom_fields_collection
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
                            if (optionGroupsAllow((string)$field_data_data['allowed_groups_fill'])) {
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

        if ($this->validation_method === self::VALIDATION_METHOD_INSERT && !isset($this->validation_entry->entry_slug)) {
            $unique_found = false;

            while (!$unique_found) {
                $this->validation_entry->entry_slug = (new StringUtil())->generateCommentSlug(getSetting('slugLength'));

                $unique_found = !$this->context->entries_repository->queryEntry(
                    ["entry_slug='{$this->context->database->escape_string($this->validation_entry->entry_slug)}'"],
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
        if (!empty($this->validation_entry->post_hash)) {
            // queryAttachments() yields rows without keys; read the ids first, then write.
            $attachment_ids = array_map(
                static fn(array $attachment_data): int => (int)$attachment_data['attachment_id'],
                iterator_to_array(
                    $this->context->attachments_repository->queryAttachments(
                        ["post_hash='{$this->context->database->escape_string($this->validation_entry->post_hash)}'"],
                        'attachment_id',
                    ),
                    false
                )
            );

            foreach ($attachment_ids as $attachment_id) {
                $this->context->attachments_repository->updateAttachment(
                    ['entry_id' => $this->validation_entry->entry_id],
                    $attachment_id
                );
            }
        }

        if (!isset($this->validation_entry->entry_slug)) {
            $this->validation_entry->entry_slug = $this->context->entries_repository->queryEntry(
                ["entry_id='{$this->validation_entry->entry_id}'"],
                ['entry_slug'],
            )['entry_slug'];
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

    private ?array $stored_entry_row = null;

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
            ['entry_data.dateline', 'entry_data.update_dateline', 'entry_data.edit_user_id', 'entry_data.last_comment_user_id', 'entry_data.ipaddress'],
        );

        $value = $this->validation_entry->$column ?? null;

        if ($value instanceof DateTime) {
            $value = $value->format('Y-m-d H:i:s');
        }

        return (string)$value !== (string)($this->stored_entry_row[$column] ?? '');
    }
}
