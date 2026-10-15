<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Controller\Api\AbstractApiCrudController;
use App\Controller\Api\Traits\CanSearchResults;
use App\Controller\Api\Traits\CanSortResults;
use App\Controller\Frontend\Account\MasqueradeAction;
use App\Entity\Api\Admin\UserWithDetails;
use App\Entity\Api\Error;
use App\Entity\Api\Status;
use App\Entity\Role;
use App\Entity\User;
use App\Enums\GlobalPermissions;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Paginator;
use App\Security\UserAdministration;
use App\Utilities\Types;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpBadRequestException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** @extends AbstractApiCrudController<User> */
#[
    OA\Get(
        path: '/admin/users',
        operationId: 'getUsers',
        summary: 'List all current users in the system.',
        tags: [OpenApi::TAG_ADMIN_USERS],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: UserWithDetails::class)
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Post(
        path: '/admin/users',
        operationId: 'addUser',
        summary: 'Create a new user.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: User::class)
        ),
        tags: [OpenApi::TAG_ADMIN_USERS],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(ref: UserWithDetails::class)
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Get(
        path: '/admin/user/{id}',
        operationId: 'getUser',
        summary: 'Retrieve details for a single current user.',
        tags: [OpenApi::TAG_ADMIN_USERS],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'User ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(ref: UserWithDetails::class)
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Put(
        path: '/admin/user/{id}',
        operationId: 'editUser',
        summary: 'Update details of a single user.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: User::class)
        ),
        tags: [OpenApi::TAG_ADMIN_USERS],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'User ID',
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
        path: '/admin/user/{id}',
        operationId: 'deleteUser',
        summary: 'Delete a single user.',
        tags: [OpenApi::TAG_ADMIN_USERS],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'User ID',
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
class UsersController extends AbstractApiCrudController
{
    use CanSortResults;
    use CanSearchResults;

    protected string $entityClass = User::class;
    protected string $resourceRouteName = 'api:admin:user';

    public function __construct(
        Serializer $serializer,
        ValidatorInterface $validator,
        private readonly UserAdministration $administration,
    ) {
        parent::__construct($serializer, $validator);
    }

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $this->administration->requirePermission($request, [
            GlobalPermissions::CreateUsers,
            GlobalPermissions::ManageUsers,
            GlobalPermissions::AssignRoles,
        ]);
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(User::class, 'e');

        $qb = $this->sortQueryBuilder(
            $request,
            $qb,
            [
                'name' => 'e.name',
            ],
            'e.name'
        );

        $qb = $this->searchQueryBuilder(
            $request,
            $qb,
            [
                'e.name',
                'e.email',
            ]
        );

        if ($this->administration->isSuperAdmin($request->getUser())) {
            return $this->listPaginatedFromQuery($request, $response, $qb->getQuery());
        }

        $canRead = $request->getAcl()->userAllowed([
            GlobalPermissions::ManageUsers,
            GlobalPermissions::AssignRoles,
        ], $request->getUser());
        $rows = $canRead ? array_values(array_filter(
            $qb->getQuery()->getResult(),
            fn(User $user) => $this->administration->canAdministerUser($request->getUser(), $user)
        )) : [];
        $paginator = Paginator::fromArray($rows, $request);
        $paginator->setPostprocessor(fn(User $user) => $this->viewRecord($user, $request));
        return $paginator->write($response);
    }

    protected function createRecord(ServerRequest $request, array $data): object
    {
        $this->administration->requirePermission($request, GlobalPermissions::CreateUsers);
        return parent::createRecord($request, $this->authorizeData($request, $data));
    }

    protected function getRecord(ServerRequest $request, array $params): ?object
    {
        $this->administration->requirePermission($request, [
            GlobalPermissions::ManageUsers,
            GlobalPermissions::AssignRoles,
        ]);
        $record = parent::getRecord($request, $params);
        if ($record instanceof User) {
            $this->administration->require(
                $request,
                $this->administration->canAdministerUser($request->getUser(), $record)
            );
        }
        return $record;
    }

    protected function viewRecord(object $record, ServerRequest $request): mixed
    {
        $isSuperAdmin = $this->administration->isSuperAdmin($request->getUser());
        if ($isSuperAdmin) {
            $return = $this->toArray($record);
        } else {
            $return = [
                'id' => $record->id,
                'name' => $record->name,
                'email' => $record->email,
                'roles' => array_map(
                    static fn(Role $role) => ['id' => $role->id, 'name' => $role->name],
                    $record->roles->toArray()
                ),
            ];
        }

        $isInternal = $request->isInternal();
        $router = $request->getRouter();
        $currentUser = $request->getUser();

        $return['is_me'] = $currentUser->id === $record->id;

        $return['links'] = [
            'self' => $router->fromHere(
                routeName: $this->resourceRouteName,
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            ),
        ];

        if ($isSuperAdmin) {
            $return['links']['masquerade'] = $router->fromHere(
                routeName: 'account:masquerade',
                routeParams: [
                    'id' => $record->id,
                    'csrf' => $request->getCsrf()->generate(MasqueradeAction::CSRF_NAMESPACE),
                ],
                absolute: !$isInternal
            );
        }

        return $return;
    }

    public function editAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $record = $this->getRecord($request, $params);

        if (null === $record) {
            return $response->withStatus(404)
                ->withJson(Error::notFound());
        }

        $currentUser = $request->getUser();
        if ($record->id === $currentUser->id) {
            return $response->withStatus(403)
                ->withJson(new Error(403, __('You cannot modify yourself.')));
        }

        $data = $this->authorizeData($request, (array)$request->getParsedBody(), $record);
        $this->editRecord($data, $record);

        return $response->withJson(Status::updated());
    }

    protected function fromArray(array $data, ?object $record = null, array $context = []): object
    {
        // Self-service account endpoints use serializer groups and must never update role assignments.
        $roles = isset($context[AbstractNormalizer::GROUPS]) ? null : ($data['roles'] ?? null);
        unset($data['roles']);
        $record = parent::fromArray($data, $record, $context);

        if (null !== $roles) {
            $record->roles->clear();
            foreach ($roles as $role) {
                $record->roles->add($role);
            }
        }

        if (isset($data['new_password'])) {
            $record->setNewPassword(Types::stringOrNull($data['new_password'], true));
        }

        return $record;
    }

    public function deleteAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $this->administration->requirePermission($request, GlobalPermissions::ManageUsers);
        $record = $this->getRecord($request, $params);

        if (null === $record) {
            return $response->withStatus(404)
                ->withJson(Error::notFound());
        }

        $currentUser = $request->getUser();
        if ($record->id === $currentUser->id) {
            return $response->withStatus(403)
                ->withJson(new Error(403, __('You cannot remove yourself.')));
        }

        $this->deleteRecord($record);

        return $response->withJson(Status::deleted());
    }

    private function authorizeData(ServerRequest $request, array $data, ?User $target = null): array
    {
        $actor = $request->getUser();
        $isSuperAdmin = $this->administration->isSuperAdmin($actor);
        if (!$isSuperAdmin) {
            $this->administration->require($request, [] === array_diff(array_keys($data), [
                'name', 'email', 'new_password', 'roles', 'locale', 'show_24_hour_time',
            ]));
        }

        if (null !== $target && array_diff(array_keys($data), ['roles']) !== []) {
            $this->administration->requirePermission($request, GlobalPermissions::ManageUsers);
        }

        if (array_key_exists('roles', $data)) {
            if (!is_array($data['roles']) || !array_is_list($data['roles'])) {
                throw new HttpBadRequestException($request, 'Roles must be a list of role IDs.');
            }
            $roles = [];
            foreach ($data['roles'] as $id) {
                if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id < 1) {
                    throw new HttpBadRequestException($request, 'Roles must contain only role IDs.');
                }
                $role = $this->em->find(Role::class, (int)$id);
                if (!$role instanceof Role) {
                    throw new HttpBadRequestException($request, 'Role not found.');
                }
                $this->administration->require($request, $this->administration->canAssignRole($actor, $role));
                $roles[$role->id] = $role;
            }
            if (null !== $target) {
                $oldIds = array_map(static fn(Role $role) => $role->id, $target->roles->toArray());
                $newIds = array_keys($roles);
                sort($oldIds);
                sort($newIds);
                if ($oldIds !== $newIds) {
                    $this->administration->requirePermission($request, GlobalPermissions::AssignRoles);
                }
            }
            $data['roles'] = array_values($roles);
        }

        return $data;
    }
}
