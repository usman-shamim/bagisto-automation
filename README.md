# Webkul/Automation

External-automation surface for a Bagisto store. Exposes a bearer-token REST API,
an admin review queue for staged price/stock updates, signed outbound webhooks,
and a competitor-product-mapping table.

Designed to be the integration substrate for a separate AI agent (e.g. n8n +
Selenium + LLM) that scrapes competitors and pushes updates back — without
bypassing Bagisto's domain logic or scraping the admin UI.

This package only ships the **Bagisto-side capabilities**. The agent itself is
out of scope.

---

## Contents

1. [What this gives you](#what-this-gives-you)
2. [Issuing an API token](#issuing-an-api-token)
3. [Authentication, rate limiting, request IDs](#authentication-rate-limiting-request-ids)
4. [REST API reference](#rest-api-reference)
5. [Admin UIs](#admin-uis)
6. [Outbound webhooks](#outbound-webhooks)
7. [Error response shape and codes](#error-response-shape-and-codes)
8. [Schema overview](#schema-overview)
9. [Non-goals](#non-goals)

---

## What this gives you

- `Authorization: Bearer <token>` REST API under `/api/automation/v1/*`.
- Three token scopes: `read`, `write:staged`, `write:approved`.
- Staging queue: agents POST proposed price/stock changes; admins approve or reject from the panel.
- Drift detection on approval: if current price/stock has moved more than 10% since the snapshot was captured, the row is marked `failed` instead of applied.
- Outbound webhooks on `catalog.product.update.after`, HMAC-SHA256 signed, with retry/backoff (1m, 5m, 30m, 2h, 12h — up to 5 attempts).
- Manual competitor product mapping table — admin curates `bagisto_product_id → (competitor_name, competitor_url)` from each product's edit page.
- Per-token rate limit (default 60 req/min) and synchronous audit log on every write.

All admin pages live under `/admin/automation/*` and all API routes under `/api/automation/v1/*`. No existing Bagisto routes or schema are modified.

---

## Issuing an API token

1. Sign in to the admin panel.
2. Go to **`/admin/automation/tokens`**.
3. Fill in: name (any label, e.g. `n8n-agent`), one or more scopes, optional expiry datetime.
4. Click **Mint token**.
5. The plaintext token is shown **once** in a banner — copy it and store it in your agent's secrets store. After this page render, the plaintext is no longer accessible. The DB only stores a SHA-256 hash. The list view shows the last 4 characters for identification.
6. To revoke, click **Revoke** on the row. The token immediately stops working.

Token format: `bag_<60 random alphanumerics>` — 64 characters total.

Use the token by sending the header:

```
Authorization: Bearer bag_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

---

## Authentication, rate limiting, request IDs

- Every `/api/automation/v1/*` request requires `Authorization: Bearer <token>`. Missing → `401 missing_token`. Invalid / revoked / expired → `401 invalid_token`. Wrong scope → `403 insufficient_scope`.
- Per-token rate limit, 60 requests per rolling minute by default. Override at the env level if needed:
  ```env
  AUTOMATION_API_RATE_LIMIT_PER_MINUTE=120
  ```
  (Reads `config('automation.api.rate_limit_per_minute', 60)`. Set via `.env` or a published config.)
- `X-Request-ID` is round-tripped: send your own, or the server generates a UUID. The same value is echoed on every response — including errors. Recommended: log this value alongside agent-side requests for cross-system tracing.

---

## REST API reference

All requests must include `Authorization: Bearer <token>`. JSON request bodies must use `Content-Type: application/json`.

### `GET /api/automation/v1/products`

Scope: **`read`**.

Paginated catalog listing.

Query params:

| param | type | default | notes |
|---|---|---|---|
| `per_page` | int 1–100 | 50 | clamped to 100 |
| `page` | int | 1 | |
| `updated_since` | ISO-8601 datetime | — | filter `updated_at >= since` |

Response `200`:

```json
{
  "data": [
    {
      "id": 17,
      "sku": "TSHIRT-RED-L",
      "name": "Red T-Shirt (L)",
      "price": 1499.0,
      "stock_total": 42,
      "status": true,
      "updated_at": "2026-05-20T10:14:22+00:00",
      "competitor_mappings": [
        {
          "id": 3,
          "competitor_name": "daraz",
          "competitor_url": "https://daraz.pk/products/abc",
          "last_scraped_at": null
        }
      ]
    }
  ],
  "meta": { "current_page": 1, "last_page": 12, "per_page": 50, "total": 587 }
}
```

```bash
curl -sS -H "Authorization: Bearer $BAG_TOKEN" \
  "https://store.example.com/api/automation/v1/products?per_page=10&updated_since=2026-05-01T00:00:00Z"
```

---

### `GET /api/automation/v1/products/{productId}/mappings`

Scope: **`read`**.

Returns the competitor URLs an admin has linked to this product. Response shape:

```json
{
  "data": [
    {
      "id": 3,
      "product_id": 17,
      "competitor_name": "daraz",
      "competitor_url": "https://daraz.pk/products/abc",
      "last_scraped_at": null,
      "created_at": "2026-05-15T08:01:00+00:00"
    }
  ]
}
```

---

### `POST /api/automation/v1/products/{productId}/mappings`

Scope: **`write:staged`**.

Body:

```json
{
  "competitor_name": "daraz",
  "competitor_url": "https://daraz.pk/products/abc"
}
```

`201` returns the created mapping in the same shape as the `GET`.

`409 conflict` if `(product_id, competitor_url)` already exists.

---

### `DELETE /api/automation/v1/products/{productId}/mappings/{mappingId}`

Scope: **`write:staged`**. Returns `204`. `404 not_found` if the pair doesn't exist.

---

### `POST /api/automation/v1/pending-updates`

Scope: **`write:staged`**.

Stages a proposed price and/or stock change for admin review. The row is created with `status=pending`, capturing the current price and stock at insert time as the snapshot used later for drift detection.

Body:

```json
{
  "product_id": 17,
  "source_url": "https://daraz.pk/products/abc",
  "proposed_price": 1399.0,
  "proposed_stock": 38,
  "flags": ["price_drop_8pct"],
  "confidence": 0.92,
  "external_request_id": "agent-run-2026-05-24-abc123"
}
```

| field | type | required | notes |
|---|---|---|---|
| `product_id` | int | yes | must exist |
| `source_url` | URL string | yes | up to 2048 chars |
| `proposed_price` | number ≥ 0 | one-of | required if `proposed_stock` is absent |
| `proposed_stock` | int ≥ 0 | one-of | required if `proposed_price` is absent |
| `flags` | array of strings ≤ 64 chars | no | free-form tags shown in admin filter |
| `confidence` | number 0..1 | no | shown in admin list, sortable later |
| `external_request_id` | string ≤ 128 chars | no | idempotency key, scoped to the calling token — see note below |

**Idempotent retries.** Pass a stable `external_request_id` (any string you control —
e.g. a workflow run id) and the API will deduplicate retries from the same token:
the first call returns `201` with a new row, and any subsequent call with the same
`(token, external_request_id)` returns `200` with the original row instead of creating
a duplicate. The key is scoped per token, so two different tokens reusing the same
value get independent rows.

Two details worth knowing:

- **Empty string is not a key.** `""` is normalized to "no idempotency key" so
  multiple empty-string submissions don't collide on the unique index. Use a
  real value or omit the field.
- **Same key, different body, original wins.** If a retry sends the same
  `external_request_id` but a different `proposed_price` / `proposed_stock` /
  `source_url`, the API returns the *original* row unchanged. Treat the
  external_request_id as immutable; mint a new one if the proposal really changed.

Response `201`:

```json
{
  "data": {
    "id": 42,
    "product_id": 17,
    "submitted_by_token_id": 9,
    "source_url": "https://daraz.pk/products/abc",
    "proposed_price": "1399.0",
    "proposed_stock": 38,
    "current_price_snapshot": "1499.0",
    "current_stock_snapshot": 42,
    "flags": ["price_drop_8pct"],
    "confidence": "0.92",
    "status": "pending",
    "created_at": "2026-05-23T08:14:22+00:00"
  }
}
```

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $BAG_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"product_id":17,"source_url":"https://daraz.pk/products/abc","proposed_price":1399}' \
  https://store.example.com/api/automation/v1/pending-updates
```

The row is **not applied**. An admin must approve it via the admin UI for the underlying product to change.

---

## Admin UIs

| Path | Purpose |
|---|---|
| `/admin/automation/tokens` | Mint / list / revoke API tokens |
| `/admin/automation/webhooks` | Register / toggle / delete outbound webhooks; view recent deliveries |
| `/admin/automation/pending-updates` | Review staged updates; approve or reject |

The pending-updates page lists rows with their proposed vs current values, the source URL, agent-set flags, and confidence. Filter by status, product id, or flag. **Approve** runs the change through `ProductRepository::update()` (price) and `ProductInventoryRepository::saveInventories()` (stock) — exactly the same paths the admin UI uses, so events fire and caches invalidate normally. **Reject** marks the row terminal with an optional review note.

### Role permissions

The package registers ACL nodes so admin roles with `permission_type = 'custom'`
can grant access at the page or action level. Grant a role any of the parent
keys to expose the corresponding admin page, then add the child keys to allow
the destructive actions.

| Key | Grants |
|---|---|
| `automation` | Top-level menu group (required to see anything else) |
| `automation.pending-updates` | View the pending-updates list |
| `automation.pending-updates.approve` | Approve a staged update |
| `automation.pending-updates.reject` | Reject a staged update |
| `automation.tokens` | View the tokens page |
| `automation.tokens.create` | Mint new API tokens |
| `automation.tokens.revoke` | Revoke API tokens |
| `automation.webhooks` | View the webhooks page |
| `automation.webhooks.create` | Register new outbound webhooks |
| `automation.webhooks.toggle` | Enable / disable an existing webhook |
| `automation.webhooks.delete` | Delete an existing webhook |
| `automation.products-mappings` | Attach / detach competitor URLs on product edit pages |

Roles with `permission_type = 'all'` get every node automatically — that's the
default for the seeded super-admin.

**Drift check on approve**: if the current price or stock has moved more than 10% since the row's snapshot was captured, the apply fails (`status=failed`, `error_message` populated). The product is not changed. Stage a fresh row to retry.

A "Competitor mappings" panel is injected into each product's edit page (no core edits — via Bagisto's `bagisto.admin.catalog.product.edit.form.after` view-render event).

---

## Outbound webhooks

### Registering a webhook

1. Go to **`/admin/automation/webhooks`**.
2. Provide a name, target HTTPS URL, and event.
3. The generated secret (64 hex chars) is shown **once** in a banner. Copy it to your receiver's environment — the DB only stores it encrypted, and there is no way to retrieve it again after this page render. To rotate, delete the webhook and create a new one.

Supported events:

| Event | Fired when |
|---|---|
| `product.updated` | Any product update completes (Bagisto fires `catalog.product.update.after`) |

### Delivery payload

The body sent to your target URL:

```json
{
  "event": "product.updated",
  "product_id": 17,
  "sku": "TSHIRT-RED-L",
  "price": 1399.0,
  "stock_total": 38,
  "occurred_at": "2026-05-23T08:14:22+00:00"
}
```

Headers:

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-Bagisto-Signature` | `sha256=<hex>` — HMAC-SHA256 of `"{timestamp}.{raw body}"`, using the webhook's secret |
| `X-Bagisto-Timestamp` | Unix timestamp (seconds) of dispatch. Receivers MUST reject if `\|now - timestamp\| > 300s` to block replays |
| `X-Bagisto-Event` | the event name |
| `X-Bagisto-Delivery` | the delivery row's id (useful in support tickets) |

### Payload envelope

All payloads carry a `version` string so receivers can detect schema drift without
parsing. The current version is `"v1"`; it is bumped only on breaking changes to
key names or value types. New optional keys do not bump the version.

```json
{
  "version": "v1",
  "event": "product.updated",
  "product_id": 42,
  "sku": "ABC-123",
  "price": 4999.0,
  "stock_total": 7,
  "occurred_at": "2026-05-24T12:34:56+00:00"
}
```

### Verifying the signature (Node.js example)

```javascript
const crypto = require('crypto');

const SKEW_SECONDS = 300; // reject anything older than 5 minutes

function verify(rawBody, signatureHeader, timestampHeader, secret) {
  // 1) Reject if the dispatch timestamp is missing, malformed, or stale.
  const ts = parseInt(timestampHeader, 10);
  if (!Number.isFinite(ts)) return false;
  if (Math.abs(Math.floor(Date.now() / 1000) - ts) > SKEW_SECONDS) return false;

  // 2) Recompute HMAC over "{timestamp}.{raw body}" — the timestamp is part
  //    of the signed material, so an attacker can't reuse an old signature
  //    with a fresh timestamp header.
  const expected = 'sha256=' + crypto
    .createHmac('sha256', secret)
    .update(`${ts}.${rawBody}`)
    .digest('hex');

  // 3) Constant-time compare to avoid timing leaks.
  const a = Buffer.from(signatureHeader);
  const b = Buffer.from(expected);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
```

In n8n: use a Webhook trigger node, set "Response Mode" to "Last Node", and add a Function node that runs the verify snippet against `items[0].headers['x-bagisto-signature']`, `items[0].headers['x-bagisto-timestamp']`, and `items[0].body` before doing any work. Reject the request if `verify` returns false.

### Retry and backoff

Non-2xx responses (or connection errors) schedule a retry. Schedule per attempt:

| Attempt | Delay before retry |
|---|---|
| 1 → 2 | 1 minute |
| 2 → 3 | 5 minutes |
| 3 → 4 | 30 minutes |
| 4 → 5 | 2 hours |
| 5 → exhausted | 12 hours after attempt 5; then no further retry |

`automation_webhook_deliveries` always has the row with the latest `response_status`, `response_body` (first 2KB), `attempt`, `next_retry_at`, `succeeded_at`. Recent rows are visible at the bottom of the admin webhooks page.

**Queue worker note:** in default Sail dev, jobs run inline because `QUEUE_CONNECTION=sync`. In production, run `php artisan queue:work` (or supervisor) so retries actually defer.

---

## Error response shape and codes

Every error response uses the shape:

```json
{
  "error": {
    "code": "snake_case_code",
    "message": "Human-readable message.",
    "details": { /* optional, present for validation errors */ }
  }
}
```

| HTTP | Code | Meaning |
|---|---|---|
| 400 | `invalid_json` | Body had `Content-Type: application/json` but was not parseable |
| 401 | `missing_token` | `Authorization: Bearer …` header absent |
| 401 | `invalid_token` | Token unknown, revoked, or expired |
| 403 | `insufficient_scope` | Token authenticated but missing the required scope |
| 404 | `not_found` | Resource (product, mapping) does not exist |
| 409 | `conflict` | Duplicate mapping (`product_id` + `competitor_url`) |
| 422 | `invalid_parameter` | Field-level validation failed; see `details` |
| 429 | `rate_limited` | Per-token rate limit exceeded (default 60 req/min) |

---

## Schema overview

New tables introduced by this package (no existing tables modified):

| Table | Purpose |
|---|---|
| `api_admin_tokens` | Bearer tokens (SHA-256 hashed) with scopes, expiry, last-used |
| `competitor_product_mappings` | `bagisto_product_id → (competitor_name, competitor_url)` |
| `pending_product_updates` | Staged price/stock proposals + status + snapshots |
| `automation_audit_log` | Per-write log of `token_id, endpoint, payload_hash, ip, status` |
| `automation_webhooks` | Registered outbound endpoints, encrypted secret |
| `automation_webhook_deliveries` | Per-attempt delivery history with response status and signature |

Writes to product price and stock always go through Bagisto's existing repositories (`ProductRepository::update()`, `ProductInventoryRepository::saveInventories()`) so events and cache invalidation behave identically to the admin UI.

---

## Non-goals

Out of scope for this package:

- The n8n agent, Selenium workflows, and any LLM prompts for parsing or anomaly detection.
- Order, customer, and shipping endpoints. Only product / inventory / mapping / pending-update.
- Bulk import (use Bagisto's `Webkul/DataTransfer` for CSV/XLSX).
- GraphQL.
- OAuth / SSO for admins.
- Auto-apply rule engine. All agent updates land in the review queue.
