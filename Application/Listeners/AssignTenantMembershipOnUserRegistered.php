<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Listeners;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use Plugins\Tenancy\Application\Ports\MembershipWriter;

/**
 * Assigns a freshly-registered user to their originating tenant.
 *
 * Subscribed to `user.registered` and driven by the User outbox relay — so it
 * runs out-of-band (CoreContainer-resolved, no request context). The tenant
 * therefore travels ON the event payload (`tenantId`), set from the request's
 * resolved tenant at self-signup time. No tenant on the event (central/apex
 * signup) → nothing to assign.
 *
 * The seat's role is TENANCY_SIGNUP_ROLE (default `member`). Self-signup is
 * open to anyone, so a role that would hand a stranger the tenant — `owner`,
 * `admin` — is never granted this way, and neither is a value the
 * `user_tenants.role` column cannot hold; either falls back to `member`
 * (see signupRole()).
 *
 * Idempotent: upsertActive is a portable upsert keyed on (user_id, tenant_id),
 * so an at-least-once relay redelivery cannot create duplicate seats.
 */
final class AssignTenantMembershipOnUserRegistered implements EventListenerContract
{
    public const DEFAULT_ROLE = 'member';

    /** Roles self-signup never grants, whatever TENANCY_SIGNUP_ROLE says. */
    private const PRIVILEGED_ROLES = ['owner', 'admin'];

    private readonly string $role;

    public function __construct(
        private readonly MembershipWriter $memberships,
        ?string $role = null,
    ) {
        $this->role = self::signupRole($role);
    }

    public function handle(IntegrationEventContract $event): void
    {
        $payload  = $event->payload();
        $userId   = (string) ($payload['userId'] ?? '');
        $tenantId = (string) ($payload['tenantId'] ?? '');

        if ($userId === '' || $tenantId === '') {
            return; // no tenant context on this registration — nothing to assign
        }

        $this->memberships->upsertActive($userId, $tenantId, $this->role);
    }

    /**
     * The role a self-signup seat gets for a configured value: lower-cased,
     * a single token of at most 32 characters (`user_tenants.role` is
     * VARCHAR(32)), and not a privileged role. Anything else is `member` —
     * a bad setting must never grant MORE than the default.
     */
    public static function signupRole(?string $configured): string
    {
        $role = strtolower(trim((string) $configured));

        if ($role === ''
            || \in_array($role, self::PRIVILEGED_ROLES, true)
            || preg_match('/^[a-z][a-z0-9_.-]{0,31}$/', $role) !== 1) {
            return self::DEFAULT_ROLE;
        }

        return $role;
    }
}
