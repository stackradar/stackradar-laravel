<?php

declare(strict_types=1);

namespace StackRadar\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReportRuntime
{
    public function __construct(private readonly Reporter $reporter) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->reporter->reportIfDue('web');
    }
}
