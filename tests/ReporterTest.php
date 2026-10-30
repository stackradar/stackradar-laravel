<?php

declare(strict_types=1);

namespace StackRadar\Laravel\Tests;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use StackRadar\Client;
use StackRadar\Inventory;
use StackRadar\Laravel\Reporter;
use StackRadar\TransportException;

final class ReporterTest extends TestCase
{
    public function test_the_due_gate_survives_new_reporter_instances_and_does_not_collect_when_not_due(): void
    {
        $inventory = $this->createMock(Inventory::class);
        $inventory->expects(self::exactly(2))->method('collect')->willReturn(['packages' => [], 'runtime' => []]);
        $this->app->instance(Client::class, new Client('PROD', $this->transport, $inventory));

        self::assertTrue($this->reporter()->reportIfDue('web'));
        $this->app->forgetInstance(Reporter::class);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        Carbon::setTestNow(Carbon::now()->addHour());
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertCount(2, $this->transport->payloads);
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
            ['release', 'release-b'], ['instance_id', 'instance-b'],
            ['environment', 'STAGE'], ['project_token', 'other-token'],
            ['endpoint', 'https://other.example.test/ingest'],
        ];
    }

    public function test_web_queue_and_cli_have_separate_reporting_state(): void
    {
        foreach (['web', 'queue', 'cli'] as $role) {
            self::assertTrue($this->reporter()->reportIfDue($role));
            self::assertFalse($this->reporter()->reportIfDue($role));
        }
        self::assertSame(['web', 'queue', 'cli'], array_map(fn ($p) => $p['context']->role, $this->transport->payloads));
    }

    public function test_failures_back_off_and_success_resets_the_failure_counter(): void
    {
        $this->transport->failure = new TransportException('Unavailable', 503);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertCount(1, $this->transport->payloads);

        Carbon::setTestNow(Carbon::now()->addSeconds(60));
        self::assertFalse($this->reporter()->reportIfDue('web'));
        Carbon::setTestNow(Carbon::now()->addSeconds(60));
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertCount(2, $this->transport->payloads);

        Carbon::setTestNow(Carbon::now()->addSeconds(60));
        $this->transport->failure = null;
        self::assertTrue($this->reporter()->reportIfDue('web'));
        Carbon::setTestNow(Carbon::now()->addHour());
        $this->transport->failure = new TransportException('Unavailable', 503);
        self::assertFalse($this->reporter()->reportIfDue('web'));
        Carbon::setTestNow(Carbon::now()->addSeconds(60));
        $this->transport->failure = null;
        self::assertTrue($this->reporter()->reportIfDue('web'));
        self::assertCount(5, $this->transport->payloads);
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
        return [['enabled', false], ['project_token', null], ['environment', null], ['endpoint', null]];
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
        $inventory = $this->createMock(Inventory::class);
        $inventory->method('collect')->willThrowException(new RuntimeException('Collection failed'));
        $this->app->instance(Client::class, new Client('PROD', $this->transport, $inventory));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willThrowException(new RuntimeException('Logger failed'));
        $this->app->instance(LoggerInterface::class, $logger);

        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertFalse($this->reporter()->reportIfDue('web'));
        self::assertSame([], $this->transport->payloads);
    }
}
