<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\TestCase;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\Application\Ports\InvitationStore;
use Plugins\Tenancy\Application\Ports\MembershipWriter;
use Plugins\Tenancy\Application\Services\InvitationService;
use Plugins\Tenancy\Domain\Entities\Invitation;
use Plugins\Tenancy\Domain\Exceptions\InvalidInvitationException;
use Plugins\Tenancy\Domain\ValueObjects\InvitationStatus;

final class InvitationServiceTest extends TestCase
{
    private function owner(string $tenantId = 't1'): Identity
    {
        return new Identity('owner-1', $tenantId, ['owner'], [], 'jwt');
    }

    private function member(string $tenantId = 't1'): Identity
    {
        return new Identity('user-1', $tenantId, ['member'], [], 'jwt');
    }

    private function guest(): Identity
    {
        return Identity::guest();
    }

    private function store(): InvitationStore
    {
        return new class implements InvitationStore {
            /** @var array<string, array<string, mixed>> keyed by token hash */
            public array $rows = [];
            public function create(string $inviteId, string $tenantId, string $email, string $role, string $tokenHash, string $invitedBy, \DateTimeImmutable $expiresAt): void
            {
                $this->rows[$tokenHash] = [
                    'invite_id' => $inviteId, 'tenant_id' => $tenantId, 'email' => $email,
                    'role' => $role, 'status' => InvitationStatus::Pending->value,
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s'), 'invited_by' => $invitedBy,
                ];
            }
            public function findByTokenHash(string $tokenHash): ?Invitation
            {
                return isset($this->rows[$tokenHash]) ? Invitation::fromRow($this->rows[$tokenHash]) : null;
            }
            public function pendingExists(string $tenantId, string $email): bool
            {
                foreach ($this->rows as $r) {
                    if ($r['tenant_id'] === $tenantId && $r['email'] === $email && $r['status'] === InvitationStatus::Pending->value) {
                        return true;
                    }
                }
                return false;
            }
            public function markAccepted(string $inviteId): void { $this->setStatus($inviteId, InvitationStatus::Accepted); }
            public function markRevoked(string $inviteId): void { $this->setStatus($inviteId, InvitationStatus::Revoked); }
            public function findById(string $inviteId): ?Invitation
            {
                foreach ($this->rows as $r) {
                    if ($r['invite_id'] === $inviteId) {
                        return Invitation::fromRow($r);
                    }
                }
                return null;
            }
            public function listForTenant(string $tenantId): array
            {
                $out = [];
                foreach ($this->rows as $r) {
                    if ($r['tenant_id'] === $tenantId) {
                        $out[] = Invitation::fromRow($r);
                    }
                }
                return $out;
            }
            public function rotateToken(string $inviteId, string $newTokenHash, \DateTimeImmutable $newExpiresAt): void
            {
                foreach ($this->rows as $h => $r) {
                    if ($r['invite_id'] === $inviteId) {
                        unset($this->rows[$h]);
                        $r['status'] = InvitationStatus::Pending->value;
                        $r['expires_at'] = $newExpiresAt->format('Y-m-d H:i:s');
                        $this->rows[$newTokenHash] = $r;
                        return;
                    }
                }
            }
            private function setStatus(string $inviteId, InvitationStatus $s): void
            {
                foreach ($this->rows as $h => $r) {
                    if ($r['invite_id'] === $inviteId) { $this->rows[$h]['status'] = $s->value; }
                }
            }
        };
    }

    private function writer(): MembershipWriter
    {
        return new class implements MembershipWriter {
            /** @var list<array{0:string,1:string,2:string}> */
            public array $added = [];
            public function upsertActive(string $userId, string $tenantId, string $role): void
            {
                $this->added[] = [$userId, $tenantId, $role];
            }
        };
    }

    private function audit(): AuditServiceContract
    {
        return new class implements AuditServiceContract {
            /** @var list<string> */
            public array $actions = [];
            public function record(string $action, ?string $userId = null, ?string $tenantId = null, array $meta = [], ?string $ip = null): void
            {
                $this->actions[] = $action;
            }
        };
    }

    public function test_invite_returns_token_and_blocks_duplicates(): void
    {
        $store = $this->store();
        $svc = new InvitationService($store, $this->writer(), $this->audit(), $this->owner());

        $res = $svc->invite('t1', 'Alice@Example.com', 'admin', 'inviter-1');

        $this->assertNotSame('', $res->token);
        $this->assertSame('alice@example.com', $res->email); // normalised
        $this->assertSame('admin', $res->role);

        $this->expectException(ValidationException::class);
        $svc->invite('t1', 'alice@example.com', 'member', 'inviter-1');
    }

    public function test_accept_creates_membership_when_email_matches(): void
    {
        $store = $this->store();
        $writer = $this->writer();
        $audit = $this->audit();
        $svc = new InvitationService($store, $writer, $audit, $this->owner());

        $res = $svc->invite('t1', 'bob@example.com', 'member', 'inviter-1');

        $tenant = $svc->accept($res->token, 'user-bob', 'BOB@example.com');

        $this->assertSame('t1', $tenant);
        $this->assertSame([['user-bob', 't1', 'member']], $writer->added);
        $this->assertContains('member.join', $audit->actions);
    }

    public function test_accept_rejects_email_mismatch(): void
    {
        $store = $this->store();
        $svc = new InvitationService($store, $this->writer(), $this->audit(), $this->owner());
        $res = $svc->invite('t1', 'carol@example.com', 'member', 'inviter-1');

        $this->expectException(InvalidInvitationException::class);
        $svc->accept($res->token, 'user-x', 'mallory@example.com');
    }

    public function test_accept_rejects_unknown_token(): void
    {
        $svc = new InvitationService($this->store(), $this->writer(), $this->audit(), $this->owner());

        $this->expectException(InvalidInvitationException::class);
        $svc->accept('deadbeef', 'user-x', 'x@example.com');
    }

    public function test_accept_rejects_expired_invitation(): void
    {
        $store = $this->store();
        $svc = new InvitationService($store, $writer = $this->writer(), $this->audit(), $this->owner());

        // Seed an already-expired pending invite directly.
        $raw = 'rawtoken123';
        $store->create('inv-exp', 't1', 'dan@example.com', 'member', hash('sha256', $raw), 'inviter-1',
            (new \DateTimeImmutable())->modify('-1 hour'));

        try {
            $svc->accept($raw, 'user-dan', 'dan@example.com');
            $this->fail('Expected InvalidInvitationException');
        } catch (InvalidInvitationException) {
            // expected
        }
        $this->assertSame([], $writer->added);
    }

    // ── list / resend / revokeById / bulk ────────────────────────────────────

    public function test_list_for_tenant_only_returns_that_tenants_invites(): void
    {
        $store = $this->store();
        $writer = $this->writer();
        $audit = $this->audit();
        $svcT1 = new InvitationService($store, $writer, $audit, $this->owner('t1'));
        $svcT2 = new InvitationService($store, $writer, $audit, $this->owner('t2'));
        $svcT1->invite('t1', 'a@example.com', 'member', 'inviter-1');
        $svcT2->invite('t2', 'b@example.com', 'member', 'inviter-1');

        $list = $svcT1->listForTenant('t1');

        $this->assertCount(1, $list);
        $this->assertSame('a@example.com', $list[0]->email);
    }

    public function test_resend_issues_a_new_token_and_invalidates_the_old_one(): void
    {
        $store = $this->store();
        $svc = new InvitationService($store, $this->writer(), $this->audit(), $this->owner());
        $first = $svc->invite('t1', 'a@example.com', 'member', 'inviter-1');

        $second = $svc->resend('t1', $first->inviteId);

        $this->assertNotSame($first->token, $second->token);
        $this->assertSame($first->inviteId, $second->inviteId);

        $this->expectException(InvalidInvitationException::class);
        $svc->accept($first->token, 'user-x', 'a@example.com');
    }

    /**
     * An invite created for t1 must not be resendable by a caller who is
     * properly authorized to manage a DIFFERENT tenant (t2-not-owner) — the
     * IDOR guard in requireOwnedByTenant() still fires even once the caller
     * has legitimately passed the identity/tenant-scope gate.
     */
    public function test_resend_rejects_an_invite_from_another_tenant(): void
    {
        $store = $this->store();
        $writer = $this->writer();
        $audit = $this->audit();
        $svcT1 = new InvitationService($store, $writer, $audit, $this->owner('t1'));
        $invite = $svcT1->invite('t1', 'a@example.com', 'member', 'inviter-1');

        $svcOther = new InvitationService($store, $writer, $audit, $this->owner('t2-not-owner'));

        $this->expectException(ServiceException::class);
        $svcOther->resend('t2-not-owner', $invite->inviteId);
    }

    public function test_revoke_by_id_makes_the_token_unacceptable(): void
    {
        $store = $this->store();
        $svc = new InvitationService($store, $this->writer(), $this->audit(), $this->owner());
        $invite = $svc->invite('t1', 'a@example.com', 'member', 'inviter-1');

        $svc->revokeById('t1', $invite->inviteId);

        $this->expectException(InvalidInvitationException::class);
        $svc->accept($invite->token, 'user-x', 'a@example.com');
    }

    /** Same IDOR guard as resend(), see test_resend_rejects_an_invite_from_another_tenant(). */
    public function test_revoke_by_id_rejects_an_invite_from_another_tenant(): void
    {
        $store = $this->store();
        $writer = $this->writer();
        $audit = $this->audit();
        $svcT1 = new InvitationService($store, $writer, $audit, $this->owner('t1'));
        $invite = $svcT1->invite('t1', 'a@example.com', 'member', 'inviter-1');

        $svcOther = new InvitationService($store, $writer, $audit, $this->owner('not-t1'));

        $this->expectException(ServiceException::class);
        $svcOther->revokeById('not-t1', $invite->inviteId);
    }

    public function test_bulk_invite_isolates_one_bad_entry_from_the_rest(): void
    {
        $store = $this->store();
        $svc = new InvitationService($store, $this->writer(), $this->audit(), $this->owner());
        $svc->invite('t1', 'dup@example.com', 'member', 'inviter-1'); // pre-existing pending invite

        $results = $svc->inviteBulk('t1', [
            ['email' => 'new@example.com', 'role' => 'member'],
            ['email' => 'dup@example.com', 'role' => 'member'],   // duplicate -> fails
            ['email' => 'not-an-email', 'role' => 'member'],       // invalid -> fails
        ], 'inviter-1');

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]['ok']);
        $this->assertNotNull($results[0]['token']);
        $this->assertFalse($results[1]['ok']);
        $this->assertFalse($results[2]['ok']);
    }

    // ── authorization gate — requireInviteManager() ──────────────────────────

    public function test_guest_cannot_invite(): void
    {
        $svc = new InvitationService($this->store(), $this->writer(), $this->audit(), $this->guest());

        $this->expectException(SecurityException::class);
        $svc->invite('t1', 'a@example.com', 'member', 'inviter-1');
    }

    public function test_member_without_owner_or_admin_role_cannot_invite(): void
    {
        $svc = new InvitationService($this->store(), $this->writer(), $this->audit(), $this->member());

        $this->expectException(SecurityException::class);
        $svc->invite('t1', 'a@example.com', 'member', 'inviter-1');
    }

    public function test_identity_scoped_to_a_different_tenant_cannot_invite(): void
    {
        $svc = new InvitationService($this->store(), $this->writer(), $this->audit(), $this->owner('t2'));

        $this->expectException(SecurityException::class);
        $svc->invite('t1', 'a@example.com', 'member', 'inviter-1');
    }

    public function test_member_with_the_explicit_permission_can_invite(): void
    {
        $identity = new Identity('user-9', 't1', ['member'], ['tenant.members.manage'], 'jwt');
        $svc = new InvitationService($this->store(), $this->writer(), $this->audit(), $identity);

        $res = $svc->invite('t1', 'a@example.com', 'member', 'inviter-9');

        $this->assertNotSame('', $res->token);
    }

    public function test_member_cannot_list_for_tenant(): void
    {
        $svc = new InvitationService($this->store(), $this->writer(), $this->audit(), $this->member());

        $this->expectException(SecurityException::class);
        $svc->listForTenant('t1');
    }

    public function test_member_cannot_resend(): void
    {
        $store = $this->store();
        $writer = $this->writer();
        $audit = $this->audit();
        $owner = new InvitationService($store, $writer, $audit, $this->owner());
        $invite = $owner->invite('t1', 'a@example.com', 'member', 'inviter-1');

        $svc = new InvitationService($store, $writer, $audit, $this->member());

        $this->expectException(SecurityException::class);
        $svc->resend('t1', $invite->inviteId);
    }

    public function test_member_cannot_revoke_by_id(): void
    {
        $store = $this->store();
        $writer = $this->writer();
        $audit = $this->audit();
        $owner = new InvitationService($store, $writer, $audit, $this->owner());
        $invite = $owner->invite('t1', 'a@example.com', 'member', 'inviter-1');

        $svc = new InvitationService($store, $writer, $audit, $this->member());

        $this->expectException(SecurityException::class);
        $svc->revokeById('t1', $invite->inviteId);
    }

    public function test_member_cannot_bulk_invite(): void
    {
        $svc = new InvitationService($this->store(), $this->writer(), $this->audit(), $this->member());

        $this->expectException(SecurityException::class);
        $svc->inviteBulk('t1', [['email' => 'a@example.com', 'role' => 'member']], 'inviter-1');
    }
}
