<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Cookie\Infrastructure\CookieJar;
use Throwable;

/**
 * Where "which tenant am I acting inside" is remembered between requests.
 *
 * ── THIS IS A CHOICE, NOT A CREDENTIAL ─────────────────────────────────────
 * Everything stored here is re-checked against
 * {@see \Plugins\Tenancy\API\Contracts\TenantSelectionPolicyContract} on every
 * single request before it is honoured. The value answers "which of the tenants
 * I am allowed did I pick", never "which tenant am I allowed". If the store
 * were to be forged wholesale, the worst outcome is a pick that the policy then
 * refuses.
 *
 * That distinction is the reason this uses its OWN keys and does not reuse
 * TenantContextStage's `hkm_tnat_v01`. That cookie is read at priority 10 —
 * before the session is opened (20) and before the Identity is attached (22) —
 * so at the moment it is read the user id is always `''`. Two things follow
 * there and neither is fixable from this class: its `u` binding compares `''`
 * to `''` and therefore matches everybody, and its membership re-verification
 * is wrapped in `if ($userId !== '')` and therefore never runs. Writing a
 * user's deliberate selection into that cookie would turn a hint that currently
 * only ever echoes the hostname into an unverified, unbound grant of another
 * tenant's database. A separate key keeps that stage's semantics exactly as
 * they are and keeps this path verified-only.
 *
 * ── SESSION FIRST, COOKIE ONLY AS A FALLBACK ───────────────────────────────
 * The session is the right home: it is server-side, already scoped to one
 * principal, and destroyed by logout — which means the single nastiest failure
 * mode of a cookie (the next person to sign in on a shared browser inherits the
 * previous person's tenant) cannot happen. The cookie exists for deployments
 * with no session at all, and it is stamped with the user it was minted for so
 * the same failure mode is closed there too, by comparison rather than by
 * lifetime.
 */
final readonly class ActiveTenantStore
{
    /** Session key. Namespaced because a session is shared with every other plugin. */
    private const string SESSION_KEY = 'tenancy.active_tenant';

    public function __construct(
        private ?SessionPort $session = null,
        private ?CookieJar $cookies = null,
        private string $cookieName = 'hkm_tsel_v01',
        private int $cookieTtl = 0,
    ) {
       
    }

    /**
     * The tenant this principal last chose, or '' when there is none.
     *
     * Returns '' rather than throwing on any storage fault. A selection that
     * cannot be read is indistinguishable from one that was never made, and
     * both should leave the request on the scope it already had.
     *
     * @param string $userId the VERIFIED user; a value minted for anyone else is ignored
     */
    public function read(Request $request, string $userId, string $hostTenantId): string
    {
        if ($userId === '') {
            // No principal, no selection. A selection belongs to a person; an
            // anonymous one is the unbound grant this class exists to avoid.
            return '';
        }
        // $container = $request->container();

        // if(!is_subclass_of($this->cookies, CookieJar::class)) {            
        //     $jar = $container->has(CookieJar::class) ? $container->make(CookieJar::class) : null;
        //     if($jar === null) {
        //         throw new \RuntimeException('Failed to resolve CookieJar dependency.');
        //     }
        //     $this->cookies = $jar;
        // }

        $raw = $this->fromSession($hostTenantId) ?: $this->fromCookie($request);

        if ($raw === '') {
            return '';
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return '';
        }

        // The stamp is checked even for the session copy. A session survives a
        // re-login in some drivers, and "who was this minted for" is one string
        // comparison against a value that is already in hand.
        $mintedFor  = is_string($data['u'] ?? null) ? $data['u'] : '';
        $mintedHost = is_string($data['h'] ?? null) ? $data['h'] : '';
        $tenantId   = is_string($data['t'] ?? null) ? $data['t'] : '';

        if ($mintedFor !== $userId || $tenantId === '') {
            return '';
        }

        // A choice made while browsing one brand must not follow the user to
        // another. The host tenant anchors the hierarchy rule a policy applies,
        // so replaying a selection across hosts would be asking the policy a
        // question about a different parent than the one it was answered for.
        if ($mintedHost !== '' && $mintedHost !== $hostTenantId) {
            return '';
        }

        return $tenantId;
    }

    /** Record the choice. The caller has already had it approved by the policy. */
    public function write(string $tenantId, string $userId, string $hostTenantId): void
    {
        if ($tenantId === '' || $userId === '') {
            return;
        }

        $payload = json_encode(['t' => $tenantId, 'u' => $userId, 'h' => $hostTenantId]);

        if ($payload === false) {
            return;
        }

        $this->put($hostTenantId, $payload);

        // A new choice is a new entry: the next request inside it must be
        // recorded, even if it is the same tenant as last time.
        $this->forgetAudited($hostTenantId);

        // Encrypted by the jar, and HttpOnly so no script can read which tenant
        // an operator is inside — a small leak, but a free one to close.
        $this->cookies?->queue($this->cookieName, $payload, $this->cookieTtl);
    }

    /** Forget the choice — the "exit" half of the switcher, and the logout hook. */
    public function clear(string $hostTenantId): void
    {
        $this->forgetAudited($hostTenantId);

        try {
            $this->session?->forget($this->key($hostTenantId));
        } catch (Throwable) {
            // A session that cannot be written is not a reason to fail a
            // request; the stamp check above still refuses a stale value.
        }

        $this->cookies?->forget($this->cookieName);
    }

    /**
     * Which tenant's entry has already been written to that tenant's own audit
     * trail during this selection — so ActiveTenantStage records a visit ONCE
     * per entry rather than on every request. Session-only: with no session
     * there is nowhere to remember it, and the stage then records nothing
     * rather than one row per request.
     */
    public function audited(string $hostTenantId): ?string
    {
        if ($this->session === null) {
            return null;
        }

        try {
            return (string) ($this->session->get($this->key($hostTenantId) . '.audited', '') ?? '');
        } catch (Throwable) {
            return null;
        }
    }

    public function markAudited(string $hostTenantId, string $tenantId): void
    {
        try {
            $this->session?->put($this->key($hostTenantId) . '.audited', $tenantId);
        } catch (Throwable) {
            // Worst case the next request records the visit again.
        }
    }

    private function forgetAudited(string $hostTenantId): void
    {
        try {
            $this->session?->forget($this->key($hostTenantId) . '.audited');
        } catch (Throwable) {
        }
    }

    /**
     * Keyed by host tenant so one browser can hold a different active
     * organisation per brand without the two overwriting each other.
     */
    private function key(string $hostTenantId): string
    {
        return self::SESSION_KEY . '.' . $hostTenantId;
    }

    private function fromSession(string $hostTenantId): string
    {
        try {
            return (string) ($this->session?->get($this->key($hostTenantId), '') ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    private function fromCookie(Request $request): string
    {
        try {
            return (string) ($this->cookies?->read($request, $this->cookieName) ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    private function put(string $hostTenantId, string $payload): void
    {
        try {
            $this->session?->put($this->key($hostTenantId), $payload);
        } catch (Throwable) {
            // Same reasoning as clear(): the cookie below still carries it.
        }
    }
}
