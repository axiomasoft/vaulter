<?php

declare(strict_types=1);

namespace Vaulter\Enums;

use Vaulter\Attributes\Icon;
use Vaulter\Attributes\Label;
use Vaulter\Attributes\TiptapExtensions;
use Vaulter\Support\Concerns\HasIcon;
use Vaulter\Support\Concerns\HasLabel;
use Vaulter\Support\Concerns\HasTiptapExtensions;

enum DocumentType: string
{
    use HasIcon;
    use HasLabel;
    use HasTiptapExtensions;

    #[Label('Rich text')]
    #[Icon('document-text')]
    #[TiptapExtensions(['StarterKit', 'Image', 'Table', 'Link'])]
    case RichText = 'richtext';

    #[Label('Table')]
    #[Icon('table-cells')]
    #[TiptapExtensions(['StarterKit', 'Table'])]
    case Table = 'table';

    #[Label('Markdown')]
    #[Icon('hashtag')]
    #[TiptapExtensions(['StarterKit', 'CodeBlock'])]
    case Markdown = 'markdown';

    #[Label('Template')]
    #[Icon('rectangle-stack')]
    #[TiptapExtensions(['StarterKit', 'Placeholder'])]
    case Template = 'template';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
