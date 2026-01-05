<?php

declare(strict_types=1);

namespace myshowcase\Service;

use MyShowcase\System\FieldDefaultTypes;
use MyShowcase\System\FieldHtmlTypes;
use MyShowcase\System\Showcase;
use MyShowcase\System\ModeratorPermissions;
use MyShowcase\Entity\Entry;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\attachmentDelete;
use function MyShowcase\Plugin\Functions\attachmentGet;
use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchBinary;
use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldTypeMatchDateTime;
use function MyShowcase\Plugin\Functions\fieldTypeMatchFloat;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\CACHE_TYPE_FIELDS;
use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\FILTER_TYPE_USER_ID;

class EntriesManager
{
    public const STATUS_PENDING = 0;

    public const STATUS_VISIBLE = 1;

    public const STATUS_DELETED = 2;

    private const  FIRST_PAGE = 1;

    private int $total_entries = 0;

    private int $total_pages = 1;

    private array $where_clauses = [];

    private array $query_tables = [];

    private array $structure_fields = [
        'entry_id',
        'entry_slug',
        'entry_slug_custom',
        'subject',
        'prefix_id',
        'icon_id',
        'user_id',
        'dateline',
        'views',
        'comments',
        'is_closed',
        'is_sticky',
        'status',
        'edit_user_id',
        'post_hash',
        'ipaddress',
    ];

    private array $query_fields = [];

    private array $query_options = ['order_by' => 'dateline', 'order_dir' => 'asc', 'limit_start' => 0, 'limit' => 10];

    private array $entries = [];

    private readonly ?int $entry_id;

    private array $insert_data = [];

    private array $entry_data = [];

    private readonly bool $is_update;

    public function __construct(
        private readonly int $showcase_id,
        private readonly int $showcase_fieldset_id,
        private readonly int $showcase_filter_force_field_id,
        private int $current_page = self::FIRST_PAGE,
        private readonly int $entries_per_page = 10,
        private readonly int $current_user_id = 0,
    ) {
        return $this;
    }

    public function setCurrentPage(int $page): void
    {
        if ($page > 0) {
            $this->current_page = $page;
        }
    }

    public function setEntry(array $entry_data): void
    {
        $extra_data = [];

        foreach ($entry_data as $field_key => $field_value) {
            if (!in_array($field_key, $this->structure_fields)) {
                $extra_data[$field_key] = $field_value;

                unset($entry_data[$field_key]);
            }
        }

        if (!empty($extra_data)) {
            $entry_data['extra_data'] = $extra_data;
        }

        $this->entries[$entry_data['entry_id']] = new Entry(...$entry_data);
    }

    public function getTotalEntries(): int
    {
        return $this->total_entries;
    }

    /**
     * @return iterable<Entry>
     */
    public function getEntries(): iterable
    {
        foreach ($this->entries as $entry_id => $entry_data) {
            yield $entry_data;
        }
    }

    public function getEntryByID(int $entry_id): \MyShowcase\Entity\Entry|bool
    {
        if (isset($this->entries[$entry_id])) {
            return $this->entries[$entry_id];
        }

        return false;
    }

    public function getEntriesIDs(): array
    {
        $entry_ids = [];

        foreach ($this->entries as $entry_id => $entry_data) {
            $entry_ids[$entry_id] = $entry_id;
        }

        return $entry_ids;
    }

    public function getCurrentPage(): int
    {
        return $this->current_page;
    }

    /**
     * @throws RandomException
     */
    public function generateSlug(?int $length = null): string
    {
        if (empty($length)) {
            $length = getSetting('slugLength');
        }

        global $db;

        $generatedSlug = '';

        $uniqueFound = false;

        while (!$uniqueFound) {
            $generatedSlug = my_strtolower(bin2hex(random_bytes($length)));

            $uniqueFound = !$this->queryEntry(["entry_slug='{$db->escape_string($generatedSlug)}'"]);
        }

        return $generatedSlug;
    }

    /**
     * @return iterable<array>
     */
    public function queryEntries(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
        array $query_tables = []
    ): iterable {
        global $db;

        $query_tables = array_merge(["myshowcase_data{$this->showcase_id} entry_data"], $query_tables);

        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        $query = $db->simple_select(
            implode(" LEFT JOIN {$db->table_prefix}", $query_tables),
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($entry_data = $db->fetch_array($query)) {
            yield $entry_data;
        }
    }

    public function queryEntry(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
        array $query_tables = []
    ): array {
        $query_options['limit'] = 1;

        $entry_object = [];

        foreach (
            $this->queryEntries(
                $where_clauses,
                $query_fields,
                $query_options,
                $query_tables
            ) as $entry_data
        ) {
            $entry_object = $entry_data;

            break;
        }

        return $entry_object;
    }

    public function queryEntryCount(
        array $where_clauses = [],
        array $query_tables = []
    ): int {
        return $this->queryEntry(
            $where_clauses,
            'COUNT(entry_id) AS total_entries',
            query_tables: $query_tables
        )['total_entries'];
    }

    public function fetchEntries(
        array $user_permissions,
        array $fields_cache,
        array $fields_searchable,
        array $url_params = [],
        string $search_keywords = '',
        string $search_field = '',
        int $search_exact = 0,
        string $sort_direction = 'asd',
        string $sort_field = 'dateline',
    ): self {
        $this->where_clauses = [];

        if (!$user_permissions[ModeratorPermissions::CanManageEntries]) {
            $this->where_clauses[] = "(entry_data.status='" . self::STATUS_VISIBLE . "' OR (entry_data.user_id='{$this->current_user_id}' AND entry_data.status='" . self::STATUS_PENDING . "'))";
        }

        global $mybb;

        // First, the date cut for the entries.
        if (empty($url_params['days']) && !empty($mybb->user['daysprune'])) {
            $days_cut = $mybb->user['daysprune'];
        } else {
            $days_cut = $url_params['days'];
        }

        if ($days_cut > 0) {
            $this->where_clauses[] = "(entry_data.dateline>='" . (TIME_NOW - ($days_cut * 86400)) . "' OR entry_data.is_sticky='1') ";
            // todo, add setting to force showing featured entries regardless of date filter
        }

        switch ($this->showcase_filter_force_field_id) {
            case FILTER_TYPE_USER_ID:
                $this->where_clauses[] = "entry_data.user_id='{$url_params['user_id']}'";

                break;
        }

        //setup joins for query and build where clause based on search_field terms

        $this->query_tables = ['users user_data ON (user_data.uid = entry_data.user_id)'];

        $search_done = false;

        $keywords = explode(' ', $search_keywords);

        $this->query_fields = array_merge(array_map(function (string $field_name): string {
            return str_starts_with($field_name, 'entry_data.') ? $field_name : 'entry_data.' . $field_name;
        }, $this->structure_fields), [
            'user_data.username',
            'user_data.usergroup',
            'user_data.displaygroup'
        ]);

        global $db;

        foreach ($fields_cache as $field_id => $field_definition) {
            if ($field_definition['html_type'] === FieldHtmlTypes::Select || $field_definition['html_type'] === FieldHtmlTypes::Radio) {
                $this->query_tables[] = "myshowcase_field_data table_{$field_definition['field_key']} ON (table_{$field_definition['field_key']}.field_data_id = entry_data.{$field_definition['field_key']} AND table_{$field_definition['field_key']}.field_id = '{$field_definition['field_id']}')";

                $this->query_fields[] = "table_{$field_definition['field_key']}.display_style AS {$field_definition['field_key']}";

                if ($search_keywords && $search_field === $field_definition['field_key']) {
                    if ($search_exact) {
                        $this->where_clauses[] = "table_{$field_definition['field_key']}.value='{$db->escape_string($search_keywords)}'";
                    } else {
                        $this->where_clauses[] = "table_{$field_definition['field_key']}.value LIKE '%{$db->escape_string($search_keywords)}%'";
                    }

                    $this->where_clauses[] = "table_{$field_definition['field_key']}.set_id='{$this->showcase_fieldset_id}'";
                }
            } elseif ($search_field === 'username' && !$search_done) {
                $this->query_tables[] = 'users user_search_data ON (entry_data.user_id = user_search_data.uid)';

                $this->query_fields[] = $field_definition['field_key'];

                if ($search_keywords) {
                    if ($search_exact) {
                        $this->where_clauses[] = "user_search_data.username='{$db->escape_string($search_keywords)}'";
                    } else {
                        $this->where_clauses[] = "user_search_data.username LIKE '%{$db->escape_string($search_keywords)}%'";
                    }
                }

                $search_done = true;
            } else {
                $this->query_fields[] = $field_definition['field_key'];

                if ($search_keywords && $search_field === $field_definition['field_key']) {
                    if ($search_exact) {
                        $this->where_clauses[] = "entry_data.{$field_definition['field_key']}='{$db->escape_string($search_keywords)}'";
                    } else {
                        $this->where_clauses[] = "entry_data.{$field_definition['field_key']} LIKE '%{$db->escape_string($search_keywords)}%'";
                    }
                }
            }

            if ($search_keywords && isset($fields_searchable[$field_definition['field_key']])) {
                foreach ($keywords as $keyword) {
                    if ($search_exact) {
                        $this->where_clauses[] = "entry_data.{$field_definition['field_key']}='{$db->escape_string($keyword)}'";
                    } else {
                        $this->where_clauses[] = "entry_data.{$field_definition['field_key']} LIKE '%{$db->escape_string($keyword)}%'";
                    }
                }
            }
        }

        if ($sort_field !== 'dateline') {
            $this->query_options['order_by'] = "{$db->escape_string($sort_field)} {$sort_direction}, is_sticky {$sort_direction}, entry_id";

            $this->query_options['order_dir'] = $sort_direction;
        } else {
            $this->query_options['order_by'] = "is_sticky {$sort_direction}, entry_id";

            $this->query_options['order_dir'] = $sort_direction;
        }

        $this->query_options['limit'] = $this->entries_per_page;

        hooksRun('entry_fetch_start', $this);

        $this->total_entries = $this->queryEntryCount($this->where_clauses, $this->query_tables);

        $this->total_pages = (int)ceil($this->total_entries / $this->entries_per_page);

        if ($this->current_page > $this->total_pages) {
            $this->setCurrentPage(self::FIRST_PAGE);
        }

        if ($this->current_page) {
            $this->query_options['limit_start'] = ($this->current_page - 1) * $this->entries_per_page;
        } else {
            $this->setCurrentPage(self::FIRST_PAGE);
        }

        hooksRun('entry_fetch_intermediate', $this);

        foreach (
            $this->queryEntries(
                $this->where_clauses,
                $this->query_fields,
                $this->query_options,
                $this->query_tables
            ) as $entry_data
        ) {
            $this->setEntry($entry_data);
        }

        hooksRun('entry_fetch_end', $this);

        return $this;
    }

    /**
     * @throws RandomException
     */
    public function insertEntry(array $entry_data, bool $is_update = false, ?int $entry_id = null): int
    {
        global $db;

        $this->entry_data = $entry_data;

        $this->entry_id = $entry_id;

        $this->insert_data = [];

        $this->is_update = $is_update;

        foreach (DATA_TABLE_STRUCTURE['myshowcase_data'] as $field_name => $field_definition) {
            if (isset($this->entry_data[$field_name])) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $this->entry_data[$field_name],
                    $field_definition['type']
                );
            } elseif (!$this->is_update) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $field_definition['default'] ?? '',
                    $field_definition['type']
                );
            }
        }

        foreach (cacheGet(CACHE_TYPE_FIELDS)[$this->config['field_set_id']] ?? [] as $field_id => $field_definition) {
            if (!isset($this->entry_data[$field_definition['field_key']]) && !$this->is_update) {
                switch ($field_definition['default_type']) {
                    case FieldDefaultTypes::AsDefined:
                        $this->entry_data[$field_definition['field_key']] = $db->escape_string(
                            $field_definition['default_value']
                        );
                        break;
                    case FieldDefaultTypes::CurrentTimestamp:
                        $this->entry_data[$field_definition['field_key']] = $db->escape_string(
                            date('Y-m-d H:i:s', TIME_NOW)
                        );
                        break;
                    case FieldDefaultTypes::UUID:
                        $this->entry_data[$field_definition['field_key']] = $db->escape_string(generateUUIDv4());
                        break;
                }
            }

            if (isset($this->entry_data[$field_definition['field_key']])) {
                if (fieldTypeMatchInt($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = (int)$this->entry_data[$field_definition['field_key']];
                } elseif (fieldTypeMatchFloat($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = (float)$this->entry_data[$field_definition['field_key']];
                } elseif (fieldTypeMatchChar($field_definition['field_type']) ||
                    fieldTypeMatchText($field_definition['field_type']) ||
                    fieldTypeMatchDateTime($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = $db->escape_string(
                        $this->entry_data[$field_definition['field_key']]
                    );
                } elseif (fieldTypeMatchBinary($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = $db->escape_binary(
                        $this->entry_data[$field_definition['field_key']]
                    );
                }
            }
        }

        hooksRun('entry_insert_update_end', $this);

        if ($this->is_update) {
            $db->update_query(
                'myshowcase_data' . $this->showcase_id,
                $this->insert_data,
                "entry_id='{$this->entry_id}'"
            );

            return $this->entry_id;
        } else {
            return (int)$db->insert_query('myshowcase_data' . $this->showcase_id, $this->insert_data);
        }
    }

    /**
     * @throws RandomException
     */
    public function updateEntry(array $entry_data, int $entry_id): int
    {
        return $this->insertEntry($entry_data, true, $entry_id);
    }

    public function deleteEntry(int $entry_id, CommentsManager $commentManager): void
    {
        global $db;

        $this->entry_id = $entry_id;

        hooksRun('entry_delete', $this);

        foreach (
            $commentManager->queryComments(["entry_id='{$this->entry_id}'"],
                'comment_id') as $commentID => $commentData
        ) {
            $commentManager->deleteComment($commentID);
        }

        foreach (
            attachmentGet(["entry_id='{$this->entry_id}'", "showcase_id='{$this->showcase_id}'"]
            ) as $attachmentID => $attachmentData
        ) {
            attachmentDelete($attachmentID);
        }

        $db->delete_query(
            'myshowcase_data' . $this->showcase_id,
            "entry_id='{$this->entry_id}'"
        );
    }
}