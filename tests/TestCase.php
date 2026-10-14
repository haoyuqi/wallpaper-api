<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        // RefreshDatabase defines its own empty beforeRefreshingDatabase hook,
        // which overrides an inherited hook. Check before any trait can migrate.
        if (config('database.default') !== 'testing') {
            throw new RuntimeException('Database tests must use the dedicated testing connection.');
        }

        $connection = config('database.connections.testing');

        if (($connection['driver'] ?? null) === 'pgsql' && ($connection['database'] ?? null) !== 'wallpaper_api_test') {
            throw new RuntimeException('PostgreSQL tests require TEST_DB_DATABASE=wallpaper_api_test.');
        }

        return parent::setUpTraits();
    }
}
