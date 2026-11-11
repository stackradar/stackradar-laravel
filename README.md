# StackRadar for Laravel

See which Composer packages are deployed in each environment. The package reports from your web and queue runtimes every ten minutes, using normal application activity. No cron or daemon required.

Supports Laravel 12+. Requires PHP 8.2+ and the PHP version required by your Laravel release.

## Install

```bash
composer require stackradar/laravel
```

## Configure

Laravel discovers the package automatically. Set your Project's Ingest Key in your deployment secrets or environment settings:

```dotenv
STACKRADAR_INGEST_KEY=your-ingest-key
```

The environment defaults to `APP_ENV`. The service defaults to a slug of `APP_NAME`: `My Customer API` becomes `my-customer-api`. Override these with `STACKRADAR_ENVIRONMENT` and `STACKRADAR_SERVICE`. Use distinct service names for apps sharing a Project.

Without a key, nothing is reported. Reporting is disabled during Laravel tests. Set `STACKRADAR_RELEASE` to identify deployments. Runtime reporting must be enabled for your organization in StackRadar.

For other settings:

```bash
php artisan vendor:publish --tag=stackradar-config
```

Use `STACKRADAR_CACHE_STORE` to select a persistent cache with atomic locks; the default is `file`. Set `STACKRADAR_DEBUG=true` for diagnostics. Invalid keys and rejected inventories warn once without enabling debug.

## Check the connection

```bash
php artisan stackradar:report
```

This sends one CLI report and prints any failure reason. Web and queue reporting continues automatically. Idle apps won't send heartbeats, and long-lived workers need restarting on deployment.

## Development

```bash
composer install
composer check
```

Tests use Laravel Testbench and fake transports; they don't call StackRadar.

## License

[Apache 2.0](LICENSE).
