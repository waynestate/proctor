---
title: "App checks | PROCTOR docs"
description: "The checks that read an app's own files for an end-of-life Node line, an end-of-life MySQL or MariaDB image, env() outside config, identity headers read directly, a committed APP_KEY, debug files in public/ and chmod 777."
url: https://proctor.apps.wayne.edu/docs/guides/check-app.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# App checks[#](https://proctor.apps.wayne.edu/docs/guides/check-app.html#app-checks)

This page covers the seven PROCTOR checks that read the app's own files rather than its Composer metadata: the Node line it builds on, the MySQL or MariaDB image it runs, `env()` calls outside config, identity headers read directly, a committed APP_KEY, files a stranger can download from `public/`, and `chmod 777`. Each one holds a CREED practice. Run `vendor/bin/proctor check` against the app path and fix what the report names. The proctor walks the aisles as well as reading the papers.

`node-eol` and `mysql-eol` can fail a run. The other five warn, never fail: a new check must not turn a passing app into a failing one.

## node-eol[#](https://proctor.apps.wayne.edu/docs/guides/check-app.html#node-eol)

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

## mysql-eol[#](https://proctor.apps.wayne.edu/docs/guides/check-app.html#mysql-eol)

`mysql-eol` grades every MySQL or MariaDB version the repository names against the policy's `mysql` and `mariadb` lines as of `--today`. The sources:

- an official `mysql` or `mariadb` image on an `image:` line in a root `docker-compose*.yml` or `compose*.yml` (`mysql:8.0`, `mariadb:10.11`, `docker.io/library/mysql:8.4`, or one behind a registry host or a `$VAR` prefix);
- `SQUIRE_MYSQL_IMAGE=mysql:<version>` in `.env.squire.example` or `.env.example`.

A `@sha256:` digest after the tag is tolerated: the version still comes from the tag. Each source gets the same table as `php-floor`, except an active version stays silent instead of passing:

| State | Result |
| --- | --- |
| Active | silent |
| Security only | warning, naming the security end date and the days left |
| End of life | fail, naming the security end date |
| Past the security end date plus the grace window | fail as `eol_grace_expired` |

An active override downgrades the eol fail to a warning, never the grace-expired one.

No finding when the policy has no `mysql` or `mariadb` component, when the repository names no database version, or for another organization's image that happens to be called mysql (`someorg/mysql:8.0`).

Fix by moving the image to an active line, in every place the report names: the compose file and the env example should agree.

| Check | Rule id |
| --- | --- |
| `mysql-eol` | `proctor/mysql-eol` |

## env-outside-config[#](https://proctor.apps.wayne.edu/docs/guides/check-app.html#env-outside-config)

`env-outside-config` warns for every `env()` call in a Laravel app outside its config files. After `php artisan config:cache`, Laravel no longer loads .env, so the call returns null in production while it works on a laptop.

It runs only on a Laravel app: an `artisan` file at the root, or `laravel/framework` in composer.json `require`. It scans every `*.php` file except the top-level `config/` and `bootstrap/cache/`, and skips `tests/`, `vendor/`, `node_modules/`, `storage/` and `.git/` at any depth.

PHP is read on the syntax tree with names resolved, so `env()` in a comment or a string, a method named `env` (`$app->env()`) and a function imported from another namespace under the name `env` are not flagged. A Blade template is matched line by line and only with a quoted first argument (`{{ env('APP_NAME') }}`); Blade's own `@env('local')` directive is not a call.

Larastan enforces the same rule, so a call that carries its ignore marker for it (`@phpstan-ignore larastan.noEnvCallsOutsideOfConfig`, on the same line or on a comment line right above) was reviewed already. It is an info note (`proctor/env-outside-config/suppressed`), visible in the report but not counted. A closure in `bootstrap/app.php` that runs before config is loaded is the usual reason.

Fix by reading the variable in a config file (`'url' => env('APP_URL')` in `config/app.php`) and calling `config('app.url')` where it is used.

| Check | Rule id |
| --- | --- |
| `env-outside-config` | `proctor/env-outside-config` |
| `env-outside-config` | `proctor/env-outside-config/suppressed` |

## raw-identity-header[#](https://proctor.apps.wayne.edu/docs/guides/check-app.html#raw-identity-header)

`raw-identity-header` warns, never fails, when app code reads an identity header directly instead of taking the user from SESAME. DRAWBRIDGE, the gateway, signs the session into the `X-Sesame-Assertion` header and passes the user's claims as request headers (`REMOTE_USER`, `X-Authenticated-*`, `X-Drawbridge-*`, `OIDC_CLAIM_*`); only SESAME's gateway driver may open that pair, because it verifies the signature before a claim becomes the logged-in user (CREED auth-3). App code that reads the assertion or a claim header itself accepts whatever value the proxy, or a client, put there.

It runs on every app and scans every `*.php` file except `vendor/`, `node_modules/`, `storage/`, `tests/` and `.git/` at any depth.

PHP is read on the syntax tree with names resolved, so a header name in a comment or a log string, a variable named `$REMOTE_USER`, and `getenv()` with a computed argument are not flagged. Flagged read positions: a string key on `$_SERVER` or `$_ENV`, the quoted argument of `getenv()` or `apache_getenv()`, the quoted first argument of a method named `header`, `server`, `hasHeader`, `getHeader` or `getHeaderLine` (and of `get()` on a `->headers` object), and a string key on the result of `getallheaders()` or `apache_request_headers()`, directly or through a variable assigned from them.

Names match case-insensitively with `-` and `_` interchangeable. Where the read comes from `$_SERVER` or the environment (and from `request()->server()`, which reads the same bag) the `HTTP_` prefix Apache adds to request headers is accepted too. `X-Sesame-Assertion` is the signed assertion itself, so it is flagged like any other direct read; `PHP_AUTH_USER` is basic-auth material, so it is not an identity claim and is not flagged. A file that does not parse is skipped here; the portability check reports it.

Blade templates (`*.blade.php`) are not PHP until compiled, so a Blade line is matched with a pattern for `$_SERVER['...']` and `request()->header('...')` / `request()->server('...')`.

Fix by taking the user from SESAME (the gateway driver) and reading the claims it returns.

| Check | Rule id |
| --- | --- |
| `raw-identity-header` | `proctor/raw-identity-header` |

## app-key[#](https://proctor.apps.wayne.edu/docs/guides/check-app.html#app-key)

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

## served-folder[#](https://proctor.apps.wayne.edu/docs/guides/check-app.html#served-folder)

`served-folder` warns when a committed file under the top-level `public/` is something a stranger should not be able to download:

- a PHP file that calls `phpinfo()`, which shows the server configuration and environment (`proctor/served-folder/phpinfo`);
- a database dump (`*.sql`, `*.sql.gz`, `*.sql.zip`, `*.sql.bz2`, `*.dump`), a backup or editor copy (`*.bak`, `*.backup`, `*.old`, `*.orig`, `*.swp`, `*.swo`, a name ending in `~`), a log, an env file, or a database admin tool (`adminer*.php`) (`proctor/served-folder/debug-file`).

No finding when the app has no `public/` folder. Fix by deleting the file, or moving it out of `public/` and out of the repository.

| Check | Rule id |
| --- | --- |
| `served-folder` | `proctor/served-folder` |
| `served-folder` | `proctor/served-folder/debug-file` |
| `served-folder` | `proctor/served-folder/phpinfo` |

## crlf-deploy-script[#](https://proctor.apps.wayne.edu/docs/guides/check-app.html#crlf-deploy-script)

`crlf-deploy-script` fails when a deploy script has Windows line endings (CRLF): bash on Linux reads the carriage return as part of each command, so `set -e` becomes `set -e\r` and the script breaks on the host that runs it.

Scanned: the top-level `build.sh` and `release.sh`, and every `.sh` file under a `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/` directory at any depth. Skipped at any depth: `vendor/`, `node_modules/` and `.git/`. One finding per file, on the line of its first carriage return.

Fix by converting the file to LF (`sed -i 's/\r$//' build.sh`, or the editor's line-ending setting) and adding `*.sh text eol=lf` to .gitattributes, so Git keeps it LF on every machine.

| Check | Rule id |
| --- | --- |
| `crlf-deploy-script` | `proctor/crlf-deploy-script` |

## world-writable[#](https://proctor.apps.wayne.edu/docs/guides/check-app.html#world-writable)

`world-writable` warns when the repository sets mode 777, which lets any process on the host change the file or folder:

- `chmod` with `777`, `0777`, `a+rwx` or `ugo+rwx` (flags such as `-R` allowed) in a shell script, a `Dockerfile` or `Containerfile`, a `Makefile`, `.gitlab-ci.yml`, or the `scripts` of composer.json and package.json;
- a PHP `chmod()` call whose mode is the literal `0777`.

Comment lines, the sticky `1777` and `mkdir()` (the umask still applies to it) are not flagged; `tests/` is skipped.

Fix by giving the web server user write access to the folders it needs (`storage/` and `bootstrap/cache/` in Laravel) and nothing more. Whether the container runs as a non-root user is a runtime fact, outside what PROCTOR reads.

| Check | Rule id |
| --- | --- |
| `world-writable` | `proctor/world-writable` |

## Related[#](https://proctor.apps.wayne.edu/docs/guides/check-app.html#related)

- [Checks](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html)
- [PHP checks](https://proctor.apps.wayne.edu/docs/guides/check-php.html)
- [Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html)
- [Rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html)
