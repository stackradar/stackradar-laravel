<?php

declare(strict_types=1);

namespace StackRadar\Laravel;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use LengthException;
use LogicException;
use Psr\Log\LoggerInterface;
use StackRadar\Client;
use StackRadar\PackageInventory;
use StackRadar\TransportException;
use Throwable;

final class Reporter
{
    private const HEARTBEAT_INTERVAL = 600;

    /**
     * @param  array<string, mixed>  $options
     * @param  Closure(): Client  $client
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
                // Application telemetry must also tolerate a broken logger.
            }

            return false;
        }
    }

    private function report(string $role, bool $force): bool
    {
        $options = $this->options;
        if (! $options['enabled'] || empty($options['ingest_key'])) {
            return false;
        }
        if ((int) $options['startup_delay'] < 0 || (int) $options['startup_jitter'] < 0) {
            throw new LogicException('StackRadar reporting intervals are invalid.');
        }

        $root = InstalledVersions::getRootPackage();
        $metadata = $root['install_path'].'/vendor/composer/installed.php';
        clearstatcache(true, $metadata);
        $key = 'stackradar:'.hash('sha256', json_encode([
            $options['ingest_key'], $options['ingest_url'], $options['environment'], $options['service'],
            $options['release'], $options['artifact'],
            $this->applicationPath, gethostname(), PHP_VERSION, PHP_SAPI, $role,
            is_file($metadata) ? [filemtime($metadata), filesize($metadata)] : null,
        ], JSON_THROW_ON_ERROR));
        $cache = $this->cache->store($options['cache_store']);
        if (! $cache instanceof Repository || $cache->getStore() instanceof ArrayStore || ! $cache->getStore() instanceof LockProvider) {
            throw new LogicException('StackRadar needs a persistent cache store supporting atomic locks.');
        }
        $state = $cache->get($key, []);
        if (($state['stopped'] ?? false) || (! $force && ($state['next_attempt_at'] ?? 0) > Carbon::now()->getTimestamp())) {
            return false;
        }
        $lease = $cache->getStore()->lock($key.':lock', max(15, (int) ceil((float) $options['timeout']) + 5));
        if (! $lease->get()) {
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
            if (($state['stopped'] ?? false) || (! $force && $state['next_attempt_at'] > $now)) {
                return false;
            }
            $state['next_attempt_at'] = $now + self::HEARTBEAT_INTERVAL;
            $this->save($cache, $key, $state);
            $client = ($this->client)();

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
                        'service' => $options['service'] ?? ($root['name'] === '__root__' ? null : $root['name']),
                        'release' => is_string($options['release']) && trim($options['release']) !== ''
                            ? ['value' => $options['release'], 'source' => 'config:stackradar.release'] : null,
                        'artifact' => $options['artifact'],
                    ],
                ], $inventory, isset($state['inventory_error']) ? [['code' => $state['inventory_error']]] : []);
            } catch (TransportException $exception) {
                if (in_array($exception->getCode(), [401, 403, 410], true)) {
                    $state['stopped'] = true;
                } elseif ($exception->getCode() === 429 && $exception->retryAfter !== null) {
                    $state['next_attempt_at'] = $now + $exception->retryAfter;
                } elseif ($exception->inventoryUpload && in_array($exception->getCode(), [413, 422], true)) {
                    $state['inventory_blocked'] = true;
                    $state['inventory_error'] = $exception->reason ?? 'inventory_rejected';
                }
                $this->save($cache, $key, $state);
                throw $exception;
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

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-'.dechex((hexdec($hex[16]) & 3) | 8).substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
