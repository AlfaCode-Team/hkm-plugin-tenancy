<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Cookie\Infrastructure\CookieJar;

/**
 * The `hkm_tnat_v01` cookie: which tenant this browser was last served, and for
 * WHOM — `{"t": tenantId, "u": userId}`, encrypted by the jar.
 *
 * A hint, never authority. TenantContextStage honours it only for the principal
 * it names, and everything it points at is re-resolved through the registry on
 * every request. The user id is what makes the binding mean something: a hint
 * minted for one account is never replayed onto another, or onto the guest the
 * same browser becomes after signing out.
 *
 * ── WHO WRITES IT ───────────────────────────────────────────────────────────
 * TenantContextStage READS it, at priority 10 — before the session is open, so
 * for a session user it cannot know whose request this is. It therefore never
 * writes: a write there would stamp every session user's hint with `u: ''`,
 * which matches everybody. TenantHintStage writes it at 23, once
 * SessionAuthStage (22) has attached the real Identity, and the sign-in
 * listener writes it at the start of a new session.
 */
final readonly class TenantHint
{
    public const string COOKIE = 'hkm_tnat_v01';

    /** Thirty days. Re-minted whenever the tenant or the principal changes. */
    public const int TTL = 60 * 60 * 24 * 30;

    public function __construct(private CookieJar $jar)
    {
    }

    /**
     * The hint as the browser sent it, or null when it is absent, tampered with
     * (the jar refuses to decrypt it) or malformed.
     *
     * @return array{t: string, u: string}|null
     */
    public function read(Request $request): ?array
    {
        $raw = $this->jar->read($request, self::COOKIE);
        if ($raw === null || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['t'] ?? null) || $data['t'] === '') {
            return null;
        }

        return ['t' => $data['t'], 'u' => is_string($data['u'] ?? null) ? $data['u'] : ''];
    }

    public function write(string $tenantId, string $userId): void
    {
        if ($tenantId === '') {
            return;
        }

        $payload = json_encode(['t' => $tenantId, 'u' => $userId]);
        if ($payload !== false) {
            $this->jar->queue(self::COOKIE, $payload, self::TTL);
        }
    }

    public function forget(): void
    {
        $this->jar->forget(self::COOKIE);
    }

    /** Already written (or forgotten) during this request. */
    public function queued(): bool
    {
        return $this->jar->hasQueued(self::COOKIE);
    }
}
