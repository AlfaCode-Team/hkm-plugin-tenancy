<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Plugins\Tenancy\Domain\ValueObjects\MembershipStatus;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;

/**
 * Membership — a view of one `user_tenants` row joined with the tenant it grants
 * access to. Carries everything the selection flow needs to decide routing and
 * mint a tenant-scoped token.
 *
 * Domain layer: zero external imports beyond Domain/ — a plain read model, no
 * shared framework base class.
 */
final class Membership
{
    private function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
        public readonly string $tenantName,
        public readonly string $tenantSlug,
        public readonly string $role,
        public readonly MembershipStatus $status,
        public readonly TenantStatus $tenantStatus,
        private readonly ?\DateTimeImmutable $joinedAtValue = null,
    ) {
    }

    public static function of(
        string $userId,
        string $tenantId,
        string $tenantName,
        string $tenantSlug,
        string $role,
        MembershipStatus $status,
        TenantStatus $tenantStatus,
    ): self {
        return new self(
            userId: $userId,
            tenantId: $tenantId,
            tenantName: $tenantName,
            tenantSlug: $tenantSlug,
            role: $role,
            status: $status,
            tenantStatus: $tenantStatus,
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            userId: (string) $row['user_id'],
            tenantId: (string) $row['tenant_id'],
            tenantName: (string) ($row['name'] ?? ''),
            tenantSlug: (string) ($row['slug'] ?? ''),
            role: (string) $row['role'],
            status: MembershipStatus::from((int) $row['status']),
            tenantStatus: TenantStatus::from((int) ($row['tenant_status'] ?? TenantStatus::Active->value)),
            joinedAtValue: self::parseDate($row['joined_at'] ?? null),
        );
    }

    /** Routable only when BOTH the seat and the tenant are active. */
    public function isRoutable(): bool
    {
        return $this->status->isActive() && $this->tenantStatus->isRoutable();
    }

    public function joinedAt(): ?\DateTimeImmutable
    {
        return $this->joinedAtValue;
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
