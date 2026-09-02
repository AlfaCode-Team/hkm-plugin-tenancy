<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Tenancy\API\Contracts\InvitationServiceContract;
use Plugins\Tenancy\API\DTOs\InvitationResult;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\Application\Ports\InvitationStore;
use Plugins\Tenancy\Application\Ports\MembershipWriter;
use Plugins\Tenancy\Domain\Entities\Invitation;
use Plugins\Tenancy\Domain\Exceptions\InvalidInvitationException;
use Plugins\Tenancy\Support\Token;

/**
 * InvitationService — create / accept / revoke tenant invitations.
 *
 * Security posture:
 *   - Only the SHA-256 of the token is persisted; the raw token is returned once.
 *   - Accept REQUIRES the authenticated user's verified email to match the
 *     invited email (an invite for alice@ cannot be claimed by bob@).
 *   - Accept is idempotent on the seat (upsert) so a double-click can't fail.
 *   - Every action is audited (member.invite / member.join / member.invite_revoked).
 *   - Every tenant-admin action (invite/list/resend/revokeById/inviteBulk) is
 *     gated here on the verified Identity — not only in TenantInvitationController
 *     — so any other module consuming the published InvitationServiceContract
 *     gets the same protection the HTTP boundary already enforces.
 */
final class InvitationService implements InvitationServiceContract
{
    /** Hard cap on one inviteBulk() call so a single request can't queue an unbounded number of invites. */
    private const MAX_BULK_INVITES = 100;

    /** Permission (or owner/admin role) a caller must hold to manage a tenant's invitations. */
    private const MEMBER_MANAGE_PERMISSION = 'tenant.members.manage';

    public function __construct(
        private readonly InvitationStore $invitations,
        private readonly MembershipWriter $memberships,
        private readonly AuditServiceContract $audit,
        private readonly Identity $identity,
    ) {}

    public function invite(
        string $tenantId,
        string $email,
        string $role,
        string $invitedBy,
        int $ttlSeconds = 604800,
    ): InvitationResult {
        $this->requireInviteManager($tenantId);

        $email = mb_strtolower(trim($email));

        if ($this->invitations->pendingExists($tenantId, $email)) {
            throw new ValidationException(['email' => 'A pending invitation already exists for this email.']);
        }

        $inviteId  = Token::ulid();
        $rawToken  = Token::random();
        $expiresAt = (new \DateTimeImmutable())->add(new \DateInterval('PT' . max(60, $ttlSeconds) . 'S'));

        $this->invitations->create(
            $inviteId, $tenantId, $email, $role, Token::hash($rawToken), $invitedBy, $expiresAt,
        );

        $this->audit->record('member.invite', $invitedBy, $tenantId, ['email' => $email, 'role' => $role]);

        return new InvitationResult(
            inviteId:  $inviteId,
            tenantId:  $tenantId,
            email:     $email,
            role:      $role,
            token:     $rawToken,
            expiresAt: $expiresAt->format(\DateTimeInterface::RFC3339),
        );
    }

    public function accept(string $rawToken, string $userId, string $userEmail, ?string $ip = null): string
    {
        $invitation = $this->invitations->findByTokenHash(Token::hash($rawToken));

        if ($invitation === null || !$invitation->isAcceptable()) {
            throw InvalidInvitationException::notUsable();
        }
        if (!hash_equals($invitation->email, mb_strtolower(trim($userEmail)))) {
            $this->audit->record('member.join_denied', $userId, $invitation->tenantId, ['reason' => 'email_mismatch'], $ip);
            throw InvalidInvitationException::emailMismatch();
        }

        $this->memberships->upsertActive($userId, $invitation->tenantId, $invitation->role);
        $this->invitations->markAccepted($invitation->inviteId);

        $this->audit->record('member.join', $userId, $invitation->tenantId, ['role' => $invitation->role], $ip);

        return $invitation->tenantId;
    }

    public function revoke(string $rawToken): void
    {
        $invitation = $this->invitations->findByTokenHash(Token::hash($rawToken));
        if ($invitation === null) {
            return;
        }

        $this->invitations->markRevoked($invitation->inviteId);
        $this->audit->record('member.invite_revoked', null, $invitation->tenantId, ['inviteId' => $invitation->inviteId]);
    }

    public function listForTenant(string $tenantId): array
    {
        $this->requireInviteManager($tenantId);

        return $this->invitations->listForTenant($tenantId);
    }

    public function revokeById(string $tenantId, string $inviteId): void
    {
        $this->requireInviteManager($tenantId);

        $invitation = $this->requireOwnedByTenant($tenantId, $inviteId);

        $this->invitations->markRevoked($invitation->inviteId);
        $this->audit->record('member.invite_revoked', null, $tenantId, ['inviteId' => $inviteId]);
    }

    public function resend(string $tenantId, string $inviteId, int $ttlSeconds = 604800): InvitationResult
    {
        $this->requireInviteManager($tenantId);

        $invitation = $this->requireOwnedByTenant($tenantId, $inviteId);

        $rawToken  = Token::random();
        $expiresAt = (new \DateTimeImmutable())->add(new \DateInterval('PT' . max(60, $ttlSeconds) . 'S'));

        // Rewrites the SAME row (id, email, role unchanged) with a fresh token +
        // expiry and status reset to Pending — the old emailed link stops
        // working the instant this runs, since its hash no longer matches.
        $this->invitations->rotateToken($inviteId, Token::hash($rawToken), $expiresAt);

        $this->audit->record('member.invite_resent', null, $tenantId, ['inviteId' => $inviteId, 'email' => $invitation->email]);

        return new InvitationResult(
            inviteId: $invitation->inviteId,
            tenantId: $tenantId,
            email: $invitation->email,
            role: $invitation->role,
            token: $rawToken,
            expiresAt: $expiresAt->format(\DateTimeInterface::RFC3339),
        );
    }

    public function inviteBulk(string $tenantId, array $entries, string $invitedBy, int $ttlSeconds = 604800): array
    {
        $this->requireInviteManager($tenantId);

        $entries = array_slice($entries, 0, self::MAX_BULK_INVITES);
        $results = [];

        foreach ($entries as $entry) {
            $email = mb_strtolower(trim((string) ($entry['email'] ?? '')));
            $role  = trim((string) ($entry['role'] ?? 'member')) ?: 'member';

            if ($email === '' || !str_contains($email, '@')) {
                $results[] = ['email' => $email, 'ok' => false, 'inviteId' => null, 'token' => null, 'error' => 'Invalid email address.'];
                continue;
            }

            try {
                $invite = $this->invite($tenantId, $email, $role, $invitedBy, $ttlSeconds);
                $results[] = ['email' => $email, 'ok' => true, 'inviteId' => $invite->inviteId, 'token' => $invite->token, 'error' => null];
            } catch (ValidationException $e) {
                $results[] = ['email' => $email, 'ok' => false, 'inviteId' => null, 'token' => null, 'error' => implode(' ', $e->errors)];
            }
        }

        return $results;
    }

    /**
     * The caller must be scoped to $tenantId (never trust a client-supplied
     * tenant id over the verified Identity) AND hold the owner/admin role or
     * the dedicated permission — mirrors TenantAdminService::requireTenantManager()
     * and TenantInvitationController::requireInviteManager().
     */
    private function requireInviteManager(string $tenantId): void
    {
        if ($this->identity->isGuest()) {
            throw new SecurityException('tenancy.invitation.forbidden', layer: 'service.tenancy.invitation');
        }
        if ($this->identity->tenantId !== $tenantId) {
            throw new SecurityException('tenancy.invitation.forbidden', layer: 'service.tenancy.invitation');
        }

        $allowed = $this->identity->hasPermission(self::MEMBER_MANAGE_PERMISSION)
            || $this->identity->hasRole('owner')
            || $this->identity->hasRole('admin');

        if (!$allowed) {
            throw new SecurityException('tenancy.invitation.forbidden', layer: 'service.tenancy.invitation');
        }
    }

    /** A pending, non-revoked lookup is not required here — only that the id exists AND belongs to $tenantId. */
    private function requireOwnedByTenant(string $tenantId, string $inviteId): Invitation
    {
        $invitation = $this->invitations->findById($inviteId);

        // Same "not found" whether the id doesn't exist or belongs to a
        // different tenant — never confirm another tenant's invite id exists.
        if ($invitation === null || $invitation->tenantId !== $tenantId) {
            throw new ServiceException(
                'tenancy.invitation.not_found',
                layer: 'service.tenancy.invitation',
                context: ['inviteId' => $inviteId],
            );
        }

        return $invitation;
    }
}
