<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_check_returns_ok(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
    }

    public function test_welcome_page_is_not_served(): void
    {
        $response = $this->get('/');

        $response->assertNotFound();
    }
}
