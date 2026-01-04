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

use JetBrains\PhpStorm\NoReturn;
use MyBB;
use MyShowcase\Plugin\RouterUrls;

use function MyShowcase\Plugin\Functions\attachmentDelete;
use function MyShowcase\Plugin\Functions\attachmentGet;
use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\commentsGet;
use function MyShowcase\Plugin\Functions\commentsDelete;
use function MyShowcase\Plugin\Functions\dataTableStructureGet;
use function MyShowcase\Plugin\Functions\entryDelete;
use function MyShowcase\Plugin\Functions\entryInsert;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;
use function MyShowcase\Plugin\Functions\renderGetObject;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;
use function MyShowcase\Plugin\Functions\showcaseDataTableExists;
use function MyShowcase\Plugin\Functions\entryUpdate;
use function MyShowcase\Plugin\Functions\showcaseDefaultModeratorPermissions;

use const MyShowcase\Plugin\Core\ALL_UNLIMITED_VALUE;
use const MyShowcase\Plugin\Core\CACHE_TYPE_CONFIG;
use const MyShowcase\Plugin\Core\CACHE_TYPE_FIELDS;
use const MyShowcase\Plugin\Core\CACHE_TYPE_MODERATORS;
use const MyShowcase\Plugin\Core\CACHE_TYPE_PERMISSIONS;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_SOFT_DELETED;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_VISIBLE;
use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\DEBUG;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\ERROR_TYPE_NOT_CONFIGURED;
use const MyShowcase\Plugin\Core\ERROR_TYPE_NOT_INSTALLED;
use const MyShowcase\Plugin\Core\GUEST_GROUP_ID;
use const MyShowcase\Plugin\Core\ORDER_DIRECTION_ASCENDING;
use const MyShowcase\Plugin\Core\ORDER_DIRECTION_DESCENDING;
use const MyShowcase\Plugin\Core\TABLES_DATA;
use const MyShowcase\Plugin\Core\VERSION_CODE;

class Showcase
{
    public array $fieldset_display_fields = [];
    public readonly array $user_permissions;
    private array $entry_objects = [];
    private array $attachment_objects = [];

    /**
     * Constructor of class.
     *
     * @return Showcase
     */
    public function __construct(
        public string $script_name,
        public ?Render &$renderObject = null,
        public string $selfPhpScript = '',
        public string $dataTableName = '',
        public string $prefix = '',
        public string $cleanName = '',
        public array $userPermissions = [],
        public array $parserOptions = [
            'filter_badwords' => true,
            'highlight' => '',
            'nl2br' => true
        ],
        public int $errorType = 0,
        public string $showcaseUrl = '',
        public array $entry_data = [],
        public int $entry_id = 0,
        public int $entryUserID = 0,
        public array $fieldset_fields_display_fields = [],
        public string $searchField = '',
        public string $sort_field = '',
        public string $sort_direction = '',
        public array $errorMessages = [],
        #Config
        public int $showcase_id = 0,
        public string $name = '',
        public array $fieldSetCache = [],
        public array $fieldSetFieldsIDs = [],
        public array $fieldSetEnabledFields = [],
        public array $fieldSetSearchableFields = [],
        public array $fieldSetFieldsOrder = [],
        public bool $attachmentsDisplayThumbnails = true,
        public bool $attachmentsDisplayFullSizeImage = false,
        public string $maximumAvatarSize = '55x55',
        #urls
        public string $urlBase = '',
        public string $urlMain = '',
        public string $urlMainUnapproved = '',
        public string $urlPaged = '',
        public string $urlViewEntry = '',
        public string $urlViewEntryPage = '',
        public string $urlViewComment = '',
        public string $urlCreateEntry = '',
        public string $urlUpdateEntry = '',
        public string $urlApproveEntry = '',
        public string $urlUnapproveEntry = '',
        public string $urlSoftDeleteEntry = '',
        public string $urlRestoreEntry = '',
        public string $urlDeleteEntry = '',
        public string $urlCreateComment = '',
        public string $urlUpdateComment = '',
        public string $urlApproveComment = '',
        public string $urlUnapproveComment = '',
        public string $urlRestoreComment = '',
        public string $urlSoftDeleteComment = '',
        public string $urlDeleteComment = '',
        public string $urlViewAttachment = '',
        public string $urlViewAttachmentItem = '',
        public array $config = [],
        public array $urlParams = [],
        public array $whereClauses = [],
        // ***/
        public array $fields = [],
        public int $current_user_id = 0,
    ) {
        global $db, $mybb, $cache;

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

        $current_user_id = (int)$mybb->user['uid'];

        $this->user_permissions = $this->userPermissionsGet($current_user_id);

        $this->parserOptions = array_merge($this->parserOptions, [
            'allow_html' => $this->config['parser_allow_html'],
            'allow_mycode' => $this->config['parser_allow_mycode'],
            'me_username' => $mybb->user['username'] ?? '',
            'allow_smilies' => $this->config['parser_allow_smiles']
        ]);

        $this->entryUserID = $current_user_id;

        // todo, probably unnecessary since entryDataSet() already sets the entryUserID
        // probably also ignores moderator permissions
        if (!empty($mybb->input['entryUserID'])) {
            $this->entryUserID = $mybb->get_input('entryUserID', MyBB::INPUT_INT);
        }

        if ($this->config['field_set_id']) {
            $this->fieldSetCache = cacheGet(CACHE_TYPE_FIELDS)[$this->config['field_set_id']] ?? [];

            foreach ($this->fieldSetCache as $fieldID => $fieldDefinition) {
                if (!$fieldDefinition['enabled']/* || $fieldDefinition['is_required']*/) {
                    unset($this->fieldSetCache[$fieldID]);

                    continue;
                }

                $this->fieldSetFieldsIDs[$fieldDefinition['field_key']] = $fieldID;

                $this->fieldSetEnabledFields[$fieldDefinition['field_key']] = $fieldDefinition['html_type'];

                if ($fieldDefinition['enable_search']) {
                    $this->fieldSetSearchableFields[$fieldDefinition['field_key']] = $fieldDefinition['html_type'];
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
            'comments' => ''
        ];

        foreach ($this->fieldSetCache as $fieldID => $fieldDefinition) {
            if ($fieldDefinition['display_in_main_page']) {
                $this->fieldset_display_fields[$fieldDefinition['field_key']] = '';
            }
        }

        $this->searchField = $mybb->get_input('search_field');

        $this->sort_field = $mybb->get_input('sort');

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

        $this->renderObject = renderGetObject($this);

        //loadLanguage('myshowcase_fs' . $this->config['field_set_id']);

        $hookArguments = [
            'showcaseObject' => &$this,
        ];

        $this->config['url_root'] = $mybb->settings['bburl'];

        $this->config['url_showcase'] = $this->config['url_root'] . '/' . $this->selfPhpScript;

        $this->config['url_showcase_attachments'] = $mybb->get_asset_url($this->config['url_showcase']);

        RouterUrls::init($this);

        $hookArguments = hooksRun('system_showcase_construct_end', $hookArguments);

        return $this;
    }

    public function urlBuild(
        string $url,
        $entrySlug = 0,
        int $commentID = 0,
        int $attachmentID = 0,
        int|string $currentPage = 0
    ): string {
        $this->renderObject->current_page = $currentPage;

        return 'showcase.php?route=/' . str_replace(
                ['{entry_slug_custom}', '{comment_id}', '{current_page}'],
                [$entrySlug, $commentID, $this->renderObject->current_page],
                $url
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

    public function showcaseDataDelete(int $entryID): void
    {
        entryDelete($this->showcase_id, $entryID);
    }

    /**
     * delete attachments from a showcase
     */
    public function attachmentsDelete(int $entryID): bool
    {
        foreach (
            attachmentGet(
                ["entry_id='{$entryID}'", "showcase_id='{$this->showcase_id}'"]
            ) as $attachmentID => $attachmentData
        ) {
            attachmentDelete($attachmentID);
        }

        return true;
    }

    /**
     * delete a comment
     */
    public function commentsDelete(int $entryID): void
    {
        foreach (
            commentsGet(["showcase_id='{$this->showcase_id}'", "entry_id='{$entryID}'"]) as $commentID => $commentData
        ) {
            commentsDelete($commentID);
        }
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

    public function parseMessage(string $message, array $parserOptions = []): string
    {
        return postParser()->parse_message(
            $message,
            array_merge($this->parserOptions, $parserOptions)
        );
    }

    public function attachmentsParseObjects(): Showcase
    {
        foreach ($this->attachment_objects as $entry_id_hash => &$attachmentObjects) {
            foreach ($attachmentObjects as $attachment_id => &$attachment_data) {
                $entry_exist = !empty($entry_id_hash) && isset($this->entry_objects[$entry_id_hash]);

                $attachment_data = array_merge(
                    $attachment_data,
                    [
                        'file_name' => $attachment_data['file_name'],
                        'file_size' => get_friendly_size($attachment_data['file_size']),
                        'attachment_name' => $attachment_data['attachment_name'],
                        'downloads' => (int)$attachment_data['downloads'],
                        'dateline' => (int)$attachment_data['dateline'],
                        'thumbnail_name' => $attachment_data['thumbnail_name'],
                        'is_image' => str_starts_with($attachment_data['mime_type'], 'image/'),
                        'icon' => get_attachment_icon($attachment_data['attachment_name']),
                        'has_thumbnail' => !empty($attachment_data['thumbnail_name']) && $this->attachmentsDisplayThumbnails,
                        'url' => $entry_exist ? (empty($this->entry_objects[$attachment_data['entry_id']]['entry_slug']) ? _dump(
                                $entry_exist,
                                $this->entry_objects[$attachment_data['entry_id']]
                            ) : '') . $this->urlGenerate(
                                RouterUrls::AttachmentView,
                                [
                                    'entry_slug' => $this->entry_objects[$attachment_data['entry_id']]['entry_slug'],
                                    'entry_slug_custom' => $this->entry_objects[$attachment_data['entry_id']]['entry_slug_custom'],
                                    'attachment_hash' => $attachment_data['attachment_hash'],
                                ],
                            ) : $this->urlGenerate(
                            RouterUrls::AttachmentViewFromPostHash,
                            [
                                'post_hash' => $this->entry_objects[$attachment_data['entry_id']]['post_hash'],
                                'attachment_hash' => $attachment_data['attachment_hash']
                            ],
                        ),
                    ]
                );

                if ($attachment_data['has_thumbnail']) {
                    $attachment_data['url_thumbnail'] = $entry_exist ? $this->urlGenerate(
                        RouterUrls::ThumbnailView,
                        [
                            'entry_slug' => $this->entry_objects[$attachment_data['entry_id']]['entry_slug'],
                            'entry_slug_custom' => $this->entry_objects[$attachment_data['entry_id']]['entry_slug_custom'],
                            'attachment_hash' => $attachment_data['attachment_hash']
                        ]
                    ) : $this->urlGenerate(
                        RouterUrls::ThumbnailView,
                        [
                            'entry_slug' => $this->entry_objects[$attachment_data['entry_id']]['entry_slug'],
                            'entry_slug_custom' => $this->entry_objects[$attachment_data['entry_id']]['entry_slug_custom'],
                            'attachment_hash' => $attachment_data['attachment_hash']
                        ]
                    );
                } else {
                    $attachment_data['url_thumbnail'] = $attachment_data['url'];
                }
            }
        }

        return $this;
    }

    public function attachmentsGetFromObject(?int $field_id = null): array
    {
        $where_clauses = ["showcase_id='{$this->showcase_id}'"];

        if ($this->entry_data['entry_id']) {
            $where_clauses[] = "entry_id='{$this->entry_data['entry_id']}'";
        } else {
            $where_clauses[] = "post_hash='{$this->entry_data['post_hash']}'";
        }

        if (!empty($field_id)) {
            $where_clauses[] = "field_id='{$field_id}'";
        }

        $this->attachmentsAppend(
            attachmentGet(
                $where_clauses,
                [
                    'entry_id',
                    'post_hash',
                    'attachment_hash',
                    'file_name',
                    'mime_type',
                    'file_size',
                    'attachment_name',
                    'downloads',
                    'dateline',
                    'status',
                    'thumbnail_name',
                    'thumbnail_dimensions',
                    'field_id',
                ]
            )
        )
            ->attachmentsParseObjects();

        if ($this->entry_data['entry_id']) {
            return $this->attachmentsGetByEntryID($this->entry_data['entry_id']);
        } else {
            return $this->attachmentsGetByPostHash($this->entry_data['post_hash']);
        }
    }

    public function attachmentsGetFromEntry(): array
    {
        $attachment_objects = [];

        foreach ($this->attachmentsGetFromObject() as $attachment_id => $attachment_data) {
            if (($attachment_data['entry_id'] === $this->entry_data['entry_id'] || $attachment_data['post_hash'] === $this->entry_data['post_hash']) && empty($attachment_data['field_id'])) {
                $attachment_objects[$attachment_data['attachment_id']] = $attachment_data;
            }
        }

        return $attachment_objects;
    }

    public function attachmentsGetFromField(int $field_id): array
    {
        $attachment_objects = [];

        foreach ($this->attachmentsGetFromObject($field_id) as $entry_id_hash => $attachment_data) {
            if ($attachment_data['field_id'] === $field_id) {
                $attachment_objects[$attachment_data['attachment_id']] = $attachment_data;
            }
        }

        return $attachment_objects;
    }

    public function attachmentsRemove(array $whereClauses): void
    {
        $whereClauses[] = "showcase_id='{$this->showcase_id}'";

        $attachmentObjects = attachmentGet($whereClauses, ['attachment_name', 'thumbnail_name', 'status']);

        $attachmentObjects = hooksRun('remove_attachment_do_delete', $attachmentObjects);

        foreach ($attachmentObjects as $attachmentID => $attachmentData) {
            attachmentDelete($attachmentID);

            if (file_exists($this->config['attachments_uploads_path'] . '/' . $attachmentData['attachment_name'])) {
                unlink($this->config['attachments_uploads_path'] . '/' . $attachmentData['attachment_name']);
            }

            if (!empty($attachmentData['thumbnail_name'])) {
                if (file_exists($this->config['attachments_uploads_path'] . '/' . $attachmentData['thumbnail_name'])) {
                    unlink($this->config['attachments_uploads_path'] . '/' . $attachmentData['thumbnail_name']);
                }
            }

            $dateDirectory = explode('/', $attachmentData['attachment_name']);

            if (!empty($dateDirectory[0]) && is_dir(
                    $this->config['attachments_uploads_path'] . '/' . $dateDirectory[0]
                )) {
                rmdir($this->config['attachments_uploads_path'] . '/' . $dateDirectory[0]);
            }
        }
    }

    public function entriesGetUnapprovedCount(): int
    {
        global $db;

        $entryStatusUnapproved = ENTRY_STATUS_PENDING_APPROVAL;

        $query = $db->simple_select(
            $this->dataTableName,
            'COUNT(entry_id) AS totalUnapprovedEntries',
            "approved='{$entryStatusUnapproved}'",
            [
                'group_by' => 'entry_id'
            ]
        );

        return (int)$db->fetch_field($query, 'totalUnapprovedEntries');
    }

    public function dataGet(
        array $whereClauses,
        array $queryFields = [],
        array $queryOptions = [],
        array $queryTables = []
    ): array {
        global $db;

        $queryTables = array_merge(["{$this->dataTableName} entryData"], $queryTables);

        $query = $db->simple_select(
            implode(" LEFT JOIN {$db->table_prefix}", $queryTables),
            implode(',', array_merge(['entry_id'], $queryFields)),
            implode(' AND ', $whereClauses),
            $queryOptions
        );

        if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
            return (array)$db->fetch_array($query);
        }

        $entriesObjects = [];

        while ($fieldValueData = $db->fetch_array($query)) {
            $entriesObjects[(int)$fieldValueData['entry_id']] = $fieldValueData;
        }

        return $entriesObjects;
    }

    public function dataInsert(
        array $insertData
    ): int {
        return entryInsert($this->showcase_id, $insertData);
    }

    public function dataUpdate(
        array $updateData
    ): int {
        return entryUpdate(
            $this->showcase_id,
            $updateData,
            $this->entry_id,
        );
    }

    public function commentGet(
        array $whereClauses,
        array $queryFields = [],
        array $queryOptions = [],
    ): array {
        $whereClauses[] = "showcase_id='{$this->showcase_id}'";

        return commentsGet(
            $whereClauses,
            $queryFields,
            $queryOptions
        );
    }

    public function entryDataSet(array $entryData): Showcase
    {
        $this->entry_data = $entryData;

        if (isset($this->entry_data['entry_id'])) {
            $this->entry_id = (int)$this->entry_data['entry_id'];
        }

        if (isset($this->entry_data['user_id'])) {
            $this->entryUserID = (int)$this->entry_data['user_id'];
        }

        if (isset($this->entry_data['post_hash'])) {
            //$this->post_hash = (string)$this->entryData['post_hash'];
        }

        return $this;
    }

    public function urlSet(string $showcaseUrl): void
    {
        $this->showcaseUrl = $showcaseUrl;
    }

    public function entryDelete(): void
    {
        $this->attachmentsDelete($this->entry_id);

        $this->commentsDelete($this->entry_id);

        $this->showcaseDataDelete($this->entry_id);
    }

    public function commentDelete(int $commentID): void
    {
        commentsDelete($commentID);
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
                RouterUrls::EntryView,
                $options,
            )->getRelativeUrl() . ($addAnchor ? '#' . $entrySlug : '');
    }

    public function urlGetComment(string $commentSlug, bool $addAnchor = true): string
    {
        return url(RouterUrls::Comment, ['comment_slug' => $commentSlug])->getRelativeUrl();
    }

    public function urlGetEntryComment(
        string $entrySlug,
        string $entrySlugCustom,
        string $commentSlug,
        bool $addAnchor = true
    ): string {
        return url(
                RouterUrls::CommentView,
                ['entry_slug' => $entrySlug, 'entry_slug_custom' => $entrySlugCustom, 'comment_slug' => $commentSlug]
            )->getRelativeUrl() . ($addAnchor ? '#' . $commentSlug : '');
    }

    public function urlGetCommentApprove(string $entrySlug, string $entrySlugCustom, string $commentSlug): string
    {
        return url(
            RouterUrls::CommentApprove,
            ['entry_slug' => $entrySlug, 'entry_slug_custom' => $entrySlugCustom, 'comment_slug' => $commentSlug]
        )->getRelativeUrl();
    }

    public function whereClauseStatusComment(): string
    {
        global $mybb;

        $current_user_id = (int)$mybb->user['uid'];

        $statusVisible = COMMENT_STATUS_VISIBLE;

        $statusPendingApproval = COMMENT_STATUS_PENDING_APPROVAL;

        $statusSoftDeleted = COMMENT_STATUS_SOFT_DELETED;

        $whereClausesStatus = [
            "status='{$statusVisible}'",
        ];

        if ($this->user_permissions[ModeratorPermissions::CanManageComments]) {
            $whereClausesStatus[] = "status='{$statusPendingApproval}'";

            $whereClausesStatus[] = "status='{$statusSoftDeleted}'";
        } else {
            $whereClausesStatus[] = "(user_id='{$current_user_id}' AND status='{$statusPendingApproval}')";
        }

        $whereClausesStatus = implode(' OR ', $whereClausesStatus);

        return "({$whereClausesStatus})";
    }

    public function entriesAppend(array $entries_objects): Showcase
    {
        foreach ($entries_objects as $entry_data) {
            $entry_id = !empty($entry_data['entry_id']) ? $entry_data['entry_id'] : $entry_data['post_hah'];

            if ($entry_id) {
                $this->entry_objects[$entry_id] = $entry_data;
            }
        }

        return $this;
    }

    public function entriesGetIDs(): array
    {
        return array_keys($this->entry_objects);
    }

    public function entriesGetObjects(): array
    {
        return $this->entry_objects;
    }

    public function attachmentsAppend(array $attachment_objects): Showcase
    {
        foreach ($attachment_objects as $attachment_id => $attachment_data) {
            $entry_id = !empty($attachment_data['entry_id']) ? $attachment_data['entry_id'] : $attachment_data['post_hash'];

            if ($entry_id) {
                $this->attachment_objects[$entry_id][$attachment_id] = $attachment_data;
            }
        }

        return $this;
    }

    public function attachmentsGetByEntryID(int $entry_id): array
    {
        return $this->attachment_objects[$entry_id] ?? [];
    }

    public function attachmentsGetByPostHash(string $post_hash): array
    {
        return $this->attachment_objects[$post_hash] ?? [];
    }

    public function attachmentGetByHash(string $attachment_hash): array|bool
    {
        foreach ($this->attachment_objects as $entry_id => $attachment_objects) {
            foreach ($attachment_objects as $attachment_id => $attachment_data) {
                if ($attachment_data['attachment_hash'] === $attachment_hash) {
                    return $attachment_data;
                }
            }
        }

        return false;
    }

    public function setEntry(
        string $entrySlug,
        bool $loadFields = false
    ): void {
        global $db;

        $dataTableStructure = dataTableStructureGet($this->showcase_id);

        $whereClauses = ["entryData.entry_slug='{$db->escape_string($entrySlug)}'"];

        $queryFields = array_merge(
            array_map(function (string $fieldName): string {
                return 'entryData.' . $fieldName;
            }, array_keys(DATA_TABLE_STRUCTURE['myshowcase_data'])),
            [
                'user_data.username',
            ]
        );

        $queryTables = ['users user_data ON (user_data.uid=entryData.user_id)'];

        if ($loadFields) {
            foreach ($this->fieldSetCache as $fieldID => $fieldDefinition) {
                $field_key = $fieldDefinition['field_key'];

                $htmlType = $fieldDefinition['html_type'];

                $fieldID = (int)$fieldDefinition['field_id'];

                if (false and ($htmlType === FieldHtmlTypes::Select || $htmlType === FieldHtmlTypes::Radio)) {
                    $queryTables[] = "myshowcase_field_data table_{$field_key} ON (table_{$field_key}.field_data_id=entryData.{$field_key} AND table_{$field_key}.field_id='{$fieldID}')";

                    //$queryFields[] = "table_{$field_key}.value AS {$field_key}";

                    $queryFields[] = "table_{$field_key}.display_style AS {$field_key}";

                    // todo, I don't understand the purpose of this now
                    // the condition after OR seems to fix it for now
                    //$whereClauses[] = "(table_{$field_key}.set_id='{$this->config['field_set_id']}' OR entryData.{$field_key}=0)";
                } else {
                    $queryFields[] = $field_key;
                }
            }
        }

        $entry_data = $this->dataGet($whereClauses, $queryFields, ['limit' => 1], $queryTables);

        $this->entryDataSet($entry_data)
            ->entriesAppend([$entry_data['entry_id'] => $entry_data]);
    }

    public function urlGenerate(string $name, array $parameters = []): string
    {
        return RouterUrls::generateUrl($name, $parameters);
    }

    public function urlParamsSet(array $urlParams): void
    {
        $this->urlParams = $urlParams;
    }

    public function urlParamsAppend(array $urlParams): void
    {
        $this->urlParams = array_merge($this->urlParams, $urlParams);
    }
}