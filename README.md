# StackRadar for Laravel

Automatic runtime dependency inventory for Laravel 12 and 13. This repository depends on the separate `stackradar/php` package. Requires PHP 8.2+ for Laravel 12; Laravel 13 has its own higher PHP requirement.

This is an unpublished first version. The endpoint and JSON payload are provisional, with a replaceable transport so the ingestion API can evolve independently.

## Install and configure

After both packages are published:

```bash
composer require stackradar/laravel
```

```dotenv
STACKRADAR_PROJECT_TOKEN=your-project-token
STACKRADAR_ENVIRONMENT=PROD
STACKRADAR_ENDPOINT=https://ingest.example.com/runtime
```

The URL above is a placeholder. Set the full URL supplied by the ingestion API. The project token uses the `Authorization: Bearer` header. Laravel discovers the service provider automatically. No migrations, cron entry, inbound endpoint, queue connection, or daemon are required.

Optionally publish configuration:

```bash
php artisan vendor:publish --tag=stackradar-config
```

| Setting | Default | Purpose |
| --- | --- | --- |
| `enabled` | `true` | Disable with `STACKRADAR_ENABLED=false` |
| `project_token` | unset | Required credential |
| `environment` | `APP_ENV` / `production` | Environment label, preserved as supplied |
| `endpoint` | unset | Required full ingestion URL |
| `interval` | 3600 seconds | Interval after a successful report |
| `retry_after` | 60 seconds | Initial failure delay, doubling to a maximum of one hour |
| `timeout` | 2 seconds | Total HTTP request timeout, at most 10 seconds |
| `cache_store` | `file` | Persistent Laravel cache store with atomic locks |
| `instance_id` | Hash of the runtime hostname | Override with `STACKRADAR_INSTANCE_ID` |
| `release` | unset | Set `STACKRADAR_RELEASE` to the deployment/image identifier |

Missing credentials or endpoint means no automatic reporting. Invalid configuration and reporting failures produce a warning containing the exception class and HTTP status, without the exception message, token, URL, or payload.

## Reporting lifecycle

HTTP reports run in terminable middleware. Queue workers report from loop and completed/failed job events; synchronous queue jobs are excluded. Ordinary Artisan commands do not report automatically.

Each eligible event checks persisted due state before collecting inventory. When due, it attempts a non-waiting lease, rechecks the state, records a retry reservation, then collects and sends. Success advances the due time. Failure keeps the retry reservation, with exponential backoff and support for `Retry-After` up to seven days. Cache failures skip the upload rather than sending without throttling. The `array` cache driver is rejected because it does not persist across PHP requests.

Unchanged inventories are still reported periodically to establish freshness. There is no local outbox and no inline HTTP retry. A process killed during an upload leaves a retry reservation; a later eligible event can try again. Duplicates remain possible, so API ingestion should be idempotent.

Reporting is scoped by token, environment, endpoint, application path, instance, release, runtime role, PHP version/SAPI, and Laravel version. Different instances, releases and web/queue/CLI roles do not suppress one another. State keys are hashed. Defaults aggregate processes on the same instance and role; this is not a census of every worker process.

The default file cache needs writable Laravel storage. Redis or another persistent lock-capable store can be selected through `STACKRADAR_CACHE_STORE`. Give every replica a distinct instance ID when using a shared cache or when hostnames are not unique. Set the release explicitly to report new deployments immediately, and restart long-lived workers on deployment. The default instance identifier is resolved at runtime, not baked into `config:cache`.

**Timing is traffic-driven:** the first eligible request or worker event after an interval is due attempts a report. An idle web application has no wall-clock delivery guarantee. Termination still occupies the PHP worker; this is bounded HTTP work, not a detached background process. Queue loop reporting also uses worker time, outside job processing where possible. The configured HTTP timeout does not bound a customer's cache or logging service latency.

The standard HTTP kernel termination path also runs under Octane. Tests cover the Octane application gateway across requests; native Swoole, RoadRunner, and FrankenPHP servers have not been exercised. Octane-specific routes that bypass Laravel's HTTP kernel are outside this version's automatic coverage.

## Report now

```bash
php artisan stackradar:report
```

This forces a report of the **CLI runtime**, bypassing the normal interval but respecting an active lease. It does not verify PHP-FPM or queue worker state. It exits unsuccessfully when disabled, unconfigured, locked, or unsuccessful. You may schedule this command yourself for CLI coverage.

Reports contain PHP and extension versions, installed Composer packages (including deployed dev dependencies), environment, instance, release, runtime role, Laravel version, observation time, and SDK version. Installed metadata does not establish execution or reachability. See the PHP SDK README for the complete provisional schema.

## Replace the transport

Bind `StackRadar\Transport` in your application's service provider to adapt to a different API contract or test without network calls. The binding is resolved lazily after the due check and lease. Custom transports must enforce their own timeouts.

## Local development with the sibling repository

Keep the separate Git repositories side by side:

```text
php-libs/
  php-sdk/
  laravel-sdk/
```

```bash
cd ../php-sdk && composer install
cd ../laravel-sdk
bin/install-local
composer check
```

`bin/install-local` generates an ignored `composer.local.json` and lockfile, with a path repository pointing to `../php-sdk`. Production Composer metadata contains no developer filesystem paths. Run the script again after changing either manifest; Composer does not import repositories from dependencies.

Development tests require PHP 8.3+ because they use PHPUnit 11. Tests use Laravel Testbench, a fake transport and temporary file caches, with no external API calls. The integration suite covers requests, queue events, commands, Octane gateway termination, backoff and separate-process concurrency.

Verified locally with Laravel 12.70.2 / PHP 8.3.31 and Laravel 13.39.0 / PHP 8.5.12. PHPStan also targets PHP 8.2 syntax; the PHP 8.2 runtime itself has not been exercised. No remote repository or release has been created. License metadata remains proprietary pending a distribution decision.
