<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\Domain\Entities\AuditEntry;

/**
 * AuditQueryServiceContract — admin-facing read access to the central audit
 * trail (`audit_log`). The read counterpart to writing through Audit's
 * published AuditServiceContract: any plugin can WRITE an entry, but reading
 * the trail back is gated to platform admins, so it is exposed here rather
 * than as an open port.
 *
 * Exactly one of $tenantId / $userId / $action narrows the query; passing more
 * than one is a caller error the implementation is free to reject or ignore
 * the extras of. Passing none returns the trail unfiltered, newest first.
 *
 * Listings are keyset-paginated: pass the last id seen as $beforeId to fetch
 * the next page.
 */
interface AuditQueryServiceContract
{
    /**
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException caller is not a platform admin
     * @return list<AuditEntry>
     */
    public function query(
        ?string $tenantId = null,
        ?string $userId = null,
        ?string $action = null,
        int $limit = 50,
        ?int $beforeId = null,
    ): array;

    /**
     * How many entries exist for a tenant — for the admin UI's page count.
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException caller is not a platform admin
     */
    public function countForTenant(string $tenantId): int;
}
