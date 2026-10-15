<?php

declare(strict_types=1);

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

final class TestDatabaseSafetyTest extends TestCase
{
    public function test_non_testing_connection_is_rejected_before_database_traits_run(): void
    {
        config(['database.default' => 'pgsql']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database tests must use the dedicated testing connection.');

        $this->setUpTraits();
    }

    public function test_non_testing_postgresql_database_is_rejected_before_database_traits_run(): void
    {
        config(['database.connections.testing.driver' => 'pgsql', 'database.connections.testing.database' => 'wallpaper_api']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PostgreSQL tests require TEST_DB_DATABASE=wallpaper_api_test.');

        $this->setUpTraits();
    }
}
