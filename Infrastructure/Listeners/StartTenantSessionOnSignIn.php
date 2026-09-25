<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Listeners;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use Plugins\Tenancy\Infrastructure\TenantSession;

/**
 * `auth.session.started` — the first request of a new signed-in session
 * (Auth's /auth/session/start). Clears any organisation chosen before, and
 * stamps the tenant hint with the user now signed in.
 */
final class StartTenantSessionOnSignIn implements EventListenerContract
{
    public function __construct(private readonly TenantSession $session)
    {
    }

    public function handle(IntegrationEventContract $event): void
    {
        $userId = $event->payload()['userId'] ?? '';

        $this->session->start(is_string($userId) ? $userId : '');
    }
}
