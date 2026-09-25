<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use Plugins\Tenancy\API\Contracts\TenantSelectionPolicyContract;
use Plugins\Tenancy\Application\Ports\MembershipReader;

/**
 * The default answer: you may act inside a tenant you hold an active seat in.
 *
 * This is the only rule the plugin can justify from its own data. `user_tenants`
 * records who belongs where and in what role; nothing else in the tenancy model
 * grants authority on its own. A hierarchy rule — "an admin of the parent may
 * enter a child" — is a product decision and belongs in whichever project wants
 * it, bound over this one. See
 * {@see \Plugins\Tenancy\API\Contracts\TenantSelectionPolicyContract}.
 *
 * ── WHY THE READER AND NOT MembershipServiceContract ───────────────────────
 * The service answers the same two questions, and reaching for it here cost a
 * 500 on every page of the first deployment to try this. It composes
 * `AuditServiceContract`, so resolving it drags `audit.trail` into the request
 * — and this policy is consulted by an ALWAYS-ON stage, on routes that never
 * asked for the audit domain and therefore do not have it bound.
 *
 * The reader is the right dependency on its own merits anyway: deciding whether
 * someone may look at a tenant is a read, and a read has nothing to write to an
 * audit trail. Recording that a switch HAPPENED is the endpoint's business, and
 * the endpoint can require what it needs.
 *
 * Semantics are kept identical to `MembershipService`: `activeMember()` is
 * `find()` plus `isRoutable()`, and `myTenants()` maps `activeForUser()` — so
 * binding either one gives the same answers.
 *
 * `$hostTenantId` is unused here on purpose, and its absence is the point: a
 * seat is a seat regardless of which hostname you arrived on. Only a rule that
 * derives authority from the hierarchy needs an anchor to measure against.
 */
final readonly class MembershipSelectionPolicy implements TenantSelectionPolicyContract
{
    public function __construct(
        private MembershipReader $memberships,
    ) {
    }

    public function roleFor(string $userId, string $tenantId, string $hostTenantId): ?string
    {
        if ($userId === '' || $tenantId === '') {
            return null;
        }

        $membership = $this->memberships->find($userId, $tenantId);

        // isRoutable() is what makes this a live check rather than a historical
        // one: it requires BOTH the seat and the tenant itself to be active, so
        // a suspended tenant stops being selectable without anyone editing
        // memberships.
        return $membership !== null && $membership->isRoutable() ? $membership->role : null;
    }

    public function selectable(string $userId, string $hostTenantId): array
    {
        if ($userId === '') {
            return [];
        }

        return array_map(
            static fn ($m): array => [
                'tenantId' => $m->tenantId,
                'name'     => $m->tenantName,
                'slug'     => $m->tenantSlug,
                'role'     => $m->role,
            ],
            $this->memberships->activeForUser($userId),
        );
    }
}
