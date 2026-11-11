<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Collection\Collection;
use MyShowcase\Entity\PermissionEntity;
use MyShowcase\Entity\UserEntity;
use MyShowcase\Enum\FormTypes;
use MyShowcase\Repository\PermissionsRepository;
use MyShowcase\Security\PermissionsFactory;
use MyShowcase\ShowcaseContext;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class UsersManager
{
    private Collection $users;

    public function __construct(
        public readonly ShowcaseContext $context,
    ) {
        $this->users = new Collection();
    }

    /**
     * @return Collection<UserEntity>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function getUserByID(int $user_id): UserEntity|bool
    {
        if (!$this->users->offsetExists($user_id)) {
            return false;
        }

        return $this->users->offsetGet($user_id);
    }

    public function addUser(array $user_data): void
    {
        if (!$this->users->contains($user_data)) {
            $this->users[(int)$user_data['uid']] = new UserEntity();

            foreach ($user_data as $key => $value) {
                $this->users[(int)$user_data['uid']]->$key = $value;
            }

            $this->userPermissionsGet($this->users[(int)$user_data['uid']]);
        }
    }

    /**
     * get user permissions for a specific showcase
     *
     * @param UserEntity $user The user to build permissions for
     * @return void user permissions for the specific showcase
     */
    public function userPermissionsGet(UserEntity $user): void
    {
        if ($user->getPermissions() !== null) {
            return;
        }

        static $permissions_cache = [];

        if (isset($permissions_cache[$user->uid])) {
            return;
        }

        $permissions_cache[$user->uid] = [];

        // we apply the more restrictive permissions by default
        foreach (PermissionsFactory::$permission_options_map as $permission_name => $permission_definition) {
            switch ($permission_definition['input_type']) {
                case FormTypes::PermissionBoolean:
                    $permissions_cache[$user->uid][$permission_name] = PermissionEntity::BOOLEAN_TYPE_DISALLOW;

                    break;
                case FormTypes::Number:
                    if (empty($permission_definition['highest'])) {
                        $permissions_cache[$user->uid][$permission_name] = PermissionEntity::NUMERIC_TYPE_INFINITY;
                    } else {
                        $permissions_cache[$user->uid][$permission_name] = PermissionEntity::NUMERIC_TYPE_LOWER;
                    }

                    break;
                case FormTypes::SelectTimeInterval:
                    $permissions_cache[$user->uid][$permission_name] = PermissionEntity::TIME_INTERVAL_TYPE_DAYS;

                    break;
            }
        }

        // if disabled, we keep restrictive defaults
        if (empty($this->context->showcase->is_enabled)) {
            $user->setPermissions($permissions_cache[$user->uid]);

            return;
        }

        $user_data = get_user($user->uid);

        // we follow with global group permissions
        if (empty($user_data['uid'])) {
            $user_groups_ids = [PermissionEntity::GROUP_GUESTS];
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

        static $global_permissions_repository = null;

        if ($global_permissions_repository === null) {
            $global_permissions_repository = new PermissionsRepository($this->context->database);
        }

        foreach (
            $global_permissions_repository->queryPermissions(
                ["group_id IN ('{$user_groups_ids}')"],
                array_keys(TABLES_DATA['myshowcase_permissions'])
            ) as $permission_id => $permission_data
        ) {
            $group_permissions_cache[$permission_data['group_id']] = $permission_data;
        }

        foreach (
            $this->context->permissions_repository->queryPermissions(
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
                        if ($permissions_cache[$user->uid][$permission_name] === PermissionEntity::BOOLEAN_TYPE_NEVER) {
                            break;
                        }

                        switch ($permission_data[$permission_name]) {
                            case PermissionEntity::BOOLEAN_TYPE_NEVER:
                                $permissions_cache[$user->uid][$permission_name] = PermissionEntity::BOOLEAN_TYPE_NEVER;

                                break;
                            case PermissionEntity::BOOLEAN_TYPE_ALLOW:
                                $permissions_cache[$user->uid][$permission_name] = PermissionEntity::BOOLEAN_TYPE_ALLOW;

                                break;
                            case PermissionEntity::BOOLEAN_TYPE_DISALLOW:
                                // Boolean flags stay integers: PermissionEntity types several of them as int.
                                $permissions_cache[$user->uid][$permission_name] = (int)max(
                                    (float)$permissions_cache[$user->uid][$permission_name],
                                    PermissionEntity::BOOLEAN_TYPE_DISALLOW
                                );

                                break;
                        }

                        break;
                    case FormTypes::Number:
                    case FormTypes::SelectTimeInterval:
                        if (!empty($permission_definition['zero_unlimited']) &&
                            $permission_data[$permission_name] === PermissionEntity::NUMERIC_TYPE_UNLIMITED) {
                            $permissions_cache[$user->uid][$permission_name] = PermissionEntity::NUMERIC_TYPE_UNLIMITED;

                            break;
                        }

                        if (empty($permission_definition['highest'])) {
                            $permissions_cache[$user->uid][$permission_name] = min(
                                (float)$permissions_cache[$user->uid][$permission_name],
                                (float)$permission_data[$permission_name]
                            );
                        } else {
                            $permissions_cache[$user->uid][$permission_name] = max(
                                (float)$permissions_cache[$user->uid][$permission_name],
                                (float)$permission_data[$permission_name]
                            );
                        }
                }
            }
        }

        // todo, get user specific permissions

        // todo, get moderator permissions

        $user->setPermissions($permissions_cache[$user->uid]);
    }
}