<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Provisioning;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\API\Contracts\TenantDatabaseProvisionerContract;
use Plugins\Tenancy\Domain\ValueObjects\SqlIdentifier;
use Plugins\Tenancy\Infrastructure\Cli\Concerns\ManagesTenantDatabase;

/**
 * DatabaseDdlProvisioner — the ONE implementation of tenant database DDL.
 *
 * `CREATE DATABASE`, `CREATE USER`/`CREATE ROLE`/`CREATE LOGIN`, `GRANT` and
 * their teardowns, per driver, escaped, idempotent, and with the grant scoped
 * to a single database. Everything that provisions a tenant database goes
 * through here: the CLI (`tenant:create`), the HTTP control plane (via
 * {@see DdlTenantProvisioner}), and any application that mints its own tenant
 * rows (via {@see TenantDatabaseProvisionerContract}, which is published).
 *
 * It had been written twice — once in the command, once in the adapter — and
 * the two copies had already drifted in their comments. A third copy in a
 * consuming project would have been the one that got the MySQL host part wrong.
 *
 * IDENTIFIERS ARE VALIDATED HERE, at the boundary that actually interpolates
 * them, and not only by the callers that remember to. A password cannot be
 * parameter-bound in DDL either, so it is escaped per driver and inlined.
 */
final class DatabaseDdlProvisioner implements TenantDatabaseProvisionerContract
{
    use ManagesTenantDatabase {
        databaseExists as private databaseExistsFor;
        dropDatabase as private dropDatabaseFor;
        dropDatabaseUser as private dropDatabaseUserFor;
    }

    private const DRIVERS = ['mysql', 'pgsql', 'sqlsrv'];

    public function __construct(private readonly DatabasePort $central)
    {
    }

    public function supports(string $driver): bool
    {
        return in_array($driver, self::DRIVERS, true);
    }

    public function databaseExists(string $driver, string $dbName): bool
    {
        $this->guard($driver, ['database' => $dbName]);

        return $this->databaseExistsFor($this->central, $driver, $dbName);
    }

    public function createDatabase(string $driver, string $dbName, bool $ifNotExists = true): void
    {
        $this->guard($driver, ['database' => $dbName]);

        if ($ifNotExists) {
            match ($driver) {
                'pgsql'  => $this->createPgDatabaseIfAbsent($dbName),
                'sqlsrv' => $this->central->execute("IF DB_ID('{$dbName}') IS NULL CREATE DATABASE [{$dbName}]"),
                default  => $this->central->execute("CREATE DATABASE IF NOT EXISTS `{$dbName}`"),
            };

            return;
        }

        // A plain CREATE, which FAILS on an existing database. The caller asked
        // for that: when the name is derived from the tenant's own name, silently
        // adopting a database somebody else keeps on this server is worse than
        // refusing to provision.
        match ($driver) {
            'pgsql'  => $this->central->execute("CREATE DATABASE \"{$dbName}\""),
            'sqlsrv' => $this->central->execute("CREATE DATABASE [{$dbName}]"),
            default  => $this->central->execute("CREATE DATABASE `{$dbName}`"),
        };
    }

    public function provisionUser(
        string $driver,
        string $dbName,
        string $dbUser,
        string $plainPassword,
        string $dbHost,
    ): void {
        $this->guard($driver, ['database' => $dbName, 'username' => $dbUser]);

        if ($driver === 'pgsql') {
            $pass   = "'" . str_replace("'", "''", $plainPassword) . "'";
            $exists = $this->central->queryOne('SELECT 1 AS present FROM pg_roles WHERE rolname = :u', ['u' => $dbUser]);
            if ($exists === null) {
                $this->central->execute("CREATE ROLE \"{$dbUser}\" LOGIN PASSWORD {$pass}");
            } else {
                $this->central->execute("ALTER ROLE \"{$dbUser}\" WITH LOGIN PASSWORD {$pass}");
            }
            // GRANT ON DATABASE only covers CONNECT/CREATE/TEMP. Make the role the
            // database OWNER so it also owns the public schema (via
            // pg_database_owner on PG 15+, and CREATE-to-PUBLIC on older versions)
            // — otherwise its template migrations cannot CREATE TABLE.
            $this->central->execute("GRANT ALL PRIVILEGES ON DATABASE \"{$dbName}\" TO \"{$dbUser}\"");
            $this->central->execute("ALTER DATABASE \"{$dbName}\" OWNER TO \"{$dbUser}\"");

            return;
        }

        if ($driver === 'sqlsrv') {
            $pass  = "'" . str_replace("'", "''", $plainPassword) . "'";
            $login = str_replace(']', ']]', $dbUser);   // escape ] in the bracketed identifier
            // Server-level LOGIN (idempotent) — create if absent, else force the
            // password so a pre-existing login can't keep a stale credential.
            $this->central->execute(
                "IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = '{$dbUser}') "
                . "CREATE LOGIN [{$login}] WITH PASSWORD = {$pass}; "
                . "ELSE ALTER LOGIN [{$login}] WITH PASSWORD = {$pass};"
            );
            // …then a database USER mapped to it, made db_owner of ONLY this database.
            $this->central->execute(
                "USE [{$dbName}]; "
                . "IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = '{$dbUser}') "
                . "CREATE USER [{$login}] FOR LOGIN [{$login}]; "
                . "ALTER ROLE db_owner ADD MEMBER [{$login}];"
            );

            return;
        }

        // MySQL / MariaDB — escape backslash then single quote for the literal.
        // The account is pinned to the connecting host (NEVER '%'): for a local
        // DB this is the loopback set so it works over both socket ('localhost')
        // and TCP ('127.0.0.1'); a remote host is bound to that exact host only.
        $pass = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $plainPassword) . "'";
        foreach ($this->grantHosts($dbHost) as $host) {
            // CREATE USER IF NOT EXISTS is a no-op — password included — when the
            // account already exists, so ALTER USER forces the credential we were
            // handed (otherwise a lingering account keeps its stale password and
            // the tenant connection fails "using password: YES").
            $this->central->execute("CREATE USER IF NOT EXISTS '{$dbUser}'@'{$host}' IDENTIFIED BY {$pass}");
            $this->central->execute("ALTER USER '{$dbUser}'@'{$host}' IDENTIFIED BY {$pass}");
            $this->central->execute("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'{$host}'");
        }
        $this->central->execute('FLUSH PRIVILEGES');
    }

    public function dropDatabase(string $driver, string $dbName): void
    {
        $this->guard($driver, ['database' => $dbName]);

        $this->dropDatabaseFor($this->central, $driver, $dbName);
    }

    public function dropUser(string $driver, string $dbUser, string $dbHost): void
    {
        $this->guard($driver, ['username' => $dbUser]);

        $this->dropDatabaseUserFor($this->central, $driver, $dbUser, $dbHost);
    }

    /**
     * PostgreSQL has no `CREATE DATABASE IF NOT EXISTS` — and CREATE DATABASE
     * cannot run inside a transaction block, so catching the duplicate-object
     * error is not equivalent either: on a pooled connection it can leave the
     * session aborted. Check the catalogue first.
     */
    private function createPgDatabaseIfAbsent(string $dbName): void
    {
        if (!$this->databaseExistsFor($this->central, 'pgsql', $dbName)) {
            $this->central->execute("CREATE DATABASE \"{$dbName}\"");
        }
    }

    /**
     * @param array<string, string> $identifiers label => value
     * @throws ServiceException
     */
    private function guard(string $driver, array $identifiers): void
    {
        if (!$this->supports($driver)) {
            throw new ServiceException(
                'tenancy.ddl.unsupported_driver',
                layer: 'infrastructure.tenancy.ddl',
                context: ['driver' => $driver, 'supported' => self::DRIVERS],
            );
        }

        foreach ($identifiers as $label => $value) {
            if (!SqlIdentifier::isValid($value)) {
                throw new ServiceException(
                    'tenancy.ddl.unsafe_identifier',
                    layer: 'infrastructure.tenancy.ddl',
                    context: [$label => $value],
                );
            }
        }
    }
}
