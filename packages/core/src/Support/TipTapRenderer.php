<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Vaulter\Enums\ExportFormat;
use Vaulter\ValueObjects\TipTapContent;

/**
 * Renders a TipTap JSON document to HTML or Markdown. Supports the common node
 * and mark set (headings, lists, blockquote, code blocks, and bold/italic/strike/
 * code/link marks); unknown nodes fall back to rendering their children.
 */
final class TipTapRenderer
{
    public function render(TipTapContent $content, ExportFormat $format): string
    {
        return match ($format) {
            ExportFormat::Html => $this->toHtml($content),
            ExportFormat::Markdown => $this->toMarkdown($content),
        };
    }

    public function toHtml(TipTapContent $content): string
    {
        return $this->htmlNodes($this->children($content->toArray()));
    }

    public function toMarkdown(TipTapContent $content): string
    {
        $blocks = array_map($this->markdownBlock(...), $this->children($content->toArray()));

        return trim(implode("\n\n", array_filter($blocks, static fn (string $block): bool => $block !== '')))."\n";
    }

    // ─── HTML ────────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function htmlNodes(array $nodes): string
    {
        return implode('', array_map($this->htmlNode(...), $nodes));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function htmlNode(array $node): string
    {
        $children = $this->children($node);

        return match ($this->type($node)) {
            'paragraph' => '<p>'.$this->htmlNodes($children).'</p>',
            'heading' => $this->htmlHeading($node, $children),
            'text' => $this->htmlText($node),
            'hardBreak' => '<br>',
            'horizontalRule' => '<hr>',
            'bulletList' => '<ul>'.$this->htmlNodes($children).'</ul>',
            'orderedList' => '<ol>'.$this->htmlNodes($children).'</ol>',
            'listItem' => '<li>'.$this->htmlNodes($children).'</li>',
            'blockquote' => '<blockquote>'.$this->htmlNodes($children).'</blockquote>',
            'codeBlock' => '<pre><code>'.$this->escape($this->plainText($children)).'</code></pre>',
            default => $this->htmlNodes($children),
        };
    }

    /**
     * @param array<string, mixed>       $node
     * @param list<array<string, mixed>> $children
     */
    private function htmlHeading(array $node, array $children): string
    {
        $level = $this->headingLevel($node);

        return sprintf('<h%1$d>%2$s</h%1$d>', $level, $this->htmlNodes($children));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function htmlText(array $node): string
    {
        $text = $this->escape($this->stringValue($node, 'text'));

        foreach ($this->marks($node) as $mark) {
            $text = match ($this->type($mark)) {
                'bold' => sprintf('<strong>%s</strong>', $text),
                'italic' => sprintf('<em>%s</em>', $text),
                'strike' => sprintf('<s>%s</s>', $text),
                'code' => sprintf('<code>%s</code>', $text),
                'link' => '<a href="'.$this->escape($this->stringValue($this->attrs($mark), 'href')).'">'.$text.'</a>',
                default => $text,
            };
        }

        return $text;
    }

    // ─── Markdown ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $node
     */
    private function markdownBlock(array $node): string
    {
        $children = $this->children($node);

        return match ($this->type($node)) {
            'paragraph' => $this->markdownInline($children),
            'heading' => str_repeat('#', $this->headingLevel($node)).' '.$this->markdownInline($children),
            'bulletList' => $this->markdownList($children, '- '),
            'orderedList' => $this->markdownOrderedList($children),
            'blockquote' => '> '.str_replace("\n", "\n> ", $this->markdownInline($children)),
            'codeBlock' => "```\n".$this->plainText($children)."\n```",
            'horizontalRule' => '---',
            default => $this->markdownInline($children),
        };
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function markdownList(array $items, string $bullet): string
    {
        $lines = array_map(fn (array $item): string => $bullet.$this->markdownInline($this->children($item)), $items);

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function markdownOrderedList(array $items): string
    {
        $lines = [];
        foreach ($items as $index => $item) {
            $lines[] = ($index + 1).'. '.$this->markdownInline($this->children($item));
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function markdownInline(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($this->type($node)) {
                'text' => $this->markdownText($node),
                'hardBreak' => "\n",
                default => $this->markdownInline($this->children($node)),
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function markdownText(array $node): string
    {
        $text = $this->stringValue($node, 'text');

        foreach ($this->marks($node) as $mark) {
            $text = match ($this->type($mark)) {
                'bold' => sprintf('**%s**', $text),
                'italic' => sprintf('*%s*', $text),
                'strike' => sprintf('~~%s~~', $text),
                'code' => sprintf('`%s`', $text),
                'link' => '['.$text.']('.$this->stringValue($this->attrs($mark), 'href').')',
                default => $text,
            };
        }

        return $text;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>       $node
     * @return list<array<string, mixed>>
     */
    private function children(array $node): array
    {
        return $this->arrayOfNodes($node['content'] ?? null);
    }

    /**
     * @param  array<string, mixed>       $node
     * @return list<array<string, mixed>>
     */
    private function marks(array $node): array
    {
        return $this->arrayOfNodes($node['marks'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function arrayOfNodes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $nodes = [];
        foreach ($value as $node) {
            if (is_array($node)) {
                /** @var array<string, mixed> $node */
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function type(array $node): string
    {
        return $this->stringValue($node, 'type');
    }

    /**
     * @param  array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function attrs(array $node): array
    {
        $attrs = $node['attrs'] ?? null;

        /** @var array<string, mixed> $result */
        $result = is_array($attrs) ? $attrs : [];

        return $result;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function headingLevel(array $node): int
    {
        $level = $this->attrs($node)['level'] ?? 1;
        $level = is_numeric($level) ? (int) $level : 1;

        return max(1, min(6, $level));
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function plainText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= $this->type($node) === 'text'
                ? $this->stringValue($node, 'text')
                : $this->plainText($this->children($node));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function stringValue(array $node, string $key): string
    {
        $value = $node[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
