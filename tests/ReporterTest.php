<?php

declare(strict_types=1);

namespace StackRadar\Laravel\Tests;

use Composer\InstalledVersions;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use StackRadar\Client;
use StackRadar\HttpTransport;
use StackRadar\Inventory;
use StackRadar\Laravel\Reporter;
use StackRadar\PackageInventory;
use StackRadar\TransportException;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

final class ReporterTest extends TestCase
{
    public function test_the_due_gate_survives_new_reporter_instances_and_does_not_collect_when_not_due(): void
    {
        $inventory = $this->createMock(Inventory::class);
        $inventory->expects(self::once())->method('collect')->willReturn(new PackageInventory('composer', []));
        $this->app->instance(Client::class, new Client($this->transport, $inventory));

        self::assertTrue($this->reporter()->reportIfDue('web'));
        $this->app->forgetInstance(Reporter::class);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertCount(2, $this->transport->payloads);
    }

    public function test_ordinary_requests_do_not_read_the_large_inventory_cache_entry(): void
    {
        self::assertTrue($this->reporter()->reportIfDue('web'));
        $hits = new \ArrayObject;
        $this->app['events']->listen(CacheHit::class, function ($event) use ($hits): void {
            $hits[] = $event->key;
        });
        $this->app->forgetInstance(Reporter::class);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertNotEmpty($hits->getArrayCopy());
        foreach ($hits as $key) {
            self::assertStringEndsNotWith(':inventory', $key);
        }
    }

    #[DataProvider('scopeChanges')]
    public function test_distinct_observation_scopes_report_independently(string $key, mixed $value): void
    {
        self::assertTrue($this->reporter()->reportIfDue('web'));
        $this->configure($key, $value);
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertCount(2, $this->transport->payloads);
    }

    public static function scopeChanges(): array
    {
        return [
            ['release', 'release-b'], ['service', 'worker'], ['artifact', 'image-b'],
            ['environment', 'STAGE'], ['ingest_key', 'other-key'],
            ['ingest_url', 'https://other.example.test'],
        ];
    }

    public function test_web_queue_and_cli_have_separate_reporting_state(): void
    {
        foreach (['web', 'queue', 'cli'] as $role) {
            self::assertTrue($this->reporter()->reportIfDue($role));
            self::assertFalse($this->reporter()->reportIfDue($role));
        }
        self::assertCount(3, array_unique(array_map(fn ($p) => $p['instance']['id'], $this->transport->payloads)));
    }

    public function test_transient_failures_retry_at_the_next_heartbeat_without_recollecting(): void
    {
        $inventory = $this->createMock(Inventory::class);
        $inventory->expects(self::once())->method('collect')->willReturn(new PackageInventory('composer', []));
        $inventory->method('runtime')->willReturn(['name' => 'php', 'versions' => ['php' => PHP_VERSION]]);
        $this->app->instance(Client::class, new Client($this->transport, $inventory));
        $this->transport->failure = new TransportException('Unavailable', 503);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        Carbon::setTestNow(Carbon::now()->addSeconds(599));
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertCount(1, $this->transport->payloads);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->transport->failure = null;
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertSame($this->transport->payloads[0]['instance'], $this->transport->payloads[1]['instance']);
    }

    #[DataProvider('stopStatuses')]
    public function test_stop_responses_persist_across_requests_and_even_manual_reporting(int $status, ?string $reason): void
    {
        $this->transport->failure = new TransportException('Stopped', $status, reason: $reason);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        $this->app->forgetInstance(Reporter::class);
        Carbon::setTestNow(Carbon::now()->addDay());
        self::assertFalse($this->reporter()->reportIfDue('web', force: true));
        self::assertCount(1, $this->transport->payloads);
        $this->configure('ingest_key', 'replacement-key');
        $this->transport->failure = null;
        self::assertTrue($this->reporter()->reportIfDue('web'));
    }

    public static function stopStatuses(): array
    {
        return [[401, 'invalid_ingest_key'], [410, null]];
    }

    #[DataProvider('temporaryAuthResponses')]
    public function test_temporary_auth_failures_recover_without_a_deploy(int $status, ?string $reason): void
    {
        $this->transport->failure = new TransportException('Not available', $status, reason: $reason);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        $first = $this->transport->payloads[0]['instance'];
        $this->app->forgetInstance(Reporter::class);
        Carbon::setTestNow(Carbon::now()->addSeconds(3599));
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertCount(1, $this->transport->payloads);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->transport->failure = null;
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertSame($first, $this->transport->payloads[1]['instance']);
    }

    public static function temporaryAuthResponses(): array
    {
        return [[403, 'runtime_reporting_disabled'], [403, null], [401, null], [401, 'proxy_authentication_required']];
    }

    public function test_retry_after_zero_waits_at_least_a_minute(): void
    {
        $this->transport->failure = new TransportException('Limited', 429, 0);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        Carbon::setTestNow(Carbon::now()->addSeconds(59));
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertCount(1, $this->transport->payloads);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->transport->failure = null;
        self::assertTrue($this->reporter()->reportIfDue('web'));
    }

    public function test_old_and_restarted_workers_cannot_share_stale_inventory_without_release_metadata(): void
    {
        $original = InstalledVersions::getAllRawData()[0];
        $data = $original;
        $data['root']['install_path'] = $this->cachePath.'/project-with-custom-vendor';
        $data['versions'] = ['vendor/package' => ['version' => '1.0.0.0', 'pretty_version' => '1.0.0', 'dev_requirement' => false]];
        $this->configure('release', null);
        try {
            InstalledVersions::reload($data);
            $oldWorker = new Client($this->transport);
            $oldWorker->inventoryFingerprint();
            $expectedOld = $oldWorker->collectInventory()->hash();
            $data['versions']['vendor/package']['version'] = '2.0.0.0';
            $data['versions']['vendor/package']['pretty_version'] = '2.0.0';
            InstalledVersions::reload($data);

            // The old worker reports after deployment but still observes its loaded v1 metadata.
            $this->app->instance(Client::class, $oldWorker);
            self::assertTrue($this->reporter()->reportIfDue('queue'));
            $oldHash = $this->transport->payloads[0]['packages']['inventory_hash'];
            self::assertSame($expectedOld, $oldHash);

            $this->app->forgetInstance(Reporter::class);
            $freshWorker = new Client($this->transport);
            $expectedNew = $freshWorker->collectInventory()->hash();
            $this->app->instance(Client::class, $freshWorker);
            self::assertTrue($this->reporter()->reportIfDue('queue'));
            $newHash = $this->transport->payloads[1]['packages']['inventory_hash'];
            self::assertSame($expectedNew, $newHash);
            self::assertNotSame($oldHash, $newHash);
            self::assertNotSame($this->transport->payloads[0]['instance']['id'], $this->transport->payloads[1]['instance']['id']);
        } finally {
            InstalledVersions::reload($original);
        }
    }

    public function test_a_persistent_cache_outage_uses_a_local_cooldown_and_logs_only_when_requested(): void
    {
        $this->configure('debug', true);
        $cache = $this->createMock(Factory::class);
        $cache->expects(self::exactly(2))->method('store')->willThrowException(new RuntimeException('Redis unavailable'));
        $this->app->instance(Factory::class, $cache);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning');
        $this->app->instance(LoggerInterface::class, $logger);
        self::assertFalse($this->reporter()->reportIfDue('queue'));
        self::assertFalse($this->reporter()->reportIfDue('queue'));
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        self::assertFalse($this->reporter()->reportIfDue('queue'));
        self::assertSame([], $this->transport->calls);
    }

    public function test_fresh_fpm_reporters_do_not_flood_logs_when_the_cache_is_broken(): void
    {
        $cache = $this->createMock(Factory::class);
        $cache->method('store')->willThrowException(new RuntimeException('Storage unavailable'));
        $this->app->instance(Factory::class, $cache);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $this->app->instance(LoggerInterface::class, $logger);
        for ($i = 0; $i < 3; $i++) {
            $this->app->forgetInstance(Reporter::class);
            self::assertFalse($this->reporter()->reportIfDue('web'));
        }
    }

    public function test_diagnostics_include_the_safe_reason_but_never_the_key_or_message(): void
    {
        $this->configure('debug', true);
        $this->transport->failure = new TransportException('Sensitive upstream response', 422, reason: 'instance_limit');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('StackRadar runtime report failed.', self::callback(function (array $context): bool {
            self::assertSame('instance_limit', $context['reason']);
            self::assertSame(422, $context['status']);
            self::assertStringNotContainsString('Sensitive', json_encode($context));
            self::assertStringNotContainsString('test-project-token', json_encode($context));

            return true;
        }));
        $this->app->instance(LoggerInterface::class, $logger);
        self::assertFalse($this->reporter()->reportIfDue('web'));
    }

    public function test_debug_dumps_redact_the_ingest_key(): void
    {
        ob_start();
        var_dump($this->reporter());
        $dump = ob_get_clean();
        self::assertStringNotContainsString('test-project-token', $dump);
        self::assertStringContainsString('[redacted]', $dump);
    }

    public function test_actual_dump_mechanisms_cannot_reveal_sdk_credentials(): void
    {
        $this->configure('ingest_key', 'sr_ingest_SECRET123');
        $objects = [$this->reporter(), new HttpTransport('sr_ingest_SECRET123')];
        $cloner = new VarCloner;
        $cloner->setMaxItems(100000);
        $cloner->setMaxString(100000);
        $dumper = new CliDumper;
        foreach ($objects as $object) {
            $dump = $dumper->dump($cloner->cloneVar($object), true);
            self::assertStringNotContainsString('sr_ingest_SECRET123', $dump);
            self::assertStringNotContainsString('sr_ingest_SECRET123', var_export($object, true));
            self::assertStringNotContainsString('sr_ingest_SECRET123', print_r($object, true));
            try {
                serialize($object);
                self::fail('Secret-bearing SDK objects must not serialize.');
            } catch (\Exception $exception) {
                self::assertStringNotContainsString('sr_ingest_SECRET123', $exception->getMessage());
            }
        }
    }

    public function test_a_terminal_stop_warns_once_by_default_and_rearms_the_local_pause(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('StackRadar runtime report failed.', [
            'status' => 401, 'reason' => 'invalid_ingest_key',
        ]);
        $this->app->instance(LoggerInterface::class, $logger);
        $this->transport->failure = new TransportException('Invalid key', 401, reason: 'invalid_ingest_key');
        self::assertFalse($this->reporter()->reportIfDue('queue'));
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->app->forgetInstance(Reporter::class);
        $reads = new \ArrayObject;
        $this->app['events']->listen(CacheHit::class, function ($event) use ($reads): void {
            $reads[] = $event->key;
        });
        $reporter = $this->reporter();
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($reporter->reportIfDue('queue'));
        }
        self::assertCount(1, $reads);
        self::assertSame('invalid_ingest_key', $reporter->lastFailure()['reason']);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        self::assertFalse($reporter->reportIfDue('queue'));
        self::assertCount(2, $reads);
        self::assertCount(1, $this->transport->calls);
    }

    public function test_a_rejected_inventory_warns_only_once_even_as_heartbeats_continue(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $this->app->instance(LoggerInterface::class, $logger);
        $this->transport->failures = [
            new TransportException('Unknown', 409, reason: 'unknown_inventory'),
            new TransportException('Rejected', 422, reason: 'inventory_hash_mismatch'),
        ];
        self::assertFalse($this->reporter()->reportIfDue('web'));
        $this->app->forgetInstance(Reporter::class);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertSame('inventory_hash_mismatch', $this->reporter()->lastFailure()['reason']);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        self::assertTrue($this->reporter()->reportIfDue('web'));
    }

    public function test_a_held_lease_rearms_the_local_pause_even_when_the_saved_attempt_is_past(): void
    {
        self::assertTrue($this->reporter()->reportIfDue('queue'));
        $reads = new \ArrayObject;
        $this->app['events']->listen(CacheHit::class, function ($event) use ($reads): void {
            $reads[] = $event->key;
        });
        $this->app->forgetInstance(Reporter::class);
        self::assertFalse($this->reporter()->reportIfDue('queue'));
        $key = $reads[0];
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $lock = $this->app->make(Factory::class)->store('file')->getStore()->lock($key.':lock', 15);
        self::assertTrue($lock->get());
        $this->app->forgetInstance(Reporter::class);
        $reads->exchangeArray([]);
        try {
            $reporter = $this->reporter();
            for ($i = 0; $i < 5; $i++) {
                self::assertFalse($reporter->reportIfDue('queue'));
            }
            self::assertCount(1, $reads);
        } finally {
            $lock->release();
        }
    }

    public function test_an_inventory_rejection_preserves_heartbeats_without_repeating_the_upload(): void
    {
        $this->transport->failures = [
            new TransportException('Unknown', 409, reason: 'unknown_inventory'),
            new TransportException('Rejected', 422, reason: 'inventory_hash_mismatch'),
        ];
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertCount(2, $this->transport->calls);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertCount(3, $this->transport->calls);
        $body = $this->transport->calls[2]['payload'];
        self::assertSame('failed', $body['packages']['status']);
        self::assertArrayNotHasKey('inventory_hash', $body['packages']);
        self::assertSame('POST', $this->transport->calls[2]['method']);
    }

    public function test_startup_jitter_is_persisted_without_sleeping_the_request(): void
    {
        $this->configure('startup_delay', 5);
        $this->configure('startup_jitter', 30);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertSame([], $this->transport->calls);
        $this->app->forgetInstance(Reporter::class);
        Carbon::setTestNow(Carbon::now()->addSeconds(4));
        self::assertFalse($this->reporter()->reportIfDue('web'));
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        self::assertTrue($this->reporter()->reportIfDue('web'));
    }

    public function test_environment_defaults_to_laravel_and_instance_id_is_a_persisted_uuid(): void
    {
        $this->app['config']->set('app.env', 'staging');
        $this->configure('environment', null);
        self::assertTrue($this->reporter()->reportIfDue('web'));
        $first = $this->transport->payloads[0];
        self::assertSame('staging', $first['deployment']['environment']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first['instance']['id']);
        self::assertArrayNotHasKey('hostname', $first);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->app->forgetInstance(Reporter::class);
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertSame($first['instance'], $this->transport->payloads[1]['instance']);
    }

    #[DataProvider('applicationNames')]
    public function test_service_defaults_to_a_normalized_application_name(string $name, ?string $expected): void
    {
        $this->app['config']->set('app.name', $name);
        $this->configure('service', null);
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertSame($expected, $this->transport->payloads[0]['deployment']['service']);
    }

    public static function applicationNames(): array
    {
        return [
            ['My Customer API', 'my-customer-api'],
            ['  Café & Queue_Workers  ', 'cafe-queue-workers'],
            ['!!!', null],
            ['', null],
            [str_repeat('a', 254).' b', str_repeat('a', 254)],
        ];
    }

    public function test_explicit_environment_and_service_override_laravel_defaults(): void
    {
        $this->app['config']->set('app.env', 'local');
        $this->app['config']->set('app.name', 'My App');
        $this->configure('environment', 'production');
        $this->configure('service', 'backend-api');
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertSame('production', $this->transport->payloads[0]['deployment']['environment']);
        self::assertSame('backend-api', $this->transport->payloads[0]['deployment']['service']);
    }

    public function test_changing_the_default_service_gets_separate_reporting_state(): void
    {
        $this->app['config']->set('app.name', 'First App');
        $this->configure('service', null);
        self::assertTrue($this->reporter()->reportIfDue('web'));
        $this->app['config']->set('app.name', 'Second App');
        $this->app->forgetInstance(Reporter::class);
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertNotSame($this->transport->payloads[0]['instance']['id'], $this->transport->payloads[1]['instance']['id']);
    }

    public function test_server_retry_after_is_respected(): void
    {
        $this->transport->failure = new TransportException('Rate limited', 429, 300);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        Carbon::setTestNow(Carbon::now()->addSeconds(299));
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertCount(1, $this->transport->payloads);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->transport->failure = null;
        self::assertTrue($this->reporter()->reportIfDue('web'));
    }

    public function test_a_contending_report_does_not_wait_or_send_even_when_forced(): void
    {
        $this->transport->onSend = function (): void {
            $this->app->forgetInstance(Reporter::class);
            $started = microtime(true);
            self::assertFalse($this->reporter()->reportIfDue('web', force: true));
            self::assertLessThan(0.1, microtime(true) - $started);
        };

        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertCount(1, $this->transport->payloads);
    }

    #[DataProvider('disabledConfigurations')]
    public function test_disabled_or_unconfigured_reporting_is_a_no_op(string $key, mixed $value): void
    {
        $this->configure($key, $value);
        $cache = $this->createMock(Factory::class);
        $cache->expects(self::never())->method('store');
        $this->app->instance(Factory::class, $cache);

        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertSame([], $this->transport->payloads);
    }

    public static function disabledConfigurations(): array
    {
        return [['enabled', false], ['ingest_key', null]];
    }

    public function test_an_oversized_inventory_is_not_truncated_or_recollected_but_heartbeats_continue(): void
    {
        $inventory = $this->createMock(Inventory::class);
        $inventory->expects(self::once())->method('collect')->willThrowException(new \LengthException('Too large'));
        $inventory->method('runtime')->willReturn(['name' => 'php', 'versions' => ['php' => PHP_VERSION]]);
        $this->app->instance(Client::class, new Client($this->transport, $inventory));
        self::assertTrue($this->reporter()->reportIfDue('web'));
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertSame('failed', $this->transport->payloads[0]['packages']['status']);
        self::assertSame([['code' => 'inventory_too_large']], $this->transport->payloads[0]['packages']['errors']);
        self::assertCount(2, $this->transport->calls);
    }

    public function test_an_ephemeral_cache_is_rejected(): void
    {
        $this->configure('cache_store', 'array');
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertSame([], $this->transport->payloads);
    }

    public function test_a_broken_cache_does_not_trigger_unthrottled_uploads_or_escape(): void
    {
        $cache = $this->createMock(Factory::class);
        $cache->method('store')->willThrowException(new RuntimeException('Cache unavailable'));
        $this->app->instance(Factory::class, $cache);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertSame([], $this->transport->payloads);
    }

    public function test_it_does_not_upload_when_attempt_state_cannot_be_saved(): void
    {
        $store = new class(new Filesystem, $this->cachePath) extends FileStore
        {
            public function put($key, $value, $seconds): bool
            {
                return false;
            }
        };
        $cache = $this->createMock(Factory::class);
        $cache->method('store')->willReturn(new Repository($store));
        $this->app->instance(Factory::class, $cache);

        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertSame([], $this->transport->payloads);
    }

    public function test_collection_and_logger_failures_do_not_escape(): void
    {
        $this->configure('debug', true);
        $inventory = $this->createMock(Inventory::class);
        $inventory->method('collect')->willThrowException(new RuntimeException('Collection failed'));
        $this->app->instance(Client::class, new Client($this->transport, $inventory));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willThrowException(new RuntimeException('Logger failed'));
        $this->app->instance(LoggerInterface::class, $logger);
        $this->transport->failure = new TransportException('Network failure');

        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertSame('failed', $this->transport->payloads[0]['packages']['status']);
        self::assertCount(1, $this->transport->payloads);
    }
}
