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
| `/ucp/v1/checkout-sessions` | POST | Creates a checkout session backed by a Sylius cart |

The profile is also announced in `llms.txt` and `/.well-known/api-catalog`
when the corresponding `koh` bundles are installed.

This is a first pass, not a complete UCP implementation: cart updates, order
management, identity linking and payment are not implemented, and request
signing is not offered yet (`signing_keys` is empty).

## Install

Requires PHP 8.5 and Sylius 2.2 or 2.3.

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

Two container parameters, both optional:

```yaml
# config/services.yaml
parameters:
    koh_sylius_ucp.version: '2026-04-08'      # UCP version announced everywhere
    koh_sylius_ucp.endpoint_base: '/ucp/v1'   # base URL announced in the profile
```

`endpoint_base` changes what the profile announces, not where the routes
listen; they stay under `/ucp/v1`.

## Output

`GET /.well-known/ucp` (shortened — `capabilities` and `services` are
repeated at the top level for discovery scanners that read that shape):

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
    "checkout": "https://shop.example.com/ucp/v1/checkout_sessions"
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
    "capabilities": ["dev.ucp.shopping.catalog", "dev.ucp.shopping.checkout"],
    "agent": ""
  }
}
```

Known gap: `endpoints.checkout` and the `openapi.json` schema URL are announced
but not served yet — the session route is `/ucp/v1/checkout-sessions`.

A search without `q` answers `400`: an empty term would match every product.
`agent` echoes the `UCP-Agent` request header.

`POST /ucp/v1/checkout-sessions` answers `201` with
`{"checkout_session": {"id": "<order token>", "status": "open", "currency": "EUR", "total": 0}, "ucp": {…}}`.

## Decommission

Remove the `composer require`, the `config/bundles.php` entry, the route import
and the two parameters. Carts created through checkout sessions are ordinary
Sylius carts; `bin/console sylius:remove-expired-carts` removes them like any other.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).

---

Part of the **AI Ready Platform** (content commerce: Sylius × TYPO3). Status: basic skeleton.

<!-- release: enable initial release -->
