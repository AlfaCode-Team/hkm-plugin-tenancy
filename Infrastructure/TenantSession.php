<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure;

use Closure;

/**
 * Tenancy's per-browser state at the two moments a session changes hands.
 *
 * Driven by Auth's `auth.session.reset` and `auth.session.started` events (see
 * the listeners beside this class). Tenancy subscribes by event NAME and reads
 * primitives off the payload, so it needs no Auth class and Auth needs no
 * knowledge of these cookies.
 */
final readonly class TenantSession
{
    /**
     * @param Closure(): string $hostTenant the tenant the HOST resolved to — not a
     *                                      switched-into child — read lazily,
     *                                      because it is only bound once
     *                                      TenantContextStage has run
     */
    public function __construct(
        private ActiveTenantStore $selection,
        private ?TenantHint $hint,
        private Closure $hostTenant,
    ) {
    }

    /** A signed-out visitor opened a sign-in or sign-up page: drop the active organisation. */
    public function reset(): void
    {
        $this->selection->reset();
    }

    /**
     * A sign-in completed: start with no active organisation, and stamp the
     * tenant hint with the user who now owns this browser.
     */
    public function start(string $userId): void
    {
        $this->selection->reset();

        if ($userId !== '') {
            $this->hint?->write(($this->hostTenant)(), $userId);
        }
    }
}
