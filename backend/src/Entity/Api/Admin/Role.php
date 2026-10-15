<?php

declare(strict_types=1);

namespace App\Entity\Api\Admin;

use App\Entity\Api\Traits\HasLinks;
use App\Entity\Role as RoleEntity;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Api_Admin_Role',
    required: ['name'],
    type: 'object'
)]
final class Role
{
    use HasLinks;

    public function __construct(
        #[OA\Property(
            readOnly: true
        )]
        public readonly int $id,
        #[OA\Property(
            example: "Super Administrator"
        )]
        public readonly string $name,
        #[OA\Property]
        public readonly RolePermissions $permissions,
    ) {
    }

    #[OA\Property(
        description: 'Whether this role is the protected "Super Administrator" role.',
        readOnly: true
    )]
    public bool $is_super_admin;

    #[OA\Property(type: 'array', items: new OA\Items(type: 'integer'))]
    public array $assignable_roles = [];

    #[OA\Property]
    public bool $is_protected = false;


    public static function fromRole(RoleEntity $role): self
    {
        $result = new self(
            $role->id,
            $role->name,
            RolePermissions::fromRolePermissions($role->permissions)
        );
        $result->assignable_roles = array_values(array_map(
            static fn(RoleEntity $assignableRole) => $assignableRole->id,
            $role->assignable_roles->toArray()
        ));
        $result->is_protected = $role->is_protected;
        return $result;
    }
}
