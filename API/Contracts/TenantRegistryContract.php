<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\Domain\Entities\Tenant;

/**
 * TenantRegistryContract — read access to the central `tenants` registry.
 *
 * The registry is the source of truth that maps a tenant_id to its database
 * connection coordinates. Lookups are cached (short TTL) so they stay off the
 * per-request hot path; the central DB is only hit on a cache miss.
 */
interface TenantRegistryContract
{
    /** Resolve a tenant by its public id, or null when it does not exist. */
    public function find(string $tenantId): ?Tenant;

    /** True when an active, routable membership target exists for the id. */
    public function exists(string $tenantId): bool;

    /**
     * Tenants at a given status — used by the fleet migrator and provisioning
     * tools. Not a hot path; not cached. Optionally paged so a fleet-wide
     * operation (e.g. tenants:migrate) can stream the registry in batches
     * instead of loading every row — including every encrypted credential —
     * into memory at once. $limit === null means unbounded (the historical
     * behaviour).
     *
     * @return list<Tenant>
     */
    public function listByStatus(int $status, ?int $limit = null, int $offset = 0): array;

    /** Drop any cached copy of a tenant (call after registry mutations). */
    public function forget(string $tenantId): void;
}
