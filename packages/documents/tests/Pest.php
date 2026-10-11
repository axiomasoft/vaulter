<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Documents\Tests\Collaboration\CollaborationEnabledTestCase;
use Vaulter\Documents\Tests\Comment\CommentsTestCase;
use Vaulter\Documents\Tests\Document\DocumentTestCase;
use Vaulter\Documents\Tests\Export\ExportTestCase;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Documents\Tests\TestCase;
use Vaulter\Documents\Tests\Types\TypesTestCase;

// The v_node_comments/v_documents/v_document_revisions schema is always migrated (core owns
// it unconditionally); only the ROUTES are feature-gated. Schema + Unit run on the
// base TestCase.
uses(TestCase::class, RefreshDatabase::class)->in('Schema', 'Unit');

// The doctor checks read the document aggregate; documents are created through the document actions.
uses(DocumentTestCase::class, RefreshDatabase::class)->in('Doctor');

// The document-node suite boots with 'document_versions' ON so the routes register.
// 'collaboration' stays OFF (the true default) — CollaborationFeatureGateTest (VLT-
// REMED P3.1 / F-P1.6-01) lives here and asserts exactly that combination.
uses(DocumentTestCase::class, RefreshDatabase::class)->in('Document');

// These exercise the realtime-collab routes themselves (collab/token,
// collab/authenticate, collab/store) — VLT-REMED P3.1 (F-P1.6-01, D13/D20) gates
// them on 'collaboration' TOO (on top of 'document_versions'), so this suite needs
// it ON. Kept in its own top-level folder (Pest forbids two test cases for one
// path) so the DocumentTestCase binding above never also claims these files.
uses(CollaborationEnabledTestCase::class, RefreshDatabase::class)->in('Collaboration');

// PostgreSQL ForkRace scenarios: forked backends must see committed fixtures, so these
// migrate per test instead of wrapping it in a transaction.
uses(CollaborationEnabledTestCase::class, DatabaseMigrations::class)->in('Concurrency');

// Events that must wait for the OUTERMOST commit are proved without the fixture's wrapping
// transaction, which would stand in for the commit: this suite migrates per test.
uses(OperationsTestCase::class, DatabaseMigrations::class)->in('AfterCommit');

// The node-comment suite boots with 'comments' ON (+ the database driver) so the
// routes register and the real grant-based algorithm runs. Kept apart from Document
// so the two feature-gated TestCases never both claim the same path.
uses(CommentsTestCase::class, RefreshDatabase::class)->in('Comment');

// The Documents API (DocumentOperations/CommentOperations) with every guard live:
// database grants, comments and collaboration ON.
uses(OperationsTestCase::class, RefreshDatabase::class)->in('Operations');

// Document export: registry, authorization, source selection and the file response.
uses(ExportTestCase::class, RefreshDatabase::class)->in('Export');

// Document types: the registry, the built-in types, a third-party type that is
// installed, removed and installed again, and content that reads only partly.
uses(TypesTestCase::class, RefreshDatabase::class)->in('Types');

// The v1 HTTP surface: documents, revisions, comments, export, the session and the hub
// (collaboration ON so the hub stack is registered).
uses(CollaborationEnabledTestCase::class, RefreshDatabase::class)->in('Http');

// Registrar checks use a booted application without mutating the database.
uses(TestCase::class)->in('Providers');

uses(TestCase::class)->in('Configuration');

// The HTTP shape checks read the booted route table.
uses(TestCase::class)->in('Arch');
