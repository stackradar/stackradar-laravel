# StackRadar for Laravel

See which Composer packages are deployed in each environment. The package reports from your web and queue runtimes every ten minutes, using normal application activity. No cron or daemon required.

Supports Laravel 12+. Requires PHP 8.2+ and the PHP version required by your Laravel release.

## Install with your agent

Open your application repository in your coding agent and paste this prompt.
The agent will confirm which deployed environments to configure, such as QA,
test, staging or production. Local development is opt-in.
Keep your Ingest Key in your deployment's secret store, outside the prompt.

```text
Set up StackRadar runtime reporting for this Laravel app in the deployed
environments I choose, such as QA, test, staging or production. Include local
development only if I explicitly request it.
Read https://github.com/stackradar/stackradar-laravel for the current
installation and configuration reference, then make the changes in this app.

1. Inspect the app's instructions, PHP/Laravel versions, deployment config
   and secrets workflow. Confirm which deployed environments to configure and
   the destination StackRadar organization and Project; ask for missing details.
2. Install stackradar/laravel in Composer require (not require-dev) and update
   the lockfile so it is installed in deployed builds. Laravel discovers it
   automatically.
3. Configure STACKRADAR_INGEST_KEY through the deployment's existing secret
   management workflow. Have me enter the value directly there when needed.
   Keep the value out of chat, tool output, command arguments and committed
   files. Do not retrieve or copy it into a local .env for this setup.
4. Give each selected deployment a distinct environment name, check the
   service name, set STACKRADAR_RELEASE from its release identifier, and
   select an existing persistent cache store with atomic locks. Use config
   supported by this app and host; ephemeral or read-only filesystems need
   a suitable shared store.
5. Run relevant checks and follow this repository's release process. Prepare
   reviewable changes before requesting any required deployment approval.
   Ensure web processes and long-lived queue workers pick up the new config.
6. For each selected environment, verify a fresh report in the intended
   StackRadar Project from the deployed app, with the correct environment,
   service, release and installed packages.
   Check automatic web and queue reporting through normal activity where
   applicable. A local or CLI-only report does not prove deployed web and
   queue reporting. If access or approval blocks verification, state exactly
   what remains; distinguish installed, deployed and verified in your summary.
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
