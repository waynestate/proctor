---
title: "Checks | PROCTOR docs"
description: "An overview of every check PROCTOR runs and how each reports pass, warning, info or fail."
url: https://proctor.apps.wayne.edu/docs/guides/checks-overview.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Checks[#](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html#checks)

This page lists every check PROCTOR runs in a single `vendor/bin/proctor check` run, and how each one reports. The proctor asks the same questions in the same order on every run; only the hall changes.

![Nine real lines from PROCTOR's text report, one per check shown: FAIL php-floor, WARN unpatched, FAIL unbounded-constraints, PASS lock-committed, WARN portability, WARN mysql56, WARN ci-pipelines, PASS composer-audit, FAIL overrides. Each line is written in words; color only repeats what the word says.](https://proctor.apps.wayne.edu/docs/readme/report.svg)

*Nine of the thirty-six checks shown, asked in this order on every run; `policy-age` stays silent while the policy is fresh. The lines are real output from the test fixtures.*

## How a check reports[#](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html#how-a-check-reports)

Every finding carries one of four statuses: **pass**, **warning**, **info** or **fail**. A fail is a resit; a warning is a note in the margin that never changes the exit code; an info is the proctor thinking out loud, such as a missing composer or a lookup that could not run.

Every finding also carries a stable, namespaced rule id: `proctor/<check-id>` for a check's default finding, `proctor/<check-id>/<rule>` for a named rule. The id is the finding's identity in the machine formats: `rule_id` in `json`, `ruleId` in `sarif`, and `check_name` in `codequality`. A rule keeps the same id across runs and across formats even when its message changes. The full table lives on the [Rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html) page.

## Composer[#](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html#composer)

The seven checks that read composer.json and composer.lock. Details and fixes: [Composer checks](https://proctor.apps.wayne.edu/docs/guides/check-composer.html).

- `unbounded-constraints`: fails for every runtime constraint that is `*`, names a `dev-` branch, carries `@dev`, has a lower bound with no upper bound, or matches the policy's `banned_constraints`; warns on `path` and `vcs` repositories, on a `minimum-stability` below stable, and on a repository URL that is plain http. `require-dev`, `php`, `ext-*` and `lib-*` are not checked.
- `dev-only-packages`: fails when Laravel Boost or Debugbar is in `require`, because they install in production and expose app internals; warns when Telescope is in `require` (keep it there only if the app registers it for local use) and when a test tool (phpunit, Pest, Mockery, Sail, Pail, Collision) is in `require`. It reads only `require`, never `require-dev`.
- `composer-config`: warns when composer.json opens a door Composer closes by default: `allow-plugins` set to `true`, `secure-http` set to `false`, `lock` set to `false`, `audit.block-insecure` set to `false`, `config.policy` set to `false`, `policy.malware` set to `false` or `policy.advisories` set to `false`; notes when `require.php` is set without `config.platform.php`. Warning and info only, never a fail.
- `lock-committed`: fails when composer.lock is missing from the app path. When the lock is present and its `content-hash` is a real 32-hex hash, it warns (`proctor/lock-committed/out-of-sync`) when the hash no longer matches composer.json, so a stale lock that still installs is visible instead of silent; the warning never changes the exit code.
- `composer-audit`: fails for advisories at or above the policy's `audit_severity_threshold`, warns for lower ones, and reports info when composer or the lock file is absent. Fails (`proctor/composer-audit/could-not-run`) when the audit runs but its stdout is not parseable JSON, because a green run that audited nothing is worse than a red one. Abandoned packages follow the policy's `abandoned_packages` rule: a fail under `fail` (the default), a warning under `report`, nothing under `ignore` (`proctor/composer-audit/abandoned`).
- `composer-version`: fails when the Composer on PATH is Composer 1 (end of life) or carries the command-injection holes CVE-2024-35241 and CVE-2024-35242 (2.2.x below 2.2.24, or any other 2.x below 2.7.7); warns when composer cannot be run.
- `repo-url-credentials`: warns when a credential is written into a package source URL: a deploy token in the userinfo of a composer.json repository URL or a composer.lock source/dist URL, a bare token user of 20+ characters, a `private_token`, `access_token`, `token` or `password` query parameter, or a `.npmrc` line that sets `_authToken`, `_auth` or `_password` to a literal value. The secret is masked as `***` in the message, so the report itself is safe to hand back.

## PHP and database[#](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html#php-and-database)

The checks that grade the PHP floor, the locked Laravel line, patch currency, MySQL 5.6 compatibility and the age of the policy in use. Details and fixes: [PHP checks](https://proctor.apps.wayne.edu/docs/guides/check-php.html).

- `php-floor`: fails when the lowest PHP version allowed by `require.php` is past security support plus the grace window, when composer.json has no usable php constraint, or when there is no composer.json at all.
- `unpatched`: warns when a locked component is behind the policy's `latest_patch` for its version line, and passes when it is current. Reads laravel from composer.lock (`laravel/framework`), vue and inertia from package-lock.json (`vue`, `@inertiajs/vue3`), and the PHP runtime from `--php-runtime`. No finding when the component, its version line, or `latest_patch` is absent from the policy.
- `laravel-eol`: classifies the locked `laravel/framework` version against the policy as of `--today`: active passes, naming the line's end date when it has one; security-only warns, naming the end date and the days left; eol fails, and past the security end date plus the grace window it fails as `eol_grace_expired`, each naming the security end date when the line has one. No finding when composer.lock has no `laravel/framework` or the version resolves to no policy line.
- `laravel-php`: fails when composer.json's `require.php` allows no PHP version inside the range the policy records for the locked Laravel major, or when its lowest allowed version is below the range's floor, and passes otherwise (an open upper bound such as `^8.2` passes); the message names the app's constraint, the policy range and the Laravel major. No finding when composer.lock has no `laravel/framework`, the line has no recorded range, or composer.json declares no `require.php`.
- `mysql56`: warns when migrations or app code use MySQL features the shared 5.6 server rejects: JSON columns (in `database/`) and JSON queries, generated columns, `renameColumn`/`renameIndex`, vector, invisible and spatial columns, `Schema::hasColumn`-style introspection, raw SQL with 5.7+ syntax, the upsert row alias, indexed strings over 191 characters, date columns defaulting to the current time, and the `db:show`/`db:monitor` commands. Warnings, not failures: an app on its own newer database is not affected.
- `policy-age`: warns when the policy in use is more than 60 days old (its `refreshed_on` date is more than 60 days before `--today`), or when a non-bundled policy has no `refreshed_on` date. Warning only: an old policy never fails a run.

The `portability` and `destructive-deploy` checks also read PHP and deploy scripts; they are covered on the [CI checks](https://proctor.apps.wayne.edu/docs/guides/check-ci.html) page.

## CI and deploy[#](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html#ci-and-deploy)

Details and fixes: [CI checks](https://proctor.apps.wayne.edu/docs/guides/check-ci.html).

- `ci-pipelines`: for stack packages (composer.json `name` starting with `waynestate/`), fails when `.gitlab-ci.yml` or `.github/workflows/ci.yml` is missing or runs no `composer ci`; for any other project, a missing or incomplete `.gitlab-ci.yml` is a warning and `.github/workflows/ci.yml` is optional. Warns when a GitHub `uses:` ref is not a 40-hex commit SHA, and when the pipeline environment switches Composer's audit or blocking policy off.
- `preflight-pinned`: warns, never fails, when an app includes a Preflight component at a moving ref or re-declares what an included Preflight job runs.
- `preflight-gates`: warns, never fails, when `.gitlab-ci.yml` includes no Preflight component, lets a Preflight gate fail with `allow_failure`, or has no job that runs PROCTOR.
- `portability`: warns when code hard-codes a platform fact: an absolute host path, a hard-coded proxy address, a local-disk session store, or a php_value/php_flag line in .htaccess.
- `destructive-deploy`: fails when a deploy script runs an artisan command that drops or rebuilds the database (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`), which erases production data on every deploy.
- `crlf-deploy-script`: fails when a deploy script has Windows line endings (CRLF), which break it on Linux.
- `case-clash`: fails when two paths differ only by letter case, which collide on macOS and Windows.
- `instant-client-committed`: warns when Oracle Instant Client is committed to the repo: an archive, an unzipped install under an `instantclient` directory, or a loose client library.

## App code and files[#](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html#app-code-and-files)

The checks that read the app's own files: the Node line it builds on, the MySQL or MariaDB image it runs, `env()` outside config, identity headers read directly, a committed APP_KEY, a committed APP_DEBUG, secret-looking `VITE_` variables, files in `public/`, a container entrypoint that runs migrations and `chmod 777`. Details and fixes: [App checks](https://proctor.apps.wayne.edu/docs/guides/check-app.html).

- `node-eol`: grades every Node version the repository pins (`.nvmrc`, `.node-version`, package.json `volta.node`, an official `node` image in `.gitlab-ci.yml` or a Dockerfile) with php-floor's table: active passes, security-only warns, eol fails, and past the grace window it fails as `eol_grace_expired`. `engines.node` warns at worst and an odd major always warns. Silent when the policy has no node component.
- `mysql-eol`: grades every MySQL or MariaDB version the repository names (an official `mysql` or `mariadb` image in a root compose file, and `SQUIRE_MYSQL_IMAGE=mysql:<version>` in an env example) with php-floor's table, except an active version stays silent: security-only warns, and eol and `eol_grace_expired` warn until Citadel offers a supported MySQL (D-008). Silent when the policy has no mysql or mariadb component.
- `env-outside-config`: warns for every `env()` call in a Laravel app outside its config files, which returns null after `php artisan config:cache`; a call with Larastan's ignore marker for the same rule is an info note.
- `raw-identity-header`: warns, never fails, when app code reads an identity header directly: a string key on `$_SERVER` or `$_ENV`, the quoted argument of `getenv()` or `apache_getenv()`, a quoted first argument of the request's `header()`, `server()`, `hasHeader()`, `getHeader()` or `getHeaderLine()` methods, or a key on the `getallheaders()` result. Only SESAME's gateway driver may read those headers: it verifies the signed assertion first (CREED auth-3).
- `app-key`: warns on a committed real env file, a real APP_KEY committed in an env file or `config/app.php` (never printed), and the cookie session driver. A real env file is graded only when git lists it or the run is in CI.
- `app-debug`: warns, never fails, when a committed env, deploy or CI file sets `APP_DEBUG` to `true` or `1`; one warning per file, on the line of its first true value. It never reads `.env`.
- `vite-secrets`: warns, never fails, when a committed env file declares a secret-looking `VITE_` variable (`VITE_API_KEY`, `VITE_*_SECRET`, `VITE_*_TOKEN`, `VITE_*_PASSWORD`, `VITE_*_PRIVATE*`) or front-end code under `resources/js/` or `src/` reads one through `import.meta.env`: Vite ships every `VITE_` variable to the browser. Only names are read, never values.
- `served-folder`: warns when a committed file under `public/` is a dump, backup, editor copy, log, env file or `adminer*.php`, or calls `phpinfo()`.
- `world-writable`: warns on `chmod 777` (and `0777`, `a+rwx`, `ugo+rwx`) in scripts, Dockerfiles, Makefiles, CI and composer or npm scripts, and on a PHP `chmod()` with `0777`.

## WayneUI[#](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html#wayneui)

Details and fixes: [WayneUI checks](https://proctor.apps.wayne.edu/docs/guides/check-wayneui.html).

- `wayneui-forked-views`: warns when forked copies of WayneUI are committed under `resources/views/vendor/wayne-ui/` or `public/vendor/wayne-ui/`; those copies silently override the package, so an app keeps old layouts after a WayneUI upgrade and nobody sees it.
- `wayneui-outdated`: warns when the WayneUI an app carries is behind the latest release of the same major; by default a warning, not a failure, and an offline run never breaks.

## Overrides[#](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html#overrides)

- `overrides`: fails for every expired entry in policy-overrides.json; warns for an active entry that expires within 14 days of `--today` and for an active entry that matches no failure. How the file works: [Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html).
- `override-approver`: in `proctor check` only, warns (or fails, per CREED's `rules.override_self_approval`) when an entry's `approved_by` names someone git blame says wrote it, and says `not checked:` instead of passing when there is no git history to read. How approval works: [Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html#someone-else-signs-the-note).

## Related[#](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html#related)

- [Composer checks](https://proctor.apps.wayne.edu/docs/guides/check-composer.html)
- [PHP checks](https://proctor.apps.wayne.edu/docs/guides/check-php.html)
- [CI checks](https://proctor.apps.wayne.edu/docs/guides/check-ci.html)
- [App checks](https://proctor.apps.wayne.edu/docs/guides/check-app.html)
- [WayneUI checks](https://proctor.apps.wayne.edu/docs/guides/check-wayneui.html)
- [Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html)
- [Rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html)
