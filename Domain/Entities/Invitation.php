<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Plugins\Tenancy\Domain\ValueObjects\InvitationStatus;

/**
 * Invitation — a view of one `tenant_invitations` row.
 *
 * The raw token is NEVER stored or carried here — only its SHA-256 hash. The
 * plaintext exists once, in the emailed link.
 *
 * Domain layer: zero external imports beyond Domain/ — a plain read model, no
 * shared framework base class.
 */
final class Invitation
{
    private function __construct(
        public readonly string $inviteId,
        public readonly string $tenantId,
        public readonly string $email,
        public readonly string $role,
        public readonly InvitationStatus $status,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly string $invitedBy,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            inviteId: (string) $row['invite_id'],
            tenantId: (string) $row['tenant_id'],
            email: (string) $row['email'],
            role: (string) $row['role'],
            status: InvitationStatus::from((int) $row['status']),
            expiresAt: new \DateTimeImmutable((string) $row['expires_at']),
            invitedBy: (string) $row['invited_by'],
        );
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return ($now ?? new \DateTimeImmutable()) >= $this->expiresAt;
    }

    /** Acceptable only when pending AND not past expiry. */
    public function isAcceptable(?\DateTimeImmutable $now = null): bool
    {
        return $this->status->isPending() && !$this->isExpired($now);
    }

    /**
     * Shape returned to the tenant's own admin UI. Never carries the raw
     * token or its hash — only the InvitationResult returned at creation/resend
     * time ever exposes the raw token, and exactly once.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'inviteId' => $this->inviteId,
            'tenantId' => $this->tenantId,
            'email' => $this->email,
            'role' => $this->role,
            'status' => strtolower($this->status->name),
            'expiresAt' => $this->expiresAt->format(\DateTimeInterface::RFC3339),
            'invitedBy' => $this->invitedBy,
        ];
    }
}
