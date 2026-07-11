<?php

declare(strict_types=1);

namespace Vaulter\Documents\Enums\Export;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

enum ExportFormat: string
{
    use HasLabel;

    #[Label('HTML')]
    case Html = 'html';

    #[Label('Markdown')]
    case Markdown = 'markdown';

    public function mimeType(): string
    {
        return match ($this) {
            self::Html => 'text/html',
            self::Markdown => 'text/markdown',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Html => 'html',
            self::Markdown => 'md',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::cases());
    }
}
