<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Tenancy\API\Contracts\TenantAdminServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * Control-plane CRUD endpoints for tenants (the JSON twin of the tenant:create /
 * tenant:delete CLI commands). Drives the admin resource UI under /tenants.
 *
 * These endpoints mutate the central registry and provision real databases, so
 * every route is gated by the `auth` filter and an explicit platform-admin
 * permission check here — a tenant member must never reach them.
 *
 * RequestAware (via ApiController): actions take route params only; the active
 * request is $this->resolveRequest().
 */
final class TenantAdminController extends ApiController
{
    /** Permission a caller must hold to manage the tenant fleet. */
    private const ADMIN_PERMISSION = 'tenancy:admin';

    public function __construct(
        private readonly TenantAdminServiceContract $tenants,
    ) {}

    /**
     * GET /ajx/admin/tenants — a page of the registry.
     * Query params: limit (default 50, capped server-side), offset, search
     * (name/slug substring), status (active|provisioning|suspended|deleted).
     */
    public function index(): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        $request = $this->resolveRequest();
        $limit   = $request->integer('limit') ?: 50;
        $offset  = $request->integer('offset') ?: 0;
        $search  = $request->filled('search') ? $request->string('search') : null;
        $status  = $request->filled('status') ? $request->string('status') : null;

        return $this->ok([
            'data' => array_map(
                static fn ($t) => $t->toArray(),
                $this->tenants->list($limit, $offset, $search, $status),
            ),
            'meta' => [
                'total'  => $this->tenants->count($search, $status),
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    /** GET /ajx/admin/tenants/{tenantId} — one tenant. */
    public function show(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        return $this->okOrNotFound($this->tenants->get($tenantId)?->toArray());
    }

    /** POST /ajx/admin/tenants — provision a new tenant. */
    public function store(): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        try {
            $tenant = $this->tenants->create($this->payload());
        } catch (ValidationException $e) {
            return $this->unprocessable($e->errors);
        }

        return $this->created($tenant->toArray());
    }

    /** PUT /ajx/admin/tenants/{tenantId} — update name/slug/status. */
    public function update(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        try {
            $tenant = $this->tenants->update($tenantId, $this->payload());
        } catch (ValidationException $e) {
            return $this->unprocessable($e->errors);
        }

        return $this->ok($tenant->toArray());
    }

    /** DELETE /ajx/admin/tenants/{tenantId} — de-provision a tenant. */
    public function destroy(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        $dropDatabase = $this->resolveRequest()->boolean('drop_database');
        $this->tenants->delete($tenantId, $dropDatabase);

        return $this->noContent();
    }

    /** POST /ajx/admin/tenants/{tenantId}/suspend — stop routing without deleting anything. */
    public function suspend(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        return $this->ok($this->tenants->suspend($tenantId)->toArray());
    }

    /** POST /ajx/admin/tenants/{tenantId}/reactivate — the inverse of suspend(). */
    public function reactivate(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        return $this->ok($this->tenants->reactivate($tenantId)->toArray());
    }

    /** GET /ajx/admin/tenants/{tenantId}/health — ping the tenant's own database. */
    public function health(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        return $this->ok($this->tenants->healthCheck($tenantId));
    }

    /**
     * GET /ajx/admin/tenants/{tenantId}/children — sub-tenants created BY this
     * tenant, so the admin resource UI can always show them nested under it.
     */
    public function children(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        return $this->ok(['data' => array_map(
            static fn ($t) => $t->toArray(),
            $this->tenants->listChildren($tenantId),
        )]);
    }

    /**
     * POST /ajx/admin/tenants/{tenantId}/sub-tenants/grant — the super-admin
     * grant: let this tenant create its own sub-tenants (self-service, via
     * SubTenantController).
     */
    public function grantSubTenants(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        return $this->ok($this->tenants->grantSubTenantCreation($tenantId)->toArray());
    }

    /** POST /ajx/admin/tenants/{tenantId}/sub-tenants/revoke — the inverse of grantSubTenants(). */
    public function revokeSubTenants(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        return $this->ok($this->tenants->revokeSubTenantCreation($tenantId)->toArray());
    }

    /** Deny non-admins. Returns a Response to short-circuit, or null to proceed. */
    private function guard(): ?Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }
        if (!$identity->hasPermission(self::ADMIN_PERMISSION) && !$identity->hasRole('platform-admin')) {
            return $this->forbidden('Tenant administration requires platform-admin access.');
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return $this->resolveRequest()->all();
    }
}
