<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\Application\Ports\TenantWriteStore;
use Plugins\Tenancy\Domain\Entities\Tenant;

/**
 * TenantAdminRepository — central `tenants` registry persistence for the
 * control-plane CRUD flow. DatabasePort only; \PDOException is always
 * translated to RepositoryException so no vendor exception escapes this layer.
 *
 * Pinned to the CENTRAL connection by the Provider (ConnectionManager default) —
 * tenant CRUD must never be redirected to a tenant database.
 */
final class TenantAdminRepository implements TenantWriteStore
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function paginate(int $limit, int $offset, ?string $search = null, ?int $status = null): array
    {
        [$where, $params] = $this->filterClause($search, $status);

        try {
            $rows = $this->db->query(
                "SELECT * FROM tenants{$where} ORDER BY status ASC, name ASC LIMIT :limit OFFSET :offset",
                $params + ['limit' => $limit, 'offset' => $offset],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list tenants', layer: 'repository.tenant_admin', previous: $e);
        }

        return array_map(static fn (array $row): Tenant => Tenant::fromRow($row), $rows);
    }

    public function count(?string $search = null, ?int $status = null): int
    {
        [$where, $params] = $this->filterClause($search, $status);

        try {
            $row = $this->db->queryOne("SELECT COUNT(*) AS c FROM tenants{$where}", $params);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to count tenants', layer: 'repository.tenant_admin', previous: $e);
        }

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Shared WHERE-clause builder for paginate()/count() so the two never drift —
     * a page whose filters don't match its own total count is a worse UI bug
     * than no filtering at all.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filterClause(?string $search, ?int $status): array
    {
        $clauses = [];
        $params  = [];

        if ($search !== null && $search !== '') {
            // Escape LIKE metacharacters in user input so a search for a literal
            // '%' or '_' in a tenant name/slug doesn't act as a wildcard.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $clauses[] = '(name LIKE :search OR slug LIKE :search)';
            $params['search'] = '%' . $escaped . '%';
        }
        if ($status !== null) {
            $clauses[] = 'status = :status';
            $params['status'] = $status;
        }

        if ($clauses === []) {
            return ['', []];
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    public function find(string $tenantId): ?Tenant
    {
        try {
            $row = $this->db->queryOne('SELECT * FROM tenants WHERE tenant_id = :id', ['id' => $tenantId]);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to load tenant', layer: 'repository.tenant_admin', context: ['id' => $tenantId], previous: $e);
        }

        return $row === null ? null : Tenant::fromRow($row);
    }

    public function slugExists(string $slug, ?string $exceptId = null): bool
    {
        try {
            $row = $exceptId === null
                ? $this->db->queryOne('SELECT 1 AS p FROM tenants WHERE slug = :s', ['s' => $slug])
                : $this->db->queryOne('SELECT 1 AS p FROM tenants WHERE slug = :s AND tenant_id <> :id', ['s' => $slug, 'id' => $exceptId]);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to check slug', layer: 'repository.tenant_admin', context: ['slug' => $slug], previous: $e);
        }

        return $row !== null;
    }

    public function insert(Tenant $tenant): void
    {
        try {
            $this->db->execute(
                'INSERT INTO tenants
                    (tenant_id, parent_tenant_id, name, slug, db_driver, db_host, db_port, db_name,
                     db_username, db_password_enc, status, schema_version, can_create_sub_tenants)
                 VALUES (:id, :parent_id, :name, :slug, :driver, :host, :port, :db, :user, :pass, :status, :version, :can_create_sub_tenants)',
                [
                    'id' => $tenant->tenantId, 'parent_id' => $tenant->parentTenantId,
                    'name' => $tenant->name, 'slug' => $tenant->slug,
                    'driver' => $tenant->dbDriver, 'host' => $tenant->dbHost, 'port' => $tenant->dbPort,
                    'db' => $tenant->dbName, 'user' => $tenant->dbUsername,
                    'pass' => $tenant->dbPasswordEnc,
                    'status' => $tenant->status->value, 'version' => $tenant->schemaVersion,
                    'can_create_sub_tenants' => $tenant->canCreateSubTenants ? 1 : 0,
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to insert tenant', layer: 'repository.tenant_admin', context: ['id' => $tenant->tenantId], previous: $e);
        }
    }

    public function findByParent(string $parentTenantId): array
    {
        try {
            $rows = $this->db->query(
                'SELECT * FROM tenants WHERE parent_tenant_id = :id ORDER BY name ASC',
                ['id' => $parentTenantId],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list sub-tenants', layer: 'repository.tenant_admin', context: ['id' => $parentTenantId], previous: $e);
        }

        return array_map(static fn (array $row): Tenant => Tenant::fromRow($row), $rows);
    }

    public function hasChildren(string $tenantId): bool
    {
        try {
            $row = $this->db->queryOne('SELECT 1 AS p FROM tenants WHERE parent_tenant_id = :id', ['id' => $tenantId]);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to check for sub-tenants', layer: 'repository.tenant_admin', context: ['id' => $tenantId], previous: $e);
        }

        return $row !== null;
    }

    public function countByParent(string $parentTenantId): int
    {
        try {
            $row = $this->db->queryOne('SELECT COUNT(*) AS c FROM tenants WHERE parent_tenant_id = :id', ['id' => $parentTenantId]);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to count sub-tenants', layer: 'repository.tenant_admin', context: ['id' => $parentTenantId], previous: $e);
        }

        return (int) ($row['c'] ?? 0);
    }

    public function setCanCreateSubTenants(string $tenantId, bool $allowed): void
    {
        try {
            $this->db->execute(
                'UPDATE tenants SET can_create_sub_tenants = :v WHERE tenant_id = :id',
                ['v' => $allowed ? 1 : 0, 'id' => $tenantId],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to update sub-tenant grant', layer: 'repository.tenant_admin', context: ['id' => $tenantId], previous: $e);
        }
    }

    public function markActive(string $tenantId, int $schemaVersion): void
    {
        try {
            $this->db->execute(
                'UPDATE tenants SET status = :s, schema_version = :v WHERE tenant_id = :id',
                ['s' => \Plugins\Tenancy\Domain\ValueObjects\TenantStatus::Active->value, 'v' => $schemaVersion, 'id' => $tenantId],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to activate tenant', layer: 'repository.tenant_admin', context: ['id' => $tenantId], previous: $e);
        }
    }

    public function updateMeta(string $tenantId, ?string $name, ?string $slug, ?int $status): void
    {
        $sets   = [];
        $params = ['id' => $tenantId];

        if ($name !== null) {
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($slug !== null) {
            $sets[] = 'slug = :slug';
            $params['slug'] = $slug;
        }
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($sets === []) {
            return;
        }

        try {
            $this->db->execute('UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE tenant_id = :id', $params);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to update tenant', layer: 'repository.tenant_admin', context: ['id' => $tenantId], previous: $e);
        }
    }

    public function delete(string $tenantId): void
    {
        try {
            $this->db->execute('DELETE FROM tenants WHERE tenant_id = :id', ['id' => $tenantId]);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to delete tenant', layer: 'repository.tenant_admin', context: ['id' => $tenantId], previous: $e);
        }
    }
}
