<?php

declare(strict_types=1);

namespace VaulterRef;

use VaulterRef\Ai\Core\Operations\CommitCommand;
use VaulterRef\Ai\Core\Operations\StatusCommand;
use VaulterRef\Ai\Core\Persistence\OwnerRoutes;
use VaulterRef\Ai\Core\Persistence\Proposals;
use VaulterRef\Ai\Core\Support\SecretBox;
use VaulterRef\Ai\Documents\DocumentsDirectory;
use VaulterRef\Ai\Documents\Writing\SingleTextOwner;
use VaulterRef\Database\Pg;
use VaulterRef\Documents\Actions\ChangeDocumentWriteMode;
use VaulterRef\Documents\Actions\RunAtomicDocumentMutation;
use VaulterRef\Documents\Contracts\AtomicDocumentMutation;
use VaulterRef\Drive\AccessGate;
use VaulterRef\Drive\SqlAccessGate;
use VaulterRef\Support\FaultPoint;
use VaulterRef\Support\NoFaults;

/**
 * What the service providers bind. Documents: AtomicDocumentMutation → RunAtomicDocumentMutation (BindingsRegistrar).
 * AI Core: Proposals, OwnerRoutes, CommitCommand, StatusCommand on the CORE connection (OfficeServiceProvider).
 * AI Documents: SingleTextOwner under its owner key on the OWNER = Documents connection (DocumentsAiServiceProvider).
 */
final readonly class Wiring
{
    public AccessGate $access;
    public AtomicDocumentMutation $atomic;
    public SingleTextOwner $textOwner;
    public Proposals $proposals;
    public OwnerRoutes $routes;
    public CommitCommand $commit;
    public StatusCommand $status;
    public ChangeDocumentWriteMode $writeMode;

    public function __construct(public Pg $owner, public Pg $core, public SecretBox $box, FaultPoint $faults = new NoFaults,
        ?AccessGate $access = null, ?AtomicDocumentMutation $atomic = null, ?\VaulterRef\Ai\Core\Contracts\CommandOwner $textOwner = null)
    {
        $this->access = $access ?? new SqlAccessGate;
        $this->atomic = $atomic ?? new RunAtomicDocumentMutation($owner, $this->access);
        $this->textOwner = new SingleTextOwner($this->atomic, $owner, $this->access, $box, $faults);
        $this->proposals = new Proposals($core, $box);
        $this->routes = new OwnerRoutes($core);
        $directory = new DocumentsDirectory($owner, $this->access);
        $owners = [SingleTextOwner::KEY => $textOwner ?? $this->textOwner];
        $this->commit = new CommitCommand($this->proposals, $this->routes, $directory, $owners, $faults);
        $this->status = new StatusCommand($this->routes, $directory, $owners);
        $this->writeMode = new ChangeDocumentWriteMode($owner, $this->access);
    }
}
