<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'chait_test'
            || config('database.connections.pgsql.url')
            || config('database.connections.pgsql.search_path') !== 'public') {
            throw new \RuntimeException('Tests require PostgreSQL chait_test, schema public, APP_ENV=testing and an empty DB_URL.');
        }
        config(['inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
