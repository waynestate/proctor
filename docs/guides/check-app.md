---
title: App checks
description: The checks that read an app's own files for an end-of-life Node line, an end-of-life MySQL or MariaDB image, env() outside config, identity headers read directly, a committed APP_KEY, a committed APP_DEBUG, debug files in public/ and chmod 777.
---

# App checks

This page covers the twelve PROCTOR checks that read the app's own files rather than its Composer metadata: the Node line it builds on, the MySQL or MariaDB image it runs, the database version its Laravel line needs, `env()` calls outside config, identity headers read directly, a committed APP_KEY, a committed APP_DEBUG, migrations run by a container entrypoint, files a stranger can download from `public/`, deploy scripts with Windows line endings, paths that differ only by letter case, and `chmod 777`. Each one holds a CREED practice. Run `vendor/bin/proctor check` against the app path and fix what the report names. The proctor walks the aisles as well as reading the papers.

`node-eol`, `crlf-deploy-script` and `case-clash` can fail a run. The other nine warn, never fail: `mysql-eol` warns until Citadel's shared database server offers a supported MySQL (D-008), and a new check must not turn a passing app into a failing one.

## node-eol

`node-eol` grades every Node version the repository pins against the policy's `node` lines as of `--today`. The sources:

- `.nvmrc` and `.node-version` (the first line that is not a comment);
- package.json `volta.node`;
- an official `node` image in `.gitlab-ci.yml`, on an `image:` or `name:` line (`node:22`, `node:24-alpine`, `docker.io/library/node:22`, or one behind a registry host or a `$CI_DEPENDENCY_PROXY_...` prefix);
- an official `node` image on a `FROM` line in a `Dockerfile`, `Containerfile`, `Dockerfile.*` or `*.Dockerfile`.

Each source gets the same table as `php-floor`:

| State | Result |
| --- | --- |
| Active | pass, naming the active end date |
| Security only | warning, naming the security end date and the days left |
| End of life | fail, naming the security end date |
| Past the security end date plus the grace window | fail as `eol_grace_expired` |

An active override downgrades the eol fail to a warning, never the grace-expired one.

package.json `engines.node` is a declaration, not what runs, so its lowest allowed major is graded the same way but warns at worst (`proctor/node-eol/engines`). An odd major (21, 23, 25) is never an LTS line and never a target, so it warns wherever it is named (`proctor/node-eol/odd-major`).

No finding when the policy has no node component, when the repository names no Node version, for aliases (`lts/*`, `node`, `latest`), for image tags without a version (`node:lts-alpine`), and for another organization's image that happens to be called node (`someorg/node:14`).

Fix by moving the pin to an active even line, in every place the report names: the `.nvmrc`, the CI image and the Dockerfile should agree.

| Check | Rule id |
| --- | --- |
| `node-eol` | `proctor/node-eol` |
| `node-eol` | `proctor/node-eol/engines` |
| `node-eol` | `proctor/node-eol/odd-major` |

## mysql-eol

`mysql-eol` grades every MySQL or MariaDB version the repository names against the policy's `mysql` and `mariadb` lines as of `--today`. The sources:

- an official `mysql` or `mariadb` image on an `image:` line in a root `docker-compose*.yml` or `compose*.yml` (`mysql:8.0`, `mariadb:10.11`, `docker.io/library/mysql:8.4`, or one behind a registry host or a `$VAR` prefix);
- `SQUIRE_MYSQL_IMAGE=mysql:<version>` in `.env.squire.example` or `.env.example`.

A `@sha256:` digest after the tag is tolerated: the version still comes from the tag. Each source gets the same table as `php-floor`, except an active version stays silent instead of passing:

| State | Result |
| --- | --- |
| Active | silent |
| Security only | warning, naming the security end date and the days left |
| End of life | warning, naming the security end date |
| Past the security end date plus the grace window | warning as `eol_grace_expired` |

Every row warns, never fails, until Citadel offers a supported MySQL (D-008). No override is needed while that holds: every graded state already warns.

No finding when the policy has no `mysql` or `mariadb` component, when the repository names no database version, or for another organization's image that happens to be called mysql (`someorg/mysql:8.0`). A bare major tag such as `mysql:8` is a floating tag and gets a warning to pin a major.minor; it is not graded as a guessed line.

Fix by moving the image to an active line, in every place the report names: the compose file and the env example should agree.

| Check | Rule id |
| --- | --- |
| `mysql-eol` | `proctor/mysql-eol` |

## mysql-minimum

`mysql-minimum` warns, never fails, when the app's MySQL or MariaDB image is below the minimum the policy records for its Laravel line: an app on Laravel 12 with `mysql:5.6` passes `mysql-eol`'s line lookup but cannot run its migrations. It reads the locked `laravel/framework` version from composer.lock, resolves its policy line, and takes the line's recorded `requires.mysql` and `requires.mariadb` minimums. The image is found the way `mysql-eol` finds it: an official `mysql` or `mariadb` image on an `image:` line in a root `docker-compose*.yml` or `compose*.yml`, and `SQUIRE_MYSQL_IMAGE=mysql:<version>` in `.env.squire.example` or `.env.example`; the version the tag names is what is compared.

Each declared image whose engine has a recorded minimum is compared with it: below the minimum, one warning per declaration (`proctor/mysql-minimum/below`) naming the declaration, such as `mysql 5.6 is below the 5.7 minimum Laravel 12 needs (docker-compose.yml:7)`. With at least one image graded and none below, one pass finding names the first graded image: `mysql 8.4 meets the 5.7 minimum Laravel 12 needs`. No finding when composer.lock has no `laravel/framework`, the line resolves to no policy line, the line records no minimums, or the repository names no database image with a version.

Fix by moving the image to a supported version, in every place the report names: the compose file and the env example should agree.

| Check | Rule id |
| --- | --- |
| `mysql-minimum` | `proctor/mysql-minimum` |
| `mysql-minimum` | `proctor/mysql-minimum/below` |

## env-outside-config

`env-outside-config` warns for every `env()` call in a Laravel app outside its config files. After `php artisan config:cache`, Laravel no longer loads .env, so the call returns null in production while it works on a laptop.

It runs only on a Laravel app: an `artisan` file at the root, or `laravel/framework` in composer.json `require`. It scans every `*.php` file except the top-level `config/` and `bootstrap/cache/`, and skips `tests/`, `vendor/`, `node_modules/`, `storage/` and `.git/` at any depth.

PHP is read on the syntax tree with names resolved, so `env()` in a comment or a string, a method named `env` (`$app->env()`) and a function imported from another namespace under the name `env` are not flagged. A Blade template is matched line by line and only with a quoted first argument (`{{ env('APP_NAME') }}`); Blade's own `@env('local')` directive is not a call.

Larastan enforces the same rule, so a call that carries its ignore marker for it (`@phpstan-ignore larastan.noEnvCallsOutsideOfConfig`, on the same line or on a comment line right above) was reviewed already. It is an info note (`proctor/env-outside-config/suppressed`), visible in the report but not counted. A closure in `bootstrap/app.php` that runs before config is loaded is the usual reason.

Fix by reading the variable in a config file (`'url' => env('APP_URL')` in `config/app.php`) and calling `config('app.url')` where it is used.

| Check | Rule id |
| --- | --- |
| `env-outside-config` | `proctor/env-outside-config` |
| `env-outside-config` | `proctor/env-outside-config/suppressed` |

## raw-identity-header

`raw-identity-header` warns, never fails, when app code reads an identity header directly instead of taking the user from SESAME. Drawbridge, the gateway, signs the session into the `X-Sesame-Assertion` header and passes the user's claims as request headers (`REMOTE_USER`, `X-Authenticated-*`, `X-Drawbridge-*`, `OIDC_CLAIM_*`); only SESAME's gateway driver may open that pair, because it verifies the signature before a claim becomes the logged-in user (CREED auth-3). App code that reads the assertion or a claim header itself accepts whatever value the proxy, or a client, put there.

It runs on every app and scans every `*.php` file except `vendor/`, `node_modules/`, `storage/`, `tests/` and `.git/` at any depth.

PHP is read on the syntax tree with names resolved, so a header name in a comment or a log string, a variable named `$REMOTE_USER`, and `getenv()` with a computed argument are not flagged. Flagged read positions: a string key on `$_SERVER` or `$_ENV`, the quoted argument of `getenv()` or `apache_getenv()`, the quoted first argument of a method named `header`, `server`, `hasHeader`, `getHeader` or `getHeaderLine` (and of `get()` on a `->headers` object), and a string key on the result of `getallheaders()` or `apache_request_headers()`, directly or through a variable assigned from them.

Names match case-insensitively with `-` and `_` interchangeable. Where the read comes from `$_SERVER` or the environment (and from `request()->server()`, which reads the same bag) the `HTTP_` prefix Apache adds to request headers is accepted too. `X-Sesame-Assertion` is the signed assertion itself, so it is flagged like any other direct read; `PHP_AUTH_USER` is basic-auth material, so it is not an identity claim and is not flagged. A file that does not parse is skipped here; the portability check reports it.

Blade templates (`*.blade.php`) are not PHP until compiled, so a Blade line is matched with a pattern for `$_SERVER['...']` and `request()->header('...')` / `request()->server('...')`.

Fix by taking the user from SESAME (the gateway driver) and reading the claims it returns.

| Check | Rule id |
| --- | --- |
| `raw-identity-header` | `proctor/raw-identity-header` |

## app-key

`app-key` warns when the repository gives away the Laravel APP_KEY, or makes a leaked one worse. Whoever holds the key can forge encrypted payloads the app will unserialize, which can mean code execution.

| Rule id | Warns when |
| --- | --- |
| `proctor/app-key/committed-env` | A real env file is committed: `.env`, `.env.production`, or any `.env.*` that is not an example or a test file. |
| `proctor/app-key/committed-key` | A committed env file, examples included, sets `APP_KEY` or `APP_PREVIOUS_KEYS` to a real key (`base64:` and 16 or 32 bytes), or `config/app.php` carries one as a literal. The key is never printed. |
| `proctor/app-key/cookie-sessions` | `SESSION_DRIVER=cookie` in a committed env file, or a `cookie` default in `config/session.php`: every session is sealed with the APP_KEY, so a leaked key opens all of them. |

A real env file is graded only when PROCTOR knows it is committed: git lists it, or `CI`, `GITLAB_CI` or `GITHUB_ACTIONS` is set, where the checkout holds only what the repository holds. A developer's own `.env` on a laptop never raises a flag. Example files (`.example`, `.dist`, `.sample`, `.template`) are always graded; test files (`.env.testing`, `.env.test`, `.env.ci`, `.env.dusk.*`) are skipped.

Fix by running `git rm --cached` on the file, adding it to .gitignore and committing a `.env.example` with placeholders. Then rotate the key with `php artisan key:generate` on every environment that used it, and every other secret the file held. Move sessions to the `database` or `redis` driver.

| Check | Rule id |
| --- | --- |
| `app-key` | `proctor/app-key` |
| `app-key` | `proctor/app-key/committed-env` |
| `app-key` | `proctor/app-key/committed-key` |
| `app-key` | `proctor/app-key/cookie-sessions` |

## app-debug

`app-debug` warns, never fails, when a committed env, deploy or CI file sets `APP_DEBUG` to a truthy value: `true` or `1`, case-insensitive, with optional spaces around `=` or `:`. A debug build shows stack traces and environment values to whoever requests the page, so it must stay off in production; Laravel reads `APP_DEBUG` in `config/app.php`. It reports, and is not in the deploy gate.

Scanned: `.env.production` and `.env.squire.example` at the root, every file under a top-level `deploy/` or `deployments/` directory at any depth, and the CI pipelines (`.gitlab-ci.yml` and every `.github/workflows/*.yml`). Skipped at any depth: `vendor/`, `node_modules/` and `.git/`. One warning per file, on the line of its first true value; a file that cannot be read is skipped. It never reads `.env` (a committed `.env` is the app-key check's finding) or `.env.example`, the local-development template where debug is expected to be on.

Fix by setting `APP_DEBUG=false` in the file the report names.

| Check | Rule id |
| --- | --- |
| `app-debug` | `proctor/app-debug` |
| `app-debug` | `proctor/app-debug/enabled` |

## entrypoint-migrate

`entrypoint-migrate` warns, never fails, when a container entrypoint runs migrations at start: with two replicas both would run `migrate` at the same moment, and a failed migration crash-loops the container, so WELD practice W24 runs migrations once per release as a job, never in an entrypoint. It reports, and is not in the deploy gate.

Scanned: every file whose name contains `entrypoint` (case-insensitive) at any depth; Dockerfiles themselves are not read. Skipped at any depth: `vendor/`, `node_modules/` and `.git/`. A line is flagged when, after removing a trailing shell comment, it contains `artisan migrate` followed by whitespace, `;`, `&`, `|`, a quote or the end of the line, so `migrate --force` and a bare `migrate` flag while `migrate:status`, `migrate:fresh` and the other subcommands do not; a line whose first non-space character is `#` is ignored. A line inside a shell `case` branch whose label is `migrate)` or `migrate|...)`, with optional spaces before it, is not flagged: the branch runs from its label line to the next line ending in `;;`, or to `esac`. That is the shape Squire's own entrypoint takes, where the `migrate` role runs once as a job. One warning per file, on the line of its first flagged line; a file that cannot be read is skipped.

Fix by moving the migration out of the entrypoint and running it once per release as a job.

| Check | Rule id |
| --- | --- |
| `entrypoint-migrate` | `proctor/entrypoint-migrate` |
| `entrypoint-migrate` | `proctor/entrypoint-migrate/runs` |

## served-folder

`served-folder` warns when a committed file under the top-level `public/` is something a stranger should not be able to download:

- a PHP file that calls `phpinfo()`, which shows the server configuration and environment (`proctor/served-folder/phpinfo`);
- a database dump (`*.sql`, `*.sql.gz`, `*.sql.zip`, `*.sql.bz2`, `*.dump`), a backup or editor copy (`*.bak`, `*.backup`, `*.old`, `*.orig`, `*.swp`, `*.swo`, a name ending in `~`), a log, an env file, or a database admin tool (`adminer*.php`) (`proctor/served-folder/debug-file`).

No finding when the app has no `public/` folder. Fix by deleting the file, or moving it out of `public/` and out of the repository.

| Check | Rule id |
| --- | --- |
| `served-folder` | `proctor/served-folder` |
| `served-folder` | `proctor/served-folder/debug-file` |
| `served-folder` | `proctor/served-folder/phpinfo` |

## crlf-deploy-script

`crlf-deploy-script` fails when a deploy script has Windows line endings (CRLF): bash on Linux reads the carriage return as part of each command, so `set -e` becomes `set -e\r` and the script breaks on the host that runs it.

Scanned: the top-level `build.sh` and `release.sh`, and every `.sh` file under a `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/` directory at any depth. Skipped at any depth: `vendor/`, `node_modules/` and `.git/`. One finding per file, on the line of its first carriage return.

Warns (`proctor/crlf-deploy-script/no-gitattributes`) when the app has a deploy script and `.gitattributes` has no `*.sh`, `**/*.sh` or `*` line with `eol=lf`.

Fix by converting the file to LF (`sed -i 's/\r$//' build.sh`, or the editor's line-ending setting) and adding `*.sh text eol=lf` to .gitattributes, so Git keeps it LF on every machine.

| Check | Rule id |
| --- | --- |
| `crlf-deploy-script` | `proctor/crlf-deploy-script` |
| `crlf-deploy-script` | `proctor/crlf-deploy-script/no-gitattributes` |

## case-clash

`case-clash` fails when two paths differ only by letter case (`Docs/Guide.md` and `docs/guide.md`): macOS and Windows ignore case, so one checkout silently overwrites the other, and a Linux host then autoloads a different file than the developer edited.

Scanned: every file and directory under the app path. Skipped at any depth: `vendor/`, `node_modules/` and `.git/`. One finding per topmost clash, naming every colliding path; a clash below a clashing directory is part of it, not its own finding.

Fix by keeping one name and removing the duplicate: `git rm docs/guide.md`, or `git mv` it to a different name.

| Check | Rule id |
| --- | --- |
| `case-clash` | `proctor/case-clash` |

## world-writable

`world-writable` warns when the repository sets mode 777, which lets any process on the host change the file or folder:

- `chmod` with `777`, `0777`, `a+rwx` or `ugo+rwx` (flags such as `-R` allowed) in a shell script, a `Dockerfile` or `Containerfile`, a `Makefile`, `.gitlab-ci.yml`, or the `scripts` of composer.json and package.json;
- a PHP `chmod()` call whose mode is the literal `0777`.

Comment lines, the sticky `1777` and `mkdir()` (the umask still applies to it) are not flagged; `tests/` is skipped.

Fix by giving the web server user write access to the folders it needs (`storage/` and `bootstrap/cache/` in Laravel) and nothing more. Whether the container runs as a non-root user is a runtime fact, outside what PROCTOR reads.

| Check | Rule id |
| --- | --- |
| `world-writable` | `proctor/world-writable` |

## Related

- [Checks](checks-overview.md)
- [PHP checks](check-php.md)
- [Overrides](overrides.md)
- [Rule ids](../reference/rule-ids.md)
