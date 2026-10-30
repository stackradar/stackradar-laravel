<?php

declare(strict_types=1);

namespace StackRadar\Laravel;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use LogicException;
use Psr\Log\LoggerInterface;
use StackRadar\Client;
use StackRadar\TransportException;
use Throwable;

final class Reporter
{
    /**
     * @param  array<string, mixed>  $options
     * @param  Closure(): Client  $client  Resolved only after the due gate and lease.
     */
    public function __construct(
        private readonly array $options,
        private readonly Factory $cache,
        private readonly Closure $client,
        private readonly LoggerInterface $logger,
        private readonly string $applicationPath,
    ) {}

    public function reportIfDue(string $role, bool $force = false): bool
    {
        try {
            return $this->report($role, $force);
        } catch (Throwable $exception) {
            try {
                $this->logger->warning('StackRadar runtime report failed.', [
                    'exception_type' => $exception::class,
                    'status' => $exception instanceof TransportException ? $exception->getCode() : null,
                ]);
            } catch (Throwable) {
                // A broken application logger must not turn telemetry into an application error.
            }

            return false;
        }
    }

    private function report(string $role, bool $force): bool
    {
        $options = $this->options;

        if (! $options['enabled'] || empty($options['project_token']) || empty($options['environment']) || empty($options['endpoint'])) {
            return false;
        }

        $interval = (int) $options['interval'];
        $retry = (int) $options['retry_after'];

        if ($interval < 1 || $retry < 1) {
            throw new LogicException('StackRadar reporting intervals must be positive.');
        }

        $instance = $options['instance_id'] ?: substr(hash('sha256', gethostname() ?: $this->applicationPath), 0, 16);
        $context = [
            'instance_id' => $instance,
            'release' => $options['release'],
            'role' => $role,
            'framework' => ['name' => 'laravel', 'version' => Application::VERSION],
        ];
        $key = 'stackradar:'.hash('sha256', json_encode([
            $options['project_token'], $options['environment'], $options['endpoint'],
            $this->applicationPath, PHP_VERSION, PHP_SAPI, $context,
        ], JSON_THROW_ON_ERROR));

        $cache = $this->cache->store($options['cache_store']);

        if (! $cache instanceof Repository || $cache->getStore() instanceof ArrayStore || ! $cache->getStore() instanceof LockProvider) {
            throw new LogicException('StackRadar needs a persistent cache store supporting atomic locks.');
        }

        $state = $cache->get($key, []);

        if (! $force && ($state['next_attempt_at'] ?? 0) > Carbon::now()->getTimestamp()) {
            return false;
        }

        $lease = $cache->getStore()->lock($key.':lock', max(15, (int) ceil((float) $options['timeout']) + 5));

        if (! $lease->get()) {
            return false;
        }

        try {
            $state = $cache->get($key, []);
            $now = Carbon::now()->getTimestamp();

            if (! $force && ($state['next_attempt_at'] ?? 0) > $now) {
                return false;
            }

            $failures = min(16, ($state['failures'] ?? 0) + 1);
            $delay = min(3600, $retry * (2 ** ($failures - 1)));
            $state = [
                'next_attempt_at' => $now + $delay,
                'last_success_at' => $state['last_success_at'] ?? null,
                'failures' => $failures,
            ];

            // Reserve a retry before collecting/sending, including when this process is killed.
            $this->save($cache, $key, $state, $delay);

            try {
                ($this->client)()->report($context);
            } catch (TransportException $exception) {
                if (($exception->retryAfter ?? 0) > $delay) {
                    $state['next_attempt_at'] = $now + $exception->retryAfter;
                    $this->save($cache, $key, $state, $exception->retryAfter);
                }

                throw $exception;
            }

            $now = Carbon::now()->getTimestamp();
            $this->save($cache, $key, [
                'next_attempt_at' => $now + $interval,
                'last_success_at' => $now,
                'failures' => 0,
            ], $interval);

            return true;
        } finally {
            $lease->release();
        }
    }

    /** @param array<string, int|null> $state */
    private function save(Repository $cache, string $key, array $state, int $delay): void
    {
        if (! $cache->put($key, $state, $delay + 86400)) {
            throw new LogicException('Unable to persist StackRadar reporting state.');
        }
    }
}
