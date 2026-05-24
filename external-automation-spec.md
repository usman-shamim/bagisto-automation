# SDD: Bagisto External-Automation Capability

> Specification-Driven Development plan for the Bagisto-side integration surface that a future n8n + Selenium AI agent will plug into.
> Status: **approved (2026-05-23)**, ready for implementation.

## Context

The Bagisto store at `~/bagisto-store` will eventually be driven by an external n8n AI agent that:
- Scrapes competitor sites (Daraz, PriceOye, etc.) using the existing `selenium/standalone-chromium` container.
- Uses an LLM to extract price/stock from variable HTML, detect anomalies, classify products, and draft Urdu descriptions.
- Pushes price/stock updates back into Bagisto.

That agent will be built later. **This spec covers only the Bagisto-side capabilities that the agent will plug into.** Without these, the future agent would be forced to either drive the admin UI via browser automation (brittle) or write straight to MySQL (skips events/cache invalidation).

Exploration of `~/bagisto-store` confirmed: admin write routes exist (`PUT /admin/catalog/products/edit/{id}/inventories`, `POST /admin/catalog/products/mass-update`) but require admin session cookies — no bearer-token path. Price is stored as a product attribute (`product_attribute_values.float_value`), stock in `product_inventories.qty`, and `catalog.product.update.after` already fires on updates but isn't delivered anywhere outbound.

---

## Phase 1: Specify

### Intent
Expose a minimal, secure, well-typed integration surface on the Bagisto store so a future external automation agent (n8n + Selenium + LLM) can read product mappings, stage price/stock updates for human review, apply approved updates, and receive product-change notifications — without bypassing Bagisto's domain logic or scraping the admin UI.

### Success Criteria
1. An admin can mint, list, and revoke long-lived bearer tokens from the admin panel; tokens are scoped (read-only, write-staged, write-approved).
2. Calling `GET /api/automation/v1/products` with a valid bearer token returns paginated products with their `id`, `sku`, `name`, current `price`, current `stock`, `status`, and `competitor_mappings` (list of competitor URLs).
3. Calling `POST /api/automation/v1/pending-updates` with a valid bearer token stages a price/stock change against a product; the row lands in a `pending_product_updates` table with `status=pending`, source URL, scraped value, reason, and timestamp.
4. An admin dashboard at `/admin/automation/pending-updates` lists pending rows with Approve/Reject actions; Approve calls `ProductInventoryRepository::saveInventories()` / price-attribute write, sets `status=approved`, and dispatches `catalog.product.update.after`.
5. A webhook delivery system fires outbound HTTPS POSTs to admin-configured URLs on `catalog.product.update.after` with HMAC-SHA256 signature; failed deliveries retry with exponential backoff up to 5 times.
6. A `competitor_product_mappings` table links `bagisto_product_id → (competitor_name, competitor_url, last_scraped_at)`; admin UI under product edit page allows adding/removing mappings.
7. All write endpoints are rate-limited (60 req/min per token) and audit-logged with `token_id`, `endpoint`, `payload_hash`, `ip`, `at` to `automation_audit_log`.
8. Bearer tokens never leak: stored as SHA-256 hashes, shown plaintext only at creation, last-4 visible afterwards.

### Constraints
- **Stack**: Laravel 12 + Bagisto 2.4.x in WSL Sail; PHP 8.3; MySQL 8. No new framework or language.
- **Auth library**: `laravel/sanctum` (already in `composer.lock`) — do not add a competing token library.
- **No core schema edits**: changes restricted to new tables (`api_admin_tokens`, `pending_product_updates`, `competitor_product_mappings`, `automation_audit_log`, `automation_webhooks`, `automation_webhook_deliveries`). Existing `products`, `product_inventories`, `product_attribute_values` are read/written only via Bagisto's existing repositories.
- **Bagisto package conventions** (per CLAUDE.md + AGENTS.md):
  - Dual registration — main provider in `bootstrap/providers.php` AND `ModuleServiceProvider` in `config/concord.php`.
  - Every model gets the Concord trinity: **Contract (interface) + Model + Proxy + Repository** (Prettus L5). Repositories return Contract class via `model()`.
  - Repository pattern enforced — no `DB::table()` or direct model queries in controllers.
- **Translations**: every translation key in this package must exist in all **21 locale files** (`ar, bn, ca, de, en, es, fa, fr, he, hi_IN, id, it, ja, nl, pl, pt_BR, ru, sin, tr, uk, zh_CN`). `php artisan bagisto:translations:check` must pass.
- **Code style + tests**: `vendor/bin/pint --test` and `vendor/bin/pest` (or `php artisan test --compact`) must pass before each task is marked complete.
- **Performance**: `GET /products` p95 < 300 ms with 10k product catalog and `per_page=100`.
- **Security**: bearer tokens hashed at rest; webhook signatures verified by HMAC-SHA256 of body with per-webhook secret; CSRF disabled only for `/api/automation/*` because tokens replace it.
- **Backward compat**: no changes to existing admin or shop routes; all new routes namespaced under `/api/automation/v1/` and `/admin/automation/`.
- **Deployment**: must work in the Sail dev compose without adding new containers; ready for the planned VPS Docker compose.
- **Time**: fits inside the remaining 2-day MVP window — target ~6 working hours of implementation.

### Non-Goals
- **The n8n agent itself**, the Selenium workflows, the LLM prompts for parsing/anomaly/Urdu/classification — all out of scope; built later against this surface.
- **Multi-tenant or per-vendor tokens** — single-store admin tokens only.
- **GraphQL** — REST only.
- **Order or customer endpoints** — only product/inventory/mapping/pending-update endpoints in this spec.
- **Bulk file imports (CSV/XLSX)** — Bagisto's `Webkul/DataTransfer` already handles those; this surface is for streaming/per-record updates.
- **Customer-facing changes** — storefront UI untouched.
- **OAuth / SSO for admins** — bearer tokens issued by admin UI only.

---

## Phase 2: Clarify (Answers encoded into spec)

**Q1: How does the agent authenticate?**
A: New Sanctum-based admin API tokens. Admin mints them from `Settings → Automation → API Tokens`. Scopes: `read`, `write:staged`, `write:approved`. Token shown plaintext only once; SHA-256-hashed at rest (matches Sanctum's own approach — bcrypt is wrong for high-entropy opaque tokens, since it adds no security beyond the token's ~240 bits of entropy but turns every API call into an O(n) row-by-row hash). No browser-login simulation, no direct DB writes.

**Q2: How do we match a competitor's product to a Bagisto product?**
A: A `competitor_product_mappings` table that admins curate manually. UI under each product's edit page: "Add competitor URL → pick competitor (dropdown) → save". The agent never guesses — it only scrapes URLs explicitly mapped.

**Q3: What does the "AI" part of the agent do?**
A: Out of scope for this spec. The Bagisto surface only needs to support what the agent eventually does:
- HTML parsing / price+stock extraction → agent writes to `pending_product_updates` via `POST /pending-updates`
- Anomaly detection → agent populates `pending_product_updates.flags` ("price_drop_50pct") and `confidence` (0–1) so the admin dashboard can sort/filter
- Urdu description generation → agent will POST to a future `PATCH /products/{id}/translations` endpoint — flagged as **future**, not built now
- Category/attribute classification → same as above; future endpoint

**Q4: Auto-apply or queue for review?**
A: All agent-originated updates land in `pending_product_updates` with `status=pending`. Admin reviews and approves. Once admin trust is established, an auto-approve rule engine could be added — out of scope here.

**Open question (low risk, deferred):** webhook event types beyond `product.updated`. v1 ships with `product.updated` only; `order.placed`, `customer.created` deferred.

---

## Phase 3: Plan

### Architecture

```
                      ┌─────────────────────────────┐
                      │  Future n8n + Selenium AI   │
                      │  agent (separate container) │
                      └──────────┬──────────────────┘
                                 │ Bearer token
                                 ▼
┌──────────────────────────────────────────────────────────────┐
│  Bagisto (laravel.test container)                            │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ Route: /api/automation/v1/*                            │ │
│  │ Middleware: auth.api-token + scope:* + throttle:60,1   │ │
│  └─────────┬──────────────────────────────────────────────┘ │
│            ▼                                                 │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ Controllers (Webkul\Automation\Http\Controllers\Api)   │ │
│  │  - ProductsController (read)                           │ │
│  │  - PendingUpdatesController (stage/approve/reject)     │ │
│  │  - MappingsController (CRUD competitor mappings)       │ │
│  └─────────┬──────────────────────────────────────────────┘ │
│            ▼                                                 │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ Reuses existing repositories:                          │ │
│  │  - ProductRepository, ProductInventoryRepository       │ │
│  │  - Listens on: catalog.product.update.after            │ │
│  └─────────┬──────────────────────────────────────────────┘ │
│            ▼                                                 │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ WebhookDispatcher (queued job)                         │ │
│  │  - Reads automation_webhooks                           │ │
│  │  - HMAC-signs payload                                  │ │
│  │  - POSTs with retry/backoff (5 attempts)               │ │
│  └────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

### New package: `packages/Webkul/Automation`

Standard Bagisto package layout. Registered in **both** `bootstrap/providers.php` (main ServiceProvider) and `config/concord.php` (ModuleServiceProvider for Concord model registration). Autoload entry added to root `composer.json` PSR-4 map.

```
packages/Webkul/Automation/
├── composer.json
├── src/
│   ├── Providers/
│   │   ├── AutomationServiceProvider.php      // routes, views, events, migrations, translations
│   │   ├── ModuleServiceProvider.php          // Concord — registers Models against Contracts
│   │   └── EventServiceProvider.php           // listens catalog.product.update.after
│   ├── Contracts/                             // one interface per model (Concord trinity)
│   │   ├── AdminToken.php
│   │   ├── CompetitorProductMapping.php
│   │   ├── PendingProductUpdate.php
│   │   ├── AutomationAuditLog.php
│   │   ├── AutomationWebhook.php
│   │   └── AutomationWebhookDelivery.php
│   ├── Models/                                // Eloquent models implementing the Contracts
│   │   ├── AdminToken.php          + AdminTokenProxy.php
│   │   ├── CompetitorProductMapping.php + Proxy
│   │   ├── PendingProductUpdate.php + Proxy
│   │   ├── AutomationAuditLog.php + Proxy
│   │   ├── AutomationWebhook.php + Proxy
│   │   └── AutomationWebhookDelivery.php + Proxy
│   ├── Repositories/                          // Prettus L5; model() returns Contract class
│   ├── Database/Migrations/
│   │   ├── *_create_api_admin_tokens_table.php
│   │   ├── *_create_competitor_product_mappings_table.php
│   │   ├── *_create_pending_product_updates_table.php
│   │   ├── *_create_automation_audit_log_table.php
│   │   ├── *_create_automation_webhooks_table.php
│   │   └── *_create_automation_webhook_deliveries_table.php
│   ├── Http/
│   │   ├── Middleware/AuthenticateApiToken.php
│   │   ├── Controllers/
│   │   │   ├── Api/{Products,PendingUpdates,Mappings}Controller.php
│   │   │   └── Admin/{Tokens,PendingUpdates,Webhooks}Controller.php
│   │   └── Requests/
│   ├── Jobs/DispatchWebhook.php
│   ├── Services/{TokenIssuer,PendingUpdateApplier,WebhookSigner}.php
│   ├── Routes/
│   │   ├── admin-routes.php                   // /admin/automation/*  (Bagisto naming)
│   │   └── api.php                            // /api/automation/v1/*
│   ├── Resources/
│   │   ├── views/                             // admin Blade pages
│   │   └── lang/{ar,bn,ca,de,en,es,fa,fr,he,hi_IN,id,it,ja,nl,pl,pt_BR,ru,sin,tr,uk,zh_CN}/app.php
│   └── tests/                                 // Pest — Feature + Unit suites
```

### Schema (new tables only)

```sql
api_admin_tokens
  id, admin_id (FK admins), name, token_hash (sha256, UNIQUE),
  last_four, scopes (json: ["read","write:staged"]),
  expires_at (nullable), last_used_at, revoked_at, created_at, updated_at

competitor_product_mappings
  id, product_id (FK products), competitor_name (varchar 64),
  competitor_url (text), last_scraped_at (nullable),
  created_at, updated_at
  UNIQUE(product_id, competitor_url)

pending_product_updates
  id, product_id (FK), submitted_by_token_id (FK api_admin_tokens),
  source_url (text), proposed_price (decimal nullable), proposed_stock (int nullable),
  current_price_snapshot (decimal), current_stock_snapshot (int),
  flags (json: ["price_drop_50pct"]), confidence (decimal 3,2 nullable),
  status enum('pending','approved','rejected','applied','failed'),
  reviewed_by_admin_id (FK admins, nullable), reviewed_at, review_note (text nullable),
  applied_at, error_message (nullable),
  created_at, updated_at

automation_audit_log
  id, token_id (FK), admin_id (FK nullable),
  endpoint, method, payload_hash (sha256 hex), ip, status_code, created_at
  INDEX(token_id, created_at), INDEX(endpoint, created_at)

automation_webhooks
  id, admin_id (FK), name, target_url (text), event (e.g. 'product.updated'),
  secret (random 32 bytes hex, shown once), is_active, created_at, updated_at

automation_webhook_deliveries
  id, webhook_id (FK), payload (json), signature (sha256 hex),
  attempt (1..5), response_status, response_body (text truncated),
  succeeded_at, next_retry_at, created_at
```

### Key decisions and rationale

| Decision | Choice | Why |
|---|---|---|
| Auth | Sanctum personal access tokens, scoped | Already in `composer.lock`; native Laravel pattern; revocable |
| Update flow | Pending-queue + admin approval | User picked safety for MVP; auto-apply rule engine can layer on later |
| Mapping strategy | Manual `competitor_product_mappings` curated in admin UI | User picked this; zero false-match risk |
| Package location | New `Webkul/Automation` package | Keeps Bagisto core unmodified; clean upgrade path |
| Webhook delivery | Queued job with exponential backoff (5 retries: 1m, 5m, 30m, 2h, 12h) | Decouples Bagisto request thread from slow external endpoints |
| Webhook signing | HMAC-SHA256 over raw body, sent in `X-Bagisto-Signature` header | Industry standard (GitHub/Stripe pattern); per-webhook secret |
| Audit log | Every write hits `automation_audit_log` synchronously | Cheap insert; needed for incident forensics |
| Price write path | Update product attribute via `ProductRepository::update(['price' => $v], $id)` — do NOT write directly to `product_attribute_values` | Lets Bagisto run validation, fire events, invalidate FPC cache |
| Stock write path | `ProductInventoryRepository::saveInventories()` | Already exists; fires the same events the admin UI does |

### Testing strategy
- **Unit**: TokenIssuer hashing/verifying; WebhookSigner produces stable signatures; PendingUpdateApplier rejects approved-but-stale rows (where current snapshot drifted).
- **Feature**: full request lifecycle for `GET /products`, `POST /pending-updates`, `POST /pending-updates/{id}/approve`, `POST /webhooks`; uses Bagisto's existing seeders to populate ~50 products.
- **Auth**: bearer absent → 401; bearer with wrong scope → 403; revoked token → 401.
- **Webhook**: stub HTTP server in test (Guzzle MockHandler) returns 500 → delivery row goes into retry; returns 200 → `succeeded_at` set.
- **Edge**: pending update for deleted product, price snapshot drift, mapping for product that no longer exists.

### Tradeoffs noted
- **No queue worker in default Sail compose** — `DispatchWebhook` will run synchronously unless the user runs `sail artisan queue:work` in a side terminal. Acceptable for dev; the prod compose will have a dedicated `queue` service.
- **Price-as-attribute** complicates the snapshot logic — we must read price via `ProductRepository` accessor, not a direct column. Mitigated by `current_price_snapshot` capture at staging time + drift check at apply time.
- **No bulk endpoint in v1** — agents update one product per request. If throughput becomes a bottleneck, add `POST /pending-updates/bulk` later. Not premature now.

---

## Phase 4: Tasks

Each task ends with a verifiable acceptance check. Tasks are ordered by dependency — earlier tasks unblock later ones.

1. **Scaffold `Webkul/Automation` package**
   - Create directory tree, `composer.json`, `AutomationServiceProvider`, `ModuleServiceProvider`.
   - Add PSR-4 autoload entry in root `composer.json` for `Webkul\Automation\`.
   - Register main provider in `bootstrap/providers.php` (append, do not modify existing entries).
   - Register `ModuleServiceProvider` in `config/concord.php` (Concord trinity registration).
   - Run `composer dump-autoload && php artisan optimize:clear`.
   - Acceptance: `php artisan package:discover` lists it; `php artisan route:list` is unchanged (no routes registered yet); `vendor/bin/pint --test` passes.

2. **Migration: `api_admin_tokens`**
   - Schema as above.
   - Acceptance: `sail artisan migrate` succeeds; `DESCRIBE api_admin_tokens` shows all columns.

3. **`AdminToken` model + `TokenIssuer` service**
   - `TokenIssuer::issue(Admin $a, string $name, array $scopes): string` returns 64-char plaintext token once (`bag_` prefix + 60 hex chars), stores SHA-256 hash + last-4.
   - `TokenIssuer::find(string $plaintext): ?AdminToken` constant-time compare.
   - Acceptance: unit test issues + finds + rejects wrong tokens; revoked tokens return null.

4. **`AuthenticateApiToken` middleware**whay
   - Reads `Authorization: Bearer <token>`, resolves to `AdminToken`, attaches to request, enforces scope per route, updates `last_used_at`, writes `automation_audit_log` entry.
   - Acceptance: feature test — missing header → 401; bad token → 401; valid `read` token on `write:staged` route → 403; success path → 200 and audit row exists.

5. **Routes file `Routes/api.php` + `ProductsController::index`**
   - `GET /api/automation/v1/products?per_page=100&page=1&updated_since=ISO8601`
   - Returns paginated JSON: `id, sku, name, price, stock_total, status, competitor_mappings`.
   - Reuses `ProductRepository` and `ProductInventoryRepository`; sums stock across inventory sources.
   - Acceptance: feature test seeds 30 products, queries page 1 with `per_page=10`, asserts shape + count + `last_page=3`. p95 < 300 ms verified locally.

6. **Migrations: `competitor_product_mappings`, `pending_product_updates`, `automation_audit_log`**
   - Schema as above.
   - Acceptance: migrations run; FK constraints validate by inserting an orphan row → fails.

7. **`MappingsController` (REST) + admin tab on product edit**
   - REST: `GET/POST/DELETE /api/automation/v1/products/{id}/mappings`.
   - Admin: tab in product edit blade injecting via `bagisto.admin.catalog.products.edit.form_buttons.after` event (no core edits).
   - Acceptance: add mapping via admin UI → row visible in API response; delete via API → gone from admin UI.

8. **`PendingUpdatesController::store` (API)**
   - `POST /api/automation/v1/pending-updates` with `{product_id, source_url, proposed_price?, proposed_stock?, flags?, confidence?}`; requires `write:staged` scope.
   - Captures snapshots of current price/stock at insert time.
   - Acceptance: feature test posts a valid payload → 201 + row in DB with `status=pending` and snapshot values; missing fields → 422.

9. **Admin UI: `PendingUpdatesController::index` + approve/reject**
   - Datagrid-style list at `/admin/automation/pending-updates`; filters by status, product, flags.
   - Approve button → calls `PendingUpdateApplier::apply($row)`; Reject sets `status=rejected` + note.
   - `PendingUpdateApplier` performs drift check (re-read current price/stock; if drifted >10% since snapshot, mark `status=failed` + error_message); on success calls `ProductRepository::update(['price' => $v], $id)` and `ProductInventoryRepository::saveInventories(...)`, sets `status=applied`, `applied_at`.
   - Acceptance: approving a pending row mutates the product (verify via `GET /products`); drift case fails as expected.

10. **Admin UI: Tokens management**
    - List, create (scopes multi-select, expiry date), revoke. Plaintext shown only at creation in a one-shot modal.
    - Acceptance: create token → use in `curl -H "Authorization: Bearer …" /api/automation/v1/products` → 200; revoke → next call 401.

11. **Migrations + model + admin UI: `automation_webhooks`, `automation_webhook_deliveries`**
    - Admin can register webhook URLs for `product.updated` event with a generated secret.
    - Acceptance: create webhook → row exists with secret displayed once.

12. **`DispatchWebhook` queued job + `EventServiceProvider`**
    - Listens to `catalog.product.update.after`. For each active webhook with matching event, dispatches `DispatchWebhook` with payload `{event, product_id, sku, price, stock_total, occurred_at}`.
    - Job signs body with HMAC-SHA256 using webhook secret, POSTs with 10 s timeout. Writes delivery row. On non-2xx or exception, re-queues with backoff schedule.
    - Acceptance: integration test — register webhook pointing at `http://httpbin/post`, update a product, assert `automation_webhook_deliveries` row with `response_status=200` and `succeeded_at` set.

13. **Hardening: rate limiting, validation, error shape**
    - `throttle:60,1` on `/api/automation/v1/*`; standardized error JSON `{error: {code, message, details?}}`; request IDs in `X-Request-ID` echoed in responses.
    - Acceptance: 61st request in a minute → 429; malformed JSON → 400 with shape.

14. **Documentation**
    - `packages/Webkul/Automation/README.md` with: token issuance, endpoint reference (request/response schemas + curl examples), webhook payload schema + signature verification snippet, error codes.
    - Acceptance: a developer who has never seen this code can issue a token, register a webhook, and stage an update using only the README.

---

## Phase 5: Implement (Approach Guidance)

When implementation begins, follow:

- **Order**: do tasks 1–5 in sequence (auth foundation); tasks 6–10 form the pending-update + admin pieces and can interleave; tasks 11–12 are webhook delivery and are independent of pending-updates; 13 and 14 close out.
- **Per-task discipline**: each task gets its own commit with the acceptance test added BEFORE the implementation (TDD-lite). PRs reviewed against the spec's success criteria — not against feel.
- **No premature abstraction**: don't build a generic "AutomationEventDispatcher" — wire the one event we need.
- **Reuse**: every product write MUST go through the existing repositories. Direct `DB::table()->update()` on `products` or `product_inventories` from this package is a code-smell — flag in review.
- **Bagisto patterns to follow**: package skeleton mirrors existing first-party packages (e.g. `Webkul/Marketing`); admin views use `<x-admin::layouts>`; events use the `bagisto.admin.*` view-render-event names so other packages can layer on later.

---

## Phase 6: Validate

Validation runs after implementation, against THIS spec:

### Success-criteria check (one row per criterion from Phase 1)
- [ ] **#1 Token CRUD**: mint via admin → JSON returns plaintext once → list shows last-4 → revoke clears it from API calls.
- [ ] **#2 Products endpoint**: shape, pagination, `updated_since` filter, mappings nested, p95 < 300 ms over 10k seeded products.
- [ ] **#3 Pending-update staging**: POST → row exists with snapshot + flags; validation rejects missing fields.
- [ ] **#4 Admin approve/reject**: approve mutates product via repository; reject leaves it untouched; drift case fails loudly.
- [ ] **#5 Webhook delivery**: register URL → update product → delivery row 200; force 500 → retry rows appear on backoff schedule; signature verifies in receiver.
- [ ] **#6 Competitor mappings**: admin UI under product edit; REST endpoints CRUD; UNIQUE(product_id, competitor_url) enforced.
- [ ] **#7 Rate limit + audit**: 60 req/min enforced; every write produces an audit row.
- [ ] **#8 Token storage**: DB inspection shows hashed tokens only; plaintext appears in no log file.

### Constraint check
- [ ] No edits to files outside `packages/Webkul/Automation/`, root `composer.json`, `bootstrap/providers.php`, and `config/concord.php`. Migrations live inside the package, not root `database/migrations/`.
- [ ] No new container in `docker-compose.yml`.
- [ ] Existing storefront + admin smoke tests pass (`http://localhost/` storefront loads, `/admin` login works, product edit still functions).
- [ ] `vendor/bin/pint --test` passes (no style violations).
- [ ] `vendor/bin/pest` (or `php artisan test --compact`) passes — both new package tests and pre-existing suites.
- [ ] `php artisan bagisto:translations:check` passes — every new translation key exists in all 21 locale files.
- [ ] Concord trinity present for each model (Contract interface, Model, Proxy, Repository); no controller uses `DB::table()` or raw models.

### Security check
- [ ] Tokens hashed at rest (`SELECT token_hash FROM api_admin_tokens` shows 64-char SHA-256 hex values, never plaintext).
- [ ] Webhook signature verifies with documented HMAC recipe.
- [ ] No CSRF tokens required on `/api/automation/*` (token replaces them); CSRF still required on `/admin/automation/*` (session).
- [ ] OWASP top-ten sweep: SQL injection (parameterized queries via Eloquent), broken auth (token middleware on every write), excessive data exposure (price/stock only — no customer fields leak).

### Documentation check
- [ ] README is complete enough that a stranger can integrate without reading source.

### Sign-off
- [ ] All success criteria checked.
- [ ] All constraints satisfied.
- [ ] All validation gates green.
- [ ] User confirms a future n8n agent could plausibly drive this surface end-to-end (mental dry-run of the future workflow against the documented endpoints).

---

## Critical files to be created/touched (representative paths)

- **New package root**: `packages/Webkul/Automation/` (everything under here is new)
- **Root composer**: `~/bagisto-store/composer.json` — autoload PSR-4 entry for `Webkul\Automation\`
- **Provider registration**: `~/bagisto-store/bootstrap/providers.php` — append `AutomationServiceProvider::class` (do not modify existing entries)
- **Concord registration**: `~/bagisto-store/config/concord.php` — append `ModuleServiceProvider::class` (Bagisto's dual-registration convention)
- **Migrations**: ship inside the package at `packages/Webkul/Automation/src/Database/Migrations/` and auto-loaded by the provider via `$this->loadMigrationsFrom(...)` — do NOT pollute root `database/migrations/`
- **No edits** to `packages/Webkul/Product/`, `packages/Webkul/Admin/`, `packages/Webkul/Customer/`, or any other existing package

## Reused functions (from exploration)

- `Webkul\Product\Repositories\ProductRepository::update($data, $id)` — for price write path
- `Webkul\Product\Repositories\ProductInventoryRepository::saveInventories(array $data, $product)` — `packages/Webkul/Product/src/Repositories/ProductInventoryRepository.php:19`
- Event `catalog.product.update.after` — fired by `Webkul\Product\Providers\EventServiceProvider`; subscribe in our `AutomationEventServiceProvider`
- Admin auth guard `admin` — already protects all `/admin/*` routes; reuse for admin UI pages
- View-render events `bagisto.admin.catalog.products.edit.*` — to inject the mappings tab into the product edit page without editing core

