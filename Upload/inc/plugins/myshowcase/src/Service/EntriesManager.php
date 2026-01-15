<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use JetBrains\PhpStorm\NoReturn;
use MyBB;
use MyLanguage;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\Entry;
use MyShowcase\Entity\User;
use MyShowcase\Enum\FilterTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Util\StringUtil;
use MyShowcase\Util\UuidUtil;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

class EntriesManager
{
    public const VALIDATION_METHOD_INSERT = 'insert';

    public const VALIDATION_METHOD_UPDATE = 'update';

    private Collection $entries;

    public const STATUS_PENDING = 0;

    public const STATUS_VISIBLE = 1;

    public const STATUS_DELETED = 2;

    private const  FIRST_PAGE = 1;

    public int $total_entries = 0;

    private array $where_clauses = [];

    private array $query_tables = [];

    private array $query_fields = [];

    private array $query_options = ['order_by' => 'dateline', 'order_dir' => 'asc', 'limit_start' => 0];
    private array $validation_errors = [];
    private Entry $validation_data;
    private bool $validation_data_is_valid = true;
    private $validation_method = self::VALIDATION_METHOD_INSERT;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly MyBB $mybb,
        private readonly MyLanguage $language,
        private readonly User $current_user,
        private readonly EntriesRepository $entries_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly array $config,
        private readonly array $fields_cache,
        private readonly array $fieldset_searchable_fields,
        private readonly array $parser_options = [],
        private readonly array $filter,
    ) {
        $this->entries = new Collection();
    }

    /**
     * @return Collection<Entry>
     */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function getEntryByID(int $entry_id): Entry|bool
    {
        if (!$this->entries->offsetExists($entry_id)) {
            return false;
        }

        return $this->entries->offsetGet($entry_id);
    }

    public function addEntry(array $entry_data): void
    {
        $extra_data = [];

        foreach ($entry_data as $field_key => $field_value) {
            if (!in_array($field_key, array_keys(DATA_TABLE_STRUCTURE['myshowcase_data']))) {
                $extra_data[$field_key] = $field_value;

                unset($entry_data[$field_key]);
            }
        }

        if (!empty($extra_data)) {
            $entry_data['extra_data'] = $extra_data;
        }

        if (!$this->entries->contains($entry_data)) {
            if (!empty($entry_data['entry_id'])) {
                $this->entries[$entry_data['entry_id']] = new Entry(...$entry_data);
            } elseif (!empty($entry_data['post_hash'])) {
                $this->entries[$entry_data['post_hash']] = new Entry(...$entry_data);
            } else {
                $this->entries[] = new Entry(...$entry_data);
            }
        }
    }

    public function parseMessage(string $message, array $options = []): string
    {
        return postParser()->parse_message($message, array_merge($this->parser_options, $options));
    }

    /**
     * @throws RandomException
     */
    public function slugGenerate(?int $length = null): string
    {
        if (empty($length)) {
            $length = getSetting('slugLength') / 2;
        }

        $generated_slug = '';

        $unique_found = false;

        while (!$unique_found) {
            $generated_slug = my_strtolower(bin2hex(random_bytes($length)));

            $unique_found = !$this->entries_repository->queryEntry(
                ["entry_slug='{$this->database->escape_string($generated_slug)}'"],
                ['entry_id']
            );
        }

        return $generated_slug;
    }

    #[NoReturn] public function fetchEntries(
        array $where_clauses = [],
        array $query_options = [],
        array $url_params = [],
        bool $load_all = false,
    ): array {
        $this->query_options = array_merge($this->query_options, $query_options);

        if (!isset($this->query_options['limit']) && !$load_all) {
            $this->query_options['limit'] = $this->config['main_per_page'];
        }

        $this->where_clauses = $where_clauses;

        if (!$this->current_user->hasPermission(UserPermissions::EntriesCanView)) {
            $this->where_clauses[] = "(entry_data.status='" . self::STATUS_VISIBLE . "' OR (entry_data.user_id='{$this->current_user->user_id}' AND entry_data.status='" . self::STATUS_PENDING . "'))";
        }

        // First, the date cut for the entries.
        if (empty($url_params['days']) && !empty($this->current_user->days_prune)) {
            $days_cut = $this->current_user->days_prune;
        } else {
            $days_cut = $url_params['days'] ?? 0;
        }

        if ($days_cut > 0) {
            $this->where_clauses[] = "(entry_data.dateline>='" . (TIME_NOW - ($days_cut * 86400)) . "' OR entry_data.is_sticky='1') ";
            // todo, add setting to force showing featured entries regardless of date filter
        }

        if (!empty($url_params['user_id'])) {
            $this->where_clauses[] = "entry_data.user_id='{$url_params['user_id']}'";
        }

        //setup joins for query and build where clause based on search_field terms

        $this->query_tables = ['users user_data ON (user_data.uid = entry_data.user_id)'];

        $search_done = false;

        $keywords = array_filter(array_map(function (string $value) {
            return trim($value);
        }, explode(' ', $url_params['keywords'] ?? '')));

        $this->query_fields = array_merge(
            array_map(function (string $field_name): string {
                return str_starts_with($field_name, 'entry_data.') ? $field_name : 'entry_data.' . $field_name;
            }, array_keys(DATA_TABLE_STRUCTURE['myshowcase_data'])),
            [
                'user_data.username',
                'user_data.usergroup',
                'user_data.displaygroup'
            ]
        );

        foreach ($this->fields_cache as $field_id => $field_definition) {
            if ($field_definition['html_type'] === FieldHtmlTypes::CheckBox ||
                $field_definition['html_type'] === FieldHtmlTypes::Radio ||
                $field_definition['html_type'] === FieldHtmlTypes::Select) {
                $this->query_tables[] = "myshowcase_field_data table_{$field_definition['field_key']} ON (table_{$field_definition['field_key']}.field_data_id = entry_data.{$field_definition['field_key']} AND table_{$field_definition['field_key']}.field_id = '{$field_definition['field_id']}')";

                $this->query_fields[] = $field_definition['field_key'];

                if ($keywords && $url_params['sort_by'] === $field_definition['field_key']) {
                    foreach ($keywords as $keyword) {
                        if ($url_params['exact_match']) {
                            $this->where_clauses[] = "(table_{$field_definition['field_key']}.value='{$this->database->escape_string($keyword)}' OR table_{$field_definition['field_key']}.display_style='{$this->database->escape_string($keyword)}')";
                        } else {
                            $this->where_clauses[] = "(table_{$field_definition['field_key']}.value LIKE '%{$this->database->escape_string($keyword)}%' OR table_{$field_definition['field_key']}.display_style LIKE '%{$this->database->escape_string($keyword)}%'";
                        }
                    }

                    $this->where_clauses[] = "table_{$field_definition['field_key']}.set_id='{$this->config['field_set_id']}'";
                }
            } elseif ($url_params['sort_by'] === 'username' && !$search_done) {
                $this->query_tables[] = 'users user_search_data ON (entry_data.user_id = user_search_data.uid)';

                $this->query_fields[] = $field_definition['field_key'];

                if ($keywords) {
                    foreach ($keywords as $keyword) {
                        if ($url_params['exact_match']) {
                            $this->where_clauses[] = "user_search_data.username='{$this->database->escape_string($keyword)}'";
                        } else {
                            $this->where_clauses[] = "user_search_data.username LIKE '%{$this->database->escape_string($keyword)}%'";
                        }
                    }
                }

                $search_done = true;
            } else {
                $this->query_fields[] = $field_definition['field_key'];

                if ($keywords && $url_params['sort_by'] === $field_definition['field_key']) {
                    foreach ($keywords as $keyword) {
                        if ($url_params['exact_match']) {
                            $this->where_clauses[] = "entry_data.{$field_definition['field_key']}='{$this->database->escape_string($keyword)}'";
                        } else {
                            $this->where_clauses[] = "entry_data.{$field_definition['field_key']} LIKE '%{$this->database->escape_string($keyword)}%'";
                        }
                    }
                }
            }

            if ($keywords && isset($this->fieldset_searchable_fields[$field_definition['field_key']])) {
                foreach ($keywords as $keyword) {
                    if ($url_params['exact_match']) {
                        $this->where_clauses[] = "entry_data.{$field_definition['field_key']}='{$this->database->escape_string($keyword)}'";
                    } else {
                        $this->where_clauses[] = "entry_data.{$field_definition['field_key']} LIKE '%{$this->database->escape_string($keyword)}%'";
                    }
                }
            }
        }

        if (!empty($url_params['sort_by']) && $url_params['sort_by'] !== 'dateline') {
            $this->query_options['order_by'] = "{$this->database->escape_string($url_params['sort_by'])} {$url_params['order_by']}, is_sticky {$url_params['order_by']}, entry_id";

            $this->query_options['order_dir'] = $url_params['order_by'];
        } else {
            $this->query_options['order_by'] = 'is_sticky ' . ($url_params['order_by'] ?? '') . ', entry_id';

            if (isset($url_params['order_by'])) {
                $this->query_options['order_dir'] = $url_params['order_by'];
            }
        }

        hooksRun('entry_fetch_start', $this);

        $this->total_entries = $this->entries_repository->queryEntryCount(
            $this->where_clauses,
            $this->query_tables
        );

        if ($this->query_options['limit'] > 1 && !$load_all) {
            if ($this->mybb->input['page'] > ceil($this->total_entries / $this->query_options['limit'])) {
                $this->mybb->input['page'] = self::FIRST_PAGE;
            }

            if ($this->mybb->input['page']) {
                $this->query_options['limit_start'] = ($this->mybb->input['page'] - 1) * $this->query_options['limit'];
            } else {
                $this->mybb->input['page'] = self::FIRST_PAGE;
            }
        }

        hooksRun('entry_fetch_intermediate', $this);

        $entry_objects = [];

        foreach (
            $this->entries_repository->queryEntries(
                $this->where_clauses,
                $this->query_fields,
                $this->query_options,
                $this->query_tables
            ) as $entry_data
        ) {
            $entry_objects[] = $entry_data;
        }

        hooksRun('entry_fetch_end', $this);

        return $entry_objects;
    }

    #[NoReturn] public function fetchEntry(array $where_clauses = []): array
    {
        return $this->fetchEntries($where_clauses, ['limit' => 1])[0] ?? [];
    }

    /**
     * Sets the data to be used for the data handler
     *
     * @param Entry $entry_data
     * @param string $method
     * @return void
     */
    public function validateSet(Entry $entry_data, string $method = self::VALIDATION_METHOD_INSERT): void
    {
        $this->validation_data = $entry_data;

        $this->validation_method = $method;
    }

    /**
     * Add an error to the error array.
     *
     * @param string $error The error name.
     * @param array $data
     */
    public function validateSetError(string $error, array $data = []): void
    {
        $this->validation_errors[$error] = $this->language->sprintf($error, ...$data);
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
     */
    public function validateEntry(): bool
    {
        hooksRun('entry_validate_start', $this);

        if (empty($this->validation_data)) {
            $this->validateSetError('invalid_entry');
        }

        if (isset($this->validation_data->entry_slug_custom)) {
            $slugLength = my_strlen($this->validation_data->entry_slug_custom);

            if ($slugLength < 1) {
                $this->validateSetError('custom_slug_too_short', [1]);
            }

            if ($slugLength > DATA_TABLE_STRUCTURE['myshowcase_data']['entry_slug_custom']['size']) {
                $this->validateSetError(
                    'custom_slug_too_long',
                    [DATA_TABLE_STRUCTURE['myshowcase_data']['entry_slug_custom']['size']]
                );
            }
        }

        if (!empty($this->validation_data->user_id) && empty(get_user($this->validation_data->user_id)['uid'])) {
            $this->validateSetError('invalid_user');
        }

        if (isset($this->validation_data->views) && $this->validation_data->views < 0) {
            $this->validateSetError('invalid_views');
        }

        if (isset($this->validation_data->comments_approved) && $this->validation_data->comments_approved < 0) {
            $this->validateSetError('invalid_comments');
        }

        if (isset($this->validation_data->status) && !in_array(
                $this->validation_data->status,
                [
                    $this::STATUS_PENDING,
                    $this::STATUS_VISIBLE,
                    $this::STATUS_DELETED
                ]
            )) {
            $this->validateSetError('invalid_status');
        }

        if (!empty($this->validation_data->edit_user_id) &&
            empty(get_user($this->validation_data->edit_user_id)['uid'])) {
            $this->validateSetError('invalid_edit_user');
        }

        if (isset($this->validation_data->dateline) && $this->validation_data->dateline < 0) {
            $this->validateSetError('invalid_dateline');
        }

        if (isset($this->validation_data->edit_stamp) && $this->validation_data->edit_stamp < 0) {
            $this->validateSetError('invalid_edit_stamp');
        }

        foreach ($this->fields_cache as $fieldID => $fieldDefinition) {
            if (!$fieldDefinition['is_enabled'] ||
                $fieldDefinition['read_only'] ||
                !isset($this->validation_data->extra_data[$fieldDefinition['field_key']]) ||
                !is_member($fieldDefinition['allowed_groups_fill'])) {
                // todo, send user to data handler to check instead of current user
                continue;
            }

            if ($fieldDefinition['regular_expression'] &&
                preg_match($fieldDefinition['regular_expression'], '') !== false &&
                preg_match(
                    $fieldDefinition['regular_expression'],
                    $this->validation_data->extra_data[$fieldDefinition['field_key']]
                ) === 1) {
                $this->validateSetError(
                    'invalid_value',
                    [$this->language->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                );
            }

            if ($fieldDefinition['is_required'] && empty($this->validation_data->extra_data[$fieldDefinition['field_key']])) {
                if ($fieldDefinition['html_type'] === FieldHtmlTypes::File) {
                    if ($this->validation_data->getAttachmentsByFieldID($fieldID)->count() < 1) {
                        $this->validateSetError(
                            'required_field',
                            [$this->language->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                        );
                    }
                } else {
                    $this->validateSetError(
                        'required_field',
                        [$this->language->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                    );
                }
            }

            if (empty($this->validation_data->extra_data[$fieldDefinition['field_key']]) && $fieldDefinition['default_value'] !== '') {
                $this->validation_data->extra_data[$fieldDefinition['field_key']] = $fieldDefinition['default_value'];
            }

            if (fieldTypeMatchInt($fieldDefinition['field_type'])) {
                if (is_array($this->validation_data->extra_data[$fieldDefinition['field_key']])) {
                    foreach ($this->validation_data->extra_data[$fieldDefinition['field_key']] as $fieldValue) {
                        if (!is_numeric($fieldValue)) {
                            $this->validateSetError(
                                'invalid_value',
                                [$this->language->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                            );
                        }
                    }
                } elseif (!is_numeric($this->validation_data->extra_data[$fieldDefinition['field_key']])) {
                    $this->validateSetError(
                        'invalid_value',
                        [$this->language->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                    );
                }
            } elseif (fieldTypeMatchChar($fieldDefinition['field_type']) ||
                fieldTypeMatchText($fieldDefinition['field_type'])) {
                if (!is_scalar($this->validation_data->extra_data[$fieldDefinition['field_key']])) {
                    $this->validateSetError(
                        'invalid_value',
                        [$this->language->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                    );
                }

                if ($fieldDefinition['html_type'] !== FieldHtmlTypes::Date) {
                    if ($fieldDefinition['minimum_length'] &&
                        my_strlen(
                            $this->validation_data->extra_data[$fieldDefinition['field_key']]
                        ) < $fieldDefinition['minimum_length']) {
                        $this->validateSetError(
                            'invalid_length_minimum',
                            [
                                $this->language->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label'],
                                my_strlen($this->validation_data->extra_data[$fieldDefinition['field_key']]),
                                my_number_format($fieldDefinition['minimum_length']),
                                my_number_format($fieldDefinition['maximum_length']),
                            ]
                        );
                    }

                    if ($fieldDefinition['maximum_length'] &&
                        my_strlen(
                            $this->validation_data->extra_data[$fieldDefinition['field_key']]
                        ) > $fieldDefinition['maximum_length']) {
                        $this->validateSetError(
                            'invalid_length_maximum',
                            [
                                $this->language->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label'],
                                my_strlen($this->validation_data->extra_data[$fieldDefinition['field_key']]),
                                my_number_format($fieldDefinition['minimum_length']),
                                my_number_format($fieldDefinition['maximum_length']),
                            ]
                        );
                    }
                }
            }
        }

        foreach ($this->fields_cache as $fieldID => $fieldDefinition) {
            if (!$fieldDefinition['is_enabled'] ||
                !isset($this->validation_data->extra_data[$fieldDefinition['field_key']]) ||
                !is_member($fieldDefinition['allowed_groups_fill'])) {
                continue;
            }

            if ($fieldDefinition['filter_on_save']) {
                $this->validation_data->extra_data[$fieldDefinition['field_key']] = match ($fieldDefinition['filter_on_save']) {
                    FilterTypes::UUID => (new UuidUtil())->generateUuidV4(),
                    default => $this->validation_data->extra_data[$fieldDefinition['field_key']],
                };
            }
        }

        // it doesn't matter if this changes, only the entry_slug should remain the same always
        if ($this->validation_method !== self::VALIDATION_METHOD_UPDATE) {
            $entry_slug_custom = [];

            foreach ($this->fields_cache as $fieldID => $fieldDefinition) {
                if (!$fieldDefinition['enable_slug']) {
                    continue;
                }

                if (is_array($this->validation_data->extra_data[$fieldDefinition['field_key']])) {
                    foreach ($this->validation_data->extra_data[$fieldDefinition['field_key']] as $field_data_id) {
                        $field_data_id = (int)$field_data_id;

                        if (!isset($this->fields_cache[$field_data_id])) {
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
                } elseif (is_member($fieldDefinition['allowed_groups_fill'])) {
                    $entry_slug_custom[] = $this->validation_data->extra_data[$fieldDefinition['field_key']];
                }
            }

            $this->validation_data->entry_slug_custom = (new StringUtil())
                ->createSlug(implode(' ', $entry_slug_custom))
                ->lower()
                ->toString();
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
     * @return Entry Object of new showcase entry
     * @throws RandomException
     */
    public function validationEntryInsert(): Entry
    {
        if (!$this->validation_data_is_valid) {
            die('The entry needs to be validated before inserting it into the DB.');
        }

        if ($this->validation_errors) {
            die('The entry is not valid.');
        }

        hooksRun('entry_validate_insert_update_start', $this);

        if ($this->validation_method === self::VALIDATION_METHOD_INSERT && !isset($this->validation_data->entry_slug)) {
            $this->validation_data->entry_slug = $this->slugGenerate();
        }

        if ($this->validation_method === self::VALIDATION_METHOD_UPDATE) {
            $this->validation_data->entry_id = $this->entries_repository->updateEntry($this->validation_data);
        } else {
            $this->validation_data->entry_id = $this->entries_repository->insertEntry($this->validation_data);
        }

        // Assign any uploaded attachments with the specific post_hash to the newly created entry.
        if (!empty($this->validation_data->post_hash)) {
            foreach (
                $this->attachments_repository->queryAttachments(
                    ["post_hash='{$this->database->escape_string($this->validation_data->post_hash)}'"],
                    'attachment_id',
                ) as $attachmentID => $attachmentData
            ) {
                $this->attachments_repository->updateAttachment(
                    ['entry_id' => $this->validation_data->entry_id],
                    $attachmentID
                );
            }
        }

        if (!isset($this->validation_data->entry_slug)) {
            $this->validation_data->entry_slug = $this->entries_repository->queryEntry(
                ["entry_id='{$this->validation_data->entry_id}'"],
                ['entry_slug'],
            )['entry_slug'];
        }

        if (!isset($this->validation_data->entry_slug_custom)) {
            $this->validation_data->entry_slug_custom = $this->entries_repository->queryEntry(
                ["entry_id='{$this->validation_data->entry_id}'"],
                ['entry_slug_custom'],
            )['entry_slug_custom'];
        }

        hooksRun('entry_validate_insert_update_end', $this);

        return $this->validation_data;
    }

    /**
     * Updates a showcase that is already in the database.
     *
     * @throws RandomException
     */
    public function validationEntryUpdate(): Entry
    {
        return $this->validationEntryInsert();
    }
}