<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use Plugins\Cookie\Infrastructure\CookieJar;
use Plugins\Tenancy\Infrastructure\TenantHint;

/**
 * Keeps the tenant hint cookie stamped with the principal it was minted for.
 *
 * TenantContextStage (10) resolves the tenant before the session is open, so it
 * cannot tell a session user from a guest and must not write the hint — see
 * {@see TenantHint}. This runs at 23, after SessionAuthStage (22), where the
 * request's Identity is the real one, and writes `{t: the tenant stage 10
 * resolved, u: that Identity}` whenever the cookie says anything else:
 *
 *   - a signed-in user whose hint still says `u: ''` (or another user) gets
 *     one minted for them;
 *   - a browser that signed out gets its hint re-minted for the guest, so the
 *     previous user's id stops travelling with every request.
 *
 * Written only on CHANGE rather than on every response, so an ordinary page
 * view carries no Set-Cookie for it.
 *
 * Before ActiveTenantStage (24) on purpose: the hint records the tenant the
 * HOST resolved to. A switched-into child is a separate, policy-checked choice
 * that ActiveTenantStore remembers on its own.
 */
final class TenantHintStage implements HttpStageContract
{
    public const int PRIORITY = 23;

    public function handle(Request $request, callable $next): Response
    {
        $tenantId  = (string) ($request->attribute('tenant') ?? '');
        $container = $request->container();

        if ($tenantId !== '' && $container !== null && $container->has(CookieJar::class)) {
            $hint = new TenantHint($container->make(CookieJar::class));

            if (!$hint->queued()) {
                $userId  = $request->identity()?->userId ?? '';
                $current = $hint->read($request);

                if ($current === null || $current['t'] !== $tenantId || $current['u'] !== $userId) {
                    $hint->write($tenantId, $userId);
                }
            }
        }

        return $next($request);
    }
}
