<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Exception;

/**
 * A catalog request this shop cannot run: answered with HTTP 400 and the UCP
 * error response, the message pointing at the offending member.
 */
final class InvalidUcpRequestException extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly string $path = '$')
    {
        parent::__construct($message);
    }
}
