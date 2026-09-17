<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Cli;

use AlfaCode\LetMigrate\MigrationServiceFactory;
use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\Components\NumberInput;
use AlfacodeTeam\PhpIoCli\Components\Password;
use AlfacodeTeam\PhpIoCli\Components\RadioGroup;
use AlfacodeTeam\PhpIoCli\Components\TextInput;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Tenancy\API\Contracts\TenantDatabaseProvisionerContract;
use Plugins\Tenancy\Infrastructure\Provisioning\DatabaseDdlProvisioner;
use Plugins\Tenancy\Domain\ValueObjects\SqlIdentifier;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;
use Plugins\Tenancy\Support\TenantsFile;
use Plugins\Tenancy\Support\Token;

/**
 * tenants:create — provision a new tenant (CONTROL PLANE + DATA PLANE).
 *
 * Steps, with compensating status so a half-provisioned tenant is detectable:
 *   1. Insert a `tenants` registry row (status=provisioning), password ENCRYPTED.
 *   2. CREATE DATABASE for the tenant (idempotent — IF NOT EXISTS).
 *   3. Run the tenant template migrations against the new database.
 *   4. Flip status=active and stamp schema_version.
 *
 * If step 2/3 fails the row stays `provisioning` (never `active`), so the
 * resolver fast-fails routing to it and ops can retry.
 */
final class CreateTenantCommand extends AbstractCommand
{
    /** Friendly label => canonical driver token (the interactive picker list). */
    private const DRIVERS = [
        'MySQL / MariaDB' => 'mysql',
        'PostgreSQL'      => 'pgsql',
        'SQL Server'      => 'sqlsrv',
    ];

    private ?TenantDatabaseProvisionerContract $ddl = null;

    public function __construct(
        private readonly DatabaseConnectionManagerContract $connections,
        private readonly EncryptionPort $crypto,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'tenant:create';
        $this->description = 'Provision a new tenant: registry row + isolated database + template migrations';

        $this->addOption('name', '', 'Human-readable tenant name', acceptsValue: true);
        $this->addOption('slug', '', 'DNS/db-safe slug (^[a-z0-9-]+$)', acceptsValue: true);
        $this->addOption('driver', '', 'mysql|pgsql|sqlsrv', acceptsValue: true, default: 'mysql');
        $this->addOption('db-host', '', 'Tenant DB host', acceptsValue: true, default: '127.0.0.1');
        $this->addOption('db-port', '', 'Tenant DB port (default per driver: 3306/5432/1433)', acceptsValue: true, default: '');
        $this->addOption('db-name', '', 'Physical database name (e.g. tnt_acme)', acceptsValue: true);
        $this->addOption('db-user', '', 'Tenant DB username', acceptsValue: true);
        $this->addOption('db-password', '', 'Tenant DB password (stored encrypted)', acceptsValue: true, default: '');
        $this->addOption('template', '', 'Override template migrations path', acceptsValue: true);
        $this->addOption('dry-run', '', 'Validate inputs and check for conflicts without creating anything');
    }

    protected function handle(): int
    {
        $driver = strtolower((string) $this->option('driver', 'mysql'));
        $name   = (string) $this->option('name');
        $slug   = strtolower((string) $this->option('slug'));
        $dbName = (string) $this->option('db-name');
        $dbUser = (string) $this->option('db-user');
        $dbHost = (string) $this->option('db-host', '127.0.0.1');
        $dbPort = (string) $this->option('db-port');
        $dbPass = (string) $this->option('db-password', '');

        // No required values on the command line → walk the operator through it
        // one prompt at a time: pick the driver from a list FIRST, then collect
        // the rest with driver-aware defaults. Needs a TTY; CI must pass flags.
        if ($name === '' || $slug === '' || $dbName === '' || $dbUser === '') {
            if (!$this->isInteractive()) {
                $this->error('Required: --name --slug --db-name --db-user (or run in an interactive terminal to be prompted).');
                return self::FAILURE;
            }

            // Driver FIRST — a radio list of the supported engines.
            $radio   = new RadioGroup('Select the database driver', array_keys(self::DRIVERS));
            $current = array_search($driver, self::DRIVERS, true);
            if ($current !== false) {
                $radio->default($current);
            }
            $driver = self::DRIVERS[(string) $radio->run()] ?? $driver;

            // Inline validators — the prompt blocks until the value is valid.
            $idRule = static fn (string $v): ?string =>
                SqlIdentifier::isValid($v) ? null : 'Letters, digits and underscore only.';

            if ($name === '') {
                $name = (string) (new TextInput('Tenant display name'))
                    ->placeholder('Acme Inc')
                    ->validate(static fn (string $v): ?string => trim($v) === '' ? 'A name is required.' : null)
                    ->run();
            }
            if ($slug === '') {
                $slug = strtolower((string) (new TextInput('Tenant slug'))
                    ->default($this->slugify($name))
                    ->validate(static fn (string $v): ?string =>
                        preg_match('/^[a-z0-9-]+$/', strtolower($v)) ? null : 'Use ^[a-z0-9-]+$ only.')
                    ->run());
            }
            if ($dbName === '') {
                $dbName = (string) (new TextInput('Physical database name'))
                    ->default('tnt_' . $this->identifier($slug))
                    ->validate($idRule)
                    ->run();
            }
            if ($dbUser === '') {
                $dbUser = (string) (new TextInput('Database username'))
                    ->default($this->identifier($slug) . '_user')
                    ->validate($idRule)
                    ->run();
            }
            if (!$this->hasOption('db-password')) {
                $dbPass = (string) (new Password('Database password (stored encrypted)'))->showStrength()->run();
            }
            $dbHost = (string) (new TextInput('Database host'))
                ->default($dbHost !== '' ? $dbHost : '127.0.0.1')
                ->run();
            $dbPort = (string) (int) (new NumberInput('Database port'))
                ->integer()
                ->min(1)
                ->max(65535)
                ->default((float) ($dbPort !== '' ? $dbPort : $this->defaultPort($driver)))
                ->run();
        }

        if ($dbPort === '') {
            $dbPort = $this->defaultPort($driver);
        }
        $dbPort = (int) $dbPort;

        if ($name === '' || $slug === '' || $dbName === '' || $dbUser === '') {
            $this->error('Required: --name --slug --db-name --db-user');
            return self::FAILURE;
        }
        if (in_array($driver, ['sqlite', 'sqlite3'], true)) {
            $this->error("SQLite is file-per-database with no users/roles — tenant:create's CREATE DATABASE/USER model does not apply. Provision SQLite tenants as one file per tenant instead.");
            return self::FAILURE;
        }
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
            $this->error("Unsupported --driver [{$driver}] — use 'mysql', 'pgsql', or 'sqlsrv'.");
            return self::FAILURE;
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $this->error("Invalid slug [{$slug}] — must match ^[a-z0-9-]+$");
            return self::FAILURE;
        }
        if (!SqlIdentifier::isValid($dbName)) {
            $this->error("Unsafe --db-name [{$dbName}] — letters, digits, underscore only.");
            return self::FAILURE;
        }
        if (!SqlIdentifier::isValid($dbUser)) {
            $this->error("Unsafe --db-user [{$dbUser}] — letters, digits, underscore only.");
            return self::FAILURE;
        }
        if (!preg_match('/^[A-Za-z0-9_.:\-]+$/', $dbHost)) {
            $this->error("Unsafe --db-host [{$dbHost}] — hostname/IP characters only.");
            return self::FAILURE;
        }

        if ($this->hasOption('dry-run')) {
            return $this->printDryRunReport($driver, $name, $slug, $dbName, $dbUser, $dbHost, $dbPort);
        }

        // Interactive runs get a final confirmation with the resolved settings.
        if ($this->isInteractive()) {
            $this->alertInfo('Provision tenant', [
                "driver   : {$driver}",
                "name     : {$name}",
                "slug     : {$slug}",
                "database : {$dbName} @ {$dbHost}:{$dbPort}",
                "username : {$dbUser}",
            ]);
            if (!$this->confirm('Create this tenant now?', true)) {
                $this->warning('Aborted — nothing was provisioned.');
                return self::SUCCESS;
            }
        }

        $central  = $this->connections->default();
        $tenantId = Token::ulid();

        // Atomic provisioning. DDL (CREATE DATABASE/USER) can't be rolled back by
        // a SQL transaction on MySQL, so on ANY failure we COMPENSATE: drop the
        // user, drop the database (only if WE created it), delete the registry
        // row — leaving the system exactly as it was before the command ran.
        $dbPreExisted = $this->ddl()->databaseExists($driver, $dbName);
        $dbCreated    = false;
        $userCreated  = false;

        try {
            // 1. Registry row (provisioning), password encrypted.
            $central->execute(
                'INSERT INTO tenants
                    (tenant_id, name, slug, db_driver, db_host, db_port, db_name,
                     db_username, db_password_enc, status, schema_version)
                 VALUES (:id, :name, :slug, :driver, :host, :port, :db, :user, :pass, :status, 0)',
                [
                    'id' => $tenantId, 'name' => $name, 'slug' => $slug,
                    'driver' => $driver, 'host' => $dbHost, 'port' => $dbPort,
                    'db' => $dbName, 'user' => $dbUser,
                    'pass' => $this->crypto->encryptString($dbPass),
                    'status' => TenantStatus::Provisioning->value,
                ],
            );
            $this->info("Registered tenant [{$tenantId}] (slug={$slug}, status=provisioning).");

            // 2. Create the isolated database (idempotent, driver-aware).
            if (!$dbPreExisted) {
                $this->ddl()->createDatabase($driver, $dbName);
                $dbCreated = true;
            }
            $this->info("Database [{$dbName}] ready.");

            // 2b. Create the tenant DB user, granted on its database only.
            $this->ddl()->provisionUser($driver, $dbName, $dbUser, $dbPass, $dbHost);
            $userCreated = true;
            $this->info("User [{$dbUser}] granted on [{$dbName}].");

            // 3. Run template migrations against the new tenant database.
            $template = (string) ($this->option('template') ?: $this->defaultTemplatePath());
            $service  = MigrationServiceFactory::fromConfig([
                'driver'   => $driver,
                'host'     => $dbHost,
                'port'     => $dbPort,
                'database' => $dbName,
                'username' => $dbUser,
                'password' => $dbPass,
                'paths'    => [$template],
                'transactional' => true,
            ]);
            $service->install();
            $result = $service->run();
            $this->success("Applied {$result->appliedCount()} template migration(s).");

            // 4. Activate.
            $central->execute(
                'UPDATE tenants SET status = :s, schema_version = :v WHERE tenant_id = :id',
                ['s' => TenantStatus::Active->value, 'v' => 1, 'id' => $tenantId],
            );
        } catch (\Throwable $e) {
            $this->error("Provisioning failed: {$e->getMessage()}");
            $this->rollbackProvisioning($central, $driver, $dbName, $dbUser, $dbHost, $tenantId, $dbCreated, $userCreated);
            return self::FAILURE;
        }

        $this->success("Tenant [{$tenantId}] is ACTIVE.");

        // Remember the tenant in var/tenants.json (last created = default) so
        // tenant:delete / tenant:host:add work without --tenant/--slug.
        // Best-effort convenience — never fail a provisioned tenant over it.
        try {
            TenantsFile::remember($tenantId, $slug, $name);
            $this->info("Recorded as default tenant in " . TenantsFile::path() . '.');
        } catch (\Throwable) {
        }

        return self::SUCCESS;
    }

    /**
     * --dry-run: run every read-only check handle() would run before its first
     * mutation (slug collision against the central registry, whether the target
     * physical database already exists) and report the outcome — no INSERT, no
     * CREATE DATABASE/USER, no migration ever runs. Risk mitigation before
     * provisioning a real tenant on a fleet an operator doesn't want to pollute
     * with a typo'd run.
     */
    private function printDryRunReport(
        string $driver,
        string $name,
        string $slug,
        string $dbName,
        string $dbUser,
        string $dbHost,
        int $dbPort,
    ): int {
        $central = $this->connections->default();

        $slugTaken = $central->queryOne('SELECT 1 AS p FROM tenants WHERE slug = :s', ['s' => $slug]) !== null;
        $dbTaken   = $this->ddl()->databaseExists($driver, $dbName);
        $template  = (string) ($this->option('template') ?: $this->defaultTemplatePath());

        $this->alertInfo('Dry run — nothing will be created', [
            "driver   : {$driver}",
            "name     : {$name}",
            "slug     : {$slug}" . ($slugTaken ? '  [CONFLICT: already registered]' : '  [free]'),
            "database : {$dbName} @ {$dbHost}:{$dbPort}" . ($dbTaken ? '  [WARNING: already exists on this server]' : '  [does not exist yet]'),
            "username : {$dbUser}",
            "template : {$template}",
        ]);

        if ($slugTaken) {
            $this->error('Would fail: a tenant with this slug already exists in the registry.');

            return self::FAILURE;
        }

        $this->success('Dry run OK — inputs are valid and the slug is free. Re-run without --dry-run to provision.');

        return self::SUCCESS;
    }

    /**
     * Compensating teardown — undo everything this run created, in reverse order
     * (user → database → registry row), so a failed provisioning leaves no trace.
     * Each step is isolated so one teardown failure cannot abort the rest.
     */
    private function rollbackProvisioning(
        DatabasePort $central,
        string $driver,
        string $dbName,
        string $dbUser,
        string $dbHost,
        string $tenantId,
        bool $dbCreated,
        bool $userCreated,
    ): void {
        $this->warning('Rolling back…');

        if ($userCreated) {
            try {
                $this->ddl()->dropUser($driver, $dbUser, $dbHost);
                $this->info("· dropped user [{$dbUser}].");
            } catch (\Throwable $e) {
                $this->error("· could not drop user [{$dbUser}]: {$e->getMessage()}");
            }
        }

        if ($dbCreated) {
            try {
                $this->ddl()->dropDatabase($driver, $dbName);
                $this->info("· dropped database [{$dbName}].");
            } catch (\Throwable $e) {
                $this->error("· could not drop database [{$dbName}]: {$e->getMessage()}");
            }
        }

        try {
            $central->execute('DELETE FROM tenants WHERE tenant_id = :id', ['id' => $tenantId]);
            $this->info('· removed registry row.');
        } catch (\Throwable $e) {
            $this->error("· could not remove registry row: {$e->getMessage()}");
        }

        $this->warning('Rollback complete — nothing was left provisioned.');
    }

    /**
     * The shared tenant DDL provisioner — CREATE/DROP DATABASE, CREATE/DROP
     * USER, GRANT, per driver, escaped and idempotent. Held in one place (and
     * published as {@see TenantDatabaseProvisionerContract}) so the command,
     * the HTTP control plane and a consuming project cannot drift apart on the
     * SQL that grants a tenant access to a database.
     */
    private function ddl(): TenantDatabaseProvisionerContract
    {
        return $this->ddl ??= new DatabaseDdlProvisioner($this->connections->default());
    }

    /** True only when STDIN is a real terminal — guards the prompt wizard. */
    private function isInteractive(): bool
    {
        return \function_exists('stream_isatty') && @stream_isatty(\STDIN);
    }

    /** Conventional default port per driver. */
    private function defaultPort(string $driver): string
    {
        return match ($driver) {
            'pgsql'  => '5432',
            'sqlsrv' => '1433',
            default  => '3306',
        };
    }

    /** Turn an arbitrary string into a DNS/db-safe slug (^[a-z0-9-]+$). */
    private function slugify(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))) ?? '';

        return trim($slug, '-');
    }

    /** Turn a slug into a safe SQL identifier fragment (^[a-z0-9_]+$). */
    private function identifier(string $value): string
    {
        return preg_replace('/[^a-z0-9_]+/', '_', strtolower($value)) ?? '';
    }

    private function defaultTemplatePath(): string
    {
        $custom = env('TENANCY_TEMPLATE_PATH');
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        return dirname(__DIR__, 2) . '/database/tenant-template';
    }
}
