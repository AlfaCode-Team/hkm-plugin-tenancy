<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\Domain\Entities\Tenant;

/**
 * TenantRegistry — reads the central `tenants` table.
 *
 * Access rule: DatabasePort ONLY (this is a repository). The injected
 * DatabasePort is the CENTRAL connection (the ConnectionManager default) — the
 * registry never resolves a tenant connection itself, which would be circular.
 *
 * Lookups are cached in CachePort with a short TTL so the central DB is hit
 * once per tenant per TTL rather than once per request. A miss (unknown tenant)
 * is cached as a sentinel to absorb lookup storms for non-existent ids.
 */
final class TenantRegistry implements TenantRegistryContract
{
    private const MISS = '__tenancy_miss__';

    public function __construct(
        private readonly DatabasePort $central,
        private readonly CachePort $cache,
        private readonly int $ttl = 60,
    ) {}

    public function find(string $tenantId): ?Tenant
    {
        // Deliberately NO worker-local L1 cache in front of CachePort here (a
        // short-TTL local memo was tried and reverted): forget() below can only
        // clear the CALLING worker's own local state, so a control-plane
        // suspend/delete/rotate on worker B would leave worker A serving a
        // stale row for up to the memo's TTL — contradicting the "takes effect
        // immediately" guarantee this class and TenantConnectionResolver both
        // document and rely on (status is re-checked on every for() call
        // specifically so a suspension can never linger on a warm connection).
        // The shared CachePort tier below is what makes forget() actually
        // immediate across every worker, so it stays the only cache here.
        $key = $this->key($tenantId);

        $cached = $this->cache->get($key);
        if ($cached === self::MISS) {
            return null;
        }
        if (is_array($cached)) {
            return Tenant::fromRow($cached);
        }

        $row = $this->central->queryOne(
            'SELECT tenant_id, name, slug, db_driver, db_host, db_port, db_name,
                    db_username, db_password_enc, db_shard, status, schema_version
               FROM tenants
              WHERE tenant_id = :id AND deleted_at IS NULL',
            ['id' => $tenantId],
        );

        if ($row === null) {
            $this->cache->set($key, self::MISS, $this->jitteredTtl());
            return null;
        }

        // Cache the raw row (cheaply serializable) rather than the entity.
        $this->cache->set($key, $row, $this->jitteredTtl());

        return Tenant::fromRow($row);
    }

    /** +/-10% jitter so a fleet of hot keys does not expire in lockstep and stampede the DB. */
    private function jitteredTtl(): int
    {
        $spread = max(1, intdiv($this->ttl, 10));

        return max(1, $this->ttl + random_int(-$spread, $spread));
    }

    public function exists(string $tenantId): bool
    {
        return $this->find($tenantId)?->status->isRoutable() === true;
    }

    /** Hard ceiling applied when a caller passes no $limit, so a large fleet can never load unbounded. */
    private const MAX_UNPAGED_LIMIT = 1000;

    public function listByStatus(int $status, ?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT tenant_id, name, slug, db_driver, db_host, db_port, db_name,
                       db_username, db_password_enc, db_shard, status, schema_version
                  FROM tenants
                 WHERE status = :status AND deleted_at IS NULL
                 ORDER BY id ASC
                 LIMIT :limit OFFSET :offset';

        $rows = $this->central->query($sql, [
            'status' => $status,
            'limit'  => $limit !== null ? max(1, $limit) : self::MAX_UNPAGED_LIMIT,
            'offset' => $offset,
        ]);

        return array_map(static fn (array $r): Tenant => Tenant::fromRow($r), $rows);
    }

    public function forget(string $tenantId): void
    {
        $this->cache->delete($this->key($tenantId));
    }

    private function key(string $tenantId): string
    {
        return 'tenancy:registry:' . $tenantId;
    }
}
