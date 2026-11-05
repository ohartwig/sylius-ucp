<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Exception;

/**
 * A checkout-session request the bundle refuses before anything is persisted.
 * The controller turns it into a 400; the message is safe to show the client.
 */
final class InvalidCheckoutRequestException extends \InvalidArgumentException
{
}
