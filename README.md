<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# sylius-ucp

> UCP adapter (Universal Commerce Protocol, Google/Shopify) for Sylius

Adapter for the Universal Commerce Protocol (UCP, ucp.dev) — the open agentic-commerce standard — against Sylius: the business profile, capability negotiation, catalog search and lookup over REST, and the permalink handoff into the buyer's cart.

## What it serves

| Route | Method | Purpose |
|---|---|---|
| `/.well-known/ucp` | GET, HEAD | UCP business profile (UCP 2026-08-25) |
| `/ucp/v1/catalog/search` | POST | `dev.ucp.shopping.catalog.search` |
| `/ucp/v1/catalog/lookup` | POST | `dev.ucp.shopping.catalog.lookup`, batch |
| `/ucp/v1/catalog/product` | POST | `dev.ucp.shopping.catalog.lookup`, one product in detail |
| `/ucp/buy/{items}` | GET | `dev.ucp.shopping.permalink`: `303` into the buyer's cart |
| `/ucp/v1/openapi.json` | GET, HEAD | OpenAPI description of the catalog routes |

The profile is also announced in `llms.txt` and `/.well-known/api-catalog`
when the corresponding `koh` bundles are installed.

The profile declares exactly these three capabilities. Checkout is not
declared and not served: a UCP checkout is completed by the platform with a
payment instrument through a payment handler the business declares, and a
shop selling age-restricted goods takes no instrument from an agent — the
purchase runs in the buyer's browser, behind the shop's own gates and payment
provider. The permalink is the capability UCP defines for that handoff.
`payment_handlers` is present and empty, as the schema requires. The shop
signs nothing, so the profile publishes no `keys`.

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

The catalog routes under `/ucp/v1/` need no session; the permalink route
`/ucp/buy` does — it fills the buyer's cart — so it must stay in the shop
firewall.

## Configure

```yaml
# config/packages/koh_sylius_ucp.yaml
koh_sylius_ucp:
    age_restriction: 18                 # shop-wide minimum age; default null
    permalink:
        enabled: true                   # serve /ucp/buy and declare the permalink
        cart_route: sylius_shop_cart_summary
        fallback_route: sylius_shop_homepage
```

Container parameters, all optional, for what the configuration does not
cover: `koh_sylius_ucp.version` (`2026-08-25`), `koh_sylius_ucp.endpoint_base`
(`/ucp/v1`; changes what the profile announces, not where the routes listen),
`koh_sylius_ucp.profile_fetch_limit` (`20`) and
`koh_sylius_ucp.profile_fetch_interval` (`1 minute`).

## Negotiation

Every catalog request must name its platform profile in `UCP-Agent`, an
RFC 8941 dictionary:

```http
POST /ucp/v1/catalog/search
UCP-Agent: profile="https://platform.example/.well-known/ucp"
Content-Type: application/json

{"query": "robusto"}
```

The shop fetches and validates that profile, then checks the protocol version
and the capability before it runs the operation:

| Case | Answer |
|---|---|
| no header, not a dictionary, no `profile` string, not https, credentials, a host resolving to a non-public address | `400` `invalid_profile_url` |
| fetch failed: timeout, non-2xx, redirect | `424` `profile_unreachable` |
| not JSON, larger than 256 KiB, not a UCP profile | `422` `profile_malformed` |
| the shop's budget for unknown profiles is spent | `503` `profile_unreachable`, `Retry-After` |
| `ucp.version` other than 2026-08-25 | `422` `version_unsupported` |
| the operation's capability not declared at 2026-08-25 | `200`, UCP error response `capabilities_incompatible` |

Transport errors carry `{code, content, continue_url}`; `continue_url` is the
storefront. Capabilities whose `schema` URL fails UCP's authority binding are
ignored.

The URL comes from an anonymous caller, so the fetch is fenced: https only,
no redirects, every resolved address must be public (RFC 6890 ranges,
including 100.64/10, 169.254/16, NAT64 and IPv4-mapped IPv6) and the
connection is pinned to the checked address; no proxy; 2 s to connect, 4 s in
total; the body is cut at 256 KiB. Profiles are cached in `cache.app` for five
minutes and failures for one; at most 20 uncached fetches a minute across all
workers (rate limiter on `cache.app`). Nothing is kept in service state, so
FrankenPHP's worker mode needs no reset.

## Catalog

- **Search** matches enabled products of the channel by name in the content
  language (`context.language`, else `Accept-Language`, else the channel
  default). A request needs `query` or a filter. `filters.categories` matches
  taxon codes (the `categories[].value` the results carry);
  `filters.price` (minor units) applies in the channel currency — a price in
  another `context.currency` is ignored and said so. Unknown filters are
  reported as a warning. 10 products a page by default, at most 50, at most
  200 per search.
- **Lookup** takes up to 50 product or variant codes. A variant code resolves
  to that variant (`match: exact`), a product code to its first available
  variant (`match: featured`); every returned variant lists its `inputs`.
  Unknown codes come back as `not_found` info messages, more than 50 as `400`
  `request_too_large`.
- **Get product** returns one product with its option axes, `selected` and
  per-value `available`/`exists` signals; a variant code features that
  variant, `selected` and `preferences` narrow and relax as the spec defines.
  An unknown product is `200` with `not_found`.
- Ids are Sylius codes. Variants without a price in the channel are left out,
  and so is a product without any.
- With `age_restriction` set, every response carrying products has a warning
  `age_restricted` with `presentation: disclosure` pointing at them. UCP has no
  age field on products; this is the place the schema offers.

Invalid requests answer `400` with the UCP error response
(`ucp.status: "error"`, `invalid_request`, `path` naming the member).
Responses are `Cache-Control: no-store`.

## Permalink

`GET /ucp/buy/{items}?continue_to=/…` where `items` is
`id:quantity[,id:quantity…]` (ids raw if they match `[A-Za-z0-9._-]+`,
otherwise `~` + base64url). The buyer opens it; each item goes into the
buyer's own cart through `sylius.cart_item_add`, the event the shop's
add-to-cart button fires, so the shop's gates (age confirmation, quantity
rules) run as if the buyer had clicked. If a gate refuses, nothing is added and
the buyer lands on the fallback route with a notice; the same link works once
the gate is satisfied. The answer is always `303` with `Cache-Control:
no-store` and `Referrer-Policy: no-referrer`; loading the link places no order.

- `continue_to` is validated as the spec says (one leading slash, no scheme,
  backslash, whitespace or control character, dot segments removed) and
  re-encoded; anything else falls back to the cart.
- Variant codes resolve directly; a product code only if the product has one
  enabled variant. Unknown items are skipped and the buyer told.
- At most 20 items, quantities capped at 99. A malformed link, two
  `continue_to` or two writes to one field path change nothing.
- UCP field paths (`buyer/…`, `context/…`) are consumed and not applied —
  the buyer enters them in the shop's checkout. `attribution/x` travels on as
  `x`; other parameters pass through unless their name looks like a credential.
- Opening the same link twice in a session adds once; a `HEAD` request changes
  nothing.

## Upgrading

- Catalog requests without a usable `UCP-Agent` are refused (UCP makes
  negotiation a MUST).
- The `/ucp/v1/checkout-sessions` route and the parameters
  `koh_sylius_ucp.checkout`, `checkout_limit` and `checkout_interval` are
  gone. The route was opt-in, never declared, and could not complete a
  purchase; it also wrote carts past the add-to-cart event the shop's age gate
  listens on. The permalink replaces it.
- The profile now also declares `dev.ucp.shopping.catalog.lookup` and
  `dev.ucp.shopping.permalink`.

## Decommission

Remove the `composer require`, the `config/bundles.php` entry, the route import
and the configuration. Carts filled through permalinks are the buyers' own
Sylius carts; nothing else is stored.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).

---

Part of the **AI Ready Platform** (content commerce: Sylius × TYPO3). Status: basic skeleton.

<!-- release: enable initial release -->
