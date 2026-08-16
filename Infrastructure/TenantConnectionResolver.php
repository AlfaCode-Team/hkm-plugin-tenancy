<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Database\Infrastructure\Drivers\MySQLConfiguration;
use Plugins\Database\Infrastructure\Drivers\PostgreSQLConfiguration;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\Domain\Entities\Tenant;
use Plugins\Tenancy\Domain\Exceptions\TenantUnavailableException;
use Plugins\Tenancy\Domain\Exceptions\UnknownTenantException;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Logger\Infrastructure\NullLogger;

/**
 * TenantConnectionResolver — maps tenant_id -> isolated DatabasePort.
 *
 * Sits on top of plugins/Database's ConnectionManager: it registers a named
 * connection per tenant ("tenant:<id>") whose config is derived from the
 * central registry row, then asks the manager to resolve it. The manager
 * caches the resolved adapter and builds it lazily, so reuse and lazy socket
 * opening come for free.
 *
 * Guarantees:
 *   - Fail closed. Unknown / suspended / deleted / unreachable -> throw. Never
 *     fall back to another tenant or the central DB.
 *   - Per-tenant circuit breaker. After N consecutive failures the breaker
 *     opens and the tenant fast-fails for a cooldown window, isolating one dead
 *     tenant DB from the rest of the fleet.
 *
 * Swoole safety: this resolver is app-lifetime, but the DatabasePort it returns
 * is bound into the per-request ModuleContainer by TenantContextStage and
 * discarded on reset(). Two coroutines serving different tenants get different
 * bindings. The resolver itself holds no per-request state.
 */
final class TenantConnectionResolver implements TenantConnectionResolverContract
{
    /**
     * Decrypted-credential cache, keyed by "tenantId:ciphertextHash" -> [plaintext, expiresAt].
     *
     * This resolver is bound per request (a fresh instance per ModuleContainer),
     * so an ordinary instance property would buy nothing across requests. A
     * `static` property belongs to the CLASS, not the instance, so under a
     * long-lived OpenSwoole worker it survives being rebuilt every request —
     * decryptString() then runs once per tenant per worker instead of once per
     * request. Under PHP-FPM each request is a fresh process, so this is a
     * correctness-neutral no-op there.
     *
     * Deliberately NOT stored in CachePort: a shared/network cache (Redis, etc.)
     * would put plaintext DB passwords somewhere new and persistent that anyone
     * with cache access can read. Process memory only, bounded, and TTL'd.
     *
     * Keyed by a hash of the CIPHERTEXT (not just tenantId), so a credential
     * rotation (new db_password_enc) is automatically a cache miss — no explicit
     * invalidation needed for that case, only for invalidate() below.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $credentialCache = [];

    /** FIFO cap so a large or churning fleet cannot grow this without bound in one worker. */
    private const MAX_CACHED_CREDENTIALS = 500;

    /**
     * Worker-local record of "this process has seen a connectivity failure for
     * this tenant since its last success" — see recordFailure()/recordSuccess().
     * Bounded the same way as $credentialCache, for the same reason.
     */
    private static array $dirtyTenants = [];

    private const MAX_DIRTY_TENANTS = 500;

    /**
     * Worker-local, SHORT-lived memo of "the breaker is open for this tenant"
     * (tenantId => expires-at unix timestamp). Only ever caches the REJECT
     * verdict, never the pass verdict — caching "closed" locally would let this
     * worker keep serving a tenant for up to the memo's TTL after another worker
     * observed it fail, which breaks the fail-closed guarantee documented on
     * this class. Caching "open" locally is always safe in that regard: it can
     * only make rejection faster, never wrongly grant access. This is what
     * spares the cache backend from being hammered with has() checks by every
     * request hitting an already-known-dead tenant.
     */
    private static array $breakerOpenL1 = [];

    private const BREAKER_L1_TTL = 2;

    private const MAX_BREAKER_L1_ENTRIES = 500;

    /**
     * Least-recently-used order of warm connection names this worker has built,
     * used to cap how many stay open at once — see capWarmConnections(). A
     * long-lived Swoole worker that has ever served N distinct tenants would
     * otherwise hold N open sockets/file handles forever, even though only a
     * handful are ever active at once.
     */
    private static array $warmOrder = [];

    public function __construct(
        private readonly DatabaseConnectionManagerContract $connections,
        private readonly TenantRegistryContract $registry,
        private readonly EncryptionPort $crypto,
        private readonly CachePort $cache,
        private readonly LoggerPort $logger = new NullLogger(),
        private readonly int $breakerThreshold = 5,
        private readonly int $breakerCooldown = 30,
        /**
         * Sliding window (seconds) over which consecutive failures must occur to
         * trip the breaker. Without it the failure counter never expires, so rare
         * blips spread over hours/days would eventually open the breaker on a
         * perfectly healthy tenant.
         */
        private readonly int $breakerWindow = 60,
        /** TTL (seconds) for the in-process decrypted-credential cache above. */
        private readonly int $credentialCacheTtl = 300,
        /** Cap on distinct warm tenant connections held open by one worker. */
        private readonly int $maxWarmConnections = 200,
    ) {}

    public function for(string $tenantId): DatabasePort
    {
        $name = 'tenant:' . $tenantId;

        // ALWAYS re-validate status, even when the connection is already warm in
        // this worker. The registry is cache-backed (short TTL), so this is cheap,
        // and it is what makes a suspension/deletion take effect on a long-lived
        // Swoole worker instead of lingering until the process restarts. A stale
        // warm handle to a now-unavailable tenant is dropped so it cannot be served.
        $tenant = $this->registry->find($tenantId)
            ?? $this->reject($name, UnknownTenantException::for($tenantId));


        try {
            $this->guardStatus($tenant);
        } catch (TenantUnavailableException $e) {
            $this->reject($name, $e);
        }

        $this->guardBreaker($tenantId);

        // Reuse a healthy warm connection; otherwise build it lazily.
        if (!$this->connections->has($name)) {
            $this->capWarmConnections();
            $this->connections->register($name, $this->configFor($tenant));
        }
        $this->touchWarm($name);

        return $this->connections->connection($name);
    }

    /**
     * Explicitly drop any open tenant connection and forget its cached registry
     * row, so a control-plane action (suspend/delete/credential rotation) takes
     * effect immediately rather than waiting out the registry TTL.
     */
    public function invalidate(string $tenantId): void
    {
        $this->registry->forget($tenantId);
        $this->connections->close('tenant:' . $tenantId);
        $this->forgetCredentialCache($tenantId);
        unset(self::$warmOrder['tenant:' . $tenantId]);
    }

    /** Close any stale warm handle, then throw the routing decision. */
    private function reject(string $connectionName, \Throwable $e): never
    {
        $this->connections->close($connectionName);
        unset(self::$warmOrder[$connectionName]);
        throw $e;
    }

    /**
     * Record a tenant DB failure (called by TenantContextStage when a query
     * against the tenant connection throws a connectivity error). Trips the
     * breaker once the threshold is reached and drops the cached adapter so the
     * next attempt rebuilds it.
     */
    public function recordFailure(string $tenantId, \Throwable $e): void
    {
        $key   = $this->failKey($tenantId);
        $count = $this->cache->increment($key);
        $this->connections->close('tenant:' . $tenantId);
        unset(self::$warmOrder['tenant:' . $tenantId]);
        $this->markDirty($tenantId);

        // Establish the sliding window on the FIRST failure of a window. Redis
        // INCR preserves an existing TTL, so subsequent increments keep counting
        // within the same window; the counter then expires if failures stop.
        if ($count <= 1) {
            $this->cache->set($key, $count, $this->breakerWindow);
        }

        if ($count >= $this->breakerThreshold) {
            $this->cache->set($this->breakerKey($tenantId), 1, $this->breakerCooldown);
            $this->rememberBreakerOpenLocally($tenantId);
            $this->logger->error('Tenant DB circuit breaker opened', [
                'tenant_id' => $tenantId,
                'failures'  => $count,
                'cooldown'  => $this->breakerCooldown,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear the failure counter after a healthy request.
     *
     * Only actually calls the cache backend when THIS worker has locally
     * witnessed a failure for this tenant since its last reset — most requests,
     * for most tenants, never do. Skipping the round-trip otherwise trades a
     * little cross-worker healing speed for one fewer network call per request:
     * a failure counter this worker never touched still self-expires via
     * $breakerWindow regardless (see recordFailure()), so the breaker's actual
     * safety property — trip after N failures within the window — is unchanged;
     * only how FAST an unrelated worker's stale streak gets reset early is.
     */
    public function recordSuccess(string $tenantId): void
    {
        unset(self::$breakerOpenL1[$tenantId]);

        if (!isset(self::$dirtyTenants[$tenantId])) {
            return;
        }

        unset(self::$dirtyTenants[$tenantId]);
        $this->cache->delete($this->failKey($tenantId));
    }

    private function guardStatus(Tenant $tenant): void
    {
        match ($tenant->status) {
            TenantStatus::Active       => null,
            TenantStatus::Suspended    => throw TenantUnavailableException::suspended($tenant->tenantId),
            TenantStatus::Deleted      => throw TenantUnavailableException::deleted($tenant->tenantId),
            TenantStatus::Provisioning => throw TenantUnavailableException::provisioning($tenant->tenantId),
        };
    }

    private function guardBreaker(string $tenantId): void
    {
        $expiresAt = self::$breakerOpenL1[$tenantId] ?? null;
        if ($expiresAt !== null && $expiresAt > time()) {
            // Known open, recently — skip the network round-trip entirely.
            throw TenantUnavailableException::breakerOpen($tenantId);
        }

        if ($this->cache->has($this->breakerKey($tenantId))) {
            $this->rememberBreakerOpenLocally($tenantId);
            throw TenantUnavailableException::breakerOpen($tenantId);
        }
    }

    /** Bounded, TTL'd local memo that the breaker is open — see $breakerOpenL1. */
    private function rememberBreakerOpenLocally(string $tenantId): void
    {
        if (count(self::$breakerOpenL1) >= self::MAX_BREAKER_L1_ENTRIES && !isset(self::$breakerOpenL1[$tenantId])) {
            array_shift(self::$breakerOpenL1);
        }
        self::$breakerOpenL1[$tenantId] = time() + self::BREAKER_L1_TTL;
    }

    /** Track that this worker has seen a connectivity failure for $tenantId — see recordSuccess(). */
    private function markDirty(string $tenantId): void
    {
        if (count(self::$dirtyTenants) >= self::MAX_DIRTY_TENANTS && !isset(self::$dirtyTenants[$tenantId])) {
            array_shift(self::$dirtyTenants);
        }
        self::$dirtyTenants[$tenantId] = true;
    }

    /** Mark a connection name as most-recently-used (moves it to the end). */
    private function touchWarm(string $connectionName): void
    {
        unset(self::$warmOrder[$connectionName]);
        self::$warmOrder[$connectionName] = true;
    }

    /**
     * Close and forget the least-recently-used warm connections until there is
     * room for one more, so a worker that has ever served more than
     * $maxWarmConnections distinct tenants does not hold that many open sockets
     * forever — only the ones actually in recent rotation.
     */
    private function capWarmConnections(): void
    {
        while (count(self::$warmOrder) >= $this->maxWarmConnections) {
            $oldest = array_key_first(self::$warmOrder);
            if ($oldest === null) {
                return;
            }
            unset(self::$warmOrder[$oldest]);
            $this->connections->close($oldest);
        }
    }

    private function configFor(Tenant $tenant): DatabaseConfigurationContract
    {
        // SQLite is file-based — no host/port/credentials to decrypt. The
        // registry stores the file path in db_name (storefront/domain mode).
        // Fail CLOSED when the file is absent: opening it would silently create
        // an empty database and serve empty data (e.g. a just-deleted tenant
        // whose row is still cached). A missing file means "not provisioned".
        if ($tenant->dbDriver === 'sqlite') {
            if ($tenant->dbName !== ':memory:' && !is_file($tenant->dbName)) {
                throw TenantUnavailableException::provisioning($tenant->tenantId);
            }

            return new SQLiteConfiguration($tenant->dbName);
        }

        $password = $this->decryptedPassword($tenant);

        return match ($tenant->dbDriver) {
            'pgsql' => new PostgreSQLConfiguration(
                host: $tenant->dbHost,
                port: $tenant->dbPort,
                database: $tenant->dbName,
                username: $tenant->dbUsername,
                password: $password,
            ),
            default => new MySQLConfiguration(
                host: $tenant->dbHost,
                port: $tenant->dbPort,
                database: $tenant->dbName,
                username: $tenant->dbUsername,
                password: $password,
            ),
        };
    }

    /**
     * Decrypt once per tenant per worker instead of once per request (see the
     * class-level docblock on $credentialCache for why this is `static` and why
     * it is not CachePort-backed).
     */
    private function decryptedPassword(Tenant $tenant): string
    {
        $key = $tenant->tenantId . ':' . hash('crc32b', $tenant->dbPasswordEnc);
        $now = time();

        $cached = self::$credentialCache[$key] ?? null;
        if ($cached !== null && $cached[1] > $now) {
            return $cached[0];
        }

        $password = $this->crypto->decryptString($tenant->dbPasswordEnc);

        if (count(self::$credentialCache) >= self::MAX_CACHED_CREDENTIALS) {
            array_shift(self::$credentialCache);
        }
        self::$credentialCache[$key] = [$password, $now + $this->credentialCacheTtl];

        return $password;
    }

    /** Drop every cached credential entry for this tenant, regardless of ciphertext. */
    private function forgetCredentialCache(string $tenantId): void
    {
        $prefix = $tenantId . ':';
        foreach (self::$credentialCache as $key => $_) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$credentialCache[$key]);
            }
        }
    }

    private function failKey(string $tenantId): string
    {
        return 'tenancy:fail:' . $tenantId;
    }

    private function breakerKey(string $tenantId): string
    {
        return 'tenancy:breaker:' . $tenantId;
    }
}
