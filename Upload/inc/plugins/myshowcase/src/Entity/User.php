<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

use InvalidArgumentException;
use MyShowcase\Collection\Collection;
use MyShowcase\Security\PermissionsFactory;

class User
{
    private Collection $groups;
    private ?Permission $permissions = null;

    public function __construct(
        public ?int $user_id = null,
        public ?bool $show_avatars = null,
        public ?bool $suspend_signature = null,
        public ?string $avatar = null,
        public ?int $days_prune = null,
    ) {
        $this->groups = new Collection();
    }

    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group_data): void
    {
        if (!$this->groups->contains($group_data)) {
            if (!empty($group_data->field_id)) {
                $this->groups[$group_data->field_id] = $group_data;
            } else {
                $this->groups[] = $group_data;
            }
        }
    }

    public function getPermissions(): ?Permission
    {
        return $this->permissions;
    }

    public function setPermissions(array $permissions): void
    {
        $this->permissions = new Permission(...$permissions);
    }

    public function getPermission(string $permission_name): int
    {
        if (!isset($this->permissions->$permission_name)) {
            throw new InvalidArgumentException("Permission '{$permission_name}' does not exist.");
        }

        return $this->permissions->$permission_name ?? 0;
    }

    public function hasPermission(string $permission_name): bool
    {
        if (empty($this->permissions->$permission_name) ||
            $this->permissions->$permission_name === Permission::BOOLEAN_TYPE_NEVER ||
            $this->permissions->$permission_name === Permission::BOOLEAN_TYPE_DISALLOW) {
            return false;
        }

        return true;
    }
}