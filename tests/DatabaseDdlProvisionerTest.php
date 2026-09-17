<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Tests;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\Infrastructure\Provisioning\DatabaseDdlProvisioner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The published tenant DDL.
 *
 * Two properties are worth a test, because getting either wrong is invisible
 * until it is exploited rather than at the point of the mistake:
 *
 *  - a MySQL account is NEVER granted at `'%'` — an account reachable from any
 *    host is one a leaked password opens from anywhere;
 *  - an identifier that could break out of its quoting never reaches the
 *    statement at all, since DDL cannot parameter-bind one.
 */
#[CoversClass(DatabaseDdlProvisioner::class)]
final class DatabaseDdlProvisionerTest extends TestCase
{
    public function test_a_mysql_account_is_pinned_to_hosts_and_granted_on_one_database(): void
    {
        $db  = new RecordingDatabasePort();
        $ddl = new DatabaseDdlProvisioner($db);

        $ddl->provisionUser('mysql', 'tnt_acme', 'acme_user', 's3cret', '127.0.0.1');

        $sql = implode("\n", $db->statements);

        self::assertStringNotContainsString("'%'", $sql, "an account must never be granted at '%'");
        foreach (['localhost', '127.0.0.1', '::1'] as $host) {
            self::assertStringContainsString("'acme_user'@'{$host}'", $sql, "loopback host {$host}");
        }
        self::assertStringContainsString('GRANT ALL PRIVILEGES ON `tnt_acme`.*', $sql, 'one database only');
        self::assertStringContainsString(
            "ALTER USER 'acme_user'@'localhost' IDENTIFIED BY 's3cret'",
            $sql,
            'an existing account has its password forced, or it keeps a stale one',
        );
    }

    public function test_a_remote_host_is_pinned_to_exactly_that_host(): void
    {
        $db = new RecordingDatabasePort();
        (new DatabaseDdlProvisioner($db))->provisionUser('mysql', 'tnt_acme', 'acme_user', 'pw', 'db.internal');

        $sql = implode("\n", $db->statements);

        self::assertStringContainsString("'acme_user'@'db.internal'", $sql);
        self::assertStringNotContainsString("'acme_user'@'localhost'", $sql);
    }

    public function test_a_password_quote_cannot_close_the_literal(): void
    {
        $db = new RecordingDatabasePort();
        (new DatabaseDdlProvisioner($db))->provisionUser('mysql', 'tnt_acme', 'acme_user', "pw' OR '1", '127.0.0.1');

        foreach ($db->statements as $sql) {
            self::assertStringNotContainsString("IDENTIFIED BY 'pw' OR '1'", $sql);
        }
        self::assertStringContainsString("pw\\' OR \\'1", implode("\n", $db->statements));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeIdentifiers(): iterable
    {
        yield 'backtick'      => ['tnt`acme', 'a quote that closes the identifier'];
        yield 'statement end' => ['tnt_acme`; DROP DATABASE `x', 'a second statement'];
        yield 'space'         => ['tnt acme', 'a space'];
        yield 'empty'         => ['', 'nothing at all'];
        yield 'newline'       => ["tnt_acme\n", 'a trailing newline'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeIdentifiers')]
    public function test_an_unsafe_database_name_never_reaches_a_statement(string $dbName, string $why): void
    {
        $db = new RecordingDatabasePort();

        try {
            (new DatabaseDdlProvisioner($db))->createDatabase('mysql', $dbName);
            self::fail("createDatabase() accepted {$why}.");
        } catch (ServiceException $e) {
            self::assertSame('tenancy.ddl.unsafe_identifier', $e->getMessage());
        }

        self::assertSame([], $db->statements, 'nothing may be executed with an unsafe identifier');
    }

    public function test_sqlite_is_refused_because_it_has_no_users_or_roles(): void
    {
        $db  = new RecordingDatabasePort();
        $ddl = new DatabaseDdlProvisioner($db);

        self::assertFalse($ddl->supports('sqlite'));
        self::assertTrue($ddl->supports('mysql'));

        $this->expectException(ServiceException::class);
        $ddl->createDatabase('sqlite', 'tenants');
    }

    public function test_create_database_can_refuse_to_adopt_an_existing_one(): void
    {
        $db = new RecordingDatabasePort();
        (new DatabaseDdlProvisioner($db))->createDatabase('mysql', 'tnt_acme', ifNotExists: false);

        self::assertSame(['CREATE DATABASE `tnt_acme`'], $db->statements);

        $idempotent = new RecordingDatabasePort();
        (new DatabaseDdlProvisioner($idempotent))->createDatabase('mysql', 'tnt_acme');

        self::assertSame(['CREATE DATABASE IF NOT EXISTS `tnt_acme`'], $idempotent->statements);
    }
}

final class RecordingDatabasePort implements DatabasePort
{
    /** @var list<string> */
    public array $statements = [];

    public function query(string $sql, array $params = []): array { return []; }
    public function queryOne(string $sql, array $params = []): ?array { return null; }

    public function execute(string $sql, array $params = []): int
    {
        $this->statements[] = $sql;

        return 0;
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { return 0; }
    public function lastInsertId(?string $sequence = null): string { return '1'; }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function inTransaction(): bool { return false; }
}
