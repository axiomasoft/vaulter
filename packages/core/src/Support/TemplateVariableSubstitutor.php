<?php

declare(strict_types=1);

namespace DocVault\Support;

/**
 * Substitutes `{{ placeholder }}` tokens inside the text nodes of a TipTap
 * document with values from a variables map. Unknown placeholders are left intact.
 */
final class TemplateVariableSubstitutor
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, string> $variables
     * @return array<string, mixed>
     */
    public function substitute(array $document, array $variables): array
    {
        return $this->walk($document, $variables);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string> $variables
     * @return array<string, mixed>
     */
    private function walk(array $node, array $variables): array
    {
        if (isset($node['text']) && is_string($node['text'])) {
            $node['text'] = $this->replace($node['text'], $variables);
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $content = [];
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    /** @var array<string, mixed> $child */
                    $content[] = $this->walk($child, $variables);
                } else {
                    $content[] = $child;
                }
            }
            $node['content'] = $content;
        }

        return $node;
    }

    /**
     * @param array<string, string> $variables
     */
    private function replace(string $text, array $variables): string
    {
        $replaced = preg_replace_callback(
            '/\{\{\s*([\w.-]+)\s*\}\}/',
            static fn (array $matches): string => $variables[$matches[1]] ?? $matches[0],
            $text,
        );

        return $replaced ?? $text;
    }
}
