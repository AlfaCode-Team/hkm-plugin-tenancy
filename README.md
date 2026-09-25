# Tenancy — Multi-Tenant Control Plane

Database-per-tenant routing for the AlfacodeTeam PhpServicePlatform. Maps the
authenticated `Identity.tenantId` to an **isolated tenant database** and rebinds
`DatabasePort` per request, so every repository transparently talks to the
correct tenant DB. Built on top of `plugins/Database`'s `ConnectionManager`.

- **solves:** `tenancy.routing`
- **requires:** `database.management` (stage path only — its routes carry `auth.identity`/`user.management`/`audit.trail` as route-level `requires[]`)
- **exposes:** `TenantRegistryContract`, `TenantConnectionResolverContract`, `MembershipServiceContract`, `InvitationServiceContract`

## Two planes

| Plane | DB | Tables |
|---|---|---|
| Control (central) | one, always connected | `users`, `tenants`, `user_tenants` (+ invitations/audit) |
| Data (per tenant)  | one per tenant, on demand | pure business domain — `projects`, `tasks`, … **no auth, no `tenant_id` column** |

## Wiring

1. **Run central migrations** (against the central connection):
   ```
   hkm migrate:run        # creates tenants, user_tenants (users lives in plugins/User)
   ```

2. **Register as an ESSENTIAL module** so every request is routed — declared by
   the PROJECT in `proj.json` (the bootstrap wires
   `->withEssentialModules(EntryHelpers::projectEssentials($projectRoot))`):
   ```jsonc
   // proj.json
   "essentials": ["tenancy.routing"]
   ```
   The domain resolves to the provider at `build()` (unknown domain = boot
   failure), and essentials load their transitive `requires[]` — so
   `database.management` comes along automatically. `EncryptionPort` and
   `CachePort` are core ports (bootstrap `withPorts`) used by the
   resolver/registry — ensure both are bound.

3. **Mint a tenant-scoped Identity** in your Auth layer. After the user selects a
   tenant, re-check `user_tenants` and put the tenant in the JWT `tnt` claim; the
   Auth security layer sets `Identity.tenantId` from it. `TenantContextStage`
   (registered at `after.load`) does the rest.

   > The membership re-check on every request lives in the Auth layer, not here —
   > a revoked `user_tenants` row must drop access before the JWT expires.

## Control-plane tables

Central migrations (run on the central connection via `hkm migrate:run`):

| Table | Role |
|---|---|
| `tenants` | registry → connection coordinates (password encrypted) |
| `user_tenants` | M:N membership: user ↔ tenant + role + status |
| `tenant_invitations` | email onboarding; SHA-256 token only; converts to a membership on accept |
| `audit_log` | append-only trail (login, `tenant.switch`, `tenant.create`, …) |

## Tenant-selection flow (`MembershipServiceContract`)

Turns an authenticated but *unscoped* user into a tenant-scoped session. Exposed
as routes (both behind the `auth` filter):

```
GET  /ajx/me/tenants                 → the tenant picker (active seats only)
POST /ajx/tenants/{tenantId}/select  → re-mint a tenant-scoped token
```

`selectTenant()` **re-verifies** the membership against central `user_tenants`
(never trusts a client-supplied tenant id), audits `tenant.switch`, and returns
the verified seat (`TenantSummary`). Tenancy is control plane ONLY — it does
NOT mint credentials: `TenantController` composes the seat with the Auth
module (`AuthServiceContract::issueJwt`, `tnt` claim + `roles` + the `name`
claim read via User's published `TenantProfileReaderContract`) and builds the
response:

```php
$seat = $memberships->selectTenant($identity->userId, $tenantId, $request->ip());
// controller: issueJwt(userId, ['tnt' => ..., 'roles' => [$seat->role], 'name' => ...])
// → { token, tokenType: "Bearer", tenantId, role, expiresIn }
```

The client sends the returned token on subsequent requests; `TenantContextStage`
routes them to the tenant database. A revoked/suspended seat fails `selectTenant`
with `403` (audited `tenant.switch_denied`) and — because the Auth layer re-checks
membership per request — also loses access on an already-issued token before it
expires. `TENANCY_TOKEN_TTL` (default 3600s) sets the scoped-token lifetime.

## Active tenant for BROWSER sessions (`TenantSelectionPolicyContract`)

`POST /ajx/tenants/{id}/select` above re-mints a JWT. That is the right answer
for a token client and no answer at all for a cookie session — the browser has
nowhere to put the token, and the next page load arrives with the session it
already had. These three routes are the session-shaped sibling (all behind
`auth`):

```
GET    /ajx/tenant/active   → { host, active, selectable[] }   the switcher's data
POST   /ajx/tenant/active   { "tenant": "…" }                  switch into one
DELETE /ajx/tenant/active                                      back to the host's tenant
```

The choice is stored server-side (session first, an encrypted `hkm_tsel_v01`
cookie only where there is no session) and applied by `ActiveTenantStage`.

### Why a second stage, and why it is not the cookie `TenantContextStage` reads

`TenantContextStage` registers at `after.load` priority **10**, where lower runs
first:

```
10  Tenancy   TenantContextStage   picks the tenant, rebinds DatabasePort
20  Session   StartSessionStage    opens the session
22  Auth      SessionAuthStage     attaches the Identity
24  Tenancy   ActiveTenantStage    applies the user's choice
```

At 10 there is no session and no Identity. So on a cookie-session deployment
`Identity.tenantId` is always `''` and the host always decides — and the seat
re-verification in that stage, guarded by `if ($userId !== '')`, never executes.
Its `hkm_tnat_v01` hint inherits both problems: its `u` binding compares `''` to
`''` and therefore matches every user, and nothing re-checks it.

Writing a deliberate selection into that cookie would turn a hint that today only
ever echoes the hostname into an unverified, unbound grant of another tenant's
database, surviving logout. The selection therefore uses its own keys and is
consumed at 24, where the user is known.

### The policy is the seam

`ActiveTenantStage` trusts the store for nothing. On **every** request it asks
`TenantSelectionPolicyContract` whether this user may still act inside the
selected tenant, and with what role. Returning `null` drops the selection. A
revoked seat therefore stops working on the next request rather than whenever a
cookie expires.

The default, `MembershipSelectionPolicy`, answers the only question this
plugin's data supports: do you hold an active, routable seat. Override the
binding to widen it — the canonical case being *an admin of a parent tenant may
act inside its children*, derived from `tenants.parent_tenant_id` rather than
from membership rows that would otherwise have to be created per admin per child
and revoked by hand:

```php
$container->bind(TenantSelectionPolicyContract::class, fn($c) => new MyHierarchyPolicy(...));
```

`roleFor()` returns the role carried **inside** the selection. The stage replaces
the Identity's tenant id and role with it and empties permissions: a role is a
statement about one tenant, and carrying one earned elsewhere into this scope is
how a seat in one place quietly decides what happens in another. A policy that
wants read-only access returns a role its application does not treat as
privileged.

`hostTenantId` — the tenant owning the hostname, published as the `tenant_host`
request attribute — anchors any hierarchy rule, so "parent admin" cannot be
re-derived from a child the caller has already switched into and turned into a
chain. Anything that must stay fleet-level (the switcher, a list of children)
reads that attribute rather than `tenant`.

When a permitted selection cannot be applied because its database is
unavailable (suspended, gone, still provisioning), the request stays on the
host's scope, the choice is kept, and the stage sets `tenant_unavailable` to the
selected id — so a UI can say so instead of silently showing the host.

### Every switch is audited — on both sides

A policy may grant access without a seat, and such a visitor never appears in
the entered tenant's member list — so each switch leaves a record on both sides,
through Audit's `AuditServiceContract`, with `{host_tenant, tenant, role}` meta:

| Action | Written by | Lands in the trail of |
|---|---|---|
| `tenant.switch.enter` / `tenant.switch.exit` | `ActiveTenantController` | the tenant owning the hostname (operator side) |
| `tenant.switch.visit` | `ActiveTenantStage`, first request inside, once per entry | the tenant entered |

Two writers because Audit writes through the request's `DatabasePort`: the
switch endpoint always runs at the host's scope (the stage never applies a
selection to it), and only a request already inside the entered tenant can reach
that tenant's trail. The visit record needs `audit.trail` in that request's
graph — make it essential if you want it on every route. A refused switch
records nothing; every write is best-effort, so an audit outage never blocks
switching.

### Restrict switching to the consoles — `TENANCY_SELECTION_HOSTS`

A selection rescopes **everything** on the host it applies to. On a public site
that means votes, checkouts and signups landing in whichever tenant an operator
last looked at. Set the hosts that should offer switching:

```dotenv
TENANCY_SELECTION_HOSTS=app.*,organizer.*
```

Comma-separated; `*` matches any run of characters and patterns match the whole
hostname (`app.*` matches `app.brand.com`, not `myapp.brand.com`). Outside the
list the stage applies no selection and publishes no `tenant_host`, and the
endpoint answers 404 rather than storing a choice with no anchor. Unset keeps
the historical behaviour — every host.

| Var | Default | Effect |
|---|---|---|
| `TENANCY_ACTIVE_SELECTION` | `true` | master switch for the stage and the routes |
| `TENANCY_SELECTION_HOSTS` | *(every host)* | hostname patterns where switching is offered |
| `TENANCY_SELECTION_COOKIE` | `hkm_tsel_v01` | cookie name used only where there is no session |
| `TENANCY_SELECTION_TTL` | `0` | cookie lifetime in seconds; `0` = session cookie |

## Invitations (`InvitationServiceContract`)

Email-based onboarding that decouples "invited" from "has an account".

```php
$res = $invitations->invite($tenantId, 'alice@example.com', 'member', $inviterUserId);
// → InvitationResult{ token, … }  — embed $res->token in the emailed accept link (shown ONCE)

$tenantId = $invitations->accept($rawToken, $identity->userId, $userVerifiedEmail, $ip);
// validates (pending, not expired, email matches), creates/activates the user_tenants
// seat (idempotent), marks the invite accepted, audits member.join.

$invitations->revoke($rawToken);
```

Only the SHA-256 of the token is stored. `accept()` REQUIRES the authenticated
user's verified email to match the invited address (an invite for alice@ cannot
be claimed by bob@).

Wired endpoint (behind the `auth` filter; the email is read from the User
identity store, never the request body):

```
POST /ajx/invitations/accept   { "token": "…" }   → { "tenantId": "…" }
```

This is why the invitation route carries `"requires": ["user.management"]` in
`module.json` — `InvitationController` resolves the caller's verified email via
`UserServiceContract` (route-level, so it loads only when the endpoint is hit).

## Refresh tokens — moved to `Plugins\Auth`

Refresh tokens are an **authentication** concern, so they now live in
`Plugins\Auth` (`RefreshTokenServiceContract`, table `refresh_tokens`, endpoints
`POST /auth/refresh` + `/auth/refresh/logout`). See `docs/ai-context/25_AUTH.md`.

The relocated flow is **tenant-agnostic**: `tenantId` rides through as a
passthrough hint for the access token's `tnt` claim but is NOT re-verified on
refresh. The tenant seat re-check (a revoked seat can't get back in) lives HERE,
in the tenant-**selection** flow (`POST /ajx/tenants/{id}/select`), not on refresh.

## Provisioning & migrations

```
hkm tenants:create --name="Acme" --slug=acme \
    --db-name=tnt_acme --db-user=acme --db-password=secret \
    --db-host=127.0.0.1 --db-port=3306

hkm tenants:migrate                 # apply template migrations to all active tenants
hkm tenants:migrate --tenant=<id>   # one tenant
hkm tenants:migrate --pretend       # print SQL, change nothing
```

The tenant template lives in `database/tenant-template/`. Override with
`TENANCY_TEMPLATE_PATH` or `--template`. Each tenant DB keeps its own
`let_migrations` table; the central `tenants.schema_version` mirrors the latest
applied batch for fleet-wide drift visibility. A failing tenant is skipped, not
fatal — the run is resumable.

### `var/tenants.json` — default tenant for the CLI

A successful `tenant:create` records the tenant in the project's
`var/tenants.json` (`Plugins\Tenancy\Support\TenantsFile`) and makes it the
**default** (last created wins). Commands that target one tenant then work
without `--tenant`/`--slug`:

```
hkm tenant:create --name="Acme" --slug=acme ...   # recorded as default
hkm tenant:host:add --host=acme.localhost --verified   # → default tenant
hkm tenant:delete --drop-database                       # → default tenant
```

Tenants provisioned BEFORE this existed (or after a `var/` wipe — it is
disposable) are backfilled with `tenant:remember`:

```
hkm tenant:remember                  # only one tenant registered → recorded; else interactive pick
hkm tenant:remember --slug=acme      # one tenant by slug (becomes the default)
hkm tenant:remember --all            # every registered tenant (last = default)
```

The file is a convenience HINT only — the central `tenants` table stays the
source of truth. Every command re-validates the recorded id against the
registry and silently drops a stale entry (e.g. a tenant deleted elsewhere).
`tenant:delete` also removes the entry on success; the default falls back to
the last remaining recorded tenant. `tenant:migrate` needs no id either way —
it fleet-migrates every active tenant by default.

## Isolation guarantees

- **Fail closed.** Unknown / suspended / deleted / unreachable tenant → throw.
  Never falls back to another tenant or to central. Status is re-validated on
  **every** request (the registry is cache-backed, so it's cheap) — including
  when the connection is already warm in a long-lived worker — so a suspension or
  deletion takes effect within `TENANCY_REGISTRY_TTL`, not "after the next worker
  restart". A control-plane change can call `resolver->invalidate($tenantId)` to
  drop the warm handle + cached row immediately.
- **Per-tenant circuit breaker.** After `TENANCY_BREAKER_THRESHOLD` consecutive
  **connectivity** failures within `TENANCY_BREAKER_WINDOW` seconds, the tenant
  fast-fails for `TENANCY_BREAKER_COOLDOWN` seconds, isolating one dead tenant DB
  from the fleet. Only genuine connection faults (`ConnectionException` with a
  connect / connection_lost / pool_acquire operation) feed the breaker — a bad
  query or domain error does not trip a healthy tenant. The failure counter is a
  sliding window, so sporadic blips never accumulate into a false trip.
- **Swoole-safe.** The resolved tenant `DatabasePort` is bound into the
  per-request `ModuleContainer` and discarded on `reset()`; the tenant id rides
  on the immutable `Request`/`Identity`, never a static or `CoreContainer`.

## Config (`module.json`)

| Env | Default | Meaning |
|---|---|---|
| `TENANCY_MODE` | `claim` | tenant identification: `claim` (Identity.tenantId) \| `domain` (Host sub-domain label) \| `host` (full Host via `tenant_hosts`) |
| `TENANCY_BASE_DOMAINS` | — | domain mode: comma-separated base domains a tenant label hangs off |
| `TENANCY_RESERVED_SUBDOMAINS` | `www,api,admin,…` | domain mode: labels that are never tenants (identify to `''`) |
| `TENANCY_CENTRAL_DOMAINS` | — | hosts served from the CENTRAL connection, no tenant scope (`*.` wildcard allowed) |
| `TENANCY_CONTROL_PLANE` | `false` | **bool.** `true` disables the whole stage for this deployment |
| `TENANCY_EXEMPT` | `/ping` | paths served with no tenant scope (trailing `*` = prefix match) |
| `TENANCY_REGISTRY_TTL` | `60` | registry cache TTL (s) |
| `TENANCY_BREAKER_THRESHOLD` | `5` | connectivity failures before the breaker opens |
| `TENANCY_BREAKER_WINDOW` | `60` | sliding window (s) failures must occur within |
| `TENANCY_BREAKER_COOLDOWN` | `30` | breaker open window (s) |
| `TENANCY_TEMPLATE_PATH` | bundled | tenant template migrations path |
| `TENANCY_MEMBERSHIP_CACHE_TTL` | `10` | per-request seat re-check cache TTL (s) |
| `TENANCY_MAX_WARM_CONNECTIONS` | `200` | warm tenant connections held per worker |
| `TENANCY_MAX_HOSTS_PER_TENANT` | — | cap on custom hosts a tenant may claim |
| `TENANCY_MAX_SUB_TENANTS_PER_PARENT` | — | cap on sub-tenants under one parent |
| `TENANCY_TOKEN_TTL` | — | invitation / host-verification token lifetime (s) |
| `TENANCY_DNS_CHALLENGE_PREFIX` | — | TXT record name prefix for host verification |
| `TENANCY_DNS_VALUE_PREFIX` | — | TXT record value prefix for host verification |

## Strict routing — every host is a tenant

Routing is **strict**: every request must resolve to a tenant (remembered
cookie hint first — principal-bound — then the `TENANCY_MODE` identifier). A
request that cannot be scoped — an unknown host, or no tenant claim/cookie —
**fails closed with 404**; there is no unscoped passthrough to the central
connection. Register every served host (`tenant:host:add <host> --verified` in
host mode). Control-plane code that needs central pins it explicitly via the
`ConnectionManager` default.

### `TENANCY_BASE_DOMAINS` and `TENANCY_CENTRAL_DOMAINS` are BOTH needed

They are not alternatives and they are not duplicates — they answer different
questions, at different layers, and in `domain` mode a deployment needs both:

| | `TENANCY_BASE_DOMAINS` | `TENANCY_CENTRAL_DOMAINS` |
|---|---|---|
| Answers | *which tenant is this host?* | *should this host be tenant-scoped at all?* |
| Read by | `DomainTenantIdentifier` (via `Provider`) | `TenantContextStage::isCentralDomain()` |
| Applies in | `TENANCY_MODE=domain` only | **every** mode, always |
| Grammar | plain suffixes — `shop.example` | exact host, or `*.parent` wildcard |
| Effect | strips the suffix; left-most label is the tenant id | skips identification entirely, serves CENTRAL |

The coupling is the part that bites. Under strict routing an identifier that
returns `''` is a **404**, not a fallback to central — so every non-tenant host
under a base domain must be listed in `TENANCY_CENTRAL_DOMAINS` or it stops
being served:

```dotenv
TENANCY_MODE=domain
TENANCY_BASE_DOMAINS=shop.example
TENANCY_CENTRAL_DOMAINS=shop.example,admin.shop.example
```

- `acme.shop.example` → base domain stripped → tenant `acme` → tenant DB.
- `shop.example` (the apex) → identifier returns `''`. **Without** it being in
  `TENANCY_CENTRAL_DOMAINS` that is a 404, and the apex login the tenant
  sub-domains depend on is unreachable.
- `admin.shop.example` → `admin` is a default `TENANCY_RESERVED_SUBDOMAINS`
  label, so the identifier also returns `''` → same 404 unless listed here.

Being on the reserved list is therefore **not** enough to serve a host; it only
stops the label being read as a tenant id. `TENANCY_CENTRAL_DOMAINS` is what
actually serves it.

`TENANCY_CENTRAL_DOMAINS` is also the narrow tool next to the blunt one:
`TENANCY_CONTROL_PLANE=true` is a **bool** that switches the stage off for the
*entire deployment* (right for a dedicated control-plane project);
`TENANCY_CENTRAL_DOMAINS` exempts named hosts when ONE deployment serves both
kinds at once. Setting `TENANCY_CONTROL_PLANE` to a hostname fails the boot —
`ValidateConfigStage` rejects it as a non-bool.

## Activation & per-request cost

Tenancy must register on EVERY request — the project declares it in `proj.json`:
`"essentials": ["tenancy.routing"]` (resolved by `Kernel::withEssentialModules()`;
an unknown domain fails the boot). Module-level `requires` is just
`["database.management"]` — the always-on stage path — so the every-request
graph stays at two modules; the selection/admin/invitation/host routes pull
`auth.identity` / `user.management` / `audit.trail` via route-level
`requires[]` only when hit. A single-tenant project must leave Tenancy out of
`withModules` entirely (not merely out of essentials) — the always-on stage
fails loudly when the module never registered.

## Swoole connection pooling (optional optimization)

By default `ConnectionManager` is request-scoped, so tenant sockets aren't reused
across requests. For long-lived OpenSwoole workers, bind `ConnectionManager` (and
the resolver) into the **CoreContainer** in bootstrap so resolved tenant adapters
persist per worker. Cap with an LRU eviction of idle tenant connections so a
worker serving thousands of tenants never holds thousands of open sockets — and
front the DB tier with ProxySQL/PgBouncer under PHP-FPM.

## Documentation

- [docs/TENANCY.md](docs/TENANCY.md) — the full Tenancy reference.
- [Kernel guides](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/docs/guides) — the framework contracts this plugin builds on.
