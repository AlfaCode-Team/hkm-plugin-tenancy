<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Plugins\Tenancy\API\Contracts\MembershipServiceContract;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\Infrastructure\Http\Stages\TenantContextStage;
use Plugins\Tenancy\Infrastructure\Http\Identification\TenantIdentifier;

/**
 * Regression cover for S-05 (a stale cookie outranked the signed tenant claim,
 * routing a switched user back to the previous tenant's DATABASE) and S-06 (a
 * revoked seat kept tenant-DB access until token expiry).
 */
#[CoversClass(TenantContextStage::class)]
final class TenantContextStageSecurityTest extends TestCase
{
    /** Records which tenant the DatabasePort was resolved for. */
    private ?string $routedTo = null;

    private function resolver(): TenantConnectionResolverContract
    {
        $resolver = $this->createStub(TenantConnectionResolverContract::class);
        $resolver->method('for')->willReturnCallback(function (string $tenantId): DatabasePort {
            $this->routedTo = $tenantId;

            return $this->createStub(DatabasePort::class);
        });

        return $resolver;
    }

    private function identifier(string $returns): TenantIdentifier
    {
        $identifier = $this->createStub(TenantIdentifier::class);
        $identifier->method('identify')->willReturn($returns);

        return $identifier;
    }

    private function container(bool $isActiveMember = true): ModuleContainer
    {
        $container = new ModuleContainer(new CoreContainer());
        $container->setScope('tenancy.routing');

        $memberships = $this->createStub(MembershipServiceContract::class);
        $memberships->method('isActiveMember')->willReturn($isActiveMember);
        $container->instance(MembershipServiceContract::class, $memberships);

        return $container;
    }

    private function dispatch(Request $request, ModuleContainer $container, string $identifies = ''): Response
    {
        return (new TenantContextStage($this->resolver(), $this->identifier($identifies)))
            ->handle(
                $request->withContainer($container),
                static fn(Request $r): Response => Response::json(['ok' => true]),
            );
    }

    // ── S-05 ────────────────────────────────────────────────────────────────

    public function test_the_signed_tenant_claim_decides_the_database(): void
    {
        $request = Request::build(method: 'GET', path: '/ajx/x')
            ->withIdentity(Identity::asUser('user-1', 'tenant-b'));

        $response = $this->dispatch($request, $this->container());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('tenant-b', $this->routedTo, 'must route to the tenant the token asserts');
    }

    public function test_the_claim_outranks_the_host_identifier(): void
    {
        // A hint or host that disagrees with the signed claim must not win —
        // anything the server signed outranks anything the client supplies.
        $request = Request::build(method: 'GET', path: '/ajx/x')
            ->withIdentity(Identity::asUser('user-1', 'tenant-b'));

        $this->dispatch($request, $this->container(), identifies: 'tenant-a');

        self::assertSame('tenant-b', $this->routedTo);
    }

    public function test_the_identifier_still_resolves_a_guest(): void
    {
        // No identity, no claim — host-based resolution must keep working.
        $response = $this->dispatch(
            Request::build(method: 'GET', path: '/ajx/x'),
            $this->container(),
            identifies: 'tenant-a',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('tenant-a', $this->routedTo);
    }

    // ── S-06 ────────────────────────────────────────────────────────────────

    public function test_a_revoked_seat_is_refused_before_the_database_is_rebound(): void
    {
        $request = Request::build(method: 'GET', path: '/ajx/x')
            ->withIdentity(Identity::asUser('user-1', 'tenant-b'));

        $response = $this->dispatch($request, $this->container(isActiveMember: false));

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($this->routedTo, 'the tenant connection must never be resolved for a revoked seat');
    }

    public function test_membership_failure_fails_closed(): void
    {
        $container = new ModuleContainer(new CoreContainer());
        $container->setScope('tenancy.routing');

        $memberships = $this->createStub(MembershipServiceContract::class);
        $memberships->method('isActiveMember')->willThrowException(new \RuntimeException('db down'));
        $container->instance(MembershipServiceContract::class, $memberships);

        $request = Request::build(method: 'GET', path: '/ajx/x')
            ->withIdentity(Identity::asUser('user-1', 'tenant-b'));

        $response = $this->dispatch($request, $container);

        // If membership cannot be established, do not serve the tenant's data.
        self::assertSame(404, $response->getStatusCode());
        self::assertNull($this->routedTo);
    }

    // ── TENANCY_CENTRAL_DOMAINS ────────────────────────────────────────────
    //
    // isCentralDomain() memoises the parsed env value in a function-static
    // local (same pattern as isExempt(), justified in the class docblock),
    // so any test that sets TENANCY_CENTRAL_DOMAINS runs in its own process —
    // otherwise the FIRST test to touch this code path would freeze the
    // parsed value for every test after it in the same PHPUnit run.

    #[RunInSeparateProcess]
    public function test_a_central_domain_skips_resolution_and_is_served_centrally(): void
    {
        $_ENV['TENANCY_CENTRAL_DOMAINS'] = 'admin.example.com';

        $request = Request::build(method: 'GET', path: '/ajx/x')
            ->withAttribute('route_host', 'admin.example.com');

        $response = $this->dispatch($request, $this->container(), identifies: 'tenant-a');

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->routedTo, 'a central-domain request must never resolve a tenant connection');
    }

    #[RunInSeparateProcess]
    public function test_a_wildcard_central_domain_matches_subdomains(): void
    {
        $_ENV['TENANCY_CENTRAL_DOMAINS'] = '*.admin.example.com';

        $request = Request::build(method: 'GET', path: '/ajx/x')
            ->withAttribute('route_host', 'ops.admin.example.com');

        $response = $this->dispatch($request, $this->container(), identifies: 'tenant-a');

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->routedTo);
    }

    #[RunInSeparateProcess]
    public function test_a_host_not_listed_still_resolves_normally(): void
    {
        $_ENV['TENANCY_CENTRAL_DOMAINS'] = 'admin.example.com';

        $request = Request::build(method: 'GET', path: '/ajx/x')
            ->withAttribute('route_host', 'app.example.com')
            ->withIdentity(Identity::asUser('user-1', 'tenant-b'));

        $response = $this->dispatch($request, $this->container());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('tenant-b', $this->routedTo, 'a non-listed host must still resolve its tenant as usual');
    }
}
