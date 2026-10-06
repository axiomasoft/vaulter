<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Documents\Tests\Collaboration\CollaborationEnabledTestCase;
use Vaulter\Documents\Tests\Comment\CommentsTestCase;
use Vaulter\Documents\Tests\Document\DocumentTestCase;
use Vaulter\Documents\Tests\TestCase;

// The v_node_comments/v_node_document_versions schema is always migrated (core owns
// it unconditionally); only the ROUTES are feature-gated. Schema + Unit run on the
// base TestCase.
uses(TestCase::class, RefreshDatabase::class)->in('Schema', 'Unit');

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

// The node-comment suite boots with 'comments' ON (+ the database driver) so the
// routes register and the real grant-based algorithm runs. Kept apart from Document
// so the two feature-gated TestCases never both claim the same path.
uses(CommentsTestCase::class, RefreshDatabase::class)->in('Comment');

// Registrar checks use a booted application without mutating the database.
uses(TestCase::class)->in('Providers');

uses(TestCase::class)->in('Configuration');
