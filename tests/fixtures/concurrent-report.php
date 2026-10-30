<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Psr\Log\NullLogger;
use StackRadar\Client;
use StackRadar\Laravel\Reporter;
use StackRadar\Transport;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = $argv[1];
$app = new Container;
$app->instance('config', new Repository([
    'cache' => ['stores' => ['file' => ['driver' => 'file', 'path' => $directory.'/cache']]],
]));
$app->instance('files', new Filesystem);
$options = [
    'enabled' => true, 'project_token' => 'test-token', 'environment' => 'PROD',
    'endpoint' => 'https://example.test/ingest', 'interval' => 3600, 'retry_after' => 60,
    'timeout' => 2, 'cache_store' => 'file', 'instance_id' => 'instance-a', 'release' => 'release-a',
];
$transport = new class($directory) implements Transport
{
    public function __construct(private readonly string $directory) {}

    public function send(array $payload): void
    {
        file_put_contents($this->directory.'/reports', "report\n", FILE_APPEND | LOCK_EX);
        usleep(200000);
    }
};
$reporter = new Reporter($options, new CacheManager($app), fn () => new Client('PROD', $transport), new NullLogger, $directory);

// Synchronise both processes so they contend for the same real file-cache lease.
file_put_contents($directory.'/ready-'.getmypid(), 'ready');
$deadline = microtime(true) + 5;
while (! file_exists($directory.'/start')) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(1000);
}
$reporter->reportIfDue('web');
