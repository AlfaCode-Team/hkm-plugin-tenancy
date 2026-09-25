<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\API\Contracts\TenantHostRegistryContract;
use Plugins\Tenancy\API\Contracts\TenantSelectionPolicyContract;
use Plugins\Tenancy\Infrastructure\ActiveTenantStore;
use Plugins\Tenancy\Infrastructure\Http\Stages\ActiveTenantStage;

/**
 * What must hold for a user's chosen tenant to be honoured.
 *
 * The stage exists because a selection cannot safely be applied at
 * TenantContextStage's priority 10 — no session, no Identity, so nothing to
 * bind the choice to and nothing to check it against. Every test here is a
 * property that only becomes checkable once the user IS known, which is the
 * whole reason for a second stage.
 *
 * `HOST` is the tenant owning the hostname; `CHILD` is one the user selected.
 */
#[CoversClass(ActiveTenantStage::class)]
#[CoversClass(ActiveTenantStore::class)]
final class ActiveTenantStageTest extends TestCase
{
    private const string HOST  = 'tenant-parent';
    private const string CHILD = 'tenant-child';

    /** Which tenant the DatabasePort was resolved for, or null if never. */
    private ?string $routedTo = null;

    /** An in-memory SessionPort — the store's primary home. */
    private function session(array $seed = []): SessionPort
    {
        return new class ($seed) implements SessionPort {
            public function __construct(private array $data) {}
            public function start(?string $id = null): void {}
            public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
            public function put(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function pull(string $key, mixed $default = null): mixed
            {
                $v = $this->data[$key] ?? $default;
                unset($this->data[$key]);
                return $v;
            }
            public function has(string $key): bool { return isset($this->data[$key]); }
            public function push(string $key, mixed $value): void { $this->data[$key][] = $value; }
            public function increment(string $key, int $by = 1): int
            {
                return $this->data[$key] = (int) ($this->data[$key] ?? 0) + $by;
            }
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

    /** A selection as the store writes it: tenant, the user it is for, the host anchor. */
    private function stored(string $tenant, string $user, string $host): array
    {
        return ['tenancy.active_tenant.' . self::HOST => json_encode(['t' => $tenant, 'u' => $user, 'h' => $host])];
    }

    private function container(SessionPort $session, ?string $policyGrants): ModuleContainer
    {
        $container = new ModuleContainer(new CoreContainer());
        $container->setScope('tenancy.routing');

        $hosts = $this->createStub(TenantHostRegistryContract::class);
        $hosts->method('tenantForHost')->willReturn(self::HOST);
        $container->instance(TenantHostRegistryContract::class, $hosts);

        $policy = $this->createStub(TenantSelectionPolicyContract::class);
        $policy->method('roleFor')->willReturn($policyGrants);
        $container->instance(TenantSelectionPolicyContract::class, $policy);

        $resolver = $this->createStub(TenantConnectionResolverContract::class);
        $resolver->method('for')->willReturnCallback(function (string $id): DatabasePort {
            $this->routedTo = $id;
            return $this->createStub(DatabasePort::class);
        });
        $container->instance(TenantConnectionResolverContract::class, $resolver);

        $container->instance(ActiveTenantStore::class, new ActiveTenantStore(session: $session));

        // What TenantContextStage leaves behind at priority 10: DatabasePort
        // already pointing at the tenant that owns the hostname. Every test here
        // runs downstream of that, so the fixture has to include it.
        $container->instance(DatabasePort::class, $this->createStub(DatabasePort::class));

        return $container;
    }

    /** @return array{0: Response, 1: Request} the response, and the request the stage passed on */
    private function dispatch(ModuleContainer $container, ?Identity $identity, string $handler = 'App\\EditionController@index'): array
    {
        $request = Request::build(method: 'GET', path: '/ajx/editions')
            ->withAttribute('route_host', 'app.example.test')
            ->withAttribute('route_entry', ['handler' => $handler])
            ->withContainer($container);

        if ($identity !== null) {
            $request = $request->withIdentity($identity);
        }

        $seen = null;
        $response = (new ActiveTenantStage())->handle($request, static function (Request $r) use (&$seen): Response {
            $seen = $r;
            return Response::json(['ok' => true]);
        });

        return [$response, $seen];
    }

    private function user(string $id = 'user-1'): Identity
    {
        return new Identity(
            userId: $id,
            tenantId: self::HOST,
            roles: ['member'],
            permissions: ['parent.permission'],
            tokenType: 'session',
        );
    }

    // ── the happy path ──────────────────────────────────────────────────────

    public function test_a_permitted_selection_routes_the_database_to_it(): void
    {
        $container = $this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), 'owner');

        [$response, $seen] = $this->dispatch($container, $this->user());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::CHILD, $this->routedTo, 'the selected tenant must decide the database');
        self::assertSame(self::CHILD, $seen->attribute('tenant'));
    }

    public function test_the_role_comes_from_the_policy_and_replaces_the_parent_role(): void
    {
        // A role is a statement about ONE tenant. Carrying 'member' (held on the
        // parent) into the child would let a seat in one place decide what
        // happens in another — and 'member' is exactly what would fail the
        // write guards the application applies inside the child.
        $container = $this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), 'observer');

        [, $seen] = $this->dispatch($container, $this->user());

        self::assertSame(['observer'], $seen->identity()->roles);
        self::assertSame(self::CHILD, $seen->identity()->tenantId);
        self::assertSame([], $seen->identity()->permissions, 'parent permissions must not leak into the child');
        self::assertSame('user-1', $seen->identity()->userId, 'the person does not change');
    }

    public function test_the_host_tenant_is_published_for_fleet_level_callers(): void
    {
        // Anything that must stay pointed at the parent — the switcher itself,
        // a list of children — needs an anchor the switch does not move.
        $container = $this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), 'owner');

        [, $seen] = $this->dispatch($container, $this->user());

        self::assertSame(self::HOST, $seen->attribute('tenant_host'));
    }

    public function test_the_sign_in_connection_stays_reachable_after_a_switch(): void
    {
        // Auth's credential tables — auth_sessions, refresh_tokens,
        // personal_access_tokens — exist in EVERY tenant database, because every
        // one is built from the same template. So a store that follows the switch
        // does not fail loudly; it finds an empty table, and a logout reports
        // success while revoking nothing. The stage publishes the connection the
        // person signed in on so that layer can decline to follow.
        $session   = $this->session($this->stored(self::CHILD, 'user-1', self::HOST));
        $container = $this->container($session, 'owner');

        $hostDb = $container->make(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort::class);

        $this->dispatch($container, $this->user());

        self::assertTrue($container->has('tenant.host.db'), 'the sign-in connection must stay reachable');
        self::assertSame(
            $hostDb,
            $container->make('tenant.host.db'),
            'it must be the SAME connection object, not a second one to the same tenant',
        );
        self::assertNotSame(
            $hostDb,
            $container->make(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort::class),
            'business data must still follow the switch',
        );
        self::assertSame(self::HOST, $container->make('tenant.host'));
    }

    // ── the properties that only a post-auth stage can enforce ──────────────

    public function test_a_selection_the_policy_refuses_is_ignored(): void
    {
        $container = $this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), null);

        [, $seen] = $this->dispatch($container, $this->user());

        self::assertNull($this->routedTo, 'a refused selection must not reach a database');
        self::assertSame(self::HOST, $seen->identity()->tenantId);
    }

    public function test_authority_withdrawn_takes_effect_on_the_very_next_request(): void
    {
        // The selection is re-checked every request, so revoking a seat does not
        // wait for a cookie or a token to expire. It also drops the stored
        // choice, so the user is not bounced off the same refusal forever.
        $session   = $this->session($this->stored(self::CHILD, 'user-1', self::HOST));
        $container = $this->container($session, null);

        $this->dispatch($container, $this->user());

        self::assertFalse(
            $session->has('tenancy.active_tenant.' . self::HOST),
            'a refused selection must be cleared, not left to be re-refused',
        );
    }

    public function test_a_selection_minted_for_another_user_is_never_replayed(): void
    {
        // The shared-browser case: someone switches into a tenant, signs out,
        // and the next person signs in. Without the principal stamp they would
        // inherit the previous person's database.
        $container = $this->container($this->session($this->stored(self::CHILD, 'someone-else', self::HOST)), 'owner');

        [, $seen] = $this->dispatch($container, $this->user('user-1'));

        self::assertNull($this->routedTo);
        self::assertSame(self::HOST, $seen->identity()->tenantId);
    }

    public function test_a_selection_made_under_another_brand_does_not_follow(): void
    {
        // The host tenant anchors whatever hierarchy rule the policy applies, so
        // replaying a choice across hosts would answer a question about a
        // different parent than the one it was approved for.
        $container = $this->container($this->session($this->stored(self::CHILD, 'user-1', 'some-other-parent')), 'owner');

        [, $seen] = $this->dispatch($container, $this->user());

        self::assertNull($this->routedTo);
        self::assertSame(self::HOST, $seen->identity()->tenantId);
    }

    public function test_a_guest_is_left_entirely_alone(): void
    {
        $container = $this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), 'owner');

        [$response, $seen] = $this->dispatch($container, null);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->routedTo, 'a selection belongs to a person; an anonymous one is no selection');
        self::assertNull($seen->identity());
    }

    public function test_no_selection_changes_nothing(): void
    {
        $container = $this->container($this->session(), 'owner');

        [, $seen] = $this->dispatch($container, $this->user());

        self::assertNull($this->routedTo);
        self::assertSame(self::HOST, $seen->identity()->tenantId);
    }

    public function test_selecting_the_host_tenant_itself_is_the_way_back(): void
    {
        $container = $this->container($this->session($this->stored(self::HOST, 'user-1', self::HOST)), 'owner');

        [, $seen] = $this->dispatch($container, $this->user());

        self::assertNull($this->routedTo, 'the host scope is already correct — no rebind needed');
        self::assertSame(self::HOST, $seen->identity()->tenantId);
    }

    // ── TENANCY_SELECTION_HOSTS ─────────────────────────────────────────────

    protected function tearDown(): void
    {
        unset($_ENV['TENANCY_SELECTION_HOSTS']);
    }

    public function test_a_selection_is_not_applied_on_a_host_outside_the_allow_list(): void
    {
        // The dispatch fixture browses app.example.test; only the organiser
        // console is allowed, so the stored choice must be inert here.
        $_ENV['TENANCY_SELECTION_HOSTS'] = 'organizer.*';
        $container = $this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), 'owner');

        [, $seen] = $this->dispatch($container, $this->user());

        self::assertNull($this->routedTo);
        self::assertSame(self::HOST, $seen->identity()->tenantId);
        // No anchor published either, which is what makes the controller
        // refuse to record a choice on this host.
        self::assertNull($seen->attribute('tenant_host'));
    }

    public function test_a_selection_is_applied_on_a_host_the_allow_list_names(): void
    {
        $_ENV['TENANCY_SELECTION_HOSTS'] = 'organizer.*, app.*';
        $container = $this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), 'owner');

        [, $seen] = $this->dispatch($container, $this->user());

        self::assertSame(self::CHILD, $this->routedTo);
        self::assertSame(self::CHILD, $seen->identity()->tenantId);
    }

    public function test_host_patterns_match_whole_hostnames_only(): void
    {
        $_ENV['TENANCY_SELECTION_HOSTS'] = 'app.*,*.brand.test,exact.test';

        self::assertTrue(ActiveTenantStage::hostAllowed('app.africavoting.local'));
        self::assertTrue(ActiveTenantStage::hostAllowed('organizer.brand.test'));
        self::assertTrue(ActiveTenantStage::hostAllowed('exact.test'));
        self::assertFalse(ActiveTenantStage::hostAllowed('africavoting.local'));
        self::assertFalse(ActiveTenantStage::hostAllowed('myapp.africavoting.local'));
        self::assertFalse(ActiveTenantStage::hostAllowed('brand.test'));
        self::assertFalse(ActiveTenantStage::hostAllowed('notexact.test'));
        self::assertFalse(ActiveTenantStage::hostAllowed(''));
    }

    public function test_an_unset_allow_list_keeps_every_host(): void
    {
        self::assertTrue(ActiveTenantStage::hostAllowed('anything.example'));
    }

    // ── the switch endpoint and the child-side audit record ─────────────────

    /** @var list<array{0: string, 1: string}> action, tenant */
    private array $visits = [];

    private function withAudit(ModuleContainer $container): ModuleContainer
    {
        $audit = $this->createStub(AuditServiceContract::class);
        $audit->method('record')->willReturnCallback(function (string $a, ?string $u, ?string $t): void {
            $this->visits[] = [$a, (string) $t];
        });
        $container->instance(AuditServiceContract::class, $audit);

        return $container;
    }

    public function test_the_switch_endpoint_itself_is_left_at_the_host_scope(): void
    {
        $container = $this->withAudit($this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), 'owner'));

        [, $seen] = $this->dispatch($container, $this->user(), 'Plugins\\Tenancy\\Infrastructure\\Http\\Controllers\\ActiveTenantController@activate');

        self::assertNull($this->routedTo);
        self::assertSame(self::HOST, $seen->identity()->tenantId);
        self::assertSame(self::HOST, $seen->attribute('tenant_host'));
        self::assertSame([], $this->visits);
    }

    public function test_the_first_request_inside_records_one_visit_in_the_entered_trail(): void
    {
        $session   = $this->session($this->stored(self::CHILD, 'user-1', self::HOST));
        $container = $this->withAudit($this->container($session, 'observer'));

        $this->dispatch($container, $this->user());
        $this->dispatch($container, $this->user());
        $this->dispatch($container, $this->user());

        self::assertSame([['tenant.switch.visit', self::CHILD]], $this->visits);
    }

    public function test_a_fresh_entry_is_recorded_again_even_into_the_same_tenant(): void
    {
        $session   = $this->session($this->stored(self::CHILD, 'user-1', self::HOST));
        $container = $this->withAudit($this->container($session, 'observer'));

        $this->dispatch($container, $this->user());
        (new ActiveTenantStore(session: $session))->write(self::CHILD, 'user-1', self::HOST);
        $this->dispatch($container, $this->user());

        self::assertCount(2, $this->visits);
    }

    public function test_a_refused_selection_records_no_visit(): void
    {
        $container = $this->withAudit($this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), null));

        $this->dispatch($container, $this->user());

        self::assertSame([], $this->visits);
    }

    public function test_an_unavailable_selection_is_named_rather_than_silently_dropped(): void
    {
        $container = $this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), 'owner');
        $resolver = $this->createStub(TenantConnectionResolverContract::class);
        $resolver->method('for')->willThrowException(new \Plugins\Tenancy\Domain\Exceptions\TenantUnavailableException(self::CHILD));
        $container->instance(TenantConnectionResolverContract::class, $resolver);

        [, $seen] = $this->dispatch($container, $this->user());

        self::assertSame(self::HOST, $seen->identity()->tenantId);
        self::assertSame(self::CHILD, $seen->attribute('tenant_unavailable'));
    }

    public function test_only_the_plugin_controller_itself_is_treated_as_the_switch_endpoint(): void
    {
        $container = $this->container($this->session($this->stored(self::CHILD, 'user-1', self::HOST)), 'owner');

        [, $seen] = $this->dispatch($container, $this->user(), 'App\\Http\\MyActiveTenantController@index');

        self::assertSame(self::CHILD, $seen->identity()->tenantId);
    }
}
