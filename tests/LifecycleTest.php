<?php

declare(strict_types=1);

namespace StackRadar\Laravel\Tests;

use Composer\InstalledVersions;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\ApplicationGateway;
use Laravel\Octane\OctaneServiceProvider;
use RuntimeException;
use StackRadar\Laravel\Reporter;
use StackRadar\Transport;
use StackRadar\TransportException;

final class LifecycleTest extends TestCase
{
    public function test_the_laravel_package_is_identified_in_the_report(): void
    {
        $this->artisan('stackradar:report')->assertSuccessful();
        self::assertSame([
            'name' => 'stackradar/laravel',
            'version' => InstalledVersions::getPrettyVersion('stackradar/laravel'),
        ], $this->transport->payloads[0]['reporter']);
    }

    public function test_the_manual_command_identifies_an_invalid_ingest_url_without_echoing_it(): void
    {
        $this->configure('ingest_url', 'https://sensitive.example.test/unexpected-path');
        $this->app->forgetInstance(Transport::class);
        $this->artisan('stackradar:report')->expectsOutput('Report not sent: invalid_ingest_url')->assertFailed();
        self::assertSame([], $this->transport->calls);
        self::assertSame('invalid_ingest_url', $this->reporter()->lastFailure()['reason']);
    }

    public function test_testing_apps_never_report_from_http_queue_or_manual_commands(): void
    {
        $this->app->instance('env', 'testing');
        self::assertTrue($this->app->runningUnitTests());
        Route::get('/healthy', fn () => 'healthy');

        $this->get('/healthy')->assertOk()->assertSee('healthy');
        $this->app['events']->dispatch(new Looping('database', 'default'));
        $this->app['config']->set('queue.connections.database.driver', 'database');
        $this->app['events']->dispatch(new JobProcessed('database', $this->createMock(Job::class)));
        $this->artisan('stackradar:report')->expectsOutput('Report not sent: sdk_disabled')->assertFailed();

        self::assertSame([], $this->transport->calls);
        self::assertDirectoryDoesNotExist($this->cachePath);
    }

    public function test_http_reports_at_termination_and_keeps_the_response_intact(): void
    {
        Route::get('/healthy', fn () => 'healthy');
        $request = Request::create('/healthy');
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        self::assertSame('healthy', $response->getContent());
        self::assertSame([], $this->transport->payloads);

        $kernel->terminate($request, $response);
        self::assertCount(1, $this->transport->payloads);
        self::assertSame('php', $this->transport->payloads[0]['runtime']['name']);

        $second = Request::create('/healthy');
        $kernel->terminate($second, $kernel->handle($second));
        self::assertCount(1, $this->transport->payloads);
    }

    public function test_transport_failure_cannot_break_an_http_response(): void
    {
        $this->transport->failure = new RuntimeException('Sensitive failure detail');
        Route::get('/healthy', fn () => 'healthy');
        $this->get('/healthy')->assertOk()->assertSee('healthy');
    }

    public function test_queue_lifecycle_reports_once_and_does_not_throw_on_failure(): void
    {
        $this->transport->failure = new RuntimeException('Upload failed');
        $this->app['events']->dispatch(new Looping('database', 'default'));
        $this->app['events']->dispatch(new Looping('database', 'default'));
        self::assertCount(1, $this->transport->payloads);
        self::assertSame('php', $this->transport->payloads[0]['runtime']['name']);
    }

    public function test_sync_jobs_do_not_create_queue_runtime_reports(): void
    {
        $this->app['config']->set('queue.connections.custom-sync.driver', 'sync');
        $this->app['events']->dispatch(new JobProcessed('custom-sync', $this->createMock(Job::class)));
        self::assertSame([], $this->transport->payloads);
    }

    public function test_one_off_worker_jobs_report_after_processing(): void
    {
        $this->app['config']->set('queue.connections.database.driver', 'database');
        $this->app['events']->dispatch(new JobProcessed('database', $this->createMock(Job::class)));
        self::assertCount(1, $this->transport->payloads);
    }

    public function test_the_octane_gateway_terminates_the_reporting_middleware_between_requests(): void
    {
        $this->app->register(OctaneServiceProvider::class);
        Route::get('/healthy', fn () => 'healthy');

        for ($i = 0; $i < 2; $i++) {
            $sandbox = clone $this->app;
            $gateway = new ApplicationGateway($this->app, $sandbox);
            $request = Request::create('/healthy');
            $gateway->terminate($request, $gateway->handle($request));
        }

        self::assertCount(1, $this->transport->payloads);
        self::assertSame('php', $this->transport->payloads[0]['runtime']['name']);
    }

    public function test_the_manual_command_reports_cli_and_bypasses_the_due_gate(): void
    {
        $this->artisan('stackradar:report')->expectsOutput('Runtime inventory reported.')->assertSuccessful();
        $this->artisan('stackradar:report')->assertSuccessful();
        self::assertCount(2, $this->transport->payloads);
        self::assertSame('cli', $this->transport->payloads[0]['runtime']['sapi']);
    }

    public function test_the_manual_command_returns_a_failure_exit_code_when_unconfigured(): void
    {
        $this->configure('ingest_key', null);
        $this->artisan('stackradar:report')->assertFailed();
    }

    public function test_the_manual_command_prints_the_persisted_stop_reason_without_debug(): void
    {
        $this->transport->failure = new TransportException('Invalid key', 401, reason: 'invalid_ingest_key');
        $this->artisan('stackradar:report')->expectsOutput('Report not sent: HTTP 401 invalid_ingest_key')->assertFailed();
        $this->app->forgetInstance(Reporter::class);
        $this->artisan('stackradar:report')->expectsOutput('Report not sent: HTTP 401 invalid_ingest_key')->assertFailed();
        self::assertCount(1, $this->transport->calls);
    }

    public function test_an_accepted_heartbeat_does_not_claim_a_rejected_inventory_was_reported(): void
    {
        $this->transport->failures = [
            new TransportException('Unknown', 409, reason: 'unknown_inventory'),
            new TransportException('Rejected', 422, reason: 'inventory_hash_mismatch'),
        ];
        $this->artisan('stackradar:report')->assertFailed();
        $this->artisan('stackradar:report')
            ->expectsOutput('Check-in accepted; inventory unavailable: HTTP 422 inventory_hash_mismatch')
            ->assertFailed();
        self::assertCount(3, $this->transport->calls);
    }

    public function test_ordinary_artisan_commands_do_not_report(): void
    {
        $this->artisan('list')->assertSuccessful();
        self::assertSame([], $this->transport->payloads);
    }
}
