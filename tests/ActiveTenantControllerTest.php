<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\API\Contracts\TenantSelectionPolicyContract;
use Plugins\Tenancy\Infrastructure\ActiveTenantStore;
use Plugins\Tenancy\Infrastructure\Http\Controllers\ActiveTenantController;

/**
 * The switch endpoint: what it records, and where it refuses to record anything.
 *
 * The audit properties matter because a policy may grant access with no seat —
 * a parent admin inside a child never appears in the child's member list, so
 * the trail is the only place the child can learn who came in.
 */
#[CoversClass(ActiveTenantController::class)]
final class ActiveTenantControllerTest extends TestCase
{
    private const string HOST  = 'tenant-parent';
    private const string CHILD = 'tenant-child';

    /** @var list<array{0: string, 1: string, 2: string, 3: array}> */
    private array $audited = [];

    /** @var array<string, mixed> */
    private array $session = [];

    private function controller(?string $grants, string $host = self::HOST, ?string $inside = null, array $body = []): ActiveTenantController
    {
        if ($inside !== null) {
            // What the store holds after an earlier switch into $inside.
            $this->session['tenancy.active_tenant.' . self::HOST] = json_encode(['t' => $inside, 'u' => 'user-1', 'h' => self::HOST]);
        }

        $policy = $this->createStub(TenantSelectionPolicyContract::class);
        $policy->method('roleFor')->willReturn($grants);

        $audit = $this->createStub(AuditServiceContract::class);
        $audit->method('record')->willReturnCallback(function (string $a, ?string $u, ?string $t, array $m): void {
            $this->audited[] = [$a, (string) $u, (string) $t, $m];
        });

        $controller = new ActiveTenantController($policy, new ActiveTenantStore(session: $this->sessionPort()), $audit);

        $request = Request::build(method: 'POST', path: '/ajx/tenant/active', body: $body)
            // Always the HOST's Identity: ActiveTenantStage leaves this endpoint
            // unswitched so its audit entries land in the host's trail.
            ->withIdentity(new Identity('user-1', self::HOST, ['admin'], [], 'session'));

        if ($host !== '') {
            $request = $request->withAttribute('tenant_host', $host);
        }

        return $controller->setRequest($request);
    }

    private function sessionPort(): SessionPort
    {
        $store = &$this->session;

        return new class ($store) implements SessionPort {
            public function __construct(private array &$data) {}
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

    public function test_entering_is_recorded_once_in_the_host_trail(): void
    {
        $response = $this->controller('observer', body: ['tenant' => self::CHILD])->activate();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([[
            'tenant.switch.enter', 'user-1', self::HOST,
            ['host_tenant' => self::HOST, 'tenant' => self::CHILD, 'role' => 'observer'],
        ]], $this->audited);
    }

    public function test_a_refused_switch_records_nothing_and_stores_nothing(): void
    {
        $response = $this->controller(null, body: ['tenant' => self::CHILD])->activate();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->audited);
        self::assertSame([], $this->session);
    }

    public function test_leaving_is_recorded_only_when_there_was_somewhere_to_leave(): void
    {
        $this->controller('observer', inside: self::CHILD)->clear();
        self::assertSame([[
            'tenant.switch.exit', 'user-1', self::HOST,
            ['host_tenant' => self::HOST, 'tenant' => self::CHILD],
        ]], $this->audited);
        self::assertArrayNotHasKey('tenancy.active_tenant.' . self::HOST, $this->session);

        $this->audited = [];
        $this->controller('observer')->clear();
        self::assertSame([], $this->audited);
    }

    public function test_a_selection_the_policy_no_longer_honours_is_not_reported_as_active_or_as_an_exit(): void
    {
        $response = $this->controller(null, inside: self::CHILD)->show();
        self::assertSame(self::HOST, json_decode((string) $response->getContent(), true)['data']['active']);

        $this->controller(null, inside: self::CHILD)->clear();
        self::assertSame([], $this->audited);
    }

    public function test_show_reports_the_selection_in_force(): void
    {
        $response = $this->controller('observer', inside: self::CHILD)->show();

        self::assertSame(self::CHILD, json_decode((string) $response->getContent(), true)['data']['active']);
    }

    public function test_choosing_the_host_itself_is_a_recorded_exit(): void
    {
        $this->controller('observer', inside: self::CHILD, body: ['tenant' => self::HOST])->activate();

        self::assertSame(['tenant.switch.exit'], array_column($this->audited, 0));
    }

    public function test_nothing_is_recorded_on_a_host_where_switching_is_not_offered(): void
    {
        // No `tenant_host` — ActiveTenantStage withholds it outside
        // TENANCY_SELECTION_HOSTS. The default policy ignores the host anchor,
        // so without this refusal a choice would be stored under ''.
        $response = $this->controller('owner', host: '', body: ['tenant' => self::CHILD])->activate();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->session);
        self::assertSame([], $this->audited);
    }
}
