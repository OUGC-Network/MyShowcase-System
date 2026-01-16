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
        public $user_id = null,
        public $show_avatars = null,
        public $suspend_signature = null,
        public $avatar = null,
        public $days_prune = null,
        public $style = null,
    ) {
        $this->groups = new Collection();
    }

    public function __call(string $name, array $arguments)
    {
        _dump('__call', $name);
        if (isset($this->methods[$name]) && is_callable($this->methods[$name])) {
            return call_user_func_array($this->methods[$name], $arguments);
        }
        throw new BadMethodCallException("Method $name does not exist");
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