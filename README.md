# StackRadar for Laravel

See which Composer packages are deployed in each environment. The package reports from your web and queue runtimes every ten minutes, using normal application activity. No cron or daemon required.

Supports Laravel 12–13. Requires PHP 8.2+ and the PHP version required by your Laravel release.

## Install

Both packages are private and unreleased. Use a read-only GitHub token with access to the two repositories:

```bash
composer config --global --auth github-oauth.github.com "$STACKRADAR_PACKAGES_TOKEN"
composer config repositories.stackradar-php vcs https://github.com/stackradar/php-sdk
composer config repositories.stackradar-laravel vcs https://github.com/stackradar/laravel-sdk
composer require 'stackradar/laravel:dev-runtime-ingest-v1' 'stackradar/php:dev-runtime-ingest-v1 as 0.1.0'
```

The temporary alias satisfies the core package's release constraint. Commit `composer.lock` so deployments install the same commits. No global `minimum-stability` change is needed.

## Configure

Laravel discovers the package automatically. Add your Project's Ingest Key and environment:

```dotenv
STACKRADAR_INGEST_KEY=your-ingest-key
STACKRADAR_ENVIRONMENT=production
STACKRADAR_SERVICE=api
```

Without a key, nothing is reported. Set `STACKRADAR_RELEASE` to identify deployments. Runtime reporting must be enabled for your organization in StackRadar.

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

## Laravel Cloud

Add `STACKRADAR_PACKAGES_TOKEN` as a secret, then put this before the existing `composer install` build step:

```bash
composer config --global --auth github-oauth.github.com "$STACKRADAR_PACKAGES_TOKEN"
```

The GitHub download token and the runtime Ingest Key are separate credentials. Keep both out of Git. No deploy command is needed.

## Development

Until the core package is released, use an ignored local Composer manifest:

```bash
cp composer.json composer.local.json
COMPOSER=composer.local.json composer config repositories.stackradar-php vcs https://github.com/stackradar/php-sdk
COMPOSER=composer.local.json composer require 'stackradar/php:dev-runtime-ingest-v1 as 0.1.0'
composer check
```

Tests use Laravel Testbench and fake transports; they don't call StackRadar.
