<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Documents\Tests\Comment\CommentsTestCase;
use Vaulter\Documents\Tests\Document\DocumentTestCase;
use Vaulter\Documents\Tests\TestCase;

// The v_node_comments/v_node_document_versions schema is always migrated (core owns
// it unconditionally); only the ROUTES are feature-gated. Schema + Unit run on the
// base TestCase.
uses(TestCase::class, RefreshDatabase::class)->in('Schema', 'Unit');

// The document-node suite boots with 'document_versions' ON so the routes register.
uses(DocumentTestCase::class, RefreshDatabase::class)->in('Document');

// The node-comment suite boots with 'comments' ON (+ the database driver) so the
// routes register and the real grant-based algorithm runs. Kept apart from Document
// so the two feature-gated TestCases never both claim the same path.
uses(CommentsTestCase::class, RefreshDatabase::class)->in('Comment');
