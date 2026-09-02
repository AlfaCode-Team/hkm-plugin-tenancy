<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\API\DTOs\TenantDetail;

/**
 * TenantAdminServiceContract — control-plane CRUD over the central `tenants`
 * registry, the HTTP-facing twin of the tenant:create / tenant:delete CLI
 * commands.
 *
 * create() provisions the full stack (registry row + isolated database + user +
 * template migrations) with compensating teardown on failure, exactly like the
 * CLI. update() only mutates safe metadata (name / slug / status) — it never
 * rewrites a live tenant's connection coordinates. delete() de-provisions.
 *
 * create()/update()/delete()/suspend()/reactivate()/healthCheck()/grant…/revoke…
 * are platform-admin only. createSubTenant() and listChildren() are the one
 * exception: they additionally allow an owner/admin of the PARENT tenant to
 * act on their own tenant — see their method docblocks for the exact gate.
 */
interface TenantAdminServiceContract
{
    /**
     * A page of the registry, status-then-name order, optionally filtered by a
     * name/slug search term and/or an exact status name ('active', 'suspended',
     * 'provisioning', 'deleted'). $limit is clamped to a sane maximum by the
     * implementation so a caller cannot force an unbounded scan.
     *
     * @return list<TenantDetail>
     */
    public function list(int $limit = 50, int $offset = 0, ?string $search = null, ?string $status = null): array;

    /** Total tenants matching the same $search/$status filters list() would apply. */
    public function count(?string $search = null, ?string $status = null): int;

    /** One tenant by id, or null when it does not exist. */
    public function get(string $tenantId): ?TenantDetail;

    /**
     * Provision a brand new tenant.
     *
     * @param array{name:string,slug:string,driver:string,db_host:string,db_port:int,db_name:string,db_user:string,db_password:string} $input
     */
    public function create(array $input): TenantDetail;

    /**
     * Update safe metadata only (name, slug, status). Unknown/absent keys are
     * left untouched.
     *
     * @param array{name?:string,slug?:string,status?:string} $input
     */
    public function update(string $tenantId, array $input): TenantDetail;

    /** De-provision a tenant: drop its DB user, optionally its database, and the row. */
    public function delete(string $tenantId, bool $dropDatabase = false): void;

    /**
     * Suspend an active tenant: it stops routing (TenantConnectionResolver
     * fails closed on it) without dropping any data or infrastructure — the
     * reversible alternative to delete(). Any warm connection is invalidated
     * immediately so this takes effect before the registry cache TTL expires.
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException
     *         tenant not found, or not currently active
     */
    public function suspend(string $tenantId): TenantDetail;

    /**
     * Reactivate a suspended tenant. The inverse of suspend().
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException
     *         tenant not found, or not currently suspended
     */
    public function reactivate(string $tenantId): TenantDetail;

    /**
     * Probe the tenant's own database with a trivial query and time it. Never
     * throws for an unreachable/unavailable tenant — that IS the result being
     * reported, not a failure of the health check itself.
     *
     * @return array{tenantId: string, status: string, reachable: bool, latencyMs: ?int, error: ?string}
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException tenant not found
     */
    public function healthCheck(string $tenantId): array;

    /**
     * Sub-tenants of $parentTenantId (children in the hierarchy), name-ordered.
     *
     * A platform admin may list any tenant's children; anyone else may only
     * list the children of the tenant they are currently scoped to
     * (Identity.tenantId === $parentTenantId) — this is a read, open to any
     * member, not just an owner/admin (compare {@see createSubTenant()}).
     *
     * @return list<TenantDetail>
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException
     *         caller is a guest, or scoped to a different tenant and not a platform admin
     */
    public function listChildren(string $parentTenantId): array;

    /**
     * Create a sub-tenant under $parentTenantId. The new tenant is fully
     * provisioned (isolated database + template migrations) exactly like
     * create(), but the caller supplies only business identity — database
     * connection coordinates (driver/host/port) are inherited from the
     * parent, and the database name/user/password are generated. Records
     * $parentTenantId on the new row so it is always attributable to (and
     * listable under, via listChildren()) the tenant that created it.
     *
     * Callable by a platform admin for any tenant, OR by an owner/admin of
     * $parentTenantId — but the latter ONLY when a platform admin has
     * GRANTED that tenant permission via grantSubTenantCreation(). The grant
     * is per-tenant, not per-user: it lives on the tenant row, not on the
     * caller's Identity.
     *
     * @param array{name:string,slug:string} $input
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException
     *         caller is a guest, not scoped to $parentTenantId (and not a
     *         platform admin), lacks an owner/admin role on it, or the
     *         parent has not been granted sub-tenant creation
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException
     *         parent tenant not found, or not currently active
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException invalid/taken name or slug
     */
    public function createSubTenant(string $parentTenantId, array $input): TenantDetail;

    /**
     * Grant a tenant permission to create its own sub-tenants — the
     * super-admin gate createSubTenant() checks for a non-platform-admin
     * caller. Platform admin only.
     */
    public function grantSubTenantCreation(string $tenantId): TenantDetail;

    /** Revoke a tenant's permission to create sub-tenants. Platform admin only. */
    public function revokeSubTenantCreation(string $tenantId): TenantDetail;
}
