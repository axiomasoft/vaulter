<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Comment;

use Illuminate\Foundation\Application;
use Vaulter\Documents\Tests\TestCase;

/**
 * Boots the package with the 'comments' feature ENABLED (ADR-0014 шаг 1) so the
 * node-comment routes are registered at boot and the AccessResolver runs the real
 * grant-based algorithm. Kept in its own top-level folder so the base binding never
 * also claims it (Pest forbids two test cases for one path).
 */
abstract class CommentsTestCase extends TestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Opt INTO the node-comment routes (default off) and authorise through the
        // real database grant resolver (the base default is the null driver).
        $app['config']->set('vaulter-documents.comments.enabled', true);
        $app['config']->set('vaulter.access.permission_driver', 'database');
    }
}
