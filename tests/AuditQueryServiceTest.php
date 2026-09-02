<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Tenancy\Application\Ports\AuditReader;
use Plugins\Tenancy\Application\Services\AuditQueryService;
use Plugins\Tenancy\Domain\Entities\AuditEntry;

#[CoversClass(AuditQueryService::class)]
final class AuditQueryServiceTest extends TestCase
{
    private function admin(): Identity
    {
        return new Identity('admin-1', '', ['platform-admin'], ['tenancy:admin'], 'jwt');
    }

    private function member(): Identity
    {
        return new Identity('user-1', 'tenant-1', ['member'], [], 'jwt');
    }

    private function entry(string $action, ?string $tenantId = null, ?string $userId = null): AuditEntry
    {
        return AuditEntry::fromRow([
            'id' => 1, 'event_id' => 'evt-1', 'user_id' => $userId, 'tenant_id' => $tenantId,
            'action' => $action, 'ip' => null, 'meta' => null, 'occurred_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_guest_cannot_query(): void
    {
        $svc = new AuditQueryService(new FakeAuditReader(), Identity::guest());

        $this->expectException(SecurityException::class);
        $svc->query();
    }

    public function test_member_cannot_query(): void
    {
        $svc = new AuditQueryService(new FakeAuditReader(), $this->member());

        $this->expectException(SecurityException::class);
        $svc->query();
    }

    public function test_admin_query_with_no_filter_hits_recent(): void
    {
        $reader = new FakeAuditReader();
        $reader->recentEntries = [$this->entry('tenant.created')];
        $svc = new AuditQueryService($reader, $this->admin());

        $result = $svc->query();

        $this->assertSame(['tenant.created'], array_map(static fn (AuditEntry $e) => $e->action, $result));
        $this->assertTrue($reader->calledRecent);
        $this->assertFalse($reader->calledForTenant);
    }

    public function test_admin_query_with_tenant_id_wins_over_other_filters(): void
    {
        $reader = new FakeAuditReader();
        $reader->tenantEntries = [$this->entry('tenant.suspended', tenantId: 't1')];
        $svc = new AuditQueryService($reader, $this->admin());

        $result = $svc->query(tenantId: 't1', userId: 'u1', action: 'x');

        $this->assertSame(['tenant.suspended'], array_map(static fn (AuditEntry $e) => $e->action, $result));
        $this->assertTrue($reader->calledForTenant);
        $this->assertFalse($reader->calledForUser);
        $this->assertFalse($reader->calledByAction);
    }

    public function test_admin_can_count_for_tenant(): void
    {
        $reader = new FakeAuditReader();
        $reader->count = 7;
        $svc = new AuditQueryService($reader, $this->admin());

        $this->assertSame(7, $svc->countForTenant('t1'));
    }
}

final class FakeAuditReader implements AuditReader
{
    /** @var list<AuditEntry> */
    public array $recentEntries = [];
    /** @var list<AuditEntry> */
    public array $tenantEntries = [];
    /** @var list<AuditEntry> */
    public array $userEntries = [];
    /** @var list<AuditEntry> */
    public array $actionEntries = [];
    public int $count = 0;

    public bool $calledRecent = false;
    public bool $calledForTenant = false;
    public bool $calledForUser = false;
    public bool $calledByAction = false;

    public function recent(int $limit = 50, ?int $beforeId = null): array
    {
        $this->calledRecent = true;
        return $this->recentEntries;
    }

    public function forTenant(string $tenantId, int $limit = 50, ?int $beforeId = null): array
    {
        $this->calledForTenant = true;
        return $this->tenantEntries;
    }

    public function forUser(string $userId, int $limit = 50, ?int $beforeId = null): array
    {
        $this->calledForUser = true;
        return $this->userEntries;
    }

    public function byAction(string $action, int $limit = 50, ?int $beforeId = null): array
    {
        $this->calledByAction = true;
        return $this->actionEntries;
    }

    public function find(string $eventId): ?AuditEntry
    {
        return null;
    }

    public function countForTenant(string $tenantId): int
    {
        return $this->count;
    }

    public function purgeOlderThan(\DateTimeImmutable $cutoff): int
    {
        return 0;
    }
}
