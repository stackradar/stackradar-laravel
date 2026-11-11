# StackRadar for Laravel

See which Composer packages are deployed in each environment. The package reports from your web and queue runtimes every ten minutes, using normal application activity. No cron or daemon required.

Supports Laravel 12+. Requires PHP 8.2+ and the PHP version required by your Laravel release.

## Install with your agent

Paste this prompt into your coding agent from your application repository:

```text
Set up stackradar/laravel runtime reporting for this app.

1. Target: inspect the app's instructions and deployment configuration.
   Before editing, confirm which deployed environments to report (for example,
   QA, test, staging or production) and their destination StackRadar organization
   and Project. Resolve missing details with me. Include local development only
   when I explicitly request it.

2. Configure: read the installation, configuration and connection-check reference
   at https://github.com/stackradar/stackradar-laravel#manual-installation.
   Check compatibility, then prepare the Composer dependency and lockfile changes
   so deployed builds include the SDK. For every selected environment, configure
   its environment name, service, release identifier and persistent cache with
   atomic locks through the app's existing deployment workflow.

   Keep ingest keys within the deployment's secret-management workflow. Reuse an
   existing secret reference or have me enter STACKRADAR_INGEST_KEY directly into
   the secret store. Keep values out of chat, tool output, command arguments,
   committed files and local development configuration.

3. Release: run the relevant project checks and resolve failures caused by these
   changes. Present reviewable changes for any required release approval, then
   follow the authorized deployment process. Ensure deployed web processes and
   long-lived queue workers load the SDK and configuration.

4. Verify: for every selected environment, confirm fresh reports in the intended
   StackRadar Project with the expected environment, service, release and
   installed packages. Verify web and queue reporting through deployed activity
   where applicable; the CLI connection check verifies only the CLI runtime.
   Report each environment as configured, deployed or verified, backed by the
   evidence obtained. If a step is blocked, name the remaining action and owner.
```

## Manual installation

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

On Vapor or read-only containers, set `STACKRADAR_CACHE_STORE` to `redis` or `dynamodb`.

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
