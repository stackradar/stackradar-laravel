<?php

declare(strict_types=1);

namespace StackRadar\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase as Orchestra;
use StackRadar\Client;
use StackRadar\Laravel\Reporter;
use StackRadar\Laravel\StackRadarServiceProvider;
use StackRadar\Transport;

abstract class TestCase extends Orchestra
{
    protected RecordingTransport $transport;

    protected string $cachePath;

    protected function getPackageProviders($app): array
    {
        return [StackRadarServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->cachePath = sys_get_temp_dir().'/stackradar-laravel-test-'.bin2hex(random_bytes(8));
        $app['config']->set('cache.stores.file', ['driver' => 'file', 'path' => $this->cachePath]);
        $app['config']->set('stackradar.ingest_key', 'test-project-token');
        $app['config']->set('stackradar.environment', 'PROD');
        $app['config']->set('stackradar.ingest_url', 'https://example.test');
        $app['config']->set('stackradar.startup_delay', 0);
        $app['config']->set('stackradar.startup_jitter', 0);
        $app['config']->set('stackradar.release', 'release-a');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00 UTC');
        $this->transport = new RecordingTransport;
        $this->app->instance(Transport::class, $this->transport);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        (new Filesystem)->deleteDirectory($this->cachePath);
        parent::tearDown();
    }

    protected function reporter(): Reporter
    {
        return $this->app->make(Reporter::class);
    }

    protected function configure(string $key, mixed $value): void
    {
        $this->app['config']->set('stackradar.'.$key, $value);
        $this->app->forgetInstance(Reporter::class);
        $this->app->forgetInstance(Client::class);
    }
}
