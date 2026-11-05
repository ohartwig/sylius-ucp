<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# sylius-ucp

> UCP adapter (Universal Commerce Protocol, Google/Shopify) for Sylius

Adapter for the Universal Commerce Protocol (UCP) — open agentic-commerce standard by Google + Shopify (ucp.dev, Jan 2026; REST/JSON-RPC/MCP/A2A/AP2) — against Sylius: discovery, cart, checkout, order mgmt, identity/payment. No Sylius UCP adapter exists publicly yet; this is ours.

## What it serves

| Route | Method | Purpose |
|---|---|---|
| `/.well-known/ucp` | GET, HEAD | UCP business profile (UCP 2026-08-25) |
| `/ucp/v1/catalog/search` | POST | `dev.ucp.shopping.catalog.search`: product search by name |
| `/ucp/v1/openapi.json` | GET, HEAD | OpenAPI description of the routes above |
| `/ucp/v1/checkout-sessions` | POST | Creates a Sylius cart holding the given line items (opt-in, see below; not the UCP checkout capability) |

The profile is also announced in `llms.txt` and `/.well-known/api-catalog`
when the corresponding `koh` bundles are installed.

The profile declares exactly one capability, catalog search, because it is
the only one the shop implements as the specification defines it. Catalog
lookup (`/catalog/lookup` and `/catalog/product`), checkout, cart, order,
identity linking and payment handlers are not implemented and therefore not
declared; `payment_handlers` is present and empty, as the schema requires.
The shop signs nothing yet, so the profile publishes no `keys`.

## Install

Requires PHP 8.3 or later and Sylius 2.2 or 2.3.

```bash
composer require koh/sylius-ucp
```

Register the bundle and the shared contracts bundle it builds on:

```php
// config/bundles.php
return [
    // …
    Koh\SyliusAgentContracts\KohSyliusAgentContractsBundle::class => ['all' => true],
    Koh\SyliusUcp\KohSyliusUcpBundle::class => ['all' => true],
];
```

Import the routes:

```yaml
# config/routes/koh_agent.yaml
koh_ucp:
    resource: '@KohSyliusUcpBundle/config/routes.php'
```

Clear the cache and check the profile and a search:

```bash
bin/console cache:clear
curl -s https://shop.example.com/.well-known/ucp
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"query": "shirt"}' https://shop.example.com/ucp/v1/catalog/search
```

## Configure

Container parameters, all optional:

```yaml
# config/services.yaml
parameters:
    koh_sylius_ucp.version: '2026-08-25'      # UCP version announced everywhere
    koh_sylius_ucp.endpoint_base: '/ucp/v1'   # base URL announced in the profile
    koh_sylius_ucp.checkout: false            # serve and advertise the checkout-session route
    koh_sylius_ucp.checkout_limit: 10         # checkout sessions per client address …
    koh_sylius_ucp.checkout_interval: '1 minute'  # … per sliding window
```

`endpoint_base` changes what the profile announces, not where the routes
listen; they stay under `/ucp/v1`.

### Checkout sessions are off by default

The session route writes a Sylius cart for an anonymous caller. With
`koh_sylius_ucp.checkout: false` (the default) it answers `404` and the
OpenAPI document does not describe it. The UCP profile never declares
checkout, whatever the switch says: the route creates a cart but offers no
update, complete or cancel, so it is not the UCP checkout capability. Set it
from the same decision as `koh_acp.checkout` when both bundles are installed.

When it is on:

- a request without a JSON body, or without a non-empty `line_items` list,
  answers `400` and persists nothing; so does an unknown, disabled or
  out-of-channel variant. At most 50 line items, quantity 1 to 99;
- each client address gets `checkout_limit` sessions per `checkout_interval`
  (sliding window, stored in `cache.app`); the request past the limit answers
  `429` with `Retry-After`. The key is `Request::getClientIp()`, so set
  `framework.trusted_proxies` for the proxy in front of the shop, or every
  client shares one bucket;
- the session is bound to the session cookie the response sets. A client that
  does not keep the cookie creates a new cart per call; schedule
  `sylius:remove-expired-carts` so abandoned ones are removed.

## Output

`GET /.well-known/ucp` (shortened — `services` and `capabilities` are repeated
at the top level, next to `protocol_version` and `endpoints`, for discovery
scanners that read that shape; the nested `ucp` object is the authority):

```json
{
  "ucp": {
    "version": "2026-08-25",
    "services": {
      "dev.ucp.shopping": [{
        "version": "2026-08-25",
        "spec": "https://ucp.dev/2026-08-25/specification/overview",
        "transport": "rest",
        "endpoint": "https://shop.example.com/ucp/v1",
        "schema": "https://ucp.dev/2026-08-25/services/shopping/rest.openapi.json"
      }]
    },
    "capabilities": {
      "dev.ucp.shopping.catalog.search": [{
        "version": "2026-08-25",
        "spec": "https://ucp.dev/2026-08-25/specification/shopping/catalog/search",
        "schema": "https://ucp.dev/2026-08-25/schemas/shopping/catalog_search.json"
      }]
    },
    "payment_handlers": {}
  },
  "protocol_version": "2026-08-25",
  "endpoints": { "catalog_search": "https://shop.example.com/ucp/v1/catalog/search" }
}
```

The `schema` URLs point at ucp.dev on purpose: a platform accepts a
`dev.ucp.*` entity only when its schema is served from ucp.dev.

`POST /ucp/v1/catalog/search` with `{"query": "shirt", "pagination": {"limit": 2}}`
answers the UCP search response (shortened):

```json
{
  "ucp": {
    "version": "2026-08-25",
    "status": "success",
    "capabilities": { "dev.ucp.shopping.catalog.search": [{ "version": "2026-08-25" }] }
  },
  "products": [{
    "id": "T_SHIRT",
    "handle": "t-shirt",
    "title": "T-shirt",
    "description": { "plain": "Organic cotton." },
    "url": "https://shop.example.com/en_US/products/t-shirt",
    "price_range": { "min": { "amount": 1990, "currency": "EUR" }, "max": { "amount": 1990, "currency": "EUR" } },
    "variants": [{
      "id": "T_SHIRT_M",
      "sku": "T_SHIRT_M",
      "title": "M",
      "description": { "plain": "Organic cotton." },
      "price": { "amount": 1990, "currency": "EUR" },
      "availability": { "available": true, "status": "in_stock" }
    }]
  }],
  "pagination": { "has_next_page": true, "cursor": "bzoy" }
}
```

- Products are the enabled products of the current channel whose name in the
  current locale contains `query`. Variant ids are variant codes, the value the
  checkout-session route takes as `item.id`. Variants without a price in the
  channel are left out, and so is a product without any priced variant.
- `query` is required; a request without it, with malformed JSON, or with an
  invalid `pagination.limit` or `cursor` answers `400` with a UCP error
  response (`ucp.status: "error"`, code `invalid_request`).
- A page holds 10 products by default and at most 50; paging through one term
  stops after 200 products.
- `filters` and `context` are not applied; the response then carries a
  `warning` message saying so.

`POST /ucp/v1/checkout-sessions` (checkout enabled) with
`{"line_items": [{"item": {"id": "<variant code>"}, "quantity": 1}]}` answers
`201` with
`{"checkout_session": {"id": "<order token>", "status": "open", "currency": "EUR", "total": 1990, "line_items": 1}, "ucp": {"version": "2026-08-25"}}`.

## Upgrading to UCP 2026-08-25

- The profile declares UCP 2026-08-25. Installations that pin
  `koh_sylius_ucp.version` to `2026-04-08` should drop the parameter.
- `GET /ucp/v1/catalog?q=` is gone; it was never a UCP operation. Use
  `POST /ucp/v1/catalog/search`.
- `dev.ucp.shopping.catalog` (a name no UCP version defines) and
  `dev.ucp.shopping.checkout` are no longer declared; `signing_keys` is no
  longer emitted.

## Decommission

Remove the `composer require`, the `config/bundles.php` entry, the route import
and the parameters. Carts created through checkout sessions are ordinary
Sylius carts; `bin/console sylius:remove-expired-carts` removes them like any other.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).

---

Part of the **AI Ready Platform** (content commerce: Sylius × TYPO3). Status: basic skeleton.

<!-- release: enable initial release -->
