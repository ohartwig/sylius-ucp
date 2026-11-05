<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Catalog;

/**
 * The shop-wide minimum age (`koh_sylius_ucp.age_restriction`, the value
 * koh/sylius-acp and koh/sylius-a2a carry as `age_restriction`).
 *
 * UCP has no age field on products or variants. What it does define is the
 * warning message, whose well-known codes include `age_restricted`, and the
 * `disclosure` presentation: the platform MUST show the content next to the
 * component the path names and MUST NOT hide or dismiss it. So every catalog
 * response that carries products carries this warning, pointing at them.
 * Nothing about the purchase changes here: the buyer's age is confirmed by the
 * shop itself, in the browser, before anything enters a cart.
 */
final readonly class AgeRestriction
{
    public const CODE = 'age_restricted';

    public function __construct(public ?int $minimumAge = null)
    {
    }

    /**
     * @return array{type: string, code: string, path: string, presentation: string, content: string}|null
     */
    public function message(string $path): ?array
    {
        if (null === $this->minimumAge || $this->minimumAge < 1) {
            return null;
        }

        return [
            'type' => 'warning',
            'code' => self::CODE,
            'path' => $path,
            'presentation' => 'disclosure',
            'content' => \sprintf(
                'Age-restricted goods: sold only to buyers aged %d or over. The shop confirms the buyer\'s age before anything goes into the cart; an agent cannot buy on the buyer\'s behalf.',
                $this->minimumAge,
            ),
        ];
    }
}
