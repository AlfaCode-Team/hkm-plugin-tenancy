<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Tenancy\API\Contracts\InvitationServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * TenantInvitationController — manage pending invitations for the CURRENT
 * tenant (list / send / resend / revoke / bulk-send).
 *
 * The tenant id always comes from the verified, tenant-scoped Identity — never
 * the request body — so a caller can only ever manage invitations for the
 * tenant they are currently scoped to. Distinct from InvitationController,
 * which is the public accept() endpoint any authenticated user can reach for
 * their OWN invite; this controller is the tenant-admin side.
 */
final class TenantInvitationController extends ApiController
{
    private const INVITE_MANAGE_PERMISSION = 'tenant.members.manage';

    public function __construct(
        private readonly InvitationServiceContract $invitations,
    ) {}

    /** GET /ajx/tenant/invitations — list every invitation for the current tenant. */
    public function index(): Response
    {
        $tenantId = $this->requireInviteManager();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        return $this->ok(['data' => array_map(
            static fn ($i) => $i->toArray(),
            $this->invitations->listForTenant($tenantId),
        )]);
    }

    /** POST /ajx/tenant/invitations { "email": "…", "role": "member" } */
    public function store(): Response
    {
        $tenantId = $this->requireInviteManager();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        $email = trim((string) $this->request?->input('email', ''));
        $role  = trim((string) $this->request?->input('role', 'member')) ?: 'member';
        if ($email === '' || !str_contains($email, '@')) {
            return $this->unprocessable(['email' => 'A valid email is required.']);
        }

        try {
            $result = $this->invitations->invite($tenantId, $email, $role, $this->identity()->userId);
        } catch (ValidationException $e) {
            return $this->unprocessable($e->errors);
        }

        return $this->created($result->toArray());
    }

    /** POST /ajx/tenant/invitations/bulk { "invitations": [{"email":"…","role":"member"}, …] } */
    public function bulk(): Response
    {
        $tenantId = $this->requireInviteManager();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        $entries = $this->request?->input('invitations', []);
        if (!is_array($entries) || $entries === []) {
            return $this->unprocessable(['invitations' => 'At least one {email, role} entry is required.']);
        }

        $results = $this->invitations->inviteBulk($tenantId, $entries, $this->identity()->userId);

        return $this->ok(['data' => $results]);
    }

    /** POST /ajx/tenant/invitations/{inviteId}/resend — new token, fresh expiry. */
    public function resend(string $inviteId): Response
    {
        $tenantId = $this->requireInviteManager();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        try {
            $result = $this->invitations->resend($tenantId, $inviteId);
        } catch (ServiceException) {
            return $this->notFound('Invitation not found.');
        }

        return $this->ok($result->toArray());
    }

    /** DELETE /ajx/tenant/invitations/{inviteId} — revoke a pending invitation. */
    public function destroy(string $inviteId): Response
    {
        $tenantId = $this->requireInviteManager();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        try {
            $this->invitations->revokeById($tenantId, $inviteId);
        } catch (ServiceException) {
            return $this->notFound('Invitation not found.');
        }

        return $this->noContent();
    }

    /**
     * The tenant the caller manages invitations for, or a 403 Response.
     * Gate: the `tenant.members.manage` permission OR an owner/admin role on
     * the active tenant-scoped Identity — mirrors TenantHostController's
     * requireHostManager().
     */
    private function requireInviteManager(): string|Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }
        if ($identity->tenantId === '') {
            return $this->forbidden('Select a tenant before managing its invitations.');
        }

        $allowed = $identity->hasPermission(self::INVITE_MANAGE_PERMISSION)
            || $identity->hasRole('owner')
            || $identity->hasRole('admin');

        if (!$allowed) {
            return $this->forbidden('You are not allowed to manage this tenant\'s invitations.');
        }

        return $identity->tenantId;
    }
}
