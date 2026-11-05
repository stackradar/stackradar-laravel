<?php

declare(strict_types=1);

namespace StackRadar\Laravel\Tests;

use Closure;
use StackRadar\Transport;
use Throwable;

final class RecordingTransport implements Transport
{
    public array $payloads = [];

    public array $calls = [];

    public ?Throwable $failure = null;

    public array $failures = [];

    public ?Closure $onSend = null;

    public function send(string $method, string $path, array $payload, float $timeout = 2.0): void
    {
        $this->payloads[] = $payload;
        $this->calls[] = compact('method', 'path', 'payload');
        ($this->onSend ?? static fn () => null)();
        $failure = array_shift($this->failures) ?? $this->failure;
        if ($failure !== null) {
            throw $failure;
        }
    }
}
