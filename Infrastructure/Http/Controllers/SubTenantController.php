<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Tenancy\API\Contracts\TenantAdminServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * SubTenantController — self-service sub-tenant creation for the CURRENT
 * tenant (list / create children). Distinct from TenantAdminController: no
 * platform-admin permission is required here.
 *
 * Authorization is per-tenant and enforced in the SERVICE
 * (TenantAdminServiceContract::createSubTenant() / listChildren()), not just
 * here — an owner/admin role on the active tenant-scoped Identity, AND the
 * tenant must have been GRANTED sub-tenant creation by a platform admin (see
 * TenantAdminController::grantSubTenants()). This controller only rejects the
 * cheap case (no authenticated tenant scope at all).
 *
 * The parent tenant id ALWAYS comes from the verified, tenant-scoped Identity
 * — never the request body — so a caller can only ever create sub-tenants
 * under the tenant they are currently scoped to.
 */
final class SubTenantController extends ApiController
{
    public function __construct(
        private readonly TenantAdminServiceContract $tenants,
    ) {}

    /** GET /ajx/tenant/sub-tenants — list the current tenant's children. */
    public function index(): Response
    {
        $tenantId = $this->requireTenant();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        return $this->ok(['data' => array_map(
            static fn ($t) => $t->toArray(),
            $this->tenants->listChildren($tenantId),
        )]);
    }

    /** POST /ajx/tenant/sub-tenants { "name": "…", "slug": "…" } */
    public function store(): Response
    {
        $tenantId = $this->requireTenant();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        try {
            $tenant = $this->tenants->createSubTenant($tenantId, $this->payload());
        } catch (ValidationException $e) {
            return $this->unprocessable($e->errors);
        }

        return $this->created($tenant->toArray());
    }

    /**
     * The tenant the caller is currently scoped to, or a 403 Response.
     * Role/grant authorization happens in the service — this is only the
     * cheap "are you even scoped to a tenant" early reject.
     */
    private function requireTenant(): string|Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }
        if ($identity->tenantId === '') {
            return $this->forbidden('Select a tenant before creating a sub-tenant.');
        }

        return $identity->tenantId;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return $this->resolveRequest()->all();
    }
}
