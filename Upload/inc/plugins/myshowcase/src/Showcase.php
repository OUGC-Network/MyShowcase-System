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

namespace MyShowcase;

use MyBB;
use MyShowcase\Repository\ModeratorsRepository;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\CustomFieldEntity;
use MyShowcase\Entity\UserEntity;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Event\Listener\EntryDeletedAttachmentListener;
use MyShowcase\Event\Listener\EntryDeletedCommentListener;
use MyShowcase\Event\Listener\EntryDeletedSubscriptionListener;
use MyShowcase\Routing\Routing;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\CacheManager;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Service\RenderManager;
use MyShowcase\Service\UsersManager;
use MyShowcase\Entity\ShowcaseEntity;
use RuntimeException;

use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;
use function MyShowcase\Plugin\Functions\showcaseDefaultModeratorPermissions;

use const MyShowcase\Plugin\Core\GUEST_GROUP_ID;
use const MyShowcase\Plugin\Core\TABLES_DATA;

class Showcase
{
    public Routing $routing;
    public EventDispatcher $event_dispatcher;
    public CacheManager $cache_manager;
    public UsersManager $users_manager;
    public CommentsManager $comments_manager;
    public AttachmentsManager $attachments_manager;
    public RenderManager $render_manager;
    public Collection $custom_fields;
    public readonly UserEntity $current_user;
    private array $tables_data;
    public array $config;
    public array $collection_fields = [];
    public array $attachment_types = [];
    private array $parser_options = [
        'filter_badwords' => true,
        'highlight' => '',
        'nl2br' => true,
    ];
    public bool $data_table_exists;

    public function __construct(
        public readonly ShowcaseContext $context,
        public readonly ?int $showcase_id = null,
        public ?string $route_identifier = null,
    ) {
        $this->tables_data = TABLES_DATA;

        $this->resolveRouteIdentifier();
        $this->loadShowcaseConfig();
        $this->initializeRepositories();

        if (empty($this->context->showcase)) {
            throw new RuntimeException('The MyShowcase System does not exist.');
        }

        $this->data_table_exists = $this->context->database->table_exists(
            'myshowcase_data' . $this->context->showcase->showcase_id
        );

        $this->initializeCurrentUser();
        $this->configureParserOptions();
        $this->initializeCollection();
        $this->registerBinaryFields();
        $this->registerEntryDeletedListeners();

        hooksRun('system_showcase_construct_end', $this);

        $this->attachment_types = $this->context->cache_manager->get(
            CacheDataTypes::AttachmentTypes
        )[$this->context->showcase->showcase_id] ?? [];

        $page = $this->context->mybb->get_input('page', MyBB::INPUT_INT);
        $this->context->mybb->input['page'] = $page > 0 ? $page : 1;
    }

    private function resolveRouteIdentifier(): void
    {
        if ($this->context->routing->routeIdentifier() !== false) {
            $this->route_identifier = $this->context->routing->routeIdentifier();
        }
    }

    private function loadShowcaseConfig(): void
    {
        foreach ($this->context->cache_manager->get(CacheDataTypes::Config) as $showcase_id => $showcase_data) {
            if ($this->route_identifier === null && $this->showcase_id !== $showcase_id) {
                continue;
            }

            if ($this->route_identifier !== null && $this->route_identifier !== $showcase_data['route_identifier']) {
                continue;
            }

            $this->context->showcase = new ShowcaseEntity(...$showcase_data);
            $this->route_identifier = $showcase_data['route_identifier'];

            break;
        }
    }

    private function initializeRepositories(): void
    {
        $showcase = $this->context->showcase;

        $this->context->showcases_repository->setShowcase($showcase);
        $this->context->access_logs_repository->setShowcase($showcase);
        $this->context->moderators_repository->setShowcase($showcase);
        $this->context->entries_repository->setShowcase($showcase);
        $this->context->subscriptions_repository->setShowcase($showcase);
        $this->context->comments_repository->setShowcase($showcase);
        $this->context->attachments_repository->setShowcase($showcase);
        $this->context->permissions_repository->setShowcase($showcase);
    }

    private function initializeCurrentUser(): void
    {
        $this->context->users_manager = new UsersManager($this->context);
        $this->context->users_manager->addUser($this->context->mybb->user);
        $this->context->current_user = $this->context->users_manager->getUserByID(
            (int)$this->context->mybb->user['uid']
        );
    }

    private function configureParserOptions(): void
    {
        $showcase = $this->context->showcase;

        $this->parser_options = array_merge($this->parser_options, [
            'allow_html' => !empty($showcase->parser_allow_html_in_entries),
            'allow_mycode' => !empty($showcase->parser_allow_mycode_in_entries),
            'allow_smilies' => !empty($showcase->parser_allow_smiles_in_entries),
            'allow_imgcode' => !empty($showcase->parser_allow_image_code_in_entries),
            'allow_videocode' => !empty($showcase->parser_allow_video_code_in_entries),
            'me_username' => '',
            'filter_badwords' => true,
            'shorten_urls' => false,
            'highlight' => false,
        ]);
    }

    private function initializeCollection(): void
    {
        if (!$this->context->showcase->collection_id) {
            return;
        }

        $this->context->collection_fields = $this->context->cache_manager->get(
            CacheDataTypes::Fields
        )[$this->context->showcase->collection_id] ?? [];

        foreach ($this->context->collection_fields as $field_id => $field_definition) {
            if (!$field_definition['is_enabled']) {
                unset($this->context->collection_fields[$field_id]);
            }
        }

        // The field labels of the collection (myshowcase_collection_field_{key}), when its language file exists.
        $this->context->language->load('myshowcase_fs' . $this->context->showcase->collection_id, true, true);

        $this->buildCustomFields();
    }

    private function registerBinaryFields(): void
    {
        $showcase_id = $this->context->showcase->showcase_id;

        $this->context->mybb->binary_fields['myshowcase_data' . $showcase_id]['ipaddress'] = true;
        $this->context->mybb->binary_fields['myshowcase_access_logs']['ipaddress'] = true;
    }

    /** What belongs to an entry is removed in one place, whichever path deleted the entry. */
    private function registerEntryDeletedListeners(): void
    {
        foreach (
            [
                new EntryDeletedCommentListener($this->context),
                new EntryDeletedAttachmentListener($this->context),
                new EntryDeletedSubscriptionListener($this->context),
            ] as $listener
        ) {
            $this->context->event_dispatcher->addListener(EntryDeletedEvent::NAME, $listener);
        }
    }

    public function newInstance(?int $showcase_id = null, ?string $route_identifier = null): self
    {
        return new self($this->context, $showcase_id, $route_identifier);
    }

    private function buildCustomFields(): void
    {
        $fields = $this->context->cache_manager->get(
            CacheDataTypes::Fields
        )[$this->context->showcase->collection_id] ?? [];

        foreach ($fields as $field_id => $field_definition) {
            $this->context->custom_fields_collection[$field_id] = new CustomFieldEntity(...$field_definition);
        }
    }

    /**
     * Returns merged group permissions for the given group, with showcase-specific overrides applied.
     */
    public function groupPermissionsGet(int $groupID = GUEST_GROUP_ID): array
    {
        static $cache = [];

        if (isset($cache[$groupID])) {
            return $cache[$groupID];
        }

        $dataFields = TABLES_DATA['myshowcase_permissions'];
        $hookArguments = ['dataFields' => &$dataFields];
        hooksRun('admin_user_groups_edit_graph_start', $hookArguments);

        $permissions = $this->buildDefaultGroupPermissions($dataFields);
        $permissions = $this->applyUsergroupPermissions($permissions, $dataFields, $groupID);
        $permissions = $this->applyShowcasePermissionOverrides($permissions, $groupID);

        $cache[$groupID] = $permissions;

        return $cache[$groupID];
    }

    private function buildDefaultGroupPermissions(array $dataFields): array
    {
        // TABLES_DATA does not flag permission columns, so every column that is not a row identifier is one.
        $identifierFields = ['permission_id', 'showcase_id', 'group_id', 'moderator_id', 'user_id'];

        $permissions = [];

        foreach ($dataFields as $key => $field) {
            if (in_array($key, $identifierFields, true)) {
                continue;
            }

            $permissions[$key] = $field['default'];
        }

        return $permissions;
    }

    private function applyUsergroupPermissions(array $permissions, array $dataFields, int $groupID): array
    {
        // The usergroups cache is keyed by group id, and each row can also carry numeric keys (SQLite), so the
        // row is read by the myshowcase_ column name of each permission instead of being looped over.
        $usergroupsCache = $this->context->mybb->cache->read('usergroups') ?? [];

        $usergroup = $usergroupsCache[$groupID] ?? [];

        foreach ($dataFields as $permissionName => $field) {
            $columnName = "myshowcase_{$permissionName}";

            if (!array_key_exists($columnName, $usergroup)) {
                continue;
            }

            $permissions[$permissionName] = sanitizeTableFieldValue(
                $usergroup[$columnName],
                $field['type'] ?? ''
            );
        }

        return $permissions;
    }

    private function applyShowcasePermissionOverrides(array $permissions, int $groupID): array
    {
        $permissionsCache = $this->context->cache_manager->get(CacheDataTypes::Permissions);
        $showcaseOverrides = $permissionsCache[$this->context->showcase->showcase_id][$groupID] ?? [];

        if (!empty($showcaseOverrides)) {
            return array_merge($permissions, $showcaseOverrides);
        }

        return $permissions;
    }

    public function moderatorPermissionsGet(int $userID): array
    {
        $userData = get_user($userID);
        $permissions = showcaseDefaultModeratorPermissions();

        if (empty($userData['uid'])) {
            return $permissions;
        }

        $userGroupIDs = $this->extractUserGroupIDs($userData);

        if (is_member(getSetting('superModeratorGroups'), $userData)) {
            $permissions = array_map(fn() => true, $permissions);
        }

        return $this->applyShowcaseModeratorPermissions($permissions, $userGroupIDs, $userID);
    }

    private function extractUserGroupIDs(array $userData): array
    {
        return array_filter(
            array_map('intval', explode(',', "{$userData['usergroup']},{$userData['additionalgroups']}"))
        );
    }

    private function applyShowcaseModeratorPermissions(
        array $permissions,
        array $userGroupIDs,
        int $userID
    ): array {
        $moderatorsCache = $this->context->cache_manager->get(CacheDataTypes::Moderators);
        $showcaseModerators = $moderatorsCache[$this->context->showcase->showcase_id] ?? [];

        foreach ($showcaseModerators as $moderatorEntry) {
            if (!$this->moderatorMatchesUser($moderatorEntry, $userGroupIDs, $userID)) {
                continue;
            }

            foreach ($permissions as $key => &$value) {
                $value = !empty($moderatorEntry[$key]) || !empty($value);
            }
        }

        return $permissions;
    }

    private function moderatorMatchesUser(array $moderatorEntry, array $userGroupIDs, int $userID): bool
    {
        return ModeratorsRepository::matchesUser($moderatorEntry, $userGroupIDs, $userID);
    }
}
