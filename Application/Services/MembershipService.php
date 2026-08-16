<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use Plugins\Tenancy\API\Contracts\MembershipServiceContract;
use Plugins\Tenancy\API\DTOs\TenantSummary;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\Application\Ports\MembershipReader;
use Plugins\Tenancy\Domain\Exceptions\NotAMemberException;

/**
 * MembershipService — the tenant-selection authority (control plane ONLY).
 *
 * Answers exactly one question: does this user hold an active, routable seat
 * in this tenant? It lists seats for the picker, RE-VERIFIES the membership
 * against central `user_tenants` on selection (never trusting a client-supplied
 * tenant id), and audits the switch/denial.
 *
 * It does NOT mint credentials — tenancy is not authentication. The HTTP
 * boundary (TenantController) takes the verified seat returned here and asks
 * the Auth module to issue the tenant-scoped token. Keeping Auth out of this
 * service also keeps the container graph acyclic
 * (AuthService → UserService → MembershipService).
 *
 * The re-verification on selection — and the per-request re-check that the Auth
 * layer/TenantContextStage performs — is what makes a revoked seat lose access
 * before the previously-issued token would expire.
 *
 * isActiveMember() is TenantContextStage's hot-path check, run on every
 * authenticated request. It is cached with a deliberately SHORT TTL (a few
 * seconds, not TENANCY_REGISTRY_TTL's minute-scale default): long enough to
 * spare the central `user_tenants` JOIN on every request from the same user,
 * short enough that a revoked seat still loses access within a few seconds
 * rather than lingering — the exact guarantee this re-check exists for.
 * selectTenant() (the explicit switch/re-verification endpoint) never reads
 * this cache — it always hits the repository directly.
 */
final class MembershipService implements MembershipServiceContract
{
    public function __construct(
        private readonly MembershipReader $memberships,
        private readonly AuditServiceContract $audit,
        private readonly ?CachePort $cache = null,
        private readonly int $activeMembershipCacheTtl = 10,
    ) {}

    public function myTenants(string $userId): array
    {
        return array_map(
            static fn ($m): TenantSummary => TenantSummary::fromMembership($m),
            $this->memberships->activeForUser($userId),
        );
    }

    public function isActiveMember(string $userId, string $tenantId): bool
    {
        if ($this->cache === null) {
            return $this->activeMember($userId, $tenantId) !== null;
        }

        $key = $this->activeMembershipCacheKey($userId, $tenantId);

        $cached = $this->cache->get($key);
        if (is_bool($cached)) {
            return $cached;
        }

        $isActive = $this->activeMember($userId, $tenantId) !== null;
        $this->cache->set($key, $isActive, $this->activeMembershipCacheTtl);

        return $isActive;
    }

    /** Bust the isActiveMember() cache immediately (e.g. after a seat is revoked or re-granted). */
    public function forgetActiveMemberCache(string $userId, string $tenantId): void
    {
        $this->cache?->delete($this->activeMembershipCacheKey($userId, $tenantId));
    }

    private function activeMembershipCacheKey(string $userId, string $tenantId): string
    {
        return 'tenancy:membership:active:' . $userId . ':' . $tenantId;
    }

    public function activeMember(string $userId, string $tenantId): ?TenantSummary
    {
        $membership = $this->memberships->find($userId, $tenantId);

        return $membership !== null && $membership->isRoutable()
            ? TenantSummary::fromMembership($membership)
            : null;
    }

    public function selectTenant(string $userId, string $tenantId, ?string $ip = null): TenantSummary
    {
        $membership = $this->memberships->find($userId, $tenantId);

        if ($membership === null || !$membership->isRoutable()) {
            $this->audit->record('tenant.switch_denied', $userId, $tenantId, [], $ip);
            throw NotAMemberException::for($userId, $tenantId);
        }

        $this->audit->record('tenant.switch', $userId, $tenantId, ['role' => $membership->role], $ip);

        return TenantSummary::fromMembership($membership);
    }
}
