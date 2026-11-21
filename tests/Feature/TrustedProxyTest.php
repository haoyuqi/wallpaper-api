<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use ReflectionProperty;
use Tests\TestCase;

final class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('TRUSTED_PROXIES=172.18.0.0/16');

        parent::setUp();

        Route::get('/testing/client-ip', fn (Request $request): string => (string) $request->ip());
    }

    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
        $this->forgetConfiguredProxies();

        parent::tearDown();
    }

    public function test_forwarded_client_ip_is_used_only_by_a_trusted_proxy(): void
    {
        try {
            $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.9'])
                ->withHeaders(['X-Forwarded-For' => '203.0.113.10'])
                ->get('/testing/client-ip')
                ->assertOk()
                ->assertSee('203.0.113.10');

            putenv('TRUSTED_PROXIES');
            $this->forgetConfiguredProxies();

            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
                ->withHeaders(['X-Forwarded-For' => '203.0.113.10'])
                ->get('/testing/client-ip')
                ->assertOk()
                ->assertSee('198.51.100.7');
        } finally {
            putenv('TRUSTED_PROXIES');
            $this->forgetConfiguredProxies();
        }
    }

    private function forgetConfiguredProxies(): void
    {
        foreach (['alwaysTrustProxies', 'alwaysTrustHeaders'] as $property) {
            new ReflectionProperty(TrustProxies::class, $property)->setValue(null, null);
        }
    }
}
