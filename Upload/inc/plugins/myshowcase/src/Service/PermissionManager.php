<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Entity\UserEntity;

readonly class PermissionManager
{
    public function __construct()
    {
    }

    public function hasPermission(UserEntity $user, int $permission_value): bool
    {
        $permissions = 0;

        foreach ($user->getGroups() as $group) {
            foreach ($group->getPermissions() as $permission) {
                $permissions |= $permission->getValue();
            }
        }

        return ($permissions & $permission_value) === $permission_value;
    }

    public function getUserPermissions(UserEntity $user): int
    {
        $permissions = 0;

        foreach ($user->getGroups() as $group) {
            foreach ($group->getPermissions() as $permission) {
                $permissions |= $permission->getValue();
            }
        }

        return $permissions;
    }
}