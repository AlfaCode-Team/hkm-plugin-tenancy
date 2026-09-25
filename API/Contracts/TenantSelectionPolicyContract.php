<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

/**
 * Who may a given user switch INTO, and with what role.
 *
 * ── WHY THIS IS A SEAM AND NOT A HARD-CODED RULE ───────────────────────────
 * The plugin's own answer is the only one it can justify on its own evidence:
 * you may select a tenant you hold an active seat in. That is
 * {@see \Plugins\Tenancy\Application\Services\MembershipSelectionPolicy}, and
 * it is the default.
 *
 * But it is not the only correct answer, and the common counter-example is the
 * reason this interface exists. A tenant hierarchy has parents and children
 * (`tenants.parent_tenant_id`), and an operator administering the parent
 * usually SHOULD be able to look inside a child — without being seated in it.
 * Seating them would mean maintaining `admins × children` membership rows that
 * have to be created as organisations appear and revoked as staff leave, and a
 * row that outlives the person is a silent grant. Deriving the right from the
 * hierarchy instead means it disappears the moment their parent seat does.
 *
 * That rule is a PRODUCT decision — how much authority a parent holds over a
 * child is not something a routing plugin can decide for every deployment — so
 * the plugin publishes the question and a project binds its own answer.
 *
 * ── THE CONTRACT ───────────────────────────────────────────────────────────
 * An implementation is consulted on EVERY request that carries a selection, not
 * once when the selection is made. It must therefore be cheap, and it must read
 * live authority rather than anything the caller supplied. Returning null is
 * how access ends: the selection is dropped and the request falls back to the
 * scope it would have had anyway.
 */
interface TenantSelectionPolicyContract
{
    /**
     * May $userId act inside $tenantId right now, and as what?
     *
     * @param string $userId       the VERIFIED user, from the Identity — never from a request body
     * @param string $tenantId     the tenant they are asking to act inside
     * @param string $hostTenantId the tenant that owns the hostname being browsed; the
     *                             anchor a hierarchy rule measures against, so that
     *                             "parent admin" cannot be re-derived from a tenant the
     *                             caller has already switched into
     *
     * @return string|null the role to carry inside that tenant, or null when not permitted.
     *                     The role is what downstream authorization reads, so a policy
     *                     granting read-only access returns a role its application does
     *                     not treat as privileged.
     */
    public function roleFor(string $userId, string $tenantId, string $hostTenantId): ?string;

    /**
     * Everything $userId could select right now — the picker's options.
     *
     * Must agree with {@see roleFor()}: an entry listed here and refused there
     * is a switcher that offers a choice and then fails on it.
     *
     * @return list<array{tenantId: string, name: string, slug: string, role: string}>
     */
    public function selectable(string $userId, string $hostTenantId): array;
}
