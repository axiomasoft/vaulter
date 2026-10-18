<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Documents\Tests\Comment\CommentsTestCase;
use Vaulter\Documents\Tests\Document\DocumentTestCase;
use Vaulter\Documents\Tests\Export\ExportTestCase;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Documents\Tests\State\StateTestCase;
use Vaulter\Documents\Tests\TestCase;
use Vaulter\Documents\Tests\Types\TypesTestCase;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Testing\Support\TestGroups;

// The v_node_comments/v_documents/v_document_revisions schema is always migrated (core owns
// it unconditionally); only the ROUTES are feature-gated. Schema + Unit run on the
// base TestCase.
uses(TestCase::class, RefreshDatabase::class)->in('Schema', 'Unit');

// The doctor checks read the document aggregate; documents are created through the document actions.
uses(DocumentTestCase::class, RefreshDatabase::class)->in('Doctor');

// The document-node suite boots with 'document_versions' ON so the routes register.
uses(DocumentTestCase::class, RefreshDatabase::class)->in('Document');

// PostgreSQL ForkRace scenarios: forked backends must see committed fixtures, so these
// migrate per test instead of wrapping it in a transaction.
uses(DocumentTestCase::class, DatabaseMigrations::class)->in('Concurrency');

// Events that must wait for the OUTERMOST commit are proved without the fixture's wrapping
// transaction, which would stand in for the commit: this suite migrates per test.
uses(OperationsTestCase::class, DatabaseMigrations::class)->in('AfterCommit');

// The node-comment suite boots with 'comments' ON (+ the database driver) so the
// routes register and the real grant-based algorithm runs. Kept apart from Document
// so the two feature-gated TestCases never both claim the same path.
uses(CommentsTestCase::class, RefreshDatabase::class)->in('Comment');

// The Documents API (DocumentOperations/CommentOperations) with every guard live:
// database grants and comments ON.
uses(OperationsTestCase::class, RefreshDatabase::class)->in('Operations', 'Recipes');

// Document export: registry, authorization, source selection and the file response;
// the host testing kit (factories, contract suites) runs on the same booted types.
uses(ExportTestCase::class, RefreshDatabase::class)->in('Export', 'Testing');

// Document types: the registry, the built-in types, a third-party type that is
// installed, removed and installed again, and content that reads only partly.
uses(TypesTestCase::class, RefreshDatabase::class)->in('Types');

// Generated scaffolds run their contract suites on the same booted types and exporters.
uses(ExportTestCase::class, RefreshDatabase::class)->in('Console');

// The collaborative-state contract and the drain-or-409 guards (D26, D27) check "inside a transaction" themselves:
// these files migrate per test instead of wrapping each in a transaction.
uses(StateTestCase::class, DatabaseMigrations::class)->in('State');
// The v1 HTTP surface: documents, revisions, comments and export.
uses(DocumentTestCase::class, RefreshDatabase::class)->in('Http');

// Registrar checks use a booted application without mutating the database.
uses(TestCase::class)->in('Providers');

uses(TestCase::class)->in('Configuration');

// The HTTP shape checks read the booted route table.
uses(TestCase::class)->in('Arch');

// Database-free (P1.5): these files run on the booted application without a schema; each one stayed green under
// WithoutDatabaseSchema (a test that reaches the database fails there) — see
// plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md.
uses(WithoutDatabaseSchema::class)->in('Arch', 'Configuration', 'Providers');

// Test groups (P1.5): database, serial, extended, stand and the engine-only engine:* — see groups.php.
TestGroups::bind(__DIR__, 'documents');
