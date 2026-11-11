<?php

declare(strict_types=1);

namespace StackRadar\Laravel;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use LengthException;
use LogicException;
use Psr\Log\LoggerInterface;
use SensitiveParameterValue;
use StackRadar\Client;
use StackRadar\PackageInventory;
use StackRadar\TransportException;
use Throwable;

final class Reporter
{
    private const HEARTBEAT_INTERVAL = 600;

    private const AUTH_RETRY_INTERVAL = 3600;

    /** @var array<string, int> */
    private array $localNextAttempt = [];

    /** @var array<string, mixed> */
    private array $options;

    private readonly SensitiveParameterValue $services;

    private string $applicationPath;

    private bool $hasKey;

    private string $keyFingerprint;

    /** @var array{status: int|null, reason: string}|null */
    private ?array $lastFailure = null;

    /** @param array<string, mixed> $options */
    public function __construct(
        #[\SensitiveParameter]
        array $options,
        Factory $cache,
        Closure $client,
        LoggerInterface $logger,
        string $applicationPath
    ) {
        $key = (string) ($options['ingest_key'] ?? '');
        $this->hasKey = trim($key) !== '';
        $this->keyFingerprint = hash('sha256', $key);
        unset($options['ingest_key']);
        $this->options = $options;
        $this->applicationPath = $applicationPath;
        $this->services = new SensitiveParameterValue(compact('cache', 'client', 'logger'));
    }

    /** @return array{status: int|null, reason: string}|null */
    public function lastFailure(): ?array
    {
        return $this->lastFailure;
    }

    /** @return array{cache: Factory, client: Closure(): Client, logger: LoggerInterface} */
    private function services(): array
    {
        return $this->services->getValue();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['options' => $this->options, 'ingest_key' => $this->hasKey ? '[redacted]' : null];
    }

    public function reportIfDue(string $role, bool $force = false): bool
    {
        $now = Carbon::now()->getTimestamp();
        if (! $force && ($this->localNextAttempt[$role] ?? 0) > $now) {
            return false;
        }
        $this->lastFailure = null;
        $this->localNextAttempt[$role] = $now + self::HEARTBEAT_INTERVAL;
        try {
            return $this->report($role, $force);
        } catch (Throwable $exception) {
            $this->lastFailure = $this->failure($exception);
            if ($this->localNextAttempt[$role] <= Carbon::now()->getTimestamp()) {
                $this->localNextAttempt[$role] = Carbon::now()->getTimestamp() + self::HEARTBEAT_INTERVAL;
            }
            if (($this->options['debug'] ?? false) && ! $this->isTerminal($exception)) {
                $this->warn([...$this->lastFailure, 'exception_type' => get_class($exception)]);
            }

            return false;
        }
    }

    private function report(string $role, bool $force): bool
    {
        $options = $this->options;
        if (! $options['enabled'] || ! $this->hasKey) {
            $this->lastFailure = ['status' => null, 'reason' => ! $options['enabled'] ? 'sdk_disabled' : 'not_configured'];

            return false;
        }
        if ((int) $options['startup_delay'] < 0 || (int) $options['startup_jitter'] < 0) {
            throw new LogicException('StackRadar reporting intervals are invalid.');
        }

        $client = ($this->services()['client'])();
        $key = 'stackradar:'.hash('sha256', json_encode([
            $this->keyFingerprint, $options['ingest_url'], $options['environment'], $options['service'],
            $options['release'], $options['artifact'],
            $this->applicationPath, gethostname(), PHP_VERSION, PHP_SAPI, $role,
            $client->inventoryFingerprint(),
        ], JSON_THROW_ON_ERROR));
        $cache = $this->services()['cache']->store($options['cache_store']);
        if (! $cache instanceof Repository || $cache->getStore() instanceof ArrayStore || ! $cache->getStore() instanceof LockProvider) {
            throw new LogicException('StackRadar needs a persistent cache store supporting atomic locks.');
        }
        $state = $cache->get($key, []);
        $this->localNextAttempt[$role] = $state['next_attempt_at'] ?? $this->localNextAttempt[$role];
        if ($state['stopped'] ?? false) {
            $this->lastFailure = $state['failure'] ?? ['status' => null, 'reason' => 'reporting_stopped'];
            $this->pause($role);

            return false;
        }
        if (! $force && ($state['next_attempt_at'] ?? 0) > Carbon::now()->getTimestamp()) {
            return false;
        }
        $lease = $cache->getStore()->lock($key.':lock', max(15, (int) ceil((float) $options['timeout']) + 5));
        if (! $lease->get()) {
            $this->lastFailure = ['status' => null, 'reason' => 'report_in_progress'];
            $this->pause($role);

            return false;
        }

        try {
            $state = $cache->get($key, []);
            $now = Carbon::now()->getTimestamp();
            if ($state === []) {
                $state = [
                    'instance' => ['id' => $this->uuid(), 'started_at' => Carbon::now()->toIso8601String()],
                    'next_attempt_at' => $now + (int) $options['startup_delay'] + random_int(0, (int) $options['startup_jitter']),
                ];
                $this->save($cache, $key, $state);
            }
            $this->localNextAttempt[$role] = $state['next_attempt_at'];
            if ($state['stopped'] ?? false) {
                $this->lastFailure = $state['failure'] ?? ['status' => null, 'reason' => 'reporting_stopped'];
                $this->pause($role);

                return false;
            }
            if (! $force && $state['next_attempt_at'] > $now) {
                return false;
            }
            $state['next_attempt_at'] = $now + self::HEARTBEAT_INTERVAL;
            $this->localNextAttempt[$role] = $state['next_attempt_at'];
            $this->save($cache, $key, $state);

            $cached = ($state['inventory_blocked'] ?? false) ? null : $cache->get($key.':inventory');
            if ($cached === null && ! ($state['inventory_blocked'] ?? false)) {
                try {
                    $cached = $client->collectInventory()->payload();
                } catch (LengthException) {
                    $state['inventory_blocked'] = true;
                    $state['inventory_error'] = 'inventory_too_large';
                } catch (Throwable) {
                    $state['inventory_error'] = 'collection_failed';
                }
                if ($cached !== null) {
                    $this->save($cache, $key.':inventory', $cached);
                    unset($state['inventory_error']);
                }
                $this->save($cache, $key, $state);
            }
            $inventory = $cached === null ? null : new PackageInventory($cached['ecosystem'], $cached['packages']);

            try {
                $client->report([
                    'instance' => $state['instance'],
                    'deployment' => [
                        'environment' => $options['environment'],
                        'service' => $options['service'],
                        'release' => is_string($options['release']) && trim($options['release']) !== ''
                            ? ['value' => $options['release'], 'source' => 'config:stackradar.release'] : null,
                        'artifact' => $options['artifact'],
                    ],
                ], $inventory, isset($state['inventory_error']) ? [['code' => $state['inventory_error']]] : []);
            } catch (TransportException $exception) {
                if (($exception->getCode() === 401 && $exception->reason === 'invalid_ingest_key') || $exception->getCode() === 410) {
                    $state['stopped'] = true;
                } elseif (in_array($exception->getCode(), [401, 403], true)) {
                    $state['next_attempt_at'] = $now + max(self::AUTH_RETRY_INTERVAL, $exception->retryAfter ?? 0);
                } elseif ($exception->getCode() === 429 && $exception->retryAfter !== null) {
                    $state['next_attempt_at'] = $now + max(60, $exception->retryAfter);
                } elseif ($exception->inventoryUpload && in_array($exception->getCode(), [413, 422], true)) {
                    $state['inventory_blocked'] = true;
                    $state['inventory_error'] = $exception->reason ?? 'inventory_rejected';
                }
                $this->localNextAttempt[$role] = $state['next_attempt_at'];
                if ($this->isTerminal($exception)) {
                    $state['failure'] = $this->failure($exception);
                    $state = $this->warnOnce($cache, $key, $state, $state['failure']);
                } else {
                    $this->save($cache, $key, $state);
                }
                throw $exception;
            }
            if ($inventory === null) {
                $this->lastFailure = $state['failure'] ?? ['status' => null, 'reason' => $state['inventory_error'] ?? 'collection_failed'];
                if ($state['inventory_blocked'] ?? false) {
                    $state['failure'] = $this->lastFailure;
                    $state = $this->warnOnce($cache, $key, $state, $this->lastFailure);
                }
            }
            $state['last_success_at'] = Carbon::now()->getTimestamp();
            $this->save($cache, $key, $state);

            return true;
        } finally {
            $lease->release();
        }
    }

    /** @param array<string, mixed> $state */
    private function save(Repository $cache, string $key, array $state): void
    {
        if (! $cache->put($key, $state, 2592000)) {
            throw new LogicException('Unable to persist StackRadar reporting state.');
        }
    }

    private function pause(string $role): void
    {
        $this->localNextAttempt[$role] = max($this->localNextAttempt[$role] ?? 0, Carbon::now()->getTimestamp() + self::HEARTBEAT_INTERVAL);
    }

    private function isTerminal(Throwable $exception): bool
    {
        return $exception instanceof TransportException && (
            ($exception->getCode() === 401 && $exception->reason === 'invalid_ingest_key') || $exception->getCode() === 410
            || ($exception->inventoryUpload && in_array($exception->getCode(), [413, 422], true))
        );
    }

    /** @return array{status: int|null, reason: string} */
    private function failure(Throwable $exception): array
    {
        return [
            'status' => $exception instanceof TransportException ? $exception->getCode() : null,
            'reason' => $exception instanceof TransportException && preg_match('/\A[a-z0-9_]{1,100}\z/', $exception->reason ?? '') === 1
                ? $exception->reason : 'report_failed',
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array{status: int|null, reason: string}  $failure
     * @return array<string, mixed>
     */
    private function warnOnce(Repository $cache, string $key, array $state, array $failure): array
    {
        $warning = (string) $failure['status'].'|'.$failure['reason'];
        if (! isset($state['warnings'][$warning])) {
            $state['warnings'][$warning] = true;
            $this->save($cache, $key, $state);
            $this->warn($failure);
        } else {
            $this->save($cache, $key, $state);
        }

        return $state;
    }

    /** @param array{status: int|null, reason: string, exception_type?: string} $failure */
    private function warn(array $failure): void
    {
        try {
            $this->services()['logger']->warning('StackRadar runtime report failed.', $failure);
        } catch (Throwable $ignored) {
            // Logging must not turn telemetry into an application error.
        }
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-'.dechex((hexdec($hex[16]) & 3) | 8).substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
