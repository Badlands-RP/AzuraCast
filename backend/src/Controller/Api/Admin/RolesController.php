<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Acl;
use App\Controller\Api\AbstractApiCrudController;
use App\Controller\Api\Traits\CanSearchResults;
use App\Controller\Api\Traits\CanSortResults;
use App\Entity\Api\Admin\Role as ApiRole;
use App\Entity\Api\Error;
use App\Entity\Api\Status;
use App\Entity\Repository\RolePermissionRepository;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\Station;
use App\Enums\GlobalPermissions;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Paginator;
use App\Security\UserAdministration;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Exception\HttpBadRequestException;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** @extends AbstractApiCrudController<Role> */
#[
    OA\Get(
        path: '/admin/roles',
        operationId: 'getRoles',
        summary: 'List all current roles in the system.',
        tags: [OpenApi::TAG_ADMIN_ROLES],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        ref: ApiRole::class
                    )
                )
            ),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Post(
        path: '/admin/roles',
        operationId: 'addRole',
        summary: 'Create a new role.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: ApiRole::class)
        ),
        tags: [OpenApi::TAG_ADMIN_ROLES],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    ref: ApiRole::class
                )
            ),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Get(
        path: '/admin/role/{id}',
        operationId: 'getRole',
        summary: 'Retrieve details for a single current role.',
        tags: [OpenApi::TAG_ADMIN_ROLES],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Role ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    ref: ApiRole::class
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Put(
        path: '/admin/role/{id}',
        operationId: 'editRole',
        summary: 'Update details of a single role.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: ApiRole::class)
        ),
        tags: [OpenApi::TAG_ADMIN_ROLES],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Role ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Delete(
        path: '/admin/role/{id}',
        operationId: 'deleteRole',
        summary: 'Delete a single role.',
        tags: [OpenApi::TAG_ADMIN_ROLES],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Role ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class RolesController extends AbstractApiCrudController
{
    use CanSortResults;
    use CanSearchResults;

    protected string $entityClass = Role::class;
    protected string $resourceRouteName = 'api:admin:role';

    private readonly Role $superAdminRole;

    public function __construct(
        Serializer $serializer,
        ValidatorInterface $validator,
        RolePermissionRepository $permissionRepo,
        private readonly Acl $acl,
        private readonly UserAdministration $administration,
    ) {
        parent::__construct($serializer, $validator);

        $this->superAdminRole = $permissionRepo->ensureSuperAdministratorRole();
    }

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $this->administration->requirePermission($request, GlobalPermissions::ManageRoles);
        $qb = $this->em->createQueryBuilder()
            ->select('r, rp')
            ->from(Role::class, 'r')
            ->leftJoin('r.permissions', 'rp');

        $qb = $this->sortQueryBuilder(
            $request,
            $qb,
            [
                'name' => 'r.name',
            ],
            'r.name'
        );

        $qb = $this->searchQueryBuilder(
            $request,
            $qb,
            [
                'r.name',
            ]
        );

        if ($this->administration->isSuperAdmin($request->getUser())) {
            return $this->listPaginatedFromQuery($request, $response, $qb->getQuery());
        }
        $rows = array_values(array_filter(
            $qb->getQuery()->getResult(),
            fn(Role $role) => $this->administration->canAdministerRole($request->getUser(), $role)
        ));
        $paginator = Paginator::fromArray($rows, $request);
        $paginator->setPostprocessor(fn(Role $role) => $this->viewRecord($role, $request));
        return $paginator->write($response);
    }

    protected function createRecord(ServerRequest $request, array $data): object
    {
        $this->administration->requirePermission($request, GlobalPermissions::ManageRoles);
        return parent::createRecord($request, $this->authorizeData($request, $data));
    }

    protected function getRecord(ServerRequest $request, array $params): ?object
    {
        $this->administration->requirePermission($request, GlobalPermissions::ManageRoles);
        $record = parent::getRecord($request, $params);
        if ($record instanceof Role) {
            $this->administration->require(
                $request,
                $this->administration->canAdministerRole($request->getUser(), $record)
            );
        }
        return $record;
    }

    public function editAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $record = $this->getRecord($request, $params);
        if (null === $record) {
            return $response->withStatus(404)->withJson(Error::notFound());
        }
        $this->editRecord($this->authorizeData($request, (array)$request->getParsedBody()), $record);
        return $response->withJson(Status::updated());
    }

    protected function viewRecord(object $record, ServerRequest $request): ApiRole
    {
        $isInternal = $request->isInternal();
        $router = $request->getRouter();

        $apiRole = ApiRole::fromRole($record);
        $apiRole->is_super_admin = $record->id === $this->superAdminRole->id;
        $apiRole->links = [
            'self' => $router->fromHere(
                routeName: $this->resourceRouteName,
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            ),
        ];

        return $apiRole;
    }

    protected function editRecord(?array $data, ?object $record = null, array $context = []): object
    {
        if (
            null !== $record
            && $this->superAdminRole->id === $record->id
        ) {
            throw new RuntimeException('Cannot modify the Super Administrator role.');
        }

        $result = $this->em->wrapInTransaction(fn() => parent::editRecord($data, $record, $context));
        $this->acl->reload();
        return $result;
    }

    protected function deleteRecord(object $record): void
    {
        if ($this->superAdminRole->id === $record->id) {
            throw new RuntimeException('Cannot remove the Super Administrator role.');
        }

        parent::deleteRecord($record);
        $this->acl->reload();
    }

    protected function fromArray(array $data, $record = null, array $context = []): object
    {
        $assignableRoles = $data['assignable_roles'] ?? null;
        unset($data['assignable_roles']);
        $permissions = null;
        if (isset($data['permissions'])) {
            $permissions = (array)$data['permissions'];
            unset($data['permissions']);
        }

        $record = parent::fromArray($data, $record, $context);

        if (null !== $assignableRoles) {
            $record->assignable_roles->clear();
            foreach ($assignableRoles as $role) {
                $record->assignable_roles->add($role);
            }
        }

        if (null !== $permissions) {
            $this->doUpdatePermissions($record, $permissions);
        }

        return $record;
    }

    private function authorizeData(ServerRequest $request, array $data): array
    {
        $actor = $request->getUser();
        $isSuperAdmin = $this->administration->isSuperAdmin($actor);
        $allowedFields = $isSuperAdmin
            ? ['name', 'permissions', 'assignable_roles', 'is_protected']
            : ['name', 'permissions'];
        $this->administration->require($request, [] === array_diff(array_keys($data), $allowedFields));

        if (array_key_exists('assignable_roles', $data)) {
            if (!is_array($data['assignable_roles']) || !array_is_list($data['assignable_roles'])) {
                throw new HttpBadRequestException($request, 'Assignable roles must be a list of role IDs.');
            }
            $roles = [];
            foreach ($data['assignable_roles'] as $id) {
                if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id < 1) {
                    throw new HttpBadRequestException($request, 'Assignable roles must contain only role IDs.');
                }
                $role = $this->em->find(Role::class, (int)$id);
                if (!$role instanceof Role || !$this->administration->isOrdinaryRole($role)) {
                    throw new HttpBadRequestException($request, 'Only ordinary, unprotected roles can be delegated.');
                }
                $roles[$role->id] = $role;
            }
            $data['assignable_roles'] = array_values($roles);
        }

        if (array_key_exists('permissions', $data)) {
            if (!is_array($data['permissions'])) {
                throw new HttpBadRequestException($request, 'Permissions must be an object.');
            }
            $input = $data['permissions'];
            if (array_diff(array_keys($input), ['global', 'station']) !== []) {
                throw new HttpBadRequestException($request, 'Unknown permission scope.');
            }
            $global = $input['global'] ?? [];
            $stations = $input['station'] ?? [];
            if (!is_array($global) || !array_is_list($global) || !is_array($stations)) {
                throw new HttpBadRequestException($request, 'Invalid permissions.');
            }
            foreach ($global as $permission) {
                $this->administration->require(
                    $request,
                    is_string($permission) && $this->administration->canGrantPermission($actor, $permission)
                );
            }
            $normalizedStations = [];
            foreach ($stations as $id => $permissions) {
                if (!is_array($permissions)) {
                    throw new HttpBadRequestException($request, 'Invalid station permissions.');
                }
                if (isset($permissions['id'])) {
                    $id = $permissions['id'];
                    $permissions = $permissions['permissions'] ?? [];
                }
                if (
                    (!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id < 1
                    || !is_array($permissions) || !array_is_list($permissions)
                    || !$this->em->find(Station::class, (int)$id) instanceof Station
                ) {
                    throw new HttpBadRequestException($request, 'Invalid station permissions.');
                }
                foreach ($permissions as $permission) {
                    $this->administration->require(
                        $request,
                        is_string($permission)
                            && $this->administration->canGrantPermission($actor, $permission, (int)$id)
                    );
                }
                $normalizedStations[(int)$id] = $permissions;
            }
            $data['permissions'] = ['global' => $global, 'station' => $normalizedStations];
        }

        return $data;
    }

    private function doUpdatePermissions(Role $role, array $newPermissions): void
    {
        $existingPerms = $role->permissions;
        if ($existingPerms->count() > 0) {
            foreach ($existingPerms as $perm) {
                $this->em->remove($perm);
            }
            $this->em->flush();
            $existingPerms->clear();
        }

        if (isset($newPermissions['global'])) {
            foreach ($newPermissions['global'] as $permName) {
                if ($this->acl->isValidPermission($permName, true)) {
                    $permRecord = new RolePermission($role, null, $permName);
                    $this->em->persist($permRecord);
                    $existingPerms->add($permRecord);
                }
            }
        }

        if (isset($newPermissions['station'])) {
            foreach ($newPermissions['station'] as $stationId => $stationPerms) {
                // Accept both { id: perms[] } and [ { id: 1, perms: string[] } ] formats.
                if (isset($stationPerms['id'])) {
                    $stationId = $stationPerms['id'];
                    $stationPerms = $stationPerms['permissions'] ?? [];
                }

                $station = $this->em->find(Station::class, $stationId);

                if ($station instanceof Station) {
                    foreach ($stationPerms as $permName) {
                        if ($this->acl->isValidPermission($permName, false)) {
                            $permRecord = new RolePermission($role, $station, $permName);
                            $this->em->persist($permRecord);
                            $existingPerms->add($permRecord);
                        }
                    }
                }
            }
        }
    }
}
