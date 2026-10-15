<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin\Vue;

use App\Controller\SingleActionInterface;
use App\Entity\Api\Admin\Vue\PermissionsProps;
use App\Entity\Repository\RoleRepository;
use App\Entity\Repository\StationRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Security\UserAdministration;
use Psr\Http\Message\ResponseInterface;

final readonly class PermissionsAction implements SingleActionInterface
{
    public function __construct(
        private StationRepository $stationRepo,
        private RoleRepository $roleRepo,
        private UserAdministration $administration,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $actions = $request->getAcl()->listPermissions();

        $assignableRoles = [];
        $stations = $this->stationRepo->fetchSelect();
        $stationPermissionsByStation = [];
        if ($this->administration->isSuperAdmin($request->getUser())) {
            foreach ($this->roleRepo->getRepository()->findAll() as $role) {
                if ($this->administration->isOrdinaryRole($role)) {
                    $assignableRoles[$role->id] = $role->name;
                }
            }
        } else {
            $actions['global'] = array_filter(
                $actions['global'],
                fn(string $permission) => $this->administration->canGrantPermission($request->getUser(), $permission),
                ARRAY_FILTER_USE_KEY
            );
            foreach ($stations as $stationId => $stationName) {
                $stationPermissionsByStation[$stationId] = array_filter(
                    $actions['station'],
                    fn(string $permission) => $this->administration->canGrantPermission(
                        $request->getUser(),
                        $permission,
                        (int)$stationId
                    ),
                    ARRAY_FILTER_USE_KEY
                );
                if ($stationPermissionsByStation[$stationId] === []) {
                    unset($stations[$stationId]);
                }
            }
        }

        return $response->withJson(
            new PermissionsProps(
                stations: $stations,
                globalPermissions: $actions['global'],
                stationPermissions: $actions['station'],
                assignableRoles: $assignableRoles,
                stationPermissionsByStation: $stationPermissionsByStation,
            )
        );
    }
}
