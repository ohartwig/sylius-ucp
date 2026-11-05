<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The two error shapes of the UCP REST binding (overview, "Error Handling").
 *
 * - error(): the UCP error response (`common/types/error_response.json`) — a
 *   handler ran and reports an outcome: invalid input, a product not found, no
 *   shared capability.
 * - transportError(): the flat body of discovery and version failures, which
 *   the spec's REST binding gives as `{code, content, continue_url}` next to
 *   the HTTP status (400, 422, 424, 503).
 *
 * Every response says it must not be stored: results depend on the caller's
 * profile and on stock and prices that change.
 */
final class UcpResponses
{
    private const ENCODING = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($body, $status, $headers + ['Cache-Control' => 'no-store']);
        $response->setEncodingOptions(self::ENCODING);

        return $response;
    }

    public static function error(
        int $status,
        string $version,
        ?string $capability,
        string $code,
        string $content,
        ?string $path = null,
        ?string $continueUrl = null,
    ): JsonResponse {
        $message = ['type' => 'error', 'code' => $code, 'content' => $content, 'severity' => 'unrecoverable'];
        if (null !== $path) {
            $message['path'] = $path;
        }

        $body = [
            'ucp' => [
                'version' => $version,
                'status' => 'error',
                'capabilities' => null === $capability ? new \stdClass() : [$capability => [['version' => $version]]],
            ],
            'messages' => [$message],
        ];
        if (null !== $continueUrl) {
            $body['continue_url'] = $continueUrl;
        }

        return self::json($body, $status);
    }

    public static function transportError(int $status, string $code, string $content, string $continueUrl, ?int $retryAfter = null): JsonResponse
    {
        return self::json(
            ['code' => $code, 'content' => $content, 'continue_url' => $continueUrl],
            $status,
            null === $retryAfter ? [] : ['Retry-After' => (string) $retryAfter],
        );
    }
}
