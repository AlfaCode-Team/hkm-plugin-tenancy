<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Listeners;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use Plugins\Tenancy\Infrastructure\TenantSession;

/**
 * `auth.session.reset` — a signed-out visitor opened a sign-in or sign-up page
 * and Auth gave them a fresh session. The active-organisation cookie lives
 * outside the session, so it is cleared here.
 */
final class ResetTenantSelectionOnAuthPage implements EventListenerContract
{
    public function __construct(private readonly TenantSession $session)
    {
    }

    public function handle(IntegrationEventContract $event): void
    {
        $this->session->reset();
    }
}
