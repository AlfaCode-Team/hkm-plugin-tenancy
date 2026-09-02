<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\API\DTOs\InvitationResult;
use Plugins\Tenancy\Domain\Entities\Invitation;

/**
 * InvitationServiceContract — email-based tenant onboarding.
 *
 * Invitations decouple "invited" from "has an account": the invite stores an
 * email + role; accepting it (by an authenticated user whose verified email
 * matches) converts it into an active `user_tenants` seat.
 */
interface InvitationServiceContract
{
    /**
     * Create a pending invitation and return the one-time raw token.
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException
     *         when a pending invite already exists for (tenant, email)
     */
    public function invite(
        string $tenantId,
        string $email,
        string $role,
        string $invitedBy,
        int $ttlSeconds = 604800,
    ): InvitationResult;

    /**
     * Accept an invitation: validate the token (pending, not expired, email
     * matches), create/activate the seat, mark the invite accepted, audit
     * `member.join`. Returns the tenant id joined.
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\InvalidInvitationException (→ 422)
     */
    public function accept(string $rawToken, string $userId, string $userEmail, ?string $ip = null): string;

    /** Revoke a pending invitation (no longer acceptable). */
    public function revoke(string $rawToken): void;

    /** @return list<Invitation> every invitation for a tenant, newest first. */
    public function listForTenant(string $tenantId): array;

    /**
     * Revoke a pending invitation by id, scoped to $tenantId so one tenant's
     * admin cannot revoke another tenant's invite by guessing an id.
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException not found / wrong tenant
     */
    public function revokeById(string $tenantId, string $inviteId): void;

    /**
     * Rotate an existing invite's token + expiry and return the new one-time
     * raw token, e.g. to re-send the email. Scoped to $tenantId like revokeById().
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException not found / wrong tenant
     */
    public function resend(string $tenantId, string $inviteId, int $ttlSeconds = 604800): InvitationResult;

    /**
     * Invite several emails at once. Each entry is processed independently —
     * one bad entry (invalid email, duplicate pending invite) does not abort
     * the rest — so the caller can show a per-row result.
     *
     * @param list<array{email: string, role?: string}> $entries
     * @return list<array{email: string, ok: bool, inviteId: ?string, token: ?string, error: ?string}>
     */
    public function inviteBulk(string $tenantId, array $entries, string $invitedBy, int $ttlSeconds = 604800): array;
}
