<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\ValueObjects;

/**
 * SqlIdentifier — the single shape check for a value that ends up interpolated
 * into inlined DDL (CREATE DATABASE/USER, GRANT — statements whose identifiers
 * cannot be parameter-bound).
 *
 * Kept as one Domain-layer predicate rather than a `preg_match()` copied at
 * each call site: {@see \Plugins\Tenancy\Application\Services\TenantAdminService::create()}
 * and {@see \Plugins\Tenancy\Infrastructure\Cli\CreateTenantCommand::handle()}
 * both provision tenants and both validate db_name/db_user through this same
 * pattern today — a future third path (bulk import, an API, …) gets the
 * guarantee structurally instead of needing to remember to copy the regex.
 *
 * Domain layer: zero external imports beyond Domain/ — a plain predicate, no
 * shared framework base class.
 */
final class SqlIdentifier
{
    /**
     * Letters, digits, underscore only — safe inside a backtick/bracket/quoted
     * identifier. The `D` modifier is required: without it PCRE's `$` matches
     * just before a trailing "\n", so "tenant\n" would otherwise pass.
     */
    public static function isValid(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z0-9_]+$/D', $value) === 1;
    }
}
