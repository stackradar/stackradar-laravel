<?php

declare(strict_types=1);

namespace StackRadar\Laravel;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use StackRadar\Client;
use StackRadar\HttpTransport;
use StackRadar\Transport;

final class StackRadarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stackradar.php', 'stackradar');

        $this->app->singleton(Transport::class, fn ($app) => new HttpTransport(
            ingestKey: (string) $app['config']->get('stackradar.ingest_key'),
            baseUrl: (string) $app['config']->get('stackradar.ingest_url'),
        ));

        $this->app->singleton(Client::class, fn ($app) => new Client(
            transport: $app->make(Transport::class),
            timeout: (float) $app['config']->get('stackradar.timeout'),
            reporterName: 'stackradar/laravel',
        ));

        $this->app->singleton(Reporter::class, function ($app): Reporter {
            $options = $app['config']->get('stackradar');
            $options['enabled'] = $options['enabled'] && ! $app->runningUnitTests();
            $options['environment'] ??= $app['config']->get('app.env');
            $options['service'] ??= rtrim(Str::substr(Str::slug((string) $app['config']->get('app.name')), 0, 255), '-') ?: null;

            return new Reporter(
                options: $options,
                cache: $app->make(Factory::class),
                client: fn (): Client => $app->make(Client::class),
                logger: $app->make(LoggerInterface::class),
                applicationPath: $app->basePath(),
            );
        });
    }

    public function boot(): void
    {
        $this->callAfterResolving(Kernel::class, static function (Kernel $kernel): void {
            if ($kernel instanceof HttpKernel) {
                $kernel->pushMiddleware(ReportRuntime::class);
            }
        });

        $this->app['events']->listen(Looping::class, fn () => $this->app->make(Reporter::class)->reportIfDue('queue'));

        $this->app['events']->listen([JobProcessed::class, JobExceptionOccurred::class], function (object $event): void {
            if ($this->app['config']->get('queue.connections.'.$event->connectionName.'.driver') !== 'sync') {
                $this->app->make(Reporter::class)->reportIfDue('queue');
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ReportCommand::class]);
            $this->publishes([__DIR__.'/../config/stackradar.php' => $this->app->configPath('stackradar.php')], 'stackradar-config');
        }
    }
}
