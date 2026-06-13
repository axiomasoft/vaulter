<?php

declare(strict_types=1);

namespace DocVault\Enums;

use DocVault\Attributes\Icon;
use DocVault\Attributes\Label;
use DocVault\Attributes\TiptapExtensions;
use DocVault\Support\Concerns\HasIcon;
use DocVault\Support\Concerns\HasLabel;
use DocVault\Support\Concerns\HasTiptapExtensions;

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
