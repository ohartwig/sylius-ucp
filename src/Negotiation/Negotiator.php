<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Negotiation;

use Koh\SyliusUcp\Http\UcpResponses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Request-time negotiation (overview, "Negotiation Protocol", "Request-Time
 * Validation", "Intersection Algorithm").
 *
 * Every UCP request names its platform profile in `UCP-Agent`; the business
 * MUST fetch and validate it, check the protocol version and intersect
 * capabilities before it runs the operation:
 *
 *   no or unparsable header, URL not usable      400 invalid_profile_url
 *   fetch failed (timeout, non-2xx, redirect)    424 profile_unreachable
 *   not JSON, too large, not a UCP profile       422 profile_malformed
 *   discovery budget spent                       503 + Retry-After
 *   other protocol version                       422 version_unsupported
 *   operation's capability not shared            200 capabilities_incompatible
 *
 * The first five are transport errors with the flat body; the last is a UCP
 * response. Each carries `continue_url`, the storefront fallback the spec asks
 * for. This shop publishes one version and no `supported_versions`, so the
 * version check and the capability intersection are both equality checks
 * against that one version.
 */
final class Negotiator
{
    public const HEADER = 'UCP-Agent';

    public function __construct(
        private readonly PlatformProfileFetcher $fetcher,
        private readonly string $version = '2026-08-25',
    ) {
    }

    /**
     * Null when the request may proceed; otherwise the response to send.
     */
    public function negotiate(Request $request, string $capability, string $continueUrl): ?JsonResponse
    {
        $header = $request->headers->get(self::HEADER);
        if (null === $header || '' === trim($header)) {
            return UcpResponses::transportError(400, 'invalid_profile_url', 'The UCP-Agent header with the platform profile URL is required, e.g. UCP-Agent: profile="https://platform.example/.well-known/ucp".', $continueUrl);
        }

        $url = UcpAgentHeader::profile($header);
        if (null === $url) {
            return UcpResponses::transportError(400, 'invalid_profile_url', 'UCP-Agent must be an RFC 8941 dictionary with the profile URL as a string member: profile="https://…".', $continueUrl);
        }

        $profile = $this->fetcher->fetch($url);
        if ($profile instanceof ProfileFetchError) {
            return UcpResponses::transportError($profile->status, $profile->code, $profile->message, $continueUrl, $profile->retryAfter);
        }

        if ($this->version !== $profile->version) {
            return UcpResponses::transportError(
                422,
                'version_unsupported',
                \sprintf('Protocol version %s is not supported. This business implements version %s.', $profile->version, $this->version),
                $continueUrl,
            );
        }

        if (!$profile->supports($capability, $this->version)) {
            return UcpResponses::error(
                200,
                $this->version,
                null,
                'capabilities_incompatible',
                \sprintf('The platform profile does not declare %s at version %s.', $capability, $this->version),
                null,
                $continueUrl,
            );
        }

        return null;
    }
}
