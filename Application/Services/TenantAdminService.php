<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\API\Contracts\TenantAdminServiceContract;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\API\DTOs\TenantDetail;
use Plugins\Tenancy\Application\Ports\TenantProvisioner;
use Plugins\Tenancy\Application\Ports\TenantWriteStore;
use Plugins\Tenancy\Domain\Entities\Tenant;
use Plugins\Tenancy\Domain\ValueObjects\SqlIdentifier;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;
use Plugins\Tenancy\Support\Token;

/**
 * TenantAdminService — control-plane CRUD over the central `tenants` registry.
 *
 * Pure orchestration: it validates input, then drives the persistence boundary
 * ({@see TenantWriteStore}) and the data-plane boundary ({@see TenantProvisioner}).
 * It holds no connection, writes no SQL and runs no DDL — those live behind the
 * ports, honouring the GDA rule "Service → Repository/Gateway; Repository →
 * DatabasePort only".
 */
final class TenantAdminService implements TenantAdminServiceContract
{
    private const DRIVERS = ['mysql', 'pgsql', 'sqlsrv'];

    /** Permission (or role) a caller must hold to manage the tenant fleet. */
    private const ADMIN_PERMISSION = 'tenancy:admin';
    private const ADMIN_ROLE       = 'platform-admin';

    /** Permission a non-admin tenant member may hold to create sub-tenants (in addition to owner/admin role). */
    private const SUB_TENANT_PERMISSION = 'tenant.sub_tenants.manage';

    /** Hard ceiling on a single page, independent of what a caller asks for. */
    private const MAX_PAGE_SIZE = 200;

    public function __construct(
        private readonly TenantWriteStore $store,
        private readonly TenantProvisioner $provisioner,
        private readonly TenantRegistryContract $registry,
        private readonly EncryptionPort $crypto,
        private readonly Identity $identity,
        private readonly TenantConnectionResolverContract $connections,
        private readonly AuditServiceContract $audit,
        /** Max sub-tenants a single tenant may self-service create (anti-abuse; 0 = unlimited). Platform admins bypass this. */
        private readonly int $maxSubTenantsPerParent = 10,
    ) {}

    public function list(int $limit = 50, int $offset = 0, ?string $search = null, ?string $status = null): array
    {
        $this->requireAdmin();

        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $offset = max(0, $offset);
        $status = $this->statusFilterValue($status);

        return array_map(
            static fn (Tenant $t): TenantDetail => TenantDetail::fromEntity($t),
            $this->store->paginate($limit, $offset, $this->normalizeSearch($search), $status),
        );
    }

    public function count(?string $search = null, ?string $status = null): int
    {
        $this->requireAdmin();

        return $this->store->count($this->normalizeSearch($search), $this->statusFilterValue($status));
    }

    public function get(string $tenantId): ?TenantDetail
    {
        $this->requireAdmin();

        $tenant = $this->store->find($tenantId);

        return $tenant === null ? null : TenantDetail::fromEntity($tenant);
    }

    public function create(array $input): TenantDetail
    {
        $this->requireAdmin();

        $name   = trim((string) ($input['name'] ?? ''));
        $slug   = strtolower(trim((string) ($input['slug'] ?? '')));
        $driver = strtolower(trim((string) ($input['driver'] ?? 'mysql')));
        $dbHost = trim((string) ($input['db_host'] ?? '127.0.0.1')) ?: '127.0.0.1';
        $dbPort = (int) ($input['db_port'] ?? 0) ?: $this->defaultPort($driver);
        $dbName = trim((string) ($input['db_name'] ?? ''));
        $dbUser = trim((string) ($input['db_user'] ?? ''));
        $dbPass = (string) ($input['db_password'] ?? '');

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'A name is required.';
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $errors['slug'] = 'Use ^[a-z0-9-]+$ only.';
        }
        if (!in_array($driver, self::DRIVERS, true)) {
            $errors['driver'] = "Use 'mysql', 'pgsql' or 'sqlsrv'.";
        }
        if (!SqlIdentifier::isValid($dbName)) {
            $errors['db_name'] = 'Letters, digits and underscore only.';
        }
        if (!SqlIdentifier::isValid($dbUser)) {
            $errors['db_user'] = 'Letters, digits and underscore only.';
        }
        if (!preg_match('/^[A-Za-z0-9_.:\-]+$/', $dbHost)) {
            $errors['db_host'] = 'Hostname/IP characters only.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($this->store->slugExists($slug)) {
            throw new ValidationException(['slug' => 'A tenant with this slug already exists.']);
        }

        // Provisioning entity — password stored ENCRYPTED, status=provisioning.
        $tenant = Tenant::create(
            tenantId:      Token::ulid(),
            name:          $name,
            slug:          $slug,
            dbDriver:      $driver,
            dbHost:        $dbHost,
            dbPort:        $dbPort,
            dbName:        $dbName,
            dbUsername:    $dbUser,
            dbPasswordEnc: $this->crypto->encryptString($dbPass),
            status:        TenantStatus::Provisioning,
            schemaVersion: 0,
        );

        return $this->provisionAndActivate(
            $tenant,
            $dbPass,
            failedErrorCode: 'tenancy.create.failed',
            auditAction: 'tenant.created',
            auditContext: ['slug' => $slug, 'name' => $name],
        );
    }

    /**
     * Create a sub-tenant under $parentTenantId. See the contract docblock for
     * the full authorization gate (platform admin, or an owner/admin of a
     * tenant a platform admin has explicitly GRANTED this capability to).
     * Database coordinates are inherited from the parent (same server) — the
     * caller supplies only business identity, never raw credentials.
     */
    public function createSubTenant(string $parentTenantId, array $input): TenantDetail
    {
        $isPlatformAdmin = $this->isPlatformAdmin();
        if (!$isPlatformAdmin) {
            $this->requireTenantManager($parentTenantId);
        }

        $parent = $this->store->find($parentTenantId);
        if ($parent === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $parentTenantId]);
        }
        if (!$isPlatformAdmin && !$parent->canCreateSubTenants) {
            throw new SecurityException(
                'tenancy.sub_tenant.not_granted',
                layer: 'service.tenancy.admin',
                context: ['id' => $parentTenantId],
            );
        }
        if (!$parent->status->isRoutable()) {
            throw new ServiceException(
                'tenancy.sub_tenant.parent_not_active',
                layer: 'service.tenancy.admin',
                context: ['id' => $parentTenantId, 'status' => strtolower($parent->status->name)],
            );
        }
        // Anti-abuse: each sub-tenant is a REAL isolated database (CREATE
        // DATABASE + CREATE USER), so a self-service caller gets a hard cap —
        // mirrors TenantHostService's maxHostsPerTenant. Platform admins are
        // exempt, same as the canCreateSubTenants grant check above.
        if (!$isPlatformAdmin
            && $this->maxSubTenantsPerParent > 0
            && $this->store->countByParent($parentTenantId) >= $this->maxSubTenantsPerParent) {
            throw new ServiceException(
                'tenancy.sub_tenant.quota_exceeded',
                layer: 'service.tenancy.admin',
                context: ['id' => $parentTenantId, 'max' => $this->maxSubTenantsPerParent],
            );
        }

        $name = trim((string) ($input['name'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));

        $errors = $this->validateNameSlug($name, $slug);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($this->store->slugExists($slug)) {
            throw new ValidationException(['slug' => 'A tenant with this slug already exists.']);
        }

        $identifier = $this->identifier($slug);
        $dbPass     = Token::random(24);

        // Same server/driver as the parent by default — a sub-tenant is a
        // full isolated database, but a self-service caller never chooses
        // where it lives; ops can relocate it later like any other tenant.
        $tenant = Tenant::create(
            tenantId:            Token::ulid(),
            name:                $name,
            slug:                $slug,
            dbDriver:            $parent->dbDriver,
            dbHost:              $parent->dbHost,
            dbPort:              $parent->dbPort,
            dbName:              'tnt_' . $identifier,
            dbUsername:          $identifier . '_user',
            dbPasswordEnc:       $this->crypto->encryptString($dbPass),
            status:              TenantStatus::Provisioning,
            schemaVersion:       0,
            parentTenantId:      $parentTenantId,
            canCreateSubTenants: false,
        );

        return $this->provisionAndActivate(
            $tenant,
            $dbPass,
            failedErrorCode: 'tenancy.sub_tenant.create_failed',
            auditAction: 'tenant.sub_tenant.created',
            auditContext: ['parentTenantId' => $parentTenantId, 'slug' => $slug, 'name' => $name],
        );
    }

    public function listChildren(string $parentTenantId): array
    {
        $this->requireAdminOrTenantMember($parentTenantId);

        return array_map(
            static fn (Tenant $t): TenantDetail => TenantDetail::fromEntity($t),
            $this->store->findByParent($parentTenantId),
        );
    }

    public function grantSubTenantCreation(string $tenantId): TenantDetail
    {
        return $this->setSubTenantGrant($tenantId, true, 'tenant.sub_tenant_grant.granted');
    }

    public function revokeSubTenantCreation(string $tenantId): TenantDetail
    {
        return $this->setSubTenantGrant($tenantId, false, 'tenant.sub_tenant_grant.revoked');
    }

    private function setSubTenantGrant(string $tenantId, bool $allowed, string $auditAction): TenantDetail
    {
        $this->requireAdmin();

        $tenant = $this->store->find($tenantId);
        if ($tenant === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
        }

        $this->store->setCanCreateSubTenants($tenantId, $allowed);
        $this->registry->forget($tenantId);
        $this->audit->record($auditAction, $this->identity->userId, $tenantId, []);

        return $this->get($tenantId) ?? throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
    }

    /**
     * Insert + provision + activate a tenant entity, compensating (teardown +
     * registry-row delete) on any failure. Shared by create() (platform admin,
     * caller-supplied coordinates) and createSubTenant() (tenant self-service,
     * coordinates inherited from the parent) so the transactional shape never
     * drifts between the two entry points.
     */
    private function provisionAndActivate(
        Tenant $tenant,
        string $dbPass,
        string $failedErrorCode,
        string $auditAction,
        array $auditContext,
    ): TenantDetail {
        $dbPreExisted = $this->provisioner->databaseExists($tenant);

        try {
            $this->store->insert($tenant);
            $this->provisioner->provision($tenant, $dbPass, $dbPreExisted);
            $this->store->markActive($tenant->tenantId, schemaVersion: 1);
        } catch (\Throwable $e) {
            // Compensate: undo infra we created, then the registry row.
            $this->provisioner->teardown($tenant, dropDatabase: !$dbPreExisted);
            try {
                $this->store->delete($tenant->tenantId);
            } catch (\Throwable) {
            }

            throw new ServiceException(
                $failedErrorCode,
                layer: 'service.tenancy.admin',
                context: ['slug' => $tenant->slug],
                previous: $e,
            );
        }

        $this->registry->forget($tenant->tenantId);
        // The acting user, not null — createSubTenant() is often a tenant
        // owner/admin, not a platform admin, and "who provisioned this" is
        // exactly what a control-plane audit trail exists to answer.
        $this->audit->record($auditAction, $this->identity->userId, $tenant->tenantId, $auditContext);

        // NOT $this->get() — that re-checks requireAdmin(), which would 403 a
        // non-platform-admin self-service caller (createSubTenant()) who just
        // successfully provisioned this tenant. Authorization for THIS write
        // already happened before provisioning started; reporting its own
        // result back needs no second gate.
        $created = $this->store->find($tenant->tenantId) ?? throw new ServiceException(
            'tenancy.create.missing_after_commit',
            layer: 'service.tenancy.admin',
        );

        return TenantDetail::fromEntity($created);
    }

    public function update(string $tenantId, array $input): TenantDetail
    {
        $this->requireAdmin();

        $tenant = $this->store->find($tenantId);
        if ($tenant === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
        }

        $name   = null;
        $slug   = null;
        $status = null;
        $errors = [];

        if (array_key_exists('name', $input)) {
            $candidate = trim((string) $input['name']);
            if ($candidate === '') {
                $errors['name'] = 'A name is required.';
            } else {
                $name = $candidate;
            }
        }

        if (array_key_exists('slug', $input)) {
            $candidate = strtolower(trim((string) $input['slug']));
            if (!preg_match('/^[a-z0-9-]+$/', $candidate)) {
                $errors['slug'] = 'Use ^[a-z0-9-]+$ only.';
            } elseif ($this->store->slugExists($candidate, exceptId: $tenantId)) {
                $errors['slug'] = 'Another tenant already uses this slug.';
            } else {
                $slug = $candidate;
            }
        }

        if (array_key_exists('status', $input)) {
            $resolved = $this->statusFromName((string) $input['status']);
            if ($resolved === null) {
                $errors['status'] = 'Unknown status.';
            } else {
                $status = $resolved;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($name !== null || $slug !== null) {
            $this->store->updateMeta($tenantId, $name, $slug, null);
            $this->registry->forget($tenantId);
            $this->audit->record('tenant.updated', null, $tenantId, array_filter([
                'name' => $name,
                'slug' => $slug,
            ], static fn ($v) => $v !== null));
        }

        // Routed through the shared status-change path (not bundled into the
        // updateMeta() call above) so ANY status change here — not just the
        // dedicated suspend()/reactivate() below — drops the warm connection
        // and the decrypted-credential cache immediately and gets its own
        // audit entry, instead of only taking effect once the registry TTL expires.
        if ($status !== null && $status !== $tenant->status) {
            $this->changeStatus($tenant, $status, 'tenant.status_changed');
        }

        return $this->get($tenantId) ?? throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
    }

    /**
     * Suspend an active tenant: stop routing to it without dropping any data or
     * infrastructure. Reversible via reactivate().
     */
    public function suspend(string $tenantId): TenantDetail
    {
        $this->requireAdmin();

        $tenant = $this->store->find($tenantId);
        if ($tenant === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
        }
        if ($tenant->status !== TenantStatus::Active) {
            throw new ServiceException(
                'tenancy.suspend.invalid_state',
                layer: 'service.tenancy.admin',
                context: ['id' => $tenantId, 'status' => strtolower($tenant->status->name)],
            );
        }

        $this->changeStatus($tenant, TenantStatus::Suspended, 'tenant.suspended');

        return $this->get($tenantId) ?? throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
    }

    /** Reactivate a suspended tenant. The inverse of suspend(). */
    public function reactivate(string $tenantId): TenantDetail
    {
        $this->requireAdmin();

        $tenant = $this->store->find($tenantId);
        if ($tenant === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
        }
        if ($tenant->status !== TenantStatus::Suspended) {
            throw new ServiceException(
                'tenancy.reactivate.invalid_state',
                layer: 'service.tenancy.admin',
                context: ['id' => $tenantId, 'status' => strtolower($tenant->status->name)],
            );
        }

        $this->changeStatus($tenant, TenantStatus::Active, 'tenant.reactivated');

        return $this->get($tenantId) ?? throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
    }

    public function healthCheck(string $tenantId): array
    {
        $this->requireAdmin();

        $tenant = $this->store->find($tenantId);
        if ($tenant === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
        }

        $status  = strtolower($tenant->status->name);
        $started = microtime(true);

        try {
            $db = $this->connections->for($tenantId);
            $db->queryOne('SELECT 1 AS ok');

            return [
                'tenantId' => $tenantId,
                'status' => $status,
                'reachable' => true,
                'latencyMs' => (int) round((microtime(true) - $started) * 1000),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            // Deliberately broad: a health probe's entire job is to turn ANY
            // failure (suspended/breaker-open/unreachable/query error) into a
            // status report, never to propagate it.
            return [
                'tenantId' => $tenantId,
                'status' => $status,
                'reachable' => false,
                'latencyMs' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function delete(string $tenantId, bool $dropDatabase = false): void
    {
        $this->requireAdmin();

        $tenant = $this->store->find($tenantId);
        if ($tenant === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
        }
        if ($this->store->hasChildren($tenantId)) {
            throw new ServiceException(
                'tenancy.delete.has_children',
                layer: 'service.tenancy.admin',
                context: ['id' => $tenantId],
            );
        }

        $failed = $this->provisioner->teardown($tenant, dropDatabase: $dropDatabase);
        $this->store->delete($tenantId);
        $this->registry->forget($tenantId);
        $this->connections->invalidate($tenantId);
        $this->audit->record('tenant.deleted', null, $tenantId, ['dropDatabase' => $dropDatabase]);

        if ($failed > 0) {
            throw new ServiceException(
                'tenancy.delete.partial',
                layer: 'service.tenancy.admin',
                context: ['tenantId' => $tenantId, 'failedSteps' => $failed],
            );
        }
    }

    /**
     * Persist a status transition and make it take effect immediately: forgets
     * the cached registry row AND invalidates any warm connection / decrypted
     * credential the resolver may be holding for this tenant, then audits the
     * transition. Shared by update() (generic status change) and the dedicated
     * suspend()/reactivate() methods so neither path can apply a status change
     * that "only takes effect eventually."
     */
    private function changeStatus(Tenant $current, TenantStatus $newStatus, string $auditAction): void
    {
        $this->store->updateMeta($current->tenantId, null, null, $newStatus->value);
        $this->registry->forget($current->tenantId);
        $this->connections->invalidate($current->tenantId);
        $this->audit->record($auditAction, null, $current->tenantId, [
            'from' => strtolower($current->status->name),
            'to'   => strtolower($newStatus->name),
        ]);
    }

    /**
     * Control-plane authorization. Tenant management provisions real databases
     * and mutates the central registry, so it is restricted to platform admins.
     * Enforced HERE (not only in the controller) so any caller of the published
     * contract is gated — the HTTP guard is just a cheap early reject.
     */
    private function requireAdmin(): void
    {
        if (!$this->isPlatformAdmin()) {
            throw new SecurityException(
                'tenancy.admin.forbidden',
                layer: 'service.tenancy.admin',
            );
        }
    }

    private function isPlatformAdmin(): bool
    {
        return !$this->identity->isGuest()
            && ($this->identity->hasPermission(self::ADMIN_PERMISSION) || $this->identity->hasRole(self::ADMIN_ROLE));
    }

    /**
     * Read gate for listChildren(): a platform admin may read any tenant's
     * children; anyone else only their OWN active tenant's — open to any
     * member, not just an owner/admin (compare requireTenantManager()).
     */
    private function requireAdminOrTenantMember(string $tenantId): void
    {
        if ($this->identity->isGuest()) {
            throw new SecurityException('tenancy.admin.forbidden', layer: 'service.tenancy.admin');
        }
        if ($this->isPlatformAdmin()) {
            return;
        }
        if ($this->identity->tenantId !== $tenantId) {
            throw new SecurityException('tenancy.admin.forbidden', layer: 'service.tenancy.admin');
        }
    }

    /**
     * Write gate for createSubTenant()'s non-platform-admin path: the caller
     * must be scoped to $tenantId (never trust a client-supplied parent id
     * over the verified Identity) AND hold an owner/admin role — or the
     * dedicated permission — on it. The tenant-level GRANT
     * (Tenant::$canCreateSubTenants) is checked separately by the caller.
     */
    private function requireTenantManager(string $tenantId): void
    {
        if ($this->identity->isGuest()) {
            throw new SecurityException('tenancy.admin.forbidden', layer: 'service.tenancy.admin');
        }
        if ($this->identity->tenantId !== $tenantId) {
            throw new SecurityException('tenancy.admin.forbidden', layer: 'service.tenancy.admin');
        }

        $allowed = $this->identity->hasPermission(self::SUB_TENANT_PERMISSION)
            || $this->identity->hasRole('owner')
            || $this->identity->hasRole('admin');

        if (!$allowed) {
            throw new SecurityException('tenancy.sub_tenant.forbidden', layer: 'service.tenancy.admin');
        }
    }

    /** @return array<string, string> field => message, empty when valid */
    private function validateNameSlug(string $name, string $slug): array
    {
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'A name is required.';
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $errors['slug'] = 'Use ^[a-z0-9-]+$ only.';
        }

        return $errors;
    }

    /** Turn a slug into a safe SQL identifier fragment (^[a-z0-9_]+$) — mirrors tenant:create's CLI helper. */
    private function identifier(string $value): string
    {
        return preg_replace('/[^a-z0-9_]+/', '_', strtolower($value)) ?? '';
    }

    private function defaultPort(string $driver): int
    {
        return match ($driver) {
            'pgsql'  => 5432,
            'sqlsrv' => 1433,
            default  => 3306,
        };
    }

    private function statusFromName(string $name): ?TenantStatus
    {
        return match (strtolower(trim($name))) {
            'active'       => TenantStatus::Active,
            'provisioning' => TenantStatus::Provisioning,
            'suspended'    => TenantStatus::Suspended,
            'deleted'      => TenantStatus::Deleted,
            default        => null,
        };
    }

    private function normalizeSearch(?string $search): ?string
    {
        $trimmed = trim((string) $search);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * An unrecognised status name is treated as "no filter" rather than an
     * error — list()/count() are read paths, and a typo'd filter should widen
     * the result rather than 422 an admin dashboard.
     */
    private function statusFilterValue(?string $status): ?int
    {
        if ($status === null || trim($status) === '') {
            return null;
        }

        return $this->statusFromName($status)?->value;
    }
}
