<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Tenancy\API\Contracts\AuditQueryServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * AuditLogController — admin-facing read access to the central audit trail.
 *
 * Authorization is enforced in {@see \Plugins\Tenancy\Application\Services\AuditQueryService},
 * not here — this controller is translation only, per the plugin's Controller rule.
 */
final class AuditLogController extends ApiController
{
    public function __construct(
        private readonly AuditQueryServiceContract $audit,
    ) {}

    /**
     * GET /ajx/admin/audit-log — newest first, optionally filtered by exactly
     * one of tenant_id / user_id / action. Query params: limit (default 50),
     * before_id (keyset cursor — pass the last id seen for the next page).
     */
    public function index(): Response
    {
        $request = $this->resolveRequest();

        $entries = $this->audit->query(
            tenantId: $request->filled('tenant_id') ? $request->string('tenant_id') : null,
            userId: $request->filled('user_id') ? $request->string('user_id') : null,
            action: $request->filled('action') ? $request->string('action') : null,
            limit: $request->integer('limit') ?: 50,
            beforeId: $request->filled('before_id') ? $request->integer('before_id') : null,
        );

        return $this->ok(['data' => array_map(static fn ($e) => $e->toArray(), $entries)]);
    }
}
