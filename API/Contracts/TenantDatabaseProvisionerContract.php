<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

/**
 * TenantDatabaseProvisionerContract — the tenant DATA-PLANE primitives, with no
 * tenant entity in sight.
 *
 * WHY THIS EXISTS ALONGSIDE TenantProvisioner
 * -------------------------------------------
 * {@see \Plugins\Tenancy\Application\Ports\TenantProvisioner} is the whole
 * provisioning step for a tenant this plugin OWNS: it takes a `Tenant` entity
 * and also runs the template migrations. That makes it unusable to anyone
 * outside the plugin twice over — the entity is a Domain internal, and the
 * binding is `bindInternal`, so resolving it from another scope throws
 * `ScopeViolationException`.
 *
 * Some applications mint their own tenant rows: a deployment where the tenant
 * id is CLIENT-supplied (so a server-assigned ULID is wrong), or where the
 * physical database is named after the tenant rather than `tnt_<slug>`, cannot
 * call `TenantAdminServiceContract::create()`. Those applications still must
 * not hand-write `CREATE DATABASE` / `CREATE USER` / `GRANT`: that SQL is
 * driver-specific, cannot parameter-bind its identifiers, and gets the host
 * part of a MySQL account wrong in the direction of `'%'`.
 *
 * So the DDL is published as primitives. Callers keep their own registry
 * writes, their own naming and their own compensation; the escaping, the driver
 * branches, the idempotency and the grant scoping live here, once.
 *
 * EVERY METHOD IS IDEMPOTENT and validates its own identifiers before
 * interpolating them — a caller cannot reach the inlined DDL with an unsafe
 * name even by accident.
 *
 * SQLite is NOT supported: it is file-per-database with no users or roles, so
 * there is no DDL to run. Create and unlink the file directly instead.
 */
interface TenantDatabaseProvisionerContract
{
    /** Does this physical database already exist on the server? */
    public function databaseExists(string $driver, string $dbName): bool;

    /**
     * Create the database.
     *
     * @param bool $ifNotExists true adopts an existing database of that name;
     *        false FAILS on one — pass false when the name is derived from
     *        something the caller owns (a tenant's own name), where adopting a
     *        stranger's database would be worse than failing.
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException
     *         unsupported driver, or an unsafe identifier
     */
    public function createDatabase(string $driver, string $dbName, bool $ifNotExists = true): void;

    /**
     * Create (or re-credential) a database account and grant it full privileges
     * on ONE database — its own. Existing accounts have the password forced to
     * $plainPassword, because an account left over from an earlier run keeps a
     * stale credential and the tenant connection then fails authentication.
     *
     * On MySQL the account is pinned to the host it will connect FROM, never
     * `'%'`; a loopback $dbHost pins the whole local set (socket + IPv4 + IPv6).
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException
     *         unsupported driver, or an unsafe identifier
     */
    public function provisionUser(
        string $driver,
        string $dbName,
        string $dbUser,
        string $plainPassword,
        string $dbHost,
    ): void;

    /** Drop the database if it is there. Never throws for an absent one. */
    public function dropDatabase(string $driver, string $dbName): void;

    /** Drop the account if it is there (every grant host on MySQL). */
    public function dropUser(string $driver, string $dbUser, string $dbHost): void;

    /** Drivers this provisioner can serve — 'mysql', 'pgsql', 'sqlsrv'. */
    public function supports(string $driver): bool;
}
