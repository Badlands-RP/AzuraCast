<?php

declare(strict_types=1);

namespace Unit;

use App\Acl;
use App\Controller\Api\Admin\RolesController;
use App\Controller\Api\Admin\UsersController;
use App\Controller\Api\Admin\Vue\PermissionsAction;
use App\Controller\Api\Admin\Vue\UsersAction;
use App\Controller\Api\Frontend\Account\GetMeAction;
use App\Controller\Api\Frontend\Account\PutMeAction;
use App\Doctrine\DecoratedEntityManager;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Repository\RolePermissionRepository;
use App\Entity\Repository\RoleRepository;
use App\Entity\Repository\SettingsRepository;
use App\Entity\Repository\StationRepository;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\Settings;
use App\Entity\Station;
use App\Entity\User;
use App\Enums\GlobalPermissions;
use App\Enums\StationPermissions;
use App\Environment;
use App\Http\HttpFactory;
use App\Http\RouterInterface;
use App\Http\ServerRequest;
use App\Security\UserAdministration;
use App\Service\Avatar;
use App\Session\Csrf;
use Azura\Normalizer\DoctrineEntityNormalizer;
use DI\Container;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\SchemaTool;
use Mezzio\Session\Session;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Runs the real API routes, ACL, serializers and Doctrine mutations against an isolated SQLite database. */
final class DelegatedUserAdministrationTest extends TestCase
{
    private DecoratedEntityManager $em;
    private Acl $acl;
    private UserAdministration $policy;
    private App $app;
    private HttpFactory $http;
    private User $actor;
    private User $superAdmin;
    private User $dj;
    private Role $administratorRole;
    private Role $djRole;
    private Role $staffRole;
    private Role $superRole;
    private Station $station;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../../backend/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->em = new DecoratedEntityManager(fn() => new EntityManager($connection, $config));
        (new SchemaTool($this->em))->createSchema(array_map(
            $this->em->getClassMetadata(...),
            [Role::class, User::class, RolePermission::class]
        ));

        // Only station identities are needed by these controllers; radio services are outside this test.
        $connection->executeStatement('CREATE TABLE station (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('INSERT INTO station (id) VALUES (1), (2)');
        $this->station = new Station();
        (new ReflectionProperty(Station::class, 'id'))->setValue($this->station, 1);
        $this->em->getUnitOfWork()->registerManaged($this->station, ['id' => 1], []);
        $otherStation = new Station();
        (new ReflectionProperty(Station::class, 'id'))->setValue($otherStation, 2);
        $this->em->getUnitOfWork()->registerManaged($otherStation, ['id' => 2], []);

        $this->superRole = $this->role('Super Administrator', [GlobalPermissions::All]);
        $this->administratorRole = $this->role('Radio Admin', [
            GlobalPermissions::CreateUsers, GlobalPermissions::ManageUsers,
            GlobalPermissions::AssignRoles, GlobalPermissions::ManageRoles,
        ], [StationPermissions::All]);
        $this->djRole = $this->role('DJ', [], [StationPermissions::View]);
        $this->staffRole = $this->role('Radio Staff', [], [StationPermissions::Media]);
        $this->administratorRole->assignable_roles->add($this->djRole);
        $this->administratorRole->assignable_roles->add($this->staffRole);
        $this->superAdmin = $this->user('owner@example.com', [$this->superRole]);
        $this->actor = $this->user('radio-admin@example.com', [$this->administratorRole]);
        $this->dj = $this->user('dj@example.com', [$this->djRole]);
        $this->em->flush();

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);
        $this->acl = new Acl($this->em, $dispatcher);
        $this->policy = new UserAdministration($this->acl);
        $metadata = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([
            new DoctrineEntityNormalizer($this->em, $metadata),
            new ObjectNormalizer(classMetadataFactory: $metadata, propertyTypeExtractor: new ReflectionExtractor()),
        ]);
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());
        $permissionRepo = new RolePermissionRepository();
        $permissionRepo->setEntityManager($this->em);
        $users = new UsersController($serializer, $validator, $this->policy);
        $roles = new RolesController($serializer, $validator, $permissionRepo, $this->acl, $this->policy);
        $users->setEntityManager($this->em);
        $roles->setEntityManager($this->em);
        $roleRepo = new RoleRepository();
        $roleRepo->setEntityManager($this->em);
        // Stub unrelated station/settings reads at the ORM boundary; keep the final repositories intact.
        $stationQuery = $this->createStub(Query::class);
        $stationQuery->method('getArrayResult')->willReturn([
            ['id' => 1, 'name' => 'Radio station'],
            ['id' => 2, 'name' => 'Other station'],
        ]);
        $stationQueryBuilder = $this->createStub(QueryBuilder::class);
        $stationQueryBuilder->method('select')->willReturnSelf();
        $stationQueryBuilder->method('from')->willReturnSelf();
        $stationQueryBuilder->method('getQuery')->willReturn($stationQuery);
        $settings = new Settings();
        $settings->app_unique_identifier = '00000000-0000-0000-0000-000000000001';
        $settings->avatar_service = Avatar::SERVICE_DISABLED;
        $settingsEntityRepo = $this->createStub(EntityRepository::class);
        $settingsEntityRepo->method('find')->willReturn($settings);
        $settingsEntityRepo->method('findOneBy')->willReturn($settings);
        $metadataEm = $this->createStub(ReloadableEntityManagerInterface::class);
        $metadataEm->method('getRepository')->willReturn($settingsEntityRepo);
        $metadataEm->method('createQueryBuilder')->willReturn($stationQueryBuilder);
        $stationRepo = new StationRepository();
        $stationRepo->setEntityManager($metadataEm);
        $settingsRepo = new SettingsRepository($serializer, $validator);
        $settingsRepo->setEntityManager($metadataEm);
        $avatar = new Avatar();
        $avatar->setSettingsRepo($settingsRepo);
        $getMe = new GetMeAction($avatar, $serializer, $validator, $this->policy);
        $putMe = new PutMeAction($serializer, $validator, $this->policy);
        $getMe->setEntityManager($this->em);
        $putMe->setEntityManager($this->em);

        $this->http = new HttpFactory();
        $this->app = AppFactory::create($this->http, new Container([
            UsersController::class => $users,
            RolesController::class => $roles,
            UsersAction::class => new UsersAction($roleRepo, $this->policy),
            PermissionsAction::class => new PermissionsAction($stationRepo, $roleRepo, $this->policy),
            GetMeAction::class => $getMe,
            PutMeAction::class => $putMe,
        ]));
        $routes = require __DIR__ . '/../../backend/config/routes/api_admin.php';
        $this->app->group('/api', function (RouteCollectorProxy $group) use ($routes): void {
            $routes($group);
        });
        $frontendRoutes = require __DIR__ . '/../../backend/config/routes/api_frontend.php';
        $this->app->group('/api', function (RouteCollectorProxy $group) use ($frontendRoutes): void {
            $frontendRoutes($group);
        });
        $this->app->addRoutingMiddleware();
        $this->app->addErrorMiddleware(false, false, false);
    }

    public function testApprovedUsersCanBeCreatedEditedAndAssigned(): void
    {
        $response = $this->request('POST', '/api/admin/users', [
            'name' => 'New DJ', 'email' => 'new-dj@example.com', 'new_password' => 'A strong password!',
            'roles' => [$this->djRole->id],
        ]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $created = $this->json($response);
        self::assertSame($this->djRole->id, $created['roles'][0]['id']);
        self::assertArrayNotHasKey('api_keys', $created);
        self::assertArrayNotHasKey('two_factor_secret', $created);
        self::assertArrayNotHasKey('masquerade', $created['links']);

        self::assertSame(200, $this->request('PUT', '/api/admin/user/' . $created['id'], [
            'name' => 'Renamed DJ', 'new_password' => 'A different strong password!',
        ])->getStatusCode());
        $user = $this->em->find(User::class, $created['id']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Renamed DJ', $user->name);
        self::assertTrue($user->verifyPassword('A different strong password!'));
        self::assertSame(200, $this->request('PUT', '/api/admin/user/' . $created['id'], [
            'roles' => [$this->djRole->id, $this->staffRole->id],
        ])->getStatusCode());
        self::assertCount(2, $user->roles);
        self::assertSame(200, $this->request('PUT', '/api/admin/user/' . $created['id'], [
            'roles' => [],
        ])->getStatusCode());
        self::assertCount(0, $user->roles);
    }

    public function testArbitraryAndSuperAdministratorRolesAreDeniedBeforeCreation(): void
    {
        $unapproved = $this->role('Unapproved DJ', [], [StationPermissions::View]);
        $this->acl->reload();
        foreach ([$unapproved->id, $this->superRole->id] as $id) {
            $response = $this->request('POST', '/api/admin/users', [
                'email' => 'attacker@example.com', 'roles' => [$id],
            ]);
            self::assertSame(403, $response->getStatusCode());
        }
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'attacker@example.com']));
    }

    public function testProtectedPeersAndSelfCannotBeReadEditedOrRemoved(): void
    {
        $peer = $this->user('peer@example.com', [$this->administratorRole]);
        $protected = $this->user('protected@example.com', [$this->djRole]);
        $protected->is_protected = true;
        $this->em->flush();
        foreach ([$this->actor, $this->superAdmin, $peer, $protected] as $user) {
            foreach (['GET', 'PUT', 'DELETE'] as $method) {
                self::assertSame(403, $this->request($method, '/api/admin/user/' . $user->id, [
                    'roles' => [$this->djRole->id],
                ])->getStatusCode());
            }
        }
        $listed = $this->json($this->request('GET', '/api/admin/users'));
        self::assertSame([$this->dj->id], array_column($listed, 'id'));
    }

    public function testPrivilegesFromEveryTargetRoleMustBeWithinAuthority(): void
    {
        $otherRole = $this->role('Other station', [], [], 2);
        $permission = new RolePermission($otherRole, $this->em->find(Station::class, 2), StationPermissions::View);
        $otherRole->permissions->add($permission);
        $this->em->persist($permission);
        $this->administratorRole->assignable_roles->add($otherRole);
        $this->dj->roles->add($otherRole);
        $this->em->flush();
        $this->acl->reload();
        self::assertFalse($this->policy->canAssignRole($this->actor, $otherRole));
        self::assertSame(403, $this->request('PUT', '/api/admin/user/' . $this->dj->id, [
            'roles' => [$this->djRole->id],
        ])->getStatusCode());
    }

    public function testCreateManageAndAssignPermissionsRemainIndependent(): void
    {
        $creatorRole = $this->role('Creator', [GlobalPermissions::CreateUsers], [StationPermissions::View]);
        $creatorRole->assignable_roles->add($this->djRole);
        $creator = $this->user('creator@example.com', [$creatorRole]);
        $managerRole = $this->role('Manager', [GlobalPermissions::ManageUsers], [StationPermissions::All]);
        $managerRole->assignable_roles->add($this->djRole);
        $managerRole->assignable_roles->add($this->staffRole);
        $manager = $this->user('manager@example.com', [$managerRole]);
        $assignerRole = $this->role('Assigner', [GlobalPermissions::AssignRoles], [StationPermissions::All]);
        $assignerRole->assignable_roles->add($this->djRole);
        $assignerRole->assignable_roles->add($this->staffRole);
        $assigner = $this->user('assigner@example.com', [$assignerRole]);
        $this->em->flush();
        $this->acl->reload();

        self::assertSame(200, $this->request('POST', '/api/admin/users', [
            'email' => 'created@example.com', 'roles' => [$this->djRole->id],
        ], $creator)->getStatusCode());
        self::assertSame([], $this->json($this->request('GET', '/api/admin/users', [], $creator)));
        self::assertSame(403, $this->request('PUT', '/api/admin/user/' . $this->dj->id, [
            'name' => 'Denied',
        ], $creator)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/admin/users', [
            'email' => 'denied@example.com',
        ], $manager)->getStatusCode());
        self::assertSame(200, $this->request('PUT', '/api/admin/user/' . $this->dj->id, [
            'name' => 'Allowed', 'roles' => [$this->djRole->id],
        ], $manager)->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/admin/user/' . $this->dj->id, [
            'roles' => [$this->staffRole->id],
        ], $manager)->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/admin/user/' . $this->dj->id, [
            'email' => 'takeover@example.com',
        ], $assigner)->getStatusCode());
        $delete = $this->request('DELETE', '/api/admin/user/' . $this->dj->id, [], $assigner);
        self::assertSame(403, $delete->getStatusCode());
        self::assertSame(200, $this->request('PUT', '/api/admin/user/' . $this->dj->id, [
            'roles' => [$this->staffRole->id],
        ], $assigner)->getStatusCode());
    }

    public function testNestedPayloadsAndCredentialRelationsCannotBypassAuthorization(): void
    {
        foreach (
            [
            ['roles' => [['id' => $this->djRole->id, 'permissions' => ['global' => [GlobalPermissions::All->value]]]]],
            ['api_keys' => [['user' => $this->superAdmin->id]]],
            ['passkeys' => []], ['login_tokens' => []], ['is_protected' => false],
            ['two_factor_secret' => 'injected'], ['auth_password' => 'injected'],
            ] as $data
        ) {
            $response = $this->request('PUT', '/api/admin/user/' . $this->dj->id, $data);
            self::assertGreaterThanOrEqual(400, $response->getStatusCode());
            self::assertLessThan(500, $response->getStatusCode(), (string)$response->getBody());
        }
        self::assertSame([$this->djRole], $this->dj->roles->toArray());
    }

    public function testRoleEditsCannotIncreaseTheDelegationCeilingOrChangeOwnAuthority(): void
    {
        foreach (
            [
            ['permissions' => ['global' => [GlobalPermissions::All->value]]],
            ['permissions' => ['global' => [GlobalPermissions::AssignRoles->value]]],
            ['permissions' => ['global' => [GlobalPermissions::ApiKeys->value]]],
            ['permissions' => ['station' => [2 => [StationPermissions::View->value]]]],
            ['permissions' => ['station' => [['id' => 2, 'permissions' => [StationPermissions::View->value]]]]],
            ['permissions' => ['station' => [1 => [StationPermissions::All->value]]]],
            ['assignable_roles' => [$this->superRole->id]], ['is_protected' => false],
            ['users' => [$this->actor->id]],
            ] as $data
        ) {
            $response = $this->request('PUT', '/api/admin/role/' . $this->djRole->id, $data);
            self::assertSame(403, $response->getStatusCode());
        }
        self::assertSame(403, $this->request('PUT', '/api/admin/role/' . $this->administratorRole->id, [
            'name' => 'Own role',
        ])->getStatusCode());
        self::assertSame(403, $this->request('DELETE', '/api/admin/role/' . $this->superRole->id)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/admin/roles', [
            'name' => 'Escalation', 'permissions' => ['global' => [GlobalPermissions::All->value]],
        ])->getStatusCode());
        self::assertSame(200, $this->request('PUT', '/api/admin/role/' . $this->djRole->id, [
            'name' => 'DJ Renamed',
            'permissions' => ['station' => [1 => [StationPermissions::View->value, StationPermissions::Media->value]]],
        ])->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/admin/roles', [
            'name' => 'New ordinary role',
            'permissions' => ['station' => [['id' => 1, 'permissions' => [StationPermissions::View->value]]]],
        ])->getStatusCode());
    }

    public function testSharedRolesAndOwnOrdinaryRolesCannotBeEditedIndirectly(): void
    {
        $this->superAdmin->roles->add($this->djRole);
        $this->em->flush();
        $this->em->refresh($this->djRole);
        self::assertSame(403, $this->request('PUT', '/api/admin/role/' . $this->djRole->id, [
            'permissions' => ['global' => []],
        ])->getStatusCode());
        self::assertSame(403, $this->request('DELETE', '/api/admin/role/' . $this->djRole->id)->getStatusCode());
        $this->actor->roles->add($this->staffRole);
        $this->em->flush();
        self::assertSame(403, $this->request('PUT', '/api/admin/role/' . $this->staffRole->id, [
            'name' => 'Indirect self-edit',
        ])->getStatusCode());
    }

    public function testSensitiveRolesStayProtectedEvenWhenExplicitlyAllowlisted(): void
    {
        foreach ([GlobalPermissions::All, GlobalPermissions::ApiKeys, GlobalPermissions::ManageUsers] as $permission) {
            $role = $this->role('Sensitive', [$permission]);
            $this->administratorRole->assignable_roles->add($role);
            $this->em->flush();
            $this->acl->reload();
            self::assertFalse($this->policy->canAssignRole($this->actor, $role));
        }
        $this->djRole->is_protected = true;
        self::assertFalse($this->policy->canAssignRole($this->actor, $this->djRole));
    }

    public function testSuperAdministratorConfiguresDelegationAndRetainsAccess(): void
    {
        self::assertSame(200, $this->request('PUT', '/api/admin/role/' . $this->administratorRole->id, [
            'assignable_roles' => [$this->djRole->id], 'is_protected' => true,
        ], $this->superAdmin)->getStatusCode());
        self::assertCount(1, $this->administratorRole->assignable_roles);
        self::assertSame(200, $this->request('PUT', '/api/admin/user/' . $this->actor->id, [
            'is_protected' => true,
        ], $this->superAdmin)->getStatusCode());
        self::assertTrue($this->actor->is_protected);
        $response = $this->request('GET', '/api/admin/user/' . $this->actor->id, [], $this->superAdmin);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/admin/users', [
            'email' => 'second-owner@example.com', 'roles' => [$this->superRole->id],
        ], $this->superAdmin)->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/admin/user/' . $this->superAdmin->id, [
            'name' => 'Self edit remains forbidden',
        ], $this->superAdmin)->getStatusCode());
    }

    public function testDelegationIsEmptyByDefaultAndRevocationTakesEffect(): void
    {
        $unconfigured = $this->role('Unconfigured', [GlobalPermissions::CreateUsers], [StationPermissions::All]);
        $unconfiguredUser = $this->user('unconfigured@example.com', [$unconfigured]);
        $this->acl->reload();
        self::assertFalse($this->policy->canAssignRole($unconfiguredUser, $this->djRole));
        $this->administratorRole->assignable_roles->clear();
        $this->em->flush();
        self::assertSame(403, $this->request('POST', '/api/admin/users', [
            'email' => 'revoked@example.com', 'roles' => [$this->djRole->id],
        ])->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/admin/user/' . $this->dj->id, [
            'new_password' => 'Cannot reset after revocation!',
        ])->getStatusCode());
    }

    public function testActorAuthorityCanComeFromMultipleRolesWithoutWideningDelegation(): void
    {
        $weakAdmin = $this->role('Administration only', [GlobalPermissions::CreateUsers]);
        $weakAdmin->assignable_roles->add($this->djRole);
        $actor = $this->user('split-authority@example.com', [$weakAdmin]);
        $this->acl->reload();
        self::assertFalse($this->policy->canAssignRole($actor, $this->djRole));
        $actor->roles->add($this->djRole);
        $this->em->flush();
        self::assertTrue($this->policy->canAssignRole($actor, $this->djRole));
        self::assertFalse($this->policy->canAssignRole($actor, $this->staffRole));
        self::assertFalse($this->policy->canGrantPermission($actor, StationPermissions::All->value, 1));
    }

    public function testDelegationDoesNotOpenCredentialOrSystemAdministrationEndpoints(): void
    {
        foreach (
            [
            '/api/admin/api-keys', '/api/admin/login_tokens', '/api/admin/settings',
            '/api/admin/stations', '/api/admin/backups', '/api/admin/permissions',
            ] as $path
        ) {
            self::assertSame(403, $this->request('GET', $path)->getStatusCode(), $path);
        }
        $ordinaryUser = $this->user('ordinary@example.com', [$this->djRole]);
        self::assertSame(403, $this->request('POST', '/api/admin/users', [
            'email' => 'denied@example.com',
        ], $ordinaryUser)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/admin/roles', [
            'name' => 'Denied',
        ], $ordinaryUser)->getStatusCode());
    }

    public function testUiMetadataUsesTheSameDelegationLimitsWithoutRequiringSystemAccess(): void
    {
        $users = $this->request('GET', '/api/admin/vue/users');
        self::assertSame(200, $users->getStatusCode(), (string)$users->getBody());
        self::assertEqualsCanonicalizing(
            [$this->djRole->id, $this->staffRole->id],
            array_keys($this->json($users)['roles'])
        );
        $response = $this->request('GET', '/api/admin/vue/permissions');
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $roles = $this->json($response);
        self::assertArrayNotHasKey(GlobalPermissions::All->value, $roles['globalPermissions']);
        self::assertArrayNotHasKey(2, $roles['stations']);
        self::assertArrayNotHasKey(StationPermissions::All->value, $roles['stationPermissionsByStation'][1]);
        self::assertArrayHasKey(StationPermissions::View->value, $roles['stationPermissionsByStation'][1]);
        self::assertSame([], $roles['assignableRoles']);
    }

    public function testSelfServiceProfileStillWorksAndCannotAssignRolesOrRemoveProtection(): void
    {
        self::assertSame(200, $this->request('GET', '/api/frontend/account/me')->getStatusCode());
        $this->actor->is_protected = true;
        $this->em->flush();
        $response = $this->request('PUT', '/api/frontend/account/me', [
            'name' => 'Updated display name', 'roles' => [$this->superRole->id], 'is_protected' => false,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Updated display name', $this->actor->name);
        self::assertSame([$this->administratorRole], $this->actor->roles->toArray());
        self::assertTrue($this->actor->is_protected);
    }

    private function request(string $method, string $path, array $data = [], ?User $actor = null): ResponseInterface
    {
        $request = $this->http->createServerRequest($method, 'http://localhost' . $path);
        $router = $this->createStub(RouterInterface::class);
        $router->method('fromHere')->willReturnCallback(static fn($routeName, $routeParams = []) =>
            '/api/admin/user/' . ($routeParams['id'] ?? 0));
        $request = $request->withParsedBody($data)
            ->withAttribute(ServerRequest::ATTR_USER, $actor ?? $this->actor)
            ->withAttribute(ServerRequest::ATTR_ACL, $this->acl)
            ->withAttribute(ServerRequest::ATTR_ROUTER, $router);
        $request = $request->withAttribute(ServerRequest::ATTR_SESSION_CSRF, new Csrf(
            $request,
            new Session([]),
            new Environment([Environment::APP_ENV => 'testing'])
        ));
        return $this->app->handle($request);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function role(string $name, array $global = [], array $station = [], int $stationId = 1): Role
    {
        $role = new Role();
        $role->name = $name;
        $this->em->persist($role);
        foreach ($global as $permission) {
            $record = new RolePermission($role, null, $permission);
            $role->permissions->add($record);
            $this->em->persist($record);
        }
        foreach ($station as $permission) {
            $record = new RolePermission($role, $this->em->find(Station::class, $stationId), $permission);
            $role->permissions->add($record);
            $this->em->persist($record);
        }
        $this->em->flush();
        return $role;
    }

    private function user(string $email, array $roles): User
    {
        $user = new User();
        $user->email = $email;
        foreach ($roles as $role) {
            $user->roles->add($role);
        }
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
