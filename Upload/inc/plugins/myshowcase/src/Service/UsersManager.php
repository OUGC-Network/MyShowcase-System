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
use MyShowcase\Entity\Permission;
use MyShowcase\Entity\User;
use MyShowcase\Enum\FilterTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\FormTypes;
use MyShowcase\Enum\SortDirections;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Repository\PermissionsRepository;
use MyShowcase\Security\PermissionsFactory;
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

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\TABLES_DATA;

class UsersManager
{
    private Collection $users;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly MyBB $mybb,
        private readonly array $config,
    ) {
        $this->users = new Collection();
    }

    /**
     * @return Collection<User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function getUserByID(int $user_id): User|bool
    {
        if (!$this->users->offsetExists($user_id)) {
            return false;
        }

        return $this->users->offsetGet($user_id);
    }

    public function addUser(array $user_data): void
    {
        if (!$this->users->contains($user_data)) {
            $this->users[(int)$user_data['uid']] = new User();

            foreach ($user_data as $key => $value) {
                $this->users[(int)$user_data['uid']]->$key = $value;
            }

            $this->userPermissionsGet($this->users[(int)$user_data['uid']]);
        }
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

        if (isset($permissions_cache[$user->uid])) {
            return;
        }

        $permissions_cache[$user->uid] = [];

        $user_permissions = &$permissions_cache[$user->uid];

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

        $user_data = get_user($user->uid);

        // we follow with global group permissions
        if (empty($user_data['uid'])) {
            $user_groups_ids = [Permission::GROUP_GUESTS];
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
            $global_permissions_repository = new PermissionsRepository($this->database);
        }

        foreach (
            $global_permissions_repository->queryPermissions(
                ["group_id IN ('{$user_groups_ids}')"],
                array_keys(TABLES_DATA['myshowcase_permissions'])
            ) as $permission_id => $permission_data
        ) {
            $group_permissions_cache[$permission_data['group_id']] = $permission_data;
        }

        static $showcase_permissions_repository = null;

        if ($showcase_permissions_repository === null) {
            $showcase_permissions_repository = new PermissionsRepository($this->database, $this->config);
        }

        foreach (
            $showcase_permissions_repository->queryPermissions(
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
}