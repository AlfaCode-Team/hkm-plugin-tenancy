<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\API\Contracts\TenantHostRegistryContract;
use Plugins\Tenancy\API\Contracts\TenantSelectionPolicyContract;
use Plugins\Tenancy\Domain\Exceptions\TenantUnavailableException;
use Plugins\Tenancy\Domain\Exceptions\UnknownTenantException;
use Plugins\Tenancy\Infrastructure\ActiveTenantStore;
use Throwable;

/**
 * Apply the signed-in user's chosen tenant, AFTER they are known to be signed in.
 *
 * ── WHY A SECOND STAGE EXISTS AT ALL ───────────────────────────────────────
 * {@see TenantContextStage} already resolves a tenant, and its resolution order
 * — signed claim, then cookie hint, then host — reads as though a user's choice
 * could win. On a cookie session it cannot, and the reason is ordering. Four
 * plugins register in `after.load`, where LOWER runs first:
 *
 *     10  Tenancy   TenantContextStage   picks the tenant, rebinds DatabasePort
 *     20  Session   StartSessionStage    opens the session
 *     22  Auth      SessionAuthStage     attaches the Identity
 *     24  Tenancy   ActiveTenantStage    ← this stage
 *
 * At 10 there is no session and no Identity, so `Identity.tenantId` is always
 * `''` and the host always decides. Worse, the membership re-verification in
 * that stage is guarded by `if ($userId !== '')`, so on a cookie-session
 * deployment it never executes.
 *
 * This stage runs where the Auth plugin always assumed tenant routing would
 * run: after the Identity exists, and before `RouteFilterStage` and
 * `ExecuteStage` — so the `auth` filter and every autowired repository see the
 * corrected scope. It only ever re-points an already-resolved request; it never
 * creates a scope where there was none, and it never widens one.
 *
 * ── IT TRUSTS THE STORE FOR NOTHING ────────────────────────────────────────
 * {@see ActiveTenantStore} answers "which tenant did this person pick". The
 * answer is then put to {@see TenantSelectionPolicyContract} on EVERY request,
 * against live authority. A revoked seat stops working on the next request, not
 * whenever a cookie happens to expire — which is the guarantee the stage at
 * priority 10 cannot make and this one can, because by here the user is known.
 *
 * ── THE HOST TENANT IS RESOLVED HERE, NOT INHERITED ────────────────────────
 * The policy needs the tenant that owns the HOSTNAME as its anchor, so that a
 * rule like "an admin of the parent may enter its children" is measured against
 * the brand being browsed rather than against a child the caller has already
 * switched into — which would let one hop become a chain. Reading it from the
 * `tenant` request attribute would be exactly that mistake once this stage has
 * overwritten it, so it is resolved independently from the registry and
 * published as `tenant_host` for anything downstream that must stay
 * fleet-level rather than follow the switch.
 */
final class ActiveTenantStage implements HttpStageContract
{
    /**
     * After Auth's SessionAuthStage (22). Deliberately 24 rather than 23: the
     * sibling docblocks in this plugin and in Auth both name 23 for
     * TenantContextStage, while the code registers 10. Sitting at 24 means this
     * stage runs after that one wherever it ends up, so correcting that number
     * later cannot silently invert the two.
     */
    public const int PRIORITY = 24;

    public function handle(Request $request, callable $next): Response
    {
        return $next($this->rescope($request) ?? $request);
    }

    /**
     * The request re-pointed at the selected tenant, or null to change nothing.
     *
     * Every branch that cannot answer returns null rather than failing. A
     * selection is a convenience layered on a scope the pipeline has already
     * established and already validated; when it cannot be applied, the right
     * outcome is that earlier scope, not an error page for a user who has done
     * nothing wrong.
     */
    private function rescope(Request $request): ?Request
    {
        if (!self::enabled()) {
            return null;
        }

        $identity = $request->identity();

        if ($identity === null || $identity->isGuest()) {
            return null;
        }

        $container = $request->container();

        if ($container === null || !$container->has(TenantSelectionPolicyContract::class)) {
            return null;
        }

        $hostTenant = $this->hostTenant($request, $container);

        if ($hostTenant === '') {
            return null;
        }

        $store    = $container->make(ActiveTenantStore::class);
        $selected = $store->read($request, $identity->userId, $hostTenant);

        // Nothing chosen, or chosen the host's own tenant: the scope the earlier
        // stage produced is already the right one.
        if ($selected === '' || $selected === $hostTenant) {
            return $request->withAttribute('tenant_host', $hostTenant);
        }

        $role = $container->make(TenantSelectionPolicyContract::class)
            ->roleFor($identity->userId, $selected, $hostTenant);

        if ($role === null) {
            // Authority ended, or never existed. Drop the choice so the user is
            // not bounced off the same refusal on every subsequent request.
            $store->clear($hostTenant);

            return $request->withAttribute('tenant_host', $hostTenant);
        }

        // Whatever DatabasePort currently points at IS the host's connection:
        // TenantContextStage resolved it from the hostname and nothing since has
        // touched it. Taken by reference rather than re-resolved so there is one
        // connection, not two, for the same tenant.
        $hostDb = $container->has(DatabasePort::class) ? $container->make(DatabasePort::class) : null;

        try {
            $db = $container->make(TenantConnectionResolverContract::class)->for($selected);
        } catch (UnknownTenantException | TenantUnavailableException) {
            // Permitted, but the database is gone, suspended or still building.
            // Keep the choice: suspension is reversible and clearing it would
            // make an operator re-pick once the tenant comes back.
            return $request->withAttribute('tenant_host', $hostTenant);
        }

        // ── the SIGN-IN tenant stays reachable ───────────────────────────────
        //
        // Published BEFORE the rebind below, because after it the host's
        // connection is no longer reachable through DatabasePort and nothing
        // else holds a reference to it.
        //
        // Not every store should follow a switch. Auth's credential tables —
        // `auth_sessions`, `refresh_tokens`, `personal_access_tokens` — are
        // tenant-scoped by design, and the tenant they belong to is the one the
        // person SIGNED IN to, not the one they are currently looking at. A
        // logout issued while viewing a child would otherwise try to revoke a
        // session row in the child's database, where it has never existed: the
        // table is there (every tenant gets it from the template) and empty, so
        // the revoke reports success and the session stays alive.
        //
        // Anything holding session or credential state binds this instead of
        // DatabasePort. Business data follows the switch; the right to be here
        // does not.
        if ($hostDb !== null) {
            $container->instance('tenant.host.db', $hostDb);
        }
        $container->bind('tenant.host', static fn (): string => $hostTenant);

        $container->instance(DatabasePort::class, $db);

        // A closure bind, not instance(): ModuleContainer::instance() requires
        // an object and TypeErrors on a bare string. Same shape, same reason, as
        // TenantContextStage.
        $container->bind('tenant.current', static fn (): string => $selected);

        $scoped = $this->narrow($identity, $selected, $role);
        $container->instance(Identity::class, $scoped);

        return $request
            ->withIdentity($scoped)
            ->withAttribute('tenant', $selected)
            ->withAttribute('tenant_host', $hostTenant);
    }

    /**
     * The Identity as it stands inside the selected tenant.
     *
     * Tenant id and role are REPLACED, not merged. A role is a statement about
     * one tenant; carrying a role earned elsewhere into this scope is how a seat
     * in one place quietly decides what happens in another. Permissions are
     * emptied for the same reason — they were derived against a different
     * tenant, and an empty list is the honest representation of "not yet
     * derived here". A policy that wants privileges inside the selection says so
     * through the role it returns.
     * 
     * The display fields describe the PERSON and are carried through unchanged.
     */
    private function narrow(Identity $identity, string $tenantId, string $role): Identity
    {
        return new Identity(
            userId:      $identity->userId,
            tenantId:    $tenantId,
            roles:       [$role],
            permissions: [],
            tokenType:   $identity->tokenType,
            username:    $identity->username,
            email:       $identity->email,
            fullName:    $identity->fullName,
            avatarUrl:   $identity->avatarUrl,
        );
    }

    /**
     * The tenant that owns the hostname being browsed.
     *
     * Uses the DomainResolver-validated `route_host` attribute in preference to
     * `Request::host()`. The raw Host header is caller-controlled and
     * `setTrustedHosts()` is configured nowhere, so measuring a hierarchy rule
     * against it would let the caller choose which parent they are judged
     * against. This mirrors the reasoning in TenantContextStage::isCentralDomain().
     */
    private function hostTenant(Request $request, $container): string
    {
        if (!$container->has(TenantHostRegistryContract::class)) {
            return '';
        }

        $host = strtolower(trim((string) ($request->attribute('route_host') ?? $request->host())));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $host = trim($host, '.');

        if ($host === '') {
            return '';
        }

        try {
            return $container->make(TenantHostRegistryContract::class)->tenantForHost($host) ?? '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * TENANCY_ACTIVE_SELECTION — on by default.
     *
     * The stage is inert without a stored selection, and nothing stores one
     * until a deployment calls the endpoint, so the default costs a bound-check
     * and a registry lookup that the request has already paid for once. An
     * operator who wants the switcher unreachable regardless of what the UI
     * offers turns it off here.
     */
    private static function enabled(): bool
    {
        return filter_var(env('TENANCY_ACTIVE_SELECTION', 'true'), FILTER_VALIDATE_BOOL);
    }
}
