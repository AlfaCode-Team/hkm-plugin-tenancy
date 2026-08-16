<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\Application\Ports\TenantProvisioner;
use Plugins\Tenancy\Application\Ports\TenantWriteStore;
use Plugins\Tenancy\Application\Services\TenantAdminService;
use Plugins\Tenancy\Domain\Entities\Tenant;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;

#[CoversClass(TenantAdminService::class)]
final class TenantAdminServiceTest extends TestCase
{
    private FakeTenantWriteStore $store;
    private FakeTenantProvisioner $provisioner;
    private FakeTenantConnectionResolver $connections;
    private FakeAuditService $audit;

    protected function setUp(): void
    {
        $this->store       = new FakeTenantWriteStore();
        $this->provisioner = new FakeTenantProvisioner();
        $this->connections = new FakeTenantConnectionResolver();
        $this->audit       = new FakeAuditService();
    }

    private function service(Identity $identity): TenantAdminService
    {
        return new TenantAdminService(
            store:       $this->store,
            provisioner: $this->provisioner,
            registry:    new FakeTenantRegistry(),
            crypto:      new FakeCrypto(),
            identity:    $identity,
            connections: $this->connections,
            audit:       $this->audit,
        );
    }

    private function admin(): Identity
    {
        return new Identity('admin-1', '', ['platform-admin'], ['tenancy:admin'], 'jwt');
    }

    private function member(): Identity
    {
        return new Identity('user-1', 'tenant-1', ['member'], [], 'jwt');
    }

    private function owner(string $tenantId = 'tenant-1'): Identity
    {
        return new Identity('user-2', $tenantId, ['owner'], [], 'jwt');
    }

    private function validInput(): array
    {
        return [
            'name' => 'Acme', 'slug' => 'acme', 'driver' => 'mysql',
            'db_name' => 'acme_db', 'db_user' => 'acme_user', 'db_password' => 'secret',
        ];
    }

    // ── authorization ───────────────────────────────────────────────────────

    public function test_guest_cannot_list(): void
    {
        $this->expectException(SecurityException::class);
        $this->service(Identity::guest())->list();
    }

    public function test_member_cannot_create(): void
    {
        $this->expectException(SecurityException::class);
        $this->service($this->member())->create($this->validInput());
    }

    public function test_member_cannot_delete(): void
    {
        $this->expectException(SecurityException::class);
        $this->service($this->member())->delete('any');
    }

    // ── provisioning (admin) ──────────────────────────────────────────────────

    public function test_admin_creates_and_provisions_tenant(): void
    {
        $detail = $this->service($this->admin())->create($this->validInput());

        $this->assertSame('acme', $detail->slug);
        $this->assertTrue($this->provisioner->provisioned, 'provision() should be called');
        $this->assertNotNull($this->store->find($detail->tenantId));
        $this->assertSame(TenantStatus::Active->value, $this->store->find($detail->tenantId)->status->value);
    }

    public function test_invalid_slug_is_rejected_before_provisioning(): void
    {
        try {
            $this->service($this->admin())->create(['name' => 'Acme', 'slug' => 'Bad Slug!', 'db_name' => 'd', 'db_user' => 'u']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors);
            $this->assertFalse($this->provisioner->provisioned, 'must not provision on invalid input');
        }
    }

    public function test_provision_failure_compensates_and_throws(): void
    {
        $this->provisioner->failOnProvision = true;

        try {
            $this->service($this->admin())->create($this->validInput());
            $this->fail('Expected ServiceException');
        } catch (ServiceException $e) {
            $this->assertTrue($this->provisioner->toreDown, 'teardown() should compensate');
            $this->assertSame([], $this->store->rows, 'registry row should be rolled back');
        }
    }

    // ── suspend / reactivate ──────────────────────────────────────────────────

    public function test_admin_suspends_an_active_tenant(): void
    {
        $svc    = $this->service($this->admin());
        $detail = $svc->create($this->validInput());

        $suspended = $svc->suspend($detail->tenantId);

        $this->assertSame('suspended', $suspended->status);
        $this->assertContains($detail->tenantId, $this->connections->invalidated, 'suspend() must drop the warm connection immediately');
        $this->assertSame('tenant.suspended', $this->audit->recorded[array_key_last($this->audit->recorded)]['action']);
    }

    public function test_suspending_an_already_suspended_tenant_is_rejected(): void
    {
        $svc    = $this->service($this->admin());
        $detail = $svc->create($this->validInput());
        $svc->suspend($detail->tenantId);

        $this->expectException(ServiceException::class);
        $svc->suspend($detail->tenantId);
    }

    public function test_admin_reactivates_a_suspended_tenant(): void
    {
        $svc    = $this->service($this->admin());
        $detail = $svc->create($this->validInput());
        $svc->suspend($detail->tenantId);

        $reactivated = $svc->reactivate($detail->tenantId);

        $this->assertSame('active', $reactivated->status);
        $this->assertSame('tenant.reactivated', $this->audit->recorded[array_key_last($this->audit->recorded)]['action']);
    }

    public function test_reactivating_an_active_tenant_is_rejected(): void
    {
        $svc    = $this->service($this->admin());
        $detail = $svc->create($this->validInput());

        $this->expectException(ServiceException::class);
        $svc->reactivate($detail->tenantId);
    }

    public function test_member_cannot_suspend(): void
    {
        $svc    = $this->service($this->admin());
        $detail = $svc->create($this->validInput());

        $this->expectException(SecurityException::class);
        $this->service($this->member())->suspend($detail->tenantId);
    }

    // ── health check ─────────────────────────────────────────────────────────

    public function test_health_check_reports_reachable_on_success(): void
    {
        $svc    = $this->service($this->admin());
        $detail = $svc->create($this->validInput());

        $health = $svc->healthCheck($detail->tenantId);

        $this->assertTrue($health['reachable']);
        $this->assertIsInt($health['latencyMs']);
        $this->assertNull($health['error']);
    }

    public function test_health_check_reports_unreachable_without_throwing(): void
    {
        $svc    = $this->service($this->admin());
        $detail = $svc->create($this->validInput());
        $this->connections->failWith = new \RuntimeException('connection refused');

        $health = $svc->healthCheck($detail->tenantId);

        $this->assertFalse($health['reachable']);
        $this->assertNull($health['latencyMs']);
        $this->assertSame('connection refused', $health['error']);
    }

    public function test_member_cannot_health_check(): void
    {
        $svc    = $this->service($this->admin());
        $detail = $svc->create($this->validInput());

        $this->expectException(SecurityException::class);
        $this->service($this->member())->healthCheck($detail->tenantId);
    }

    // ── sub-tenants ────────────────────────────────────────────────────────────

    public function test_owner_cannot_create_sub_tenant_without_a_grant(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());

        $this->expectException(SecurityException::class);
        $this->service($this->owner($parent->tenantId))
            ->createSubTenant($parent->tenantId, ['name' => 'Branch', 'slug' => 'branch']);
    }

    public function test_member_without_owner_or_admin_role_cannot_create_sub_tenant(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());
        $svc->grantSubTenantCreation($parent->tenantId);

        $this->expectException(SecurityException::class);
        $this->service($this->member())
            ->createSubTenant($parent->tenantId, ['name' => 'Branch', 'slug' => 'branch']);
    }

    public function test_owner_creates_sub_tenant_once_granted(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());
        $svc->grantSubTenantCreation($parent->tenantId);

        $child = $this->service($this->owner($parent->tenantId))
            ->createSubTenant($parent->tenantId, ['name' => 'Branch', 'slug' => 'branch']);

        $this->assertSame('branch', $child->slug);
        $this->assertSame($parent->tenantId, $child->parentTenantId);
        $this->assertFalse($child->canCreateSubTenants, 'the grant is per-tenant, never inherited automatically');
        $this->assertSame($this->store->find($parent->tenantId)->dbDriver, $this->store->find($child->tenantId)->dbDriver);
        $this->assertContains($child->tenantId, array_map(
            static fn ($t) => $t->tenantId,
            $this->service($this->admin())->listChildren($parent->tenantId),
        ));
    }

    public function test_platform_admin_creates_sub_tenant_without_a_grant(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());

        $child = $svc->createSubTenant($parent->tenantId, ['name' => 'Branch', 'slug' => 'branch']);

        $this->assertSame($parent->tenantId, $child->parentTenantId);
    }

    public function test_owner_of_a_different_tenant_cannot_create_sub_tenant(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());
        $svc->grantSubTenantCreation($parent->tenantId);

        $this->expectException(SecurityException::class);
        $this->service($this->owner('some-other-tenant'))
            ->createSubTenant($parent->tenantId, ['name' => 'Branch', 'slug' => 'branch']);
    }

    public function test_member_can_list_own_tenants_children(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());
        $svc->grantSubTenantCreation($parent->tenantId);
        $svc->createSubTenant($parent->tenantId, ['name' => 'Branch', 'slug' => 'branch']);

        $children = $this->service(new Identity('user-3', $parent->tenantId, ['member'], [], 'jwt'))
            ->listChildren($parent->tenantId);

        $this->assertCount(1, $children);
        $this->assertSame('branch', $children[0]->slug);
    }

    public function test_member_cannot_list_another_tenants_children(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());

        $this->expectException(SecurityException::class);
        $this->service($this->member())->listChildren($parent->tenantId);
    }

    public function test_admin_grant_and_revoke_round_trip(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());

        $granted = $svc->grantSubTenantCreation($parent->tenantId);
        $this->assertTrue($granted->canCreateSubTenants);

        $revoked = $svc->revokeSubTenantCreation($parent->tenantId);
        $this->assertFalse($revoked->canCreateSubTenants);
    }

    public function test_member_cannot_grant_sub_tenant_creation(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());

        $this->expectException(SecurityException::class);
        $this->service($this->member())->grantSubTenantCreation($parent->tenantId);
    }

    public function test_deleting_a_tenant_with_children_is_rejected(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());
        $svc->grantSubTenantCreation($parent->tenantId);
        $svc->createSubTenant($parent->tenantId, ['name' => 'Branch', 'slug' => 'branch']);

        $this->expectException(ServiceException::class);
        $svc->delete($parent->tenantId);
    }

    public function test_self_service_creation_is_capped_per_parent(): void
    {
        $store       = $this->store;
        $provisioner = $this->provisioner;
        $svc = new TenantAdminService(
            store: $store, provisioner: $provisioner, registry: new FakeTenantRegistry(),
            crypto: new FakeCrypto(), identity: $this->admin(), connections: $this->connections,
            audit: $this->audit, maxSubTenantsPerParent: 1,
        );
        $parent = $svc->create($this->validInput());
        $svc->grantSubTenantCreation($parent->tenantId);

        $ownerSvc = new TenantAdminService(
            store: $store, provisioner: $provisioner, registry: new FakeTenantRegistry(),
            crypto: new FakeCrypto(), identity: $this->owner($parent->tenantId), connections: $this->connections,
            audit: $this->audit, maxSubTenantsPerParent: 1,
        );
        $ownerSvc->createSubTenant($parent->tenantId, ['name' => 'Branch 1', 'slug' => 'branch-1']);

        $this->expectException(ServiceException::class);
        $ownerSvc->createSubTenant($parent->tenantId, ['name' => 'Branch 2', 'slug' => 'branch-2']);
    }

    public function test_platform_admin_bypasses_the_sub_tenant_quota(): void
    {
        $svc = new TenantAdminService(
            store: $this->store, provisioner: $this->provisioner, registry: new FakeTenantRegistry(),
            crypto: new FakeCrypto(), identity: $this->admin(), connections: $this->connections,
            audit: $this->audit, maxSubTenantsPerParent: 1,
        );
        $parent = $svc->create($this->validInput());
        $svc->createSubTenant($parent->tenantId, ['name' => 'Branch 1', 'slug' => 'branch-1']);
        $svc->createSubTenant($parent->tenantId, ['name' => 'Branch 2', 'slug' => 'branch-2']);

        $this->assertCount(2, $svc->listChildren($parent->tenantId));
    }

    public function test_sub_tenant_creation_is_audited_under_the_acting_user(): void
    {
        $svc    = $this->service($this->admin());
        $parent = $svc->create($this->validInput());
        $svc->grantSubTenantCreation($parent->tenantId);

        $owner = $this->owner($parent->tenantId);
        $this->service($owner)->createSubTenant($parent->tenantId, ['name' => 'Branch', 'slug' => 'branch']);

        $entry = $this->audit->recorded[array_key_last($this->audit->recorded)];
        $this->assertSame('tenant.sub_tenant.created', $entry['action']);
        $this->assertSame($owner->userId, $entry['userId']);
    }
}

// ── in-memory fakes ───────────────────────────────────────────────────────────

final class FakeTenantWriteStore implements TenantWriteStore
{
    /** @var array<string, Tenant> */
    public array $rows = [];

    public function paginate(int $limit, int $offset, ?string $search = null, ?int $status = null): array
    {
        $rows = array_values(array_filter(
            $this->rows,
            static function (Tenant $t) use ($search, $status): bool {
                if ($status !== null && $t->status->value !== $status) {
                    return false;
                }
                if ($search !== null && $search !== ''
                    && !str_contains(strtolower($t->name), strtolower($search))
                    && !str_contains(strtolower($t->slug), strtolower($search))) {
                    return false;
                }
                return true;
            },
        ));

        return array_slice($rows, $offset, $limit);
    }

    public function count(?string $search = null, ?int $status = null): int
    {
        return count($this->paginate(PHP_INT_MAX, 0, $search, $status));
    }

    public function find(string $tenantId): ?Tenant
    {
        return $this->rows[$tenantId] ?? null;
    }

    public function slugExists(string $slug, ?string $exceptId = null): bool
    {
        foreach ($this->rows as $t) {
            if ($t->slug === $slug && $t->tenantId !== $exceptId) {
                return true;
            }
        }
        return false;
    }

    public function insert(Tenant $tenant): void { $this->rows[$tenant->tenantId] = $tenant; }

    public function markActive(string $tenantId, int $schemaVersion): void
    {
        $this->rows[$tenantId] = $this->withStatus($this->rows[$tenantId], TenantStatus::Active, $schemaVersion);
    }

    public function updateMeta(string $tenantId, ?string $name, ?string $slug, ?int $status): void
    {
        $t = $this->rows[$tenantId] ?? null;
        if ($t === null) {
            return;
        }

        $this->rows[$tenantId] = Tenant::create(
            tenantId: $t->tenantId,
            name: $name ?? $t->name,
            slug: $slug ?? $t->slug,
            dbDriver: $t->dbDriver, dbHost: $t->dbHost, dbPort: $t->dbPort, dbName: $t->dbName,
            dbUsername: $t->dbUsername, dbPasswordEnc: $t->dbPasswordEnc,
            status: $status !== null ? TenantStatus::from($status) : $t->status,
            schemaVersion: $t->schemaVersion,
            parentTenantId: $t->parentTenantId,
            canCreateSubTenants: $t->canCreateSubTenants,
        );
    }

    public function delete(string $tenantId): void { unset($this->rows[$tenantId]); }

    public function findByParent(string $parentTenantId): array
    {
        $rows = array_values(array_filter(
            $this->rows,
            static fn (Tenant $t): bool => $t->parentTenantId === $parentTenantId,
        ));
        usort($rows, static fn (Tenant $a, Tenant $b): int => $a->name <=> $b->name);

        return $rows;
    }

    public function hasChildren(string $tenantId): bool
    {
        return $this->findByParent($tenantId) !== [];
    }

    public function countByParent(string $parentTenantId): int
    {
        return count($this->findByParent($parentTenantId));
    }

    public function setCanCreateSubTenants(string $tenantId, bool $allowed): void
    {
        $t = $this->rows[$tenantId] ?? null;
        if ($t === null) {
            return;
        }

        $this->rows[$tenantId] = Tenant::create(
            tenantId: $t->tenantId, name: $t->name, slug: $t->slug, dbDriver: $t->dbDriver,
            dbHost: $t->dbHost, dbPort: $t->dbPort, dbName: $t->dbName, dbUsername: $t->dbUsername,
            dbPasswordEnc: $t->dbPasswordEnc, status: $t->status, schemaVersion: $t->schemaVersion,
            parentTenantId: $t->parentTenantId, canCreateSubTenants: $allowed,
        );
    }

    private function withStatus(Tenant $t, TenantStatus $status, int $schemaVersion): Tenant
    {
        return Tenant::create(
            tenantId: $t->tenantId, name: $t->name, slug: $t->slug, dbDriver: $t->dbDriver,
            dbHost: $t->dbHost, dbPort: $t->dbPort, dbName: $t->dbName, dbUsername: $t->dbUsername,
            dbPasswordEnc: $t->dbPasswordEnc, status: $status, schemaVersion: $schemaVersion,
            parentTenantId: $t->parentTenantId, canCreateSubTenants: $t->canCreateSubTenants,
        );
    }
}

final class FakeTenantConnectionResolver implements TenantConnectionResolverContract
{
    /** @var list<string> */
    public array $invalidated = [];

    /** Set to make for() throw instead of returning the fake connection (simulates an unreachable tenant DB). */
    public ?\Throwable $failWith = null;

    public function for(string $tenantId): DatabasePort
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return new FakeDatabasePort();
    }

    public function invalidate(string $tenantId): void
    {
        $this->invalidated[] = $tenantId;
    }
}

final class FakeDatabasePort implements DatabasePort
{
    public function query(string $sql, array $params = []): array { return []; }
    public function queryOne(string $sql, array $params = []): ?array { return ['ok' => 1]; }
    public function execute(string $sql, array $params = []): int { return 0; }
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { return 0; }
    public function lastInsertId(?string $sequence = null): string { return '1'; }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function inTransaction(): bool { return false; }
}

final class FakeAuditService implements AuditServiceContract
{
    /** @var list<array{action: string, userId: ?string, tenantId: ?string, meta: array}> */
    public array $recorded = [];

    public function record(string $action, ?string $userId = null, ?string $tenantId = null, array $meta = [], ?string $ip = null): void
    {
        $this->recorded[] = ['action' => $action, 'userId' => $userId, 'tenantId' => $tenantId, 'meta' => $meta];
    }
}

final class FakeTenantProvisioner implements TenantProvisioner
{
    public bool $provisioned = false;
    public bool $toreDown = false;
    public bool $failOnProvision = false;

    public function databaseExists(Tenant $tenant): bool { return false; }

    public function provision(Tenant $tenant, string $plainPassword, bool $databaseAlreadyExists): void
    {
        if ($this->failOnProvision) {
            throw new \RuntimeException('provision boom');
        }
        $this->provisioned = true;
    }

    public function teardown(Tenant $tenant, bool $dropDatabase): int
    {
        $this->toreDown = true;
        return 0;
    }
}

final class FakeTenantRegistry implements TenantRegistryContract
{
    public function find(string $tenantId): ?Tenant { return null; }
    public function exists(string $tenantId): bool { return false; }
    public function listByStatus(int $status, ?int $limit = null, int $offset = 0): array { return []; }
    public function forget(string $tenantId): void {}
}

final class FakeCrypto implements EncryptionPort
{
    public function encrypt(mixed $value, bool $serialize = true): string { return 'enc'; }
    public function decrypt(string $payload, bool $unserialize = true): mixed { return ''; }
    public function encryptString(string $value): string { return 'enc:' . $value; }
    public function decryptString(string $payload): string { return substr($payload, 4); }
}
