<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

use MyShowcase\Collection\Collection;

class GroupEntity
{
    private Collection $permissions;

    public function __construct(
        public ?int $group_id = null,
        public ?array $extra_data = null,
    ) {
        if ($this->extra_data === null) {
            $this->extra_data = [];
        }

        $this->permissions = new Collection();
    }

    public function setExtraData(string $name, int|null $value): self
    {
        $this->extra_data[$name] = $value;

        return $this;
    }

    public function getExtraData(string $name): int|null|false
    {
        if (!isset($this->extra_data[$name])) {
            return false;
        }

        return $this->extra_data[$name];
    }

    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    public function addPermission(PermissionEntity $permission_data): void
    {
        if (!$this->permissions->contains($permission_data)) {
            if (!empty($permission_data->field_id)) {
                $this->permissions[$permission_data->field_id] = $permission_data;
            } else {
                $this->permissions[] = $permission_data;
            }
        }
    }
}