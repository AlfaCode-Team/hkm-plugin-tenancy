<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Tenancy\API\Contracts\AuditQueryServiceContract;
use Plugins\Tenancy\Application\Ports\AuditReader;

/**
 * AuditQueryService — thin authorization wrapper over {@see AuditReader}.
 *
 * All the query logic already lives in the repository (keyset pagination,
 * per-filter SQL); this layer's only job is the one thing a raw port cannot
 * enforce — that only a platform admin may read the trail back.
 */
final class AuditQueryService implements AuditQueryServiceContract
{
    private const ADMIN_PERMISSION = 'tenancy:admin';
    private const ADMIN_ROLE       = 'platform-admin';

    public function __construct(
        private readonly AuditReader $reader,
        private readonly Identity $identity,
    ) {}

    public function query(
        ?string $tenantId = null,
        ?string $userId = null,
        ?string $action = null,
        int $limit = 50,
        ?int $beforeId = null,
    ): array {
        $this->requireAdmin();

        // Most specific filter wins when a caller (mistakenly) sends more than one.
        return match (true) {
            $tenantId !== null && $tenantId !== '' => $this->reader->forTenant($tenantId, $limit, $beforeId),
            $userId !== null && $userId !== ''     => $this->reader->forUser($userId, $limit, $beforeId),
            $action !== null && $action !== ''     => $this->reader->byAction($action, $limit, $beforeId),
            default                                => $this->reader->recent($limit, $beforeId),
        };
    }

    public function countForTenant(string $tenantId): int
    {
        $this->requireAdmin();

        return $this->reader->countForTenant($tenantId);
    }

    private function requireAdmin(): void
    {
        if ($this->identity->isGuest()
            || (!$this->identity->hasPermission(self::ADMIN_PERMISSION)
                && !$this->identity->hasRole(self::ADMIN_ROLE))) {
            throw new SecurityException(
                'tenancy.audit.forbidden',
                layer: 'service.tenancy.audit',
            );
        }
    }
}
