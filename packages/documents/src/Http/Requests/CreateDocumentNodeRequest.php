<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Documents\Engines\Handlers\TipTapHandler;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Documents\ValueObjects\OpaqueDocumentContent;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Enums\Handlers\Capability;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Support\DriveNameRules;
use Vaulter\ValueObjects\Handlers\FileDescriptor;

/**
 * Validates a create-document-node request (§2.5 / M4a). The destination parent +
 * target storage are route/repository resolved by the controller; this Request owns
 * the name + initial content + format validation and builds the value objects the
 * controller hands to CreateDocumentAction.
 *
 * It is HANDLER-AWARE (§7 level 2): an optional `mime` selects the document format.
 * With none, the node is a rich-text TipTap document (the historical default). With a
 * concrete mime (e.g. the xlsx sheet mime), the {@see FileHandlerRegistry} resolves
 * the matching editable handler (e.g. the spreadsheet {@see UniverHandler}) — the
 * content shape follows its richText capability (a validated TipTap doc vs an opaque
 * snapshot), and the node is stamped with that handler's canonical mime so a later read
 * re-resolves the same editor. A mime that resolves to a NON-editable handler (e.g. the
 * spreadsheets feature is off, so xlsx falls to DownloadOnly) is rejected.
 */
final class CreateDocumentNodeRequest extends VaulterFormRequest
{
    private ?FileHandler $handler = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => DriveNameRules::rules(),
            'parent_id' => ['nullable'],
            'format' => ['nullable', 'string', 'in:'.implode(',', DocumentType::values())],
            'mime' => ['nullable', 'string'],
            'content' => ['nullable', 'array'],
        ];

        // Only a rich-text handler pins the TipTap {"type":"doc"} shape; a non-rich-text
        // handler's initial content is opaque and validated only as a JSON object.
        if ($this->isRichText()) {
            $rules['content.type'] = ['required_with:content', 'string', 'in:doc'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        // A document node must open in an EDITABLE surface. A concrete mime that resolves
        // to a non-editable handler (unknown format, or an off-by-flag one like xlsx →
        // DownloadOnly) cannot back a document node — reject it on the `mime` field.
        $validator->after(function (Validator $validator): void {
            if ($this->requestedMime() !== null && !$this->resolvedHandler()->capabilities($this->descriptor())->editable) {
                $validator->errors()->add('mime', 'This format has no editable document handler.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return DriveNameRules::messages();
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }

    public function parentId(): int|string|null
    {
        return $this->nullableScalarId('parent_id');
    }

    /**
     * The mime to STAMP on the node so a read re-resolves the same handler — the
     * resolved handler's canonical (first-declared) mime: the synthetic Vaulter
     * document mime for TipTap, the xlsx sheet mime for the spreadsheet handler.
     */
    public function nodeMimeType(): string
    {
        return $this->resolvedHandler()->mimeTypes()[0] ?? TipTapHandler::DOCUMENT_MIME;
    }

    /**
     * The version `format` label: the DocumentType for a rich-text node (markdown for
     * .md, else rich text), or the handler id for a non-rich-text one (e.g. 'vaulter/univer').
     */
    public function versionFormat(): string
    {
        if (!$this->isRichText()) {
            return $this->resolvedHandler()->key();
        }

        $format = $this->string('format')->toString();

        return $format !== '' ? $format : DocumentType::RichText->value;
    }

    /**
     * The initial content, shaped for the resolved handler — a validated TipTap doc
     * (empty when none supplied) for a rich-text node, else an opaque snapshot stored
     * verbatim (an empty object when none supplied).
     */
    public function content(): DocumentContent
    {
        $raw = $this->nullableArrayInput('content');

        if (!$this->isRichText()) {
            return OpaqueDocumentContent::fromArray($raw ?? []);
        }

        return $raw === null ? TipTapContent::empty() : TipTapContent::fromArray($raw);
    }

    /**
     * Whether the node's editable content is rich text (TipTap) — read off the resolved
     * handler's capability.
     */
    private function isRichText(): bool
    {
        return $this->resolvedHandler()->capabilities($this->descriptor())->richText;
    }

    /**
     * The handler the create routes to, resolved once through the registry at the Edit
     * capability from the descriptor's mime (SSOT, §4.5) — the default TipTap document
     * mime when the request names no format.
     */
    private function resolvedHandler(): FileHandler
    {
        return $this->handler ??= app(FileHandlerRegistry::class)
            ->resolve($this->descriptor(), Capability::Edit);
    }

    /**
     * The descriptor the registry routes on: the requested mime (or the synthetic TipTap
     * document mime by default) as the trusted detected mime, plus the name so the
     * extension is available as a fallback.
     */
    private function descriptor(): FileDescriptor
    {
        $name = $this->input('name');

        return FileDescriptor::make(
            nodeId: 0,
            filename: is_string($name) ? $name : '',
            detectedMime: $this->requestedMime() ?? TipTapHandler::DOCUMENT_MIME,
        );
    }

    /**
     * The explicitly requested format mime, or null when the create names none (a plain
     * rich-text document).
     */
    private function requestedMime(): ?string
    {
        $mime = $this->input('mime');

        return is_string($mime) && $mime !== '' ? $mime : null;
    }
}
