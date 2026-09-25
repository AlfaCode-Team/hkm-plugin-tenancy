<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Tests;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\TestCase;
use Plugins\Cookie\Infrastructure\CookieJar;
use Plugins\Tenancy\API\Contracts\MembershipServiceContract;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\Infrastructure\ActiveTenantStore;
use Plugins\Tenancy\Infrastructure\Http\Identification\TenantIdentifier;
use Plugins\Tenancy\Infrastructure\Http\Stages\TenantContextStage;
use Plugins\Tenancy\Infrastructure\Http\Stages\TenantHintStage;
use Plugins\Tenancy\Infrastructure\Listeners\ResetTenantSelectionOnAuthPage;
use Plugins\Tenancy\Infrastructure\Listeners\StartTenantSessionOnSignIn;
use Plugins\Tenancy\Infrastructure\TenantHint;
use Plugins\Tenancy\Infrastructure\TenantSession;

/**
 * The tenant hint names the user it was minted for, and a session changing
 * hands leaves no organisation behind.
 *
 * TenantContextStage runs at priority 10, before the session user is known, so
 * it used to stamp every session user's hint with `u: ''` — a binding that
 * matched everybody. The hint is now written at 23 by TenantHintStage, with the
 * real Identity, and on sign-in by the auth.session.started listener.
 */
final class TenantSessionTest extends TestCase
{
    private CookieJar $jar;

    protected function setUp(): void
    {
        $this->jar = new CookieJar();
    }

    // ── TenantHintStage ──────────────────────────────────────────────────────

    public function test_a_signed_in_user_gets_a_hint_minted_for_them(): void
    {
        $this->hintStage(
            Request::build('GET', '/', cookies: [TenantHint::COOKIE => self::hint('host', '')])
                ->withAttribute('tenant', 'host')
                ->withIdentity(Identity::asUser('user-1', '')),
        );

        self::assertSame(['t' => 'host', 'u' => 'user-1'], $this->queuedHint());
    }

    public function test_a_signed_out_browser_stops_carrying_the_previous_users_id(): void
    {
        $this->hintStage(
            Request::build('GET', '/', cookies: [TenantHint::COOKIE => self::hint('host', 'user-1')])
                ->withAttribute('tenant', 'host'),
        );

        self::assertSame(['t' => 'host', 'u' => ''], $this->queuedHint());
    }

    public function test_a_hint_that_already_matches_is_not_rewritten(): void
    {
        $this->hintStage(
            Request::build('GET', '/', cookies: [TenantHint::COOKIE => self::hint('host', 'user-1')])
                ->withAttribute('tenant', 'host')
                ->withIdentity(Identity::asUser('user-1', '')),
        );

        self::assertFalse($this->jar->hasQueued(TenantHint::COOKIE), 'an ordinary page view must carry no Set-Cookie for it');
    }

    public function test_a_request_with_no_tenant_writes_nothing(): void
    {
        $this->hintStage(Request::build('GET', '/ping')->withIdentity(Identity::asUser('user-1', '')));

        self::assertFalse($this->jar->hasQueued(TenantHint::COOKIE));
    }

    // ── TenantContextStage reads, never writes ───────────────────────────────

    public function test_the_stage_at_ten_no_longer_writes_the_hint(): void
    {
        $this->contextStage(Request::build('GET', '/'), identifies: 'host');

        self::assertFalse($this->jar->hasQueued(TenantHint::COOKIE), 'at 10 it cannot know whose hint it would be writing');
    }

    public function test_a_hint_naming_a_user_is_not_honoured_before_that_user_is_known(): void
    {
        // Priority 10: the session user is not attached yet, so this request is
        // anonymous here. A hint naming someone must not choose the database.
        $routedTo = $this->contextStage(
            Request::build('GET', '/', cookies: [TenantHint::COOKIE => self::hint('other-tenant', 'user-1')]),
            identifies: 'host',
        );

        self::assertSame('host', $routedTo);
    }

    public function test_a_guest_hint_still_serves_a_guest(): void
    {
        $routedTo = $this->contextStage(
            Request::build('GET', '/', cookies: [TenantHint::COOKIE => self::hint('remembered', '')]),
            identifies: 'host',
        );

        self::assertSame('remembered', $routedTo);
    }

    // ── ActiveTenantStore::reset / TenantSession ─────────────────────────────

    public function test_reset_forgets_every_brands_choice_and_the_cookie(): void
    {
        $session = self::session([
            'tenancy.active_tenant.brand-a'         => '{"t":"child-a","u":"user-1","h":"brand-a"}',
            'tenancy.active_tenant.brand-a.audited' => 'child-a',
            'tenancy.active_tenant.brand-b'         => '{"t":"child-b","u":"user-1","h":"brand-b"}',
            'unrelated'                             => 'kept',
        ]);

        (new ActiveTenantStore($session, $this->jar))->reset();

        self::assertSame(['unrelated' => 'kept'], $session->all());
        self::assertTrue($this->jar->hasQueued('hkm_tsel_v01'));
        self::assertSame('', $this->queuedValue('hkm_tsel_v01'), 'the selection cookie is expired, not rewritten');
    }

    public function test_starting_a_session_clears_the_choice_and_stamps_the_hint_with_the_host_tenant(): void
    {
        $session = self::session(['tenancy.active_tenant.host' => '{"t":"child","u":"user-1","h":"host"}']);

        $this->tenantSession($session, host: 'host')->start('user-1');

        self::assertSame([], $session->all());
        self::assertSame(['t' => 'host', 'u' => 'user-1'], $this->queuedHint());
    }

    // ── Wired through the Provider and the request's EventBus ────────────────

    public function test_the_provider_wires_both_auth_events_to_their_listeners(): void
    {
        $session   = self::session(['tenancy.active_tenant.host' => '{"t":"child","u":"user-1","h":"host"}']);
        $container = new ModuleContainer(new CoreContainer());
        $container->instance(SessionPort::class, $session);
        $container->instance(CookieJar::class, $this->jar);
        $container->bind('tenant.current', static fn (): string => 'host');

        (new \Plugins\Tenancy\Provider())->register($container);

        $bus = (new EventBus(new CoreContainer()))->forContainer($container);
        $bus->subscribe('auth.session.reset', ResetTenantSelectionOnAuthPage::class);
        $bus->subscribe('auth.session.started', StartTenantSessionOnSignIn::class);

        self::assertSame([], $bus->dispatch(self::event('auth.session.started', ['userId' => 'user-1', 'tenantId' => ''])));
        self::assertSame([], $session->all());
        self::assertSame(['t' => 'host', 'u' => 'user-1'], $this->queuedHint());

        $session->put('tenancy.active_tenant.host', 'stale');
        self::assertSame([], $bus->dispatch(self::event('auth.session.reset')));
        self::assertSame([], $session->all());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function hintStage(Request $request): void
    {
        $container = new ModuleContainer(new CoreContainer());
        $container->instance(CookieJar::class, $this->jar);

        (new TenantHintStage())->handle($request->withContainer($container), static fn (): Response => Response::empty());
    }

    private function contextStage(Request $request, string $identifies): string
    {
        $routedTo = '';
        $resolver = $this->createStub(TenantConnectionResolverContract::class);
        $resolver->method('for')->willReturnCallback(function (string $tenant) use (&$routedTo): DatabasePort {
            $routedTo = $tenant;

            return $this->createStub(DatabasePort::class);
        });
        $identifier = $this->createStub(TenantIdentifier::class);
        $identifier->method('identify')->willReturn($identifies);

        $container = new ModuleContainer(new CoreContainer());
        $container->instance(CookieJar::class, $this->jar);
        $container->instance(MembershipServiceContract::class, $this->createStub(MembershipServiceContract::class));

        (new TenantContextStage($resolver, $identifier))
            ->handle($request->withContainer($container), static fn (): Response => Response::empty());

        return $routedTo;
    }

    private function tenantSession(SessionPort $session, string $host): TenantSession
    {
        return new TenantSession(new ActiveTenantStore($session, $this->jar), new TenantHint($this->jar), static fn (): string => $host);
    }

    /** @return array{t: string, u: string}|null */
    private function queuedHint(): ?array
    {
        $value = $this->queuedValue(TenantHint::COOKIE);

        return $value === null ? null : json_decode($value, true);
    }

    private function queuedValue(string $name): ?string
    {
        foreach ($this->jar->applyTo(Response::empty())->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return (string) $cookie->getValue();
            }
        }

        return null;
    }

    private static function hint(string $tenant, string $user): string
    {
        return (string) json_encode(['t' => $tenant, 'u' => $user]);
    }

    /** @param array<string, string> $payload */
    private static function event(string $name, array $payload = []): IntegrationEventContract
    {
        return new class ($name, $payload) implements IntegrationEventContract {
            public function __construct(private string $name, private array $payload) {}
            public function name(): string { return $this->name; }
            public function version(): string { return '1.0'; }
            public function payload(): array { return $this->payload; }
        };
    }

    /** @param array<string, mixed> $data */
    private static function session(array $data = []): SessionPort
    {
        return new class ($data) implements SessionPort {
            public function __construct(private array $data) {}
            public function start(?string $id = null): void {}
            public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
            public function put(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function pull(string $key, mixed $default = null): mixed { $v = $this->data[$key] ?? $default; unset($this->data[$key]); return $v; }
            public function has(string $key): bool { return isset($this->data[$key]); }
            public function push(string $key, mixed $value): void { $this->data[$key][] = $value; }
            public function increment(string $key, int $by = 1): int { return $this->data[$key] = (int) ($this->data[$key] ?? 0) + $by; }
            public function forget(string $key): void { unset($this->data[$key]); }
            public function flush(): void { $this->data = []; }
            public function all(): array { return $this->data; }
            public function flash(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function id(): string { return 'test-session'; }
            public function reflash(): void {}
            public function token(): string { return 'test-token'; }
            public function regenerateToken(): void {}
            public function shouldPersist(): bool { return true; }
            public function regenerate(): void {}
            public function invalidate(): void { $this->data = []; }
            public function save(): void {}
        };
    }
}
