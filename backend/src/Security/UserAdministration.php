<?php

declare(strict_types=1);

namespace App\Security;

use App\Acl;
use App\Entity\Role;
use App\Entity\User;
use App\Enums\GlobalPermissions;
use App\Exception\Http\PermissionDeniedException;
use App\Http\ServerRequest;

/** Object-level limits shared by the user API, role API and their UI metadata. */
final readonly class UserAdministration
{
    public function __construct(private Acl $acl)
    {
    }

    public function isSuperAdmin(User $actor): bool
    {
        return $this->acl->userAllowed(GlobalPermissions::All, $actor);
    }

    public function requirePermission(ServerRequest $request, GlobalPermissions|array $permission): void
    {
        $this->require($request, $this->acl->userAllowed($permission, $request->getUser()));
    }

    public function require(ServerRequest $request, bool $allowed): void
    {
        if (!$allowed) {
            throw PermissionDeniedException::create($request);
        }
    }

    /** Administration rights and credential access are never delegated through ordinary roles. */
    public function isOrdinaryRole(Role $role): bool
    {
        if ($role->is_protected || !$role->assignable_roles->isEmpty()) {
            return false;
        }

        foreach ($role->permissions as $permission) {
            if (
                $permission->station_id === null
                && !$this->isDelegableGlobalPermission($permission->action_name)
            ) {
                return false;
            }
        }

        return true;
    }

    public function isDelegableGlobalPermission(string $permission): bool
    {
        return !in_array($permission, [
            GlobalPermissions::All->value,
            GlobalPermissions::ApiKeys->value,
            GlobalPermissions::CreateUsers->value,
            GlobalPermissions::ManageUsers->value,
            GlobalPermissions::AssignRoles->value,
            GlobalPermissions::ManageRoles->value,
        ], true);
    }

    public function canAssignRole(User $actor, Role $role): bool
    {
        if ($this->isSuperAdmin($actor)) {
            return true;
        }

        if (!$this->isOrdinaryRole($role)) {
            return false;
        }

        $approved = false;
        foreach ($actor->roles as $actorRole) {
            if ($actorRole->assignable_roles->contains($role)) {
                $approved = true;
                break;
            }
        }

        if (!$approved) {
            return false;
        }

        foreach ($role->permissions as $permission) {
            if (
                !$this->acl->isValidPermission($permission->action_name, $permission->station_id === null)
                || !$this->acl->userAllowed($permission->action_name, $actor, $permission->station_id)
            ) {
                return false;
            }
        }

        return true;
    }

    public function canAdministerUser(User $actor, User $target): bool
    {
        if ($this->isSuperAdmin($actor)) {
            return true;
        }

        if ($actor->id === $target->id || $target->is_protected) {
            return false;
        }

        foreach ($target->roles as $role) {
            if (!$this->canAssignRole($actor, $role)) {
                return false;
            }
        }

        return true;
    }

    public function canAdministerRole(User $actor, Role $role): bool
    {
        if ($this->isSuperAdmin($actor)) {
            return true;
        }

        if (!$this->canAssignRole($actor, $role) || $actor->roles->contains($role)) {
            return false;
        }

        // Editing a shared role also changes every member's privileges.
        foreach ($role->users as $user) {
            if (!$this->canAdministerUser($actor, $user)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, Role> */
    public function assignableRoles(User $actor): array
    {
        $roles = [];
        foreach ($actor->roles as $actorRole) {
            foreach ($actorRole->assignable_roles as $role) {
                if ($this->canAssignRole($actor, $role)) {
                    $roles[$role->id] = $role;
                }
            }
        }
        return $roles;
    }

    public function canGrantPermission(User $actor, string $permission, ?int $stationId = null): bool
    {
        if (!$this->acl->isValidPermission($permission, $stationId === null)) {
            return false;
        }
        if ($this->isSuperAdmin($actor)) {
            return true;
        }
        if (
            ($stationId === null && !$this->isDelegableGlobalPermission($permission))
            || !$this->acl->userAllowed($permission, $actor, $stationId)
        ) {
            return false;
        }

        // The ceiling is the intersection of the actor's effective authority and explicit delegation.
        return $this->acl->roleAllowed(array_keys($this->assignableRoles($actor)), $permission, $stationId);
    }
}
