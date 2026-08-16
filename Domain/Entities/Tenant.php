<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;

/**
 * Tenant — a view of one row of the central `tenants` registry.
 *
 * Carries the connection coordinates needed to reach the tenant's isolated
 * database. The password is stored ENCRYPTED here (dbPasswordEnc); decryption
 * happens only at the moment a connection is built, inside the resolver, never
 * in this entity. dbPasswordEnc is excluded from jsonSerialize()/__debugInfo()
 * so a stray json_encode()/var_dump() of this entity can never leak it — no
 * current call site serializes it directly (TenantDetail hand-picks fields for
 * the admin API instead), but the exclusion is cheap and the invariant is worth
 * holding regardless of who calls it today.
 *
 * Domain layer: zero external imports beyond Domain/ — a plain read model, no
 * shared framework base class.
 */
final class Tenant implements \JsonSerializable
{
    private function __construct(
        public readonly string $tenantId,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $dbDriver,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUsername,
        public readonly string $dbPasswordEnc,
        public readonly TenantStatus $status,
        public readonly int $schemaVersion,
        public readonly ?string $dbShard = null,
        /** tenant_id of the tenant that created this one, or null for a top-level tenant. */
        public readonly ?string $parentTenantId = null,
        /** Super-admin GRANT: may this tenant create its own sub-tenants? */
        public readonly bool $canCreateSubTenants = false,
    ) {
    }

    /**
     * Build a brand-new provisioning tenant. $dbPasswordEnc must ALREADY be the
     * ciphertext — this entity never sees or stores the plaintext credential.
     */
    public static function create(
        string $tenantId,
        string $name,
        string $slug,
        string $dbDriver,
        string $dbHost,
        int $dbPort,
        string $dbName,
        string $dbUsername,
        string $dbPasswordEnc,
        TenantStatus $status,
        int $schemaVersion = 0,
        ?string $dbShard = null,
        ?string $parentTenantId = null,
        bool $canCreateSubTenants = false,
    ): self {
        return new self(
            tenantId: $tenantId,
            name: $name,
            slug: $slug,
            dbDriver: $dbDriver,
            dbHost: $dbHost,
            dbPort: $dbPort,
            dbName: $dbName,
            dbUsername: $dbUsername,
            dbPasswordEnc: $dbPasswordEnc,
            status: $status,
            schemaVersion: $schemaVersion,
            dbShard: $dbShard,
            parentTenantId: $parentTenantId,
            canCreateSubTenants: $canCreateSubTenants,
        );
    }

    /**
     * Hydrate from a central-DB row. Reconstitution only — records no events.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            tenantId: (string) $row['tenant_id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            dbDriver: (string) $row['db_driver'],
            dbHost: (string) $row['db_host'],
            dbPort: (int) $row['db_port'],
            dbName: (string) $row['db_name'],
            dbUsername: (string) $row['db_username'],
            dbPasswordEnc: (string) $row['db_password_enc'],
            status: TenantStatus::from((int) $row['status']),
            schemaVersion: (int) ($row['schema_version'] ?? 0),
            dbShard: isset($row['db_shard']) ? (string) $row['db_shard'] : null,
            parentTenantId: isset($row['parent_tenant_id']) && $row['parent_tenant_id'] !== null
                ? (string) $row['parent_tenant_id']
                : null,
            canCreateSubTenants: (bool) ($row['can_create_sub_tenants'] ?? false),
        );
    }

    /** Stable connection name in the ConnectionManager registry. */
    public function connectionName(): string
    {
        return 'tenant:' . $this->tenantId;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->safeArray();
    }

    /** Redacts the encrypted credential from var_dump()/debug output too. */
    public function __debugInfo(): array
    {
        return $this->safeArray();
    }

    /** @return array<string, mixed> everything except dbPasswordEnc. */
    private function safeArray(): array
    {
        return [
            'tenantId' => $this->tenantId,
            'name' => $this->name,
            'slug' => $this->slug,
            'dbDriver' => $this->dbDriver,
            'dbHost' => $this->dbHost,
            'dbPort' => $this->dbPort,
            'dbName' => $this->dbName,
            'dbUsername' => $this->dbUsername,
            'status' => $this->status,
            'schemaVersion' => $this->schemaVersion,
            'dbShard' => $this->dbShard,
            'parentTenantId' => $this->parentTenantId,
            'canCreateSubTenants' => $this->canCreateSubTenants,
        ];
    }
}
