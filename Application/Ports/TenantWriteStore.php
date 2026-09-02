<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

use Plugins\Tenancy\Domain\Entities\Tenant;

/**
 * TenantWriteStore — the persistence boundary for control-plane tenant CRUD.
 *
 * Implemented by an Infrastructure repository that talks to the CENTRAL
 * `tenants` registry through DatabasePort only. The Application service depends
 * on THIS interface, never on a connection or raw SQL — so the GDA rule
 * "Service → Repository, Repository → DatabasePort" holds.
 */
interface TenantWriteStore
{
    /**
     * A page of the registry, optionally filtered by a name/slug search term
     * and/or exact status. Ordered the same way the old unbounded all() was.
     *
     * @return list<Tenant>
     */
    public function paginate(int $limit, int $offset, ?string $search = null, ?int $status = null): array;

    /** Total rows matching the same filters paginate() would apply — for UI page counts. */
    public function count(?string $search = null, ?int $status = null): int;

    /** One tenant by id, or null when it does not exist. */
    public function find(string $tenantId): ?Tenant;

    /** True when a tenant with this slug exists (optionally excluding one id). */
    public function slugExists(string $slug, ?string $exceptId = null): bool;

    /** Insert a new registry row (status carried on the entity). */
    public function insert(Tenant $tenant): void;

    /** Flip a provisioning tenant to active and stamp its schema version. */
    public function markActive(string $tenantId, int $schemaVersion): void;

    /**
     * Sub-tenants whose parent is this tenant id, name-ordered.
     *
     * @return list<Tenant>
     */
    public function findByParent(string $parentTenantId): array;

    /** True when at least one tenant has this id as its parent — guards delete(). */
    public function hasChildren(string $tenantId): bool;

    /** Number of tenants with this id as their parent — the createSubTenant() quota check. */
    public function countByParent(string $parentTenantId): int;

    /** Grant or revoke the "may create sub-tenants" flag — the super-admin gate. */
    public function setCanCreateSubTenants(string $tenantId, bool $allowed): void;

    /**
     * Update safe metadata. Null values are left untouched.
     *
     * @param int|null $status backing TenantStatus value
     */
    public function updateMeta(string $tenantId, ?string $name, ?string $slug, ?int $status): void;

    /** Remove the registry row. */
    public function delete(string $tenantId): void;
}
