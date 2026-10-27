<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Vaulter\Attributes\Internal;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * Load-stand tier label (`S0`/`S`/`M`/`L`) from host middleware.
 * Package seams read only this context — never load env/config directly.
 */
#[Spi(SpiKind::Call)]
final class LoadTier
{
    #[Internal]
    public const string REQUEST_ATTRIBUTE = 'vaulter_load_tier';

    /** @var list<string> */
    private const array ALLOWED = ['S0', 'S', 'M', 'L'];

    public static function label(): string
    {
        $tier = self::raw();

        return $tier ?? 'S0';
    }

    #[Internal]
    public static function set(?string $tier): void
    {
        $normalized = self::normalize($tier);

        Context::add(self::REQUEST_ATTRIBUTE, $normalized);

        if (app()->bound('request')) {
            app(Request::class)->attributes->set(self::REQUEST_ATTRIBUTE, $normalized);
        }
    }

    private static function raw(): ?string
    {
        if (app()->bound('request')) {
            $fromRequest = app(Request::class)->attributes->get(self::REQUEST_ATTRIBUTE);

            if (is_string($fromRequest) && $fromRequest !== '') {
                return self::normalize($fromRequest);
            }
        }

        $fromContext = Context::get(self::REQUEST_ATTRIBUTE);

        if (is_string($fromContext) && $fromContext !== '') {
            return self::normalize($fromContext);
        }

        return null;
    }

    private static function normalize(?string $tier): ?string
    {
        if ($tier === null || $tier === '') {
            return null;
        }

        $upper = strtoupper($tier);

        return in_array($upper, self::ALLOWED, true) ? $upper : null;
    }
}
