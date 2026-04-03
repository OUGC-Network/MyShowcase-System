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
use MyBB;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\CustomFieldEntity;
use MyShowcase\Entity\UserEntity;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Routing\Routing;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\CacheManager;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Service\RenderManager;
use MyShowcase\Service\UsersManager;
use MyShowcase\Entity\ShowcaseEntity;

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
    public array $fieldset_fields = [];
    public array $attachment_types = [];
    private array $parser_options = [
        'filter_badwords' => true,
        'highlight' => '',
        'nl2br' => true
    ];
    public bool $data_table_exists;

    /**
     * Constructor of class.
     */
    public function __construct(
        public readonly ShowcaseContext $context,
        public readonly ?int $showcase_id = null,
        public ?string $route_identifier = null,
    ) {
        $this->tables_data = TABLES_DATA;

        if ($this->context->routing->routeIdentifier() !== false) {
            $this->route_identifier = $this->context->routing->routeIdentifier();
        }

        foreach (
            $this->context->cache_manager->get(
                CacheDataTypes::Config
            ) as $showcase_id => $showcase_data
        ) {
            if ($this->route_identifier === null && $this->showcase_id !== $showcase_id ||
                $this->route_identifier !== null && $this->route_identifier !== $showcase_data['route_identifier']) {
                continue;
            }

            $this->context->showcase = new ShowcaseEntity(...$showcase_data);

            $this->route_identifier = $showcase_data['route_identifier'];

            break;
        }

        $this->context->showcases_repository->setShowcase($this->context->showcase);

        $this->context->access_logs_repository->setShowcase($this->context->showcase);

        $this->context->moderators_repository->setShowcase($this->context->showcase);

        $this->context->entries_repository->setShowcase($this->context->showcase);

        $this->context->subscriptions_repository->setShowcase($this->context->showcase);

        $this->context->comments_repository->setShowcase($this->context->showcase);

        $this->context->attachments_repository->setShowcase($this->context->showcase);

        $this->context->permissions_repository->setShowcase($this->context->showcase);

        if (empty($this->context->showcase)) {
            throw new RuntimeException('The MyShowcase System does not exist.');
        }

        $this->data_table_exists = $this->context->database->table_exists(
            'myshowcase_data' . $this->context->showcase->showcase_id
        );

        $this->context->users_manager = new UsersManager($this->context);

        $this->context->users_manager->addUser($this->context->mybb->user);

        $this->context->current_user = $this->context->users_manager->getUserByID(
            (int)$this->context->mybb->user['uid']
        );

        $this->parser_options = array_merge($this->parser_options, [
            'allow_html' => !empty($this->context->showcase->parser_allow_html_in_entries),
            'allow_mycode' => !empty($this->context->showcase->parser_allow_mycode_in_entries),
            'allow_smilies' => !empty($this->context->showcase->parser_allow_smiles_in_entries),
            'allow_imgcode' => !empty($this->context->showcase->parser_allow_image_code_in_entries),
            'allow_videocode' => !empty($this->context->showcase->parser_allow_video_code_in_entries),
            'me_username' => '',
            'filter_badwords' => true,
            'shorten_urls' => false,
            'highlight' => false,
        ]);

        if ($this->context->showcase->field_set_id) {
            $this->context->fieldset_fields = $this->context->cache_manager->get(
                CacheDataTypes::Fields
            )[$this->context->showcase->field_set_id] ?? [];

            foreach ($this->context->fieldset_fields as $field_id => $field_definition) {
                if (!$field_definition['is_enabled']) {
                    unset($this->context->fieldset_fields[$field_id]);
                }
            }

            $this->buildCustomFields();
        }

        $this->context->mybb->binary_fields['myshowcase_data' . $this->context->showcase->showcase_id]['ipaddress'] = true;

        $this->context->mybb->binary_fields['myshowcase_access_logs']['ipaddress'] = true;

        $hookArguments = [
            'showcaseObject' => &$this,
        ];

        $this->attachment_types = $this->context->cache_manager->get(
            CacheDataTypes::AttachmentTypes
        )[$this->context->showcase->showcase_id] ?? [];

        $hookArguments = hooksRun('system_showcase_construct_end', $hookArguments);

        if ($this->context->mybb->get_input('page', MyBB::INPUT_INT) > 0) {
            $this->context->mybb->input['page'] = $this->context->mybb->get_input('page', MyBB::INPUT_INT);
        } else {
            $this->context->mybb->input['page'] = 1;
        }
    }

    public function newInstance(?int $showcase_id = null, ?string $route_identifier = null): self
    {
        return new self(
            $this->context,
            $showcase_id,
            $route_identifier,
        );
    }

    private function buildCustomFields(): void
    {
        foreach (
            $this->context->cache_manager->get(
                CacheDataTypes::Fields
            )[$this->context->showcase->field_set_id] ?? [] as $field_id => $field_definition
        ) {
            $this->context->custom_fields_collection[$field_id] = new CustomFieldEntity(...$field_definition);
        }
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

            $groupsCache = $this->context->mybb->cache->read('usergroups') ?? [];

            foreach ($groupsCache[$groupID] as $permissionKey => $permissionValue) {
                if (str_starts_with($permissionKey, 'myshowcase_')) {
                    $permissionName = str_replace('myshowcase_', '', $permissionKey);

                    $showcaseGroupPermissions[$groupID][$permissionName] = sanitizeTableFieldValue(
                        $permissionValue,
                        $dataFields[$permissionName]['type'] ?? ''
                    );
                }
            }

            $permissionsCache = $this->context->cache_manager->get(
                CacheDataTypes::Permissions
            );

            if (!empty($permissionsCache[$this->context->showcase->showcase_id][$groupID])) {
                $showcaseGroupPermissions[$groupID] = array_merge(
                    $showcaseGroupPermissions[$groupID],
                    $permissionsCache[$this->context->showcase->showcase_id][$groupID]
                );
            }
        }

        return $showcaseGroupPermissions[$groupID];
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
            $moderatorsCache = $this->context->cache_manager->get(
                CacheDataTypes::Moderators
            );

            if (!empty($moderatorsCache[$this->context->showcase->showcase_id])) {
                foreach ($moderatorsCache[$this->context->showcase->showcase_id] as $moderatorPermissions) {
                    if ($moderatorPermissions['group_id'] && in_array(
                            $moderatorPermissions['user_id'],
                            $userGroupsIDs
                        ) ||
                        !$moderatorPermissions['group_id'] && (int)$moderatorPermissions['user_id'] === $userID) {
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
}