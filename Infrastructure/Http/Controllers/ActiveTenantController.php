<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\API\Contracts\TenantSelectionPolicyContract;
use Plugins\Tenancy\Infrastructure\ActiveTenantStore;
use Project\Http\Controllers\ApiController;

/**
 * The tenant switcher, for browser sessions.
 *
 * ── HOW THIS DIFFERS FROM TenantController::select() ───────────────────────
 * That endpoint re-mints a JWT carrying a `tnt` claim, which is the right
 * answer for a token client and no answer at all for a cookie session: the
 * browser has nowhere to put the token, and the next page load arrives with the
 * same session it had before. This one records the choice server-side and lets
 * {@see \Plugins\Tenancy\Infrastructure\Http\Stages\ActiveTenantStage} apply it
 * on every subsequent request. The two are siblings, not replacements — a
 * deployment serving both models needs both.
 *
 * ── THE BODY NAMES A TENANT, AND THAT GRANTS NOTHING ───────────────────────
 * Accepting a tenant id from a request would normally be the shape of a hole.
 * It is not one, because the id is only ever MATCHED against what
 * {@see TenantSelectionPolicyContract} says this user may reach, and because
 * the stage re-asks that same question on every later request rather than
 * trusting what was stored. A forged body fails to match and changes nothing;
 * a selection that was legitimate this morning stops working the moment the
 * authority behind it is withdrawn.
 *
 * The user id always comes from the verified Identity, never the body.
 *
 * ── EVERY ENTRY AND EXIT IS AUDITED, ON BOTH SIDES ─────────────────────────
 * A policy may grant access WITHOUT a seat — a parent admin looking inside a
 * child — and such a person never appears in the child's member list. So each
 * switch leaves a record on both sides:
 *
 *   - HERE, `tenant.switch.enter` / `tenant.switch.exit` in the trail of the
 *     tenant that owns the hostname — the operator's side;
 *   - in ActiveTenantStage, `tenant.switch.visit` in the ENTERED tenant's own
 *     trail, on the first request actually served inside it.
 *
 * Two places because the Audit plugin writes through the request's
 * DatabasePort: this endpoint always runs at the host's scope (the stage does
 * not apply a selection to it), so only the host's trail is reachable from
 * here, and only a request already inside the child can reach the child's.
 * Best-effort, like every audit write: a trail that is down must not lock
 * people out of switching.
 */
final class ActiveTenantController extends ApiController
{
    public function __construct(
        private readonly TenantSelectionPolicyContract $policy,
        private readonly ActiveTenantStore $store,
        private readonly ?AuditServiceContract $audit = null,
    ) {
    }

    /**
     * GET /ajx/tenant/active — what am I inside, and where else could I go?
     *
     * Both halves in one response because the switcher needs both to render,
     * and two endpoints would let them disagree by a request.
     */
    public function show(): Response
    {
        $identity = $this->identity();

        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }

        $host = $this->hostTenant();

        return $this->ok([
            'host'       => $host,
            'active'     => $this->current($identity->userId, $host) ?: $host,
            'selectable' => $host === '' ? [] : $this->policy->selectable($identity->userId, $host),
        ]);
    }

    /**
     * POST /ajx/tenant/active { "tenant": "…" } — switch into one.
     *
     * 404 rather than 403 on a tenant that is not selectable: whether a tenant
     * exists is itself information, and a caller who may not enter it has no
     * business distinguishing "no such tenant" from "not yours".
     */
    public function activate(): Response
    {
        $identity = $this->identity();

        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }

        $target = trim((string) $this->resolveRequest()->input('tenant', ''));

        if ($target === '') {
            return $this->unprocessable(['tenant' => 'Choose a tenant.']);
        }

        $host = $this->hostTenant();

        if ($host === '') {
            return $this->unavailable();
        }

        // Selecting the host's own tenant is how you go back, not a no-op to
        // reject: an operator inside a child needs a way out that does not
        // require knowing the exit endpoint exists.
        if ($target === $host) {
            $this->exit($identity->userId, $host);

            return $this->ok(['active' => $host]);
        }

        $role = $this->policy->roleFor($identity->userId, $target, $host);

        if ($role === null) {
            return $this->notFound('No such tenant.');
        }

        $this->store->write($target, $identity->userId, $host);

        $this->record('tenant.switch.enter', $identity->userId, $host,
            ['host_tenant' => $host, 'tenant' => $target, 'role' => $role]);

        // The role is returned so a caller can render the switch immediately
        // without a round trip — it takes effect on the NEXT request, when the
        // stage re-derives it. This response is still scoped to the old tenant.
        return $this->ok(['active' => $target, 'role' => $role]);
    }

    /** DELETE /ajx/tenant/active — back to the tenant that owns this hostname. */
    public function clear(): Response
    {
        $identity = $this->identity();

        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }

        $host = $this->hostTenant();

        if ($host === '') {
            return $this->unavailable();
        }

        $this->exit($identity->userId, $host);

        return $this->ok(['active' => $host]);
    }

    /**
     * The selection currently in force, or '' — the stored choice, honoured
     * only if the policy still permits it, exactly as ActiveTenantStage would.
     * Read here rather than from the Identity because the stage deliberately
     * leaves this endpoint at the host's scope.
     */
    private function current(string $userId, string $host): string
    {
        if ($host === '') {
            return '';
        }

        $stored = $this->store->read($this->resolveRequest(), $userId, $host);

        return $stored !== '' && $stored !== $host
            && $this->policy->roleFor($userId, $stored, $host) !== null ? $stored : '';
    }

    /** Forget the choice, recording the exit only when there was somewhere to leave. */
    private function exit(string $userId, string $host): void
    {
        $was = $this->current($userId, $host);

        $this->store->clear($host);

        if ($was !== '') {
            $this->record('tenant.switch.exit', $userId, $host, ['host_tenant' => $host, 'tenant' => $was]);
        }
    }

    /** @param array<string, scalar|null> $meta */
    private function record(string $action, string $userId, string $tenantId, array $meta): void
    {
        try {
            $this->audit?->record($action, $userId, $tenantId, $meta);
        } catch (\Throwable) {
            // Best-effort by the Audit contract's own policy.
        }
    }

    /**
     * No host tenant: this hostname is outside TENANCY_SELECTION_HOSTS, or no
     * tenant owns it. Refused rather than recorded against an empty anchor —
     * a choice stored under '' would be replayed on every host that also
     * resolves to ''.
     */
    private function unavailable(): Response
    {
        return $this->notFound('Tenant switching is not available on this host.');
    }

    /**
     * The tenant that owns the hostname, as the stage resolved and published it.
     *
     * Read from the attribute rather than resolved again: the stage has already
     * done the registry lookup for this request, and a second one could disagree
     * with the anchor the scope was actually decided against.
     */
    private function hostTenant(): string
    {
        return (string) ($this->resolveRequest()->attribute('tenant_host') ?? '');
    }
}
