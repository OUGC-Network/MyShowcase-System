<?php

declare(strict_types=1);

namespace myshowcase\Service;

use MyShowcase\Entity\Entry;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\formatField;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\FILTER_TYPE_USER_ID;

class EntriesManager
{
    public const STATUS_PENDING = 0;

    public const STATUS_VISIBLE = 1;

    public const STATUS_DELETED = 2;

    private const  FIRST_PAGE = 1;

    private int $total_entries = 0;

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
        'comments_approved',
        'is_closed',
        'is_sticky',
        'status',
        'edit_user_id',
        'post_hash',
        'ipaddress',
    ];

    private array $query_fields = [];

    private array $query_options = ['order_by' => 'dateline', 'order_dir' => 'asc', 'limit_start' => 0, 'limit' => 10];

    public function __construct(
        private readonly int $current_user_id,
        private readonly array $user_permissions,
        private readonly EntriesRepository $entry_repository,
        private readonly CommentsRepository $comment_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly EventDispatcher $event_dispatcher,
        private readonly array $fields_cache,
        private readonly array $fields_searchable,
        private readonly array $parser_options = [],
        private readonly int $fieldset_id,
        private readonly int $filter_force_field_id,
        private readonly int $filter_force_field_value,
        private int $current_page = self::FIRST_PAGE,
        private int $entries_per_page = 10,
    ) {
    }

    public function parseMessage(string $message, array $options = []): string
    {
        return postParser()->parse_message($message, array_merge($this->parser_options, $options));
    }

    public function setCurrentPage(int $page): void
    {
        if ($page > 0) {
            $this->current_page = $page;
        }
    }

    public function getTotalEntries(): int
    {
        return $this->total_entries;
    }

    public function getCurrentPage(): int
    {
        return $this->current_page;
    }

    /**
     * @throws RandomException
     */
    public function slugGenerate(?int $length = null): string
    {
        if (empty($length)) {
            $length = getSetting('slugLength');
        }

        global $db;

        $generated_slug = '';

        $unique_found = false;

        while (!$unique_found) {
            $generated_slug = my_strtolower(bin2hex(random_bytes($length)));

            $unique_found = !$this->entry_repository->queryEntry(["entry_slug='{$db->escape_string($generated_slug)}'"]
            );
        }

        return $generated_slug;
    }

    public function fetchEntries(
        array $url_params = [],
        string $search_keywords = '',
        string $search_field = '',
        int $search_exact = 0,
        string $sort_direction = 'asc',
        string $sort_field = 'dateline',
        array $where_clauses = []
    ): array {
        $this->where_clauses = $where_clauses;

        if (!$this->user_permissions[ModeratorPermissions::CanManageEntries]) {
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

        if (!empty($url_params['user_id'])) {
            $this->where_clauses[] = "entry_data.user_id='{$url_params['user_id']}'";
        }

        switch ($this->filter_force_field_id) {
            case FILTER_TYPE_USER_ID:
                $this->where_clauses[] = "entry_data.user_id='{$this->filter_force_field_value}'";

                break;
        }

        //setup joins for query and build where clause based on search_field terms

        $this->query_tables = ['users user_data ON (user_data.uid = entry_data.user_id)'];

        $search_done = false;

        $keywords = explode(' ', $search_keywords);

        $this->query_fields = array_merge(
            array_map(function (string $field_name): string {
                return str_starts_with($field_name, 'entry_data.') ? $field_name : 'entry_data.' . $field_name;
            }, $this->structure_fields),
            [
                'user_data.username',
                'user_data.usergroup',
                'user_data.displaygroup'
            ]
        );

        global $db;

        foreach ($this->fields_cache as $field_id => $field_definition) {
            if ($field_definition['html_type'] === FieldHtmlTypes::Select || $field_definition['html_type'] === FieldHtmlTypes::Radio) {
                $this->query_tables[] = "myshowcase_field_data table_{$field_definition['field_key']} ON (table_{$field_definition['field_key']}.field_data_id = entry_data.{$field_definition['field_key']} AND table_{$field_definition['field_key']}.field_id = '{$field_definition['field_id']}')";

                $this->query_fields[] = "table_{$field_definition['field_key']}.display_style AS {$field_definition['field_key']}";

                if ($search_keywords && $search_field === $field_definition['field_key']) {
                    if ($search_exact) {
                        $this->where_clauses[] = "table_{$field_definition['field_key']}.value='{$db->escape_string($search_keywords)}'";
                    } else {
                        $this->where_clauses[] = "table_{$field_definition['field_key']}.value LIKE '%{$db->escape_string($search_keywords)}%'";
                    }

                    $this->where_clauses[] = "table_{$field_definition['field_key']}.set_id='{$this->fieldset_id}'";
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

            if ($search_keywords && isset($this->fields_searchable[$field_definition['field_key']])) {
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

        if ($this->entries_per_page === 1) {
            $this->total_entries = 1;
        } else {
            $this->total_entries = $this->entry_repository->queryEntryCount($this->where_clauses, $this->query_tables);

            if ($this->current_page > ceil($this->total_entries / $this->entries_per_page)) {
                $this->setCurrentPage(self::FIRST_PAGE);
            }

            if ($this->current_page) {
                $this->query_options['limit_start'] = ($this->current_page - 1) * $this->entries_per_page;
            } else {
                $this->setCurrentPage(self::FIRST_PAGE);
            }
        }

        hooksRun('entry_fetch_intermediate', $this);

        $entry_objects = [];

        foreach (
            $this->entry_repository->queryEntries(
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

    public function fetchEntry(array $where_clauses = []): array
    {
        $this->entries_per_page = 1;

        return $this->fetchEntries(where_clauses: $where_clauses)[0] ?? [];
    }

    public function buildSubject(Entry $entry): string
    {
        global $lang;

        $subject_elements = [];

        foreach ($this->fields_cache as $field_id => $field_definition) {
            if (!$field_definition['enable_subject'] ||
                ($field_value = $entry->getExtraData($field_definition['field_key'] ?? '')) === false) {
                continue;
            }

            if (empty($field_definition['parse'])) {
                // todo, remove this legacy updating the database and updating the format field to TINYINT
                formatField((int)$field_definition['format'], $field_value);
            } else {
                $field_value = $this->parseMessage((string)$field_value, [
                    'allow_html' => $field_definition['parser_allow_html'],
                    'allow_mycode' => $field_definition['parser_allow_mycode'],
                    'allow_smilies' => $field_definition['parser_allow_smiles'],
                    'allow_imgcode' => $field_definition['parser_allow_image_code'],
                    'allow_videocode' => $field_definition['parser_allow_video_code'],
                ]);
            }

            if (!empty($field_value)) {
                $subject_elements[] = $field_value;
            }
        }

        $subject_elements = trim(implode(' ', $subject_elements));

        if (empty($subject_elements)) {
            $subject_elements = $lang->sprintf($lang->myshowcase_viewing_user, $entry->getExtraData('username'));
        }

        return $subject_elements;
    }
}