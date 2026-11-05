<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Negotiation;

/**
 * Why a platform profile could not be used, in UCP's negotiation error terms:
 * `invalid_profile_url` (400), `profile_unreachable` (424),
 * `profile_malformed` (422) — or 503 with Retry-After when the shop's own
 * discovery budget is spent.
 */
final readonly class ProfileFetchError
{
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
        public ?int $retryAfter = null,
    ) {
    }
}
