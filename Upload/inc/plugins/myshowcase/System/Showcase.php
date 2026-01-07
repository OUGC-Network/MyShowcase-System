<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\System;

use MyBB;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\Entry;
use MyShowcase\Enum\Routes;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Fields\FieldFactory;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Routing\Routing;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Service\EntriesManager;
use MyShowcase\Service\Render;

use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;
use function MyShowcase\Plugin\Functions\showcaseDataTableExists;
use function MyShowcase\Plugin\Functions\showcaseDefaultModeratorPermissions;

use const MyShowcase\Plugin\Core\ALL_UNLIMITED_VALUE;
use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;
use const MyShowcase\Plugin\Core\CACHE_TYPE_CONFIG;
use const MyShowcase\Plugin\Core\CACHE_TYPE_FIELDS;
use const MyShowcase\Plugin\Core\CACHE_TYPE_MODERATORS;
use const MyShowcase\Plugin\Core\CACHE_TYPE_PERMISSIONS;
use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\DEBUG;
use const MyShowcase\Plugin\Core\ERROR_TYPE_NOT_CONFIGURED;
use const MyShowcase\Plugin\Core\ERROR_TYPE_NOT_INSTALLED;
use const MyShowcase\Plugin\Core\FILTER_TYPE_USER_ID;
use const MyShowcase\Plugin\Core\GUEST_GROUP_ID;
use const MyShowcase\Plugin\Core\ORDER_DIRECTION_ASCENDING;
use const MyShowcase\Plugin\Core\ORDER_DIRECTION_DESCENDING;
use const MyShowcase\Plugin\Core\TABLES_DATA;
use const MyShowcase\Plugin\Core\VERSION_CODE;
use const MyShowcase\ROOT;

class Showcase
{
    private Collection $entries;

    public const DATA_HANDLER_METHOD_INSERT = 'insert';

    public const DATA_HANDLER_METHOD_UPDATE = 'update';

    public readonly array $user_permissions;

    public readonly int $current_user_id;

    public array $fieldset_display_fields = [];

    public Routing $routing;

    public EventDispatcher $event_dispatcher;

    public EntriesRepository $entries_repository;

    public CommentsRepository $comments_repository;

    public AttachmentsRepository $attachments_repository;

    public EntriesManager $entries_manager;

    public CommentsManager $comments_manager;

    public AttachmentsManager $attachments_manager;
    public Render $render_manager;

    public FieldFactory $field_factory;

    /**
     * Constructor of class.
     *
     * @return Showcase
     */
    public function __construct(
        public string $script_name,
        public array $fields_cache = [],
        public array $fields_searchable = [],
        public array $url_params = [],
        public string $search_keywords = '',
        public string $search_highlight = '',
        public string $search_field = '',
        public int $search_exact = 0,
        public string $sort_direction = 'asc',
        public string $sort_field = 'dateline',
        public int $current_page = 0,
        public int $filter_force_field_value = 0,


        public string $selfPhpScript = '',
        public string $dataTableName = '',
        public string $prefix = '',
        public string $cleanName = '',
        public array $parser_options = [
            'filter_badwords' => true,
            'highlight' => '',
            'nl2br' => true
        ],
        public int $errorType = 0,
        public array $entry_data = [],
        public array $error_messages = [],
        #Config
        public int $showcase_id = 0,
        public array $fieldSetFieldsIDs = [],
        public array $fieldSetEnabledFields = [],
        public array $fieldSetFieldsOrder = [],
        public bool $attachments_display_thumbnails = true,
        public bool $attachments_display_full_images = false,
        #urls
        public string $urlViewEntry = '',
        public string $urlViewComment = '',
        public array $config = [],
        public array $ur_base = [],
        public array $attachment_types = [],
        // ***/
    )
    {
        $this->entries = new Collection();

        global $db, $mybb, $cache;

        $this->current_user_id = (int)$mybb->user['uid'];

        //make sure the plugin is installed and active
        $plugin_cache = $cache->read('plugins');

        //check if the requesting file is in the cache
        foreach (cacheGet(CACHE_TYPE_CONFIG) as $showcaseID => $showcaseData) {
            $showcaseData['version'] = VERSION_CODE;

            if (DEBUG) {
                $showcaseData['version'] = TIME_NOW;
            }

            if ($showcaseData['script_name'] === $this->script_name/* &&
                $showcaseData['script_name'] === THIS_SCRIPT*/) {
                $this->config = array_merge($showcaseData, $this->config);

                $this->showcase_id = $showcaseID;

                $this->config['name_friendly'] = ucwords(strtolower($this->config['name']));

                break;
            }
        }

        if (!$this->showcase_id ||
            !$db->table_exists('myshowcase_config') ||
            !array_key_exists('myshowcase', $plugin_cache['active'])) {
            $this->config['enabled'] = false;

            $this->errorType = ERROR_TYPE_NOT_INSTALLED;

            return;
        }

        //clean the name and make it suitable for SEO
        //cleaning borrowed from Google SEO plugin
        $pattern = '!"#$%&\'( )*+,-./:;<=>?@[\]^_`{|}~';
        $pattern = preg_replace(
            "/[\\\\\\^\\-\\[\\]\\/]/u",
            "\\\\\\0",
            $pattern
        );

        // Cut off punctuation at the beginning and end.
        $this->cleanName = preg_replace(
            "/^[$pattern]+|[$pattern]+$/u",
            '',
            strtolower($this->config['name'])
        );

        // Replace middle punctuation with one separator.
        $this->cleanName = preg_replace(
            "/[$pattern]+/u",
            '-',
            $this->cleanName
        );

        //make sure the data table exists and assign table name var if it does
        if (showcaseDataTableExists($this->showcase_id)) {
            $this->dataTableName = 'myshowcase_data' . $this->showcase_id;
        }

        if (!$this->dataTableName || !$this->config['field_set_id']) {
            $this->config['enabled'] = false;

            $this->errorType = ERROR_TYPE_NOT_CONFIGURED;

            return;
        }

        //Get the basename of the calling file. This is used later for SEO support
        $this->prefix = explode('.', $this->config['script_name'])[0];

        $this->user_permissions = $this->userPermissionsGet($this->current_user_id);

        $this->parser_options = array_merge($this->parser_options, [
            'allow_html' => $this->config['parser_allow_html'],
            'allow_mycode' => $this->config['parser_allow_mycode'],
            'me_username' => $mybb->user['username'] ?? '',
            'allow_smilies' => $this->config['parser_allow_smiles']
        ]);

        if ($this->config['field_set_id']) {
            $this->fields_cache = cacheGet(CACHE_TYPE_FIELDS)[$this->config['field_set_id']] ?? [];

            foreach ($this->fields_cache as $fieldID => $fieldDefinition) {
                if (!$fieldDefinition['enabled']/* || $fieldDefinition['is_required']*/) {
                    unset($this->fields_cache[$fieldID]);

                    continue;
                }

                $this->fieldSetFieldsIDs[$fieldDefinition['field_key']] = $fieldID;

                $this->fieldSetEnabledFields[$fieldDefinition['field_key']] = $fieldDefinition['html_type'];

                if ($fieldDefinition['enable_search']) {
                    $this->fields_searchable[$fieldDefinition['field_key']] = $fieldDefinition['html_type'];
                }

                $this->fieldSetFieldsOrder[$fieldDefinition['render_order']] = $fieldID;
                //$this->fieldSetFieldsOrder[$fieldDefinition['display_order']] = $fieldDefinition['field_key'];
            }
        }

        $this->fieldset_display_fields = [
            'dateline' => '',
            //'edit_stamp' => '',
            'username' => '',
            //'user' => '',
            'views' => '',
            'comments_approved' => ''
        ];

        foreach ($this->fields_cache as $fieldID => $fieldDefinition) {
            if ($fieldDefinition['display_in_main_page']) {
                $this->fieldset_display_fields[$fieldDefinition['field_key']] = '';
            }
        }

        $this->search_field = $mybb->get_input('search_field');

        $this->sort_field = $mybb->get_input('sort');

        if ($mybb->get_input('page', MyBB::INPUT_INT) > 0) {
            $this->current_page = $mybb->get_input('page', MyBB::INPUT_INT);
        }

        $this->url_params = [];

        if ($this->current_page) {
            $this->url_params['page'] = $this->current_page;
        }

        if ($mybb->get_input('user_id', MyBB::INPUT_INT) > 0) {
            $this->url_params['user_id'] = $mybb->get_input('user_id', MyBB::INPUT_INT);
        }

        switch ($this->config['filter_force_field']) {
            case FILTER_TYPE_USER_ID:
                $this->filter_force_field_value = $this->url_params['user_id'] ?? $this->current_user_id;

                break;
        }

        if ($mybb->get_input('unapproved', MyBB::INPUT_INT)) {
            $this->url_params['unapproved'] = $mybb->get_input('unapproved', MyBB::INPUT_INT);
        }

        if (array_key_exists(
            $this->sort_field,
            $this->fieldset_display_fields
        )) {
            $this->url_params['sort_by'] = $this->sort_field;
        }

        if ($this->search_exact) {
            $this->url_params['exact_match'] = $this->search_exact;
        }

        if ($this->search_keywords) {
            $this->url_params['keywords'] = $this->search_keywords;
        }

        if (in_array($this->search_field, array_keys($this->fields_searchable))) {
            $this->url_params['search_field'] = $this->search_field;
        }

        if ($this->sort_direction) {
            $this->url_params['order_by'] = $this->sort_direction;
        }

        if ($mybb->get_input('direction') === ORDER_DIRECTION_DESCENDING) {
            $this->sort_direction = ORDER_DIRECTION_DESCENDING;
        } else {
            $this->sort_direction = ORDER_DIRECTION_ASCENDING;
        }

        if (!array_key_exists($this->sort_field, $this->fieldset_display_fields)) {
            $this->sort_field = 'dateline';
        }

        $this->url_base = !empty($mybb->settings['homeurl']) ? $mybb->settings['homeurl'] : $mybb->settings['bburl'];

        if (str_ends_with($this->url_base, '/')) {
            $this->url_base = rtrim($this->url_base, '/');
        }

        if ($this->config['enable_friendly_urls']) {
            $this->selfPhpScript = 'showcase';
        } else {
            $this->selfPhpScript = 'showcase.php';
        }

        if (str_ends_with($this->selfPhpScript, '/')) {
            $this->selfPhpScript = rtrim($this->selfPhpScript, '/');
        }

        $this->contextPath = 'showcase/';

        $mybb->binary_fields[$this->dataTableName]['ipaddress'] = true;

        //loadLanguage('myshowcase_fs' . $this->config['field_set_id']);

        $hookArguments = [
            'showcaseObject' => &$this,
        ];

        $this->config['url_root'] = $mybb->settings['bburl'];

        $this->config['url_showcase'] = $this->config['url_root'] . '/' . $this->selfPhpScript;

        $this->config['url_showcase_attachments'] = $mybb->get_asset_url($this->config['url_showcase']);

        if (isset($mybb->input['keywords'])) {
            $this->search_keywords = $mybb->get_input('keywords');
        }

        if (isset($mybb->input['highlight'])) {
            $this->search_highlight = $mybb->get_input('highlight');

            $this->parser_options['highlight'] = $this->search_highlight;
        }

        if (!empty($mybb->input['exact_match'])) {
            $this->search_exact = 1;
        }

        $this->attachment_types = cacheGet(CACHE_TYPE_ATTACHMENT_TYPES)[$this->showcase->showcase_id] ?? [];

        $hookArguments = hooksRun('system_showcase_construct_end', $hookArguments);

        $this->routing = new Routing($this);

        $this->event_dispatcher = new EventDispatcher();

        $this->entries_repository = new EntriesRepository(
            $db,
            $this->showcase_id,
            $this->event_dispatcher,
        );

        $this->comments_repository = new CommentsRepository(
            $db,
            $this->showcase_id,
            $this->event_dispatcher,
        );

        $this->attachments_repository = new AttachmentsRepository(
            $db,
            $this->showcase_id,
            $this->event_dispatcher,
        );

        $this->entries_manager = new EntriesManager(
            $this->current_user_id,
            $this->user_permissions,
            $this->entries_repository,
            $this->comments_repository,
            $this->attachments_repository,
            $this->event_dispatcher,
            $this->fields_cache,
            $this->fields_searchable,
            $this->parser_options,
            $this->config['field_set_id'],
            $this->config['filter_force_field'],
            $this->filter_force_field_value,
            current_page: $mybb->get_input('page', MyBB::INPUT_INT),
            entries_per_page: $this->config['entries_per_page'],
        );

        $this->comments_manager = new CommentsManager(
            $this->current_user_id,
            $this->user_permissions,
            $this->entries_repository,
            $this->comments_repository,
            $this->attachments_repository,
            $this->event_dispatcher,
            current_page: $mybb->get_input('page', MyBB::INPUT_INT),
            comments_per_page: $this->config['comments_per_page'],
        );

        $this->attachments_manager = new AttachmentsManager(
            $this->showcase_id,
            $this->user_permissions,
            $this->attachment_types,
            $this->entries_repository,
            $this->comments_repository,
            $this->attachments_repository,
            $this->event_dispatcher,
            $this->config['attachments_uploads_path'],
            (bool)$this->config['moderate_attachments_upload'],
            (bool)$this->config['moderate_attachments_update'],
            $this->config['attachments_thumbnails_width'],
            $this->config['attachments_thumbnails_height'],
            (string)$this->config['attachments_watermark_file'],
            $this->config['attachments_watermark_location'],
        );

        $this->field_factory = new FieldFactory(
            $this->attachments_repository,
            $this->entries_manager,
            $this->attachments_manager,
            $this->config['field_set_id'],
            $this->parser_options,
            $this->search_highlight,
        );

        $this->render_manager = new Render(
            $this->field_factory,
            $this->current_user_id,
            $this->user_permissions,
            $this->url_params,
            $this->config,
            $this->attachment_types,
            $this->routing,
            $this->entries_repository,
            $this->attachments_repository,
            $this->entries_manager,
            $this->comments_manager,
            $this->fields_cache,
            $this->attachments_display_thumbnails,
            $this->attachments_display_full_images,
        );
    }

    /**
     * get group permissions for a specific showcase
     *
     * @return array group permissions for the specific showcase
     */
    public function groupPermissionsGet(int $groupID = GUEST_GROUP_ID): array
    {
        static $showcaseGroupPermissions = [];

        if (!isset($showcaseGroupPermissions[$groupID])) {
            $showcaseGroupPermissions[$groupID] = [];

            $dataFields = TABLES_DATA['myshowcase_permissions'];

            $hookArguments = [
                'dataFields' => &$dataFields,
            ];

            // todo, similar to `admin_user_groups_edit_graph_start`
            $hookArguments = hooksRun(
                'admin_user_groups_edit_graph_start',
                $hookArguments
            );

            foreach ($dataFields as $dataFieldKey => $dataFieldData) {
                if (!isset($dataFieldData['isPermission'])) {
                    continue;
                }

                $showcaseGroupPermissions[$groupID][$dataFieldKey] = $dataFieldData['default'];
            }

            global $cache;

            $groupsCache = $cache->read('usergroups') ?? [];

            foreach ($groupsCache[$groupID] as $permissionKey => $permissionValue) {
                if (str_starts_with($permissionKey, 'myshowcase_')) {
                    $permissionName = str_replace('myshowcase_', '', $permissionKey);

                    $showcaseGroupPermissions[$groupID][$permissionName] = sanitizeTableFieldValue(
                        $permissionValue,
                        $dataFields[$permissionName]['type']
                    );
                }
            }

            $permissionsCache = cacheGet(CACHE_TYPE_PERMISSIONS);

            if (!empty($permissionsCache[$this->showcase_id][$groupID])) {
                $showcaseGroupPermissions[$groupID] = array_merge(
                    $showcaseGroupPermissions[$groupID],
                    $permissionsCache[$this->showcase_id][$groupID]
                );
            }
        }

        return $showcaseGroupPermissions[$groupID];
    }

    /**
     * get user permissions for a specific showcase
     *
     * @param int $userID The User identifier for the user to build permissions for
     * @return array user permissions for the specific showcase
     */
    public function userPermissionsGet(int $userID): array
    {
        static $userPermissions = [];

        if (!isset($userPermissions[$userID])) {
            $userPermissions[$userID] = [];

            $userData = get_user($userID);

            if (!empty($userData['uid'])) {
                $userGroupsIDs = array_filter(
                    array_map(
                        'intval',
                        explode(',', "{$userData['usergroup']},{$userData['additionalgroups']}")
                    )
                );

                $dataFields = TABLES_DATA['myshowcase_permissions'];

                foreach ($dataFields as $permissionName => $dataFieldData) {
                    if (!isset($dataFieldData['isPermission'])) {
                        continue;
                    }

                    foreach ($userGroupsIDs as $groupID) {
                        $groupPermissions = $this->groupPermissionsGet($groupID);

                        if (!empty($dataFieldData['zeroUnlimited']) && empty($groupPermissions[$permissionName]) ||
                            !empty($dataFieldData['zeroUnlimited']) && isset($userPermissions[$userID][$permissionName]) && empty($userPermissions[$userID][$permissionName])) {
                            $userPermissions[$userID][$permissionName] = ALL_UNLIMITED_VALUE;

                            continue 2;
                        }

                        if (isset($userPermissions[$userID][$permissionName])) {
                            if (!empty($dataFieldData['closest_to'])) {
                                if (
                                    abs($dataFieldData['closest_to'] - $userPermissions[$userID][$permissionName]) >
                                    abs($groupPermissions[$permissionName] - $dataFieldData['closest_to'])) {
                                    $userPermissions[$userID][$permissionName] = $groupPermissions[$permissionName];
                                }
                            } elseif (!empty($dataFieldData['lowest'])) {
                                $userPermissions[$userID][$permissionName] = min(
                                    $userPermissions[$userID][$permissionName],
                                    $groupPermissions[$permissionName]
                                );
                            } else {
                                $userPermissions[$userID][$permissionName] = max(
                                    $userPermissions[$userID][$permissionName],
                                    $groupPermissions[$permissionName]
                                );
                            }
                        } else {
                            $userPermissions[$userID][$permissionName] = $groupPermissions[$permissionName];
                        }
                    }
                }
            } else {
                $userPermissions[$userID] = $this->groupPermissionsGet();
            }

            $userPermissions[$userID] = array_merge($userPermissions[$userID], $this->moderatorPermissionsGet($userID));
        }

        return $userPermissions[$userID];
    }

    public function moderatorPermissionsGet(int $userID): array
    {
        $userData = get_user($userID);

        $userModeratorPermissions = showcaseDefaultModeratorPermissions();

        if (!empty($userData['uid'])) {
            $userGroupsIDs = array_filter(
                array_map(
                    'intval',
                    explode(',', "{$userData['usergroup']},{$userData['additionalgroups']}")
                )
            );

            if (is_member(getSetting('superModeratorGroups'), $userData)) {
                foreach ($userModeratorPermissions as $permissionKey => $permissionValue) {
                    $userModeratorPermissions[$permissionKey] = true;
                }
            }

            //get showcase moderator cache to handle additional mods/modgroups
            $moderatorsCache = cacheGet(CACHE_TYPE_MODERATORS);

            if (!empty($moderatorsCache[$this->showcase_id])) {
                foreach ($moderatorsCache[$this->showcase_id] as $moderatorPermissions) {
                    if ($moderatorPermissions['is_group'] && in_array(
                            $moderatorPermissions['user_id'],
                            $userGroupsIDs
                        ) ||
                        !$moderatorPermissions['is_group'] && (int)$moderatorPermissions['user_id'] === $userID) {
                        foreach ($userModeratorPermissions as $permissionKey => &$permissionValue) {
                            $userModeratorPermissions[$permissionKey] = !empty($moderatorPermissions[$permissionKey]) ||
                                !empty($permissionValue);
                        }
                    }
                }
            }
        }

        return $userModeratorPermissions;
    }

    /**
     * get ids from cookie inline moderation
     */
    public function inlineGetIDs(int $id = ALL_UNLIMITED_VALUE, string $type = 'showcase'): array
    {
        if ($id === ALL_UNLIMITED_VALUE) {
            $id = 'all';
        }

        global $mybb;

        $newIDs = [];

        if (!empty($id)) {
            foreach (explode('|', $mybb->cookies['inlinemod_' . $type . $id]) as $id) {
                $newIDs[] = (int)$id;
            }
        }

        return $newIDs;
    }

    /**
     * clear cookie inline moderation
     */
    public function inlineClear(int $id = ALL_UNLIMITED_VALUE, string $type = 'showcase'): bool
    {
        if ($id === ALL_UNLIMITED_VALUE) {
            $id = 'all';
        }

        my_unsetcookie('inlinemod_' . $type . $id);

        return true;
    }

    /**
     * add to cookie inline moderation
     */
    public function inlineExtend(int $id, string $type): bool
    {
        my_setcookie("inlinemod_$type.$id", '', TIME_NOW + 3600);

        return true;
    }

    public function parseMessage(string $message, array $options = []): string
    {
        return postParser()->parse_message($message, array_merge($this->parser_options, $options));
    }

    public function getBasicUrl(): string
    {
        if ($this->config['enable_friendly_urls']) {
            $scriptBasicUrl = str_replace('.php', '/', $this->script_name);
        } else {
            $scriptBasicUrl = $this->script_name;
        }

        if (str_ends_with($scriptBasicUrl, '/')) {
            return rtrim($scriptBasicUrl, '/');
        }

        return $scriptBasicUrl;
    }

    public function urlGetEntry(string $entrySlug, ?string $entrySlugCustom = '', bool $addAnchor = true): string
    {
        $options = ['entry_slug' => $entrySlug];

        if ($entrySlugCustom !== null) {
            $options['entry_slug_custom'] = $entrySlugCustom;
        }

        return url(
                Routes::EntryView,
                $options,
            )->getRelativeUrl() . ($addAnchor ? '#' . $entrySlug : '');
    }

    public function urlGetComment(string $commentSlug, bool $addAnchor = true): string
    {
        return url(Routes::Comment, ['comment_slug' => $commentSlug])->getRelativeUrl();
    }

    public function urlGetEntryComment(
        string $entrySlug,
        string $entrySlugCustom,
        string $commentSlug,
        bool $addAnchor = true
    ): string {
        return url(
                Routes::CommentView,
                ['entry_slug' => $entrySlug, 'entry_slug_custom' => $entrySlugCustom, 'comment_slug' => $commentSlug]
            )->getRelativeUrl() . ($addAnchor ? '#' . $commentSlug : '');
    }

    public function urlGenerate(string $name, array $parameters = []): string
    {
        return Routing::generateUrl($name, $parameters);
    }

    public function urlParamsAppend(array $params): void
    {
        $this->url_params = array_merge($this->url_params, $params);
    }

    public function dataHandler(Entry $entry_data, string $method = self::DATA_HANDLER_METHOD_INSERT): DataHandler
    {
        require_once MYBB_ROOT . 'inc/datahandler.php';
        require_once ROOT . '/System/DataHandler.php';

        return new DataHandler(
            $this->showcase_id,
            $entry_data,
            $this->fields_cache,
            $this->entries_repository,
            $this->comments_repository,
            $this->attachments_repository,
            $this->entries_manager,
            $this->comments_manager,
            $this->config,
            method: $method,
        );
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

    public function editorBuild(string &$code_buttons, string &$smiles_inserter, string $identifier = 'comment'): void
    {
        global $mybb;

        if (!empty($mybb->settings['bbcodeinserter']) &&
            $this->config['parser_allow_mycode'] &&
            (!$this->current_user_id || !empty($mybb->user['showcodebuttons']))) {
            $code_buttons = build_mycode_inserter(
                $identifier,
                $this->config['parser_allow_smiles']
            );

            if ($this->config['parser_allow_smiles']) {
                $smiles_inserter = build_clickable_smilies();
            }
        }
    }
}