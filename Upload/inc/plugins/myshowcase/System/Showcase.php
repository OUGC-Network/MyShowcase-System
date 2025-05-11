<?php
/**
 * MyShowcase Plugin for MyBB - MyShowcase Class
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: http://www.communityplugins.com
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\class_showcase.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\System;

use MyBB;

use function MyShowcase\Core\attachmentDelete;
use function MyShowcase\Core\attachmentGet;
use function MyShowcase\Core\cacheGet;
use function MyShowcase\Core\commentsGet;
use function MyShowcase\Core\commentsDelete;
use function MyShowcase\Core\entryDataInsert;
use function MyShowcase\Core\getSetting;
use function MyShowcase\Core\getTemplate;
use function MyShowcase\Core\hooksRun;
use function MyShowcase\Core\postParser;
use function MyShowcase\Core\sanitizeTableFieldValue;
use function MyShowcase\Core\showcaseDataTableExists;
use function MyShowcase\Core\entryDataUpdate;
use function MyShowcase\Core\showcaseDefaultModeratorPermissions;

use const MyShowcase\Core\ALL_UNLIMITED_VALUE;
use const MyShowcase\Core\CACHE_TYPE_CONFIG;
use const MyShowcase\Core\CACHE_TYPE_FIELDS;
use const MyShowcase\Core\CACHE_TYPE_MODERATORS;
use const MyShowcase\Core\CACHE_TYPE_PERMISSIONS;
use const MyShowcase\Core\ENTRY_STATUS_PENDING_APPROVAL;
use const MyShowcase\Core\ERROR_TYPE_NOT_CONFIGURED;
use const MyShowcase\Core\ERROR_TYPE_NOT_INSTALLED;
use const MyShowcase\Core\FILTER_TYPE_USER_ID;
use const MyShowcase\Core\GUEST_GROUP_ID;
use const MyShowcase\Core\ORDER_DIRECTION_ASCENDING;
use const MyShowcase\Core\ORDER_DIRECTION_DESCENDING;
use const MyShowcase\Core\TABLES_DATA;

class Showcase
{
    /**
     * Constructor of class.
     *
     * @return Showcase
     */
    public function __construct(
        public string $scriptName,
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
        public array $entryData = [],
        public int $entryID = 0,
        public int $entryUserID = 0,
        public array $fieldSetFieldsDisplayFields = [],
        public string $searchField = '',
        public string $sortByField = '',
        public string $orderBy = '',
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
    ) {
        global $db, $mybb, $cache;

        //make sure plugin is installed and active
        $plugin_cache = $cache->read('plugins');

        //check if the requesting file is in the cache
        foreach (cacheGet(CACHE_TYPE_CONFIG) as $showcaseID => $showcaseData) {
            if ($showcaseData['script_name'] === $this->scriptName/* &&
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

        // Cut off punctuation at beginning and end.
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

        //make sure data table exists and assign table name var if it does
        if (showcaseDataTableExists($this->showcase_id)) {
            $this->dataTableName = 'myshowcase_data' . $this->showcase_id;
        }

        if (!$this->dataTableName || !$this->config['field_set_id']) {
            $this->config['enabled'] = false;

            $this->errorType = ERROR_TYPE_NOT_CONFIGURED;

            return;
        }

        //get basename of the calling file. This is used later for SEO support
        $this->prefix = explode('.', $this->config['script_name'])[0];

        $currentUserID = (int)$mybb->user['uid'];

        $this->userPermissions = $this->userPermissionsGet($currentUserID);

        $this->parserOptions = array_merge($this->parserOptions, [
            'allow_html' => $this->config['parser_allow_html'],
            'allow_mycode' => $this->config['parser_allow_mycode'],
            'me_username' => $mybb->user['username'] ?? '',
            'allow_smilies' => $this->config['parser_allow_smiles']
        ]);

        $this->entryUserID = $currentUserID;

        // todo, probably unnecessary since entryDataSet() already sets the entryUserID
        // probably also ignores moderator permissions
        if (!empty($mybb->input['entryUserID'])) {
            $this->entryUserID = $mybb->get_input('entryUserID', MyBB::INPUT_INT);
        }

        if ($this->config['field_set_id']) {
            $this->fieldSetCache = cacheGet(CACHE_TYPE_FIELDS)[$this->config['field_set_id']] ?? [];

            foreach ($this->fieldSetCache as $fieldID => $fieldData) {
                if (!$fieldData['enabled']/* || $fieldData['is_required']*/) {
                    unset($this->fieldSetCache[$fieldID]);

                    continue;
                }

                $this->fieldSetFieldsIDs[$fieldData['field_key']] = $fieldID;

                $this->fieldSetEnabledFields[$fieldData['field_key']] = $fieldData['html_type'];

                if ($fieldData['enable_search']) {
                    $this->fieldSetSearchableFields[$fieldData['field_key']] = $fieldData['html_type'];
                }

                $this->fieldSetFieldsOrder[$fieldData['render_order']] = $fieldID;
                //$this->fieldSetFieldsOrder[$fieldData['display_order']] = $fieldData['field_key'];
            }
        }

        $this->fieldSetFieldsDisplayFields = [
            'dateline' => '',
            //'edit_stamp' => '',
            'username' => '',
            'user' => '',
            'views' => '',
            'comments' => ''
        ];

        foreach ($this->fieldSetFieldsOrder as $renderOrder => $fieldID) {
            $fieldKey = $this->fieldSetCache[$fieldID]['field_key'];

            $this->fieldSetFieldsDisplayFields[$fieldKey] = '';
        }

        $this->searchField = $mybb->get_input('search_field');

        $this->sortByField = $mybb->get_input('sort_by');

        if ($mybb->get_input('order_by') === ORDER_DIRECTION_ASCENDING) {
            $this->orderBy = ORDER_DIRECTION_ASCENDING;
        } elseif ($mybb->get_input('order_by') === ORDER_DIRECTION_DESCENDING) {
            $this->orderBy = ORDER_DIRECTION_DESCENDING;
        }

        if (!array_key_exists($this->sortByField, $this->fieldSetFieldsDisplayFields)) {
            $this->sortByField = 'dateline';
        }

        $this->urlBase = $mybb->settings['homeurl'];

        if (str_ends_with($this->urlBase, '/')) {
            $this->urlBase = rtrim($this->urlBase, '/');
        }

        if ($this->config['enable_friendly_urls']) {
            $this->selfPhpScript = str_replace('.php', '/', $_SERVER['PHP_SELF']);
        } else {
            $this->selfPhpScript = $_SERVER['PHP_SELF'];
        }

        if (str_ends_with($this->selfPhpScript, '/')) {
            $this->selfPhpScript = rtrim($this->selfPhpScript, '/');
        }

        if ($this->config['enable_friendly_urls']) {
            //$showcase_name = strtolower($showcaseObject->config['name']);
            $this->urlMain = $this->cleanName . '.html';

            $this->urlPaged = $this->cleanName . '-page-{page}.html';

            $this->urlViewEntry = $this->cleanName . '-view-{entry_id}.html';

            $this->urlViewComment = $this->cleanName . '-view-{entry_id}-last-comment.html';

            $this->urlCreateEntry = $this->cleanName . '-new.html';

            $this->urlViewAttachment = $this->cleanName . '-attachment-{attachment_id}.html';

            $this->urlViewAttachmentItem = $this->cleanName . '-item-{attachment_id}.php';
        } else {
            $this->urlMain = $this->selfPhpScript;

            $this->urlMainUnapproved = $this->selfPhpScript . '/unapproved';

            $this->urlPaged = $this->prefix . '.php?page={page}';

            $this->urlViewEntry = $this->selfPhpScript . '/view/{entry_slug}';

            $this->urlViewEntryPage = $this->selfPhpScript . '/view/{entry_slug}/page/{current_page}';

            $this->urlViewComment = $this->selfPhpScript . '/view/{entry_slug}/comment/{comment_id}';

            $this->urlCreateEntry = $this->selfPhpScript . '/create';

            $this->urlUpdateEntry = $this->selfPhpScript . '/view/{entry_slug}/update';

            $this->urlApproveEntry = $this->selfPhpScript . '/view/{entry_slug}/approve';

            $this->urlUnapproveEntry = $this->selfPhpScript . '/view/{entry_slug}/unapprove';

            $this->urlSoftDeleteEntry = $this->selfPhpScript . '/view/{entry_slug}/soft_delete';

            $this->urlRestoreEntry = $this->selfPhpScript . '/view/{entry_slug}/restore';

            $this->urlDeleteEntry = $this->selfPhpScript . '/view/{entry_slug}/delete';

            $this->urlCreateComment = $this->selfPhpScript . '/view/{entry_slug}/comment/create';

            $this->urlUpdateComment = $this->selfPhpScript . '/view/{entry_slug}/comment/{comment_id}/update';

            $this->urlApproveComment = $this->selfPhpScript . '/view/{entry_slug}/comment/{comment_id}/approve';

            $this->urlUnapproveComment = $this->selfPhpScript . '/view/{entry_slug}/comment/{comment_id}/unapprove';

            $this->urlRestoreComment = $this->selfPhpScript . '/view/{entry_slug}/comment/{comment_id}/restore';

            $this->urlSoftDeleteComment = $this->selfPhpScript . '/view/{entry_slug}/comment/{comment_id}/soft_delete';

            $this->urlDeleteComment = $this->selfPhpScript . '/view/{entry_slug}/comment/{comment_id}/delete';

            $this->urlViewAttachment = $this->selfPhpScript . '/view/{entry_slug}/attachment/{attachment_id}';

            //$this->urlViewAttachmentThumbnail = $this->showcaseSlug . '/view/{entry_slug}/attachment/{thumbnail_id}/thumbnail';

            $this->urlViewAttachmentItem = $this->prefix . '.php?action=item&attachment_id={attachment_id}';
        }

        return $this;
    }

    public function urlGetCreateEntry(): string
    {
        return $this->urlBuild($this->urlCreateEntry);
    }

    public function urlGetUpdateEntry(): string
    {
        return $this->urlBuild($this->urlUpdateEntry, $this->entryData['entry_slug']);
    }

    public function urlBuild(
        string $url,
        $entrySlug = 0,
        int $commentID = 0,
        int $attachmentID = 0,
        int|string $currentPage = 0
    ): string {
        return 'showcase.php?route=/' . str_replace(
                ['{entry_slug}', '{comment_id}', '{current_page}'],
                [$entrySlug, $commentID, $currentPage],
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
                            if (empty($dataFieldData['lowest'])) {
                                $userPermissions[$userID][$permissionName] = max(
                                    $userPermissions[$userID][$permissionName],
                                    $groupPermissions[$permissionName]
                                );
                            } else {
                                $userPermissions[$userID][$permissionName] = min(
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

    public function showcaseDataDelete(array $whereClauses = []): void
    {
        global $db;

        $db->delete_query($this->dataTableName, implode(' AND ', $whereClauses));
    }

    /**
     * delete attachments from a showcase
     */
    public function attachmentsDelete(int $entryID): bool
    {
        foreach (
            attachmentGet(["entry_id='{$entryID}'", "showcase_id='{$this->showcase_id}'"]
            ) as $attachmentID => $attachmentData
        ) {
            attachmentDelete(["attachment_id='{$attachmentID}'"]);
        }

        return true;
    }

    /**
     * delete a comment
     */
    public function commentsDelete(int $entryID): void
    {
        commentsDelete(["entry_id='{$entryID}'", "showcase_id='{$this->showcase_id}'"]);
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

    public function permissionCheck(string $permissionKey): bool|int
    {
        return $this->userPermissions[$permissionKey];
    }

    public function parseMessage(string $message, array $parserOptions = []): string
    {
        return postParser()->parse_message(
            $message,
            array_merge($this->parserOptions, $parserOptions)
        );
    }

    public function attachmentsRemove(array $whereClauses): void
    {
        $whereClauses[] = "showcase_id='{$this->showcase_id}'";

        $attachmentObjects = attachmentGet($whereClauses, ['attachment_name', 'thumbnail', 'status']);

        $attachmentObjects = hooksRun('remove_attachment_do_delete', $attachmentObjects);

        foreach ($attachmentObjects as $attachmentID => $attachmentData) {
            attachmentDelete(["attachment_id='{$attachmentID}'"]);

            if (file_exists($this->config['attachments_uploads_path'] . '/' . $attachmentData['attachment_name'])) {
                unlink($this->config['attachments_uploads_path'] . '/' . $attachmentData['attachment_name']);
            }

            if (!empty($attachmentData['thumbnail'])) {
                if (file_exists($this->config['attachments_uploads_path'] . '/' . $attachmentData['thumbnail'])) {
                    unlink($this->config['attachments_uploads_path'] . '/' . $attachmentData['thumbnail']);
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

    public function urlGet(): string
    {
        global $mybb;

        if ($this->config['relative_path']) {
            return $this->config['relative_path'] . '/' . $this->config['script_name'];
        } else {
            return $this->urlBase . '/' . $this->config['script_name'];
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
                'group_by' => 'entry_id, approved'
            ]
        );

        return (int)$db->fetch_field($query, 'totalUnapprovedEntries');
    }

    public function templateGet(string $templateName = '', bool $enableHTMLComments = true): string
    {
        return getTemplate($templateName, $enableHTMLComments, $this->showcase_id);
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
        return entryDataInsert($this->showcase_id, $insertData);
    }

    public function dataUpdate(
        array $updateData
    ): int {
        return entryDataUpdate(
            $this->showcase_id,
            $this->entryID,
            $updateData
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

    public function entryDataSet(array $entryData): void
    {
        $this->entryData = $entryData;

        if (isset($this->entryData['entry_id'])) {
            $this->entryID = (int)$this->entryData['entry_id'];
        }

        if (isset($this->entryData['user_id'])) {
            $this->entryUserID = (int)$this->entryData['user_id'];
        }
    }

    public function urlSet(string $showcaseUrl): void
    {
        $this->showcaseUrl = $showcaseUrl;
    }

    public function getDefaultFilter(): void
    {
        if (!$this->config['filter_force_field']) {
            return;
        }

        global $mybb;

        switch ($this->config['filter_force_field']) {
            case FILTER_TYPE_USER_ID:
                if (!isset($mybb->input['user_id'])) {
                    error_no_permission();
                }

                //$this->urlParams['user_id'] = $mybb->get_input('user_id', MyBB::INPUT_INT);

                //$this->whereClauses[] = "user_id='{$this->urlParams['user_id']}'";

                break;
        }
    }
}