# StackRadar for Laravel

Laravel 12/13 integration for Runtime Ingest Protocol v1, depending on the separate `stackradar/php` repository. Requires PHP 8.2+ for Laravel 12; Laravel 13 has its own higher requirement.

## Server compatibility

PHP runtime reports and Composer inventories are supported by Runtime Ingest v1, following the server changes merged in v0 PR #784 (`4eb7d27`). The integration's payload, hash algorithm and response handling already match that contract; no protocol upgrade or additional package fields are required.

Generated PHP reports were checked against the merged server validator, including nullable deployment fields, empty inventories, collection failures and multiple versions of one package. Inventory hashes match the server implementation. Production endpoint access has not been exercised from this workspace.

A valid Project Ingest Key and the organization's `multi-source-projects` feature are required. The SDK does not enable the feature or alter repository exposures. The packages remain unpublished.

## Configuration

After publishing both packages, install `stackradar/laravel`. Laravel discovers its service provider automatically.

```dotenv
STACKRADAR_INGEST_KEY=sr_ingest_...
STACKRADAR_ENVIRONMENT=PROD
STACKRADAR_SERVICE=api
STACKRADAR_RELEASE=deploy-123
```

Only the Ingest Key is required. Without it the package does nothing. The server derives the Project from that key. Environment and release are nullable; environment is never implicitly set to production. Service defaults to the root Composer package's name, or null if unnamed. `STACKRADAR_ARTIFACT` optionally identifies an image/build artifact.

The endpoint origin defaults to `https://ingest.stackradar.com`. `STACKRADAR_INGEST_URL` is an internal testing override, not a full route URL. The previous prototype's `STACKRADAR_PROJECT_TOKEN` and `STACKRADAR_ENDPOINT` are no longer read.

```bash
php artisan vendor:publish --tag=stackradar-config
```

Published config exposes `enabled`, `ingest_key`, `environment`, `service`, `release`, `artifact`, `ingest_url`, `startup_delay` (5 seconds), `startup_jitter` (0–30 seconds), `timeout` (2 seconds total network budget), and `cache_store` (`file`). `STACKRADAR_ENABLED=false` disables reporting. Use `STACKRADAR_CACHE_STORE` to select another persistent Laravel cache store with atomic locks. The array cache is rejected.

## Lifecycle and state

HTTP reporting uses terminable middleware; queue reporting uses loop/completed/failed job events. Synchronous queue jobs and ordinary Artisan commands do not report automatically. The first lifecycle event persists an opaque UUID, its start timestamp and a randomized initial due time. No request sleeps to implement the delay. The first eligible event after it becomes due attempts the check-in.

State is local to the application replica, runtime role, PHP version/SAPI, key, endpoint and deployment configuration. Local hostname/application paths participate only in hashing cache keys and are never sent. Web, queue and CLI reporters get distinct persisted UUIDs. Multiple PHP-FPM processes on one replica/role share the reporter identity and lock; these identities do not count every OS process individually.

The inventory is collected once and cached as plain package metadata, then reused across 10-minute check-ins. Installed Composer metadata file timestamp/size changes, a changed release/artifact, or a different scope invalidate the state. Set `STACKRADAR_RELEASE` or `STACKRADAR_ARTIFACT` for every deployment and restart long-lived workers; file timestamps alone cannot reliably identify every in-place installation change. Cache state expires after 30 days without a write. This is coordination metadata, not a queue of unsent reports.

Each due event takes a non-waiting lease, rechecks due state and reserves the next attempt before collecting or sending. It then POSTs the hash check-in and PUTs the complete list only after `409 unknown_inventory`. The two HTTP requests share the configured timeout. Unchanged inventories still generate check-ins; the server's 409 repairs a missing inventory.

| Response | Behavior |
| --- | --- |
| 204 | Next check-in in 10 minutes |
| 409 `unknown_inventory` | Upload the cached inventory |
| 401 / 403 / 410 | Persist a stop decision for this reporter identity, including manual calls |
| 413 / 422 on inventory upload | Suppress that unchanged inventory; later check-ins report collection failure without a list/hash |
| 422 on check-in | Retry at the next interval; log a safe warning |
| 429 | Persist `Retry-After`, or use the next interval |
| Network / 5xx | Retry at the next interval; no disk outbox |

Changing the key or deployment scope creates fresh state and clears an old stop/rejection decision. Clearing the selected cache also resets reporting identities and decisions. A locally oversized list is never truncated: check-ins continue with `inventory_too_large`. Temporary collection failures can be retried on later due events. Transport/cache/collection failures do not escape into requests/jobs; logging is guarded and excludes raw exception messages, keys and response bodies. Cache failures skip the upload rather than sending without throttling.

Delivery is traffic-driven. Idle web applications cannot guarantee wall-clock heartbeats, and a stale observation does not prove the application stopped. Termination still consumes PHP worker capacity. The HTTP timeout does not bound a customer's cache/logging latency. This integration uses the normal HTTP kernel termination path under Octane; tests cover its application gateway, not native Swoole/RoadRunner/FrankenPHP servers or routes bypassing the kernel.

## Manual check-in

```bash
php artisan stackradar:report
```

This forces the CLI reporter past its interval/startup delay, while respecting an active lease or stop decision. It reports CLI runtime state. The command exits unsuccessfully when disabled, unconfigured, locked, stopped, or unsuccessful.

## Development

Keep the repositories side by side:

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

The local installer generates ignored Composer metadata linking the sibling SDK; production Composer metadata contains no developer paths. Tests require PHP 8.3+ because they use PHPUnit 11. Testbench, fake transports, temporary file caches and separate PHP processes cover lifecycle, stable identity, caching, concurrent leases, startup jitter, stop decisions, retries and rejected inventories. Tests send no external API requests.

See the PHP SDK README for the wire contract and its name/version-only limitation for moving development branches. No remote repository or package release has been created. License metadata remains proprietary pending a distribution decision.
