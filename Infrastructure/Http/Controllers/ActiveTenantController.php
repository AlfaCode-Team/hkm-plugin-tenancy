<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
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
 */
final class ActiveTenantController extends ApiController
{
    public function __construct(
        private readonly TenantSelectionPolicyContract $policy,
        private readonly ActiveTenantStore $store,
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
            'active'     => $identity->tenantId,
            'selectable' => $this->policy->selectable($identity->userId, $host),
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

        // Selecting the host's own tenant is how you go back, not a no-op to
        // reject: an operator inside a child needs a way out that does not
        // require knowing the exit endpoint exists.
        if ($target === $host) {
            $this->store->clear($host);

            return $this->ok(['active' => $host]);
        }

        $role = $this->policy->roleFor($identity->userId, $target, $host);

        if ($role === null) {
            return $this->notFound('No such tenant.');
        }

        $this->store->write($target, $identity->userId, $host);

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
        $this->store->clear($host);

        return $this->ok(['active' => $host]);
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
