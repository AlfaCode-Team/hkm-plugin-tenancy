<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Provisioning;

use AlfaCode\LetMigrate\MigrationServiceFactory;
use Plugins\Tenancy\API\Contracts\TenantDatabaseProvisionerContract;
use Plugins\Tenancy\Application\Ports\TenantProvisioner;
use Plugins\Tenancy\Domain\Entities\Tenant;

/**
 * DdlTenantProvisioner — the data-plane adapter behind {@see TenantProvisioner}.
 *
 * Turns a `Tenant` entity into the provisioning steps: the database, its
 * account, and the template-migration run — exactly what the tenant:create CLI
 * command does, but reusable from the HTTP control plane.
 *
 * The DDL itself lives in {@see DatabaseDdlProvisioner}, which is published as
 * {@see TenantDatabaseProvisionerContract} so applications that mint their own
 * tenant rows reach the same escaping, driver branches and grant scoping
 * instead of hand-writing them. This class is the entity-shaped wrapper over
 * it, plus the migration run.
 */
final class DdlTenantProvisioner implements TenantProvisioner
{
    public function __construct(
        private readonly TenantDatabaseProvisionerContract $ddl,
        private readonly string $templatePath,
    ) {}

    public function databaseExists(Tenant $tenant): bool
    {
        return $this->ddl->databaseExists($tenant->dbDriver, $tenant->dbName);
    }

    public function provision(Tenant $tenant, string $plainPassword, bool $databaseAlreadyExists): void
    {
        // 1. Create the isolated database (idempotent, driver-aware).
        if (!$databaseAlreadyExists) {
            $this->ddl->createDatabase($tenant->dbDriver, $tenant->dbName);
        }

        // 2. Create the tenant DB user, granted on its database only.
        $this->ddl->provisionUser(
            $tenant->dbDriver,
            $tenant->dbName,
            $tenant->dbUsername,
            $plainPassword,
            $tenant->dbHost,
        );

        // 3. Run the tenant template migrations against the new database — as
        //    the TENANT's own account, which also proves the grant works before
        //    a request depends on it.
        $service = MigrationServiceFactory::fromConfig([
            'driver'        => $tenant->dbDriver,
            'host'          => $tenant->dbHost,
            'port'          => $tenant->dbPort,
            'database'      => $tenant->dbName,
            'username'      => $tenant->dbUsername,
            'password'      => $plainPassword,
            'paths'         => [$this->templatePath],
            'transactional' => true,
        ]);
        $service->install();
        $service->run();
    }

    public function teardown(Tenant $tenant, bool $dropDatabase): int
    {
        $failed = 0;

        try {
            $this->ddl->dropUser($tenant->dbDriver, $tenant->dbUsername, $tenant->dbHost);
        } catch (\Throwable) {
            $failed++;
        }

        if ($dropDatabase) {
            try {
                $this->ddl->dropDatabase($tenant->dbDriver, $tenant->dbName);
            } catch (\Throwable) {
                $failed++;
            }
        }

        return $failed;
    }
}
