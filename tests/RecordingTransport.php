<?php

declare(strict_types=1);

namespace StackRadar\Laravel\Tests;

use Closure;
use StackRadar\Transport;
use Throwable;

final class RecordingTransport implements Transport
{
    public array $payloads = [];

    public ?Throwable $failure = null;

    public ?Closure $onSend = null;

    public function send(array $payload): void
    {
        $this->payloads[] = $payload;
        ($this->onSend ?? static fn () => null)();

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
