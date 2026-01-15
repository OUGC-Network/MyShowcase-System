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

use _PHPStan_ea7072c0a\Symfony\Component\String\Exception\RuntimeException;
use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyBB;
use MyLanguage;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\Permission;
use MyShowcase\Entity\User;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\FilterFields;
use MyShowcase\Enum\FormTypes;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Fields\FieldFactory;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Repository\ModeratorsRepository;
use MyShowcase\Repository\PermissionsRepository;
use MyShowcase\Repository\ShowcasesRepository;
use MyShowcase\Repository\SubscriptionsRepository;
use MyShowcase\Routing\Routing;
use MyShowcase\Security\PermissionsFactory;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\CacheManager;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Service\EntriesManager;
use MyShowcase\Service\Render;
use MyShowcase\Service\SubscriptionsManager;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;
use function MyShowcase\Plugin\Functions\showcaseDefaultModeratorPermissions;

use const MyShowcase\Plugin\Core\GUEST_GROUP_ID;
use const MyShowcase\Plugin\Core\TABLES_DATA;

class Showcase
{
    private Collection $users;

    public Routing $routing;

    private EventDispatcher $event_dispatcher;

    public EntriesRepository $entries_repository;

    public ShowcasesRepository $showcase_repository;

    public SubscriptionsRepository $subscriptions_repository;

    public CommentsRepository $comments_repository;

    public AttachmentsRepository $attachments_repository;

    public PermissionsRepository $permissions_repository;
    public ModeratorsRepository $moderators_repository;

    public CacheManager $cache_manager;

    public EntriesManager $entries_manager;

    private SubscriptionsManager $subscriptions_manager;

    public CommentsManager $comments_manager;

    public AttachmentsManager $attachments_manager;
    public Render $render_manager;

    public FieldFactory $field_factory;

    public readonly User $current_user;

    private array $tables_data;

    public array $config;

    public array $fieldset_fields = [];

    private array $fieldset_searchable_fields = [];

    public array $attachment_types = [];

    private array $parser_options = [
        'filter_badwords' => true,
        'highlight' => '',
        'nl2br' => true
    ];

    public array $filter = [];

    private string $search_highlight = '';

    public bool $data_table_exists;

    /**
     * Constructor of class.
     */
    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        public readonly MyBB $mybb,
        private readonly MyLanguage $language,
        private readonly ?int $showcase_id = null,
        private ?string $route_identifier = null,
    ) {
        $this->users = new Collection();

        $this->tables_data = TABLES_DATA;

        $this->event_dispatcher = new EventDispatcher();

        $this->routing = new Routing($this, $this->database, $this->mybb, $this->language, $this->event_dispatcher);

        if ($this->routing->routeIdentifier() !== false) {
            $this->route_identifier = $this->routing->routeIdentifier();
        }

        $this->cache_manager = new CacheManager($this->database, $this->mybb->cache,);

        foreach (
            $this->cache_manager->get(
                CacheDataTypes::Config
            ) as $showcase_id => $showcase_data
        ) {
            if ($this->showcase_id !== null && $this->showcase_id !== $showcase_id ||
                $this->route_identifier !== null && $this->route_identifier !== $showcase_data['route_identifier']) {
                //_dump($showcase_data['route_identifier']);
                continue;
            }

            $showcase_data['version'] = VERSION_CODE;

            if (DEBUG) {
                $showcase_data['version'] = TIME_NOW;
            }

            $this->config = $showcase_data;

            break;
        }

        if (empty($this->config)) {
            throw new RuntimeException('The MyShowcase System does not exist.');
        }

        $this->routing->setConfig($this->config);

        $this->showcase_repository = new ShowcasesRepository($this->database, $this->config,);

        $this->subscriptions_repository = new SubscriptionsRepository($this->database, $this->config,);

        $this->comments_repository = new CommentsRepository($this->database, $this->config,);

        $this->attachments_repository = new AttachmentsRepository($this->database, $this->config,);

        $this->permissions_repository = new PermissionsRepository($this->database, $this->config);

        $this->moderators_repository = new ModeratorsRepository($this->database, $this->config);


        $this->data_table_exists = $this->database->table_exists('myshowcase_data' . $this->config['showcase_id']);

        if (!$this->database->table_exists('myshowcase_data' . $this->config['showcase_id'])) {
            //throw new RuntimeException('The MyShowcase System does not exist.');
        }

        $this->config['showcase_url'] = rtrim($this->config['showcase_url'], '/');

        $this->config['showcase_url_assets'] = $this->mybb->get_asset_url($this->config['showcase_url']);

        $this->addUser([
            'user_id' => (int)$this->mybb->user['uid'],
            'show_avatars' => !empty($this->mybb->user['showavatars']),
            'suspend_signature' => !empty($this->mybb->user['suspendsignature']),
            'avatar' => $this->mybb->user['avatar'],
            'days_prune' => $mybb->user['daysprune'] ?? 0,
            //'style' => $mybb->user['style'] ?? 0,
        ]);

        $this->current_user = $this->getUsers()->offsetGet((int)$this->mybb->user['uid']);

        $this->parser_options = array_merge($this->parser_options, [
            'allow_html' => $this->config['parser_allow_html'],
            'allow_mycode' => $this->config['parser_allow_mycode'],
            'me_username' => $this->mybb->user['username'] ?? '',
            'allow_smilies' => $this->config['parser_allow_smiles']
        ]);

        if ($this->config['field_set_id']) {
            $this->fieldset_fields = $this->cache_manager->get(
                CacheDataTypes::Fields
            )[$this->config['field_set_id']] ?? [];

            foreach ($this->fieldset_fields as $field_id => $field_definition) {
                if (!$field_definition['is_enabled']) {
                    unset($this->fieldset_fields[$field_id]);

                    continue;
                }

                if ($field_definition['enable_search']) {
                    $this->fieldset_searchable_fields[$field_definition['field_key']] = $field_definition['html_type'];
                }
            }
        }

        switch ($this->config['main_filter_force_field']) {
            case FilterFields::UserID:

                if ($this->mybb->get_input('user_id', MyBB::INPUT_INT) > 0) {
                    $this->filter['force_field_value'] = $this->mybb->get_input('user_id', MyBB::INPUT_INT);
                } else {
                    $this->filter['force_field_value'] = $this->current_user->user_id;
                }

                break;
        }

        $this->mybb->binary_fields['myshowcase_data' . $this->config['showcase_id']]['ipaddress'] = true;

        $this->mybb->binary_fields['myshowcase_access_logs']['ipaddress'] = true;

        //loadLanguage('myshowcase_fs' . $this->config['field_set_id']);

        $hookArguments = [
            'showcaseObject' => &$this,
        ];

        if (isset($this->mybb->input['highlight'])) {
            $this->search_highlight = $this->mybb->get_input('highlight');

            $this->parser_options['highlight'] = $this->search_highlight;
        }

        $this->attachment_types = $this->cache_manager->get(
            CacheDataTypes::AttachmentTypes
        )[$this->config['showcase_id']] ?? [];

        $hookArguments = hooksRun('system_showcase_construct_end', $hookArguments);

        $this->config['attachments_display_thumbnails'] = true;

        if ($this->config['main_per_page'] < 1) {
            $this->config['main_per_page'] = 1;
        }

        $this->config['attachments_display_full_images'] = false;

        $this->entries_repository = new EntriesRepository(
            $this->database,
            $this->mybb,
            $this->event_dispatcher,
            $this->config,
        );

        $this->userPermissionsGet($this->current_user);

        $current_page = 1;

        if ($this->mybb->get_input('page', MyBB::INPUT_INT) > 0) {
            $mybb->input['page'] = $this->mybb->get_input('page', MyBB::INPUT_INT);
        } else {
            $mybb->input['page'] = 1;
        }

        $this->entries_manager = new EntriesManager(
            $this->database,
            $this->mybb,
            $this->language,
            $this->current_user,
            $this->entries_repository,
            $this->attachments_repository,
            $this->config,
            $this->fieldset_fields,
            $this->fieldset_searchable_fields,
            $this->parser_options,
            $this->filter,
        );

        $this->subscriptions_manager = new SubscriptionsManager(
            $this->subscriptions_repository,
            $this->event_dispatcher,
        );

        $this->comments_manager = new CommentsManager(
            $this->database,
            $this->current_user,
            $this->comments_repository,
            $this->event_dispatcher,
            $this->config,
            $current_page,
        );

        $this->attachments_manager = new AttachmentsManager(
            $this->database,
            $this->language,
            $this->current_user,
            $this->attachment_types,
            $this->attachments_repository,
            $this->event_dispatcher,
            $this->config,
        );

        $this->field_factory = new FieldFactory(
            $this->attachments_repository,
            $this->entries_manager,
            $this->attachments_manager,
            $this->config,
            $this->parser_options,
            $this->search_highlight,
        );

        $this->render_manager = new Render(
            $this->database,
            $this->mybb,
            $this->language,
            $this->current_user,
            $this->routing,
            $this->entries_repository,
            $this->attachments_repository,
            $this->entries_manager,
            $this->attachments_manager,
            $this->comments_manager,
            $this->config,
            $this->attachment_types,
            $this->fieldset_fields,
            $this->fieldset_searchable_fields,
            $this->parser_options,
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

            $groupsCache = $this->mybb->cache->read('usergroups') ?? [];

            foreach ($groupsCache[$groupID] as $permissionKey => $permissionValue) {
                if (str_starts_with($permissionKey, 'myshowcase_')) {
                    $permissionName = str_replace('myshowcase_', '', $permissionKey);

                    $showcaseGroupPermissions[$groupID][$permissionName] = sanitizeTableFieldValue(
                        $permissionValue,
                        $dataFields[$permissionName]['type'] ?? ''
                    );
                }
            }

            $permissionsCache = $this->cache_manager->get(
                CacheDataTypes::Permissions
            );

            if (!empty($permissionsCache[$this->config['showcase_id']][$groupID])) {
                $showcaseGroupPermissions[$groupID] = array_merge(
                    $showcaseGroupPermissions[$groupID],
                    $permissionsCache[$this->config['showcase_id']][$groupID]
                );
            }
        }

        return $showcaseGroupPermissions[$groupID];
    }

    /**
     * get user permissions for a specific showcase
     *
     * @param User $user The user to build permissions for
     * @return void user permissions for the specific showcase
     */
    public function userPermissionsGet(User $user): void
    {
        if ($user->getPermissions() !== null) {
            return;
        }

        static $permissions_cache = [];

        if (isset($permissions_cache[$user->user_id])) {
            return;
        }

        $permissions_cache[$user->user_id] = [];

        $user_permissions = &$permissions_cache[$user->user_id];

        // we apply the more restrictive permissions by default
        foreach (PermissionsFactory::$permission_options_map as $permission_name => $permission_definition) {
            switch ($permission_definition['input_type']) {
                case FormTypes::PermissionBoolean:
                    $user_permissions[$permission_name] = Permission::BOOLEAN_TYPE_DISALLOW;

                    break;
                case FormTypes::Number:
                    if (empty($permission_definition['highest'])) {
                        $user_permissions[$permission_name] = Permission::NUMERIC_TYPE_INFINITY;
                    } else {
                        $user_permissions[$permission_name] = Permission::NUMERIC_TYPE_LOWER;
                    }

                    break;
                case FormTypes::SelectTimeInterval:
                    $user_permissions[$permission_name] = Permission::TIME_INTERVAL_TYPE_DAYS;

                    break;
            }
        }

        // if disabled, we keep restrictive defaults
        if (empty($this->config['is_enabled'])) {
            $user->setPermissions($user_permissions);

            return;
        }

        $user_data = get_user($user->user_id);

        // we follow with global group permissions
        if (empty($user_data['uid'])) {
            $user_groups_ids = Permission::GROUP_GUESTS;
        } else {
            $user_groups_ids = array_filter(
                array_unique(
                    array_map(
                        'intval',
                        explode(',', "{$user_data['usergroup']},{$user_data['additionalgroups']}")
                    )
                )
            );
        }

        $user_groups_ids = implode("','", $user_groups_ids);

        $group_permissions_cache = [];

        static $permissions_repository = null;

        if ($permissions_repository === null) {
            $permissions_repository = new PermissionsRepository($this->database);
        }

        foreach (
            $permissions_repository->queryPermissions(
                ["group_id IN ('{$user_groups_ids}')"],
                array_keys(TABLES_DATA['myshowcase_permissions'])
            ) as $permission_id => $permission_data
        ) {
            $group_permissions_cache[$permission_data['group_id']] = $permission_data;
        }

        foreach (
            $this->permissions_repository->queryPermissions(
                ["group_id IN ('{$user_groups_ids}')"],
                array_keys(TABLES_DATA['myshowcase_permissions'])
            ) as $permission_id => $permission_data
        ) {
            $group_permissions_cache[$permission_data['group_id']] = $permission_data;
        }

        foreach ($group_permissions_cache as $group_id => $permission_data) {
            foreach (PermissionsFactory::$permission_options_map as $permission_name => $permission_definition) {
                if (empty($permission_definition['is_group_permission'])) {
                    continue;
                }

                switch ($permission_definition['input_type']) {
                    case FormTypes::PermissionBoolean:
                        if ($user_permissions[$permission_name] === Permission::BOOLEAN_TYPE_NEVER) {
                            break;
                        }

                        switch ($permission_data[$permission_name]) {
                            case Permission::BOOLEAN_TYPE_NEVER:
                                $user_permissions[$permission_name] = Permission::BOOLEAN_TYPE_NEVER;

                                break;
                            case Permission::BOOLEAN_TYPE_ALLOW:
                                $user_permissions[$permission_name] = Permission::BOOLEAN_TYPE_ALLOW;

                                break;
                            case Permission::BOOLEAN_TYPE_DISALLOW:
                                $user_permissions[$permission_name] = max(
                                    $user_permissions[$permission_name],
                                    Permission::BOOLEAN_TYPE_DISALLOW
                                );

                                break;
                        }

                        break;
                    case FormTypes::Number:
                    case FormTypes::SelectTimeInterval:
                        if (!empty($permission_definition['zero_unlimited']) &&
                            $permission_data[$permission_name] === Permission::NUMERIC_TYPE_UNLIMITED) {
                            $user_permissions[$permission_name] = Permission::NUMERIC_TYPE_UNLIMITED;

                            break;
                        }

                        if (empty($permission_definition['highest'])) {
                            $user_permissions[$permission_name] = min(
                                $user_permissions[$permission_name],
                                $permission_data[$permission_name]
                            );
                        } else {
                            $user_permissions[$permission_name] = max(
                                $user_permissions[$permission_name],
                                $permission_data[$permission_name]
                            );
                        }
                }
            }
        }

        // todo, get user specific permissions

        // todo, get moderator permissions

        $user->setPermissions($user_permissions);
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
            $moderatorsCache = $this->cache_manager->get(
                CacheDataTypes::Moderators
            );

            if (!empty($moderatorsCache[$this->config['showcase_id']])) {
                foreach ($moderatorsCache[$this->config['showcase_id']] as $moderatorPermissions) {
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

    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(array $user_data): void
    {
        if (!$this->users->contains($user_data)) {
            if (!empty($user_data['user_id'])) {
                $this->users[$user_data['user_id']] = new User(...$user_data);
            } else {
                $this->users[] = new User(...$user_data);
            }
        }
    }
}