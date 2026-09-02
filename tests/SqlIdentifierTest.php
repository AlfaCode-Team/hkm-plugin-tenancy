<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Tenancy\Domain\ValueObjects\SqlIdentifier;

/**
 * SqlIdentifier is the single shape check standing between tenant-supplied
 * db_name/db_user input and inline DDL (CREATE DATABASE/USER, GRANT) that
 * cannot be parameter-bound — see TenantAdminService::create() and
 * CreateTenantCommand::handle(). A regex regression here is a SQL injection.
 */
#[CoversClass(SqlIdentifier::class)]
final class SqlIdentifierTest extends TestCase
{
    public function test_accepts_letters_digits_and_underscore(): void
    {
        $this->assertTrue(SqlIdentifier::isValid('tenant_db_1'));
        $this->assertTrue(SqlIdentifier::isValid('A'));
        $this->assertTrue(SqlIdentifier::isValid('123'));
        $this->assertTrue(SqlIdentifier::isValid('Acme_Tenant_DB'));
    }

    public function test_rejects_empty_string(): void
    {
        $this->assertFalse(SqlIdentifier::isValid(''));
    }

    public function test_rejects_whitespace(): void
    {
        $this->assertFalse(SqlIdentifier::isValid('tenant db'));
        $this->assertFalse(SqlIdentifier::isValid(' tenant'));
        $this->assertFalse(SqlIdentifier::isValid("tenant\n"));
    }

    public function test_rejects_hyphen_and_dot(): void
    {
        $this->assertFalse(SqlIdentifier::isValid('tenant-db'));
        $this->assertFalse(SqlIdentifier::isValid('tenant.db'));
    }

    public function test_rejects_quoting_and_escape_characters_used_by_every_supported_driver(): void
    {
        // backtick (MySQL), double-quote (Postgres), bracket (SQL Server), single-quote (all).
        $this->assertFalse(SqlIdentifier::isValid('a`b'));
        $this->assertFalse(SqlIdentifier::isValid('a"b'));
        $this->assertFalse(SqlIdentifier::isValid('a]b'));
        $this->assertFalse(SqlIdentifier::isValid("a'b"));
    }

    public function test_rejects_statement_terminators_and_comment_markers(): void
    {
        $this->assertFalse(SqlIdentifier::isValid('x; DROP TABLE tenants;--'));
        $this->assertFalse(SqlIdentifier::isValid("x'; DROP TABLE tenants;--"));
        $this->assertFalse(SqlIdentifier::isValid('x/*comment*/'));
    }
}
