# stackradar/laravel

A Laravel package that reports an application's installed Composer packages and PHP runtime versions to StackRadar from normal web and queue activity, with no cron or daemon.

It builds on [stackradar/php](https://github.com/stackradar/stackradar-php), which collects the packages and sends the report. This package decides when to report. Changes to collection or transport belong in stackradar/php.

## Layout

- `src/StackRadarServiceProvider.php`: container bindings, the HTTP middleware and the queue listeners.
- `src/Reporter.php`: `reportIfDue()`, with the cooldown, the cache lock and the handling of each answer.
- `src/ReportRuntime.php`: terminable middleware that reports after the response is sent.
- `src/ReportCommand.php`: `php artisan stackradar:report`.
- `config/stackradar.php`: the package configuration.
- `tests/`: Orchestra Testbench tests. They use a recording transport and never call StackRadar.

## Checks

Run `composer check` before committing. It runs Pint, PHPStan and PHPUnit. CI runs the same on PHP 8.2 to 8.5 with Laravel 12 and 13, lowest and highest dependencies.

## Rules

- **Reporting never interrupts the application.** No exception escapes, nothing delays a response, and a queue listener never returns `false`: Laravel pauses the worker when a `Looping` listener does.
- **Nothing is reported without an Ingest Key, or during Laravel tests.**
- **State lives in the cache store**, which needs atomic locks. Two processes must never report the same check-in twice.
- **Never log or expose the Ingest Key**, including in debug output.
- **Environment variable names and config keys are a public API.** Renaming one is a breaking change.
- A change in behaviour comes with a test. Tests use made-up data.

## Pull requests

- `main` changes only through a pull request.
- Each pull request gets one label: `semver:major`, `semver:minor`, `semver:patch` or `semver:chore`. The draft release is built from them.
- Maintainers create release tags with the Release workflow.
