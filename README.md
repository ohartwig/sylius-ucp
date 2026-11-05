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
| `/.well-known/ucp` | GET, HEAD | UCP discovery profile |
| `/ucp/v1/catalog?q=<term>` | GET | Product search by name (at most 200 results) |
| `/ucp/v1/checkout-sessions` | POST | Creates a checkout session backed by a Sylius cart holding the given line items (opt-in, see below) |

The profile is also announced in `llms.txt` and `/.well-known/api-catalog`
when the corresponding `koh` bundles are installed.

This is a first pass, not a complete UCP implementation: cart updates, order
management, identity linking and payment are not implemented, and request
signing is not offered yet (`signing_keys` is empty).

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
curl -s 'https://shop.example.com/ucp/v1/catalog?q=shirt&localeCode=en_US'
```

## Configure

Container parameters, all optional:

```yaml
# config/services.yaml
parameters:
    koh_sylius_ucp.version: '2026-04-08'      # UCP version announced everywhere
    koh_sylius_ucp.endpoint_base: '/ucp/v1'   # base URL announced in the profile
    koh_sylius_ucp.checkout: false            # serve and advertise the checkout-session route
    koh_sylius_ucp.checkout_limit: 10         # checkout sessions per client address …
    koh_sylius_ucp.checkout_interval: '1 minute'  # … per sliding window
```

`endpoint_base` changes what the profile announces, not where the routes
listen; they stay under `/ucp/v1`.

### Checkout sessions are off by default

The session route writes a Sylius cart for an anonymous caller. With
`koh_sylius_ucp.checkout: false` (the default) it answers `404`, and neither
the profile, the OpenAPI document nor the `ucp.capabilities` echo mentions
checkout. Set it from the same decision as `koh_acp.checkout` when both bundles
are installed.

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

`GET /.well-known/ucp` with checkout enabled (shortened — `capabilities` and
`services` are repeated at the top level for discovery scanners that read that
shape; with checkout off, the checkout capability and endpoint are absent):

```json
{
  "ucp": {
    "version": "2026-04-08",
    "services": {
      "dev.ucp.shopping": [{
        "version": "2026-04-08",
        "spec": "https://ucp.dev/2026-04-08/specification/overview",
        "transport": "rest",
        "endpoint": "https://shop.example.com/ucp/v1",
        "schema": "https://shop.example.com/ucp/v1/openapi.json"
      }]
    },
    "capabilities": {
      "dev.ucp.shopping.catalog": [{ "version": "2026-04-08", "spec": "https://ucp.dev/2026-04-08/specification/catalog", "schema": "https://shop.example.com/ucp/v1/openapi.json" }],
      "dev.ucp.shopping.checkout": [{ "version": "2026-04-08", "spec": "https://ucp.dev/2026-04-08/specification/checkout", "schema": "https://shop.example.com/ucp/v1/openapi.json" }]
    }
  },
  "signing_keys": [],
  "protocol_version": "2026-04-08",
  "endpoints": {
    "catalog": "https://shop.example.com/ucp/v1/catalog",
    "checkout": "https://shop.example.com/ucp/v1/checkout-sessions"
  }
}
```

`GET /ucp/v1/catalog?q=shirt`:

```json
{
  "products": [
    { "code": "T_SHIRT", "name": "T-shirt", "slug": "t-shirt" }
  ],
  "ucp": {
    "version": "2026-04-08",
    "capabilities": ["dev.ucp.shopping.catalog"],
    "agent": ""
  }
}
```

A search without `q` answers `400`: an empty term would match every product.
`agent` echoes the `UCP-Agent` request header.

`POST /ucp/v1/checkout-sessions` (checkout enabled) with
`{"line_items": [{"item": {"id": "<variant code>"}, "quantity": 1}]}` answers
`201` with
`{"checkout_session": {"id": "<order token>", "status": "open", "currency": "EUR", "total": 1990, "line_items": 1}, "ucp": {…}}`.

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
