<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin\Vue;

use App\Controller\SingleActionInterface;
use App\Entity\Api\Admin\Vue\UsersProps;
use App\Entity\Repository\RoleRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Security\UserAdministration;
use Psr\Http\Message\ResponseInterface;

final readonly class UsersAction implements SingleActionInterface
{
    public function __construct(
        private RoleRepository $roleRepo,
        private UserAdministration $administration,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $actor = $request->getUser();
        $roles = $this->administration->isSuperAdmin($actor)
            ? $this->roleRepo->fetchSelect()
            : array_map(static fn($role) => $role->name, $this->administration->assignableRoles($actor));
        return $response->withJson(
            new UsersProps(
                roles: $roles
            )
        );
    }
}
